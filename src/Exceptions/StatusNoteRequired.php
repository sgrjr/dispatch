<?php

namespace Sgrjr\Dispatch\Exceptions;

/**
 * Refused: `resolved` means "dealt with, but not as written" (TASK-1193), so a
 * task only enters it with a note saying what actually happened. Thrown by the
 * Task saving hook, the one choke point every status write passes through.
 *
 * An InvalidArgumentException on purpose, like ApprovalTaskLocked: every agent
 * endpoint and the batch service already turn that into a 422.
 */
class StatusNoteRequired extends \InvalidArgumentException
{
    public static function forStatus(string $status, ?string $code = null): self
    {
        $label = $code ?: 'A task';

        return new self("{$label} can only be marked `{$status}` with a note saying what actually happened (`{$status}` = dealt with, but not as written). Use `done` when the prescribed work was completed, `declined` when it was not done by decision.");
    }
}
