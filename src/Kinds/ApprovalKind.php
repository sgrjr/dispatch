<?php

namespace Sgrjr\Dispatch\Kinds;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Exceptions\ApprovalTaskLocked;
use Sgrjr\Dispatch\Exceptions\TaskKindLocked;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\ApprovalTasks;
use Sgrjr\Dispatch\Support\TaskAction;

/**
 * The first task kind (TASK-1188 reshaping TASK-1021): "Approval requested".
 *
 * - Actions: Approve (with the approvable's own choices, e.g. an agent session's
 *   length) and Deny, offered only to STAFF while the request is pending.
 *   ⛔ No agent may run either: an agent never approves anything.
 * - Every default control is hidden, and the status is LOCKED: only Approve,
 *   Deny or the expiry (all through ApprovalTasks::resolve(), the ONE closer)
 *   move it.
 * - The panel shows the request, with the thing to CHECK emphasized (an agent
 *   session's code).
 *
 * The marker stays `context.approval` = {kind, id, state}; the approvable's own
 * contract is {@see \Sgrjr\Dispatch\Contracts\Approvable}.
 */
class ApprovalKind extends BaseTaskKind
{
    public const APPROVE = 'approve';

    public const DENY = 'deny';

    public static function key(): string
    {
        return ApprovalTasks::MARKER;
    }

    public function actions(Task $task, ?Authenticatable $viewer): array
    {
        if ($viewer === null || ! app(DispatchGate::class)->isStaff($viewer)) {
            return [];
        }

        $item = app(ApprovalTasks::class)->approvableFor($task);
        if ($item === null || ! $item->approvalIsPending() || $task->isClosed()) {
            return [];
        }

        return [
            new TaskAction(self::APPROVE, 'Approve', TaskAction::STYLE_PRIMARY, $item->approvalInputs()),
            new TaskAction(self::DENY, 'Deny', TaskAction::STYLE_DANGER),
        ];
    }

    public function hides(Task $task): array
    {
        return self::CONTROLS;
    }

    public function locksStatus(Task $task): bool
    {
        return true;
    }

    public function panel(Task $task, ?Authenticatable $viewer): ?array
    {
        $marker = $task->context[ApprovalTasks::MARKER] ?? [];
        $item = app(ApprovalTasks::class)->approvableFor($task);
        $pending = $item?->approvalIsPending() ?? false;

        return [
            'title' => 'Approval requested',
            'subject' => $marker['kind'] ?? null,
            'state' => $pending ? 'pending' : ($marker['state'] ?? 'closed'),
            'expires_at' => $item?->approvalExpiresAt()?->toIso8601String(),
            'rows' => $item?->approvalPanelRows() ?? [],
        ];
    }

    public function perform(Task $task, string $key, ?Authenticatable $user, array $input): ?string
    {
        if ($user === null) {
            throw new ApprovalTaskLocked('Only staff can decide an approval request.');
        }

        if ($key === self::APPROVE) {
            $options = [];
            if (($ttl = (int) ($input['ttl'] ?? 0)) > 0) {
                $options['ttl'] = $ttl;
            }
            app(ApprovalTasks::class)->approve($task, $user, $options);

            return 'Approved.';
        }

        if ($key === self::DENY) {
            app(ApprovalTasks::class)->deny($task, $user);

            return 'Denied.';
        }

        return parent::perform($task, $key, $user, $input);
    }

    public function lockedException(Task $task, bool $filing): TaskKindLocked
    {
        return $filing ? ApprovalTaskLocked::filing() : ApprovalTaskLocked::forTask($task->code);
    }
}
