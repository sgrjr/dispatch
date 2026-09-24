<?php

namespace Sgrjr\Dispatch\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Sgrjr\Dispatch\Contracts\AttachmentStore;
use Sgrjr\Dispatch\Models\TaskAttachment;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The default {@see AttachmentStore}: bytes on a (private) Laravel disk,
 * `dispatch.attachments.disk`, under an unguessable hashed filename.
 */
class DiskAttachmentStore implements AttachmentStore
{
    public function put(UploadedFile $file, string $folder): array
    {
        $disk = (string) config('dispatch.attachments.disk', 'local');

        // hashName() is an unguessable random filename; combined with a private
        // disk this means the only way to reach a file is the authorized route.
        return [
            'disk' => $disk,
            'path' => $file->storeAs($folder, $file->hashName(), ['disk' => $disk]),
        ];
    }

    public function response(TaskAttachment $attachment, bool $inline, ?string $contentType = null): Response
    {
        $headers = $contentType !== null ? ['Content-Type' => $contentType] : [];

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            $headers,
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
        );
    }

    public function delete(TaskAttachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);
    }
}
