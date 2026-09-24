<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Contracts\LaneResolver;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Notifications\TaskUpdate;

/**
 * Shipped mail-backed DispatchNotifier. Resolves recipients off Task's own
 * relations (submitter/watchers/assignee), dedupes by auth identifier, and
 * excludes the acting user (nobody needs an email about their own action) —
 * except taskCreated, where the submitter IS the recipient.
 *
 * ── PRIORITY IS THE VOLUME KNOB (owner ruling, 2026-09-22) ──────────────────
 * Every recipient of every event used to get an email, which is how an inbox
 * stops being read. A task's PRIORITY now decides whether its alerts leave the
 * app at all: `dispatch.notifications.email_priorities` (blocker + high by
 * default) sends mail, and everything quieter is left to the in-app bell.
 *
 * ⛔ A RECEIPT IS NOT AN ALERT. The submitter's own copy — "your request was
 * received", and what happened to it since — sends at every priority that
 * is not SILENT, and never carries the alarm: it is a reply to the person who
 * asked, not an interruption aimed at staff.
 *
 * SILENT (owner ruling, 2026-09-23): a task at a
 * `dispatch.notifications.silent_priorities` priority (`low` by default) sends
 * NO email to anyone, receipts included. Low is mostly work an agent or the
 * system filed, and its submitter is the person who just asked for it (an
 * agent-session approval is the canonical case), so a receipt is pure noise.
 *
 * Gated by `dispatch.notifications.enabled`. Per the DispatchNotifier
 * contract this NEVER throws: every method wraps its body in try/catch so
 * one bad recipient (a stale relation, a notify() failure) can't break the
 * caller — a Livewire action or the create path.
 */
class MailNotifier implements DispatchNotifier
{
    public function taskCreated(Task $task): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            // The submitter's receipt: always sent, never alarmed. Null for
            // work the SYSTEM filed — which is precisely the case alertLane()
            // below exists for, so this must not return out of the method.
            if ($submitter = $task->submitter) {
                $this->send($submitter, $task, 'Your request was received.');
            }
        } catch (\Throwable) {
            // never throw — see class docblock
        }

        $this->alertLane($task);
    }

    /**
     * A LOUD task that lands on a department and nobody's hands yet: tell the
     * people who work that lane.
     *
     * Without this, the loudest work in the system reaches no inbox at all.
     * The recipient list for a task the system files (a captured exception, an
     * incident) is otherwise empty — no submitter, no assignee, no watchers —
     * so "urgent" would ring an in-app bell and wait to be found, which is the
     * whole problem priority is meant to solve.
     *
     * Skipped once a task HAS an assignee: taskAssigned announces it to them,
     * and being told twice about the same arrival is how people learn to
     * filter a sender.
     */
    protected function alertLane(Task $task): void
    {
        if (! $this->enabled() || ! $this->isLoud($task)) {
            return;
        }

        try {
            if ($task->lane === null || $task->assignee_user_id !== null) {
                return;
            }

            $ids = app(LaneResolver::class)->memberIds((string) $task->lane);

            if ($ids === []) {
                return;
            }

            /** @var class-string $userModel */
            $userModel = config('dispatch.models.user');
            $label = app(LaneResolver::class)->label((string) $task->lane) ?? $task->lane;

            // The submitter already has their receipt; nobody needs both.
            $recipients = $this->dedupe(
                $userModel::whereIn((new $userModel)->getKeyName(), $ids)->get()->all(),
                $task->submitter_user_id,
            );

            foreach ($recipients as $recipient) {
                $this->send($recipient, $task, "New work for {$label}, and nobody has it yet.");
            }
        } catch (\Throwable) {
            // never throw
        }
    }

    public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $pool = array_merge(
                [$task->submitter],
                $this->watchersFor($task, 'status', $to),
                [$task->assignee],
                Groups::members($task->assignee_group)->all(), // W13-4: a team assignee means every member
            );
            $recipients = $this->dedupe($pool, $actor?->getAuthIdentifier());

            $summary = "Status changed from `{$from}` to `{$to}`.";
            // TASK-1193 — a close that carried a note says what happened.
            if ($task->statusNote !== null) {
                $summary .= ' '.strtok($task->statusNote, "\n");
            }

            foreach ($recipients as $recipient) {
                $this->send($recipient, $task, $summary);
            }
        } catch (\Throwable) {
            // never throw
        }
    }

    public function taskCommented(Task $task, TaskComment $comment): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $watchers = $this->watchersFor($task, 'comment');

            $pool = $comment->is_internal
                ? $watchers
                : array_merge([$task->submitter], $watchers);

            $recipients = $this->dedupe($pool, $comment->user_id);

            $summary = $comment->body !== '' && $comment->body !== null
                ? $comment->body
                : 'New comment on your request.';

            foreach ($recipients as $recipient) {
                $this->send($recipient, $task, $summary, $comment);
            }
        } catch (\Throwable) {
            // never throw
        }
    }

    public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            if ($to === null) {
                return;
            }

            /** @var class-string $userModel */
            $userModel = config('dispatch.models.user');
            $assignee = $userModel::find($to);

            $recipients = $this->dedupe([$assignee], $actor?->getAuthIdentifier());

            foreach ($recipients as $recipient) {
                $this->send($recipient, $task, 'You were assigned this task.');
            }
        } catch (\Throwable) {
            // never throw
        }
    }

    /**
     * W13-4: a task was assigned to a config-defined GROUP — notify every
     * member. Like watcherAdded, a duck-typed optional hook rather than a
     * DispatchNotifier contract method (which would break host notifiers on
     * upgrade): callers check method_exists before invoking.
     */
    public function taskAssignedGroup(Task $task, ?string $from, string $to, ?Authenticatable $actor): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $recipients = $this->dedupe(Groups::members($to)->all(), $actor?->getAuthIdentifier());

            foreach ($recipients as $recipient) {
                $this->send($recipient, $task, "Your team \"{$to}\" was assigned this task.");
            }
        } catch (\Throwable) {
            // never throw
        }
    }

    /**
     * W13-2 "watch on behalf of": tell someone they were CC'd onto a task.
     * Deliberately NOT on the DispatchNotifier contract — adding a method
     * there would break every host-implemented notifier on upgrade. Callers
     * duck-type (`method_exists($notifier, 'watcherAdded')`), so a custom
     * notifier without it simply sends nothing, and one that wants it just
     * defines it.
     */
    public function watcherAdded(Task $task, mixed $watcher, ?Authenticatable $actor): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $recipients = $this->dedupe([$watcher], $actor?->getAuthIdentifier());

            $by = trim((string) ($actor->name ?? '')) !== '' ? $actor->name : 'A teammate';

            foreach ($recipients as $recipient) {
                $this->send($recipient, $task, "{$by} added you as a watcher — you'll be notified of updates here. Open the task to adjust what you're notified about, or to stop watching.");
            }
        } catch (\Throwable) {
            // never throw
        }
    }

    protected function enabled(): bool
    {
        return (bool) config('dispatch.notifications.enabled', true);
    }

    /**
     * The watcher pool filtered by each watcher's W13-1 preference pivot:
     *  - null/'any' (and any unknown mode — fail OPEN, a notification beats
     *    silence on a bad value) → every event;
     *  - 'status_change' → only $event 'status', and when notify_statuses is
     *    a non-empty array, only transitions INTO one of those statuses.
     *
     * @return array<int,mixed>
     */
    protected function watchersFor(Task $task, string $event, ?string $toStatus = null): array
    {
        return $task->watchers
            ->filter(function ($watcher) use ($event, $toStatus) {
                $mode = $watcher->pivot->notify_on ?? null;

                if ($mode !== Task::WATCH_STATUS_CHANGE) {
                    return true;
                }

                if ($event !== 'status') {
                    return false;
                }

                $statuses = $watcher->pivot->notify_statuses;
                if (is_string($statuses)) {
                    $statuses = json_decode($statuses, true);
                }

                return ! is_array($statuses) || $statuses === [] || in_array($toStatus, $statuses, true);
            })
            ->values()
            ->all();
    }

    /**
     * Dedupe a pool of possibly-null/duplicate notifiables by auth
     * identifier, excluding $excludeId.
     *
     * @param  array<int,mixed>  $pool
     * @return array<int,mixed>
     */
    protected function dedupe(array $pool, mixed $excludeId): array
    {
        $seen = [];
        $recipients = [];

        foreach ($pool as $user) {
            if (! $user) {
                continue;
            }

            $id = $user->getAuthIdentifier();

            if ($excludeId !== null && (string) $id === (string) $excludeId) {
                continue;
            }

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $recipients[] = $user;
        }

        return $recipients;
    }

    /**
     * The ONE gate every email passes through.
     *
     * A recipient is either the SUBMITTER — who gets their receipt whatever the
     * priority — or a staff ALERT, which only leaves the app when the task is
     * loud enough to be worth an interruption. The in-app bell is unaffected
     * either way: it is written by the host's notifier, not here.
     */
    protected function send(mixed $user, Task $task, string $summary, ?TaskComment $comment = null): void
    {
        if (! $user || ! method_exists($user, 'notify') || $this->isSilent($task)) {
            return;
        }

        $isReceipt = $this->isSubmitter($user, $task);

        if (! $isReceipt && ! $this->isLoud($task)) {
            return;
        }

        $user->notify(new TaskUpdate($task, $summary, $comment, alarm: ! $isReceipt));
    }

    /** Is this recipient the person who filed the task (their copy is a receipt)? */
    protected function isSubmitter(mixed $user, Task $task): bool
    {
        return $task->submitter_user_id !== null
            && (string) $user->getAuthIdentifier() === (string) $task->submitter_user_id;
    }

    /** Is this task too quiet to email ANYONE about, its submitter included? */
    protected function isSilent(Task $task): bool
    {
        $silent = (array) config('dispatch.notifications.silent_priorities', ['low']);

        return in_array((string) $task->priority, $silent, true);
    }

    /**
     * Is this task loud enough to email about? `blocker` and `high` by default
     * — the two priorities that mean "someone should look now"; `medium` and
     * `low` ring the bell and wait to be found.
     */
    protected function isLoud(Task $task): bool
    {
        $loud = (array) config('dispatch.notifications.email_priorities', ['blocker', 'high']);

        return in_array((string) $task->priority, $loud, true);
    }
}
