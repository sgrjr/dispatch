<?php

namespace Sgrjr\Dispatch\Support;

/**
 * Finds the Claude Code transcript(s) for the current session so metrics can be
 * windowed against them. Resolution order (first hit wins):
 *
 *   1. an explicit `--transcript=` path
 *   2. the session sidecar a SessionStart hook wrote (transcript_path / session_id)
 *   3. derived: <root>/<munged cwd>/<session>.jsonl, or the newest *.jsonl there
 *
 * The derive step needs no hook: Claude Code stores transcripts under
 * `~/.claude/projects/<slug>/` where <slug> is the project cwd with every
 * non-alphanumeric run replaced by '-' (verified: `C:\Users\me\GitHub\dispatch`
 * → `C--Users-me-GitHub-dispatch`). The newest `*.jsonl` in that directory is the
 * active session — the one being appended to as the agent runs this command.
 *
 * Subagents write separate files under `<dir>/<session>/subagents/*.jsonl`; those
 * are returned alongside the main transcript so their tokens are counted too.
 */
class TranscriptLocator
{
    /**
     * @return array{main:string|null,subagents:array<int,string>,source:string}
     */
    public function locate(?string $transcript, ?string $session, ?string $projectDir, ?string $sessionFile, ?string $root): array
    {
        $main = null;
        $source = 'none';

        if ($transcript !== null && $transcript !== '' && is_file($transcript)) {
            $main = $transcript;
            $source = 'explicit';
        }

        if ($main === null && $sessionFile !== null && $sessionFile !== '' && is_file($sessionFile)) {
            $data = json_decode((string) @file_get_contents($sessionFile), true);
            if (is_array($data)) {
                $tp = $data['transcript_path'] ?? null;
                if (is_string($tp) && is_file($tp)) {
                    $main = $tp;
                    $source = 'sidecar';
                }
                if ($session === null && isset($data['session_id'])) {
                    $session = (string) $data['session_id'];
                }
                if ($projectDir === null && isset($data['cwd'])) {
                    $projectDir = (string) $data['cwd'];
                }
            }
        }

        if ($main === null) {
            $dir = $this->projectTranscriptDir($projectDir ?? base_path(), $root);
            if ($dir !== null && is_dir($dir)) {
                if ($session !== null && $session !== '') {
                    $cand = $dir.DIRECTORY_SEPARATOR.$session.'.jsonl';
                    if (is_file($cand)) {
                        $main = $cand;
                        $source = 'session';
                    }
                }
                if ($main === null && ($newest = $this->newestJsonl($dir)) !== null) {
                    $main = $newest;
                    $source = 'derived';
                }
            }
        }

        $subagents = [];
        if ($main !== null) {
            $subDir = dirname($main).DIRECTORY_SEPARATOR.basename($main, '.jsonl').DIRECTORY_SEPARATOR.'subagents';
            if (is_dir($subDir)) {
                foreach (glob($subDir.DIRECTORY_SEPARATOR.'*.jsonl') ?: [] as $p) {
                    $subagents[] = $p;
                }
            }
        }

        return ['main' => $main, 'subagents' => $subagents, 'source' => $source];
    }

    /**
     * The transcript directory for a project cwd: <root>/<munged cwd>.
     */
    public function projectTranscriptDir(string $projectDir, ?string $root): ?string
    {
        $root = ($root !== null && $root !== '') ? $root : $this->defaultRoot();
        if ($root === null) {
            return null;
        }

        $slug = preg_replace('/[^A-Za-z0-9]/', '-', $projectDir);

        return rtrim($root, '/\\').DIRECTORY_SEPARATOR.$slug;
    }

    private function defaultRoot(): ?string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: null;
        if (! $home) {
            return null;
        }

        return rtrim($home, '/\\').DIRECTORY_SEPARATOR.'.claude'.DIRECTORY_SEPARATOR.'projects';
    }

    private function newestJsonl(string $dir): ?string
    {
        $files = glob(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'*.jsonl') ?: [];
        $newest = null;
        $mtime = -1;
        foreach ($files as $f) {
            $m = @filemtime($f);
            if ($m !== false && $m > $mtime) {
                $mtime = $m;
                $newest = $f;
            }
        }

        return $newest;
    }
}
