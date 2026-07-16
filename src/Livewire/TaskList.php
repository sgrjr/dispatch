<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * Full-page task table: search, filters, sort, pagination, and bulk actions.
 * Same staff-only gate as TaskBoard (see the DECISION note there) — non-staff
 * submitters use MySubmissions for their own portal view.
 */
class TaskList extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';

    #[Url(as: 'priority', except: '')]
    public string $priorityFilter = '';

    #[Url(as: 'label', except: '')]
    public string $labelFilter = '';

    #[Url(as: 'sort', except: 'priority')]
    public string $sort = 'priority';

    /** @var array<int,string> Checked task ids (bulk selection). */
    public array $selected = [];

    /** Bulk action toolbar: '', 'status', 'label', 'assign', or 'decline'. */
    public string $bulkAction = '';
    public string $bulkStatusValue = '';
    public string $bulkLabelValue = '';
    public ?int $bulkAssigneeId = null;

    public function mount(): void
    {
        // Non-staff have no list — redirect them to their own submissions
        // instead of 403 (staff-only surface; the portal is the non-staff view).
        if (! app(DispatchGate::class)->isStaff(Auth::user())) {
            $this->redirect(route(config('dispatch.routes.name_prefix', 'dispatch.').'portal'));

            return;
        }
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'statusFilter', 'typeFilter', 'priorityFilter', 'labelFilter'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'typeFilter', 'priorityFilter', 'labelFilter']);
        $this->resetPage();
        $this->selected = [];
    }

    /**
     * Toggle every currently-rendered (visible) task id in/out of the
     * selection. $ids is the current page's task ids, embedded straight from
     * the view via @js() so this always matches what's on screen — no second
     * query needed.
     *
     * @param  array<int,int|string>  $ids
     */
    public function toggleSelectAllVisible(array $ids): void
    {
        $ids = array_map('strval', $ids);

        $allAlreadySelected = empty(array_diff($ids, $this->selected));

        $this->selected = $allAlreadySelected
            ? array_values(array_diff($this->selected, $ids))
            : array_values(array_unique(array_merge($this->selected, $ids)));
    }

    /**
     * Apply the chosen bulk action to every selected task.
     *
     * Re-scopes the selection through DispatchGate::scopeVisible() so a user
     * can never act on a task they can't see, regardless of what ids were
     * posted from the client, then authorizes each surviving task individually
     * ('update' for status/label/assign, 'delete' for decline) before applying
     * the change. Labels are ADDED (attach), never replaced.
     */
    public function bulkApply(): void
    {
        if ($this->selected === [] || ! in_array($this->bulkAction, ['status', 'label', 'assign', 'decline'], true)) {
            return;
        }

        /** @var class-string<Task> $taskClass */
        $taskClass = config('dispatch.models.task');
        $gate = app(DispatchGate::class);
        $actor = Auth::user();

        $query = $taskClass::query()->whereIn('id', $this->selected);
        $gate->scopeVisible($query, $actor);
        $tasks = $query->get();

        $ability = $this->bulkAction === 'decline' ? 'delete' : 'update';
        $applied = 0;

        foreach ($tasks as $task) {
            if (Gate::denies($ability, $task)) {
                continue;
            }

            $changed = match ($this->bulkAction) {
                'status' => $this->applyBulkStatus($task, $actor),
                'label' => $this->applyBulkLabel($task, $actor),
                'assign' => $this->applyBulkAssign($task, $actor),
                'decline' => $this->applyBulkDecline($task, $actor),
                default => false,
            };

            if ($changed) {
                $applied++;
            }
        }

        $this->selected = [];
        $this->reset(['bulkAction', 'bulkStatusValue', 'bulkLabelValue', 'bulkAssigneeId']);

        session()->flash(
            'dispatch-status',
            $applied > 0
                ? "Updated {$applied} task(s)."
                : 'No tasks were updated — check permissions and selection.'
        );
    }

    private function applyBulkStatus(Task $task, $actor): bool
    {
        /** @var class-string<Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        if (! in_array($this->bulkStatusValue, $taskClass::statuses(), true) || $task->status === $this->bulkStatusValue) {
            return false;
        }

        $from = $task->status;
        $to = $this->bulkStatusValue;
        $task->status = $to;
        $task->save();

        $task->recordEvent(
            TaskComment::EVENT_STATUS_CHANGE,
            Auth::id(),
            ['from' => $from, 'to' => $to],
            "Status changed from `{$from}` to `{$to}` (bulk).",
        );

        app(DispatchNotifier::class)->taskStatusChanged($task, $from, $to, $actor);

        return true;
    }

    private function applyBulkDecline(Task $task, $actor): bool
    {
        if ($task->status === 'declined') {
            return false;
        }

        $from = $task->status;
        $task->status = 'declined';
        $task->save();

        $task->recordEvent(
            TaskComment::EVENT_STATUS_CHANGE,
            Auth::id(),
            ['from' => $from, 'to' => 'declined'],
            'Declined (bulk).',
        );

        app(DispatchNotifier::class)->taskStatusChanged($task, $from, 'declined', $actor);

        return true;
    }

    private function applyBulkLabel(Task $task, $actor): bool
    {
        $name = trim($this->bulkLabelValue);
        if ($name === '') {
            return false;
        }

        app(DispatchTaskService::class)->attachLabels($task, [$name]);

        $task->recordEvent(
            TaskComment::EVENT_LABEL_ADDED,
            Auth::id(),
            ['label' => $name],
            "Label `{$name}` added (bulk).",
        );

        return true;
    }

    private function applyBulkAssign(Task $task, $actor): bool
    {
        if ($this->bulkAssigneeId === null || $task->assignee_user_id === $this->bulkAssigneeId) {
            return false;
        }

        $from = $task->assignee_user_id;
        $task->assignee_user_id = $this->bulkAssigneeId;
        $task->save();

        $task->recordEvent(
            TaskComment::EVENT_ASSIGNEE_CHANGE,
            Auth::id(),
            ['from' => $from, 'to' => $this->bulkAssigneeId],
            'Assignee updated (bulk).',
        );

        return true;
    }

    /**
     * Whether $task should show the "stale" badge — hasn't moved in
     * `staleness.threshold_days` and isn't in a terminal status. Purely a
     * display signal (see F6/config('dispatch.staleness.*')).
     */
    public function isStale(Task $task): bool
    {
        if (! config('dispatch.staleness.enabled', true)) {
            return false;
        }

        if (in_array($task->status, ['done', 'declined'], true) || $task->updated_at === null) {
            return false;
        }

        $thresholdDays = (int) config('dispatch.staleness.threshold_days', 42);

        return $task->updated_at->lt(now()->subDays($thresholdDays));
    }

    public function render()
    {
        /** @var class-string<Task> $taskClass */
        $taskClass = config('dispatch.models.task');
        $labelClass = config('dispatch.models.label');
        $userClass = config('dispatch.models.user');

        $query = $taskClass::query()->with(['labels', 'submitter', 'assignee']);
        app(DispatchGate::class)->scopeVisible($query, Auth::user());

        if ($this->search !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', $term)->orWhere('code', 'like', $term);
            });
        }

        $staleEnabled = (bool) config('dispatch.staleness.enabled', true);

        if ($this->statusFilter === 'stale' && $staleEnabled) {
            $thresholdDays = (int) config('dispatch.staleness.threshold_days', 42);
            $query->whereNotIn('status', ['done', 'declined'])
                ->where('updated_at', '<', now()->subDays($thresholdDays));
        } elseif (in_array($this->statusFilter, $taskClass::statuses(), true)) {
            $query->where('status', $this->statusFilter);
        }

        if (in_array($this->typeFilter, $taskClass::types(), true)) {
            $query->where('type', $this->typeFilter);
        }
        if (in_array($this->priorityFilter, $taskClass::priorities(), true)) {
            $query->where('priority', $this->priorityFilter);
        }
        if ($this->labelFilter !== '') {
            $label = $this->labelFilter;
            $query->whereHas('labels', fn (Builder $q) => $q->where('name', $label));
        }

        $query = $this->applySort($query);

        return view('dispatch::livewire.task-list', [
            'tasks' => $query->paginate(25),
            'labels' => $labelClass::orderBy('name')->get(),
            'statusLabels' => $taskClass::statusLabels(),
            'typeLabels' => $taskClass::typeLabels(),
            'priorityLabels' => $taskClass::priorityLabels(),
            'assigneeOptions' => $userClass::query()->orderBy('name')->limit(50)->get(['id', 'name', 'email']),
            'staleEnabled' => $staleEnabled,
        ])->layout('dispatch::components.layout');
    }

    private function applySort(Builder $query): Builder
    {
        /** @var class-string<Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        return match ($this->sort) {
            'newest' => $query->orderByDesc('created_at'),
            'oldest' => $query->orderBy('created_at'),
            'code' => $query->orderBy('code'),
            'title' => $query->orderBy('title'),
            'status' => $query->orderByRaw($taskClass::statusSql())->orderByDesc('updated_at'),
            default => $query->orderByRaw($taskClass::prioritySql())->orderBy('code'),
        };
    }
}
