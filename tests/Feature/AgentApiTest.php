<?php

use Livewire\Livewire;
use Sgrjr\Dispatch\Livewire\AgentSessions;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * The remote agent HTTP surface (§19/§20 Phase 2): session request -> human
 * approve -> poll-for-token -> the scoped verb loop (next/queue/show/claim/
 * add/note/done). AgentSessionCoreTest already covers the service/model/
 * middleware in isolation; this file drives the routes end to end.
 */

beforeEach(fn () => dispatchFakeUsers());

/**
 * Request, approve (optionally with an explicit scope list), and poll a
 * session through the SERVICE directly (not the HTTP surface, which is
 * covered by its own test below) — returns the delivered bearer token.
 */
function agentApiToken(?array $scopes = null): string
{
    static $nextApproverId = 89000;
    $nextApproverId++;

    $svc = app(AgentSessionService::class);

    $req = $svc->request('claude-remote', 'work the backlog');
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();

    $svc->approve($session, dispatchMakeUser($nextApproverId)->id, null, $scopes);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

test('POST session creates a pending session and returns the bootstrap payload', function () {
    $response = $this->postJson('api/dispatch/agent/session', [
        'agent_name' => 'claude-remote',
        'purpose' => 'work the backlog',
    ])->assertCreated()
        ->assertJsonStructure(['public_id', 'device_code', 'user_code', 'poll_interval', 'expires_at']);

    $publicId = $response->json('public_id');

    expect(AgentSession::where('public_id', $publicId)->where('status', 'pending')->exists())->toBeTrue();
});

test('approve then poll over HTTP delivers the bearer token exactly once', function () {
    $bootstrap = $this->postJson('api/dispatch/agent/session', ['agent_name' => 'claude-remote'])
        ->assertCreated()
        ->json();

    $session = AgentSession::where('public_id', $bootstrap['public_id'])->firstOrFail();
    app(AgentSessionService::class)->approve($session, dispatchMakeUser(801)->id);

    $pollUrl = 'api/dispatch/agent/session/'.$bootstrap['public_id'].'?device_code='.$bootstrap['device_code'];

    $first = $this->getJson($pollUrl)->assertOk()->json();
    expect($first['status'])->toBe('approved')
        ->and($first['token'])->toBeString();

    $second = $this->getJson($pollUrl)->assertOk()->json();
    expect($second['status'])->toBe('approved')
        ->and($second)->not->toHaveKey('token');
});

test('a bad public_id/device_code poll 404s', function () {
    $this->getJson('api/dispatch/agent/session/00000000-0000-0000-0000-000000000000?device_code=nope')
        ->assertStatus(404);
});

test('GET next with a valid bearer returns the highest-priority actionable task', function () {
    app(DispatchTaskService::class)->create(['title' => 'Do the thing', 'status' => 'open', 'priority' => 'high']);

    $token = agentApiToken();

    $this->withToken($token)->getJson('api/dispatch/agent/next')
        ->assertOk()
        ->assertJsonPath('task.title', 'Do the thing')
        ->assertJsonStructure(['task' => ['code', 'title', 'type', 'priority', 'status', 'labels', 'submitter', 'assignee']]);
});

test('GET next surfaces comment_count so an agent knows a task carries direction (GAP 2c)', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'has direction', 'status' => 'open', 'priority' => 'high']);
    $task->recordEvent(Sgrjr\Dispatch\Models\TaskComment::EVENT_COMMENT, dispatchMakeUser(88600)->id, [], 'do X first');
    $task->recordEvent(Sgrjr\Dispatch\Models\TaskComment::EVENT_CLAIMED, null, []); // system event — must not count

    $token = agentApiToken();

    $this->withToken($token)->getJson('api/dispatch/agent/next')
        ->assertOk()
        ->assertJsonPath('task.code', $task->code)
        ->assertJsonPath('task.comment_count', 1);
});

test('a revoked session gets a uniform 401 on a verb it was previously scoped for', function () {
    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-remote', null);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser(802)->id);
    $token = $svc->poll($req['public_id'], $req['device_code'])['token'];

    $svc->revoke($session);

    $this->withToken($token)->getJson('api/dispatch/agent/next')->assertStatus(401);
});

test('a session scoped only to next is forbidden from claim', function () {
    $token = agentApiToken(['next']);

    $this->withToken($token)->postJson('api/dispatch/agent/claim')->assertStatus(403);
});

test('POST claim marks a seeded open task in_progress and returns it', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Claim me', 'status' => 'open']);

    $token = agentApiToken();

    $this->withToken($token)->postJson('api/dispatch/agent/claim')
        ->assertOk()
        ->assertJsonPath('task.code', $task->code);

    expect($task->fresh()->status)->toBe('in_progress');
});

test('POST claim returns the FULL shape — description + comments — so an agent sees human direction (GAP 2a)', function () {
    $task = app(DispatchTaskService::class)->create([
        'title' => 'Claim me',
        'status' => 'open',
        'description' => 'Handle the after-tax coupon path.',
    ]);

    // A human plants direction as a comment BEFORE the agent claims — the exact
    // thing the summary shape (next/queue) hides.
    $author = dispatchMakeUser(88100);
    $task->recordEvent(Sgrjr\Dispatch\Models\TaskComment::EVENT_COMMENT, $author->id, [], 'Do the null-coupon case first.');

    $token = agentApiToken();

    $this->withToken($token)->postJson('api/dispatch/agent/claim')
        ->assertOk()
        ->assertJsonPath('task.code', $task->code)
        ->assertJsonPath('task.description', 'Handle the after-tax coupon path.')
        ->assertJsonStructure(['task' => ['description', 'context', 'comments' => [['id', 'event_type', 'body', 'author']]]])
        ->assertJsonFragment(['body' => 'Do the null-coupon case first.']);
});

test('POST add creates a task with a null submitter and stamps agent attribution in context', function () {
    $token = agentApiToken();

    $response = $this->withToken($token)->postJson('api/dispatch/agent/add', [
        'title' => 'Filed by an agent',
        'type' => 'bug',
    ])->assertCreated()
        ->assertJsonPath('task.title', 'Filed by an agent');

    $code = $response->json('task.code');

    $task = Sgrjr\Dispatch\Models\Task::where('code', $code)->firstOrFail();
    expect($task->submitter_user_id)->toBeNull()
        ->and($task->context['agent']['agent_name'])->toBe('claude-remote');
});

test('POST note appends a comment event authored by the agent (null user)', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Needs a note', 'status' => 'open']);

    $token = agentApiToken();

    $response = $this->withToken($token)->postJson('api/dispatch/agent/note', [
        'code' => $task->code,
        'body' => 'Investigated — root cause found.',
    ])->assertOk()
        ->assertJsonStructure(['task', 'comment_id']);

    $comment = Sgrjr\Dispatch\Models\TaskComment::findOrFail($response->json('comment_id'));

    expect($comment->user_id)->toBeNull()
        ->and($comment->event_type)->toBe(Sgrjr\Dispatch\Models\TaskComment::EVENT_COMMENT)
        ->and($comment->body)->toBe('Investigated — root cause found.')
        ->and($comment->meta['agent_name'])->toBe('claude-remote');
});

test('POST done moves the task to done and records a status_change event', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Ship it', 'status' => 'in_progress']);

    $token = agentApiToken();

    $this->withToken($token)->postJson('api/dispatch/agent/done', [
        'code' => $task->code,
        'commit' => 'abc123',
        'result' => ['tests' => 'green'],
    ])->assertOk()
        ->assertJsonPath('task.status', 'done');

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('done')
        ->and($fresh->context['result']['commit'])->toBe('abc123');

    $event = Sgrjr\Dispatch\Models\TaskComment::where('task_id', $task->id)
        ->where('event_type', Sgrjr\Dispatch\Models\TaskComment::EVENT_STATUS_CHANGE)
        ->firstOrFail();
    expect($event->user_id)->toBeNull()
        ->and($event->meta['to'])->toBe('done');
});

test('POST session/end revokes the callers own session; the token then 401s (GAP 5)', function () {
    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-remote', null);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser(88500)->id);
    $token = $svc->poll($req['public_id'], $req['device_code'])['token'];

    $this->withToken($token)->postJson('api/dispatch/agent/session/end')
        ->assertOk()
        ->assertJsonPath('ended', true)
        ->assertJsonPath('status', 'revoked')
        ->assertJsonPath('public_id', $req['public_id']);

    expect($session->fresh()->status)->toBe(AgentSession::STATUS_REVOKED);

    // The token is now dead — any further call (including end again) 401s.
    $this->withToken($token)->getJson('api/dispatch/agent/next')->assertStatus(401);
    $this->withToken($token)->postJson('api/dispatch/agent/session/end')->assertStatus(401);
});

test('session/end is not scope-gated — a next-only session can still end itself (GAP 5)', function () {
    $token = agentApiToken(['next']);   // scoped to `next` ONLY

    $this->withToken($token)->postJson('api/dispatch/agent/session/end')->assertOk();
});

test('session/end requires a valid bearer', function () {
    $this->postJson('api/dispatch/agent/session/end')->assertStatus(401);
});

test('the AgentSessions Livewire approve action approves a pending session for a staff user', function () {
    $staff = dispatchMakeUser(803);
    $this->actingAs($staff);

    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-remote', null);
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();

    Livewire::test(AgentSessions::class)
        ->call('approve', $session->id);

    $fresh = $session->fresh();
    expect($fresh->status)->toBe('approved')
        ->and($fresh->approved_by_user_id)->toBe($staff->id);
});
