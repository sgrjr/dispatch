<?php

use Illuminate\Support\Facades\Artisan;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchBatchService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * The batch "memorialize" applier (§20) — DispatchBatchService + the local
 * `dispatch:batch <file>` CLI. Work a run offline, then commit the whole
 * manifest in one transaction: `add` mints new tasks (triage), `update` upserts
 * the WORK on an existing task by code, labels attach additively, comments dedupe.
 *
 * dispatchFakeUsers() runs first so comment/status events can carry an author id.
 */

beforeEach(fn () => dispatchFakeUsers());

/** Write a manifest to a temp file and return its path (unlinked in afterEach). */
function batchManifest(array $operations): string
{
    $path = sys_get_temp_dir().'/dispatch-batch-test-'.uniqid().'.json';
    file_put_contents($path, json_encode(['operations' => $operations]));

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/dispatch-batch-test-*.json') ?: [] as $f) {
        @unlink($f);
    }
});

// --- service: add ----------------------------------------------------------

test('an add op mints a new task in triage with labels and a comment', function () {
    $out = app(DispatchBatchService::class)->apply([
        [
            'op' => 'add',
            'ref' => 'a1',
            'title' => 'Batch-filed bug',
            'type' => 'bug',
            'priority' => 'high',
            'labels' => ['area:api', 'source:agent'],
            'comments' => [['body' => 'spotted while working the queue']],
        ],
    ]);

    expect($out['summary']['tasks_created'])->toBe(1)
        ->and($out['summary']['comments_added'])->toBe(1)
        ->and($out['results'][0]['ref'])->toBe('a1')
        ->and($out['results'][0]['created'])->toBeTrue();

    $task = Task::where('code', $out['results'][0]['code'])->firstOrFail();
    expect($task->status)->toBe('triage')            // never assumes done
        ->and($task->type)->toBe('bug')
        ->and($task->submitter_user_id)->toBeNull()  // agent/CLI task
        ->and($task->labels->pluck('name')->sort()->values()->all())->toBe(['area:api', 'source:agent'])
        ->and($task->comments()->where('event_type', TaskComment::EVENT_COMMENT)->count())->toBe(1);
});

test('an add op honors an explicit non-done status', function () {
    $out = app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'already in flight', 'status' => 'in_progress'],
    ]);

    expect(Task::where('code', $out['results'][0]['code'])->value('status'))->toBe('in_progress');
});

// --- service: update -------------------------------------------------------

test('an update op upserts partial work: sets a non-done status and records the transition', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'in flight', 'status' => 'open']);

    $out = app(DispatchBatchService::class)->apply([
        [
            'op' => 'update',
            'code' => $task->code,
            'status' => 'in_progress',
            'priority' => 'blocker',
            'labels' => ['needs-review'],
            'commit' => 'abc123',
            'comments' => [['body' => 'partial: A done, B remains', 'internal' => true]],
        ],
    ]);

    expect($out['summary']['tasks_updated'])->toBe(1)
        ->and($out['summary']['statuses_changed'])->toBe(1);

    $fresh = $task->fresh();
    expect($fresh->status)->toBe('in_progress')
        ->and($fresh->priority)->toBe('blocker')
        ->and($fresh->context['result']['commit'])->toBe('abc123')
        ->and($fresh->labels->pluck('name')->all())->toContain('needs-review');

    $event = $fresh->comments()->where('event_type', TaskComment::EVENT_STATUS_CHANGE)->firstOrFail();
    expect($event->meta['to'])->toBe('in_progress');

    $note = $fresh->comments()->where('event_type', TaskComment::EVENT_COMMENT)->firstOrFail();
    expect($note->is_internal)->toBeTrue();
});

test('update labels ATTACH additively — existing labels survive', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'keep my labels', 'status' => 'open'], ['keep-me']);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'labels' => ['added-by-batch']],
    ]);

    expect($task->fresh()->labels->pluck('name')->sort()->values()->all())
        ->toBe(['added-by-batch', 'keep-me']);
});

test('an update op that only appends a comment records no status_change', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'no status move', 'status' => 'open']);

    $out = app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'comments' => [['body' => 'just a note']]],
    ]);

    expect($out['summary']['statuses_changed'])->toBe(0)
        ->and($task->fresh()->status)->toBe('open')
        ->and($task->fresh()->comments()->where('event_type', TaskComment::EVENT_STATUS_CHANGE)->count())->toBe(0);
});

// --- atomicity + validation ------------------------------------------------

test('a bad op rolls the whole batch back — nothing persists', function () {
    $out = fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'would be created first'],
        ['op' => 'update', 'code' => 'TASK-DOESNOTEXIST', 'status' => 'done'],
    ]);

    expect($out)->toThrow(InvalidArgumentException::class);
    expect(Task::count())->toBe(0); // the add before the bad op was rolled back
});

test('an invalid status is rejected up front before any write', function () {
    $out = fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'bad status', 'status' => 'not-a-status'],
    ]);

    expect($out)->toThrow(InvalidArgumentException::class);
    expect(Task::count())->toBe(0);
});

test('an add op without a title is rejected', function () {
    expect(fn () => app(DispatchBatchService::class)->apply([['op' => 'add', 'type' => 'bug']]))
        ->toThrow(InvalidArgumentException::class);
});

// --- re-submit safety ------------------------------------------------------

test('re-applying the same manifest is safe: keyed adds dedupe and comments do not double-post', function () {
    $svc = app(DispatchBatchService::class);
    $manifest = [
        ['op' => 'add', 'key' => 'batch:one', 'title' => 'once', 'comments' => [['body' => 'the note']]],
    ];

    $first = $svc->apply($manifest);
    $second = $svc->apply($manifest);

    expect(Task::where('dedupe_key', 'batch:one')->count())->toBe(1)
        ->and($first['results'][0]['created'])->toBeTrue()
        ->and($second['results'][0]['created'])->toBeFalse()
        ->and($second['summary']['comments_added'])->toBe(0); // comment deduped on re-run

    $task = Task::where('dedupe_key', 'batch:one')->firstOrFail();
    expect($task->comments()->where('event_type', TaskComment::EVENT_COMMENT)->count())->toBe(1);
});

// --- op inference ----------------------------------------------------------

test('op is inferred: a `code` means update, its absence means add', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'existing', 'status' => 'open']);

    $out = app(DispatchBatchService::class)->apply([
        ['code' => $task->code, 'status' => 'done'],        // inferred update
        ['title' => 'brand new'],                            // inferred add
    ]);

    expect($out['results'][0]['op'])->toBe('update')
        ->and($out['results'][1]['op'])->toBe('add')
        ->and($task->fresh()->status)->toBe('done');
});

// --- CLI: local + dry-run --------------------------------------------------

test('dispatch:batch applies a manifest file to the local DB', function () {
    $existing = app(DispatchTaskService::class)->create(['title' => 'move me', 'status' => 'open']);

    $path = batchManifest([
        ['op' => 'add', 'title' => 'from the CLI', 'type' => 'chore'],
        ['op' => 'update', 'code' => $existing->code, 'status' => 'verifying'],
    ]);

    $exit = Artisan::call('dispatch:batch', ['path' => $path]);

    expect($exit)->toBe(0)
        ->and($existing->fresh()->status)->toBe('verifying')
        ->and(Task::where('title', 'from the CLI')->where('status', 'triage')->exists())->toBeTrue();
});

test('dispatch:batch --dry-run validates and reports without writing', function () {
    $path = batchManifest([['op' => 'add', 'title' => 'not persisted']]);

    $exit = Artisan::call('dispatch:batch', ['path' => $path, '--dry-run' => true, '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($decoded['dry_run'])->toBeTrue()
        ->and($decoded['applied'])->toBeFalse()
        ->and($decoded['summary']['tasks_created'])->toBe(1) // counted, then rolled back
        ->and(Task::count())->toBe(0);
});

test('dispatch:batch accepts a bare array manifest (no operations wrapper)', function () {
    $path = sys_get_temp_dir().'/dispatch-batch-test-'.uniqid().'.json';
    file_put_contents($path, json_encode([['op' => 'add', 'title' => 'bare array op']]));

    $exit = Artisan::call('dispatch:batch', ['path' => $path]);

    expect($exit)->toBe(0)
        ->and(Task::where('title', 'bare array op')->exists())->toBeTrue();
});

test('dispatch:batch fails cleanly on a missing file', function () {
    $exit = Artisan::call('dispatch:batch', ['path' => 'no-such-manifest.json']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('File not found');
});

test('dispatch:batch surfaces a validation error and writes nothing', function () {
    $path = batchManifest([
        ['op' => 'add', 'title' => 'ok'],
        ['op' => 'update', 'code' => 'TASK-NOPE', 'status' => 'done'],
    ]);

    $exit = Artisan::call('dispatch:batch', ['path' => $path]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('TASK-NOPE')
        ->and(Task::count())->toBe(0);
});
