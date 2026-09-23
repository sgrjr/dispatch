<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Exceptions\ApprovalTaskLocked;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\ApprovalTasks;
use Sgrjr\Dispatch\Services\DispatchBatchService;

/*
 * PU-2.10 / TASK-1021: "Approval requested", a task that times out. Agent-session
 * requests are the first client (R18/R19).
 *
 *   - a request FILES a task (lane, due = expiry, topic/origin = the request, high);
 *   - Approve / Deny on the task decides the request; deciding it on its own
 *     screen closes the task too (ONE closer);
 *   - an undecided request EXPIRES, and so does its task (declined, resolution
 *     expired: never read as a refusal);
 *   - ⛔ nothing else closes it: not a save, not dispatch:done, not the agent
 *     API, not a batch, and never a non-staff user.
 */

beforeEach(function () {
    dispatchFakeUsers();
    config(['dispatch.agent.approval_lane' => 'marketing:developer']);
});

/** @return array{0: AgentSession, 1: Task, 2: array} */
function approvalRequest(string $name = 'claude-remote'): array
{
    $payload = app(AgentSessionService::class)->request($name, 'work the backlog', ['scopes' => ['next', 'claim']], '10.0.0.9');
    $session = AgentSession::where('public_id', $payload['public_id'])->firstOrFail();
    $task = app(ApprovalTasks::class)->openTaskFor('agent_session', $session->public_id);

    return [$session, $task, $payload];
}

test('a session request files an "Approval requested" task: routed, due at the expiry, high, marked', function () {
    [$session, $task, $payload] = approvalRequest();

    expect($task)->not->toBeNull()
        ->and($payload['approval_task'])->toBe($task->code)
        ->and($task->title)->toBe('Approval requested: agent session for claude-remote')
        ->and($task->status)->toBe('open')
        ->and($task->priority)->toBe('high')
        ->and($task->lane)->toBe('marketing:developer')
        ->and($task->due_at->timestamp)->toBe($session->expires_at->timestamp)
        ->and($task->topic_type)->toBe('agent_session')
        ->and($task->topic_id)->toBe($session->public_id)
        ->and($task->context['approval'])->toMatchArray(['kind' => 'agent_session', 'id' => $session->public_id, 'state' => 'pending'])
        // The device-code check survives: the code is on the task.
        ->and($task->description)->toContain($session->user_code);
});

test('Approve on the task approves the session and closes the task as approved', function () {
    [$session, $task] = approvalRequest();
    $approver = dispatchMakeUser(501);

    app(ApprovalTasks::class)->approve($task, $approver, ['ttl' => 600, 'lane' => '']);

    $session->refresh();
    $task->refresh();
    expect($session->status)->toBe('approved')
        ->and((int) $session->approved_by_user_id)->toBe(501)
        ->and($task->status)->toBe('done')
        ->and($task->context['approval']['state'])->toBe('approved')
        ->and($task->context['result']['resolution'])->toBe('approved');
});

test('Deny on the task denies the session and closes the task as declined/denied', function () {
    [$session, $task] = approvalRequest();

    app(ApprovalTasks::class)->deny($task, dispatchMakeUser(502));

    expect($session->refresh()->status)->toBe('denied')
        ->and($task->refresh()->status)->toBe('declined')
        ->and($task->context['result']['resolution'])->toBe('denied');
});

test('ONE closer: approving on the Agent Sessions screen closes the task too', function () {
    [$session, $task] = approvalRequest();

    app(AgentSessionService::class)->approve($session, dispatchMakeUser(503)->id);

    expect($task->refresh()->status)->toBe('done')
        ->and($task->context['result']['resolution'])->toBe('approved');
});

test('an undecided request expires, and its task closes as EXPIRED, never as denied', function () {
    [$session, $task] = approvalRequest();

    $this->travel(16)->minutes();
    app(AgentSessionService::class)->prune();

    expect($session->refresh()->status)->toBe('expired')
        ->and($task->refresh()->status)->toBe('declined')
        ->and($task->context['result']['resolution'])->toBe('expired');
});

test('⛔ a plain status write on an approval task is refused', function () {
    [, $task] = approvalRequest();

    $task->status = 'done';
    expect(fn () => $task->save())->toThrow(ApprovalTaskLocked::class);
    expect($task->fresh()->status)->toBe('open');
});

test('⛔ an agent cannot close an approval task through the API', function () {
    [, $task] = approvalRequest('claude-asking');
    // A DIFFERENT, already-approved agent with the done scope. The asking
    // agent has no token until approved, so another agent is the real threat.
    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-other', 'work the backlog');
    $svc->approve(AgentSession::where('public_id', $req['public_id'])->firstOrFail(), dispatchMakeUser(505)->id, null, ['done']);
    $token = $svc->poll($req['public_id'], $req['device_code'])['token'];

    $response = $this->withToken($token)->postJson('api/dispatch/agent/done', ['code' => $task->code, 'status' => 'done'])
        ->assertStatus(422);

    // The LOCK refused it, not some unrelated validation error.
    expect($response->json('message'))->toContain('is an approval request')
        ->and($task->fresh()->status)->toBe('open');
});

test('⛔ a batch update cannot close an approval task', function () {
    [, $task] = approvalRequest();

    expect(fn () => app(DispatchBatchService::class)->apply([['code' => $task->code, 'status' => 'done']]))
        ->toThrow(InvalidArgumentException::class);
    expect($task->fresh()->status)->toBe('open');
});

test('⛔ nobody but the approval service can file a task carrying the marker', function () {
    expect(fn () => app(\Sgrjr\Dispatch\Services\DispatchTaskService::class)->create([
        'title' => 'Sneaky',
        'context' => ['approval' => ['kind' => 'agent_session', 'id' => 'x']],
    ]))->toThrow(ApprovalTaskLocked::class);
});

test('⛔ a non-staff user cannot approve', function () {
    [$session, $task] = approvalRequest();
    app()->singleton(DispatchGate::class, fn () => new class implements DispatchGate
    {
        public function isStaff(?Authenticatable $user): bool
        {
            return false;
        }

        public function canSeeAll(?Authenticatable $user): bool
        {
            return false;
        }

        public function scopeVisible(Builder $query, ?Authenticatable $user): Builder
        {
            return $query;
        }
    });

    expect(fn () => app(ApprovalTasks::class)->approve($task, dispatchMakeUser(504)))->toThrow(ApprovalTaskLocked::class);
    expect($session->refresh()->status)->toBe('pending')
        ->and($task->fresh()->status)->toBe('open');
});

test('filing is idempotent: one open approval task per request', function () {
    [$session, $task] = approvalRequest();

    $again = app(ApprovalTasks::class)->file($session);

    expect($again->id)->toBe($task->id);
});
