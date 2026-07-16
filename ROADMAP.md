# `sgrjr/dispatch` — Roadmap & Reference

> **Living reference + backlog for the package**, kept in the repo. Use **§18 (Backlog / TODO)** as the running checklist; the sections below are the design/decision reference and build history. Edit freely as the feature evolves.

---

## ▶️ RESUME HERE — orientation for a new session (read before acting)

**What this is.** The living roadmap, design reference, and backlog for **`sgrjr/dispatch`** — a standalone Laravel package: task / bug / feature dispatch with capture widgets, a Kanban board + list + submitter portal, a CLI verb-loop, attachments, client diagnostics capture, and a programmatic `DispatchTask` facade. This file lives in the package repo and travels with the code. **§18 is the actionable backlog; §1–§17 are design decisions and build history.**

**Where the pieces live** (paths are from the machine this was built on — confirm they exist):
- **This package:** `C:\Users\steph\Documents\laravel-dispatch` — git repo, GitHub `sgrjr/dispatch`, released via tags (`git tag`; was through **v0.2.1** at last write). PHP 8.2, Laravel 11/12, Livewire 3, Testbench + Pest. Its DB tables are **`dispatch_*`-prefixed**.
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
- The **config-bound seams are the extension model**: `DispatchGate`, `TenantResolver`, `SubmitterResolver` (and the planned `DispatchNotifier`). A host customizes behavior by binding these in `config/dispatch.php` — never by editing package internals.

**Biggest caveat before real users:** the **entire UI has never been verified in a live browser** — everything is unit/feature-tested only. A human browser smoke test under real centerpoint auth is the gate. Treat the UI as "compiles + tested, not yet seen."

**Decisions still pending** (see §17 open questions): the `DispatchNotifier` default (null vs mail), the capture-throttle default, and whether to build the v0.3 AI-agent set now or after the browser smoke test.

---

> **v4 change:** added **§15 Build orchestration (waves)** — how the build is executed with the `waves` conductor pattern (driver-owned contract + Sonnet workstream agents in dependency-ordered waves), including the repo-specific adaptation (the skill is `staff/`-tuned; the package repo has different verify/cwd/test rules).
>
> **BUILD LOG:**
> - ✅ **Wave 0 (foundation) shipped & committed** (`laravel-dispatch` @ `e48841a`). Testbench+Pest green (5 tests): migrations, race-safe sequential/reminted codes, contract-driven service defaults+labels, capture dedupe. 25 PHP files `php -l` clean.
> - ✅ **Wave 1 (surfaces) shipped & committed** (`bf7dcd9`) — 4 parallel Sonnet agents, disjoint file sets, zero collisions. 10 CLI verbs + `--json`; AttachmentController/SyncController/TaskUpdate; 7 Livewire components + Blade + native DnD + paste-upload; README/skill/tests. **Full suite green: 14 tests, 60 assertions** (incl. attachment authz-outside-scope, mime/size rejection, scope matrix). Package = 62 files, PHP 8.2, Laravel 11/12 + Livewire 3.
> - ✏️ Refinement: dropped the SortableJS CDN dep entirely — the board uses **dependency-free native HTML5 drag-and-drop** (`resources/dist/dispatch.js`), which also carries the paste-a-screenshot glue.
> - 📌 Known v0.1 behavior: board within-column ordering is priority-primary (a within-column drag across priority tiers won't visually stick; cross-column status moves work). Revisit if manual ordering is wanted.
> - 🔨 **Phase D (centerpoint) — backend integration PROVEN (D1–D3 + migration).** Package installed via local Composer **path repo** (`../../laravel-dispatch`, symlinked) + auto-discovered; all 10 verbs & routes register. Bound `CenterpointDispatchGate` (staff = `isAdminOrStaff`) + `AccountKeyTenantResolver` (stamps `account_key`) + `App\Dispatch\Task` subclass via published config. Migrations ran (5 `dispatch_*` tables + `account_key`). **CLI end-to-end works: `dispatch:add` → `TASK-001` on `dispatch_tasks` as the subclass, `dispatch:next --json` reads it.**
> - 🛠️ Two integration fixes to the package (committed): `enforceMorphMap`→`morphMap` (never flip `requireMorphMap` on the host); **prefixed all tables `dispatch_*`** after discovering centerpoint already has a `tasks` table (the earlier single-quote grep gave a false clear; `migrate:status` caught it).
> - ✅ **Phase D frontend wired (verified compiling):** Vue capture widget mounted on ALL authenticated pages via centerpoint's own `#chat-portal-root` sibling-app pattern (new `@auth #dispatch-widget-root` + mount block in `app.js`); staff "Dispatch" link added to `UserDrawer` (static `/dispatch/board` href, no Ziggy config); board JS published to `public/vendor/dispatch`. check-sfc + `node --check` + `php -l` all clean.
> - ✅ **Centerpoint integration committed to `master`** (`307ac32c4`). composer.json declares BOTH repos (path first for this machine, GitHub VCS fallback for others).
> - ✅ **Package pushed to GitHub** `sgrjr/dispatch` (`master` + tag **`v0.1.0`**). Other machines resolve it via the VCS repo automatically (`composer update` → `php artisan migrate`).
> - ⏭️ **Remaining:** YOU visually verify under a real staff login (`composer dev` → floating "Feedback" button on any page, "Dispatch" in the user menu, `/dispatch/board`). Optionally pin centerpoint to `^0.1.0` instead of `@dev` once stable.
> - ⚠️ The path-repo entry in centerpoint `composer.json` is **dev-only** (breaks a fresh prod `composer install`). Dispatch isn't production-deployable until `sgrjr/dispatch` is pushed to GitHub and the entry becomes a VCS repo — a one-line cutover.
>
> **v3 change (DECIDED):** image/file attachments are a **core v0.1 feature** and an explicit improvement over the rupkeep PoC — polymorphic `task_attachments` (on tasks *and* comments), paste-a-screenshot in the widget and thread, storage-disk config, strict validation.
>
> **v2 changes** (adversarial pass): added the from-any-page dispatch widget + submitter portal (v1 omitted the core product UX); collapsed the two overlapping query-scope seams into one; added model-override config; race-safe configurable task codes; theming instead of wholesale view publishing (drift risk); bundled SortableJS (no CDN); exception capture moved into the package (off by default — Sentry overlap); cross-app sync deferred; Testbench for package tests; Ziggy step; acceptance criteria.

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
| 8 | SortableJS from CDN | **Bundled publishable asset** — no external runtime dependency, CSP/intranet-safe |
| 9 | **No attachments at all** (old centerpoint Tickets proved the need; rupkeep Dispatch lacks them) | **First-class images/files**: polymorphic attachments on tasks & comments, **paste-a-screenshot** in widget/thread, disk-configurable storage, authorized downloads |
| — | CLI verb-loop, `--json` agent interface, skill, JSON-LD snapshot format | **Kept as-is** — port faithfully |

---

## 4. Package repo layout

```
laravel-dispatch/
  composer.json                  # sgrjr/dispatch; requires laravel ^11||^12, livewire ^3.0
  src/
    DispatchServiceProvider.php  # config, migrations, views, commands, Livewire, policy, routes (opt-in)
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
      DispatchWidget.php          # from-any-page capture (floating button + modal form)
      MySubmissions.php           # submitter portal: status of "my" dispatched tasks
    Console/Commands/             # add pull next queue show note done push export import
    Http/Controllers/
      SyncController.php          # JSON-LD snapshot/apply (only meaningful package↔package)
      AttachmentController.php    # authorized upload/download/delete (streams via Storage, gated by DispatchGate)
    Services/
      DispatchTaskService.php     # create + capture() single entry
      ExceptionCapture.php        # signature-dedupe 500s → bug task (config-gated, OFF by default)
    Policies/TaskPolicy.php       # delegates to DispatchGate
    Notifications/TaskUpdate.php  # brand/route from config
  database/migrations/
  resources/
    views/                        # layout-agnostic Blade; theme via CSS variables
    dist/sortable.min.js          # bundled, published via vendor:publish --tag=dispatch-assets
  config/dispatch.php
  routes/web.php  routes/api.php  # registered only if config('dispatch.routes.enabled')
  .claude/skills/dispatch-track/SKILL.md
  tests/                          # Pest + Orchestra Testbench (required to boot Laravel in a package)
  README.md
```

---

## 5. Core schema (generic — no tenant FK)

`tasks`
- `id`, `code` (unique index; prefix from config — `TASK-`, `CP-`, …; minted in a transaction with retry-on-collision), `title`, `description` (longText)
- `type` (bug/feature/chore/debt/verify), `priority` (blocker/high/medium/low), `status` (triage/open/in_progress/verifying/done/declined)
- `is_public` (bool), `position` (int, board ordering)
- `submitter_user_id`, `assignee_user_id` (unsignedBigInteger, nullable; relation via `config('dispatch.models.user')`) ⚠ assumes integer user PKs — fine for both your apps; UUID-key apps would subclass (documented limitation, not solved in v0.1)
- `exception_signature` (nullable, indexed — dedupe auto-captured errors)
- timestamps + softDeletes; indexes on `status`, `priority`, `type`, `position`

`task_comments` — event-typed timeline (comment / status_change / assignee_change / label_added|removed / is_public_toggle / promoted / exception_occurrence); `body`, `is_internal`, `notified_submitter` *(rupkeep's `sent_to_customer`, de-branded)*, `event_type`, `meta` (json)

`labels` — `name`, `color`, `description` (epics = `epic:*` naming convention) · `task_label` pivot

`task_attachments` **(core v0.1 — the headline improvement over rupkeep)**
- `id`, `attachable_type` + `attachable_id` (morph: Task or TaskComment), `uploaded_by_user_id` (nullable)
- `disk`, `path`, `original_name`, `mime_type`, `size_bytes`, `is_image` (bool), `meta` (json — dimensions, etc.)
- timestamps; index on morph pair
- **Storage rules:** files live on a configurable Laravel disk under an unguessable hashed path; **never in the DB, never web-root public**. Downloads stream through `AttachmentController` and are authorized by `DispatchGate::scopeVisible` on the parent task — no direct URLs.
- **Validation:** mime allowlist (images + pdf/txt/log by default, config), max size (default 10 MB), max per upload batch; images verified as actual images (not just extension).

**Tenant columns are NOT in the package migration.** A consuming app adds its own column via its own migration and a `Task` subclass (see §6/§8). The package never assumes what a tenant is.

> ✏️ Extra core fields wanted in v0.1 (`due_at`, `estimate`, external link)? → __________

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

- `models.user`, `models.task`, `models.task_comment`, `models.label`, `models.task_attachment`
- `attachments.disk` (default `local`), `attachments.path_prefix`, `attachments.max_size_kb`, `attachments.allowed_mimes`, `attachments.max_per_batch`
- `contracts.gate`, `contracts.tenant`, `contracts.submitter`
- `code_prefix` (default `TASK`)
- `routes.enabled`, `routes.prefix`, `routes.name_prefix`, `routes.middleware`, `routes.portal_middleware`
- `brand.name`, `brand.task_url` (closure/route-name for notification links)
- `widget.enabled` (drop-in capture widget)
- `capture.exceptions` (**default false** — see Sentry note §8), `capture.dedupe_window`
- `notifications.enabled`, `notifications.channels`
- `sync.remote_url`, `sync.token` (**optional**; verbs no-op gracefully when unset)
- `jsonld.vocab` (default `https://sgrjr.dev/schema/dispatch/v1#` or similar — not rupkeep's)

---

## 8. centerpoint integration specifics

1. Composer VCS repo entry → `composer require sgrjr/dispatch:dev-master` (pin a tag before any second consumer relies on it).
2. **Tenant:** app migration adds `account_key` (string, nullable, indexed) to `tasks`; `App\Dispatch\Task extends Sgrjr\Dispatch\Models\Task` adds it to `$fillable` + an `account()` relation; set `dispatch.models.task`.
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

## 10. Phased build plan

### Phase A — Package foundation
- [ ] A1. Scaffold: composer.json, PSR-4, provider, Testbench + Pest wiring, `.gitignore`, README, `git init`, GitHub remote
- [ ] A2. `config/dispatch.php` (full surface, §7)
- [ ] A3. Contracts + shipped defaults (`DefaultGate`, `NullTenantResolver`, `AuthSubmitterResolver`)
- [ ] A4. Core migrations (§5, indexes + unique `code`)
- [ ] A5. Models via `models.*` config; race-safe `mintCode()`; `recordEvent()`
- [ ] A6. `TaskPolicy` delegating to `DispatchGate` (no second scope anywhere)
- [ ] A7. `DispatchTaskService` (create/capture) + `ExceptionCapture` (signature dedupe, off by default)
- [ ] A8. `TaskAttachment` model + `AttachmentController` (upload/stream/delete; authz via parent-task visibility; validation per §5)
- [ ] A9. Pest+Testbench tests: minting race, scope visibility matrix (staff/submitter/anon), capture dedupe, **attachment authz (non-visible task → 403 on download) + mime/size rejection**

### Phase B — CLI + skill
- [ ] B1. The verb commands (add/pull/next/queue/show/note/done/push + export/import), `--json`, graceful no-remote
- [ ] B2. `.claude/skills/dispatch-track/SKILL.md` + CLAUDE.md snippet
- [ ] B3. Pest tests for commands (incl. `--json` shape — that's the agent contract)

### Phase C — UI (Livewire + Blade, layout-agnostic + CSS-var theme)
- [ ] C1. TaskBoard (Kanban, drag-drop → position + status_change event; bundled SortableJS asset)
- [ ] C2. TaskList (filters/search/sort/paginate)
- [ ] C3. TaskShow / TaskCreate / TaskThread
- [ ] C4. **DispatchWidget** (from-any-page floating capture: title, type, description, current-URL auto-attached, **paste/drag screenshot → attachment**)
- [ ] C5. **MySubmissions** portal view (submitter sees own tasks' status/progress)
- [ ] C6. **Attachment UI**: paste/drag upload in TaskCreate + TaskThread; inline image thumbnails/lightbox on TaskShow; file rows with size + download
- [ ] C7. Theme file (CSS variables) + configurable layout component

### Phase D — centerpoint adoption (first consumer)
- [ ] D1. VCS repo entry + require; publish config + migrations; run
- [ ] D2. `account_key` column migration + `Task` subclass + `models.task` config
- [ ] D3. Implement + bind the three contracts against centerpoint auth — **checkpoint: prove board/widget under real centerpoint login before any polish**
- [ ] D4. Layout + theme integration (no wholesale view publishing); nav entry; widget in app layout
- [ ] D5. Ziggy whitelist + `ziggy:discover --audit` green
- [ ] D6. Skill + CLAUDE.md snippet in centerpoint
- [ ] D7. Configure `attachments.disk` for centerpoint (private local disk or S3-compatible; NOT `public`)
- [ ] D8. End-to-end: user dispatches from a page **with a pasted screenshot** → appears in triage with image → staff drags on board → submitter sees status in MySubmissions → dev drives `dispatch:*` loop

### Phase E — Prove & document
- [ ] E1. `composer verify` green in centerpoint; package test suite green
- [ ] E2. README install guide written *as if for a 3rd project* (the reuse test)
- [ ] E3. Tag `v0.1.0`; pin centerpoint to the tag; note in centerpoint memory/docs

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

---

## 13. Open questions (edit inline)

1. ~~Repo folder name + GitHub~~ **DECIDED: `laravel-dispatch` dir, GitHub `sgrjr/dispatch`.**
2. Extra core `tasks` fields for v0.1 (due date, estimate, external link)? → *(none specified — omitting; add later, non-breaking)*
3. ~~centerpoint tenancy~~ **DECIDED: stamp `account_key` on every task on create; scope visibility by ROLE only in v0.1** (per-account filtering can be turned on later with no backfill). `TenantResolver.stamp()` active; Gate scopes by role.
4. ~~Attachments/images in v0.1?~~ **DECIDED: YES — core v0.1 feature.** Folded into §3/§5/§7/§10/§11.
5. ~~Widget placement~~ **DECIDED: floating capture widget on ALL authenticated pages** in centerpoint.
6. Attachment storage disk for centerpoint: private local disk (simplest) or S3-compatible? → *(driver default: private `local` disk for v0.1; revisit at D7)*

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
  - *WS-UI* → `src/Livewire/*`, `resources/views/**`, `resources/dist/sortable.min.js`, theme + paste-upload JS glue
  - *WS-Docs+Tests* → `.claude/skills/dispatch-track/SKILL.md`, `README.md`, `tests/**` (Pest+Testbench)
  - → **driver audits the combined tree, runs the full suite, one commit.**

**centerpoint integration (Phase D) — driver-led, minimal fan-out:** auth is the load-bearing seam, so the **driver writes the `DispatchGate` binding + `Task` subclass + tenant migration itself** (do not hand centerpoint's non-standard auth to a Sonnet agent). At most one agent for the layout/theme/nav reskin. Runs under native `staff/` skill rules (the right column above).

**Audit gates (match §10 checkpoints):** after **Wave 1** (data + authz correct before any surface consumes it) and after **D3** (auth binding proven under a real centerpoint login).

---

## 16. Programmatic API — the `Dispatch` facade (planned, editable)

> Edit freely; mark features in/out. Approve and I build it as **v0.2.0**.
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

**Open questions:**
1. **Facade name** — `Dispatch` / `Feedback` / `Ticket` / other?
2. **Default dispatch mode** — sync (simplest) or queued (safer under load)?
3. **Auto-register** the exception hook via config in v1, or manual snippet only?

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
- **Host interop:** centerpoint binds `CenterpointDispatchNotifier` that routes into its **own** notification system — the package stays agnostic and lean, the host owns delivery. (The default impl may also fire Laravel events so event-listener hosts work too.)

### C. AI-agent iteration enhancements (the interesting layer)
The feature already ships the `dispatch:*` verbs + `--json` + the `dispatch-track` skill + JSON-LD sync + rich `context`. To make agent loops (incl. parallel/`waves`) materially better:

| # | Enhancement | Why it helps an agent | Cost |
|---|---|---|---|
| C1 | **Atomic claim** — `dispatch:next --claim` marks the task `in_progress` + assigns it in one transaction and returns it | Two agents (or agent + human) in a parallel loop never grab the same task — the #1 multi-agent hazard | low ⭐ |
| C2 | **Idempotent create** — `dispatch:add --key=…` (CLI parity with the facade's `key`) | A re-running agent doesn't spawn duplicate tasks | low ⭐ |
| C3 | **Agent-scoping** — `dispatch:next/queue --label=… --type=…` filters | Agents pick up only work flagged automatable (e.g. label `agent:ok`); humans keep the rest | low ⭐ |
| C4 | **Structured completion result** — `dispatch:done --commit=SHA --result='{…}'` stored in `context.result` | Ties each task to the code change + verification an agent produced; makes human review + audit trivial | low ⭐ |
| C5 | **Stable `--json` contract** — a documented schema for the verb outputs (+ a `dispatch:schema` dump) | The agent/skill parses against a fixed contract instead of guessing shape | low ⭐ |
| C6 | **Notifier events enable reactive orchestration** (from §17B) — a host listener can auto-spawn an agent on `taskCreated` | Turns "dispatch a bug" into "agent picks it up automatically" | free w/ B |
| C7 | **Task dependencies** — `blocks` / `blocked_by` between tasks | An agent works items in a safe order; `dispatch:next` skips blocked ones | med |
| C8 | **MCP server** exposing the verbs as native tools (`dispatch.next/show/note/done`) | Any Claude agent manipulates the board as first-class tools, no shell round-trips | high (v0.4) |

**Recommended v0.3 set:** A + B + **C1–C5** (all low-cost, high-leverage, and C4/C5 directly improve the `--json` agent contract). Defer C7 (dependencies) and C8 (MCP) to their own phase.

**Open questions:** (1) Notifier default — `NullNotifier` or `MailNotifier`? (2) Throttle default when unset — none, or a sane `'60,1'`? (3) Build the recommended v0.3 set now, or stage it after the browser smoke test?

---

## 18. Backlog / TODO (living checklist)

Single at-a-glance list of everything open. Details live in §14 / §16 / §17. Check items as they ship; add freely. Priority buckets, not a strict order.

### 🔴 Pre-rollout hardening (before real users)
- [ ] **Browser smoke test** of the full UI under real centerpoint auth — board render + drag-drop, widget submit + paste screenshot, diagnostics panel, submitter portal, `/dispatch/board` as a staff user. The biggest unknown; can't be automated from here.
- [ ] **Client-configurable capture throttle** (§17A) — `config('dispatch.capture.throttle')`; provider conditionally applies `throttle:` middleware to `/dispatch/capture` (+ upload). Guards abuse/flood.
- [ ] **Agnostic notifications via a `DispatchNotifier` seam** (§17B) — 4th config-bound contract, fire-and-forget at every mutation point. Also **fixes the board-drag-doesn't-notify gap** and keeps the package from duplicating a host's notification stack.
- [ ] **Verify notification delivery** in centerpoint (mail driver + running queue worker), or consciously accept portal-only status tracking.

### 🟡 Soon after launch
- [ ] Cap / paginate / archive the board **"done" column** (currently unbounded load — slows as it grows).
- [ ] **Submission acknowledgement** to the submitter (a receipt beyond the code shown in the modal).
- [ ] **Assignee notification** on assignment.
- [ ] **Image thumbnails / resizing** (v0.1 stores + streams full-size originals; heavy with many/large screenshots).
- [ ] Board **within-column manual ordering that sticks** (currently priority-primary sort; drag-reorder across tiers doesn't hold).

### 🤖 AI-agent iteration (target v0.3 — §17C)
- [ ] **C1** Atomic claim — `dispatch:next --claim` (marks in_progress + assigns in one txn; parallel-agent safety).
- [ ] **C2** Idempotent create — `dispatch:add --key` (CLI parity with the facade dedupe key).
- [ ] **C3** Agent-scoping — `--label` / `--type` filters on `next`/`queue` (agents pick only `agent:ok` work).
- [ ] **C4** Structured completion result — `dispatch:done --commit=SHA --result='{…}'` → `context.result` (ties tasks to code + verification).
- [ ] **C5** Stable `--json` contract + a `dispatch:schema` dump (agents parse a fixed shape).
- [ ] **C6** Reactive orchestration via notifier events (auto-spawn an agent on `taskCreated`) — free once §17B lands.

### 🌐 Remote agent seam — working the production backlog from elsewhere (§19)
- [ ] **Dedicated agent API** (`/api/dispatch/agent/*`) — a SEPARATE endpoint group from the human/sync surface so it carries its own security posture; NOT bolted onto `SyncController`.
- [ ] **Agent-token guard** — a distinct least-privilege credential (dispatch-only), issued per agent, revocable independently of human creds; `DISPATCH_AGENT_TOKEN` on the agent machine.
- [ ] **Remote CLI mode** — `dispatch:* --remote` routes reads/acts to the agent API (next/queue/show/add/note/done/claim) instead of the local DB.
- [ ] **Forced agent attribution** — the token identifies the agent; every remote action stamps agent id / run into the timeline as a structured event (non-optional).
- [ ] **Per-agent rate limiting + restricted verb set** (no delete/bulk); optional IP allowlist / signed requests on the agent group only.
- [ ] Update the `dispatch-track` skill + CLAUDE snippet to target production via the agent API (`--remote` / MCP), never the local dev DB.

### 🔵 Deferred / bigger phases
- [ ] **C7** Task dependencies (`blocks` / `blocked_by`) for agent sequencing.
- [ ] **C8** **MCP server** exposing the verbs as native tools — the eventual crown jewel for Claude-Code-centric workflows (v0.4).
- [ ] Cross-instance JSON-LD **sync** wired between environments (built, not yet used).
- [ ] Attachments on the **Vue widget** beyond paste; comment-attachment UI polish.
- [ ] Migrate **rupkeep-app** onto the package (retire its inline copy).
- [ ] Retire centerpoint's legacy `App\Models\Task` + old `tasks` table (tracked in centerpoint `todo.md`).
- [ ] **Server-side** `DispatchTask` integration into centerpoint's frontend error-ping endpoint (tracked in centerpoint `todo.md`).
- [ ] **Packagist** publish (currently GitHub VCS only).

### ✅ Shipped (reference — tags through v0.2.1)
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

**Dedicated agent endpoints with their own security posture — the core of Tier 1.**
Agents get a **separate API surface** (e.g. `/api/dispatch/agent/*`, its own route group + controller) — **not** the human super-user `SyncController` endpoints. Separating them is the point: the agent surface can be strict/paranoid (automated, high-volume, credential-bearing, acting on many real tasks) without constraining the human UI, and each surface carries its own protocol stack:
- **Agent-token guard** — a dedicated, least-privilege credential (dispatch actions only), issued per agent, **revocable independently** of human credentials. Not a human session, not a full app API token. Stored as `DISPATCH_AGENT_TOKEN` on the agent's machine.
- **Tighter, per-token rate limiting** — agents throttled harder and separately from humans (ties to §17A).
- **Forced attribution** — the token *is* the agent identity; every action stamps which agent / run into the task timeline as a structured event. Non-optional, so a reviewer can always tell agent actions from human ones.
- **Restricted verb set** — curated (`next` / `queue` / `show` / `claim` / `note` / `done` / `add`); destructive or bulk ops (delete, bulk `apply`, full snapshot) excluded or separately gated.
- **Independent audit** — agent actions are separately observable and killable without touching human access.
- **Optional hardening the separate group makes cheap:** IP allowlist, per-agent scopes, signed requests / mTLS — layered on the agent surface only.

**Atomic claim is a prerequisite here (C1).** A remote agent must **claim** a production task (atomic `in_progress` + assign) before working it, so parallel agents/humans never grab the same one. Across a network seam this isn't optional.

**Anti-patterns:**
- ❌ Point a dev app's DB connection at production to "just see the tasks" — live operation from a dev box, no audit boundary, easy to corrupt real data.
- ❌ Work a stale local snapshot and treat it as current — live work must hit the authoritative agent API.
- ❌ Let an agent run the loop locally thinking it affects production — it doesn't; it edits throwaway dev tasks.
- ❌ Reuse the human super-user token for agents — defeats independent revocation, attribution, and rate policy.

**Also update:** the `dispatch-track` skill + any CLAUDE.md snippet must drive the verbs against the **agent API** (`--remote` / MCP), never the local dev DB, when working the real backlog.
