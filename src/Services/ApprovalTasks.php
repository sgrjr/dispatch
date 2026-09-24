<?php

namespace Sgrjr\Dispatch\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Sgrjr\Dispatch\Contracts\Approvable;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Exceptions\ApprovalTaskLocked;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * "Approval requested" (PU-2.10, TASK-1021, R18/R19): the service behind the
 * first task KIND, {@see \Sgrjr\Dispatch\Kinds\ApprovalKind} (TASK-1188), whose
 * Approve / Deny actions call approve()/deny() below.
 *
 * An Approvable (an agent-session request first) files ONE task here:
 *   - title "Approval requested: <label>", priority low, open;
 *   - routed to the approvers' lane;
 *   - due_at = the request's expiry;
 *   - topic + origin = the approvable (kind:id).
 * It is marked by `context.approval = {kind, id, state}`. That marker is SYSTEM
 * set: Task's saving hook refuses to let anything but this service write it or
 * change the task's status.
 *
 * It closes three ways, all through resolve():
 *   - approved → done;
 *   - denied   → declined;
 *   - expired  → declined, with result.resolution = expired, so a timeout
 *     never reads as a refusal.
 * ONE closer: Approve/Deny on the task and on the kind's own screen
 * (/it/agent-sessions) both end in the approvable's approve/deny, and that
 * calls resolve().
 *
 * ⛔ No generic close, no self-approval: dispatch:done, batch updates, board
 * drags, claims, closing passes and every agent verb are refused
 * (ApprovalTaskLocked). Only a staff human, through approve()/deny() below, can
 * decide. An agent never holds a web session, so it can never be that human.
 */
final class ApprovalTasks
{
    public const MARKER = 'approval';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    public const EXPIRED = 'expired';

    /** Is this service (or any kind's own service) writing right now? The Task lock lets only that through. */
    public static function resolving(): bool
    {
        return TaskKinds::writing();
    }

    /** Is this task an approval task? */
    public static function isApproval(?Task $task): bool
    {
        return $task !== null && is_array($task->context[self::MARKER] ?? null);
    }

    /**
     * File the approval task for a new request. Idempotent: an open one for the
     * same approvable is returned rather than doubled. Never throws. A request
     * whose task could not be filed is still approvable on its own screen.
     */
    public function file(Approvable $item): ?Task
    {
        try {
            if ($existing = $this->openTaskFor($item::approvalKind(), $item->approvalId())) {
                return $existing;
            }

            return $this->asResolver(fn () => app(DispatchTaskService::class)->create([
                'title' => 'Approval requested: '.$item->approvalLabel(),
                'description' => $item->approvalDetails(),
                'type' => 'verify',
                // LOW on purpose: the approver is the person who just prompted
                // the agent, and is already watching for the code. A loud ring
                // (R31) mails them about their own request, which is pure noise.
                // The task still rings the lane bell and sits in chat to approve.
                'priority' => 'low',
                'status' => 'open',
                'visibility' => Task::VISIBILITY_STAFF,
                'submitter_user_id' => null,
                'lane' => $item->approvalLane(),
                'due_at' => $item->approvalExpiresAt(),
                'topic_type' => $item::approvalKind(),
                'topic_id' => $item->approvalId(),
                'origin_type' => $item::approvalKind(),
                'origin_id' => $item->approvalId(),
                'context' => [self::MARKER => [
                    'kind' => $item::approvalKind(),
                    'id' => $item->approvalId(),
                    'state' => 'pending',
                ]],
            ], [], null));
        } catch (\Throwable $e) {
            Log::warning('Approval task could not be filed; the request stands on its own screen', [
                'kind' => $item::approvalKind(),
                'id' => $item->approvalId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The approvable behind an approval task, or null (not an approval task, or
     * its kind is not registered, or the row is gone).
     */
    public function approvableFor(Task $task): ?Approvable
    {
        $marker = $task->context[self::MARKER] ?? null;
        if (! is_array($marker)) {
            return null;
        }

        $class = config('dispatch.approvals.kinds.'.($marker['kind'] ?? ''));
        if (! is_string($class) || ! is_subclass_of($class, Approvable::class)) {
            return null;
        }

        return $class::findForApproval((string) ($marker['id'] ?? ''));
    }

    /**
     * A staff human approves, from the task. Resolving the approvable closes
     * the task (one closer).
     *
     * @param  array<string, mixed>  $options  kind-specific (agent session: ttl, scopes, lane)
     */
    public function approve(Task $task, Authenticatable $user, array $options = []): Approvable
    {
        $item = $this->decidable($task, $user);
        $item->approveBy($user, $options);

        return $item;
    }

    /** A staff human denies, from the task. */
    public function deny(Task $task, Authenticatable $user): Approvable
    {
        $item = $this->decidable($task, $user);
        $item->denyBy($user);

        return $item;
    }

    /**
     * Close the open approval task(s) for an approvable. Called by the
     * approvable itself when it is approved or denied, from ANY screen, and by
     * expireDue() for a timeout.
     */
    public function resolve(string $kind, string $id, string $outcome, ?int $userId = null): void
    {
        $task = $this->openTaskFor($kind, $id);
        if (! $task) {
            return;
        }

        $from = $task->status;
        $to = $outcome === self::APPROVED ? 'done' : 'declined';

        $this->asResolver(function () use ($task, $outcome, $userId, $to) {
            $context = $task->context ?? [];
            $context[self::MARKER]['state'] = $outcome;
            $context[self::MARKER]['decided_by_user_id'] = $userId;
            $context[self::MARKER]['decided_at'] = now()->toIso8601String();
            $context['result'] = array_merge($context['result'] ?? [], ['resolution' => $outcome]);
            $task->context = $context;
            $task->status = $to;
            $task->save();
        });

        $task->recordEvent(
            TaskComment::EVENT_STATUS_CHANGE,
            $userId,
            ['from' => $from, 'to' => $to, 'approval' => $outcome],
            match ($outcome) {
                self::APPROVED => 'Approved.',
                self::DENIED => 'Denied.',
                default => 'Expired. Nobody decided before the request lapsed.',
            },
        );

        try {
            app(DispatchNotifier::class)->taskStatusChanged($task, $from, $to, null);
        } catch (\Throwable) {
            // never let a notification failure undo a decision
        }
    }

    /**
     * The timeout. Close every open approval task whose due_at has passed as
     * EXPIRED, unless its approvable was decided meanwhile, in which case close
     * it the way it was decided. Scheduled alongside dispatch:sessions:prune.
     */
    public function expireDue(): int
    {
        $count = 0;
        $taskModel = config('dispatch.models.task');

        $taskModel::query()
            ->whereNotNull('context->'.self::MARKER.'->kind')
            ->whereNotIn('status', Task::closedStatuses())
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->get()
            ->each(function (Task $task) use (&$count) {
                $marker = $task->context[self::MARKER];
                $item = $this->approvableFor($task);
                $outcome = $item !== null && ! $item->approvalIsPending()
                    ? $this->decidedOutcome($item)
                    : self::EXPIRED;
                $this->resolve((string) $marker['kind'], (string) $marker['id'], $outcome);
                $count++;
            });

        return $count;
    }

    /** The open approval task for an approvable, if any. */
    public function openTaskFor(string $kind, string $id): ?Task
    {
        $taskModel = config('dispatch.models.task');

        return $taskModel::query()
            ->where('context->'.self::MARKER.'->kind', $kind)
            ->where('context->'.self::MARKER.'->id', $id)
            ->whereNotIn('status', Task::closedStatuses())
            ->latest('id')
            ->first();
    }

    /** The approvable behind a task, if a staff human may decide it now. */
    private function decidable(Task $task, Authenticatable $user): Approvable
    {
        if (! app(DispatchGate::class)->isStaff($user)) {
            throw new ApprovalTaskLocked('Only staff can decide an approval request.');
        }

        $item = $this->approvableFor($task);
        if (! $item) {
            throw new ApprovalTaskLocked(($task->code ?? 'This task').' is not an open approval request.');
        }
        if (! $item->approvalIsPending()) {
            // Decided elsewhere, or lapsed: settle the task and say so.
            $this->resolve($item::approvalKind(), $item->approvalId(), $this->decidedOutcome($item));
            throw new ApprovalTaskLocked('That request is no longer waiting on a decision.');
        }

        return $item;
    }

    /** How a no-longer-pending approvable ended, for its task's resolution. */
    private function decidedOutcome(Approvable $item): string
    {
        return method_exists($item, 'approvalOutcome') ? $item->approvalOutcome() : self::EXPIRED;
    }

    /** Run a write as the approval kind's own service: the only writer the Task lock lets through (TASK-1188). */
    private function asResolver(callable $write): mixed
    {
        return TaskKinds::asKind($write);
    }
}
