<?php

use Illuminate\Support\Facades\Artisan;
use Sgrjr\Dispatch\Contracts\ConversationResolver;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Services\DispatchTaskService;
use Sgrjr\Dispatch\Support\NullConversationResolver;
use Sgrjr\Dispatch\Support\TaskPresenter;

/*
 * TASK-1001 (PU-3.1), rulings R7/R8 — CONVERSATION AS ARC.
 *
 * A conversation is an arc of undefined size: many tasks share one envelope,
 * and `dispatch:show` / the agent JSON carry that arc — sibling tasks in
 * birth order, plus whatever the bound ConversationResolver can say about the
 * conversation itself (label, URL, the tail of its transcript).
 *
 * The package owns the arc's SHAPE; the host owns what a conversation IS.
 */

beforeEach(fn () => dispatchFakeUsers());

/** A resolver that knows one conversation and has a two-message transcript. */
function bindFakeConversationResolver(?callable $onTranscript = null): void
{
    app()->singleton(ConversationResolver::class, fn () => new class($onTranscript) implements ConversationResolver
    {
        public function __construct(private $onTranscript) {}

        public function label(int|string $id): ?string
        {
            return $id == 7 ? 'Marketing · the website is broken' : null;
        }

        public function url(int|string $id): ?string
        {
            return $id == 7 ? 'https://example.test/chat/7' : null;
        }

        public function transcript(int|string $id, int $limit = 20): array
        {
            if ($this->onTranscript !== null) {
                return ($this->onTranscript)($id, $limit);
            }

            return $id == 7 ? [
                ['id' => 1, 'author' => 'matt', 'body' => 'the plan page 500s', 'at' => '2026-09-21T10:00:00-04:00'],
                ['id' => 2, 'author' => 'owner', 'body' => 'looking now', 'at' => '2026-09-21T10:02:00-04:00'],
            ] : [];
        }
    });
}

// --- the arc's shape ------------------------------------------------------

test('Task::arc() returns the siblings sharing a conversation, in birth order', function () {
    $svc = app(DispatchTaskService::class);
    $a = $svc->create(['title' => 'first', 'conversation_id' => 7]);
    $b = $svc->create(['title' => 'second', 'conversation_id' => 7]);
    $c = $svc->create(['title' => 'third', 'conversation_id' => 7]);
    $svc->create(['title' => 'elsewhere', 'conversation_id' => 8]);

    expect($b->arc()->pluck('code')->all())->toBe([$a->code, $c->code])
        ->and($b->arc(includeSelf: true)->pluck('code')->all())->toBe([$a->code, $b->code, $c->code]);
});

test('an unhomed task has an EMPTY arc, never every other unhomed task', function () {
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'also unhomed']);
    $lonely = $svc->create(['title' => 'unhomed']);

    // The regression: a null conversation_id matching other nulls would make
    // every unhomed task one enormous arc.
    expect($lonely->arc()->all())->toBe([]);
});

test('the arc orders by id, not created_at — a batch mints siblings on one timestamp', function () {
    $svc = app(DispatchTaskService::class);
    $stamp = now()->subDay();

    $first = $svc->create(['title' => 'a', 'conversation_id' => 7]);
    $second = $svc->create(['title' => 'b', 'conversation_id' => 7]);
    $third = $svc->create(['title' => 'c', 'conversation_id' => 7]);
    Task::whereIn('id', [$first->id, $second->id, $third->id])->update(['created_at' => $stamp]);

    expect($first->fresh()->arc(includeSelf: true)->pluck('code')->all())
        ->toBe([$first->code, $second->code, $third->code]);
});

// --- the presenter --------------------------------------------------------

test('the full shape carries the arc; the summary shape does not', function () {
    bindFakeConversationResolver();
    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'sibling', 'conversation_id' => 7]);
    $task = $svc->create(['title' => 'mine', 'conversation_id' => 7]);
    $task->load('labels', 'submitter', 'assignee', 'comments.user', 'attachments', 'blockedBy', 'blocks');

    $full = TaskPresenter::toArray($task, true);
    $summary = TaskPresenter::toArray($task);

    expect($summary)->not->toHaveKey('arc')
        ->and($full['arc']['conversation_id'])->toBe(7)
        ->and($full['arc']['conversation_label'])->toBe('Marketing · the website is broken')
        ->and($full['arc']['conversation_url'])->toBe('https://example.test/chat/7')
        ->and($full['arc']['siblings'])->toHaveCount(1)
        ->and($full['arc']['siblings'][0]['title'])->toBe('sibling')
        ->and($full['arc']['transcript'])->toHaveCount(2)
        ->and($full['arc']['transcript'][0]['body'])->toBe('the plan page 500s');
});

test('an unhomed task still gets the arc KEY, with a null id and empty lists', function () {
    bindFakeConversationResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'unhomed']);
    $task->load('labels', 'submitter', 'assignee', 'comments.user', 'attachments', 'blockedBy', 'blocks');

    // Always present so a consumer reads arc.siblings without a guard; "no
    // arc" and "an arc of one" stay distinguishable by conversation_id.
    $arc = TaskPresenter::toArray($task, true)['arc'];

    expect($arc['conversation_id'])->toBeNull()
        ->and($arc['siblings'])->toBe([])
        ->and($arc['transcript'])->toBe([]);
});

test('the NullConversationResolver still yields siblings — only label/url/transcript go quiet', function () {
    app()->singleton(ConversationResolver::class, fn () => new NullConversationResolver);

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'sibling', 'conversation_id' => 7]);
    $task = $svc->create(['title' => 'mine', 'conversation_id' => 7]);
    $task->load('labels', 'submitter', 'assignee', 'comments.user', 'attachments', 'blockedBy', 'blocks');

    $arc = TaskPresenter::toArray($task, true)['arc'];

    // The package computes the arc itself from its own column, so a host with
    // no chat system still sees the structure.
    expect($arc['siblings'])->toHaveCount(1)
        ->and($arc['conversation_label'])->toBeNull()
        ->and($arc['transcript'])->toBe([]);
});

test('a throwing ConversationResolver degrades to the structural arc, never a failed render', function () {
    app()->singleton(ConversationResolver::class, fn () => new class implements ConversationResolver
    {
        public function label(int|string $id): ?string
        {
            throw new \RuntimeException('chat is down');
        }

        public function url(int|string $id): ?string
        {
            throw new \RuntimeException('chat is down');
        }

        public function transcript(int|string $id, int $limit = 20): array
        {
            throw new \RuntimeException('chat is down');
        }
    });

    $svc = app(DispatchTaskService::class);
    $svc->create(['title' => 'sibling', 'conversation_id' => 7]);
    $task = $svc->create(['title' => 'mine', 'conversation_id' => 7]);
    $task->load('labels', 'submitter', 'assignee', 'comments.user', 'attachments', 'blockedBy', 'blocks');

    // Context must never be the reason a task fails to render.
    $arc = TaskPresenter::toArray($task, true)['arc'];

    expect($arc['siblings'])->toHaveCount(1)
        ->and($arc['conversation_label'])->toBeNull()
        ->and($arc['transcript'])->toBe([]);
});

test('the transcript limit is config-driven and reaches the resolver', function () {
    $seen = null;
    bindFakeConversationResolver(function ($id, $limit) use (&$seen) {
        $seen = $limit;

        return [];
    });
    config(['dispatch.arc.transcript_limit' => 5]);

    $task = app(DispatchTaskService::class)->create(['title' => 'mine', 'conversation_id' => 7]);
    $task->load('labels', 'submitter', 'assignee', 'comments.user', 'attachments', 'blockedBy', 'blocks');
    TaskPresenter::toArray($task, true);

    expect($seen)->toBe(5);
});

// --- dispatch:show --------------------------------------------------------

test('dispatch:show prints the arc — siblings and the recent transcript', function () {
    bindFakeConversationResolver();
    $svc = app(DispatchTaskService::class);
    $sibling = $svc->create(['title' => 'CS tells the customer', 'conversation_id' => 7, 'lane' => null]);
    $task = $svc->create(['title' => 'fix the 500', 'conversation_id' => 7]);

    Artisan::call('dispatch:show', ['code' => $task->code]);
    $out = Artisan::output();

    expect($out)->toContain('# Arc')
        ->and($out)->toContain('Marketing · the website is broken')
        ->and($out)->toContain($sibling->code)
        ->and($out)->toContain('CS tells the customer')
        ->and($out)->toContain('the plan page 500s');
});

test('dispatch:show grows no Arc section for an unhomed task', function () {
    bindFakeConversationResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'unhomed']);

    Artisan::call('dispatch:show', ['code' => $task->code]);

    expect(Artisan::output())->not->toContain('# Arc');
});

test('dispatch:show says so when a task is the only one in its conversation', function () {
    bindFakeConversationResolver();
    $task = app(DispatchTaskService::class)->create(['title' => 'first of many', 'conversation_id' => 7]);

    Artisan::call('dispatch:show', ['code' => $task->code]);

    expect(Artisan::output())->toContain('the only task in this conversation so far');
});

test('the schema documents the arc key', function () {
    expect(TaskPresenter::schema()['full_adds'])->toHaveKey('arc');
});
