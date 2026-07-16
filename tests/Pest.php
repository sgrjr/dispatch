<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Sgrjr\Dispatch\Tests\Fixtures\User as FixtureUser;
use Sgrjr\Dispatch\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

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
