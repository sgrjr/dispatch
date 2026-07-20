<?php

use Sgrjr\Dispatch\Models\Label;
use Sgrjr\Dispatch\Models\Task;
use Sgrjr\Dispatch\Models\TaskAttachment;
use Sgrjr\Dispatch\Models\TaskComment;
use Sgrjr\Dispatch\Support\AuthSubmitterResolver;
use Sgrjr\Dispatch\Support\DefaultGate;
use Sgrjr\Dispatch\Support\MailNotifier;
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
    | The four seams that make the package portable. Bind your own classes to
    | teach Dispatch about your app's authorization, tenancy, and notification
    | delivery. The shipped defaults treat any authenticated user as staff
    | (fine for a single team), apply no tenant scoping, and mail updates.
    */
    'contracts' => [
        'gate' => DefaultGate::class,
        'tenant' => NullTenantResolver::class,
        'submitter' => AuthSubmitterResolver::class,
        'notifier' => MailNotifier::class,
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
    | Workflow vocabulary
    |--------------------------------------------------------------------------
    |
    | The type/priority/status vocab a task can take, seeded here with the
    | package's built-in defaults (Task::TYPES/PRIORITIES/STATUSES) so the
    | published config documents them and can be edited to add/rename/reorder
    | values (priority/status rank — used by Task::prioritySql()/statusSql()
    | for board/list ordering — follows list order). `*_labels` optionally
    | maps a raw value to its display label; leave a map empty ([]) to
    | auto-humanize instead (`in_progress` -> `In Progress`).
    */
    'workflow' => [
        'types' => ['bug', 'feature', 'chore', 'debt', 'verify'],
        'priorities' => ['blocker', 'high', 'medium', 'low'],
        'statuses' => ['triage', 'open', 'in_progress', 'verifying', 'done', 'declined'],

        'type_labels' => [],
        'priority_labels' => [],
        'status_labels' => [],
    ],

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

        // Rate limiter applied to POST /capture + the attachment upload route
        // by the routes file (a later wave). null/false = no throttle; a
        // limiter string like '30,1' (30/min), or ['max' => 30, 'per' => 1].
        'throttle' => '60,1',
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
    | Board
    |--------------------------------------------------------------------------
    |
    | Kanban board tuning. `done_limit` caps how many cards load into the Done
    | column so a long-lived board doesn't drag in years of history (0/null =
    | unbounded). `manual_order` false keeps today's priority-primary sort;
    | true lets a manual drag position stick instead of being resorted by
    | priority on every render.
    */
    'board' => [
        'done_limit' => 50,
        'manual_order' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Staleness
    |--------------------------------------------------------------------------
    |
    | Flags a task as stale once it hasn't moved in `threshold_days` (~6 weeks
    | by default). Purely a display/reporting signal — set `enabled` false to
    | turn it off entirely.
    */
    'staleness' => [
        'enabled' => true,
        'threshold_days' => 42,
    ],

    /*
    |--------------------------------------------------------------------------
    | Markdown
    |--------------------------------------------------------------------------
    |
    | Renders task/comment bodies with league/commonmark when enabled (HTML
    | input escaped, unsafe links disallowed — see Support\Markdown). false
    | falls back to a plain nl2br(e($text)) render with no markdown parsing.
    */
    'markdown' => [
        'enabled' => true,
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

    /*
    |--------------------------------------------------------------------------
    | Remote agent seam (§19/§20)
    |--------------------------------------------------------------------------
    |
    | A dedicated, human-commissioned agent API so a remote Claude agent can work
    | the PRODUCTION backlog without a standing credential. OFF by default —
    | enable deliberately on the authoritative (production) instance. An agent
    | REQUESTS a session, a human approves it in the "Agent Sessions" UI, and a
    | short-TTL bearer token is issued for the verb loop.
    |
    | `bootstrap_secret` gates the unauthenticated request endpoint (send it as
    | the X-Dispatch-Bootstrap header). Required in production; leave unset only
    | on a trusted/local network (see VerifyBootstrapSecret). `verbs` is the
    | global allowlist a session's scopes are bounded by — no delete, and the
    | one many-task verb (`batch`) is additive-only (upsert, never replace/delete).
    | `remote.*` is the CLIENT side (a dev box driving `dispatch:* --remote`).
    */
    'agent' => [
        'enabled' => env('DISPATCH_AGENT', false),
        'middleware' => ['api'],
        'bootstrap_secret' => env('DISPATCH_AGENT_BOOTSTRAP_SECRET'),
        // Approved token TTL (s). A backstop, not the lifecycle — sessions end
        // via dispatch:session:end; expiry mid-run 401s that closing call and
        // loses the session's stamped metrics, so keep this generous.
        'session_ttl' => (int) env('DISPATCH_AGENT_SESSION_TTL', 10800),
        'request_ttl' => (int) env('DISPATCH_AGENT_REQUEST_TTL', 900),   // pending-approval window (s)
        'poll_interval' => (int) env('DISPATCH_AGENT_POLL_INTERVAL', 5),
        'request_throttle' => env('DISPATCH_AGENT_REQUEST_THROTTLE', '10,1'),
        'verb_throttle' => env('DISPATCH_AGENT_VERB_THROTTLE', '120,1'),
        'verbs' => ['next', 'queue', 'show', 'add', 'note', 'done', 'claim', 'batch'],

        // Explicit denylist — the supported way to WITHHOLD a shipped verb. The
        // grant ceiling for an explicitly-requested scope is the UNION of `verbs`
        // and the package's known verbs (AgentSessionService::KNOWN_VERBS), so a
        // stale *published* config can't silently drop a verb the package ships
        // (GAP-3). To withhold one (e.g. `batch` on a public instance), list it
        // here rather than removing it from `verbs`.
        'disabled_verbs' => [],

        // Batch "memorialize" endpoint (POST agent/batch). Additive + server-
        // bounded (no delete, labels attach not replace, status never assumed
        // done) so it stays inside the curated-verb posture. `max_operations`
        // caps a single request so it can't become an unbounded bulk write
        // (0 = uncapped — not recommended on a public instance).
        'batch' => [
            'max_operations' => (int) env('DISPATCH_AGENT_BATCH_MAX', 200),
        ],

        'remote' => [
            'url' => env('DISPATCH_AGENT_REMOTE_URL'),
            'token_path' => env('DISPATCH_AGENT_TOKEN_PATH'),
            // Sticky remote: while an approved session token exists (the dotfile
            // is created at approval and deleted on session:end/401), the verbs
            // default to the remote — a loud target line names the host on every
            // call, and --local overrides per call. Set false to require the
            // explicit --remote flag on every call instead.
            'sticky' => env('DISPATCH_AGENT_STICKY', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent run metrics
    |--------------------------------------------------------------------------
    |
    | Powers `dispatch:metrics`, which reads the local Claude Code transcript and
    | stamps per-task token/cost/tool/duration figures under context.result.metrics.
    | A model can't read its own usage mid-run, so the numbers come from the
    | transcript JSONL — never from the agent's say-so.
    |
    | `session_file` is where the SessionStart hook (`dispatch:metrics:capture`)
    | writes the current transcript path; discovery falls back to the newest
    | transcript for this project if it's absent. `transcript_root` overrides the
    | default `~/.claude/projects` location.
    |
    | `pricing` is $ / 1M tokens per model, prefix-matched against the transcript's
    | model id (so `claude-opus-4-8` matches `claude-opus-4-8[1m]`). Raw tokens are
    | stored durably; cost is derived here, so edit these rates rather than trusting
    | a baked-in dollar figure. `cache_write` defaults to the 5-minute-TTL rate
    | (1.25x input); Claude Code may use the 1-hour TTL (2x) — adjust if that
    | matters for your accounting.
    |
    | `touch_time` powers a derived "estimated human touch-time" figure — a
    | deterministic, versioned estimate of the focused human minutes the same
    | workflow would have taken. Like cost, it is computed at READ time from the
    | stamped signals (never stored), so historical tasks re-derive whenever you
    | tune these coefficients — edit them rather than trusting the baked-in
    | numbers, and bump `version` when you retune (it renders in the label).
    | Delete or null the block to hide the figure everywhere.
    */
    'metrics' => [
        'session_file' => storage_path('app/dispatch/agent-session.json'),
        'transcript_root' => env('DISPATCH_METRICS_TRANSCRIPT_ROOT'),

        'pricing' => [
            'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
            'claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00, 'cache_write' => 3.75, 'cache_read' => 0.30],
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],
            'claude-fable-5' => ['input' => 10.00, 'output' => 50.00, 'cache_write' => 12.50, 'cache_read' => 1.00],
        ],

        // Estimated human touch-time — modeled focused touch-time for the same
        // workflow (no queue latency), NOT a measurement. See the banner above.
        'touch_time' => [
            'version' => 'v1',

            // Orientation + reading the task + commit/PR overhead a human pays
            // once per task, by task type; 'default' covers unknown/null types.
            'base_minutes' => [
                'default' => 10,
                'bug' => 15,
                'feature' => 20,
                'chore' => 5,
                'debt' => 15,
                'verify' => 10,
            ],

            // Minutes per tool call by category. The lists map raw stamped tool
            // names (PascalCase, as they appear in the tools histogram) into
            // categories; any unlisted name counts as 'other'.
            'per_tool_minutes' => ['mutate' => 4.0, 'bash' => 1.5, 'other' => 0.5],
            'mutate_tools' => ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'],
            'bash_tools' => ['Bash', 'PowerShell'],

            // Per-category ceilings so huge sweeps (500 Edits) don't produce
            // absurd totals: each category contributes min(count × rate, cap).
            'category_cap_minutes' => ['mutate' => 240, 'bash' => 90, 'other' => 60],

            // Parallel subagent work a human would have done serially.
            'per_subagent_minutes' => 5,
            'subagent_cap_minutes' => 60,

            // Wall-clock enters only as + min(duration_minutes × weight, cap) —
            // a capped, low-weight term, never a multiplier on the whole run.
            'duration_weight' => 0.15,
            'duration_cap_minutes' => 20,
        ],
    ],
];
