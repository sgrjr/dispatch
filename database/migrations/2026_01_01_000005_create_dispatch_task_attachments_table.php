<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_task_attachments', function (Blueprint $table) {
            $table->id();

            // Polymorphic: an attachment hangs off a Task OR a TaskComment.
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');

            $table->unsignedBigInteger('uploaded_by_user_id')->nullable(); // no FK: app owns users

            $table->string('disk');
            $table->string('path');                 // hashed, unguessable, on a private disk
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->boolean('is_image')->default(false);
            $table->json('meta')->nullable();       // width/height, etc.

            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_task_attachments');
    }
};
