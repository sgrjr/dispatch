<?php

namespace Sgrjr\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TaskAttachment extends Model
{
    protected $table = 'dispatch_task_attachments';

    protected $fillable = [
        'uploaded_by_user_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'is_image',
        'meta',
    ];

    protected $casts = [
        'is_image' => 'boolean',
        'size_bytes' => 'integer',
        'meta' => 'array',
    ];

    /** What a browser can show inline — {@see viewerKind()}. */
    public const VIEW_IMAGE = 'image';

    public const VIEW_PDF = 'pdf';

    public const VIEW_CSV = 'csv';

    public const VIEW_TEXT = 'text';

    /**
     * TASK-1328 — rendered through the SAME safe converter already trusted for
     * comment bodies and task descriptions ({@see \Sgrjr\Dispatch\Support\Markdown}),
     * never the raw bytes: real HTML, not sandboxed plain text.
     */
    public const VIEW_MARKDOWN = 'markdown';

    /** Raster images only: an SVG can carry script, so it is never shown inline. */
    private const INLINE_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    private const CSV_MIMES = ['text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'];

    private const MARKDOWN_EXTENSIONS = ['md', 'markdown'];

    /**
     * How this file can be viewed in a browser: `image` / `pdf` as themselves,
     * `markdown` rendered to safe HTML, `csv` (a table to render) / `text`
     * served as plain text — or null, meaning download it (the backstop for
     * everything a browser cannot show).
     *
     * Reads the stored (server-sniffed) mime first; the extension only refines
     * a text/* sniff (a CSV usually sniffs as text/plain, and content-sniffing
     * has no way to tell ".md" text apart from any other plain text — a REAL
     * upload almost never arrives already labeled `text/markdown`, unlike a
     * synthetic UploadedFile that infers it from the extension) and never
     * turns a binary into something shown inline.
     */
    public function viewerKind(): ?string
    {
        $mime = strtolower((string) $this->mime_type);
        $ext = strtolower(pathinfo((string) $this->original_name, PATHINFO_EXTENSION));

        if (in_array($mime, self::INLINE_IMAGE_MIMES, true)) {
            return self::VIEW_IMAGE;
        }
        if ($mime === 'application/pdf') {
            return self::VIEW_PDF;
        }
        if (in_array($mime, self::CSV_MIMES, true) && ($ext === 'csv' || $mime !== 'application/vnd.ms-excel')) {
            return self::VIEW_CSV;
        }
        if (str_starts_with($mime, 'text/') || in_array($mime, ['application/json', 'application/xml'], true)) {
            if (in_array($ext, self::MARKDOWN_EXTENSIONS, true)) {
                return self::VIEW_MARKDOWN;
            }

            return $ext === 'csv' ? self::VIEW_CSV : self::VIEW_TEXT;
        }

        return null;
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(config('dispatch.models.user'), 'uploaded_by_user_id');
    }

    /**
     * The owning Task — directly (task attachment) or via the parent comment.
     * Used by the download authorization check so a single visibility rule
     * governs both task-level and comment-level attachments.
     */
    public function ownerTask(): ?Task
    {
        $parent = $this->attachable;

        if ($parent instanceof TaskComment) {
            return $parent->task;
        }

        return $parent instanceof Task ? $parent : null;
    }
}
