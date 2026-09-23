<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Contracts\LaneResolver;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\AssignableUsers;
use Sgrjr\Dispatch\Support\Groups;
use Sgrjr\Dispatch\Support\NullLaneResolver;

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

    /** TASK-1193 — what actually happened; required to move the task to `resolved`. */
    public string $statusNote = '';
    public string $type = '';
    public string $priority = '';
    /**
     * The assignee slot as ONE select (W13-4): '' = unassigned, a numeric
     * user id, or 'group:<name>' for a config-defined team. Parsed in
     * saveMeta into the mutually-exclusive assignee_user_id/assignee_group
     * column pair.
     */
    public string $assignee_choice = '';
    public bool $is_public = false;
    /** W13-5: which staff see the task — 'participants' or 'staff'. */
    public string $visibility = '';
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

    /**
     * TASK-997 part A — the lane picked in the "Claim" action's picker, shown
     * only when the task is unrouted AND the current user works more than one
     * (most-specific) lane. Left blank, the service auto-joins the single
     * candidate lane or throws a "pick a lane" error listing the choices.
     */
    public string $claimLaneChoice = '';

    /** TASK-997 part A — the lane picked in the "Route to…" picker (canRoute() users, or a lane member claiming an unrouted task for their department). */
    public string $routeLaneChoice = '';

    /**
     * TASK-997 part B — the "Hand off" panel: who gets the ball, whether
     * it's an Ask, an optional note, and (only when the choice is
     * ambiguous — see DispatchTaskService::handoff()) which of the
     * recipient's lanes to use.
     */
    public string $handoffToUserId = '';

    public bool $handoffAsk = false;

    public string $handoffNote = '';

    public string $handoffLaneChoice = '';

    protected $listeners = ['commentAdded' => '$refresh'];

    public function mount(Task $task): void
    {
        Gate::authorize('view', $task);

        $this->task = $task->load(['labels', 'submitter', 'assignee', 'attachments']);

        $this->status = $task->status;
        $this->type = $task->type;
        $this->priority = $task->priority;
        $this->assignee_choice = $task->assignee_group
            ? 'group:'.$task->assignee_group
            : (string) ($task->assignee_user_id ?? '');
        $this->is_public = (bool) $task->is_public;
        $this->visibility = $task->visibility ?? Task::VISIBILITY_STAFF;
        $this->label_ids = $task->labels->pluck('id')->all();
        $this->editDescription = $task->description;
        $this->due_at = $task->due_at?->format('Y-m-d');

        // TASK-998 — opening the task IS looking at it: move this person's read
        // cursor. ⚠️ Fails soft: a cursor is a courtesy, and between a package
        // upgrade and its `migrate` the table does not exist yet — the task page
        // must not break over it.
        if (Auth::check()) {
            try {
                app(DispatchTaskService::class)->markRead($task, Auth::user());
            } catch (\Throwable) {
                // nothing to do — the next open moves it
            }
        }
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
            'assignee_choice' => 'nullable|string',
            'is_public' => 'boolean',
            'visibility' => 'required|in:'.implode(',', Task::VISIBILITIES),
            'label_ids' => 'array',
            'label_ids.*' => 'integer',
            'editDescription' => 'nullable|string|max:20000',
            'due_at' => 'nullable|date',
        ]);

        // Captured BEFORE any mutation below — the notifier routing (N3)
        // needs the pre-save values regardless of what else changed.
        $oldStatus = $this->task->status;
        $oldAssigneeId = $this->task->assignee_user_id;
        $oldAssigneeGroup = $this->task->assignee_group;

        $changes = [];
        $statusChanged = false;
        $assigneeChanged = false;

        if ($this->task->status !== $this->status) {
            // TASK-1193 — refused BEFORE any mutation, so nothing half-saves.
            if ($taskClass::requiresStatusNote($this->status) && trim($this->statusNote) === '') {
                $this->addError('statusNote', 'Say what actually happened: Resolved means dealt with, but not as written.');

                return;
            }

            $changes[] = ['status', $this->task->status, $this->status];
            $this->task->status = $this->status;
            $this->task->withStatusNote($this->statusNote);
            $statusChanged = true;
        }
        if ($this->task->type !== $this->type) {
            $this->task->type = $this->type;
        }
        if ($this->task->priority !== $this->priority) {
            $this->task->priority = $this->priority;
        }
        // W13-4: parse the single choice into the mutually-exclusive
        // user/group column pair. A group name is validated against config —
        // a stale option (group removed from dispatch.groups) errors instead
        // of silently storing an unroutable assignment.
        $newAssigneeId = null;
        $newAssigneeGroup = null;
        if (str_starts_with($this->assignee_choice, 'group:')) {
            $newAssigneeGroup = substr($this->assignee_choice, 6);
            if (! Groups::exists($newAssigneeGroup)) {
                $this->addError('assignee_choice', 'That team is no longer configured.');

                return;
            }
        } elseif ($this->assignee_choice !== '') {
            $newAssigneeId = (int) $this->assignee_choice;
        }

        $groupChanged = ($this->task->assignee_group ?? null) !== $newAssigneeGroup;
        if ($this->task->assignee_user_id !== $newAssigneeId || $groupChanged) {
            $changes[] = [
                'assignee_user_id',
                $this->task->assignee_group ? 'group:'.$this->task->assignee_group : $this->task->assignee_user_id,
                $newAssigneeGroup ? 'group:'.$newAssigneeGroup : $newAssigneeId,
            ];
            $this->task->assignee_user_id = $newAssigneeId;
            $this->task->assignee_group = $newAssigneeGroup;
            $assigneeChanged = true;
        }
        if ((bool) $this->task->is_public !== $this->is_public) {
            $changes[] = ['is_public', (bool) $this->task->is_public, $this->is_public];
            $this->task->is_public = $this->is_public;
        }
        if (($this->task->visibility ?? Task::VISIBILITY_STAFF) !== $this->visibility) {
            $changes[] = ['visibility', $this->task->visibility, $this->visibility];
            $this->task->visibility = $this->visibility;
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
                    'visibility' => $to === Task::VISIBILITY_STAFF
                        ? 'Shared with all staff.'
                        : 'Restricted to participants (submitter, assignee, watchers).',
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
                    'visibility' => TaskComment::EVENT_VISIBILITY_CHANGE,
                    'labels' => TaskComment::EVENT_LABEL_ADDED,
                    default => TaskComment::EVENT_COMMENT,
                }
                : TaskComment::EVENT_COMMENT;

            $this->task->recordEvent(
                $eventType,
                Auth::id(),
                $statusChanged ? $this->task->statusChangeMeta(['changes' => $changes]) : ['changes' => $changes],
                $statusChanged ? $this->task->statusChangeBody(implode(' ', $messages)) : implode(' ', $messages),
            );
        }
        $this->statusNote = '';

        // N3: notifier routing replaces the old ad-hoc submitter-only email.
        // Each hook independently fans out to submitter/assignee/watchers per
        // the bound DispatchNotifier's own policy; the contract guarantees it
        // never throws.
        if ($statusChanged) {
            app(DispatchNotifier::class)->taskStatusChanged($this->task, $oldStatus, $this->task->status, Auth::user());
            // TASK-997 part B — "closing a blocker notifies the next
            // holder." A no-op unless $this->task just went terminal AND
            // has dependents.
            app(DispatchTaskService::class)->notifyDependentsOfClosure($this->task, Auth::id());
        }
        if ($assigneeChanged) {
            $notifier = app(DispatchNotifier::class);

            if ($this->task->assignee_group !== null) {
                // W13-4: group assignment — duck-typed optional hook, same
                // posture as watcherAdded (a contract change would break
                // host notifiers).
                if (method_exists($notifier, 'taskAssignedGroup')) {
                    $notifier->taskAssignedGroup($this->task, $oldAssigneeGroup, $this->task->assignee_group, Auth::user());
                }
            } else {
                $notifier->taskAssigned($this->task, $oldAssigneeId, $this->task->assignee_user_id, Auth::user());
            }
        }

        $this->task->refresh()->load(['labels', 'submitter', 'assignee', 'attachments']);

        $this->dispatch('task-saved');
    }

    /**
     * Start watching this task for the current user (F4). A fresh watch
     * subscribes with the every-update default; preferences are edited via
     * the popover once watching (W13-1).
     *
     * NOT named `watch()`: Livewire 3's `$wire` proxy resolves an `aliases`
     * map BEFORE it looks for a component method, and that map contains
     * `watch => $watch` (livewire/livewire 3.7.3, dist/livewire.esm.js:8219).
     * So `wire:click="watch"` never reached this method — it resolved to
     * Livewire's own `$watch(path, callback)`, which Alpine then invoked with
     * no arguments, and `dataGet(reactive, undefined)` threw
     * "Cannot read properties of undefined (reading 'split')". The other
     * reserved names are on/el/id/js/get/set/call/hook/commit/entangle/
     * dispatch/dispatchTo/dispatchSelf/upload/uploadMultiple/removeUpload/
     * cancelUpload — avoid all of them for Livewire action methods.
     * `unwatch` is not reserved, so it stays as-is.
     */
    public function startWatching(): void
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
     * TASK-997 part A, R15 — claim this task for the current user. Gated the
     * same as the meta editor (`update` — staff only): claiming is a routing
     * action, not a new visibility surface. See
     * DispatchTaskService::claimForUser() for the auto-join-a-lane semantics;
     * a "pick a lane" or "not one of your lanes" rejection surfaces as a
     * field error on the picker rather than a hard failure.
     */
    public function claimForSelf(): void
    {
        Gate::authorize('update', $this->task);

        try {
            $this->task = app(DispatchTaskService::class)->claimForUser(
                $this->task,
                Auth::user(),
                $this->claimLaneChoice !== '' ? $this->claimLaneChoice : null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addError('claimLaneChoice', $e->getMessage());

            return;
        }

        $this->claimLaneChoice = '';
        $this->task->refresh()->load(['labels', 'submitter', 'assignee', 'attachments']);
        $this->dispatch('task-saved');
    }

    /**
     * TASK-997 part A, R15 — route this task into `$routeLaneChoice`. An
     * admin (LaneResolver::canRoute()) may route any task into any lane; a
     * lane member may only pull an UNROUTED task into one of their own
     * lanes — see DispatchTaskService::routeToLane() for the full rule.
     * Unauthorized/invalid attempts surface as a field error, not a hard
     * failure, matching claimForSelf()'s posture.
     */
    public function routeTask(): void
    {
        Gate::authorize('update', $this->task);

        $this->validate(['routeLaneChoice' => 'required|string']);

        try {
            $this->task = app(DispatchTaskService::class)->routeToLane(
                $this->task,
                $this->routeLaneChoice,
                Auth::user(),
            );
        } catch (\InvalidArgumentException|AuthorizationException $e) {
            $this->addError('routeLaneChoice', $e->getMessage());

            return;
        }

        $this->routeLaneChoice = '';
        $this->task->refresh()->load(['labels', 'submitter', 'assignee', 'attachments']);
        $this->dispatch('task-saved');
    }

    /**
     * TASK-997 part B — pass or ask. Errors (an ambiguous/invalid lane pick)
     * surface on `handoffLaneChoice`, matching claimForSelf()/routeTask()'s
     * posture. A cross-lane/no-lane PASS returns a DIFFERENT task (the
     * continuation) — the ball is no longer here, so the page follows it
     * (mirroring mergeInto()'s redirect). A same-lane pass or an ask returns
     * THIS task (moved, or now blocked) — stay put and refresh in place.
     */
    public function handoffTask(): void
    {
        Gate::authorize('update', $this->task);

        $this->validate(['handoffToUserId' => 'required']);

        $to = AssignableUsers::query()->whereKey($this->handoffToUserId)->first();
        if ($to === null) {
            $this->addError('handoffToUserId', 'Pick a user from the list.');

            return;
        }

        $originalCode = $this->task->code;

        try {
            $result = app(DispatchTaskService::class)->handoff($this->task, $to, Auth::user(), array_filter([
                'ask' => $this->handoffAsk,
                'lane' => $this->handoffLaneChoice !== '' ? $this->handoffLaneChoice : null,
                'note' => $this->handoffNote !== '' ? $this->handoffNote : null,
            ], fn ($v) => $v !== null && $v !== false));
        } catch (\InvalidArgumentException $e) {
            $this->addError('handoffLaneChoice', $e->getMessage());

            return;
        }

        $this->handoffToUserId = '';
        $this->handoffAsk = false;
        $this->handoffNote = '';
        $this->handoffLaneChoice = '';

        if ($result->code !== $originalCode) {
            $this->redirect(route('dispatch.show', $result), navigate: false);

            return;
        }

        $this->task = $result->load(['labels', 'submitter', 'assignee', 'attachments', 'blockedBy', 'blocks']);
        $this->dispatch('task-saved');
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

        // TASK-997 part A — lane display + routing actions. `$laneActive`
        // mirrors the same "is a real LaneResolver bound?" check TaskBoard
        // uses for swimlanes: with the inert NullLaneResolver, every task is
        // permanently unrouted and every list/picker below is empty, so the
        // whole panel stays hidden rather than showing permanently-useless
        // controls on a host that hasn't adopted lanes.
        $laneResolver = app(LaneResolver::class);
        $laneActive = ! ($laneResolver instanceof NullLaneResolver);
        $user = Auth::user();
        $myLaneOptions = [];
        $canRouteLane = false;
        $allLaneOptions = [];

        if ($laneActive && $this->canEdit() && $user !== null) {
            $myLaneOptions = collect($laneResolver->lanesFor($user))
                ->mapWithKeys(fn ($l) => [$l => $laneResolver->label($l) ?? $l])
                ->all();
            $canRouteLane = $laneResolver->canRoute($user);
            if ($canRouteLane) {
                $allLaneOptions = collect($laneResolver->lanes())
                    ->mapWithKeys(fn ($l) => [$l => $laneResolver->label($l) ?? $l])
                    ->all();
            }
        }

        return view('dispatch::livewire.task-show', [
            'watchPrefs' => Auth::id() ? $this->task->watchPreferencesFor((int) Auth::id()) : null,
            'assigneeOptions' => $assigneeOptions,
            'groupOptions' => $this->canEdit() ? Groups::names() : [],
            'allLabels' => $labelClass::orderBy('name')->get(),
            'statuses' => $taskClass::statuses(),
            'types' => $taskClass::types(),
            'priorities' => $taskClass::priorities(),
            'statusLabels' => $taskClass::statusLabels(),
            'typeLabels' => $taskClass::typeLabels(),
            'priorityLabels' => $taskClass::priorityLabels(),
            // TASK-997 part A.
            'laneActive' => $laneActive,
            'laneLabel' => $this->task->lane !== null ? ($laneResolver->label($this->task->lane) ?? $this->task->lane) : null,
            'myLaneOptions' => $myLaneOptions,
            'canRouteLane' => $canRouteLane,
            'allLaneOptions' => $allLaneOptions,
            // TASK-997 part B — the ball. Same pool as the assignee dropdown;
            // the panel itself works with the inert NullLaneResolver too (a
            // same-lane pass degrades to a plain reassignment — see
            // DispatchTaskService::sameLane()), so it is NOT gated on
            // $laneActive.
            'handoffOptions' => $this->canEdit() ? AssignableUsers::options() : collect(),
            'blockedByTasks' => $this->task->blockedBy()->get(['dispatch_tasks.id', 'dispatch_tasks.code', 'dispatch_tasks.title', 'dispatch_tasks.status']),
            'blocksTasks' => $this->task->blocks()->get(['dispatch_tasks.id', 'dispatch_tasks.code', 'dispatch_tasks.title', 'dispatch_tasks.status']),
        ])->layout('dispatch::components.layout');
    }
}
