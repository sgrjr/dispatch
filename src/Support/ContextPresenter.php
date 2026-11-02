<?php

namespace Sgrjr\Dispatch\Support;

/**
 * Shapes the two human-readable parts of a task's `context` blob into
 * display-ready arrays, so every surface (the board's task page, a host's
 * chat card, the CLI) reads the SAME shape instead of re-deriving it:
 *
 *  - closeOut():    what an agent wrote under `context.result` when it closed
 *                   the task — summary, the owner's walkthrough (owner_check),
 *                   follow-ups it did not build, tests, commit, deploy.
 *  - diagnostics(): the evidence a task was FILED with — an exception and its
 *                   trace (exception capture), and/or a browser capture
 *                   (console errors, url, viewport, user agent).
 *
 * DB-free and container-free: both take a plain array and return null when
 * there is nothing to show, so a caller's "render the panel?" is a null check.
 * Everything returned is plain strings/lists — escaping is the renderer's job
 * (Blade's {{ }}, Vue's text interpolation); nothing here is HTML.
 */
class ContextPresenter
{
    /** The prose fields of a close-out, in display order. */
    public const CLOSE_OUT_TEXT = ['summary', 'resolution', 'tests', 'deploy', 'commit'];

    /** The list fields of a close-out, in display order. */
    public const CLOSE_OUT_LISTS = ['owner_check', 'follow_ups_not_built'];

    /**
     * @param  array<string,mixed>|null  $context  A task's `context` attribute.
     * @return array{summary:?string,resolution:?string,tests:?string,deploy:?string,commit:?string,owner_check:list<string>,follow_ups_not_built:list<string>}|null
     */
    public static function closeOut(?array $context): ?array
    {
        $result = is_array($context['result'] ?? null) ? $context['result'] : [];

        $out = [];
        foreach (self::CLOSE_OUT_TEXT as $key) {
            $out[$key] = self::text($result[$key] ?? null);
        }
        foreach (self::CLOSE_OUT_LISTS as $key) {
            $out[$key] = self::lines($result[$key] ?? null);
        }

        $hasAny = array_filter($out, fn ($v) => $v !== null && $v !== []) !== [];

        return $hasAny ? $out : null;
    }

    /**
     * @param  array<string,mixed>|null  $context  A task's `context` attribute.
     * @return array<string,mixed>|null
     */
    public static function diagnostics(?array $context): ?array
    {
        $context = is_array($context) ? $context : [];

        $exception = null;
        if (is_array($context['exception'] ?? null) && $context['exception'] !== []) {
            $e = $context['exception'];
            $exception = [
                'class' => self::text($e['class'] ?? null),
                'message' => self::text($e['message'] ?? null),
                'file' => self::text($e['file'] ?? null),
                'line' => isset($e['line']) && is_scalar($e['line']) ? (string) $e['line'] : null,
                'app_frame' => self::frame($e['app_frame'] ?? null),
            ];
        }

        $trace = self::lines($context['trace'] ?? null);

        // A host's browser-error capture (DispatchTask::bug() with the page's
        // context): the error type, where it was caught, the page, and its
        // stack — a string of newline-separated frames, or a list.
        $stack = is_string($context['stack'] ?? null)
            ? self::lines(preg_split('/\R/', $context['stack']) ?: [])
            : self::lines($context['stack'] ?? null);
        $clientError = array_filter([
            'error_type' => self::text($context['error_type'] ?? null),
            'severity' => self::text($context['severity'] ?? null),
            'info' => self::text($context['info'] ?? null),
            'inertia_page' => self::text($context['inertia_page'] ?? null),
            'component' => self::text($context['component_path'] ?? null) ?? self::text($context['component'] ?? null),
        ], fn ($v) => $v !== null);
        if ($stack !== []) {
            $clientError['stack'] = $stack;
        }
        $clientError = $clientError === [] ? null : $clientError;

        $consoleErrors = [];
        foreach ((is_array($context['console_errors'] ?? null) ? $context['console_errors'] : []) as $err) {
            if (! is_array($err)) {
                continue;
            }
            $consoleErrors[] = [
                'type' => self::text($err['type'] ?? null) ?? 'error',
                'message' => self::text($err['message'] ?? null),
                'source' => self::text($err['source'] ?? null),
                'stack' => self::text($err['stack'] ?? null),
            ];
        }

        $viewport = is_array($context['viewport'] ?? null) ? $context['viewport'] : null;
        $capture = array_filter([
            'url' => self::text($context['url'] ?? null),
            'referrer' => self::text($context['referrer'] ?? null),
            'route' => self::text($context['route'] ?? null),
            'method' => self::text($context['method'] ?? null),
            'viewport' => $viewport
                ? ($viewport['w'] ?? '?').'×'.($viewport['h'] ?? '?').' (dpr '.($viewport['dpr'] ?? 1).')'
                : null,
            'user_agent' => self::text($context['user_agent'] ?? null),
            'captured_at' => self::text($context['captured_at'] ?? null),
        ], fn ($v) => $v !== null);

        $occurrences = array_filter([
            'times_seen' => isset($context['times_seen']) && is_numeric($context['times_seen']) ? (int) $context['times_seen'] : null,
            'first_seen' => self::text($context['first_seen'] ?? null),
            'last_seen' => self::text($context['last_seen'] ?? null),
        ], fn ($v) => $v !== null);

        if ($exception === null && $trace === [] && $clientError === null && $consoleErrors === [] && $capture === []) {
            return null;
        }

        return [
            // The evidence test the "carries diagnostic context" notice rides on
            // (Task::hasDiagnostics): an exception, a trace, a client error, or
            // ≥1 console error. A capture of a healthy page (a user agent, zero
            // errors) is context, not a diagnosis.
            'has_evidence' => $exception !== null || $trace !== [] || isset($clientError['error_type']) || isset($clientError['stack']) || $consoleErrors !== [],
            'exception' => $exception,
            'trace' => $trace,
            'client_error' => $clientError,
            'console_errors' => $consoleErrors,
            'capture' => $capture,
            'occurrences' => $occurrences,
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (is_bool($value) || ! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** A string or a list of strings → a list of non-empty strings. @return list<string> */
    private static function lines(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $text = is_array($item) ? self::text($item['text'] ?? $item['step'] ?? null) : self::text($item);
            if ($text !== null) {
                $out[] = $text;
            }
        }

        return $out;
    }

    private static function frame(mixed $frame): ?string
    {
        if (is_array($frame)) {
            $file = self::text($frame['file'] ?? null);

            return $file === null ? null : $file.(isset($frame['line']) ? ':'.$frame['line'] : '');
        }

        return self::text($frame);
    }
}
