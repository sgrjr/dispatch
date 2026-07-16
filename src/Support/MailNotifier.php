<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Notifications\TaskUpdate;

/**
 * Shipped mail-backed DispatchNotifier. Resolves recipients off Task's own
 * relations (submitter/watchers/assignee), dedupes by auth identifier, and
 * excludes the acting user (nobody needs an email about their own action) —
 * except taskCreated, where the submitter IS the recipient.
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
            $submitter = $task->submitter;

            if (! $submitter) {
                return;
            }

            $this->send($submitter, $task, 'Your request was received.');
        } catch (\Throwable) {
            // never throw — see class docblock
        }
    }

    public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $pool = array_merge([$task->submitter], $task->watchers->all(), [$task->assignee]);
            $recipients = $this->dedupe($pool, $actor?->getAuthIdentifier());

            $summary = "Status changed from `{$from}` to `{$to}`.";

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
            $pool = $comment->is_internal
                ? $task->watchers->all()
                : array_merge([$task->submitter], $task->watchers->all());

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

    protected function enabled(): bool
    {
        return (bool) config('dispatch.notifications.enabled', true);
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

    protected function send(mixed $user, Task $task, string $summary, ?TaskComment $comment = null): void
    {
        if ($user && method_exists($user, 'notify')) {
            $user->notify(new TaskUpdate($task, $summary, $comment));
        }
    }
}
