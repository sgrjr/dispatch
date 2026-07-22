<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_labels', function (Blueprint $table) {
            // Per-label facet override. Null means "inherit from the namespace
            // map" (dispatch.labels.namespace_kinds, keyed on the name prefix) —
            // see Label::effectiveKind(). An explicit 'elevated'/'meta' here
            // wins over the namespace default for this one label.
            $table->string('kind')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_labels', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
