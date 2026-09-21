<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-997 part A — the `lane` column: the department (or a role-named
 * sub-lane of a department) that does a task's work. Nullable — null is the
 * NO-DEPARTMENT lane (R15), not "unknown" — so nothing changes until a caller
 * starts writing it. No backfill: lane keys are host data, resolved through
 * the bound LaneResolver (see Support\Lane, Contracts\LaneResolver).
 *
 * Indexed alongside `status` because the dominant read is "the open/triage
 * backlog for MY lane" (dispatch:queue/next/claim --lane=, a lane-scoped
 * board/personal view in a later wave).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dispatch_tasks', 'lane')) {
            Schema::table('dispatch_tasks', function (Blueprint $table) {
                $table->string('lane', 96)->nullable()->after('assignee_group');
                $table->index(['lane', 'status']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('dispatch_tasks', 'lane')) {
            return;
        }

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->dropIndex(['lane', 'status']);
            $table->dropColumn('lane');
        });
    }
};
