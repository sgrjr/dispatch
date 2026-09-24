<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Notification;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Notifications\TaskUpdate;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\MailNotifier;
use Sgrjr\Dispatch\Support\NullNotifier;

/**
 * DispatchNotifier is the 4th portability seam: fire-and-forget hooks fired
 * at task mutation points (create/status-change/comment/assign). These
 * tests exercise the binding wiring (a spy bound over the singleton), the
 * shipped no-op default, and the shipped mail-backed default's
 * never-throw guarantee.
 */

test('creating a task through the service fires the notifier taskCreated hook exactly once', function () {
    $spy = new class implements DispatchNotifier
    {
        public int $created = 0;

        public function taskCreated(Task $task): void
        {
            $this->created++;
        }

        public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
        {
            //
        }

        public function taskCommented(Task $task, TaskComment $comment): void
        {
            //
        }

        public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void
        {
            //
        }
    };

    app()->singleton(DispatchNotifier::class, fn () => $spy);

    $task = app(DispatchTaskService::class)->create(['title' => 'Something broke']);

    expect($task)->toBeInstanceOf(Task::class);
    expect($spy->created)->toBe(1);
});

test('NullNotifier methods run with no error and no side effects', function () {
    // Bind it as the active notifier too, so the service's own internal
    // taskCreated hook doesn't reach out to the (default) MailNotifier.
    app()->singleton(DispatchNotifier::class, fn () => new NullNotifier());

    $task = app(DispatchTaskService::class)->create(['title' => 'Quiet task']);
    $originalStatus = $task->status;

    $notifier = new NullNotifier();
    $notifier->taskCreated($task);
    $notifier->taskStatusChanged($task, 'open', 'in_progress', null);
    $notifier->taskCommented($task, new TaskComment(['body' => 'hi']));
    $notifier->taskAssigned($task, null, 5, null);

    expect($task->fresh()->status)->toBe($originalStatus);
});

test('MailNotifier::taskCreated never throws when the task has no submitter', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Orphan task']);
    $task->submitter_user_id = null;
    $task->save();

    (new MailNotifier())->taskCreated($task->fresh());

    expect(true)->toBeTrue();
});

test('MailNotifier fans a status change out to submitter + watchers, excluding the actor', function () {
    Notification::fake();

    $submitter = dispatchMakeUser(1);
    $watcher = dispatchMakeUser(2);
    $actor = dispatchMakeUser(3);

    // Loud on purpose: what is under test is the RECIPIENT SET, and since
    // the priority ruling (2026-09-22) a quiet task emails no staff at all —
    // the volume rule has its own tests below.
    $task = app(DispatchTaskService::class)->create([
        'title' => 'Fan out',
        'submitter_user_id' => $submitter->id,
        'priority' => 'blocker',
    ]);
    $task->watch($watcher->id);

    (new MailNotifier())->taskStatusChanged($task->fresh(), 'open', 'in_progress', $actor);

    Notification::assertSentTo([$submitter, $watcher], TaskUpdate::class);
    Notification::assertNotSentTo([$actor], TaskUpdate::class);
});

test('a status_change watcher is notified only on transitions into their chosen statuses, never on comments (W13-1)', function () {
    Notification::fake();

    $any = dispatchMakeUser(1);
    $narrowed = dispatchMakeUser(2);
    $actor = dispatchMakeUser(3);

    $task = app(DispatchTaskService::class)->create(['title' => 'Pref fan-out', 'priority' => 'blocker']);
    $task->submitter_user_id = null;
    $task->save();

    $task->watch($any->id);
    $task->watch($narrowed->id);
    $task->setWatchPreferences($narrowed->id, Task::WATCH_STATUS_CHANGE, ['done']);

    $notifier = new MailNotifier();

    // → in_progress: not in the narrowed set — only the 'any' watcher hears.
    $notifier->taskStatusChanged($task->fresh(), 'open', 'in_progress', $actor);
    Notification::assertSentTo([$any], TaskUpdate::class);
    Notification::assertNotSentTo([$narrowed], TaskUpdate::class);

    // → done: in the set — both hear.
    Notification::fake();
    $notifier->taskStatusChanged($task->fresh(), 'in_progress', 'done', $actor);
    Notification::assertSentTo([$any, $narrowed], TaskUpdate::class);

    // A comment reaches the 'any' watcher but never a status_change watcher.
    Notification::fake();
    $comment = $task->comments()->create(['body' => 'ping', 'user_id' => $actor->id, 'event_type' => TaskComment::EVENT_COMMENT]);
    $notifier->taskCommented($task->fresh(), $comment);
    Notification::assertSentTo([$any], TaskUpdate::class);
    Notification::assertNotSentTo([$narrowed], TaskUpdate::class);
});

test('a status_change watcher with NO status subset hears every status change (W13-1)', function () {
    Notification::fake();

    $watcher = dispatchMakeUser(1);
    $actor = dispatchMakeUser(2);

    $task = app(DispatchTaskService::class)->create(['title' => 'All transitions']);
    $task->submitter_user_id = null;
    $task->save();

    $task->watch($watcher->id, Task::WATCH_STATUS_CHANGE);

    (new MailNotifier())->taskStatusChanged($task->fresh(), 'open', 'in_progress', $actor);

    Notification::assertSentTo([$watcher], TaskUpdate::class);
});

test('the notifier binding falls back to the shipped default when the host config omits contracts.notifier', function () {
    // Simulate a host that published config/dispatch.php BEFORE the notifier
    // seam existed: its `contracts` array has gate/tenant/submitter but no
    // `notifier` key (mergeConfigFrom shallow-merges, so the host array wins
    // wholesale). The binding MUST fall back in code — otherwise app->make(null)
    // throws "Target class [] does not exist" (the real /livewire/update 500).
    config(['dispatch.contracts' => [
        'gate' => \Sgrjr\Dispatch\Support\DefaultGate::class,
        'tenant' => \Sgrjr\Dispatch\Support\NullTenantResolver::class,
        'submitter' => \Sgrjr\Dispatch\Support\AuthSubmitterResolver::class,
    ]]);
    app()->forgetInstance(DispatchNotifier::class);

    expect(app(DispatchNotifier::class))->toBeInstanceOf(MailNotifier::class);
});

/*
 * ── PRIORITY IS THE VOLUME KNOB (owner ruling, 2026-09-22) ──────────────────
 *
 * `notifications.email_priorities` decides which tasks are loud enough to
 * email STAFF about. ⛔ A SUBMITTER's receipt is never gated and never alarmed
 * — that is the invariant a "make it quieter" change would silently take out.
 */

test('a quiet task emails no staff — but the submitter still gets their receipt', function () {
    Notification::fake();

    $submitter = dispatchMakeUser(1);
    $watcher = dispatchMakeUser(2);
    $actor = dispatchMakeUser(3);

    $task = app(DispatchTaskService::class)->create([
        'title' => 'Ordinary work',
        'submitter_user_id' => $submitter->id,
        'priority' => 'medium',
    ]);
    $task->watch($watcher->id);

    (new MailNotifier())->taskStatusChanged($task->fresh(), 'open', 'in_progress', $actor);

    Notification::assertSentTo([$submitter], TaskUpdate::class);
    Notification::assertNotSentTo([$watcher], TaskUpdate::class);
});

test('a low task emails nobody — not staff, not even the submitter\'s receipt', function () {
    Notification::fake();

    $submitter = dispatchMakeUser(1);
    $watcher = dispatchMakeUser(2);
    $actor = dispatchMakeUser(3);

    $task = app(DispatchTaskService::class)->create([
        'title' => 'Someday work',
        'submitter_user_id' => $submitter->id,
        'priority' => 'low',
    ]);
    $task->watch($watcher->id);

    (new MailNotifier())->taskCreated($task->fresh());
    (new MailNotifier())->taskStatusChanged($task->fresh(), 'open', 'done', $actor);

    Notification::assertNothingSent();
});

test('the alarm says WHY: a blocker is blocking, a high is urgent, a receipt is neither', function () {
    Notification::fake();

    $submitter = dispatchMakeUser(1);
    $watcher = dispatchMakeUser(2);

    $task = app(DispatchTaskService::class)->create([
        'title' => 'The press is down',
        'submitter_user_id' => $submitter->id,
        'priority' => 'blocker',
    ]);
    $task->watch($watcher->id);

    (new MailNotifier())->taskStatusChanged($task->fresh(), 'open', 'in_progress', null);

    $subjectFor = fn ($user) => Notification::sent($user, TaskUpdate::class)
        ->map(fn (TaskUpdate $n) => $n->toMail($user)->subject)
        ->first();

    expect($subjectFor($watcher))->toBe("🚨 Blocking — [{$task->code}] The press is down")
        ->and($subjectFor($submitter))->toBe("[{$task->code}] The press is down");

    // `high` is urgent without the blocking claim.
    Notification::fake();
    $task->update(['priority' => 'high']);
    (new MailNotifier())->taskStatusChanged($task->fresh(), 'open', 'in_progress', null);

    expect($subjectFor($watcher))->toBe("🚨 Urgent — [{$task->code}] The press is down");
});

test('a loud task nobody holds reaches the lane that has to act — and a quiet one does not', function () {
    $dev = dispatchMakeUser(1);
    $elsewhere = dispatchMakeUser(2);

    bindNotifierLaneResolver([$dev->id => ['ops'], $elsewhere->id => ['support']]);

    // The shape of a captured exception: no submitter, no assignee, a lane.
    $loud = app(DispatchTaskService::class)->create(['title' => 'Undefined index', 'lane' => 'ops', 'priority' => 'blocker']);
    $loud->submitter_user_id = null;
    $loud->save();

    Notification::fake();
    (new MailNotifier())->taskCreated($loud->fresh());

    Notification::assertSentTo([$dev], TaskUpdate::class);
    Notification::assertNotSentTo([$elsewhere], TaskUpdate::class);

    $quiet = app(DispatchTaskService::class)->create(['title' => 'Tidy up', 'lane' => 'ops', 'priority' => 'medium']);
    $quiet->submitter_user_id = null;
    $quiet->save();

    Notification::fake();
    (new MailNotifier())->taskCreated($quiet->fresh());

    Notification::assertNotSentTo([$dev], TaskUpdate::class);
});

test('a loud task that already has a holder does not also shout at the whole lane', function () {
    $dev = dispatchMakeUser(1);
    $holder = dispatchMakeUser(2);

    bindNotifierLaneResolver([$dev->id => ['ops'], $holder->id => ['ops']]);

    $task = app(DispatchTaskService::class)->create([
        'title' => 'Already someone\'s',
        'lane' => 'ops',
        'priority' => 'blocker',
        'assignee_user_id' => $holder->id,
    ]);
    $task->submitter_user_id = null;
    $task->save();

    Notification::fake();
    (new MailNotifier())->taskCreated($task->fresh());

    // taskAssigned is what announces it to the holder — once.
    Notification::assertNotSentTo([$dev, $holder], TaskUpdate::class);
});

/** A lane resolver whose memberIds() actually answers — the shipped Null one returns []. */
function bindNotifierLaneResolver(array $lanesByUser): void
{
    app()->singleton(\Sgrjr\Dispatch\Contracts\LaneResolver::class, fn () => new class($lanesByUser) implements \Sgrjr\Dispatch\Contracts\LaneResolver
    {
        public function __construct(private array $lanesByUser) {}

        public function isLane(string $lane): bool
        {
            return in_array($lane, ['ops', 'support'], true);
        }

        public function label(string $lane): ?string
        {
            return ucfirst($lane);
        }

        public function lanes(): array
        {
            return ['ops', 'support'];
        }

        public function lanesFor(Authenticatable $user): array
        {
            return $this->lanesByUser[$user->getAuthIdentifier()] ?? [];
        }

        public function lanesManagedBy(Authenticatable $user): array
        {
            return [];
        }

        public function memberIds(string $lane): array
        {
            return array_keys(array_filter($this->lanesByUser, fn ($lanes) => in_array($lane, $lanes, true)));
        }

        public function canRoute(Authenticatable $user): bool
        {
            return true;
        }
    });
}
