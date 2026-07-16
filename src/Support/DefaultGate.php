<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Sgrjr\Dispatch\Contracts\DispatchGate;

/**
 * Sensible single-team default: any authenticated user is staff and sees
 * everything; guests see only public tasks. Bind your own DispatchGate to
 * distinguish staff from submitters or to apply tenant scoping.
 */
class DefaultGate implements DispatchGate
{
    public function isStaff(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function canSeeAll(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function scopeVisible(Builder $query, ?Authenticatable $user): Builder
    {
        if ($this->canSeeAll($user)) {
            return $query;
        }

        // Guests: public tasks only.
        return $query->where('is_public', true);
    }
}
