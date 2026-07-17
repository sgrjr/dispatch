<?php

use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\TaskPresenter;

/*
 * C5 — the frozen Task -> machine JSON contract. Every --json verb + the remote
 * API parse this shape; these tests pin the keys.
 */

// The presenter reads the submitter/assignee/comment-author relations, which
// resolve config('dispatch.models.user'). Bind the fixture user model (Testbench
// has no App\Models\User) so building those relations doesn't blow up.
beforeEach(fn () => dispatchFakeUsers());

test('the summary shape carries exactly the documented keys', function () {
    $task = app(DispatchTaskService::class)->create(
        ['title' => 'Broken', 'type' => 'bug', 'priority' => 'high', 'status' => 'open'],
        ['area:api', 'urgent'],
    );

    $data = TaskPresenter::toArray($task);

    expect(array_keys($data))->toEqual([
        'code', 'title', 'type', 'priority', 'status', 'is_public',
        'labels', 'comment_count', 'due_at', 'dedupe_key', 'submitter', 'assignee',
        'created_at', 'updated_at',
    ]);

    expect($data['code'])->toBe($task->code)
        ->and($data['type'])->toBe('bug')
        ->and($data['priority'])->toBe('high')
        ->and($data['is_public'])->toBeFalse()
        ->and($data['labels'])->toEqualCanonicalizing(['area:api', 'urgent'])
        ->and($data['comment_count'])->toBe(0)
        ->and($data['dedupe_key'])->toBeNull()
        ->and($data)->not->toHaveKey('description');
});

test('comment_count counts human comments only, not system events (GAP 2c)', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 'has direction', 'status' => 'open']);

    // System timeline events — must NOT count toward "direction".
    $task->recordEvent(TaskComment::EVENT_STATUS_CHANGE, null, ['from' => 'triage', 'to' => 'open']);
    $task->recordEvent(TaskComment::EVENT_CLAIMED, null, ['agent_name' => 'x']);
    // Two human comments — the actual direction.
    $task->recordEvent(TaskComment::EVENT_COMMENT, null, [], 'do X first');
    $task->recordEvent(TaskComment::EVENT_COMMENT, null, [], 'not Y');

    // Path 1 — fallback COUNT query (relation not loaded, no withCount).
    expect(TaskPresenter::toArray($task->fresh())['comment_count'])->toBe(2);

    // Path 2 — eager ->withCount (the summary/collection query sites).
    $eager = config('dispatch.models.task')::query()
        ->withCount(['comments as comment_count' => fn ($q) => $q->where('event_type', TaskComment::EVENT_COMMENT)])
        ->whereKey($task->id)->first();
    expect(TaskPresenter::toArray($eager)['comment_count'])->toBe(2);

    // Path 3 — full shape, comments relation already loaded (counted in memory).
    $full = $task->fresh()->load('comments.user');
    expect(TaskPresenter::toArray($full, full: true)['comment_count'])->toBe(2);
});

test('the full shape adds description, context and comments', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 'Detailed', 'description' => 'the body']);
    $svc->recordResult($task, ['tests' => 'green'], 'sha1');
    $task->recordEvent(TaskComment::EVENT_COMMENT, null, [], 'a note');

    $data = TaskPresenter::toArray($task->fresh()->load(['labels', 'submitter', 'assignee', 'comments.user']), full: true);

    expect($data['description'])->toBe('the body')
        ->and($data['context']['result']['tests'])->toBe('green')
        ->and($data['comments'])->toBeArray()->toHaveCount(1);

    $comment = $data['comments'][0];
    expect(array_keys($comment))->toEqual(['id', 'event_type', 'is_internal', 'author', 'body', 'meta', 'created_at'])
        ->and($comment['body'])->toBe('a note');
});

test('submitter resolves to the related user email when present', function () {
    $user = dispatchMakeUser(7, ['email' => 'dev@example.test']);
    $task = app(DispatchTaskService::class)->create(['title' => 'mine', 'submitter_user_id' => $user->id]);

    expect(TaskPresenter::toArray($task->fresh())['submitter'])->toBe('dev@example.test');
});

test('collection maps a list of tasks to summaries', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'one']);
    $svc->create(['title' => 'two']);

    $out = TaskPresenter::collection(config('dispatch.models.task')::query()->with('labels')->get());

    expect($out)->toHaveCount(2)
        ->and($out[0])->toHaveKey('code')
        ->and($out[0])->not->toHaveKey('description');
});

test('schema() documents the shape and the claimed event type', function () {
    $schema = TaskPresenter::schema();

    expect($schema)->toHaveKeys(['summary', 'full_adds', 'batch', 'import', 'event_types'])
        ->and($schema['summary'])->toHaveKeys(['code', 'status', 'dedupe_key'])
        ->and($schema['event_types'])->toContain(TaskComment::EVENT_CLAIMED);
});

test('schema() documents the import (backfill-with-history) shape', function () {
    $import = TaskPresenter::schema()['import'];

    expect($import)->toHaveKeys(['request', 'task', 'label', 'semantics'])
        ->and($import['request'])->toHaveKeys(['tasks', 'labels'])
        // the codeless-migration handle (M1) and the backdating keys are the
        // reason import exists as a distinct path from batch — assert they're documented.
        ->and($import['task'])->toHaveKeys(['code', 'key', 'title', 'status', 'comments', 'createdAt', 'updatedAt'])
        ->and($import['label'])->toHaveKeys(['name', 'color', 'description']);
});
