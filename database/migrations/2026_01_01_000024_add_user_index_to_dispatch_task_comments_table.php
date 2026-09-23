<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PU-2.14 (TASK-1062) — "recently worked": the tasks a person DID something on,
 * newest first, read off the timeline by its author. Without this index that
 * is a full scan of every event on every sidebar load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_task_comments', function (Blueprint $table) {
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_task_comments', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
        });
    }
};
