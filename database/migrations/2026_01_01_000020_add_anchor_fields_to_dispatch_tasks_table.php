<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-995 — the ANCHOR fields (topic / origin / conversation). All nullable;
 * nothing here changes existing behavior until a caller starts writing them.
 *
 *  - `topic_type`/`topic_id`   — what the task is ABOUT (an account, a plan, a
 *    title, an order, …). `topic_account_key` is a STORED rollup the
 *    TopicResolver stamps whenever topic_type/topic_id changes (see
 *    Task::booted()) — never written directly by a caller.
 *  - `origin_type`/`origin_id` — where the task came FROM. Write-once: once
 *    `origin_type` is non-null, changing either column is rejected (see
 *    Task::setOrigin() + the `saving` guard).
 *  - `conversation_id`         — the home conversation/arc. A plain int
 *    column, no FK (the host owns the conversation table, if any).
 *
 * `topic_account_key` is distinct from the pre-existing `account_key` host
 * column some subclasses carry for submitter tenancy — this rollup is about
 * the task's SUBJECT, not who filed it, and never widens visibility (see
 * VisibilityGates — neither anchor is consulted there).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dispatch_tasks', 'topic_type')) {
            Schema::table('dispatch_tasks', function (Blueprint $table) {
                $table->string('topic_type', 32)->nullable()->after('duplicate_of');
                $table->string('topic_id', 191)->nullable()->after('topic_type');
                // STORED rollup — stamped by the TopicResolver, never user-set.
                $table->string('topic_account_key', 64)->nullable()->after('topic_id');

                $table->string('origin_type', 32)->nullable()->after('topic_account_key');
                $table->string('origin_id', 191)->nullable()->after('origin_type');

                $table->unsignedBigInteger('conversation_id')->nullable()->after('origin_id');

                $table->index(['topic_type', 'topic_id'], 'dispatch_tasks_topic_index');
                $table->index(['origin_type', 'origin_id'], 'dispatch_tasks_origin_index');
                $table->index('conversation_id');
                $table->index('topic_account_key');
            });
        }

        $this->backfillOriginFromLabels();
    }

    /**
     * Map a handful of pre-existing `source:*` labels onto the new
     * `origin_type` column, ONLY where it's still null (idempotent — safe to
     * run again, and never clobbers an origin a caller has since set). Tasks
     * carrying no matching label, or already stamped, are left exactly alone.
     */
    protected function backfillOriginFromLabels(): void
    {
        if (! Schema::hasTable('dispatch_labels') || ! Schema::hasTable('dispatch_task_label')) {
            return;
        }

        $map = [
            'source:exception' => 'exception',
            'source:contact-form' => 'contact_form',
            'source:email' => 'email',
        ];

        foreach ($map as $labelName => $originType) {
            $taskIds = DB::table('dispatch_task_label')
                ->join('dispatch_labels', 'dispatch_labels.id', '=', 'dispatch_task_label.label_id')
                ->where('dispatch_labels.name', $labelName)
                ->pluck('dispatch_task_label.task_id');

            if ($taskIds->isEmpty()) {
                continue;
            }

            DB::table('dispatch_tasks')
                ->whereIn('id', $taskIds)
                ->whereNull('origin_type')
                ->update(['origin_type' => $originType]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('dispatch_tasks', 'topic_type')) {
            return;
        }

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->dropIndex('dispatch_tasks_topic_index');
            $table->dropIndex('dispatch_tasks_origin_index');
            $table->dropIndex(['conversation_id']);
            $table->dropIndex(['topic_account_key']);

            $table->dropColumn([
                'topic_type', 'topic_id', 'topic_account_key',
                'origin_type', 'origin_id', 'conversation_id',
            ]);
        });
    }
};
