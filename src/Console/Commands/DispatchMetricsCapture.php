<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;

/**
 * SessionStart hook target: reads the hook JSON on stdin and writes the current
 * Claude Code session's transcript_path / session_id / cwd to a sidecar file so
 * `dispatch:metrics` can pin the exact transcript instead of guessing.
 *
 * Discovery in {@see \Sgrjr\Dispatch\Support\TranscriptLocator} works without
 * this sidecar (it derives the transcript dir from cwd and picks the newest
 * file), so this hook is a robustness upgrade — it pins the session when several
 * run against the same project. It must never break session start: every failure
 * is swallowed and the command always exits 0.
 *
 * Wire it up in .claude/settings.json:
 *   { "hooks": { "SessionStart": [ { "hooks": [
 *       { "type": "command", "command": "php artisan dispatch:metrics:capture" }
 *   ] } ] } }
 */
class DispatchMetricsCapture extends Command
{
    protected $signature = 'dispatch:metrics:capture';

    protected $description = 'Internal: record the current session transcript path for metrics (SessionStart hook target).';

    protected $hidden = true;

    public function handle(): int
    {
        try {
            // Guard against blocking on a TTY if run by hand (hooks pipe stdin).
            if (function_exists('stream_isatty') && @stream_isatty(STDIN)) {
                return self::SUCCESS;
            }

            $raw = stream_get_contents(STDIN);
            if (! is_string($raw) || $raw === '') {
                return self::SUCCESS;
            }

            $payload = json_decode($raw, true);
            if (! is_array($payload)) {
                return self::SUCCESS;
            }

            $this->write(array_filter([
                'transcript_path' => $payload['transcript_path'] ?? null,
                'session_id' => $payload['session_id'] ?? null,
                'cwd' => $payload['cwd'] ?? null,
                'hook_event' => $payload['hook_event_name'] ?? null,
            ], fn ($v) => $v !== null));
        } catch (\Throwable) {
            // Never break session start over metrics bookkeeping.
        }

        return self::SUCCESS;
    }

    /**
     * Persist the sidecar. Public + array-based so it's unit-testable without
     * driving stdin.
     *
     * @param  array<string,mixed>  $data
     */
    public function write(array $data): void
    {
        $path = (string) config('dispatch.metrics.session_file');
        if ($path === '') {
            return;
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
