# Dispatch

Drop-in task / bug / feature dispatch pipeline for Laravel: capture from any
page (with screenshots), a Kanban board + list for staff, a submitter portal,
and a CLI verb-loop that AI agents drive.

Dispatch is a standalone package (`sgrjr/dispatch`) designed to be installed
into any Laravel 11/12 app, not just one project. Everything app-specific
(who's staff, what "tenant" means, who submitted a task) is a config-bound
seam — the package ships sane single-team defaults and lets you override
exactly the three points where your app's rules matter.

This guide walks through installing Dispatch into a **brand-new third app**,
so it doubles as the "multi-project ready" proof: nothing below assumes any
prior Dispatch install.

---

## 1. Install

Dispatch isn't (yet) on Packagist, so pull it straight from GitHub via a VCS
repository entry.

```jsonc
// composer.json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/sgrjr/dispatch"
        }
    ]
}
```

```bash
# Recommended: a tagged release (stable; picks up 0.2.x patches)
composer require sgrjr/dispatch:^0.2

# Or track the latest unreleased tip of the master branch (bleeding edge).
# NOTE: "dev-master" is Composer's alias for the `master` branch — not a branch
# literally named dev-master — and pulls unreleased code.
# composer require sgrjr/dispatch:dev-master
```

@dev is the latest-and-greatest lane. It stays unpinned, so every composer update floats to the newest available release (right now v0.4.2), and it'll automatically pick up v0.5.x, etc., as you tag them — no version bump in composer.json required.

The one nuance: if prefer-stable is on in host application, @dev grabs the newest tagged release. If by "latest and greatest" you want the bleeding edge of untagged master commits, that'd be dev-master instead. But for tracking newest releases as they ship, @dev is correct.

Publish the config and migrations, then migrate:

```bash
php artisan vendor:publish --tag=dispatch-config
php artisan vendor:publish --tag=dispatch-migrations
php artisan migrate
```

This creates `config/dispatch.php`. The package's migrations are namespaced
(`dispatch_tasks`, `dispatch_labels`, `dispatch_task_label`,
`dispatch_task_comments`, `dispatch_task_attachments`) so they never collide with
a table your app already owns, and they load automatically — `php artisan migrate`
runs them without publishing. (Publish them with `--tag=dispatch-migrations` only
if you want to edit them in your own `database/migrations/`.)

Optional publish tags:

```bash
# Blade views, if you want to override the shipped dispatch:: views
php artisan vendor:publish --tag=dispatch-views

# Compiled front-end assets used by the Livewire components
php artisan vendor:publish --tag=dispatch-assets

# Claude Code skills (dispatch-track + dispatch-agent-session) into .claude/skills/.
# Required for the skills to work at all — Claude Code discovers skills only from
# your project's .claude/skills, never from vendor/. Re-run with --force to re-sync
# after upgrading; without --force it skips files you've already customized (e.g. an
# agent-session skill you've pointed at your own production host).
php artisan vendor:publish --tag=dispatch-skills
```

---

## 2. Point Dispatch at your User model

`config/dispatch.php` resolves every model through a map so the package never
hard-references a concrete class. Set the one that matters for a fresh
install — your app's User:

```php
// config/dispatch.php
'models' => [
    'user' => env('DISPATCH_USER_MODEL', App\Models\User::class),
    // task / task_comment / label / task_attachment default to the
    // package's own models — leave these unless you're subclassing one
    // (e.g. to add a tenant column) to teach Dispatch about it.
],
```

or in `.env`:

```
DISPATCH_USER_MODEL=App\Models\User
```

---

## 3. The three contract bindings

Dispatch has exactly three seams a consuming app can (and usually should)
override. All three are bound in `config/dispatch.php` under `contracts`:

```php
'contracts' => [
    'gate' => Sgrjr\Dispatch\Support\DefaultGate::class,
    'tenant' => Sgrjr\Dispatch\Support\NullTenantResolver::class,
    'submitter' => Sgrjr\Dispatch\Support\AuthSubmitterResolver::class,
],
```

| Contract | Interface | Shipped default | What it decides |
|---|---|---|---|
| **DispatchGate** | `Sgrjr\Dispatch\Contracts\DispatchGate` | `DefaultGate` — any authenticated user is staff and sees everything; guests see only `is_public` tasks | Who may use the staff board/list/CLI (`isStaff`), who's a superuser (`canSeeAll`), and — critically — the **one** query scope (`scopeVisible`) every task query in the package is passed through |
| **TenantResolver** | `Sgrjr\Dispatch\Contracts\TenantResolver` | `NullTenantResolver` — no-op | Stamps your app's tenant column(s) onto a task at creation time (it never filters queries — your `DispatchGate` does that, using this resolver internally if it needs to) |
| **SubmitterResolver** | `Sgrjr\Dispatch\Contracts\SubmitterResolver` | `AuthSubmitterResolver` — current auth id, or the lowest-id user for system/CLI captures | Who a task is attributed to when there's no clear actor |

The shipped `DefaultGate` is fine for a small single-team app where "logged
in" == "staff". Most real installs split staff from submitters — bind your
own.

### Example: a custom DispatchGate

```php
// app/Support/Dispatch/AppDispatchGate.php
namespace App\Support\Dispatch;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Sgrjr\Dispatch\Contracts\DispatchGate;

class AppDispatchGate implements DispatchGate
{
    public function isStaff(?Authenticatable $user): bool
    {
        return $user !== null && $user->hasRole('staff');
    }

    public function canSeeAll(?Authenticatable $user): bool
    {
        return $user !== null && $user->hasRole('admin');
    }

    public function scopeVisible(Builder $query, ?Authenticatable $user): Builder
    {
        if ($this->canSeeAll($user)) {
            return $query; // superuser: unconstrained
        }

        if ($this->isStaff($user)) {
            return $query; // staff: sees every task (add tenant limits here if needed)
        }

        if ($user === null) {
            return $query->where('is_public', true); // guest
        }

        // Ordinary submitter: their own tasks, plus anything public.
        return $query->where(function (Builder $q) use ($user) {
            $q->where('is_public', true)
                ->orWhere('submitter_user_id', $user->getAuthIdentifier());
        });
    }
}
```

```php
// config/dispatch.php
'contracts' => [
    'gate' => App\Support\Dispatch\AppDispatchGate::class,
    // ...
],
```

Never write a second, ad-hoc `where()` for visibility anywhere in your app —
route every task query through `scopeVisible()` (or `Gate::authorize(...,
$task)`, which delegates to it via `TaskPolicy`).

---

## 4. From-any-page capture widget

Dispatch ships two interchangeable widgets — pick the one that matches your host's
front end. Both open the same modal (title / type / description, **paste a
screenshot**, and built-in links to the board / your submissions) and POST to the
headless `POST /dispatch/capture` endpoint. Every submission auto-captures the
current page URL plus structured diagnostics (recent console errors, user agent,
viewport).

**Blade / Livewire hosts** — drop the Livewire component into your layout:

```blade
{{-- resources/views/layouts/app.blade.php --}}
<body>
    @yield('content')
    <livewire:dispatch-widget />
</body>
```

**Inertia / Vue (or any JS) hosts** — publish the Vue component and place it
yourself (e.g. inline in a footer):

```bash
php artisan vendor:publish --tag=dispatch-vue
```
```vue
<script setup>
import DispatchWidget from '@/vendor/dispatch/DispatchWidget.vue';
</script>
<template>
  <footer>
    <!-- variant="float" (default floating button) or "inline" for a footer trigger -->
    <DispatchWidget variant="inline" label="Send Feedback" />
  </footer>
</template>
```

The Vue widget is dependency-free and talks to `/dispatch/capture` directly (CSRF
via the `<meta name="csrf-token">` tag). Disable the shipped widgets globally
without touching your layout:

> **Keeping the Vue widget up to date.** `vendor:publish` **copies** the file —
> it does not symlink it. Editing `resources/js/DispatchWidget.vue` in this
> package has **no effect** on an app that already published it; the app's copy
> is now stale and `vendor:publish` silently skips files that already exist.
> Every time the widget changes, refresh it with `--force`:
>
> ```bash
> php artisan vendor:publish --tag=dispatch-vue --force
> ```
>
> Only pass `--force` for `dispatch-vue`. Never re-run
> `vendor:publish --tag=dispatch-config --force` on an app that has already
> hand-edited `config/dispatch.php` (e.g. custom contract bindings, §3) —
> `--force` overwrites the whole file and would clobber those edits.

```php
// config/dispatch.php
'widget' => ['enabled' => env('DISPATCH_WIDGET', true)],
```

---

## 5. Staff board / list / portal routes

The service provider registers these routes automatically once the Livewire
UI classes exist in the package (`dispatch.routes.enabled`, default `true`):

| Route name | Path (default prefix `dispatch`) | Component | Middleware |
|---|---|---|---|
| `dispatch.index` | `/dispatch` | `TaskList` | `dispatch.routes.middleware` (`web`, `auth`) |
| `dispatch.board` | `/dispatch/board` | `TaskBoard` (kanban) | same |
| `dispatch.create` | `/dispatch/new` | `TaskCreate` | same |
| `dispatch.show` | `/dispatch/{task:code}` | `TaskShow` | same |
| `dispatch.portal` | `/dispatch/mine` | `MySubmissions` | `dispatch.routes.portal_middleware` |
| `dispatch.attachments.store` | `POST /dispatch/attachments` | `AttachmentController@store` | same |
| `dispatch.attachments.download` | `GET /dispatch/attachments/{attachment}/download` | `AttachmentController@download` | same |
| `dispatch.attachments.destroy` | `DELETE /dispatch/attachments/{attachment}` | `AttachmentController@destroy` | same |
| `dispatch.api.sync.snapshot` | `GET /api/dispatch/snapshot` | `SyncController@snapshot` | `dispatch.routes.api_middleware` |
| `dispatch.api.sync.apply` | `POST /api/dispatch/apply` | `SyncController@apply` | same |

Change the URL prefix, name prefix, or middleware stacks entirely in
`config/dispatch.php` under `routes`. Set `routes.enabled` to `false` and
wire your own routes to the same Livewire component classes if you need a
non-default mount point.

---

## 6. The CLI verb loop

Dispatch ships a small set of `dispatch:*` Artisan commands designed for both
a human and an AI agent to drive the same task lifecycle from the terminal.
Every command that emits data supports `--json` for machine consumption.

```
dispatch:add    <title> [--type=] [--priority=] [--status=] [--description=]
                [--public] [--label=]*  [--submitter=]
                → create a task (goes through DispatchTaskService — never a bare Task::create)

dispatch:next   [--type=] [--label=] [--json]
                → the single highest-priority open task, ordered
                  in_progress > open > triage, then blocker > high > medium > low

dispatch:queue  [--n=10] [--type=] [--priority=] [--label=] [--status=]*
                → the next N tasks in the same priority order, as a table

dispatch:show   <code> [--no-internal] [--json]
                → full detail for one task: fields, labels, submitter/assignee, full thread

dispatch:note   <code> <body> [--public] [--author=]
                → append a comment; internal by default, --public makes it customer-visible

dispatch:done   <code> [--status=done|declined|verifying] [--ref=] [--note=] [--author=]
                → close out a task with an optional commit/PR ref and closing note

dispatch:pull   [--path=] [--dry-run]
                → fetch canonical task state from a remote Dispatch install
                  (dispatch.sync.remote_url / dispatch.sync.token) and import it locally

dispatch:push   [--path=] [--skip-export]
                → export local task state and push it to the remote install
```

A typical agent session:

```bash
php artisan dispatch:pull                     # sync canonical state down first
php artisan dispatch:next --json              # pick up the next task
# ... do the work described in the task ...
php artisan dispatch:note TASK-042 "Root cause: X. Fix: Y." 
php artisan dispatch:done TASK-042 --ref=abc1234
php artisan dispatch:push                     # sync local state back up
```

`dispatch:add`/`dispatch:next`/`dispatch:show` are the ones most worth
scripting against — their `--json` output is stable, pretty-printed JSON
(`code`, `title`, `type`, `priority`, `status`, `is_public`, `labels`,
`description`, and — for `show` — the full comment thread).

`dispatch:pull` / `dispatch:push` are for syncing two installs of *this
package* (e.g. local dev ↔ production) over `dispatch.sync.remote_url` +
`dispatch.sync.token`. Leave those unset and the two commands no-op with an
instructive error instead of failing silently — the rest of the verb loop
works fine without them.

---

## 7. Attachments

Screenshots and files on tasks/comments always go through
`Sgrjr\Dispatch\Services\AttachmentService` — never write to a disk directly.

- **Private disk only.** `config('dispatch.attachments.disk')` defaults to
  `local`, an app-private disk. Files are stored under a hashed filename, so
  the *only* way to read one back is the authorized
  `dispatch.attachments.download` route — never construct a public URL for
  an attachment, even if you point the disk at S3 or similar.
- Validation (size via `attachments.max_size_kb`, mime via
  `attachments.allowed_mimes`, and a content-sniff for anything claiming to
  be an image) happens in `AttachmentService::validate()`, called
  automatically by `store()`. A disallowed upload throws
  `Illuminate\Validation\ValidationException` — it is never silently stored.
- `AttachmentService::canAccess()` gates a download by the *same*
  `DispatchGate::scopeVisible()` scope as every other task query — an
  attachment is only as visible as the task (or comment) that owns it.

If you swap `attachments.disk` to a cloud disk, keep it off any disk
configured with a public URL — Dispatch never generates one, but a
misconfigured disk driver could still make files guessable.

---

## 8. AI / remote agent

Beyond the human-oriented verb loop in §6, Dispatch ships a set of
**agent-CLI verbs** purpose-built for an AI agent driving the backlog — plus
a way for that agent to safely work the **production** backlog from outside
the deploy entirely, via a human-commissioned session. Every verb below
supports `--json`, a stable, documented machine contract (not a
best-effort dump).

### Agent-CLI verbs

```
dispatch:claim  {--type=} {--label=*} {--assignee=} {--json} {--remote}
                → atomically claim the next actionable task: marks it
                  in_progress + assigns it in one transaction, so two agents
                  (or an agent and a human) never grab the same task

dispatch:add    {title} ... {--key=} {--remote}
                → idempotent create: pass --key=<dedupe key> and a re-run
                  with the same key returns the existing task instead of
                  creating a duplicate

dispatch:next   {--type=} {--label=*} {--json} {--remote}
dispatch:queue  {--status=} {--type=} {--label=*} {--json} {--remote}
                → filter to only agent-appropriate work, e.g. --label agent:ok

dispatch:done   {code} {--status=} {--commit=} {--result=} {--json} {--remote}
                → record a structured completion: --commit=<sha> plus
                  --result='{...}' land under context.result, tying the task
                  to the exact code change and verification that closed it

dispatch:show   {code} {--json} {--remote}
dispatch:note   {code} {body} {--internal} {--remote}

dispatch:batch  {path} {--remote} {--dry-run} {--json}
                → apply a whole manifest of add/update ops in ONE transaction:
                  work the backlog offline, then memorialize the run in a single
                  hit instead of a verb call per task (see "Batch memorialize")

dispatch:schema → dumps the documented --json shape (the frozen
                  TaskPresenter contract) — parse against this, not a guess
```

A compact verb loop, claiming a task labeled for agent work and closing it
out with a structured result:

```bash
php artisan dispatch:claim --label=agent:ok --json
```

```json
{
    "code": "TASK-042",
    "title": "Fix checkout total with coupon applied",
    "type": "bug",
    "priority": "high",
    "status": "in_progress",
    "is_public": false,
    "labels": ["agent:ok", "area:checkout"],
    "due_at": null,
    "dedupe_key": null,
    "submitter": "jane@example.com",
    "assignee": "agent@example.com",
    "created_at": "2026-07-10T14:02:00+00:00",
    "updated_at": "2026-07-16T09:15:00+00:00",
    "description": "Totals are wrong when a coupon is applied after tax. Repro on the linked order.",
    "context": null,
    "comments": [
        {
            "id": 51,
            "event_type": "comment",
            "is_internal": false,
            "author": "jane@example.com",
            "body": "Focus on the after-tax path first — that's the reported one.",
            "meta": null,
            "created_at": "2026-07-16T09:10:00+00:00"
        }
    ]
}
```

```bash
php artisan dispatch:note TASK-042 "Root cause: coupon discount applied after tax."
php artisan dispatch:done TASK-042 --commit=abc1234 --result='{"tests":"passing"}'
```

`add`/`next`/`queue` return the **summary** shape (through `updated_at`).
`claim` and `dispatch:show` return the **full** shape shown above — the summary
fields plus `description`, `context`, and the full `comments[]` thread — because
a claiming agent needs the human's direction, which lives there. Run
`php artisan dispatch:schema` to get both shapes as data instead of relying on
this example.

### Batch memorialize — one hit instead of forty

The single verbs above each cost a round trip. An agent working a long run —
especially against **production** over `--remote` — would otherwise fire a
`claim`/`note`/`done`/`add` per task, dozens of progressive HTTPS hits. Instead
it can work the whole run **offline**, tracking its own changes, and commit the
result as one JSON manifest with `dispatch:batch`.

```
dispatch:batch  {path} {--remote} {--dry-run} {--json}
```

The manifest is a list of two op kinds, in the **same vocabulary** as the single
verbs:

```json
{
  "operations": [
    { "op": "add", "ref": "a1", "title": "Null-coupon crash", "type": "bug",
      "priority": "high", "labels": ["area:checkout"],
      "comments": [{ "body": "spotted while working TASK-042" }] },

    { "op": "update", "code": "TASK-042", "status": "in_progress",
      "commit": "abc1234", "labels": ["needs-review"],
      "comments": [{ "body": "partial: after-tax path fixed, pre-tax remains",
                     "internal": true }] }
  ]
}
```

```bash
php artisan dispatch:batch run.json --dry-run   # validate + preview, writes nothing
php artisan dispatch:batch run.json             # apply to the local DB
php artisan dispatch:batch run.json --remote    # memorialize on production in one hit
```

The semantics are deliberately **additive and safe**, so it stays inside the
same posture as the curated single verbs (it is *not* the destructive
package↔package snapshot `apply`):

- **`add`** mints a new task — server-minted code, defaults to **triage** (batch
  never assumes `done`). An idempotency `key` makes a re-add return the existing
  task instead of duplicating.
- **`update`** upserts the *work* on an existing task by `code` — it never
  creates, and only touches the fields you send. `status` is whatever the run
  actually reached; leave it out and the status doesn't move. This is how
  **partially-completed** work is memorialized honestly.
- **Labels attach additively** (never replace-all), so a batch can't strip a
  task's existing labels.
- **Comments dedupe** on content and **the whole manifest is one transaction** —
  a bad op rolls it all back, and a re-submit is safe (keyed adds dedupe,
  duplicate comments are skipped, an unchanged status records no event).

`op` is optional — an object with a `code` is inferred as `update`, otherwise
`add`. The response echoes each op's `ref` → minted `code` so you can map your
local handles back to production task codes. `php artisan dispatch:schema` dumps
the full manifest contract (the `batch` key) as data.

To turn a plain `todo.md`-style checklist into a manifest, see the
`dispatch-batch-migrate` skill.

### Agent run metrics

`dispatch:metrics` memorializes what an agent run cost — tokens, dollar cost,
tool usage, duration, subagents — by reading the **local Claude Code
transcript** (a model can't read its own token usage mid-run, so these come
from the transcript, never the agent's estimate). It windows to the task's
claim→now span, so one Claude Code session working many tasks in the verb loop
attributes each task correctly.

```
dispatch:metrics {code} {--since=} {--until=} {--transcript=} {--session=}
                 {--stamp} {--note} {--json}
                 → tokens (input/output/cache split + cache-hit ratio), cost_usd,
                   turns, tool_calls, per-tool histogram, subagents, errors,
                   models — for the claim→now window
```

The intended use is to fold it into the same `dispatch:done` call so the
numbers land under `context.result.metrics` alongside the commit:

```bash
php artisan dispatch:done TASK-042 --commit=abc1234 \
  --result="$(php artisan dispatch:metrics TASK-042 --json)"
```

`--stamp` deep-merges the metrics into `context.result.metrics` directly, and
`--note` posts a one-line internal summary on the timeline. Raw token counts
are stored durably; **cost is derived** from the per-model rate table in
`config/dispatch.php` (`metrics.pricing`), so edit those rates rather than
trusting a baked-in dollar figure — cache writes default to the 5-minute-TTL
rate (1.25× input).

**Locating the transcript.** By default the command derives the transcript
directory from the project path and picks the active session's file, so it
works with **no setup**. To pin the exact session when several run against one
project, add a `SessionStart` hook to the **host app's** `.claude/settings.json`
that records the transcript path (`dispatch:metrics:capture` reads the hook JSON
on stdin and writes a sidecar the command prefers):

```json
{
  "hooks": {
    "SessionStart": [
      { "hooks": [ { "type": "command", "command": "php artisan dispatch:metrics:capture" } ] }
    ]
  }
}
```

The hook is optional — discovery falls back to the newest transcript for the
project without it. Metrics against a **remote** (production) task work too:
pass `--since=<claim time>` and `--json` and pipe into `dispatch:done --remote
--result` (the task lives on production, so `--stamp`/`--note`, which write the
local DB, don't apply).

### The remote agent seam — working the production backlog from elsewhere

An agent normally only ever sees the database of the app it's running
inside. The remote agent seam (§19/§20) is a dedicated, human-commissioned
API so an agent can work the **authoritative production backlog** from
somewhere else — a laptop, a different CI job, another project entirely —
**without a standing credential**. It's an RFC-8628 device-flow shape:

1. The agent requests a session: `dispatch:session:request --name=... --purpose=... [--scope=next --scope=claim --scope=show]`. This prints a short `user_code`.
2. A human approves or denies it in production's "Agent Sessions" UI — a
   staff-gated Livewire page at `/dispatch/agent-sessions` — confirming the
   `user_code` matches what the agent displayed.
3. The agent polls with `dispatch:session:status` (async + human-gated —
   back off, don't spin). On approval, a short-TTL bearer token is stored in
   a dotfile outside the repo, owner-only (`0600`).
4. The agent then drives the same verb loop with `--remote` appended —
   `dispatch:next --remote`, `dispatch:claim --remote`,
   `dispatch:note --remote`, `dispatch:done --remote`, etc. — which routes
   through the agent API to production instead of the local DB. A `401`
   mid-session means the session was revoked or expired: the local token is
   cleared automatically and the agent should stop.
5. When the work is done, the agent ends its session with
   `dispatch:session:end` — a bearer-authed call that revokes its **own**
   session server-side (no id param, so it can only ever end itself) and
   deletes the local token. Least-privilege: surrender the credential instead
   of letting it idle to TTL. The route is not scope-gated, so an agent can
   always end itself regardless of which verbs it was granted.

Enable it on the **production** (authoritative) instance via the `agent`
block in `config/dispatch.php`:

```php
'agent' => [
    'enabled' => env('DISPATCH_AGENT', false),
    'bootstrap_secret' => env('DISPATCH_AGENT_BOOTSTRAP_SECRET'),
    'session_ttl' => (int) env('DISPATCH_AGENT_SESSION_TTL', 3600),
    'verbs' => ['next', 'queue', 'show', 'add', 'note', 'done', 'claim'],
    'remote' => [
        'url' => env('DISPATCH_AGENT_REMOTE_URL'),
        'token_path' => env('DISPATCH_AGENT_TOKEN_PATH'),
    ],
    // ...
],
```

`bootstrap_secret` gates the unauthenticated session-request endpoint (sent
as the `X-Dispatch-Bootstrap` header) — required in production. `verbs` is
the global allowlist every session's scopes are bounded by. `enabled` is
`false` by default: turn it on deliberately on the instance whose backlog an
agent should be able to work remotely.

**Generating `bootstrap_secret`.** It's compared with `hash_equals`, so any
high-entropy opaque string works — generate one with a CSPRNG (the same
primitive the package mints its own tokens with):

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # 64 hex chars / 256 bits
# Laravel-native alternative:
php artisan tinker --execute="echo Illuminate\Support\Str::random(48);"
```

Generate it in a terminal (don't let it get logged), put it in the **production**
instance's `.env` (never commit it), and clear the config cache:

```
DISPATCH_AGENT=true
DISPATCH_AGENT_BOOTSTRAP_SECRET=<the value>
```

```bash
php artisan config:clear   # or config:cache if you cache config in prod
```

It's a *coarse* anti-spam / anti-social-engineering gate on the unauthenticated
request endpoint — **not** the primary credential (that's the human approval plus
the per-session token) — so 32 bytes is ample, and rotating it later just means
updating both ends. If it's unset in production the request endpoint fail-closes
with a 503. The **client** sends the same value: pass `--secret=<value>` to
`dispatch:session:request`, or set `DISPATCH_AGENT_BOOTSTRAP_SECRET` in the client
env (the flag falls back to `config('dispatch.agent.bootstrap_secret')`).

On the **client** side (wherever the agent runs), point it at that instance:

```
DISPATCH_AGENT_REMOTE_URL=https://<production-host>/api/dispatch/agent
```

See `.claude/skills/dispatch-agent-session/SKILL.md` for the full
commissioning-and-poll protocol an agent should follow, including how to
handle a pending/denied/revoked session gracefully.

### Reactive orchestration — the `EventNotifier` binding

Dispatch's notifier seam (§3-adjacent — the fourth contract binding, under
`contracts.notifier`) fires at every mutation point regardless of which
implementation is bound. Bind
`Sgrjr\Dispatch\Support\EventNotifier` to additionally fire Laravel events —
`Sgrjr\Dispatch\Events\TaskCreated`, `TaskStatusChanged`, `TaskCommented`,
`TaskAssigned` — so a host-side listener can react automatically:

```php
// config/dispatch.php
'contracts' => [
    // ...
    'notifier' => Sgrjr\Dispatch\Support\EventNotifier::class,
],
```

```php
// app/Providers/EventServiceProvider.php (or a dedicated listener)
use Sgrjr\Dispatch\Events\TaskCreated;

Event::listen(function (TaskCreated $event) {
    if ($event->task->labels->contains('name', 'agent:ok')) {
        // e.g. queue a job that runs `dispatch:claim` and starts an agent run
    }
});
```

This is what turns "a bug got filed" into "an agent picked it up
automatically" — no polling loop required on the listening side.

---

## Next steps

- Read `config/dispatch.php` top to bottom — every option has a comment
  explaining what it's for and what the default means.
- If you have more than one class of user (staff vs. customers/submitters),
  bind a real `DispatchGate` on day one (see §3) — the shipped `DefaultGate`
  is a single-team convenience, not a security model for a multi-role app.
- See `.claude/skills/dispatch-track/SKILL.md` for wiring an AI coding agent
  to capture and drive tasks automatically via the CLI verb loop, and
  `.claude/skills/dispatch-agent-session/SKILL.md` for driving the remote
  agent protocol against a production backlog (§8).

## Programmatic reporting — the `DispatchTask` facade

Create tasks from code. The facade is a thin proxy; all logic lives in the package.

```php
use Sgrjr\Dispatch\Facades\DispatchTask;

DispatchTask::report('CSV import produced 3 malformed rows', [
    'type' => 'bug', 'priority' => 'high', 'labels' => ['import'],
]);

DispatchTask::bug('Checkout total is wrong when a coupon is applied');
DispatchTask::feature('Add a dark mode toggle');
```

Returns the created `Task` (sync mode) or `null` (queued / gated / throttled).

### Auto-file bug reports from your exception handler

```php
// bootstrap/app.php
use Sgrjr\Dispatch\Facades\DispatchTask;

->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions) {
    $exceptions->report(fn (\Throwable $e) => DispatchTask::fromException($e));
})
```

`fromException()` derives a title, a stable signature (recurring errors dedupe onto
one task and bump `times_seen` / `last_seen`), captures request/console context, and
labels it `source:exception`. It **never throws** and returns `null` when gated,
throttled, or on failure — safe to call inside an exception handler.

### Config (`config/dispatch.php` → `reporter`)

- `queue` — `false` = synchronous (default, returns the Task); a queue name string
  offloads to the queue (canonical `dispatch()` / `dispatchSync()`; returns null).
- `throttle_seconds` — minimum seconds between writes per signature (error-storm guard).
- `environments` — `null` for all, or e.g. `['production']` to gate out dev noise.
- `redact` — keys whose values are scrubbed from captured request input.

## Upgrading: Runtime Activation on Production

- composer update sgrjr/dispatch
- php artisan migrate
- set DISPATCH_AGENT=true + DISPATCH_AGENT_BOOTSTRAP_SECRET
- a running queue worker
- and npm run build.
