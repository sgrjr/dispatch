<?php

namespace Sgrjr\Dispatch\Support;

/**
 * Small helpers around the lane KEY FORMAT (TASK-997 part A) — the package's
 * only structural knowledge of a lane string: "the part before the first `:`
 * is the department." Everything else about a lane (whether it's valid, its
 * label, who works it) is the bound LaneResolver's business.
 */
class Lane
{
    /**
     * The reserved filter token for the NO-DEPARTMENT lane (R15) — never a
     * valid lane key a resolver returns from isLane()/lanes(). Used on the
     * wire (`--lane=none`, `?lane=none`) to mean "unrouted", matching
     * {@see \Sgrjr\Dispatch\Models\Task::scopeInLane()}'s whereNull() branch.
     */
    public const NONE = 'none';

    /**
     * The department a lane key belongs to: the part before the first `:`,
     * or the whole string when it carries no sub-lane.
     */
    public static function department(string $lane): string
    {
        $pos = strpos($lane, ':');

        return $pos === false ? $lane : substr($lane, 0, $pos);
    }

    /**
     * Whether $lane names a sub-lane (carries a `:role` suffix) rather than a
     * bare department.
     */
    public static function isSubLane(string $lane): bool
    {
        return str_contains($lane, ':');
    }
}
