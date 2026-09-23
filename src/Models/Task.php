<?php

namespace Sgrjr\Dispatch\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Sgrjr\Dispatch\Contracts\OriginResolver;
use Sgrjr\Dispatch\Contracts\TopicResolver;
use Sgrjr\Dispatch\Support\Anchor;
use Sgrjr\Dispatch\Support\Lane;

class Task extends Model
{
    use SoftDeletes;

    public const TYPES = ['bug', 'feature', 'chore', 'debt', 'verify'];
    public const PRIORITIES = ['blocker', 'high', 'medium', 'low'];
    public const STATUSES = ['triage', 'open', 'in_progress', 'verifying', 'backburner', 'done', 'resolved', 'declined'];

    /**
     * Which STAFF see the task (W13-5). 'participants' = GATE A only
     * (submitter + assignee + watchers — the default for staff-created
     * tasks); 'staff' = GATE B, everyone on staff (per-task opt-in, and the
     * default for system/customer-originated tasks, which have no staff
     * circle yet and need triage). NOT config-driven vocab — the gates'
     * semantics are fixed. See Support\VisibilityGates.
     */
    public const VISIBILITY_PARTICIPANTS = 'participants';
    public const VISIBILITY_STAFF = 'staff';
    public const VISIBILITIES = [self::VISIBILITY_PARTICIPANTS, self::VISIBILITY_STAFF];

    /**
     * Due-date window buckets (MECE partition + the 'dated' convenience union).
     * Computed windows, NOT a workflow vocab — deliberately not config-driven,
     * so there is no `dispatch.workflow.*` override for these.
     */
    public const DUE_BUCKETS = ['overdue', 'today', 'week', 'month', 'later', 'none', 'dated'];

    protected $table = 'dispatch_tasks';

    protected $fillable = [
        'code',
        'title',
        'description',
        'type',
        'priority',
        'status',
        'is_public',
        'visibility',
        'submitter_user_id',
        'assignee_user_id',
        'assignee_group',
        'exception_signature',
        'dedupe_key',
        'position',
        'context',
        'due_at',
        'duplicate_of',
        // TASK-995 anchor fields. `topic_account_key` is deliberately NOT
        // fillable — it is a STORED rollup only the `saving` hook (via the
        // bound TopicResolver) may set; see restampTopicAccountKey().
        'topic_type',
        'topic_id',
        'origin_type',
        'origin_id',
        'conversation_id',
        // TASK-997 part A — the department (or role sub-lane) that WORKS this
        // task. Routing only — see VisibilityGates, which never reads this.
        'lane',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'position' => 'integer',
        'context' => 'array',
        'due_at' => 'datetime',
        'conversation_id' => 'integer',
    ];

    /**
     * TASK-995 — restamp `topic_account_key` on every save that touches the
     * topic, and guard origin's write-once invariant on every save
     * regardless of which write path (batch, agent API, CLI, Livewire, raw
     * attribute assignment) got there. This is the package's own `booted()` —
     * a host subclass that overrides `booted()` MUST call `parent::booted()`
     * or lose both.
     */
    protected static function booted(): void
    {
        static::saving(function (self $task) {
            $task->restampTopicAccountKeyIfDirty();
            $task->guardOriginImmutability();
            $task->guardApprovalLock();
            $task->guardStatusNote();
        });
    }

    /**
     * TASK-1193 — the note that EXPLAINS this save's status change. Transient
     * (never a column): a write path sets it with {@see withStatusNote()}
     * before `save()`, the saving hook refuses a move into a note-required
     * status ({@see noteRequiredStatuses()}) without one, and the path writes
     * it into the status-change event ({@see statusChangeBody()} /
     * {@see statusChangeMeta()}). A declared property, so Eloquent never
     * treats it as an attribute.
     */
    public ?string $statusNote = null;

    /** Depth of {@see replayingHistory()} — the import/sync bypass of the note guard. */
    protected static int $replayingHistory = 0;

    public function withStatusNote(?string $note): static
    {
        $note = $note === null ? null : trim($note);
        $this->statusNote = $note === '' ? null : $note;

        return $this;
    }

    /**
     * Run $fn with the note guard off. ONLY for replaying a snapshot of another
     * board (dispatch:import, the sync endpoint): the note already lives in the
     * source's timeline, and a mirror must be able to hold a resolved task.
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    public static function replayingHistory(callable $fn): mixed
    {
        static::$replayingHistory++;
        try {
            return $fn();
        } finally {
            static::$replayingHistory--;
        }
    }

    /**
     * `resolved` = dealt with, but NOT as written (TASK-1193). What actually
     * happened is the whole point of the status, so entering it without a
     * note is refused here, the one choke point every status write (the CLI,
     * the agent API, batch, the board, TaskShow, the host's verbs) passes
     * through. Filing a task straight into it is a status write too.
     */
    protected function guardStatusNote(): void
    {
        if (static::$replayingHistory > 0) {
            return;
        }

        if (! in_array($this->status, static::noteRequiredStatuses(), true)) {
            return;
        }

        if ($this->exists && ! $this->isDirty('status')) {
            return;
        }

        if ($this->statusNote === null || trim($this->statusNote) === '') {
            throw \Sgrjr\Dispatch\Exceptions\StatusNoteRequired::forStatus($this->status, $this->code);
        }
    }

    /**
     * The status-change event's body: the same sentence every surface writes,
     * then the note, when this change carried one (TASK-1193: "the note lands
     * on the timeline as the status event's body").
     */
    public function statusChangeBody(string $sentence): string
    {
        return $this->statusNote !== null ? $sentence."\n\n".$this->statusNote : $sentence;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed> $meta plus `note`, when this change carried one
     */
    public function statusChangeMeta(array $meta): array
    {
        return $this->statusNote !== null ? $meta + ['note' => $this->statusNote] : $meta;
    }

    /**
     * An approval task (TASK-1021) is decided, never "done'd". Its status, and
     * its `context.approval` marker, change only through the approval service
     * (Approve / Deny / expiry). This hook is the one choke point: every status
     * write in the package (done, the agent API, batch, board drag and bulk,
     * list bulk, TaskShow, claim, closing passes) is a model save, so all of
     * them land here. Nothing may FILE a task carrying the marker except the
     * service either.
     */
    protected function guardApprovalLock(): void
    {
        if (\Sgrjr\Dispatch\Services\ApprovalTasks::resolving()) {
            return;
        }

        $marker = \Sgrjr\Dispatch\Services\ApprovalTasks::MARKER;
        $now = is_array($this->context) ? ($this->context[$marker] ?? null) : null;

        if (! $this->exists) {
            if ($now !== null) {
                throw \Sgrjr\Dispatch\Exceptions\ApprovalTaskLocked::filing();
            }

            return;
        }

        $original = $this->getOriginal('context');
        $was = is_array($original) ? ($original[$marker] ?? null) : null;
        if ($was === null) {
            if ($now !== null) {
                throw \Sgrjr\Dispatch\Exceptions\ApprovalTaskLocked::filing();
            }

            return;
        }

        if ($this->isDirty('status') || $now != $was) {
            throw \Sgrjr\Dispatch\Exceptions\ApprovalTaskLocked::forTask($this->code);
        }
    }

    /**
     * Stable morph alias so polymorphic attachments keep working even when a
     * consuming app subclasses this model (config('dispatch.models.task')).
     */
    public function getMorphClass(): string
    {
        return 'dispatch_task';
    }

    /**
     * The home conversation/arc, when the host has configured one
     * (`dispatch.models.conversation`). No FK is declared in the package
     * migration — the host owns that table, if it has one at all.
     *
     * @throws \LogicException when no conversation model is configured. Callers
     *                         that don't know whether one is configured should
     *                         check `config('dispatch.models.conversation')`
     *                         first rather than catch this.
     */
    public function conversation(): BelongsTo
    {
        $model = config('dispatch.models.conversation');

        if ($model === null) {
            throw new \LogicException(
                'No conversation model configured (dispatch.models.conversation) — Task::conversation() is unavailable until a host sets it.'
            );
        }

        return $this->belongsTo($model, 'conversation_id');
    }

    /**
     * The topic anchor (TASK-995, R10) — what this task is ABOUT — lazily
     * resolved through the bound TopicResolver, or null when no topic is set.
     * Callers never touch `topic_type`/`topic_id` or the resolver directly.
     */
    public function getTopicAttribute(): ?Anchor
    {
        if ($this->topic_type === null) {
            return null;
        }

        return new Anchor($this->topic_type, $this->topic_id, app(TopicResolver::class));
    }

    /**
     * The origin anchor (TASK-995, R10) — where this task came FROM — lazily
     * resolved through the bound OriginResolver, or null when no origin is
     * set.
     */
    public function getOriginAttribute(): ?Anchor
    {
        if ($this->origin_type === null) {
            return null;
        }

        return new Anchor($this->origin_type, $this->origin_id, app(OriginResolver::class));
    }

    /**
     * Set (or change) the task's topic. `topic_account_key` is restamped
     * immediately (not only at save time) so code reading it back before
     * save() sees the current rollup; the `saving` hook restamps again for
     * every OTHER write path, so this method is a convenience, not the only
     * way the rollup stays honest. A null `$type` clears the topic (and its
     * id and account key) entirely.
     */
    public function setTopic(?string $type, ?string $id): void
    {
        $this->topic_type = $type;
        $this->topic_id = $type !== null ? $id : null;
        $this->restampTopicAccountKey();
    }

    /**
     * Set the task's origin. Origin is WRITE-ONCE (R16a): freely settable
     * while `origin_type` is null, rejected once it's set to anything else —
     * this is the primary enforcement point; guardOriginImmutability() in the
     * `saving` hook is the belt for any path that bypasses this method.
     *
     * @throws \LogicException when origin is already set to a different value.
     */
    public function setOrigin(?string $type, ?string $id): void
    {
        $id = $type !== null ? $id : null;

        if ($this->origin_type !== null && ($this->origin_type !== $type || $this->origin_id !== $id)) {
            $was = $this->origin_type.($this->origin_id !== null ? ":{$this->origin_id}" : '');

            throw new \LogicException("Task origin is immutable once set (was `{$was}`).");
        }

        $this->origin_type = $type;
        $this->origin_id = $id;
    }

    /**
     * Restamp `topic_account_key` from the CURRENT topic_type/topic_id via the
     * bound TopicResolver — null when the topic is cleared or the resolver has
     * nothing for it. Unconditional (always recomputes): called directly by
     * setTopic() for its immediate, read-your-write effect, where an isDirty()
     * check would be unreliable (two setTopic() calls before any save() both
     * compare against the same unsynced `original`, so a set-then-clear can
     * look like a no-op change even though the value truly changed).
     */
    protected function restampTopicAccountKey(): void
    {
        if ($this->topic_type === null) {
            $this->topic_account_key = null;

            return;
        }

        $this->topic_account_key = app(TopicResolver::class)->accountKey($this->topic_type, (string) $this->topic_id);
    }

    /**
     * The `saving` hook's entry point: restamps only when topic_type/topic_id
     * is actually dirty relative to the row's last-synced state, so a save
     * that never touches the topic never pays for a resolver call. Safe here
     * (unlike inside setTopic()) because a genuine mutation on an already-
     * loaded/created row always shows up as dirty at save time.
     */
    protected function restampTopicAccountKeyIfDirty(): void
    {
        if ($this->isDirty(['topic_type', 'topic_id'])) {
            $this->restampTopicAccountKey();
        }
    }

    /**
     * The write-once backstop (R16a): once `origin_type` has a value ON THE
     * ROW (getOriginal — i.e. it survived a prior save), any save that would
     * change origin_type or origin_id is rejected. A brand-new, not-yet-saved
     * task has no "original" yet, so creation with an origin already set is
     * always allowed. This is the "belt" for callers that bypass setOrigin()
     * (batch/API/CLI validate up front and should never reach it in practice).
     */
    protected function guardOriginImmutability(): void
    {
        $originalType = $this->getOriginal('origin_type');
        if ($originalType === null) {
            return;
        }

        if ($this->origin_type !== $originalType || $this->origin_id !== $this->getOriginal('origin_id')) {
            throw new \LogicException("Task origin is immutable once set (was `{$originalType}`).");
        }
    }

    /**
     * Tasks whose topic is this exact type (+ id, when given). Omitting $id
     * matches ANY id of that type.
     */
    public function scopeAboutTopic(Builder $query, string $type, ?string $id = null): Builder
    {
        return $query->where('topic_type', $type)
            ->when($id !== null, fn (Builder $q) => $q->where('topic_id', $id));
    }

    /**
     * Tasks whose origin is this exact type (+ id, when given). Omitting $id
     * matches ANY id of that type (including channel-only rows with a null
     * origin_id).
     */
    public function scopeFromOrigin(Builder $query, string $type, ?string $id = null): Builder
    {
        return $query->where('origin_type', $type)
            ->when($id !== null, fn (Builder $q) => $q->where('origin_id', $id));
    }

    public function scopeInConversation(Builder $query, int $id): Builder
    {
        return $query->where('conversation_id', $id);
    }

    /**
     * TASK-1001 (R7/R8) — the ARC this task belongs to: every task sharing
     * its home conversation, in BIRTH ORDER (id, not created_at: two tasks
     * minted in the same batch share a timestamp, and the arc's whole point
     * is a stable reading order).
     *
     * $includeSelf false gives "the siblings", which is what a task's own
     * view wants. A task with no conversation has no arc — an empty
     * collection, never "every unhomed task".
     *
     * Deliberately NOT visibility-scoped: like the anchor and lane seams,
     * this answers a structural question. A caller rendering the arc to a
     * human applies its own DispatchGate scope — see VisibilityGates.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,static>
     */
    public function arc(bool $includeSelf = false)
    {
        if ($this->conversation_id === null) {
            return $this->newCollection();
        }

        return static::query()
            ->inConversation((int) $this->conversation_id)
            ->when(! $includeSelf, fn (Builder $q) => $q->whereKeyNot($this->getKey()))
            ->orderBy('id')
            ->get();
    }

    /**
     * Tasks whose topic rolls up to this account key. NEVER a visibility
     * scope — see VisibilityGates, which does not consult this column.
     */
    public function scopeAboutAccount(Builder $query, string $key): Builder
    {
        return $query->where('topic_account_key', $key);
    }

    /**
     * TASK-997 part A — tasks in $lane, with DEPARTMENT-vs-EXACT semantics
     * (R15): a bare department key (`marketing`) matches that department AND
     * every one of its sub-lanes (`lane = 'marketing' OR lane LIKE
     * 'marketing:%'`); a `dept:role` key matches exactly. {@see Lane::NONE}
     * ('none') is the reserved token for the NO-DEPARTMENT lane and matches
     * `whereNull('lane')` — never a real lane key. Routing only — never a
     * visibility scope; see VisibilityGates, which does not consult `lane`.
     */
    public function scopeInLane(Builder $query, string $lane): Builder
    {
        if ($lane === Lane::NONE) {
            return $query->whereNull('lane');
        }

        if (Lane::isSubLane($lane)) {
            return $query->where('lane', $lane);
        }

        return $query->where(function (Builder $q) use ($lane) {
            $q->where('lane', $lane)->orWhere('lane', 'like', $lane.':%');
        });
    }

    /**
     * Tasks whose `lane` is EXACTLY one of $lanes (no department-vs-sub-lane
     * expansion) — what a personal "my lanes" view uses with
     * LaneResolver::lanesFor(), which already returns the user's specific
     * lane keys.
     *
     * @param  array<int,string>  $lanes
     */
    public function scopeInLanes(Builder $query, array $lanes): Builder
    {
        return $query->whereIn('lane', $lanes);
    }

    /**
     * Tasks in the NO-DEPARTMENT lane (R15) — open, every staff user sees
     * them, any user or department can claim from them.
     */
    public function scopeUnrouted(Builder $query): Builder
    {
        return $query->whereNull('lane');
    }

    /**
     * TASK-999 (R24) — the work a holder of $lanes is SERVED: those exact
     * lanes, plus (when $includeUnrouted) the no-department lane. Callers
     * pass an already-expanded set — {@see Lane::selfAndAncestors()} for a
     * single holder — so this scope never expands anything itself, and in
     * particular never reaches DOWN into sub-lanes the way
     * {@see scopeInLane()} does. A `marketing:developer` holder is served
     * `marketing:developer` + bare `marketing` + unrouted; never
     * `marketing:sales`, never another department.
     *
     * Routing only — never a visibility scope (R14). This narrows which
     * candidates `next`/`claim` OFFER; it does not decide who may read a
     * task (see VisibilityGates) and it never applies to claim-by-code.
     *
     * @param  array<int,string>  $lanes
     */
    public function scopeServedByLanes(Builder $query, array $lanes, bool $includeUnrouted = true): Builder
    {
        // Serving nothing must match NOTHING. An empty closure would add no
        // constraints and silently widen to the whole board — the exact
        // failure this scope exists to prevent.
        if ($lanes === [] && ! $includeUnrouted) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($lanes, $includeUnrouted) {
            if ($lanes !== []) {
                $q->whereIn('lane', $lanes);
            }

            if ($includeUnrouted) {
                $q->orWhereNull('lane');
            }
        });
    }

    /**
     * TASK-997 part B — the tasks that BLOCK this one (this task is the
     * blocked side of `dispatch_task_links`). Real dependencies, distinct
     * from labels/lane: created by {@see
     * \Sgrjr\Dispatch\Services\DispatchTaskService::linkBlockedBy()} (cycle-
     * checked there, never here), and by the Ask half of the hand-off.
     */
    public function blockedBy(): BelongsToMany
    {
        return $this->belongsToMany(config('dispatch.models.task'), 'dispatch_task_links', 'task_id', 'blocked_by_task_id')
            ->withPivot(['kind', 'created_by_user_id'])
            ->withTimestamps();
    }

    /**
     * The inverse: tasks THIS one blocks (this task is the blocker side).
     * What {@see \Sgrjr\Dispatch\Services\DispatchTaskService::notifyDependentsOfClosure()}
     * walks when this task reaches a terminal status.
     */
    public function blocks(): BelongsToMany
    {
        return $this->belongsToMany(config('dispatch.models.task'), 'dispatch_task_links', 'blocked_by_task_id', 'task_id')
            ->withPivot(['kind', 'created_by_user_id'])
            ->withTimestamps();
    }

    /**
     * Tasks currently held up by at least one ACTIVE (non-terminal) blocker.
     * A blocker that has already reached a terminal status ({@see
     * inactiveStatuses()}) no longer counts — the link row is kept for
     * history, but it stops gating. Deliberately dynamic (no stored
     * `blocked` boolean to fall out of sync): the same status check both
     * scopes share is the one and only source of truth.
     */
    public function scopeBlocked(Builder $query): Builder
    {
        return $query->whereHas(
            'blockedBy',
            fn (Builder $q) => $q->whereNotIn('status', static::inactiveStatuses())
        );
    }

    /** The inverse of {@see scopeBlocked()} — no active blocker, or none at all. */
    public function scopeUnblocked(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'blockedBy',
            fn (Builder $q) => $q->whereNotIn('status', static::inactiveStatuses())
        );
    }

    /** TASK-998 — each person's read cursor on this task ({@see TaskRead}). */
    public function reads(): HasMany
    {
        return $this->hasMany(TaskRead::class, 'task_id');
    }

    /**
     * TASK-998 — tasks with NEWS for this person: any timeline event (a
     * comment, a status or assignee change, a hand-off, an answer…) by SOMEONE
     * ELSE after they last looked. Never looked counts as never caught up.
     *
     * "Someone else" includes the system (a null user_id — an answer returned
     * by returnTheBall, a status the plan-request closer set): news you did not
     * make is news. Your own events never are.
     *
     * One correlated EXISTS, portable SQL: the cursor lookup is a scalar
     * subquery on dispatch_task_reads' (task_id, user_id) unique index, and
     * the events are read through dispatch_task_comments' (task_id, created_at)
     * index.
     */
    public function scopeWithNewsFor(Builder $query, int $userId): Builder
    {
        $tasks = $this->getTable();
        $events = (new (config('dispatch.models.task_comment')))->getTable();
        $reads = (new TaskRead)->getTable();

        return $query->whereExists(fn ($q) => $q
            ->selectRaw('1')
            ->from($events.' as news')
            ->whereColumn('news.task_id', $tasks.'.id')
            ->where(fn ($who) => $who->whereNull('news.user_id')->orWhere('news.user_id', '!=', $userId))
            ->whereRaw(
                "news.created_at > COALESCE((SELECT cursor_row.read_at FROM {$reads} cursor_row WHERE cursor_row.task_id = {$tasks}.id AND cursor_row.user_id = ?), '1970-01-01 00:00:00')",
                [$userId],
            ));
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(config('dispatch.models.user'), 'submitter_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(config('dispatch.models.user'), 'assignee_user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(config('dispatch.models.task_comment'), 'task_id')->orderBy('created_at');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(config('dispatch.models.label'), 'dispatch_task_label')->withTimestamps();
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(config('dispatch.models.task_attachment'), 'attachable');
    }

    /** Watch preference modes (dispatch_task_watchers.notify_on). Null pivot = ANY. */
    public const WATCH_ANY = 'any';

    public const WATCH_STATUS_CHANGE = 'status_change';

    /**
     * Users watching this task for updates (in addition to the submitter and
     * assignee, who are always notified — see DispatchNotifier). The pivot
     * carries the W13-1 preference columns: notify_on (null/'any' = every
     * update; 'status_change' = status changes only) and notify_statuses
     * (JSON array of TO-statuses narrowing 'status_change'; null = all).
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(config('dispatch.models.user'), 'dispatch_task_watchers', 'task_id', 'user_id')
            ->withPivot(['notify_on', 'notify_statuses'])
            ->withTimestamps();
    }

    /**
     * Start watching (idempotent). Preferences are OPTIONAL and only written
     * when given — re-watching without them never clobbers a stored choice.
     *
     * @param  array<int,string>|null  $statuses
     */
    public function watch(int $userId, ?string $notifyOn = null, ?array $statuses = null): void
    {
        $pivot = [];
        if ($notifyOn !== null) {
            $pivot['notify_on'] = $notifyOn;
            $pivot['notify_statuses'] = ($statuses === null || $statuses === []) ? null : json_encode(array_values($statuses));
        }

        $this->watchers()->syncWithoutDetaching([$userId => $pivot]);
    }

    /**
     * Overwrite an existing watcher's notification preferences. A no-op for a
     * non-watcher (use watch() to subscribe). Passing WATCH_ANY (or null)
     * resets to the every-update default; $statuses only means anything under
     * WATCH_STATUS_CHANGE.
     *
     * @param  array<int,string>|null  $statuses
     */
    public function setWatchPreferences(int $userId, ?string $notifyOn, ?array $statuses = null): void
    {
        if (! $this->isWatchedBy($userId)) {
            return;
        }

        $this->watchers()->updateExistingPivot($userId, [
            'notify_on' => ($notifyOn === null || $notifyOn === self::WATCH_ANY) ? null : $notifyOn,
            'notify_statuses' => ($notifyOn === self::WATCH_STATUS_CHANGE && $statuses !== null && $statuses !== [])
                ? json_encode(array_values($statuses))
                : null,
        ]);
    }

    /**
     * The current user's decoded watch preferences, or null when not watching:
     * ['notify_on' => 'any'|'status_change', 'statuses' => array|null].
     *
     * @return array{notify_on:string,statuses:?array<int,string>}|null
     */
    public function watchPreferencesFor(int $userId): ?array
    {
        $row = $this->watchers()->where('user_id', $userId)->first();

        if ($row === null) {
            return null;
        }

        $statuses = $row->pivot->notify_statuses;
        if (is_string($statuses)) {
            $statuses = json_decode($statuses, true);
        }

        return [
            'notify_on' => $row->pivot->notify_on ?? self::WATCH_ANY,
            'statuses' => is_array($statuses) && $statuses !== [] ? array_values($statuses) : null,
        ];
    }

    public function unwatch(int $userId): void
    {
        $this->watchers()->detach($userId);
    }

    public function isWatchedBy(int $userId): bool
    {
        return $this->watchers()->where('user_id', $userId)->exists();
    }

    /**
     * Display name for the assignee slot: the user's name, the group as
     * "Team <name>" (W13-4), or null when unassigned. The one place blades
     * resolve the user-vs-group split.
     */
    public function assigneeLabel(): ?string
    {
        if ($this->assignee_group) {
            return 'Team '.$this->assignee_group;
        }

        return $this->assignee_user_id ? $this->assignee?->name : null;
    }

    /**
     * The winning task, if this one was merged away as a duplicate.
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(config('dispatch.models.task'), 'duplicate_of');
    }

    /**
     * The configured type/priority/status vocab, falling back to the package's
     * built-in defaults. A consuming app overrides `dispatch.workflow.*` to
     * add/rename values without subclassing Task.
     *
     * @return array<int,string>
     */
    public static function types(): array
    {
        return (array) config('dispatch.workflow.types', self::TYPES);
    }

    /**
     * @return array<int,string>
     */
    public static function priorities(): array
    {
        return (array) config('dispatch.workflow.priorities', self::PRIORITIES);
    }

    /**
     * @return array<int,string>
     */
    public static function statuses(): array
    {
        return (array) config('dispatch.workflow.statuses', self::STATUSES);
    }

    /**
     * Display labels for each type, keyed by raw value. Uses the configured
     * `dispatch.workflow.type_labels` map if set; otherwise auto-humanizes
     * (`in_progress` -> `In Progress`) so custom vocab always has labels.
     *
     * @return array<string,string>
     */
    public static function typeLabels(): array
    {
        $configured = (array) config('dispatch.workflow.type_labels', []);

        return $configured !== [] ? $configured : self::humanize(self::types());
    }

    /**
     * @return array<string,string>
     */
    public static function priorityLabels(): array
    {
        $configured = (array) config('dispatch.workflow.priority_labels', []);

        return $configured !== [] ? $configured : self::humanize(self::priorities());
    }

    /**
     * @return array<string,string>
     */
    public static function statusLabels(): array
    {
        $configured = (array) config('dispatch.workflow.status_labels', []);

        return $configured !== [] ? $configured : self::humanize(self::statuses());
    }

    /**
     * @param  array<int,string>  $values
     * @return array<string,string>
     */
    private static function humanize(array $values): array
    {
        $labels = [];
        foreach ($values as $value) {
            $labels[$value] = ucwords(str_replace('_', ' ', (string) $value));
        }

        return $labels;
    }

    /**
     * The actionable-first grouping used to order the agent's `next`/`claim`
     * candidates: already-started or in-flight work (open/in_progress) sorts
     * ahead of everything else (triage), which only surfaces once nothing is
     * open. This is a 2-way GROUPING, deliberately NOT a status rank — do not
     * substitute {@see statusSql()} (the full configured rank) here; ordering
     * on the whole rank would reshuffle the actionable tier by status.
     */
    public static function actionableFirstSql(): string
    {
        return "CASE WHEN status IN ('open', 'in_progress') THEN 0 ELSE 1 END";
    }

    /**
     * A `CASE {column} WHEN '<p0>' THEN 0 ... ELSE <count> END` SQL fragment
     * ranking priorities() in configured order (index = rank), replacing the
     * hardcoded CASE that used to live inline in the board/list query.
     */
    public static function prioritySql(string $column = 'priority'): string
    {
        return self::rankSql($column, self::priorities());
    }

    /**
     * Same shape as {@see prioritySql()}, ranking statuses() in configured
     * order.
     */
    public static function statusSql(string $column = 'status'): string
    {
        return self::rankSql($column, self::statuses());
    }

    /**
     * @param  array<int,string>  $values
     */
    private static function rankSql(string $column, array $values): string
    {
        $whens = [];
        foreach (array_values($values) as $rank => $value) {
            $whens[] = "WHEN '".str_replace("'", "''", (string) $value)."' THEN {$rank}";
        }

        return "CASE {$column} ".implode(' ', $whens).' ELSE '.count($values).' END';
    }

    /**
     * TASK-1193 — the CLOSED statuses, the one answer to "is it finished?"
     * (a capture revives nothing closed, an approval/plan request is no longer
     * pending, a closed column sorts by recency). Each means exactly one thing:
     *   - done     = the prescribed work was completed, nothing left;
     *   - resolved = dealt with, but not as written (a note says what happened);
     *   - declined = not done, by decision.
     * ⛔ Never hard-code a list of these again: call this (or isClosed()).
     * `backburner` is PARKED, not closed — see {@see inactiveStatuses()}.
     *
     * @return array<int,string>
     */
    public static function closedStatuses(): array
    {
        return ['done', 'resolved', 'declined'];
    }

    public function isClosed(): bool
    {
        return in_array($this->status, static::closedStatuses(), true);
    }

    /**
     * Statuses that may only be ENTERED with a note saying what happened
     * ({@see guardStatusNote()}).
     *
     * @return array<int,string>
     */
    public static function noteRequiredStatuses(): array
    {
        return ['resolved'];
    }

    public static function requiresStatusNote(?string $status): bool
    {
        return in_array($status, static::noteRequiredStatuses(), true);
    }

    /**
     * Statuses excluded from "nag" signals (stale, overdue) and that no longer
     * gate a dependent: parked `backburner` plus every closed status. Not
     * config-driven on purpose: names absent from a custom status vocab
     * simply never match, so overdue degrades to purely date-based — the same
     * graceful degradation stale already exhibits.
     *
     * @return array<int,string>
     */
    public static function inactiveStatuses(): array
    {
        return ['backburner', ...static::closedStatuses()];
    }

    public function isInactive(): bool
    {
        return in_array($this->status, static::inactiveStatuses(), true);
    }

    /**
     * @return array<int,string>
     */
    public static function dueBuckets(): array
    {
        return self::DUE_BUCKETS;
    }

    /**
     * @return array<string,string>
     */
    public static function dueBucketLabels(): array
    {
        return [
            'overdue' => 'Overdue',
            'today' => 'Due today',
            'week' => 'Due this week',
            'month' => 'Due this month',
            'later' => 'Due later',
            'none' => 'No due date',
            'dated' => 'Has due date',
        ];
    }

    /**
     * Half-open [lo, hi) day-boundary instants, app timezone, rolling from
     * today: overdue < start | today [start, +1d) | week [+1d, +8d) |
     * month [+8d, +31d) | later >= +31d. Half-open datetime intervals stay
     * MECE even when due_at carries a time component (the UI writes date-only
     * midnights; the agent API may write full timestamps).
     *
     * @return array{start:\Illuminate\Support\Carbon,tomorrow:\Illuminate\Support\Carbon,weekEnd:\Illuminate\Support\Carbon,monthEnd:\Illuminate\Support\Carbon}
     */
    protected static function dueBucketBoundaries(): array
    {
        $start = now()->startOfDay();

        return [
            'start' => $start,
            'tomorrow' => $start->copy()->addDay(),
            'weekEnd' => $start->copy()->addDays(8),
            'monthEnd' => $start->copy()->addDays(31),
        ];
    }

    /**
     * OR'd within-axis due-bucket clauses (mirroring the whereIn semantics of
     * the other filter axes), AND'd with everything outside the wrapping
     * where(). 'overdue' applies only to active tasks — an inactive
     * (backburner/done/declined) task with a past due date matches no range
     * bucket, only 'dated'. That means a selection of every bucket EXCEPT
     * 'dated' hides such tasks — by design, not a bug: closed/parked work is
     * never "overdue".
     *
     * Carbon instances are passed straight to where() so the grammar formats
     * them — portable across SQLite and MySQL with no SQL date functions.
     *
     * @param  array<int,string>  $buckets
     */
    public function scopeDueInBuckets(Builder $query, array $buckets): Builder
    {
        if ($buckets === []) {
            return $query;
        }

        $b = static::dueBucketBoundaries();

        return $query->where(function (Builder $q) use ($buckets, $b) {
            foreach ($buckets as $bucket) {
                $q->orWhere(function (Builder $w) use ($bucket, $b) {
                    match ($bucket) {
                        'overdue' => $w->where('due_at', '<', $b['start'])
                            ->whereNotIn('status', static::inactiveStatuses()),
                        'today' => $w->where('due_at', '>=', $b['start'])->where('due_at', '<', $b['tomorrow']),
                        'week' => $w->where('due_at', '>=', $b['tomorrow'])->where('due_at', '<', $b['weekEnd']),
                        'month' => $w->where('due_at', '>=', $b['weekEnd'])->where('due_at', '<', $b['monthEnd']),
                        'later' => $w->where('due_at', '>=', $b['monthEnd']),
                        'none' => $w->whereNull('due_at'),
                        'dated' => $w->whereNotNull('due_at'),
                        default => $w->whereRaw('1 = 0'),
                    };
                });
            }
        });
    }

    /**
     * Which bucket this task's due date falls in — the same boundaries the
     * due filter queries against, so badge tiers and filter results always
     * agree. Null when due_at is null, AND for an inactive task with a past
     * due date (overdue never applies to closed/parked tasks).
     */
    public function dueBucket(): ?string
    {
        if ($this->due_at === null) {
            return null;
        }

        $b = static::dueBucketBoundaries();

        return match (true) {
            $this->due_at->lt($b['start']) => $this->isInactive() ? null : 'overdue',
            $this->due_at->lt($b['tomorrow']) => 'today',
            $this->due_at->lt($b['weekEnd']) => 'week',
            $this->due_at->lt($b['monthEnd']) => 'month',
            default => 'later',
        };
    }

    /**
     * The next unused task code (e.g. TASK-004). Prefix is configurable.
     *
     * Portable across MySQL and SQLite: scans existing codes and finds the
     * highest in PHP. NOT collision-proof on its own — {@see createWithCode()}
     * pairs it with the unique index + retry to be race-safe.
     */
    public static function mintCode(): string
    {
        $prefix = (string) config('dispatch.code_prefix', 'TASK');
        $offset = strlen($prefix) + 1; // prefix + '-'

        $max = 0;
        foreach (static::withTrashed()->where('code', 'like', $prefix.'-%')->pluck('code') as $code) {
            $n = (int) substr((string) $code, $offset);
            if ($n > $max) {
                $max = $n;
            }
        }

        return $prefix.'-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Create a task with a race-safe minted code. If two requests mint the same
     * code concurrently, the unique index rejects the loser and we remint and
     * retry. An explicit `code` in $attributes is honored and never reminted.
     *
     * $beforeSave runs on the filled-but-unsaved model (e.g. so a TenantResolver
     * can stamp an app-specific column that isn't in the base $fillable).
     */
    public static function createWithCode(array $attributes, ?callable $beforeSave = null): static
    {
        $explicit = array_key_exists('code', $attributes) && $attributes['code'] !== null && $attributes['code'] !== '';

        /** @var static $model */
        $model = new static();
        $model->fill($attributes);

        if ($beforeSave !== null) {
            $beforeSave($model);
        }

        for ($attempt = 1; ; $attempt++) {
            if (! $explicit) {
                $model->code = static::mintCode();
            }

            try {
                $model->save();

                return $model;
            } catch (QueryException $e) {
                if (! $explicit && $attempt < 5 && static::isDuplicateCodeError($e)) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Whether a QueryException is a unique-constraint violation. Public so the
     * service layer can detect a lost `dedupe_key` race (firstOrCreateByKey).
     */
    public static function isDuplicateCodeError(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        return $sqlState === '23000'
            || str_contains(strtolower($e->getMessage()), 'unique')
            || str_contains(strtolower($e->getMessage()), 'duplicate');
    }

    /**
     * Record a system event on the timeline (no human body by default).
     */
    public function recordEvent(string $eventType, ?int $userId = null, array $meta = [], ?string $body = null, bool $isInternal = false): Model
    {
        return $this->comments()->create([
            'user_id' => $userId,
            'body' => $body ?? '',
            'event_type' => $eventType,
            'meta' => $meta ?: null,
            'is_internal' => $isInternal,
        ]);
    }
}
