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
 *
 * Under the W13-5 gates that intersection is load-bearing both ways: a STAFF
 * submitter always sees their own submissions (GATE A), while a NON-staff
 * (customer) submitter sees only the ones whose "Visible to
 * submitter/customer" toggle is on (GATE C — default off, per the operator
 * ruling). An empty portal for a customer is therefore correct, not a bug.
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

        if (in_array($this->statusFilter, $taskClass::statuses(), true)) {
            $query->where('status', $this->statusFilter);
        }

        $tasks = $query->orderByDesc('updated_at')->paginate(20);

        return view('dispatch::livewire.my-submissions', [
            'tasks' => $tasks,
            'statusLabels' => $taskClass::statusLabels(),
        ])->layout('dispatch::components.layout');
    }
}
