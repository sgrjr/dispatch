<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Support\Facades\Auth;
use Sgrjr\Dispatch\Contracts\SubmitterResolver;

/**
 * Default submitter resolution off the standard auth guard. The CLI/system
 * default is the lowest-id user unless an app binds something smarter.
 */
class AuthSubmitterResolver implements SubmitterResolver
{
    public function currentUserId(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }

    public function defaultUserId(): ?int
    {
        $model = config('dispatch.models.user');

        if (! is_string($model) || ! class_exists($model)) {
            return null;
        }

        $first = $model::query()->orderBy('id')->first();

        return $first?->getKey();
    }
}
