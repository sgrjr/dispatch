<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dispatch_tasks', 'dedupe_key')) {
            return;
        }

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            // General-purpose idempotency key (CLI `--key`, facade `key`): one
            // task per key for its lifetime. UNIQUE so the DB arbitrates a
            // two-agents-same-instant race (nullable → many keyless tasks coexist).
            $table->string('dedupe_key')->nullable()->unique()->after('exception_signature');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('dispatch_tasks', 'dedupe_key')) {
            return;
        }

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
