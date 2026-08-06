<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\AssignableUsers;

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

    // The full description body, editable inline (F7). A change memorializes
    // the PREVIOUS body as a hidden timeline event before it's overwritten.
    public ?string $editDescription = null;

    // Nullable due date, bound to an <input type="date"> (F6) — kept as the
    // 'Y-m-d' string the input works with rather than a Carbon instance.
    public ?string $due_at = null;

    // Merge-into-duplicate target (F5, staff/`delete`-ability only).
    public string $mergeTargetCode = '';

    // W13-2 "watch on behalf of": the picked user id from the CC select.
    public ?int $ccUserId = null;

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
        $this->editDescription = $task->description;
        $this->due_at = $task->due_at?->format('Y-m-d');
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
            'status' => 'required|in:'.implode(',', $taskClass::statuses()),
            'type' => 'required|in:'.implode(',', $taskClass::types()),
            'priority' => 'required|in:'.implode(',', $taskClass::priorities()),
            'assignee_user_id' => 'nullable|integer',
            'is_public' => 'boolean',
            'label_ids' => 'array',
            'label_ids.*' => 'integer',
            'editDescription' => 'nullable|string|max:20000',
            'due_at' => 'nullable|date',
        ]);

        // Captured BEFORE any mutation below — the notifier routing (N3)
        // needs the pre-save values regardless of what else changed.
        $oldStatus = $this->task->status;
        $oldAssigneeId = $this->task->assignee_user_id;

        $changes = [];
        $statusChanged = false;
        $assigneeChanged = false;

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
            $assigneeChanged = true;
        }
        if ((bool) $this->task->is_public !== $this->is_public) {
            $changes[] = ['is_public', (bool) $this->task->is_public, $this->is_public];
            $this->task->is_public = $this->is_public;
        }

        // F7: memorialize the PREVIOUS description body as a hidden
        // (is_internal) timeline event BEFORE it's overwritten, so the raw
        // history is never lost even though only the latest body is shown.
        if ((string) $this->task->description !== (string) $this->editDescription) {
            $previousDescription = $this->task->description;

            $this->task->recordEvent(
                TaskComment::EVENT_DESCRIPTION_EDITED,
                Auth::id(),
                ['edited_by' => Auth::id()],
                $previousDescription,
                true,
            );

            $this->task->description = $this->editDescription !== '' ? $this->editDescription : null;
        }

        // F6: due date, compared/stored as a date-only string.
        $newDueAt = $this->due_at !== null && $this->due_at !== '' ? $this->due_at : null;
        $oldDueAt = $this->task->due_at?->toDateString();
        if ($oldDueAt !== $newDueAt) {
            $changes[] = ['due_at', $oldDueAt, $newDueAt];
            $this->task->due_at = $newDueAt;
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
                    'due_at' => $to ? "Due date set to {$to}." : 'Due date cleared.',
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

            $this->task->recordEvent(
                $eventType,
                Auth::id(),
                ['changes' => $changes],
                implode(' ', $messages),
            );
        }

        // N3: notifier routing replaces the old ad-hoc submitter-only email.
        // Each hook independently fans out to submitter/assignee/watchers per
        // the bound DispatchNotifier's own policy; the contract guarantees it
        // never throws.
        if ($statusChanged) {
            app(DispatchNotifier::class)->taskStatusChanged($this->task, $oldStatus, $this->task->status, Auth::user());
        }
        if ($assigneeChanged) {
            app(DispatchNotifier::class)->taskAssigned($this->task, $oldAssigneeId, $this->task->assignee_user_id, Auth::user());
        }

        $this->task->refresh()->load(['labels', 'submitter', 'assignee', 'attachments']);

        $this->dispatch('task-saved');
    }

    /**
     * Toggle watching this task for the current user (F4). A fresh watch
     * subscribes with the every-update default; preferences are edited via
     * the popover once watching (W13-1).
     */
    public function watch(): void
    {
        Gate::authorize('watch', $this->task);

        $this->task->watch(Auth::id());
    }

    public function unwatch(): void
    {
        Gate::authorize('watch', $this->task);

        $this->task->unwatch(Auth::id());
    }

    /**
     * Switch the current user's watch mode: 'any' (every update) or
     * 'status_change' (status changes only, optionally narrowed by
     * toggleWatchStatus below). No-op unless actually watching.
     */
    public function setWatchMode(string $mode): void
    {
        Gate::authorize('watch', $this->task);

        if (! in_array($mode, [Task::WATCH_ANY, Task::WATCH_STATUS_CHANGE], true)) {
            return;
        }

        // Switching modes deliberately clears any status subset — 'any'
        // ignores it, and a fresh 'status_change' starts at "all statuses".
        $this->task->setWatchPreferences(Auth::id(), $mode);
    }

    /**
     * Check/uncheck one status in the current user's 'status_change' subset.
     * Unchecking the last one falls back to "all status changes" (an empty
     * subset stores as null) rather than a never-notify dead state.
     */
    public function toggleWatchStatus(string $status): void
    {
        Gate::authorize('watch', $this->task);

        /** @var class-string<Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        if (! in_array($status, $taskClass::statuses(), true)) {
            return;
        }

        $prefs = $this->task->watchPreferencesFor(Auth::id());
        if ($prefs === null || $prefs['notify_on'] !== Task::WATCH_STATUS_CHANGE) {
            return;
        }

        // A null subset means "all statuses" and renders all boxes checked —
        // so the first click UNCHECKS (all-minus-clicked), mirroring the
        // filter popovers. Kept in vocab order; a full or emptied set stores
        // back as null (= all — empty would otherwise be a never-notify dead
        // state, and the panel documents that fallback).
        $vocab = $taskClass::statuses();
        $current = $prefs['statuses'] ?? $vocab;
        $next = in_array($status, $current, true)
            ? array_diff($current, [$status])
            : array_merge($current, [$status]);
        $next = array_values(array_intersect($vocab, $next));

        $this->task->setWatchPreferences(
            Auth::id(),
            Task::WATCH_STATUS_CHANGE,
            count($next) === count($vocab) ? null : $next,
        );
    }

    /**
     * W13-2 "watch on behalf of" (CC): staff picks a teammate; that teammate
     * is IMMEDIATELY a watcher (every-update default prefs) — no invitation
     * or opt-in state, by design. They decline by "Stop watching". The pool
     * is the same assignable-users seam as the assignee dropdown: CC'ing a
     * placeholder/customer account makes no more sense than assigning one.
     * Memorialized on the timeline (internal) and the teammate gets a
     * heads-up via the notifier's optional duck-typed watcherAdded hook.
     */
    public function addWatcher(): void
    {
        Gate::authorize('watch', $this->task);

        if (! $this->ccUserId) {
            return;
        }

        $user = AssignableUsers::query()->whereKey($this->ccUserId)->first();

        if ($user === null) {
            $this->addError('ccUserId', 'Pick a user from the list.');

            return;
        }

        $this->ccUserId = null;

        if ($this->task->isWatchedBy((int) $user->id)) {
            return; // already watching — nothing to record or send
        }

        $this->task->watch((int) $user->id);

        $this->task->recordEvent(
            TaskComment::EVENT_WATCHER_ADDED,
            Auth::id(),
            ['watcher_user_id' => (int) $user->id, 'watcher_name' => (string) $user->name],
            "Added {$user->name} as a watcher.",
            true,
        );

        // Optional hook — see MailNotifier::watcherAdded. A host notifier
        // without the method simply sends nothing.
        $notifier = app(DispatchNotifier::class);
        if (method_exists($notifier, 'watcherAdded')) {
            $notifier->watcherAdded($this->task, $user, Auth::user());
        }

        // The action's own re-render picks up the fresh watcher list; the
        // thread is a separate component and shows the event on next load
        // (it's internal bookkeeping, not conversation).
        $this->task->unsetRelation('watchers');
    }

    /**
     * Mark this task a duplicate and fold it into another (F5). The target is
     * resolved by code through the SAME visibility scope used everywhere else
     * in the package (DispatchGate::scopeVisible), so staff can never merge
     * into a task they aren't allowed to see. This task is always the LOSER
     * and the resolved target is always the WINNER, matching
     * DispatchTaskService::merge(Task $loser, Task $winner, ...)'s signature.
     */
    public function mergeInto(): void
    {
        Gate::authorize('delete', $this->task);

        $this->validate([
            'mergeTargetCode' => 'required|string',
        ]);

        $code = trim($this->mergeTargetCode);

        if (strcasecmp($code, $this->task->code) === 0) {
            $this->addError('mergeTargetCode', 'A task cannot be merged into itself.');

            return;
        }

        /** @var class-string<Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        $query = $taskClass::query()->whereRaw('LOWER(code) = ?', [strtolower($code)]);
        app(DispatchGate::class)->scopeVisible($query, Auth::user());

        /** @var Task|null $winner */
        $winner = $query->first();

        if ($winner === null) {
            $this->addError('mergeTargetCode', "No visible task found with code \"{$code}\".");

            return;
        }

        $merged = app(DispatchTaskService::class)->merge($this->task, $winner, Auth::id());

        $this->redirect(route('dispatch.show', $merged), navigate: false);
    }

    public function render()
    {
        /** @var class-string<Task> $taskClass */
        $taskClass = config('dispatch.models.task');
        $labelClass = config('dispatch.models.label');

        $assigneeOptions = $this->canEdit()
            ? AssignableUsers::options()
            : collect();

        return view('dispatch::livewire.task-show', [
            'watchPrefs' => Auth::id() ? $this->task->watchPreferencesFor((int) Auth::id()) : null,
            'assigneeOptions' => $assigneeOptions,
            'allLabels' => $labelClass::orderBy('name')->get(),
            'statuses' => $taskClass::statuses(),
            'types' => $taskClass::types(),
            'priorities' => $taskClass::priorities(),
            'statusLabels' => $taskClass::statusLabels(),
            'typeLabels' => $taskClass::typeLabels(),
            'priorityLabels' => $taskClass::priorityLabels(),
        ])->layout('dispatch::components.layout');
    }
}
