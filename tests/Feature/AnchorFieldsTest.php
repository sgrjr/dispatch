<?php

use Illuminate\Support\Facades\Artisan;
use Sgrjr\Dispatch\Contracts\TopicResolver;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchBatchService;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\Anchor;
use Sgrjr\Dispatch\Support\TaskPresenter;
use Sgrjr\Dispatch\Support\VisibilityGates;

/*
 * TASK-995 — the ANCHOR fields: `topic_type`/`topic_id` (+ the stamped
 * `topic_account_key` rollup) for what a task is ABOUT, `origin_type`/
 * `origin_id` for where it came FROM, and `conversation_id` for its home
 * conversation/arc. See the anchor contract (rulings R10/R16/R16a/R20) for
 * the full spec; this file exercises the package-side implementation.
 */

beforeEach(fn () => dispatchFakeUsers());

/**
 * A fake TopicResolver: `account` ids resolve to an "ACCT-<id>" account key
 * plus a canned label/url; any other type has no account (accountKey null).
 */
function bindFakeTopicResolver(): void
{
    app()->singleton(TopicResolver::class, fn () => new class implements TopicResolver
    {
        public function resolve(string $type, string $id): mixed
        {
            return ['type' => $type, 'id' => $id];
        }

        public function label(string $type, string $id): string
        {
            return "Account {$id}";
        }

        public function url(string $type, string $id): ?string
        {
            return "https://example.test/{$type}/{$id}";
        }

        public function accountKey(string $type, string $id): ?string
        {
            return $type === 'account' ? "ACCT-{$id}" : null;
        }
    });
}

/** Mint an approved agent session token (mirrors AgentApiTest's helper). */
function anchorAgentToken(?array $scopes = null): string
{
    static $approverId = 91000;
    $approverId++;

    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-remote', 'anchor fields');
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser($approverId)->id, null, $scopes);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

// --- Anchor::parse -----------------------------------------------------

test('Anchor::parse splits on the FIRST colon only — an id may itself contain colons', function () {
    expect(Anchor::parse('account:0402100000001'))->toBe(['account', '0402100000001'])
        ->and(Anchor::parse('task:TASK-042:extra'))->toBe(['task', 'TASK-042:extra']);
});

test('Anchor::parse treats a bare type (no colon) as that type with a null id', function () {
    expect(Anchor::parse('phone'))->toBe(['phone', null]);
});

test('Anchor::parse rejects an invalid type', function () {
    expect(fn () => Anchor::parse('Bad-Type:1'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Anchor::parse('1abc:1'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Anchor::parse(''))->toThrow(InvalidArgumentException::class);
});

// --- setTopic() / topic_account_key -------------------------------------

test('setTopic stamps topic_account_key via the bound TopicResolver', function () {
    bindFakeTopicResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'about an account']);

    $task->setTopic('account', '0402100000001');

    expect($task->topic_type)->toBe('account')
        ->and($task->topic_id)->toBe('0402100000001')
        ->and($task->topic_account_key)->toBe('ACCT-0402100000001');
});

test('setTopic restamps topic_account_key on change', function () {
    bindFakeTopicResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'about an account']);

    $task->setTopic('account', 'A');
    expect($task->topic_account_key)->toBe('ACCT-A');

    $task->setTopic('account', 'B');
    expect($task->topic_account_key)->toBe('ACCT-B');
});

test('setTopic clears topic_id and topic_account_key on a null type', function () {
    bindFakeTopicResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'about an account']);

    $task->setTopic('account', 'A');
    $task->setTopic(null, null);

    expect($task->topic_type)->toBeNull()
        ->and($task->topic_id)->toBeNull()
        ->and($task->topic_account_key)->toBeNull();
});

test('a topic type the resolver has no account for stamps a null topic_account_key', function () {
    bindFakeTopicResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'about a plan']);

    $task->setTopic('plan', '99');

    expect($task->topic_type)->toBe('plan')
        ->and($task->topic_account_key)->toBeNull();
});

test('the saving hook restamps topic_account_key for a direct attribute assignment, not just setTopic()', function () {
    bindFakeTopicResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'raw assignment']);

    $task->topic_type = 'account';
    $task->topic_id = 'Z9';
    $task->save();

    expect($task->fresh()->topic_account_key)->toBe('ACCT-Z9');
});

// --- $task->topic / $task->origin (Anchor accessors, R10) ----------------

test('$task->topic is null with no topic set, and a lazily-resolved Anchor otherwise', function () {
    bindFakeTopicResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'plain']);

    expect($task->topic)->toBeNull();

    $task->setTopic('account', '42');
    $anchor = $task->topic;

    expect($anchor)->toBeInstanceOf(Anchor::class)
        ->and($anchor->type)->toBe('account')
        ->and($anchor->id)->toBe('42')
        ->and($anchor->label())->toBe('Account 42')
        ->and($anchor->url())->toBe('https://example.test/account/42');
});

test('$task->origin is null with no origin set', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'plain origin']);

    expect($task->origin)->toBeNull();

    $task->setOrigin('phone', null);

    expect($task->origin)->toBeInstanceOf(Anchor::class)
        ->and($task->origin->type)->toBe('phone')
        ->and($task->origin->id)->toBeNull();
});

// --- Origin immutability (R16a) ------------------------------------------

test('origin may be written while unset', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'fresh']);

    $task->setOrigin('phone', null);
    $task->save();

    expect($task->fresh()->origin_type)->toBe('phone')
        ->and($task->fresh()->origin_id)->toBeNull();
});

test('setOrigin() rejects a change once origin_type is set — the model-level guard', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'has an origin']);
    $task->setOrigin('exception', '1');
    $task->save();

    expect(fn () => $task->setOrigin('phone', null))->toThrow(LogicException::class);
    // Rejected in-memory too — the attempted change never lands.
    expect($task->origin_type)->toBe('exception');
});

test('a direct attribute change to a set origin is rejected by the saving hook — the belt', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'belt test']);
    $task->setOrigin('exception', '1');
    $task->save();

    $fresh = $task->fresh();
    $fresh->origin_type = 'phone';

    expect(fn () => $fresh->save())->toThrow(LogicException::class);
    expect($task->fresh()->origin_type)->toBe('exception');
});

test('re-saving an origin with its own unchanged value is not rejected', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'idempotent origin']);
    $task->setOrigin('exception', '1');
    $task->save();

    $fresh = $task->fresh();
    $fresh->setOrigin('exception', '1'); // same value — a no-op change

    expect(fn () => $fresh->save())->not->toThrow(LogicException::class);
});

test('a batch update op cannot change an already-set origin — a clear validation error', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'batch origin']);
    $task->setOrigin('exception', '1');
    $task->save();

    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'origin' => 'phone'],
    ]))->toThrow(InvalidArgumentException::class);

    expect($task->fresh()->origin_type)->toBe('exception');
});

test('a batch update op CAN set origin when it was previously unset', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'batch origin unset']);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'origin' => 'phone'],
    ]);

    expect($task->fresh()->origin_type)->toBe('phone')
        ->and($task->fresh()->origin_id)->toBeNull();
});

test('agent API batch rejects an origin change on an existing task (422)', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'agent origin']);
    $task->setOrigin('exception', '1');
    $task->save();

    $token = anchorAgentToken();

    $this->withToken($token)->postJson('api/dispatch/agent/batch', [
        'operations' => [
            ['op' => 'update', 'code' => $task->code, 'origin' => 'phone'],
        ],
    ])->assertStatus(422);

    expect($task->fresh()->origin_type)->toBe('exception');
});

// --- Batch: topic/origin/conversation shorthand + tri-state --------------

test('a batch add op sets topic/origin/conversation via the shorthand strings', function () {
    bindFakeTopicResolver();

    $out = app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'batch anchors', 'topic' => 'account:555', 'origin' => 'phone', 'conversation_id' => 7],
    ]);

    $task = Task::where('code', $out['results'][0]['code'])->firstOrFail();
    expect($task->topic_type)->toBe('account')
        ->and($task->topic_id)->toBe('555')
        ->and($task->topic_account_key)->toBe('ACCT-555')
        ->and($task->origin_type)->toBe('phone')
        ->and($task->origin_id)->toBeNull()
        ->and($task->conversation_id)->toBe(7);
});

test('a batch add op accepts explicit topic_type/topic_id as an alternative to the shorthand', function () {
    bindFakeTopicResolver();

    $out = app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'explicit fields', 'topic_type' => 'account', 'topic_id' => '9'],
    ]);

    $task = Task::where('code', $out['results'][0]['code'])->firstOrFail();
    expect($task->topic_type)->toBe('account')
        ->and($task->topic_id)->toBe('9');
});

test('a batch add op cannot set topic_account_key directly — it is not a recognized input', function () {
    $out = app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'no cheating', 'topic_account_key' => 'HACKED'],
    ]);

    $task = Task::where('code', $out['results'][0]['code'])->firstOrFail();
    expect($task->topic_account_key)->toBeNull();
});

test('a batch update op edits topic (tri-state) and conversation_id, clearing on null', function () {
    bindFakeTopicResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'batch update anchors']);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'topic' => 'account:1', 'conversation_id' => 3],
    ]);
    expect($task->fresh()->topic_type)->toBe('account')
        ->and($task->fresh()->topic_account_key)->toBe('ACCT-1')
        ->and($task->fresh()->conversation_id)->toBe(3);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'topic' => null, 'conversation_id' => null],
    ]);
    expect($task->fresh()->topic_type)->toBeNull()
        ->and($task->fresh()->topic_account_key)->toBeNull()
        ->and($task->fresh()->conversation_id)->toBeNull();
});

test('a batch update op omitting topic/origin/conversation leaves them untouched', function () {
    bindFakeTopicResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'untouched anchors']);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'topic' => 'account:1', 'conversation_id' => 3],
    ]);
    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'status' => 'in_progress'],
    ]);

    expect($task->fresh()->topic_type)->toBe('account')
        ->and($task->fresh()->conversation_id)->toBe(3)
        ->and($task->fresh()->status)->toBe('in_progress');
});

test('a batch op rejects a malformed topic shorthand up front, before any write', function () {
    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'bad topic', 'topic' => 'Bad-Type:1'],
    ]))->toThrow(InvalidArgumentException::class);

    expect(Task::count())->toBe(0);
});

// --- Query scopes ----------------------------------------------------------

test('aboutTopic/fromOrigin/inConversation/aboutAccount scopes filter as documented', function () {
    bindFakeTopicResolver();

    $a = app(DispatchTaskService::class)->create(['title' => 'a']);
    $a->setTopic('account', '1');
    $a->setOrigin('phone', null);
    $a->conversation_id = 10;
    $a->save();

    $b = app(DispatchTaskService::class)->create(['title' => 'b']);
    $b->setTopic('account', '2');
    $b->save();

    expect(Task::query()->aboutTopic('account', '1')->pluck('code')->all())->toBe([$a->code])
        ->and(Task::query()->aboutTopic('account')->pluck('code')->sort()->values()->all())
            ->toBe(collect([$a->code, $b->code])->sort()->values()->all())
        ->and(Task::query()->fromOrigin('phone')->pluck('code')->all())->toBe([$a->code])
        ->and(Task::query()->inConversation(10)->pluck('code')->all())->toBe([$a->code])
        ->and(Task::query()->aboutAccount('ACCT-1')->pluck('code')->all())->toBe([$a->code]);
});

test('Task::conversation() throws a clear LogicException when unconfigured', function () {
    config(['dispatch.models.conversation' => null]);
    $task = app(DispatchTaskService::class)->create(['title' => 'no conversation model']);

    expect(fn () => $task->conversation())->toThrow(LogicException::class);
});

// --- TaskPresenter: summary vs full shapes --------------------------------

test('TaskPresenter summary carries the flat anchor fields but no resolved label/url', function () {
    bindFakeTopicResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'presenter summary']);
    $task->setTopic('account', '9');
    $task->setOrigin('phone', null);
    $task->conversation_id = 4;
    $task->save();

    $data = TaskPresenter::toArray($task->fresh(), false);

    expect($data['topic_type'])->toBe('account')
        ->and($data['topic_id'])->toBe('9')
        ->and($data['topic_account_key'])->toBe('ACCT-9')
        ->and($data['origin_type'])->toBe('phone')
        ->and($data['origin_id'])->toBeNull()
        ->and($data['conversation_id'])->toBe(4)
        ->and($data)->not->toHaveKey('topic_label')
        ->and($data)->not->toHaveKey('topic_url');
});

test('TaskPresenter full shape adds resolved topic_label/topic_url/origin_label/origin_url', function () {
    bindFakeTopicResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'presenter full']);
    $task->setTopic('account', '9');
    $task->save();

    $data = TaskPresenter::toArray($task->fresh(), true);

    expect($data['topic_label'])->toBe('Account 9')
        ->and($data['topic_url'])->toBe('https://example.test/account/9')
        // no origin set — degrades to null, not an error.
        ->and($data['origin_label'])->toBeNull()
        ->and($data['origin_url'])->toBeNull();
});

// --- Agent API: add + queue filters ----------------------------------------

test('agent add stamps topic/origin/conversation and ignores a topic_account_key sent in the request', function () {
    bindFakeTopicResolver();
    $token = anchorAgentToken();

    $response = $this->withToken($token)->postJson('api/dispatch/agent/add', [
        'title' => 'filed about an account',
        'topic' => 'account:123',
        'origin' => 'phone',
        'conversation' => 5,
        'topic_account_key' => 'HACKED', // not a validated field — must be dropped
    ])->assertCreated();

    $task = Task::where('code', $response->json('task.code'))->firstOrFail();

    expect($task->topic_type)->toBe('account')
        ->and($task->topic_id)->toBe('123')
        ->and($task->topic_account_key)->toBe('ACCT-123') // stamped by the resolver, never the request
        ->and($task->origin_type)->toBe('phone')
        ->and($task->conversation_id)->toBe(5);
});

test('agent add 422s on a malformed topic and mints no task', function () {
    $token = anchorAgentToken();

    $this->withToken($token)->postJson('api/dispatch/agent/add', [
        'title' => 'never filed',
        'topic' => 'Bad-Type:1',
    ])->assertStatus(422);

    expect(Task::count())->toBe(0);
});

test('agent queue filters by topic', function () {
    bindFakeTopicResolver();
    $svc = app(DispatchTaskService::class);

    $match = $svc->create(['title' => 'about acct', 'status' => 'open']);
    $match->setTopic('account', '1');
    $match->save();
    $svc->create(['title' => 'unrelated', 'status' => 'open']);

    $token = anchorAgentToken();

    $tasks = $this->withToken($token)->getJson('api/dispatch/agent/queue?topic=account:1')
        ->assertOk()
        ->json('tasks');

    expect(collect($tasks)->pluck('code')->all())->toBe([$match->code]);
});

test('agent queue filters by origin', function () {
    $svc = app(DispatchTaskService::class);
    $viaPhone = $svc->create(['title' => 'phoned in', 'status' => 'open']);
    $viaPhone->setOrigin('phone', null);
    $viaPhone->save();
    $svc->create(['title' => 'unrelated', 'status' => 'open']);

    $token = anchorAgentToken();

    $tasks = $this->withToken($token)->getJson('api/dispatch/agent/queue?origin=phone')
        ->assertOk()->json('tasks');

    expect(collect($tasks)->pluck('code')->all())->toBe([$viaPhone->code]);
});

test('agent queue filters by conversation', function () {
    $svc = app(DispatchTaskService::class);
    $inConvo = $svc->create(['title' => 'in the arc', 'status' => 'open']);
    $inConvo->conversation_id = 42;
    $inConvo->save();
    $svc->create(['title' => 'unrelated', 'status' => 'open']);

    $token = anchorAgentToken();

    $tasks = $this->withToken($token)->getJson('api/dispatch/agent/queue?conversation=42')
        ->assertOk()->json('tasks');

    expect(collect($tasks)->pluck('code')->all())->toBe([$inConvo->code]);
});

test('agent queue filters by topic_account', function () {
    bindFakeTopicResolver();
    $svc = app(DispatchTaskService::class);
    $withAcct = $svc->create(['title' => 'acct scope', 'status' => 'open']);
    $withAcct->setTopic('account', '2');
    $withAcct->save();
    $svc->create(['title' => 'other', 'status' => 'open']);

    $token = anchorAgentToken();

    $tasks = $this->withToken($token)->getJson('api/dispatch/agent/queue?topic_account=ACCT-2')
        ->assertOk()->json('tasks');

    expect(collect($tasks)->pluck('code')->all())->toBe([$withAcct->code]);
});

test('agent queue with a malformed topic filter 422s', function () {
    $token = anchorAgentToken();

    $this->withToken($token)->getJson('api/dispatch/agent/queue?topic=Bad-Type:1')
        ->assertStatus(422);
});

// --- CLI: dispatch:add / dispatch:queue / dispatch:find -------------------

test('dispatch:add --topic/--origin/--conversation set the anchor fields locally', function () {
    bindFakeTopicResolver();

    $exit = Artisan::call('dispatch:add', [
        'title' => 'cli anchors',
        '--topic' => 'account:321',
        '--origin' => 'phone',
        '--conversation' => '9',
        '--json' => true,
    ]);
    expect($exit)->toBe(0);

    $decoded = json_decode(Artisan::output(), true);
    $task = Task::where('code', $decoded['code'])->firstOrFail();

    expect($task->topic_type)->toBe('account')
        ->and($task->topic_id)->toBe('321')
        ->and($task->topic_account_key)->toBe('ACCT-321')
        ->and($task->origin_type)->toBe('phone')
        ->and($task->conversation_id)->toBe(9);
});

test('dispatch:add rejects a malformed --topic before writing anything', function () {
    $exit = Artisan::call('dispatch:add', ['title' => 'bad anchor', '--topic' => 'Bad-Type:1']);

    expect($exit)->toBe(1)
        ->and(Task::count())->toBe(0);
});

test('dispatch:queue --topic filters the local list', function () {
    bindFakeTopicResolver();
    $svc = app(DispatchTaskService::class);
    $match = $svc->create(['title' => 'queue topic match', 'status' => 'open']);
    $match->setTopic('account', '1');
    $match->save();
    $svc->create(['title' => 'queue topic miss', 'status' => 'open']);

    Artisan::call('dispatch:queue', ['--topic' => 'account:1', '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toHaveCount(1)
        ->and($decoded[0]['code'])->toBe($match->code);
});

test('dispatch:find --topic-account filters across all statuses', function () {
    bindFakeTopicResolver();
    $svc = app(DispatchTaskService::class);
    $done = $svc->create(['title' => 'archived about the account', 'status' => 'done']);
    $done->setTopic('account', '5');
    $done->save();

    Artisan::call('dispatch:find', ['term' => 'account', '--topic-account' => 'ACCT-5', '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toHaveCount(1)
        ->and($decoded[0]['code'])->toBe($done->code);
});

// --- Source-label backfill (the migration) ---------------------------------

test('the migration backfills origin_type from legacy source:* labels, idempotently, without clobbering an existing origin', function () {
    $svc = app(DispatchTaskService::class);

    $exceptionTask = $svc->create(['title' => 'legacy exception'], ['source:exception']);
    $contactTask = $svc->create(['title' => 'legacy contact'], ['source:contact-form']);
    $emailTask = $svc->create(['title' => 'legacy email'], ['source:email']);
    $untouched = $svc->create(['title' => 'no matching label'], ['area:api']);

    $alreadyStamped = $svc->create(['title' => 'already has an origin'], ['source:exception']);
    $alreadyStamped->setOrigin('task', 'TASK-001');
    $alreadyStamped->save();

    // Re-run the migration's up() (which is column-guarded but re-runs the
    // backfill unconditionally) now that there's data to backfill — at the
    // normal migrate time these tasks didn't exist yet.
    $migration = require __DIR__.'/../../database/migrations/2026_01_01_000020_add_anchor_fields_to_dispatch_tasks_table.php';
    $migration->up();

    expect($exceptionTask->fresh()->origin_type)->toBe('exception')
        ->and($contactTask->fresh()->origin_type)->toBe('contact_form')
        ->and($emailTask->fresh()->origin_type)->toBe('email')
        ->and($untouched->fresh()->origin_type)->toBeNull()
        ->and($alreadyStamped->fresh()->origin_type)->toBe('task'); // never clobbered

    // Idempotent — running it again changes nothing further.
    $migration->up();
    expect($exceptionTask->fresh()->origin_type)->toBe('exception')
        ->and($alreadyStamped->fresh()->origin_type)->toBe('task');
});

// --- Visibility pin ---------------------------------------------------------

test('a task whose topic_account_key equals a customer account is still invisible to that customer', function () {
    bindFakeTopicResolver();
    $customer = dispatchMakeUser(90001);

    // Staff-authored, ABOUT this customer's account — not submitted by them,
    // not public. If topic_account_key ever widened visibility this would
    // leak straight to the customer; VisibilityGates never consults it.
    $task = app(DispatchTaskService::class)->create(['title' => 'about the customer account', 'is_public' => false]);
    $task->setTopic('account', '90001');
    $task->save();

    expect($task->topic_account_key)->toBe('ACCT-90001');

    $visibleCodes = VisibilityGates::apply(Task::query(), $customer, false)->pluck('code')->all();

    expect($visibleCodes)->not->toContain($task->code);
});
