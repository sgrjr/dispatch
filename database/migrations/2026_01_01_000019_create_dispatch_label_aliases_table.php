<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_label_aliases', function (Blueprint $table) {
            $table->id();
            // A label name that was REPLACED during label cleanup. It keeps
            // resolving to the canonical label it was folded into, so the next
            // `--label=<old name>` attaches/filters the canonical label instead
            // of re-minting the retired one. See LabelAlias::canonicalize().
            $table->string('name')->unique();
            $table->foreignId('label_id')->constrained('dispatch_labels')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_label_aliases');
    }
};
