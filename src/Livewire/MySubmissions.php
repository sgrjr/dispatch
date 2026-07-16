<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Sgrjr\Dispatch\Contracts\DispatchGate;

/**
 * The submitter portal: "my submissions". Filters to submitter_user_id =
 * Auth::id() AND runs the result through DispatchGate::scopeVisible — the
 * filter never bypasses the one visibility scope, it just narrows further.
 * For the shipped DefaultGate any authenticated user already sees everything
 * (canSeeAll), so this is a no-op intersection there; for an app-supplied
 * gate that limits non-staff to "own + public", intersecting with "own" is
 * always safe since a sane gate always includes the user's own submissions.
 */
class MySubmissions extends Component
{
    use WithPagination;

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        /** @var class-string<\Sgrjr\Dispatch\Models\Task> $taskClass */
        $taskClass = config('dispatch.models.task');

        $query = $taskClass::query()
            ->with(['labels', 'assignee'])
            ->where('submitter_user_id', Auth::id());

        app(DispatchGate::class)->scopeVisible($query, Auth::user());

        if (in_array($this->statusFilter, $taskClass::STATUSES, true)) {
            $query->where('status', $this->statusFilter);
        }

        $tasks = $query->orderByDesc('updated_at')->paginate(20);

        return view('dispatch::livewire.my-submissions', [
            'tasks' => $tasks,
            'statuses' => $taskClass::STATUSES,
        ])->layout('dispatch::components.layout');
    }
}
