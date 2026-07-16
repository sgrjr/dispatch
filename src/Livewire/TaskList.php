<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Sgrjr\Dispatch\Contracts\DispatchGate;

/**
 * Full-page task table: search, filters, sort, pagination. Same staff-only
 * gate as TaskBoard (see the DECISION note there) — non-staff submitters use
 * MySubmissions for their own portal view.
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
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'typeFilter', 'priorityFilter', 'labelFilter']);
        $this->resetPage();
    }

    public function render()
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');
        $labelClass = config('dispatch.models.label');

        $query = $taskClass::query()->with(['labels', 'submitter', 'assignee']);
        app(DispatchGate::class)->scopeVisible($query, Auth::user());

        if ($this->search !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', $term)->orWhere('code', 'like', $term);
            });
        }

        if (in_array($this->statusFilter, $taskClass::STATUSES, true)) {
            $query->where('status', $this->statusFilter);
        }
        if (in_array($this->typeFilter, $taskClass::TYPES, true)) {
            $query->where('type', $this->typeFilter);
        }
        if (in_array($this->priorityFilter, $taskClass::PRIORITIES, true)) {
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
            'statuses' => $taskClass::STATUSES,
            'types' => $taskClass::TYPES,
            'priorities' => $taskClass::PRIORITIES,
        ])->layout('dispatch::components.layout');
    }

    private function applySort(Builder $query): Builder
    {
        return match ($this->sort) {
            'newest' => $query->orderByDesc('created_at'),
            'oldest' => $query->orderBy('created_at'),
            'code' => $query->orderBy('code'),
            'title' => $query->orderBy('title'),
            'status' => $query->orderByRaw("CASE status
                WHEN 'in_progress' THEN 1
                WHEN 'open' THEN 2
                WHEN 'triage' THEN 3
                WHEN 'verifying' THEN 4
                WHEN 'done' THEN 5
                WHEN 'declined' THEN 6
                ELSE 99 END")->orderByDesc('updated_at'),
            default => $query->orderByRaw("CASE priority
                WHEN 'blocker' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
                ELSE 99 END")->orderBy('code'),
        };
    }
}
