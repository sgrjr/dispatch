<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Full-page Kanban board. Columns are Task::STATUSES in order; cards within a
 * column are ordered by priority (blocker>high>medium>low) then position.
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

    public function mount(): void
    {
        Gate::authorize('viewAny', config('dispatch.models.task'));
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

        if (! in_array($toStatus, $taskClass::STATUSES, true)) {
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
        }
    }

    public function render()
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');
        $labelClass = config('dispatch.models.label');

        $query = $taskClass::query()->with(['labels', 'assignee']);
        app(DispatchGate::class)->scopeVisible($query, Auth::user());

        if (in_array($this->typeFilter, $taskClass::TYPES, true)) {
            $query->where('type', $this->typeFilter);
        }
        if (in_array($this->priorityFilter, $taskClass::PRIORITIES, true)) {
            $query->where('priority', $this->priorityFilter);
        }
        if ($this->labelFilter !== '') {
            $label = $this->labelFilter;
            $query->whereHas('labels', fn ($q) => $q->where('name', $label));
        }

        $priorityOrder = "CASE priority WHEN 'blocker' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 99 END";

        $byStatus = $query
            ->orderByRaw($priorityOrder)
            ->orderBy('position')
            ->orderBy('code')
            ->get()
            ->groupBy('status');

        return view('dispatch::livewire.task-board', [
            'columns' => $taskClass::STATUSES,
            'byStatus' => $byStatus,
            'labels' => $labelClass::orderBy('name')->get(),
            'types' => $taskClass::TYPES,
            'priorities' => $taskClass::PRIORITIES,
        ])->layout('dispatch::components.layout');
    }
}
