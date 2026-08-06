<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Sgrjr\Dispatch\Contracts\DispatchGate;

/**
 * Single-team default: any authenticated user is staff; visibility runs
 * through the W13-5 gates (see Support\VisibilityGates) — a staff member
 * sees tasks shared with staff plus every task they participate in
 * (submitter/assignee/watcher), and guests see NOTHING (there is no public
 * visibility; GATE D is a hard no-op).
 *
 * canSeeAll() is false here — the pre-W13-5 DefaultGate treated every
 * authenticated user as a superuser, which is exactly the all-staff-see-
 * everything default the gates overturn. Bind your own DispatchGate to
 * grant real superusers canSeeAll, to distinguish staff from customers
 * (GATE C), or to apply tenant scoping — compose VisibilityGates::apply()
 * so the gate semantics stay identical.
 */
class DefaultGate implements DispatchGate
{
    public function isStaff(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function canSeeAll(?Authenticatable $user): bool
    {
        return false;
    }

    public function scopeVisible(Builder $query, ?Authenticatable $user): Builder
    {
        if ($this->canSeeAll($user)) {
            return $query;
        }

        return VisibilityGates::apply($query, $user, $this->isStaff($user));
    }
}
