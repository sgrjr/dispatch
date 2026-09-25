<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Sgrjr\Dispatch\Console\Commands\DispatchAttachment;
use Sgrjr\Dispatch\Models\AgentSession;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Services\AgentSessionService;
use Sgrjr\Dispatch\Services\AttachmentService;
use Sgrjr\Dispatch\Services\DispatchTaskService;

/*
 * TASK-1242 — a commissioned agent downloads a task's attachments: the
 * scope-gated GET agent/attachments/{id} (+ its silent access audit) and the
 * `dispatch:attachment` CLI that saves them under storage/app/dispatch/
 * attachments/<CODE>/, converting a workbook to per-sheet CSVs.
 */

beforeEach(function () {
    dispatchFakeUsers();
    Storage::fake(config('dispatch.attachments.disk'));

    config([
        'dispatch.agent.remote.url' => 'https://agent.example.test/api/dispatch/agent',
        'dispatch.agent.remote.token_path' => sys_get_temp_dir().'/dispatch-attachment-test-'.uniqid().'.json',
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

/**
 * A request → approve → poll round trip through the service; returns
 * [token, session]. Null scopes = the default grant (agent.verbs).
 */
function attachmentAgentToken(?array $scopes = null): array
{
    static $approver = 91000;
    $approver++;

    $svc = app(AgentSessionService::class);
    $req = $svc->request('claude-attachments', 'read evidence');
    $session = AgentSession::where('public_id', $req['public_id'])->firstOrFail();
    $svc->approve($session, dispatchMakeUser($approver)->id, null, $scopes);

    return [$svc->poll($req['public_id'], $req['device_code'])['token'], $session->fresh()];
}

function taskWithScreenshot(): array
{
    $task = app(DispatchTaskService::class)->create(['title' => 'the grid is blank', 'status' => 'open']);
    $attachment = app(AttachmentService::class)->store(UploadedFile::fake()->image('blank grid.png', 12, 8), $task);

    return [$task, $attachment];
}

test('the default grant includes the attachment scope (on by default)', function () {
    [, $session] = attachmentAgentToken();

    expect($session->scopes)->toContain('attachment')
        ->and(AgentSessionService::KNOWN_VERBS)->toContain('attachment');
});

test('GET agent/attachments/{id} streams the bytes as a nosniff download', function () {
    [$task, $attachment] = taskWithScreenshot();
    [$token] = attachmentAgentToken();

    $response = $this->withToken($token)->get('api/dispatch/agent/attachments/'.$attachment->id);

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->streamedContent())->toBe(Storage::disk($attachment->disk)->get($attachment->path));
});

test('each first fetch per session is ONE internal, silent timeline event on the owning task', function () {
    Mail::fake();
    Notification::fake();
    [$task, $attachment] = taskWithScreenshot();
    [$token, $session] = attachmentAgentToken();

    $this->withToken($token)->get('api/dispatch/agent/attachments/'.$attachment->id)->assertOk();
    $this->withToken($token)->get('api/dispatch/agent/attachments/'.$attachment->id)->assertOk();

    $events = $task->comments()->where('event_type', TaskComment::EVENT_ATTACHMENT_FETCHED)->get();
    expect($events)->toHaveCount(1);
    expect($events->first()->is_internal)->toBeTrue()
        ->and($events->first()->user_id)->toBeNull()
        ->and($events->first()->meta)->toMatchArray([
            'agent_session_id' => $session->public_id,
            'agent_name' => 'claude-attachments',
            'attachment_id' => $attachment->id,
            'filename' => 'blank grid.png',
        ]);

    // A second session reading the same file is a new reader — recorded.
    [$other] = attachmentAgentToken();
    $this->withToken($other)->get('api/dispatch/agent/attachments/'.$attachment->id)->assertOk();
    expect($task->comments()->where('event_type', TaskComment::EVENT_ATTACHMENT_FETCHED)->count())->toBe(2);

    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

test('a comment-level attachment downloads and is audited on the comment\'s task', function () {
    $task = app(DispatchTaskService::class)->create(['title' => 'bad rows', 'status' => 'open']);
    $comment = $task->recordEvent(TaskComment::EVENT_COMMENT, dispatchMakeUser(91500)->id, [], 'rows attached');
    $attachment = app(AttachmentService::class)->store(
        UploadedFile::fake()->createWithContent('rows.csv', "isbn,qty\n978,2\n"),
        $comment,
    );
    [$token] = attachmentAgentToken();

    $response = $this->withToken($token)->get('api/dispatch/agent/attachments/'.$attachment->id);

    $response->assertOk();
    expect($response->streamedContent())->toBe("isbn,qty\n978,2\n")
        ->and($task->comments()->where('event_type', TaskComment::EVENT_ATTACHMENT_FETCHED)->count())->toBe(1);
});

test('a session without the scope gets a 403 that names it', function () {
    [, $attachment] = taskWithScreenshot();
    [$token] = attachmentAgentToken(['show', 'next']);

    $response = $this->withToken($token)->getJson('api/dispatch/agent/attachments/'.$attachment->id);

    $response->assertForbidden();
    expect($response->json('message'))->toContain("not scoped for 'attachment'");
});

test('no token is a 401; an unknown id and an attachment whose task is gone are the same 404', function () {
    [$task, $attachment] = taskWithScreenshot();

    $this->getJson('api/dispatch/agent/attachments/'.$attachment->id)->assertUnauthorized();

    [$token] = attachmentAgentToken();
    $this->withToken($token)->getJson('api/dispatch/agent/attachments/999999')->assertNotFound();

    $task->delete();
    $this->withToken($token)->getJson('api/dispatch/agent/attachments/'.$attachment->id)->assertNotFound();
});

test('safeFilename keeps an uploader\'s name inside the directory', function (string $given, string $expected) {
    expect(DispatchAttachment::safeFilename($given))->toBe($expected);
})->with([
    'plain' => ['shot.png', 'shot.png'],
    'unix climb' => ['../../.env', 'env'],
    'windows climb' => ['..\\..\\config\\app.php', 'app.php'],
    'shell specials' => ['a;b|c$(d).csv', 'a_b_c_d_.csv'],
    'dot only' => ['...', 'attachment'],
    'empty' => ['', 'attachment'],
    'long keeps ext' => [str_repeat('x', 200).'.xlsx', str_repeat('x', 115).'.xlsx'],
]);

test('CLI remote: saves every attachment (task + comment) under storage/app/dispatch/attachments/<CODE>', function () {
    seedAgentToken();

    Http::fake([
        'agent.example.test/api/dispatch/agent/show/*' => Http::response(['task' => [
            'code' => 'TASK-700',
            'attachments' => [['id' => 5, 'filename' => '../../evil.png', 'mime' => 'image/png', 'size_bytes' => 4, 'is_image' => true]],
            'comments' => [
                ['id' => 40, 'attachments' => [['id' => 6, 'filename' => 'rows.csv', 'mime' => 'text/csv', 'size_bytes' => 7, 'is_image' => false]]],
            ],
        ]], 200),
        'agent.example.test/api/dispatch/agent/attachments/5' => Http::response('PNG!', 200),
        'agent.example.test/api/dispatch/agent/attachments/6' => Http::response("a,b\n1,2", 200),
    ]);

    $exit = Artisan::call('dispatch:attachment', ['code' => 'TASK-700', '--json' => true]);
    $out = dispatchJson(Artisan::output());

    expect($exit)->toBe(0);
    $dir = storage_path('app/dispatch/attachments/TASK-700');
    expect(file_get_contents($dir.'/5-evil.png'))->toBe('PNG!')
        ->and(file_get_contents($dir.'/6-rows.csv'))->toBe("a,b\n1,2")
        ->and(collect($out['files'])->pluck('on')->all())->toBe(['task', 'comment #40'])
        ->and(collect($out['files'])->pluck('warning')->filter()->all())->toBe([]);
});

test('CLI remote: --id narrows; an unknown id is refused and lists what exists', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/api/dispatch/agent/show/*' => Http::response(['task' => [
            'code' => 'TASK-701',
            'attachments' => [['id' => 8, 'filename' => 'a.png', 'mime' => 'image/png', 'size_bytes' => 1, 'is_image' => true]],
            'comments' => [],
        ]], 200),
    ]);

    $exit = Artisan::call('dispatch:attachment', ['code' => 'TASK-701', '--id' => ['9']]);
    $out = Artisan::output();

    expect($exit)->toBe(1)
        ->and($out)->toContain('no attachment with id 9')
        ->and($out)->toContain('#8 a.png');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/attachments/'));
});

test('CLI remote: a 403 on the download narrates the scope and leaves no partial file', function () {
    seedAgentToken();
    Http::fake([
        'agent.example.test/api/dispatch/agent/show/*' => Http::response(['task' => [
            'code' => 'TASK-702',
            'attachments' => [['id' => 3, 'filename' => 'x.png', 'mime' => 'image/png', 'size_bytes' => 1, 'is_image' => true]],
            'comments' => [],
        ]], 200),
        'agent.example.test/api/dispatch/agent/attachments/3' => Http::response(['message' => "Agent session is not scoped for 'attachment'"], 403),
    ]);

    $exit = Artisan::call('dispatch:attachment', ['code' => 'TASK-702']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain("not scoped for 'attachment'")
        ->and(is_file(storage_path('app/dispatch/attachments/TASK-702/3-x.png')))->toBeFalse();
});

test('CLI local: copies the bytes out of the bound store', function () {
    [$task, $attachment] = taskWithScreenshot();

    $exit = Artisan::call('dispatch:attachment', ['code' => $task->code, '--local' => true]);

    expect($exit)->toBe(0);
    $saved = storage_path('app/dispatch/attachments/'.$task->code.'/'.$attachment->id.'-blank grid.png');
    expect(file_get_contents($saved))->toBe(Storage::disk($attachment->disk)->get($attachment->path));
});

test('CLI: a workbook also lands as one CSV per sheet, formulas as their saved values', function () {
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
    $sheet = $book->getActiveSheet()->setTitle('Bad Rows');
    $sheet->fromArray([['isbn', 'qty', 'total'], ['9780001', 2, '=B2*3']]);
    $book->createSheet()->setTitle('Notes')->setCellValue('A1', 'from the warehouse');
    $xlsx = sys_get_temp_dir().'/dispatch-rows-'.uniqid().'.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($xlsx);

    $task = app(DispatchTaskService::class)->create(['title' => 'bad rows', 'status' => 'open']);
    $attachment = app(AttachmentService::class)->store(new UploadedFile($xlsx, 'rows.xlsx', null, null, true), $task);
    @unlink($xlsx);

    $exit = Artisan::call('dispatch:attachment', ['code' => $task->code, '--local' => true, '--json' => true]);
    $file = dispatchJson(Artisan::output())['files'][0];

    expect($exit)->toBe(0)
        ->and($file['warning'])->toBeNull()
        ->and($file['csv'])->toHaveCount(2)
        ->and($file['csv'][0])->toEndWith($attachment->id.'-rows.xlsx.1-Bad-Rows.csv')
        ->and(file_get_contents($file['csv'][0]))->toBe("isbn,qty,total\n9780001,2,6\n")
        ->and(file_get_contents($file['csv'][1]))->toBe("\"from the warehouse\"\n");
});
