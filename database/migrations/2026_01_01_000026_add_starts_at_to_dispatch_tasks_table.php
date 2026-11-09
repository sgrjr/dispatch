<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PU-2.12 (TASK-1060) — a task's START date: "not before". With `due_at`
 * ("needed by") it makes scheduling honest: a task scheduled for later stays
 * out of the personal views and the agent queue until its day (R30), and is
 * still on the board and in search. Guarded so it's a no-op if present.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dispatch_tasks', 'starts_at')) {
            return;
        }

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->dateTime('starts_at')->nullable()->index()->after('due_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('dispatch_tasks', 'starts_at')) {
            return;
        }

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->dropColumn('starts_at');
        });
    }
};
