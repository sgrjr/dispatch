<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Events\ApprovalDecided;
use Sgrjr\Dispatch\Events\ApprovalRequested;
use Sgrjr\Dispatch\Exceptions\ApprovalTaskLocked;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\ApprovalTasks;

/*
 * TASK-1950: the "Approval requested" task is a CARRIER, not the record.
 * `dispatch.approvals.channel = event` files no task; the host delivers the
 * request from ApprovalRequested and decides it with approveItem()/denyItem(),
 * which keep the task's guards. Both events fire on either channel.
 */

beforeEach(function () {
    dispatchFakeUsers();
    config(['dispatch.agent.approval_lane' => 'marketing:developer']);
});

function channelRequest(): array
{
    $payload = app(AgentSessionService::class)->request('claude-remote', 'work the backlog', [], '10.0.0.9');

    return [AgentSession::where('public_id', $payload['public_id'])->firstOrFail(), $payload];
}

test('the default channel is still the task: one is filed and ApprovalRequested carries it', function () {
    Event::fake([ApprovalRequested::class]);

    [$session, $payload] = channelRequest();

    $task = app(ApprovalTasks::class)->openTaskFor('agent_session', $session->public_id);
    expect($task)->not->toBeNull()
        ->and($payload['approval_task'])->toBe($task->code)
        ->and($payload['approval_channel'])->toBe('task');
    Event::assertDispatched(ApprovalRequested::class, fn ($e) => $e->item->approvalId() === $session->public_id && $e->task?->is($task));
});

test('the event channel files NO task and announces the request instead', function () {
    config(['dispatch.approvals.channel' => 'event']);
    Event::fake([ApprovalRequested::class]);

    [$session, $payload] = channelRequest();

    expect(Task::query()->count())->toBe(0)
        ->and($payload['approval_task'])->toBeNull()
        ->and($payload['approval_channel'])->toBe('event');
    Event::assertDispatched(ApprovalRequested::class, fn ($e) => $e->item->approvalId() === $session->public_id && $e->task === null);
});

test('approveItem decides the session exactly like the task does, and announces it', function () {
    config(['dispatch.approvals.channel' => 'event']);
    [$session] = channelRequest();
    Event::fake([ApprovalDecided::class]);
    $this->freezeSecond();

    app(ApprovalTasks::class)->approveItem($session, dispatchMakeUser(601), ['ttl' => '28800']);

    $session->refresh();
    expect($session->status)->toBe('approved')
        ->and((int) $session->approved_by_user_id)->toBe(601)
        ->and($session->expires_at->timestamp)->toBe(now()->addSeconds(28800)->timestamp);
    Event::assertDispatched(ApprovalDecided::class, fn ($e) => $e->id === $session->public_id && $e->outcome === 'approved' && $e->userId === 601);
});

test('denyItem denies the session and announces it', function () {
    config(['dispatch.approvals.channel' => 'event']);
    [$session] = channelRequest();
    Event::fake([ApprovalDecided::class]);

    app(ApprovalTasks::class)->denyItem($session, dispatchMakeUser(602));

    expect($session->refresh()->status)->toBe('denied');
    Event::assertDispatched(ApprovalDecided::class, fn ($e) => $e->outcome === 'denied' && $e->userId === 602);
});

test('⛔ a late click on a decided or lapsed request is refused', function () {
    config(['dispatch.approvals.channel' => 'event']);
    [$decided] = channelRequest();
    app(ApprovalTasks::class)->denyItem($decided, dispatchMakeUser(603));

    expect(fn () => app(ApprovalTasks::class)->approveItem($decided->refresh(), dispatchMakeUser(604)))->toThrow(ApprovalTaskLocked::class);
    expect($decided->refresh()->status)->toBe('denied');

    [$lapsed] = channelRequest();
    $this->travel(16)->minutes();

    expect(fn () => app(ApprovalTasks::class)->approveItem($lapsed, dispatchMakeUser(607)))->toThrow(ApprovalTaskLocked::class);
    expect($lapsed->refresh()->status)->toBe('pending');
});

test('⛔ a length the request never offered is refused', function () {
    config(['dispatch.approvals.channel' => 'event']);
    [$session] = channelRequest();

    expect(fn () => app(ApprovalTasks::class)->approveItem($session, dispatchMakeUser(605), ['ttl' => '604800']))->toThrow(ApprovalTaskLocked::class);
    expect($session->refresh()->status)->toBe('pending');
});

test('⛔ a non-staff user cannot approve an item', function () {
    config(['dispatch.approvals.channel' => 'event']);
    [$session] = channelRequest();
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

    expect(fn () => app(ApprovalTasks::class)->approveItem($session, dispatchMakeUser(606)))->toThrow(ApprovalTaskLocked::class);
    expect($session->refresh()->status)->toBe('pending');
});

test('an undecided request that lapses is announced as EXPIRED on the event channel', function () {
    config(['dispatch.approvals.channel' => 'event']);
    [$session] = channelRequest();
    Event::fake([ApprovalDecided::class]);

    $this->travel(16)->minutes();
    app(AgentSessionService::class)->prune();

    expect($session->refresh()->status)->toBe('expired');
    Event::assertDispatched(ApprovalDecided::class, fn ($e) => $e->id === $session->public_id && $e->outcome === 'expired' && $e->userId === null);
});
