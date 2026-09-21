<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\ResolvesTextInput;
use Sgrjr\Dispatch\Console\Commands\Concerns\TalksToAgentApi;
use Sgrjr\Dispatch\Contracts\LaneResolver;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\Anchor;
use Sgrjr\Dispatch\Support\DueDate;
use Sgrjr\Dispatch\Support\TaskPresenter;

/**
 * Create a new task. Creation ALWAYS routes through DispatchTaskService (never
 * Task::create directly) so code minting, submitter resolution, tenant
 * stamping, and label attachment happen the one true way.
 *
 * The CLI is a trusted developer/agent context (no logged-in user) — the
 * service falls back to its configured default submitter when none is given.
 */
class DispatchAdd extends Command
{
    use ResolvesTextInput;
    use TalksToAgentApi;

    protected $signature = 'dispatch:add
        {title : The task title (short)}
        {--type= : bug | feature | chore | debt | verify (default: feature)}
        {--priority= : blocker | high | medium | low (default: medium)}
        {--description= : Full task body (markdown). Use heredoc or quoted multi-line.}
        {--description-file= : Read the task body from a file (or `-` for stdin) instead of inline --description}
        {--due= : Due date (parseable date/time string, e.g. "2026-08-01" or "+3 days"), resolved on the caller\'s clock; blank means no due date}
        {--label=* : Label name(s) to attach; auto-created if missing. Repeatable.}
        {--public : Mark visible outside staff (default: private)}
        {--key= : Idempotency key; returns the existing task with this key instead of creating a duplicate}
        {--topic= : What the task is ABOUT, as "<type>:<id>" (e.g. account:0402100000001) or "<type>" alone}
        {--origin= : Where the task came FROM, as "<type>:<id>" or "<type>" alone (e.g. phone). Write-once — see dispatch:schema}
        {--conversation= : The home conversation/arc id (an integer)}
        {--lane= : Route to this lane at creation, as "<department>" or "<department>:<role>" (e.g. marketing:developer) — validated against the bound LaneResolver}
        {--remote : Act on the configured remote agent API (the default while an agent session token is active)}
        {--local : Act on the local DB even while an agent session token is active (overrides sticky-remote)}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Create a new task via DispatchTaskService.';

    public function handle(DispatchTaskService $tasks): int
    {
        // Validate against the configured workflow vocab (not the const) so a
        // host's custom types/priorities are accepted, matching the agent API + UI.
        $type = $this->option('type');
        if ($type !== null && ! in_array($type, Task::types(), true)) {
            $this->error('--type must be one of: '.implode(', ', Task::types()));

            return self::FAILURE;
        }

        $priority = $this->option('priority');
        if ($priority !== null && ! in_array($priority, Task::priorities(), true)) {
            $this->error('--priority must be one of: '.implode(', ', Task::priorities()));

            return self::FAILURE;
        }

        // Resolve --due up front so a bad date string fails before any request
        // is sent or any row is written. Unlike the verbs that EDIT a date, a
        // blank value here is simply "no due date": a task being minted has
        // nothing to clear, so there is no clear sentinel to honor.
        $due = null;
        if ($this->option('due') !== null && ! DueDate::isClear($this->option('due'))) {
            $raw = trim((string) $this->option('due'));
            try {
                $due = DueDate::parseOrFail($raw);
            } catch (\InvalidArgumentException) {
                // The helper's message names the WIRE field; this surface is a
                // flag, so it keeps its own wording.
                $this->error("--due could not be parsed as a date: {$raw}");

                return self::FAILURE;
            }
        }

        // Anchor flags — validated up front (same posture as --due) so a
        // malformed one fails before any request is sent or row written.
        // Anchor::parse is the ONE parser; this is its only local call site.
        $topicType = $topicId = $originType = $originId = null;
        if (($topic = $this->option('topic')) !== null) {
            try {
                [$topicType, $topicId] = Anchor::parse($topic);
            } catch (\InvalidArgumentException $e) {
                $this->error("--topic: {$e->getMessage()}");

                return self::FAILURE;
            }
        }
        if (($origin = $this->option('origin')) !== null) {
            try {
                [$originType, $originId] = Anchor::parse($origin);
            } catch (\InvalidArgumentException $e) {
                $this->error("--origin: {$e->getMessage()}");

                return self::FAILURE;
            }
        }
        $conversation = $this->option('conversation');
        if ($conversation !== null && ! ctype_digit((string) $conversation)) {
            $this->error('--conversation must be a positive integer.');

            return self::FAILURE;
        }

        // --lane: validated ONLY against the LOCAL binding, and only when
        // this call will actually persist locally. A --remote call forwards
        // the raw value and lets the AUTHORITATIVE (production) LaneResolver
        // validate it server-side — this dev box's own binding is very likely
        // the inert NullLaneResolver and would reject every real lane.
        $lane = $this->option('lane');
        if ($lane !== null && $lane !== '' && ! $this->targetsRemote() && ! app(LaneResolver::class)->isLane($lane)) {
            $this->error("--lane `{$lane}` is not a valid lane (see dispatch:schema).");

            return self::FAILURE;
        }

        $labelNames = array_values(array_filter(
            array_map('trim', (array) $this->option('label')),
            fn ($n) => $n !== ''
        ));

        $key = $this->option('key');

        [$description, $err] = $this->resolveInlineOrFile(
            $this->option('description'),
            $this->option('description-file'),
            '--description',
            '--description-file',
        );
        if ($err !== null) {
            $this->error($err);

            return self::FAILURE;
        }

        if ($this->targetsRemote()) {
            $r = $this->agentPost('add', array_filter([
                'title' => $this->argument('title'),
                'type' => $type,
                'priority' => $priority,
                'description' => $description,
                'labels' => $labelNames ?: null,
                'public' => $this->option('public') ? true : null,
                'key' => $key,
                // Sent as ISO 8601 because it was resolved HERE — a relative
                // input like "+3 days" must mean the caller's clock, not
                // whenever the server got around to parsing it. Absent when
                // there is no due date (the filter below drops the null).
                'due_at' => $due?->toIso8601String(),
                // Anchor wire strings travel RAW — the server re-parses them
                // with the same Anchor::parse(), so there's one parser and one
                // source of truth for the format either side of the wire.
                'topic' => $topic,
                'origin' => $origin,
                'conversation' => $conversation,
                'lane' => $lane,
            ], fn ($v) => $v !== null));

            if ($r === null) {
                return self::FAILURE;
            }

            $this->line(json_encode($r['task'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $attributes = ['title' => $this->argument('title')];
        if ($type !== null) {
            $attributes['type'] = $type;
        }
        if ($priority !== null) {
            $attributes['priority'] = $priority;
        }
        if ($description !== null && $description !== '') {
            $attributes['description'] = $description;
        }
        if ($due !== null) {
            $attributes['due_at'] = $due;
        }
        if ($topicType !== null) {
            $attributes['topic_type'] = $topicType;
            $attributes['topic_id'] = $topicId;
        }
        if ($originType !== null) {
            $attributes['origin_type'] = $originType;
            $attributes['origin_id'] = $originId;
        }
        if ($conversation !== null) {
            $attributes['conversation_id'] = (int) $conversation;
        }
        if ($lane !== null && $lane !== '') {
            $attributes['lane'] = $lane;
        }
        $attributes['is_public'] = (bool) $this->option('public');

        $task = $key !== null
            ? $tasks->firstOrCreateByKey($key, $attributes, $labelNames)
            : $tasks->create($attributes, $labelNames);

        if ($this->option('json')) {
            $this->line(json_encode(TaskPresenter::toArray($task, false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Created {$task->code}");
        $this->line("  title: {$task->title}");
        $this->line("  type: {$task->type}  ·  priority: {$task->priority}  ·  status: {$task->status}  ·  public: ".($task->is_public ? 'yes' : 'no'));
        // Read off the task, not the flag: a keyed re-add returns the EXISTING
        // task untouched, and printing the date it actually carries is the
        // honest receipt.
        if ($task->due_at) {
            $this->line('  due: '.$task->due_at->toDateTimeString());
        }
        if ($labelNames) {
            $this->line('  labels: '.implode(', ', $labelNames));
        }
        if ($task->lane) {
            $this->line('  lane: '.$task->lane);
        }

        return self::SUCCESS;
    }
}
