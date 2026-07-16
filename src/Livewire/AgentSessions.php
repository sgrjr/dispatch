<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Services\AgentSessionService;

/**
 * Staff "Agent Sessions" approval queue (§20 Phase 3). A remote agent
 * requests a session (AgentSessionController::request); this is where a
 * human confirms the request is legitimate — matching the `user_code` the
 * requesting agent displayed — and approves, denies, or revokes it.
 *
 * Same staff-only gate as TaskList/TaskBoard: non-staff are redirected to
 * the submitter portal.
 */
class AgentSessions extends Component
{
    public function mount(): void
    {
        if (! app(DispatchGate::class)->isStaff(Auth::user())) {
            $this->redirect(route(config('dispatch.routes.name_prefix', 'dispatch.').'portal'));

            return;
        }
    }

    public function approve(int $id): void
    {
        abort_unless(app(DispatchGate::class)->isStaff(Auth::user()), 403);

        $session = AgentSession::query()->findOrFail($id);

        app(AgentSessionService::class)->approve($session, (int) Auth::id());
    }

    public function deny(int $id): void
    {
        abort_unless(app(DispatchGate::class)->isStaff(Auth::user()), 403);

        $session = AgentSession::query()->findOrFail($id);

        app(AgentSessionService::class)->deny($session);
    }

    public function revoke(int $id): void
    {
        abort_unless(app(DispatchGate::class)->isStaff(Auth::user()), 403);

        $session = AgentSession::query()->findOrFail($id);

        app(AgentSessionService::class)->revoke($session);
    }

    public function render()
    {
        $pending = AgentSession::query()
            ->where('status', AgentSession::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->get();

        $active = AgentSession::query()
            ->where('status', AgentSession::STATUS_APPROVED)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('approved_at')
            ->get();

        return view('dispatch::livewire.agent-sessions', [
            'pending' => $pending,
            'active' => $active,
        ])->layout('dispatch::components.layout');
    }
}
