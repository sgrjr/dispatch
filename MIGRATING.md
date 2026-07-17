# Migrating a `todo.md` / checklist into Dispatch

You have an informal task file — a `todo.md`, a `todo.archive.md`, meeting notes
with action items — and you want it in the Dispatch backlog so you work the board
instead of the file. This guide is for the person (or agent) writing the
**md → JSON translator**: it names the two import paths, tells you which to pick,
and gives the conventions so nothing in your file is lost or duplicated.

> **The shape is the contract, not this prose.** `php artisan dispatch:schema`
> dumps the authoritative `import` and `batch` shapes as data — target those.
> This guide is the *how* and *why*; the schema is the *what*.

---

## 1. Pick the path: `batch` (memorialize) vs `import` (backfill-with-history)

There are two curated, additive bulk paths. They differ on **one axis: history.**

| | `dispatch:batch` | `dispatch:import` |
|---|---|---|
| **Use for** | ongoing work — file new items + record the status a live run *actually reached* | a historical file where completed items must keep their **original dates, decisions, and commit SHAs** |
| **Timestamps** | always "now" | **backdated** `createdAt`/`updatedAt`, and per-comment `createdAt` + author |
| **`done` items** | can set `status: done`, but the completion **date is today** | `status: done` **with a completion-dated `status_change` comment** — the real history |
| **Original authors** | no (agent/null submitter) | yes — `submitter`/`author` resolved by email |
| **Idempotency** | `key` on `add` ops | `code`, or a codeless `key` (→ `dedupe_key`) |
| **Shape** | `{operations:[…]}` | `{tasks:[…], labels:[…]}` |
| **Front-half skill** | `dispatch-batch-migrate` | this guide + a translator you write |

**Rule of thumb:** memorializing *this sprint's* run → **batch**. Backfilling
*years of `todo.archive.md`* so the board shows real origination/completion dates
→ **import**. When in doubt and history matters, use import — it's the only path
that can place a task in the past.

The conventions in §3–§6 apply to **both** paths; the worked example in §7 uses
`import` because history is the harder, less-documented case. One behavioural
difference to hold onto: **`batch` attaches labels additively** (it never strips
what's there), while **`import` sets a task's labels as the authoritative set**
(a sync) — correct for a migration, where the md *is* the source of truth, but it
means you list *all* of a task's labels together in that task's `labels` array.

---

## 2. The import document at a glance

```jsonc
{
  "labels": [                       // optional — upserted by name FIRST, so tasks can reference them
    {"name": "kind:INFRA", "color": "#64748b", "description": "original todo.md kind"}
  ],
  "tasks": [
    {
      "key": "…sha1(file|first-line)…",   // codeless idempotency handle (see §6) — OR a real "code"
      "title": "…",
      "type": "chore", "priority": "medium", "status": "done",
      "labels": ["source:todo-md", "kind:INFRA", "size:M", "area:billing"],
      "submitter": "someone@example.com", // resolved to a user id by email; unresolved ⇒ null
      "createdAt": "2024-03-01T09:00:00Z",// backdated origination
      "comments": [
        {"body": "Shipped in a1b2c3d", "eventType": "status_change",
         "createdAt": "2024-03-14T17:00:00Z"}   // ← "when done" lives here
      ],
      "context": {"source": {"file": "todo.archive.md", "line": 142}}
    }
  ]
}
```

Run it:

```bash
php artisan dispatch:schema                       # confirm the import shape (the `import` key)
php artisan dispatch:import backlog.json --dry-run # validate; reports created/updated/skipped, writes nothing
php artisan dispatch:import backlog.json --no-notify # apply a bulk backfill quietly (see §below)
```

- **`--dry-run`** runs the whole import inside a rolled-back transaction and prints
  the summary (`tasks_created / tasks_updated / tasks_skipped / comments_added / …`).
  Always dry-run first and eyeball the counts.
- **`--no-notify`** suppresses the per-row "request received" receipt **and** any
  reactive automation a host has wired (the `EventNotifier` binding). A historical
  backfill should never email hundreds of submitters or trigger orchestration once
  per archived task — always pass it for a real migration.

---

## 3. Input contract — what becomes a task (and what doesn't)

Your translator decides, line by line, what is a task. The import is a
**garbage-in guard, not a parser** — it upserts what you hand it and *skips +
counts* anything without an identity, but it can't tell a heading from a task.
So the contract is yours to enforce:

- **Only actionable lines become tasks** — a checkbox (`- [ ]` / `- [x]`), a
  bullet that names a unit of work, or a numbered item.
- **Headings, prose, horizontal rules, and Q&A blocks are NOT tasks.** Use them
  as *context*: a heading like `## Billing` becomes an `area:billing` label; a
  date heading rides along in `context`. Don't emit a task per heading.
- **Every emitted task needs an identity** — a `code` or a `key` (§6). A row with
  neither is skipped and tallied in `tasks_skipped`. **Check that count** after a
  dry-run: a surprising number means your line classifier is dropping real work
  (or emitting junk rows) — fix the translator, don't ignore it.

A messy file can't silently corrupt the board — but only because you validated
the counts, not because the importer guessed.

---

## 4. Flatten convention — nested sub-items

A Dispatch task is **flat**: there is no `parent_id` / subtask hierarchy (a
deliberate, locked design choice — see ROADMAP §18 M5). An md that nests
`  - [x]` sub-items under a parent needs a flattening rule. Two good options:

- **Fold into the parent (default, best for checklist-y sub-items).** Render the
  sub-items as a checklist inside the parent's `description`:

  ```
  Migrate the billing tables

  - [x] accounts
  - [x] invoices
  - [ ] line_items
  ```

  One task, full detail, no artificial children.

- **Emit as separate tasks linked to the parent (best when sub-items are
  substantial and independently trackable).** Give each child its own task and
  link it back with a `parent:<code>` label **or** `context.parent = "<code>"`.
  (There is no relational parent column; the label/context is the convention a
  reader/board filter keys on.)

Pick per-parent by how much the sub-items deserve to be tracked on their own.

---

## 5. Vocabulary map — a rich md taxonomy → Dispatch's fixed vocab

Dispatch's vocab is intentionally narrow:

| Field | Allowed values |
|---|---|
| `type` | `bug` · `feature` · `chore` · `debt` · `verify` |
| `status` | `triage` · `open` · `in_progress` · `verifying` · `done` · `declined` |
| `priority` | `blocker` · `high` · `medium` · `low` |

A real `todo.md` carries far more — kinds like `BUG/NEW/INFRA/POLISH/UPGRADE/
PERF/PARITY/VALUE-ADD/INVESTIGATE`, states like `PARTIAL/PROD-STUBBED/DEFERRED/
WIP`, sizes `S/M/L`, `#domain` tags, `urgent`/`low` triage. **Don't discard the
richness — map to the nearest native field and preserve the original as a label**
so it's still filterable and nothing is lost:

| md signal | → native field | + preserve as label |
|---|---|---|
| kind `BUG` / `fix` / `crash` | `type: bug` | `kind:BUG` |
| kind `NEW` / `feature` | `type: feature` | `kind:NEW` |
| kind `INFRA` / `refactor` / `docs` / `polish` | `type: chore` | `kind:INFRA` (etc.) |
| kind `PERF` / `security` / `tech debt` / `UPGRADE` | `type: debt` | `kind:PERF` (etc.) |
| kind `VERIFY` / `smoke test` | `type: verify` | `kind:VERIFY` |
| state `PARTIAL` / `WIP` | `status: in_progress` | `state:PARTIAL` |
| state `DEFERRED` / `wontfix` | `status: declined` (if truly dropped) | `state:DEFERRED` |
| state `DONE` / `[x]` | `status: done` | — |
| size `S` / `M` / `L` | *(none)* | `size:M` |
| `#area-tag` / section heading | *(none)* | `area:<tag>` |
| `urgent` / `!!` / `(high)` | `priority: high` | — |

Labels are free-form, so `kind:*` / `state:*` / `size:*` sit right alongside your
domain labels — a board/list filter on `kind:INFRA` then recovers the original
taxonomy on top of the native `type`. **Define every label you reference in the
top-level `labels[]`** (import upserts those first): a task label name that isn't
declared there — and doesn't already exist — is silently dropped, so a typo loses
the label rather than erroring.

**If you'd rather have richer *native* types** than lose them to labels:
`dispatch.workflow.types` (and `.statuses` / `.priorities`) is **config-extensible**
— a host can add its own vocab and skip the `kind:*` labels. See ROADMAP §7 and
`WorkflowConfigTest`. Most migrations keep the narrow native vocab + labels; extend
the config only if the richer set is worth carrying forever.

---

## 6. Provenance convention — where a task came from (and idempotency)

Stamp every migrated task with its origin. This is what makes a re-import safe
and an imported task auditable back to its source line:

- **`label: source:todo-md`** on every migrated task — one filter shows the whole
  migrated cohort (and separates it from natively-filed tasks).
- **`context.source = {file, line, imported_at}`** — the exact origin, for audit.
- **`key = sha1(file | first-line-text)`** — the **idempotency handle**. When a
  task has no Dispatch `code`, the import upserts by this `key` (persisted as
  `dedupe_key`), so **re-running the migration updates in place instead of
  duplicating**. Keep the input to the hash stable (the source file + the task's
  first line) and a second run is a no-op-or-update, never a duplicate.

> The `key` is why a codeless md file is re-runnable at all — without it, the
> importer had to skip codeless rows. Compute it deterministically and your
> translator is safe to run, fix, and run again.

---

## 7. Worked example (import, full history)

**`todo.archive.md`:**

```markdown
## Billing — 2024 Q1

- [x] INFRA: migrate billing tables to the new schema (M) #billing
      — shipped in a1b2c3d, 2024-03-14
  - [x] accounts
  - [x] invoices
  - [ ] line_items (deferred to Q2)
- [ ] PERF: invoice PDF render is slow (high) #billing
```

**→ import JSON** (translator output):

```json
{
  "labels": [
    {"name": "source:todo-md"}, {"name": "kind:INFRA"}, {"name": "kind:PERF"},
    {"name": "size:M"}, {"name": "state:PARTIAL"}, {"name": "area:billing"}
  ],
  "tasks": [
    {
      "key": "a1f9…(sha1 of file|'INFRA: migrate billing tables…')",
      "title": "Migrate billing tables to the new schema",
      "type": "chore", "status": "done",
      "labels": ["source:todo-md", "kind:INFRA", "size:M", "area:billing"],
      "description": "Migrate billing tables to the new schema\n\n- [x] accounts\n- [x] invoices\n- [ ] line_items (deferred to Q2)",
      "createdAt": "2024-01-08T00:00:00Z",
      "comments": [
        {"body": "Shipped in a1b2c3d", "eventType": "status_change", "createdAt": "2024-03-14T00:00:00Z"}
      ],
      "context": {"source": {"file": "todo.archive.md", "line": 3}}
    },
    {
      "key": "b7c2…(sha1 of file|'PERF: invoice PDF render is slow')",
      "title": "Invoice PDF render is slow",
      "type": "debt", "priority": "high", "status": "triage",
      "labels": ["source:todo-md", "kind:PERF", "area:billing"],
      "context": {"source": {"file": "todo.archive.md", "line": 8}}
    }
  ]
}
```

Note the choices: the INFRA item is **flattened** (sub-items folded into the
description, §4); the kind/size are **preserved as labels** on top of native
`type` (§5); the done item keeps its **backdated origination** and a
**completion-dated** `status_change` comment (§1); both carry **provenance +
a stable key** (§6). Apply with `--dry-run` then `--no-notify` (§2).

---

## 8. See also

- `php artisan dispatch:schema` — the authoritative `import` **and** `batch` shapes as data.
- `.claude/skills/dispatch-batch-migrate/SKILL.md` — the guided **batch** front-half (additive/now).
- `README.md` §8 (AI / remote agent) — `dispatch:import` / `dispatch:batch` in context.
- `UPGRADING.md` — clear stale caches after upgrading before a migration run.
