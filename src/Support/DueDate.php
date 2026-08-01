<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Support\Carbon;

/**
 * THE tri-state reading of a due-date input, shared by every write surface that
 * accepts one: batch ops, the agent endpoints, and the `--due` CLI flags.
 *
 * Three states, and keeping the first two apart is the entire point:
 *   - key ABSENT       → leave the stored value untouched. Only the CALLER can
 *                        see whether the key was there, so absence is checked
 *                        with array_key_exists() at the call site, never here.
 *   - `null` or `""`   → clear to null.
 *   - any other string → must parse; anything else fails fast, before a write.
 *
 * `null` and `""` are deliberately the same input. Over HTTP the host app's
 * ConvertEmptyStringsToNull middleware rewrites a sent `""` to `null` before a
 * controller ever sees it, while a locally-read batch manifest or a CLI option
 * keeps the literal `""` — treating them identically is what lets ONE rule hold
 * on both paths.
 *
 * Parsing happens on the CALLER'S clock, which is why a relative input like
 * `+3 days` resolves where it was written (the agent's box) rather than
 * drifting to whenever the server got around to applying it.
 */
class DueDate
{
    /**
     * Is this value the clear sentinel? Callers must ask this BEFORE parsing:
     * `Carbon::parse('')` quietly returns "now", which would set a due date
     * where the caller asked to remove one.
     */
    public static function isClear(mixed $raw): bool
    {
        return $raw === null || (is_string($raw) && trim($raw) === '');
    }

    /**
     * @throws \InvalidArgumentException when $raw is not a parseable date. The
     *                                   message names the wire field and the
     *                                   offending input; a caller with its own
     *                                   voice (an operation index, a CLI flag
     *                                   name) wraps or replaces it.
     */
    public static function parseOrFail(mixed $raw): Carbon
    {
        if (is_string($raw) && trim($raw) !== '') {
            try {
                return Carbon::parse(trim($raw));
            } catch (\Throwable) {
                // Fall through to the one shared message below.
            }
        }

        throw new \InvalidArgumentException(
            '`due_at` could not be parsed as a date: '.self::describe($raw).
            '. Send an ISO 8601 date (e.g. 2026-08-15), or null/"" to clear it.'
        );
    }

    /**
     * The value to STORE, for a caller that has already established the key is
     * present: null for a clear, a Carbon otherwise.
     *
     * @throws \InvalidArgumentException on an unparseable date.
     */
    public static function resolve(mixed $raw): ?Carbon
    {
        return self::isClear($raw) ? null : self::parseOrFail($raw);
    }

    /**
     * Render an unparseable input for an error message. A structured value
     * (an array/object an agent sent by mistake) reports its TYPE — casting it
     * to string here would raise the PHP warning that Laravel promotes to an
     * ErrorException, replacing a legible message with a bare crash.
     */
    private static function describe(mixed $raw): string
    {
        if (is_string($raw)) {
            return trim($raw);
        }

        return is_object($raw) ? get_class($raw) : get_debug_type($raw);
    }
}
