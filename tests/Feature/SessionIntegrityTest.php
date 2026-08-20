<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * §18 W14 — session-identity integrity.
 *
 * The 🚨 6th wave made an INVOLUNTARY session death fail loud, but keyed the
 * guard on the drop marker — which is written in exactly one place, the 401
 * handler. Two production runs died another way: an actively-used session lost
 * its dotfile mid-run with no 401 and expiry hours away, so no marker was
 * written, `session:status` reported the clean NONE zero-state, and bare verbs
 * silently served the LOCAL dev DB (a batch aimed at production among them).
 *
 * These tests pin the two halves of the fix:
 *   W14-1 — a BREADCRUMB makes the TRANSITION the trigger, not the cause.
 *   W14-2 — an unreadable dotfile is its own state, not a clean absence; and
 *           the token is written atomically so it cannot become one.
 */

beforeEach(function () {
    dispatchFakeUsers();

    $this->tokenPath = sys_get_temp_dir().'/dispatch-integrity-'.uniqid().'.json';

    config([
        'dispatch.agent.remote.url' => 'https://prod.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => $this->tokenPath,
    ]);
});

afterEach(function () {
    foreach ([$this->tokenPath, $this->tokenPath.'.dropped', $this->tokenPath.'.session'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
});

/** Commission a session the way the real flow does — through storeToken(). */
function integritySeedSession(string $token = 'live-token'): void
{
    Http::fake(['*' => Http::sequence()
        ->push([
            'public_id' => 'pub-1',
            'device_code' => str_repeat('d', 64),
            'user_code' => 'CODE1234',
            'poll_interval' => 1,
        ], 201)
        ->push(['status' => 'approved', 'token' => $token, 'poll_interval' => 1], 200),
    ]);

    Artisan::call('dispatch:session:request', [
        '--name' => 'claude-integrity',
        '--purpose' => 'work the board',
        '--secret' => 'shh',
        '--wait' => '5',
    ]);
    Artisan::output();
}

// --- W14-1: the breadcrumb, and the transition it makes detectable ---------

test('a delivered token writes a session breadcrumb carrying the renewal identity', function () {
    integritySeedSession();

    expect(is_file($this->tokenPath))->toBeTrue()
        ->and(is_file($this->tokenPath.'.session'))->toBeTrue();

    $crumb = json_decode((string) file_get_contents($this->tokenPath.'.session'), true);

    expect($crumb['agent_name'])->toBe('claude-integrity')
        ->and($crumb['purpose'])->toBe('work the board')
        ->and($crumb['public_id'])->toBe('pub-1')
        ->and($crumb['base'])->toBe('https://prod.test/api/dispatch/agent')
        ->and($crumb['at'])->not->toBeNull();
});

test('a token that VANISHES with no 401 and no marker makes bare verbs fail loud, not serve local data', function () {
    integritySeedSession();
    app(DispatchTaskService::class)->create(['title' => 'local throwaway', 'status' => 'open']);

    // The field failure: the dotfile is gone, nothing announced it, and the
    // 401 path (the only writer of a drop marker) never ran.
    @unlink($this->tokenPath);
    expect(is_file($this->tokenPath.'.dropped'))->toBeFalse();

    Http::fake();
    $exit = Artisan::call('dispatch:queue', ['--json' => true]);
    $out = Artisan::output();

    expect($exit)->toBe(1)
        ->and($out)->toContain('VANISHED')
        ->and($out)->toContain('Refusing')
        ->and($out)->toContain('dispatch:session:refresh')
        // The masquerade itself: a local row must never be presented as the board.
        ->and($out)->not->toContain('local throwaway');
    Http::assertNothingSent();
});

test('--local remains the explicit override after an unexplained loss', function () {
    integritySeedSession();
    app(DispatchTaskService::class)->create(['title' => 'local throwaway', 'status' => 'open']);
    @unlink($this->tokenPath);

    Http::fake();
    $exit = Artisan::call('dispatch:queue', ['--local' => true, '--json' => true]);

    expect($exit)->toBe(0)
        ->and(dispatchJson(Artisan::output())[0]['title'])->toBe('local throwaway');
    Http::assertNothingSent();
});

test('session:end acknowledges an unexplained loss and restores local-by-default', function () {
    integritySeedSession();
    @unlink($this->tokenPath);

    Artisan::call('dispatch:session:end');

    expect(Artisan::output())->toContain('guard cleared')
        ->and(is_file($this->tokenPath.'.session'))->toBeFalse();

    Http::fake();
    expect(Artisan::call('dispatch:queue', ['--json' => true]))->toBe(0);
    Http::assertNothingSent();
});

test('a local-only write verb refuses on an unexplained loss too', function () {
    integritySeedSession();
    $task = app(DispatchTaskService::class)->create(['title' => 'Local task', 'description' => 'Untouched']);
    @unlink($this->tokenPath);

    $exit = Artisan::call('dispatch:edit', ['code' => $task->code, '--description' => 'Nope']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('vanished')
        ->and($task->fresh()->description)->toBe('Untouched');
});

test('session:refresh renews from the breadcrumb when neither dotfile nor marker survives', function () {
    // Seeded directly (not via a commissioning round-trip) so this test owns a
    // single HTTP sequence — the breadcrumb's own authorship is pinned above.
    file_put_contents($this->tokenPath.'.session', json_encode([
        'at' => '2026-08-19T14:12:48-04:00',
        'base' => 'https://prod.test/api/dispatch/agent',
        'public_id' => 'pub-1',
        'agent_name' => 'claude-integrity',
        'purpose' => 'work the board',
        'scopes' => [],
    ]));

    Http::fake(['*' => Http::sequence()
        ->push([
            'public_id' => 'pub-renewed',
            'device_code' => str_repeat('r', 64),
            'user_code' => 'RENEW999',
            'poll_interval' => 1,
        ], 201)
        ->push(['status' => 'approved', 'token' => 'renewed-token', 'poll_interval' => 1], 200),
    ]);

    Artisan::call('dispatch:session:refresh', ['--secret' => 'shh', '--wait' => '5']);
    $out = Artisan::output();

    // The approver must see WHO is renewing and why — the identity survives the
    // token's death because the breadcrumb mirrored it at approval time.
    expect($out)->toContain('breadcrumb')
        ->and($out)->toContain('claude-integrity');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'session')
            && ($request->data()['agent_name'] ?? null) === 'claude-integrity';
    });

    $stored = json_decode((string) file_get_contents($this->tokenPath), true);
    expect($stored['token'])->toBe('renewed-token');
});

test('a drop marker still wins over the breadcrumb — it carries the reason', function () {
    integritySeedSession();
    @unlink($this->tokenPath);
    file_put_contents($this->tokenPath.'.dropped', json_encode([
        'reason' => 'revoked or expired (agent API returned 401)',
        'at' => '2026-08-19T00:00:00Z',
    ]));

    Http::fake();
    $exit = Artisan::call('dispatch:queue', ['--json' => true]);
    $out = Artisan::output();

    expect($exit)->toBe(1)
        ->and($out)->toContain('dropped mid-run')
        ->and($out)->toContain('revoked or expired')
        ->and($out)->not->toContain('VANISHED');
});

// --- W14-2: an unreadable dotfile is a STATE, and writes are atomic --------

test('a truncated token file reads as UNREADABLE, not as a clean zero-state', function () {
    integritySeedSession();
    $good = (string) file_get_contents($this->tokenPath);
    file_put_contents($this->tokenPath, substr($good, 0, (int) (strlen($good) * 0.6)));

    $exit = Artisan::call('dispatch:session:status');
    $out = Artisan::output();

    expect($exit)->toBe(1)
        ->and($out)->toContain('does NOT parse')
        // The whole point: name the file, because "no token" is a claim about a
        // path resolved from ambient env that two shells can disagree about.
        ->and($out)->toContain($this->tokenPath);
});

test('an unreadable token file makes bare verbs refuse, naming the file rather than the session', function () {
    integritySeedSession();
    app(DispatchTaskService::class)->create(['title' => 'local throwaway', 'status' => 'open']);
    file_put_contents($this->tokenPath, '{"token": "half-writ');

    Http::fake();
    $exit = Artisan::call('dispatch:queue', ['--json' => true]);
    $out = Artisan::output();

    expect($exit)->toBe(1)
        ->and($out)->toContain('UNREADABLE')
        ->and($out)->not->toContain('local throwaway');
    Http::assertNothingSent();
});

test('the NONE zero-state names the path it searched', function () {
    $exit = Artisan::call('dispatch:session:status');
    $out = Artisan::output();

    expect($exit)->toBe(0)
        ->and($out)->toContain('No active token and no pending')
        ->and($out)->toContain($this->tokenPath);
});

test('storeToken writes atomically: no temp file survives and the payload is whole', function () {
    integritySeedSession();

    $siblings = glob($this->tokenPath.'.tmp*') ?: [];

    expect($siblings)->toBe([])
        ->and(json_decode((string) file_get_contents($this->tokenPath), true))
        ->toHaveKeys(['token', 'public_id', 'agent_name', 'purpose']);
});

// --- W14-2(c): doctor can finally SEE the dotfile -------------------------

test('doctor flags an unreadable token file as an error and names the path', function () {
    integritySeedSession();
    file_put_contents($this->tokenPath, 'not json at all');

    Artisan::call('dispatch:doctor');
    $out = Artisan::output();

    expect($out)->toContain('agent_token')
        ->and($out)->toContain('does NOT parse')
        ->and($out)->toContain($this->tokenPath);
});

test('doctor flags a vanished session (breadcrumb, no marker) and reports a healthy token otherwise', function () {
    integritySeedSession();

    Artisan::call('dispatch:doctor');
    expect(Artisan::output())->toContain('Agent session token present');

    @unlink($this->tokenPath);

    Artisan::call('dispatch:doctor');
    $out = Artisan::output();

    expect($out)->toContain('no 401 was recorded')
        ->and($out)->toContain('dispatch:session:refresh');
});
