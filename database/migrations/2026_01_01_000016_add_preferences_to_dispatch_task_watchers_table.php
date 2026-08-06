<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W13-1: per-watcher notification preferences. Both columns nullable, and
 * null MEANS "any update" — so every pre-existing watcher row keeps exactly
 * its old behavior with no backfill.
 *
 *  - notify_on: null|'any' = every update (status changes + comments, the
 *    old behavior); 'status_change' = status changes only.
 *  - notify_statuses: JSON array of TO-statuses that trigger a notification
 *    when notify_on = 'status_change' (e.g. ["verifying","done"]);
 *    null = any status change. Ignored under 'any'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_task_watchers', function (Blueprint $table) {
            $table->string('notify_on', 32)->nullable()->after('user_id');
            $table->json('notify_statuses')->nullable()->after('notify_on');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_task_watchers', function (Blueprint $table) {
            $table->dropColumn(['notify_on', 'notify_statuses']);
        });
    }
};
