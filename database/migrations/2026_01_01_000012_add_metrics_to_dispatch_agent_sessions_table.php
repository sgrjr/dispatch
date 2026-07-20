<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_agent_sessions', function (Blueprint $table) {
            // Session-anchored agent-run metrics: the client computes them from
            // its local transcript at `dispatch:session:end` and POSTs them with
            // the end call — the one step of the protocol with a forcing
            // function (surrendering the credential), unlike the per-task
            // `done --with-metrics` which is easy to drop mid-run.
            $table->json('metrics')->nullable();

            // When the session actually ended (self-end or human revoke) —
            // expires_at only records when it WOULD have died.
            $table->timestamp('ended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_agent_sessions', function (Blueprint $table) {
            $table->dropColumn(['metrics', 'ended_at']);
        });
    }
};
