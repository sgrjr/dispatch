<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The winning task's id, stamped on a merged-away loser (see
 * DispatchTaskService::merge()). Guarded so it's a no-op if already present.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dispatch_tasks', 'duplicate_of')) {
            return;
        }

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('duplicate_of')->nullable()->index()->after('due_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('dispatch_tasks', 'duplicate_of')) {
            return;
        }

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->dropColumn('duplicate_of');
        });
    }
};
