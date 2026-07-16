<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Sgrjr\Dispatch\Models\Task;

/**
 * The headless capture endpoint that the published Vue widget (and the Livewire
 * widget's server path) both feed. This is the from-any-page report entry point.
 */

function actingCaptureUser(): \Illuminate\Contracts\Auth\Authenticatable
{
    return new class extends \Illuminate\Foundation\Auth\User
    {
        protected $attributes = ['id' => 1];
    };
}

test('the capture endpoint creates a triage task with a pasted screenshot', function () {
    Storage::fake(config('dispatch.attachments.disk'));

    $response = $this
        ->actingAs(actingCaptureUser())
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->post('/dispatch/capture', [
            'title' => 'Button is broken on the dashboard',
            'type' => 'bug',
            'page_url' => 'https://app.test/dashboard',
            'files' => [UploadedFile::fake()->image('shot.png')],
        ]);

    $response->assertCreated();

    $task = Task::query()->where('title', 'Button is broken on the dashboard')->first();

    expect($task)->not->toBeNull();
    expect($task->type)->toBe('bug');
    expect($task->status)->toBe('triage');
    expect($task->description)->toContain('app.test/dashboard');
    expect($task->attachments()->count())->toBe(1);
    expect($task->labels->pluck('name')->all())->toContain('source:widget');
});

test('the capture endpoint requires a title', function () {
    $response = $this
        ->actingAs(actingCaptureUser())
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->postJson('/dispatch/capture', ['type' => 'bug']);

    $response->assertStatus(422);
});
