<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Sgrjr\Dispatch\Facades\DispatchTask;
use Sgrjr\Dispatch\Jobs\CreateDispatchTask;
use Sgrjr\Dispatch\Models\Task;

/**
 * The DispatchTask facade — programmatic reporting, the exception-handler entry
 * point, and the safety/throttle/gating guarantees around it.
 */

test('report() creates a task synchronously and returns it', function () {
    config(['dispatch.reporter.throttle_seconds' => 0]);

    $task = DispatchTask::report('Something looks off', [
        'type' => 'bug',
        'priority' => 'high',
        'description' => 'Details here',
    ]);

    expect($task)->toBeInstanceOf(Task::class);
    expect($task->type)->toBe('bug');
    expect($task->priority)->toBe('high');
    expect($task->status)->toBe('triage');
    expect($task->context)->toBeArray();
    expect($task->context['captured_at'] ?? null)->not->toBeNull();
});

test('bug() and feature() set the type', function () {
    config(['dispatch.reporter.throttle_seconds' => 0]);

    expect(DispatchTask::bug('B')->type)->toBe('bug');
    expect(DispatchTask::feature('F')->type)->toBe('feature');
});

test('fromException derives title, signature, context and dedupes with occurrence counting', function () {
    config(['dispatch.reporter.throttle_seconds' => 0]);

    $e = new RuntimeException('Kaboom in the widget');

    $first = DispatchTask::fromException($e);
    $second = DispatchTask::fromException($e);

    expect($first)->toBeInstanceOf(Task::class);
    expect($first->type)->toBe('bug');
    expect($first->title)->toContain('RuntimeException');
    expect($first->title)->toContain('Kaboom');
    expect($first->labels->pluck('name')->all())->toContain('source:exception');
    expect($first->context['exception']['class'])->toBe(RuntimeException::class);

    // Same throw site -> deduped onto the same task; occurrence counter bumped.
    expect($second->id)->toBe($first->id);
    expect($second->context['times_seen'])->toBe(2);
    expect(Task::count())->toBe(1);
});

test('throttle suppresses a rapid repeat of the same signature', function () {
    config(['dispatch.reporter.throttle_seconds' => 60]);
    Cache::flush();

    $e = new RuntimeException('Storm');

    $first = DispatchTask::fromException($e);
    $second = DispatchTask::fromException($e);

    expect($first)->toBeInstanceOf(Task::class);
    expect($second)->toBeNull();
    expect(Task::count())->toBe(1);
});

test('environment gating returns null when the current env is excluded', function () {
    config(['dispatch.reporter.environments' => ['production']]); // test env is "testing"

    expect(DispatchTask::report('Should not persist'))->toBeNull();
    expect(Task::count())->toBe(0);
});

test('queued mode dispatches the job and returns null', function () {
    config(['dispatch.reporter.queue' => 'reports', 'dispatch.reporter.throttle_seconds' => 0]);
    Bus::fake();

    $result = DispatchTask::report('Async please');

    expect($result)->toBeNull();
    Bus::assertDispatched(CreateDispatchTask::class);
});

test('the reporter never throws — a failure returns null', function () {
    config([
        'dispatch.reporter.throttle_seconds' => 0,
        'dispatch.models.task' => 'This\\Class\\Does\\Not\\Exist',
    ]);

    expect(DispatchTask::report('Boom'))->toBeNull();
});

test('capture_request => false suppresses the auto server/console context', function () {
    config(['dispatch.reporter.throttle_seconds' => 0]);

    $auto = DispatchTask::report('with auto context');
    $suppressed = DispatchTask::report('own context only', [
        'capture_request' => false,
        'context' => ['url' => 'https://spa.test/orders/42'],
    ]);

    // Default run captures the (console) context in the test runner...
    expect($auto->context['source'] ?? null)->toBe('console');
    // ...suppressed run keeps only the caller's context plus captured_at.
    expect($suppressed->context)->not->toHaveKey('source');
    expect($suppressed->context['url'])->toBe('https://spa.test/orders/42');
    expect($suppressed->context)->toHaveKey('captured_at');
});
