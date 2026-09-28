<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1018 (R14) — `type` retires. Every task's type becomes its
 * `kind:<type>` label (Task::KIND_PREFIX), then the column is dropped.
 * `$task->type` survives one release as an attribute derived from that label;
 * see UPGRADING.md.
 *
 * Idempotent: a label the task already carries is not attached twice, and a
 * second run finds no column and does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dispatch_tasks', 'type')) {
            return;
        }

        $labelIds = [];
        $labelId = function (string $name) use (&$labelIds): int {
            if (! isset($labelIds[$name])) {
                $id = DB::table('dispatch_labels')->where('name', $name)->value('id');
                $labelIds[$name] = (int) ($id ?? DB::table('dispatch_labels')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]));
            }

            return $labelIds[$name];
        };

        DB::table('dispatch_tasks')
            ->whereNotNull('type')
            ->where('type', '!=', '')
            ->select(['id', 'type'])
            ->orderBy('id')
            ->chunkById(500, function ($tasks) use ($labelId) {
                foreach ($tasks as $task) {
                    $label = $labelId('kind:'.$task->type);
                    $exists = DB::table('dispatch_task_label')->where('task_id', $task->id)->where('label_id', $label)->exists();
                    if (! $exists) {
                        DB::table('dispatch_task_label')->insert(['task_id' => $task->id, 'label_id' => $label, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            });

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->dropIndex(['type']);
        });
        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('dispatch_tasks', 'type')) {
            return;
        }

        Schema::table('dispatch_tasks', function (Blueprint $table) {
            $table->string('type')->nullable()->after('description');
            $table->index('type');
        });

        foreach (['bug', 'feature', 'chore', 'debt', 'verify'] as $kind) {
            $label = DB::table('dispatch_labels')->where('name', 'kind:'.$kind)->value('id');
            if ($label) {
                DB::table('dispatch_tasks')
                    ->whereIn('id', DB::table('dispatch_task_label')->where('label_id', $label)->select('task_id'))
                    ->whereNull('type')
                    ->update(['type' => $kind]);
            }
        }
    }
};
