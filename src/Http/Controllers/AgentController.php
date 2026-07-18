<?php

namespace Sgrjr\Dispatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sgrjr\Dispatch\Http\Middleware\AuthenticateAgentSession;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchBatchService;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\TaskPresenter;

/**
 * The remote agent verb loop (§19/§20 Phase 2). Every action here runs behind
 * `dispatch.agent` (binds + authenticates the session) and
 * `dispatch.agent.scope:<verb>` (enforces the session's per-verb allowlist) —
 * both declared in routes/agent.php, not rechecked here beyond the
 * belt-and-suspenders null check in {@see session()}.
 *
 * SCOPE BYPASS RULE: an approved AgentSession is staff-equivalent — a human
 * explicitly approved it. This controller queries the configured Task model
 * DIRECTLY (mirroring the trusted CLI commands, e.g. DispatchNext), and never
 * routes through DispatchGate::scopeVisible(), which exists for user-facing
 * web surfaces only.
 */
class AgentController extends Controller
{
    public function next(Request $request): JsonResponse
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $task = $taskModel::query()
            ->with(['labels', 'submitter', 'assignee'])
            ->withCount(['comments as comment_count' => fn ($q) => $q->where('event_type', TaskComment::EVENT_COMMENT)])
            ->when(
                $request->query('status'),
                fn ($q, $status) => $q->where('status', $status),
                fn ($q) => $q->whereIn('status', ['open', 'in_progress', 'triage'])
            )
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('label'), fn ($q, $label) => $q->whereHas(
                'labels',
                fn ($lq) => $lq->whereIn('name', (array) $label)
            ))
            ->orderByRaw("CASE WHEN status IN ('open', 'in_progress') THEN 0 ELSE 1 END")
            ->orderByRaw("CASE priority WHEN 'blocker' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 99 END")
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        return response()->json([
            'task' => $task ? TaskPresenter::toArray($task) : null,
        ]);
    }

    public function queue(Request $request): JsonResponse
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        // Shared status/type/label filter — applied identically by the count and
        // the list paths so `--count` reports the size of exactly what the list
        // would return.
        $applyFilters = function ($q) use ($request) {
            if ($status = $request->query('status')) {
                $q->where('status', $status);
            } else {
                $q->whereIn('status', ['open', 'in_progress', 'triage']);
            }

            return $q
                ->when($request->query('type'), fn ($qq, $type) => $qq->where('type', $type))
                ->when($request->query('label'), fn ($qq, $label) => $qq->whereHas(
                    'labels',
                    fn ($lq) => $lq->whereIn('name', (array) $label)
                ));
        };

        // ?count — return {total, by_status} instead of the task list, so an
        // agent learns the true backlog size without probing --limit (W4-4).
        if ($request->boolean('count')) {
            $byStatus = $applyFilters($taskModel::query())
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->map(fn ($v) => (int) $v)
                ->all();

            return response()->json([
                'total' => array_sum($byStatus),
                'by_status' => $byStatus,
            ]);
        }

        $query = $applyFilters($taskModel::query())
            ->with(['labels', 'submitter', 'assignee'])
            ->withCount(['comments as comment_count' => fn ($q) => $q->where('event_type', TaskComment::EVENT_COMMENT)])
            ->orderByRaw("CASE priority WHEN 'blocker' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 99 END")
            ->orderBy('position')
            ->orderBy('id');

        $limit = (int) $request->query('limit', 0);
        if ($limit > 0) {
            $query->limit($limit);
        }

        return response()->json([
            'tasks' => TaskPresenter::collection($query->get()),
        ]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $task = $taskModel::query()
            ->with(['labels', 'submitter', 'assignee', 'comments.user'])
            ->where('code', $code)
            ->first();

        abort_if($task === null, 404);

        return response()->json([
            'task' => TaskPresenter::toArray($task, true),
        ]);
    }

    public function claim(Request $request): JsonResponse
    {
        $s = $this->session($request);

        $v = $request->validate([
            'type' => ['nullable', 'string'],
            'label' => ['nullable'],
            'code' => ['nullable', 'string'],
        ]);

        $task = app(DispatchTaskService::class)->claim($s, array_filter([
            'type' => $v['type'] ?? null,
            'label' => $v['label'] ?? null,
        ]), null, $v['code'] ?? null);

        // Deliver the FULL shape on claim — description + context + the comments
        // thread — because claim is exactly when the agent commits to a task and
        // needs the human's direction (which lives in the description/comments,
        // invisible in the summary shape that next/queue return). Load the
        // relations the full presenter reads so it doesn't lazy-load per row.
        return response()->json([
            'task' => $task
                ? TaskPresenter::toArray(
                    $task->load('labels', 'submitter', 'assignee', 'comments.user'),
                    true,
                )
                : null,
        ]);
    }

    public function add(Request $request): JsonResponse
    {
        $s = $this->session($request);

        $v = $request->validate([
            'title' => ['required', 'string'],
            'type' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'labels' => ['nullable', 'array'],
            'labels.*' => ['string'],
            'public' => ['nullable', 'boolean'],
            'key' => ['nullable', 'string'],
        ]);

        $attributes = array_filter([
            'title' => $v['title'] ?? null,
            'type' => $v['type'] ?? null,
            'priority' => $v['priority'] ?? null,
            'description' => $v['description'] ?? null,
        ], fn ($value) => $value !== null) + [
            'submitter_user_id' => null,
            'is_public' => (bool) ($v['public'] ?? false),
        ];

        $labels = $v['labels'] ?? [];

        $task = ! empty($v['key'])
            ? app(DispatchTaskService::class)->firstOrCreateByKey($v['key'], $attributes, $labels)
            : app(DispatchTaskService::class)->create($attributes, $labels);

        // Stamp attribution in context (no timeline noise for a plain create).
        $task->context = array_merge($task->context ?? [], ['agent' => $this->agentMeta($s)]);
        $task->save();

        return response()->json([
            'task' => TaskPresenter::toArray($task),
        ], 201);
    }

    public function note(Request $request): JsonResponse
    {
        $s = $this->session($request);

        $v = $request->validate([
            'code' => ['required', 'string'],
            'body' => ['required', 'string'],
            'internal' => ['nullable', 'boolean'],
        ]);

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $task = $taskModel::query()->where('code', $v['code'])->first();

        abort_if($task === null, 404);

        $comment = $task->recordEvent(
            TaskComment::EVENT_COMMENT,
            null,
            $this->agentMeta($s),
            $v['body'],
            (bool) ($v['internal'] ?? false),
        );

        return response()->json([
            'task' => TaskPresenter::toArray($task->fresh()->load('labels', 'submitter', 'assignee')),
            'comment_id' => $comment->id,
        ]);
    }

    public function done(Request $request): JsonResponse
    {
        $s = $this->session($request);

        $v = $request->validate([
            'code' => ['required', 'string'],
            'status' => ['nullable', 'string'],
            'commit' => ['nullable', 'string'],
            'result' => ['nullable', 'array'],
        ]);

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $task = $taskModel::query()->where('code', $v['code'])->first();

        abort_if($task === null, 404);

        $to = $v['status'] ?? 'done';

        abort_unless(in_array($to, $taskModel::statuses(), true), 422);

        $from = $task->status;
        $task->status = $to;
        $task->save();

        $task->recordEvent(
            TaskComment::EVENT_STATUS_CHANGE,
            null,
            $this->agentMeta($s, ['from' => $from, 'to' => $to]),
            "Status changed from {$from} to {$to}.",
        );

        if (array_key_exists('commit', $v) || array_key_exists('result', $v)) {
            app(DispatchTaskService::class)->recordResult($task, $v['result'] ?? [], $v['commit'] ?? null);
        }

        return response()->json([
            'task' => TaskPresenter::toArray($task->fresh()->load('labels', 'submitter', 'assignee')),
        ]);
    }

    /**
     * Batch memorialize (§20). Apply a whole manifest of `add`/`update` ops in a
     * single transaction — the "work offline, commit the run in one hit" path.
     * The manifest carries the SAME vocabulary as the single verbs; see
     * {@see DispatchBatchService} for the additive, server-bounded semantics.
     *
     * The op count is capped so a single request can't be turned into an
     * unbounded bulk-write; a malformed manifest yields a 422 that names the
     * offending op index and rolls back cleanly (nothing persists).
     */
    public function batch(Request $request): JsonResponse
    {
        $s = $this->session($request);

        $v = $request->validate([
            'operations' => ['required', 'array'],
            'operations.*' => ['array'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        // env-aware default so a host that set DISPATCH_AGENT_BATCH_MAX keeps it
        // even under a stale published config missing the key (GAP-3).
        $max = (int) config('dispatch.agent.batch.max_operations', (int) env('DISPATCH_AGENT_BATCH_MAX', 200));
        abort_if($max > 0 && count($v['operations']) > $max, 422, "Batch too large: {$max} operations max.");

        $dryRun = (bool) ($v['dry_run'] ?? false);

        try {
            $outcome = app(DispatchBatchService::class)->apply(
                $v['operations'],
                $this->agentMeta($s),
                null,
                $dryRun,
            );
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'applied' => ! $dryRun,
            'dry_run' => $dryRun,
            'summary' => $outcome['summary'],
            'results' => $outcome['results'],
        ]);
    }

    /**
     * Self-revoke (§20 — GAP 5). End the CALLER'S OWN session — identified
     * solely by the bearer token, no id parameter — so a well-behaved agent can
     * surrender its credential the moment its work is done instead of leaving a
     * usable token alive until TTL. An agent can only ever end itself. The route
     * is bearer-authed but not scope-gated, so this works regardless of which
     * verbs the session was granted.
     */
    public function end(Request $request): JsonResponse
    {
        $s = $this->session($request);

        app(AgentSessionService::class)->revoke($s);

        return response()->json([
            'ended' => true,
            'status' => AgentSession::STATUS_REVOKED,
            'public_id' => $s->public_id,
        ]);
    }

    /**
     * The bound AgentSession for this request. Belt-and-suspenders: the
     * `dispatch.agent` middleware already guarantees a session is bound, but
     * a null check here means a mis-ordered middleware stack self-denies
     * rather than running as an un-scoped principal.
     */
    private function session(Request $request): AgentSession
    {
        $session = $request->attributes->get(AuthenticateAgentSession::ATTRIBUTE);

        abort_if($session === null, 401);

        return $session;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function agentMeta(AgentSession $s, array $extra = []): array
    {
        return $extra + [
            'agent_session_id' => $s->public_id,
            'agent_name' => $s->agent_name,
        ];
    }
}
