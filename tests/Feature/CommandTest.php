<?php

use Illuminate\Support\Facades\Artisan;
use Sgrjr\Dispatch\Models\Task;

/*
 * Exercises the CLI verb-loop commands (WS-Console). These commands are not
 * part of this workstream, but must exist in the tree for the shared
 * Testbench suite to run — the DispatchServiceProvider registers them only
 * if class_exists() (see registerCommands()), so if WS-Console hasn't landed
 * yet these tests will fail with "command not defined" rather than silently
 * skipping. That is the correct, loud failure mode for a missing dependency.
 */

test('dispatch:add creates a task in the local Dispatch DB', function () {
    $this->artisan('dispatch:add', [
        'title' => 'Broken thing',
        '--type' => 'bug',
    ])->assertOk();

    $task = Task::query()->where('title', 'Broken thing')->first();

    expect($task)->not->toBeNull();
    expect($task->type)->toBe('bug');
    expect($task->status)->toBe('triage');
    expect($task->code)->toStartWith(config('dispatch.code_prefix', 'TASK').'-');
});

test('dispatch:next --json surfaces the task dispatch:add just created', function () {
    $this->artisan('dispatch:add', [
        'title' => 'Broken thing',
        '--type' => 'bug',
        '--priority' => 'high',
    ])->assertOk();

    $task = Task::query()->where('title', 'Broken thing')->firstOrFail();

    // Use Artisan::call (not $this->artisan) so Artisan::output() captures the
    // command's buffer — the PendingCommand helper writes to a separate buffer.
    $exitCode = Artisan::call('dispatch:next', ['--json' => true]);
    expect($exitCode)->toBe(0);

    $output = Artisan::output();

    // Robust check regardless of exact JSON key layout: the task's unique
    // code must appear in the machine-readable output.
    expect($output)->toContain($task->code);

    // Stronger check, assuming --json means "valid JSON" and that (per the
    // rupkeep reference DispatchNext this was ported from) the payload keys
    // its fields as `code`/`title` — see report for this assumption.
    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray();
    expect($decoded['code'] ?? null)->toBe($task->code);
    expect($decoded['title'] ?? null)->toBe($task->title);
});
