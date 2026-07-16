---
name: orchestrate-waves
description: Plan and build a LARGE multi-feature, multi-file change as an orchestrator/driver who coordinates cheap parallel subagents in dependency-ordered "waves". Use when a request spans many features that touch overlapping files (a backlog batch, a big refactor, a subsystem build), when you want parallelism without agents colliding on the same file, or when the user asks to "plan and orchestrate", "use waves", "commission/dispatch subagents", "act as orchestrator/driver", or "fan out the build". NOT for a single isolated change — just do that directly.
---

# Orchestrate a build in waves (plan → contract → parallel workers → audit)

This is a battle-tested workflow for turning a big backlog into shipped, tested,
committed code without agents stepping on each other. It was distilled from the
`sgrjr/dispatch` core-feature build (39→75 tests green across a foundation wave +
5 parallel surface agents).

## The two roles

- **Driver** = you, the strong session model. You own: the plan, the **shared
  contract**, the hardest/most load-bearing seam (auth, security, the coupled
  core), **every audit**, **every commit**, and resolving worker questions. You
  do NOT hand the correctness-critical core to a cheap worker.
- **Workers** = cheap `sonnet` subagents (`Agent` tool, `model: "sonnet"`). Each
  builds exactly **one disjoint file-set slice** and reports back. Workers never
  commit and never touch a file outside their set.

## The one rule that makes it work: disjoint file sets

**No two workers in a wave may touch the same file.** Organize a parallel wave
**by component/file-cluster, not by feature** — each worker owns one file cluster
(e.g. `TaskShow.php` + its blade + its test) and implements *every* feature that
touches those files. Anything shared by multiple workers (config, the service
provider, a shared trait/helper, a migration) is **not** a wave-1 file — it moves
into the foundation wave (or the driver writes it) so parallel workers only *read*
it. If two workers would need the same file, your decomposition is wrong.

## Phase 0 — Plan (in plan mode)

1. **Explore, read-only.** Launch up to 3 `Explore` agents in parallel to map the
   code: where each feature hooks in (file:line), existing patterns/utilities to
   reuse, the test harness. Read the central doc/backlog yourself.
2. **Decide the scope line** with the user. Use `AskUserQuestion` to lock genuinely
   open decisions *before* building (defaults, what's in/out, commit cadence).
   Recommend an option; don't survey.
3. **Write the plan file**: Context (why) · a feature→insertion-point table ·
   the **shared-contract block** (below) · the wave decomposition with the exact
   file set per worker · a verification section (how to test end-to-end). Then
   `ExitPlanMode`.

## Phase 1 — Author the shared contract (the single most important artifact)

Before any worker runs, **you** write a contract block and paste it **verbatim**
into every worker prompt so nothing drifts. It fixes the exact, shared vocabulary:
- new/changed **method signatures** and where they live (FQCN),
- **config keys** (with their in-code fallbacks),
- **DB columns / table names / migration names**,
- **enum values / constants**,
- any **shared helper's API** and how to call it.

If workers invent slightly different names for the same thing, integration breaks.
The contract is your insurance against that.

## Phase 2 — Wave 0: foundation (1 worker or the driver, then audit + commit)

Build the **tightly-coupled core everything consumes**: contracts/interfaces,
shipped defaults, model/schema changes, shared services, config, the service
provider wiring, shared helpers, and foundation tests. It's correctness-critical
and interdependent, so **one** agent builds it (or you do) and **you audit closely**
before committing. Nothing parallel starts until this is green and committed —
Wave 1 builds on its API.

Register forward references safely: e.g. list not-yet-created command classes in a
`class_exists`-gated loop so the provider boots before Wave 1 creates them.

## Phase 3 — Wave 1: surfaces (N parallel workers, disjoint sets)

Launch all workers **in one message** (multiple `Agent` calls) so they run
concurrently, `run_in_background: true`. Each prompt contains:
- the **shared contract** (verbatim),
- **its file set** (and "touch nothing else — siblings edit other files in parallel"),
- per-feature deliverables with current-state anchors from your exploration,
- the **worker rules** (below),
- **designated ⚠️ decision points**: "if X is unclear, STOP and return a specific
  question instead of guessing."

You're notified as each lands. Audit each, then run the **full suite yourself** as
the authoritative gate (a worker's mid-flight suite run is only a snapshot). Commit
the wave with an explicit file list once the combined tree is clean and green.

### Worker rules (paste into every worker)
- Edit/create **only** files in your set. Do **not** run git or commit.
- Read each target file fully first; match its style; every new config read has a fallback.
- Verify: lint each file (`php -l` / `node --check` / etc.) and run your own test; report results. Don't break the existing suite.
- If a ⚠️ decision point is genuinely unclear, **STOP and ask** — don't guess.
- Final message = a precise handoff: files changed, per-feature notes, verify results, any questions.

## Phase 4 — Wave 2: audit + docs + commit

Full suite green; lint clean; sweep the living doc/roadmap (check shipped items,
sync reference sections, resolve decisions); commit. Flag the human/runtime steps
you can't do (browser smoke test, re-publish assets, run migrations, push/tag).

## Driver discipline (what keeps the build honest)

- **Own the load-bearing seam yourself** — auth, security, the coupled core. Never
  delegate the part where a subtle mistake is catastrophic.
- **Audit against ground truth**, don't trust reports: `grep` for leftovers (old
  helpers that should be gone, duplicate sends), read the risky rewrites in full,
  confirm a behavior change matches an existing test rather than silently weakening it.
- **The full suite is the gate** — run it yourself after each wave.
- **Commit per verified wave** with explicit file lists and a clear message.
- **Leave unrelated working-tree changes untouched** — if a file changed that no
  agent (and not you) touched, investigate it and surface it; do NOT blindly stage it.
- **Don't push or tag** unless asked — those are outward-facing.

## Pitfalls learned (check these)

- **Deps may be absent** (`vendor/`, `node_modules`) — install before the first test run.
- **Test-harness gaps**: features needing a user model / real rows won't work under a
  bare package test env — provide a fixture + helper once, reuse across workers.
- **Shared-helper SQL**: a relation `pluck('id')` across a join is ambiguous — qualify it.
- **Testability of boot-time config** (e.g. throttle middleware read at route
  registration): prefer a mechanism that reads config at *request* time (a named
  rate limiter) so a test can flip it post-boot. Pre-solve this in the foundation.
- **CRLF/LF warnings** on Windows are benign.

## Dispatching the workers (mechanics)

```
Agent(subagent_type: "general-purpose", model: "sonnet", run_in_background: true,
      prompt: "<shared contract> + <this worker's file set + deliverables + rules>")
```
Send all of a wave's `Agent` calls in **one** message for true concurrency. Track
waves with `TaskCreate`/`TaskUpdate`. Audit each as it completes; do the combined
audit + full-suite run once all of a wave have landed.
