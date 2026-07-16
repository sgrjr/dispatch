<?php

use Illuminate\Support\Facades\Route;
use Sgrjr\Dispatch\Http\Controllers\AttachmentController;
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

// Attachments — authorized upload/stream/delete via the shared AttachmentService.
Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');
Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

// Submitter portal — "my submissions" (separate middleware so non-staff reach it).
Route::middleware(config('dispatch.routes.portal_middleware', ['web', 'auth']))
    ->get('/mine', MySubmissions::class)
    ->name('portal');

Route::get('/{task:code}', TaskShow::class)->name('show');
