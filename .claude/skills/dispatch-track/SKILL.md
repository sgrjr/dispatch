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

When the user asks "what should I work on next", or you're about to start a
unit of work that should be tracked end-to-end, drive Dispatch's CLI verbs in
this order:

```
dispatch:pull              # sync canonical state down first, if a remote is configured
    ↓
dispatch:next --json       # pick the single highest-priority open task
    ↓
  ...do the actual work...
    ↓
dispatch:note <code> "..."  # record findings / decisions as you go (repeatable)
    ↓
dispatch:done <code> --ref=<commit-sha-or-pr>   # close it out
    ↓
dispatch:push              # sync local state back up, if a remote is configured
```

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

3. **Do the work** the task describes. This is a normal coding session —
   nothing Dispatch-specific here.

4. **`php artisan dispatch:note <code> "<finding>"`** — as you discover
   things (root cause, a decision point, a blocker), log them immediately
   rather than only summarizing at the end. Defaults to an internal note;
   pass `--public` if it should be visible to a submitter.

5. **`php artisan dispatch:done <code> --ref=<commit-sha-or-PR> [--note="..."]`**
   — mark the task complete once the work lands. `--status=declined` or
   `--status=verifying` are valid alternatives to `done` when that's the
   actual outcome. Always pass `--ref` when you have a commit SHA or PR
   number — it's the audit trail back to the change.

6. **`php artisan dispatch:push`** — only when the user explicitly asks to
   sync local state to a remote install. Never push automatically as a side
   effect of finishing a task.

### Related read-only commands

- `php artisan dispatch:queue --n=10` — the next N tasks in priority order, as a table (triage a backlog)
- `php artisan dispatch:show <code>` — full detail + thread for one task

### See also

- [`README.md`](../../../README.md) — full install/usage guide, including the
  three contract bindings (`DispatchGate`, `TenantResolver`,
  `SubmitterResolver`) that shape what "staff" and "visible" mean in this app
- `config/dispatch.php` — every tunable, commented inline
