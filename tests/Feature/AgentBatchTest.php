<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * The remote batch verb (§20) end to end: POST agent/batch behind a bearer
 * session, scope-gated `batch`, plus the `dispatch:batch --remote` client.
 * AgentApiTest covers the single verbs; this file is the batch memorialize path.
 */

beforeEach(fn () => dispatchFakeUsers());

/** Mint an approved session token, optionally scope-restricted. */
function batchAgentToken(?array $scopes = null): string
{
    static $approverId = 87000;
    $approverId++;

    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-remote', 'batch memorialize');
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser($approverId)->id, null, $scopes);

    return $svc->poll($req['public_id'], $req['device_code'])['token'];
}

test('POST batch applies a mixed add/update manifest and stamps agent attribution', function () {
    $existing = app(DispatchTaskService::class)->create(['title' => 'in flight', 'status' => 'open']);

    $token = batchAgentToken();

    $response = $this->withToken($token)->postJson('api/dispatch/agent/batch', [
        'operations' => [
            ['op' => 'add', 'ref' => 'x1', 'title' => 'filed in batch', 'type' => 'bug', 'labels' => ['area:api']],
            ['op' => 'update', 'code' => $existing->code, 'status' => 'in_progress',
             'comments' => [['body' => 'started this one']]],
        ],
    ])->assertOk()
        ->assertJsonPath('applied', true)
        ->assertJsonPath('summary.tasks_created', 1)
        ->assertJsonPath('summary.tasks_updated', 1)
        ->assertJsonPath('results.0.ref', 'x1');

    $newCode = $response->json('results.0.code');
    $new = Task::where('code', $newCode)->firstOrFail();

    // Attribution: new task carries agent origin in context; the appended comment
    // carries agent meta with a null author, mirroring the single verbs.
    expect($new->context['agent']['agent_name'])->toBe('claude-remote')
        ->and($existing->fresh()->status)->toBe('in_progress');

    $note = $existing->fresh()->comments()->where('event_type', TaskComment::EVENT_COMMENT)->firstOrFail();
    expect($note->user_id)->toBeNull()
        ->and($note->meta['agent_name'])->toBe('claude-remote');
});

test('a session scoped without `batch` is forbidden (403)', function () {
    $token = batchAgentToken(['next', 'add']);

    $this->withToken($token)->postJson('api/dispatch/agent/batch', [
        'operations' => [['op' => 'add', 'title' => 'nope']],
    ])->assertStatus(403);

    expect(Task::count())->toBe(0);
});

test('POST batch dry_run reports without persisting', function () {
    $token = batchAgentToken();

    $this->withToken($token)->postJson('api/dispatch/agent/batch', [
        'dry_run' => true,
        'operations' => [['op' => 'add', 'title' => 'phantom']],
    ])->assertOk()
        ->assertJsonPath('applied', false)
        ->assertJsonPath('dry_run', true)
        ->assertJsonPath('summary.tasks_created', 1);

    expect(Task::count())->toBe(0);
});

test('POST batch over the op cap is rejected (422) and writes nothing', function () {
    config(['dispatch.agent.batch.max_operations' => 2]);

    $token = batchAgentToken();

    $ops = [
        ['op' => 'add', 'title' => 'one'],
        ['op' => 'add', 'title' => 'two'],
        ['op' => 'add', 'title' => 'three'],
    ];

    $this->withToken($token)->postJson('api/dispatch/agent/batch', ['operations' => $ops])
        ->assertStatus(422);

    expect(Task::count())->toBe(0);
});

test('POST batch with a malformed op 422s with the offending index and rolls back', function () {
    $token = batchAgentToken();

    $this->withToken($token)->postJson('api/dispatch/agent/batch', [
        'operations' => [
            ['op' => 'add', 'title' => 'good'],
            ['op' => 'update', 'code' => 'TASK-MISSING', 'status' => 'done'],
        ],
    ])->assertStatus(422);

    expect(Task::count())->toBe(0);
});

test('POST batch requires a valid bearer', function () {
    $this->postJson('api/dispatch/agent/batch', ['operations' => []])->assertStatus(401);
});

// --- dispatch:batch --remote ------------------------------------------------

test('dispatch:batch --remote posts the manifest operations to the agent API', function () {
    config([
        'dispatch.agent.remote.url' => 'https://agent.example.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => $tokenPath = sys_get_temp_dir().'/dispatch-batch-remote-'.uniqid().'.json',
    ]);
    file_put_contents($tokenPath, json_encode(['token' => 'test-remote-token']));

    Http::fake([
        'agent.example.test/*' => Http::response([
            'applied' => true,
            'dry_run' => false,
            'summary' => ['tasks_created' => 1, 'tasks_updated' => 1, 'comments_added' => 0, 'statuses_changed' => 1],
            'results' => [
                ['ref' => 'x1', 'op' => 'add', 'code' => 'TASK-950', 'created' => true],
                ['op' => 'update', 'code' => 'TASK-042', 'status' => 'in_progress'],
            ],
        ], 200),
    ]);

    $path = sys_get_temp_dir().'/dispatch-batch-remote-manifest-'.uniqid().'.json';
    file_put_contents($path, json_encode(['operations' => [
        ['op' => 'add', 'ref' => 'x1', 'title' => 'filed in batch'],
        ['op' => 'update', 'code' => 'TASK-042', 'status' => 'in_progress'],
    ]]));

    $exit = Artisan::call('dispatch:batch', ['path' => $path, '--remote' => true, '--json' => true]);

    expect($exit)->toBe(0);
    $decoded = json_decode(Artisan::output(), true);
    expect($decoded['results'][0]['code'])->toBe('TASK-950');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/api/dispatch/agent/batch')
            && count($request->data()['operations']) === 2
            && $request->data()['operations'][0]['ref'] === 'x1';
    });

    expect(Task::count())->toBe(0); // never touches the local DB

    @unlink($path);
    @unlink($tokenPath);
});
