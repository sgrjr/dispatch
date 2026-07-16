<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();          // e.g. TASK-001 — race-safe minting
            $table->string('title');
            $table->longText('description')->nullable();

            $table->string('type')->default('feature'); // bug|feature|chore|debt|verify
            $table->string('priority')->default('medium'); // blocker|high|medium|low
            $table->string('status')->default('triage'); // triage|open|in_progress|verifying|done|declined

            $table->boolean('is_public')->default(false);
            $table->integer('position')->default(0);     // board ordering within a column

            // Generic user references — no FK constraint: the app owns the users
            // table and the package must install without assuming one exists.
            $table->unsignedBigInteger('submitter_user_id')->nullable();
            $table->unsignedBigInteger('assignee_user_id')->nullable();

            $table->string('exception_signature')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('priority');
            $table->index('type');
            $table->index('position');
            $table->index('submitter_user_id');
            $table->index('assignee_user_id');
            $table->index('exception_signature');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_tasks');
    }
};
