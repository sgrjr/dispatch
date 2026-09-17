<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Sgrjr\Dispatch\Models\Focus;
use Sgrjr\Dispatch\Models\Label;
use Sgrjr\Dispatch\Models\LabelAlias;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Services\LabelCleanupService;

/**
 * Label cleanup: LabelCleanupService::replace()/retire() and the
 * `dispatch:labels*` commands. Replace folds labels into a canonical one and
 * leaves the old names as aliases that keep resolving; retire drops labels
 * everywhere. Both rewrite focuses and leave one internal event per live task.
 */
function labeledTask(string $title, array $labels): Task
{
    return app(DispatchTaskService::class)->create(['title' => $title], $labels);
}

function labelId(string $name): int
{
    return (int) Label::query()->where('name', $name)->value('id');
}

function taskLabelNames(Task $task): array
{
    return $task->fresh()->labels()->pluck('name')->sort()->values()->all();
}

test('replace into an existing label moves every task onto it, collapses duplicates, and aliases the old name', function () {
    $a = labeledTask('A', ['area:acct']);
    $b = labeledTask('B', ['area:acct', 'area:accounts']);
    $c = labeledTask('C', ['area:accounts']);

    $result = app(LabelCleanupService::class)->replace([labelId('area:acct')], 'area:accounts');

    expect(taskLabelNames($a))->toBe(['area:accounts'])
        ->and(taskLabelNames($b))->toBe(['area:accounts'])
        ->and(taskLabelNames($c))->toBe(['area:accounts'])
        ->and(Label::query()->where('name', 'area:acct')->exists())->toBeFalse()
        ->and(LabelAlias::query()->where('name', 'area:acct')->first()?->label?->name)->toBe('area:accounts');

    expect($result)->toMatchArray([
        'target' => 'area:accounts',
        'target_created' => false,
        'replaced' => ['area:acct'],
        'tasks' => 2,              // A and B changed; C already had only the target
        'already_on_target' => 1,  // B
    ]);
});

test('replace leaves one internal timeline event on each task whose chips changed, and does not bump updated_at', function () {
    $a = labeledTask('A', ['area:acct']);
    $c = labeledTask('C', ['area:accounts']);
    DB::table('dispatch_tasks')->update(['updated_at' => now()->subWeek()]);
    $before = $a->fresh()->updated_at->toDateTimeString();

    app(LabelCleanupService::class)->replace([labelId('area:acct')], 'area:accounts', null);

    $event = $a->fresh()->comments()->where('event_type', TaskComment::EVENT_LABEL_REPLACED)->sole();
    expect($event->is_internal)->toBeTrue()
        ->and($event->meta)->toBe(['from' => ['area:acct'], 'to' => 'area:accounts'])
        ->and($event->body)->toContain('area:acct')
        ->and($a->fresh()->updated_at->toDateTimeString())->toBe($before)
        ->and($c->fresh()->comments()->where('event_type', TaskComment::EVENT_LABEL_REPLACED)->exists())->toBeFalse();
});

test('replace into a NEW name renames the most-used source in place, keeping its attributes', function () {
    labeledTask('A', ['acct']);
    labeledTask('B', ['accounts']);
    labeledTask('C', ['accounts']);
    Label::query()->where('name', 'accounts')->update(['color' => '#123456', 'kind' => Label::KIND_ELEVATED]);
    $keeperId = labelId('accounts');

    $result = app(LabelCleanupService::class)->replace([labelId('acct'), $keeperId], 'area:accounts');

    $target = Label::query()->where('name', 'area:accounts')->sole();
    expect($target->id)->toBe($keeperId)
        ->and($target->color)->toBe('#123456')
        ->and($target->kind)->toBe(Label::KIND_ELEVATED)
        ->and($target->tasks()->count())->toBe(3)
        ->and(Label::query()->count())->toBe(1)
        ->and(LabelAlias::query()->orderBy('name')->pluck('name')->all())->toBe(['accounts', 'acct'])
        ->and($result['target_created'])->toBeTrue()
        ->and($result['tasks'])->toBe(3);
});

test('an alias keeps resolving: attaching or filtering by the old name hits the canonical label', function () {
    labeledTask('A', ['area:acct']);
    labeledTask('B', ['area:accounts']);
    app(LabelCleanupService::class)->replace([labelId('area:acct')], 'area:accounts');

    $later = labeledTask('Later', ['area:acct', ' area:acct ']);

    expect(taskLabelNames($later))->toBe(['area:accounts'])
        ->and(Label::query()->where('name', 'area:acct')->exists())->toBeFalse()
        ->and(app(DispatchTaskService::class)->queueQuery(['label' => 'area:acct'])->count())->toBe(3);
});

test('replacing a label that already has aliases re-points them, and a target named by alias resolves', function () {
    labeledTask('A', ['a']);
    labeledTask('B', ['b']);
    labeledTask('C', ['c']);
    $service = app(LabelCleanupService::class);

    $service->replace([labelId('a')], 'b');   // a → b
    $service->replace([labelId('b')], 'c');   // b → c, so a must follow to c

    expect(LabelAlias::canonicalize(['a', 'b', 'c']))->toBe(['c']);

    labeledTask('D', ['d']);
    $service->replace([labelId('d')], 'a');   // 'a' is an alias of c — lands on c

    expect(Label::query()->pluck('name')->all())->toBe(['c'])
        ->and(Label::query()->sole()->tasks()->count())->toBe(4);
});

test('a selected label that is also the target gets no events on its own tasks', function () {
    $keep = labeledTask('Keep', ['area:accounts']);
    $fold = labeledTask('Fold', ['area:acct']);

    $result = app(LabelCleanupService::class)->replace([labelId('area:accounts'), labelId('area:acct')], 'area:accounts');

    expect($result['replaced'])->toBe(['area:acct'])
        ->and($result['tasks'])->toBe(1)
        ->and($keep->comments()->where('event_type', TaskComment::EVENT_LABEL_REPLACED)->exists())->toBeFalse()
        ->and($fold->comments()->where('event_type', TaskComment::EVENT_LABEL_REPLACED)->exists())->toBeTrue();
});

test('soft-deleted tasks move too, so a restore cannot resurrect a folded label', function () {
    $gone = labeledTask('Gone', ['area:acct']);
    labeledTask('Live', ['area:accounts']);
    $gone->delete();

    $result = app(LabelCleanupService::class)->replace([labelId('area:acct')], 'area:accounts');
    $gone->restore();

    expect(taskLabelNames($gone))->toBe(['area:accounts'])
        ->and($result['tasks'])->toBe(0);   // no event on a trashed task
});

test('replace rewrites focus label axes to the canonical name', function () {
    labeledTask('A', ['area:acct']);
    labeledTask('B', ['area:accounts']);
    $focus = Focus::query()->create(['name' => 'Accounts', 'rank' => 0, 'is_active' => true, 'filters' => ['labels' => ['area:acct', 'area:accounts'], 'types' => ['bug']]]);

    $result = app(LabelCleanupService::class)->replace([labelId('area:acct')], 'area:accounts');

    expect($focus->fresh()->filters)->toBe(['labels' => ['area:accounts'], 'types' => ['bug']])
        ->and($result['focuses_updated'])->toBe(1);
});

test('retire detaches everywhere, deletes the label and its aliases, and memorializes it', function () {
    $a = labeledTask('A', ['noise', 'keep']);
    labeledTask('B', ['old-noise']);
    $service = app(LabelCleanupService::class);
    $service->replace([labelId('old-noise')], 'noise');

    $result = $service->retire([labelId('noise')]);

    expect(taskLabelNames($a))->toBe(['keep'])
        ->and(Label::query()->pluck('name')->all())->toBe(['keep'])
        ->and(LabelAlias::query()->count())->toBe(0)
        ->and($result['retired'])->toBe(['noise'])
        ->and($result['tasks'])->toBe(2);

    $event = $a->comments()->where('event_type', TaskComment::EVENT_LABEL_REMOVED)->sole();
    expect($event->is_internal)->toBeTrue()
        ->and($event->meta)->toBe(['labels' => ['noise'], 'retired' => true]);

    // A retired name that comes back is simply a new label.
    expect(taskLabelNames(labeledTask('C', ['noise'])))->toBe(['noise']);
});

test('retire strips focus axes and DEACTIVATES a focus it would leave with no labels', function () {
    labeledTask('A', ['noise', 'area:accounts']);
    $narrowed = Focus::query()->create(['name' => 'Narrowed', 'rank' => 0, 'is_active' => true, 'filters' => ['labels' => ['noise', 'area:accounts']]]);
    $emptied = Focus::query()->create(['name' => 'Emptied', 'rank' => 1, 'is_active' => true, 'filters' => ['labels' => ['noise'], 'priorities' => ['high']]]);

    $service = app(LabelCleanupService::class);
    expect($service->preview([labelId('noise')])['focuses_emptied'])->toBe(['Emptied']);

    $result = $service->retire([labelId('noise')]);

    expect($narrowed->fresh()->filters)->toBe(['labels' => ['area:accounts']])
        ->and($narrowed->fresh()->is_active)->toBeTrue()
        ->and($emptied->fresh()->filters)->toBe(['priorities' => ['high']])
        ->and($emptied->fresh()->is_active)->toBeFalse()
        ->and($result['focuses_deactivated'])->toBe(['Emptied']);
});

test('preview reports what replace will do without writing anything', function () {
    labeledTask('A', ['area:acct']);
    labeledTask('B', ['area:acct', 'area:accounts']);

    $preview = app(LabelCleanupService::class)->preview([labelId('area:acct')], 'area:accounts');

    expect($preview)->toMatchArray([
        'labels' => ['area:acct'],
        'tasks' => 2,
        'target' => 'area:accounts',
        'target_exists' => true,
        'already_on_target' => 1,
    ])->and(Label::query()->where('name', 'area:acct')->exists())->toBeTrue()
        ->and(LabelAlias::query()->count())->toBe(0);
});

test('replace refuses a blank target', function () {
    labeledTask('A', ['x']);

    app(LabelCleanupService::class)->replace([labelId('x')], '   ');
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------

test('dispatch:labels lists usage fewest-first and narrows with --max-uses', function () {
    labeledTask('A', ['busy', 'once']);
    labeledTask('B', ['busy']);
    Label::query()->create(['name' => 'orphan']);

    expect(Artisan::call('dispatch:labels', ['--json' => true]))->toBe(0);
    $all = dispatchJson(Artisan::output());

    expect(array_column($all['labels'], 'name'))->toBe(['orphan', 'once', 'busy'])
        ->and(array_column($all['labels'], 'tasks'))->toBe([0, 1, 2])
        ->and($all)->toMatchArray(['total' => 3, 'unused' => 1, 'single_use' => 1]);

    Artisan::call('dispatch:labels', ['--max-uses' => '1', '--json' => true]);
    expect(array_column(dispatchJson(Artisan::output())['labels'], 'name'))->toBe(['orphan', 'once']);

    $this->artisan('dispatch:labels', ['--unused' => true])
        ->expectsOutputToContain('orphan')
        ->assertOk();
});

test('dispatch:labels:replace --dry-run writes nothing; without it, the labels fold', function () {
    $a = labeledTask('A', ['acct']);
    labeledTask('B', ['accounts']);

    $this->artisan('dispatch:labels:replace', ['labels' => ['acct'], '--with' => 'accounts', '--dry-run' => true])
        ->expectsOutputToContain('Would replace acct with accounts')
        ->assertOk();
    expect(taskLabelNames($a))->toBe(['acct']);

    $this->artisan('dispatch:labels:replace', ['labels' => ['acct'], '--with' => 'accounts'])
        ->expectsOutputToContain('Replaced acct with accounts on 1 task(s)')
        ->assertOk();
    expect(taskLabelNames($a))->toBe(['accounts']);

    // The old name is an alias now — naming it again says where it went.
    $this->artisan('dispatch:labels:replace', ['labels' => ['acct'], '--with' => 'other'])
        ->expectsOutputToContain('acct is no longer a label — it already redirects to accounts')
        ->assertFailed();
});

test('dispatch:labels:replace needs --with and known labels', function () {
    labeledTask('A', ['real']);

    $this->artisan('dispatch:labels:replace', ['labels' => ['real']])
        ->expectsOutputToContain('--with=<label> is required')
        ->assertFailed();

    $this->artisan('dispatch:labels:replace', ['labels' => ['real', 'ghost'], '--with' => 'x'])
        ->expectsOutputToContain('Label not found: ghost')
        ->assertFailed();

    expect(Label::query()->pluck('name')->all())->toBe(['real']);   // all-or-nothing
});

test('dispatch:labels:retire --json reports the retire', function () {
    labeledTask('A', ['noise', 'keep']);

    expect(Artisan::call('dispatch:labels:retire', ['labels' => ['noise'], '--json' => true]))->toBe(0);

    expect(dispatchJson(Artisan::output()))->toMatchArray(['retired' => ['noise'], 'tasks' => 1])
        ->and(Label::query()->pluck('name')->all())->toBe(['keep']);
});

test('the dispatch:labels write commands refuse the local DB while an agent session is active, unless --local', function () {
    config([
        'dispatch.agent.remote.url' => 'https://agent.example.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => sys_get_temp_dir().'/dispatch-labels-'.uniqid().'.json',
    ]);
    seedAgentToken();
    $a = labeledTask('A', ['acct', 'noise']);
    labeledTask('B', ['accounts']);

    $this->artisan('dispatch:labels:replace', ['labels' => ['acct'], '--with' => 'accounts'])
        ->expectsOutputToContain('no remote label verb exists')
        ->assertFailed();
    $this->artisan('dispatch:labels:retire', ['labels' => ['noise']])->assertFailed();
    expect(taskLabelNames($a))->toBe(['acct', 'noise']);

    // A dry run writes nothing, so it isn't refused.
    $this->artisan('dispatch:labels:retire', ['labels' => ['noise'], '--dry-run' => true])->assertOk();

    $this->artisan('dispatch:labels:retire', ['labels' => ['noise'], '--local' => true])->assertOk();
    expect(taskLabelNames($a))->toBe(['acct']);
});
