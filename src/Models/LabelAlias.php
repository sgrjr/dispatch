<?php

namespace Sgrjr\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * A retired label name that redirects to its canonical label. Written by
 * LabelCleanupService::replace() — never by hand — so that folding
 * `area:acct` into `area:accounts` STAYS folded: every later attach
 * (dispatch:add/done --label, batch, capture, the create form) and every
 * `--label` filter resolves the old name to the canonical one instead of
 * quietly re-minting the label the cleanup just removed.
 *
 * A retire leaves no alias: a retired name that comes back is a new label.
 */
class LabelAlias extends Model
{
    protected $table = 'dispatch_label_aliases';

    protected $fillable = [
        'name',
        'label_id',
    ];

    /** Per-process memo of whether the aliases table exists (see canonicalize()). */
    protected static ?bool $tableExists = null;

    public function label(): BelongsTo
    {
        return $this->belongsTo(config('dispatch.models.label'), 'label_id');
    }

    /**
     * Map label names through the alias table: an alias becomes its canonical
     * label's name, anything else passes through untouched. Names are trimmed,
     * blanks dropped, and the result de-duplicated in first-appearance order —
     * two aliases of one label collapse to one name. One query, none when the
     * input is empty.
     *
     * Degrades to a pass-through when the table is missing, because this sits
     * on the task-creation path (exception capture included): a host that
     * upgraded the package but has not yet run the migration must keep filing
     * tasks, just without alias resolution.
     *
     * @param  iterable<int,mixed>  $names
     * @return array<int,string>
     */
    public static function canonicalize(iterable $names): array
    {
        $clean = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $clean[] = $name;
            }
        }

        if ($clean === [] || ! static::tableExists()) {
            return array_values(array_unique($clean));
        }

        // Keyed case-folded: a case-insensitive collation (MySQL's default)
        // matches `Area:Acct` to the alias `area:acct`, and the lookup below
        // must not then miss on case.
        $map = static::query()
            ->whereIn('name', array_unique($clean))
            ->with('label:id,name')
            ->get()
            ->mapWithKeys(fn (self $alias) => [mb_strtolower($alias->name) => $alias->label?->name])
            ->filter()
            ->all();

        return array_values(array_unique(array_map(fn ($name) => $map[mb_strtolower($name)] ?? $name, $clean)));
    }

    protected static function tableExists(): bool
    {
        return static::$tableExists ??= Schema::hasTable((new static())->getTable());
    }
}
