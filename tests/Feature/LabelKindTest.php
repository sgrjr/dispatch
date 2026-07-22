<?php

use Sgrjr\Dispatch\Models\Label;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\LabelFacets;

/*
 * Label kind substrate (roadmap W8-1b / W8-3): the per-label `kind` column, the
 * namespace default map, and the LabelFacets split/grouped/laneKey derivations
 * the chip/filter partials and later swimlane UI all share.
 */

test('the kind column round-trips through the migration', function () {
    $label = Label::create(['name' => 'area:billing', 'kind' => Label::KIND_ELEVATED]);

    expect($label->fresh()->kind)->toBe('elevated');
});

test('prefix() is the substring before the FIRST colon, or null without one', function () {
    expect((new Label(['name' => 'area:billing']))->prefix())->toBe('area')
        ->and((new Label(['name' => 'epic:q3:launch']))->prefix())->toBe('epic') // first colon only
        ->and((new Label(['name' => 'chore']))->prefix())->toBeNull();          // no colon → null, never the whole name
});

test('effectiveKind() resolves from the namespace map', function () {
    expect((new Label(['name' => 'area:api']))->effectiveKind())->toBe(Label::KIND_ELEVATED)
        ->and((new Label(['name' => 'source:exception']))->effectiveKind())->toBe(Label::KIND_META)
        ->and((new Label(['name' => 'random']))->effectiveKind())->toBeNull();
});

test('a per-label kind column overrides the namespace default', function () {
    // area:* defaults to elevated; the explicit column flips this one to meta.
    $label = new Label(['name' => 'area:internal', 'kind' => Label::KIND_META]);

    expect($label->effectiveKind())->toBe(Label::KIND_META);
});

test('a configured namespace_kinds map is honored', function () {
    config(['dispatch.labels.namespace_kinds' => ['team' => 'elevated']]);

    expect(LabelFacets::namespaceKinds())->toBe(['team' => 'elevated'])
        ->and((new Label(['name' => 'team:core']))->effectiveKind())->toBe(Label::KIND_ELEVATED)
        // `area` is no longer mapped under the override — plain now.
        ->and((new Label(['name' => 'area:api']))->effectiveKind())->toBeNull();
});

test('absent config falls back to DEFAULT_NAMESPACE_KINDS', function () {
    // Simulate a published host config that predates the `labels` block.
    config(['dispatch.labels' => null]);

    expect(LabelFacets::namespaceKinds())->toBe(LabelFacets::DEFAULT_NAMESPACE_KINDS)
        ->and((new Label(['name' => 'area:api']))->effectiveKind())->toBe(Label::KIND_ELEVATED)
        ->and((new Label(['name' => 'kind:regression']))->effectiveKind())->toBe(Label::KIND_META);
});

test('split() buckets by effectiveKind, preserving input order within each bucket', function () {
    $labels = collect([
        new Label(['name' => 'area:api']),          // elevated
        new Label(['name' => 'urgent']),            // plain
        new Label(['name' => 'source:exception']),  // meta
        new Label(['name' => 'epic:launch']),       // elevated
        new Label(['name' => 'kind:regression']),   // meta
        new Label(['name' => 'backend']),           // plain
    ]);

    $split = LabelFacets::split($labels);

    expect($split['elevated']->pluck('name')->all())->toBe(['area:api', 'epic:launch'])
        ->and($split['plain']->pluck('name')->all())->toBe(['urgent', 'backend'])
        ->and($split['meta']->pluck('name')->all())->toBe(['source:exception', 'kind:regression']);
});

test('grouped() sections elevated namespaces first (in first-appearance order), then Labels, then Meta', function () {
    $labels = collect([
        new Label(['name' => 'area:api']),
        new Label(['name' => 'urgent']),
        new Label(['name' => 'source:exception']),
        new Label(['name' => 'area:billing']),
        new Label(['name' => 'epic:launch']),
    ]);

    $groups = LabelFacets::grouped($labels);

    expect(array_column($groups, 'title'))->toBe(['Area', 'Epic', 'Labels', 'Meta'])
        ->and($groups[0]['options'])->toBe(['area:api' => 'area:api', 'area:billing' => 'area:billing'])
        ->and($groups[1]['options'])->toBe(['epic:launch' => 'epic:launch'])
        ->and($groups[2]['options'])->toBe(['urgent' => 'urgent'])
        ->and($groups[3]['options'])->toBe(['source:exception' => 'source:exception']);
});

test('grouped() collects prefixless elevated labels under Pinned and omits empty sections', function () {
    $labels = collect([
        new Label(['name' => 'flagship', 'kind' => Label::KIND_ELEVATED]), // prefixless, forced elevated
        new Label(['name' => 'area:api']),
    ]);

    $groups = LabelFacets::grouped($labels);

    // No plain / no meta labels → those sections are omitted entirely.
    expect(array_column($groups, 'title'))->toBe(['Pinned', 'Area'])
        ->and($groups[0]['options'])->toBe(['flagship' => 'flagship']);
});

test('laneKey() takes the portion after the first colon of the first elevated label', function () {
    $svc = app(DispatchTaskService::class);

    // A meta label precedes the elevated one — laneKey skips meta/plain.
    $task = $svc->create(['title' => 'billing work'], ['source:x', 'area:billing']);

    expect(LabelFacets::laneKey($task->fresh()))->toBe('billing');
});

test('laneKey() returns the full name for a colon-less elevated label', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 'flagship work']);

    // Attach a prefixless label that is elevated by its own kind column.
    $label = Label::create(['name' => 'flagship', 'kind' => Label::KIND_ELEVATED]);
    $task->labels()->attach($label->id);

    expect(LabelFacets::laneKey($task->fresh()))->toBe('flagship');
});

test('laneKey() is null when the task carries no elevated label', function () {
    $svc = app(DispatchTaskService::class);
    $task = $svc->create(['title' => 'plain work'], ['urgent', 'source:exception']);

    expect(LabelFacets::laneKey($task->fresh()))->toBeNull();
});
