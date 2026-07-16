<?php

namespace Sgrjr\Dispatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sgrjr\Dispatch\Http\Middleware\AuthenticateAgentSession;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
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
            ->whereIn('status', ['open', 'in_progress', 'triage'])
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

        $query = $taskModel::query()->with(['labels', 'submitter', 'assignee']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', ['open', 'in_progress', 'triage']);
        }

        $query
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('label'), fn ($q, $label) => $q->whereHas(
                'labels',
                fn ($lq) => $lq->whereIn('name', (array) $label)
            ))
            ->orderByRaw("CASE priority WHEN 'blocker' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 99 END")
            ->orderBy('position')
            ->orderBy('id');

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
        ]);

        $task = app(DispatchTaskService::class)->claim($s, array_filter([
            'type' => $v['type'] ?? null,
            'label' => $v['label'] ?? null,
        ]), null);

        return response()->json([
            'task' => $task ? TaskPresenter::toArray($task) : null,
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
