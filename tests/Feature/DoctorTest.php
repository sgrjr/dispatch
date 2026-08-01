<?php

use Illuminate\Support\Facades\Artisan;

/*
 * dispatch:doctor — the agent config-drift diagnostic (GAP-3/GAP-6). The test
 * harness (TestCase::defineEnvironment) sets dispatch.agent.enabled=true and the
 * package config loads the full agent block, so the baseline is a clean bill.
 */

function doctorRun(array $opts = []): array
{
    $exit = Artisan::call('dispatch:doctor', $opts + ['--json' => true]);

    return [$exit, json_decode(Artisan::output(), true)];
}

function doctorFinding(array $out, string $check): ?array
{
    foreach ($out['findings'] as $f) {
        if ($f['check'] === $check) {
            return $f;
        }
    }

    return null;
}

test('dispatch:doctor on the package defaults reports no errors and all verbs present', function () {
    [$exit, $out] = doctorRun();

    expect($exit)->toBe(0)
        ->and($out['agent_enabled'])->toBeTrue()
        ->and($out['summary']['error'])->toBe(0)
        ->and($out['ok'])->toBeTrue()
        ->and(doctorFinding($out, 'verbs')['level'])->toBe('ok');
});

test('dispatch:doctor warns when a shipped verb is missing from the published allowlist (GAP-6)', function () {
    // A host whose published agent.verbs predates `batch` being shipped.
    config(['dispatch.agent.verbs' => ['next', 'queue', 'show', 'add', 'note', 'done', 'claim']]);

    [$exit, $out] = doctorRun();

    $verbs = doctorFinding($out, 'verbs');
    expect($verbs['level'])->toBe('warn')
        ->and($verbs['message'])->toContain('batch')
        ->and($exit)->toBe(0);  // a warning alone doesn't fail the exit code
});

test('dispatch:doctor --strict fails the exit code on warnings', function () {
    config(['dispatch.agent.verbs' => ['next', 'claim']]);

    [$exit, $out] = doctorRun(['--strict' => true]);

    expect($exit)->toBe(1)
        ->and($out['summary']['warn'])->toBeGreaterThan(0);
});

test('dispatch:doctor errors (exit 1) when production has no bootstrap_secret', function () {
    $this->app['env'] = 'production';
    config(['dispatch.agent.enabled' => true, 'dispatch.agent.bootstrap_secret' => null]);

    [$exit, $out] = doctorRun();

    expect(doctorFinding($out, 'bootstrap_secret')['level'])->toBe('error')
        ->and($out['ok'])->toBeFalse()
        ->and($exit)->toBe(1);
});

test('dispatch:doctor is ok when a bootstrap_secret is configured, and never leaks the value', function () {
    config(['dispatch.agent.bootstrap_secret' => 'super-secret-value']);

    [, $out] = doctorRun();

    $finding = doctorFinding($out, 'bootstrap_secret');
    expect($finding['level'])->toBe('ok')
        ->and($finding['message'])->not->toContain('super-secret-value');
});

test('dispatch:doctor warns on a non-HTTPS remote target outside local', function () {
    $this->app['env'] = 'production';
    config(['dispatch.agent.remote.url' => 'http://insecure.example.test/api/dispatch/agent']);

    [, $out] = doctorRun();

    expect(doctorFinding($out, 'remote.url')['level'])->toBe('warn');
});

test('dispatch:doctor reports touch_time ok on the package defaults', function () {
    [, $out] = doctorRun();

    $finding = doctorFinding($out, 'metrics.touch_time');
    expect($finding['level'])->toBe('ok')
        ->and($finding['message'])->toContain('v1');
});

test('dispatch:doctor warns when a published metrics block predates touch_time (GAP-3 shallow-merge drift)', function () {
    // A host `metrics` array published before v0.6.0: the key is ABSENT (not
    // present-null), so mergeConfigFrom drops the package default wholesale.
    $metrics = config('dispatch.metrics');
    unset($metrics['touch_time']);
    config(['dispatch.metrics' => $metrics]);

    [$exit, $out] = doctorRun();

    $finding = doctorFinding($out, 'metrics.touch_time');
    expect($finding['level'])->toBe('warn')
        ->and($finding['message'])->toContain('est. human time')
        ->and($exit)->toBe(0);  // a warning alone doesn't fail the exit code
});

test('dispatch:doctor treats an explicit null touch_time as the documented opt-out, not drift', function () {
    config(['dispatch.metrics.touch_time' => null]);

    [, $out] = doctorRun();

    expect(doctorFinding($out, 'metrics.touch_time')['level'])->toBe('info');
});

test('dispatch:doctor warns on a touch_time block missing its version or base_minutes', function () {
    config(['dispatch.metrics.touch_time' => ['base_minutes' => ['default' => 10]]]);

    [, $out] = doctorRun();

    $finding = doctorFinding($out, 'metrics.touch_time');
    expect($finding['level'])->toBe('warn')
        ->and($finding['message'])->toContain('version');
});

test('dispatch:doctor flags a stale published config that omits agent keys', function () {
    // Simulate a published agent block that predates several keys: drop them so
    // the key is ABSENT (not present-null), the real GAP-3 shape.
    $agent = config('dispatch.agent');
    unset($agent['session_ttl'], $agent['poll_interval']);
    config(['dispatch.agent' => $agent]);

    [, $out] = doctorRun();

    $drift = doctorFinding($out, 'key_drift');
    expect($drift)->not->toBeNull()
        ->and($drift['level'])->toBe('info')
        ->and($drift['message'])->toContain('session_ttl')
        ->and($drift['message'])->toContain('poll_interval');
});

/*
 * W9-6 — published-ASSET drift. Config drift had a check; asset drift did not,
 * which is how a hand-synced hotfix can be silently overwritten by a
 * `vendor:publish --force` that looks routine.
 */

test('doctor reports published assets that are not published at all (W9-6)', function () {
    $out = Artisan::call('dispatch:doctor', ['--json' => true]);
    expect($out)->toBeIn([0, 1]);

    $findings = collect(json_decode(Artisan::output(), true)['findings'] ?? []);
    $vue = $findings->firstWhere('check', 'assets.dispatch-vue');

    // Testbench has no published vendor JS, so the honest report is "not
    // published" at info level — never a warning, which would be noise.
    expect($vue)->not->toBeNull()
        ->and($vue['level'])->toBe('info')
        ->and($vue['message'])->toContain('Not published');
});

test('doctor warns when a published asset drifts from the package copy (W9-6)', function () {
    $published = resource_path('js/vendor/dispatch');
    @mkdir($published, 0777, true);
    file_put_contents($published.'/dispatchConsole.js', '// a hand-edited hotfix the vendor copy does not have');

    Artisan::call('dispatch:doctor', ['--json' => true]);
    $findings = collect(json_decode(Artisan::output(), true)['findings'] ?? []);
    $vue = $findings->firstWhere('check', 'assets.dispatch-vue');

    expect($vue['level'])->toBe('warn')
        ->and($vue['message'])->toContain('dispatchConsole.js')
        // Both directions must be named — a bare "re-publish to fix" would be
        // exactly the advice that destroys an ahead-of-release hotfix.
        ->and($vue['message'])->toContain('do NOT --force');

    array_map('unlink', glob($published.'/*'));
    @rmdir($published);
});

test('doctor treats a CRLF-only difference as in-sync, not drift (W9-6)', function () {
    $source = __DIR__.'/../../resources/js';
    $published = resource_path('js/vendor/dispatch');
    @mkdir($published, 0777, true);

    foreach (glob($source.'/*') as $file) {
        // Same bytes, Windows line endings — a checkout artifact, not a change.
        $crlf = str_replace("\n", "\r\n", str_replace("\r\n", "\n", (string) file_get_contents($file)));
        file_put_contents($published.'/'.basename($file), $crlf);
    }

    Artisan::call('dispatch:doctor', ['--json' => true]);
    $findings = collect(json_decode(Artisan::output(), true)['findings'] ?? []);

    expect($findings->firstWhere('check', 'assets.dispatch-vue')['level'])->toBe('ok');

    array_map('unlink', glob($published.'/*'));
    @rmdir($published);
});
