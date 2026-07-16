<?php

namespace Sgrjr\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;

class Task extends Model
{
    use SoftDeletes;

    public const TYPES = ['bug', 'feature', 'chore', 'debt', 'verify'];
    public const PRIORITIES = ['blocker', 'high', 'medium', 'low'];
    public const STATUSES = ['triage', 'open', 'in_progress', 'verifying', 'done', 'declined'];

    protected $table = 'dispatch_tasks';

    protected $fillable = [
        'code',
        'title',
        'description',
        'type',
        'priority',
        'status',
        'is_public',
        'submitter_user_id',
        'assignee_user_id',
        'exception_signature',
        'dedupe_key',
        'position',
        'context',
        'due_at',
        'duplicate_of',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'position' => 'integer',
        'context' => 'array',
        'due_at' => 'datetime',
    ];

    /**
     * Stable morph alias so polymorphic attachments keep working even when a
     * consuming app subclasses this model (config('dispatch.models.task')).
     */
    public function getMorphClass(): string
    {
        return 'dispatch_task';
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

    /**
     * Users watching this task for updates (in addition to the submitter and
     * assignee, who are always notified — see DispatchNotifier).
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(config('dispatch.models.user'), 'dispatch_task_watchers', 'task_id', 'user_id')->withTimestamps();
    }

    public function watch(int $userId): void
    {
        $this->watchers()->syncWithoutDetaching([$userId]);
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
