<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Events\TaskAssigned;
use Sgrjr\Dispatch\Events\TaskCommented;
use Sgrjr\Dispatch\Events\TaskCreated;
use Sgrjr\Dispatch\Events\TaskStatusChanged;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Turns the DispatchNotifier mutation hooks into real Laravel events
 * (TaskCreated, TaskStatusChanged, TaskCommented, TaskAssigned) so a host
 * can react to Dispatch mutations without the package editing any mutation
 * site itself — reactive orchestration (C6).
 *
 * A host binds this via `config('dispatch.contracts.notifier')` (or
 * `app()->singleton(DispatchNotifier::class, ...)`) and registers listeners
 * against the events above — e.g. auto-spawning an agent session on
 * TaskCreated, or posting to a chat channel on TaskStatusChanged.
 *
 * To ALSO deliver notifications (mail, etc.) alongside the events, extend
 * this class and override each method to call `parent::taskX(...)` plus
 * whatever delivery you need — or compose it with MailNotifier behind a
 * small dispatching wrapper.
 *
 * Per the DispatchNotifier contract this NEVER throws: every method wraps
 * its body in try/catch, matching MailNotifier's discipline, since the
 * caller (DispatchTaskService / Livewire mutation points) invokes these
 * synchronously with no guard of its own.
 */
class EventNotifier implements DispatchNotifier
{
    public function taskCreated(Task $task): void
    {
        try {
            event(new TaskCreated($task));
        } catch (\Throwable) {
            // never throw — see class docblock
        }
    }

    public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void
    {
        try {
            event(new TaskStatusChanged($task, $from, $to, $actor));
        } catch (\Throwable) {
            // never throw
        }
    }

    public function taskCommented(Task $task, TaskComment $comment): void
    {
        try {
            event(new TaskCommented($task, $comment));
        } catch (\Throwable) {
            // never throw
        }
    }

    public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void
    {
        try {
            event(new TaskAssigned($task, $from, $to, $actor));
        } catch (\Throwable) {
            // never throw
        }
    }
}
