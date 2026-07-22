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
use Sgrjr\Dispatch\Livewire\Concerns\HasVocabMultiFilters;
use Sgrjr\Dispatch\Models\Focus;
use Sgrjr\Dispatch\Models\Label;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\LabelFacets;

/**
 * Full-page task table: search, filters, sort, pagination, and bulk actions.
 * Same staff-only gate as TaskBoard (see the DECISION note there) — non-staff
 * submitters use MySubmissions for their own portal view.
 */
class TaskList extends Component
{
    use HasVocabMultiFilters;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    /**
     * Checkbox multi-filters (see HasVocabMultiFilters for the []/['']/subset
     * state contract). Plural aliases keep pre-multi-select scalar bookmarks
     * (?type=bug) inert and match TaskBoard's, so a filtered URL transfers
     * between the list and the board.
     */
    #[Url(as: 'types', except: [])]
    public array $typeFilter = [];

    #[Url(as: 'priorities', except: [])]
    public array $priorityFilter = [];

    #[Url(as: 'labels', except: [])]
    public array $labelFilter = [];

    /**
     * Due-date window buckets (see Task::dueBuckets()). Alias is singular:
     * no pre-multi-select scalar `?due=` bookmark ever existed, so there is
     * nothing to keep inert.
     */
    #[Url(as: 'due', except: [])]
    public array $dueFilter = [];

    /** Updated-at window: '', 'today', 'week', 'month', or 'older'. */
    #[Url(as: 'updated', except: '')]
    public string $updatedFilter = '';

    /**
     * Active steering focus id (W8-2). '' = unsteered (all tasks); otherwise a
     * Focus id whose constrained axes narrow the list via Focus::applyTo(). A
     * stale/invalid id is ignored (treated as unsteered) rather than fataling.
     */
    #[Url(as: 'focus', except: '')]
    public string $focusFilter = '';

    /**
     * Page-scoped group-by namespace (W8-5). '' = flat list; otherwise an
     * ELEVATED label namespace (area, epic, …) the CURRENT page's rows are
     * laned under. Pure presentation — never touches the query/pagination.
     */
    #[Url(as: 'group', except: '')]
    public string $groupBy = '';

    /** Name buffer for "save current filters as a Focus" (W8-2). */
    public string $newFocusName = '';

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
        // Only the wire:model-bound filters pass through here — the checkbox
        // multi-filters assign in-method and reset via afterFilterChanged().
        if (in_array($name, ['search', 'statusFilter', 'updatedFilter', 'focusFilter'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    protected function afterFilterChanged(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    /** @var array<int,string>|null Per-request memo (protected: not Livewire state). */
    protected ?array $labelNamesCache = null;

    protected function filterVocabs(): array
    {
        /** @var class-string<Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        return [
            'typeFilter' => $taskClass::types(),
            'priorityFilter' => $taskClass::priorities(),
            'labelFilter' => $this->labelNames(),
            'dueFilter' => $taskClass::dueBuckets(),
        ];
    }

    /** @return array<int,string> */
    protected function labelNames(): array
    {
        if ($this->labelNamesCache === null) {
            $labelClass = config('dispatch.models.label');
            $this->labelNamesCache = $labelClass::orderBy('name')->pluck('name')->all();
        }

        return $this->labelNamesCache;
    }

    /** @var \Illuminate\Support\Collection<int,Label>|null Per-request memo of the full label set. */
    protected $labelsCache = null;

    /**
     * The full Label models, ordered by name — one fetch feeding both the view
     * (chips/legend) and the grouped label filter's sections.
     *
     * @return \Illuminate\Support\Collection<int,Label>
     */
    protected function allLabels()
    {
        if ($this->labelsCache === null) {
            $labelClass = config('dispatch.models.label');
            $this->labelsCache = $labelClass::orderBy('name')->get();
        }

        return $this->labelsCache;
    }

    /**
     * Titled sections for the grouped label filter (W8-1a): elevated namespaces
     * first, then plain, then meta — see LabelFacets::grouped(). Render-only
     * grouping over the same label set; the filter's vocab (labelNames()) and
     * the whereIn payload stay FLAT, so the []/['']/subset state contract in
     * HasVocabMultiFilters is untouched.
     *
     * @return array<int,array{title:string,options:array<string,string>}>
     */
    public function labelFilterGroups(): array
    {
        return LabelFacets::grouped($this->allLabels());
    }

    /**
     * The ELEVATED label namespaces available for page-scoped group-by (W8-5):
     * the keys of LabelFacets::namespaceKinds() whose kind is 'elevated' (e.g.
     * area, epic). Also the allow-list validating the bound $groupBy.
     *
     * @return array<int,string>
     */
    public function groupByOptions(): array
    {
        return array_keys(array_filter(
            LabelFacets::namespaceKinds(),
            fn ($kind) => $kind === Label::KIND_ELEVATED,
        ));
    }

    /**
     * Persist the current filter selection as a steering Focus (W8-2). Stores
     * ONLY the constrained axes (labels/types/priorities) per Focus's storage
     * rule — activeSelection() returns null for an all/none axis, which we omit
     * so an absent key means "unconstrained". A blank name is a no-op. New
     * focuses rank last (max+1) and start active.
     */
    public function saveCurrentAsFocus(): void
    {
        $name = trim($this->newFocusName);
        if ($name === '') {
            return;
        }

        /** @var class-string<Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        $filters = [];
        if (null !== ($sel = $this->activeSelection($this->labelFilter, $this->labelNames()))) {
            $filters['labels'] = $sel;
        }
        if (null !== ($sel = $this->activeSelection($this->typeFilter, $taskClass::types()))) {
            $filters['types'] = $sel;
        }
        if (null !== ($sel = $this->activeSelection($this->priorityFilter, $taskClass::priorities()))) {
            $filters['priorities'] = $sel;
        }

        /** @var class-string<Focus> $focusClass */
        $focusClass = config('dispatch.models.focus', Focus::class);

        $focusClass::create([
            'name' => $name,
            'filters' => $filters,
            'rank' => (int) $focusClass::max('rank') + 1,
            'is_active' => true,
        ]);

        $this->newFocusName = '';
        session()->flash('dispatch-status', "Focus \"{$name}\" saved.");
    }

    /**
     * The group-by lane for a task under the current $groupBy namespace: the
     * value-part (after ':') of its FIRST label whose prefix() === $groupBy and
     * whose effectiveKind() is elevated. Null when the task carries no such
     * label (rendered in the '—' bucket, last).
     *
     * Deliberately namespace-SCOPED — NOT LabelFacets::laneKey(), which is
     * namespace-agnostic (first elevated label of ANY namespace) for board
     * swimlanes. Here the operator picked a specific axis to lane by.
     */
    private function groupLaneFor(Task $task): ?string
    {
        foreach ($task->labels as $label) {
            if ($label->prefix() === $this->groupBy && $label->effectiveKind() === Label::KIND_ELEVATED) {
                $name = (string) $label->name;
                $pos = strpos($name, ':');

                return $pos === false ? $name : substr($name, $pos + 1);
            }
        }

        return null;
    }

    /**
     * Partition the current page's rows into ordered lanes for group-by (W8-5).
     * Value-named lanes sort ascending; the unlabeled '—' bucket is always
     * appended LAST. Each task lands under exactly one lane (its first matching
     * label). A pure presentation pass over the already-paginated items — no
     * query, no reordering of pagination.
     *
     * @param  \Illuminate\Support\Collection<int,Task>  $tasks
     * @return array<int,array{lane:string,tasks:\Illuminate\Support\Collection<int,Task>}>
     */
    private function groupPageIntoLanes($tasks): array
    {
        $buckets = [];
        $unlabeled = [];

        foreach ($tasks as $task) {
            $lane = $this->groupLaneFor($task);
            if ($lane === null) {
                $unlabeled[] = $task;
            } else {
                $buckets[$lane][] = $task;
            }
        }

        ksort($buckets, SORT_STRING);

        $lanes = [];
        foreach ($buckets as $lane => $items) {
            $lanes[] = ['lane' => (string) $lane, 'tasks' => collect($items)];
        }

        if ($unlabeled !== []) {
            $lanes[] = ['lane' => '—', 'tasks' => collect($unlabeled)];
        }

        return $lanes;
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'typeFilter', 'priorityFilter', 'labelFilter', 'dueFilter', 'updatedFilter', 'focusFilter']);
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

        if (in_array($task->status, ['backburner', 'done', 'declined'], true) || $task->updated_at === null) {
            return false;
        }

        $thresholdDays = (int) config('dispatch.staleness.threshold_days', 42);

        return $task->updated_at->lt(now()->subDays($thresholdDays));
    }

    public function render()
    {
        /** @var class-string<Task> $taskClass */
        $taskClass = config('dispatch.models.task');
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
            $query->whereNotIn('status', ['backburner', 'done', 'declined'])
                ->where('updated_at', '<', now()->subDays($thresholdDays));
        } elseif (in_array($this->statusFilter, $taskClass::statuses(), true)) {
            $query->where('status', $this->statusFilter);
        }

        if (null !== ($sel = $this->activeSelection($this->typeFilter, $taskClass::types()))) {
            $query->whereIn('type', $sel);
        }
        if (null !== ($sel = $this->activeSelection($this->priorityFilter, $taskClass::priorities()))) {
            $query->whereIn('priority', $sel);
        }
        if (null !== ($sel = $this->activeSelection($this->labelFilter, $this->labelNames()))) {
            $query->whereHas('labels', fn (Builder $q) => $q->whereIn('name', $sel));
        }
        if (null !== ($sel = $this->activeSelection($this->dueFilter, $taskClass::dueBuckets()))) {
            $query->dueInBuckets($sel);
        }

        // Cumulative windows (today ⊂ week ⊂ month); 'older' is the remainder.
        match ($this->updatedFilter) {
            'today' => $query->where('updated_at', '>=', now()->startOfDay()),
            'week' => $query->where('updated_at', '>=', now()->subWeek()),
            'month' => $query->where('updated_at', '>=', now()->subMonth()),
            'older' => $query->where('updated_at', '<', now()->subMonth()),
            default => null,
        };

        // Steering focus (W8-2): resolve the active, ranked focuses once — they
        // feed both the switcher and (when one is selected + still valid) the
        // query narrowing. A stale id simply doesn't match, so it's unsteered.
        $focusClass = config('dispatch.models.focus', Focus::class);
        $focuses = $focusClass::query()->active()->ranked()->get();

        if ($this->focusFilter !== '' && null !== ($focus = $focuses->firstWhere('id', (int) $this->focusFilter))) {
            $focus->applyTo($query);
        }

        $query = $this->applySort($query);

        $tasks = $query->paginate(25);

        // Page-scoped group-by (W8-5): a pure presentation pass over THIS page's
        // rows only — null (off, or an invalid namespace) renders the flat list
        // unchanged. Pagination/sort are untouched.
        $groupedLanes = ($this->groupBy !== '' && in_array($this->groupBy, $this->groupByOptions(), true))
            ? $this->groupPageIntoLanes($tasks->getCollection())
            : null;

        return view('dispatch::livewire.task-list', [
            'tasks' => $tasks,
            'labels' => $this->allLabels(),
            'focuses' => $focuses,
            'groupedLanes' => $groupedLanes,
            'statusLabels' => $taskClass::statusLabels(),
            'typeLabels' => $taskClass::typeLabels(),
            'priorityLabels' => $taskClass::priorityLabels(),
            'dueBucketLabels' => $taskClass::dueBucketLabels(),
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
            'updated_desc' => $query->orderByDesc('updated_at'),
            'updated_asc' => $query->orderBy('updated_at'),
            // NULLS LAST both directions, portably (SQLite + MySQL); code
            // tiebreaker matches the default priority sort's stable ordering.
            'due_asc' => $query->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_at')->orderBy('code'),
            'due_desc' => $query->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('due_at')->orderBy('code'),
            'code' => $query->orderBy('code'),
            'title' => $query->orderBy('title'),
            'status' => $query->orderByRaw($taskClass::statusSql())->orderByDesc('updated_at'),
            default => $query->orderByRaw($taskClass::prioritySql())->orderBy('code'),
        };
    }
}
