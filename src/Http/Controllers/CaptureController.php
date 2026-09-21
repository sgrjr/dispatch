<?php

namespace Sgrjr\Dispatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Sgrjr\Dispatch\Contracts\LaneResolver;
use Sgrjr\Dispatch\Services\AttachmentService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * Headless JSON capture endpoint — the frontend-agnostic entry point for the
 * from-any-page report widget. The shipped Livewire widget uses this app's
 * server-side path; a Vue/Inertia (or any JS) host uses the published Vue
 * component, which POSTs here. Same task pipeline either way.
 */
class CaptureController extends Controller
{
    public function store(Request $request, DispatchTaskService $tasks, AttachmentService $attachments): JsonResponse
    {
        Gate::authorize('create', config('dispatch.models.task'));

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'in:bug,feature,chore,debt,verify'],
            'description' => ['nullable', 'string'],
            'page_url' => ['nullable', 'string', 'max:2048'],
            'context' => ['nullable', 'string'], // JSON blob of client diagnostics
            'files' => ['nullable', 'array', 'max:'.(int) config('dispatch.attachments.max_per_batch', 10)],
            'files.*' => ['file'],
        ]);

        $description = trim((string) ($data['description'] ?? ''));
        if (! empty($data['page_url'])) {
            $description .= ($description !== '' ? "\n\n" : '').'Reported from: '.$data['page_url'];
        }

        // Structured client diagnostics (url, user agent, viewport, console errors).
        $context = null;
        if (! empty($data['context'])) {
            $decoded = json_decode($data['context'], true);
            if (is_array($decoded)) {
                $context = $decoded;
            }
        }

        $task = $tasks->create(array_filter([
            'title' => $data['title'],
            'type' => $data['type'] ?? 'bug',
            'description' => $description !== '' ? $description : null,
            'status' => 'triage',
            'context' => $context,
            // TASK-997 part A — dispatch.capture.lane stamps every NEW widget
            // capture with a configured lane (e.g. a dev-lane-only feedback
            // form). An invalid config value is ignored + logged, never a
            // failed capture — a bug report must never be lost over a lane
            // misconfiguration.
            'lane' => $this->captureLane(),
        ], fn ($v) => $v !== null), ['source:widget'], Auth::user());

        foreach ((array) $request->file('files', []) as $file) {
            $attachments->store($file, $task, Auth::id());
        }

        return response()->json([
            'code' => $task->code,
            'title' => $task->title,
            'url' => route(config('dispatch.routes.name_prefix', 'dispatch.').'show', $task),
        ], 201);
    }

    /**
     * The configured `dispatch.capture.lane` (TASK-997 part A), or null when
     * unset or invalid. Validated against the bound LaneResolver here — never
     * inside DispatchTaskService::create(), which has no opinion on lanes —
     * so a stale/misconfigured value degrades to "no lane stamped" instead of
     * losing the whole capture.
     */
    protected function captureLane(): ?string
    {
        $lane = config('dispatch.capture.lane');
        if (empty($lane)) {
            return null;
        }

        if (! app(LaneResolver::class)->isLane((string) $lane)) {
            logger()->warning("dispatch.capture.lane is set to an unrecognized lane: {$lane}");

            return null;
        }

        return (string) $lane;
    }
}
