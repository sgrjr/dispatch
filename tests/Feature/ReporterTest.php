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

/*
|--------------------------------------------------------------------------
| Exception task BODIES
|--------------------------------------------------------------------------
|
| An auto-filed exception task used to carry a title and nothing else — and
| titleFor() truncates at 120 chars, so the one thing identifying the bug was
| clipped while the full message and stack sat unread in the context JSON.
*/

/** Pull the frame lines out of the fenced Stack block in a task body. */
function reporterStackLines(?string $description): array
{
    if ($description === null || ! preg_match('/\*\*Stack\*\*[^\n]*\n\n```\n(.*?)\n```/s', $description, $m)) {
        return [];
    }

    return explode("\n", $m[1]);
}

/** Reach the protected path/trace helpers without going through a task write. */
function reporterManager(): Sgrjr\Dispatch\DispatchManager
{
    return new class(app(Sgrjr\Dispatch\Contracts\SubmitterResolver::class)) extends Sgrjr\Dispatch\DispatchManager
    {
        public function relativePathPublic(string $path): string
        {
            return $this->relativePath($path);
        }

        public function traceLinesPublic(Throwable $e, int $limit): array
        {
            return $this->traceLines($e, $limit);
        }
    };
}

test('fromException writes a body carrying the FULL message the title truncates', function () {
    config(['dispatch.reporter.throttle_seconds' => 0]);

    // No trailing whitespace — the body trims the message, as it should.
    $message = 'Argument #1 ($array) must be of type array, string given, '
        .trim(str_repeat('and here is more detail that the title has no room for, ', 5), ' ,');

    $task = DispatchTask::fromException(new RuntimeException($message));

    // The title is still the short, clipped form...
    expect(mb_strlen($task->title))->toBeLessThan(mb_strlen($message));
    // ...and the body carries the message in full.
    expect($task->description)->toContain($message)
        ->and($task->description)->toContain('RuntimeException');
});

test('the body carries a trimmed stack, capped by description_frames', function () {
    config(['dispatch.reporter.throttle_seconds' => 0, 'dispatch.reporter.description_frames' => 4]);

    $task = DispatchTask::fromException(new RuntimeException('bounded'));
    $lines = reporterStackLines($task->description);

    expect($lines)->not->toBeEmpty()
        ->and(count($lines))->toBeLessThanOrEqual(4)
        ->and($task->description)->toContain('marks application code');
});

test('description_frames = 0 keeps the message and drops the stack entirely', function () {
    config(['dispatch.reporter.throttle_seconds' => 0, 'dispatch.reporter.description_frames' => 0]);

    $task = DispatchTask::fromException(new RuntimeException('message only please'));

    expect($task->description)->toContain('message only please')
        ->and($task->description)->not->toContain('**Stack**');
});

test('a runaway message is capped by description_message_chars', function () {
    config([
        'dispatch.reporter.throttle_seconds' => 0,
        'dispatch.reporter.description_message_chars' => 50,
    ]);

    $task = DispatchTask::fromException(new RuntimeException(str_repeat('x', 5000)));

    // The cap applies to the message block specifically — the stack below it is
    // bounded separately, by frame count.
    expect($task->description)->not->toContain(str_repeat('x', 200))
        ->and($task->description)->toContain('...');
});

test('a wrapped exception surfaces its real cause', function () {
    config(['dispatch.reporter.throttle_seconds' => 0]);

    $task = DispatchTask::fromException(
        new RuntimeException('Query failed', 0, new LogicException('SQLSTATE[22001] data too long'))
    );

    expect($task->description)->toContain('Caused by')
        ->and($task->description)->toContain('LogicException')
        ->and($task->description)->toContain('SQLSTATE[22001] data too long');
});

test('a caller-supplied description is never overwritten', function () {
    config(['dispatch.reporter.throttle_seconds' => 0]);

    $task = DispatchTask::fromException(new RuntimeException('whatever'), [
        'description' => 'The caller already explained this one.',
    ]);

    expect($task->description)->toBe('The caller already explained this one.');
});

test('a recurrence never rewrites a body a human has edited', function () {
    config(['dispatch.reporter.throttle_seconds' => 0]);

    $e = new RuntimeException('recurring');
    $first = DispatchTask::fromException($e);
    $first->update(['description' => 'Triaged by hand — do not clobber.']);

    $second = DispatchTask::fromException($e);

    expect($second->id)->toBe($first->id)
        ->and($second->description)->toBe('Triaged by hand — do not clobber.')
        ->and($second->context['times_seen'])->toBe(2);
});

test('stack paths are relativized to the app root and vendor frames are unmarked', function () {
    $manager = reporterManager();

    expect($manager->relativePathPublic(base_path('app/Exports/ExperienceExport.php')))
        ->toBe('app/Exports/ExperienceExport.php')
        ->and($manager->relativePathPublic(base_path('vendor/maatwebsite/excel/src/Sheet.php')))
        ->toBe('vendor/maatwebsite/excel/src/Sheet.php')
        // Outside the app root: left alone rather than mangled.
        ->and($manager->relativePathPublic('/somewhere/else/foo.php'))->toBe('/somewhere/else/foo.php')
        ->and($manager->relativePathPublic(''))->toBe('');

    // Application frames get the marker; vendor frames deliberately do not.
    $lines = $manager->traceLinesPublic(new RuntimeException('marked'), 30);
    expect($lines)->not->toBeEmpty();
    foreach ($lines as $line) {
        if (str_contains($line, ' vendor/')) {
            expect($line)->toStartWith('  ');
        }
    }
});

test('the stack header says plainly whether frames were dropped', function () {
    config(['dispatch.reporter.throttle_seconds' => 0, 'dispatch.reporter.description_frames' => 3]);
    $clipped = DispatchTask::fromException(new RuntimeException('clipped'));

    config(['dispatch.reporter.description_frames' => 500]);
    $whole = DispatchTask::fromException(new LogicException('whole'));

    expect($clipped->description)->toContain('first 3 of ')
        ->and($whole->description)->not->toContain('first ');
});

test('report() stamps the topic, origin and conversation anchors it is given', function () {
    config(['dispatch.reporter.throttle_seconds' => 0]);

    $task = DispatchTask::report('Customer reported a problem', [
        'topic' => 'account:0402100000001',
        'origin' => 'contact_form:77',
        'conversation' => 12,
    ]);

    expect($task->topic_type)->toBe('account');
    expect($task->topic_id)->toBe('0402100000001');
    expect($task->origin_type)->toBe('contact_form');
    expect($task->origin_id)->toBe('77');
    expect($task->conversation_id)->toBe(12);
});

test('a malformed anchor is dropped, never the report', function () {
    config(['dispatch.reporter.throttle_seconds' => 0]);

    $task = DispatchTask::report('Still filed', ['topic' => 'Not A Type:1', 'origin' => 'phone']);

    expect($task)->toBeInstanceOf(Task::class);
    expect($task->topic_type)->toBeNull();
    expect($task->origin_type)->toBe('phone');
    expect($task->origin_id)->toBeNull();
});

test('fromException() records the exception as the origin unless the caller names another', function () {
    config(['dispatch.reporter.throttle_seconds' => 0]);

    $task = DispatchTask::fromException(new RuntimeException('origin default '.uniqid()));
    expect($task->origin_type)->toBe('exception');
    expect($task->origin_id)->toBeNull();

    $named = DispatchTask::fromException(new RuntimeException('origin named '.uniqid()), ['origin' => 'contact_form:9']);
    expect($named->origin_type)->toBe('contact_form');
    expect($named->origin_id)->toBe('9');
});
