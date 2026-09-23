<?php

namespace Sgrjr\Dispatch\Models;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Sgrjr\Dispatch\Contracts\Approvable;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\ApprovalTasks;
use Sgrjr\Dispatch\Support\Lane;

/**
 * A human-commissioned, session-scoped credential for a remote agent (§19/§20).
 *
 * No standing key: an agent REQUESTS a session, a human in production approves
 * or denies it, and an approved session yields a short-TTL bearer token tied to
 * this row. The token is stored only as a sha256 hash; the plaintext is returned
 * exactly once (on the first approved poll). Authorization is by the SESSION,
 * not a User — an approved session is treated as staff-equivalent by the agent
 * surface because a human explicitly approved it.
 */
class AgentSession extends Model implements Approvable
{
    protected $table = 'dispatch_agent_sessions';

    // ── Approvable (TASK-1021): a request files an "Approval requested" task ──

    public static function approvalKind(): string
    {
        return 'agent_session';
    }

    public static function findForApproval(string $id): ?self
    {
        return static::query()->where('public_id', $id)->first();
    }

    public function approvalId(): string
    {
        return (string) $this->public_id;
    }

    public function approvalLabel(): string
    {
        return 'agent session for '.($this->agent_name ?: 'an agent');
    }

    /**
     * The task body. It carries the user_code, so the device-code check
     * survives: the approver confirms the code the agent printed.
     */
    public function approvalDetails(): string
    {
        $meta = $this->requested_meta ?? [];
        $lines = [
            "**{$this->agent_name}** is asking for a session to work the board.",
            '',
            "- **Code:** `{$this->user_code}` (confirm it matches what the agent printed)",
        ];
        if (filled($this->purpose)) {
            $lines[] = "- **Purpose:** {$this->purpose}";
        }
        $scopes = $meta['scopes'] ?? null;
        $lines[] = '- **Scopes asked for:** '.(is_array($scopes) && $scopes !== [] ? implode(', ', $scopes) : 'the default grant');
        if (filled($meta['lane'] ?? null)) {
            $lines[] = "- **Lane asked for:** {$meta['lane']}";
        }
        if ($this->ip) {
            $lines[] = "- **From:** {$this->ip}";
        }
        $lines[] = '';
        $lines[] = 'Approve or Deny here. It expires on its own if nobody decides.';

        return implode("\n", $lines);
    }

    public function approvalExpiresAt(): ?CarbonInterface
    {
        return $this->expires_at;
    }

    public function approvalLane(): ?string
    {
        return config('dispatch.agent.approval_lane') ?: null;
    }

    public function approvalIsPending(): bool
    {
        return $this->status === self::STATUS_PENDING
            && ($this->expires_at === null || now()->lt($this->expires_at));
    }

    /** How a decided request ended, for its approval task. */
    public function approvalOutcome(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED, self::STATUS_REVOKED => ApprovalTasks::APPROVED,
            self::STATUS_DENIED => ApprovalTasks::DENIED,
            default => ApprovalTasks::EXPIRED,
        };
    }

    /** @param array{ttl?:?int, scopes?:?array, lane?:?string} $options */
    public function approveBy(Authenticatable $user, array $options = []): void
    {
        app(AgentSessionService::class)->approve(
            $this,
            (int) $user->getAuthIdentifier(),
            isset($options['ttl']) ? (int) $options['ttl'] ?: null : null,
            $options['scopes'] ?? null,
            array_key_exists('lane', $options) ? (string) ($options['lane'] ?? '') : null,
        );
    }

    public function denyBy(Authenticatable $user): void
    {
        app(AgentSessionService::class)->deny($this, (int) $user->getAuthIdentifier());
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED = 'denied';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'public_id',
        'agent_name',
        'purpose',
        'user_code',
        'poll_secret_hash',
        'requested_meta',
        'scopes',
        'lane',
        'status',
        'token_hash',
        'token_delivered_at',
        'approved_by_user_id',
        'approved_at',
        'expires_at',
        'last_used_at',
        'ip',
        'metrics',
        'ended_at',
    ];

    protected $casts = [
        'requested_meta' => 'array',
        'scopes' => 'array',
        'token_delivered_at' => 'datetime',
        'approved_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'metrics' => 'array',
        'ended_at' => 'datetime',
    ];

    /**
     * Never serialize the token/poll hashes — even hashed, they are secrets.
     *
     * @var array<int,string>
     */
    protected $hidden = ['token_hash', 'poll_secret_hash'];

    /**
     * Mint the session token: a 256-bit CSPRNG value, stored only as a sha256
     * hash. Returned ONCE (plaintext never persisted). Stamps
     * token_delivered_at so a second poll can't re-deliver it.
     */
    public function mintToken(): string
    {
        $plain = bin2hex(random_bytes(32));

        $this->token_hash = hash('sha256', $plain);
        $this->token_delivered_at = now();
        $this->save();

        return $plain;
    }

    /**
     * Approve a pending session for a TTL (no-op if not pending). Scopes are set
     * by AgentSessionService (server-bounded) before this is called.
     */
    public function approve(int $userId, int $ttlSeconds): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            return;
        }

        $this->status = self::STATUS_APPROVED;
        $this->approved_by_user_id = $userId;
        $this->approved_at = now();
        $this->expires_at = now()->addSeconds(max(1, $ttlSeconds));
        $this->save();
    }

    public function deny(): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            return;
        }

        $this->status = self::STATUS_DENIED;
        $this->save();
    }

    /**
     * Revoke covers BOTH lifecycle exits — an agent's own `session:end` and a
     * human's revoke button — so ended_at is stamped here, once, for both.
     */
    public function revoke(): void
    {
        if (! in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPROVED], true)) {
            return;
        }

        $this->status = self::STATUS_REVOKED;
        $this->ended_at = now();
        $this->save();
    }

    /**
     * The authoritative usability gate — recomputed EVERY call, never cached, so
     * a mid-session revoke or TTL expiry bites on the very next request.
     */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->expires_at !== null
            && now()->lt($this->expires_at);
    }

    /**
     * TASK-999 (R24) — the lanes this session's `next`/`claim` are SERVED,
     * or null when the session carries no lane (unrestricted: the whole open
     * board, the pre-TASK-999 behavior).
     *
     * The granted `lane` plus every lane it sits under, so a
     * `marketing:developer` session is also served work addressed to the
     * whole `marketing` department but never to `marketing:sales`.
     * {@see \Sgrjr\Dispatch\Support\Lane::selfAndAncestors()}
     *
     * @return array<int,string>|null
     */
    public function servedLanes(): ?array
    {
        $lane = is_string($this->lane) ? trim($this->lane) : '';

        return $lane === '' ? null : Lane::selfAndAncestors($lane);
    }

    /**
     * Bump last_used_at without touching updated_at or firing model events.
     * (Named markUsed(), NOT touch() — that would override Eloquent's touch().)
     */
    public function markUsed(): void
    {
        $this->last_used_at = now();
        $this->saveQuietly();
    }

    /**
     * How many sessions are awaiting a human decision — powers the "Agent
     * Sessions" nav badge. Rescue-wrapped so a host that enabled the feature but
     * hasn't run the migration yet renders 0 instead of erroring in the shared
     * layout (the nav renders on every package page).
     */
    public static function pendingCount(): int
    {
        return (int) rescue(
            fn () => static::query()->where('status', self::STATUS_PENDING)->count(),
            0,
            false,
        );
    }
}
