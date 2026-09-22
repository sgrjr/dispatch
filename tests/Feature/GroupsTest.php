<?php

use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Livewire\TaskShow;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Notifications\TaskUpdate;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\Groups;
use Sgrjr\Dispatch\Support\MailNotifier;

/*
 * W13-4: config-defined groups/teams as assignees. The assignee VALUE stays
 * singular (`assignee_group`, mutually exclusive with assignee_user_id) but
 * resolves to a member collection for notification fan-out and GATE A
 * visibility.
 */

test('Groups resolves names, members (by id and email, case-insensitive), and a user\'s memberships', function () {
    $alice = dispatchMakeUser(1, ['email' => 'Alice@Staff.test']);
    $bob = dispatchMakeUser(2, ['email' => 'bob@staff.test']);
    dispatchMakeUser(3, ['email' => 'carol@staff.test']);

    config(['dispatch.groups' => [
        'it' => ['alice@staff.test', 2],
        'accounts' => ['carol@staff.test', 'ghost@nowhere.test'],
    ]]);

    expect(Groups::names())->toBe(['it', 'accounts']);
    expect(Groups::exists('it'))->toBeTrue();
    expect(Groups::exists('legal'))->toBeFalse();

    // Members by email (case-insensitive) + by id; unknown entries drop silently.
    expect(Groups::members('it')->pluck('id')->all())->toEqualCanonicalizing([1, 2]);
    expect(Groups::members('accounts')->pluck('id')->all())->toBe([3]);
    expect(Groups::members(null))->toHaveCount(0);

    expect(Groups::namesFor($alice))->toBe(['it']);
    expect(Groups::namesFor($bob))->toBe(['it']);
});

test('assigning a task to a team stores the singular group value, memorializes, and notifies every member (W13-4)', function () {
    $staff = dispatchMakeUser(1, ['email' => 'lead@staff.test']);
    $alice = dispatchMakeUser(2, ['email' => 'alice@staff.test']);
    $bob = dispatchMakeUser(3, ['email' => 'bob@staff.test']);
    $this->actingAs($staff);

    config(['dispatch.groups' => ['it' => ['alice@staff.test', 'bob@staff.test']]]);

    $task = app(DispatchTaskService::class)->create(['title' => 'Team-owned work', 'priority' => 'blocker']);

    Notification::fake();

    Livewire::test(TaskShow::class, ['task' => $task])
        ->set('assignee_choice', 'group:it')
        ->call('saveMeta')
        ->assertHasNoErrors();

    $fresh = $task->fresh();
    expect($fresh->assignee_group)->toBe('it');
    expect($fresh->assignee_user_id)->toBeNull();
    expect($fresh->assigneeLabel())->toBe('Team it');

    // Both members hear; the acting user does not (they're not a member).
    Notification::assertSentTo([$alice, $bob], TaskUpdate::class);
    Notification::assertNotSentTo([$staff], TaskUpdate::class);

    // Memorialized through the same assignee-change machinery.
    expect($fresh->comments()->where('event_type', TaskComment::EVENT_ASSIGNEE_CHANGE)->count())->toBe(1);

    // Reassigning to a person clears the group (mutual exclusivity).
    Livewire::test(TaskShow::class, ['task' => $fresh])
        ->set('assignee_choice', (string) $alice->id)
        ->call('saveMeta')
        ->assertHasNoErrors();

    $fresh = $task->fresh();
    expect($fresh->assignee_user_id)->toBe($alice->id);
    expect($fresh->assignee_group)->toBeNull();
});

test('a no-longer-configured team errors instead of silently storing an unroutable assignment (W13-4)', function () {
    $staff = dispatchMakeUser(1);
    $this->actingAs($staff);

    $task = app(DispatchTaskService::class)->create(['title' => 'Stale team target']);

    Livewire::test(TaskShow::class, ['task' => $task])
        ->set('assignee_choice', 'group:disbanded')
        ->call('saveMeta')
        ->assertHasErrors('assignee_choice');

    expect($task->fresh()->assignee_group)->toBeNull();
});

test('group members are GATE A participants: a participants-only task assigned to their team is visible (W13-4 + W13-5)', function () {
    dispatchMakeUser(7, ['email' => 'member@staff.test']);

    config(['dispatch.groups' => ['it' => ['member@staff.test']]]);

    $tasks = app(DispatchTaskService::class);
    $teamTask = $tasks->create(['title' => 'Circle via team', 'visibility' => 'participants', 'submitter_user_id' => 99, 'assignee_group' => 'it']);
    $otherTask = $tasks->create(['title' => 'Not my circle', 'visibility' => 'participants', 'submitter_user_id' => 99, 'assignee_group' => 'legal']);

    /** @var AuthenticatableUser $member */
    $member = new class extends AuthenticatableUser {};
    $member->id = 7;
    $member->email = 'member@staff.test';

    $taskModel = config('dispatch.models.task');
    $visible = app(DispatchGate::class)->scopeVisible($taskModel::query(), $member)->pluck('id')->all();

    expect($visible)->toContain($teamTask->id);
    expect($visible)->not->toContain($otherTask->id);
});

test('a status change on a team-assigned task notifies the members (W13-4)', function () {
    $actor = dispatchMakeUser(1, ['email' => 'actor@staff.test']);
    $member = dispatchMakeUser(2, ['email' => 'member@staff.test']);

    config(['dispatch.groups' => ['it' => ['member@staff.test']]]);

    $task = app(DispatchTaskService::class)->create(['title' => 'Team status ping', 'assignee_group' => 'it', 'priority' => 'blocker']);
    $task->submitter_user_id = null;
    $task->save();

    Notification::fake();

    (new MailNotifier())->taskStatusChanged($task->fresh(), 'open', 'done', $actor);

    Notification::assertSentTo([$member], TaskUpdate::class);
    Notification::assertNotSentTo([$actor], TaskUpdate::class);
});

test('the presented JSON carries assignee_group additively (W13-4)', function () {
    config(['dispatch.groups' => ['it' => []]]);

    $task = app(DispatchTaskService::class)->create(['title' => 'Presented', 'assignee_group' => 'it']);

    $summary = \Sgrjr\Dispatch\Support\TaskPresenter::toArray($task->fresh());

    expect($summary['assignee_group'])->toBe('it');
    expect($summary['assignee'])->toBeNull();
});
