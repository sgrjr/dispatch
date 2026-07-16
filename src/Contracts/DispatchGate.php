<?php

namespace Sgrjr\Dispatch\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Authorization seam.
 *
 * This is the ONLY place task visibility is decided. The board, the list, the
 * submitter portal, the CLI verbs, the sync API, and TaskPolicy all route
 * through {@see scopeVisible()} — there is no second query filter anywhere in
 * the package. A consuming app binds its own implementation to teach Dispatch
 * what "staff" and "sees everything" mean in its world.
 */
interface DispatchGate
{
    /**
     * May this user use the staff surfaces at all (board, list, CLI)?
     * Non-staff users are limited to their own submissions via the portal.
     */
    public function isStaff(?Authenticatable $user): bool;

    /**
     * May this user see every task regardless of submitter/tenant (superuser)?
     */
    public function canSeeAll(?Authenticatable $user): bool;

    /**
     * Constrain a task query to what $user is allowed to see. THE one scope.
     *
     * Typical shape: staff/super -> unconstrained (optionally tenant-limited);
     * everyone else -> own submissions plus public tasks in their tenant.
     */
    public function scopeVisible(Builder $query, ?Authenticatable $user): Builder;
}
