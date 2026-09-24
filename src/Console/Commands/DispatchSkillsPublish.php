<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Sgrjr\Dispatch\Support\SkillPublisher;

/**
 * Render the shipped Claude Code skills for this host into `.claude/skills`
 * ({@see SkillPublisher}). Re-run any time the host's `dispatch.skills.vars`,
 * an overlay, or the package changes.
 */
class DispatchSkillsPublish extends Command
{
    protected $signature = 'dispatch:skills:publish
        {--skill=* : Only these skills (default: dispatch.skills.publish)}
        {--check : Report each skill against a fresh render and exit non-zero if any is not up to date; writes nothing}
        {--force : Overwrite a hand-edited or unmanaged copy (move its edits into config or an overlay first)}';

    protected $description = 'Render the shipped Dispatch skills (templates) with this host\'s config + overlays into .claude/skills';

    public function handle(SkillPublisher $publisher): int
    {
        $skills = (array) $this->option('skill') ?: $publisher->skills();
        $failed = false;

        foreach ($skills as $skill) {
            try {
                $status = $publisher->status($skill);
            } catch (InvalidArgumentException $e) {
                $this->error("  {$skill}: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            $state = $status['state'];

            if ($this->option('check')) {
                $this->line(sprintf('  %-26s %s', $skill, $this->describe($state)));
                $failed = $failed || $state !== SkillPublisher::UP_TO_DATE;

                continue;
            }

            if (in_array($state, [SkillPublisher::HAND_EDITED, SkillPublisher::UNMANAGED], true) && ! $this->option('force')) {
                $this->warn("  {$skill}: skipped — {$this->describe($state)}.");
                $this->line('    Move those edits into config/dispatch.php (skills.vars) or an overlay in '.$publisher->overlayDir()."/{$skill}/<slot>.md, then re-run with --force.");
                $failed = true;

                continue;
            }

            if ($state === SkillPublisher::UP_TO_DATE) {
                $this->line("  {$skill}: up to date.");

                continue;
            }

            $publisher->write($skill);
            $this->info("  {$skill}: published → {$status['target']}");
        }

        if ($this->option('check') && $failed) {
            $this->line('Re-publish with: php artisan dispatch:skills:publish');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function describe(string $state): string
    {
        return match ($state) {
            SkillPublisher::UP_TO_DATE => 'up to date',
            SkillPublisher::STALE => 'STALE — config, an overlay or the package changed since it was published',
            SkillPublisher::HAND_EDITED => 'HAND-EDITED since it was published',
            SkillPublisher::UNMANAGED => 'UNMANAGED — a hand copy, never published by this command',
            SkillPublisher::MISSING => 'MISSING — not published yet',
            default => $state,
        };
    }
}
