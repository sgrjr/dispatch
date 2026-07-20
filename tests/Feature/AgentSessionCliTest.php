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

test('dispatch:claim {code} claims that specific task even behind a higher-priority one', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'top', 'status' => 'open', 'priority' => 'blocker']);
    $target = $svc->create(['title' => 'wanted', 'status' => 'open', 'priority' => 'low']);

    $this->artisan('dispatch:claim', ['code' => $target->code])
        ->expectsOutputToContain("Claimed {$target->code}")
        ->assertOk();

    expect($target->fresh()->status)->toBe('in_progress');
});

test('dispatch:claim {code} exits non-zero when the named task is already claimed', function () {
    $target = app(DispatchTaskService::class)->create(['title' => 'busy', 'status' => 'in_progress']);

    $this->artisan('dispatch:claim', ['code' => $target->code])->assertExitCode(1);
});

test('dispatch:claim {code} exits non-zero for an unknown code', function () {
    $this->artisan('dispatch:claim', ['code' => 'TASK-999999'])
        ->expectsOutputToContain('No task TASK-999999.')
        ->assertExitCode(1);
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

test('dispatch:session:request with no --scope OMITS the scopes key (full-allowlist request, not deny-all)', function () {
    Http::fake([
        '*' => Http::response([
            'public_id' => 'pub-req-2',
            'device_code' => str_repeat('c', 64),
            'user_code' => 'YYYYYYYY',
        ], 201),
    ]);

    Artisan::call('dispatch:session:request', ['--name' => 'claude', '--secret' => 'shh']);

    // The server treats an ABSENT `scopes` key as "approver grants the full
    // allowlist" but an explicit [] as deny-all — the client used to always
    // send the key, silently turning the documented default into deny-all.
    Http::assertSent(fn ($request) => ! array_key_exists('scopes', $request->data()));

    expect(Artisan::output())->toContain('full grantable verb set');
});

test('dispatch:session:request with --scope sends exactly the narrowed set', function () {
    Http::fake([
        '*' => Http::response([
            'public_id' => 'pub-req-3',
            'device_code' => str_repeat('d', 64),
            'user_code' => 'XXXXXXXX',
        ], 201),
    ]);

    Artisan::call('dispatch:session:request', ['--name' => 'claude', '--secret' => 'shh', '--scope' => ['next', 'show']]);

    Http::assertSent(fn ($request) => ($request->data()['scopes'] ?? null) === ['next', 'show']);
});

test('dispatch:session:request --wait is the one-shot commissioning: request, poll, collect the token', function () {
    // First hit: the session request (201). Then the delegated status polls:
    // pending once, then approved with a token — one command end to end.
    Http::fake(['*' => Http::sequence()
        ->push([
            'public_id' => 'pub-oneshot-1',
            'device_code' => str_repeat('f', 64),
            'user_code' => 'WWWWWWWW',
            'poll_interval' => 1,
        ], 201)
        ->push(['status' => 'pending', 'poll_interval' => 1], 200)
        ->push(['status' => 'approved', 'token' => 'oneshot-token', 'poll_interval' => 1], 200),
    ]);

    $exit = Artisan::call('dispatch:session:request', ['--name' => 'claude', '--secret' => 'shh', '--wait' => '5']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('WWWWWWWW')     // code shown before blocking
        ->and($output)->toContain('Approved');

    $stored = json_decode((string) file_get_contents($this->tokenPath), true);
    expect($stored['token'])->toBe('oneshot-token');
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

test('dispatch:session:status --wait polls in-process and collects the token when approval lands', function () {
    file_put_contents($this->tokenPath, json_encode([
        'public_id' => 'pub-wait-1',
        'device_code' => str_repeat('e', 64),
    ]));

    // First poll: still pending. Second poll: approved with a token.
    Http::fake(['*' => Http::sequence()
        ->push(['status' => 'pending', 'poll_interval' => 1], 200)
        ->push(['status' => 'approved', 'token' => 'waited-token', 'poll_interval' => 1], 200),
    ]);

    Artisan::call('dispatch:session:status', ['--wait' => '5']);
    $output = Artisan::output();

    expect($output)->toContain('Approved');
    $stored = json_decode((string) file_get_contents($this->tokenPath), true);
    expect($stored['token'])->toBe('waited-token');
});

test('dispatch:session:status surfaces a CA-bundle hint on a TLS/connection failure (DX)', function () {
    file_put_contents($this->tokenPath, json_encode([
        'public_id' => 'pub-tls-1',
        'device_code' => str_repeat('f', 64),
    ]));

    Http::fake(['*' => function () {
        throw new \Illuminate\Http\Client\ConnectionException('cURL error 60: unable to get local issuer certificate');
    }]);

    $exit = Artisan::call('dispatch:session:status');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('TLS verification failed')
        ->and($output)->toContain('cacert.pem');
});

test('dispatch:session:request hints at a stale config cache on a bootstrap 401 (DX)', function () {
    Http::fake(['*' => Http::response('Invalid bootstrap secret.', 401)]);

    $exit = Artisan::call('dispatch:session:request', ['--name' => 'x', '--secret' => 'wrong']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('config:clear');
});

test('dispatch:session:end revokes remotely and clears the local token (GAP 5)', function () {
    file_put_contents($this->tokenPath, json_encode([
        'public_id' => 'pub-end-1',
        'device_code' => str_repeat('d', 64),
        'token' => 'live-token',
    ]));

    Http::fake([
        '*' => Http::response(['ended' => true, 'status' => 'revoked', 'public_id' => 'pub-end-1'], 200),
    ]);

    Artisan::call('dispatch:session:end');
    $output = Artisan::output();

    expect($output)->toContain('Session ended');
    Http::assertSent(fn ($req) => str_ends_with($req->url(), '/session/end') && $req->method() === 'POST');
    expect(is_file($this->tokenPath))->toBeFalse();  // local token cleared
});

test('dispatch:session:end with no local token is a graceful no-op (GAP 5)', function () {
    Artisan::call('dispatch:session:end');

    expect(Artisan::output())->toContain('nothing to end');
});

test('the client falls back to DISPATCH_AGENT_REMOTE_URL when merged config lacks agent.remote (GAP 3)', function () {
    // Simulate a host whose published config predates agent.remote: mergeConfigFrom
    // is a shallow array_merge, so the nested key never merged and the resolved
    // config value is null even though the env var is set.
    config(['dispatch.agent.remote.url' => null]);

    $envUrl = 'https://env-fallback.test/api/dispatch/agent';
    putenv("DISPATCH_AGENT_REMOTE_URL={$envUrl}");
    $_ENV['DISPATCH_AGENT_REMOTE_URL'] = $envUrl;
    $_SERVER['DISPATCH_AGENT_REMOTE_URL'] = $envUrl;

    Http::fake(['*' => Http::response([
        'public_id' => 'pub-env-1',
        'device_code' => str_repeat('c', 64),
        'user_code' => 'ENVCODE1',
    ], 201)]);

    Artisan::call('dispatch:session:request', ['--name' => 'x', '--secret' => 'shh']);

    // The request reached the env-derived base URL, not "No agent remote configured".
    Http::assertSent(fn ($req) => str_starts_with($req->url(), $envUrl.'/session'));

    putenv('DISPATCH_AGENT_REMOTE_URL');
    unset($_ENV['DISPATCH_AGENT_REMOTE_URL'], $_SERVER['DISPATCH_AGENT_REMOTE_URL']);
});
