<?php

namespace Sgrjr\Dispatch\Contracts;

use Illuminate\Http\UploadedFile;
use Sgrjr\Dispatch\Models\TaskAttachment;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where attachment BYTES live — the filesystem seam.
 *
 * The package owns the attachment RECORD (dispatch_task_attachments: who,
 * what, which task or comment) and every rule around it: validation, the
 * visibility-gated download, the inline-view policy. A store owns only the
 * bytes: putting them somewhere, streaming them back, removing them. So a
 * host can keep attachments in its own file system — its own model, disk
 * and directory conventions — without re-implementing any of the rules.
 *
 * Bound via `dispatch.contracts.attachment_store`; the default is
 * {@see \Sgrjr\Dispatch\Services\DiskAttachmentStore} (a private Laravel disk).
 *
 * ⛔ A store must never expose its own public URL for the bytes: the ONLY
 * way to reach an attachment is the package's authorized routes, which ask
 * the owning task's visibility gate every time.
 */
interface AttachmentStore
{
    /**
     * Persist an already-validated upload.
     *
     * @param  string  $folder  a suggested relative folder (e.g. dispatch/attachments/2026/09)
     * @return array{disk: string, path: string, meta?: array<string, mixed>}  what the record keeps; `meta` merges into its meta
     */
    public function put(UploadedFile $file, string $folder): array;

    /**
     * Stream the bytes back. `$inline` asks for Content-Disposition: inline
     * (the caller has already decided the type is safe to show); otherwise an
     * attachment download under the original filename. `$contentType`
     * overrides the stored mime (a CSV is served as text/plain to be shown).
     */
    public function response(TaskAttachment $attachment, bool $inline, ?string $contentType = null): Response;

    /** Remove the bytes (the record is the caller's to delete). */
    public function delete(TaskAttachment $attachment): void;
}
