---
name: dispatch-agent-session
description: PROACTIVELY use when asked to work the PRODUCTION Dispatch backlog from outside the deploy — "work the live/prod backlog", "run as a remote agent", "commission an agent session", "pick up real tasks remotely", "work this against production" — or whenever context makes clear the target is the authoritative (production) Dispatch instance rather than local dev. Drives the human-commissioned session protocol (`dispatch:session:request` → human approval → `dispatch:session:status`) and then the `--remote` verb loop (`dispatch:next --remote` → `dispatch:claim --remote` → work → `dispatch:note --remote` → `dispatch:done --remote`). Also use when a session goes stale (401 mid-loop, denied, revoked, expired) and needs graceful handling. Do NOT use for local dev work against the app's own database — see `dispatch-track` for that.
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
  [--secret=<bootstrap secret, if not already configured>]
```

This POSTs to the remote's session-request endpoint (bootstrap-secret gated,
not bearer — there's no token yet) and stores a pending request locally. It
prints a short `user_code`. **Show that code to the human operator verbatim**
— they need it to confirm the request in the approval UI matches what you
requested.

### 2. Hand off to a human — do not proceed alone

Tell the operator: *"Approve this session in the Agent Sessions UI at
`/dispatch/agent-sessions` on the production instance — confirm the code
reads `<user_code>`."* That page is a staff-gated Livewire view where a human
approves or denies pending requests (and can see/kill active sessions).

You cannot approve your own session. Stop here and wait.

### 3. Poll for approval — async + human-gated, back off, do NOT spin

```bash
php artisan dispatch:session:status
```

This polls **once** and exits — it is deliberately not a loop. Approval is a
human, out-of-band action: it can take seconds or it can take minutes. Space
re-polls out (tens of seconds apart, backing off further the longer it's been
pending) rather than hammering the endpoint in a tight loop, and don't burn
the conversation spinning on it — if it's still pending after a few checks,
say so and stop, so the user can decide whether to keep waiting.

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
php artisan dispatch:queue --remote --json         # triage: the next N candidates (read-only, SUMMARY shape)
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
`dispatch:queue` are only previews.

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
actually asked for. The `queue` and `show` scopes requested in step 1 are what
make this triage-then-inspect path possible — that's why they're in the
request.

`dispatch:schema` prints the frozen `TaskPresenter` JSON contract (summary +
full-view keys, plus the timeline event vocabulary) — parse `--json` output
against that documented shape, not a guess:

```bash
php artisan dispatch:schema
```

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

---

## See also

- [`README.md`](../../../README.md) — the "AI / remote agent" section covers
  the full agent-CLI verb list, the `agent` config block on the production
  side, and the `EventNotifier` binding for reactive orchestration
- `.claude/skills/dispatch-track/SKILL.md` — local dev capture + verb loop,
  and the production/remote pointer back to this skill
- `php artisan dispatch:schema` — the authoritative `--json` shape
