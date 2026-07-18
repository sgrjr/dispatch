---
name: dispatch-inbox-absorb
description: Absorb the next wave of package-level findings from the centerpoint → sgrjr/dispatch INBOX (`staff/storage/notes/dispatch-remote-agent-gaps.md`) into this repo's `ROADMAP.md` §18, then reset the inbox to its stub. Use when asked to "absorb the inbox", "absorb the dispatch gaps / the next wave", "review the remote-agent-gaps file", "cross-check the inbox against the code and memorialize it", "process the dispatch inbox findings", or "clear the inbox into the roadmap". Runs the full pattern: read the new wave → VERIFY every claim against the actual code/docs/skills (never assume it true) → triage + tag → memorialize as a new §18 backlog group in house style → reset the inbox to its cleared stub → summarize with a verdict table and surface genuine decisions for the operator. Do NOT use to *work* the production backlog (that's `dispatch-agent-session`) or to author package features directly.
---

# Absorb the centerpoint inbox → `sgrjr/dispatch` ROADMAP

This repo keeps a **one-way inbox** that the centerpoint host writes package-level
findings into as it runs Dispatch in production. On its own cadence, `sgrjr/dispatch`
**absorbs** the accumulated notes into `ROADMAP.md` §18 as scheduled work, then
**resets the inbox to a stub**. This skill is that absorb protocol — run it the same
way every time so the roadmap stays consistent and nothing is lost or over-trusted.

**The two files this operates on** (paths drift across machines — confirm they exist,
per the ROADMAP's own trust/verify doctrine):

| Role | Location | Repo |
|---|---|---|
| **Inbox (source)** | `staff/storage/notes/dispatch-remote-agent-gaps.md` | centerpoint |
| **Roadmap (target)** | `ROADMAP.md` → **§18** | this package (cwd) |

You will typically be run from the **dispatch** repo (cwd), reading across into the
centerpoint checkout. Both edits (roadmap write + inbox reset) happen in one pass.

---

## Prime directive — VERIFY, don't absorb on faith

The inbox is written by an agent mid-run. Its claims are **leads, not facts**. Before
anything reaches the roadmap:

1. **Cross-check every claim against the actual code, docs, and skills.** Open the
   cited files. Confirm the class / method / config key / line still exists and does
   what the note says. Capture your own `file:line` evidence — do not copy the note's
   line refs (they drift). A claim that doesn't survive this becomes **invalid** or
   **partial**, said plainly, not memorialized as fact.

2. **Treat agent friction as a design signal even when a specific claim is wrong.**
   The point of the inbox is that a *capable* agent struggled to finish the task 100%
   as expected — that is evidence of a **weak seam in this package**, which must be
   practically robust. So when a claim is factually off but the agent clearly hit real
   resistance, find the *actual* seam underneath and memorialize **that**. Don't
   dismiss the whole note because one detail missed.

3. **Prefer the code as ground truth over the roadmap's own prose.** §1–§17 can be
   stale; the shipped code is authoritative (RESUME-HERE doctrine). If the note and a
   "shipped" checkbox disagree, read the code and believe the code.

---

## Step by step

### 1. Read the new wave

Read the inbox file end to end. The stub header carries a numbered ledger of waves
already absorbed; the **new** material is under `## New notes …` or a dated
`## Nth wave …` section below it. If that section is empty, there's nothing to absorb
— say so and stop.

### 2. Cross-check every claim (the load-bearing step)

For each raw gap/finding, open what it cites and verify against code/docs/skills:

- **Package code** — `src/**` (commands, controllers, services, presenters), `config/dispatch.php`, `routes/*.php`.
- **Docs** — `README.md`, `MIGRATING.md`, `UPGRADING.md`, and `ROADMAP.md` itself.
- **Skills** — `.claude/skills/**/SKILL.md` (esp. `dispatch-agent-session`, `dispatch-track`), which ship with the package and are frequently what a `[skill]` finding is really about.

Use `Grep`/`Read` (and `git log`/`git tag` for "did this actually ship") — spawn parallel
`Explore` agents when a wave is large and the claims fan across many files. Record, per
claim: **verdict** (valid / partial / invalid), **tag** (`[pkg]` code · `[skill]` a
shipped SKILL.md · `[doc]` README/guide · `[prompt]`/`[host]` = not a package change),
and **your own** `file:line` evidence.

### 3. Triage

- Split **cheap doc-only wins** (a skill/README edit the code already supports) from
  **package design decisions** (new command/route/schema/API surface). Say which is which.
- Fold genuinely non-package items (`[prompt]` operator hygiene, `[host]` centerpoint
  bugs) into a single **Context** line, not their own backlog items — but only after
  confirming they're truly out of the package's control.
- Collapse duplicates/overlaps into one item; note the sub-claims it subsumes.

### 4. Memorialize — a new §18 backlog group (house style)

Insert a new group into `ROADMAP.md` §18, **after** the most recent wave group and
before `### 🧩 Product-completeness gaps`. Match the existing 🛰️/📜/🧰/📊 groups exactly:

- **Header:** `### <emoji> <Theme title> (absorbed from the centerpoint inbox — Nth wave, YYYY-MM-DD)`.
  Scan §18 for the emojis already in use and pick a **fresh, thematically-apt** one.
- **Source `>` note:** where it came from + the run's task context; **"all claims
  re-verified against code (YYYY-MM-DD)"** and the verdict in aggregate; the tag legend;
  and which **prior-wave escape hatches held up** (validations worth recording, not just gaps).
- **Items:** unchecked `- [ ]` (this is future work), one per triaged finding, keyed
  `W<N>-<k>` (N = wave number). Each carries: a bold one-line claim with
  `[tag · severity — was G<x>]`, then **symptom**; **root cause with your own
  `file:line` evidence**; then **`Fix:`** the decided direction (name the preferred
  option when there are several). Mark severity for the standouts (⭐ HIGH).
- Sequence dependent items ("fix the path before flagging its absence").

Scaffold:

```markdown
### <emoji> <Theme> (absorbed from the centerpoint inbox — Nth wave, YYYY-MM-DD)

> Source: the same `dispatch-remote-agent-gaps.md` **inbox**, Nth wave — <run context>.
> **Every claim below was re-verified against code (YYYY-MM-DD) and found <VALID/…>.**
> Tags: **[pkg]** = package code, **[skill]** = a shipped SKILL.md, … . Prior-wave
> escape hatches that **held up** (validations, not gaps): <list>.

- [ ] **W<N>-1 [pkg · ⭐ HIGH — was G<x>] — <one-line claim>.** <symptom>. Verified: <root cause w/ file:line>. **Fix:** <decided direction; preferred option first>.
- [ ] **W<N>-2 [skill — was G<y>] — <claim>.** …
- **Context (not a package item — was G<z> [prompt]):** <why it's out of scope; which W-items cover it structurally>.
```

Do **not** bump a version, edit the RESUME-HERE "Latest" line, or touch §1–§17: this
memorializes backlog only. Version/reference sweeps ride the **release ritual** at tag
time, not an absorb.

### 5. Reset the inbox to its stub

Rewrite the inbox file back to its cleared-stub form (rewriting the whole file is
cleanest):

- **Header:** `**Status: cleared (YYYY-MM-DD).** <N> waves have now been absorbed …`
  — increment the count, and **add one ledger line** for this wave in the numbered list
  (theme → the §18 emoji group it landed in; note the verdict and the headline seam,
  mirroring the existing entries' density).
- **Keep** the `## How to use this file (inbox protocol)` section verbatim.
- **Empty** the `## New notes / todos / repo feature requests (add below)` section back
  to just its `---` rule — ready for the next wave.

### 6. Summarize & surface decisions

Report back — do not just say "done":

- A compact **verdict table**: item · tag · verdict · your evidence.
- Call out the **headline seam** (the sharpest, most-actionable finding) and *why* it
  validates taking the agent's friction seriously.
- The **cheap-wins vs design-decisions** split.
- **Surface genuine forks for the operator.** Where a fix has a real branch the operator
  should own (build now vs defer, option A vs B, or "is this in scope?"), use
  `AskUserQuestion` — but only for decisions you can't reasonably default. Everything
  else: pick the sensible default, state it, and mark still-open forks as **TBD in the
  roadmap item** (per §13's open-decisions doctrine) rather than blocking.
- **Do not auto-commit.** The roadmap change is in the dispatch tree; the stub reset is
  in the centerpoint tree (two repos). **Offer** to commit (this repo commits straight
  to master), and offer to knock out the cheap doc-only wins now.

---

## Anti-patterns (don't)

- ❌ Absorb a claim without opening the file it cites. The inbox is leads, not facts.
- ❌ Copy the note's `file:line` refs into the roadmap. Re-derive your own — theirs drift.
- ❌ Dismiss a whole note because one detail was wrong. Find the real seam under the
  friction and memorialize that.
- ❌ Believe a "shipped ✅" checkbox over the code. Read the code; it's ground truth.
- ❌ Check the new §18 items as done. They're future work — leave them `- [ ]`.
- ❌ Bump a version, rewrite RESUME-HERE, or sweep §1–§17 during an absorb. That's the
  release ritual's job, at tag time.
- ❌ Leave the `## New notes` section populated after absorbing. Reset it to the stub.
- ❌ Auto-commit either repo. Summarize, offer, let the operator decide.
- ❌ Over-ask. Use `AskUserQuestion` for genuine forks only; default the rest and mark
  TBD.

---

## See also

- `ROADMAP.md` **§18** — the backlog this writes into; §13 — the open-decisions list to
  mark TBDs against; the RESUME-HERE trust/verify doctrine that governs the whole absorb.
- `.claude/skills/dispatch-agent-session/SKILL.md` — the *remote work* skill whose
  production runs generate most inbox findings (this skill absorbs its field notes).
- The inbox's own `## How to use this file (inbox protocol)` section — the host-side
  half of this contract.
