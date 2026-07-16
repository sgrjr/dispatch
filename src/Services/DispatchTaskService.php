<?php

namespace Sgrjr\Dispatch\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sgrjr\Dispatch\Contracts\SubmitterResolver;
use Sgrjr\Dispatch\Contracts\TenantResolver;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * The single place Dispatch tasks are minted.
 *
 * Every inbound source — the in-app capture widget, the `dispatch:add` CLI, and
 * auto exception capture — routes through here so code minting, submitter
 * resolution, tenant stamping, and label attachment happen one way. Replaces
 * rupkeep's app-coupled service (hard-coded 'Reynolds Upkeep' submitter, direct
 * organization_id write) with contract-driven seams.
 */
class DispatchTaskService
{
    public function __construct(
        protected SubmitterResolver $submitters,
        protected TenantResolver $tenants,
    ) {}

    /**
     * Create a task and attach any labels (auto-creating labels as needed).
     *
     * @param  array<string,mixed>  $attributes  Task attributes (title required).
     * @param  array<int,string>    $labelNames  Label names to attach.
     */
    public function create(array $attributes, array $labelNames = [], ?Authenticatable $actor = null): Task
    {
        $actor ??= Auth::user();

        $attributes['title'] = Str::limit(trim((string) ($attributes['title'] ?? '')), 255, '…');
        $attributes['type'] ??= 'feature';
        $attributes['priority'] ??= 'medium';
        $attributes['status'] ??= 'triage';
        $attributes['is_public'] = (bool) ($attributes['is_public'] ?? false);
        $attributes['submitter_user_id'] ??= $this->submitters->currentUserId() ?? $this->submitters->defaultUserId();

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $task = $taskModel::createWithCode(
            $attributes,
            fn ($model) => $this->tenants->stamp($model, $actor),
        );

        $this->attachLabels($task, $labelNames);

        // Submission-acknowledgement receipt (N2). The DispatchNotifier
        // contract guarantees implementations never throw, but this is a
        // critical path — don't trust that promise, catch here too.
        try {
            app(\Sgrjr\Dispatch\Contracts\DispatchNotifier::class)->taskCreated($task);
        } catch (\Throwable) {
            // never break task creation over a notification failure
        }

        return $task;
    }

    /**
     * Capture entry point for automated sources (e.g. exception handler). Dedupes
     * on $signature: a recurring identical error appends an occurrence event to
     * the existing open task instead of creating a duplicate.
     *
     * @param  array<string,mixed>  $attributes
     * @param  array<int,string>    $labelNames
     */
    public function capture(string $signature, array $attributes, array $labelNames = []): Task
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $existing = $taskModel::query()
            ->where('exception_signature', $signature)
            ->whereNotIn('status', ['done', 'declined'])
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            $existing->recordEvent(
                TaskComment::EVENT_EXCEPTION,
                null,
                ['at' => now()->toIso8601String()],
            );

            // Occurrence tracking: bump the counters in the stored context so a
            // recurring error reads "seen N times" instead of spawning dupes.
            $ctx = $existing->context ?? [];
            $ctx['times_seen'] = (int) ($ctx['times_seen'] ?? 1) + 1;
            $ctx['last_seen'] = now()->toIso8601String();
            $existing->context = $ctx;
            $existing->save();

            return $existing;
        }

        $attributes['exception_signature'] = $signature;
        $attributes['type'] ??= 'bug';
        $attributes['status'] ??= 'triage';

        return $this->create($attributes, $labelNames);
    }

    /**
     * Attach the named labels to a task, creating any that don't exist yet.
     *
     * @param  array<int,string>  $labelNames
     */
    public function attachLabels(Task $task, array $labelNames): void
    {
        /** @var class-string $labelModel */
        $labelModel = config('dispatch.models.label');

        $labelIds = [];
        foreach ($labelNames as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $labelIds[] = $labelModel::firstOrCreate(['name' => $name])->id;
        }

        if (! empty($labelIds)) {
            $task->labels()->syncWithoutDetaching($labelIds);
        }
    }

    /**
     * Merge $loser into $winner: reparent the loser's comments and
     * attachments onto the winner, union labels, memorialize the merge on
     * both tasks, then soft-delete the loser (marked `duplicate_of` the
     * winner, status `declined`).
     *
     * Wrapped in a transaction — a merge touches four tables and must not
     * partially apply.
     */
    public function merge(Task $loser, Task $winner, ?int $actorId = null): Task
    {
        return DB::transaction(function () use ($loser, $winner, $actorId) {
            /** @var class-string $commentModel */
            $commentModel = config('dispatch.models.task_comment');
            $commentModel::where('task_id', $loser->id)->update(['task_id' => $winner->id]);

            /** @var class-string $attachmentModel */
            $attachmentModel = config('dispatch.models.task_attachment');
            $attachmentModel::where('attachable_type', $loser->getMorphClass())
                ->where('attachable_id', $loser->id)
                ->update(['attachable_id' => $winner->id, 'attachable_type' => $winner->getMorphClass()]);

            // allRelatedIds() reads the related keys straight off the pivot —
            // avoids an ambiguous unqualified `id` in the labels join (the
            // pivot also has an `id`).
            $labelIds = $loser->labels()->allRelatedIds()->all();
            $winner->labels()->syncWithoutDetaching($labelIds);

            $winner->recordEvent(
                TaskComment::EVENT_MERGED,
                $actorId,
                ['from' => $loser->code],
                "Merged {$loser->code} into this task.",
            );

            $loser->duplicate_of = $winner->id;
            $loser->status = 'declined';
            $loser->save();

            $loser->recordEvent(
                TaskComment::EVENT_MERGED,
                $actorId,
                ['into' => $winner->code],
                "Merged into {$winner->code}.",
            );

            $loser->delete();

            return $winner->refresh();
        });
    }
}
