---
name: dispatch-agent-session
description: PROACTIVELY use when asked to work the PRODUCTION Dispatch backlog from outside the deploy — "work the live/prod backlog", "run as a remote agent", "commission an agent session", "pick up real tasks remotely", "work this against production", "work/claim all the open production items", "plan and complete the production backlog" — or whenever context makes clear the target is the authoritative (production) Dispatch instance rather than local dev. Drives the human-commissioned session protocol (`dispatch:session:request` → human approval → `dispatch:session:status`) and then the `--remote` verb loop (`dispatch:next --remote` → `dispatch:claim --remote` → work → `dispatch:note --remote` → `dispatch:done --remote`), the batch "memorialize" path (`dispatch:batch --remote`) that commits a whole run of add/update ops in one hit, OR the plan-then-work-the-whole-open-queue recipe (§5c — survey the queue, plan, then claim tasks serially rather than all at once). Also use when a session goes stale (401 mid-loop, denied, revoked, expired) and needs graceful handling. Do NOT use for local dev work against the app's own database — see `dispatch-track` for that.
---

# Dispatch remote agent session & `--remote` verb loop

Dispatch's remote agent seam (§19/§20) lets an agent work the **PRODUCTION**
backlog from somewhere else — no CI runner, no local checkout of the prod
database, no standing credential. Instead, a human explicitly **commissions**
a short-lived session in a staff-gated UI, and every verb after that runs
against production over the network as that session.

> **This is not the same thing as local dev tracking.** `dispatch-track`
> (`dispatch:pull` / `dispatch:next` / `dispatch:done` / `dispatch:push`)
> reads and writes the **local dev database** — fine for tracking your own
> work on this checkout. This skill is for the opposite case: driving the
> **real, authoritative backlog** on the production instance, which requires
> a commissioned session and the `--remote` flag on every verb. **Never**
> substitute one for the other — a plain `dispatch:next` with no `--remote`
> during a remote-agent session still hits the local dev DB, not production.

---

## When to invoke

| Pattern | Example phrase |
|---|---|
| Explicit remote/production work request | "work the production backlog", "run as a remote agent", "pick up real tasks" |
| Session commissioning | "commission a session", "request agent access", "get me approved to work prod" |
| Mid-session trouble | "the session got revoked", "I'm getting a 401", "was I denied?" |

## Do NOT use when

- The user wants you to track or work items in **this** checkout's local dev
  DB — use `dispatch-track` instead.
- No remote is configured (`dispatch.agent.remote.url` /
  `DISPATCH_AGENT_REMOTE_URL` unset) — tell the user this skill needs a
  target production instance and stop; don't fall back to local silently.

---

## The protocol, step by step

### 1. Request a session

```bash
php artisan dispatch:session:request \
  --name="<agent name>" \
  --purpose="<short reason for this session>" \
  --scope=next --scope=queue --scope=claim --scope=show --scope=note --scope=done \
  [--scope=add] [--scope=batch] \
  [--secret=<bootstrap secret, if not already configured>]
```

**One scope per verb you'll call — and scopes FREEZE at approval.** You cannot
widen a session after it's approved. If you discover mid-run that you need a verb
you didn't request, the only fix is `dispatch:session:end` and commissioning a
new session — so request every scope you might plausibly need **up front**. The
grantable verbs and their scopes:

| Scope | Kind | What it authorizes |
|---|---|---|
| `next` / `queue` | read | preview the backlog (SUMMARY shape) |
| `show` | read | a candidate's full brief (description + comments) before claiming |
| `claim` | work | atomically pick up a task (→ `in_progress` + assigned) |
| `note` | write | append a comment to a task's timeline |
| `done` | write | set final status + structured result |
| `add` | write | **file a NEW task** — request it the moment you might create one (e.g. a bug you find while working). Its omission is the classic "had to re-commission" trap. |
| `batch` | write | memorialize a whole run of add/update ops in one hit (§5b) |

The default six (`next`/`queue`/`claim`/`show`/`note`/`done`) cover the full
read → claim → work → close loop. Add `--scope=add` if there's any chance you'll
file a task, and `--scope=batch` if you'll memorialize a run as one manifest
(§5b). `edit` and `merge` are **local-only** — they have no `--remote` path, so a
remote session can't restructure or merge tasks; that stays a local/human action.

This POSTs to the remote's session-request endpoint (bootstrap-secret gated,
not bearer — there's no token yet) and stores a pending request locally. It
prints a short `user_code`. **Show that code to the human operator verbatim**
— they need it to confirm the request in the approval UI matches what you
requested.

### 2. Hand off to a human — you cannot approve your own session

Show the operator the code **once** and point them at the approval UI:

> *"Approve this session in the Agent Sessions UI at `/dispatch/agent-sessions`
> on the production instance — confirm the code reads `<user_code>`, then click
> Approve."*

That page is a staff-gated Livewire view where a human approves/denies pending
requests (and can see/kill active sessions). Then go **straight to polling
(step 3) yourself** — don't also ask the operator to come back and tell you
"approved." The approval already happens on the site, so a second "it's
approved" confirmation is redundant round-tripping. Most approvals land within
~10 seconds, so the poll itself is your signal.

### 3. Poll for approval — wait a few seconds, then poll; back off, do NOT spin

```bash
php artisan dispatch:session:status --wait=15    # blocks in-process: sleep + retry while pending, up to 15s
```

`--wait=<secs>` polls **in one process** — it sleeps and re-checks while the
session is `pending` and returns the instant a human approves. Run one blocking
`--wait` right after showing the code and you usually collect the token on the
first call with no further word from the operator. Bare `--wait` waits ~60s;
omit it (`dispatch:session:status`) for a single non-blocking poll. This is also
the clean way to realize a "wait ~10s then check" step without your harness
blocking a foreground shell `sleep`.

If it's still `pending` when the wait budget is spent, back off and retry a
couple more times (widen the wait) rather than hammering the endpoint. Only
after a few pending attempts do you say so and ask whether to keep waiting —
never spin in a tight loop.

### 4. Handle the outcome

- **`approved`** — a bearer token is stored in a dotfile outside the repo
  (owner-only, `0600`; default `~/.dispatch/agent-token.json`, or
  `dispatch.agent.remote.token_path` / `DISPATCH_AGENT_TOKEN_PATH`). Proceed
  to the verb loop.
- **`pending`** — nothing to do yet. Re-poll later per step 3.
- **`denied` / `revoked` / `expired`** — the local token is cleared
  automatically. **Stop gracefully and report it** — do not immediately
  re-request a new session in a retry loop. Surface the outcome to the
  operator and let them decide whether to commission another one.

### 5. Drive the `--remote` verb loop

Once approved, every verb takes `--remote` to route through the agent API
against production instead of the local DB:

```bash
php artisan dispatch:queue --remote --limit=20 --json   # triage: top N candidates (SUMMARY shape; --limit caps the pull)
php artisan dispatch:next --remote --json          # or just preview the single top task (read-only, SUMMARY shape)
php artisan dispatch:show <code> --remote --json    # read a CANDIDATE's full brief (description + comments) BEFORE claiming
php artisan dispatch:claim --remote --json          # atomically claim it: in_progress + assigned in one txn — safe if
                                                     # other agents/humans poll the same backlog. Returns the FULL shape
                                                     # (description + comments) — READ IT before working.
# ...do the actual work...
php artisan dispatch:note <code> "<finding>" --remote   # record findings/decisions as you go (repeatable)
php artisan dispatch:show <code> --remote --json    # re-read the full brief at any point
php artisan dispatch:done <code> --remote \
  --commit=<sha> --result='{"tests":"passing"}'      # structured completion → context.result
```

Always claim before working — never assume a task is yours just because
`dispatch:next` showed it to you; another agent (or a human) could claim it
first. `dispatch:claim` is the atomic, race-safe pickup; `dispatch:next` and
`dispatch:queue` are only previews. Scope to agent-appropriate work with
`--type` / `--label` on `next`/`queue`/`claim` (e.g. `--label=agent:ok`) so you
only pick up tasks a human has cleared for an agent.

**Reading the human's direction is a required step, not optional.**
`dispatch:next` / `dispatch:queue` return only the SUMMARY shape — title,
type, labels — never the `description` or `comments`, which is exactly where a
human plants direction ("do X first", "don't touch Y"). Two ways to get the
full brief, and you must use one before you start work:

- **Before claiming** — to compare or vet candidates from a `next`/`queue`
  preview, `dispatch:show <code> --remote --json` reads that task's full brief
  (needs the `show` scope).
- **On claim** — `dispatch:claim` deliberately returns the FULL shape
  (description + the entire `comments[]` thread). Parse it and follow any
  direction there before touching code.

Working straight from a summary means working blind to what the commissioner
actually asked for. The summary shape does carry a `comment_count` (human
comments only) — a non-zero value flags a task with human direction waiting, so
`show` those before claiming. The `queue` and `show` scopes requested in step 1
are what make this triage-then-inspect path possible — that's why they're in the
request. If your session wasn't granted `--scope=show`, re-request one that is
rather than working a task blind.

`dispatch:schema` prints the frozen `TaskPresenter` JSON contract (summary +
full-view keys, plus the timeline event vocabulary) — parse `--json` output
against that documented shape, not a guess:

```bash
php artisan dispatch:schema
```

**Long or multi-line inputs — pass a file, don't inline-quote.** A large
`--result` JSON, or a `note`/`add` body with newlines or quotes, is a shell
quoting hazard on one command line (the same reason this repo writes commit
messages to a file, not inline). Each has a file/stdin escape hatch — give it a
path, or `-` to read stdin:

```bash
php artisan dispatch:done <code> --remote --commit=<sha> --result-file=result.json
php artisan dispatch:note <code> --remote --body-file=finding.md
php artisan dispatch:add "<title>" --remote --description-file=body.md
```

### 5a. Working a task the human named (by code)

Often the human doesn't want "the next task" — they name a specific one: *"work
`TASK-042` on prod."* `dispatch:claim <code>` targets that exact task
atomically, so there's no queue scan and no chance of picking up the wrong one:

```bash
php artisan dispatch:show TASK-042 --remote --json     # read the brief first (especially if comment_count > 0)
php artisan dispatch:claim TASK-042 --remote --json     # claim THAT task; returns the full shape
```

`dispatch:claim <code>` is honored **only while the task is still unclaimed**
(open/triage), so naming one never steals in-flight work. If it's already being
worked, the claim comes back empty and the command exits **non-zero** — treat
that as "someone else has it": report it and don't force it. (This replaces the
old workaround of claiming by `--type`/`--label` and then verifying the returned
code matched the one you were asked for — no longer needed now that `claim` takes
a code.) Needs the `claim` scope, plus `show` to read the brief first.

### 5b. Batch memorialize — commit a whole run in one hit (optional)

The per-verb loop in §5 costs a round trip **per action** — against production
that's dozens of progressive HTTPS hits for a long run. When you'd rather work
the run **offline** and then commit it all at once, use `dispatch:batch`
instead. Track your own changes as you go (new tasks you'd file, status moves,
notes), assemble one JSON manifest, and memorialize it in a single request:

```bash
php artisan dispatch:batch run.json --remote --dry-run   # validate against prod, writes nothing
php artisan dispatch:batch run.json --remote             # apply the whole manifest in one txn
```

The manifest is a list of two op kinds, in the **same vocabulary** as the
single verbs — `dispatch:schema` documents it under the `batch` key:

```json
{
  "operations": [
    {"op": "add", "ref": "a1", "title": "New bug found while working",
     "type": "bug", "priority": "high", "labels": ["area:api"],
     "comments": [{"body": "spotted while working TASK-042"}]},
    {"op": "update", "code": "TASK-042", "status": "in_progress",
     "commit": "abc123", "labels": ["needs-review"],
     "comments": [{"body": "partial: A done, B remains", "internal": true}]}
  ]
}
```

What the batch does and does **not** do — this matters for how you build the
manifest:

- **`add`** files a NEW task (server mints the code; defaults to **triage** — it
  never assumes `done`). Give each add a `ref` so you can map it back to the
  minted code in the response. Add a `key` to make a re-submit idempotent.
- **`update`** upserts the WORK on an EXISTING task by `code` — it never
  creates. Set `status` to whatever the task actually reached (leave it out and
  the status doesn't move); this is how you honestly memorialize *partially
  completed* work rather than force-closing it.
- Labels **attach** (never replace); comments **dedupe**; the whole manifest is
  **one transaction** (a bad op rolls it all back). So a re-submit after a
  network blip is safe.

Prefer `--dry-run` first against production to validate the manifest (it reports
the summary and rolls back). This path needs the `batch` scope (§1); if your
session wasn't granted it, either close tasks out with the §5 single verbs or
re-request a session that includes `--scope=batch`.

**Building the manifest from a checklist.** If your run is captured as a plain
`todo.md`-style file, the `dispatch-batch-migrate` skill converts it into a
valid `operations[]` manifest for you.

### 5c. Plan-then-work the whole open queue

When the human says *"work **all** the open items"* (or "claim everything and
build a plan"), do **not** claim them up front — that marks every task
`in_progress`, assigns them to you, blocks other agents, and you can still only
work one at a time. The pipeline shape is **survey → plan → claim-as-you-go**:

1. **Survey (read-only).** Pull the open backlog as the SUMMARY shape, capped so
   you don't drag the whole board over the wire — the queue is already
   priority-sorted:

   ```bash
   php artisan dispatch:queue --remote --limit=50 --json
   php artisan dispatch:queue --remote --label=agent:ok --limit=50 --json  # scope to agent-cleared work
   ```

2. **Read direction before planning.** Any candidate with `comment_count > 0`
   carries human notes the summary hides — `dispatch:show <code> --remote --json`
   each of those so the plan reflects what was actually asked, not just titles.

3. **Build + share the plan.** Order by priority, call out dependencies, and put
   the plan in front of the human. If they want it memorialized on the board (not
   just in chat), drop a `dispatch:note` on a tracking task, or assemble the whole
   set of intended updates as one `dispatch:batch` manifest (§5b) — record the
   plan honestly, never fabricate a `done`.

4. **Work the loop — one task at a time**, in plan order:

   ```bash
   php artisan dispatch:claim <code> --remote --json     # atomic pickup — only when you START this one
   # ...do the work...
   php artisan dispatch:note <code> "<progress>" --remote
   php artisan dispatch:done <code> --remote --commit=<sha> --result-file=result.json
   ```

   **Claim each item only when you start it**, not before. The backlog is
   **live** — other agents and humans work it too — so re-run `dispatch:queue`
   between items rather than trusting your first snapshot: a task you planned may
   already be claimed, closed, or reprioritized. If a `claim <code>` comes back
   empty (non-zero), someone else has it — skip it and move to the next.

5. **Stop cleanly.** When the queue is empty (or the plan is complete), end the
   session (§8) — don't leave the token idling.

This is the multi-task generalization of §5a: §5a claims one *named* task; here you
plan across the whole open set and claim them **serially**, never all at once.

### 6. Handle a mid-session `401`

Every `--remote` verb can fail with `401` if the session was revoked or
expired between your last check and now. When that happens the CLI clears
the local token automatically and reports the failure — **stop the loop
immediately**, tell the operator the session ended, and do not silently
re-request a new session mid-task. Go back to step 1 only if the user wants
to resume.

### 7. Record run metrics (optional)

To memorialize what the session cost the commissioner — tokens, cost, tool
usage, duration — compute them from the **local** transcript and attach them
to the remote task via `done`'s result field. The task lives on production
(not in the local DB), so pass the claim timestamp as the window and let
`dispatch:metrics` run in compute-only mode:

```bash
php artisan dispatch:done <code> --remote \
  --result="$(php artisan dispatch:metrics <code> --since=<claim-iso8601> --json)"
```

Metrics always come from the transcript, never your own estimate — you can't
read your own token usage. (`--stamp`/`--note` are local-DB only and don't
apply to a remote task.)

### 8. End the session when the work is done

Least-privilege: don't let an idle bearer token linger until its TTL. The
moment your commissioned work is complete (or you're stopping), surrender the
credential:

```bash
php artisan dispatch:session:end
```

This revokes **your own** session server-side (identified by your token — you
can only ever end yourself) and deletes the local token. It needs no scope, so
it always works. After this, every `--remote` verb will `401` until a new
session is commissioned. Prefer this over walking away and letting the session
expire.

---

## Anti-patterns (don't)

- ❌ Point a dev app's DB connection at production "just to see the real tasks."
  Commission a session and use `--remote` instead.
- ❌ Run the verb loop **without** `--remote` and think it touches production —
  it edits throwaway local tasks.
- ❌ Ask the operator to come back and confirm "approved" after they clicked
  Approve on the site — poll it yourself (step 3).
- ❌ Retry-spam a `denied` / `revoked` session with fresh requests. Stop and let
  the operator decide.
- ❌ Fire forty progressive `--remote` verbs to close out a long run when you
  could assemble one manifest and `dispatch:batch --remote` it (§5b).
- ❌ Force a partially-done task to `done` in a batch just to close it. Set its
  real status (`in_progress`, `verifying`) — batch exists to memorialize honestly.
- ❌ Request only the read/claim/close scopes and then discover mid-run you need
  `add` (or `batch`). Scopes freeze at approval — request everything you might
  need up front (step 1), or you'll have to end and re-commission.
- ❌ Claim a human-named task by guessing `--type`/`--label` and hoping you got
  the right one. Use `dispatch:claim <code>` — it targets that exact task (§5a).
- ❌ Claim every open task up front to "reserve" the queue when asked to work
  them all. Survey with `dispatch:queue`, plan, then claim each as you START it
  (§5c) — claiming all at once marks them `in_progress`, blocks other agents, and
  you can still only work one at a time.
- ❌ Inline a large `--result` JSON or a multi-line `note`/`add` body and fight
  shell quoting. Use `--result-file` / `--body-file` / `--description-file` (or
  `-` for stdin).
- ❌ Approve your own session, or ask a non-staff user to approve one (they
  can't see the approval UI).

---

## Configuration prerequisites (client side)

The machine running these commands needs the remote pointed at the target
production instance:

```
DISPATCH_AGENT_REMOTE_URL=https://<production-host>/api/dispatch/agent
DISPATCH_AGENT_TOKEN_PATH=   # optional override; defaults to ~/.dispatch/agent-token.json
```

If `dispatch.agent.remote.url` is unset, every command in this skill fails
fast with an instructive error rather than falling back to local — that's by
design; see "Do NOT use when" above.

**Diagnose config trouble with `dispatch:doctor`.** When a session request `503`s,
approval never grants a scope you asked for, or a `--remote` verb `403`s
"not scoped", it's almost always **config drift** — a stale published config on
one side. `php artisan dispatch:doctor` names the drift directly instead of
leaving you to infer it:

- **On the box you drive from** (this checkout): confirms `remote.url` is set and
  HTTPS. A red `remote.url` line is why `--remote` fails fast.
- **On the production instance** (the operator runs it there): flags a
  `bootstrap_secret` unset in prod (→ `503` on request), a verb missing from the
  published `agent.verbs` (→ `403 not scoped` — the classic `batch` case), or a
  still-cached config after a rotate/upgrade. Server-side drift is the operator's
  to fix (re-publish + `optimize:clear`), not something the agent works around —
  so when you hit one of those symptoms, ask them to run `dispatch:doctor` on prod.

---

## See also

- [`README.md`](../../../README.md) — the "AI / remote agent" section covers
  the full agent-CLI verb list, the `agent` config block on the production
  side, and the `EventNotifier` binding for reactive orchestration
- `.claude/skills/dispatch-track/SKILL.md` — local dev capture + verb loop,
  and the production/remote pointer back to this skill
- `php artisan dispatch:schema` — the authoritative `--json` shape
- `php artisan dispatch:doctor` — agent config-drift diagnostic (run it when a
  request `503`s or a verb `403`s "not scoped"); `UPGRADING.md` covers the caches
