<?php

namespace Sgrjr\Dispatch\Support;

use InvalidArgumentException;

/**
 * The shipped Claude Code skills as TEMPLATES, rendered for one host
 * (`dispatch:skills:publish`).
 *
 * Claude Code reads skills from the project's own `.claude/skills`, never from
 * vendor/, so a host has always had to copy them in — and every host fact
 * (its name, its production URL, where it mounts the admin pages, which lane
 * code work belongs to) got hand-edited into the copy, where the next package
 * improvement could not reach it without clobbering it. Now the package ships
 * templates and the host owns only DATA:
 *
 *   {{ var }}                                  a value from `dispatch.skills.vars`
 *   <!-- dispatch:if var --> … [<!-- dispatch:else --> …] <!-- dispatch:endif -->
 *                                              kept when the var is non-empty
 *   <!-- dispatch:slot name -->                the host's overlay file
 *                                              `<overlays>/<skill>/<name>.md`, or nothing
 *
 * (Markers sit alone on their line; blocks do not nest.) Change the config or
 * an overlay, re-publish, and the skill is current — a collaborative pipeline
 * rather than a fork. A rendered skill is stamped with a fingerprint right
 * after its frontmatter, so the publisher can tell a STALE copy (safe to
 * re-render) from a HAND-EDITED one (refused without --force: move the edit
 * into config or an overlay).
 */
class SkillPublisher
{
    public const UP_TO_DATE = 'up_to_date';

    public const STALE = 'stale';

    public const HAND_EDITED = 'hand_edited';

    public const UNMANAGED = 'unmanaged';

    public const MISSING = 'missing';

    /** The skills the package ships as templates (the dispatch-skills set). */
    public const SKILLS = ['dispatch-track', 'dispatch-agent-session', 'dispatch-batch-migrate'];

    private const STAMP_PREFIX = '<!-- Rendered by `php artisan dispatch:skills:publish`';

    /** @return string[] */
    public function skills(): array
    {
        $configured = config('dispatch.skills.publish');

        return is_array($configured) && $configured !== [] ? array_values($configured) : self::SKILLS;
    }

    /**
     * The template variables: the package defaults, then the host's
     * `dispatch.skills.vars`. `code_lane` falls back to `agent.lane` (the lane
     * a commissioned session serves), so a host that already routes agents to
     * its developer lane gets the filing rule with no new config.
     *
     * @return array<string, string>
     */
    public function vars(): array
    {
        $defaults = [
            'app_name' => 'this app',
            'remote_host' => '',
            'package_path' => 'vendor/sgrjr/dispatch',
            'agent_sessions_path' => '/'.trim((string) config('dispatch.routes.prefix', 'dispatch'), '/').'/agent-sessions',
            'focuses_path' => '/'.trim((string) config('dispatch.routes.prefix', 'dispatch'), '/').'/focuses',
            'labels_path' => '/'.trim((string) config('dispatch.routes.prefix', 'dispatch'), '/').'/labels',
            'code_lane' => (string) (config('dispatch.agent.lane') ?? ''),
            // The priorities that EMAIL the people a task concerns — so an agent
            // knows filing at one of them is a loud act, not a default.
            'loud_priorities' => implode(', ', array_map(fn ($p) => '`'.$p.'`', (array) config('dispatch.notifications.email_priorities', []))),
        ];

        $vars = array_map(fn ($v) => (string) $v, array_merge($defaults, array_filter((array) config('dispatch.skills.vars', []), fn ($v) => $v !== null)));

        // Derived — so a template never string-mangles a value itself.
        $vars['remote_url'] = $vars['remote_host'] !== '' ? 'https://'.$vars['remote_host'] : 'https://<production-host>';
        $vars['code_lane_department'] = $vars['code_lane'] !== '' ? explode(':', $vars['code_lane'], 2)[0] : '';

        return $vars;
    }

    public function templatePath(string $skill): string
    {
        return dirname(__DIR__, 2).'/.claude/skills/'.$skill.'/SKILL.md';
    }

    public function targetPath(string $skill): string
    {
        return rtrim((string) config('dispatch.skills.target', base_path('.claude/skills')), '/\\').'/'.$skill.'/SKILL.md';
    }

    public function overlayDir(): string
    {
        return rtrim((string) config('dispatch.skills.overlays', base_path('.claude/dispatch-skills')), '/\\');
    }

    /** The skill, rendered for this host and stamped. */
    public function render(string $skill): string
    {
        $template = @file_get_contents($this->templatePath($skill));
        if ($template === false) {
            throw new InvalidArgumentException("No shipped skill template named `{$skill}`.");
        }

        return $this->stamp($skill, $this->renderString(self::lf($template), $this->vars(), $skill));
    }

    /**
     * Render a template string (no stamp). Public for tests.
     *
     * @param  array<string, string>  $vars
     */
    public function renderString(string $template, array $vars, string $skill = ''): string
    {
        // A note for people editing the TEMPLATE — never part of the skill.
        $template = (string) preg_replace('/^<!-- dispatch:template\b.*?-->[ \t]*\n\n?/ms', '', $template);

        $out = preg_replace_callback(
            '/^[ \t]*<!-- dispatch:if (\w+) -->[ \t]*\n(.*?)(?:^[ \t]*<!-- dispatch:else -->[ \t]*\n(.*?))?^[ \t]*<!-- dispatch:endif -->[ \t]*\n/ms',
            fn (array $m) => trim($vars[$m[1]] ?? '') !== '' ? $m[2] : ($m[3] ?? ''),
            $template,
        );

        $out = preg_replace_callback(
            '/^[ \t]*<!-- dispatch:slot ([\w-]+) -->[ \t]*\n/m',
            fn (array $m) => $this->overlay($skill, $m[1]),
            $out,
        );

        $missing = [];
        $out = preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function (array $m) use ($vars, &$missing) {
            if (! array_key_exists($m[1], $vars)) {
                $missing[] = $m[1];

                return $m[0];
            }

            return $vars[$m[1]];
        }, $out);

        if (preg_match('/<!-- dispatch:(if|else|endif|slot)\b/', $out)) {
            throw new InvalidArgumentException("Skill template `{$skill}` has an unbalanced dispatch:if/else/endif or a misplaced marker.");
        }
        if ($missing !== []) {
            throw new InvalidArgumentException("Skill template `{$skill}` uses unknown variable(s): ".implode(', ', array_unique($missing)).'.');
        }

        return $out;
    }

    /**
     * Where the host's copy stands against a fresh render.
     *
     * @return array{skill: string, state: string, target: string}
     */
    public function status(string $skill): array
    {
        $target = $this->targetPath($skill);
        $state = match (true) {
            ! is_file($target) => self::MISSING,
            default => $this->stateOf(self::lf((string) file_get_contents($target)), $this->render($skill)),
        };

        return ['skill' => $skill, 'state' => $state, 'target' => $target];
    }

    /** Write the rendered skill to its target. */
    public function write(string $skill): string
    {
        $target = $this->targetPath($skill);
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        file_put_contents($target, $this->render($skill));

        return $target;
    }

    private function stateOf(string $existing, string $fresh): string
    {
        $fingerprint = self::fingerprintIn($existing);
        if ($fingerprint === null) {
            return self::UNMANAGED;
        }
        if ($fingerprint !== sha1(self::unstamped($existing))) {
            return self::HAND_EDITED;
        }

        return $existing === $fresh ? self::UP_TO_DATE : self::STALE;
    }

    private function overlay(string $skill, string $slot): string
    {
        $file = $this->overlayDir().'/'.$skill.'/'.$slot.'.md';
        if ($skill === '' || ! is_file($file)) {
            return '';
        }

        return rtrim(self::lf((string) file_get_contents($file)))."\n";
    }

    /** The stamp goes right after the YAML frontmatter, which must stay first. */
    private function stamp(string $skill, string $body): string
    {
        $line = self::STAMP_PREFIX.' from sgrjr/dispatch — do not edit here. Change `dispatch.skills.vars` in config/dispatch.php or an overlay in '
            .$this->relative($this->overlayDir()).'/'.$skill.'/<slot>.md, then re-publish. fingerprint:'.sha1($body).' -->';

        if (preg_match('/\A---\n.*?\n---\n/s', $body, $m)) {
            return $m[0].$line."\n".substr($body, strlen($m[0]));
        }

        return $line."\n".$body;
    }

    private static function fingerprintIn(string $content): ?string
    {
        return preg_match('/^'.preg_quote(self::STAMP_PREFIX, '/').'.*fingerprint:([0-9a-f]{40}) -->$/m', $content, $m) ? $m[1] : null;
    }

    private static function unstamped(string $content): string
    {
        return (string) preg_replace('/^'.preg_quote(self::STAMP_PREFIX, '/').'.*\n/m', '', $content, 1);
    }

    private function relative(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private static function lf(string $s): string
    {
        return str_replace("\r\n", "\n", $s);
    }
}
