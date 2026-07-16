<?php

namespace Sgrjr\Dispatch\Support;

/**
 * Renders task/comment body text to safe HTML.
 *
 * A security surface: unrendered/failed input is always HTML-escaped, and
 * commonmark is configured to escape raw HTML input and disallow unsafe
 * link schemes. `dispatch.markdown.enabled` false (or empty/null $text)
 * skips markdown parsing entirely and falls back to a plain
 * nl2br(e($text)) render.
 */
class Markdown
{
    public static function render(?string $text): string
    {
        if (! config('dispatch.markdown.enabled', true) || $text === null || $text === '') {
            return nl2br(e((string) $text));
        }

        try {
            $converter = new \League\CommonMark\CommonMarkConverter([
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
            ]);

            return $converter->convert($text)->getContent();
        } catch (\Throwable) {
            return nl2br(e($text));
        }
    }
}
