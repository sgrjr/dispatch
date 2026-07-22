<?php

use Illuminate\Support\Facades\Artisan;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\TaskPresenter;

/*
 * W8-6 [pkg]: attachments become VISIBLE in the agent JSON contract as EXISTENCE
 * SIGNALS (no fetch URL — binaries live on a private, auth-gated disk and never
 * travel the JSON API). This file locks the FROZEN ADDITIVE shape: the summary
 * gains exactly `attachment_count`; the full view gains exactly `attachments[]`
 * plus a per-comment `attachment_count`; nothing else moves.
 */

beforeEach(fn () => dispatchFakeUsers());

/**
 * A task carrying a label, a human comment, one task-level attachment and one
 * comment-level attachment — the full spread the contract must surface. Returns
 * [$task, $comment]. House idiom: manual creates through the morphMany relation
 * (no factories); the relation stamps the attachable morph keys.
 */
function attachmentRichTask(): array
{
    $task = app(DispatchTaskService::class)->create(
        ['title' => 'human attached a screenshot', 'status' => 'open'],
        ['ui'],
    );

    $task->attachments()->create([
        'disk' => 'private', 'path' => 'x/shot.png', 'original_name' => 'shot.png',
        'mime_type' => 'image/png', 'size_bytes' => 2048, 'is_image' => true,
    ]);

    $author = dispatchMakeUser(77100);
    $comment = $task->recordEvent(TaskComment::EVENT_COMMENT, $author->id, [], 'the failing view is attached');
    $comment->attachments()->create([
        'disk' => 'private', 'path' => 'x/trace.txt', 'original_name' => 'trace.txt',
        'mime_type' => 'text/plain', 'size_bytes' => 640, 'is_image' => false,
    ]);

    return [$task->fresh(), $comment];
}

test('GOLDEN SHAPE: the summary adds exactly attachment_count; nothing else moves (W8-6)', function () {
    [$task] = attachmentRichTask();

    // The frozen pre-existing summary keys, in order, with `attachment_count`
    // inserted right after `comment_count`. Order-sensitive on purpose: this file
    // solely owns TaskPresenter's shape, so any accidental key add/move/reorder
    // trips this lock loudly.
    expect(array_keys(TaskPresenter::toArray($task, false)))->toBe([
        'code', 'title', 'type', 'priority', 'status', 'is_public', 'labels',
        'comment_count', 'attachment_count', 'due_at', 'dedupe_key',
        'submitter', 'assignee', 'created_at', 'updated_at',
    ]);
});

test('GOLDEN SHAPE: the full view adds exactly attachments[] + a per-comment attachment_count (W8-6)', function () {
    [$task] = attachmentRichTask();

    $full = TaskPresenter::toArray($task->load('comments'), true);

    // Full = every summary key, then the full-only adds in order.
    expect(array_keys($full))->toBe([
        'code', 'title', 'type', 'priority', 'status', 'is_public', 'labels',
        'comment_count', 'attachment_count', 'due_at', 'dedupe_key',
        'submitter', 'assignee', 'created_at', 'updated_at',
        'description', 'context', 'attachments', 'comments',
    ]);

    // Each comment entry gains exactly `attachment_count` (after meta, before
    // created_at) — the rest of the comment shape is byte-frozen.
    expect(array_keys($full['comments'][0]))->toBe([
        'id', 'event_type', 'is_internal', 'author', 'body', 'meta',
        'attachment_count', 'created_at',
    ]);
    expect($full['comments'][0]['attachment_count'])->toBe(1);
});

test('attachment_count agrees across all 3 preference tiers for the same task (W8-6)', function () {
    [$task] = attachmentRichTask();
    // A second task-level attachment, so the count is a distinctive 2.
    $task->attachments()->create([
        'disk' => 'private', 'path' => 'x/two.png', 'original_name' => 'two.png',
        'mime_type' => 'image/png', 'size_bytes' => 10, 'is_image' => true,
    ]);

    // Tier 1 — the eager withCount attribute (the next/queue read path).
    $viaAttr = app(DispatchTaskService::class)
        ->eagerForRead(Task::query()->whereKey($task->id))
        ->first();
    expect(array_key_exists('attachment_count', $viaAttr->getAttributes()))->toBeTrue();
    expect(TaskPresenter::toArray($viaAttr)['attachment_count'])->toBe(2);

    // Tier 2 — the loaded relation counted in memory (full shape path).
    $viaRelation = Task::query()->with('attachments')->find($task->id);
    expect($viaRelation->relationLoaded('attachments'))->toBeTrue()
        ->and(array_key_exists('attachment_count', $viaRelation->getAttributes()))->toBeFalse();
    expect(TaskPresenter::toArray($viaRelation)['attachment_count'])->toBe(2);

    // Tier 3 — a cold model, resolved by the single COUNT query fallback.
    $cold = Task::query()->find($task->id);
    expect($cold->relationLoaded('attachments'))->toBeFalse();
    expect(TaskPresenter::toArray($cold)['attachment_count'])->toBe(2);
});

test('full attachments[] carries filename/mime/size_bytes/is_image faithfully (W8-6)', function () {
    [$task] = attachmentRichTask();

    $attachments = TaskPresenter::toArray($task, true)['attachments'];

    expect($attachments)->toHaveCount(1);
    expect($attachments[0])->toBe([
        'filename' => 'shot.png',
        'mime' => 'image/png',
        'size_bytes' => 2048,
        'is_image' => true,
    ]);
});

test('schema() documents the new summary/full_adds/done keys (W8-6)', function () {
    $schema = TaskPresenter::schema();

    // Summary gains the attachment_count signal.
    expect($schema['summary'])->toHaveKey('attachment_count')
        ->and($schema['summary']['attachment_count'])->toBe('int');

    // full_adds gains attachments[] and the comments line grows attachment_count.
    expect($schema['full_adds'])->toHaveKey('attachments')
        ->and($schema['full_adds']['attachments'])->toContain('no fetch URL')
        ->and($schema['full_adds']['comments'])->toContain('attachment_count:int');

    // The new top-level `done` close-conventions key, between full_adds and batch.
    expect($schema)->toHaveKey('done');
    expect($schema['done'])->toHaveKeys(['commit', 'result', 'resolution'])
        ->and($schema['done']['resolution'])->toContain('already-implemented');

    // The `done` key sits after full_adds and before batch.
    $order = array_keys($schema);
    expect(array_search('done', $order, true))->toBeGreaterThan(array_search('full_adds', $order, true))
        ->and(array_search('done', $order, true))->toBeLessThan(array_search('batch', $order, true));
});

test('DispatchShow human output shows the Attachments block and the per-comment suffix (W8-6)', function () {
    [$task] = attachmentRichTask();

    Artisan::call('dispatch:show', ['code' => $task->code]);
    $out = Artisan::output();

    // The task-level Attachments block: one line per file, image flagged.
    expect($out)->toContain('Attachments:')
        ->and($out)->toContain('shot.png (image/png, 2048 bytes) · image');

    // The comment that carries a file gets a [+N attachment(s)] suffix in Thread.
    expect($out)->toContain('[+1 attachment(s)]');
});
