<?php

namespace Sgrjr\Dispatch\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Contracts\DispatchGate;
use Sgrjr\Dispatch\Models\Task;

/**
 * Thin policy: authorization decisions delegate to the app-bound DispatchGate,
 * and per-task visibility reuses the ONE scope (scopeVisible) rather than
 * re-deriving it. Contrast rupkeep's policy, which re-implemented staff/org
 * checks inline and duplicated them across three Livewire components.
 */
class TaskPolicy
{
    public function __construct(
        protected DispatchGate $gate,
    ) {}

    public function viewAny(?Authenticatable $user): bool
    {
        return $this->gate->isStaff($user);
    }

    public function view(?Authenticatable $user, Task $task): bool
    {
        /** @var class-string<Task> $taskModel */
        $taskModel = config('dispatch.models.task');

        $query = $taskModel::query()->whereKey($task->getKey());
        $this->gate->scopeVisible($query, $user);

        return $query->exists();
    }

    /**
     * Any authenticated user may dispatch a bug/feature — that is the product.
     */
    public function create(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function update(?Authenticatable $user, Task $task): bool
    {
        return $this->gate->isStaff($user);
    }

    public function delete(?Authenticatable $user, Task $task): bool
    {
        return $this->gate->canSeeAll($user);
    }

    public function comment(?Authenticatable $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function commentInternal(?Authenticatable $user, Task $task): bool
    {
        return $this->gate->isStaff($user);
    }

    public function manageLabels(?Authenticatable $user): bool
    {
        return $this->gate->isStaff($user);
    }
}
