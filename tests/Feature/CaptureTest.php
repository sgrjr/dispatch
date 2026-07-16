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
            'context' => json_encode([
                'url' => 'https://app.test/dashboard',
                'user_agent' => 'PestBrowser/1.0',
                'viewport' => ['w' => 1280, 'h' => 800, 'dpr' => 2],
                'console_errors' => [
                    ['type' => 'console.error', 'message' => 'Cannot read properties of undefined'],
                ],
            ]),
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

    // Structured context is captured and stored.
    expect($task->context)->toBeArray();
    expect($task->context['user_agent'])->toBe('PestBrowser/1.0');
    expect($task->context['console_errors'][0]['message'])->toBe('Cannot read properties of undefined');
});

test('the capture endpoint requires a title', function () {
    $response = $this
        ->actingAs(actingCaptureUser())
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->postJson('/dispatch/capture', ['type' => 'bug']);

    $response->assertStatus(422);
});
