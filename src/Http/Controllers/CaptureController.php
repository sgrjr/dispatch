<?php

namespace Sgrjr\Dispatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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
            'files' => ['nullable', 'array', 'max:'.(int) config('dispatch.attachments.max_per_batch', 10)],
            'files.*' => ['file'],
        ]);

        $description = trim((string) ($data['description'] ?? ''));
        if (! empty($data['page_url'])) {
            $description .= ($description !== '' ? "\n\n" : '').'Reported from: '.$data['page_url'];
        }

        $task = $tasks->create([
            'title' => $data['title'],
            'type' => $data['type'] ?? 'bug',
            'description' => $description !== '' ? $description : null,
            'status' => 'triage',
        ], ['source:widget'], Auth::user());

        foreach ((array) $request->file('files', []) as $file) {
            $attachments->store($file, $task, Auth::id());
        }

        return response()->json([
            'code' => $task->code,
            'title' => $task->title,
            'url' => route(config('dispatch.routes.name_prefix', 'dispatch.').'show', $task),
        ], 201);
    }
}
