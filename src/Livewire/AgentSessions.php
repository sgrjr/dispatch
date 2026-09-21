<?php

namespace Sgrjr\Dispatch\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Contracts\LaneResolver;
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
    /**
     * Per-approval session TTL (seconds), keyed by session id — the approver
     * right-sizes the window per commission (short for an experiment, long for
     * an overnight run); the config default is just the pre-selected option.
     * Livewire dot-notation binds each row's select as `approveTtl.{id}`; an
     * unset key (select untouched) falls back to the service's config default.
     *
     * @var array<int,string>
     */
    public array $approveTtl = [];

    /**
     * Per-approval scope selection (TASK-749), keyed by session id → the scope
     * strings still checked on that row. render() seeds each key with exactly
     * what approve() WOULD grant if left alone, so the checkboxes are a picture
     * of the pending grant rather than a second opinion about it; approve()
     * passes null when the selection is untouched, keeping the service's
     * request-time semantics (and any host rule keyed on `requested_meta`)
     * byte-identical to the pre-checkbox behaviour.
     *
     * @var array<int,array<int,string>>
     */
    public array $approveScopes = [];

    /**
     * TASK-999 (R24) — per-approval LANE selection, keyed by session id → the
     * lane key the approver will grant ('' = unrestricted). Seeded in render()
     * with what approve() would grant untouched, for the same reason
     * $approveScopes is: the control shows the pending decision, not a second
     * opinion about it.
     *
     * @var array<int,string>
     */
    public array $approveLane = [];

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

        // Empty/untouched select ('' → 0) collapses to null, letting the service
        // apply its config default; any preset passes straight through as the TTL.
        $ttl = (int) ($this->approveTtl[$id] ?? 0) ?: null;

        // Unlike scopes, the lane control is ALWAYS seeded and always
        // submitted, so there is no absent-vs-explicit distinction to
        // preserve here — whatever the select holds is the decision.
        $lane = (string) ($this->approveLane[$session->id] ?? '');

        app(AgentSessionService::class)->approve($session, (int) Auth::id(), $ttl, $this->selectedScopes($session), $lane);
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

    /**
     * The scopes this row's approver kept, or null when they never touched the
     * boxes.
     *
     * Null is load-bearing, not laziness: the service treats a scope-less
     * REQUEST (`requested_meta.scopes` absent) differently from an explicit
     * list, and a host can key its own rules on that same distinction —
     * Centerpoint's `AgentAuthority::grantFor()` reads `requested_meta` to keep
     * tool capabilities read-only unless the agent asked for them. Handing the
     * service a synthesised array for an untouched form would silently convert
     * every "no scopes named" request into an explicit one. So: only speak when
     * the human actually changed something.
     *
     * @return array<int,string>|null
     */
    protected function selectedScopes(AgentSession $session): ?array
    {
        if (! array_key_exists($session->id, $this->approveScopes)) {
            return null;
        }

        $selected = array_values(array_unique(array_map('strval', (array) $this->approveScopes[$session->id])));
        $seeded = $this->pendingGrant($session);

        sort($selected);
        $sortedSeed = $seeded;
        sort($sortedSeed);

        // Untouched (still exactly the seeded grant) → stay silent, as above.
        return $selected === $sortedSeed ? null : $selected;
    }

    /**
     * What approve() would grant this pending session right now, with nobody
     * intervening. Asked of the service so the UI can never drift from the rule
     * it is depicting.
     *
     * @return array<int,string>
     */
    protected function pendingGrant(AgentSession $session): array
    {
        $requested = $session->requested_meta['scopes'] ?? null;

        return app(AgentSessionService::class)->resolveGrant(
            $requested === null ? null : array_map('strval', (array) $requested)
        );
    }

    /**
     * The consent card for one pending row (TASK-749): what the agent asked for,
     * split by vocabulary, plus anything it asked for that is not grantable here.
     *
     * The split matters because `scopes` is one column carrying two languages —
     * the package's board verbs (`claim`, `done`, `batch`) and whatever
     * capability names the host has added alongside them (Centerpoint's `app.*`
     * tool-surface scopes). Rendered as one undifferentiated list, "done" and
     * "app.destructive" look like the same kind of thing to the person clicking
     * Approve. They are not.
     *
     * @return array{explicit:bool, board:array<int,string>, extension:array<int,string>, ungrantable:array<int,string>}
     */
    protected function scopeCard(AgentSession $session): array
    {
        $requested = $session->requested_meta['scopes'] ?? null;
        $explicit = $requested !== null;

        $grantable = $this->pendingGrant($session);

        // Only an explicit request can name something ungrantable; the implicit
        // default is drawn from the allowlist and is grantable by construction.
        $ungrantable = $explicit
            ? array_values(array_diff(array_unique(array_map('strval', (array) $requested)), $grantable))
            : [];

        return [
            'explicit' => $explicit,
            'board' => array_values(array_filter($grantable, fn ($s) => AgentSessionService::isBoardVerb($s))),
            'extension' => array_values(array_filter($grantable, fn ($s) => ! AgentSessionService::isBoardVerb($s))),
            'ungrantable' => $ungrantable,
        ];
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

        // Recently ended: sessions that were actually commissioned (approved_at
        // set — a denied request never ran) and have since been revoked/expired.
        // This is where the session-anchored metrics verdict lives — the badge
        // on an ACTIVE row can only ever say "pending", because the load-bearing
        // stamp happens at session:end. Without this section the outcome
        // vanished with the row the moment the run finished.
        $ended = AgentSession::query()
            ->whereIn('status', [AgentSession::STATUS_REVOKED, AgentSession::STATUS_EXPIRED])
            ->whereNotNull('approved_at')
            ->orderByDesc('ended_at')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        // Seed each pending row's checkboxes with the grant it would receive
        // untouched, so the form shows the actual decision. Seeding here (not in
        // mount) also covers rows that appear while the page is open.
        $cards = [];
        foreach ($pending as $session) {
            $cards[$session->id] = $this->scopeCard($session);
            if (! array_key_exists($session->id, $this->approveScopes)) {
                $this->approveScopes[$session->id] = $this->pendingGrant($session);
            }
            if (! array_key_exists($session->id, $this->approveLane)) {
                $this->approveLane[$session->id] = (string) app(AgentSessionService::class)
                    ->resolveLane($session->requested_meta['lane'] ?? null);
            }
        }

        // Lane options for the approve control: every lane the host recognizes,
        // plus the explicit "no lane" (unrestricted) choice. Rescued because a
        // host may bind a resolver that reaches its own tables — the approval
        // queue must still render if that lookup fails.
        try {
            $laneOptions = app(LaneResolver::class)->lanes();
        } catch (\Throwable) {
            $laneOptions = [];
        }

        return view('dispatch::livewire.agent-sessions', [
            'pending' => $pending,
            'scopeCards' => $cards,
            'laneOptions' => $laneOptions,
            'active' => $active,
            'ended' => $ended,
            'metrics' => $this->metricsSummary($active->concat($ended)),
        ])->layout('dispatch::components.layout');
    }

    /**
     * Per-session metrics footprint (W4-9): how many tasks each session recorded
     * a RESULT on (`worked`), and how many of those carry stamped metrics
     * (`with_metrics`). Lets the view flag a session that closed work but captured
     * no agent-run metrics — visible at a glance instead of drilling into a task.
     * Computed for active AND recently-ended sessions; the session-level metrics
     * object itself (recorded at session:end) lives on the row (`->metrics`).
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
