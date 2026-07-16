<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Sgrjr\Dispatch\Contracts\TenantResolver;

/**
 * No-op tenancy: single-tenant apps use this. Stamps nothing, reports no tenant.
 */
class NullTenantResolver implements TenantResolver
{
    public function currentTenant(?Authenticatable $user): int|string|null
    {
        return null;
    }

    public function stamp(Model $task, ?Authenticatable $user): void
    {
        // no-op
    }
}
