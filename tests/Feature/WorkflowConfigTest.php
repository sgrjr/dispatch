<?php

use Sgrjr\Dispatch\Models\Task;

/**
 * Task::types()/priorities()/statuses() read the configured workflow vocab
 * (falling back to the built-in consts if unset), and prioritySql()/
 * statusSql() rank that vocab into a CASE expression for orderByRaw().
 */

test('Task::statuses() defaults to the built-in STATUSES const', function () {
    expect(Task::statuses())->toBe(Task::STATUSES);
    expect(Task::types())->toBe(Task::TYPES);
    expect(Task::priorities())->toBe(Task::PRIORITIES);
});

test('configuring dispatch.workflow.statuses overrides Task::statuses()', function () {
    config(['dispatch.workflow.statuses' => ['a', 'b']]);

    expect(Task::statuses())->toBe(['a', 'b']);
});

test('Task::prioritySql() ranks priorities() in configured order', function () {
    $sql = Task::prioritySql();

    expect($sql)->toStartWith('CASE priority ');
    expect($sql)->toContain("WHEN 'blocker' THEN 0");
    expect($sql)->toContain("WHEN 'high' THEN 1");
    expect($sql)->toContain("WHEN 'medium' THEN 2");
    expect($sql)->toContain("WHEN 'low' THEN 3");
    expect($sql)->toEndWith('ELSE 4 END');

    config(['dispatch.workflow.priorities' => ['urgent', 'normal']]);

    $custom = Task::prioritySql('t.priority');
    expect($custom)->toStartWith('CASE t.priority ');
    expect($custom)->toContain("WHEN 'urgent' THEN 0");
    expect($custom)->toContain("WHEN 'normal' THEN 1");
    expect($custom)->toEndWith('ELSE 2 END');
});

test('Task::statusSql() ranks statuses() in configured order', function () {
    $sql = Task::statusSql();

    expect($sql)->toStartWith('CASE status ');
    expect($sql)->toContain("WHEN 'triage' THEN 0");
    expect($sql)->toContain("WHEN 'backburner' THEN 4");
    expect($sql)->toContain("WHEN 'done' THEN 5");
    expect($sql)->toContain("WHEN 'declined' THEN 6");
    expect($sql)->toEndWith('ELSE 7 END');
});

test('statusLabels() auto-humanizes when no status_labels map is configured', function () {
    $labels = Task::statusLabels();

    expect($labels['in_progress'])->toBe('In Progress');
    expect($labels['triage'])->toBe('Triage');
});

test('a configured status_labels map wins over auto-humanization', function () {
    config(['dispatch.workflow.status_labels' => ['in_progress' => 'Working']]);

    expect(Task::statusLabels())->toBe(['in_progress' => 'Working']);
});
