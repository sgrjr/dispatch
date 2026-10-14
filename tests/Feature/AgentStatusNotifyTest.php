<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Http\Middleware\AuthenticateAgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchBatchService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * TASK-1748: a status change made through an agent session reaches the bound
 * notifier, like every human status change. Before, AgentController::done and
 * batch `update` ops saved the status and wrote the timeline event but never
 * called taskStatusChanged(), so a host's reactions (rings, Centerpoint's
 * Brutus release protocol) never ran for an agent's move.
 */

/** A bearer token for an approved session (the full grantable scope set). */
function statusNotifyAgentToken(): string
{
    $svc = app(\Sgrjr\Dispatch\Services\AgentSessionService::class);
    $req = $svc->request('claude-remote', 'status notify test');
    $session = \Sgrjr\Dispatch\Models\AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser(91748)->id);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

beforeEach(function () {
    dispatchFakeUsers();

    $this->spy = new class implements DispatchNotifier
    {
        /** @var array<int,array{code:string,from:string,to:string,actor:mixed,session:?string}> */
        public array $moves = [];

        public function taskCreated(Task $task): void {}

        public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
        {
            // What a host can see at this moment: the agent session on the request.
            $session = request()->attributes->get(AuthenticateAgentSession::ATTRIBUTE);
            $this->moves[] = ['code' => $task->code, 'from' => $from, 'to' => $to, 'actor' => $actor, 'session' => $session?->public_id];
        }

        public function taskCommented(Task $task, TaskComment $comment): void {}

        public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void {}
    };
    app()->singleton(DispatchNotifier::class, fn () => $this->spy);
});

test('an agent done tells the notifier, with a null actor and the session on the request', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Agent moves this', 'status' => 'verifying']);
    $token = statusNotifyAgentToken();
    $this->spy->moves = []; // approving the session moved its own approval task

    $this->withToken($token)->postJson('api/dispatch/agent/done', ['code' => $task->code, 'status' => 'open'])->assertOk();

    expect($this->spy->moves)->toHaveCount(1)
        ->and($this->spy->moves[0])->toMatchArray(['code' => $task->code, 'from' => 'verifying', 'to' => 'open', 'actor' => null])
        ->and($this->spy->moves[0]['session'])->toBeString();
});

test('an agent done that does not change the status tells nobody', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Already open', 'status' => 'open']);
    $token = statusNotifyAgentToken();
    $this->spy->moves = []; // approving the session moved its own approval task

    $this->withToken($token)->postJson('api/dispatch/agent/done', ['code' => $task->code, 'status' => 'open'])->assertOk();

    expect($this->spy->moves)->toBe([]);
});

test('a batch update that moves a status tells the notifier after the commit', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Batch moves this', 'status' => 'triage']);
    $token = statusNotifyAgentToken();
    $this->spy->moves = []; // approving the session moved its own approval task

    $this->withToken($token)->postJson('api/dispatch/agent/batch', ['operations' => [
        ['op' => 'update', 'code' => $task->code, 'status' => 'open'],
    ]])->assertOk();

    expect($this->spy->moves)->toHaveCount(1)
        ->and($this->spy->moves[0])->toMatchArray(['code' => $task->code, 'from' => 'triage', 'to' => 'open']);
});

test('a quiet batch and a dry run tell nobody', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Stays quiet', 'status' => 'triage']);
    $batch = app(DispatchBatchService::class);

    $batch->apply([['op' => 'update', 'code' => $task->code, 'status' => 'open']], quiet: true);
    $batch->apply([['op' => 'update', 'code' => $task->code, 'status' => 'verifying']], dryRun: true);

    expect($this->spy->moves)->toBe([])
        ->and($task->fresh()->status)->toBe('open');
});
