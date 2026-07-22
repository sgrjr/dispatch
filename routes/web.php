<?php

use Illuminate\Support\Facades\Route;
use Sgrjr\Dispatch\Http\Controllers\AttachmentController;
use Sgrjr\Dispatch\Http\Controllers\CaptureController;
use Sgrjr\Dispatch\Livewire\DispatchWidget;
use Sgrjr\Dispatch\Livewire\MySubmissions;
use Sgrjr\Dispatch\Livewire\TaskBoard;
use Sgrjr\Dispatch\Livewire\TaskCreate;
use Sgrjr\Dispatch\Livewire\TaskList;
use Sgrjr\Dispatch\Livewire\TaskShow;

/*
 * Prefix, name-prefix, and middleware are applied by DispatchServiceProvider.
 * Keep the wildcard {task:code} route LAST so literal segments win.
 */

Route::get('/', TaskList::class)->name('index');
Route::get('/board', TaskBoard::class)->name('board');
Route::get('/new', TaskCreate::class)->name('create');

// Headless JSON capture — the frontend-agnostic report entry point (Vue/any JS host).
// Rate-limited by the named 'dispatch-capture' limiter (see DispatchServiceProvider),
// which reads dispatch.capture.throttle at request time (null/false = unlimited).
Route::post('/capture', [CaptureController::class, 'store'])->middleware('throttle:dispatch-capture')->name('capture');

// Attachments — authorized upload/stream/delete via the shared AttachmentService.
Route::post('/attachments', [AttachmentController::class, 'store'])->middleware('throttle:dispatch-capture')->name('attachments.store');
Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');
Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

// Submitter portal — "my submissions" (separate middleware so non-staff reach it).
Route::middleware(config('dispatch.routes.portal_middleware', ['web', 'auth']))
    ->get('/mine', MySubmissions::class)
    ->name('portal');

// Staff "Agent Sessions" approval queue (§20 Phase 3). Registered only once the
// Wave-1 component exists — a route action is eagerly validated at registration
// (unlike the provider's class_exists-guarded class-strings), so it can't be a
// bare forward reference to a class that doesn't exist yet.
if (class_exists(\Sgrjr\Dispatch\Livewire\AgentSessions::class)) {
    Route::get('/agent-sessions', \Sgrjr\Dispatch\Livewire\AgentSessions::class)->name('agent-sessions');
}

// Staff "Focuses" management surface (roadmap W8-2). Gated the same way as the
// agent-sessions route above — a route action is eagerly validated at
// registration, so it can only be referenced once the component class exists.
if (class_exists(\Sgrjr\Dispatch\Livewire\FocusPanel::class)) {
    Route::get('/focuses', \Sgrjr\Dispatch\Livewire\FocusPanel::class)->name('focuses');
}

Route::get('/{task:code}', TaskShow::class)->name('show');
