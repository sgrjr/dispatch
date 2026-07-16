<?php

use Illuminate\Support\Facades\Route;
use Sgrjr\Dispatch\Http\Controllers\SyncController;

/*
 * Cross-instance JSON-LD sync (package <-> package on the same schema).
 * Prefix (api/dispatch), name-prefix, and middleware applied by the provider.
 * Both endpoints are additionally super-user gated inside the controller.
 */

Route::get('/snapshot', [SyncController::class, 'snapshot'])->name('sync.snapshot');
Route::post('/apply', [SyncController::class, 'apply'])->name('sync.apply');
