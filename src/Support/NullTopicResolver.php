<?php

namespace Sgrjr\Dispatch\Support;

use Sgrjr\Dispatch\Contracts\TopicResolver;

/**
 * No-op topic resolution: a host that hasn't bound its own TopicResolver
 * still gets a task with `topic_type`/`topic_id` stored and readable — it
 * just never resolves to a model, a real label beyond the raw type, a URL, or
 * an account key.
 */
class NullTopicResolver implements TopicResolver
{
    public function resolve(string $type, string $id): mixed
    {
        return null;
    }

    public function label(string $type, string $id): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $type)).($id !== '' ? " {$id}" : '');
    }

    public function url(string $type, string $id): ?string
    {
        return null;
    }

    public function accountKey(string $type, string $id): ?string
    {
        return null;
    }
}
