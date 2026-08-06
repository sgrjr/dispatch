<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * THE one source of assignable users — the pool offered by the assignee
 * dropdowns (TaskShow, TaskList bulk-assign) and the CC/watcher picker.
 *
 * Before this seam existed, both dropdowns scanned the first 50 rows of the
 * ENTIRE user table alphabetically — on a host whose user table is dominated
 * by imported customer accounts (placeholder emails and all), that window
 * showed only noise and could exclude every real staff account.
 *
 * Two config knobs, checked in order:
 *  - `dispatch.assignees.resolver` — an invokable class name returning the
 *    base Builder; the full-override escape hatch for hosts whose staff
 *    criterion isn't an email domain (role column, team table, gate check).
 *  - `dispatch.assignees.email_domains` — when set, filters `email` to those
 *    domains (e.g. ['centerpointlargeprint.com']). The one-line fix for the
 *    imported-customer noise.
 *
 * Neither set = the unfiltered user table (back-compat with the old
 * behavior, minus the accidental 50 cap — see `limit`).
 */
class AssignableUsers
{
    /**
     * Base query for assignable users, ordered by name. Callers add their own
     * select/limit; prefer {@see options()} unless you need the Builder.
     */
    public static function query(): Builder
    {
        $resolver = config('dispatch.assignees.resolver');

        if ($resolver) {
            $query = app($resolver)();

            if (! $query instanceof Builder) {
                throw new \RuntimeException(
                    'dispatch.assignees.resolver must return an Eloquent Builder, got '.get_debug_type($query).'.'
                );
            }

            return $query;
        }

        /** @var class-string $userClass */
        $userClass = config('dispatch.models.user');
        $query = $userClass::query();

        $domains = array_filter((array) config('dispatch.assignees.email_domains', []));

        if ($domains !== []) {
            $query->where(function (Builder $q) use ($domains) {
                foreach ($domains as $domain) {
                    // `@` anchors the match to the domain part; escape LIKE
                    // wildcards with an explicit `!` ESCAPE (backslash is not
                    // portable across mysql/sqlite/pgsql — see searchQuery()).
                    $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], ltrim(trim($domain), '@'));
                    $q->orWhereRaw("email LIKE ? ESCAPE '!'", ['%@'.$escaped]);
                }
            });
        }

        return $query->orderBy('name');
    }

    /**
     * The option list for a dropdown/picker: id, name, email. Capped by
     * `dispatch.assignees.limit` (default 100; 0 = unbounded) — a FILTERED
     * staff list is small, so the cap is a runaway guard, not a pager.
     *
     * @return Collection<int,mixed>
     */
    public static function options(): Collection
    {
        $limit = (int) config('dispatch.assignees.limit', 100);

        return static::query()
            ->when($limit > 0, fn (Builder $q) => $q->limit($limit))
            ->get(['id', 'name', 'email']);
    }
}
