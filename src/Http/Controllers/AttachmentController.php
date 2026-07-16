<?php

namespace Sgrjr\Dispatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskAttachment;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\AttachmentService;

/**
 * Upload/download/delete attachments on a Task or a TaskComment.
 *
 * All storage/validation/access-control logic lives in {@see AttachmentService};
 * this controller only resolves the target model, defers to TaskPolicy for
 * authorization, and shapes the HTTP response (JSON for XHR/API callers,
 * redirect-back for classic form posts).
 */
class AttachmentController extends Controller
{
    /**
     * Accept an uploaded file for a task (by `task_code` or `task_id`) or a
     * comment (`comment_id`). Authorization is the same 'comment' ability the
     * thread uses to post a reply — adding a file is just a body-less comment.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file'],
            'task_code' => ['nullable', 'string'],
            'task_id' => ['nullable', 'integer'],
            'comment_id' => ['nullable', 'integer'],
        ]);

        [$attachable, $task] = $this->resolveTarget($request);

        Gate::authorize('comment', $task);

        $attachment = app(AttachmentService::class)->store(
            $request->file('file'),
            $attachable,
            Auth::id(),
        );

        $payload = [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
            'is_image' => $attachment->is_image,
            'download_url' => route('dispatch.attachments.download', $attachment),
        ];

        if ($request->expectsJson()) {
            return response()->json($payload, 201);
        }

        return back()->with('dispatch_attachment', $payload);
    }

    /**
     * Stream the file. 403s unless the attachment's owning task is visible to
     * the current user (AttachmentService::canAccess reuses the one scope).
     */
    public function download(TaskAttachment $attachment): StreamedResponse
    {
        abort_unless(
            app(AttachmentService::class)->canAccess($attachment, Auth::user()),
            403,
        );

        return app(AttachmentService::class)->download($attachment);
    }

    /**
     * Delete an attachment. Allowed for staff (per DispatchGate::isStaff) or
     * the original uploader.
     */
    public function destroy(TaskAttachment $attachment): JsonResponse|RedirectResponse
    {
        $user = Auth::user();
        $isUploader = $attachment->uploaded_by_user_id !== null
            && $attachment->uploaded_by_user_id === Auth::id();

        abort_unless(
            app(DispatchGate::class)->isStaff($user) || $isUploader,
            403,
        );

        app(AttachmentService::class)->delete($attachment);

        if (request()->expectsJson()) {
            return response()->json(['status' => 'deleted']);
        }

        return back()->with('status', 'Attachment deleted.');
    }

    /**
     * Resolve the model the upload attaches to (a Task or a TaskComment) and
     * the Task whose policy governs the operation.
     *
     * @return array{0: Task|TaskComment, 1: Task}
     */
    protected function resolveTarget(Request $request): array
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');
        /** @var class-string<TaskComment> $commentModel */
        $commentModel = config('dispatch.models.task_comment');

        if ($request->filled('comment_id')) {
            /** @var TaskComment $comment */
            $comment = $commentModel::query()->findOrFail($request->input('comment_id'));
            $task = $comment->task;

            abort_if($task === null, 404, 'The comment has no owning task.');

            return [$comment, $task];
        }

        if ($request->filled('task_code')) {
            /** @var Task $task */
            $task = $taskModel::query()->where('code', $request->input('task_code'))->firstOrFail();

            return [$task, $task];
        }

        if ($request->filled('task_id')) {
            /** @var Task $task */
            $task = $taskModel::query()->findOrFail($request->input('task_id'));

            return [$task, $task];
        }

        abort(422, 'A task_code, task_id, or comment_id is required.');
    }
}
