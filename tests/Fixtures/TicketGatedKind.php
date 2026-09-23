<?php

namespace Sgrjr\Dispatch\Tests\Fixtures;

use Sgrjr\Dispatch\Kinds\RecordGatedKind;
use Sgrjr\Dispatch\Models\Task;

/** A test kind gated by a "ticket" (TASK-1190): the records live in a static array, [id => open?]. */
class TicketGatedKind extends RecordGatedKind
{
    /** @var array<int, bool> */
    public static array $tickets = [];

    public static function key(): string
    {
        return 'ticket';
    }

    protected function record(Task $task): mixed
    {
        $id = $task->context['ticket']['id'] ?? null;

        return array_key_exists($id, self::$tickets) ? ['id' => $id, 'open' => self::$tickets[$id]] : null;
    }

    protected function recordIsOpen(mixed $record): bool
    {
        return $record['open'];
    }

    protected function toolUrl(Task $task, mixed $record): string
    {
        return 'https://example.test/tickets/'.$record['id'];
    }

    protected function toolLabel(): string
    {
        return 'Review & Mark Done';
    }
}
