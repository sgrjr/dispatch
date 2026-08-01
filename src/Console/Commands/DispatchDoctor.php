<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchBatchService;

/**
 * Diagnose agent config drift — the recurring "stale published config silently
 * disables a shipped capability" trap (GAP-3 / GAP-6). A host publishes
 * `config/dispatch.php` once and never re-publishes (doctrine); because
 * `mergeConfigFrom` shallow-merges, any `agent.*` key the package adds later is
 * absent from that host's published file. The env()/package-default fallbacks
 * now *tolerate* that drift; this command *surfaces* it so an operator fixes the
 * root cause (re-publish + `optimize:clear`) instead of chasing a silent 403/401.
 *
 * Read-only. Compares the LIVE (merged/published) `dispatch.agent.*` config
 * against the package's canonical defaults, plus semantic checks on the
 * load-bearing keys (bootstrap_secret, verbs vs KNOWN_VERBS, enabled, batch cap,
 * remote target, config cache). Exit non-zero on any ERROR (e.g. no
 * bootstrap_secret in production); `--strict` also fails on warnings.
 */
class DispatchDoctor extends Command
{
    use TalksToAgentApi;

    protected $signature = 'dispatch:doctor
        {--strict : Exit non-zero on warnings too, not just errors (for CI)}
        {--json : Emit machine-readable JSON instead of a human report}';

    protected $description = 'Diagnose dispatch agent config drift (stale published config vs package defaults).';

    /** @var array<int,array{level:string,check:string,message:string}> */
    protected array $findings = [];

    public function handle(): int
    {
        $env = $this->laravel->environment();
        $cached = (bool) $this->laravel->configurationIsCached();

        $enabled = (bool) config('dispatch.agent.enabled', env('DISPATCH_AGENT', false));

        $this->checkEnabled($enabled);
        $this->checkBootstrapSecret($enabled, $env);
        $this->checkVerbs();
        $this->checkBatchCap();
        $this->checkRemote($env);
        $this->checkDroppedSession();
        $this->checkTouchTime();
        $this->checkConfigCache($cached);
        $this->checkKeyDrift();
        $this->checkPublishedAssetDrift();

        return $this->option('json') ? $this->reportJson($env, $enabled) : $this->reportHuman($env, $enabled);
    }

    protected function add(string $level, string $check, string $message): void
    {
        $this->findings[] = ['level' => $level, 'check' => $check, 'message' => $message];
    }

    protected function checkEnabled(bool $enabled): void
    {
        $enabled
            ? $this->add('ok', 'enabled', 'Agent API enabled — routes served at /api/dispatch/agent/*.')
            : $this->add('info', 'enabled', 'Agent API disabled. Set DISPATCH_AGENT=true (or dispatch.agent.enabled) to serve the agent surface. Fine to leave off on a consumer/dev host.');
    }

    protected function checkBootstrapSecret(bool $enabled, string $env): void
    {
        $secret = config('dispatch.agent.bootstrap_secret') ?? env('DISPATCH_AGENT_BOOTSTRAP_SECRET');
        $set = is_string($secret) && $secret !== '';

        if ($set) {
            $this->add('ok', 'bootstrap_secret', 'Bootstrap secret is configured (session-request endpoint is gated).');

            return;
        }

        if ($enabled && $env === 'production') {
            $this->add('error', 'bootstrap_secret', 'No bootstrap_secret in production — VerifyBootstrapSecret fails closed, so POST agent/session returns 503 and no agent can commission a session. Set DISPATCH_AGENT_BOOTSTRAP_SECRET.');

            return;
        }

        $this->add('info', 'bootstrap_secret', 'No bootstrap_secret set — the session-request endpoint is open. Acceptable on a trusted/local network; set DISPATCH_AGENT_BOOTSTRAP_SECRET to enforce (required in production).');
    }

    protected function checkVerbs(): void
    {
        $known = AgentSessionService::KNOWN_VERBS;
        $verbs = array_map('strval', (array) config('dispatch.agent.verbs', $known));
        $missing = array_values(array_diff($known, $verbs));

        if ($missing !== []) {
            $this->add('warn', 'verbs', 'Published agent.verbs is missing shipped verb(s): '.implode(', ', $missing).'. An explicit --scope=<verb> is still grantable (KNOWN_VERBS union, GAP-6), but a session commissioned with NO explicit scopes will not receive them. Re-publish --tag=dispatch-config to list every shipped verb.');
        } else {
            $this->add('ok', 'verbs', 'agent.verbs lists all '.count($known).' shipped verbs.');
        }

        $disabled = array_values(array_intersect(
            array_map('strval', (array) config('dispatch.agent.disabled_verbs', [])),
            $known
        ));
        if ($disabled !== []) {
            $this->add('info', 'disabled_verbs', 'Withholding shipped verb(s) via agent.disabled_verbs: '.implode(', ', $disabled).' (intentional denylist — this is the supported way to withhold one).');
        }
    }

    protected function checkBatchCap(): void
    {
        $max = (int) config('dispatch.agent.batch.max_operations', (int) env('DISPATCH_AGENT_BATCH_MAX', 200));

        if ($max <= 0) {
            $this->add('warn', 'batch.max_operations', 'agent.batch.max_operations is 0 (uncapped) — a single POST agent/batch can write an unbounded number of ops. Set a cap on a public instance.');
        } else {
            $this->add('ok', 'batch.max_operations', "Batch cap: {$max} operations/request.");
        }

        // Op-count never bounded SIZE — the gap that let an oversized manifest
        // die below the app with an opaque 500 instead of a named 422.
        $bytes = DispatchBatchService::maxPayloadBytes();

        if ($bytes <= 0) {
            $this->add('warn', 'batch.max_payload_bytes', 'agent.batch.max_payload_bytes is 0 (uncapped) — an oversized manifest is left to die at the web server body limit (post_max_size), where no dispatch error can reach the caller. Set a cap under that limit.');

            return;
        }

        // A cap at or above the server's own limit can never fire first, which
        // defeats the point: the caller still gets the opaque failure.
        $postMax = $this->bytesFromIni((string) ini_get('post_max_size'));

        if ($postMax > 0 && $bytes >= $postMax) {
            $this->add('warn', 'batch.max_payload_bytes', "Batch byte cap ({$bytes}) is at or above PHP post_max_size ({$postMax}) — an oversized manifest dies below the app with an opaque error before the legible 422 can fire. Lower agent.batch.max_payload_bytes beneath post_max_size (or raise post_max_size).");

            return;
        }

        $this->add('ok', 'batch.max_payload_bytes', "Batch byte cap: {$bytes} bytes/request".($postMax > 0 ? " (under post_max_size {$postMax})" : '').'.');
    }

    /**
     * Published-ASSET drift (W9-6) — the blind spot beside config drift.
     *
     * `vendor:publish` COPIES files out of the package; from then on the two
     * copies drift silently in both directions and nothing reports it. Both
     * directions are hazards, and they are different hazards:
     *
     *   - published OLDER than the package: the host is running a stale asset
     *     and a fix that shipped upstream is simply not live, with no signal.
     *   - published NEWER / hand-edited: a `vendor:publish --force` will
     *     OVERWRITE it. When the hand-edit is a hotfix applied ahead of a
     *     release, that republish silently reintroduces the bug it fixed.
     *
     * Only the trees a host is meant to track byte-for-byte are checked. Views
     * are excluded on purpose — overriding them is their entire point, so drift
     * there is a feature, not a finding. Config has its own key-drift check.
     */
    protected function checkPublishedAssetDrift(): void
    {
        $trees = [
            'dispatch-vue' => [__DIR__.'/../../../resources/js', resource_path('js/vendor/dispatch')],
            'dispatch-assets' => [__DIR__.'/../../../resources/dist', public_path('vendor/dispatch')],
        ];

        foreach ($trees as $tag => [$source, $published]) {
            if (! is_dir($source)) {
                continue;   // package tree absent (partial checkout) — nothing to compare
            }

            if (! is_dir($published)) {
                $this->add('info', "assets.{$tag}", "Not published (no {$published}) — fine if this host doesn't use the {$tag} tag.");

                continue;
            }

            $drifted = [];
            $missing = [];

            foreach ($this->filesUnder($source) as $relative => $absolute) {
                $target = $published.DIRECTORY_SEPARATOR.$relative;

                if (! is_file($target)) {
                    $missing[] = $relative;

                    continue;
                }

                // Compare CONTENT, normalising line endings: a Windows host's
                // CRLF checkout is not drift, and flagging it would train the
                // operator to ignore this check.
                if ($this->normalizedHash($absolute) !== $this->normalizedHash($target)) {
                    $drifted[] = $relative;
                }
            }

            if ($drifted === [] && $missing === []) {
                $this->add('ok', "assets.{$tag}", 'Published assets match the installed package.');

                continue;
            }

            $parts = [];
            if ($drifted !== []) {
                $parts[] = count($drifted).' differ ('.implode(', ', array_slice($drifted, 0, 3)).(count($drifted) > 3 ? ', …' : '').')';
            }
            if ($missing !== []) {
                $parts[] = count($missing).' missing ('.implode(', ', array_slice($missing, 0, 3)).(count($missing) > 3 ? ', …' : '').')';
            }

            $this->add('warn', "assets.{$tag}", 'Published assets drift from the installed package: '.implode('; ', $parts).
                ". If the PUBLISHED copy is the stale one, re-publish: vendor:publish --tag={$tag} --force. ".
                'If it carries a hand-applied fix the installed package does NOT have yet (a hotfix ahead of a release), do NOT --force — that would overwrite the fix with the older vendor copy.');
        }
    }

    /**
     * Relative-path => absolute-path map of every file under a directory.
     *
     * @return array<string,string>
     */
    private function filesUnder(string $dir): array
    {
        $out = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace($dir, '', $file->getPathname()), '\\/');
            $out[str_replace('\\', '/', $relative)] = $file->getPathname();
        }

        return $out;
    }

    /** Content hash with line endings normalised, so CRLF/LF is never "drift". */
    private function normalizedHash(string $path): string
    {
        $contents = (string) @file_get_contents($path);

        return md5(str_replace(["\r\n", "\r"], "\n", $contents));
    }

    /** "8M" / "512K" / "1024" -> bytes; 0 when unlimited or unparseable. */
    private function bytesFromIni(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1' || $value === '0') {
            return 0;   // unlimited (or unset) — nothing to compare against
        }

        $unit = strtolower(substr($value, -1));
        $n = (int) $value;

        return match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }

    protected function checkRemote(string $env): void
    {
        $url = config('dispatch.agent.remote.url') ?: env('DISPATCH_AGENT_REMOTE_URL');

        if (! is_string($url) || $url === '') {
            $this->add('info', 'remote.url', 'No remote configured (dispatch.agent.remote.url / DISPATCH_AGENT_REMOTE_URL). `--remote` verbs fail fast — only needed on a box that DRIVES a remote/production instance.');

            return;
        }

        $isHttps = str_starts_with(strtolower($url), 'https://');
        $isLocal = $env === 'local' || (bool) preg_match('#://(localhost|127\.0\.0\.1)([:/]|$)#i', $url);

        if (! $isHttps && ! $isLocal) {
            $this->add('warn', 'remote.url', "Remote target is not HTTPS ({$url}). `--remote` refuses a non-HTTPS endpoint outside local — a bearer token over plaintext would undo the commissioning model.");
        } else {
            $this->add('ok', 'remote.url', "Remote target: {$url}");
        }
    }

    /**
     * Client-state check: a lingering drop marker means a commissioned session
     * died involuntarily (mid-run 401 / denied / revoked / expired) and neither
     * a renewal nor an acknowledgment has happened — bare verbs are failing
     * loud instead of falling back to the local DB. Surfaced only when present.
     */
    protected function checkDroppedSession(): void
    {
        $marker = $this->sessionDropMarker();
        if ($marker === null) {
            return;
        }

        $this->add('warn', 'dropped_session', 'A previous agent session was dropped — '.($marker['reason'] ?? 'unknown reason').' at '.($marker['at'] ?? '?').'. Bare verbs refuse the silent local fallback until `dispatch:session:refresh --wait` renews it (human approves) or `dispatch:session:end` acknowledges it.');
    }

    /**
     * The est.-human-time tile (TouchTime) is render-guarded by
     * `metrics.touch_time` and hides SILENTLY when the block is absent — the
     * exact GAP-3/6 shallow-merge trap: a host `metrics` array published
     * before v0.6.0 swallows the package default wholesale. config()->has()
     * distinguishes that drift from an intentional `'touch_time' => null`
     * (the documented way to hide the figure).
     */
    protected function checkTouchTime(): void
    {
        if (! $this->laravel['config']->has('dispatch.metrics.touch_time')) {
            $this->add('warn', 'metrics.touch_time', 'Published metrics block predates touch_time — the shallow mergeConfigFrom drops the package default, so the "est. human time" figure is silently hidden everywhere (task page, dispatch:show). Copy the touch_time block from the package config (or re-publish --tag=dispatch-config); see UPGRADING.md.');

            return;
        }

        $tt = config('dispatch.metrics.touch_time');

        if ($tt === null) {
            $this->add('info', 'metrics.touch_time', 'touch_time is explicitly null — the est. human time figure is hidden on purpose (documented opt-out).');

            return;
        }

        if (! is_array($tt) || ! is_string($tt['version'] ?? null) || ($tt['version'] ?? '') === '' || ! is_array($tt['base_minutes'] ?? null)) {
            $this->add('warn', 'metrics.touch_time', 'touch_time block is present but invalid (needs a string `version` and a `base_minutes` array) — an unversioned figure never renders. Compare against the package config/dispatch.php.');

            return;
        }

        $this->add('ok', 'metrics.touch_time', "touch_time config present (version {$tt['version']}) — the est. human time figure renders.");
    }

    protected function checkConfigCache(bool $cached): void
    {
        if ($cached) {
            $this->add('warn', 'config_cache', 'Config is CACHED. If you recently published config or rotated the bootstrap secret, a stale cache serves the OLD values — run `php artisan config:clear` (and recycle php-fpm/OPcache). This is the root cause behind most drift incidents; see UPGRADING.md.');
        } else {
            $this->add('ok', 'config_cache', 'Config is not cached (edits/env take effect immediately).');
        }
    }

    /**
     * General drift scan: which canonical agent.* keys the published config
     * omits (beyond the ones already checked semantically above). Informational —
     * the env()/package-default fallbacks cover these — but it tells an operator
     * exactly what a re-publish would restore host control over.
     */
    protected function checkKeyDrift(): void
    {
        $packageAgent = $this->packageDefaultAgent();
        if ($packageAgent === []) {
            return; // can't locate the package config; skip rather than mislead
        }

        $covered = ['enabled', 'bootstrap_secret', 'verbs', 'disabled_verbs', 'batch', 'remote'];
        $missing = [];

        foreach ($packageAgent as $key => $default) {
            if (in_array($key, $covered, true)) {
                continue;
            }
            if (! $this->liveAgentHas($key)) {
                $missing[] = $key;
            }
        }
        // remote.* sub-keys (checkRemote only reads url)
        foreach (['url', 'token_path'] as $sub) {
            if (! $this->liveAgentHas("remote.{$sub}")) {
                $missing[] = "remote.{$sub}";
            }
        }

        if ($missing !== []) {
            $this->add('info', 'key_drift', 'Published config omits agent.* key(s): '.implode(', ', $missing).' — running on package defaults/env. Harmless (fallbacks cover them); re-publish --tag=dispatch-config to regain host overrides.');
        }
    }

    protected function liveAgentHas(string $dotted): bool
    {
        // config()->has() treats a present-null value as present, which is what we
        // want: a key the published file DECLARES (even as null) isn't "drift".
        return $this->laravel['config']->has("dispatch.agent.{$dotted}");
    }

    /** @return array<string,mixed> */
    protected function packageDefaultAgent(): array
    {
        $path = __DIR__.'/../../../config/dispatch.php';
        if (! is_file($path)) {
            return [];
        }
        $config = require $path;

        return is_array($config) && isset($config['agent']) && is_array($config['agent']) ? $config['agent'] : [];
    }

    protected function reportHuman(string $env, bool $enabled): int
    {
        $labels = [
            'ok' => '<info>  ok  </info>',
            'info' => '<fg=gray> info </>',
            'warn' => '<comment> warn </comment>',
            'error' => '<error> FAIL </error>',
        ];

        $this->line('');
        $this->line("Dispatch agent config — environment: <options=bold>{$env}</>, surface: <options=bold>".($enabled ? 'enabled' : 'disabled').'</>');
        $this->line('');

        foreach ($this->findings as $f) {
            $tag = $labels[$f['level']] ?? $f['level'];
            $this->line("{$tag}  <options=bold>{$f['check']}</>  {$f['message']}");
        }

        $counts = $this->counts();
        $this->line('');
        $this->line("Summary: {$counts['ok']} ok · {$counts['info']} info · <comment>{$counts['warn']} warn</comment> · <error>{$counts['error']} error</error>");

        return $this->exitCode($counts);
    }

    protected function reportJson(string $env, bool $enabled): int
    {
        $counts = $this->counts();

        $this->line(json_encode([
            'ok' => $counts['error'] === 0 && ! ($this->option('strict') && $counts['warn'] > 0),
            'environment' => $env,
            'agent_enabled' => $enabled,
            'findings' => $this->findings,
            'summary' => $counts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->exitCode($counts);
    }

    /** @return array{ok:int,info:int,warn:int,error:int} */
    protected function counts(): array
    {
        $counts = ['ok' => 0, 'info' => 0, 'warn' => 0, 'error' => 0];
        foreach ($this->findings as $f) {
            $counts[$f['level']] = ($counts[$f['level']] ?? 0) + 1;
        }

        return $counts;
    }

    /** @param array{ok:int,info:int,warn:int,error:int} $counts */
    protected function exitCode(array $counts): int
    {
        if ($counts['error'] > 0) {
            return self::FAILURE;
        }
        if ($this->option('strict') && $counts['warn'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
