<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
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

/*
 * Comment `body` shape + size. Both of these used to escape validate() and blow
 * up deeper in the stack, killing the whole transaction with an error that named
 * neither the operation nor the field.
 */
test('a structured (array) comment body is rejected by name, not an Array-to-string crash', function () {
    // Previously hit `(string) $array` in validate() — a PHP warning that
    // Laravel's handler promotes to ErrorException, so the batch died with a
    // bare "Array to string conversion".
    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'structured body', 'comments' => [['body' => ['vetted' => true, 'note' => 'x']]]],
    ]))->toThrow(InvalidArgumentException::class, 'must be a string');

    expect(Task::count())->toBe(0);
});

test('an oversized comment body is rejected up front rather than as a raw SQLSTATE', function () {
    // Previously reached the INSERT and failed with
    // SQLSTATE[22001] "Data too long for column 'body'", rolling back every
    // other operation in the manifest.
    $huge = str_repeat('y', DispatchBatchService::MAX_COMMENT_BODY_BYTES + 1);

    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'huge body', 'comments' => [['body' => $huge]]],
    ]))->toThrow(InvalidArgumentException::class, 'over the');

    expect(Task::count())->toBe(0);
});

test('a long-but-legal comment body persists intact — the column is longText now', function () {
    // 100k bytes: over the old `text` ceiling of 65,535, under the guard. This
    // is the case the widening exists for — agent result payloads and file
    // listings that must not be truncated.
    $long = str_repeat('a', 100000);

    $out = app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'long note', 'comments' => [['body' => $long]]],
    ]);

    expect($out['summary']['comments_added'])->toBe(1);

    $task = Task::where('title', 'long note')->firstOrFail();
    expect(strlen($task->comments()->where('event_type', TaskComment::EVENT_COMMENT)->firstOrFail()->body))->toBe(100000);
});

test('a null or blank comment body is still rejected', function () {
    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'blank body', 'comments' => [['body' => '   ']]],
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'null body', 'comments' => [[]]],
    ]))->toThrow(InvalidArgumentException::class);
});

// --- due dates: the tri-state (§18 W10) ------------------------------------

/*
 * `due_at` is TRI-state on every op: the key ABSENT leaves the stored date
 * alone, `null`/`""` clears it, anything else must parse. Before this wave a
 * `due_at` key passed validation and was silently dropped — the batch reported
 * success and the date never landed.
 */

test('an add op sets the due date on the new task — creation, so no timeline event', function () {
    $out = app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'due next month', 'due_at' => '2026-08-15'],
    ]);

    $task = Task::where('code', $out['results'][0]['code'])->firstOrFail();

    expect($task->due_at?->toDateString())->toBe('2026-08-15')
        // The date is part of CREATION, not a change — only `update` memorializes.
        ->and($task->comments()->where('event_type', TaskComment::EVENT_COMMENT)->count())->toBe(0);
});

test('an update op sets a due date on an existing task', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'needs a review-by', 'status' => 'open']);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'due_at' => '2026-08-15'],
    ]);

    expect($task->fresh()->due_at?->toDateString())->toBe('2026-08-15');
});

test('an update op clears the due date with null — and "" is the same sentinel', function () {
    $svc = app(DispatchTaskService::class);
    $viaNull = $svc->create(['title' => 'cleared by null', 'status' => 'open', 'due_at' => '2026-08-01']);
    $viaBlank = $svc->create(['title' => 'cleared by blank', 'status' => 'open', 'due_at' => '2026-08-01']);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $viaNull->code, 'due_at' => null],
        ['op' => 'update', 'code' => $viaBlank->code, 'due_at' => ''],
    ]);

    expect($viaNull->fresh()->due_at)->toBeNull()
        ->and($viaBlank->fresh()->due_at)->toBeNull();

    // A clear is a change, so it memorializes in the editor's own words.
    $event = $viaNull->fresh()->comments()->where('event_type', TaskComment::EVENT_COMMENT)->firstOrFail();
    expect($event->body)->toBe('Due date cleared.')
        ->and($event->meta['due_at'])->toBe(['from' => '2026-08-01', 'to' => null]);
});

test('an update op that omits due_at leaves the stored date untouched', function () {
    // The trap this pins: `due_at` cannot ride the `!== null` field loop, which
    // is structurally unable to tell "absent" from "clear".
    $task = app(DispatchTaskService::class)->create(['title' => 'keep my date', 'status' => 'open', 'due_at' => '2026-08-01']);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'status' => 'in_progress'],
    ]);

    expect($task->fresh()->due_at?->toDateString())->toBe('2026-08-01')
        ->and($task->fresh()->comments()->where('event_type', TaskComment::EVENT_COMMENT)->count())->toBe(0);
});

test('an unparseable due_at is rejected up front, naming the operation, with nothing persisted', function () {
    // Thrown from validate(), so the transaction never opens — the add before it
    // is not written and then rolled back; it is never attempted.
    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'would be created first'],
        ['op' => 'add', 'title' => 'bad date', 'due_at' => 'not-a-real-date'],
    ]))->toThrow(InvalidArgumentException::class, 'Operation 1: `due_at` could not be parsed as a date: not-a-real-date');

    expect(Task::count())->toBe(0);
});

test('a structured (array) due_at is rejected by name, not an Array-to-string crash', function () {
    // Same class of bug as the structured comment body above: casting the value
    // into the message would raise the PHP warning Laravel promotes to an
    // ErrorException, replacing a legible error with a bare crash.
    expect(fn () => app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'structured date', 'due_at' => ['date' => '2026-08-15']],
    ]))->toThrow(InvalidArgumentException::class, 'Operation 0: `due_at` could not be parsed as a date: array');

    expect(Task::count())->toBe(0);
});

test('a real due-date change is memorialized in the Livewire editor wording (W10-2)', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'move the date', 'status' => 'open', 'due_at' => '2026-08-01']);

    app(DispatchBatchService::class)->apply([
        ['op' => 'update', 'code' => $task->code, 'due_at' => '2026-08-15'],
    ]);

    // Word-for-word what TaskShow records, so a human reading the timeline can't
    // tell (and needn't care) whether the board or an agent moved the date.
    $event = $task->fresh()->comments()->where('event_type', TaskComment::EVENT_COMMENT)->firstOrFail();
    expect($event->body)->toBe('Due date set to 2026-08-15.')
        ->and($event->meta['due_at'])->toBe(['from' => '2026-08-01', 'to' => '2026-08-15']);
});

test('re-submitting the same due date mints no second event (W10-2)', function () {
    $svc = app(DispatchBatchService::class);
    $task = app(DispatchTaskService::class)->create(['title' => 're-run me', 'status' => 'open']);

    $manifest = [['op' => 'update', 'code' => $task->code, 'due_at' => '2026-08-15']];

    $svc->apply($manifest);
    $svc->apply($manifest);

    // Nothing to do with the (event_type|body) comment dedupe — the memorial is
    // a recordEvent, not an appended comment. Idempotence comes from comparing
    // the date grain, exactly like the Livewire editor.
    expect($task->fresh()->comments()->where('event_type', TaskComment::EVENT_COMMENT)->count())->toBe(1)
        ->and($task->fresh()->due_at?->toDateString())->toBe('2026-08-15');
});

test('a keyed idempotent re-add leaves the existing task due_at alone', function () {
    $svc = app(DispatchBatchService::class);

    $svc->apply([['op' => 'add', 'key' => 'batch:due', 'title' => 'once', 'due_at' => '2026-08-15']]);
    $svc->apply([['op' => 'add', 'key' => 'batch:due', 'title' => 'once', 'due_at' => '2026-12-31']]);

    // The existing-task branch folds in only labels/comments/result — fields are
    // never clobbered, and `due_at` is a field like any other.
    expect(Task::where('dedupe_key', 'batch:due')->count())->toBe(1)
        ->and(Task::where('dedupe_key', 'batch:due')->firstOrFail()->due_at?->toDateString())->toBe('2026-08-15');
});

test('dispatch:batch --dry-run with a due_at reports without persisting', function () {
    $path = batchManifest([['op' => 'add', 'title' => 'phantom date', 'due_at' => '2026-08-15']]);

    $exit = Artisan::call('dispatch:batch', ['path' => $path, '--dry-run' => true, '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($decoded['applied'])->toBeFalse()
        ->and($decoded['summary']['tasks_created'])->toBe(1) // counted, then rolled back
        ->and(Task::count())->toBe(0);
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

// --- M2: quiet / --no-notify suppresses the per-add create receipt ----------

/** A DispatchNotifier that only counts taskCreated fan-outs. */
function batchNotifierSpy(): DispatchNotifier
{
    return new class implements DispatchNotifier
    {
        public int $created = 0;

        public function taskCreated(Task $task): void
        {
            $this->created++;
        }

        public function taskStatusChanged(Task $task, string $from, string $to, ?Authenticatable $actor): void {}

        public function taskCommented(Task $task, TaskComment $comment): void {}

        public function taskAssigned(Task $task, ?int $from, ?int $to, ?Authenticatable $actor): void {}
    };
}

test('apply() notifies per add by default but the quiet flag suppresses it', function () {
    $spy = batchNotifierSpy();
    app()->singleton(DispatchNotifier::class, fn () => $spy);

    app(DispatchBatchService::class)->apply([
        ['op' => 'add', 'title' => 'loud one'],
        ['op' => 'add', 'title' => 'loud two'],
    ]);
    expect($spy->created)->toBe(2);

    $spy->created = 0;
    $out = app(DispatchBatchService::class)->apply(
        [['op' => 'add', 'title' => 'quiet one'], ['op' => 'add', 'title' => 'quiet two']],
        [], null, false, true // dryRun=false, quiet=true
    );

    expect($spy->created)->toBe(0)
        ->and($out['summary']['tasks_created'])->toBe(2);
});

test('dispatch:batch --no-notify threads quiet through to the applier', function () {
    $spy = batchNotifierSpy();
    app()->singleton(DispatchNotifier::class, fn () => $spy);

    $path = batchManifest([['op' => 'add', 'title' => 'via cli quiet']]);
    $exit = Artisan::call('dispatch:batch', ['path' => $path, '--no-notify' => true]);

    expect($exit)->toBe(0)
        ->and($spy->created)->toBe(0)
        ->and(Task::where('title', 'via cli quiet')->exists())->toBeTrue();
});
