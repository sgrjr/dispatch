<?php

namespace Sgrjr\Dispatch\Support;

use Sgrjr\Dispatch\Contracts\OriginResolver;

/**
 * No-op origin resolution: a host that hasn't bound its own OriginResolver
 * still gets a task with `origin_type`/`origin_id` stored and readable — it
 * just never resolves to a model, a real label beyond the raw type, or a URL.
 */
class NullOriginResolver implements OriginResolver
{
    public function resolve(string $type, ?string $id): mixed
    {
        return null;
    }

    public function label(string $type, ?string $id): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $type)).($id !== null && $id !== '' ? " {$id}" : '');
    }

    public function url(string $type, ?string $id): ?string
    {
        return null;
    }
}
