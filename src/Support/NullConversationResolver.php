<?php

namespace Sgrjr\Dispatch\Support;

use Sgrjr\Dispatch\Contracts\ConversationResolver;

/**
 * No-op conversation resolution: a host that hasn't bound its own
 * ConversationResolver still gets `conversation_id` stored and readable, and
 * still gets the arc's SIBLING TASKS (the package computes those itself from
 * the column) — it just gets no label, no URL, and no transcript, because
 * those are the parts only a chat system can answer.
 */
class NullConversationResolver implements ConversationResolver
{
    public function label(int|string $id): ?string
    {
        return null;
    }

    public function url(int|string $id): ?string
    {
        return null;
    }

    public function transcript(int|string $id, int $limit = 20): array
    {
        return [];
    }
}
