---
name: dispatch-agent-session
description: PROACTIVELY use when asked to work the PRODUCTION Dispatch backlog from outside the deploy — "work the live/prod backlog", "run as a remote agent", "commission an agent session", "pick up real tasks remotely", "work this against production", "work/claim all the open production items", "plan and complete the production backlog" — or whenever context makes clear the target is the authoritative (production) Dispatch instance rather than local dev. Drives the human-commissioned session protocol (one-shot `dispatch:session:request --wait` → human approval) and the verb loop that follows — while the session token is active every dispatch verb targets production automatically (sticky remote), so the loop is queue → claim → work → note → done → session:end — plus the batch "memorialize" path (`dispatch:batch`) that commits a whole run of add/update ops in one hit. Also use when a session goes stale (401 mid-loop, denied, revoked, expired) and needs graceful handling. Do NOT use for local dev work against the app's own database — see `dispatch-track` for that.
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
# 1. Commission — ONE command: prints a short user_code, blocks until a human
#    decides, then stores the token. No --scope needed: the default requests
#    the full grantable verb set (the approver sees + controls the actual grant).
php artisan dispatch:session:request --name="<agent>" --purpose="<why>" --wait

# 2. Show the operator the user_code verbatim, once:
#    "Approve in the Agent Sessions UI (/dispatch/agent-sessions on the target
#     instance) — confirm the code reads <user_code>."
#    Don't ask them to come back and say "approved" — --wait returns on its own.

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
- Claim is atomic and race-safe. A named code is honored only while the task is
  still unclaimed (open/triage) — an empty, non-zero result means someone else
  has it: skip it, don't force it.
- The backlog is **live** — other agents and humans work it too. Re-run
  `dispatch:queue` between tasks; claim each item only when you START it.
- Long or multi-line inputs always have a file escape hatch: `--result-file`,
  `--body-file`, `--description-file` (or `-` for stdin).

## Decision card — the calls the tool can't make for you

**`done` vs `verifying` — pick by who still has to act, not whether your part
feels finished.**

| Close as | When |
|---|---|
| `done` | You verified the change end-to-end yourself and it's self-contained. Still the common case — don't hedge. |
| `--status=verifying` | Something only a human can do remains: a visual/UX check, a deploy or migration, a prod-data/credential check, high blast radius (auth, billing, data integrity), or the task asked for sign-off. **Name the exact check** in the result or a note — a bare `verifying` with no stated ask is noise. Can't articulate a check? It's `done` (or you're not finished — keep `in_progress`). |
| `--status=declined` | Won't-do: obsolete, wrong, or solved elsewhere — say why in a note. |
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
statuses); labels attach; comments dedupe; keyed re-submits are safe. Needs the
`batch` scope. The `dispatch-batch-migrate` skill converts a `todo.md`-style
checklist into a manifest.

## When things go wrong

- **`expired` / `revoked` / mid-loop `401`** — the local token is cleared and a
  **drop marker** goes up: bare verbs now FAIL LOUD instead of silently serving
  the local dev DB as if it were production (that masquerade reads as data
  loss). The baked-in resolution is **`dispatch:session:refresh --wait`** — it
  re-requests with the same identity/scopes, names itself a renewal of the
  dropped session for the approver, and blocks for the human decision. Run it
  ONCE and tell the operator; **never loop it** — approval is still a human
  call. `dispatch:session:end` instead acknowledges the drop (back to local
  work); `--local` overrides per call.
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

## Client prerequisites

```
DISPATCH_AGENT_REMOTE_URL=https://<production-host>/api/dispatch/agent
# token dotfile: ~/.dispatch/agent-token.json by default (0600, outside the repo)
# bootstrap secret: --secret=… or DISPATCH_AGENT_BOOTSTRAP_SECRET (client env)
# sticky remote: on by default; DISPATCH_AGENT_STICKY=false to require --remote per call
```

`php artisan dispatch:doctor` pre-flights the client/server agent config
(remote URL, verbs, secret, cache state) before the first session of the day.
