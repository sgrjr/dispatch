<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W13-5: the visibility gates. `visibility` picks which staff see the task:
 *
 *  - 'participants' — GATE A only: submitter + assignee + watchers. The
 *    DEFAULT for new staff-created tasks (operator ruling 2026-08-06: the
 *    circle is opt-OUT of privacy, not opt-in — "I created it and assigned
 *    it to user B" is the circle, always true, never a setting).
 *  - 'staff' — GATE B: every staff member (the pre-W13-5 behavior).
 *
 * GATE C (the submitting customer) stays the existing `is_public` toggle;
 * GATE D (everyone else, including guests) is a hard no-op — no task is
 * ever publicly visible.
 *
 * EXISTING rows are backfilled to 'staff': they were created under the
 * all-staff-see expectation, and retroactively vanishing them from boards
 * would read as data loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->string('visibility', 32)->default('participants')->index()->after('is_public');
        });

        DB::table('dispatch_tasks')->update(['visibility' => 'staff']);
    }

    public function down(): void
    {
        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
