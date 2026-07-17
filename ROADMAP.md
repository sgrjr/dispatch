# `sgrjr/dispatch` — Roadmap & Reference

> **Living reference + backlog for the package**, kept in the repo. Use **§18 (Backlog / TODO)** as the running checklist; the sections below are the design/decision reference and build history. Edit freely as the feature evolves.

---

## ▶️ RESUME HERE — orientation for a new session (read before acting)

**What this is.** The living roadmap, design reference, and backlog for **`sgrjr/dispatch`** — a standalone Laravel package: task / bug / feature dispatch with capture widgets, a Kanban board + list + submitter portal, a CLI verb-loop, attachments, client diagnostics capture, and a programmatic `DispatchTask` facade. This file lives in the package repo and travels with the code. **§18 is the actionable backlog; §1–§17 are design decisions and build history.**

**Where the pieces live** (paths are from the machine this was built on — confirm they exist):
- **This package:** GitHub `sgrjr/dispatch`, released via tags (`git tag`). *(Path drifted across machines — was `C:\Users\steph\Documents\laravel-dispatch`; on the current machine it's `C:\Users\sreynoldsjr\Documents\GitHub\dispatch`. Confirm before relying on any absolute path in this doc.)* Last tag **v0.2.2**; a large non-AI feature batch (workflow config, notifier seam, watchers, merge, markdown, bulk ops, staleness, editable body, throttle, widget a11y) is committed on `master`, **browser smoke-test GREEN (2026-07-16)**. **The AI/agent layer then shipped on top** (🤖 C1–C6 + 🌐 remote agent seam §20 Phases 1–4, package-side Testbench-green at 141 tests; centerpoint Phase-4 wiring committed) — **ready to tag (~`v0.4.0`)**, not yet tagged/pushed. **MCP (C8) still deferred to a later phase** ("lean HTTP now, MCP next", §13). Remaining before tag: the centerpoint live-delivery runtime steps (§18 🌐 last item). PHP 8.2, Laravel 11/12, Livewire 3, Testbench + Pest. DB tables are **`dispatch_*`-prefixed**.
- **First consumer:** `C:\Users\steph\Documents\centerpoint\staff` — a Laravel 12 app that installs this package via a Composer **path repository** (symlinked — so package source edits are live in centerpoint immediately) with a GitHub VCS fallback. It binds the contract seams to its own auth under `App\Dispatch\*`, hosts the Vue widget in its footer, and calls the facade from its exception handler.
- **Frozen reference — do NOT edit:** `C:\Users\steph\Documents\rupkeep-app` — the original inline implementation this package was distilled from. Read-only source of patterns.

**How to use this doc.** Start at **§18** for what's open. Pick an item, then **read the current code to establish ground truth before doing anything** (see trust/verify). Use §1–§17 to understand *why* things are shaped the way they are.

**Trust vs verify — this matters most:**
- ✅ **Safe to infer:** architectural intent, the contract-seam extension model, *why* decisions were made, the rough shape of what exists.
- ⚠️ **Must verify against the code (the code is the source of truth; this doc can drift):**
  - Any "shipped" claim, file path, class/method name, config key, or table name — confirm it exists before you rely on it.
  - Version/tag numbers, and whether a backlog checkbox is *actually* done — check `git log` / `git tag` and the tests, not the checkbox here.
  - The build-history sections (waves orchestration, phase logs) describe **how it was built once** — they are **narrative, not runnable instructions**; do not re-execute them.
  - centerpoint specifics (auth rules, `account_key` tenancy, table names) — re-confirm; the host app evolves independently.

**Conventions you'll need:**
- Verify the package: `cd laravel-dispatch && ./vendor/bin/pest` (Testbench + sqlite) and `php -l` per file. There is no `composer verify` in this repo (that's a centerpoint thing).
- Package edits are **live in centerpoint via the symlink** — EXCEPT the **published** Vue widget and config, which are **copies** in centerpoint. After editing the widget source, re-publish with `php artisan vendor:publish --tag=dispatch-vue --force`. **Never re-publish the config** (`dispatch-config`) into centerpoint — it would clobber the hand-edited contract bindings.
- Commits: package → `master`, tag on release, push to GitHub (`GIT_TERMINAL_PROMPT=0 git push`); centerpoint → `master` directly (sole dev, no feature branches); multi-line messages via `git commit -F`.
- **Release ritual (doc-sync):** when tagging a release, sweep this roadmap in the same commit — check/annotate shipped items, sync the §4/§5/§7 reference sections against the code, and update the consolidated open-decisions list (§13). Reference-section drift is this file's chronic failure mode; tying the sweep to tagging is the fix.
- The **config-bound seams are the extension model**: `DispatchGate`, `TenantResolver`, `SubmitterResolver` (and the planned `DispatchNotifier`). A host customizes behavior by binding these in `config/dispatch.php` — never by editing package internals.

**Biggest caveat — RESOLVED (2026-07-16):** the full UI has now been **browser smoke-tested green under real centerpoint auth** (board render + drag-drop, widget submit, submitter portal, task-show, bulk actions). That pass surfaced four host-integration bugs invisible to Testbench — all fixed (see the Appendix build log). The UI is no longer "tested, not yet seen." Remaining live check: confirm notification *delivery* (mail + queue worker) or accept portal-only tracking.

**Decisions still pending:** see **§13 — the single consolidated open-decisions list** (notifier default, throttle default, §20 design forks). The v0.3-timing question is **DECIDED**: the browser smoke test gates all new build phases (§18 🔴).

---

> **Build history & doc changelog** — the v2–v4 change notes and the wave-by-wave build log moved to the **Appendix** at the end of this file (they were 60 lines of history before §1).

---

## 1. Goal & strategy

Extract the proven **Dispatch** task-tracking pattern from `rupkeep-app` into a **standalone, reusable Laravel package** (`sgrjr/dispatch`) usable across multiple of your projects **without mirror-drift**.

**Strategy — the low-risk path:**
- 🧊 **rupkeep-app stays frozen.** Reference implementation, read-only. We do NOT refactor it onto the package in this phase.
- 🆕 **New standalone repo** = a clean-room, *improved* implementation informed by rupkeep's map, designed multi-project from day one.
- 🎯 **centerpoint is the first real consumer** — full replacement for its abandoned Ticket system. centerpoint is the *harder* auth/tenancy case, so proving here earns the "multi-project ready" claim.
- ⏭️ **Deferred:** migrating rupkeep itself onto the package.

**Accepted tradeoff:** two implementations coexist for a while (rupkeep inline + package). Temporary and fine — but it means **no live sync between them** (see §9): their schemas will diverge, and bridging them would couple the package to rupkeep's legacy shape.

**What the product actually is** (so the plan builds all of it, not just the tracker):
1. **Capture** — any authenticated user, from any page, dispatches a bug/feature via a drop-in widget; exceptions can auto-file deduped bug tasks; devs file via CLI.
2. **Track** — Kanban board + list for staff; a "my submissions" portal so submitters see status/progress of what they dispatched.
3. **Act** — the `dispatch:*` CLI verb-loop + `--json` machine interface + Claude Code skill: AI agents pull, work, note, and complete tasks in iterations.

---

## 2. Decisions locked (from our Q&A)

| Decision | Choice |
|---|---|
| Package / namespace | `sgrjr/dispatch` — `Sgrjr\Dispatch` |
| Package home | Standalone sibling repo (proposed dir: `C:\Users\steph\Documents\laravel-dispatch`) |
| Canonical remote | GitHub `sgrjr/dispatch` (private is fine) — Composer VCS needs a git source; local clone works offline, GitHub is the anchor |
| centerpoint ↔ old Tickets | **Start clean.** Old `tickets*` tables dormant, unreferenced. No data migration. Drop later if desired. |
| rupkeep | Untouched this phase |

> ✏️ Repo folder name — `laravel-dispatch` proposed. Change if you prefer: __________

---

## 3. Architecture — what improves over rupkeep

| # | rupkeep does this | package does this instead |
|---|---|---|
| 1 | `organization_name === 'Reynolds Upkeep'` hard-coded in 4 classes | **`DispatchGate` contract** — each app decides who is staff / who sees all |
| 2 | Visibility scoping duplicated in `TaskBoard`, `TaskList`, `DispatchController`, policy | **Exactly ONE scope**: `DispatchGate::scopeVisible()` — components, CLI, policy, and API all call it. No second filtering seam anywhere (see §6). |
| 3 | Migration hard-codes FKs to `organizations`, `customers`, `user_events` | **No tenant/org FK in core schema** — app supplies its own column via a model subclass + `TenantResolver` stamp (§6) |
| 4 | `"Rupkeep"`, `pilotcar.io`, `rupkeep.app` hard-coded (brand, routes, JSON-LD vocab) | **All config-driven**, incl. task-code prefix (`TASK-` vs `CP-`) |
| 5 | `FeedbackForm` + `ExceptionCaptureService` are app code | **Both ship IN the package**: drop-in `<livewire:dispatch-widget />` + generic signature-dedupe exception capture (config-gated, off by default) |
| 6 | `nextCode()` can double-mint under concurrency | **Race-safe**: unique index on `code` + retry-on-collision in a transaction |
| 7 | Fixed `Task` model classes | **Model overrides** via `config('dispatch.models.*')` (Sanctum/Passport pattern) — apps extend to add columns/relations |
| 8 | SortableJS from CDN | **No DnD library at all** — dependency-free native HTML5 drag-and-drop (`resources/dist/dispatch.js`, published asset); CSP/intranet-safe |
| 9 | **No attachments at all** (old centerpoint Tickets proved the need; rupkeep Dispatch lacks them) | **First-class images/files**: polymorphic attachments on tasks & comments, **paste-a-screenshot** in widget/thread, disk-configurable storage, authorized downloads |
| — | CLI verb-loop, `--json` agent interface, skill, JSON-LD snapshot format | **Kept as-is** — port faithfully |

---

## 4. Package repo layout (as shipped — re-sync at each release per the release ritual)

```
laravel-dispatch/
  composer.json                  # sgrjr/dispatch; requires laravel ^11||^12, livewire ^3.0
  src/
    DispatchServiceProvider.php  # config, migrations, views, commands, Livewire, policy, routes (opt-in)
    DispatchManager.php          # facade target: report/bug/feature/fromException + gate/throttle/redact/context
    Facades/DispatchTask.php     # the static entry point (§16)
    Jobs/CreateDispatchTask.php  # queueable create (reporter.queue → dispatch() vs dispatchSync())
    Contracts/
      DispatchGate.php           # authorization + THE visibility scope
      TenantResolver.php         # stamp/read tenant (no query filtering — see §6)
      SubmitterResolver.php      # current user / CLI default submitter
    Support/
      DefaultGate.php  NullTenantResolver.php  AuthSubmitterResolver.php   # working defaults
    Models/
      Task.php  TaskComment.php  Label.php  TaskAttachment.php   # resolved via config('dispatch.models.*')
    Livewire/
      TaskBoard.php  TaskList.php  TaskShow.php  TaskCreate.php  TaskThread.php
      DispatchWidget.php          # Livewire capture (floating button + modal form)
      MySubmissions.php           # submitter portal: status of "my" dispatched tasks
    Console/Commands/             # Dispatch{Add,Pull,Next,Queue,Show,Note,Done,Push,Export,Import}
    Http/Controllers/
      SyncController.php          # JSON-LD snapshot/apply (only meaningful package↔package)
      AttachmentController.php    # authorized upload/download/delete (streams via Storage, gated by DispatchGate)
      CaptureController.php       # headless capture API (Vue widget + client diagnostics post here)
    Services/
      DispatchTaskService.php     # create + capture() single entry
      AttachmentService.php       # store/validate/authorize (shared by controller + Livewire)
    Policies/TaskPolicy.php       # delegates to DispatchGate
    Notifications/TaskUpdate.php  # brand/route from config
  database/migrations/            # 6 files; ALL tables dispatch_*-prefixed (§5)
  resources/
    views/                        # layout-agnostic Blade (components/ + livewire/); theme via CSS variables
    js/DispatchWidget.vue         # publishable Vue capture widget (vendor:publish --tag=dispatch-vue)
    js/dispatchConsole.js         # client diagnostics capture (console errors → CaptureController)
    dist/dispatch.js              # native HTML5 drag-and-drop + paste-a-screenshot glue (NO SortableJS)
  config/dispatch.php
  routes/web.php  routes/api.php  # registered only if config('dispatch.routes.enabled')
  .claude/skills/dispatch-track/SKILL.md
  tests/                          # Pest + Orchestra Testbench (required to boot Laravel in a package)
  README.md
```

> Note: exception capture lives in `DispatchManager` (the §16 reporter), not a standalone `ExceptionCapture` service as originally planned.

---

## 5. Core schema (generic — no tenant FK; **all tables `dispatch_*`-prefixed** — centerpoint already had a bare `tasks` table)

`dispatch_tasks`
- `id`, `code` (unique index; prefix from config — `TASK-`, `CP-`, …; minted in a transaction with retry-on-collision), `title`, `description` (longText)
- `type` (bug/feature/chore/debt/verify), `priority` (blocker/high/medium/low), `status` (triage/open/in_progress/verifying/done/declined)
- `is_public` (bool), `position` (int, board ordering)
- `submitter_user_id`, `assignee_user_id` (unsignedBigInteger, nullable; relation via `config('dispatch.models.user')`) ⚠ assumes integer user PKs — fine for both your apps; UUID-key apps would subclass (documented limitation, not solved in v0.1)
- `exception_signature` (nullable, indexed — dedupe auto-captured errors)
- `context` (json, nullable — request/console context, reporter occurrence data, structured results; added by migration `000006`)
- timestamps + softDeletes; indexes on `status`, `priority`, `type`, `position`

`dispatch_task_comments` — event-typed timeline (comment / status_change / assignee_change / label_added|removed / is_public_toggle / promoted / exception_occurrence); `body`, `is_internal`, `notified_submitter` *(rupkeep's `sent_to_customer`, de-branded)*, `event_type`, `meta` (json)

`dispatch_labels` — `name`, `color`, `description` (epics = `epic:*` naming convention) · `dispatch_task_label` pivot

`dispatch_task_attachments` **(core v0.1 — the headline improvement over rupkeep)**
- `id`, `attachable_type` + `attachable_id` (morph: Task or TaskComment), `uploaded_by_user_id` (nullable)
- `disk`, `path`, `original_name`, `mime_type`, `size_bytes`, `is_image` (bool), `meta` (json — dimensions, etc.)
- timestamps; index on morph pair
- **Storage rules:** files live on a configurable Laravel disk under an unguessable hashed path; **never in the DB, never web-root public**. Downloads stream through `AttachmentController` and are authorized by `DispatchGate::scopeVisible` on the parent task — no direct URLs.
- **Validation:** mime allowlist (images + pdf/txt/log by default, config), max size (default 10 MB), max per upload batch; images verified as actual images (not just extension).

**Tenant columns are NOT in the package migration.** A consuming app adds its own column via its own migration and a `Task` subclass (see §6/§8). The package never assumes what a tenant is.

**Shipped additions (this session):** `due_at` (dateTime, nullable, indexed) and `duplicate_of` (unsignedBigInteger, nullable, indexed — winner's id on a merged loser) on `dispatch_tasks`; new `dispatch_task_watchers` pivot (`task_id` FK cascade + `user_id`, unique `[task_id,user_id]`). New `TaskComment` event types `description_edited` (internal memorial of a prior body) and `merged`. `recordEvent()` gained a trailing `bool $isInternal` param.

> ✏️ Extra core fields wanted (`estimate`, external link)? → __________  *(`due_at` shipped.)*

---

## 6. The contracts (portability story — one seam per concern)

**Review fix:** v1 had query-filtering in BOTH `DispatchGate` and `TenantResolver` — re-creating rupkeep's duplicated-scope bug at the contract level. Now: **`scopeVisible()` is the only query filter in the entire system.** `TenantResolver` stamps and reports; it never filters. If an app's visibility depends on tenant, its Gate *implementation* consults its TenantResolver internally.

```php
interface DispatchGate {
    public function isStaff(?Authenticatable $user): bool;   // board/list/CLI access
    public function canSeeAll(?Authenticatable $user): bool; // superuser
    public function scopeVisible(Builder $q, ?Authenticatable $user): Builder;
    // ^ THE one scope. Board, list, portal, CLI, sync API, and TaskPolicy all route through it.
}

interface TenantResolver {
    public function currentTenant(?Authenticatable $user): int|string|null;
    public function stamp(Task $task, ?Authenticatable $user): void;  // set app's tenant column on create
}

interface SubmitterResolver {
    public function currentUserId(): ?int;
    public function defaultUserId(): ?int;   // CLI/system-created tasks
}
```

- Bound via `config('dispatch.contracts.*')`; package ships working defaults (`DefaultGate`: any authed user is staff — fine for single-team apps; `NullTenantResolver`; `AuthSubmitterResolver`).
- **Model overrides:** `config('dispatch.models.task')` etc. — an app extends `Task` to add its tenant column to `$fillable` + relations. This is how centerpoint's `account_key` attaches without the package knowing about it.

---

## 7. Config surface (`config/dispatch.php`)

> **The shipped file is the reference** — this list captures intent and grows stale; re-sync at each release (release ritual, RESUME-HERE).

- `models.user`, `models.task`, `models.task_comment`, `models.label`, `models.task_attachment`
- `attachments.disk` (default `local`), `attachments.path_prefix`, `attachments.max_size_kb`, `attachments.allowed_mimes`, `attachments.max_per_batch`
- `contracts.gate`, `contracts.tenant`, `contracts.submitter`
- `code_prefix` (default `TASK`), `connection` (DB connection override)
- `routes.enabled`, `routes.prefix`, `routes.name_prefix`, `routes.middleware`, `routes.portal_middleware`, `routes.api_middleware`
- `brand.name`, `brand.task_url` (closure/route-name for notification links)
- `widget.enabled` (drop-in capture widget)
- `capture.exceptions` (**default false** — see Sentry note §8), `capture.dedupe_window`
- `reporter.*` (the §16 facade): `queue`, `environments` (default `['production']`), `redact`, `throttle_seconds`, `trace_frames`, `exception_label`, `capture_request`
- `notifications.enabled`, `notifications.channels`
- `sync.remote_url`, `sync.token`, `sync.timeout`, `sync.verify_ssl` (**optional**; verbs no-op gracefully when unset)
- `jsonld.vocab` (default `https://sgrjr.dev/schema/dispatch/v1#` or similar — not rupkeep's)
- **Shipped (non-AI batch):** `contracts.notifier` (default `MailNotifier`) · `workflow.{types,priorities,statuses,type_labels,priority_labels,status_labels}` (empty vocab keys fall back to the `Task::*` consts; empty label maps auto-humanize) · `capture.throttle` (default `'60,1'`, `null`=off) · `board.{done_limit,manual_order}` · `staleness.{enabled,threshold_days}` · `markdown.enabled`
- **Shipped (AI/agent layer):** `agent.{enabled (default false),middleware,bootstrap_secret,session_ttl,request_ttl,poll_interval,request_throttle,verb_throttle,verbs (allowlist),remote.{url,token_path}}` — the dedicated agent API (§20). All read with in-code fallbacks; `enabled` off by default, so the agent routes register only when a host opts in.

⚠️ **In-code defaults are the real defaults.** Hosts publish `dispatch-config` once and never re-publish (doctrine — re-publishing would clobber hand-edited contract bindings). So every config key added after a host installs is **absent from that host's published file**: the package MUST read it with a safe in-code fallback (`config('dispatch.x', $default)`). The published file's defaults are documentation for new installs, nothing more.

---

## 8. centerpoint integration specifics

1. Composer VCS repo entry → `composer require sgrjr/dispatch:dev-master` (pin a tag before any second consumer relies on it).
2. **Tenant:** app migration adds `account_key` (string, nullable, indexed) to `dispatch_tasks`; `App\Dispatch\Task extends Sgrjr\Dispatch\Models\Task` adds it to `$fillable` + an `account()` relation; set `dispatch.models.task`.
3. **Bindings** (a small `DispatchServiceProvider` in centerpoint):
   - `DispatchGate` → `HasPermissionsTrait`/`HasRolesTrait` (`isStaff` = employee/admin/manager; `canSeeAll` = super/admin; `scopeVisible` = staff→all, others→own submissions + public-in-account).
   - `TenantResolver` → stamps `account_key` from `currentAccountInfo()`.
   - `SubmitterResolver` → `Auth::id()`; CLI default from config.
4. **Views — do NOT publish wholesale** (that re-creates mirror-drift at the view layer: once published+edited, package view fixes never reach centerpoint again). Instead: set `dispatch` layout component + override the CSS-variable theme file; override *individual* Blade files via `resources/views/vendor/dispatch/` only as a last resort, and log each override in the app's docs.
5. **Nav:** add "Dispatch" entry; drop the old Tickets link. Drop the `<livewire:dispatch-widget />` into the app layout so capture is truly on every page.
6. **Ziggy:** package route names must pass `php artisan ziggy:discover --audit` — whitelist `dispatch.*` names (explicit step, this is a known centerpoint tripwire).
7. **Skill:** copy `.claude/skills/dispatch-track`; add the verb-loop snippet to centerpoint's CLAUDE.md.
8. **Exception capture: leave OFF initially.** ⚠ centerpoint already runs **Sentry** — auto-filing tasks for every 500 duplicates it. Decide later whether Dispatch captures (deduped) or Sentry stays the sole error channel.
9. Old `tickets*` tables/code: dormant, untouched.

**Watch-items:** centerpoint's non-standard `Password`-model auth + the `APP_ENV=testing` boot-skip pattern → test board/widget under real centerpoint auth **early (D3)**. Confirm the layout used for Dispatch pages includes Livewire directives (`@livewireStyles/Scripts`) — centerpoint has live Livewire 3.6 components, so a working layout exists. Package pages served by Blade/Livewire, not Inertia — that's consistent with centerpoint's hybrid reality.

---

## 9. The AI / CLI pipeline

Not an AI SDK — a **CLI protocol** an external Claude Code agent drives:
`dispatch:pull → dispatch:next → …work… → dispatch:note → dispatch:done → dispatch:push`, `--json` on read verbs as the machine interface, plus the `dispatch-track` skill. Ships entirely in the package.

**Sync scope (review fix — this was scope creep in v1):**
- `SyncController` (snapshot/apply) + `pull`/`push` are **package↔package only** — same schema both ends (e.g. centerpoint local dev ↔ centerpoint prod). That's the actually-useful case.
- **No bridge to rupkeep's inline implementation.** Different schema/vocab; a compatibility shim would couple the package to rupkeep's legacy shape — the drift we're escaping. rupkeep joins the network when it migrates onto the package (future phase).
- With no `sync.remote_url` configured, `pull`/`push` print a notice and exit 0 (the agent loop still works purely locally).

---

## 10. Phased build plan (v0.1 — COMPLETED; historical)

> **Shipped through v0.2.1 — this is build history, not the backlog (that's §18).** Boxes reflect final status; the two still-open boxes (D7, D8) are tracked live in §18 🔴.

### Phase A — Package foundation
- [x] A1. Scaffold: composer.json, PSR-4, provider, Testbench + Pest wiring, `.gitignore`, README, `git init`, GitHub remote
- [x] A2. `config/dispatch.php` (full surface, §7)
- [x] A3. Contracts + shipped defaults (`DefaultGate`, `NullTenantResolver`, `AuthSubmitterResolver`)
- [x] A4. Core migrations (§5, indexes + unique `code`)
- [x] A5. Models via `models.*` config; race-safe `mintCode()`; `recordEvent()`
- [x] A6. `TaskPolicy` delegating to `DispatchGate` (no second scope anywhere)
- [x] A7. `DispatchTaskService` (create/capture) — exception capture later landed in `DispatchManager` (§16), not a standalone `ExceptionCapture`
- [x] A8. `TaskAttachment` model + `AttachmentController` (upload/stream/delete; authz via parent-task visibility; validation per §5)
- [x] A9. Pest+Testbench tests: minting race, scope visibility matrix (staff/submitter/anon), capture dedupe, **attachment authz (non-visible task → 403 on download) + mime/size rejection**

### Phase B — CLI + skill
- [x] B1. The verb commands (add/pull/next/queue/show/note/done/push + export/import), `--json`, graceful no-remote
- [x] B2. `.claude/skills/dispatch-track/SKILL.md` + CLAUDE.md snippet
- [x] B3. Pest tests for commands (incl. `--json` shape — that's the agent contract)

### Phase C — UI (Livewire + Blade, layout-agnostic + CSS-var theme)
- [x] C1. TaskBoard (Kanban, drag-drop → position + status_change event) — *shipped with dependency-free native HTML5 DnD (`resources/dist/dispatch.js`), not SortableJS*
- [x] C2. TaskList (filters/search/sort/paginate)
- [x] C3. TaskShow / TaskCreate / TaskThread
- [x] C4. **DispatchWidget** (from-any-page floating capture: title, type, description, current-URL auto-attached, **paste/drag screenshot → attachment**)
- [x] C5. **MySubmissions** portal view (submitter sees own tasks' status/progress)
- [x] C6. **Attachment UI**: paste/drag upload in TaskCreate + TaskThread; inline image thumbnails/lightbox on TaskShow; file rows with size + download
- [x] C7. Theme file (CSS variables) + configurable layout component

### Phase D — centerpoint adoption (first consumer)
- [x] D1. VCS repo entry + require; publish config + migrations; run *(landed as path repo + VCS fallback)*
- [x] D2. `account_key` column migration + `Task` subclass + `models.task` config
- [x] D3. Implement + bind the three contracts against centerpoint auth — *backend proven via CLI end-to-end; the browser half of this checkpoint is D8/§18 🔴*
- [x] D4. Layout + theme integration (no wholesale view publishing); nav entry; widget in app layout *(verified compiling, not yet in-browser)*
- [x] D5. Ziggy whitelist + `ziggy:discover --audit` green — *sidestepped: nav uses a static `/dispatch/board` href, no Ziggy names in play; re-run the audit if package route names ever enter Ziggy*
- [x] D6. Skill + CLAUDE.md snippet in centerpoint
- [ ] D7. Configure `attachments.disk` for centerpoint (private local disk or S3-compatible; NOT `public`) — **unverified; confirm alongside the §18 🔴 smoke test**
- [ ] D8. End-to-end: user dispatches from a page **with a pasted screenshot** → appears in triage with image → staff drags on board → submitter sees status in MySubmissions → dev drives `dispatch:*` loop — **this IS the §18 🔴 browser smoke test; still open**

### Phase E — Prove & document
- [x] E1. `composer verify` green in centerpoint; package test suite green
- [x] E2. README install guide written *as if for a 3rd project* (the reuse test)
- [x] E3. Tag `v0.1.0`; pin centerpoint to the tag; note in centerpoint memory/docs

**Checkpoints for your review: after Phase A** (foundation shape) **and after D3** (auth binding proven).

---

## 11. Acceptance criteria (what "PoC proven" means)

1. A centerpoint user on any page can dispatch a bug/feature in ≤ 3 clicks — **including pasting a screenshot straight into the widget** — and it lands in **triage** with the image attached.
2. Staff see board + list scoped correctly; non-staff see **only** their own submissions (+ public); enforced by ONE scope.
3. A dev (or Claude Code via the skill) completes the full verb loop against centerpoint's DB.
4. Two tasks created concurrently never mint the same code (test-proven).
5. rupkeep-app: zero commits this phase.
6. Package installs into a fresh Laravel skeleton with defaults only (Testbench proves it) — the "3rd project" claim.
7. No published/forked package views in centerpoint beyond theme + layout config (drift guard).
8. Attachments are storage-safe: a user who cannot see a task gets **403 on its attachment URLs** (test-proven); files live on a private disk, never web-root.

---

## 12. Risks / watch-items

- **Contract leakage is the whole game.** If a rupkeep-ism or centerpoint-ism leaks into a contract, drift returns. Testbench default-install (§11.6) is the tripwire.
- centerpoint auth is non-standard (`Password` model, custom provider) → D3 checkpoint exists for exactly this.
- View overrides are the most tempting drift vector — theme first, override individual files only with a logged reason.
- `dev-master` is fine solo; **pin a tag** before any second consumer or before rupkeep migrates.
- Sentry overlap: keep `capture.exceptions=false` in centerpoint until deliberately decided.
- **Attachments = the package's first real security surface.** Mitigations baked in (§5): private disk, hashed paths, streamed downloads authorized via the ONE scope, mime allowlist + content sniffing, size caps. Tests §10-A9 / criteria §11.8 enforce it. Also: Livewire temporary uploads land on the default disk (`livewire-tmp/`) before storage — verify centerpoint's default disk is private, and paste-to-upload needs a small JS glue layer (clipboard → Livewire `upload()`), plan for that in C4/C6.
- Integer-user-PK assumption: acceptable for your apps; documented, revisit only if a UUID-keyed project appears.
- **Growing config × never-re-publish doctrine:** every config key added after a host installs is absent from that host's published file — in-code fallbacks are mandatory (§7). A `config('dispatch.x')` read without a default silently breaks the oldest consumer first.

---

## 13. Open decisions — THE single consolidated list (edit inline)

Every genuinely open decision lives here; other sections link in. **If a pending decision isn't on this list, treat it as decided (or dead) and check its home section.**

**Open:**
1. **Attachment storage disk for centerpoint**: private local (current driver default) vs S3-compatible → revisit if volume grows.

**Resolved (record):**
- **§20 design forks 1 / 2 / 4 / 5** (agent sessions) → **all resolved & built (follow-up session):** fork 1 = **self-contained package session token** (no Sanctum coupling — stays portable); fork 2 = agent principal is **null `user_id` + `TaskComment.meta{agent_session_id, agent_name}`** attribution; fork 4 = approval UI = **package-shipped Livewire** (`AgentSessions`, host links it); fork 5 = the agent surface **bypasses `scopeVisible` and is authorized staff-equivalent** (a human explicitly approved the session). Fork 3 (**`bootstrap_secret` required-in-prod + `user_code` binding**) built as specced. *(§20 Phases 1–4 built; see §18 🌐.)*
- **Remote transport / MCP timing** → **"lean HTTP now, MCP next":** the remote transport is a **bespoke authenticated `--remote` HTTP API** (built this session — dependency-free, curl-able, matches the distributable-package philosophy). **MCP (C8) stays deferred to v0.4** and will be a *thin façade* over the same `AgentController` + session auth + `TaskPresenter`, not a rewrite. The commissioning flow already mirrors a proven pattern (OAuth 2.0 Device Authorization Grant, RFC 8628), so nothing here is a novel protocol.
- **`DispatchNotifier` default binding** (§17B) → **`MailNotifier`** (the existing `TaskUpdate`, gated by `notifications.enabled`) — preserves today's status-change emails; a host binds `NullNotifier` or its own to change delivery. *(built; MailNotifier fans out to submitter + watchers + assignee, excludes the actor, never throws.)*
- **Capture-throttle default when unset** (§17A) → **`'60,1'`** (60/min per user, IP fallback) via the named `dispatch-capture` limiter; a host sets `capture.throttle` to `null` to disable. *(built.)*
- Repo folder + GitHub → `laravel-dispatch` dir, GitHub `sgrjr/dispatch`.
- Extra v0.1 core `tasks` fields → none; add later, non-breaking (`due_at` now appears in §18 🧩 staleness item).
- centerpoint tenancy → stamp `account_key` on every create; scope visibility by ROLE only in v0.1 (per-account filtering can turn on later, no backfill). `TenantResolver.stamp()` active; Gate scopes by role.
- Attachments in v0.1 → YES, core feature.
- Widget placement → floating capture widget on ALL authenticated pages.
- Facade name / mode / hook (§16 Q1–Q3) → `DispatchTask`; sync default, configurable via `reporter.queue`; manual one-liner hook (no auto-register).
- v0.3 timing (was §17 Q3) → **browser smoke test first; §18 🔴 gates all new build phases.**

---

## 14. Explicitly OUT of scope this phase

- Refactoring rupkeep onto the package (future phase — its map is already documented).
- Any sync bridge between the package and rupkeep's inline implementation.
- Multi-tenant *hosted SaaS*, billing, external users, Packagist publishing.
- Migrating old centerpoint ticket data; deleting old `tickets*` tables/code.
- Image *processing* (resize/thumbnail generation via intervention etc.) — v0.1 stores + streams originals; browser-side downscale of huge pastes is a v0.2 nicety.

---

## 15. Build orchestration — waves

Execute with the **`waves` conductor pattern** (`staff/.claude/skills/waves`): the **driver** (session model — me) owns the plan, the shared contract, the hardest seam, every audit, and the commits; cheap **Sonnet `general-purpose` agents** each build ONE disjoint file-set slice, in **dependency-ordered waves** (Wave 1 = what everything references; Wave 2 = surfaces that consume it, parallel + independent). No two agents in a wave touch the same file; agents never commit.

⚠️ **Repo-specific adaptation — the skill is tuned for the centerpoint `staff/` repo; the package repo is NOT that repo:**

| | Package repo (Phases A–C) | centerpoint `staff/` (Phase D) |
|--|--|--|
| cwd | `laravel-dispatch/` — never prefix `staff/` | `staff/` (native skill rules) |
| agent verify | `php -l <file>` only (all-PHP, Blade not `.vue`) | `php -l` / `check-sfc.cjs` per skill |
| suite (driver only) | **Testbench + Pest (sqlite)** | `composer verify` + `pest`, `staff_testing` MySQL |
| build step | none (Blade + bundled JS asset) | no `npm`/`vite build` (HMR) |
| commits | driver, per-phase (fresh repo, no branches) | driver, one atomic to `master` |

**Driver owns before any agent runs (foundation contract + de-risked seam):** repo scaffold (composer.json, dir tree, `git init` + GitHub remote), `config/dispatch.php`, `src/Contracts/*`, `DispatchServiceProvider`, and the two central services every surface hits — `DispatchTaskService` (create/capture) and `AttachmentService` (store/validate/authorize). Plus the **shared-contract block** pasted verbatim into every agent: exact model FQCNs, config keys, DB column names, the race-safe code-mint algorithm, and the `status`/`type`/`priority`/`event_type` enum values. `AttachmentService` is a deliberate pre-extraction so WS4 (controller) and WS5 (Livewire) both consume it and neither edits the other.

**Package build (Phases A–C):**
- **Wave 0 — driver-owned foundation (the whole interdependent core; written solo because it's correctness-critical and tightly coupled, and the rupkeep source is already loaded):** scaffold + composer.json, `config/dispatch.php`, `src/Contracts/*`, `src/Support/{DefaultGate,NullTenantResolver,AuthSubmitterResolver}.php`, `src/DispatchServiceProvider.php`, `src/Services/{DispatchTaskService,AttachmentService}.php`, `src/Policies/TaskPolicy.php`, `database/migrations/**`, `src/Models/{Task,TaskComment,Label,TaskAttachment}.php`, and the Testbench base `TestCase`. → driver `php -l` + a smoke test, commit `A: foundation`.
- **Wave 1 — surfaces (parallel Sonnet, disjoint file sets):**
  - *WS-Console* → `src/Console/Commands/*` (the 10 verbs, `--json`)
  - *WS-Http* → `src/Http/Controllers/{SyncController,AttachmentController}.php`, `src/Notifications/TaskUpdate.php`
  - *WS-UI* → `src/Livewire/*`, `resources/views/**`, the published board asset *(planned as `sortable.min.js`; shipped as native-DnD `resources/dist/dispatch.js`)*, theme + paste-upload JS glue
  - *WS-Docs+Tests* → `.claude/skills/dispatch-track/SKILL.md`, `README.md`, `tests/**` (Pest+Testbench)
  - → **driver audits the combined tree, runs the full suite, one commit.**

**centerpoint integration (Phase D) — driver-led, minimal fan-out:** auth is the load-bearing seam, so the **driver writes the `DispatchGate` binding + `Task` subclass + tenant migration itself** (do not hand centerpoint's non-standard auth to a Sonnet agent). At most one agent for the layout/theme/nav reskin. Runs under native `staff/` skill rules (the right column above).

**Audit gates (match §10 checkpoints):** after **Wave 1** (data + authz correct before any surface consumes it) and after **D3** (auth binding proven under a real centerpoint login).

---

## 16. Programmatic API — the `DispatchTask` facade (SHIPPED — v0.2.x)

> **SHIPPED in v0.2.x** — kept as the facade's design reference. Feature-menu status: everything below landed **except the fluent builder** (not built; revive via §18 🔵 if ever wanted). Landed: `report/bug/feature/fromException` · never-throw + never-recurse · signature/`key` dedupe · env gating (`reporter.environments`) · request/console context + redaction (`reporter.redact`) · per-signature throttle (`reporter.throttle_seconds`) · rich exception parse (`reporter.trace_frames` / `exception_label`) · queued mode (`reporter.queue` → `Jobs\CreateDispatchTask`) · per-call `capture_request`.
>
> **DECIDED:** facade = **`DispatchTask`** (`DispatchTask::report/bug/feature/fromException`). Mode = **sync by default, configurable** (`reporter.queue`): the create logic lives in a **`Dispatchable` + `ShouldQueue` job** run via **`dispatchSync()`** (immediate, returns the Task) or **`dispatch()`** (queued, returns null) — canonical Laravel "always queueable, not always queued." Env-gate + throttle + context-gathering happen in the manager *before* dispatch (so a storm never enqueues, and request context is captured while it exists); dedupe + create happen in the job. Hook = **manual one-liner** in `bootstrap/app.php`.

**Goal.** An optional, dead-simple *static* entry point so a host can create tasks from code. Headline use: plug into the app's **exception handler** to auto-file deduped bug reports. The facade is a thin proxy — **all logic lives in the package** (`DispatchManager` → the existing `DispatchTaskService`).

**Naming.** Proposed `Dispatch` (`Sgrjr\Dispatch\Facades\Dispatch`). ⚠️ Collides conceptually with Laravel's job `dispatch()` helper / `Bus`. Alternatives: `Feedback`, `Ticket`, `Reporter`. → **Q1**.

**API — simple core + intent sugar:**
```php
use Sgrjr\Dispatch\Facades\Dispatch;

// Core — the straightforward signature:
Dispatch::report(string $title, array $options = []): ?Task
//   $options: type, priority, description, labels[], public(bool),
//             context[], key (dedupe), submitter

// Intent sugar (thin wrappers over report()):
Dispatch::bug(string $title, array $options = []): ?Task       // type=bug
Dispatch::feature(string $title, array $options = []): ?Task   // type=feature

// The marquee — from a caught throwable:
Dispatch::fromException(\Throwable $e, array $options = []): ?Task
```
Returns the created/deduped `Task`, or **`null`** when gated/throttled/failed (so a caller can log the code or ignore it).

**Where the logic lives.** Facade `Dispatch` → container binding `DispatchManager` (new, in package). The manager adds the ergonomic API + exception parsing + safety/throttle/gating, and delegates creation to `DispatchTaskService` (submitter/tenant/mint/labels/context/dedupe already there).

**Exception-handler integration — two ways:**
```php
// Manual (explicit) — bootstrap/app.php
->withExceptions(function (Exceptions $e) {
    $e->report(fn (\Throwable $ex) => Dispatch::fromException($ex));
});
```
Zero-code (opt-in): `config('dispatch.reporter.auto_capture')=true` → the package registers the reportable hook itself. → **Q3**.

**Feature menu for v1** (✅ recommended core · ➕ strongly recommended · 💡 defer to v1.1):
- ✅ `report()` / `bug()` / `feature()` / `fromException()`.
- ✅ **Never-throw safety** — the reporter swallows its own failures and returns null. Mandatory: a bug-reporter that throws would break the very exception handler it lives in. Also **never re-enters itself** (an exception raised inside the reporter must not recurse).
- ✅ **Signature dedupe + occurrence tracking** — recurring identical exceptions append an occurrence and bump `times_seen` / `last_seen` in `context` instead of spawning duplicates (extends the existing `capture()`).
- ✅ **Environment gating** — `config('dispatch.reporter.environments')` (default `['production']`) so local-dev noise doesn't flood the board.
- ✅ **Auto request/console context** — in a request: URL, method, route, authed user, sanitized input, a few headers; in console: command + args. Rich reports for free, reusing the `context` column.
- ✅ **Redaction** — `config('dispatch.reporter.redact')` (password, token, secret, authorization, cookie, …) scrubbed from captured input/headers. Private by default.
- ➕ **Throttle per signature** — cache-based rate limit (e.g. ≤1 write/signature/60s) so an error storm can't hammer the DB or spam the timeline.
- ➕ **Rich exception parse** — title `{Class}: {message}`; description with `file:line` + trimmed trace; stable signature = class + normalized message + top app-frame; `source:exception` label; type=bug; status=triage.
- 💡 **Async / queued dispatch** — offload the write to a job so a failing request isn't slowed (`config reporter.queue`). → interacts with **Q2**.
- 💡 **Fluent builder** — `Dispatch::for($title)->bug()->priority('high')->label('x')->context([...])->save()` for power users; core stays array-simple.
- 💡 **Idempotency `key`** — general dedupe for non-exception reports (e.g. a monitor filing one task per issue key).

**Safety invariants:** never throw; never recurse; cheap when gated/throttled (no DB hit); no hard dependency on an HTTP request (works in console/queue).

**Open questions — all RESOLVED** (record in §13): facade = `DispatchTask` · mode = sync default, configurable via `reporter.queue` · hook = manual one-liner, no auto-register.

**Out of scope for facade v1:** editing/transitioning tasks via the facade (creation only); non-Laravel transport; the fluent builder + queued mode (v1.1 unless Q2 pulls queue in).

---

## 17. Pre-rollout hardening + AI-agent enhancements (planned, editable)

Distilled from the pre-rollout gap review. Approve items and I build them (likely `v0.3.0`).

### A. Client-configurable capture throttle
- `/dispatch/capture` (+ attachment upload) rate limit driven by **config**, not hardcoded: `config('dispatch.capture.throttle')` → `null`/`false` = none, or a limiter string like `'30,1'` (30/min), or `['max' => 30, 'per' => 1]`. The provider conditionally appends Laravel `throttle:` middleware when set. Client chooses none / X.

### B. Agnostic notifications — a 4th config-bound seam (`DispatchNotifier`), fire-and-forget
- **Problem today:** direct `$submitter->notify(TaskUpdate)` calls are scattered in `TaskShow`/`TaskThread` and **missing from `TaskBoard::moveCard`** (dragging a card doesn't notify). And the built-in mail duplicates what a host like centerpoint already has.
- **Design:** add `Sgrjr\Dispatch\Contracts\DispatchNotifier` — the 4th seam alongside Gate/Tenant/Submitter — with fire-and-forget methods: `taskCreated(Task)`, `taskStatusChanged(Task, from, to, actor)`, `taskCommented(Task, comment)`, `taskAssigned(Task, from, to)`. The package **calls the notifier at every mutation point** (create, board move, meta edit, thread comment, CLI/facade) — which also *centralizes* the trigger and **fixes the board-notify gap**.
- **Shipped defaults (lean):** `NullNotifier` (does nothing) or `MailNotifier` (the existing `TaskUpdate`, gated by `notifications.enabled`) — bound via `config('dispatch.contracts.notifier')`. Never throws.
- **Latency rule:** the package calls the notifier **synchronously, in-request**, at each mutation point — a slow implementation (inline SMTP) would visibly lag Livewire actions like a board drag. Notifier implementations SHOULD queue their own delivery (or fire queued events); state this in the contract's PHPDoc.
- **Host interop:** centerpoint binds `CenterpointDispatchNotifier` that routes into its **own** notification system — the package stays agnostic and lean, the host owns delivery. (The default impl may also fire Laravel events so event-listener hosts work too.)

### C. AI-agent iteration enhancements (the interesting layer)
The feature already ships the `dispatch:*` verbs + `--json` + the `dispatch-track` skill + JSON-LD sync + rich `context`. To make agent loops (incl. parallel/`waves`) materially better:

| # | Enhancement | Why it helps an agent | Cost |
|---|---|---|---|
| C1 | **Atomic claim** — a dedicated `dispatch:claim` verb marks the task `in_progress` + assigns it in one transaction and returns it *(dedicated verb, not a `--claim` flag on `next` — §19/§20 expose claim as its own API endpoint, so the CLI matches)* | Two agents (or agent + human) in a parallel loop never grab the same task — the #1 multi-agent hazard | low ⭐ |
| C2 | **Idempotent create** — `dispatch:add --key=…` (CLI parity with the facade's `key`) | A re-running agent doesn't spawn duplicate tasks | low ⭐ |
| C3 | **Agent-scoping** — `dispatch:next/queue --label=… --type=…` filters | Agents pick up only work flagged automatable (e.g. label `agent:ok`); humans keep the rest | low ⭐ |
| C4 | **Structured completion result** — `dispatch:done --commit=SHA --result='{…}'` stored in `context.result` | Ties each task to the code change + verification an agent produced; makes human review + audit trivial | low ⭐ |
| C5 | **Stable `--json` contract** — a documented schema for the verb outputs (+ a `dispatch:schema` dump) | The agent/skill parses against a fixed contract instead of guessing shape | low ⭐ |
| C6 | **Notifier events enable reactive orchestration** (from §17B) — a host listener can auto-spawn an agent on `taskCreated` | Turns "dispatch a bug" into "agent picks it up automatically" | free w/ B |
| C7 | **Task dependencies** — `blocks` / `blocked_by` between tasks | An agent works items in a safe order; `dispatch:next` skips blocked ones | med |
| C8 | **MCP server** exposing the verbs as native tools (`dispatch.next/show/note/done`) | Any Claude agent manipulates the board as first-class tools, no shell round-trips | high (v0.4) |

**Recommended v0.3 set:** A + B + **C1–C5** (all low-cost, high-leverage, and C4/C5 directly improve the `--json` agent contract). Defer C7 (dependencies) and C8 (MCP) to their own phase.

**Open decisions** → consolidated in §13 (notifier default; throttle default). Timing is **DECIDED**: the §18 🔴 browser smoke test gates this v0.3 set — build nothing here until 🔴 is empty.

---

## 18. Backlog / TODO (living checklist)

Single at-a-glance list of everything open. Details live in §14 / §16 / §17. Check items as they ship; add freely. Priority buckets, not a strict order.

### 🔴 Pre-rollout hardening (before real users)

> **GATE — CLEARED (2026-07-16).** The browser smoke test is green (below), so the gate that blocked later phases is lifted. The 🤖/🌐 AI-agent work is now unblocked for the follow-up session on top of this verified base.

- [x] **Browser smoke test** of the full UI under real centerpoint auth — board render + drag-drop, widget submit + **visible "Go to" links**, submitter portal, task-show, bulk actions. **Confirmed GREEN (2026-07-16).** Surfaced + fixed four host-only bugs Testbench couldn't (task-show `$errors`-bag clobber, missing `contracts.notifier` fallback, widget CSS spill from a host ID-scoped footer rule, deferred bulk-select binding) — see the Appendix build log.
- [x] **Client-configurable capture throttle** (§17A) — `config('dispatch.capture.throttle')` (default `'60,1'`, `null`=off) via a named `dispatch-capture` RateLimiter that reads config at request time; `throttle:dispatch-capture` on `/capture` + `/attachments` POST. *(shipped; ThrottleTest.)*
- [x] **Agnostic notifications via a `DispatchNotifier` seam** (§17B) — 4th config-bound contract (`NullNotifier`/`MailNotifier`, default Mail), fire-and-forget at every mutation point (create/status/comment/assign, board + meta + CLI). **Fixes the board-drag-doesn't-notify gap.** *(shipped; NotifierTest.)*
- [ ] **Verify notification delivery** in centerpoint (mail driver + running queue worker) — package-side seam + fan-out are unit-tested; live delivery under a real queue worker is a centerpoint runtime check (do it with the smoke test), or consciously accept portal-only status tracking.

### 🟡 Soon after launch
- [x] Cap / paginate / archive the board **"done" column** — capped to `config('dispatch.board.done_limit', 50)` most-recent, with a "load all" toggle; other columns unbounded. *(shipped; BoardFeaturesTest.)*
- [x] **Submission acknowledgement** to the submitter — `MailNotifier::taskCreated` notifies the submitter on create (a receipt beyond the modal code). *(shipped.)*
- [x] **Assignee notification** on assignment — `MailNotifier::taskAssigned` fired from `TaskShow::saveMeta`. *(shipped.)*
- [ ] **Image thumbnails / resizing** (v0.1 stores + streams full-size originals; heavy with many/large screenshots). **Deferred by decision** — the one confirmed item needing image processing; revisit in a follow-up.
- [x] Board **within-column manual ordering that sticks** — `config('dispatch.board.manual_order', false)`; when true, order is position-primary so a drag holds (default keeps priority-primary). *(shipped.)*

### 🤖 AI-agent iteration (target v0.3 — §17C)

> Ordering: **C1–C5 land before §20 Phase 2 (remote CLI)** — the remote mode and the agent skill parse verb output, so the stable `--json` contract (C5) must exist first or the remote surface freezes an accidental schema.

- [x] **C1** Atomic claim — `dispatch:claim` + `DispatchTaskService::claim()` (one DB txn, excludes `in_progress` so two agents never grab the same task, `SKIP LOCKED` on mysql/pgsql). *(shipped; ClaimTest / AgentCliTest.)*
- [x] **C2** Idempotent create — `dispatch:add --key` → `firstOrCreateByKey()` (unique `dedupe_key` column + race retry). *(shipped.)*
- [x] **C3** Agent-scoping — `--label` / `--type` filters on `next`/`queue` (and `claim`). *(shipped.)*
- [x] **C4** Structured completion result — `dispatch:done --commit=SHA --result='{…}'` → `context.result` via `recordResult()`. *(shipped.)*
- [x] **C5** Stable `--json` contract — the frozen `Support\TaskPresenter` shape (used by every `--json` verb + the agent API) + a `dispatch:schema` dump. *(shipped; TaskPresenterTest.)*
- [x] **C6** Reactive orchestration — `src/Events/Task{Created,StatusChanged,Commented,Assigned}` + `Support\EventNotifier`; a host binds `contracts.notifier` to it to auto-orchestrate on `TaskCreated`. *(shipped; EventNotifierTest.)*

### 🌐 Remote agent seam — working the production backlog from elsewhere (§19)

> **Built (follow-up session, §20 Phases 1–4).** Package-side is Testbench-green (`AgentSessionCoreTest` / `AgentApiTest` / `AgentApiSecurityTest` / `AgentSessionCliTest`); centerpoint wiring committed; **live runtime steps remain by hand** (see the 🔴 delivery item + the Appendix).

- [x] **Dedicated agent API** (`/api/dispatch/agent/*`) — a SEPARATE route group (`AgentController` + `AgentSessionController`, own middleware stack), NOT on `SyncController`. *(shipped.)*
- [x] **Human-commissioned session tokens** (no standing credential) — `AgentSession` model + `AgentSessionService`; RFC-8628 device-flow (coarse `bootstrap_secret` on the request endpoint + display `user_code` + secret `device_code`), short-TTL **sha256-hashed** token delivered exactly once, async poll / revoke / expire. *(shipped.)*
- [x] Production **"Agent Sessions" approval UI** — `Livewire\AgentSessions` (staff-gated, `user_code` confirmation) + centerpoint `/it/agent-sessions`. *(shipped.)*
- [x] **Remote CLI mode** — `dispatch:* --remote` via the `Console\Commands\Concerns\TalksToAgentApi` trait (next/queue/show/add/note/done/claim) + `dispatch:session:request`/`:status` (token dotfile outside the repo, 0600, delete-on-401, HTTPS-only). *(shipped.)*
- [x] **Forced agent attribution** — null `user_id` + `TaskComment.meta{agent_session_id, agent_name}` on claim/note/done; `context.agent` on add. *(shipped.)*
- [x] **Per-agent rate limiting + restricted verb set** — `dispatch-agent-request` (per IP) + `dispatch-agent-verb` (per session) limiters; per-session scope allowlist enforced by `EnsureAgentScope` (403), bounded by `agent.verbs` (no delete/bulk). *(shipped.)*
- [x] **`.claude/skills/dispatch-agent-session` skill** — request → poll (async, human-gated, back off) → denial → token → 401 revoke/expiry. *(shipped.)*
- [x] Update the `dispatch-track` skill to target production via the agent API (`--remote`), never the local dev DB. *(shipped.)*
- [ ] **Live delivery (centerpoint runtime).** `php artisan migrate` (dispatch_agent_sessions), set `DISPATCH_AGENT=true` + `DISPATCH_AGENT_BOOTSTRAP_SECRET`, run a queue worker, `npm run build` (nav link), then the live `dispatch:session:request` → approve-in-UI → `dispatch:next --remote` smoke. **By hand.**

### 🛰️ Remote-agent production-run gaps (absorbed from the centerpoint inbox)

> Source: the `dispatch-remote-agent-gaps.md` **inbox** the centerpoint host writes findings into (`staff/storage/notes/`); absorbed here for scheduling, then the inbox is reset to a stub. These came out of the **first real end-to-end `--remote` human-commissioned run against production** (`TASK-001`). All are package-level, not host bugs.

- [x] **GAP 1 (BLOCKER)** `dispatch:session:status` could never succeed — the poll route was bootstrap-gated but the command sent no `X-Dispatch-Bootstrap` header. **Fixed (v0.4.5):** `routes/agent.php` splits the poll into its own group with the bootstrap middleware removed (device_code-only, RFC 8628). *(verified live.)*
- [x] **GAP 2a (HIGH)** an agent couldn't see a task's description/comments — `claim` returned the summary shape. **Fixed (v0.4.5):** `claim` eager-loads `comments.user` and returns the FULL shape, so human direction arrives on claim with no extra call. *(AgentApiTest.)*
- [ ] **GAP 2b (HIGH)** the `dispatch-agent-session` skill under-scopes/under-drives: the step-1 request omits `--scope=show` and the documented loop never calls `show`. Add `--scope=show` (+`queue`) to the request example and make "run `dispatch:show <code> --remote` right after claim" a required loop step.
- [ ] **GAP 2c (LOW, optional)** add a cheap `comment_count` / `has_agent_direction` field to the summary shape so an agent knows to fetch full even if `claim` is left summary-only. *(Touches the frozen `TaskPresenter` C5 contract — additive only; update `dispatch:schema` with it.)*
- [x] **GAP 3 (MEDIUM)** adding `agent.remote` to the publishable config silently broke hosts that published `config/dispatch.php` before the key existed (shallow `mergeConfigFrom`). **Fixed:** `TalksToAgentApi::agentBaseUrl()/agentTokenPath()` fall back to `env(DISPATCH_AGENT_REMOTE_URL / …TOKEN_PATH)`. *(AgentSessionCliTest — GAP 3 case.)* Remaining nicety → the `UPGRADING.md` note in GAP 4.
- [ ] **GAP 4 (DOC)** after a dispatch upgrade, stale **config / route / OPcache** caches silently serve old behavior (bit both the bootstrap secret and the GAP-1 route fix on the live run). Add an "After upgrading the dispatch package" section to `UPGRADING.md`: `php artisan optimize:clear` (+ rebuild if caching), and recycle php-fpm/IIS for OPcache `validate_timestamps=0`.
- [x] **GAP 5 (MEDIUM — security/hygiene)** an agent couldn't self-revoke its bearer token (stayed valid until TTL/human revoke). **Fixed:** `dispatch:session:end` → a bearer-authed, non-scope-gated `POST session/end` that revokes the caller's OWN session (identified by token, no id param) + clears the local token dotfile. *(AgentApiTest / AgentSessionCliTest — GAP 5 cases.)*
- [ ] **DX polish (operator goodwill — host root cause, CLI could soften the edge):**
  - [ ] TLS/CA hint — catch cURL error 60 in `TalksToAgentApi` + the session commands and point at `curl.cainfo` / `openssl.cafile`.
  - [ ] Bootstrap-secret 401 hint — on a 401 from `session:request`, mention a possibly stale **config cache** after rotating the secret.
  - [ ] `dispatch:session:status --wait[=secs]` — poll-in-process (sleep + retry a few times) so the operator approves on the site once and the agent collects the token in a single blocking call (kills the double-confirmation).

**Bonus shipped alongside GAP 5 (not a gap):** `dispatch:metrics` — per-task agent run metrics (tokens/cost/tools/duration) parsed from the local Claude Code transcript, windowed claim→done, stamped under `context.result.metrics`; optional `SessionStart` capture hook (host-side). *(MetricsTest.)*

### 🧩 Product-completeness gaps (confirmed — fill over time)
From a completeness review, verified against code. **The core batch below shipped this session** (Wave 0 foundation + Wave 1 surfaces).
- [x] **Configurable workflow** — `Task::types()/priorities()/statuses()` + `*Labels()` read `config('dispatch.workflow.*')` (consts stay as fallback); `prioritySql()/statusSql()` generate sort SQL from config; board columns + list/show/create selects + filters all read it. *(shipped; WorkflowConfigTest.)*
- [x] **Markdown rendering** — `Support\Markdown::render()` (CommonMark, `html_input=escape` + unsafe links off — no separate purifier); renders task description + comment bodies; gated by `config('dispatch.markdown.enabled')`. *(shipped.)*
- [x] **Bulk operations** — full on the list (status/label/assign/decline, scope- + policy-gated, labels attach); minimal on the board (status + decline, drag disabled in select-mode). *(shipped; ListFeaturesTest / BoardFeaturesTest.)* — *board bulk label-add + assign deferred to a follow-up (list has the full set).*
- [x] **Watchers / subscribers** — `dispatch_task_watchers` + `Task::watch()/unwatch()/isWatchedBy()`; watch toggle on TaskShow; staff auto-watch on comment; `MailNotifier` fans notifications to watchers. *(shipped; WatchersTest.)*
- [x] **Merge duplicate tasks** — `DispatchTaskService::merge()` reparents comments/attachments, unions labels, memorializes both sides, soft-deletes the loser (`duplicate_of`); "merge into" UI on TaskShow + `dispatch:merge` CLI. *(shipped; MergeTest / CliMergeEditTest.)*
- [x] **Age / staleness surfacing** — `due_at` column + editor; "stale" filter + age badges on list/board via `config('dispatch.staleness.*')`. *(shipped.)*
- [x] **Capture-widget accessibility** — dialog roles, associated labels, Esc-to-close, focus-in/restore + Tab trap, `aria-live` status — on BOTH the Vue and Livewire widgets. Also fixed the invisible "Go to" links (dedicated `--dw-link` token off body color, hover recolor, dark-mode value). *(shipped; board-DnD a11y stays deferred in §22.)*

### 🔵 Deferred / bigger phases
- [ ] **C7** Task dependencies (`blocks` / `blocked_by`) for agent sequencing.
- [ ] **C8** **MCP server** exposing the verbs as native tools (v0.4). **Sequenced AFTER the bespoke `--remote` seam** ("lean HTTP now, MCP next", §13) — build it as a *thin façade* over the existing `AgentController` + session auth + `TaskPresenter`, reusing the frozen C5 `--json` contract; not a rewrite.
- [ ] Cross-instance JSON-LD **sync** wired between environments (built, not yet used).
- [ ] **Snapshot-sync conflict story** (§19 Tier 2) — define what happens when local and prod copies diverge: document last-write-wins, or detect-and-refuse. Currently an acknowledged gap with no owner.
- [ ] Attachments on the **Vue widget** beyond paste; comment-attachment UI polish.
- [ ] Migrate **rupkeep-app** onto the package (retire its inline copy).
- [ ] Retire centerpoint's legacy `App\Models\Task` + old `tasks` table (tracked in centerpoint `todo.md`).
- [ ] **Server-side** `DispatchTask` integration into centerpoint's frontend error-ping endpoint (tracked in centerpoint `todo.md`).
- [ ] **Packagist** publish (currently GitHub VCS only).

### ✅ Shipped (reference — tags through v0.2.2; core non-AI batch on `master`, untagged)
Foundation (contracts · models · services · policy · migrations) · CLI verb loop + `--json` · Livewire board / list / show / thread / create + submitter portal · Livewire **and** publishable Vue capture widgets + headless capture API · attachments (private disk, authorized downloads) · **paste-a-screenshot** · structured **diagnostics capture** (console errors + request/console context) · **`DispatchTask` facade** + exception auto-capture (`report()` + 5xx `render()`) with dedupe / throttle / never-throws · per-call `capture_request`. Consumed by centerpoint (bound contracts, footer widget, exception handler; legacy `assignDeveloperTask` retired).

---

## 19. Data authority & how agents work the production backlog (remote seam)

**Doctrine — decided (model A).** The **production database is the single authoritative home** for dispatch task data: real user feedback and the live backlog exist only there. A dev environment builds the *feature* (code) against throwaway local tasks — it is **not** a copy of the real backlog. Code flows dev→prod (git/composer); task **data never leaves production** except by a deliberate, temporary snapshot. The package is DB-agnostic — "authoritative" is a *deployment* fact, not something the package tracks.

**The seam.** Agents (and developers) run **remotely** — laptop, dev box, CI — but real work must **read and act on production's data, not a local copy**. *The agent goes to the data; the data does not come to the agent.* Running `dispatch:*` against a local dev DB only touches throwaway tasks and does nothing to the real backlog — so a remote agent needs an authenticated channel to production.

**Transport — three tiers:**

| Tier | Mechanism | Status |
|---|---|---|
| 1 | **Remote CLI mode** over a **dedicated agent API** (below) — `dispatch:* --remote` acts on production | ⚠️ to build |
| 2 | **Snapshot sync** — `dispatch:pull` prod → work locally → `dispatch:push` | ✅ built (bulk snapshot/apply), not wired; offline fallback, needs a conflict story |
| 3 | **MCP server** — verbs as native tools executed against prod; the local agent just calls tools | 🔵 deferred (C8) |

⚠️ **Snapshot privacy (Tier 2):** `dispatch:pull` copies real production task data — user feedback plus diagnostics `context` (URLs, sanitized-but-real input, headers) — onto a dev machine. Redaction happens at capture time, but a pulled snapshot is still real-user data at rest on a laptop: treat it as sensitive, never commit it, delete it when done.

⚠️ **Transport rule (Tiers 1 & 3):** the agent API is HTTPS-only; the remote client refuses `verify_ssl=false` outside a local environment. A bearer token over plaintext HTTP would undo the whole commissioning model.

**Dedicated agent endpoints with their own security posture — the core of Tier 1.**
Agents get a **separate API surface** (e.g. `/api/dispatch/agent/*`, its own route group + controller) — **not** the human super-user `SyncController` endpoints. Separating them is the point: the agent surface can be strict/paranoid (automated, high-volume, credential-bearing, acting on many real tasks) without constraining the human UI, and each surface carries its own protocol stack:
- **Auth is a human-commissioned, session-scoped token — NOT a standing credential** (see *Agent session commissioning* below). No long-lived key on disk to leak: each work session is explicitly approved by a human in production and issued a short-lived, scoped token tied to that session.
- **Tighter, per-token rate limiting** — agents throttled harder and separately from humans (ties to §17A).
- **Forced attribution** — the token *is* the agent identity; every action stamps which agent / run into the task timeline as a structured event. Non-optional, so a reviewer can always tell agent actions from human ones.
- **Restricted verb set** — curated (`next` / `queue` / `show` / `claim` / `note` / `done` / `add`); destructive or bulk ops (delete, bulk `apply`, full snapshot) excluded or separately gated.
- **Independent audit** — agent actions are separately observable and killable without touching human access.
- **Optional hardening the separate group makes cheap:** IP allowlist, per-agent scopes, signed requests / mTLS — layered on the agent surface only.

**Agent session commissioning — human-approved, asynchronous, no standing credential (the auth model).**
An agent holds no permanent key. It **requests a session** and a human in production **commissions or denies** it via a UI. The shape mirrors the **OAuth 2.0 Device Authorization Grant (RFC 8628)** — request, out-of-band human approval, poll-until-granted:
1. **Request** — the agent `POST`s `agent/session` with its identity + stated purpose/scope + machine info → prod returns `{ session_id, status: pending, poll_interval, expires_at }`.
2. **Human decision** — production surfaces the pending request in an **"Agent Sessions" approval queue** (who / what / why); a human **commissions** it (issues a token) or **denies** it (blocks the agent). Production sees *every* agent session request.
3. **Poll (async — expect a human delay)** — the agent polls `agent/session/{id}` on `poll_interval`. Approval is **not synchronous** (a person must act), so the agent waits/backs off and handles `pending` / `approved` / `denied` / `expired` — it must not block or spin.
4. **Approved** → the agent receives a **short-TTL, session-scoped token** (dispatch agent verbs only, tied to `session_id`); the verb loop uses it.
5. **Revoke / expire** — a human can kill an active session anytime → the token dies → the agent's next call `401`s → it stops gracefully. TTL expiry → re-request.

Properties: no standing credential to leak · explicit per-session human consent · full request visibility · independent per-session revocation · each session is a discrete, attributable audit unit.

**Agent-side contract — a skill this package ships.** A `.claude/skills/dispatch-agent-session` skill teaches an agent the protocol: request a session; understand that **approval is human-gated and asynchronous** (expect a delay of seconds→minutes — do NOT assume instant, do NOT spin); poll with backoff; **handle a denial gracefully** (stop + report, never retry-spam); use the session token for the verb loop; and handle **mid-session revocation / expiry** (`401` → stop). This is the agent's manual for talking to production safely, and it ships *with* the API so the two never drift.

**Atomic claim is a prerequisite here (C1).** A remote agent must **claim** a production task (atomic `in_progress` + assign) before working it, so parallel agents/humans never grab the same one. Across a network seam this isn't optional.

**Anti-patterns:**
- ❌ Point a dev app's DB connection at production to "just see the tasks" — live operation from a dev box, no audit boundary, easy to corrupt real data.
- ❌ Work a stale local snapshot and treat it as current — live work must hit the authoritative agent API.
- ❌ Let an agent run the loop locally thinking it affects production — it doesn't; it edits throwaway dev tasks.
- ❌ Reuse the human super-user token for agents — defeats independent revocation, attribution, and rate policy.

**Also update:** the `dispatch-track` skill + any CLAUDE.md snippet must drive the verbs against the **agent API** (`--remote` / MCP), never the local dev DB, when working the real backlog.

---

## 20. Build plan — remote agent session system (implements §19)

> **BUILT (follow-up session) — Phases 1–4 shipped; this section is now the design reference, not a to-do.** Package-side is Testbench-green (141 tests); centerpoint Phase-4 wiring committed (`staff` `6cad1f5f3`); only the live runtime steps remain (§18 🌐 last item). The open forks below are **all resolved** (§13): fork 1 = self-contained token, fork 2 = null-user+meta, fork 3 = `bootstrap_secret`+`user_code`, fork 4 = package Livewire UI, fork 5 = bypass `scopeVisible` as staff-equivalent. What actually shipped tracks this plan closely; a few names differ (e.g. `markUsed()` not `touch()`; a `poll_secret`/`device_code` added to close the IDOR the first draft folded into `session_id`; a `VerifyBootstrapSecret` middleware; `TaskPresenter` for C5). Re-verify against code before extending (per the RESUME-HERE doctrine).

### Architecture decision (resolved by the code map)
Keep the token **self-contained in the package** — host-agnostic. The package owns the session protocol, a session store, the dedicated agent API, and a middleware that authenticates the **`AgentSession` itself** (a hashed bearer token on the session row). The **principal is the session, not a `User`** — the package needs no host token system. centerpoint *has* a customized Sanctum (`App\Models\PersonalAccessToken` with JSON abilities + `->can()`, but a hardcoded 48h TTL), which stays available as an **optional** `AgentTokenIssuer` binding for unified audit — **not required**. Rationale: the package is distributable; baking in Sanctum would couple it to one host and break the Gate/Tenant/Submitter/Notifier seam philosophy. The host provides only a staff-gated place to approve/deny and a scheduled prune.

### Phase 1 — Package: agent session core (the auth foundation)
- **Migration** `..._create_dispatch_agent_sessions_table.php`: `agent_name, purpose, requested_meta(json), status(pending|approved|denied|revoked|expired), token_hash(nullable,unique), scopes(json), approved_by_user_id(nullable), approved_at, expires_at, last_used_at, ip, timestamps`.
- **Model** `src/Models/AgentSession.php` — `mintToken()` (random + hash, plaintext returned once), `approve($userId,$ttl)`, `deny()`, `revoke()`, `isUsable()` (approved && !expired && !revoked), `touch()`.
- **Service** `src/Services/AgentSessionService.php` — `request(name,purpose,meta)`, `approve/deny/revoke`, `resolveToken($bearer): ?AgentSession` (hash lookup + usability), `prune()`.
- **Dedicated agent API** — new `routes/agent.php`, added as a **separate group** in `DispatchServiceProvider::registerRoutes()`: prefix `api/{prefix}/agent`, name `dispatch.api.agent.`, its own middleware:
  - *Unauthenticated (throttled, gated by `bootstrap_secret` — fork 3, DECIDED):* `POST session` → `{session_id, user_code, poll_interval, expires_at}` — `user_code` is a short display code (RFC 8628's binding element) the agent shows its operator, and the approver must match it in the UI; `GET session/{id}` → status; returns the **token once** on first approved poll.
  - *Behind `src/Http/Middleware/AuthenticateAgentSession.php`* (bearer → `resolveToken` → 401 if unusable → bind session + `touch()`): the verb endpoints `next / queue / show / add / note / done / claim` in `src/Http/Controllers/AgentController.php` — thin, reuse `DispatchTaskService` + models + `DispatchGate::scopeVisible`.
- **Config** new `agent` block in `config/dispatch.php`: `enabled`, `session_ttl` (~3600s), `poll_interval` (5s), `request_throttle`, `verb_throttle`, `verbs` allowlist (no delete/bulk), `bootstrap_secret` (**required by default** — fork 3, DECIDED; may be explicitly set `null` to opt out on a trusted network).
- **Scope vocabulary (defined):** `scopes` = the per-session **verb allowlist** (a subset of `agent.verbs`), nothing else in Phase 1. The middleware/controller checks each request's verb against the session's scopes. No broader scope concepts until a real need appears — an undefined-but-present security column is how "enforce later" ships.
- **Command** `dispatch:sessions:prune` (`src/Console/Commands/DispatchSessionsPrune.php`).
- **Atomic claim (C1):** `claim` = `DB::transaction` → pick next actionable → set `status=in_progress` + assignee/meta to the session → return; concurrent sessions never collide.
- **Attribution:** agent writes stamp `TaskComment.meta = {agent_session_id, agent_name}` (+ a new event convention); pass an agent-aware `$actor` to `DispatchTaskService::create()` (param already exists).
- **Tests (Testbench):** request→pending; approve→token; token→verb authorized + `last_used_at` bumped; denied/expired/revoked→401; claim atomic (two sessions, one task); prune expires.

### Phase 2 — Package: remote CLI mode + agent session commands
> **Prerequisite: §18 🤖 C1–C5 land before this phase** — the remote mode and agent skill parse verb output, so the stable `--json` contract (C5) must exist first.

- **Agent-side flow** `src/Console/Commands/DispatchSession*.php`: `dispatch:session:request` (POST, store `session_id`, display the `user_code` for the approver), `dispatch:session:status` (poll; on approved store the token in a local dotfile) — the **async, human-gated** wait.
- **Token-file handling** (the token is a live credential for its TTL — this is where "no standing credential" quietly erodes if sloppy): the dotfile lives **outside the repo** (or the package scaffolds a `.gitignore` entry), is created with owner-only permissions (`0600`-equivalent), and is **deleted on `401`, expiry, deny, or revoke** — never left behind.
- **`--remote` on the verbs** — branch in `DispatchNext/Queue/Show/Note/Done/Add` (sites in §19's map) that, when `--remote` (or `DISPATCH_TARGET=remote`), routes through the agent API with the stored session token instead of the local DB. **Reuse the `client()` `Http` helper shape** from `DispatchPull`/`DispatchPush`.
- **Config** `agent.remote`: `url`, stored-token path — distinct from `sync.*` (which stays package↔package snapshot).

### Phase 3 — Package: approval UI + agent skill
- **Livewire** `src/Livewire/AgentSessions.php` + `resources/views/livewire/agent-sessions.blade.php` — staff-gated queue: pending (name/purpose/ip/when) → **Approve / Deny**; active → **Revoke**. Consistent with the board/list components; full-page route `dispatch.agent-sessions`.
- **Skill** `.claude/skills/dispatch-agent-session/SKILL.md` — request → **poll (async, human-gated, expect delay, don't spin)** → handle denial gracefully → use token → handle 401 revoke/expiry. Ships with the API so they don't drift.

### Phase 4 — Centerpoint integration
- **Nav + route** to the package's `AgentSessions` component, mirroring `/it/tickets` → **`/it/agent-sessions`** (new `routes/agent_sessions.php` required in the `['web','auth']` group in `bootstrap/app.php`); gate `isAdminOrStaff`/`isAdministrator` (`UserIsTrait`).
- **Scheduler:** `Schedule::command('dispatch:sessions:prune')->everyFifteenMinutes()->withoutOverlapping()->runInBackground()` in `routes/console.php` (mirror `attachments:cleanup` / `model:prune`).
- **Config:** set the `agent` block in centerpoint `config/dispatch.php`.
- **(Optional)** bind an `AgentTokenIssuer` to `App\Models\PersonalAccessToken` (parameterize its 48h TTL → short; `dispatch-agent` ability) only if unified token audit is wanted.

### Open design forks (decide before Phase 1)
1. **Token mechanism** — self-contained package token *(recommended: lean, portable)* vs bind centerpoint's Sanctum via an `AgentTokenIssuer` seam (unified audit, host-coupled).
2. **Agent principal / attribution** — null `user_id` + `meta{agent_session_id, agent_name}` *(recommended)* vs a configured "agent system user" id.
3. ~~**Request-endpoint protection**~~ **DECIDED: `bootstrap_secret` required by default + `user_code` binding.** An open `POST agent/session` on production is both a spam vector and a social-engineering vector — the approval queue is itself an attack surface (an attacker files a plausible "Steve's laptop / resume backlog work" row and waits for a tired approve-click). Mitigations: the request endpoint requires the coarse `bootstrap_secret`; the approval UI displays the session's `user_code` and the approver confirms it matches what the requesting agent displayed (the RFC 8628 element the earlier draft dropped), alongside IP + machine info framed as "did you initiate this?".
4. **Approval UI home** — package-shipped Livewire component the host links *(recommended, consistent)* vs host-built under `/it/*`.
5. **How the Gate authorizes a session principal** *(added by adversarial review — decide before Phase 1; it shapes the middleware).* `DispatchGate::scopeVisible(Builder, ?Authenticatable)` takes a *user*, and an `AgentSession` isn't `Authenticatable`. Passing `null` yields anonymous visibility (wrong); a synthetic user contradicts fork 2's null-`user_id` recommendation. Options: **(a)** the agent surface bypasses `scopeVisible` and is policy-gated as staff-equivalent — a human approved the session, so it sees the staff scope *(leading candidate: simple, and honest about what approval means)*; **(b)** extend the contract with a session-aware method (e.g. `scopeVisibleForSession()`) whose default returns the staff scope. Either way, write the decision into the Gate PHPDoc.

### Verification (end-to-end)
Package: the Testbench suite above + `php -l`. Live: on a dev box, `dispatch:session:request` → approve in the centerpoint UI → `dispatch:next --remote` returns a **production** task, `dispatch:claim --remote` locks it, `dispatch:note/done --remote` write to prod with agent attribution in the timeline; revoke mid-session → the next `--remote` call `401`s and the agent stops.

---

## 21. Design notes (decided; not yet built)

Small, settled design decisions that aren't full build plans. Capture the doctrine so a future session builds them consistently.

### 21.1 Editable task body — history *is* the comment stream (no separate mechanism)  ✅ BUILT (this session)
Built exactly as designed below: TaskShow's meta editor (gated by `canEdit()`) and the `dispatch:edit` CLI both write an `is_internal=true` `description_edited` memorial holding the full prior body before overwriting; `recordEvent()` now takes an `$isInternal` flag so the memorial is hidden from the portal. Covered by TaskShowFeaturesTest / CliMergeEditTest.

A task's `description` (body) is editable after creation — the natural home for a living "Done / Remaining" checklist on a multi-step task, distinct from the append-only comment log. The edit **is** the history mechanism:

1. Capture the **current (pre-edit) description**.
2. Write it into the **timeline as a comment** (the memorial): `event_type: 'description_edited'`, `meta: { edited_by, at }`, and the comment **body = the full previous description**.
3. Overwrite `description` with the new value.

The task always shows the *current* body; scrolling the comment stream reconstructs the full change log. **Reuses the existing `dispatch_task_comments` timeline — no diff table, no revision model, no second mechanism.** Implementation is essentially a memorial comment written immediately before `$task->description = $new; save()`.

**Decisions (locked):**
- **Full body, not a marker (doctrine).** The memorial stores the **entire previous body**, not a bare "description updated" note — storing the full prior version is what makes the stream a true change log. A marker-only approach is rejected.
- **Visibility: internal — hidden from customer-facing, visible everywhere staff see.** The memorial is an internal system event (`is_internal = true`), so the submitter portal / non-staff views (which already filter `is_internal = false` in `TaskThread`) never show stale prior bodies, while it appears in the staff timeline alongside `status_change`-style events.

**Implementation notes:**
- New `TaskComment` constant, e.g. `EVENT_DESCRIPTION_EDITED = 'description_edited'`.
- `Task::recordEvent()` currently hardcodes `is_internal = false`, so the memorial must either be written directly via `$task->comments()->create([... 'is_internal' => true ...])`, or `recordEvent()` gains an `is_internal` parameter — the memorial requires `is_internal = true` per the visibility decision.
- Editable surfaces to add when built: the `TaskShow` meta editor (staff-gated by the existing `canEdit()`) gains a description field, and a `dispatch:edit {code} --description=… [--title=…]` CLI verb (the one an agent calls, `--remote`, to keep its checklist current). Not logged in §18 backlog by request — captured here.

---

## 22. Brainstorming — gaps to reconsider (NOT committed)

Speculative ideas from the completeness review, kept **distinct from the confirmed backlog (§18)**. Not planned; revisit only if a real need appears. Do not treat as commitments.

- **Inbound email → task** — an email-to-dispatch capture channel; today all capture is in-app widget / CLI / API / facade.
- **Reporting & metrics** — cycle time, throughput, aging, backlog trend; today only per-column counts on the board.
- **Board accessibility** — native drag-drop is keyboard/screen-reader hostile; an a11y pass (keyboard reorder, ARIA) if the staff audience needs it. *(Capture-widget a11y was promoted to §18 🧩 — the widget fronts all authenticated users, not just staff.)*
- **(Clarification — not a gap)** External integrations / webhooks / Slack are the intended job of the **`DispatchNotifier` seam (§17B)**: a host binds a notifier that posts anywhere. Do **not** build a parallel webhook system.

---

## Appendix — build history & doc changelog

> Moved here from the doc header. Narrative record of how the build actually ran — **not runnable instructions** (per the trust/verify doctrine). Annotations in *[brackets]* mark what has changed since a line was written.

**v4 change:** added **§15 Build orchestration (waves)** — how the build is executed with the `waves` conductor pattern (driver-owned contract + Sonnet workstream agents in dependency-ordered waves), including the repo-specific adaptation (the skill is `staff/`-tuned; the package repo has different verify/cwd/test rules).

**BUILD LOG:**
- ✅ **Wave 0 (foundation) shipped & committed** (`laravel-dispatch` @ `e48841a`). Testbench+Pest green (5 tests): migrations, race-safe sequential/reminted codes, contract-driven service defaults+labels, capture dedupe. 25 PHP files `php -l` clean.
- ✅ **Wave 1 (surfaces) shipped & committed** (`bf7dcd9`) — 4 parallel Sonnet agents, disjoint file sets, zero collisions. 10 CLI verbs + `--json`; AttachmentController/SyncController/TaskUpdate; 7 Livewire components + Blade + native DnD + paste-upload; README/skill/tests. **Full suite green: 14 tests, 60 assertions** (incl. attachment authz-outside-scope, mime/size rejection, scope matrix). Package = 62 files, PHP 8.2, Laravel 11/12 + Livewire 3.
- ✏️ Refinement: dropped the SortableJS CDN dep entirely — the board uses **dependency-free native HTML5 drag-and-drop** (`resources/dist/dispatch.js`), which also carries the paste-a-screenshot glue.
- 📌 Known v0.1 behavior: board within-column ordering is priority-primary (a within-column drag across priority tiers won't visually stick; cross-column status moves work). Revisit if manual ordering is wanted. *[tracked in §18 🟡]*
- 🔨 **Phase D (centerpoint) — backend integration PROVEN (D1–D3 + migration).** Package installed via local Composer **path repo** (`../../laravel-dispatch`, symlinked) + auto-discovered; all 10 verbs & routes register. Bound `CenterpointDispatchGate` (staff = `isAdminOrStaff`) + `AccountKeyTenantResolver` (stamps `account_key`) + `App\Dispatch\Task` subclass via published config. Migrations ran (5 `dispatch_*` tables + `account_key`). **CLI end-to-end works: `dispatch:add` → `TASK-001` on `dispatch_tasks` as the subclass, `dispatch:next --json` reads it.**
- 🛠️ Two integration fixes to the package (committed): `enforceMorphMap`→`morphMap` (never flip `requireMorphMap` on the host); **prefixed all tables `dispatch_*`** after discovering centerpoint already has a `tasks` table (the earlier single-quote grep gave a false clear; `migrate:status` caught it).
- ✅ **Phase D frontend wired (verified compiling):** Vue capture widget mounted on ALL authenticated pages via centerpoint's own `#chat-portal-root` sibling-app pattern (new `@auth #dispatch-widget-root` + mount block in `app.js`); staff "Dispatch" link added to `UserDrawer` (static `/dispatch/board` href, no Ziggy config); board JS published to `public/vendor/dispatch`. check-sfc + `node --check` + `php -l` all clean.
- ✅ **Centerpoint integration committed to `master`** (`307ac32c4`). composer.json declares BOTH repos (path first for this machine, GitHub VCS fallback for others).
- ✅ **Package pushed to GitHub** `sgrjr/dispatch` (`master` + tag **`v0.1.0`**). Other machines resolve it via the VCS repo automatically (`composer update` → `php artisan migrate`).
- ⏭️ **Remaining:** YOU visually verify under a real staff login (`composer dev` → floating "Feedback" button on any page, "Dispatch" in the user menu, `/dispatch/board`). Optionally pin centerpoint to `^0.1.0` instead of `@dev` once stable. *[still open — now the §18 🔴 browser smoke test, which gates all new build phases]*
- ⚠️ The path-repo entry in centerpoint `composer.json` is **dev-only** (breaks a fresh prod `composer install`). Dispatch isn't production-deployable until `sgrjr/dispatch` is pushed to GitHub and the entry becomes a VCS repo — a one-line cutover. *[resolved — package is on GitHub; centerpoint declares path-first + VCS fallback]*
- 🛠️ **Post-v0.2.1 (2026-07-16):** `d98729d` fixed table-name stragglers — `Label::$table` was still unprefixed `labels` and the pivot `task_label` (now `dispatch_labels` / `dispatch_task_label`; migrations + models + tests swept). Tagged **v0.2.2** (with the roadmap sweep) — v0.2.1 ships the bug, so consumers should update to the new tag.
- 🌊 **Core feature batch (2026-07-16) — waves orchestration, driver = Opus, workers = Sonnet.** Landed the confirmed non-AI backlog (🔴 buildable + 🟡 + 🧩 + §21.1 + the invisible-"Go to"-links bug) ahead of the 🔴 browser smoke test by explicit direction. **Wave 0 (foundation, 1 agent + driver audit, `df3bf11` + rate-limiter `96d9ccb`):** `DispatchNotifier` seam (Null/Mail), config-driven workflow accessors + sort-SQL, watchers table, `merge()`, `due_at`/`duplicate_of`, `Markdown::render()`, `recordEvent()` `$isInternal`. **Wave 1 (5 parallel Sonnet agents, disjoint file sets, zero collisions, `907353c`):** board/list/show/create/thread/portal made config-driven; bulk ops (full on list, status+decline on board); watch toggle + auto-watch; merge UI + `dispatch:merge`; editable body + `dispatch:edit`; markdown render; done-column cap; manual-order toggle; stale filter/badges; notifier routing (drops the two private `notifySubmitterOfUpdate` copies, fixes the board-drag-notify gap); capture throttle; widget accessibility + the "Go to" `--dw-link` fix. Suite grew 39→**75 green (257 assertions)**. Deferred to a follow-up: image thumbnails (image-processing dep), board bulk label/assign, and the whole 🤖/🌐/🔵 AI-agent layer. NOT tagged yet — verify the 🔴 smoke test first. **Two package-side driver fixes during audit:** ambiguous `id` in `merge()`'s label pluck (→ `allRelatedIds()`), and a Testbench notifiable-user fixture (`tests/Fixtures/User.php` + `dispatchMakeUser()`).
- ✅ **Browser smoke test GREEN (2026-07-16) — 🔴 gate cleared.** Verified live in centerpoint under real staff auth. It surfaced **four host-integration bugs Testbench structurally can't catch** (each got a fix + a regression test where testable; suite 75→**77 green**):
  1. `task-show.blade.php` reused `$errors` as a local console-errors array, clobbering the ViewErrorBag → `@error(...)` threw "getBag() on array" — but only for a task WITH `context`, which no test task had (`3be1d3a`).
  2. The `contracts.notifier` binding had no in-code fallback; centerpoint's pre-seam published config lacks the key (mergeConfigFrom shallow-merges) → `app->make(null)` → "Target class [] does not exist" on every notifier call (board drag, saveMeta, comment, widget submit). Fallbacks added to all four seam bindings (`937400b`). *The canonical "growing config × never-re-publish" failure (§7/§12) — Testbench always loads the complete package config, so only a real host hits it.*
  3. Widget "Go to" links stayed white: the host's ID-scoped `footer#application-footer a { color:white }` out-specifies the widget's `data-v` scoped classes. Fixed with `!important` on the widget's own color declarations (values stay `--dispatch-*` variables, so host theming still works) — plus the Vue widget is a *compiled* copy needing `vendor:publish` + `npm run build`, unlike the symlinked Blade/PHP (`3cf3c7f`).
  4. Bulk-select used deferred `wire:model`, so toggling a checkbox never synced → "0 selected" + a permanently-disabled Apply button (deadlock). Switched to `.live` on board + list (`d13acbc`).
  Takeaway: this whole class — host CSS specificity, host config drift, Livewire binding modifiers, compiled-vs-symlinked asset delivery — is invisible to a package's own test suite and is exactly why the human smoke test is the gate.
- 🤖 **AI/agent layer (follow-up session) — waves orchestration, driver = Opus, workers = Sonnet.** Built the whole 🤖 C1–C6 + 🌐 remote agent seam (§20 Phases 1–4) on the smoke-tested base. Transport decision **"lean HTTP now, MCP next"** (§13): a bespoke authenticated `--remote` HTTP API now; MCP (C8) still deferred to v0.4 as a thin façade over the same core. Security posture: forks 1/2/4/5 resolved (self-contained token · null-user+meta attribution · package Livewire approval UI · agent surface bypasses `scopeVisible` as staff-equivalent); fork 3 = `bootstrap_secret` + `user_code`.
  - **Wave 0 (driver-owned foundation, `c993066`):** `AgentSession` model + `AgentSessionService` (RFC-8628 request→approve→poll→once-delivered sha256 token→resolve/prune), `AuthenticateAgentSession`/`EnsureAgentScope`/`VerifyBootstrapSecret` middleware, `Support\TaskPresenter` (the frozen C5 `--json` contract), `DispatchTaskService::claim()/firstOrCreateByKey()/recordResult()` + explicit-null-submitter, `TalksToAgentApi` `--remote` trait, `dedupe_key` + `EVENT_CLAIMED`, `agent` config block + provider wiring + `routes/agent.php`. Migrations `000010` (agent sessions) + `000011` (dedupe_key). 77→98 green.
  - **Wave 1 (5 parallel Sonnet agents, disjoint sets, zero collisions, `fc0a89c`):** verb edits (C2/C3/C4/C5/`--remote`); new commands (claim/schema/session:request/status/prune); `AgentController`+`AgentSessionController`+`AgentSessions` Livewire+blade; `src/Events/*`+`EventNotifier` (C6); `dispatch-agent-session` skill + README + dispatch-track update. Suite 98→**141 green (482 assertions)**. **Driver fixes during audit:** hardened `TaskPresenter` to skip a relation on a null FK (agent/CLI tasks have a null submitter — also unbroke `CommandTest` under the bare test env); authored `AgentApiSecurityTest` (no-token/expired/bootstrap-secret/foreign-private-visibility/scope matrix) as the authoritative gate.
  - **Centerpoint Phase 4 (`staff` monorepo, scoped commit `6cad1f5f3`):** `/it/agent-sessions` route → package `AgentSessions`, `dispatch:sessions:prune` scheduler, `agent` config block (WIRED BUT OFF — `DISPATCH_AGENT`/`DISPATCH_AGENT_BOOTSTRAP_SECRET`), UserDrawer nav link. **Left the repo's pre-existing unrelated WIP untouched** (committed only the 5 Phase-4 files). **Live runtime steps remain by hand** (migrate, env vars, queue worker, `npm run build`, request→approve→`--remote` smoke). Package **not yet tagged** (~`v0.4.0`) or pushed.

**v3 change (DECIDED):** image/file attachments are a **core v0.1 feature** and an explicit improvement over the rupkeep PoC — polymorphic `task_attachments` (on tasks *and* comments), paste-a-screenshot in the widget and thread, storage-disk config, strict validation.

**v2 changes** (adversarial pass): added the from-any-page dispatch widget + submitter portal (v1 omitted the core product UX); collapsed the two overlapping query-scope seams into one; added model-override config; race-safe configurable task codes; theming instead of wholesale view publishing (drift risk); bundled SortableJS (no CDN) *[later dropped for native DnD]*; exception capture moved into the package (off by default — Sentry overlap); cross-app sync deferred; Testbench for package tests; Ziggy step; acceptance criteria.
