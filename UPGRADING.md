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
