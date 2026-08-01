<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `body` was `text` — 65,535 BYTES. Agent comments are the durable record of
     * a task's work (result payloads, file listings, mined evidence), and a long
     * one blew the ceiling: the whole batch died mid-transaction with
     * SQLSTATE[22001] "Data too long for column 'body'", losing every operation
     * in that manifest, not just the oversized comment.
     *
     * Widened to longText so the record is never the thing that fails. The
     * runaway-payload guard lives in DispatchBatchService (MAX_COMMENT_BODY_BYTES),
     * where it can name the offending operation instead of surfacing a raw
     * SQLSTATE.
     *
     * Note the limit is BYTES, not characters — under utf8mb4 the old column
     * capped out at ~16k characters of 4-byte content, well short of what the
     * `text` label suggests.
     */
    public function up(): void
    {
        Schema::table('dispatch_task_comments', function (Blueprint $table) {
            $table->longText('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Irreversible in practice: any row already over 65,535 bytes would be
        // truncated on the way back down. Guard the rollback rather than
        // silently shredding history.
        $oversized = \DB::table('dispatch_task_comments')
            ->whereRaw('LENGTH(body) > 65535')
            ->count();

        if ($oversized > 0) {
            throw new \RuntimeException(
                "Refusing to narrow dispatch_task_comments.body: {$oversized} comment(s) exceed 65,535 bytes and would be truncated."
            );
        }

        Schema::table('dispatch_task_comments', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
        });
    }
};
