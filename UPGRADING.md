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

## Unreleased — task kinds: a task defines its own controls (TASK-1188)

**No migration. Two config edits on hosts with a published `config/dispatch.php`.**

- **Add `'perform'` to `agent.verbs`** (a new agent verb: `POST agent/perform`,
  `dispatch:perform <code> <action> [--input=k=v]`). Until then no session is
  granted it by default.
- **Add the `task_kinds` block** (`'approval' => \Sgrjr\Dispatch\Kinds\ApprovalKind::class`).
  Without it, approval tasks lose their lock and their Approve / Deny actions
  and degrade to the default controls.

A **task kind** (`Contracts\TaskKind`, extend `Kinds\BaseTaskKind`) is
registered as `[key => class]`. A task IS of kind `<key>` when `context.<key>`
is an array: a SYSTEM-set marker that only the kind's own service may file,
edit or remove (wrap its writes in `Services\TaskKinds::asKind()`). The kind
declares:
- `actions(task, viewer)`: `Support\TaskAction` objects (key, label, style,
  inputs, confirm, agentAllowed);
- `hides()`: default controls to hide (status / assignee / claim / pass / ask);
- `locksStatus()`: when true, the Task saving hook refuses every other status
  write (`Exceptions\TaskKindLocked`, a 422 on the API/batch);
- `panel()`: what the task view shows about the underlying thing;
- `perform()`: the work. It runs as the kind, so it may close its own task;
- `continues()` (TASK-1190): the marker a cross-lane PASS hands to the
  continuation, so the kind travels with the ball; null = the continuation is
  a plain task. A kind that travels also lets the pass close the passer; one
  that doesn't and locks its status (an approval) refuses the pass.

**A task that closes with its record** (`Kinds\RecordGatedKind`, TASK-1190):
the canonical pattern for work decided in ANOTHER tool (a customer's plan
request, a timesheet). Implement `record()`, `recordIsOpen()`, `toolUrl()` (and
`toolLabel()`). The base gives one LINK action to the tool (`TaskAction::link`,
never performed from the task), hides status + claim, locks the status, and
travels with a pass. The record's closer calls `TaskKinds::closeGated($task,
$status, $note, $actor, $because)` for each task it gates: that closes it as the
kind, with the note as the body of the status event, then notifies and unblocks
dependents. ⛔ Not the task-to-task `blocked_by` link.

**One action path:** `Services\TaskActions` (`describe` / `offered` /
`perform`). TaskShow renders the actions and hides what the kind asks; the
agent API's `show` carries a `kind` block; `perform` runs an action, **403** for
a non-agentAllowed one. A host's own screens call the same service.

The approval task (TASK-1021) is the first kind: `ApprovalKind`, marker
`context.approval`, every default hidden, status locked, Approve / Deny for
staff only. `Approvable` gains `approvalPanelRows()` and `approvalInputs()`.
`ApprovalTaskLocked` now extends `TaskKindLocked`: catch the parent.

## Unreleased — the `resolved` status: the canon of "finished" (TASK-1193)

**No migration (status is a plain string). One config edit on hosts with a
published `config/dispatch.php`.**

Three CLOSED statuses, each meaning exactly one thing:

| status | meaning | note |
|---|---|---|
| `done` | the prescribed work was completed, nothing left | optional |
| `resolved` *(new)* | dealt with, but **not as written**: partly, differently, or the need went away | **required** |
| `declined` | not done, by decision | recommended |

- **Hosts with a published config must add `'resolved'`** to
  `dispatch.workflow.statuses`, between `done` and `declined`. Without it the
  board column, the dropdowns and `--status=resolved` validation won't offer it.
- **The note is enforced server-side, on every write path**, by the Task saving
  hook (`StatusNoteRequired`, an `InvalidArgumentException`, so the agent API
  and batch answer 422): `dispatch:done --status=resolved --note=…|--note-file=…`,
  the agent API `done` takes `note`, and a batch op carries a `note` or a comment.
  TaskShow shows a note field when Resolved is picked. A board drag and the bulk
  actions REFUSE resolved (each task needs its own note). The note is the body
  of the status event (and its `meta.note`). A write path of your own sets it
  with `$task->withStatusNote($note)` before `save()`, and writes
  `$task->statusChangeBody(…)` / `statusChangeMeta(…)` into its event.
  `Task::replayingHistory(fn)` is the import/sync bypass, and nothing else.
- **One closed predicate:** `Task::closedStatuses()` / `$task->isClosed()`.
  `Task::inactiveStatuses()` = `backburner` + the closed set (a parked task is
  inactive, not closed). ⛔ Replace any hard-coded `['done', 'declined']` in host
  code with it. A guard test keeps the package free of them.
- All three close alike: dependents unblock, an ask returns the ball (a
  resolved ask's note IS the answer), a capture never revives one.

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

## Unreleased — agents serve a lane (TASK-999, R24)

**One migration; inert until a session is granted a lane.** An approved agent
session now carries the lane it SERVES, and `next`/`claim` offer it only that
work. A session with no lane behaves exactly as before.

```bash
composer update sgrjr/dispatch
php artisan migrate              # 000013: lane on dispatch_agent_sessions
php artisan optimize:clear
```

- **One new nullable column on `dispatch_agent_sessions`**: `lane`,
  string(96). `null` = unrestricted — the whole open board, the
  pre-TASK-999 behavior — so nothing changes until you set one.
- **What a laned session is served**: its lane, plus the departments ABOVE it
  (`marketing:developer` is also served bare `marketing`, because
  whole-department work reaches every member — R22), plus, by config, the
  no-department lane. **Never** a sibling sub-lane (`marketing:sales`) and
  never another department. This is the reverse of the `--lane=` FILTER,
  which expands a bare department DOWN into its sub-lanes.
- **Claim-by-code is exempt.** `dispatch:claim <CODE>` still works on any
  task, from any lane — that is how a human hands an agent a specific task.
- **The lane is a GRANT, not a client setting.** `dispatch:session:request
  --lane=…` asks; the approver sees it at `/it/agent-sessions` and can change
  it before granting; the server reads it off the session row. Passing
  `?lane=` on a verb still only narrows WITHIN what the session is served, so
  an agent cannot widen its own reach. An unrecognized lane 422s at request
  time.
- **Two new config keys** (both optional):
  - `dispatch.agent.lane` (`DISPATCH_AGENT_LANE`) — the lane an unspecified
    request falls back to. Default null (unrestricted).
  - `dispatch.agent.lane_includes_unrouted`
    (`DISPATCH_AGENT_LANE_INCLUDES_UNROUTED`, default **true**) — whether a
    laned agent also gets the no-department lane. True keeps unrouted work
    reachable while a backlog is still mostly unlaned; false requires a human
    to route work into a lane before any agent can claim it.
- **`approve()` gained a fifth parameter**, `?string $lane = null`, after
  `$scopes`. Additive — existing callers are unaffected. `''` means "no lane"
  (deliberately unrestricted) and is distinct from `null` ("nobody chose"),
  which falls back to the config default.
- **The approved poll response now carries `lane` and `scopes`**, so an agent
  learns the grant it actually received rather than the one it asked for.
  `dispatch:session:status` prints it on both collection and every later
  probe — worth knowing, because an empty `next` under a lane is a scope, not
  an empty board.

## Unreleased — lanes (TASK-997 part A)

**One migration, one new contract seam, no breaking change — inert until you bind a LaneResolver.**

```bash
composer update sgrjr/dispatch
php artisan migrate              # 000021: lane
php artisan optimize:clear
```

- **One new nullable column on `dispatch_tasks`**: `lane`, string(96), indexed
  with `status`. `null` is the NO-DEPARTMENT lane (R15) — open, every staff
  user sees it, any user or department can claim from it — not "unknown."
  No backfill: a lane key is host data (a department, or a role-named
  sub-lane like `marketing:developer`), resolved through the new
  `LaneResolver` seam.
- **A lane decides ROUTING, never visibility** (R14) — same doctrine as the
  anchor fields' `topic_account_key`. `DispatchGate::scopeVisible()` never
  reads `lane`, pinned by a test.
- **New contract seam**: `LaneResolver` (`dispatch.contracts.lanes` —
  plural, matching the array key), defaulting to `NullLaneResolver`. With
  the Null default bound, the WHOLE feature is inert: `isLane()` is always
  false, so no write path can persist a non-null lane, every list is empty,
  and `canRoute()` is always false. If your published `config/dispatch.php`
  predates this release, the shallow `mergeConfigFrom` means the
  `contracts.lanes` key is simply absent — the package falls back to
  `NullLaneResolver` in code either way, so this is safe to skip; republish
  (`--force`) only if you want the key visible for editing.
- **New model API on `Task`**: `scopeInLane()` (department-vs-exact
  semantics — a bare `marketing` matches `marketing` AND every
  `marketing:*` sub-lane; a `dept:role` key matches exactly;
  `Sgrjr\Dispatch\Support\Lane::NONE` ('none') means the no-department
  lane), `scopeInLanes()` (exact set membership), `scopeUnrouted()`.
- **New service methods on `DispatchTaskService`**: `claimForUser(Task,
  Authenticatable, ?lane)` — a human claiming a task for themselves; when
  the task is unrouted it also auto-joins one of the claimer's lanes (the
  single most-specific one, or an explicit `$lane`, or a "pick a lane" error
  when ambiguous) — and `routeToLane(Task, lane, Authenticatable)` — put a
  task in a lane, unclaimed, allowed for an admin (`canRoute()`) on any
  task, or for a lane member pulling an UNROUTED task into their own lane.
  Both are distinct from the pre-existing `claim()`, which powers the AGENT
  verb loop and **never** sets a lane.
- **New CLI flag**: `dispatch:add --lane=` at creation (validated against the
  bound LaneResolver — locally when persisting locally; a `--remote` call
  forwards the raw value for the AUTHORITATIVE host to validate, since your
  dev box's own binding is very likely the inert default).
  `dispatch:next`/`queue`/`find`/`claim` gain `--lane=<key|none>` as a
  FILTER, local and `--remote` — `claim`'s filter narrows candidates only;
  claiming through the agent verb loop never sets a lane.
- **New batch-op field**: `lane` (tri-state like `due_at` — absent =
  untouched, `null`/`""` clears to the no-department lane on `update`; set
  silently at creation on `add`). A non-null value failing
  `LaneResolver::isLane()` fails the WHOLE batch, naming the operation. A
  real change to an existing task's lane records a new `lane_change`
  timeline event (`TaskComment::EVENT_LANE_CHANGE`).
- **New capture config**: `dispatch.capture.lane` (env
  `DISPATCH_CAPTURE_LANE`) stamps a lane on every NEW footer-widget capture.
  An invalid value is ignored + logged, never a failed capture.
- **The agent JSON contract (`dispatch:schema`) gained fields, never lost
  any**: `lane` on both the summary and full shapes; `lane_label` on the
  full shape only.
- **Board UI**: `TaskShow` shows the lane badge and, for staff, a "Claim for
  me" / "Route to…" panel (hidden entirely unless a real LaneResolver is
  bound). `TaskBoard`'s swimlane mode (`?lanes=1`) groups by `lane` — "No
  department" first — once a real LaneResolver is bound; otherwise it keeps
  today's elevated-label grouping unchanged.
- **Out of scope for this release** (a later wave, "part B"): hand-off
  (pass/ask), blocked-by links, push notifications, personal lane-scoped
  views, agents serving a lane.

## Unreleased — the ball (hand-off + task links, TASK-997 part B)

**One migration (`dispatch_task_links`), one new agent verb (`handoff`), no breaking change.**

```bash
composer update sgrjr/dispatch
php artisan migrate              # 000022: dispatch_task_links
php artisan optimize:clear
```

Part B of TASK-997 (see "Lanes" above for part A): the HAND-OFF — who is
expected to pick a task up next — and real task->task BLOCKED-BY links,
which is what makes "Ask" (the legacy request -> respond -> dismiss loop)
work.

**The ball** = the current holder of an open task: a person
(`assignee_user_id`) or the lane itself (unassigned = unclaimed). Exactly
one holder, always inside the task's lane.

- **New table `dispatch_task_links`**: `task_id` (the BLOCKED task),
  `blocked_by_task_id` (the BLOCKER), `kind` (string, default `blocks` —
  room for `relates` later; nothing reads a second kind yet),
  `created_by_user_id` nullable, timestamps; unique on (`task_id`,
  `blocked_by_task_id`, `kind`).
- **New model API on `Task`**: `blockedBy()` / `blocks()` (belongsToMany
  through the link table), `scopeBlocked()` / `scopeUnblocked()` —
  DYNAMIC, computed off the blocker's CURRENT status ({@see
  Task::inactiveStatuses()}), never a stored boolean that could drift out
  of sync with the row it describes.
- **New service method**
  `DispatchTaskService::handoff(Task $task, Authenticatable $to, ?Authenticatable $actor, array $opts): Task`
  — `$opts`: `ask` (bool, default false), `lane` (?string — required only
  to disambiguate), `note` (?string), `due` (?string), `keep_open` (bool,
  default false). `$actor` is **nullable** — an `AgentSession` isn't an
  `Authenticatable`, and the CLI is often an unauthenticated trusted
  context, so a null actor simply means the timeline events below carry no
  `user_id`, same posture as every other agent/CLI mutation in the package.

  | Recipient's lanes vs. the task's lane | PASS ("your turn") | ASK ("I need this, then it's back to me") |
  |---|---|---|
  | Same lane (or BOTH unrouted — see the inert-compatible `sameLane()` reading below) | The ball moves on the SAME task: `assignee_user_id := $to`, ONE `assignee_change` event carrying the note | Still mints a NEW, linked task — even in the same lane |
  | Different lane, or the task is unrouted and the recipient works ≥1 lane | Mints a new task in the recipient's lane (their single most-specific one, `$opts['lane']` to disambiguate, or a "pick a lane" error); the passing task CLOSES (`status` -> `done`) unless `keep_open` | Mints a new task in the recipient's lane, same selection rule |
  | Recipient works no lane at all | New task lands in the no-department lane, for an admin to route | Same |

  A cross-lane (or no-lane) pass never simply reassigns — it mints a NEW
  task (same `conversation_id`; `origin` = `task:<passing code>`) because a
  person holding another lane's task is invisible to their own manager
  (R21/R22). An ask ALWAYS mints a new, linked task and BLOCKS the asker's
  task with it; the asker's task keeps its holder, untouched, for the
  whole time it's blocked.

  `sameLane()`'s inert-compatible reading: `$taskLane === null &&
  $recipientLanes === []` counts as "the same place" too — with the
  shipped `NullLaneResolver` bound, every task and every user is
  permanently in that state, so a pass degrades to a plain reassignment
  (no task is ever minted) rather than spinning a same-shape new task on
  every hand-off. "Nothing can create a lane" holds; "hand-off still works"
  also holds.
- **`DispatchTaskService::linkBlockedBy(Task $task, Task $blocker, ?int $actorUserId = null, string $kind = TaskLink::KIND_BLOCKS): TaskLink`**
  — the primitive behind Ask and the batch `blocked_by` field. A self-link,
  or a cycle (linking A blocked-by B when B is already, transitively,
  blocked by A), is refused with a clear `InvalidArgumentException`;
  re-linking the same pair/kind is idempotent (returns the existing row).
- **`DispatchTaskService::notifyDependentsOfClosure(Task $task, ?int $actorUserId = null): void`**
  — "closing a blocker notifies the next holder." A no-op unless $task just
  reached a terminal status, so it's safe to call unconditionally; wired
  into `dispatch:done`, `POST agent/done`, `dispatch:batch`'s `update` op,
  and every Livewire status-change surface (`TaskShow`, `TaskList`,
  `TaskBoard`). For each task the closer blocks: if the closer's `origin`
  points back at that dependent (`origin_type` = `task`, `origin_id` = the
  dependent's own code — the signature an Ask task always carries), **the
  ball RETURNS**: the dependent is re-assigned to whoever asked (the
  link's `created_by_user_id`, falling back to its current holder) and
  gains an `answered` event carrying the closer's last human comment (or
  its recorded `result.resolution`/`result.commit`). Otherwise it's a
  plain `blocked_by` link: the dependent gets a `dependency_resolved`
  event, no reassignment. **Both reuse the EXISTING `DispatchNotifier`
  seam** (`taskAssigned` for a real reassignment, `taskCommented`
  otherwise) — no new channel.
- **New CLI**:
  `dispatch:handoff <CODE> --to=<id|email> [--ask] [--lane=] [--note=|--note-file=] [--due=] [--keep-open]`,
  local and `--remote` (`POST agent/handoff`). `--to` accepts a numeric user
  id or an email, same convention as `dispatch:import`'s
  submitter/assignee resolution.

  ⚠️ **A host must add `handoff` to its published `agent.verbs`** (or
  re-publish `config/dispatch.php --force` and re-apply customizations)
  before any session can be GRANTED the scope — the exact same
  "stale-published-config" trap that previously disabled `batch` (see
  "Enabling the batch verb" below; the fix is identical, just for
  `handoff`). Without it, the verb 403s "not scoped" regardless of what a
  session requests. `php artisan dispatch:doctor` flags this the same way
  it flags a missing `batch`.
- **New JSON fields, FULL shape only** (never the summary/list shape,
  matching `lane_label`'s posture): `blocked_by` / `blocks` — arrays of
  task **codes** (never ids — codes are the identifier that travels
  off-instance). `dispatch:show` prints them as plain lines.
- **New batch op field `blocked_by`**: an array of task codes and/or
  `@ref` entries — an in-batch reference to an EARLIER op's `ref` in the
  SAME manifest, resolved to that op's minted code. This is what ends the
  two-batch dance: file the blocker and the blocked task's link in ONE
  `dispatch:batch` / `POST agent/batch` call instead of two round-trips.
  ADDITIVE, same posture as `labels` — never a replace-all. An
  unresolvable `@ref`, an unknown code, a self-link, or a would-be cycle
  fails the WHOLE batch, naming the operation index.
- **New event types** (`Sgrjr\Dispatch\Models\TaskComment`): `handed_off`
  (a cross-lane/no-lane pass, recorded on the PASSING task),  `asked` (on
  the asker's task, when the ask mints the recipient's task), `answered`
  (on the asker's task, when the ask closes and the ball returns),
  `dependency_resolved` (on a dependent task, a plain — non-ask —
  `blocked_by` link's blocker closing).
- **Livewire `TaskShow`**: a new "Hand off" panel (staff/`update`-ability
  only) — a person picker (the same assignable-users pool as the assignee
  dropdown), an Ask checkbox, a note, an optional lane disambiguator
  (shown only once a real `LaneResolver` is bound and needed), plus the
  blocked-by/blocks lists as plain links to those tasks. **Deliberately
  NOT gated on a real `LaneResolver` being bound** — see the inert reading
  of `sameLane()` above — so the panel stays useful on a host that hasn't
  adopted lanes at all. A cross-lane pass redirects the page to the new
  continuation task (mirroring `mergeInto()`'s redirect); a same-lane pass
  or an ask refreshes in place.
- **Visibility unchanged**: neither `dispatch_task_links` nor `lane`
  widens who can see a task — `DispatchGate::scopeVisible()` remains the
  one and only visibility scope, pinned by a test that greps
  `VisibilityGates`'s source for both the table and the relation names.
- **Out of scope for this release**: push notifications, personal
  lane-scoped views, agents serving a lane (still part A's stated
  out-of-scope list — part B doesn't touch it either).

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
