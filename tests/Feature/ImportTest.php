<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/*
 * dispatch:import — upserts a JSON-LD snapshot (dispatch:export's shape) by
 * `code`, OR, for a codeless md migration, by a stable import `key` persisted
 * as `dedupe_key` so a re-import upserts instead of duplicating (M1). Also
 * guards the update path's title truncation (M1) so a long-titled re-import
 * can't overflow the column the way create() already prevents.
 *
 * The command resolves its path under base_path(), so fixtures are written
 * there (not sys_get_temp_dir()) and cleaned up afterEach.
 */

/** Write an import doc under base_path() and return its app-relative filename. */
function importDoc(array $doc): string
{
    $name = 'dispatch-import-test-'.uniqid().'.json';
    file_put_contents(base_path($name), json_encode($doc));

    return $name;
}

afterEach(function () {
    foreach (glob(base_path('dispatch-import-test-*.json')) ?: [] as $f) {
        @unlink($f);
    }
});

// --- baseline: the existing code path still works --------------------------

test('imports a task by code and re-imports update in place (no duplicate)', function () {
    $path = importDoc(['tasks' => [
        ['code' => 'TASK-900', 'title' => 'First', 'type' => 'bug', 'status' => 'open'],
    ]]);

    expect(Artisan::call('dispatch:import', ['path' => $path]))->toBe(0);
    expect(Task::where('code', 'TASK-900')->count())->toBe(1)
        ->and(Task::where('code', 'TASK-900')->value('title'))->toBe('First');

    // Re-import the same code with a changed title → updates, never duplicates.
    $path2 = importDoc(['tasks' => [
        ['code' => 'TASK-900', 'title' => 'First (edited)', 'type' => 'bug', 'status' => 'open'],
    ]]);
    expect(Artisan::call('dispatch:import', ['path' => $path2]))->toBe(0);
    expect(Task::where('code', 'TASK-900')->count())->toBe(1)
        ->and(Task::where('code', 'TASK-900')->value('title'))->toBe('First (edited)');
});

// --- M1: codeless keyed upsert ---------------------------------------------

test('a codeless row is created with a minted code and its import key persisted', function () {
    $path = importDoc(['tasks' => [
        ['key' => 'sha-abc', 'title' => 'Migrated from todo.md', 'type' => 'chore', 'status' => 'done'],
    ]]);

    expect(Artisan::call('dispatch:import', ['path' => $path]))->toBe(0);

    $task = Task::where('dedupe_key', 'sha-abc')->first();
    expect($task)->not->toBeNull()
        ->and($task->code)->not->toBeNull()          // code was minted
        ->and($task->code)->toStartWith('TASK-')
        ->and($task->title)->toBe('Migrated from todo.md')
        ->and($task->status)->toBe('done');          // history fidelity preserved
});

test('re-importing the same codeless key upserts instead of duplicating', function () {
    $doc = ['tasks' => [
        ['key' => 'sha-dup', 'title' => 'Once', 'status' => 'open'],
    ]];
    expect(Artisan::call('dispatch:import', ['path' => importDoc($doc)]))->toBe(0);

    $mintedCode = Task::where('dedupe_key', 'sha-dup')->value('code');

    // Second run with the same key + a new title → updates the same row.
    $doc['tasks'][0]['title'] = 'Twice';
    expect(Artisan::call('dispatch:import', ['path' => importDoc($doc)]))->toBe(0);

    expect(Task::where('dedupe_key', 'sha-dup')->count())->toBe(1)
        ->and(Task::where('dedupe_key', 'sha-dup')->value('title'))->toBe('Twice')
        ->and(Task::where('dedupe_key', 'sha-dup')->value('code'))->toBe($mintedCode); // code stable
});

test('the dedupeKey alias is accepted as the import key', function () {
    $path = importDoc(['tasks' => [
        ['dedupeKey' => 'sha-alias', 'title' => 'Alias key'],
    ]]);

    expect(Artisan::call('dispatch:import', ['path' => $path]))->toBe(0);
    expect(Task::where('dedupe_key', 'sha-alias')->exists())->toBeTrue();
});

test('a row with neither code nor key is skipped and counted', function () {
    $path = importDoc(['tasks' => [
        ['title' => 'No identity — skipped'],
        ['key' => 'sha-kept', 'title' => 'Kept'],
    ]]);

    expect(Artisan::call('dispatch:import', ['path' => $path]))->toBe(0);
    expect(Task::where('dedupe_key', 'sha-kept')->exists())->toBeTrue()
        ->and(Task::where('title', 'No identity — skipped')->exists())->toBeFalse()
        ->and(Artisan::output())->toContain('tasks_skipped: 1');
});

// --- M1: title truncation on the update path -------------------------------

test('a long title is truncated on the update path (matching create())', function () {
    $long = str_repeat('x', 400);

    // Create it first (short title), then re-import with an overlong title so
    // the UPDATE branch (fill(), which bypasses the service) handles truncation.
    expect(Artisan::call('dispatch:import', ['path' => importDoc(['tasks' => [
        ['code' => 'TASK-950', 'title' => 'short'],
    ]])]))->toBe(0);

    expect(Artisan::call('dispatch:import', ['path' => importDoc(['tasks' => [
        ['code' => 'TASK-950', 'title' => $long],
    ]])]))->toBe(0);

    $stored = Task::where('code', 'TASK-950')->value('title');
    expect($stored)->toBe(Str::limit($long, 255, '…'))   // update path truncated
        ->and(mb_strlen($stored))->toBeLessThanOrEqual(256);
});

// --- history fidelity on the keyed create path -----------------------------

test('a codeless done row preserves backdated timestamps and comments', function () {
    $path = importDoc(['tasks' => [[
        'key' => 'sha-hist',
        'title' => 'Historical item',
        'status' => 'done',
        'createdAt' => '2024-01-02T03:04:05+00:00',
        'comments' => [
            ['body' => 'Completed in commit abc123', 'eventType' => TaskComment::EVENT_STATUS_CHANGE, 'createdAt' => '2024-02-03T04:05:06+00:00'],
        ],
    ]]]);

    expect(Artisan::call('dispatch:import', ['path' => $path]))->toBe(0);

    $task = Task::where('dedupe_key', 'sha-hist')->firstOrFail();
    expect($task->created_at->toDateString())->toBe('2024-01-02')
        ->and($task->comments()->where('event_type', TaskComment::EVENT_STATUS_CHANGE)->value('body'))
        ->toBe('Completed in commit abc123');
});
