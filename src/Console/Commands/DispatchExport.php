<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Sgrjr\Dispatch\Models\Label;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;

/**
 * Writes a full JSON-LD snapshot (tasks + comments + labels) used both as a
 * git-diffable bridge file and as the payload `dispatch:push` uploads.
 */
class DispatchExport extends Command
{
    protected $signature = 'dispatch:export
        {--path=storage/app/dispatch-tasks.jsonld : Output path, resolved relative to the app base path}';

    protected $description = 'Export all tasks + labels + comments into a JSON-LD snapshot.';

    public function handle(): int
    {
        $path = base_path($this->option('path'));

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');
        /** @var class-string<Label> $labelModel */
        $labelModel = config('dispatch.models.label');

        $tasks = $taskModel::query()
            ->with(['labels', 'comments.user', 'submitter', 'assignee'])
            ->orderBy('code')
            ->get()
            ->map(fn (Task $t) => $this->taskToJsonLd($t))
            ->all();

        $labels = $labelModel::query()->orderBy('name')->get()->map(fn (Label $l) => [
            '@type' => 'Label',
            'name' => $l->name,
            'color' => $l->color,
            'description' => $l->description,
        ])->all();

        $doc = [
            '@context' => [
                '@vocab' => config('dispatch.jsonld.vocab'),
                'code' => '@id',
                'labels' => ['@container' => '@set'],
                'comments' => ['@container' => '@list'],
                'createdAt' => ['@type' => 'http://www.w3.org/2001/XMLSchema#dateTime'],
                'updatedAt' => ['@type' => 'http://www.w3.org/2001/XMLSchema#dateTime'],
            ],
            '@type' => 'TaskCollection',
            'schemaVersion' => '1.0',
            'exportedAt' => now()->toIso8601String(),
            'exportedBy' => 'dispatch:export',
            'tasks' => $tasks,
            'labels' => $labels,
        ];

        file_put_contents(
            $path,
            json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );

        $this->info("Wrote {$path}");
        $this->line('Tasks: '.count($tasks));
        $this->line('Labels: '.count($labels));

        return self::SUCCESS;
    }

    private function taskToJsonLd(Task $t): array
    {
        return [
            '@type' => 'Task',
            'code' => $t->code,
            'title' => $t->title,
            'description' => $t->description,
            'type' => $t->type,
            'priority' => $t->priority,
            'status' => $t->status,
            'isPublic' => (bool) $t->is_public,
            'position' => (int) $t->position,
            'exceptionSignature' => $t->exception_signature,
            'labels' => $t->labels->pluck('name')->all(),
            'submitter' => $t->submitter?->email,
            'assignee' => $t->assignee?->email,
            'createdAt' => optional($t->created_at)->toIso8601String(),
            'updatedAt' => optional($t->updated_at)->toIso8601String(),
            'comments' => $t->comments->map(fn (TaskComment $c) => [
                '@type' => 'Comment',
                'body' => $c->body,
                'author' => $c->user?->email,
                'isInternal' => (bool) $c->is_internal,
                'notifiedSubmitter' => (bool) $c->notified_submitter,
                'eventType' => $c->event_type,
                'meta' => $c->meta,
                'createdAt' => optional($c->created_at)->toIso8601String(),
            ])->all(),
        ];
    }
}
