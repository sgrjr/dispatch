<?php

namespace Sgrjr\Dispatch\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Exceptions\TaskKindLocked;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Support\TaskAction;

/**
 * A task KIND (TASK-1188, epic:task-kinds): a task that defines its own controls.
 *
 * Every task has the DEFAULT controls (status, assignee, claim, pass, ask). A
 * kind adds its own ACTIONS, can hide the defaults that don't fit, can LOCK its
 * status to its own actions, shows a PANEL about the underlying thing, and says
 * which actions an agent may run. ONE action path serves the chat canvas, the
 * package board (TaskShow) and the agent API: {@see \Sgrjr\Dispatch\Services\TaskActions}.
 * The kind does the work, updates its underlying record, and closes the task.
 *
 * The MARKER: a kind is registered in `config('dispatch.task_kinds')` as
 * [key => class], and a task IS of that kind when `context[<key>]` is an array.
 * The marker is SYSTEM set: the Task saving hook refuses to let anything but
 * the kind's own service (inside {@see \Sgrjr\Dispatch\Services\TaskKinds::asKind()})
 * file, change or remove it. A marker whose key is no longer registered is just
 * context: the task degrades to the default controls.
 *
 * Extend {@see \Sgrjr\Dispatch\Kinds\BaseTaskKind} for the defaults.
 */
interface TaskKind
{
    /** The registry key; also the context key that marks a task of this kind. */
    public static function key(): string;

    /**
     * The actions THIS viewer is offered now, in display order. A null viewer
     * is an agent or the trusted CLI (no person); the action service then keeps
     * only the agentAllowed ones.
     *
     * @return array<int, TaskAction>
     */
    public function actions(Task $task, ?Authenticatable $viewer): array;

    /**
     * Default controls this kind hides: any of status, assignee, claim, pass, ask.
     *
     * @return array<int, string>
     */
    public function hides(Task $task): array;

    /** Is the status changed ONLY by this kind's own actions (and service)? */
    public function locksStatus(Task $task): bool;

    /**
     * What the task view shows about the underlying thing, or null:
     *   {title?: string, state?: string, expires_at?: iso8601|null,
     *    rows: [{label: string, value: string, emphasis?: bool}], note?: string}
     *
     * @return array<string, mixed>|null
     */
    public function panel(Task $task, ?Authenticatable $viewer): ?array;

    /**
     * Run action $key. The action service has already checked it is offered to
     * this viewer and its required inputs are present. Runs inside
     * TaskKinds::asKind(), so the kind may move its own locked status. Returns
     * a short message for the person ("Approved."), or null.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws \InvalidArgumentException for a refusal the person should read
     */
    public function perform(Task $task, string $key, ?Authenticatable $user, array $input): ?string;

    /**
     * The refusal thrown when something other than this kind writes its locked
     * status, or files / edits its marker ($filing: the marker appeared).
     */
    public function lockedException(Task $task, bool $filing): TaskKindLocked;
}
