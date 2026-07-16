<?php

use Illuminate\Support\Facades\Route;
use Sgrjr\Dispatch\Http\Controllers\AgentController;
use Sgrjr\Dispatch\Http\Controllers\AgentSessionController;

/*
 * Dedicated agent API (§19/§20) — a SEPARATE surface from the human/sync routes,
 * with its own security posture. The group prefix (api/dispatch/agent), name
 * (dispatch.api.agent.), and base middleware are applied by
 * DispatchServiceProvider::registerRoutes(); the per-route security middleware
 * is declared here. This file is only loaded once the AgentController classes
 * exist (class_exists guard in the provider), so the `use` refs above are safe
 * before Wave 1 lands.
 *
 * Session commissioning — UNAUTHENTICATED, bootstrap-gated + throttled (RFC 8628):
 *   POST session            -> request a session (returns public_id + device_code + user_code)
 *   GET  session/{publicId}  -> poll (device_code required); token delivered once on approval
 */
Route::middleware(['dispatch.agent.bootstrap', 'throttle:dispatch-agent-request'])->group(function () {
    Route::post('session', [AgentSessionController::class, 'request'])->name('session.request');
    Route::get('session/{publicId}', [AgentSessionController::class, 'poll'])->name('session.poll');
});

/*
 * Verb endpoints — bearer session token, per-verb scope-gated, per-agent throttle.
 * `dispatch.agent` binds + authenticates the session; `dispatch.agent.scope:<verb>`
 * enforces the per-session allowlist.
 */
Route::middleware(['dispatch.agent', 'throttle:dispatch-agent-verb'])->group(function () {
    Route::get('next', [AgentController::class, 'next'])->middleware('dispatch.agent.scope:next')->name('next');
    Route::get('queue', [AgentController::class, 'queue'])->middleware('dispatch.agent.scope:queue')->name('queue');
    Route::get('show/{code}', [AgentController::class, 'show'])->middleware('dispatch.agent.scope:show')->name('show');
    Route::post('claim', [AgentController::class, 'claim'])->middleware('dispatch.agent.scope:claim')->name('claim');
    Route::post('add', [AgentController::class, 'add'])->middleware('dispatch.agent.scope:add')->name('add');
    Route::post('note', [AgentController::class, 'note'])->middleware('dispatch.agent.scope:note')->name('note');
    Route::post('done', [AgentController::class, 'done'])->middleware('dispatch.agent.scope:done')->name('done');
});
