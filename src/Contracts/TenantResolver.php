<?php

namespace Sgrjr\Dispatch\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenancy seam.
 *
 * This seam only STAMPS and REPORTS a tenant; it never filters a query. If an
 * app's visibility depends on tenant, its {@see DispatchGate} consults this
 * resolver internally — keeping all query filtering in the single Gate scope.
 *
 * The package core schema has no tenant column. A consuming app adds its own
 * column (int org id, string account key, morph, …) via its own migration + a
 * Task subclass, and this resolver writes to it on create.
 */
interface TenantResolver
{
    /**
     * The tenant the given user currently acts within, or null if not
     * tenant-scoped. May be an int, a string key, or null.
     */
    public function currentTenant(?Authenticatable $user): int|string|null;

    /**
     * Stamp the app's tenant column(s) onto a task being created. No-op for
     * single-tenant apps.
     */
    public function stamp(Model $task, ?Authenticatable $user): void;
}
