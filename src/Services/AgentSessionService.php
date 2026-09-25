<?php

namespace Sgrjr\Dispatch\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sgrjr\Dispatch\Contracts\LaneResolver;
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
     *
     * `attachment` (TASK-1242) — download a task's (or comment's) attachment
     * bytes. A read, the same sensitivity tier as `show` (which already hands
     * the session internal notes and exception context).
     */
    public const KNOWN_VERBS = ['next', 'queue', 'show', 'add', 'note', 'done', 'claim', 'batch', 'handoff', 'perform', 'attachment'];

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

        // TASK-1021: the request is a TASK a human acts on, routed to the
        // approvers' lane and due when the request lapses. It rings like any
        // new lane work, so nobody has to go looking for /it/agent-sessions.
        $approvalTask = app(ApprovalTasks::class)->file($session);

        return [
            'public_id' => $session->public_id,
            'device_code' => $deviceCode, // returned ONCE
            'user_code' => $session->user_code,
            'approval_task' => $approvalTask?->code,
            'poll_interval' => (int) config('dispatch.agent.poll_interval', 5),
            'expires_at' => optional($session->expires_at)->toIso8601String(),
        ];
    }

    /**
     * Approve a pending session. Grants a server-bounded scope set: the caller's
     * explicit scopes (what the approver kept in the UI), else the ones requested
     * at request-time, else the full allowlist — see resolveGrant().
     *
     * $lane (TASK-999/R24) resolves the same way: what the approver chose,
     * else what the request named, else the host default. An explicit `''`
     * means "no lane" — an unrestricted session — and is distinct from null
     * ("the approver didn't touch it").
     *
     * @param  array<int,string>|null  $scopes
     */
    public function approve(AgentSession $session, int $userId, ?int $ttl = null, ?array $scopes = null, ?string $lane = null): AgentSession
    {
        $session->scopes = $this->resolveGrant($scopes ?? ($session->requested_meta['scopes'] ?? null));
        $session->lane = $this->resolveLane($lane ?? ($session->requested_meta['lane'] ?? null));
        $session->save();

        $session->approve($userId, $ttl ?? (int) config('dispatch.agent.session_ttl', 10800));

        // One closer (TASK-1021): approving from /it/agent-sessions or from the
        // approval task both land here, and the task closes as approved.
        if ($session->status === AgentSession::STATUS_APPROVED) {
            app(ApprovalTasks::class)->resolve(AgentSession::approvalKind(), $session->approvalId(), ApprovalTasks::APPROVED, $userId);
        }

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
     * TASK-999 (R24) — turn a requested lane into the lane actually granted.
     *
     *  - null (nobody named one) → the host default, `dispatch.agent.lane`;
     *  - `''` → no lane: an UNRESTRICTED session (the whole open board). The
     *    approver's deliberate "don't scope this one" — never widened into
     *    the default;
     *  - anything else → validated through the bound LaneResolver. An
     *    unrecognized key falls back to the default rather than silently
     *    becoming unrestricted: a lane that was renamed or retired must not
     *    turn into a wider grant than the requester asked for.
     *
     * Public for the same reason resolveGrant() is: the approval UI shows the
     * human the lane they are about to grant, rather than re-deriving it.
     */
    public function resolveLane(?string $lane): ?string
    {
        if ($lane === null) {
            $lane = config('dispatch.agent.lane');
            $lane = is_string($lane) ? $lane : null;

            if ($lane === null) {
                return null;
            }
        }

        $lane = trim($lane);

        if ($lane === '') {
            return null;
        }

        if (! app(LaneResolver::class)->isLane($lane)) {
            $fallback = config('dispatch.agent.lane');
            $fallback = is_string($fallback) ? trim($fallback) : '';

            return $fallback !== '' && $fallback !== $lane && app(LaneResolver::class)->isLane($fallback)
                ? $fallback
                : null;
        }

        return $lane;
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

    public function deny(AgentSession $session, ?int $userId = null): AgentSession
    {
        $session->deny();

        if ($session->status === AgentSession::STATUS_DENIED) {
            app(ApprovalTasks::class)->resolve(AgentSession::approvalKind(), $session->approvalId(), ApprovalTasks::DENIED, $userId);
        }

        return $session;
    }

    public function revoke(AgentSession $session): AgentSession
    {
        $wasPending = $session->status === AgentSession::STATUS_PENDING;
        $session->revoke();

        // Revoking a request nobody approved yet is a refusal as far as its
        // approval task goes. (An approved session's task is already closed.)
        if ($wasPending) {
            app(ApprovalTasks::class)->resolve(AgentSession::approvalKind(), $session->approvalId(), ApprovalTasks::DENIED);
        }

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
                        // TASK-999 — the GRANTED lane (which may differ from
                        // the requested one: the approver can change it). The
                        // agent needs to know what next/claim will offer it,
                        // and this is the only response that carries it.
                        'lane' => $locked->lane,
                        'scopes' => $locked->scopes,
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

        // The approval tasks' timeout (TASK-1021) rides the same schedule:
        // requests nobody decided close as EXPIRED, never as a refusal.
        app(ApprovalTasks::class)->expireDue();

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
