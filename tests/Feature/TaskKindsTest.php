<?php

use Livewire\Livewire;
use Sgrjr\Dispatch\Exceptions\TaskActionRefused;
use Sgrjr\Dispatch\Exceptions\TaskKindLocked;
use Sgrjr\Dispatch\Livewire\TaskBoard;
use Sgrjr\Dispatch\Livewire\TaskShow;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\ApprovalTasks;
use Sgrjr\Dispatch\Services\DispatchBatchService;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Services\TaskActions;
use Sgrjr\Dispatch\Services\TaskKinds;
use Sgrjr\Dispatch\Tests\Fixtures\ChecklistKind;

/*
 * TASK-1188 — task kinds: a task defines its own controls.
 *   - a kind's action runs through the ONE action path and can close its task;
 *   - a hidden default control is absent (TaskShow, and `hides` on show);
 *   - a locked status refuses done / batch / drag / the agent API;
 *   - an agent can't perform a non-agentAllowed action (403);
 *   - an unregistered kind degrades to the defaults.
 * The approval kind (TASK-1021) is the first real one: ApprovalTasksTest stays
 * identical; here, its Approve runs through the action path.
 */

beforeEach(function () {
    dispatchFakeUsers();
    config(['dispatch.task_kinds.checklist' => ChecklistKind::class]);
});

function kindsAgentToken(array $scopes = ['show', 'perform', 'done', 'batch']): string
{
    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-kinds', 'work the backlog');
    $svc->approve(AgentSession::where('public_id', $req['public_id'])->firstOrFail(), dispatchMakeUser(700)->id, null, $scopes);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

// --- the marker and the registry ------------------------------------------------

test('a task IS of a kind when context.<key> is an array for a registered kind', function () {
    $task = ChecklistKind::file('Pre-flight');
    $plain = app(DispatchTaskService::class)->create(['title' => 'Plain']);

    expect(TaskKinds::keyFor($task))->toBe('checklist')
        ->and($task->kind())->toBeInstanceOf(ChecklistKind::class)
        ->and($plain->kind())->toBeNull()
        ->and(app(TaskActions::class)->describe($plain, dispatchMakeUser(701)))->toBeNull();
});

test('⛔ only the kind\'s own service may file, edit or remove its marker', function () {
    expect(fn () => app(DispatchTaskService::class)->create(['title' => 'Forged', 'context' => ['checklist' => ['ticks' => 9]]]))
        ->toThrow(TaskKindLocked::class);

    $task = ChecklistKind::file('Pre-flight');
    $task->context = ['checklist' => ['ticks' => 99]];
    expect(fn () => $task->save())->toThrow(TaskKindLocked::class);

    $task = $task->fresh();
    $task->context = [];
    expect(fn () => $task->save())->toThrow(TaskKindLocked::class);

    $plain = app(DispatchTaskService::class)->create(['title' => 'Plain']);
    $plain->context = ['checklist' => []];
    expect(fn () => $plain->save())->toThrow(TaskKindLocked::class);
});

// --- one action path ----------------------------------------------------------

test('a kind\'s action runs through the one path, is recorded, and can close its task', function () {
    $task = ChecklistKind::file('Pre-flight');
    $staff = dispatchMakeUser(702);
    $actions = app(TaskActions::class);

    expect($actions->perform($task, 'tick', $staff))->toBe('Ticked.')
        ->and($task->fresh()->context['checklist']['ticks'])->toBe(1);

    expect(fn () => $actions->perform($task->fresh(), 'sign_off', $staff, []))
        ->toThrow(TaskActionRefused::class, 'needs `note`');

    $message = $actions->perform($task->fresh(), 'sign_off', $staff, ['note' => 'All green', 'smuggled' => 'x']);
    expect($message)->toBe('Signed off: All green')
        ->and($task->fresh()->status)->toBe('done');

    $events = $task->comments()->where('event_type', TaskComment::EVENT_ACTION)->orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events[1]->meta)->toMatchArray(['kind' => 'checklist', 'action' => 'sign_off', 'input' => ['note' => 'All green']])
        ->and((int) $events[1]->user_id)->toBe(702);
});

test('describe() gives a surface the actions, the hidden defaults, the lock and the panel', function () {
    $task = ChecklistKind::file('Pre-flight');

    $view = app(TaskActions::class)->describe($task, dispatchMakeUser(703));
    expect($view['key'])->toBe('checklist')
        ->and(array_column($view['actions'], 'key'))->toBe(['tick', 'sign_off'])
        ->and($view['hides'])->toBe(['claim', 'assignee'])
        ->and($view['locks_status'])->toBeTrue()
        ->and($view['panel']['rows'][0])->toBe(['label' => 'Ticks', 'value' => '0']);

    // As an agent: only the agentAllowed action.
    $asAgent = app(TaskActions::class)->describe($task, null, true);
    expect(array_column($asAgent['actions'], 'key'))->toBe(['tick']);
});

// --- ⛔ the lock, on every path ---------------------------------------------------

test('⛔ a locked status refuses a plain save, dispatch:done, batch and a board drag', function () {
    $task = ChecklistKind::file('Pre-flight');

    $task->status = 'done';
    expect(fn () => $task->save())->toThrow(TaskKindLocked::class);

    $this->artisan('dispatch:done', ['code' => $task->code, '--local' => true])->assertFailed();

    expect(fn () => app(DispatchBatchService::class)->apply([['code' => $task->code, 'status' => 'done']]))
        ->toThrow(InvalidArgumentException::class);

    $this->actingAs(dispatchMakeUser(704));
    Livewire::test(TaskBoard::class)
        ->call('moveCard', $task->id, 'done', 0)
        ->assertSet('statusNotice', fn ($v) => is_string($v) && str_contains($v, 'checklist'));

    expect($task->fresh()->status)->toBe('open');
});

test('⛔ the agent API refuses a locked status on done, shows the kind, runs an agentAllowed action, and 403s a person\'s action', function () {
    $token = kindsAgentToken();
    $task = ChecklistKind::file('Pre-flight');

    $this->withToken($token)->postJson('api/dispatch/agent/done', ['code' => $task->code, 'status' => 'done'])->assertStatus(422);

    $shown = $this->withToken($token)->getJson('api/dispatch/agent/show/'.$task->code)->assertOk()->json('task.kind');
    expect($shown['key'])->toBe('checklist')
        ->and(array_column($shown['actions'], 'key'))->toBe(['tick'])
        ->and($shown['hides'])->toBe(['claim', 'assignee']);

    $this->withToken($token)->postJson('api/dispatch/agent/perform', ['code' => $task->code, 'action' => 'tick'])
        ->assertOk()
        ->assertJsonPath('message', 'Ticked.');

    $refused = $this->withToken($token)->postJson('api/dispatch/agent/perform', ['code' => $task->code, 'action' => 'sign_off', 'input' => ['note' => 'I say so']])
        ->assertStatus(403);
    expect($refused->json('message'))->toContain('for people only')
        ->and($task->fresh()->status)->toBe('open')
        ->and($task->fresh()->context['checklist']['ticks'])->toBe(1);
});

test('the perform scope gates the verb, and dispatch:perform runs agent-allowed actions only', function () {
    $token = kindsAgentToken(['show']);
    $task = ChecklistKind::file('Pre-flight');

    $this->withToken($token)->postJson('api/dispatch/agent/perform', ['code' => $task->code, 'action' => 'tick'])->assertStatus(403);

    $this->artisan('dispatch:perform', ['code' => $task->code, 'action' => 'tick', '--local' => true])->assertOk();
    $this->artisan('dispatch:perform', ['code' => $task->code, 'action' => 'sign_off', '--input' => ['note=ok'], '--local' => true])->assertFailed();

    expect($task->fresh()->context['checklist']['ticks'])->toBe(1)
        ->and($task->fresh()->status)->toBe('open');
});

// --- TaskShow ------------------------------------------------------------------

test('TaskShow renders the kind\'s actions and panel, hides what the kind hides, and runs an action', function () {
    $this->actingAs(dispatchMakeUser(705));
    $task = ChecklistKind::file('Pre-flight');
    $plain = app(DispatchTaskService::class)->create(['title' => 'Plain', 'status' => 'open']);

    Livewire::test(TaskShow::class, ['task' => $plain])
        ->assertSee('Assignee')
        ->assertDontSee('data-dispatch-kind', false);

    Livewire::test(TaskShow::class, ['task' => $task])
        ->assertSee('data-dispatch-kind="checklist"', false)
        ->assertSee('Sign off')
        ->assertSee('Ticks')
        ->assertDontSee('<label class="dispatch-label">Assignee</label>', false)
        ->call('performAction', 'sign_off')
        ->assertHasErrors('kindAction')
        ->set('actionInput.sign_off.note', 'Checked by hand')
        ->call('performAction', 'sign_off')
        ->assertHasNoErrors();

    expect($task->fresh()->status)->toBe('done');
});

// --- degrade --------------------------------------------------------------------

test('an unregistered kind degrades to the defaults: no actions, status free', function () {
    $task = ChecklistKind::file('Pre-flight');
    config(['dispatch.task_kinds' => ['approval' => \Sgrjr\Dispatch\Kinds\ApprovalKind::class]]);

    $fresh = $task->fresh();
    expect($fresh->kind())->toBeNull()
        ->and(app(TaskActions::class)->describe($fresh, dispatchMakeUser(706)))->toBeNull();

    $fresh->status = 'done';
    $fresh->save();
    expect($fresh->fresh()->status)->toBe('done');
});

// --- the first real kind: approvals ------------------------------------------------

test('approval: Approve runs through the action path for staff; agents are offered nothing', function () {
    config(['dispatch.agent.approval_lane' => 'marketing:developer']);
    $payload = app(AgentSessionService::class)->request('claude-asking', 'work', ['scopes' => ['next']]);
    $session = AgentSession::where('public_id', $payload['public_id'])->firstOrFail();
    $task = app(ApprovalTasks::class)->openTaskFor('agent_session', $session->public_id);

    $staff = dispatchMakeUser(707);
    $view = app(TaskActions::class)->describe($task, $staff);
    expect(array_column($view['actions'], 'key'))->toBe(['approve', 'deny'])
        ->and($view['hides'])->toBe(['status', 'assignee', 'claim', 'pass', 'ask'])
        ->and(collect($view['panel']['rows'])->firstWhere('emphasis', true)['value'])->toBe($session->user_code)
        ->and(app(TaskActions::class)->describe($task, null, true)['actions'])->toBe([]);

    expect(fn () => app(TaskActions::class)->perform($task, 'approve', null, [], true))
        ->toThrow(TaskActionRefused::class);

    app(TaskActions::class)->perform($task, 'approve', $staff, ['ttl' => '3600']);

    expect($session->refresh()->status)->toBe('approved')
        ->and($task->fresh()->status)->toBe('done')
        ->and(app(TaskActions::class)->describe($task->fresh(), $staff)['actions'])->toBe([]);
});
