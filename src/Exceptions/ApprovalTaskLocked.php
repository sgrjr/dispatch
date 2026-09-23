<?php

namespace Sgrjr\Dispatch\Exceptions;

/**
 * Refused: an approval task's status (or its approval marker) may only change
 * through Approve / Deny / expiry, never through done, a batch update, a board
 * drag, a claim, a pass that closes it, or any agent verb (TASK-1021).
 *
 * An InvalidArgumentException on purpose: every agent endpoint and the batch
 * service already turn that into a 422, so the refusal reaches an agent as a
 * clear message, never a 500. The approval kind's own TaskKindLocked (TASK-1188).
 */
class ApprovalTaskLocked extends TaskKindLocked
{
    public static function forTask(?string $code): self
    {
        $label = $code ?: 'This task';

        return new self("{$label} is an approval request. Only a staff member's Approve or Deny can close it (or it expires on its own).");
    }

    public static function filing(): self
    {
        return new self('An approval task can only be filed by the approval service.');
    }
}
