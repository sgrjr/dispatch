<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Sgrjr\Dispatch\Contracts\LaneResolver;

/**
 * No-op lane resolution: a host that hasn't bound its own LaneResolver gets a
 * task with `lane` stored and readable (if something writes it directly), but
 * every validated write path rejects a non-null lane (isLane() is always
 * false), every list is empty, and canRoute() is always false. With this
 * bound, the whole lane feature is INERT and every task effectively stays in
 * the no-department lane — backwards compatible with a host that never adopts
 * lanes at all.
 */
class NullLaneResolver implements LaneResolver
{
    public function isLane(string $lane): bool
    {
        return false;
    }

    public function label(string $lane): ?string
    {
        return null;
    }

    public function lanes(): array
    {
        return [];
    }

    public function lanesFor(Authenticatable $user): array
    {
        return [];
    }

    public function lanesManagedBy(Authenticatable $user): array
    {
        return [];
    }

    public function memberIds(string $lane): array
    {
        return [];
    }

    public function canRoute(Authenticatable $user): bool
    {
        return false;
    }
}
