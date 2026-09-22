<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-998 — a per-person READ CURSOR on each task: when this person last
 * looked at it. "New since you looked" is then any timeline event, by someone
 * else, after that moment (Task::scopeWithNewsFor()).
 *
 * One row per (task, person), moved forward by DispatchTaskService::markRead()
 * whenever they open the task. `user_id` is unowned by an FK (no `users` table
 * in the package), the same posture as dispatch_task_links.created_by_user_id.
 *
 * ⚠️ Not the bell's read state. A notification is read when the person marks it
 * so (the app's "reading never marks read" rule is about the BELL); a task is
 * "looked at" when it is opened — that is what the cursor records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_task_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('dispatch_tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id'); // no FK: app owns users
            $table->timestamp('read_at');

            // One cursor per person per task — the upsert key, and the
            // correlated lookup scopeWithNewsFor() makes per task row.
            $table->unique(['task_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_task_reads');
    }
};
