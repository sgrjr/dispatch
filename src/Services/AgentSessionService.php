<?php

namespace Sgrjr\Dispatch\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sgrjr\Dispatch\Models\AgentSession;

/**
 * Orchestrates the agent-session lifecycle (§20 Phase 1): request → human
 * approve/deny → poll-for-token → resolve on each request → prune.
 *
 * Security notes live with each method. The two invariants: the token is stored
 * only as a hash and delivered exactly once, and the server is the ceiling on
 * scopes (an agent can never grant itself a verb outside `agent.verbs`).
 */
class AgentSessionService
{
    /** user_code alphabet — no O/0/I/1/L so an operator can't misread it. */
    protected const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Every verb the package ships a route for — the package-owned source of
     * truth for what is grantable. The explicit-request grant ceiling is the
     * UNION of this and the host's `agent.verbs`, so a stale *published*
     * `config/dispatch.php` (shallow `mergeConfigFrom` can't deep-merge a
     * newly-shipped verb into an already-published array) can never silently
     * drop a verb the package actually registers — the recurring GAP-3
     * "stale-published-config" trap that most recently disabled `batch` and
     * 403'd every `todo:inbox --remote` push. A host still WITHHOLDS a verb via
     * the explicit `agent.disabled_verbs` denylist, not by omitting it here.
     *
     * `handoff` (TASK-997 part B) is the newest entrant — a host that
     * published `config/dispatch.php` before it existed keeps an
     * `agent.verbs` array missing it; this UNION is what still lets it be
     * explicitly requested (see UPGRADING.md).
     */
    public const KNOWN_VERBS = ['next', 'queue', 'show', 'add', 'note', 'done', 'claim', 'batch', 'handoff'];

    /**
     * Register a pending session and return the one-time bootstrap payload.
     * `device_code` is the RFC-8628 secret the agent must present on every poll;
     * only its hash is stored.
     *
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    public function request(string $name, ?string $purpose, array $meta = [], ?string $ip = null): array
    {
        $deviceCode = bin2hex(random_bytes(32));

        $session = AgentSession::create([
            'public_id' => (string) Str::uuid(),
            'agent_name' => Str::limit(trim($name) ?: 'agent', 120, ''),
            'purpose' => $purpose !== null ? Str::limit($purpose, 2000, '') : null,
            'user_code' => $this->generateUserCode(),
            'poll_secret_hash' => hash('sha256', $deviceCode),
            'requested_meta' => $meta ?: null,
            'status' => AgentSession::STATUS_PENDING,
            'expires_at' => now()->addSeconds((int) config('dispatch.agent.request_ttl', 900)),
            'ip' => $ip,
        ]);

        return [
            'public_id' => $session->public_id,
            'device_code' => $deviceCode, // returned ONCE
            'user_code' => $session->user_code,
            'poll_interval' => (int) config('dispatch.agent.poll_interval', 5),
            'expires_at' => optional($session->expires_at)->toIso8601String(),
        ];
    }

    /**
     * Approve a pending session. Grants a server-bounded scope set: the caller's
     * explicit scopes (what the approver kept in the UI), else the ones requested
     * at request-time, else the full allowlist — see resolveGrant().
     *
     * @param  array<int,string>|null  $scopes
     */
    public function approve(AgentSession $session, int $userId, ?int $ttl = null, ?array $scopes = null): AgentSession
    {
        $session->scopes = $this->resolveGrant($scopes ?? ($session->requested_meta['scopes'] ?? null));
        $session->save();

        $session->approve($userId, $ttl ?? (int) config('dispatch.agent.session_ttl', 10800));

        return $session;
    }

    /**
     * Turn a requested scope set into the grant it actually yields. Null (nothing
     * named) grants the host's whole allowlist; anything explicit is intersected
     * with the ceiling, so an explicit [] grants nothing.
     *
     * Public because the approval UI pre-checks exactly this set: the human sees
     * the grant they are about to make, not a re-derivation of it.
     *
     * @param  array<int,string>|null  $requested
     * @return array<int,string>
     */
    public function resolveGrant(?array $requested): array
    {
        if ($requested === null) {
            return $this->defaultGrant();
        }

        return array_values(array_intersect(array_map('strval', $requested), $this->grantCeiling()));
    }

    /**
     * What a request that named NO scopes is granted: the host's configured
     * allowlist, falling back to the package's KNOWN_VERBS (not []) when a
     * published `config/dispatch.php` omits `agent.verbs` — otherwise a stale
     * published config would make that path grant nothing at all (GAP-3 trap).
     *
     * @return array<int,string>
     */
    public function defaultGrant(): array
    {
        return array_values(array_map('strval', (array) config('dispatch.agent.verbs', self::KNOWN_VERBS)));
    }

    /**
     * The ceiling every EXPLICITLY requested scope is bounded by: the host
     * allowlist UNIONED with the package's KNOWN_VERBS, minus any explicit
     * `agent.disabled_verbs`. The union lets a verb the package actually ships
     * survive a stale published config (GAP-3); the denylist is how a host still
     * withholds one. Public because the approval UI has to show the human which
     * of the requested scopes are actually grantable — it must not re-derive
     * this rule (two copies of a ceiling is how one of them drifts).
     *
     * @return array<int,string>
     */
    public function grantCeiling(): array
    {
        return array_values(array_diff(
            array_unique(array_merge($this->defaultGrant(), self::KNOWN_VERBS)),
            array_map('strval', (array) config('dispatch.agent.disabled_verbs', []))
        ));
    }

    /**
     * Is this scope one of the board verbs the package ships a route for, as
     * opposed to a host-defined capability sharing the same column? The two are
     * different vocabularies (Centerpoint's `app.*` tool-surface scopes are the
     * live example), and the approver has to be able to tell them apart before
     * consenting. KNOWN_VERBS is the package-owned answer for its own half.
     */
    public static function isBoardVerb(string $scope): bool
    {
        return in_array($scope, self::KNOWN_VERBS, true);
    }

    public function deny(AgentSession $session): AgentSession
    {
        $session->deny();

        return $session;
    }

    public function revoke(AgentSession $session): AgentSession
    {
        $session->revoke();

        return $session;
    }

    /**
     * Resolve a bearer token to a usable session, or null. Deterministic indexed
     * hash lookup (no per-char timing leak) + constant-time compare + usability.
     */
    public function resolveToken(?string $bearer): ?AgentSession
    {
        $bearer = trim((string) $bearer);
        if ($bearer === '') {
            return null;
        }

        $hash = hash('sha256', $bearer);

        /** @var AgentSession|null $session */
        $session = AgentSession::query()->where('token_hash', $hash)->first();

        if ($session === null || ! hash_equals((string) $session->token_hash, $hash) || ! $session->isUsable()) {
            return null;
        }

        return $session;
    }

    /**
     * Poll a session by its public id + device_code (RFC-8628). On the first
     * approved poll, mint and deliver the token exactly once (atomic guard so two
     * concurrent polls can't both receive it).
     *
     * @return array<string,mixed>  status: not_found|invalid|pending|approved|denied|revoked|expired
     */
    public function poll(string $publicId, string $deviceCode): array
    {
        /** @var AgentSession|null $session */
        $session = AgentSession::query()->where('public_id', $publicId)->first();

        if ($session === null || ! hash_equals((string) $session->poll_secret_hash, hash('sha256', (string) $deviceCode))) {
            // Uniform "invalid": don't leak whether the public_id exists.
            return ['status' => 'invalid'];
        }

        $pollInterval = (int) config('dispatch.agent.poll_interval', 5);

        if ($session->status === AgentSession::STATUS_APPROVED && $session->token_delivered_at === null) {
            return DB::transaction(function () use ($session, $pollInterval) {
                /** @var AgentSession $locked */
                $locked = AgentSession::query()->whereKey($session->getKey())->lockForUpdate()->first();

                if ($locked->status === AgentSession::STATUS_APPROVED && $locked->token_delivered_at === null) {
                    $token = $locked->mintToken();

                    return [
                        'status' => AgentSession::STATUS_APPROVED,
                        'token' => $token,
                        'poll_interval' => $pollInterval,
                        'expires_at' => optional($locked->expires_at)->toIso8601String(),
                    ];
                }

                return ['status' => $locked->status, 'poll_interval' => $pollInterval];
            });
        }

        return ['status' => $session->status, 'poll_interval' => $pollInterval];
    }

    /**
     * Housekeeping: flip stale approved/pending rows to expired. Lazy expiry in
     * isUsable() is the security boundary; this is hygiene for the approval UI.
     */
    public function prune(): int
    {
        $now = now();

        $count = AgentSession::query()
            ->whereIn('status', [AgentSession::STATUS_APPROVED, AgentSession::STATUS_PENDING])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->update(['status' => AgentSession::STATUS_EXPIRED]);

        return (int) $count;
    }

    protected function generateUserCode(int $length = 8): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;

        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
