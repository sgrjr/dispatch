<?php

namespace Sgrjr\Dispatch\Support;

/**
 * Aggregates agent-run metrics from Claude Code transcript JSONL files, windowed
 * to a time range (a task's claim→done span). Pure/DB-free so it unit-tests
 * against fixtures.
 *
 * The transcript format is a Claude Code internal (documented as version-unstable),
 * so the two non-obvious invariants this class encodes — verified against real
 * transcripts — live here, in one place to fix if the format shifts:
 *
 *  1. STREAMING SNAPSHOTS. One assistant *message* is written as several JSONL
 *     records (one per content block: thinking, text, tool_use, ...), all sharing
 *     `message.id`, and each carrying a `usage` object that GROWS as the message
 *     streams. Summing usage across records massively over-counts; taking the first
 *     under-counts output. We keep, per message id, the usage from the terminal
 *     record (non-null `stop_reason`), falling back to the max-output snapshot.
 *
 *  2. TOOL_USE blocks are NOT duplicated across those records — each block appears
 *     in exactly one record — so tool calls are counted per content block across
 *     every record (not deduped by message id).
 *
 * Token fields on `message.usage`: input_tokens, output_tokens,
 * cache_creation_input_tokens, cache_read_input_tokens.
 */
class TranscriptMetrics
{
    /**
     * @param  array<string,array{input?:float,output?:float,cache_write?:float,cache_read?:float}>  $pricing
     *   Per-model $ / 1M tokens, keyed by model id (prefix-matched).
     */
    public function __construct(private array $pricing = []) {}

    /**
     * @param  array<int,array{path:string,subagent:bool}>  $files
     * @param  int|null  $since  Unix epoch (inclusive lower bound); null = unbounded.
     * @param  int|null  $until  Unix epoch (inclusive upper bound); null = unbounded.
     * @return array<string,mixed>
     */
    public function summarize(array $files, ?int $since, ?int $until): array
    {
        // Per message id: the best (terminal, else max-output) usage snapshot.
        $usageById = [];        // id => ['model','input','output','cache_read','cache_creation','stop']
        $tools = [];            // tool name => count
        $seenErrorTools = [];   // tool_use_id => true (dedupe error tool_results)
        $errors = 0;
        $models = [];           // model id => true
        $subagentHit = [];      // subagent file path => true

        foreach ($files as $file) {
            $path = $file['path'];
            $isSub = (bool) $file['subagent'];

            $fh = @fopen($path, 'r');
            if ($fh === false) {
                continue;
            }

            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $rec = json_decode($line, true);
                if (! is_array($rec)) {
                    continue;
                }

                // Every content-bearing record carries a top-level ISO-8601 (UTC)
                // timestamp; records without one (summaries, snapshots) can't be
                // windowed and carry no usage, so skip.
                $tsRaw = $rec['timestamp'] ?? null;
                if (! is_string($tsRaw)) {
                    continue;
                }
                $ts = strtotime($tsRaw);
                if ($ts === false) {
                    continue;
                }
                if ($since !== null && $ts < $since) {
                    continue;
                }
                if ($until !== null && $ts > $until) {
                    continue;
                }

                $msg = $rec['message'] ?? null;
                if (! is_array($msg)) {
                    continue;
                }
                $role = $msg['role'] ?? ($rec['type'] ?? null);

                if ($role === 'assistant') {
                    $this->ingestAssistant($rec, $msg, $isSub, $path, $usageById, $tools, $models, $subagentHit);
                } elseif ($role === 'user') {
                    foreach ($this->blocks($msg) as $block) {
                        if (($block['type'] ?? null) === 'tool_result' && ! empty($block['is_error'])) {
                            $tid = $block['tool_use_id'] ?? null;
                            if ($tid === null || ! isset($seenErrorTools[$tid])) {
                                if ($tid !== null) {
                                    $seenErrorTools[$tid] = true;
                                }
                                $errors++;
                            }
                        }
                    }
                }
            }

            fclose($fh);
        }

        return $this->assemble($usageById, $tools, $errors, array_keys($models), count($subagentHit));
    }

    /**
     * @param  array<string,mixed>  $rec
     * @param  array<string,mixed>  $msg
     * @param  array<string,array<string,mixed>>  $usageById
     * @param  array<string,int>  $tools
     * @param  array<string,bool>  $models
     * @param  array<string,bool>  $subagentHit
     */
    private function ingestAssistant(array $rec, array $msg, bool $isSub, string $path, array &$usageById, array &$tools, array &$models, array &$subagentHit): void
    {
        // Count tool_use blocks per record (blocks aren't duplicated across the
        // streaming snapshots — see class docblock invariant #2).
        foreach ($this->blocks($msg) as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                $name = (string) ($block['name'] ?? 'unknown');
                $tools[$name] = ($tools[$name] ?? 0) + 1;
            }
        }

        $usage = $msg['usage'] ?? null;
        if (! is_array($usage)) {
            return;
        }

        $id = $msg['id'] ?? ($rec['uuid'] ?? null);
        if ($id === null) {
            $id = 'anon:'.md5(json_encode($usage).($rec['uuid'] ?? ''));
        }
        $id = (string) $id;

        $candidate = [
            'model' => (string) ($msg['model'] ?? 'unknown'),
            'input' => (int) ($usage['input_tokens'] ?? 0),
            'output' => (int) ($usage['output_tokens'] ?? 0),
            'cache_read' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cache_creation' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
            'stop' => array_key_exists('stop_reason', $msg) && $msg['stop_reason'] !== null,
        ];

        $models[$candidate['model']] = true;
        if ($isSub) {
            $subagentHit[$path] = true;
        }

        // Keep the terminal snapshot (has stop_reason); before we've seen one,
        // keep the largest-output snapshot (usage grows monotonically as the
        // message streams). Once a terminal snapshot is stored, it wins.
        $existing = $usageById[$id] ?? null;
        if ($existing === null) {
            $usageById[$id] = $candidate;

            return;
        }
        if ($existing['stop']) {
            return;
        }
        if ($candidate['stop'] || $candidate['output'] >= $existing['output']) {
            $usageById[$id] = $candidate;
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $usageById
     * @param  array<string,int>  $tools
     * @param  array<int,string>  $modelList
     * @return array<string,mixed>
     */
    private function assemble(array $usageById, array $tools, int $errors, array $modelList, int $subagents): array
    {
        $byModel = [];
        $tot = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0];

        foreach ($usageById as $u) {
            $model = $u['model'];
            $byModel[$model] ??= ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0];
            foreach (['input', 'output', 'cache_read', 'cache_creation'] as $k) {
                $byModel[$model][$k] += $u[$k];
                $tot[$k] += $u[$k];
            }
        }

        $total = $tot['input'] + $tot['output'] + $tot['cache_read'] + $tot['cache_creation'];
        $inputSide = $tot['input'] + $tot['cache_read'] + $tot['cache_creation'];
        $cacheHit = $inputSide > 0 ? round($tot['cache_read'] / $inputSide, 4) : 0.0;

        [$cost, $unpriced] = $this->cost($byModel);

        arsort($tools);

        return [
            'tokens' => [
                'input' => $tot['input'],
                'output' => $tot['output'],
                'cache_read' => $tot['cache_read'],
                'cache_creation' => $tot['cache_creation'],
                'total' => $total,
                'cache_hit_ratio' => $cacheHit,
            ],
            'cost_usd' => $cost === null ? null : round($cost, 4),
            'cost_partial' => $unpriced !== [],
            'turns' => count($usageById),
            'tool_calls' => array_sum($tools),
            'tools' => $tools,
            'subagents' => $subagents,
            'errors' => $errors,
            'models' => $modelList,
            'unpriced_models' => array_values($unpriced),
        ];
    }

    /**
     * @param  array<string,array{input:int,output:int,cache_read:int,cache_creation:int}>  $byModel
     * @return array{0:float|null,1:array<int,string>}
     */
    private function cost(array $byModel): array
    {
        // Longest key first so 'claude-opus-4-8' wins over a hypothetical
        // 'claude-opus-4' before matching a suffixed id like
        // 'claude-haiku-4-5-20251001' or 'claude-opus-4-8[1m]'.
        $keys = array_keys($this->pricing);
        usort($keys, fn ($a, $b) => strlen($b) - strlen($a));

        $total = 0.0;
        $unpriced = [];

        foreach ($byModel as $model => $u) {
            $rate = null;
            foreach ($keys as $k) {
                if ($model === $k || str_starts_with($model, $k) || str_contains($model, $k)) {
                    $rate = $this->pricing[$k];
                    break;
                }
            }
            if ($rate === null) {
                $unpriced[$model] = $model;

                continue;
            }
            $total += $u['input'] * (float) ($rate['input'] ?? 0) / 1_000_000
                + $u['output'] * (float) ($rate['output'] ?? 0) / 1_000_000
                + $u['cache_read'] * (float) ($rate['cache_read'] ?? 0) / 1_000_000
                + $u['cache_creation'] * (float) ($rate['cache_write'] ?? 0) / 1_000_000;
        }

        // Every model unpriced → no meaningful cost to report.
        if ($byModel !== [] && count($unpriced) === count($byModel)) {
            return [null, $unpriced];
        }

        return [$total, $unpriced];
    }

    /**
     * The content blocks of a message, tolerant of string content (user turns
     * are often a bare string) and missing content.
     *
     * @param  array<string,mixed>  $msg
     * @return array<int,array<string,mixed>>
     */
    private function blocks(array $msg): array
    {
        $content = $msg['content'] ?? [];
        if (! is_array($content)) {
            return [];
        }

        return array_values(array_filter($content, 'is_array'));
    }
}
