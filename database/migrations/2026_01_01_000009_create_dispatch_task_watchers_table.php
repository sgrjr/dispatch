<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_task_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('dispatch_tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id'); // no FK: app owns users

            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_task_watchers');
    }
};
