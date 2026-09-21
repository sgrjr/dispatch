<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Contracts\LaneResolver;
use Sgrjr\Dispatch\Livewire\TaskBoard;
use Sgrjr\Dispatch\Livewire\TaskShow;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchBatchService;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\Lane;
use Sgrjr\Dispatch\Support\NullLaneResolver;
use Sgrjr\Dispatch\Support\TaskPresenter;

/*
 * TASK-997 part A — the LANE column, seam, claim/route semantics for the
 * no-department lane, filters, capture lane, and the board UI. See the lane
 * contract (rulings R14/R15/R21/R22/R23) for the full spec; this file
 * exercises the package-side implementation.
 *
 * Part B (hand-off, blocked-by, notifications) is a later wave — nothing
 * here exercises it.
 */

beforeEach(fn () => dispatchFakeUsers());

/**
 * A fake LaneResolver: four lanes (`ops`, `ops:triage`, `ops:billing`,
 * `support`), an admin id list, and an explicit per-user lanesFor()/
 * lanesManagedBy() map keyed by user id.
 *
 * @param  array<int,int>              $adminIds
 * @param  array<int,array<int,string>> $lanesByUser
 * @param  array<int,array<int,string>> $managedByUser
 */
function bindFakeLaneResolver(array $adminIds = [], array $lanesByUser = [], array $managedByUser = []): void
{
    app()->singleton(LaneResolver::class, fn () => new class($adminIds, $lanesByUser, $managedByUser) implements LaneResolver
    {
        private array $allLanes = ['ops', 'ops:triage', 'ops:billing', 'support'];

        public function __construct(
            private array $adminIds,
            private array $lanesByUser,
            private array $managedByUser,
        ) {}

        public function isLane(string $lane): bool
        {
            return in_array($lane, $this->allLanes, true);
        }

        public function label(string $lane): ?string
        {
            return match ($lane) {
                'ops' => 'Ops',
                'ops:triage' => 'Ops · Triage',
                'ops:billing' => 'Ops · Billing',
                'support' => 'Support',
                default => null,
            };
        }

        public function lanes(): array
        {
            return $this->allLanes;
        }

        public function lanesFor(Authenticatable $user): array
        {
            return $this->lanesByUser[$user->getAuthIdentifier()] ?? [];
        }

        public function lanesManagedBy(Authenticatable $user): array
        {
            return $this->managedByUser[$user->getAuthIdentifier()] ?? [];
        }

        public function memberIds(string $lane): array
        {
            $ids = [];
            foreach ($this->lanesByUser as $id => $lanes) {
                if (in_array($lane, $lanes, true)) {
                    $ids[] = $id;
                }
            }

            return $ids;
        }

        public function canRoute(Authenticatable $user): bool
        {
            return in_array($user->getAuthIdentifier(), $this->adminIds, true);
        }
    });
}

/** Mint an approved agent session token (mirrors AnchorFieldsTest's helper). */
function laneAgentToken(?array $scopes = null): string
{
    static $approverId = 92000;
    $approverId++;

    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-remote', 'lanes');
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser($approverId)->id, null, $scopes);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

// --- migration / column -----------------------------------------------

test('the lane column exists on dispatch_tasks, nullable, and round-trips through the model', function () {
    expect(Schema::hasColumn('dispatch_tasks', 'lane'))->toBeTrue();

    $task = app(DispatchTaskService::class)->create(['title' => 'plain']);
    expect($task->lane)->toBeNull();

    $task->lane = 'ops:triage';
    $task->save();

    expect($task->fresh()->lane)->toBe('ops:triage');
});

// --- Lane helper + inLane/inLanes/unrouted scopes ------------------------

test('Lane::department() returns the part before the first colon, or the whole string', function () {
    expect(Lane::department('ops'))->toBe('ops')
        ->and(Lane::department('ops:triage'))->toBe('ops')
        ->and(Lane::isSubLane('ops'))->toBeFalse()
        ->and(Lane::isSubLane('ops:triage'))->toBeTrue();
});

test('scopeInLane: a bare department matches itself AND every sub-lane', function () {
    $svc = app(DispatchTaskService::class);
    $dept = $svc->create(['title' => 'bare dept', 'lane' => 'ops']);
    $triage = $svc->create(['title' => 'sub-lane', 'lane' => 'ops:triage']);
    $other = $svc->create(['title' => 'other dept', 'lane' => 'support']);

    $matched = Task::inLane('ops')->pluck('id')->all();

    expect($matched)->toContain($dept->id, $triage->id)
        ->and($matched)->not->toContain($other->id);
});

test('scopeInLane: a "dept:role" key matches EXACTLY, not the bare department', function () {
    $svc = app(DispatchTaskService::class);
    $dept = $svc->create(['title' => 'bare dept', 'lane' => 'ops']);
    $triage = $svc->create(['title' => 'triage', 'lane' => 'ops:triage']);
    $billing = $svc->create(['title' => 'billing', 'lane' => 'ops:billing']);

    $matched = Task::inLane('ops:triage')->pluck('id')->all();

    expect($matched)->toBe([$triage->id])
        ->and($matched)->not->toContain($dept->id, $billing->id);
});

test('scopeInLane(Lane::NONE) and scopeUnrouted() both mean the no-department lane', function () {
    $svc = app(DispatchTaskService::class);
    $unrouted = $svc->create(['title' => 'unrouted']);
    $routed = $svc->create(['title' => 'routed', 'lane' => 'ops']);

    expect(Task::inLane(Lane::NONE)->pluck('id')->all())->toBe([$unrouted->id])
        ->and(Task::unrouted()->pluck('id')->all())->toBe([$unrouted->id])
        ->and(Task::inLane(Lane::NONE)->pluck('id')->all())->not->toContain($routed->id);
});

test('scopeInLanes matches an exact SET, with no department-vs-sub-lane expansion', function () {
    $svc = app(DispatchTaskService::class);
    $dept = $svc->create(['title' => 'bare dept', 'lane' => 'ops']);
    $triage = $svc->create(['title' => 'triage', 'lane' => 'ops:triage']);
    $support = $svc->create(['title' => 'support', 'lane' => 'support']);

    // Naming only the sub-lane must NOT pull in its bare department.
    $matched = Task::inLanes(['ops:triage', 'support'])->pluck('id')->all();

    expect($matched)->toEqualCanonicalizing([$triage->id, $support->id])
        ->and($matched)->not->toContain($dept->id);
});

// --- writes: create with a lane (service / agent API / CLI / batch / report) --

test('DispatchTaskService::create() stores an explicit lane attribute as-is (the raw persistence path — validation lives at each write SURFACE, not here)', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops']);

    expect($task->lane)->toBe('ops');
});

test('dispatch:add --lane= stores a valid lane locally', function () {
    bindFakeLaneResolver();

    Artisan::call('dispatch:add', ['title' => 'filed into ops', '--lane' => 'ops']);

    $task = Task::where('title', 'filed into ops')->firstOrFail();
    expect($task->lane)->toBe('ops');
});

test('dispatch:add --lane= with an invalid lane fails locally and mints NO task', function () {
    bindFakeLaneResolver();

    $exit = Artisan::call('dispatch:add', ['title' => 'never filed', '--lane' => 'not-a-lane']);

    expect($exit)->not->toBe(0)
        ->and(Task::where('title', 'never filed')->exists())->toBeFalse();
});

test('dispatch:add --lane= --remote forwards the RAW value — the local (likely inert) resolver never blocks it', function () {
    // Deliberately NOT binding a fake resolver: the local box's binding is the
    // shipped NullLaneResolver, which would reject every real lane. A --remote
    // call must still be able to carry one, because the AUTHORITATIVE
    // (production) host validates it server-side.
    config([
        'dispatch.agent.remote.url' => 'https://agent.example.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => sys_get_temp_dir().'/dispatch-lanes-test-'.uniqid().'.json',
    ]);
    seedAgentToken();
    Http::fake([
        'agent.example.test/*' => Http::response(['task' => ['code' => 'TASK-910', 'title' => 'remote', 'lane' => 'ops']], 201),
    ]);

    $exit = Artisan::call('dispatch:add', ['title' => 'remote', '--lane' => 'ops', '--remote' => true]);

    expect($exit)->toBe(0);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/dispatch/agent/add')
        && ($request->data()['lane'] ?? null) === 'ops');
});

test('agent API add stores a valid lane and 422s (minting no task) on an invalid one', function () {
    bindFakeLaneResolver();
    $token = laneAgentToken();

    $ok = $this->withToken($token)->postJson('api/dispatch/agent/add', [
        'title' => 'agent-filed', 'lane' => 'support',
    ])->assertCreated();

    expect(Task::where('code', $ok->json('task.code'))->firstOrFail()->lane)->toBe('support');

    $this->withToken($token)->postJson('api/dispatch/agent/add', [
        'title' => 'rejected', 'lane' => 'nope',
    ])->assertStatus(422);

    expect(Task::where('title', 'rejected')->exists())->toBeFalse();
});

test('batch add sets a lane SILENTLY (no lane_change event) and fails the WHOLE batch on an invalid lane', function () {
    bindFakeLaneResolver();

    $out = app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'ref' => 'a1', 'title' => 'batch-filed', 'lane' => 'ops:billing'],
    ]);

    $task = Task::where('code', $out['results'][0]['code'])->firstOrFail();
    expect($task->lane)->toBe('ops:billing')
        ->and($task->comments()->where('event_type', TaskComment::EVENT_LANE_CHANGE)->count())->toBe(0);

    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'a', 'lane' => 'ops'],
        ['op' => 'add', 'title' => 'b', 'lane' => 'not-a-lane'],
    ]))->toThrow(InvalidArgumentException::class);

    // The WHOLE batch rolled back — not even the first (valid) op persisted.
    expect(Task::where('title', 'a')->exists())->toBeFalse();
});

test('DispatchTask::report() carries a valid lane and DROPS (never fails) an invalid one', function () {
    bindFakeLaneResolver();
    config(['dispatch.reporter.throttle_seconds' => 0]);

    $task = \Sgrjr\Dispatch\Facades\DispatchTask::report('a report', ['lane' => 'ops']);
    expect($task->lane)->toBe('ops');

    $dropped = \Sgrjr\Dispatch\Facades\DispatchTask::report('another report', ['lane' => 'not-a-lane']);
    expect($dropped)->not->toBeNull()   // the report itself is never lost
        ->and($dropped->lane)->toBeNull();
});

// --- batch update: tri-state + lane_change event -------------------------

test('batch update: lane ABSENT leaves an existing lane untouched', function () {
    bindFakeLaneResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops']);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'status' => 'in_progress'],
    ]);

    expect($task->fresh()->lane)->toBe('ops')
        ->and($task->fresh()->comments()->where('event_type', TaskComment::EVENT_LANE_CHANGE)->count())->toBe(0);
});

test('batch update: lane null/"" clears it to the no-department lane and records lane_change', function () {
    bindFakeLaneResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops']);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'lane' => null],
    ]);

    $fresh = $task->fresh();
    expect($fresh->lane)->toBeNull();

    $event = $fresh->comments()->where('event_type', TaskComment::EVENT_LANE_CHANGE)->firstOrFail();
    expect($event->meta['from'])->toBe('ops')
        ->and($event->meta['to'])->toBeNull();
});

test('batch update: setting a lane on an unrouted task records lane_change with from=null', function () {
    bindFakeLaneResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'lane' => 'support'],
    ]);

    $fresh = $task->fresh();
    expect($fresh->lane)->toBe('support');

    $event = $fresh->comments()->where('event_type', TaskComment::EVENT_LANE_CHANGE)->firstOrFail();
    expect($event->meta['from'])->toBeNull()
        ->and($event->meta['to'])->toBe('support');
});

test('batch update: an invalid lane fails the WHOLE batch, naming the operation, and nothing persists', function () {
    bindFakeLaneResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'status' => 'open']);

    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'status' => 'in_progress', 'lane' => 'nope'],
    ]))->toThrow(InvalidArgumentException::class);

    expect($task->fresh()->status)->toBe('open')
        ->and($task->fresh()->lane)->toBeNull();
});

// --- claimForUser (R15) --------------------------------------------------

test('claimForUser: an unrouted task auto-joins the user\'s single lane', function () {
    bindFakeLaneResolver(lanesByUser: [301 => ['support']]);
    $user = dispatchMakeUser(301);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'status' => 'open']);

    $claimed = app(DispatchTaskService::class)->claimForUser($task, $user);

    expect($claimed->lane)->toBe('support')
        ->and($claimed->assignee_user_id)->toBe(301);
});

test('claimForUser: reduces to the MOST-SPECIFIC lane when the user holds a bare department AND exactly one of its sub-lanes', function () {
    bindFakeLaneResolver(lanesByUser: [302 => ['ops', 'ops:triage']]);
    $user = dispatchMakeUser(302);
    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    $claimed = app(DispatchTaskService::class)->claimForUser($task, $user);

    // 'ops:triage' wins over the bare 'ops' — reads as ONE lane, not two.
    expect($claimed->lane)->toBe('ops:triage');
});

test('claimForUser: throws a "pick a lane" error when the user holds two genuinely ambiguous lanes', function () {
    bindFakeLaneResolver(lanesByUser: [303 => ['ops:triage', 'ops:billing']]);
    $user = dispatchMakeUser(303);
    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    expect(fn () => app(DispatchTaskService::class)->claimForUser($task, $user))
        ->toThrow(InvalidArgumentException::class);

    expect($task->fresh()->lane)->toBeNull()
        ->and($task->fresh()->assignee_user_id)->toBeNull();
});

test('claimForUser: an explicit $lane in the user\'s lanesFor() is honored', function () {
    bindFakeLaneResolver(lanesByUser: [304 => ['ops:triage', 'ops:billing']]);
    $user = dispatchMakeUser(304);
    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    $claimed = app(DispatchTaskService::class)->claimForUser($task, $user, 'ops:billing');

    expect($claimed->lane)->toBe('ops:billing');
});

test('claimForUser: an explicit $lane NOT in the user\'s lanesFor() throws', function () {
    bindFakeLaneResolver(lanesByUser: [305 => ['support']]);
    $user = dispatchMakeUser(305);
    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    expect(fn () => app(DispatchTaskService::class)->claimForUser($task, $user, 'ops'))
        ->toThrow(InvalidArgumentException::class);
});

test('claimForUser: an ALREADY-routed task keeps its lane unchanged, regardless of $lane', function () {
    bindFakeLaneResolver(lanesByUser: [306 => ['support']]);
    $user = dispatchMakeUser(306);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops']);

    $claimed = app(DispatchTaskService::class)->claimForUser($task, $user);

    expect($claimed->lane)->toBe('ops')
        ->and($claimed->assignee_user_id)->toBe(306);
});

test('claimForUser: a user who works NO lanes at all still claims — the task stays unrouted (inert-compatible)', function () {
    bindFakeLaneResolver(); // no lanesByUser entries at all
    $user = dispatchMakeUser(307);
    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    $claimed = app(DispatchTaskService::class)->claimForUser($task, $user);

    expect($claimed->lane)->toBeNull()
        ->and($claimed->assignee_user_id)->toBe(307);
});

test('claimForUser records a claimed event AND a lane_change event when it joins a lane', function () {
    bindFakeLaneResolver(lanesByUser: [308 => ['support']]);
    $user = dispatchMakeUser(308);
    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    app(DispatchTaskService::class)->claimForUser($task, $user);

    expect($task->fresh()->comments()->where('event_type', TaskComment::EVENT_CLAIMED)->count())->toBe(1)
        ->and($task->fresh()->comments()->where('event_type', TaskComment::EVENT_LANE_CHANGE)->count())->toBe(1);
});

// --- routeToLane (R15) ----------------------------------------------------

test('routeToLane: an admin (canRoute) may route ANY task into ANY valid lane, clearing the assignee', function () {
    bindFakeLaneResolver(adminIds: [401]);
    $admin = dispatchMakeUser(401);
    $someoneElse = dispatchMakeUser(402);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'support', 'assignee_user_id' => $someoneElse->id]);

    $routed = app(DispatchTaskService::class)->routeToLane($task, 'ops:billing', $admin);

    expect($routed->lane)->toBe('ops:billing')
        ->and($routed->assignee_user_id)->toBeNull();
});

test('routeToLane: a lane MEMBER may pull an UNROUTED task into their OWN lane', function () {
    bindFakeLaneResolver(lanesByUser: [403 => ['support']]);
    $member = dispatchMakeUser(403);
    $task = app(DispatchTaskService::class)->create(['title' => 'x']); // unrouted

    $routed = app(DispatchTaskService::class)->routeToLane($task, 'support', $member);

    expect($routed->lane)->toBe('support');
});

test('routeToLane: a NON-member is refused routing an unrouted task', function () {
    bindFakeLaneResolver(lanesByUser: [404 => ['ops']]); // works 'ops', not 'support'
    $user = dispatchMakeUser(404);
    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    expect(fn () => app(DispatchTaskService::class)->routeToLane($task, 'support', $user))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    expect($task->fresh()->lane)->toBeNull();
});

test('routeToLane: a non-admin member is refused RE-routing an ALREADY-routed task, even into their own lane', function () {
    bindFakeLaneResolver(lanesByUser: [405 => ['support']]);
    $member = dispatchMakeUser(405);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops']); // already routed

    expect(fn () => app(DispatchTaskService::class)->routeToLane($task, 'support', $member))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    expect($task->fresh()->lane)->toBe('ops');
});

test('routeToLane: an invalid lane throws BEFORE any authorization check', function () {
    bindFakeLaneResolver(adminIds: [406]);
    $admin = dispatchMakeUser(406);
    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    expect(fn () => app(DispatchTaskService::class)->routeToLane($task, 'not-a-lane', $admin))
        ->toThrow(InvalidArgumentException::class);
});

test('routeToLane records a lane_change event on a real change, none on a no-op re-route', function () {
    bindFakeLaneResolver(adminIds: [407]);
    $admin = dispatchMakeUser(407);
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops']);

    app(DispatchTaskService::class)->routeToLane($task->fresh(), 'ops', $admin);
    expect($task->fresh()->comments()->where('event_type', TaskComment::EVENT_LANE_CHANGE)->count())->toBe(0);

    app(DispatchTaskService::class)->routeToLane($task->fresh(), 'support', $admin);
    expect($task->fresh()->comments()->where('event_type', TaskComment::EVENT_LANE_CHANGE)->count())->toBe(1);
});

// --- agent claim never sets a lane ---------------------------------------

test('DispatchTaskService::claim() (the AGENT path) never sets a lane, even for an unrouted task', function () {
    bindFakeLaneResolver();
    app(DispatchTaskService::class)->create(['title' => 'x', 'status' => 'open']);

    $claimed = app(DispatchTaskService::class)->claim();

    expect($claimed->lane)->toBeNull();
});

test('agent claim\'s --lane is a FILTER only — it narrows candidates but never writes the claimed task\'s lane', function () {
    bindFakeLaneResolver();
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'wrong lane', 'status' => 'open', 'priority' => 'blocker', 'lane' => 'support']);
    $target = $svc->create(['title' => 'right lane', 'status' => 'open', 'priority' => 'low', 'lane' => 'ops']);

    $claimed = $svc->claim(filters: ['lane' => 'ops']);

    expect($claimed->code)->toBe($target->code)
        ->and($claimed->lane)->toBe('ops'); // unchanged by the claim itself
});

test('POST agent/claim honors ?lane as a candidate filter', function () {
    bindFakeLaneResolver();
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'wrong lane', 'status' => 'open', 'priority' => 'blocker', 'lane' => 'support']);
    $target = $svc->create(['title' => 'right lane', 'status' => 'open', 'priority' => 'low', 'lane' => 'ops']);

    $token = laneAgentToken();

    $this->withToken($token)->postJson('api/dispatch/agent/claim', ['lane' => 'ops'])
        ->assertOk()
        ->assertJsonPath('task.code', $target->code)
        ->assertJsonPath('task.lane', 'ops');
});

// --- capture stamps dispatch.capture.lane --------------------------------

test('the capture endpoint stamps dispatch.capture.lane onto a new task when valid', function () {
    bindFakeLaneResolver();
    config(['dispatch.capture.lane' => 'ops:triage']);

    $response = $this
        ->actingAs(dispatchMakeUser(500))
        ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
        ->postJson('/dispatch/capture', ['title' => 'stamped capture']);

    $response->assertCreated();
    expect(Task::where('title', 'stamped capture')->firstOrFail()->lane)->toBe('ops:triage');
});

test('the capture endpoint ignores an invalid dispatch.capture.lane — the capture still succeeds, unstamped', function () {
    bindFakeLaneResolver();
    config(['dispatch.capture.lane' => 'not-a-real-lane']);

    $response = $this
        ->actingAs(dispatchMakeUser(501))
        ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
        ->postJson('/dispatch/capture', ['title' => 'unstamped capture']);

    $response->assertCreated();
    expect(Task::where('title', 'unstamped capture')->firstOrFail()->lane)->toBeNull();
});

test('with no dispatch.capture.lane configured, captures are simply unstamped', function () {
    bindFakeLaneResolver();
    config(['dispatch.capture.lane' => null]);

    $response = $this
        ->actingAs(dispatchMakeUser(502))
        ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
        ->postJson('/dispatch/capture', ['title' => 'default capture']);

    $response->assertCreated();
    expect(Task::where('title', 'default capture')->firstOrFail()->lane)->toBeNull();
});

// --- presenter fields (summary vs full) ----------------------------------

test('TaskPresenter: `lane` is on the summary shape; `lane_label` is full-only', function () {
    bindFakeLaneResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'lane' => 'ops:billing']);

    $summary = TaskPresenter::toArray($task);
    expect($summary)->toHaveKey('lane')
        ->and($summary['lane'])->toBe('ops:billing')
        ->and($summary)->not->toHaveKey('lane_label');

    $full = TaskPresenter::toArray($task, true);
    expect($full['lane'])->toBe('ops:billing')
        ->and($full['lane_label'])->toBe('Ops · Billing');
});

test('TaskPresenter: an unrouted task presents lane=null and lane_label=null on the full shape', function () {
    bindFakeLaneResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    $full = TaskPresenter::toArray($task, true);

    expect($full['lane'])->toBeNull()
        ->and($full['lane_label'])->toBeNull();
});

test('schema() documents `lane`/`lane_label`, the batch op field, and EVENT_LANE_CHANGE', function () {
    $schema = TaskPresenter::schema();

    expect($schema['summary'])->toHaveKey('lane')
        ->and($schema['full_adds'])->toHaveKey('lane_label')
        ->and($schema['batch']['op'])->toHaveKey('lane')
        ->and($schema['event_types'])->toContain(TaskComment::EVENT_LANE_CHANGE);
});

// --- Board UI (Livewire) ---------------------------------------------------

test('TaskBoard swimlanes group by the `lane` COLUMN — "No department" first — once a real LaneResolver is bound', function () {
    bindFakeLaneResolver();
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'ops work', 'status' => 'open', 'lane' => 'ops']);
    $svc->create(['title' => 'triage work', 'status' => 'open', 'lane' => 'ops:triage']);
    $svc->create(['title' => 'no lane', 'status' => 'open']);

    $board = Livewire::test(TaskBoard::class)->set('swimlanes', true);

    // "No department" (unrouted) leads; department lanes sort naturally after it.
    expect($board->viewData('lanes'))->toBe(['—', 'ops', 'ops:triage']);

    $html = $board->html();
    expect($html)->toContain('No department')
        ->and($html)->toContain('Ops · Triage');
});

test('TaskBoard swimlanes keep the pre-existing ELEVATED-LABEL grouping unchanged when the inert default LaneResolver is bound', function () {
    // No bindFakeLaneResolver() call — the shipped NullLaneResolver stays bound.
    $staff = dispatchMakeUser(2);
    $this->actingAs($staff);

    app(DispatchTaskService::class)->create(['title' => 'labeled', 'status' => 'open', 'lane' => 'ops'], ['area:accounts']);

    $board = Livewire::test(TaskBoard::class)->set('swimlanes', true);

    // Unchanged from today: grouped by the elevated LABEL, not the (inert) lane column.
    expect($board->viewData('lanes'))->toBe(['accounts']);
});

test('TaskShow shows the lane badge and the Claim/Route panel once a real LaneResolver is bound', function () {
    bindFakeLaneResolver(lanesByUser: [11 => ['support']]);
    $staff = dispatchMakeUser(11);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'status' => 'open']); // unrouted

    $html = Livewire::test(TaskShow::class, ['task' => $task])->html();

    expect($html)->toContain('No department')
        ->and($html)->toContain('Claim for me');
});

test('TaskShow hides the lane panel entirely when the inert default LaneResolver is bound', function () {
    $staff = dispatchMakeUser(12);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    $html = Livewire::test(TaskShow::class, ['task' => $task])->html();

    expect($html)->not->toContain('Claim for me');
});

test('TaskShow::claimForSelf() claims the task and auto-joins the single lane the user works', function () {
    bindFakeLaneResolver(lanesByUser: [13 => ['support']]);
    $staff = dispatchMakeUser(13);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'x', 'status' => 'open']);

    Livewire::test(TaskShow::class, ['task' => $task])
        ->call('claimForSelf')
        ->assertHasNoErrors();

    $fresh = $task->fresh();
    expect($fresh->lane)->toBe('support')
        ->and($fresh->assignee_user_id)->toBe(13);
});

test('TaskShow::claimForSelf() surfaces a "pick a lane" error on the picker field instead of throwing', function () {
    bindFakeLaneResolver(lanesByUser: [14 => ['ops:triage', 'ops:billing']]);
    $staff = dispatchMakeUser(14);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    Livewire::test(TaskShow::class, ['task' => $task])
        ->call('claimForSelf')
        ->assertHasErrors('claimLaneChoice');

    expect($task->fresh()->assignee_user_id)->toBeNull();
});

test('TaskShow::routeTask() lets an admin route a task into any lane', function () {
    bindFakeLaneResolver(adminIds: [15]);
    $admin = dispatchMakeUser(15);
    $this->actingAs($admin);

    $task = app(DispatchTaskService::class)->create(['title' => 'x']);

    Livewire::test(TaskShow::class, ['task' => $task])
        ->set('routeLaneChoice', 'ops:billing')
        ->call('routeTask')
        ->assertHasNoErrors();

    expect($task->fresh()->lane)->toBe('ops:billing');
});

// --- NullLaneResolver keeps everything inert ------------------------------

test('NullLaneResolver: isLane always false, every list is empty, canRoute is always false', function () {
    $resolver = new NullLaneResolver();
    $user = dispatchMakeUser(600);

    expect($resolver->isLane('ops'))->toBeFalse()
        ->and($resolver->label('ops'))->toBeNull()
        ->and($resolver->lanes())->toBe([])
        ->and($resolver->lanesFor($user))->toBe([])
        ->and($resolver->lanesManagedBy($user))->toBe([])
        ->and($resolver->memberIds('ops'))->toBe([])
        ->and($resolver->canRoute($user))->toBeFalse();
});

test('the SHIPPED default binding is NullLaneResolver — the feature is inert out of the box', function () {
    expect(app(LaneResolver::class))->toBeInstanceOf(NullLaneResolver::class);
});

test('with the inert default bound, every write surface rejects a non-null lane', function () {
    // No bindFakeLaneResolver() call — this exercises the real shipped default.
    $exit = Artisan::call('dispatch:add', ['title' => 'never filed', '--lane' => 'anything']);
    expect($exit)->not->toBe(0);

    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'nope', 'lane' => 'anything'],
    ]))->toThrow(InvalidArgumentException::class);

    // report() degrades instead of failing — the report survives, unstamped.
    config(['dispatch.reporter.throttle_seconds' => 0]);
    $task = \Sgrjr\Dispatch\Facades\DispatchTask::report('inert report', ['lane' => 'anything']);
    expect($task)->not->toBeNull()->and($task->lane)->toBeNull();
});

// --- the VISIBILITY pin (R14) --------------------------------------------

test('a task routed into the VIEWER\'S OWN lane is still invisible under GATE B participants-only visibility — lane never widens visibility', function () {
    bindFakeLaneResolver(lanesByUser: [701 => ['ops']]);
    $owner = dispatchMakeUser(700);
    $viewer = dispatchMakeUser(701); // works 'ops', same lane as the task below

    $task = app(DispatchTaskService::class)->create([
        'title' => 'private to owner',
        'submitter_user_id' => $owner->id,
        'visibility' => Task::VISIBILITY_PARTICIPANTS,
        'lane' => 'ops',
    ], [], $owner);

    $query = Task::query();
    app(DispatchGate::class)->scopeVisible($query, $viewer);

    expect($query->pluck('id')->all())->not->toContain($task->id);
});

test('VisibilityGates::apply() never references the lane column at all', function () {
    // A structural pin, not just a behavioral one: grep the ONE gate helper's
    // source for the column name so a future edit that starts reading `lane`
    // for a visibility decision trips this test even if the specific
    // participants-only scenario above doesn't happen to catch it.
    $source = file_get_contents((new ReflectionClass(\Sgrjr\Dispatch\Support\VisibilityGates::class))->getFileName());

    expect($source)->not->toContain("'lane'")
        ->and($source)->not->toContain('->lane');
});
