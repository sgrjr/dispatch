<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Sgrjr\Dispatch\Contracts\AttachmentStore;
use Sgrjr\Dispatch\Models\TaskAttachment;
use Sgrjr\Dispatch\Services\AttachmentService;
use Sgrjr\Dispatch\Services\DiskAttachmentStore;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Symfony\Component\HttpFoundation\Response;

/*
 * The AttachmentStore seam: WHERE the bytes live is the bound store's business
 * (a private disk by default, a host's own file system when it binds one); the
 * record, validation, the visibility gate and the inline-view policy stay in
 * AttachmentService. And the view route: what a browser can show opens inline,
 * safely; everything else downloads.
 */

function storedAttachment(string $name, string $mime, string $contents = 'x'): TaskAttachment
{
    $disk = config('dispatch.attachments.disk');
    Storage::disk($disk)->put('dispatch/attachments/'.$name, $contents);
    $task = app(DispatchTaskService::class)->create(['title' => 'Has a file', 'is_public' => true]);

    return $task->attachments()->create([
        'disk' => $disk, 'path' => 'dispatch/attachments/'.$name, 'original_name' => $name,
        'mime_type' => $mime, 'size_bytes' => strlen($contents), 'is_image' => str_starts_with($mime, 'image/'),
    ]);
}

beforeEach(fn () => Storage::fake(config('dispatch.attachments.disk')));

test('the default store is the private-disk store', function () {
    expect(app(AttachmentStore::class))->toBeInstanceOf(DiskAttachmentStore::class);
});

test('viewerKind: raster images and PDFs show as themselves, CSV and text as text, the rest download', function (string $name, string $mime, ?string $kind) {
    $attachment = new TaskAttachment(['original_name' => $name, 'mime_type' => $mime]);

    expect($attachment->viewerKind())->toBe($kind);
})->with([
    ['shot.png', 'image/png', TaskAttachment::VIEW_IMAGE],
    ['logo.svg', 'image/svg+xml', null],
    ['invoice.pdf', 'application/pdf', TaskAttachment::VIEW_PDF],
    ['orders.csv', 'text/csv', TaskAttachment::VIEW_CSV],
    ['orders.csv', 'text/plain', TaskAttachment::VIEW_CSV],
    ['orders.csv', 'application/vnd.ms-excel', TaskAttachment::VIEW_CSV],
    ['old.xls', 'application/vnd.ms-excel', null],
    ['trace.txt', 'text/plain', TaskAttachment::VIEW_TEXT],
    ['payload.json', 'application/json', TaskAttachment::VIEW_TEXT],
    ['page.html', 'text/html', TaskAttachment::VIEW_TEXT],
    ['report.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null],
    ['bundle.zip', 'application/zip', null],
]);

test('view() shows an image inline, never letting the browser sniff it into something else', function () {
    $response = app(AttachmentService::class)->view(storedAttachment('shot.png', 'image/png'));

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBeNull();
});

test('view() serves a CSV — and even an HTML file — as sandboxed plain text', function (string $name, string $mime) {
    $response = app(AttachmentService::class)->view(storedAttachment($name, $mime, '<script>alert(1)</script>'));

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
})->with([
    ['orders.csv', 'text/csv'],
    ['page.html', 'text/html'],
]);

test('view() falls back to a download for a file no browser can show', function () {
    $response = app(AttachmentService::class)->view(storedAttachment('report.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Content-Disposition'))->toContain('report.xlsx');
});

test('the view route is registered beside download', function () {
    $attachment = storedAttachment('trace.txt', 'text/plain');

    expect(route('dispatch.attachments.view', $attachment))->toEndWith('/attachments/'.$attachment->id.'/view');
});

test('a bound AttachmentStore owns the bytes: store(), download() and delete() all go through it', function () {
    $store = new class implements AttachmentStore
    {
        public array $calls = [];

        public function put(UploadedFile $file, string $folder): array
        {
            $this->calls[] = 'put:'.$folder;

            return ['disk' => 'host-files', 'path' => 'host/'.$file->getClientOriginalName(), 'meta' => ['file_id' => 42]];
        }

        public function response(TaskAttachment $attachment, bool $inline, ?string $contentType = null): Response
        {
            $this->calls[] = 'response:'.($inline ? 'inline' : 'attachment');

            return new Response('bytes');
        }

        public function delete(TaskAttachment $attachment): void
        {
            $this->calls[] = 'delete:'.$attachment->path;
        }
    };
    app()->instance(AttachmentStore::class, $store);
    app()->forgetInstance(AttachmentService::class);

    $task = app(DispatchTaskService::class)->create(['title' => 'Host-stored', 'is_public' => true]);
    $attachment = app(AttachmentService::class)->store(UploadedFile::fake()->createWithContent('notes.txt', 'hello'), $task, 5);

    expect($attachment->disk)->toBe('host-files')
        ->and($attachment->path)->toBe('host/notes.txt')
        ->and($attachment->meta)->toBe(['file_id' => 42])
        ->and($attachment->mime_type)->toBe('text/plain');

    app(AttachmentService::class)->download($attachment);
    app(AttachmentService::class)->view($attachment);
    app(AttachmentService::class)->delete($attachment);

    expect($store->calls)->toBe([
        'put:dispatch/attachments/'.date('Y').'/'.date('m'),
        'response:attachment',
        'response:inline',
        'delete:host/notes.txt',
    ])->and(TaskAttachment::query()->whereKey($attachment->id)->exists())->toBeFalse();
});

test('a CSV upload passes the default allow-list', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'Has a CSV', 'is_public' => true]);

    $attachment = app(AttachmentService::class)->store(UploadedFile::fake()->createWithContent('orders.csv', "isbn,qty\n978,2\n"), $task);

    expect($attachment->viewerKind())->toBe(TaskAttachment::VIEW_CSV);
});
