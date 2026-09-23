---
name: dispatch-agent-session
description: PROACTIVELY use when asked to work the PRODUCTION Dispatch backlog from outside the deploy — "work the live/prod backlog", "run as a remote agent", "commission an agent session", "pick up real tasks remotely", "work this against production", "work/claim all the open production items", "plan and complete the production backlog" — or whenever context makes clear the target is the authoritative (production) Dispatch instance rather than local dev. Drives the human-commissioned session protocol (two-step for agents: `dispatch:session:request` → surface the user_code → `dispatch:session:status --wait` collects on approval; `--wait` on the request is the human-at-a-terminal one-shot) and the verb loop that follows — while the session token is active every dispatch verb targets production automatically (sticky remote), so the loop is queue → claim → work → note → done → session:end — plus the batch "memorialize" path (`dispatch:batch`) that commits a whole run of add/update ops in one hit. Also use when a session goes stale (401 mid-loop, denied, revoked, expired) and needs graceful handling. Do NOT use for local dev work against the app's own database — see `dispatch-track` for that.
---

# Work the production Dispatch backlog as a commissioned agent

A human **commissions** you a short-lived session in a staff-gated UI; every
dispatch verb after that runs against production as that session. This is NOT
local dev tracking (that's `dispatch-track`) — it's the real, authoritative
backlog.

**The prime rule: the CLI narrates the pipeline.** Every output and every error
names the next step — often as a ready-to-paste command with the values filled
in. Follow what's printed rather than memorizing this document. Below: the
happy path, the few judgment calls the tool can't make for you, and the hard
boundaries.

## Happy path — one run, end to end

```bash
# 1. Commission — TWO steps for an agent. Request WITHOUT --wait: the user_code
#    prints and the command EXITS, so a buffered harness can actually surface it.
#    No --scope needed — the default requests the full grantable verb set (the
#    approver sees + controls the actual grant). Buffered/blocked harness that
#    can't read stdout mid-command? add --code-file=<path> — the user_code is
#    written there as JSON the moment it exists.
php artisan dispatch:session:request --name="<agent>" --purpose="<why>"

# 2. Show the operator the user_code verbatim, then collect the token — the poll
#    blocks until the human decides (most land in ~10s); don't ask them to come
#    back and say "approved":
#    "Approve in the Agent Sessions UI (/dispatch/agent-sessions on the target
#     instance) — confirm the code reads <user_code>."
php artisan dispatch:session:status --wait
#    (--wait ON session:request folds request→collect into ONE call — the
#     shortcut for a HUMAN at a terminal, not a buffered agent.)

# 3. Survey. While the token is active EVERY verb targets production by
#    default (each call announces "→ remote: <host>"; --local overrides).
php artisan dispatch:queue --count            # zero-filled census of the non-terminal board
php artisan dispatch:queue --limit=20 --json  # top of the priority order (summary shape)

# 4. Claim ONE task, only when you start it. The response is the FULL brief —
#    description + comments[], where the human's direction lives — READ IT
#    before touching code. The output also prints claimed_at and the exact
#    closing command.
php artisan dispatch:claim TASK-042 --json    # or bare, for the top candidate

# 5. Work. Record findings as you go (files beat inline quoting):
php artisan dispatch:note TASK-042 --body-file=finding.md

# 6. Close by PASTING the command claim printed — status per the decision card:
php artisan dispatch:done TASK-042 --status=done --commit=<sha> \
  --result-file=result.json --with-metrics --since="<claimed_at from claim>"

# 7. Repeat 4–6 through the plan. When all work is closed out, surrender the
#    credential — this also records the whole session's run metrics
#    automatically (tokens/cost/duration from your transcript):
php artisan dispatch:session:end
```

Notes on the loop:

- `dispatch:next` / `dispatch:queue` return the SUMMARY shape; `comment_count > 0`
  flags waiting human direction — `dispatch:show <CODE> --json` reads the full
  brief before you commit to a claim. `dispatch:schema` prints the frozen JSON
  contract when you need field-level truth.
- `attachment_count > 0` on a task (or a comment) means a human hung evidence the
  API **can't hand you** (no URL, no binary) — a screenshot or file. Ask the
  operator to transcribe it before you act on that brief; don't guess past it.
- **The full shape also carries `context`, and for machine-filed tasks that — not
  the description — is where the evidence lives.** A task minted by exception
  capture files its occurrence events with an intentionally **empty body**, so
  walking `description` + `comments[]` alone makes it look like there is nothing
  to act on. There usually is, under `context`:
  `context.exception.{class,message,file,line}`, the full `trace[]`,
  `route`/`method`/`url`, `context.times_seen` (how often it has fired — real
  triage signal, and on no list view), and `context.result.commit` from any
  earlier agent that worked it. **Rule: an exception-filed task with an empty
  description is not evidence-free — read `context` before you decline it.**
  (Field cost of skipping this: a sweep `declined` a live bug whose `context`
  already named its fix commit.)
- `next`/`claim` are **focus-steered** — production runs a Focus that steers you
  to the sanctioned work first (it never starves; empty/busy focuses fall
  through). That's the mandate and normally what you WANT. Use `--no-focus` only
  when the commission explicitly says to ignore it. (`queue` isn't steered;
  claim-by-code ignores steering.)
- Claim is atomic and race-safe. A named code is honored only while the task is
  still unclaimed (open/triage) — an empty, non-zero result means someone else
  has it: skip it, don't force it.
- The backlog is **live** — other agents and humans work it too. Re-run
  `dispatch:queue` between tasks; claim each item only when you START it.
- On close, record **`result.resolution`** — `built | already-implemented |
  obsolete` (free-form allowed) — so the board can tell what you built from what
  was already there (the vet's "already-implemented" close should stamp it).
- **`--commit` belongs on a `verifying` hand-off, not just a `done`.** The flag is
  status-agnostic, and `verifying` is *exactly* the case where someone else has to
  find your code later — pass the sha you already know. Without it the next reader
  reconstructs the mapping by grepping commit messages; that archaeology has been
  paid for in full at least once, over 112 tasks.
- **Due dates are yours to set — don't hand them back to the human.** `--due=`
  on `dispatch:add` files a task with its deadline already on it; `--due=` on
  `dispatch:done` sets the review-by as you close (the natural pairing with
  `--status=verifying`). Anything Carbon parses works — `2026-08-15` or
  `"+3 days"`, resolved on YOUR clock and sent as ISO 8601; `done --due=""`
  clears an existing date and omitting the flag leaves it untouched. The change
  is memorialized on the timeline, so never write the review-by into a note and
  ask the operator to set the real one in the UI — that detour is retired.
- Long or multi-line inputs always have a file escape hatch: `--result-file`,
  `--body-file`, `--description-file` (on `add` **and** `edit`) — or `-` for
  stdin. Never pipe a body through shell command substitution to dodge a missing
  flag; there is always a file.
- **`dispatch:edit` and `dispatch:merge` do NOT reach the remote** — they are not
  agent verbs, so mid-session they refuse instead of writing to the local dev DB
  (codes are minted per-database; the same code names a different task on each
  side). To change a **title or description** on the remote, write a batch
  manifest `update` op (`{"op":"update","code":"TASK-042","description":"…"}`)
  and apply it with `dispatch:batch` — that op carries `title`, `type`,
  `priority`, `description`, `labels`, `due_at`, and comments. `--due` and
  `--label` also ride `dispatch:done` for a single task.

## Decision card — the calls the tool can't make for you

**`done` vs `verifying` — pick by who still has to act, not whether your part
feels finished.**

| Close as | When |
|---|---|
| `done` | You verified the change end-to-end yourself and it's self-contained. Still the common case — don't hedge. |
| `--status=verifying` | Something only a human can do remains: a visual/UX check, a prod-data/credential check, a migration or backfill whose *result* someone must eyeball, high blast radius (auth, billing, data integrity), or the task asked for sign-off. **Name the exact check** in the result or a note — a bare `verifying` with no stated ask is noise. If the check has a deadline, attach it in the same call with `--due=` instead of writing it in prose. Can't articulate a check? It's `done` (or you're not finished — keep `in_progress`). |

**"Waiting on a deploy" is NOT a `verifying` check.** If the code is committed and
the only thing left is *"has this shipped to prod yet"*, close it **`done`** — the
deploy is one shared operator action, tracked once, globally. Re-asking it per task
is what turns the hand-off pile into a landfill: a real sweep found 4 of 17 legitimate
rows reduced to that single question, plus more that had bundled a deploy clause into
an otherwise-answerable check. A deploy only justifies `verifying` when it carries a
task-specific verification a human must actually perform (a backfill to eyeball, a
flag to flip) — and then that, not the deploy, is the check you name.
| `--status=resolved --note="…"` | **Dealt with, but not as written**: partly done, done a different way, or the need went away (a duplicate you folded in, a request the customer withdrew). The note (what actually happened) is REQUIRED; it's refused without one. ⛔ Never close handled-another-way work as `done`: `done` means the prescribed work was completed, nothing left. |
| `--status=declined` | Won't-do, by decision: obsolete, wrong, or solved elsewhere. Say why in a note. |
| `--status=backburner` | Real but consciously parked: not actionable now or anytime soon (someday-item out of triage), OR code-done but blocked on an external event — a launch date, an ops cutover window. Not rejection (`declined`) and not a pending human check (`verifying`) — say what unblocks it in a note. **Never self-park a commissioned task unless the commission says so.** |

Your `verifying` hand-off pile: `dispatch:queue --status=verifying` (it sits
outside the default queue view; the `--count` census always shows its size).

**"The open items" is ambiguous.** Literal `status:open` = triaged & greenlit
(often a batch a human bulk-moved to mean "these, now"); colloquial "open" =
the whole non-done backlog. `dispatch:queue --count` shows every bucket's size
— state which reading you're using (or ask) before planning a whole-queue run.

**Vet before you plan or build.** Read the brief (claim/show). Then confirm the
described change isn't ALREADY in the tree — imported/backfilled tasks are often
pre-resolved. Grep by the identifier the wiring actually uses (route name,
config/registry key, event name), never the feature's display/component name — a
near-miss grep manufactures phantom work. Already shipped? `claim` → `note` the
evidence (`file:line` + landing commit) → `done` as already-implemented.

**Greenlighting (`triage → open`).** There is no promote verb —
`dispatch:done <CODE> --status=open` records the transition (`done` accepts any
configured workflow status; needs the `done` scope). Self-greenlight ONLY when
the commission explicitly delegates it; otherwise promotion is a human call —
leave items in `triage` and ask. Never claim-then-close a task just to move it.
Park/unpark works the same way: `--status=backburner` shelves,
`--status=open|triage|verifying` revives — the timeline's status-change events
say where it came from.

**Task kinds (TASK-1188).** Some tasks define their own controls: `dispatch:show <CODE> --json`
carries a `kind` block (null for a plain task). `kind.locks_status: true` means done/batch/claim
are REFUSED (a 422, by design); the task moves only by its own actions. `kind.actions` lists the
ones YOU may run: `php artisan dispatch:perform <CODE> <action> [--input=key=value]`. A person's
action (an approval's Approve / Deny) is never offered to an agent, and forcing it is a 403.

## Batch memorialize — one hit instead of forty (optional)

For a long offline run, assemble ONE manifest of add/update ops and apply it in
a single transaction instead of a verb call per task:

```bash
php artisan dispatch:batch run.json --dry-run   # validate first (writes nothing)
php artisan dispatch:batch run.json
```

`dispatch:schema` documents the manifest under its `batch` key. What matters:
`add` mints (defaults to triage — never assumes done); `update` upserts work on
an existing code (status moves only if you set it — memorialize honest
statuses); labels attach; comments dedupe; keyed re-submits are safe; `due_at`
is tri-state on either op kind — omit it to leave the date alone, `null`/`""`
to clear, an ISO date to set. Needs the `batch` scope. The
`dispatch-batch-migrate` skill converts a `todo.md`-style checklist into a
manifest.

## When things go wrong

- **Lost track of where you stand?** `dispatch:session:status` is the safe first
  probe — a local, exit-0 read of your state that names the next verb: ACTIVE
  (token live), DROPPED (names `session:refresh` / `session:end`), or NONE (names
  `session:request`). Only a still-pending request actually polls the remote.
- **`expired` / `revoked` / mid-loop `401`** — the local token is cleared and a
  **drop marker** goes up: bare verbs now FAIL LOUD instead of silently serving
  the local dev DB as if it were production (that masquerade reads as data
  loss). The baked-in resolution is **`dispatch:session:refresh --wait`** — it
  re-requests with the same identity/scopes, names itself a renewal of the
  dropped session for the approver, and blocks for the human decision. Run it
  ONCE and tell the operator; **never loop it** — approval is still a human
  call. `dispatch:session:end` instead acknowledges the drop (back to local
  work); `--local` overrides per call.
- **No banner, and a task you KNOW exists reads as missing?** `→ remote:` rides
  every sticky call; its **absence is the tell**. If `show` answers "Task not
  found" for a live production code, `queue` comes back empty for a bucket that
  isn't, or a `batch` update target "doesn't exist", you are almost certainly
  talking to the LOCAL dev DB. Check `dispatch:session:status` immediately — and
  note that a token which died WITHOUT a 401 leaves no drop marker, so status
  reports NONE rather than DROPPED. Re-commission (or `session:refresh --wait`)
  before writing anything: a batch aimed at production that lands locally can
  write the wrong board and report success.
- **`denied`** — a human said no. **Stop and report** — a refresh would just
  re-ask them; don't.
- **`429`** — rate-limited, NOT a dead session: the token (or pending request)
  is still valid. Back off and retry the SAME call once; never re-request or
  refresh a session over a 429 (that cascade is how tokens get orphaned).
- **`403` "not scoped"** — the error message itself carries the recovery paths.
  Follow it.
- **Still `pending` after `--wait`** — re-run `dispatch:session:status --wait`
  once or twice, widening the budget; then surface it and ask. Never spin.
- **Transport / TLS / secret errors** — the CLI prints the exact fix (CA
  bundle, stale config cache). `php artisan dispatch:doctor` diagnoses agent
  config drift on either end.

## Hard boundaries (no tool guardrail — hold these yourself)

- Never point a dev checkout's DB connection at production; the commissioned
  session IS the access path.
- Never approve your own session, or route approval through a non-staff user.
- Never fabricate a `done` — memorialize partial work honestly
  (`in_progress` / `verifying` + a note), in the verb loop and in batches alike.
- Don't claim tasks to "reserve" them. Survey → plan → claim serially as you
  start each; claiming a pile blocks other agents and marks work in-flight that
  isn't.
- Metrics come from the transcript, never your own estimate. Session totals are
  recorded automatically by `session:end`; per-task cost lands only if that
  task's `done` carried `--with-metrics` — so paste the closing command claim
  printed on EVERY done, not just the last one.
- **`session:end` is a RUN boundary, not a filing boundary.** If you expect to
  file findings intermittently — work, discover something, file it, keep working
  — hold ONE session open for the whole run. Every re-arm costs a human a trip to
  the approval UI, and ending after each filing is how one errand turns into
  three approvals.
- **Never `2>&1` a `--json` verb.** The `→ remote:` banner and every tip ride
  STDERR precisely so a piped stdout stays contract-pure; merging the streams
  corrupts the JSON and the parse failure looks like a broken verb. Same
  discipline when a verb *seems* to have failed: print the raw payload before
  asserting it did — more than one "the API dropped my labels" report has turned
  out to be a client-side parse of a documented shape (`labels` is `string[]`).

## Client prerequisites

```
DISPATCH_AGENT_REMOTE_URL=https://<production-host>/api/dispatch/agent
# token dotfile: ~/.dispatch/agent-token.json by default (0600, outside the repo)
# bootstrap secret: --secret=… or DISPATCH_AGENT_BOOTSTRAP_SECRET (client env)
# sticky remote: on by default; DISPATCH_AGENT_STICKY=false to require --remote per call
```

`php artisan dispatch:doctor` pre-flights the client/server agent config
(remote URL, verbs, secret, cache state) before the first session of the day.
