<?php

namespace Sgrjr\Dispatch\Contracts;

/**
 * Topic seam (TASK-995) — what a task is ABOUT (an account, a plan, a title,
 * an order, …), keyed by the generic `topic_type`/`topic_id` pair.
 *
 * This resolver never filters a query and never widens visibility: it only
 * resolves an anchor into a display label/URL/model, and stamps the
 * `topic_account_key` rollup. If an app's visibility depends on the topic's
 * account, that belongs in its {@see DispatchGate} — the ONE scope — never
 * here.
 */
interface TopicResolver
{
    /**
     * The model/object a topic anchor points at, or null when it can't be
     * resolved (a stale id, a type the host no longer recognizes).
     */
    public function resolve(string $type, string $id): mixed;

    /**
     * A human label for the anchor (e.g. "Account 0402100000001"), even when
     * resolve() would return null — a label should degrade gracefully, not
     * throw, so a stale topic still renders something.
     */
    public function label(string $type, string $id): string;

    /**
     * A link to the topic's own page, or null when there isn't one.
     */
    public function url(string $type, string $id): ?string;

    /**
     * The `topic_account_key` rollup for this anchor, or null when the topic
     * has no associated account (or can't be resolved). Called by Task's
     * `saving` hook whenever topic_type/topic_id changes — see
     * Task::setTopic().
     */
    public function accountKey(string $type, string $id): ?string;
}
