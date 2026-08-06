<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W13-4: config-defined groups as assignees. The ASSIGNEE stays a singular
 * value — this column names a `dispatch.groups` key and is mutually
 * exclusive with assignee_user_id (the UI writes exactly one; both null =
 * unassigned). A group assignment fans out to its members for
 * notifications and counts every member as a GATE A participant.
 *
 * A string naming a config key — not a groups table — because groups
 * change rarely, hosts already hand-edit the published config, and a CRUD
 * surface would be new machinery for no new capability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->string('assignee_group', 64)->nullable()->index()->after('assignee_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->dropColumn('assignee_group');
        });
    }
};
