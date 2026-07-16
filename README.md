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

## Next steps

- Read `config/dispatch.php` top to bottom — every option has a comment
  explaining what it's for and what the default means.
- If you have more than one class of user (staff vs. customers/submitters),
  bind a real `DispatchGate` on day one (see §3) — the shipped `DefaultGate`
  is a single-team convenience, not a security model for a multi-role app.
- See `.claude/skills/dispatch-track/SKILL.md` for wiring an AI coding agent
  to capture and drive tasks automatically via the CLI verb loop.

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
