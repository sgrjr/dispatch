---
name: dispatch-track
description: PROACTIVELY capture any actionable item — bug, feature request, follow-up, "same recipe for X", "we should also...", customer feedback, "track this", "future task" — as a Dispatch task via the `dispatch:add` CLI, in the SAME response that surfaces the item; don't ask permission first. Also use to DRIVE the Dispatch verb loop when picking up and closing out work: `dispatch:pull` → `dispatch:next` → do the work → `dispatch:note` → `dispatch:done` → `dispatch:push`. Also use when the user explicitly says "track ...", "add a task for ...", "log this as ...", "future task: ...", "remember to ...", "what should I work on next", "pull tasks", "push tasks".
---

<!-- dispatch:template
  A TEMPLATE (TASK-1237): hosts don't copy this file, they render it with
  `php artisan dispatch:skills:publish` (variables, if/else/endif blocks and
  overlay slots — see src/Support/SkillPublisher.php). This note is dropped
  from the rendered skill.
-->

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
  --priority=<low|medium|high|blocker> \
<!-- dispatch:if code_lane -->
  --lane={{ code_lane }} \
<!-- dispatch:else -->
  [--lane=<department>] \
<!-- dispatch:endif -->
  --description-file=<body.md> \
  --label=source:<customer|agent> \
  [--label=area:<area>] \
  [--due=<date>] \
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

**`--priority`** — how urgent, which is NOT how important:
- `low` — **the default for a new feature, idea or follow-up you file**; nice-to-have polish, someday
- `medium` — a latent bug, or a feature someone is actively waiting on
- `high` — user-blocking, a security issue, or a noisy production bug
- `blocker` — production is broken right now, users can't use the app
<!-- dispatch:if loud_priorities -->

⚠️ {{ loud_priorities }} are **loud**: they email the people a task concerns,
with an alarm in the subject. Filing at one of them is a deliberate act — never
the default, never because the item "matters".
<!-- dispatch:endif -->

**`--lane`** — the department that will WORK the task.
<!-- dispatch:if code_lane -->
- **Code work → `--lane={{ code_lane }}`.** A bug, feature, chore, debt or
  verify task that gets worked in this codebase goes to the developer lane.
  Leave `--lane` off and the task lands in **No department**, where nobody is
  watching for it.
- Name another lane only when another department does the work — a customer
  follow-up, a sales or accounting action. `dispatch:add --help` names the lanes.
<!-- dispatch:else -->
- Omit it unless this app routes work by department; then name the lane that
  will do the work.
<!-- dispatch:endif -->

**`--description-file`** (or `--description="…"`, or `-` for stdin) — write it
as if for a future agent with no context. Include:
- What was reported / what triggers the issue
- What success looks like (acceptance criteria, even one line)
- Relevant file paths, function/class names, line numbers if known
- Related task codes (e.g. `TASK-042`) if this links to existing work
- Any commands or one-liners that reproduce the issue

Use markdown freely. A body file beats shell quoting for anything multi-line.

**`--label`** (repeat for each) — labels are auto-created if missing, no
setup required. Sensible starting conventions:
- `source:agent` — you noticed it during work
- `source:customer` — relayed from a user/customer
- `area:<area>` — check this project's existing labels (`dispatch:labels` lists
  them with usage, or the board) before inventing a new one; reuse what's
  already there. A near-duplicate you mint becomes a one-off label someone has
  to clean up at `{{ labels_path }}` later
- `epic:<slug>` — an epic is now just a single-label **Focus**: tag it with an
  `epic:<slug>` label and manage the steering lens at `{{ focuses_path }}`. There
  is no special epic type anymore.

**Label kinds** decide where a label renders: `area:*` / `epic:*` are
**elevated** (navigational — they lead cards/rows and a board can lane by them);
`source:*` / `kind:*` are **meta** (bookkeeping — detail view only). Anything
else is a plain label.

**`--due`** — a deadline, when there is one (`2026-08-15`, `"+3 days"`).

**`--public`** — omit unless the item should be visible to non-staff
submitters (default is private/internal).
<!-- dispatch:slot capture -->

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
<!-- dispatch:if remote_host -->
> {{ app_name }}'s authoritative task list lives on production
> (`{{ remote_host }}`) — stop and use
<!-- dispatch:else -->
> i.e. the authoritative task list lives on a different, deployed instance —
> stop and use
<!-- dispatch:endif -->
> `.claude/skills/dispatch-agent-session/SKILL.md` instead: it commissions a
> human-approved session, after which the verbs target production
> automatically (sticky remote).
>
> **Sticky-remote caveat:** while a commissioned agent-session token is
> ACTIVE on this machine, the plain verbs below default to the REMOTE
> (each call announces `→ remote: <host>`). For local tracking during an
> active session, pass `--local` — or end the session first
> (`dispatch:session:end`).
>
> **Dropped-session caveat:** if a session died involuntarily instead
> (mid-run 401, denied/revoked/expired), the plain verbs FAIL LOUD rather
> than silently acting on the local DB — local throwaway tasks must never
> masquerade as the production board. For local tracking in that state:
> pass `--local` per call, or run `dispatch:session:end` once to
> acknowledge the drop and restore local-by-default
> (`dispatch:session:refresh --wait` renews the session instead, if
> production work should continue).

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

   **The full shape also carries `context` — read it.** A task filed by
   exception capture records its occurrences with an empty comment body, so
   walking `description` + `comments[]` alone can make a fully-diagnosed bug
   look like an empty row. The evidence is under `context`:
   `context.exception.{class,message,file,line}`, the `trace[]`, the route/URL,
   `context.times_seen` (how often it has fired), and `context.result.commit`
   from any earlier agent that worked it. **An exception-filed task with an
   empty description is not evidence-free — read `context` before declining
   it.**

3. **`php artisan dispatch:claim --json`** — claim it before you start:
   marks the task `in_progress` and assigns it in one atomic transaction.
   Scope with `--type=` / `--label=*` the same way you'd scope `next`. This
   matters whenever more than one agent (or an agent and a human) might pull
   from the same backlog — `next` alone is just a preview and doesn't
   reserve anything. When an active **Focus** exists, `next`/`claim` steer
   toward its matches first (it never starves — an empty focus falls through);
   `--no-focus` ignores it, and `queue` is never steered.

4. **Do the work** the task describes. This is a normal coding session —
   nothing Dispatch-specific here.

5. **`php artisan dispatch:note <code> "<finding>"`** — as you discover
   things (root cause, a decision point, a blocker), log them immediately
   rather than only summarizing at the end. The note is visible to the
   submitter by default; pass `--internal` to keep it staff-only.

6. **`php artisan dispatch:done <code> --commit=<sha> --result='{"tests":"passing"}'`**
   — mark the task complete once the work lands. `--commit` + `--result` are
   stored under the task's `context.result` as the audit trail back to the
   change; always pass a commit SHA when you have one. Record how it resolved
   with a `result.resolution` key (`built | already-implemented | obsolete`,
   free-form allowed) so the board can tell built work from what was already in
   the tree. **Pick the closed status by what actually happened**; each means
   exactly one thing:
   - `done` = the prescribed work was completed, nothing left;
   - `resolved` = dealt with, but **not as written** (partly, differently, or
     the need went away). `--note="<what actually happened>"` is REQUIRED
     (refused without one);
   - `declined` = not done, by decision.
   ⛔ Never close handled-another-way work as `done`. `--status=verifying` or
   `--status=backburner` (parked / not-now, out of the queue without declining)
   are the non-closing alternatives. (`--note` rides any status as the body of
   the status event; for a free-standing comment, use `dispatch:note`.)

   **If you close `verifying`, name the exact check** — in `--result` or a
   preceding note. A bare `verifying` with no stated ask is noise: it reads
   identically to abandoned work, and the pile it builds can only be cleared by
   code archaeology. Two rules that follow: **"waiting on a deploy" is not a
   check** (close `done`; the deploy is one shared action, tracked once, not
   re-asked per task) — it only counts when the deploy carries a task-specific
   verification a human must perform; and **pass `--commit` on a `verifying`
   hand-off too**, not just a `done`, since that is precisely the case where
   someone else has to find your code later.

   **Stamp run metrics (optional).** To memorialize what the run cost —
   tokens, cost, tool usage, duration — add `--with-metrics` to the same
   `done` call. The numbers come from the transcript, not your say-so (you
   can't read your own token usage, so never hand-write these):

   ```bash
   php artisan dispatch:done <code> --commit=<sha> --result-file=result.json \
     --with-metrics --since="<claimed_at from claim>"
   ```

   It windows the transcript to this task's claim→now span (many tasks per
   session is fine) and lands under `context.result.metrics`, beside your
   summary rather than over it.

7. **`php artisan dispatch:push`** — only when the user explicitly asks to
   sync local state to a remote install. Never push automatically as a side
   effect of finishing a task.

### Related read-only commands

- `php artisan dispatch:queue --limit=10` — the next N tasks in priority order (triage a backlog); `--count` is the zero-filled census
- `php artisan dispatch:find <words>` — search title/code/description across **every** status ("was this already built?")
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
whole file applies atomically. The same `priority` and `lane` rules as
`dispatch:add` apply to every `add`. `php artisan dispatch:schema` documents the
manifest under the `batch` key. To turn a checklist-style markdown file into a
manifest automatically, use the `dispatch-batch-migrate` skill. (Add `--remote`
only when driving the **production** backlog — see the agent-session skill.)
<!-- dispatch:slot loop -->

### Working the production backlog instead of local dev

Everything in Part 2 operates on **this app's local database**. `pull` /
`push` sync two installs of *this package* against each other (e.g. local
dev ↔ production, over `dispatch.sync.remote_url` / `dispatch.sync.token`) —
that's still local-DB reads/writes on this end, just kept in sync with a
peer.

That's different from **working the real, authoritative backlog directly on
production** from somewhere else (no local checkout of the prod DB at all).
For that, commission a human-approved session first — see
`.claude/skills/dispatch-agent-session/SKILL.md` for the
`dispatch:session:request` → approval flow. While that session's token
is active, the verbs target production **by default** (sticky remote — each
call announces `→ remote: <host>`; `--local` overrides); with no active
session, the plain verbs above never reach production — and after a session
DROPS mid-run, they fail loud instead of quietly reverting to local (see the
dropped-session caveat above).

### See also

- `{{ package_path }}/README.md` — full install/usage guide, including the
  three contract bindings (`DispatchGate`, `TenantResolver`,
  `SubmitterResolver`) that shape what "staff" and "visible" mean in this app,
  plus §8 "AI / remote agent" for the full agent-CLI verb list and the
  remote agent seam
- `.claude/skills/dispatch-agent-session/SKILL.md` — commissioning and
  driving a session against the production backlog
- `config/dispatch.php` — every tunable, commented inline
<!-- dispatch:slot see-also -->
