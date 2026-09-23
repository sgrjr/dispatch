<?php

namespace Sgrjr\Dispatch\Kinds;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Exceptions\TaskKindLocked;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Support\TaskAction;

/**
 * A task that CLOSES WITH ITS RECORD (TASK-1190, owner ruling 2026-09-23).
 *
 * Not every task is self-closing. Some are gated by a record that lives in
 * another tool: a customer's plan request, an approval, a timesheet. The work
 * happens in that tool, and the task can't close until the record is decided.
 * Deciding the record closes the task, with the closer's note.
 *
 * The canon, so each gated kind is a small subclass rather than a new design:
 *   - ONE action: a LINK to the record's own tool (never rebuild the tool in the
 *     task), offered to staff while the task and the record are open;
 *   - status + Claim hidden, and the status LOCKED: a task never reads closed
 *     over an open record, and nothing else closes it;
 *   - it TRAVELS with a pass, so whoever holds the ball has the same link;
 *   - the record's closer calls {@see \Sgrjr\Dispatch\Services\TaskKinds::closeGated()}
 *     for each task it gates: ONE closer.
 *
 * ⛔ Not the task-to-task `blocked_by` link (the ball: a dependent unblocks when
 * its blocker closes). A gated task is gated by a RECORD, outside the board.
 * A simple decision may still put its buttons in the task (an approval's
 * Approve / Deny): override actions().
 */
abstract class RecordGatedKind extends BaseTaskKind
{
    /** The record this task is gated by, or null when it is gone. */
    abstract protected function record(Task $task): mixed;

    /** Is the record still waiting on a decision? */
    abstract protected function recordIsOpen(mixed $record): bool;

    /** Where the record is decided: the tool the task links to. */
    abstract protected function toolUrl(Task $task, mixed $record): string;

    /** The link's label, e.g. "Review & Mark Done". */
    protected function toolLabel(): string
    {
        return 'Open';
    }

    public function actions(Task $task, ?Authenticatable $viewer): array
    {
        if ($viewer === null || ! app(DispatchGate::class)->isStaff($viewer) || $task->isClosed()) {
            return [];
        }

        $record = $this->record($task);
        if ($record === null || ! $this->recordIsOpen($record)) {
            return [];
        }

        return [TaskAction::link('open_record', $this->toolLabel(), $this->toolUrl($task, $record))];
    }

    public function hides(Task $task): array
    {
        return ['status', 'claim'];
    }

    public function locksStatus(Task $task): bool
    {
        return true;
    }

    public function continues(Task $from): ?array
    {
        $marker = $from->context[static::key()] ?? null;

        return is_array($marker) ? $marker : null;
    }

    public function lockedException(Task $task, bool $filing): TaskKindLocked
    {
        return $filing
            ? new TaskKindLocked('A `'.static::key().'` task can only be filed by its own service.')
            : new TaskKindLocked(($task->code ?: 'This task').' closes with its record: open "'.$this->toolLabel().'" and decide it there. This task closes when the record does.');
    }
}
