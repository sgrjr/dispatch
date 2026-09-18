<?php

namespace Sgrjr\Dispatch;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Sgrjr\Dispatch\Contracts\SubmitterResolver;
use Sgrjr\Dispatch\Jobs\CreateDispatchTask;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\Anchor;
use Throwable;

/**
 * Backs the DispatchTask facade. The manager owns the cheap, must-happen-now
 * work — environment gating, per-signature throttling, and gathering request/
 * console context while it still exists — then hands the create to a queueable
 * job (sync by default, queued via config). It NEVER throws: a failure here
 * (especially when called from an exception handler) returns null instead.
 */
class DispatchManager
{
    public function __construct(
        protected SubmitterResolver $submitters,
    ) {}

    /**
     * The simple, straightforward entry point.
     *
     * @param  array<string,mixed>  $options  type, priority, description,
     *         labels[], public, context[], key (dedupe), signature, submitter,
     *         topic / origin ("<type>[:<id>]" anchor strings) and
     *         conversation (int) — see the anchor fields (TASK-995)
     */
    public function report(string $title, array $options = []): ?Task
    {
        return $this->dispatchTask($title, $options);
    }

    public function bug(string $title, array $options = []): ?Task
    {
        return $this->report($title, ['type' => 'bug'] + $options);
    }

    public function feature(string $title, array $options = []): ?Task
    {
        return $this->report($title, ['type' => 'feature'] + $options);
    }

    /**
     * File a deduped bug task from a caught throwable — the exception-handler
     * entry point. Derives the title, a stable signature, and rich context.
     */
    public function fromException(Throwable $e, array $options = []): ?Task
    {
        $options['type'] ??= 'bug';
        // Where the task came FROM (TASK-995): an exception, unless the caller
        // says otherwise. No id — the signature already identifies the error.
        $options['origin'] ??= 'exception';
        $options['signature'] ??= $this->signatureFor($e);
        $options['labels'] = array_values(array_unique(array_merge(
            $options['labels'] ?? [],
            [(string) config('dispatch.reporter.exception_label', 'source:exception')],
        )));
        $options['context'] = array_merge($this->exceptionContext($e), $options['context'] ?? []);
        // Only when the caller didn't write one — and only on the initial
        // create, since capture() never rewrites an existing task's body.
        $options['description'] ??= $this->describeException($e);

        $title = $options['title'] ?? $this->titleFor($e);
        unset($options['title']);

        return $this->dispatchTask($title, $options);
    }

    protected function dispatchTask(string $title, array $options): ?Task
    {
        try {
            if (! $this->enabled()) {
                return null;
            }

            $signature = $options['signature'] ?? ($options['key'] ?? null);

            if ($signature !== null && $this->throttled((string) $signature)) {
                return null;
            }

            // Per-call override of the auto server/console context capture, so a
            // caller supplying its own context (e.g. a frontend-error endpoint
            // forwarding the real page's context) can suppress this request's noise.
            $captureRequest = (bool) ($options['capture_request']
                ?? config('dispatch.reporter.capture_request', true));

            $attributes = [
                'title' => $title,
                'type' => $options['type'] ?? 'bug',
                'priority' => $options['priority'] ?? 'medium',
                'status' => $options['status'] ?? 'triage',
                'description' => $options['description'] ?? null,
                'is_public' => (bool) ($options['public'] ?? false),
                // Reporter tasks are SYSTEM artifacts (auto-filed bugs, host
                // facade calls) — always staff-visible (W13-5). Without this,
                // an exception captured under a staff member's session would
                // default to their participants circle and hide the bug from
                // the rest of the team.
                'visibility' => $options['visibility'] ?? Task::VISIBILITY_STAFF,
                // Capture the submitter NOW — a queued job has no auth context.
                'submitter_user_id' => $options['submitter'] ?? $this->submitters->currentUserId(),
                'context' => array_merge($this->baseContext($captureRequest), $options['context'] ?? []),
            ];

            $attributes += $this->anchorAttributes($options);

            $labels = (array) ($options['labels'] ?? []);

            if ($this->shouldQueue()) {
                $pending = CreateDispatchTask::dispatch($attributes, $labels, $signature);
                if ($conn = config('dispatch.reporter.connection')) {
                    $pending->onConnection($conn);
                }
                if (is_string($queue = config('dispatch.reporter.queue'))) {
                    $pending->onQueue($queue);
                }

                return null; // created asynchronously
            }

            // Sync: run the job's handler inline so we can return the Task.
            // (dispatchSync() on a ShouldQueue job runs it but does not surface
            // the handler's return value.)
            return (new CreateDispatchTask($attributes, $labels, $signature))
                ->handle(app(DispatchTaskService::class));
        } catch (Throwable $e) {
            // Never throw from the reporter. Best-effort log, then swallow.
            try {
                logger()->warning('DispatchTask reporter failed: '.$e->getMessage());
            } catch (Throwable $ignored) {
                // ignore
            }

            return null;
        }
    }

    /**
     * The anchor options (TASK-995) as task attributes: `topic` / `origin` are
     * "<type>[:<id>]" strings through Anchor::parse (the one parser), and
     * `conversation` is the home conversation id. A malformed anchor is logged
     * and DROPPED — never the report itself: losing an exception report over a
     * bad topic string would be the worse failure.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    protected function anchorAttributes(array $options): array
    {
        $attributes = [];

        foreach (['topic', 'origin'] as $anchor) {
            if (empty($options[$anchor])) {
                continue;
            }

            try {
                [$attributes[$anchor.'_type'], $attributes[$anchor.'_id']] = Anchor::parse((string) $options[$anchor]);
            } catch (Throwable $e) {
                try {
                    logger()->warning("DispatchTask reporter dropped a malformed {$anchor}: ".$e->getMessage());
                } catch (Throwable $ignored) {
                    // ignore
                }
            }
        }

        if (! empty($options['conversation'])) {
            $attributes['conversation_id'] = (int) $options['conversation'];
        }

        return $attributes;
    }

    protected function enabled(): bool
    {
        if (! config('dispatch.reporter.enabled', true)) {
            return false;
        }

        $envs = config('dispatch.reporter.environments');
        if (is_array($envs) && ! empty($envs) && ! in_array(app()->environment(), $envs, true)) {
            return false;
        }

        return true;
    }

    protected function shouldQueue(): bool
    {
        $queue = config('dispatch.reporter.queue', false);

        return $queue !== false && $queue !== null && $queue !== '';
    }

    protected function throttled(string $signature): bool
    {
        $seconds = (int) config('dispatch.reporter.throttle_seconds', 60);
        if ($seconds <= 0) {
            return false;
        }

        $key = 'dispatch:reporter:throttle:'.sha1($signature);
        if (Cache::has($key)) {
            return true;
        }

        Cache::put($key, 1, $seconds);

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    protected function baseContext(bool $captureRequest = true): array
    {
        $ctx = ['captured_at' => now()->toIso8601String()];

        // Caller opted out (it's supplying its own context) — no server noise.
        if (! $captureRequest) {
            return $ctx;
        }

        if (app()->runningInConsole()) {
            $ctx['source'] = 'console';
            $argv = $_SERVER['argv'] ?? [];
            $ctx['command'] = trim(implode(' ', array_slice((array) $argv, 1)));

            return $ctx;
        }

        $request = request();
        if ($request !== null && $request->method()) {
            $ctx['source'] = 'http';
            $ctx['url'] = $request->fullUrl();
            $ctx['method'] = $request->method();
            $ctx['route'] = optional($request->route())->getName();
            $ctx['ip'] = $request->ip();
            $ctx['user_id'] = Auth::id();
            $ctx['input'] = $this->redact($request->all());
        }

        return $ctx;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function redact(array $data): array
    {
        $redact = array_map('strtolower', (array) config('dispatch.reporter.redact', []));

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $redact, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    protected function exceptionContext(Throwable $e): array
    {
        $frames = (int) config('dispatch.reporter.trace_frames', 20);

        return [
            'exception' => [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'code' => $e->getCode(),
            ],
            'trace' => collect($e->getTrace())
                ->take($frames)
                ->map(fn ($f) => ($f['file'] ?? '[internal]').':'.($f['line'] ?? '?').' '
                    .($f['class'] ?? '').($f['type'] ?? '').($f['function'] ?? '').'()')
                ->all(),
            'times_seen' => 1,
            'first_seen' => now()->toIso8601String(),
            'last_seen' => now()->toIso8601String(),
        ];
    }

    /**
     * Build the task BODY for an exception-sourced task.
     *
     * Without this, an auto-filed exception task carries a title and nothing
     * else — and the title is necessarily short (truncated at 120 chars by
     * titleFor), so the one thing that identifies the bug is clipped. Everything
     * else sat in the raw `context` JSON, which no reader of the board sees.
     *
     * So the body carries the two things that actually identify an exception:
     * the FULL message, and enough stack to place it.
     *
     * Deliberately NOT the whole trace — that's noise. The meat is near the top
     * but rarely in the first frame or two (those are usually the generic throw
     * site), so the leading frames are kept rather than skipped, and application
     * frames are marked with `»` so the eye lands on the app's own code instead
     * of the vendor scaffolding between it. Paths are relativized to the app
     * root because the deploy prefix is the single noisiest thing in a Windows
     * stack trace.
     */
    protected function describeException(Throwable $e): string
    {
        $parts = ['**'.class_basename($e).'** in `'
            .$this->relativePath($e->getFile()).':'.$e->getLine().'`'];

        // Fenced rather than inline: a raw message is full of backslashes,
        // underscores and asterisks that markdown would otherwise eat.
        $message = trim($e->getMessage());
        if ($message !== '') {
            $limit = (int) config('dispatch.reporter.description_message_chars', 4000);
            $parts[] = "```\n".($limit > 0 ? Str::limit($message, $limit) : $message)."\n```";
        }

        // A wrapped exception (a QueryException around a PDOException, say)
        // hides the real cause a level down. Surface the chain compactly.
        $causes = [];
        $previous = $e->getPrevious();
        while ($previous !== null && count($causes) < 3) {
            $causes[] = '- `'.class_basename($previous).'` at `'
                .$this->relativePath($previous->getFile()).':'.$previous->getLine().'` — '
                .Str::limit(trim($previous->getMessage()), 200);
            $previous = $previous->getPrevious();
        }
        if ($causes !== []) {
            $parts[] = "**Caused by**\n".implode("\n", $causes);
        }

        $lines = $this->traceLines($e, (int) config('dispatch.reporter.description_frames', 30));
        if ($lines !== []) {
            // Say plainly whether frames were dropped — "first 20 frames" on a
            // 20-frame trace reads as though there were more to find.
            $total = count($e->getTrace());
            $parts[] = '**Stack** — '.(count($lines) < $total ? 'first '.count($lines).' of '.$total : $total)
                .' frames, `»` marks application code:';
            $parts[] = "```\n".implode("\n", $lines)."\n```";
        }

        return implode("\n\n", $parts);
    }

    /**
     * The trimmed, app-marked frame list used by describeException().
     *
     * @return array<int,string>
     */
    protected function traceLines(Throwable $e, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return collect($e->getTrace())
            ->take($limit)
            ->map(function (array $frame): string {
                $path = $this->relativePath((string) ($frame['file'] ?? ''));
                $isApp = $path !== '' && ! str_starts_with($path, 'vendor/');
                $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '').'()';

                return ($isApp ? '» ' : '  ')
                    .($path !== '' ? $path.':'.($frame['line'] ?? '?') : '[internal]')
                    .'  '.$call;
            })
            ->all();
    }

    /**
     * Strip the application root from an absolute path. The deploy prefix
     * ("C:\inetpub\wwwroot\sites\production_customer\staff\") is pure noise in
     * a task body, and dropping it is what makes a `vendor/` frame obvious at a
     * glance. Case-insensitive because Windows hands back inconsistent drive
     * casing; returns the path unchanged when it isn't under the app root.
     */
    protected function relativePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $normalized = str_replace('\\', '/', $path);

        try {
            $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        } catch (Throwable) {
            return $normalized;
        }

        return stripos($normalized, $base) === 0
            ? substr($normalized, strlen($base))
            : $normalized;
    }

    protected function signatureFor(Throwable $e): string
    {
        // Location-based: the same throw site groups occurrences together.
        return sha1(get_class($e).'|'.$e->getFile().':'.$e->getLine());
    }

    protected function titleFor(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return class_basename($e).($message !== '' ? ': '.Str::limit($message, 120) : '');
    }
}
