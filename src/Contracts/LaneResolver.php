<?php

namespace Sgrjr\Dispatch\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Lane seam (TASK-997 part A, rulings R14/R15/R21/R22/R23) — the DEPARTMENT (or
 * a role-named sub-lane of a department) that does a task's work. A lane is
 * ONE string: `<department>` or `<department>:<role>` (e.g. `marketing`,
 * `marketing:developer`, `marketing:sales`, `customer_service`). The package
 * treats lane keys as opaque strings the host validates — the only structure
 * it knows is "the part before the first `:` is the department" (see
 * {@see \Sgrjr\Dispatch\Support\Lane::department()}).
 *
 * A lane decides ROUTING, never visibility (R14): this resolver — like
 * TopicResolver/OriginResolver — never filters a query or widens who can see a
 * task. {@see \Sgrjr\Dispatch\Support\VisibilityGates} does not consult `lane`.
 *
 * `NULL` on the task's `lane` column is the NO-DEPARTMENT lane (R15): open,
 * every staff user sees it, any user or department can claim from it, and an
 * admin can route it anywhere. There is no method on this interface for "the
 * no-department lane" — it is represented by `null`/{@see
 * \Sgrjr\Dispatch\Support\Lane::NONE} at the Task/query layer, never a key a
 * resolver returns from isLane()/lanes().
 */
interface LaneResolver
{
    /**
     * Whether $lane is a valid, currently-recognized lane key (a department or
     * one of its sub-lanes). Every write path validates a non-null lane
     * through this before it's stored.
     */
    public function isLane(string $lane): bool;

    /**
     * A human label for the lane (e.g. "Marketing · Developer"), or null when
     * $lane isn't recognized. Degrades gracefully — never throws.
     */
    public function label(string $lane): ?string;

    /**
     * Every valid lane key, for pickers (the route-to select, dispatch:schema).
     *
     * @return array<int,string>
     */
    public function lanes(): array;

    /**
     * The lanes $user WORKS — i.e. the department(s)/sub-lane(s) they do work
     * in, used to auto-join a lane on claim and to gate a member's
     * claim-from-the-open-lane routing.
     *
     * @return array<int,string>
     */
    public function lanesFor(Authenticatable $user): array;

    /**
     * The lanes $user OVERSEES (manages), distinct from the lanes they work —
     * out of scope for part A's routing logic, but part of the seam so a host
     * can bind it once and have both questions answered consistently.
     *
     * @return array<int,string>
     */
    public function lanesManagedBy(Authenticatable $user): array;

    /**
     * User ids who work $lane (for a "route to a person on this lane" picker
     * — not used by part A's routing logic itself, which routes to a LANE, not
     * a person).
     *
     * @return array<int,int|string>
     */
    public function memberIds(string $lane): array;

    /**
     * May $user put ANY task in ANY lane (an admin), bypassing the "only from
     * the open lane, into your own lane" member restriction?
     */
    public function canRoute(Authenticatable $user): bool;
}
