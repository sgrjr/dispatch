<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured client diagnostics captured with a report (console errors, url,
 * user agent, viewport). Added as its own migration so existing installs pick
 * it up; guarded so it's a no-op if already present.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dispatch_tasks', 'context')) {
            return;
        }

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->json('context')->nullable()->after('exception_signature');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('dispatch_tasks', 'context')) {
            return;
        }

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
