---
name: dispatch-batch-migrate
description: Convert a checklist-style markdown file — meeting notes with action items, a handoff doc, a scratch list, a legacy checklist — into a Dispatch BATCH MANIFEST (the operations[] JSON that `dispatch:batch` applies) and apply it in one shot. Use when someone has an informal task list — "turn this checklist into Dispatch tasks", "import these action items", "memorialize these into the backlog", "batch-load these tasks", "migrate my notes into dispatch" — and wants the items filed/updated as Dispatch tasks in a single transaction rather than one `dispatch:add` at a time. Produces a manifest, validates it with `--dry-run`, shows the mapping and the board dedupe, and only then applies (local or `--remote`). For a single item use `dispatch:add`; for a full-history backfill that must preserve original dates, authors, and commit SHAs, use the `dispatch:import` path — see `{{ package_path }}/MIGRATING.md`.
---

<!-- dispatch:template
  A TEMPLATE (TASK-1237): hosts don't copy this file, they render it with
  `php artisan dispatch:skills:publish` (variables, if/else/endif blocks and
  overlay slots — see src/Support/SkillPublisher.php). This note is dropped
  from the rendered skill.
-->

# Migrate a checklist-style markdown file into a Dispatch batch manifest

`dispatch:batch` applies a whole manifest of task operations in one transaction
(see the `dispatch-track` / `dispatch-agent-session` skills for the command
itself). This skill is the **front half**: turn a human's informal checklist —
meeting notes with action items, a handoff doc, a scratch list, a legacy
checklist — into a valid `operations[]` manifest, validate it, and apply it.

The point is to memorialize a batch of work honestly and in one hit:
**batch-insert brand-new items straight to triage**, and **upsert existing tasks
to the status they actually reached** (never force everything to `done`).

> **Pick the right path first.** This skill produces a **`dispatch:batch`**
> manifest — additive, and always timestamped **"now"**. That's right for a live
> run you're memorializing. If the source is a **historical** file whose done
> items must keep their **original dates, authors, and commit SHAs**, that's the
> **`dispatch:import`** path instead — it backdates `createdAt` and dates
> completion via a `status_change` comment. **`{{ package_path }}/MIGRATING.md`
> is the full guide** and covers both; the conventions below (§Step 2)
> — vocab-preserving labels, flatten rule, provenance key — apply to either path.

---

## When to invoke

| Pattern | Example phrase |
|---|---|
| A file of tasks to load | "turn this checklist into dispatch tasks", "import these action items" |
| Memorialize a run | "record all of this into the backlog", "memorialize these" |
| Bulk load / migrate | "batch-load these", "migrate my notes into dispatch" |

## Do NOT use when

<!-- dispatch:slot do-not-use -->
- There's a **single** item → just `php artisan dispatch:add "<title>" …`.
- The user wants to *design* or change the batch API → that's package work, not
  this skill.
- The list is exploratory ("ideas we might do") with no intent to file — confirm
  first; don't auto-file speculation.

---

## Step 1 — Read the source file and classify each line

Read the file. Treat every **actionable line** (a bullet, a checkbox item, or a
numbered item) as one operation. Ignore headings, prose, blank lines, and
horizontal rules — but **use headings as context** (a line under "## In
progress" is in-progress; under "## Done" is done; under "## Backlog"/"## New"
is a fresh triage item).

**Nested sub-items** (`  - [x]` indented under a parent) have no home in Dispatch's
**flat** model — there is no subtask/parent column. Flatten them: by default,
**fold the sub-items into the parent's `description`** as a checklist (one task,
full detail). Only when a sub-item is substantial and worth tracking on its own,
emit it as a separate task linked back with a `parent:<code>` label or
`context.parent`. (MIGRATING.md §4.)

Classify each line as **update** (it names an existing task) or **add** (new):

- **Contains a task code** matching this project's prefix (default `TASK-\d+`,
  e.g. `TASK-042`) → an **`update`** op, keyed on that `code`. The task already
  exists on the target; you're memorializing progress on it.
- **No task code** → an **`add`** op. A brand-new task the batch will file.

If unsure what the code prefix is, run `php artisan dispatch:queue` (or
`dispatch:schema`) to see real codes, or check `dispatch.code_prefix`.

## Step 2 — Map each line to an operation

### Status — from the checkbox + heading (never blanket-`done`)

| Source signal | `add` (new) status | `update` (existing) status |
|---|---|---|
| `[x]` / under "Done" / "(done)" | `done` | `done` |
| `[ ]` under "In progress" / "Doing", or has progress notes | `in_progress` | `in_progress` |
| `[ ]` plain / under "Backlog" / "New" / "Todo" | `triage` (default) | *(omit — don't move it)* |
| "needs verify" / "to verify" | `verifying` | `verifying` |
| "someday" / "icebox" / "parked" / "deferred" / "wishlist" | `backburner` | `backburner` |
| "declined" / "won't do" / "wontfix" | `declined` | `declined` |

**Key rule:** for an **`update`**, if you can't tell the status changed, **omit
`status`** — the batch then leaves it untouched. For an **`add`**, the default is
**`triage`** (file it, don't presume it's done). Only mark `done` on an explicit
done signal.

### Other fields

- **`title`** (add) — the cleaned line text (strip the checkbox, code, trailing
  metadata, and inline tags). Keep it short and specific.
- **`type`** (add) — infer from keywords, else `feature`:
  `bug` (`bug`, `fix`, `broken`, `crash`, `regression`), `chore`
  (`refactor`, `cleanup`, `polish`, `docs`), `debt` (`tech debt`, `security`,
  `perf`, `harden`), `verify` (`verify`, `smoke test`), else `feature`.
- **`priority`** (add) — from an explicit `(blocker)` / `(high)` / `(low)`, or
  `!!`/`!`/"urgent"/"asap" → `high`; otherwise `low` for a feature, idea or
  follow-up and `medium` for a bug.
<!-- dispatch:if loud_priorities -->
  ⚠️ {{ loud_priorities }} are **loud** — they email the people a task concerns.
  Never reach for one just because an item matters; only an explicit urgency
  signal in the source earns it.
<!-- dispatch:endif -->
- **`lane`** (add) — the department that will WORK the task.
<!-- dispatch:if code_lane -->
  **Code work → `"lane": "{{ code_lane }}"`**: a bug, feature, chore, debt or
  verify item worked in this codebase. An add with no lane lands in **No
  department**, where nobody is watching for it. Name another lane only when
  another department does the work (a customer follow-up, a sales or
  accounting action).
<!-- dispatch:else -->
  Omit it unless this app routes work by department.
<!-- dispatch:endif -->
- **`labels`** — from inline `#tags`, `[area:x]`, `@owner`, or a section like
  "## API". Applies to both add and update (labels **attach** additively —
  batch never strips existing labels).
- **`labels` — preserve a richer md taxonomy (don't discard it).** Dispatch's
  native vocab is narrow (`type` = bug/feature/chore/debt/verify). When the md
  carries more — kinds `BUG/NEW/INFRA/POLISH/PERF/UPGRADE`, states
  `PARTIAL/DEFERRED/WIP`, sizes `S/M/L` — map to the nearest native field **and
  keep the original as a label**: `kind:INFRA`, `state:PARTIAL`, `size:M`. Nothing
  is lost, and a filter recovers the original taxonomy. (`dispatch.workflow.types`
  is config-extensible if a host wants richer *native* types instead — MIGRATING.md §5.)
- **Provenance** — stamp every migrated task with a `source:<slug>` label naming
  the actual file it came from (`source:handoff-md`, `source:standup-notes`), so
  one filter shows the whole migrated cohort. For an `add` that a re-run might
  re-file, set a **stable `key`** derived from the source
  (`sha1(file|first-line)` is the convention) so re-applying upserts instead of
  duplicating (MIGRATING.md §6).
- **`due_at`** — a line carrying a deadline ("by Friday", "due 8/15", "ship
  before the 20th", `{due:2026-08-15}`) → resolve it to an **iso8601 date** on
  the op (`"due_at": "2026-08-15"`), same on add and update. It is **tri-state**:
  leave the key out and the due date is untouched (the default for every line
  with no deadline in it); `null` or `""` clears an existing one — only emit
  that when the source explicitly drops the deadline ("no longer time-boxed").
  A relative phrase resolves against *today* — if that reading is a guess
  ("soon", "this sprint"), leave it out rather than invent a date, and an
  unparseable value fails the whole manifest by op index anyway.
- **`commit`** — a trailing `(commit <sha>)` / `#<sha>` → the op's `commit`.
- **`comments`** — trailing notes after `—`, `:`, or an indented sub-bullet
  become a `comment` (`{"body": "…"}`). A comment is an **internal** (staff-only)
  note by default; add `"public": true` only for a reply meant for the
  submitter (they see it and are emailed).
- **`ref`** (add) — give every add a short handle (`n1`, `n2`, … or a slug) so
  the response maps it back to the minted code.
- **`key`** (add, optional) — if the same list may be applied more than once,
  add a stable `key` (e.g. `todo:<slug>`) so a re-run doesn't duplicate the task.

Leave out anything you genuinely can't infer — a partial op is fine; a wrong
guess is not. When a whole line is ambiguous (can't tell add vs update, or a
title you can't clean confidently), **ask** rather than fabricate.

## Step 3 — Write the manifest file

Write the JSON to the **session scratchpad**, not into the repo — a manifest is
a working file, not a checked-in artifact (e.g. `<scratchpad>/dispatch-batch.json`):

```json
{
  "operations": [
    {"op": "update", "code": "TASK-042", "status": "in_progress",
     "commit": "abc1234", "labels": ["area:checkout"],
     "comments": [{"body": "after-tax path fixed; pre-tax remains"}]},

    {"op": "add", "ref": "n1", "title": "Checkout crashes on null coupon",
     "type": "bug", "priority": "medium", "labels": ["area:checkout"]},

    {"op": "add", "ref": "n2", "title": "Add CSV export to reports",
     "type": "feature", "priority": "low"}
  ]
}
```

`op` may be omitted — a line with a `code` is inferred as `update`, otherwise
`add` — but writing it explicitly makes the manifest easier for the human to
review.

## Step 4 — Validate with `--dry-run`, then show the human the mapping

**Always dry-run first.** It validates the whole manifest (vocab, required
fields, that every `update` code exists) and reports the summary **without
writing**:

```bash
php artisan dispatch:batch dispatch-batch.json --dry-run
# add --remote to validate against production instead of the local DB
```

If it errors, it names the offending op index — fix that line and re-run. Then
present a short summary to the user: *"N new tasks → triage, M existing tasks
updated; here's the mapping"* — so they can catch a miscategorized line before
anything is written. Do not skip this review, especially for `--remote`.

**Dedupe the adds against the FULL board first — not just against the open
buckets.** An informal list is written by someone who doesn't have the board in
front of them, so it re-states work that is already filed (often already
`done`). The dry-run does NOT catch this: an `add` whose twin already exists is
perfectly valid, and it mints a duplicate. Search by the words the task would
actually carry (`dispatch:find <words>` spans every status) before filing, and
turn each hit into an `update` on the existing code.

## Step 5 — Apply

Once the dry-run is clean and the human is good with the mapping:

```bash
php artisan dispatch:batch dispatch-batch.json            # apply to the LOCAL dev DB
php artisan dispatch:batch dispatch-batch.json --remote   # memorialize on PRODUCTION in one hit
```

`--remote` requires a commissioned session with the `batch` scope — see the
`dispatch-agent-session` skill. The response echoes each op's `ref → code`
so you can tell the user exactly which new task codes were minted. Re-running the
same manifest is safe (keyed adds dedupe, duplicate comments are skipped, an
unchanged status records no event), so a network blip on `--remote` is recoverable
by simply applying again.

---

## Worked example

**`handoff.md`:**

```markdown
# Sprint handoff

## In progress
- [ ] TASK-042 Fix coupon totals — after-tax done, pre-tax left (commit abc1234)

## Done
- [x] TASK-043 Add webhook retry #area:webhooks

## Backlog
- [ ] Bug: checkout crashes on null coupon #area:checkout
- [ ] Add CSV export to reports
- [ ] Refactor the mailer — tech debt
```

**→ manifest:**

```json
{
  "operations": [
    {"op": "update", "code": "TASK-042", "status": "in_progress", "commit": "abc1234",
     "comments": [{"body": "after-tax done, pre-tax left"}]},
    {"op": "update", "code": "TASK-043", "status": "done", "labels": ["area:webhooks"]},
    {"op": "add", "ref": "n1", "title": "Checkout crashes on null coupon",
     "type": "bug", "priority": "medium", "labels": ["area:checkout"]},
    {"op": "add", "ref": "n2", "title": "Add CSV export to reports", "type": "feature", "priority": "low"},
    {"op": "add", "ref": "n3", "title": "Refactor the mailer", "type": "debt", "priority": "low"}
  ]
}
```

The backlog adds land in **triage** (no status set → the default); the
in-progress task keeps moving, not force-closed; only the explicitly-checked
`TASK-043` goes to `done`.
<!-- dispatch:if code_lane -->
Each add is code work, so each also carries `"lane": "{{ code_lane }}"`.
<!-- dispatch:endif -->

---

## See also

- **`{{ package_path }}/MIGRATING.md`** — the full migration guide: batch-vs-import path
  choice, the flatten / vocab-map / provenance conventions, and the full-history
  `dispatch:import` backfill (backdated dates, original authors, `--no-notify`).
- `php artisan dispatch:schema` — the contract as data (the `batch` **and** `import` keys)
- `.claude/skills/dispatch-track/SKILL.md` — local `dispatch:batch` + the verb loop
- `.claude/skills/dispatch-agent-session/SKILL.md` — driving `dispatch:batch --remote`
  against production, including the `batch` scope
<!-- dispatch:slot see-also -->
