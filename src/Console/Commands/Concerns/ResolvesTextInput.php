<?php

namespace Sgrjr\Dispatch\Console\Commands\Concerns;

/**
 * Shared "inline value OR a file / stdin" resolution for the long-text and JSON
 * command options (dispatch:done --result[-file], dispatch:add
 * --description[-file], dispatch:note body / --body-file). The file path is the
 * escape hatch for content too long or too quote-heavy to sit safely on one
 * command line — the same guidance this repo's CLAUDE.md gives for commit
 * messages ("write it to a file, then -F").
 */
trait ResolvesTextInput
{
    /**
     * Resolve a text value from EITHER an inline value OR a file path (with `-`
     * meaning stdin). Exactly one source may be given.
     *
     * A file path resolves as given (absolute or cwd-relative) first, then falls
     * back to the app base path — an agent's file usually lives in its cwd.
     *
     * Returns a [value, error] pair so the caller keeps its own
     * `$this->error(...); return self::FAILURE;` flow:
     *   - success: [string|null, null] — value is null only when neither source
     *     is given and $required is false.
     *   - failure: [null, "message"] — both given, file missing, unreadable, or
     *     required-but-absent.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function resolveInlineOrFile(
        ?string $inline,
        ?string $file,
        string $inlineLabel,
        string $fileLabel,
        bool $required = false,
    ): array {
        if ($inline !== null && $file !== null) {
            return [null, "Use either {$inlineLabel} or {$fileLabel}, not both."];
        }

        if ($file !== null) {
            if ($file === '-') {
                $contents = file_get_contents('php://stdin');
            } else {
                $resolved = is_file($file) ? $file : base_path($file);
                if (! is_file($resolved)) {
                    return [null, "{$fileLabel} not found: {$file}"];
                }
                $contents = file_get_contents($resolved);
            }

            if ($contents === false) {
                return [null, "Could not read {$fileLabel} input."];
            }

            return [$contents, null];
        }

        if ($inline === null && $required) {
            return [null, "Provide {$inlineLabel} or {$fileLabel}."];
        }

        return [$inline, null];
    }
}
