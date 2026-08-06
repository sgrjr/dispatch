<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Sgrjr\Dispatch\Models\Task;

/**
 * The W13-5 visibility gates, as ONE reusable query fragment so the shipped
 * DefaultGate and any host gate apply identical semantics instead of each
 * re-deriving them. This is NOT a second filtering seam — it is a helper a
 * DispatchGate::scopeVisible() implementation composes; scopeVisible remains
 * the only scope in the system (§6 doctrine).
 *
 * The gates (operator ruling, 2026-08-06):
 *  - GATE A (unconditional truth, never a setting): the submitter,
 *    assignee, and watchers of a task always see it — they ARE the task's
 *    circle. Applies to staff viewers.
 *  - GATE B (per-task opt-IN via `visibility` = 'staff'): all other staff.
 *    The default for new staff-created tasks is 'participants' — the circle
 *    is closed until someone opens it.
 *  - GATE C (per-task toggle via `is_public`): the non-staff customer who
 *    submitted. Default INVISIBLE even to them; the existing "Visible to
 *    submitter/customer" toggle opens it.
 *  - GATE D (everyone else — guests, customers who didn't submit): a hard
 *    no-op. There is NO public visibility; an unauthenticated query matches
 *    nothing.
 *
 * A gate's canSeeAll() superuser check belongs in the CALLER (short-circuit
 * before applying the gates), matching the existing scopeVisible shape.
 */
class VisibilityGates
{
    /**
     * Apply the gates for $user to a task query. $isStaff is the caller's
     * own DispatchGate::isStaff() verdict — passed in rather than resolved
     * here so a host gate composes with its own staff test.
     */
    public static function apply(Builder $query, ?Authenticatable $user, bool $isStaff): Builder
    {
        // GATE D: guests match nothing, unconditionally.
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        $userId = $user->getAuthIdentifier();

        if ($isStaff) {
            // GATE B ∪ GATE A: staff-shared tasks, plus every task the
            // viewer participates in regardless of its visibility.
            return $query->where(function (Builder $q) use ($userId) {
                $q->where('visibility', Task::VISIBILITY_STAFF)
                    ->orWhere(fn (Builder $p) => self::participant($p, $userId));
            });
        }

        // GATE C: a non-staff user sees only their OWN submissions, and only
        // when the per-task toggle opened them. (Their GATE-A-shaped claim as
        // submitter is deliberately NOT honored here — C is the customer
        // gate, and it defaults closed.)
        return $query->where('submitter_user_id', $userId)->where('is_public', true);
    }

    /**
     * GATE A membership: submitter, assignee, or watcher.
     */
    protected static function participant(Builder $query, mixed $userId): void
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');
        $table = (new $taskModel)->getTable();

        $query->where('submitter_user_id', $userId)
            ->orWhere('assignee_user_id', $userId)
            ->orWhereExists(function ($sub) use ($table, $userId) {
                $sub->selectRaw('1')
                    ->from('dispatch_task_watchers')
                    ->whereColumn('dispatch_task_watchers.task_id', "{$table}.id")
                    ->where('dispatch_task_watchers.user_id', $userId);
            });
    }
}
