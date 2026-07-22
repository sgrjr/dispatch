# Upgrading `sgrjr/dispatch`

## After upgrading the dispatch package

The package's **routes and config are read from `vendor/` at runtime**, so an
upgrade has no effect while stale caches remain. On every host, after
`composer update sgrjr/dispatch`:

```bash
php artisan optimize:clear   # clears config + route + compiled/view/event caches
# if you deploy with caching enabled, rebuild after:
php artisan config:cache && php artisan route:cache
```

Then, on any host that serves or drives the agent API, **verify the agent config
resolved as intended**:

```bash
php artisan dispatch:doctor        # add --strict to fail CI on warnings; --json for machines
```

`dispatch:doctor` compares the live/published `dispatch.agent.*` against the
package defaults and names exactly the drift the cache layers below cause — a
verb missing from `agent.verbs`, an absent `bootstrap_secret` / `remote.*`, a
still-cached config — so you catch it here instead of via a downstream
`403 not scoped` / `401` / `503`. It exits non-zero on an error (e.g. no
bootstrap_secret in production).

Three cache layers can each **silently mask** a dispatch upgrade. The agent API
(`§20`) is especially prone to it, because both its routes/middleware and its
`bootstrap_secret` are cache-frozen:

- **Config cache** (`bootstrap/cache/config.php`) — freezes `config/dispatch.php`
  values (`agent.bootstrap_secret`, `agent.remote.*`, TTLs). Stale → a wrong or
  absent secret, or "No agent remote configured."
- **Route cache** (`bootstrap/cache/routes-*.php`) — freezes the route→middleware
  mapping and **skips the runtime route loader** in `DispatchServiceProvider`, so
  `routes/agent.php` changes stay invisible. Stale → old middleware gating (e.g. a
  poll endpoint still bootstrap-gated after an upgrade that moved it out).
- **OPcache** (especially `opcache.validate_timestamps=0`, common in production)
  — serves old compiled PHP until reset. `php artisan *:clear` does **not** reset
  it; recycle the web server / php-fpm / IIS app pool.

Quick diagnosis:

- A `401` / "Invalid bootstrap secret" right after rotating
  `DISPATCH_AGENT_BOOTSTRAP_SECRET` is almost always a **stale config cache** —
  `php artisan config:clear` and retry.
- An agent route whose middleware seems unchanged after an upgrade is a **stale
  route cache** (or OPcache) — `php artisan route:clear`, then recycle the app
  pool if it persists.
- Not sure which layer bit you? `php artisan dispatch:doctor` names the drift
  directly (missing verb, unset secret, still-cached config) instead of leaving
  you to infer it from a `403`/`401`/`503`.

## v0.7.0 — label kinds & focus steering + `backburner` status + multi-select board/list filters

- **Two new migrations** — `dispatch_labels.kind` (the per-label facet column)
  and `dispatch_focuses` (saved steering lenses). Run `php artisan migrate` (they
  load from the package automatically; publish with `--tag=dispatch-migrations`
  only to edit them in your own `database/migrations/`). Without them the label
  kind facet and Focus steering have no storage.
- **Focus steering on `next`/`claim`.** `dispatch:next` and `dispatch:claim`
  (CLI **and** the agent API) now surface the top-ranked **active** Focus's
  matches first, falling through to lower-ranked focuses and then the unsteered
  base — it steers, never starves. It is **default-on but inert with zero
  focuses**: no active focus ⇒ identical ordering to before. `--no-focus`
  (`?no_focus=1`) bypasses it for a call; `dispatch:queue` is not steered and
  claim-by-code ignores steering.
- **`dispatch:session:status` exit-code change.** The old zero-state (no token
  and no pending request) exited **1**; it is now a three-state exit-**0** probe
  — ACTIVE / DROPPED / NONE all exit 0 and name the next verb. A genuinely
  pending request still polls, and denied/revoked/expired still exit 1. **Update
  any script that asserted `session:status` fails when no session exists.**
- **Claim bridge template changed.** `dispatch:claim` now echoes a
  ready-to-paste close command that includes `--commit=<sha>` (plus
  `--result-file` and `--with-metrics --since=<claimed_at>` placeholders) on the
  stderr side channel. Anything that scraped the old bridge text should re-read
  it.
- **Meta labels demoted off cards/rows.** `source:*` / `kind:*` (any `meta`-kind
  namespace) no longer render on board cards or list rows — a **visual change**
  only; the detail view still shows them, and elevated labels (`area:*` /
  `epic:*`) now lead.
- **`Label::isEpic()` removed.** There is no special epic type — an epic is now a
  single-label Focus (an `epic:<slug>` elevated label plus a Focus constrained to
  it). No known consumers.
- **New config keys** `dispatch.labels.namespace_kinds` and
  `dispatch.models.focus`. In-code fallbacks cover an unpublished or older
  `config/dispatch.php` (the shipped `area/epic → elevated`, `source/kind → meta`
  map and the package `Focus` model), so nothing errors — **re-publish, or add
  the keys, to pin/customize them** (same shallow-`mergeConfigFrom` trap as the
  other blocks here).
- **Per-approval session TTL.** The Agent Sessions approve row gains a
  session-length select (Default = the configured `agent.session_ttl`, presets
  1h / 3h / 8h / 24h) — no migration or config change required.
- **Agent contract additive keys.** The `--json` summary shape gains
  `attachment_count`; the full shape gains an `attachments[]` array and a
  per-comment `attachment_count`. **Additive — existing parsers are unaffected**
  (signals only: no fetch URL, binaries never travel the agent API).
- **New default status `backburner`** sits between `verifying` and `done`:
  parked — consciously not actionable now or anytime soon, or code-done but
  blocked on an external date — distinct from `triage` (unprocessed) and
  `declined` (rejected). No migration (status is a plain string), but **hosts
  with a published `config/dispatch.php` must add `'backburner'` to
  `workflow.statuses` themselves** — the published array wins wholesale over
  the package default (same shallow-merge trap as the `batch` verb below), and
  without it the board column, dropdowns, and `--status=backburner` validation
  simply don't know the value. Park with `dispatch:done <code>
  --status=backburner`; unpark with `--status=open` (or `triage`/`verifying`).
  Backburner tasks are excluded from `dispatch:next`/`dispatch:queue` defaults,
  the `--count` census, claiming, and staleness nagging.
- **Board/list filter URLs changed shape.** The type/priority/label filters are
  now multi-select checkbox groups, so their query params went from scalar
  (`?type=bug`) to arrays (`?types[0]=bug&types[1]=chore`) — note the plural
  names. Old bookmarked filter URLs aren't errors; they simply load the
  unfiltered (all-selected) view.

## v0.6.0 — sticky remote + one-shot commissioning (client behavior changes)

Two client-side defaults changed so an agent needs less ceremony (and less
doc) to drive the pipeline. Both have escape hatches; neither changes the
server surface, so mixed v0.5.x/v0.6.0 client-server pairs keep working
(`claimed_at` in the claim envelope and the zero-filled `queue --count`
census are additive).

- **Sticky remote.** While an approved agent-session token exists (the dotfile
  is created at approval and deleted on `session:end`/`401`), the eight loop
  verbs (`next/queue/show/claim/add/note/done/batch`) target the **remote by
  default** — no `--remote` flag needed. Every sticky call announces
  `→ remote: <host>` on stderr, and `--local` overrides per call. If a stray
  token ever surprises you, `dispatch:session:end` clears it; opt out
  host-wide with `dispatch.agent.remote.sticky=false`
  (`DISPATCH_AGENT_STICKY=false`). With no token present nothing changes —
  verbs act locally exactly as before.
- **`dispatch:session:request` with no `--scope` now really requests the full
  allowlist.** The client used to always send the `scopes` key, so omitting
  `--scope` posted `scopes: []` — which the server (correctly) treats as
  request-NOTHING, i.e. a deny-all session. Fixed: an omitted `--scope` omits
  the key, and the approver grants the host allowlist — what the option help
  always claimed. Pass `--scope=...` only to deliberately narrow a session.
- **`dispatch:session:request --wait`** folds request → show code → poll →
  collect-token into one command (it delegates to the `session:status --wait`
  loop on your behalf).
- **Dropped sessions fail loud + `dispatch:session:refresh` (client behavior
  change).** Previously, when a session token died mid-run (401, or a
  denied/revoked/expired poll), sticky resolution silently fell back to the
  **local dev DB** — production tasks looked deleted and local throwaway tasks
  read as the board (observed in the field as apparent data loss). Now an
  involuntary token death writes a **drop marker** beside the dotfile
  (`<token_path>.dropped`), and while it stands bare verbs **exit non-zero
  with the recovery paths** instead of quietly serving local data. Resolve it
  with the new **`dispatch:session:refresh --wait`** — re-requests a session
  with the same identity/scopes (persisted in the dotfile since this
  version), flagged as a renewal in the purpose the approver sees — or
  acknowledge with `dispatch:session:end` (restores local-by-default);
  `--local` always overrides per call. Related hardening: a re-`session:request`
  no longer resurrects a stale `token` key from the old dotfile (that cascade
  could wipe a fresh pending request on the next 401); a `429` is now
  answered with back-off guidance instead of looking like token trouble; a
  token past its stored `expires_at` warns and names `session:refresh` before
  the 401 interrupts the loop; and `dispatch:doctor` reports a lingering drop
  marker. Purely client-side — no server or schema change.
- **Session-anchored metrics: `dispatch:session:end` now records whole-session
  run metrics by default.** The client computes tokens/cost/duration from its
  local Claude Code transcript (window: token stored → now) and folds them into
  the end call; the server stores them on the session row (`metrics` +
  `ended_at` columns — **run your migrations**: a new
  `add_metrics_to_dispatch_agent_sessions_table` migration ships with this).
  The staff Agent Sessions page gains a **"Recently ended"** section showing
  each finished session's verdict — previously the row (and any metrics signal)
  vanished the moment the session ended. Opt out per call with `--no-metrics`;
  when no transcript can be located the session still ends, just without
  metrics (a warning names the fix). Per-task `done --with-metrics` is
  unchanged and remains the fine-grained per-task view; the session total is
  now the load-bearing default. A v0.6.0 client against a v0.5.x server keeps
  working — the extra `metrics` key on `session/end` is simply ignored there
  (`$request->validate` tolerates it; nothing is stored).
- **Estimated human touch-time (derived, v1).** The "Agent run" card and the
  `dispatch:show` block gain an "est. human time (v1)" figure — a deterministic,
  versioned estimate of the focused human minutes the run's workflow would have
  taken, derived at **read time** from the stamped signals (task type, tool mix,
  subagents, capped wall-clock). It is never stored, so historical tasks
  re-derive whenever you tune the coefficients in `metrics.touch_time`. **Hosts
  with a previously published `config/dispatch.php` must add that block (or
  re-publish) to see it** — absent config hides the figure and nothing errors
  (shallow `mergeConfigFrom`, the same trap as GAP 3/6).
- **Agent session TTL default is now 3 hours (was 1).** The 1h default
  force-expired legitimate long sessions, and an expiry mid-run `401`s the
  closing `dispatch:session:end` call — so the longest runs were exactly the
  ones that lost their session metrics. The TTL is a backstop, not the
  lifecycle (`session:end` is how sessions are meant to end); stricter hosts
  set `DISPATCH_AGENT_SESSION_TTL` as before, and a published config's
  `session_ttl` value still wins wholesale.

## Enabling the batch verb (`dispatch:batch --remote` / `POST agent/batch`)

The batch memorialize verb is gated by the server's `agent.verbs` allowlist. If
you **published `config/dispatch.php` before this verb existed**, your host's
`agent` block wins wholesale over the package default (shallow `mergeConfigFrom`,
the same trap as GAP 3), so `batch` is absent from `agent.verbs` and **no session
can ever be granted the `batch` scope** — a `--remote` batch call will `403`.
(`php artisan dispatch:doctor` flags exactly this as a `verbs` warning.)

To enable it on the server, either re-publish the config and re-apply your
customizations:

```bash
php artisan vendor:publish --tag=dispatch-config --force
```

or just add `'batch'` to the `agent.verbs` array (and, optionally, the
`agent.batch.max_operations` cap) in your existing `config/dispatch.php`. Then
clear the config cache (see above). The **local** `dispatch:batch <file>` path
needs none of this — it doesn't go through the session/scope layer.

## Re-publishing skills after an upgrade

The Claude Code skills ship in the package but are used from the host's own
`.claude/skills/`, so they only pick up package changes when re-published:

```bash
php artisan vendor:publish --tag=dispatch-skills --force
```

`--force` **overwrites** the host's copies. If you have **hand-edited** a
published skill — e.g. baked your production host and paths into
`dispatch-agent-session/SKILL.md` — `--force` discards those edits. Two safe
options:

1. **Keep your customized copy:** skip `--force`. Without it, existing files are
   left untouched (you keep your edits but miss the package's newer generic
   content). Re-run with `--force` only when you're ready to re-apply your
   host-specifics.
2. **Re-sync then re-customize:** `--force`, then re-apply your host/paths on top
   of the refreshed package version.

Before a `--force`, it's worth diffing your published copy against the vendored
package copy so you know exactly what you're overwriting:

```bash
diff -u vendor/sgrjr/dispatch/.claude/skills/dispatch-agent-session/SKILL.md \
        .claude/skills/dispatch-agent-session/SKILL.md
```

Generic, reusable improvements you make to a published skill are worth sending
back upstream to the package so the next `--force` carries them forward instead
of pulverizing them.
