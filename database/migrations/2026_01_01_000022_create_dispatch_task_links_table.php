<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-997 part B — real task->task BLOCKED-BY links (the ball contract, R15/
 * R22). `task_id` is the BLOCKED task, `blocked_by_task_id` is the BLOCKER;
 * `kind` defaults to `blocks` (room for `relates` later, per the contract —
 * nothing reads a second kind yet). `created_by_user_id` is unowned by an FK
 * (no `users` table in the package) — it's who ASKED / who linked, read back
 * by the Ask flow to return the ball to the right person.
 *
 * Cascades on a HARD delete of either task (SoftDeletes means this rarely
 * fires — a soft-deleted task's links simply go stale, same posture as every
 * other Task relation in the package).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_task_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('dispatch_tasks')->cascadeOnDelete();
            $table->foreignId('blocked_by_task_id')->constrained('dispatch_tasks')->cascadeOnDelete();
            $table->string('kind', 32)->default('blocks');
            $table->unsignedBigInteger('created_by_user_id')->nullable(); // no FK: app owns users
            $table->timestamps();

            $table->unique(['task_id', 'blocked_by_task_id', 'kind']);
            // `task_id` is covered as the unique index's leftmost column
            // (blockedBy() lookups); blocks() lookups need their own index.
            $table->index('blocked_by_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_task_links');
    }
};
