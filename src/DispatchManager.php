<?php

namespace Sgrjr\Dispatch;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Sgrjr\Dispatch\Contracts\SubmitterResolver;
use Sgrjr\Dispatch\Jobs\CreateDispatchTask;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;
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
     *         labels[], public, context[], key (dedupe), signature, submitter
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
        $options['signature'] ??= $this->signatureFor($e);
        $options['labels'] = array_values(array_unique(array_merge(
            $options['labels'] ?? [],
            [(string) config('dispatch.reporter.exception_label', 'source:exception')],
        )));
        $options['context'] = array_merge($this->exceptionContext($e), $options['context'] ?? []);

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

            $attributes = [
                'title' => $title,
                'type' => $options['type'] ?? 'bug',
                'priority' => $options['priority'] ?? 'medium',
                'status' => $options['status'] ?? 'triage',
                'description' => $options['description'] ?? null,
                'is_public' => (bool) ($options['public'] ?? false),
                // Capture the submitter NOW — a queued job has no auth context.
                'submitter_user_id' => $options['submitter'] ?? $this->submitters->currentUserId(),
                'context' => array_merge($this->baseContext(), $options['context'] ?? []),
            ];

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
    protected function baseContext(): array
    {
        $ctx = ['captured_at' => now()->toIso8601String()];

        if (app()->runningInConsole()) {
            $ctx['source'] = 'console';
            $argv = $_SERVER['argv'] ?? [];
            $ctx['command'] = trim(implode(' ', array_slice((array) $argv, 1)));

            return $ctx;
        }

        if (config('dispatch.reporter.capture_request', true)) {
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
