<?php

namespace Sgrjr\Dispatch\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Kinds\BaseTaskKind;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\TaskKinds;
use Sgrjr\Dispatch\Support\TaskAction;

/**
 * A test task kind (TASK-1188): `context.checklist` marks it. "Tick" is open to
 * agents; "Sign off" (note required) is for people only and closes the task.
 * The status is locked; claim and assignee are hidden.
 */
class ChecklistKind extends BaseTaskKind
{
    public static function key(): string
    {
        return 'checklist';
    }

    /** File one, the way a kind's own service would. */
    public static function file(string $title): Task
    {
        return TaskKinds::asKind(fn () => app(\Sgrjr\Dispatch\Services\DispatchTaskService::class)->create([
            'title' => $title,
            'status' => 'open',
            'context' => ['checklist' => ['ticks' => 0]],
        ]));
    }

    public function actions(Task $task, ?Authenticatable $viewer): array
    {
        if ($task->isClosed()) {
            return [];
        }

        return [
            new TaskAction('tick', 'Tick', TaskAction::STYLE_SECONDARY, agentAllowed: true),
            new TaskAction('sign_off', 'Sign off', TaskAction::STYLE_PRIMARY, [
                ['key' => 'note', 'label' => 'What was checked', 'type' => 'textarea', 'required' => true],
            ]),
        ];
    }

    public function hides(Task $task): array
    {
        return ['claim', 'assignee'];
    }

    public function locksStatus(Task $task): bool
    {
        return true;
    }

    public function panel(Task $task, ?Authenticatable $viewer): ?array
    {
        return ['title' => 'Checklist', 'rows' => [['label' => 'Ticks', 'value' => (string) ($task->context['checklist']['ticks'] ?? 0)]]];
    }

    public function perform(Task $task, string $key, ?Authenticatable $user, array $input): ?string
    {
        $context = $task->context;
        if ($key === 'tick') {
            $context['checklist']['ticks'] = ($context['checklist']['ticks'] ?? 0) + 1;
            $task->context = $context;
            $task->save();

            return 'Ticked.';
        }

        if ($key === 'sign_off') {
            $context['checklist']['signed_off'] = true;
            $task->context = $context;
            $task->status = 'done';
            $task->save();

            return 'Signed off: '.$input['note'];
        }

        return parent::perform($task, $key, $user, $input);
    }
}
