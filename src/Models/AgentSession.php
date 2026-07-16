<?php

namespace Sgrjr\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

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
class AgentSession extends Model
{
    protected $table = 'dispatch_agent_sessions';

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
        'status',
        'token_hash',
        'token_delivered_at',
        'approved_by_user_id',
        'approved_at',
        'expires_at',
        'last_used_at',
        'ip',
    ];

    protected $casts = [
        'requested_meta' => 'array',
        'scopes' => 'array',
        'token_delivered_at' => 'datetime',
        'approved_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
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

    public function revoke(): void
    {
        if (! in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPROVED], true)) {
            return;
        }

        $this->status = self::STATUS_REVOKED;
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
     * Bump last_used_at without touching updated_at or firing model events.
     * (Named markUsed(), NOT touch() — that would override Eloquent's touch().)
     */
    public function markUsed(): void
    {
        $this->last_used_at = now();
        $this->saveQuietly();
    }
}
