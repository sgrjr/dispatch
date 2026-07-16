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
     * explicit scopes, else the ones requested at request-time, else the full
     * allowlist — always intersected with `agent.verbs` (an explicit [] grants
     * nothing).
     *
     * @param  array<int,string>|null  $scopes
     */
    public function approve(AgentSession $session, int $userId, ?int $ttl = null, ?array $scopes = null): AgentSession
    {
        $allowed = (array) config('dispatch.agent.verbs', []);

        $requested = $scopes ?? ($session->requested_meta['scopes'] ?? null);

        $granted = $requested === null
            ? $allowed
            : array_values(array_intersect(array_map('strval', (array) $requested), $allowed));

        $session->scopes = $granted;
        $session->save();

        $session->approve($userId, $ttl ?? (int) config('dispatch.agent.session_ttl', 3600));

        return $session;
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
