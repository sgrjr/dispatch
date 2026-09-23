<?php

namespace Sgrjr\Dispatch\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Kinds\BaseTaskKind;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Support\TaskAction;

/** A test kind whose work lives in its own tool: the task only links to it (TASK-1190). */
class FormLinkKind extends BaseTaskKind
{
    public static function key(): string
    {
        return 'form_link';
    }

    public function actions(Task $task, ?Authenticatable $viewer): array
    {
        return [TaskAction::link('open_form', 'Review & Mark Done', 'https://example.test/requests/'.($task->context['form_link']['id'] ?? 0))];
    }

    public function hides(Task $task): array
    {
        return ['status'];
    }

    public function locksStatus(Task $task): bool
    {
        return true;
    }
}
