<?php

use Livewire\Livewire;
use Sgrjr\Dispatch\Livewire\TaskShow;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\ContextPresenter;

/**
 * TASK-2299 — a task's context.result (the agent's close-out) is rendered, and
 * "diagnostic context" means real evidence, not a healthy page's user agent.
 */
function closeOutContext(): array
{
    return [
        'url' => 'https://example.test/db/standing_orders',
        'user_agent' => 'Mozilla/5.0',
        'viewport' => ['w' => 1920, 'h' => 945, 'dpr' => 1],
        'console_errors' => [],
        'result' => [
            'summary' => 'Rebuilt the plans search.',
            'resolution' => 'built',
            'commit' => '14397cfb',
            'tests' => 'ParamsTest 38 green',
            'deploy' => 'staff only, no migration',
            'owner_check' => ['Open /db/standing_orders and try the presets.', 'In /chat: show expiring plans.'],
            'follow_ups_not_built' => ['a GET for the global-actions list'],
            'metrics' => ['tokens' => ['total' => 10]],
        ],
    ];
}

test('a browser capture of a healthy page is not diagnostic evidence; one console error is', function () {
    $healthy = app(DispatchTaskService::class)->create(['title' => 'Widget-filed', 'context' => closeOutContext()]);
    $broken = app(DispatchTaskService::class)->create(['title' => 'Errored', 'context' => ['user_agent' => 'X', 'console_errors' => [['type' => 'error', 'message' => 'boom']]]]);
    $trace = app(DispatchTaskService::class)->create(['title' => 'Traced', 'context' => ['trace' => ['app/x.php:1 f()']]]);

    expect($healthy->hasDiagnostics())->toBeFalse()
        ->and($broken->hasDiagnostics())->toBeTrue()
        ->and($trace->hasDiagnostics())->toBeTrue()
        // A host's browser-error capture (no console_errors key at all) is evidence too.
        ->and(app(DispatchTaskService::class)->create(['title' => 'Client error', 'context' => ['user_agent' => 'X', 'error_type' => 'TypeError', 'stack' => "at a (app.js:1)\nat b (app.js:2)"]])->hasDiagnostics())->toBeTrue()
        ->and(ContextPresenter::diagnostics(['error_type' => 'TypeError', 'stack' => "at a (app.js:1)\n\nat b (app.js:2)", 'component_path' => 'Inertia > X'])['client_error'])
        ->toBe(['error_type' => 'TypeError', 'component' => 'Inertia > X', 'stack' => ['at a (app.js:1)', 'at b (app.js:2)']])
        ->and(ContextPresenter::diagnostics($healthy->context)['has_evidence'])->toBeFalse()
        ->and(ContextPresenter::diagnostics($broken->context)['has_evidence'])->toBeTrue();
});

test('closeOut shapes context.result; a metrics-only result or none at all is null', function () {
    $out = ContextPresenter::closeOut(closeOutContext());

    expect($out['summary'])->toBe('Rebuilt the plans search.')
        ->and($out['resolution'])->toBe('built')
        ->and($out['commit'])->toBe('14397cfb')
        ->and($out['owner_check'])->toBe(['Open /db/standing_orders and try the presets.', 'In /chat: show expiring plans.'])
        ->and($out['follow_ups_not_built'])->toBe(['a GET for the global-actions list'])
        ->and($out)->not->toHaveKey('metrics')
        ->and(ContextPresenter::closeOut(['result' => ['owner_check' => 'One step, as a string']])['owner_check'])->toBe(['One step, as a string'])
        ->and(ContextPresenter::closeOut(['result' => ['metrics' => ['x' => 1]]]))->toBeNull()
        ->and(ContextPresenter::closeOut(['user_agent' => 'X']))->toBeNull()
        ->and(ContextPresenter::closeOut(null))->toBeNull();
});

test('diagnostics carries the exception, trace and occurrences; a context-free task has none', function () {
    $d = ContextPresenter::diagnostics([
        'exception' => ['class' => 'RuntimeException', 'message' => 'Boom', 'file' => '/app/x.php', 'line' => 12, 'app_frame' => 'app/x.php:12'],
        'trace' => ['app/x.php:12 X->y()', 'app/z.php:3 Z->w()'],
        'times_seen' => 4,
        'url' => 'https://example.test/a',
    ]);

    expect($d['exception'])->toBe(['class' => 'RuntimeException', 'message' => 'Boom', 'file' => '/app/x.php', 'line' => '12', 'app_frame' => 'app/x.php:12'])
        ->and($d['trace'])->toHaveCount(2)
        ->and($d['occurrences']['times_seen'])->toBe(4)
        ->and($d['capture']['url'])->toBe('https://example.test/a')
        ->and(ContextPresenter::diagnostics(['ticket' => ['id' => 1]]))->toBeNull();
});

test('the task page shows the close-out and the exception', function () {
    $this->actingAs(dispatchMakeUser(2299));
    $task = app(DispatchTaskService::class)->create(['title' => 'Closed by an agent', 'context' => closeOutContext() + [
        'exception' => ['class' => 'RuntimeException', 'message' => 'Kaboom'],
        'trace' => ['app/x.php:12 X->y()'],
    ]]);

    Livewire::test(TaskShow::class, ['task' => $task])
        ->assertOk()
        ->assertSee('data-dispatch-close-out', false)
        ->assertSee('Agent close-out')
        ->assertSee('Open /db/standing_orders and try the presets.')
        ->assertSee('a GET for the global-actions list')
        ->assertSee('14397cfb')
        ->assertSee('data-dispatch-exception', false)
        ->assertSee('Kaboom')
        ->assertSee('app/x.php:12 X-&gt;y()', false);
});
