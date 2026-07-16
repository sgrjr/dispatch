<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Models\TaskAttachment;
use Sgrjr\Dispatch\Services\AttachmentService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * AttachmentService is the ONLY path that stores/validates/authorizes files.
 * Files live on a private disk (never a public URL); canAccess() re-uses the
 * DispatchGate scope so a task hidden from a user hides its attachments too.
 */

test('AttachmentService::store persists a task_attachments row and writes the file to the private disk', function () {
    $disk = config('dispatch.attachments.disk');
    Storage::fake($disk);

    $task = app(DispatchTaskService::class)->create(['title' => 'Needs a screenshot', 'is_public' => true]);
    $file = UploadedFile::fake()->image('shot.png');

    $attachment = app(AttachmentService::class)->store($file, $task, 9);

    expect($attachment)->toBeInstanceOf(TaskAttachment::class);
    expect($attachment->exists)->toBeTrue();
    expect($attachment->attachable_id)->toBe($task->id);
    expect($attachment->disk)->toBe($disk);
    expect($attachment->original_name)->toBe('shot.png');
    expect($attachment->is_image)->toBeTrue();
    expect($attachment->uploaded_by_user_id)->toBe(9);

    expect(TaskAttachment::query()->count())->toBe(1);
    Storage::disk($disk)->assertExists($attachment->path);
});

test('AttachmentService::canAccess returns false when the owning task falls outside the gate scope', function () {
    $disk = config('dispatch.attachments.disk');
    Storage::fake($disk);

    $task = app(DispatchTaskService::class)->create(['title' => 'Public task with a screenshot', 'is_public' => true]);
    $attachment = app(AttachmentService::class)->store(UploadedFile::fake()->image('shot.png'), $task);

    // Positive control: under the shipped DefaultGate a public task is visible
    // even to a guest, so its attachment is accessible.
    expect(app(AttachmentService::class)->canAccess($attachment, null))->toBeTrue();

    // Now bind a gate whose scope hides EVERY task (standing in for "this
    // user's tenant/staff status excludes this task"). canAccess() must fall
    // through to false rather than granting access some other way.
    $hidingGate = new class implements DispatchGate
    {
        public function isStaff(?Authenticatable $user): bool
        {
            return false;
        }

        public function canSeeAll(?Authenticatable $user): bool
        {
            return false;
        }

        public function scopeVisible(Builder $query, ?Authenticatable $user): Builder
        {
            return $query->whereRaw('1 = 0');
        }
    };
    app()->singleton(DispatchGate::class, fn () => $hidingGate);

    $strangerUser = new class extends AuthenticatableUser {};
    $strangerUser->id = 123;

    expect(app(AttachmentService::class)->canAccess($attachment, $strangerUser))->toBeFalse();
    expect(app(AttachmentService::class)->canAccess($attachment, null))->toBeFalse();
});

test('AttachmentService rejects a disallowed mime type with a ValidationException and never persists it', function () {
    $disk = config('dispatch.attachments.disk');
    Storage::fake($disk);

    $task = app(DispatchTaskService::class)->create(['title' => 'Malware drop attempt']);
    $badFile = UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload');

    expect(fn () => app(AttachmentService::class)->validate($badFile))
        ->toThrow(ValidationException::class);

    expect(fn () => app(AttachmentService::class)->store($badFile, $task))
        ->toThrow(ValidationException::class);

    expect(TaskAttachment::query()->count())->toBe(0);
    expect(Storage::disk($disk)->allFiles())->toBeEmpty();
});

test('AttachmentService rejects a file over the configured size limit', function () {
    $disk = config('dispatch.attachments.disk');
    Storage::fake($disk);

    config(['dispatch.attachments.max_size_kb' => 100]);

    $task = app(DispatchTaskService::class)->create(['title' => 'Oversized upload attempt']);
    $tooBig = UploadedFile::fake()->create('huge.pdf', 200, 'application/pdf');

    expect(fn () => app(AttachmentService::class)->store($tooBig, $task))
        ->toThrow(ValidationException::class);

    expect(TaskAttachment::query()->count())->toBe(0);
});
