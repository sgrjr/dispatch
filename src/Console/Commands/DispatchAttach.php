<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Sgrjr\Dispatch\Console\Commands\Concerns\ResolvesTextInput;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\AttachmentService;

/**
 * TASK-1328 — attach a file already on disk to a task (or one of its
 * comments): the upload counterpart to `dispatch:attachment`'s download.
 *
 * The gap this closes: an agent that writes an artifact straight to the
 * filesystem (e.g. a report under storage/app/private/reports/*.html)
 * produces bytes no auth-gated URL ever serves — an orphaned artifact,
 * reachable only with shell access to the box. This makes it a real
 * TaskAttachment, reachable through the SAME task-visibility gate as
 * everything else on the board.
 *
 * A mime the board doesn't accept inline (SVG/HTML — see
 * config/dispatch.php `attachments.allowed_mimes`, deliberately excluded
 * because both can carry script) is zipped rather than refused, so the
 * artifact is never silently dropped just because of its format — {@see
 * AttachmentService::storeForAgent()}. Prefer a board-accepted format
 * (Markdown/CSV/plain text/PDF/an image) in the first place: it attaches
 * directly and, for Markdown, gets a real preview in the UI — a zip only
 * downloads.
 */
class DispatchAttach extends Command
{
    use ResolvesTextInput;
    use TalksToAgentApi;

    protected $signature = 'dispatch:attach
        {code : The task code, e.g. TASK-042}
        {path : Local path to the file to attach}
        {--as= : Display filename (default: the file\'s own basename)}
        {--body= : Post this as a NEW internal note and attach the file to it (markdown ok)}
        {--body-file= : Read --body from a file (or `-` for stdin) instead of inline}
        {--comment-id= : Attach to this EXISTING comment instead of the task or a new one — lets several files share one note}
        {--public : The note (if any) is public — the submitter sees it and is emailed (default: internal, staff only)}
        {--remote : Act on the configured remote agent API (the default while an agent session token is active)}
        {--local : Act on the local DB even while an agent session token is active (overrides sticky-remote)}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = "Attach a local file to a task — the upload counterpart to dispatch:attachment's download.";

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Not a readable file: {$path}");

            return self::FAILURE;
        }

        [$body, $err] = $this->resolveInlineOrFile(
            $this->option('body'),
            $this->option('body-file'),
            '--body',
            '--body-file',
        );
        if ($err !== null) {
            $this->error($err);

            return self::FAILURE;
        }

        $commentId = $this->option('comment-id');
        if ($commentId !== null && $body !== null) {
            $this->error('Use either --comment-id (attach to an existing note) or --body (post a new one), not both.');

            return self::FAILURE;
        }

        $filename = $this->option('as') ?: basename($path);

        return $this->targetsRemote()
            ? $this->attachRemote($path, $filename, $commentId, $body)
            : $this->attachLocal($path, $filename, $commentId, $body);
    }

    private function attachRemote(string $path, string $filename, ?string $commentId, ?string $body): int
    {
        $r = $this->agentPostFile('attach', $path, $filename, array_filter([
            'code' => $this->argument('code'),
            'comment_id' => $commentId,
            'body' => $body,
            'public' => $this->option('public') ? true : null,
        ], fn ($v) => $v !== null));

        if ($r === null) {
            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->announce($r['attachment']['original_name'], $r['zipped_from'] ?? null, $r['comment_id'] ?? null);

        return self::SUCCESS;
    }

    private function attachLocal(string $path, string $filename, ?string $commentId, ?string $body): int
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');
        $task = $taskModel::query()->where('code', $this->argument('code'))->first();
        if (! $task) {
            $this->error("Task not found: {$this->argument('code')}");

            return self::FAILURE;
        }

        $attachable = $task;
        $resolvedCommentId = null;

        if ($commentId !== null) {
            $comment = $task->comments()->whereKey((int) $commentId)->first();
            if ($comment === null) {
                $this->error("{$task->code} has no comment #{$commentId}.");

                return self::FAILURE;
            }
            $attachable = $comment;
            $resolvedCommentId = $comment->id;
        } elseif ($body !== null) {
            $comment = $task->comments()->create([
                'user_id' => Auth::id(),
                'body' => $body,
                'is_internal' => ! $this->option('public'),
                'event_type' => TaskComment::EVENT_COMMENT,
            ]);
            $attachable = $comment;
            $resolvedCommentId = $comment->id;
        }

        $file = new UploadedFile($path, $filename, null, null, true);

        try {
            $result = app(AttachmentService::class)->storeForAgent($file, $attachable, Auth::id());
        } catch (ValidationException $e) {
            $this->error((string) collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'task' => $task->code,
                'attachment' => [
                    'id' => $result['attachment']->id,
                    'original_name' => $result['attachment']->original_name,
                    'mime_type' => $result['attachment']->mime_type,
                    'size_bytes' => $result['attachment']->size_bytes,
                ],
                'zipped_from' => $result['zipped_from'],
                'comment_id' => $resolvedCommentId,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->announce($result['attachment']->original_name, $result['zipped_from'], $resolvedCommentId);

        return self::SUCCESS;
    }

    private function announce(string $name, ?string $zippedFrom, ?int $commentId): void
    {
        $this->info('Attached '.$name.($commentId !== null ? " (comment #{$commentId})" : ' (task-level)').'.');
        if ($zippedFrom !== null) {
            $this->line("Note: {$zippedFrom} isn't a board-accepted attachment type, so the file was zipped before attaching.");
        }
    }
}
