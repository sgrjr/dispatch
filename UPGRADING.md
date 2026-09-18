# Upgrading `sgrjr/dispatch`

## After upgrading the dispatch package

The package's **routes and config are read from `vendor/` at runtime**, so an
upgrade has no effect while stale caches remain. On every host, after
`composer update sgrjr/dispatch`:

```bash
php artisan optimize:clear   # clears config + route + compiled/view/event caches
# if you deploy with caching enabled, rebuild after:
php artisan config:cache && php artisan route:cache
```

Then, on any host that serves or drives the agent API, **verify the agent config
resolved as intended**:

```bash
php artisan dispatch:doctor        # add --strict to fail CI on warnings; --json for machines
```

`dispatch:doctor` compares the live/published `dispatch.agent.*` against the
package defaults and names exactly the drift the cache layers below cause — a
verb missing from `agent.verbs`, an absent `bootstrap_secret` / `remote.*`, a
still-cached config — so you catch it here instead of via a downstream
`403 not scoped` / `401` / `503`. It exits non-zero on an error (e.g. no
bootstrap_secret in production).

Three cache layers can each **silently mask** a dispatch upgrade. The agent API
(`§20`) is especially prone to it, because both its routes/middleware and its
`bootstrap_secret` are cache-frozen:

- **Config cache** (`bootstrap/cache/config.php`) — freezes `config/dispatch.php`
  values (`agent.bootstrap_secret`, `agent.remote.*`, TTLs). Stale → a wrong or
  absent secret, or "No agent remote configured."
- **Route cache** (`bootstrap/cache/routes-*.php`) — freezes the route→middleware
  mapping and **skips the runtime route loader** in `DispatchServiceProvider`, so
  `routes/agent.php` changes stay invisible. Stale → old middleware gating (e.g. a
  poll endpoint still bootstrap-gated after an upgrade that moved it out).
- **OPcache** (especially `opcache.validate_timestamps=0`, common in production)
  — serves old compiled PHP until reset. `php artisan *:clear` does **not** reset
  it; recycle the web server / php-fpm / IIS app pool.

Quick diagnosis:

- A `401` / "Invalid bootstrap secret" right after rotating
  `DISPATCH_AGENT_BOOTSTRAP_SECRET` is almost always a **stale config cache** —
  `php artisan config:clear` and retry.
- An agent route whose middleware seems unchanged after an upgrade is a **stale
  route cache** (or OPcache) — `php artisan route:clear`, then recycle the app
  pool if it persists.
- Not sure which layer bit you? `php artisan dispatch:doctor` names the drift
  directly (missing verb, unset secret, still-cached config) instead of leaving
  you to infer it from a `403`/`401`/`503`.

## Unreleased — anchor fields (topic / origin / conversation)

**One migration, two new contract seams, no breaking change.**

```bash
composer update sgrjr/dispatch
php artisan migrate              # 000020: topic_type/topic_id/topic_account_key,
                                  #         origin_type/origin_id, conversation_id
php artisan optimize:clear
```

- **Six new nullable columns on `dispatch_tasks`**: `topic_type`/`topic_id`
  (what a task is ABOUT) + the stamped `topic_account_key` rollup,
  `origin_type`/`origin_id` (where it came FROM, write-once once set),
  `conversation_id` (its home conversation/arc). Nothing changes until a
  caller starts writing them — see README → "Anchor fields".
- **Two new contract seams**: `TopicResolver` / `OriginResolver`
  (`dispatch.contracts.topic` / `.origin`), both defaulting to a no-op Null
  implementation. If your published `config/dispatch.php` predates this
  release, the shallow `mergeConfigFrom` means these keys are simply absent
  from your `contracts` array — the package falls back to the Null resolvers
  in code either way, so this is safe to skip; republish (`--force`) only if
  you want the keys visible for editing.
- **New model API on `Task`**: `$task->topic` / `$task->origin` (a lazily-
  resolved `Anchor` value object), `setTopic()` / `setOrigin()`, the
  `conversation()` relation (throws until `dispatch.models.conversation` is
  set — nothing calls it unconfigured), and scopes `aboutTopic()`,
  `fromOrigin()`, `inConversation()`, `aboutAccount()`. If you subclass `Task`
  and override `booted()`, add `parent::booted()` — that's where the
  `topic_account_key` restamp and origin write-once guard live.
- **New CLI flags**: `dispatch:add --topic= --origin= --conversation=`;
  `dispatch:next`/`queue`/`find`/`claim` gain the same three plus
  `--topic-account=` as filters, local AND `--remote`. New batch-op fields
  `topic`/`origin`/`topic_type`/`topic_id`/`origin_type`/`origin_id`/
  `conversation_id` (tri-state like `due_at`).
- **The agent JSON contract (`dispatch:schema`) gained fields, never lost
  any**: `topic_type`, `topic_id`, `topic_account_key`, `origin_type`,
  `origin_id`, `conversation_id` on both the summary and full shapes;
  `topic_label`/`topic_url`/`origin_label`/`origin_url` on the full shape
  only. A client that reads the shape positionally (rather than by key) will
  break — everyone else is unaffected.
- **The reporter carries anchors too**: `DispatchTask::report()` /
  `bug()` / `feature()` / `fromException()` accept `topic` and `origin`
  (`"<type>[:<id>]"` strings) and `conversation` (int) options. A malformed
  anchor is logged and dropped — never the report. `fromException()` now
  records `origin_type = exception` unless the caller names another origin.
- **Backfill**: the same migration sets `origin_type` from a few pre-existing
  `source:*` labels (`source:exception`, `source:contact-form`,
  `source:email`) wherever `origin_type` is still null. Idempotent, and never
  overwrites an origin already set.
- **Visibility is unchanged**: `topic_account_key` is never consulted by
  `DispatchGate::scopeVisible()` — the anchor fields cannot widen who sees a
  task, pinned by a test.

## Unreleased — label cleanup (`/labels`) + a full-width layout

**One migration, no config key, no asset republish.**

```bash
composer update sgrjr/dispatch
php artisan migrate              # 000019: dispatch_label_aliases
php artisan optimize:clear
```

- **New staff page `/labels`** (route `dispatch.labels`, linked in the nav for
  staff) and commands `dispatch:labels`, `dispatch:labels:replace`,
  `dispatch:labels:retire`. Replace folds labels into a canonical one across
  every task and leaves the old names as **aliases**; retire removes labels
  everywhere. See README → "Cleaning up labels".
- **Label names now resolve through aliases** wherever a label is attached
  (`dispatch:add`/`done --label`, batch, capture, the create form, list bulk
  label) or filtered (`--label` on `next`/`queue`/`claim`/`find`). Nothing
  changes until someone replaces a label — the alias table starts empty. If the
  migration hasn't run yet, resolution is skipped rather than failing, so task
  capture keeps working in the window between `composer update` and `migrate`.
- **New timeline event type `label_replaced`** (internal) — it appears in
  `dispatch:schema`'s `event_types`. Anything that switches exhaustively on
  `event_type` should expect it.
- **The page shell no longer caps its width** (`.dispatch-shell` lost
  `max-width: 1200px; margin: 0 auto`). To restore the old cap, add that rule to
  your own stylesheet after the package's.
- Host with a **custom `DispatchGate`**: both the page and its write actions are
  gated on `isStaff()`, like `/focuses`.

## v0.9.0 — a session that dies without a 401 stops masquerading as local data

**No migration, no config key, no asset republish.** One **behavior change** and
one **exit-code change**, both in the same direction: a state that used to pass
silently now stops you. Read this if anything of yours scripts the dispatch CLI.

```bash
composer update sgrjr/dispatch
php artisan optimize:clear
php artisan dispatch:doctor        # now reports the token dotfile too
```

**Why.** The v0.6.0 dropped-session guard keyed itself on the drop marker, and
that marker is written in exactly one place: the 401 handler. A token that died
any *other* way left no marker, so `dispatch:session:status` reported the clean
NONE zero-state and bare verbs quietly fell back to the LOCAL dev DB — where
production tasks look deleted and local throwaways look like the board. That
happened twice in production, and once it aimed a `dispatch:batch` at the wrong
database (it rolled back on a missing code; had those codes existed locally it
would have written the wrong board and reported success).

- **Bare verbs now REFUSE after an unexplained session loss.** A new breadcrumb
  file records that this workspace held a live session; if the token is gone and
  no drop marker explains it, sticky verbs fail loud (exit 1) instead of serving
  local data. The message distinguishes **VANISHED** (no token, no 401) from
  **UNREADABLE** (a token file that exists but does not parse), because the
  recovery differs. `dispatch:edit` / `dispatch:merge` refuse on the same
  transition.
  - **Clear it deliberately:** `php artisan dispatch:session:end` acknowledges
    the loss and restores local-by-default, exactly as it does for a drop.
    `--local` remains the per-call override, and `dispatch.agent.remote.sticky=false`
    stands the whole mechanism down.
  - **Pre-existing sessions fail OPEN, once.** A token delivered by an older
    version has no breadcrumb beside it, so the guard stays quiet for that
    session and arms itself on the next token delivered. Nothing to do.

- **`dispatch:session:status` exit codes changed.** Since v0.7.0 all three local
  states exited 0. It now exits **1** in the two new failure states — a token
  file that will not parse, and a session that vanished without a marker — while
  ACTIVE / DROPPED / NONE keep their v0.7.0 codes. If you branch on this exit
  code, treat non-zero as "do not proceed against the remote", which is what it
  now means. The NONE line additionally **names the token path it searched**
  (that path is resolved from ambient env on every invocation, so two shells can
  disagree about where the session lives).

- **`dispatch:doctor` gained an `agent_token` check** — resolved path, present /
  absent / unreadable, and `expires_at`. It reports `error` for an unreadable
  dotfile, so **a `--strict` CI invocation can now fail where it previously
  passed**. That is the point: an unparseable credential was invisible to every
  diagnostic the package had.

- **A new sibling file appears beside the token dotfile:** `<token_path>.session`
  (0600, same directory as the existing `.dropped` marker). Written whenever a
  token is delivered, cleared by `dispatch:session:end`. If you back up, sync, or
  clean that directory, treat it the way you treat the token itself. Related:
  the token is now written **atomically** (temp file + rename), so an interrupted
  write can no longer leave a corrupt dotfile behind — which was the reachable
  mechanism for the failure above.

- **`dispatch:schema`'s `context` description is longer.** The frozen JSON shape
  is UNCHANGED — only the self-documenting description widened, to say that an
  exception-filed task carries its whole incident under `context` (an agent that
  read only `description` + `comments[]` declined a live bug whose `context`
  already named its fix commit). No consumer action; parsers of the shape itself
  are unaffected.

**Skills:** both shipped `SKILL.md` copies changed (read `context` before
declining a machine-filed task; "waiting on a deploy" is not a `verifying`
check; `--commit` rides a `verifying` hand-off; `session:end` is a run boundary,
not a filing boundary; never `2>&1` a `--json` verb). Hosts that published the
skills should re-publish them — see *Re-publishing skills after an upgrade*
below.

## v0.8.1 — groups/teams as assignees (W13-4)

**One migration (`000018`), no behavior change for existing data.** Tasks gain a
nullable `assignee_group` naming a key of the new `dispatch.groups` config map
(group name → member user ids/emails). The assignee stays a SINGULAR value —
the UI writes exactly one of `assignee_user_id`/`assignee_group` — but a group
assignment notifies every member, and each member counts as a GATE A
participant for the W13-5 visibility gates. The frozen agent JSON gains one
ADDITIVE key, `assignee_group` (null on ungrouped tasks). Custom notifiers can
opt into the group hook by defining `taskAssignedGroup(Task, ?string $from,
string $to, ?Authenticatable $actor)` — duck-typed, like `watcherAdded`, so
not defining it simply sends nothing.

## v0.8.1 — the visibility gates: participants-by-default, no public tasks (W13-5)

**One migration (`000017`) and a deliberate BEHAVIOR INVERSION** — read this
before upgrading a host with real users.

Tasks now carry a `visibility` column deciding which STAFF see them:

- `participants` — only the submitter, assignee, and watchers (**GATE A** —
  these three always see the task; that part is unconditional, not a setting).
  **This is the DEFAULT for new staff-created tasks**: the circle is opt-in
  to the rest of staff, per operator ruling (2026-08-06).
- `staff` — every staff member (**GATE B**, the pre-upgrade behavior). The
  per-task "Staff visibility" select on the task page (and the "Visible to
  all staff" checkbox on create) opens it.

What does NOT change silently:

- **Existing rows are backfilled to `staff`** by migration `000017` — nothing
  already on a board disappears.
- **System-originated tasks** (exception reporter, facade calls, CLI/agent/
  batch/import with no authenticated staff actor, customer widget submits)
  default to `staff` — they have no circle yet, and an invisible auto-filed
  bug would be the exact wrong failure.

What DOES change:

- **`DefaultGate` guests see NOTHING** — the old `is_public`-tasks-for-guests
  branch is gone. There is no public task visibility (**GATE D**). `is_public`
  now means exactly what its UI label always said: visible to the
  **submitting customer** (**GATE C**, default off).
- **`DefaultGate::canSeeAll()` is now `false`** (was: every authenticated
  user). `TaskPolicy::delete` moved from `canSeeAll` to `isStaff` so
  single-team apps keep delete/merge; but anything you gate on `canSeeAll`
  yourself (e.g. the Tier-2 `SyncController` endpoints) now requires a host
  gate that grants it to real superusers.
- **Host gates must adopt the gates to enforce them.** A custom
  `DispatchGate::scopeVisible` keeps whatever it did before — compose the new
  `Sgrjr\Dispatch\Support\VisibilityGates::apply($query, $user, $isStaff)`
  helper (short-circuit your `canSeeAll` first) so your gate and the shipped
  one rule identically.
- A **non-staff submitter's portal can now legitimately be empty**: GATE C
  shows a customer only the submissions whose "Visible to submitter/customer"
  toggle is on.

## v0.8.1 — `dispatch:edit` / `dispatch:merge` refuse to write the wrong database

**No migration, no config change.** One **behavior change** worth knowing before
you upgrade, because it turns a silent success into a loud failure.

- **`dispatch:edit` and `dispatch:merge` now refuse to run while an agent session
  is active**, unless you pass the new `--local`. Both are local-only verbs (no
  `--remote`, not in `agent.verbs`) and neither carried any session plumbing, so
  under a sticky-remote session they were the one place a command wrote to the
  **local dev DB** while every neighbouring verb targeted production — with no
  banner, no flag, and exit `0`. Because task codes are minted **per-database**
  (`Task::nextCode()` is `max + 1` over local rows), the same code names a
  *different* task on each side: a mid-session `dispatch:edit TASK-042
  --description=…` could overwrite an unrelated local task's body and memorialize
  the wrong prior version onto the wrong timeline, reporting success either way.
  `dispatch:merge` is worse — it soft-deletes one of the two tasks it names. The
  refusal names the remote alternative (a batch `update` op for
  title/description; `done --due=` / `done --label=`; the board for a merge).

  **If a script of yours edits or merges during a commissioned session and meant
  the local DB, add `--local`.** Nothing else changes: with no
  `agent.remote.url`, no session token, or `agent.remote.sticky=false`, the guard
  is inert and both verbs behave exactly as before. A **dropped** session (the
  `.dropped` marker) also trips it, on the same reasoning as the existing
  dropped-session guard — clear it with `dispatch:session:end`.

- **New: `dispatch:edit --description-file=PATH`** (or `-` for stdin), the same
  escape hatch `add`/`note`/`done` got in v0.5.4 and `edit` never did — despite
  writing the longest text in the package. Mutually exclusive with
  `--description`, resolved *before* any lookup or write, so a bad path is a
  no-op rather than a half-applied edit.

- **Promoting `edit` to an agent verb remains the open §13 decision.** This
  change deliberately does not force it: it makes local-only *honest*, which is
  the opposite of widening the curated-verb posture.

## v0.8.0 — batch payload ceiling fixed, `dispatch:find`, pre-expiry TTL warning, console-capture guards

**Do this in order.** One migration is load-bearing (it unblocks `dispatch:batch`),
and one asset republish is now safe that previously was not.

```bash
composer update sgrjr/dispatch
php artisan migrate                 # 000015 — REQUIRED, see below
php artisan optimize:clear
php artisan dispatch:doctor         # two new checks; see below
php artisan vendor:publish --tag=dispatch-vue --force   # only if you use the JS capture widget
```

- **One new migration, and it fixes data loss — run it.** `000015` widens
  `dispatch_task_comments.body` from `text` to `longText`. The old column capped
  at **65,535 BYTES** (~16k characters of 4-byte UTF-8 — well short of what
  "text" suggests), and an agent comment over that ceiling raised
  `SQLSTATE[22001] Data too long` *mid-transaction*, rolling back the **entire**
  batch manifest and surfacing to the caller as a bare
  `HTTP 500 {"message":"Server Error"}` that named nothing. If you ever saw
  "batch 500s on an op with a long comment body," this was it — the size was the
  cause, not the op. Until you migrate, that ceiling is still live.

- **The runaway-payload guard now names the operation.** A comment body over
  1 MB (`DispatchBatchService::MAX_COMMENT_BODY_BYTES`) raises a 422 naming the
  op index and the actual byte count instead of a raw SQLSTATE.

- **New: a whole-manifest byte cap.** `agent.batch.max_payload_bytes`
  (`DISPATCH_AGENT_BATCH_MAX_BYTES`, default **4 MB**, `0` disables). Op-count
  alone never bounded *size*, so a manifest of individually-legal ops could still
  die at the web server's body limit — **below** the application, where no
  dispatch error can reach the caller. The default sits deliberately under a
  typical 8M `post_max_size` so the legible 422 wins the race. `dispatch:batch`
  also measures the manifest locally and refuses to send an oversized one, so you
  learn the number without spending a request. `dispatch:doctor` warns if your cap
  is at or above `post_max_size`, where it could never fire first.

- **New verb: `dispatch:find <term>`.** Text search over title, code, and
  description. **It spans ALL statuses by default** — the inverse of
  `dispatch:queue`, and deliberately so: "has this already been filed or already
  built?" is answered by the `done`/`declined`/`backburner` work the actionable
  queue hides. Remotely it rides the **existing `queue` scope** via `?q=`, so
  sessions commissioned before this release gain the verb with no re-approval and
  no re-commissioning.

- **New: pre-expiry session warnings.** `agent.remote.expiry_warning_minutes`
  (default 10) and `agent.remote.claim_cycle_minutes` (default 15). Previously
  the only expiry notice fired once the token was *already* past `expires_at` —
  i.e. on the call that was already failing. The hazard that motivated this is a
  **half-applied close**: a `note` that succeeds followed by a `done` that 401s
  leaves a task carrying its full audit note but not its status transition,
  reading as in-flight with no agent on it. Also fixed: an explicit `--remote`
  previously skipped the expiry check entirely, so explicitly-remote runs got no
  warning at all — only sticky (bare-verb) runs did.

- **`dispatch:done --label=<name>` (repeatable).** Attaches labels on close,
  never replaces. Note `dispatch:edit` is local-only and `edit` is not an agent
  verb, so on a remote session `done` is the only way to label a task without a
  batch manifest.

- **Smaller surfaces:** `--json` on `dispatch:note` (its output was already the
  JSON shape); `dispatch:show <key>` now resolves a `dedupe_key` when the code
  lookup misses, so a task minted with `add --key=` is fetchable by that key;
  `dispatch:schema`'s `batch` key now documents its `limits`.

- **`dispatch:doctor` gains published-ASSET drift detection.** It compares your
  published `dispatch-vue` / `dispatch-assets` trees against the installed
  package (content-hashed, line endings normalised, so a CRLF checkout is never
  reported as drift). **Read the direction before acting:** if the *published*
  copy is stale, re-publish; but if it carries a fix the installed package does
  not have yet, do **not** `--force` — that overwrites the fix with the older
  vendor copy.

- **`dispatchConsole.js` capture guards (republish to take them).** `stringify()`
  is now total — the old `catch { return String(v) }` blew up on exactly the
  input it existed to rescue (a Vue component proxy resolving neither `valueOf`
  nor `toString`, where `JSON.stringify` has already failed as circular). Capture
  is wrapped separately from passthrough and the original console method now runs
  **unconditionally**, so a capture failure can never swallow the host's own
  error. Non-plain objects are no longer probed at all — class instances,
  framework proxies and DOM nodes report by internal class via
  `Object.prototype.toString`, which runs no user code; output is capped at 500
  chars. Covered by a dependency-free node test (`node tests/js/console-capture.test.mjs`).

## v0.7.0 — label kinds & focus steering + `backburner` status + multi-select board/list filters

- **Two new migrations** — `dispatch_labels.kind` (the per-label facet column)
  and `dispatch_focuses` (saved steering lenses). Run `php artisan migrate` (they
  load from the package automatically; publish with `--tag=dispatch-migrations`
  only to edit them in your own `database/migrations/`). Without them the label
  kind facet and Focus steering have no storage.
- **Focus steering on `next`/`claim`.** `dispatch:next` and `dispatch:claim`
  (CLI **and** the agent API) now surface the top-ranked **active** Focus's
  matches first, falling through to lower-ranked focuses and then the unsteered
  base — it steers, never starves. It is **default-on but inert with zero
  focuses**: no active focus ⇒ identical ordering to before. `--no-focus`
  (`?no_focus=1`) bypasses it for a call; `dispatch:queue` is not steered and
  claim-by-code ignores steering.
- **`dispatch:session:status` exit-code change.** The old zero-state (no token
  and no pending request) exited **1**; it is now a three-state exit-**0** probe
  — ACTIVE / DROPPED / NONE all exit 0 and name the next verb. A genuinely
  pending request still polls, and denied/revoked/expired still exit 1. **Update
  any script that asserted `session:status` fails when no session exists.**
- **Claim bridge template changed.** `dispatch:claim` now echoes a
  ready-to-paste close command that includes `--commit=<sha>` (plus
  `--result-file` and `--with-metrics --since=<claimed_at>` placeholders) on the
  stderr side channel. Anything that scraped the old bridge text should re-read
  it.
- **Meta labels demoted off cards/rows.** `source:*` / `kind:*` (any `meta`-kind
  namespace) no longer render on board cards or list rows — a **visual change**
  only; the detail view still shows them, and elevated labels (`area:*` /
  `epic:*`) now lead.
- **`Label::isEpic()` removed.** There is no special epic type — an epic is now a
  single-label Focus (an `epic:<slug>` elevated label plus a Focus constrained to
  it). No known consumers.
- **New config keys** `dispatch.labels.namespace_kinds` and
  `dispatch.models.focus`. In-code fallbacks cover an unpublished or older
  `config/dispatch.php` (the shipped `area/epic → elevated`, `source/kind → meta`
  map and the package `Focus` model), so nothing errors — **re-publish, or add
  the keys, to pin/customize them** (same shallow-`mergeConfigFrom` trap as the
  other blocks here).
- **Per-approval session TTL.** The Agent Sessions approve row gains a
  session-length select (Default = the configured `agent.session_ttl`, presets
  1h / 3h / 8h / 24h) — no migration or config change required.
- **Agent contract additive keys.** The `--json` summary shape gains
  `attachment_count`; the full shape gains an `attachments[]` array and a
  per-comment `attachment_count`. **Additive — existing parsers are unaffected**
  (signals only: no fetch URL, binaries never travel the agent API).
- **New default status `backburner`** sits between `verifying` and `done`:
  parked — consciously not actionable now or anytime soon, or code-done but
  blocked on an external date — distinct from `triage` (unprocessed) and
  `declined` (rejected). No migration (status is a plain string), but **hosts
  with a published `config/dispatch.php` must add `'backburner'` to
  `workflow.statuses` themselves** — the published array wins wholesale over
  the package default (same shallow-merge trap as the `batch` verb below), and
  without it the board column, dropdowns, and `--status=backburner` validation
  simply don't know the value. Park with `dispatch:done <code>
  --status=backburner`; unpark with `--status=open` (or `triage`/`verifying`).
  Backburner tasks are excluded from `dispatch:next`/`dispatch:queue` defaults,
  the `--count` census, claiming, and staleness nagging.
- **Board/list filter URLs changed shape.** The type/priority/label filters are
  now multi-select checkbox groups, so their query params went from scalar
  (`?type=bug`) to arrays (`?types[0]=bug&types[1]=chore`) — note the plural
  names. Old bookmarked filter URLs aren't errors; they simply load the
  unfiltered (all-selected) view.

## v0.6.0 — sticky remote + one-shot commissioning (client behavior changes)

Two client-side defaults changed so an agent needs less ceremony (and less
doc) to drive the pipeline. Both have escape hatches; neither changes the
server surface, so mixed v0.5.x/v0.6.0 client-server pairs keep working
(`claimed_at` in the claim envelope and the zero-filled `queue --count`
census are additive).

- **Sticky remote.** While an approved agent-session token exists (the dotfile
  is created at approval and deleted on `session:end`/`401`), the eight loop
  verbs (`next/queue/show/claim/add/note/done/batch`) target the **remote by
  default** — no `--remote` flag needed. Every sticky call announces
  `→ remote: <host>` on stderr, and `--local` overrides per call. If a stray
  token ever surprises you, `dispatch:session:end` clears it; opt out
  host-wide with `dispatch.agent.remote.sticky=false`
  (`DISPATCH_AGENT_STICKY=false`). With no token present nothing changes —
  verbs act locally exactly as before.
- **`dispatch:session:request` with no `--scope` now really requests the full
  allowlist.** The client used to always send the `scopes` key, so omitting
  `--scope` posted `scopes: []` — which the server (correctly) treats as
  request-NOTHING, i.e. a deny-all session. Fixed: an omitted `--scope` omits
  the key, and the approver grants the host allowlist — what the option help
  always claimed. Pass `--scope=...` only to deliberately narrow a session.
- **`dispatch:session:request --wait`** folds request → show code → poll →
  collect-token into one command (it delegates to the `session:status --wait`
  loop on your behalf).
- **Dropped sessions fail loud + `dispatch:session:refresh` (client behavior
  change).** Previously, when a session token died mid-run (401, or a
  denied/revoked/expired poll), sticky resolution silently fell back to the
  **local dev DB** — production tasks looked deleted and local throwaway tasks
  read as the board (observed in the field as apparent data loss). Now an
  involuntary token death writes a **drop marker** beside the dotfile
  (`<token_path>.dropped`), and while it stands bare verbs **exit non-zero
  with the recovery paths** instead of quietly serving local data. Resolve it
  with the new **`dispatch:session:refresh --wait`** — re-requests a session
  with the same identity/scopes (persisted in the dotfile since this
  version), flagged as a renewal in the purpose the approver sees — or
  acknowledge with `dispatch:session:end` (restores local-by-default);
  `--local` always overrides per call. Related hardening: a re-`session:request`
  no longer resurrects a stale `token` key from the old dotfile (that cascade
  could wipe a fresh pending request on the next 401); a `429` is now
  answered with back-off guidance instead of looking like token trouble; a
  token past its stored `expires_at` warns and names `session:refresh` before
  the 401 interrupts the loop; and `dispatch:doctor` reports a lingering drop
  marker. Purely client-side — no server or schema change.
- **Session-anchored metrics: `dispatch:session:end` now records whole-session
  run metrics by default.** The client computes tokens/cost/duration from its
  local Claude Code transcript (window: token stored → now) and folds them into
  the end call; the server stores them on the session row (`metrics` +
  `ended_at` columns — **run your migrations**: a new
  `add_metrics_to_dispatch_agent_sessions_table` migration ships with this).
  The staff Agent Sessions page gains a **"Recently ended"** section showing
  each finished session's verdict — previously the row (and any metrics signal)
  vanished the moment the session ended. Opt out per call with `--no-metrics`;
  when no transcript can be located the session still ends, just without
  metrics (a warning names the fix). Per-task `done --with-metrics` is
  unchanged and remains the fine-grained per-task view; the session total is
  now the load-bearing default. A v0.6.0 client against a v0.5.x server keeps
  working — the extra `metrics` key on `session/end` is simply ignored there
  (`$request->validate` tolerates it; nothing is stored).
- **Estimated human touch-time (derived, v1).** The "Agent run" card and the
  `dispatch:show` block gain an "est. human time (v1)" figure — a deterministic,
  versioned estimate of the focused human minutes the run's workflow would have
  taken, derived at **read time** from the stamped signals (task type, tool mix,
  subagents, capped wall-clock). It is never stored, so historical tasks
  re-derive whenever you tune the coefficients in `metrics.touch_time`. **Hosts
  with a previously published `config/dispatch.php` must add that block (or
  re-publish) to see it** — absent config hides the figure and nothing errors
  (shallow `mergeConfigFrom`, the same trap as GAP 3/6).
- **Agent session TTL default is now 3 hours (was 1).** The 1h default
  force-expired legitimate long sessions, and an expiry mid-run `401`s the
  closing `dispatch:session:end` call — so the longest runs were exactly the
  ones that lost their session metrics. The TTL is a backstop, not the
  lifecycle (`session:end` is how sessions are meant to end); stricter hosts
  set `DISPATCH_AGENT_SESSION_TTL` as before, and a published config's
  `session_ttl` value still wins wholesale.

## Enabling the batch verb (`dispatch:batch --remote` / `POST agent/batch`)

The batch memorialize verb is gated by the server's `agent.verbs` allowlist. If
you **published `config/dispatch.php` before this verb existed**, your host's
`agent` block wins wholesale over the package default (shallow `mergeConfigFrom`,
the same trap as GAP 3), so `batch` is absent from `agent.verbs` and **no session
can ever be granted the `batch` scope** — a `--remote` batch call will `403`.
(`php artisan dispatch:doctor` flags exactly this as a `verbs` warning.)

To enable it on the server, either re-publish the config and re-apply your
customizations:

```bash
php artisan vendor:publish --tag=dispatch-config --force
```

or just add `'batch'` to the `agent.verbs` array (and, optionally, the
`agent.batch.max_operations` cap) in your existing `config/dispatch.php`. Then
clear the config cache (see above). The **local** `dispatch:batch <file>` path
needs none of this — it doesn't go through the session/scope layer.

## Re-publishing skills after an upgrade

The Claude Code skills ship in the package but are used from the host's own
`.claude/skills/`, so they only pick up package changes when re-published:

```bash
php artisan vendor:publish --tag=dispatch-skills --force
```

`--force` **overwrites** the host's copies. If you have **hand-edited** a
published skill — e.g. baked your production host and paths into
`dispatch-agent-session/SKILL.md` — `--force` discards those edits. Two safe
options:

1. **Keep your customized copy:** skip `--force`. Without it, existing files are
   left untouched (you keep your edits but miss the package's newer generic
   content). Re-run with `--force` only when you're ready to re-apply your
   host-specifics.
2. **Re-sync then re-customize:** `--force`, then re-apply your host/paths on top
   of the refreshed package version.

Before a `--force`, it's worth diffing your published copy against the vendored
package copy so you know exactly what you're overwriting:

```bash
diff -u vendor/sgrjr/dispatch/.claude/skills/dispatch-agent-session/SKILL.md \
        .claude/skills/dispatch-agent-session/SKILL.md
```

Generic, reusable improvements you make to a published skill are worth sending
back upstream to the package so the next `--force` carries them forward instead
of pulverizing them.
