<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * WS2 — the agent-session CLI verbs (dispatch:claim, dispatch:schema,
 * dispatch:session:request, dispatch:session:status, dispatch:sessions:prune).
 * The token dotfile is redirected to a throwaway temp path per test so these
 * runs never touch a real operator's ~/.dispatch/agent-token.json.
 */

beforeEach(function () {
    dispatchFakeUsers();

    $this->tokenPath = sys_get_temp_dir().'/dispatch-test-'.uniqid().'.json';

    config([
        'dispatch.agent.remote.url' => 'https://prod.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => $this->tokenPath,
    ]);
});

afterEach(function () {
    if (isset($this->tokenPath) && is_file($this->tokenPath)) {
        @unlink($this->tokenPath);
    }
});

test('dispatch:sessions:prune expires a stale approved session', function () {
    $session = AgentSession::create([
        'public_id' => 'pub-prune-1',
        'agent_name' => 'claude-ci',
        'user_code' => 'ABCDEFGH',
        'poll_secret_hash' => hash('sha256', 'secret'),
        'status' => AgentSession::STATUS_APPROVED,
        'expires_at' => now()->subMinute(),
    ]);

    Artisan::call('dispatch:sessions:prune');
    $output = Artisan::output();

    expect($output)->toContain('Expired 1 agent session(s).');
    expect($session->fresh()->status)->toBe(AgentSession::STATUS_EXPIRED);
});

test('dispatch:schema output decodes to an array containing the claimed event type', function () {
    Artisan::call('dispatch:schema');
    $output = Artisan::output();

    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray();
    expect($decoded['event_types'] ?? [])->toContain('claimed');
});

test('dispatch:claim locally claims a seeded open task', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'seeded open task', 'status' => 'open']);

    $this->artisan('dispatch:claim')->assertOk();

    expect($task->fresh()->status)->toBe('in_progress');
});

test('dispatch:session:request stores public_id/device_code and prints the user_code', function () {
    Http::fake([
        '*' => Http::response([
            'public_id' => 'pub-req-1',
            'device_code' => str_repeat('a', 64),
            'user_code' => 'ZZZZZZZZ',
            'poll_interval' => 5,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
        ], 201),
    ]);

    Artisan::call('dispatch:session:request', ['--name' => 'claude-laptop', '--secret' => 'shh']);
    $output = Artisan::output();

    expect($output)->toContain('ZZZZZZZZ');

    $stored = json_decode((string) file_get_contents($this->tokenPath), true);
    expect($stored['public_id'])->toBe('pub-req-1');
    expect($stored['device_code'])->toBe(str_repeat('a', 64));
    expect($stored['user_code'])->toBe('ZZZZZZZZ');
});

test('dispatch:session:status stores the token once the session is approved', function () {
    file_put_contents($this->tokenPath, json_encode([
        'public_id' => 'pub-status-1',
        'device_code' => str_repeat('b', 64),
    ]));

    Http::fake([
        '*' => Http::response([
            'status' => 'approved',
            'token' => 'abc',
            'poll_interval' => 5,
            'expires_at' => now()->addHour()->toIso8601String(),
        ], 200),
    ]);

    Artisan::call('dispatch:session:status');
    $output = Artisan::output();

    expect($output)->toContain('Approved');

    $stored = json_decode((string) file_get_contents($this->tokenPath), true);
    expect($stored['token'])->toBe('abc');
    expect($stored['public_id'])->toBe('pub-status-1');
});
