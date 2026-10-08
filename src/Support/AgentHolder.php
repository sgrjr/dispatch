<?php

namespace Sgrjr\Dispatch\Support;

/**
 * TASK-1059 — the AGENT HOLDER: the one user whose name on a task means
 * "waiting for an agent". Configured by `dispatch.agent.holder` (a user id or
 * an email); null (the default) leaves the feature off, and every path that
 * consults it behaves exactly as it did before.
 *
 * Why a SHARED identity: a hand-off must outlive the session that will pick it
 * up (sessions expire in hours), so the ball is passed to "an agent", not to
 * one session. Per-agent attribution is untouched — the claimed event still
 * stamps `agent_name` + `agent_session_id`.
 *
 * ⛔ ROUTING ONLY. The holder is an inbox, never a login and never an
 * authority: nothing here grants it a scope, a session, or the right to act.
 *
 * Bound `scoped` in the container, so the id is resolved at most once per
 * request/job; the memo is also keyed by the configured value, so a config
 * change mid-process (tests) never answers with a stale id.
 */
class AgentHolder
{
    private mixed $resolvedFor = null;

    private ?int $id = null;

    private bool $resolved = false;

    /** The holder's user id, or null when the feature is off (or the user is missing). */
    public function id(): ?int
    {
        $configured = config('dispatch.agent.holder');

        if ($configured === null || $configured === '') {
            return null;
        }

        if (! $this->resolved || $this->resolvedFor !== $configured) {
            $this->resolvedFor = $configured;
            $this->id = $this->resolve($configured);
            $this->resolved = true;
        }

        return $this->id;
    }

    /** Is the holder configured AND resolvable? */
    public function enabled(): bool
    {
        return $this->id() !== null;
    }

    /** Whether $userId is the holder. Always false when the feature is off. */
    public function is(int|string|null $userId): bool
    {
        $id = $this->id();

        return $id !== null && $userId !== null && (int) $userId === $id;
    }

    private function resolve(mixed $ref): ?int
    {
        /** @var class-string|null $userModel */
        $userModel = config('dispatch.models.user');

        if (! is_string($userModel) || ! class_exists($userModel)) {
            return null;
        }

        $ref = trim((string) $ref);

        $user = str_contains($ref, '@')
            ? $userModel::query()->where('email', $ref)->first()
            : (ctype_digit($ref) ? $userModel::query()->find((int) $ref) : null);

        return $user ? (int) $user->getKey() : null;
    }
}
