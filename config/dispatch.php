<?php

use Sgrjr\Dispatch\Models\Label;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskAttachment;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Support\AuthSubmitterResolver;
use Sgrjr\Dispatch\Support\DefaultGate;
use Sgrjr\Dispatch\Support\NullTenantResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Every model the package uses is resolved through this map, so a consuming
    | app can subclass one to add its own columns/relations (e.g. a tenant key)
    | without the package knowing about them. Point `user` at your app's User.
    */
    'models' => [
        'user' => env('DISPATCH_USER_MODEL', 'App\\Models\\User'),
        'task' => Task::class,
        'task_comment' => TaskComment::class,
        'label' => Label::class,
        'task_attachment' => TaskAttachment::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Contract bindings
    |--------------------------------------------------------------------------
    |
    | The three seams that make the package portable. Bind your own classes to
    | teach Dispatch about your app's authorization and tenancy. The shipped
    | defaults treat any authenticated user as staff (fine for a single team)
    | and apply no tenant scoping.
    */
    'contracts' => [
        'gate' => DefaultGate::class,
        'tenant' => NullTenantResolver::class,
        'submitter' => AuthSubmitterResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Task codes
    |--------------------------------------------------------------------------
    |
    | Human-facing task identifier prefix. Codes look like `TASK-001`. Minting
    | is race-safe (unique index + retry-on-collision), so the prefix is purely
    | cosmetic — set it per project (e.g. `CP`, `RUK`).
    */
    'code_prefix' => env('DISPATCH_CODE_PREFIX', 'TASK'),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Set `enabled` to false to register no routes and wire your own. `middleware`
    | guards the staff board/list/CLI-sync surfaces; `portal_middleware` guards
    | the submitter "my submissions" surface.
    */
    'routes' => [
        'enabled' => env('DISPATCH_ROUTES', true),
        'prefix' => env('DISPATCH_ROUTE_PREFIX', 'dispatch'),
        'name_prefix' => 'dispatch.',
        'middleware' => ['web', 'auth'],
        'portal_middleware' => ['web', 'auth'],
        'api_middleware' => ['api', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | `name` appears in notifications. `task_url` is a callable/route-name used
    | to build the link back to a task in outbound notifications. If a route
    | name, it is called as route($name, $task).
    */
    'brand' => [
        'name' => env('DISPATCH_BRAND', config('app.name', 'Dispatch')),
        'task_url' => 'dispatch.show',
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture widget
    |--------------------------------------------------------------------------
    |
    | The from-any-page floating "report a bug / suggest a feature" widget.
    | Drop <livewire:dispatch-widget /> into your layout; this master switch
    | lets an app disable it globally without touching the layout.
    */
    'widget' => [
        'enabled' => env('DISPATCH_WIDGET', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Images/files on tasks and comments. Files live on a PRIVATE disk under a
    | hashed path and are streamed through an authorized controller — never a
    | public URL. Keep `disk` off the `public` disk.
    */
    'attachments' => [
        'enabled' => true,
        'disk' => env('DISPATCH_ATTACHMENT_DISK', 'local'),
        'path_prefix' => 'dispatch/attachments',
        'max_size_kb' => env('DISPATCH_ATTACHMENT_MAX_KB', 10240),
        'max_per_batch' => 10,
        'allowed_mimes' => [
            'image/png', 'image/jpeg', 'image/gif', 'image/webp',
            'application/pdf', 'text/plain',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-capture uncaught exceptions
    |--------------------------------------------------------------------------
    |
    | When enabled, an uncaught 500-level exception opens a deduped `bug` task
    | in triage labeled `source:exception`. OFF by default — enable deliberately
    | and mind overlap with an existing error tracker (e.g. Sentry).
    */
    'capture' => [
        'exceptions' => env('DISPATCH_CAPTURE_EXCEPTIONS', false),
        'environments' => ['production'],
        'label' => 'source:exception',
    ],

    /*
    |--------------------------------------------------------------------------
    | Programmatic reporter (the DispatchTask facade)
    |--------------------------------------------------------------------------
    |
    | Powers DispatchTask::report()/bug()/feature()/fromException(). The create
    | runs through a queueable job: sync by default (returns the Task), or set
    | `queue` to a queue name to offload it (returns null). Env gating, throttle,
    | and context capture happen before dispatch; dedupe happens in the job.
    */
    'reporter' => [
        'enabled' => env('DISPATCH_REPORTER', true),

        // null / [] = all environments; e.g. ['production'] to gate out dev noise.
        'environments' => null,

        // false = run synchronously (dispatchSync). A queue name string (or true)
        // offloads to the queue (dispatch). `connection` optionally overrides it.
        'queue' => env('DISPATCH_REPORTER_QUEUE', false),
        'connection' => env('DISPATCH_REPORTER_CONNECTION'),

        // Minimum seconds between writes for the same dedupe signature (0 = off).
        // Protects the DB/board from an error storm.
        'throttle_seconds' => 60,

        // Attach request context (url/method/route/user/input) to the task.
        'capture_request' => true,

        // Keys whose values are scrubbed from captured input/context.
        'redact' => [
            'password', 'password_confirmation', 'current_password',
            'token', '_token', 'secret', 'api_key', 'authorization', 'cookie',
        ],

        'exception_label' => 'source:exception',
        'trace_frames' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'enabled' => env('DISPATCH_NOTIFICATIONS', true),
        'channels' => ['mail'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-instance sync (optional, package<->package only)
    |--------------------------------------------------------------------------
    |
    | `dispatch:pull` / `dispatch:push` move task state between two installs of
    | THIS package on the same schema (e.g. local dev <-> production). Leave the
    | URL unset and the verbs no-op gracefully; the local agent loop still works.
    */
    'sync' => [
        'remote_url' => env('DISPATCH_REMOTE_URL'),
        'token' => env('DISPATCH_REMOTE_TOKEN'),
        'timeout' => env('DISPATCH_REMOTE_TIMEOUT', 30),
        'verify_ssl' => env('DISPATCH_REMOTE_VERIFY_SSL', true),
    ],

    'jsonld' => [
        'vocab' => env('DISPATCH_JSONLD_VOCAB', 'https://sgrjr.dev/schema/dispatch/v1#'),
    ],
];
