<?php

namespace Sgrjr\Dispatch\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sgrjr\Dispatch\Models\Task;

/**
 * A task's status changed. Mirrors DispatchNotifier::taskStatusChanged();
 * fired by Sgrjr\Dispatch\Support\EventNotifier when it is bound as the
 * active notifier.
 */
class TaskStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Task $task,
        public string $from,
        public string $to,
        public ?Authenticatable $actor,
    ) {}
}
