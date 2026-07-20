<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Sgrjr\Dispatch\Tests\Fixtures\User as FixtureUser;
use Sgrjr\Dispatch\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/**
 * Seed an agent-session token file at the CONFIGURED token path. With sticky
 * remote, a present token flips bare verbs to the remote target — so tests
 * seed it only when they mean "an active commissioned session exists" (remote
 * verb tests + the sticky cases), never as ambient fixture state.
 */
function seedAgentToken(string $token = 'test-remote-token'): void
{
    $path = config('dispatch.agent.remote.token_path');
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, json_encode(['token' => $token]));
}

/**
 * Decode the JSON portion of a command's captured output. In production the
 * side-channel (target banner / next-step hints) goes to real STDERR and
 * stdout stays pure — but the test harness merges both into one buffer, so
 * this extracts first-brace → last-brace before decoding.
 */
function dispatchJson(string $out): mixed
{
    $start = min(array_filter([strpos($out, '{'), strpos($out, '[')], fn ($v) => $v !== false) ?: [0]);
    $end = max((int) strrpos($out, '}'), (int) strrpos($out, ']'));

    return json_decode(substr($out, $start, $end - $start + 1), true);
}

/**
 * Point dispatch at a real, notifiable test User model and stand up a `users`
 * table. Feature tests that touch watchers / notifications call this in setup
 * (the host app supplies the real user model in production; Testbench has none).
 */
function dispatchFakeUsers(): void
{
    config(['dispatch.models.user' => FixtureUser::class]);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }
}

/**
 * Create a persisted test user (and the users table + binding) with a fixed id.
 */
function dispatchMakeUser(int $id, array $attributes = []): FixtureUser
{
    dispatchFakeUsers();

    return FixtureUser::query()->create(array_merge([
        'id' => $id,
        'name' => "User {$id}",
        'email' => "user{$id}@example.test",
    ], $attributes));
}
