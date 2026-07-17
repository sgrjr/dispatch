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
