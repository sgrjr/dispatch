<?php

namespace Sgrjr\Dispatch\Contracts;

/**
 * Origin seam (TASK-995) — where a task came FROM (a chat message, a
 * customer note, an exception, a contact-form submission, an out-of-band
 * channel like a phone call), keyed by the generic `origin_type`/`origin_id`
 * pair. `origin_id` is nullable: an out-of-band channel origin (`phone`,
 * `in_person`) sets only `origin_type` (R16a).
 *
 * Origin is write-once at the Task level (see Task::setOrigin()) — this
 * resolver only ever READS an anchor; it never filters a query.
 */
interface OriginResolver
{
    /**
     * The model/object an origin anchor points at, or null — always null for
     * a channel-only origin (no id to resolve).
     */
    public function resolve(string $type, ?string $id): mixed;

    /**
     * A human label (e.g. "Phone", "In person", "Exception #42"). Must
     * degrade gracefully for a channel-only or unresolvable origin, never
     * throw.
     */
    public function label(string $type, ?string $id): string;

    /**
     * A link to the origin (e.g. the source message/comment), or null when
     * there isn't one — always null for a channel-only origin.
     */
    public function url(string $type, ?string $id): ?string;
}
