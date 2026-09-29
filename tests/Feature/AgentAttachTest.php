<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * TASK-1328 — the upload counterpart to TASK-1242's download: a commissioned
 * agent (or the trusted local CLI) attaches a file already on disk to a task,
 * so an artifact written straight to the filesystem (a generated report) is
 * never an orphan reachable only with shell access to the box. A mime the
 * board doesn't accept inline (HTML/SVG) is zipped rather than refused.
 */

beforeEach(function () {
    dispatchFakeUsers();
    Storage::fake(config('dispatch.attachments.disk'));

    config([
        'dispatch.agent.remote.url' => 'https://agent.example.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => sys_get_temp_dir().'/dispatch-attach-test-'.uniqid().'.json',
    ]);
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/dispatch/attachments'));

    $path = config('dispatch.agent.remote.token_path');
    foreach ([$path, $path.'.dropped', $path.'.session'] as $file) {
        if (is_string($file) && is_file($file)) {
            @unlink($file);
        }
    }
});

/** @return array{0:string,1:AgentSession} [token, session]. Null scopes = the default grant. */
function attachAgentToken(?array $scopes = null): array
{
    static $approver = 92000;
    $approver++;

    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-attach', 'file a report');
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser($approver)->id, null, $scopes);

    return [$svc->poll($req['public_id'], $req['device_code'])['token'], $session->fresh()];
}

function taskForAttach(): \Sgrjr\Dispatch\Models\Task
{
    return app(DispatchTaskService::class)->create(['title' => 'guernsey — two orders', 'status' => 'open']);
}

/** A temp file on disk, path returned; caller unlinks. */
function tempFile(string $suffix, string $contents): string
{
    $path = sys_get_temp_dir().'/dispatch-attach-fixture-'.uniqid().$suffix;
    file_put_contents($path, $contents);

    return $path;
}

test('the default grant includes the attach scope (on by default)', function () {
    [, $session] = attachAgentToken();

    expect($session->scopes)->toContain('attach')
        ->and(AgentSessionService::KNOWN_VERBS)->toContain('attach');
});

test('POST agent/attach with no body/comment_id attaches directly to the task', function () {
    $task = taskForAttach();
    [$token] = attachAgentToken();

    $response = $this->withToken($token)->post('api/dispatch/agent/attach', [
        'code' => $task->code,
        'file' => UploadedFile::fake()->createWithContent('summary.md', "# Guernsey\n\ntwo orders overlap.\n"),
    ]);

    $response->assertCreated();
    expect($response->json('zipped_from'))->toBeNull()
        ->and($response->json('comment_id'))->toBeNull()
        ->and($response->json('attachment.original_name'))->toBe('summary.md')
        ->and($response->json('attachment.mime_type'))->toBe('text/markdown');

    $attachment = $task->attachments()->sole();
    expect($attachment->id)->toBe($response->json('attachment.id'))
        ->and(Storage::disk($attachment->disk)->get($attachment->path))->toContain('two orders overlap');
});

test('POST agent/attach with body mints a NEW internal comment and attaches to it', function () {
    $task = taskForAttach();
    [$token, $session] = attachAgentToken();

    $response = $this->withToken($token)->post('api/dispatch/agent/attach', [
        'code' => $task->code,
        'body' => 'Report attached.',
        'file' => UploadedFile::fake()->createWithContent('report.md', '# report'),
    ]);

    $response->assertCreated();
    $commentId = $response->json('comment_id');
    expect($commentId)->not->toBeNull();

    $comment = $task->comments()->findOrFail($commentId);
    expect($comment->body)->toBe('Report attached.')
        ->and($comment->is_internal)->toBeTrue()
        ->and($comment->attachments()->sole()->original_name)->toBe('report.md')
        ->and($task->attachments()->count())->toBe(0);
});

test('POST agent/attach with comment_id attaches to that EXISTING comment', function () {
    $task = taskForAttach();
    $comment = $task->recordEvent(TaskComment::EVENT_COMMENT, null, [], 'earlier note', true);
    [$token] = attachAgentToken();

    $response = $this->withToken($token)->post('api/dispatch/agent/attach', [
        'code' => $task->code,
        'comment_id' => $comment->id,
        'file' => UploadedFile::fake()->createWithContent('extra.csv', "a,b\n1,2\n"),
    ]);

    $response->assertCreated();
    expect($response->json('comment_id'))->toBe($comment->id)
        ->and($comment->attachments()->sole()->original_name)->toBe('extra.csv');
});

test('comment_id from a DIFFERENT task is refused with a 422, not silently cross-attached', function () {
    $task = taskForAttach();
    $otherTask = taskForAttach();
    $otherComment = $otherTask->recordEvent(TaskComment::EVENT_COMMENT, null, [], 'not this task', true);
    [$token] = attachAgentToken();

    $response = $this->withToken($token)->postJson('api/dispatch/agent/attach', [
        'code' => $task->code,
        'comment_id' => $otherComment->id,
        'file' => UploadedFile::fake()->createWithContent('x.csv', 'a,b'),
    ]);

    $response->assertStatus(422);
    expect($otherComment->fresh()->attachments()->count())->toBe(0);
});

test('a disallowed mime (html) is ZIPPED rather than refused, and zipped_from names the original mime', function () {
    $task = taskForAttach();
    [$token] = attachAgentToken();

    $response = $this->withToken($token)->post('api/dispatch/agent/attach', [
        'code' => $task->code,
        'file' => UploadedFile::fake()->createWithContent('TASK-1328-report.html', '<html><body>evidence</body></html>'),
    ]);

    $response->assertCreated();
    expect($response->json('zipped_from'))->toBe('text/html')
        ->and($response->json('attachment.original_name'))->toBe('TASK-1328-report.html.zip')
        ->and($response->json('attachment.mime_type'))->toBe('application/zip');

    $attachment = $task->attachments()->sole();
    $zip = new ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), 'dispatch-attach-assert-').'.zip';
    file_put_contents($tmp, Storage::disk($attachment->disk)->get($attachment->path));
    expect($zip->open($tmp))->toBeTrue()
        ->and($zip->getFromName('TASK-1328-report.html'))->toContain('evidence');
    $zip->close();
    @unlink($tmp);
});

test('a session without the scope gets a 403 that names it', function () {
    $task = taskForAttach();
    [$token] = attachAgentToken(['show', 'next']);

    $response = $this->withToken($token)->postJson('api/dispatch/agent/attach', [
        'code' => $task->code,
        'file' => UploadedFile::fake()->createWithContent('x.md', 'x'),
    ]);

    $response->assertForbidden();
    expect($response->json('message'))->toContain("not scoped for 'attach'");
});

test('no token is a 401; an unknown task code is a 404', function () {
    $task = taskForAttach();

    $this->postJson('api/dispatch/agent/attach', [
        'code' => $task->code,
        'file' => UploadedFile::fake()->createWithContent('x.md', 'x'),
    ])->assertUnauthorized();

    [$token] = attachAgentToken();
    $this->withToken($token)->postJson('api/dispatch/agent/attach', [
        'code' => 'TASK-999999',
        'file' => UploadedFile::fake()->createWithContent('x.md', 'x'),
    ])->assertNotFound();
});

test('CLI local: attaches directly to the task with no body', function () {
    $task = taskForAttach();
    $path = tempFile('.md', "# local report\n");

    $exit = Artisan::call('dispatch:attach', [
        'code' => $task->code,
        'path' => $path,
        '--local' => true,
        '--json' => true,
    ]);
    @unlink($path);
    $out = dispatchJson(Artisan::output());

    expect($exit)->toBe(0)
        ->and($out['comment_id'])->toBeNull()
        ->and($out['zipped_from'])->toBeNull()
        ->and($out['attachment']['original_name'])->toBe(basename($path));
    expect($task->attachments()->sole()->id)->toBe($out['attachment']['id']);
});

test('CLI local: --body mints a comment and attaches to it; --as overrides the display name', function () {
    $task = taskForAttach();
    $path = tempFile('.md', '# report');

    $exit = Artisan::call('dispatch:attach', [
        'code' => $task->code,
        'path' => $path,
        '--as' => 'TASK-1328-guernsey-two-orders.md',
        '--body' => 'Report attached.',
        '--local' => true,
        '--json' => true,
    ]);
    @unlink($path);
    $out = dispatchJson(Artisan::output());

    expect($exit)->toBe(0)
        ->and($out['attachment']['original_name'])->toBe('TASK-1328-guernsey-two-orders.md');

    $comment = $task->comments()->findOrFail($out['comment_id']);
    expect($comment->body)->toBe('Report attached.')
        ->and($comment->is_internal)->toBeTrue()
        ->and($comment->attachments()->sole()->original_name)->toBe('TASK-1328-guernsey-two-orders.md');
});

test('CLI local: an HTML report zips automatically and says so in human output', function () {
    $task = taskForAttach();
    $path = tempFile('.html', '<html>evidence</html>');

    $exit = Artisan::call('dispatch:attach', ['code' => $task->code, 'path' => $path, '--local' => true]);
    @unlink($path);
    $out = Artisan::output();

    expect($exit)->toBe(0)
        ->and($out)->toContain('.zip')
        ->and($out)->toContain("isn't a board-accepted attachment type");
    expect($task->attachments()->sole()->mime_type)->toBe('application/zip');
});

test('CLI local: --comment-id and --body together is refused before any write', function () {
    $task = taskForAttach();
    $comment = $task->recordEvent(TaskComment::EVENT_COMMENT, null, [], 'earlier', true);
    $path = tempFile('.md', 'x');

    $exit = Artisan::call('dispatch:attach', [
        'code' => $task->code,
        'path' => $path,
        '--comment-id' => (string) $comment->id,
        '--body' => 'also this',
        '--local' => true,
    ]);
    @unlink($path);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('not both')
        ->and($task->attachments()->count())->toBe(0)
        ->and($comment->attachments()->count())->toBe(0);
});

test('CLI local: a missing path is refused with no task lookup', function () {
    $exit = Artisan::call('dispatch:attach', [
        'code' => 'TASK-1',
        'path' => sys_get_temp_dir().'/does-not-exist-'.uniqid().'.md',
        '--local' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Not a readable file');
});

test('CLI remote: posts multipart with the display filename and narrates a zip fallback', function () {
    seedAgentToken();
    $path = tempFile('.html', '<html>x</html>');

    Http::fake([
        'agent.example.test/api/dispatch/agent/attach' => Http::response([
            'attachment' => ['id' => 77, 'original_name' => 'TASK-1328-report.html.zip', 'mime_type' => 'application/zip', 'size_bytes' => 40],
            'zipped_from' => 'text/html',
            'comment_id' => null,
            'task' => ['code' => 'TASK-1328'],
        ], 201),
    ]);

    $exit = Artisan::call('dispatch:attach', [
        'code' => 'TASK-1328',
        'path' => $path,
        '--as' => 'TASK-1328-report.html',
    ]);
    @unlink($path);
    $out = Artisan::output();

    expect($exit)->toBe(0)
        ->and($out)->toContain('TASK-1328-report.html.zip')
        ->and($out)->toContain("isn't a board-accepted attachment type");

    Http::assertSent(function ($request) {
        return $request->url() === 'https://agent.example.test/api/dispatch/agent/attach'
            && $request->hasFile('file', '<html>x</html>', 'TASK-1328-report.html')
            && str_contains($request->body(), 'name="code"')
            && str_contains($request->body(), 'TASK-1328');
    });
});

test('CLI remote: a 403 narrates the scope and prints nothing about a phantom attachment', function () {
    seedAgentToken();
    $path = tempFile('.md', 'x');

    Http::fake([
        'agent.example.test/api/dispatch/agent/attach' => Http::response(['message' => "Agent session is not scoped for 'attach'"], 403),
    ]);

    $exit = Artisan::call('dispatch:attach', ['code' => 'TASK-1', 'path' => $path]);
    @unlink($path);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain("not scoped for 'attach'");
});
