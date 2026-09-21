<?php

namespace Sgrjr\Dispatch\Contracts;

/**
 * Conversation seam (TASK-1001, ruling R8) — the ENVELOPE a task lives in.
 * A conversation is an arc of undefined size: many tasks per conversation
 * (R7), and the conversation is where the human transcript lives.
 *
 * The package owns `dispatch_tasks.conversation_id` and the arc's SHAPE (which
 * tasks share a conversation, in birth order); it knows nothing about what a
 * conversation IS. A host binds this to its chat system — Centerpoint binds it
 * to Brutus conversations — and the package then renders the arc in
 * `dispatch:show` and the agent JSON without ever touching a messages table.
 *
 * Read-only, like {@see TopicResolver} and {@see OriginResolver}: this
 * resolver never filters a query and never widens visibility. If who-may-read
 * depends on conversation membership, that belongs in the host's
 * {@see DispatchGate} — the ONE scope — never here.
 *
 * Every method degrades gracefully. A conversation the host has since deleted,
 * a chat backend that is down, an id from another instance: return
 * null/[]/false rather than throwing. The arc is context, and context must
 * never be the reason a task fails to render.
 */
interface ConversationResolver
{
    /**
     * A human label for the conversation (e.g. "Marketing · website is
     * broken"), or null when it can't be resolved.
     */
    public function label(int|string $id): ?string;

    /**
     * A link to the conversation in the host's chat UI, or null when there
     * isn't one.
     */
    public function url(int|string $id): ?string;

    /**
     * The tail of the conversation's human transcript, OLDEST FIRST (reading
     * order), capped at $limit. Empty when there is nothing to show, the
     * conversation is gone, or the host declines to expose it.
     *
     * Each entry: `['id' => int|string, 'author' => ?string, 'body' =>
     * string, 'at' => ?string (ISO 8601)]`. Bodies are plain text for a
     * terminal and an agent payload — a host that stores rich content
     * flattens it here.
     *
     * ⚠️ Called only on the FULL shape (show/claim), never per row in a
     * list: this is the one method that can cost a query per task.
     *
     * @return array<int,array{id:int|string,author:?string,body:string,at:?string}>
     */
    public function transcript(int|string $id, int $limit = 20): array;
}
