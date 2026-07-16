<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable(); // no FK: app owns users
            $table->text('body')->nullable();

            $table->boolean('is_internal')->default(false);
            $table->boolean('notified_submitter')->default(false); // de-branded from rupkeep's sent_to_customer

            // comment|status_change|assignee_change|label_added|label_removed
            // |is_public_toggle|promoted|exception_occurrence
            $table->string('event_type')->default('comment');
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['task_id', 'created_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
    }
};
