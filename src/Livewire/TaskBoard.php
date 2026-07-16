<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Full-page Kanban board. Columns are Task::statuses() in configured order;
 * cards within a column are ordered by priority (blocker>high>medium>low,
 * per Task::prioritySql()) then position/code — or by position/code alone
 * when `dispatch.board.manual_order` is enabled, so a manual drag sticks
 * instead of being resorted by priority on every render.
 *
 * DECISION: DispatchGate::isStaff()'s doc comment explicitly names "board,
 * list" as the staff surfaces it gates, so mount() enforces the `viewAny`
 * ability (-> DispatchGate::isStaff()) rather than leaving the board open to
 * any authenticated user. Non-staff submitters use MySubmissions instead.
 */
class TaskBoard extends Component
{
    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';

    #[Url(as: 'priority', except: '')]
    public string $priorityFilter = '';

    #[Url(as: 'label', except: '')]
    public string $labelFilter = '';

    /**
     * "Load all" override for the done column's `board.done_limit` cap (F8).
     */
    public bool $showAllDone = false;

    /**
     * Opt-in multi-select mode for bulk actions (F3 — minimal slice: bulk
     * status set + bulk decline only, no bulk label/assign yet). Cards are
     * rendered non-draggable while this is on (see the blade's `draggable`
     * attribute), so select-mode can never race with the native HTML5 DnD
     * dispatch.js wires up — that JS file is untouched by this feature.
     */
    public bool $selectMode = false;

    /** @var array<int,int|string> */
    public array $selectedIds = [];

    public string $bulkStatus = '';

    public function mount(): void
    {
        // Non-staff have no board — redirect them to their own submissions
        // instead of 403 (staff-only surface; the portal is the non-staff view).
        if (! app(DispatchGate::class)->isStaff(Auth::user())) {
            $this->redirect(route(config('dispatch.routes.name_prefix', 'dispatch.').'portal'));

            return;
        }
    }

    /**
     * Persist a drag-and-drop move fired from dispatch.js's native HTML5 DnD
     * handler. $toPosition is the zero-based index the card was dropped at
     * within the (visible, currently rendered) target column.
     *
     * DECISION: the contract's signature is (taskId, toStatus, toPosition) —
     * a single target index — unlike rupkeep's (code, toStatus, orderedCodes)
     * full-list approach. To honor position as a contiguous 0..n sequence we
     * recompute the whole target column's ordering here: pull every OTHER
     * visible card in that column (scoped via DispatchGate, matching how the
     * board itself is rendered), splice the moved task in at $toPosition, and
     * renumber sequentially. This keeps `position` consistent without trusting
     * the client to enumerate the full column.
     */
    public function moveCard($taskId, string $toStatus, int $toPosition): void
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        if (! in_array($toStatus, $taskClass::statuses(), true)) {
            return;
        }

        $gate = app(DispatchGate::class);
        $user = Auth::user();

        $query = $taskClass::query()->whereKey($taskId);
        $gate->scopeVisible($query, $user);
        $task = $query->first();

        if (! $task) {
            return;
        }

        Gate::authorize('update', $task);

        $fromStatus = $task->status;

        $siblingsQuery = $taskClass::query()->where('status', $toStatus);
        if ($fromStatus === $toStatus) {
            $siblingsQuery->whereKeyNot($task->getKey());
        }
        $gate->scopeVisible($siblingsQuery, $user);
        $siblings = $siblingsQuery->orderBy('position')->get()->all();

        $toPosition = max(0, min($toPosition, count($siblings)));
        array_splice($siblings, $toPosition, 0, [$task]);

        foreach ($siblings as $index => $sibling) {
            $sibling->position = $index;
            if ($sibling->is($task)) {
                $sibling->status = $toStatus;
            }
            $sibling->save();
        }

        if ($fromStatus !== $toStatus) {
            $task->recordEvent(
                TaskComment::EVENT_STATUS_CHANGE,
                Auth::id(),
                ['from' => $fromStatus, 'to' => $toStatus],
                "Status changed from `{$fromStatus}` to `{$toStatus}` (via board).",
            );

            // N1 gap fix: recording the timeline event above notified nobody —
            // this is the same taskStatusChanged hook every other status-change
            // path in the package fires (see TaskShow::saveMeta()), now wired
            // up for a board-driven move too. DispatchNotifier never throws.
            app(DispatchNotifier::class)->taskStatusChanged($task, $fromStatus, $toStatus, $user);
        }
    }

    /**
     * Toggle select-mode on/off, clearing any in-flight selection/bulk choice.
     */
    public function toggleSelectMode(): void
    {
        $this->selectMode = ! $this->selectMode;
        $this->selectedIds = [];
        $this->bulkStatus = '';
    }

    /**
     * Toggle the done column between its `board.done_limit` cap and showing
     * every visible done task (F8's "load all" override).
     */
    public function toggleShowAllDone(): void
    {
        $this->showAllDone = ! $this->showAllDone;
    }

    /**
     * Bulk-set status on every selected task the acting user may `update`.
     */
    public function bulkApplyStatus(): void
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        if ($this->bulkStatus === '' || ! in_array($this->bulkStatus, $taskClass::statuses(), true)) {
            return;
        }

        $this->applyBulkStatus($this->bulkStatus, 'update');
    }

    /**
     * Bulk-decline every selected task the acting user may `delete` — reusing
     * the package's existing convention (TaskPolicy::delete === canSeeAll)
     * for who may take a task out of play, rather than the softer `update`.
     */
    public function bulkDecline(): void
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        if (! in_array('declined', $taskClass::statuses(), true)) {
            return;
        }

        $this->applyBulkStatus('declined', 'delete');
    }

    /**
     * Shared bulk status-change path for bulkApplyStatus()/bulkDecline().
     * Unauthorized/no-op tasks are silently skipped rather than aborting the
     * whole batch, matching moveCard()'s "silent no-op on disallowed" style.
     */
    private function applyBulkStatus(string $toStatus, string $ability): void
    {
        if ($this->selectedIds === []) {
            return;
        }

        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');
        $gate = app(DispatchGate::class);
        $user = Auth::user();
        $notifier = app(DispatchNotifier::class);

        $query = $taskClass::query()->whereIn('id', $this->selectedIds);
        $gate->scopeVisible($query, $user);

        foreach ($query->get() as $task) {
            if ($task->status === $toStatus || ! Gate::allows($ability, $task)) {
                continue;
            }

            $fromStatus = $task->status;
            $task->status = $toStatus;
            $task->save();

            $task->recordEvent(
                TaskComment::EVENT_STATUS_CHANGE,
                Auth::id(),
                ['from' => $fromStatus, 'to' => $toStatus],
                "Status changed from `{$fromStatus}` to `{$toStatus}` (bulk board action).",
            );

            $notifier->taskStatusChanged($task, $fromStatus, $toStatus, $user);
        }

        $this->selectedIds = [];
        $this->bulkStatus = '';
    }

    public function render()
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');
        $labelClass = config('dispatch.models.label');
        $gate = app(DispatchGate::class);
        $user = Auth::user();

        $applyScopeAndFilters = function ($query) use ($taskClass, $gate, $user) {
            $gate->scopeVisible($query, $user);

            if (in_array($this->typeFilter, $taskClass::types(), true)) {
                $query->where('type', $this->typeFilter);
            }
            if (in_array($this->priorityFilter, $taskClass::priorities(), true)) {
                $query->where('priority', $this->priorityFilter);
            }
            if ($this->labelFilter !== '') {
                $label = $this->labelFilter;
                $query->whereHas('labels', fn ($q) => $q->where('name', $label));
            }

            return $query;
        };

        $manualOrder = (bool) config('dispatch.board.manual_order', false);

        $applyColumnOrder = function ($query) use ($taskClass, $manualOrder) {
            if ($manualOrder) {
                return $query->orderBy('position')->orderBy('code');
            }

            return $query->orderByRaw($taskClass::prioritySql())->orderBy('position')->orderBy('code');
        };

        // Non-done columns: the same single grouped query as before, minus
        // whatever the done column claims below.
        $nonDoneQuery = $applyScopeAndFilters(
            $taskClass::query()->with(['labels', 'assignee'])->where('status', '!=', 'done')
        );
        $byStatus = $applyColumnOrder($nonDoneQuery)->get()->groupBy('status');

        // Done column (F8): capped to `board.done_limit` most-recently-touched
        // tasks so a long-lived board doesn't drag in years of history. The
        // cap is a SELECTION step (pick the N freshest by updated_at); once
        // selected, that subset is re-ordered with the exact same rule as
        // every other column (manual position, or priority) so `manual_order`
        // still means something inside "done" too. Visibility/filters are
        // scoped identically to the non-done query above via the same closure.
        $doneLimit = (int) config('dispatch.board.done_limit', 50);
        $hasDoneStatus = in_array('done', $taskClass::statuses(), true);

        $doneTotal = 0;
        $doneItems = collect();

        if ($hasDoneStatus) {
            $doneScoped = $applyScopeAndFilters($taskClass::query()->where('status', 'done'));
            $doneTotal = (clone $doneScoped)->count();

            $doneSelectQuery = (clone $doneScoped)->orderByDesc('updated_at');
            if (! $this->showAllDone && $doneLimit > 0) {
                $doneSelectQuery->limit($doneLimit);
            }
            $doneIds = $doneSelectQuery->pluck('id')->all();

            if ($doneIds !== []) {
                $doneItems = $applyColumnOrder(
                    $taskClass::query()->with(['labels', 'assignee'])->whereIn('id', $doneIds)
                )->get();
            }
        }

        $byStatus->put('done', $doneItems);

        return view('dispatch::livewire.task-board', [
            'columns' => $taskClass::statuses(),
            'statusLabels' => $taskClass::statusLabels(),
            'byStatus' => $byStatus,
            'labels' => $labelClass::orderBy('name')->get(),
            'types' => $taskClass::types(),
            'priorities' => $taskClass::priorities(),
            'doneTotal' => $doneTotal,
            'doneShowing' => $doneItems->count(),
            'doneLimit' => $doneLimit,
            'stalenessEnabled' => (bool) config('dispatch.staleness.enabled', true),
            'staleThresholdDays' => (int) config('dispatch.staleness.threshold_days', 42),
        ])->layout('dispatch::components.layout');
    }
}
