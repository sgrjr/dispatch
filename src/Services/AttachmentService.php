<?php

namespace Sgrjr\Dispatch\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Sgrjr\Dispatch\Contracts\AttachmentStore;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Models\TaskAttachment;
use Symfony\Component\HttpFoundation\Response;

/**
 * Store, validate, authorize, and stream attachments.
 *
 * Deliberately shared by both the HTTP AttachmentController and the Livewire
 * capture/thread components so upload rules and — critically — the download
 * authorization live in exactly one place. Files go to a PRIVATE disk under a
 * hashed path; downloads are gated by the SAME task visibility scope as the
 * board (DispatchGate::scopeVisible), never a public URL.
 *
 * WHERE the bytes live is the bound {@see AttachmentStore}'s business (a
 * private disk by default; a host's own file system when it binds one) —
 * this service keeps every rule, the store only moves bytes.
 */
class AttachmentService
{
    public function __construct(
        protected DispatchGate $gate,
        protected AttachmentStore $store,
    ) {}

    /**
     * Validate and persist an uploaded file as an attachment on a Task or
     * TaskComment. Throws ValidationException on a disallowed mime/size.
     */
    public function store(UploadedFile $file, Model $attachable, ?int $uploaderId = null): TaskAttachment
    {
        $this->validate($file);

        $prefix = trim((string) config('dispatch.attachments.path_prefix', 'dispatch/attachments'), '/');
        $folder = $prefix.'/'.date('Y').'/'.date('m');

        $stored = $this->store->put($file, $folder);

        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $isImage = str_starts_with($mime, 'image/');

        $meta = (array) ($stored['meta'] ?? []);
        if ($isImage) {
            $dimensions = @getimagesize($file->getRealPath());
            if (is_array($dimensions)) {
                $meta['width'] = $dimensions[0] ?? null;
                $meta['height'] = $dimensions[1] ?? null;
            }
        }

        /** @var class-string<TaskAttachment> $model */
        $model = config('dispatch.models.task_attachment');

        /** @var TaskAttachment $attachment */
        $attachment = new $model([
            'uploaded_by_user_id' => $uploaderId,
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $file->getSize() ?: 0,
            'is_image' => $isImage,
            'meta' => $meta ?: null,
        ]);
        $attachment->attachable()->associate($attachable);
        $attachment->save();

        return $attachment;
    }

    /**
     * @throws ValidationException
     */
    public function validate(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'The upload failed.']);
        }

        $maxKb = (int) config('dispatch.attachments.max_size_kb', 10240);
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'file' => "The file may not be larger than {$maxKb} KB.",
            ]);
        }

        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        if (! $this->mimeAllowed($mime)) {
            throw ValidationException::withMessages([
                'file' => "Files of type {$mime} are not allowed.",
            ]);
        }

        // Content sniff: a claimed image must actually decode as one.
        if (str_starts_with($mime, 'image/') && @getimagesize($file->getRealPath()) === false) {
            throw ValidationException::withMessages(['file' => 'The image file is not valid.']);
        }
    }

    private function mimeAllowed(string $mime): bool
    {
        $allowed = (array) config('dispatch.attachments.allowed_mimes', []);

        return empty($allowed) || in_array($mime, $allowed, true);
    }

    /**
     * TASK-1328 — the upload counterpart to `dispatch:attachment`'s download,
     * for an agent that already has bytes ON DISK (a generated report) rather
     * than an HTTP multipart upload. The allowlist deliberately excludes any
     * mime that can carry script (HTML, SVG — see config/dispatch.php
     * `attachments.allowed_mimes`); rather than hard-refuse those and leave
     * the artifact an orphan reachable only with shell access to the box,
     * this zips the file and attaches THAT — same posture a human already
     * uses by hand for a report that isn't a board-accepted type.
     *
     * Deliberately NOT folded into store()/validate(): a human's web upload
     * (AttachmentController::store) keeps the hard rejection, since silently
     * substituting a zip for a file a person picked themselves would be
     * surprising. This is the agent attach path only (`dispatch:attach`,
     * `POST agent/attach`).
     *
     * @return array{attachment: TaskAttachment, zipped_from: ?string} `zipped_from`
     *   is the original (disallowed) mime when a substitution happened, else null.
     *
     * @throws ValidationException
     */
    public function storeForAgent(UploadedFile $file, Model $attachable, ?int $uploaderId = null): array
    {
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());

        if ($this->mimeAllowed($mime)) {
            return ['attachment' => $this->store($file, $attachable, $uploaderId), 'zipped_from' => null];
        }

        [$zipped, $zipPath] = $this->zip($file);

        try {
            return ['attachment' => $this->store($zipped, $attachable, $uploaderId), 'zipped_from' => $mime];
        } finally {
            @unlink($zipPath);
        }
    }

    /**
     * @return array{0: UploadedFile, 1: string} the zip as an UploadedFile ready
     *   for store(), and its temp path (the caller unlinks it once store() has
     *   copied the bytes into the bound AttachmentStore).
     */
    private function zip(UploadedFile $file): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw ValidationException::withMessages([
                'file' => "Files of type {$file->getMimeType()} are not allowed, and the zip fallback needs PHP's zip extension (not installed).",
            ]);
        }

        $originalName = $file->getClientOriginalName();
        $zipPath = tempnam(sys_get_temp_dir(), 'dispatch-attach-').'.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);

            throw ValidationException::withMessages(['file' => 'Could not create a zip for a disallowed file type.']);
        }
        $zip->addFile($file->getRealPath(), $originalName);
        $zip->close();

        return [new UploadedFile($zipPath, $originalName.'.zip', 'application/zip', null, true), $zipPath];
    }

    /**
     * May $user download this attachment? Governed by the owning task's
     * visibility — the one and only scope.
     */
    public function canAccess(TaskAttachment $attachment, ?Authenticatable $user): bool
    {
        $task = $attachment->ownerTask();
        if ($task === null) {
            return false;
        }

        /** @var class-string $taskModel */
        $taskModel = config('dispatch.models.task');

        $query = $taskModel::query()->whereKey($task->getKey());
        $this->gate->scopeVisible($query, $user);

        return $query->exists();
    }

    /**
     * Stream the file to the browser as a download, under its original filename.
     */
    public function download(TaskAttachment $attachment): Response
    {
        return $this->store->response($attachment, false);
    }

    /**
     * Show the file IN the browser when its kind is safe to show
     * ({@see TaskAttachment::viewerKind()}): an image or a PDF as itself, a
     * CSV or text file as UTF-8 plain text. Anything else falls back to the
     * download — the backstop for a type no browser can render.
     *
     * Every inline answer is `nosniff`, so the browser never second-guesses
     * the type into HTML; text is additionally sandboxed by CSP, so even a
     * text file full of markup can only ever be read, never run.
     */
    public function view(TaskAttachment $attachment): Response
    {
        $kind = $attachment->viewerKind();
        if ($kind === null) {
            return $this->download($attachment);
        }

        $isText = in_array($kind, [TaskAttachment::VIEW_CSV, TaskAttachment::VIEW_TEXT], true);
        $response = $this->store->response($attachment, true, $isText ? 'text/plain; charset=UTF-8' : null);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($isText) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        }

        return $response;
    }

    /**
     * Delete the stored file and the record.
     */
    public function delete(TaskAttachment $attachment): void
    {
        $this->store->delete($attachment);
        $attachment->delete();
    }
}
