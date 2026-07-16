<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional due date for staleness/scheduling surfaces. Guarded so it's a
 * no-op if already present (idempotent on repeated publish+migrate).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dispatch_tasks', 'due_at')) {
            return;
        }

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->dateTime('due_at')->nullable()->index()->after('context');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('dispatch_tasks', 'due_at')) {
            return;
        }

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->dropColumn('due_at');
        });
    }
};
