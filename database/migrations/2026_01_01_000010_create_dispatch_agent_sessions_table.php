<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_agent_sessions', function (Blueprint $table) {
            $table->id();

            // Opaque id the agent references in poll/URLs — NEVER expose the
            // autoincrement id (IDOR). Unique.
            $table->uuid('public_id')->unique();

            $table->string('agent_name');
            $table->text('purpose')->nullable();

            // RFC-8628 display code the approver eye-matches against what the
            // requesting agent printed ("did you initiate this?").
            $table->string('user_code')->index();

            // sha256 of the once-returned device_code; required on every poll so
            // guessing public_id alone can't steal the token.
            $table->string('poll_secret_hash')->unique();

            $table->json('requested_meta')->nullable();
            $table->json('scopes')->nullable(); // per-session verb allowlist (subset of agent.verbs)

            // pending | approved | denied | revoked | expired
            $table->string('status')->default('pending')->index();

            // sha256 of the session token; NULL until the first approved poll.
            $table->string('token_hash')->nullable()->unique();
            $table->timestamp('token_delivered_at')->nullable(); // enforces once-only delivery

            $table->unsignedBigInteger('approved_by_user_id')->nullable(); // no FK: app owns users
            $table->timestamp('approved_at')->nullable();

            // Meaning flips: pending-approval window before approval, session TTL after.
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamp('last_used_at')->nullable();
            $table->string('ip', 45)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_agent_sessions');
    }
};
