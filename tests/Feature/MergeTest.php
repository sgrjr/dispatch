<?php

use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/**
 * DispatchTaskService::merge() folds a duplicate ("loser") task into the
 * canonical ("winner") task: comments reparent, labels union, both sides
 * get a memorial EVENT_MERGED comment, and the loser is soft-deleted with
 * duplicate_of/status stamped.
 */

test('merging a loser into a winner reparents its comment, unions labels, and soft-deletes the loser', function () {
    $service = app(DispatchTaskService::class);

    $winner = $service->create(['title' => 'Winner task']);
    $loser = $service->create(['title' => 'Loser task']);

    $service->attachLabels($loser, ['dupe:label']);
    $comment = $loser->comments()->create([
        'user_id' => null,
        'body' => 'This is a duplicate report',
        'event_type' => TaskComment::EVENT_COMMENT,
    ]);

    $merged = $service->merge($loser, $winner, 1);

    expect($merged->id)->toBe($winner->id);

    // The loser's comment now belongs to the winner.
    expect($comment->fresh()->task_id)->toBe($winner->id);

    // The winner picked up the loser's label.
    expect($merged->labels->pluck('name')->all())->toContain('dupe:label');

    // The loser is soft-deleted and marked as a duplicate of the winner.
    $freshLoser = Task::withTrashed()->find($loser->id);

    expect($freshLoser->trashed())->toBeTrue();
    expect($freshLoser->duplicate_of)->toBe($winner->id);
    expect($freshLoser->status)->toBe('declined');

    // Both tasks carry an EVENT_MERGED memorial comment.
    expect($merged->comments()->where('event_type', TaskComment::EVENT_MERGED)->exists())->toBeTrue();
    expect($freshLoser->comments()->where('event_type', TaskComment::EVENT_MERGED)->exists())->toBeTrue();
});
