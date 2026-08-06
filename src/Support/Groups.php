<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * W13-4: config-defined assignee groups/teams. `dispatch.groups` maps a
 * group name to its members — each member an int (user id) or string
 * (email, matched case-insensitively):
 *
 *     'groups' => [
 *         'it'       => ['alice@example.com', 'bob@example.com'],
 *         'accounts' => [12, 15],
 *     ],
 *
 * A task assigned to a group keeps a SINGULAR assignee value
 * (`assignee_group`) that resolves to this collection wherever people
 * matter: notification fan-out and GATE A participation.
 */
class Groups
{
    /** @return array<int,string> */
    public static function names(): array
    {
        return array_values(array_map('strval', array_keys((array) config('dispatch.groups', []))));
    }

    public static function exists(string $name): bool
    {
        return in_array($name, self::names(), true);
    }

    /**
     * The group's members as user models (unknown ids/emails silently drop —
     * a stale config entry must never break a notifier or gate).
     *
     * @return Collection<int,mixed>
     */
    public static function members(?string $name): Collection
    {
        if ($name === null || $name === '') {
            return collect();
        }

        $members = (array) (((array) config('dispatch.groups', []))[$name] ?? []);

        if ($members === []) {
            return collect();
        }

        [$ids, $emails] = collect($members)->partition(fn ($m) => is_int($m) || ctype_digit((string) $m));

        /** @var class-string $userClass */
        $userClass = config('dispatch.models.user');

        return $userClass::query()
            ->where(function ($q) use ($ids, $emails) {
                if ($ids->isNotEmpty()) {
                    $q->orWhereIn('id', $ids->map(fn ($i) => (int) $i)->all());
                }
                foreach ($emails as $email) {
                    $q->orWhereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $email))]);
                }
            })
            ->get();
    }

    /**
     * Every group the user belongs to (by id or email) — the GATE A
     * membership question, answered from config without touching the DB.
     *
     * @return array<int,string>
     */
    public static function namesFor(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $id = $user->getAuthIdentifier();
        $email = mb_strtolower(trim((string) ($user->email ?? '')));

        $mine = [];

        foreach ((array) config('dispatch.groups', []) as $name => $members) {
            foreach ((array) $members as $member) {
                $matchesId = (is_int($member) || ctype_digit((string) $member)) && (string) $member === (string) $id;
                $matchesEmail = $email !== '' && is_string($member) && mb_strtolower(trim($member)) === $email;

                if ($matchesId || $matchesEmail) {
                    $mine[] = (string) $name;
                    break;
                }
            }
        }

        return $mine;
    }
}
