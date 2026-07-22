<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_focuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Steering axes as JSON — stores ONLY the constrained axes (a UI
            // serializer omits an axis meaning "all"); see Focus::applyTo().
            $table->json('filters')->nullable();
            $table->integer('rank')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_focuses');
    }
};
