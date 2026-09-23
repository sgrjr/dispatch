<?php

namespace Sgrjr\Dispatch\Exceptions;

/**
 * Refused: a task-kind action that is not offered to this caller, or is
 * missing a required input (TASK-1188). `httpStatus` is what the agent API
 * answers: 403 when the caller may not run it (an agent and a staff-only
 * action), 422 otherwise.
 */
class TaskActionRefused extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}
