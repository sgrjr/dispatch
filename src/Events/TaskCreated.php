<?php

namespace Sgrjr\Dispatch\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sgrjr\Dispatch\Models\Task;

/**
 * A new task was created. Mirrors DispatchNotifier::taskCreated(); fired by
 * Sgrjr\Dispatch\Support\EventNotifier when it is bound as the active
 * notifier, so a host can listen here to auto-spawn an agent, notify a
 * channel, etc.
 */
class TaskCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Task $task) {}
}
