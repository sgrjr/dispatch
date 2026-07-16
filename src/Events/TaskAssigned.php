<?php

namespace Sgrjr\Dispatch\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sgrjr\Dispatch\Models\Task;

/**
 * A task's assignee changed. Mirrors DispatchNotifier::taskAssigned(); fired
 * by Sgrjr\Dispatch\Support\EventNotifier when it is bound as the active
 * notifier.
 */
class TaskAssigned
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Task $task,
        public ?int $from,
        public ?int $to,
        public ?Authenticatable $actor,
    ) {}
}
