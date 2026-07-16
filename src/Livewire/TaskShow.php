<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Full-page task detail: badges, attachment gallery, staff meta editor, and
 * the embedded TaskThread. Route: dispatch.show ({task:code}).
 *
 * DECISION: rupkeep's TaskShow took a `$portal` bool to switch behavior; the
 * contract gives dispatch.show a single route with no portal param, so this
 * component has one code path for everyone — visibility/edit rights are
 * entirely down to Gate::authorize('view'|'update', $task), which already
 * folds in DispatchGate::scopeVisible for the view check.
 */
class TaskShow extends Component
{
    public Task $task;

    // Editable fields (staff only — see canEdit()).
    public string $status = '';
    public string $type = '';
    public string $priority = '';
    public ?int $assignee_user_id = null;
    public bool $is_public = false;
    /** @var array<int> */
    public array $label_ids = [];

    protected $listeners = ['commentAdded' => '$refresh'];

    public function mount(Task $task): void
    {
        Gate::authorize('view', $task);

        $this->task = $task->load(['labels', 'submitter', 'assignee', 'attachments']);

        $this->status = $task->status;
        $this->type = $task->type;
        $this->priority = $task->priority;
        $this->assignee_user_id = $task->assignee_user_id;
        $this->is_public = (bool) $task->is_public;
        $this->label_ids = $task->labels->pluck('id')->all();
    }

    public function canEdit(): bool
    {
        return Gate::allows('update', $this->task);
    }

    public function saveMeta(): void
    {
        Gate::authorize('update', $this->task);

        /** @var class-string<Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        $this->validate([
            'status' => 'required|in:'.implode(',', $taskClass::STATUSES),
            'type' => 'required|in:'.implode(',', $taskClass::TYPES),
            'priority' => 'required|in:'.implode(',', $taskClass::PRIORITIES),
            'assignee_user_id' => 'nullable|integer',
            'is_public' => 'boolean',
            'label_ids' => 'array',
            'label_ids.*' => 'integer',
        ]);

        $changes = [];
        $statusChanged = false;

        if ($this->task->status !== $this->status) {
            $changes[] = ['status', $this->task->status, $this->status];
            $this->task->status = $this->status;
            $statusChanged = true;
        }
        if ($this->task->type !== $this->type) {
            $this->task->type = $this->type;
        }
        if ($this->task->priority !== $this->priority) {
            $this->task->priority = $this->priority;
        }
        if ($this->task->assignee_user_id !== $this->assignee_user_id) {
            $changes[] = ['assignee_user_id', $this->task->assignee_user_id, $this->assignee_user_id];
            $this->task->assignee_user_id = $this->assignee_user_id;
        }
        if ((bool) $this->task->is_public !== $this->is_public) {
            $changes[] = ['is_public', (bool) $this->task->is_public, $this->is_public];
            $this->task->is_public = $this->is_public;
        }

        $this->task->save();

        $oldLabelIds = $this->task->labels->pluck('id')->sort()->values()->all();
        $newLabelIds = collect($this->label_ids)->sort()->values()->all();

        if ($oldLabelIds !== $newLabelIds) {
            $this->task->labels()->sync($newLabelIds);
            $changes[] = ['labels', $oldLabelIds, $newLabelIds];
        }

        if (! empty($changes)) {
            $messages = [];
            foreach ($changes as [$field, $from, $to]) {
                $messages[] = match ($field) {
                    'status' => "Status changed from `{$from}` to `{$to}`.",
                    'assignee_user_id' => 'Assignee updated.',
                    'is_public' => $to ? 'Marked public — visible to the submitter/customer.' : 'Marked private.',
                    'labels' => 'Labels updated.',
                    default => "Field `{$field}` updated.",
                };
            }

            $eventType = count($changes) === 1
                ? match ($changes[0][0]) {
                    'status' => TaskComment::EVENT_STATUS_CHANGE,
                    'assignee_user_id' => TaskComment::EVENT_ASSIGNEE_CHANGE,
                    'is_public' => TaskComment::EVENT_PUBLIC_TOGGLE,
                    'labels' => TaskComment::EVENT_LABEL_ADDED,
                    default => TaskComment::EVENT_COMMENT,
                }
                : TaskComment::EVENT_COMMENT;

            /** @var TaskComment $comment */
            $comment = $this->task->recordEvent(
                $eventType,
                Auth::id(),
                ['changes' => $changes],
                implode(' ', $messages),
            );

            // A status change is a customer-facing update per the contract —
            // notify the submitter the same way TaskThread does for a public
            // comment. See TaskThread::notifySubmitterOfUpdate() for the
            // guarded-class_exists rationale (Notifications wave not in this
            // workstream).
            if ($statusChanged) {
                $this->notifySubmitterOfUpdate($this->task, $comment);
            }
        }

        $this->task->refresh()->load(['labels', 'submitter', 'assignee', 'attachments']);

        $this->dispatch('task-saved');
    }

    protected function notifySubmitterOfUpdate(Task $task, TaskComment $comment): void
    {
        if (! config('dispatch.notifications.enabled', true)) {
            return;
        }

        $notificationClass = \Sgrjr\Dispatch\Notifications\TaskUpdate::class;
        if (! class_exists($notificationClass)) {
            return;
        }

        $submitter = $task->submitter;
        if (! $submitter || $submitter->getKey() === Auth::id()) {
            return;
        }

        $submitter->notify(new $notificationClass($task, $comment));
    }

    public function render()
    {
        /** @var class-string<Task> $taskClass */
        $taskClass = config('dispatch.models.task');
        $labelClass = config('dispatch.models.label');
        $userClass = config('dispatch.models.user');

        $assigneeOptions = $this->canEdit()
            ? $userClass::query()->orderBy('name')->limit(50)->get(['id', 'name', 'email'])
            : collect();

        return view('dispatch::livewire.task-show', [
            'assigneeOptions' => $assigneeOptions,
            'allLabels' => $labelClass::orderBy('name')->get(),
            'statuses' => $taskClass::STATUSES,
            'types' => $taskClass::TYPES,
            'priorities' => $taskClass::PRIORITIES,
        ])->layout('dispatch::components.layout');
    }
}
