<?php

use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * Exercises the F7 `dispatch:edit` and F5 `dispatch:merge` CLI verbs.
 */

test('dispatch:edit memorializes the old description before applying the new one', function () {
    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'Editable task', 'description' => 'Old body']);

    $this->artisan('dispatch:edit', [
        'code' => $task->code,
        '--description' => 'New body',
    ])->assertOk();

    $fresh = $task->fresh();
    expect($fresh->description)->toBe('New body');

    $memorial = $fresh->comments()->where('event_type', TaskComment::EVENT_DESCRIPTION_EDITED)->first();
    expect($memorial)->not->toBeNull();
    expect($memorial->body)->toBe('Old body');
    expect($memorial->is_internal)->toBeTrue();
    expect($memorial->meta)->toBe(['source' => 'cli']);
});

test('dispatch:edit leaves the description (and its history) untouched when --description is omitted', function () {
    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'Untouched description', 'description' => 'Stays the same']);

    $this->artisan('dispatch:edit', [
        'code' => $task->code,
        '--title' => 'Renamed task',
        '--due' => '2026-08-01',
    ])->assertOk();

    $fresh = $task->fresh();
    expect($fresh->title)->toBe('Renamed task');
    expect($fresh->description)->toBe('Stays the same');
    expect($fresh->due_at?->toDateString())->toBe('2026-08-01');
    expect($fresh->comments()->where('event_type', TaskComment::EVENT_DESCRIPTION_EDITED)->exists())->toBeFalse();
});

test('dispatch:edit clears the due date when --due is passed an empty string', function () {
    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'Has a due date', 'due_at' => now()->addWeek()]);

    $this->artisan('dispatch:edit', [
        'code' => $task->code,
        '--due' => '',
    ])->assertOk();

    expect($task->fresh()->due_at)->toBeNull();
});

test('dispatch:edit fails clearly for an unknown code', function () {
    $this->artisan('dispatch:edit', [
        'code' => 'TASK-DOES-NOT-EXIST',
        '--title' => 'Nope',
    ])->assertFailed();
});

test('dispatch:merge folds a loser task into a winner task', function () {
    $service = app(DispatchTaskService::class);
    $winner = $service->create(['title' => 'Winner task']);
    $loser = $service->create(['title' => 'Loser task']);

    $comment = $loser->comments()->create([
        'user_id' => null,
        'body' => 'Duplicate report',
        'event_type' => TaskComment::EVENT_COMMENT,
    ]);

    $this->artisan('dispatch:merge', [
        'loser' => $loser->code,
        'winner' => $winner->code,
    ])->assertOk();

    expect($comment->fresh()->task_id)->toBe($winner->id);

    $freshLoser = Task::withTrashed()->find($loser->id);
    expect($freshLoser->trashed())->toBeTrue();
    expect($freshLoser->duplicate_of)->toBe($winner->id);
    expect($freshLoser->status)->toBe('declined');

    $freshWinner = $winner->fresh();
    expect($freshWinner->comments()->where('event_type', TaskComment::EVENT_MERGED)->exists())->toBeTrue();
});

test('dispatch:merge refuses to merge a task into itself', function () {
    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'Solo task']);

    $this->artisan('dispatch:merge', [
        'loser' => $task->code,
        'winner' => $task->code,
    ])->assertFailed();
});

test('dispatch:merge fails clearly when a code does not exist', function () {
    $service = app(DispatchTaskService::class);
    $winner = $service->create(['title' => 'Real winner']);

    $this->artisan('dispatch:merge', [
        'loser' => 'TASK-DOES-NOT-EXIST',
        'winner' => $winner->code,
    ])->assertFailed();
});

/*
 * --description-file / --local — the W11 pass.
 *
 * The file/stdin escape hatch reached done/note/add in the v0.5.4 wave and
 * missed `edit`, the surface that writes the LONGEST text of any of them; the
 * local-only guard closes the silent wrong-DB write that the same command's
 * missing session plumbing left open.
 */

test('dispatch:edit --description-file reads the new body from a file', function () {
    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'Long body', 'description' => 'Old body']);

    // Deliberately the shape that drove the finding: multi-line markdown with
    // the quoting characters that make an inline flag a hazard.
    $body = "## Findings\n\n- a `--flag` with \"quotes\"\n- a line with 'single' quotes\n\nDone.";
    $path = sys_get_temp_dir().'/dispatch-edit-body-'.uniqid().'.md';
    file_put_contents($path, $body);

    $this->artisan('dispatch:edit', [
        'code' => $task->code,
        '--description-file' => $path,
    ])->assertOk();

    @unlink($path);

    expect($task->fresh()->description)->toBe($body);

    // The memorial still fires — the file path is an input channel, not a
    // different write.
    $memorial = $task->fresh()->comments()->where('event_type', TaskComment::EVENT_DESCRIPTION_EDITED)->first();
    expect($memorial->body)->toBe('Old body');
});

test('dispatch:edit rejects --description and --description-file together', function () {
    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'Two sources', 'description' => 'Untouched']);

    $this->artisan('dispatch:edit', [
        'code' => $task->code,
        '--description' => 'inline',
        '--description-file' => 'whatever.md',
    ])->assertFailed();

    expect($task->fresh()->description)->toBe('Untouched');
});

test('dispatch:edit fails on a missing --description-file without touching the task', function () {
    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'Missing file', 'description' => 'Untouched']);

    $this->artisan('dispatch:edit', [
        'code' => $task->code,
        '--title' => 'Should not apply',
        '--description-file' => 'no-such-file-'.uniqid().'.md',
    ])->assertFailed();

    // Resolution happens before the lookup and the save, so a bad path is a
    // no-op rather than a half-applied edit.
    expect($task->fresh()->title)->toBe('Missing file')
        ->and($task->fresh()->description)->toBe('Untouched');
});

/*
 * The local-only guard. These are the ONLY tests here that configure a remote:
 * with no `agent.remote.url` the guard is inert, which is exactly the ordinary
 * local posture every test above runs in.
 */

function dispatchConfigureRemote(): string
{
    $tokenPath = sys_get_temp_dir().'/dispatch-localonly-'.uniqid().'.json';
    config([
        'dispatch.agent.remote.url' => 'https://agent.example.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => $tokenPath,
    ]);

    return $tokenPath;
}

test('dispatch:edit refuses to write locally while an agent session is active', function () {
    $tokenPath = dispatchConfigureRemote();
    seedAgentToken();

    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'Local task', 'description' => 'Untouched']);

    $this->artisan('dispatch:edit', [
        'code' => $task->code,
        '--description' => 'Would have hit the WRONG database',
    ])
        ->expectsOutputToContain('LOCAL-ONLY')
        ->expectsOutputToContain('dispatch:batch')
        ->assertFailed();

    @unlink($tokenPath);

    // The masquerade the guard exists to prevent: the code the agent typed named
    // a production task, and silently landing it here would have rewritten this
    // unrelated local one — and memorialized the wrong prior body doing it.
    expect($task->fresh()->description)->toBe('Untouched')
        ->and($task->fresh()->comments()->where('event_type', TaskComment::EVENT_DESCRIPTION_EDITED)->exists())->toBeFalse();
});

test('dispatch:edit --local is the explicit override and still writes', function () {
    $tokenPath = dispatchConfigureRemote();
    seedAgentToken();

    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'On purpose', 'description' => 'Old body']);

    $this->artisan('dispatch:edit', [
        'code' => $task->code,
        '--description' => 'New body',
        '--local' => true,
    ])->assertOk();

    @unlink($tokenPath);

    expect($task->fresh()->description)->toBe('New body');
});

test('dispatch:edit refuses after a DROPPED session too, naming the drop', function () {
    $tokenPath = dispatchConfigureRemote();
    file_put_contents($tokenPath.'.dropped', json_encode([
        'reason' => 'revoked or expired (agent API returned 401)',
        'at' => '2026-08-01T00:00:00Z',
    ]));

    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'After a drop', 'description' => 'Untouched']);

    $this->artisan('dispatch:edit', [
        'code' => $task->code,
        '--description' => 'Nope',
    ])
        ->expectsOutputToContain('was dropped')
        ->assertFailed();

    @unlink($tokenPath.'.dropped');

    expect($task->fresh()->description)->toBe('Untouched');
});

test('the guard stands down when the host has opted out of sticky remote', function () {
    $tokenPath = dispatchConfigureRemote();
    config(['dispatch.agent.remote.sticky' => false]);
    seedAgentToken();

    $service = app(DispatchTaskService::class);
    $task = $service->create(['title' => 'Sticky off', 'description' => 'Old body']);

    // sticky=false is the host ruling that a live token does NOT retarget a bare
    // verb — local IS the expected target, so there is no mismatch to report.
    $this->artisan('dispatch:edit', [
        'code' => $task->code,
        '--description' => 'New body',
    ])->assertOk();

    @unlink($tokenPath);

    expect($task->fresh()->description)->toBe('New body');
});

test('dispatch:merge refuses mid-session and soft-deletes nothing', function () {
    $tokenPath = dispatchConfigureRemote();
    seedAgentToken();

    $service = app(DispatchTaskService::class);
    $winner = $service->create(['title' => 'Local winner']);
    $loser = $service->create(['title' => 'Local loser']);

    $this->artisan('dispatch:merge', [
        'loser' => $loser->code,
        'winner' => $winner->code,
    ])
        ->expectsOutputToContain('LOCAL-ONLY')
        ->expectsOutputToContain('no remote merge verb exists')
        ->assertFailed();

    @unlink($tokenPath);

    // Merge is destructive — the refusal has to land before the soft-delete,
    // not after it.
    expect($loser->fresh()->trashed())->toBeFalse()
        ->and($winner->fresh()->comments()->where('event_type', TaskComment::EVENT_MERGED)->exists())->toBeFalse();
});
