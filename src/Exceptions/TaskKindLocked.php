<?php

namespace Sgrjr\Dispatch\Exceptions;

/**
 * Refused: a task KIND (TASK-1188) locks its status to its own actions, and
 * its marker is system set. Thrown by the Task saving hook when anything but
 * the kind's own service moves a locked status, or files / edits / removes the
 * marker: dispatch:done, the agent API, a batch update, a board drag or bulk
 * move, TaskShow, a claim, a closing pass.
 *
 * An InvalidArgumentException on purpose: every agent endpoint and the batch
 * service already turn that into a 422, so an agent reads the refusal, never a
 * 500. A kind may throw its own subclass ({@see ApprovalTaskLocked}).
 */
class TaskKindLocked extends \InvalidArgumentException {}
