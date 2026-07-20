---
name: dispatch-track
description: PROACTIVELY capture any actionable item — bug, feature request, follow-up, "same recipe for X", "we should also...", customer feedback, "track this", "future task" — as a Dispatch task via the `dispatch:add` CLI, in the SAME response that surfaces the item; don't ask permission first. Also use to DRIVE the Dispatch verb loop when picking up and closing out work: `dispatch:pull` → `dispatch:next` → do the work → `dispatch:note` → `dispatch:done` → `dispatch:push`. Also use when the user explicitly says "track ...", "add a task for ...", "log this as ...", "future task: ...", "remember to ...", "what should I work on next", "pull tasks", "push tasks".
---

# Dispatch task capture & verb loop

This project tracks open work — bugs, features, follow-ups, tech debt,
customer feedback — in **Dispatch**, a database-backed task system
(`sgrjr/dispatch`). The database is the canonical source of truth; don't let
actionable items slip into chat history and disappear.

This skill covers two related jobs:

1. **Capture** — the moment you spot an actionable item, log it with
   `dispatch:add` instead of just mentioning it in prose.
2. **Drive the verb loop** — when you (or the user) are picking a task to
   work on and closing it out, use the `pull → next → work → note → done →
   push` sequence so task state and your work stay in sync.

---

## Part 1 — Capture

### When to invoke

Auto-invoke the moment you spot any of these patterns — in the user's
message OR in your own draft response:

| Pattern | Example phrase |
|---|---|
| Bug described | "X isn't working", "Y fails when...", "this is broken", "regression in Z" |
| Feature requested | "we should add...", "it would be nice if...", "I want...", "can you build..." |
| Follow-up emerging | "same recipe for...", "do this elsewhere too", "we'll also need to...", "TBD" |
| Customer feedback | "the customer reported...", relayed quotes from a customer |
| Tech debt named | "we should clean up...", "this is hacky", "refactor later" |
| Explicit command | "track this", "log this as a task", "future task:", "remember to..." |

If a single message describes multiple items, **track each one separately**
with its own `dispatch:add` call.

### Do NOT use when

- The user is exploring an idea conversationally with no clear action item
  ("what do you think about...?", "could we...?")
- The work is already being completed in the current session (the actionable
  item IS what you're working on right now — see Part 2 instead)
- The item is already an open task you're actively working — use
  `dispatch:note` to record findings on it instead of creating a duplicate
- The user explicitly says "don't track this" or "this is just for context"

### How to invoke

```bash
php artisan dispatch:add "<title>" \
  --type=<bug|feature|chore|debt|verify> \
  --priority=<blocker|high|medium|low> \
  --description="<full markdown body>" \
  --label=source:<customer|agent> \
  [--label=area:<area>] \
  [--public]
```

**`title`** (required, positional) — ~10 words, present-tense imperative or
noun phrase. Specific enough to scan in a list: "Form fields not saving on
job creation" ✓, "Bug in jobs" ✗.

**`--type`**
- `bug` — broken behavior, regression, error, defect, customer complaint about how something works
- `feature` — new capability, enhancement, "would be nice if"
- `chore` — UI polish, refactor, doc update, dev experience
- `debt` — known tech debt, security hardening, performance, "should fix this later"
- `verify` — a previously-claimed-done thing that needs smoke-testing

**`--priority`**
- `blocker` — production is broken right now, users can't use the app
- `high` — user-blocking, security issue, or a noisy bug
- `medium` — default; pick this when unclear
- `low` — nice-to-have polish, idea for someday

**`--description`** — write it as if for a future agent with no context.
Include:
- What was reported / what triggers the issue
- What success looks like (acceptance criteria, even one line)
- Relevant file paths, function/class names, line numbers if known
- Related task codes (e.g. `TASK-042`) if this links to existing work
- Any commands or one-liners that reproduce the issue

Use markdown freely. For multi-line bodies, use a shell heredoc or a
properly-quoted string.

**`--label`** (repeat for each) — labels are auto-created if missing, no
setup required. Sensible starting conventions:
- `source:agent` — you noticed it during work
- `source:customer` — relayed from a user/customer
- `area:<area>` — check this project's existing labels (`dispatch:queue` or
  the board) before inventing a new one; reuse what's already there
- `epic:<slug>` — only if it clearly belongs to an existing epic

**`--public`** — omit unless the item should be visible to non-staff
submitters (default is private/internal).

### After creating

Mention what you tracked at the end of your response, one line:

> Captured **TASK-XXX** *(title)* as a `<type>` (priority: `<priority>`).

If you created multiple, list them all. **Don't push to a remote Dispatch
install automatically** (see `dispatch:push` below) — new tasks stay local
until the user explicitly asks to sync.

---

## Part 2 — Drive the verb loop

> **Local dev vs. the real backlog.** Everything below (`dispatch:pull` /
> `dispatch:next` / `dispatch:done` / `dispatch:push`) reads and writes
> **this app's own local database** — right for tracking work on this
> checkout. If you're working the **real, production backlog** instead —
> i.e. the authoritative task list lives on a different, deployed instance —
> stop and use `.claude/skills/dispatch-agent-session/SKILL.md` instead: it
> commissions a human-approved session, after which the verbs target
> production automatically (sticky remote).
>
> **Sticky-remote caveat:** while a commissioned agent-session token is
> ACTIVE on this machine, the plain verbs below default to the REMOTE
> (each call announces `→ remote: <host>`). For local tracking during an
> active session, pass `--local` — or end the session first
> (`dispatch:session:end`).

When the user asks "what should I work on next", or you're about to start a
unit of work that should be tracked end-to-end, drive Dispatch's CLI verbs in
this order:

```
dispatch:pull              # sync canonical state down first, if a remote is configured
    ↓
dispatch:next --json       # preview the single highest-priority open task
    ↓
dispatch:claim --json      # atomically claim it: in_progress + assigned, in
                            # one transaction — do this before starting work
                            # whenever more than one agent/human might be
                            # picking off the same backlog
    ↓
  ...do the actual work...
    ↓
dispatch:note <code> "..."  # record findings / decisions as you go (repeatable)
    ↓
dispatch:done <code> --commit=<sha> --result='{...}'   # close it out (structured completion)
    ↓
dispatch:push              # sync local state back up, if a remote is configured
```

`dispatch:claim` (`--type=` / `--label=*` to scope which task it claims) is
the race-safe way to pick up work — prefer it over treating `dispatch:next`'s
result as already yours, since `next` is read-only and doesn't reserve
anything. `php artisan dispatch:schema` prints the documented `--json` shape
(the frozen `TaskPresenter` contract for every verb's summary/full output) —
parse against that instead of guessing field names from examples.

### Step by step

1. **`php artisan dispatch:pull`** — fetches the canonical task snapshot from
   a configured remote Dispatch install (`DISPATCH_REMOTE_URL` /
   `DISPATCH_REMOTE_TOKEN`) and imports it locally. If no remote is
   configured this no-ops with an instructive message — that's fine, keep
   going with local state.

2. **`php artisan dispatch:next --json`** — returns the single
   highest-priority open task (ordering: `in_progress` > `open` > `triage`,
   then `blocker` > `high` > `medium` > `low`). Use `--json` when you need to
   parse the result programmatically; drop it for a human-readable summary.
   `php artisan dispatch:show <code> --json` gives full detail plus the
   discussion thread if you need more context before starting.

3. **`php artisan dispatch:claim --json`** — claim it before you start:
   marks the task `in_progress` and assigns it in one atomic transaction.
   Scope with `--type=` / `--label=*` the same way you'd scope `next`. This
   matters whenever more than one agent (or an agent and a human) might pull
   from the same backlog — `next` alone is just a preview and doesn't
   reserve anything.

4. **Do the work** the task describes. This is a normal coding session —
   nothing Dispatch-specific here.

5. **`php artisan dispatch:note <code> "<finding>"`** — as you discover
   things (root cause, a decision point, a blocker), log them immediately
   rather than only summarizing at the end. The note is visible to the
   submitter by default; pass `--internal` to keep it staff-only.

6. **`php artisan dispatch:done <code> --commit=<sha> --result='{"tests":"passing"}'`**
   — mark the task complete once the work lands. `--commit` + `--result` are
   stored under the task's `context.result` as the audit trail back to the
   change; always pass a commit SHA when you have one. `--status=declined` or
   `--status=verifying` are valid alternatives to `done` when that's the actual
   outcome. (To leave a comment, use `dispatch:note` — `done` has no note flag.)

   **Stamp run metrics (optional).** To memorialize what the run cost —
   tokens, cost, tool usage, duration — fold `dispatch:metrics` into the same
   `--result` call so the numbers come from the transcript, not your say-so
   (you can't read your own token usage, so never hand-write these):

   ```bash
   php artisan dispatch:done <code> --commit=<sha> \
     --result="$(php artisan dispatch:metrics <code> --json)"
   ```

   It windows the transcript to this task's claim→now span (many tasks per
   session is fine) and lands under `context.result.metrics`. Add `--note` for
   a one-line internal summary on the timeline instead.

7. **`php artisan dispatch:push`** — only when the user explicitly asks to
   sync local state to a remote install. Never push automatically as a side
   effect of finishing a task.

### Related read-only commands

- `php artisan dispatch:queue --n=10` — the next N tasks in priority order, as a table (triage a backlog)
- `php artisan dispatch:show <code>` — full detail + thread for one task
- `php artisan dispatch:schema` — the documented `--json` shape (the frozen
  `TaskPresenter` contract) every `--json` verb's output conforms to

### Batch: apply a whole manifest at once

When you've done a chunk of work offline and want to record it all in one shot
— several new tasks plus status/label/comment updates to existing ones — write
a JSON manifest and apply it with a single command instead of many `add` /
`note` / `done` calls:

```bash
php artisan dispatch:batch run.json --dry-run   # validate + preview, writes nothing
php artisan dispatch:batch run.json             # apply to the local DB in one transaction
```

Each operation is either an `add` (new task, defaults to triage) or an `update`
(existing task by `code`); labels attach additively, comments dedupe, and the
whole file applies atomically. `php artisan dispatch:schema` documents the
manifest under the `batch` key. To turn a `todo.md`-style checklist into a
manifest automatically, use the `dispatch-batch-migrate` skill. (Add `--remote`
only when driving the **production** backlog — see the agent-session skill.)

### Working the production backlog instead of local dev

Everything in Part 2 operates on **this app's local database**. `pull` /
`push` sync two installs of *this package* against each other (e.g. local
dev ↔ production, over `dispatch.sync.remote_url` / `dispatch.sync.token`) —
that's still local-DB reads/writes on this end, just kept in sync with a
peer.

That's different from **working the real, authoritative backlog directly on
production** from somewhere else (no local checkout of the prod DB at all).
For that, commission a human-approved session first — see
`.claude/skills/dispatch-agent-session/SKILL.md` for the one-shot
`dispatch:session:request --wait` → approval flow. While that session's token
is active, the verbs target production **by default** (sticky remote — each
call announces `→ remote: <host>`; `--local` overrides); with no active
session, the plain verbs above never reach production.

### See also

- [`README.md`](../../../README.md) — full install/usage guide, including the
  three contract bindings (`DispatchGate`, `TenantResolver`,
  `SubmitterResolver`) that shape what "staff" and "visible" mean in this app,
  plus §8 "AI / remote agent" for the full agent-CLI verb list and the
  remote agent seam
- `.claude/skills/dispatch-agent-session/SKILL.md` — commissioning and
  driving a session against the production backlog
- `config/dispatch.php` — every tunable, commented inline
