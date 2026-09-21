<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-999 (R24) — the lane an agent session SERVES. Requested at
 * `dispatch:session:request --lane=`, carried in `requested_meta.lane`, and
 * resolved onto this column by the approver exactly like `scopes`: the human
 * at /it/agent-sessions sees it and can change it before granting.
 *
 * NULL = unrestricted (the pre-TASK-999 behavior — the whole open board), so
 * a host that never sets a lane sees no change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dispatch_agent_sessions', 'lane')) {
            Schema::table('dispatch_agent_sessions', function (Blueprint $table) {
                $table->string('lane', 96)->nullable()->after('scopes');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('dispatch_agent_sessions', 'lane')) {
            return;
        }

        Schema::table('dispatch_agent_sessions', function (Blueprint $table) {
            $table->dropColumn('lane');
        });
    }
};
