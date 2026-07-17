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
 * Session commissioning (RFC 8628) — two DIFFERENT credentials, so two groups:
 *
 *   POST session            -> REQUEST a session (returns public_id + device_code +
 *                              user_code). UNAUTHENTICATED and a spam/social-
 *                              engineering vector — it creates a row in the human
 *                              approval queue — so it is bootstrap-secret gated
 *                              (X-Dispatch-Bootstrap) AND throttled.
 *   GET  session/{publicId}  -> POLL for approval; token delivered once on approval.
 *                              Authenticated by the per-session device_code issued
 *                              at request time (RFC 8628), NOT the shared bootstrap
 *                              secret: polling creates no queue state, and the
 *                              device_code is the credential that gates token
 *                              delivery. Throttled, but deliberately not bootstrap-
 *                              gated (the bootstrap secret guards only the request).
 */
Route::middleware(['dispatch.agent.bootstrap', 'throttle:dispatch-agent-request'])->group(function () {
    Route::post('session', [AgentSessionController::class, 'request'])->name('session.request');
});

Route::middleware(['throttle:dispatch-agent-request'])->group(function () {
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

    // Batch memorialize (§20) — apply a whole manifest of add/update ops in ONE
    // transactional hit instead of a verb call per task. Additive + server-bounded
    // (no delete, labels attach not replace, status never assumed done), so it
    // stays inside the curated-verb posture despite acting on many tasks at once.
    Route::post('batch', [AgentController::class, 'batch'])->middleware('dispatch.agent.scope:batch')->name('batch');

    // Self-revoke (§20). Bearer-authed like the verbs, but deliberately NOT
    // scope-gated — ending your own session is a hygiene action every agent may
    // take, not a backlog verb. Identified purely by the bearer token (no id
    // param), so an agent can only ever end ITSELF, never another session.
    Route::post('session/end', [AgentController::class, 'end'])->name('session.end');
});
