<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchBatchService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * The remote batch verb (§20) end to end: POST agent/batch behind a bearer
 * session, scope-gated `batch`, plus the `dispatch:batch --remote` client.
 * AgentApiTest covers the single verbs; this file is the batch memorialize path.
 */

beforeEach(fn () => dispatchFakeUsers());

/** Mint an approved session token, optionally scope-restricted. */
function batchAgentToken(?array $scopes = null): string
{
    static $approverId = 87000;
    $approverId++;

    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-remote', 'batch memorialize');
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser($approverId)->id, null, $scopes);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

test('POST batch applies a mixed add/update manifest and stamps agent attribution', function () {
    $existing = app(DispatchTaskService::class)->create(['title' => 'in flight', 'status' => 'open']);

    $token = batchAgentToken();

    $response = $this->withToken($token)->postJson('api/dispatch/agent/batch', [
        'operations' => [
            ['op' => 'add', 'ref' => 'x1', 'title' => 'filed in batch', 'type' => 'bug', 'labels' => ['area:api']],
            ['op' => 'update', 'code' => $existing->code, 'status' => 'in_progress',
             'comments' => [['body' => 'started this one']]],
        ],
    ])->assertOk()
        ->assertJsonPath('applied', true)
        ->assertJsonPath('summary.tasks_created', 1)
        ->assertJsonPath('summary.tasks_updated', 1)
        ->assertJsonPath('results.0.ref', 'x1');

    $newCode = $response->json('results.0.code');
    $new = Task::where('code', $newCode)->firstOrFail();

    // Attribution: new task carries agent origin in context; the appended comment
    // carries agent meta with a null author, mirroring the single verbs.
    expect($new->context['agent']['agent_name'])->toBe('claude-remote')
        ->and($existing->fresh()->status)->toBe('in_progress');

    $note = $existing->fresh()->comments()->where('event_type', TaskComment::EVENT_COMMENT)->firstOrFail();
    expect($note->user_id)->toBeNull()
        ->and($note->meta['agent_name'])->toBe('claude-remote');
});

test('a session scoped without `batch` is forbidden (403)', function () {
    $token = batchAgentToken(['next', 'add']);

    $this->withToken($token)->postJson('api/dispatch/agent/batch', [
        'operations' => [['op' => 'add', 'title' => 'nope']],
    ])->assertStatus(403);

    expect(Task::count())->toBe(0);
});

test('POST batch dry_run reports without persisting', function () {
    $token = batchAgentToken();

    $this->withToken($token)->postJson('api/dispatch/agent/batch', [
        'dry_run' => true,
        'operations' => [['op' => 'add', 'title' => 'phantom']],
    ])->assertOk()
        ->assertJsonPath('applied', false)
        ->assertJsonPath('dry_run', true)
        ->assertJsonPath('summary.tasks_created', 1);

    expect(Task::count())->toBe(0);
});

test('POST batch over the op cap is rejected (422) and writes nothing', function () {
    config(['dispatch.agent.batch.max_operations' => 2]);

    $token = batchAgentToken();

    $ops = [
        ['op' => 'add', 'title' => 'one'],
        ['op' => 'add', 'title' => 'two'],
        ['op' => 'add', 'title' => 'three'],
    ];

    $this->withToken($token)->postJson('api/dispatch/agent/batch', ['operations' => $ops])
        ->assertStatus(422);

    expect(Task::count())->toBe(0);
});

test('POST batch with a malformed op 422s with the offending index and rolls back', function () {
    $token = batchAgentToken();

    $this->withToken($token)->postJson('api/dispatch/agent/batch', [
        'operations' => [
            ['op' => 'add', 'title' => 'good'],
            ['op' => 'update', 'code' => 'TASK-MISSING', 'status' => 'done'],
        ],
    ])->assertStatus(422);

    expect(Task::count())->toBe(0);
});

test('POST batch requires a valid bearer', function () {
    $this->postJson('api/dispatch/agent/batch', ['operations' => []])->assertStatus(401);
});

// --- dispatch:batch --remote ------------------------------------------------

test('dispatch:batch --remote posts the manifest operations to the agent API', function () {
    config([
        'dispatch.agent.remote.url' => 'https://agent.example.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => $tokenPath = sys_get_temp_dir().'/dispatch-batch-remote-'.uniqid().'.json',
    ]);
    file_put_contents($tokenPath, json_encode(['token' => 'test-remote-token']));

    Http::fake([
        'agent.example.test/*' => Http::response([
            'applied' => true,
            'dry_run' => false,
            'summary' => ['tasks_created' => 1, 'tasks_updated' => 1, 'comments_added' => 0, 'statuses_changed' => 1],
            'results' => [
                ['ref' => 'x1', 'op' => 'add', 'code' => 'TASK-950', 'created' => true],
                ['op' => 'update', 'code' => 'TASK-042', 'status' => 'in_progress'],
            ],
        ], 200),
    ]);

    $path = sys_get_temp_dir().'/dispatch-batch-remote-manifest-'.uniqid().'.json';
    file_put_contents($path, json_encode(['operations' => [
        ['op' => 'add', 'ref' => 'x1', 'title' => 'filed in batch'],
        ['op' => 'update', 'code' => 'TASK-042', 'status' => 'in_progress'],
    ]]));

    $exit = Artisan::call('dispatch:batch', ['path' => $path, '--remote' => true, '--json' => true]);

    expect($exit)->toBe(0);
    $decoded = json_decode(Artisan::output(), true);
    expect($decoded['results'][0]['code'])->toBe('TASK-950');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/api/dispatch/agent/batch')
            && count($request->data()['operations']) === 2
            && $request->data()['operations'][0]['ref'] === 'x1';
    });

    expect(Task::count())->toBe(0); // never touches the local DB

    @unlink($path);
    @unlink($tokenPath);
});

/*
 * §18 W10 — `due_at` on batch ops. Before this wave the key passed validation
 * and was silently dropped: the endpoint answered 200 and the date never landed.
 */

test('POST batch applies due_at on both add and update ops (W10-1)', function () {
    $existing = app(DispatchTaskService::class)->create(['title' => 'set my review-by', 'status' => 'open']);

    $token = batchAgentToken();

    $response = $this->withToken($token)->postJson('api/dispatch/agent/batch', [
        'operations' => [
            ['op' => 'add', 'ref' => 'd1', 'title' => 'filed with a date', 'due_at' => '2026-08-15'],
            ['op' => 'update', 'code' => $existing->code, 'due_at' => '2026-09-01'],
        ],
    ])->assertOk();

    $new = Task::where('code', $response->json('results.0.code'))->firstOrFail();

    expect($new->due_at?->toDateString())->toBe('2026-08-15')
        ->and($existing->fresh()->due_at?->toDateString())->toBe('2026-09-01');

    // The CHANGE is memorialized (the creation is not), carrying agent meta so
    // the timeline says who moved the date.
    $event = $existing->fresh()->comments()->where('event_type', TaskComment::EVENT_COMMENT)->firstOrFail();
    expect($event->body)->toBe('Due date set to 2026-09-01.')
        ->and($event->user_id)->toBeNull()
        ->and($event->meta['agent_name'])->toBe('claude-remote')
        ->and($event->meta['due_at'])->toBe(['from' => null, 'to' => '2026-09-01']);
});

test('POST batch with an unparseable due_at 422s naming the operation and writes nothing (W10-1)', function () {
    $token = batchAgentToken();

    $response = $this->withToken($token)->postJson('api/dispatch/agent/batch', [
        'operations' => [
            ['op' => 'add', 'title' => 'good'],
            ['op' => 'add', 'title' => 'bad date', 'due_at' => 'not-a-real-date'],
        ],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('Operation 1')
        ->and($response->json('message'))->toContain('due_at');

    expect(Task::count())->toBe(0);
});

test('dispatch:batch --remote sends due_at through in the operations (W10-1)', function () {
    config([
        'dispatch.agent.remote.url' => 'https://agent.example.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => $tokenPath = sys_get_temp_dir().'/dispatch-batch-remote-'.uniqid().'.json',
    ]);
    file_put_contents($tokenPath, json_encode(['token' => 'test-remote-token']));

    Http::fake([
        'agent.example.test/*' => Http::response([
            'applied' => true,
            'dry_run' => false,
            'summary' => ['tasks_created' => 1, 'tasks_updated' => 1, 'comments_added' => 0, 'statuses_changed' => 0],
            'results' => [
                ['ref' => 'd1', 'op' => 'add', 'code' => 'TASK-951', 'created' => true],
                ['op' => 'update', 'code' => 'TASK-043', 'status' => 'open'],
            ],
        ], 200),
    ]);

    $path = sys_get_temp_dir().'/dispatch-batch-remote-manifest-'.uniqid().'.json';
    file_put_contents($path, json_encode(['operations' => [
        ['op' => 'add', 'ref' => 'd1', 'title' => 'filed with a date', 'due_at' => '2026-08-15'],
        ['op' => 'update', 'code' => 'TASK-043', 'due_at' => null],
    ]]));

    $exit = Artisan::call('dispatch:batch', ['path' => $path, '--remote' => true, '--json' => true]);

    expect($exit)->toBe(0);

    // The manifest travels verbatim — including the null that means "clear",
    // which a payload filter would have eaten on the way out.
    Http::assertSent(function ($request) {
        $ops = $request->data()['operations'];

        return str_contains($request->url(), '/api/dispatch/agent/batch')
            && $ops[0]['due_at'] === '2026-08-15'
            && array_key_exists('due_at', $ops[1])
            && $ops[1]['due_at'] === null;
    });

    expect(Task::count())->toBe(0); // never touches the local DB

    @unlink($path);
    @unlink($tokenPath);
});

/*
 * W9-1(b)/(c) — the WHOLE-manifest byte guard. The per-comment cap bounds one
 * field; nothing bounded the sum, so a manifest of individually-legal ops could
 * still die at the web server's body limit, BELOW the app, where no dispatch
 * error can reach the caller.
 */

test('a manifest over the payload cap is refused with a message naming the size (W9-1b)', function () {
    config(['dispatch.agent.batch.max_payload_bytes' => 4096]);

    $ops = [];
    for ($i = 0; $i < 8; $i++) {
        // Each op is individually legal — only the SUM crosses the cap, which is
        // exactly the case the per-comment guard could not catch.
        $ops[] = ['op' => 'add', 'title' => "task {$i}", 'description' => str_repeat('x', 1024)];
    }

    expect(fn () => app(DispatchBatchService::class)->apply($ops))
        ->toThrow(InvalidArgumentException::class);

    try {
        app(DispatchBatchService::class)->apply($ops);
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('over the 4096-byte limit')
            ->and($e->getMessage())->toContain('Split it')
            // re-submits being safe is what makes "split it" actionable advice
            ->and($e->getMessage())->toContain('dedupe');
    }

    // Nothing was written — the guard runs before any op is applied.
    expect(Task::count())->toBe(0);
});

test('the payload cap is checked before the op-count cap and before any write (W9-1b)', function () {
    config(['dispatch.agent.batch.max_payload_bytes' => 512]);

    $ops = [['op' => 'add', 'title' => str_repeat('t', 2048)]];

    expect(fn () => app(DispatchBatchService::class)->apply($ops, [], null, true))
        ->toThrow(InvalidArgumentException::class);

    // Even a --dry-run must fail on size: dry-run is a rollback-to-observe
    // transaction, so it genuinely executes the writes it discards.
    expect(Task::count())->toBe(0);
});

test('max_payload_bytes=0 disables the whole-manifest guard (W9-1b)', function () {
    config(['dispatch.agent.batch.max_payload_bytes' => 0]);

    $outcome = app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'uncapped', 'description' => str_repeat('x', 5000)],
    ]);

    expect($outcome['summary']['tasks_created'])->toBe(1);
});

test('POST batch answers an oversized manifest with a 422 naming the limit, not a 500 (W9-1b)', function () {
    config(['dispatch.agent.batch.max_payload_bytes' => 2048]);
    $token = batchAgentToken();

    $response = $this->withToken($token)->postJson('api/dispatch/agent/batch', [
        'operations' => [['op' => 'add', 'title' => 'big', 'description' => str_repeat('x', 4096)]],
    ]);

    // The whole point of the wave: a size problem must SAY it is a size problem.
    $response->assertStatus(422);
    expect($response->json('message'))->toContain('byte limit');
});
