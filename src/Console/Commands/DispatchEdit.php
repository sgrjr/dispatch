<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Sgrjr\Dispatch\Console\Commands\Concerns\WritesRawLines;
use Illuminate\Console\Command;
use Sgrjr\Dispatch\Console\Commands\Concerns\GuardsLocalOnlyWrites;
use Sgrjr\Dispatch\Console\Commands\Concerns\ResolvesTextInput;
use Sgrjr\Dispatch\Contracts\DispatchNotifier;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Support\DueDate;

/**
 * Trusted CLI surface: queries tasks directly (no DispatchGate::scopeVisible).
 *
 * Editing the description is memorialized, not silently overwritten: the OLD
 * body is preserved as an internal EVENT_DESCRIPTION_EDITED timeline comment
 * before the new body is applied, so a description edit never loses history.
 *
 * LOCAL-ONLY: this verb has no `--remote` path and `edit` is not in
 * `agent.verbs` (promoting it is the §13 open decision). GuardsLocalOnlyWrites
 * makes that boundary loud instead of silent — see the trait for why a quiet
 * local write here is a data hazard, not just a surprise.
 */
class DispatchEdit extends Command
{
    use WritesRawLines;
    use GuardsLocalOnlyWrites;
    use ResolvesTextInput;

    protected $signature = 'dispatch:edit
        {code : The task code, e.g. TASK-042}
        {--title= : New title}
        {--description= : New description (markdown ok); the old body is memorialized on the timeline}
        {--description-file= : Read the new description from a file (or `-` for stdin) instead of inline --description}
        {--due= : New due date (parseable date/time string, e.g. "2026-08-01" or "+3 days"); empty string clears it}
        {--assignee= : Reassign the task in ANY status, lane untouched: a user id or email; `none` (or an empty string) unassigns}
        {--local : Confirm the LOCAL dev DB is the intended target even while an agent session is active}
        {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Edit a task\'s title, description, due date and/or assignee.';

    public function handle(): int
    {
        // Before the lookup, so a refusal reads nothing and writes nothing. The
        // alternatives are the surfaces that genuinely reach the remote today:
        // batch carries title/description on an `update` op, and W10 routed
        // due_at through add/done for exactly this reason.
        if ($this->blockedByActiveAgentSession('dispatch:edit', "overwrite an unrelated local task's body and memorialize the wrong prior version onto its timeline", [
            'title / description on the remote' => 'a batch manifest `update` op (code + title/description) → php artisan dispatch:batch manifest.json',
            'due date on the remote' => 'php artisan dispatch:done <code> --due=… (or dispatch:add --due=… at creation)',
        ])) {
            return self::FAILURE;
        }

        // New body from --description (inline) OR --description-file (a path, or
        // `-` for stdin) — the escape hatch for a body too long or too
        // quote-heavy for one command line. A description is the LONGEST text
        // this package writes, so its absence here was the sharper half of the
        // gap: an agent piping markdown through shell substitution to get around
        // it is one quoting slip from mangling the body it is memorializing.
        [$newDescription, $err] = $this->resolveInlineOrFile(
            $this->option('description'),
            $this->option('description-file'),
            '--description',
            '--description-file',
        );
        if ($err !== null) {
            $this->error($err);

            return self::FAILURE;
        }

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $task = $taskModel::query()->where('code', $this->argument('code'))->first();
        if (! $task) {
            $this->error("Task not found: {$this->argument('code')}");

            return self::FAILURE;
        }

        // Resolve --due up front so a bad date string fails loudly before any
        // other change (including the description memorial) is applied. Absent
        // flag → untouched; empty string → cleared; anything else must parse
        // (the shared tri-state rule every write surface honors).
        $dueProvided = $this->option('due') !== null;
        $dueAt = $task->due_at;
        if ($dueProvided) {
            $raw = trim((string) $this->option('due'));
            try {
                $dueAt = DueDate::resolve($raw);
            } catch (\InvalidArgumentException) {
                // The helper's message names the WIRE field; this surface is a
                // flag, so it keeps its own wording.
                $this->error("--due could not be parsed as a date: {$raw}");

                return self::FAILURE;
            }
        }

        // TASK-1530 — a plain reassign of the SAME task, in any status, with no
        // lane move and no new task (handoff mints one across lanes, and claim
        // refuses anything past open/triage). Resolved before any write.
        $assigneeProvided = $this->option('assignee') !== null;
        $assigneeFrom = $task->assignee_user_id === null ? null : (int) $task->assignee_user_id;
        $assigneeTo = $assigneeFrom;
        if ($assigneeProvided) {
            $ref = trim((string) $this->option('assignee'));
            if ($ref === '' || strtolower($ref) === 'none') {
                $assigneeTo = null;
            } else {
                $user = $this->resolveUser($ref);
                if ($user === null) {
                    $this->error("--assignee: no user found for {$ref}");

                    return self::FAILURE;
                }
                $assigneeTo = (int) $user->getKey();
            }
        }
        // A group holder gives way to a person (the two are mutually exclusive).
        $assigneeChanged = $assigneeProvided && ($assigneeFrom !== $assigneeTo || $task->assignee_group !== null);

        $descriptionChanged = false;
        if ($newDescription !== null && $newDescription !== $task->description) {
            $task->recordEvent(
                TaskComment::EVENT_DESCRIPTION_EDITED,
                null,
                ['source' => 'cli'],
                $task->description,
                true,
            );
            $task->description = $newDescription;
            $descriptionChanged = true;
        }

        if ($this->option('title') !== null) {
            $task->title = $this->option('title');
        }

        if ($dueProvided) {
            $task->due_at = $dueAt;
        }

        if ($assigneeChanged) {
            $task->assignee_user_id = $assigneeTo;
            $task->assignee_group = null;
        }

        $task->save();

        // Memorialized and announced in the SAME shape the board's assignee
        // control uses (TaskShow), so the timeline and the notifications read
        // the same whichever surface reassigned it.
        if ($assigneeChanged) {
            $task->recordEvent(
                TaskComment::EVENT_ASSIGNEE_CHANGE,
                null,
                ['changes' => [['assignee_user_id', $assigneeFrom, $assigneeTo]], 'source' => 'cli'],
                'Assignee updated.',
            );
            app(DispatchNotifier::class)->taskAssigned($task, $assigneeFrom, $assigneeTo, null);
        }

        if ($this->option('json')) {
            $this->rawLine(json_encode([
                'code' => $task->code,
                'title' => $task->title,
                'type' => $task->type,
                'priority' => $task->priority,
                'status' => $task->status,
                'assignee_user_id' => $task->assignee_user_id,
                'due_at' => optional($task->due_at)->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Updated {$task->code}");
        $this->line("  title: {$task->title}");
        $this->line(($task->type ? "  kind: {$task->type}  ·" : ' ')." priority: {$task->priority}  ·  status: {$task->status}");
        $this->line('  due: '.($task->due_at ? $task->due_at->toDateTimeString() : '(none)'));
        if ($descriptionChanged) {
            $this->line('  description updated (previous body memorialized on the timeline).');
        }
        if ($assigneeChanged) {
            $this->line('  assignee: '.($task->assignee_user_id ?? '(none)'));
        }

        return self::SUCCESS;
    }

    /** A user id or an email, through the host's configured user model. */
    protected function resolveUser(string $ref): mixed
    {
        /** @var class-string $userModel */
        $userModel = config('dispatch.models.user');

        return str_contains($ref, '@')
            ? $userModel::query()->where('email', $ref)->first()
            : (ctype_digit($ref) ? $userModel::query()->find((int) $ref) : null);
    }
}
