<?php

namespace Sgrjr\Dispatch\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Exceptions\TaskActionRefused;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Support\TaskAction;

/**
 * THE one way to run a task kind's action (TASK-1188). TaskShow, the agent API
 * (`show` lists, `perform` runs) and a host's own screens (Centerpoint's chat)
 * all describe and perform through here, so a kind's rules hold on every
 * surface:
 *   - the action must be OFFERED to this caller right now (the kind decides,
 *     per viewer: a staff-only Approve is never offered to anyone else);
 *   - an AGENT (or the trusted CLI: no person) runs only `agentAllowed` actions;
 *   - `required` inputs are present;
 * then the kind's perform() runs as the kind (TaskKinds::asKind(), so it may
 * move its own locked status), and the press is recorded on the timeline.
 */
final class TaskActions
{
    /**
     * What a surface needs to render a kind's controls, or null for a plain
     * task (or one whose kind is no longer registered): the defaults apply.
     *
     * `lock_reason` says, in the kind's own words, why the status can't be
     * moved here (null when it isn't locked), so a surface that hides the status
     * control can say why instead of silently dropping it.
     *
     * @return array{key: string, actions: array<int, array<string, mixed>>, hides: array<int, string>, locks_status: bool, lock_reason: string|null, panel: array<string, mixed>|null}|null
     */
    public function describe(Task $task, ?Authenticatable $viewer, bool $asAgent = false): ?array
    {
        $kind = TaskKinds::for($task);
        if ($kind === null) {
            return null;
        }

        return [
            'key' => $kind::key(),
            'actions' => array_map(fn (TaskAction $a) => $a->toArray(), $this->offered($task, $viewer, $asAgent)),
            'hides' => array_values($kind->hides($task)),
            'locks_status' => $locks = $kind->locksStatus($task),
            'lock_reason' => $locks && ! $task->isClosed() ? $kind->lockedException($task, false)->getMessage() : null,
            'panel' => $kind->panel($task, $asAgent ? null : $viewer),
        ];
    }

    /**
     * The actions this caller is offered now. An agent (or the CLI, no person)
     * gets only the agentAllowed ones.
     *
     * @return array<int, TaskAction>
     */
    public function offered(Task $task, ?Authenticatable $viewer, bool $asAgent = false): array
    {
        $kind = TaskKinds::for($task);
        if ($kind === null) {
            return [];
        }

        $actions = $kind->actions($task, $asAgent ? null : $viewer);

        return array_values($asAgent || $viewer === null
            ? array_filter($actions, fn (TaskAction $a) => $a->agentAllowed)
            : $actions);
    }

    /**
     * Run action $key. $user is the person pressing it; null = an agent or the
     * CLI, which may run only agentAllowed actions. $agentMeta rides the
     * timeline event (who the agent was).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $agentMeta
     * @return string|null the kind's message for the person
     *
     * @throws TaskActionRefused
     */
    public function perform(Task $task, string $key, ?Authenticatable $user, array $input = [], bool $asAgent = false, array $agentMeta = []): ?string
    {
        $kind = TaskKinds::for($task);
        if ($kind === null) {
            throw new TaskActionRefused(($task->code ?: 'This task').' has no actions of its own.');
        }

        $asAgent = $asAgent || $user === null;
        $action = collect($this->offered($task, $user, $asAgent))->first(fn (TaskAction $a) => $a->key === $key);

        if ($action === null) {
            // Say WHY: an agent asking for a person's action is a 403, not a typo.
            $forPeople = $asAgent && collect($kind->actions($task, $user))->contains(fn (TaskAction $a) => $a->key === $key);
            throw $forPeople
                ? new TaskActionRefused("`{$key}` on {$task->code} is for people only: an agent may not run it.", 403)
                : new TaskActionRefused("`{$key}` is not an action {$task->code} offers you now.", $asAgent ? 403 : 422);
        }

        if ($action->isLink()) {
            throw new TaskActionRefused("{$action->label} opens a page ({$action->url}); it is not run from the task.");
        }

        foreach ($action->requiredInputs() as $required) {
            if (trim((string) ($input[$required] ?? '')) === '') {
                throw new TaskActionRefused("{$action->label} needs `{$required}`.");
            }
        }

        // Only the inputs the action declares travel on to the kind (and the timeline).
        $declared = array_map(fn (array $i) => (string) $i['key'], $action->inputs);
        $input = array_intersect_key($input, array_flip($declared));

        $message = TaskKinds::asKind(fn () => $kind->perform($task, $key, $user, $input));

        $task->recordEvent(
            TaskComment::EVENT_ACTION,
            $user !== null ? (int) $user->getAuthIdentifier() : null,
            $agentMeta + ['kind' => $kind::key(), 'action' => $key, 'input' => $input],
            $action->label.($message !== null && $message !== '' ? ': '.$message : '.'),
            true,
        );

        return $message;
    }
}
