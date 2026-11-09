<?php

namespace Sgrjr\Dispatch\Kinds;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Contracts\TaskKind;
use Sgrjr\Dispatch\Exceptions\TaskKindLocked;
use Sgrjr\Dispatch\Models\Task;

/**
 * The defaults a kind starts from (TASK-1188): no actions, nothing hidden, the
 * status free, no panel. A kind overrides what it needs.
 */
abstract class BaseTaskKind implements TaskKind
{
    /** The default controls a kind can hide. */
    public const CONTROLS = ['status', 'assignee', 'claim', 'pass', 'ask'];

    public function actions(Task $task, ?Authenticatable $viewer): array
    {
        return [];
    }

    public function hides(Task $task): array
    {
        return [];
    }

    public function locksStatus(Task $task): bool
    {
        return false;
    }

    public function panel(Task $task, ?Authenticatable $viewer): ?array
    {
        return null;
    }

    public function perform(Task $task, string $key, ?Authenticatable $user, array $input): ?string
    {
        throw new \InvalidArgumentException("This task has no `{$key}` action.");
    }

    public function lockedException(Task $task, bool $filing): TaskKindLocked
    {
        return $filing
            ? new TaskKindLocked('A `'.static::key().'` task can only be filed by its own service.')
            : new TaskKindLocked(($task->code ?: 'This task').' is a `'.static::key().'` task: its status changes only through its own actions.');
    }
}
