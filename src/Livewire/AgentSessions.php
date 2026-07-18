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
            'metrics' => $this->metricsSummary($active),
        ])->layout('dispatch::components.layout');
    }

    /**
     * Per-session metrics footprint (W4-9): how many tasks each session recorded
     * a RESULT on (`worked`), and how many of those carry stamped metrics
     * (`with_metrics`). Lets the view flag a session that closed work but captured
     * no agent-run metrics — visible at a glance instead of drilling into a task.
     *
     * The link is the attribution an agent write stamps
     * (TaskComment.meta.agent_session_id = session.public_id, see
     * AgentController::agentMeta); metrics live at task.context.result.metrics.
     *
     * @param  \Illuminate\Support\Collection<int, AgentSession>  $sessions
     * @return array<int, array{worked:int, with_metrics:int}>
     */
    protected function metricsSummary($sessions): array
    {
        $out = [];
        foreach ($sessions as $s) {
            $out[$s->id] = ['worked' => 0, 'with_metrics' => 0];
        }
        if ($sessions->isEmpty()) {
            return $out;
        }

        $publicToId = $sessions->pluck('id', 'public_id');   // public_id => session id

        /** @var class-string $commentModel */
        $commentModel = config('dispatch.models.task_comment');
        /** @var class-string $taskModel */
        $taskModel = config('dispatch.models.task');

        // Distinct (session public_id, task_id) pairs this batch touched.
        $links = $commentModel::query()
            ->whereIn('meta->agent_session_id', $publicToId->keys()->all())
            ->get(['task_id', 'meta'])
            ->map(fn ($c) => ['pid' => data_get($c->meta, 'agent_session_id'), 'task_id' => $c->task_id])
            ->filter(fn ($l) => $l['pid'] !== null && $l['task_id'] !== null)
            ->unique(fn ($l) => $l['pid'].'|'.$l['task_id'])
            ->values();

        if ($links->isEmpty()) {
            return $out;
        }

        $tasks = $taskModel::query()
            ->whereIn('id', $links->pluck('task_id')->unique()->all())
            ->get(['id', 'context'])
            ->keyBy('id');

        foreach ($links as $link) {
            $task = $tasks->get($link['task_id']);
            if ($task === null || data_get($task->context, 'result') === null) {
                continue;   // no result recorded ⇒ not "worked" for this purpose
            }
            $sid = $publicToId->get($link['pid']);
            if ($sid === null || ! isset($out[$sid])) {
                continue;
            }
            $out[$sid]['worked']++;
            if (data_get($task->context, 'result.metrics') !== null) {
                $out[$sid]['with_metrics']++;
            }
        }

        return $out;
    }
}
