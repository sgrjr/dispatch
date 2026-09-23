<?php

namespace Sgrjr\Dispatch\Services;

use Sgrjr\Dispatch\Contracts\TaskKind;
use Sgrjr\Dispatch\Models\Task;

/**
 * The task-kind registry (TASK-1188): `config('dispatch.task_kinds')`,
 * [key => class implementing TaskKind]. A task IS of kind <key> when
 * `context[<key>]` is an array (the system-set marker).
 *
 * Also the write gate the Task saving hook reads: only code running inside
 * {@see asKind()} (a kind's own service or action) may file, change or remove a
 * marker, or move a status the kind locks.
 */
final class TaskKinds
{
    /** Depth counter, not a bool: a kind's write may nest (a closer inside a closer). */
    private static int $writing = 0;

    /** Is a kind's own service writing right now? */
    public static function writing(): bool
    {
        return self::$writing > 0;
    }

    /**
     * Run a write as a kind's own service: the only writer the Task lock lets
     * through.
     *
     * @template T
     *
     * @param  callable():T  $write
     * @return T
     */
    public static function asKind(callable $write): mixed
    {
        self::$writing++;
        try {
            return $write();
        } finally {
            self::$writing--;
        }
    }

    /** @return array<string, class-string<TaskKind>> the registered kinds that resolve to a TaskKind class */
    public static function registered(): array
    {
        $out = [];
        foreach ((array) config('dispatch.task_kinds', []) as $key => $class) {
            if (is_string($key) && is_string($class) && is_subclass_of($class, TaskKind::class)) {
                $out[$key] = $class;
            }
        }

        return $out;
    }

    /** The marker key of this task's REGISTERED kind, or null (a plain task). */
    public static function keyFor(?Task $task): ?string
    {
        if ($task === null || ! is_array($task->context)) {
            return null;
        }

        foreach (array_keys(self::registered()) as $key) {
            if (is_array($task->context[$key] ?? null)) {
                return $key;
            }
        }

        return null;
    }

    /** This task's kind, or null: a plain task, or a marker whose kind is no longer registered (the defaults). */
    public static function for(?Task $task): ?TaskKind
    {
        $key = self::keyFor($task);

        return $key === null ? null : app(self::registered()[$key]);
    }
}
