# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Status

The repository is **empty** — no commits, no code. Everything below describes the *target* architecture
agreed with the project owner, not files that exist. Until the scaffold lands, treat this as the spec:
build toward it, and update this file when a decision changes.

Build sequencing is **OpenSpec-first**: write an OpenSpec change (`openspec-propose`) and get it approved
before writing code. Do not scaffold ahead of an approved change.

## Product

Shorty-Nah is a self-hosted, private URL shortener. Single tenant, multi-domain, with a first-boot
setup wizard (OOBE) and deep branding control. Two redirect modes trade off speed against analytics depth:

| Mode | Behaviour | Analytics captured |
|---|---|---|
| `direct` | `302` straight to destination | Server-side only: IP-derived geo/ASN, UA, referer, timing |
| `interstitial` | Branded hold page runs a beacon, then navigates | Adds screen/viewport, timezone, language, connection type, prefers-color-scheme, engagement dwell |

`redirect_mode` is per-link with an instance-wide default. `direct` is the hot path and must never
regress: it is the only route allowed to skip the normal middleware stack.

## Stack

- **API** — Laravel 13, PHP 8.4, Octane on **FrankenPHP** worker mode
- **Web** — Next.js 16 App Router, React 19, TypeScript strict, Tailwind v4, pnpm
- **App data** — Postgres 17
- **Click events** — ClickHouse (high-write events + rollups)
- **Cache / queues / sessions** — Redis, queues driven by Laravel Horizon
- **Edge** — Caddy (TLS, on-demand certs for custom short domains, routing)
- **Geo/ASN** — MaxMind GeoLite2 `.mmdb`, read in-process, refreshed by `geoipupdate`

## Layout

```
apps/api/            Laravel
apps/web/            Next.js
docker/              Dockerfiles, Caddyfile, entrypoints
openspec/            Specs and changes (committed)
docs/                Internal dev notes (GITIGNORED — never commit)
compose.yaml         Production topology
compose.dev.yaml     Dev override
Makefile             Task entrypoint for everything below
```

`docs/` is deliberately git-ignored per owner instruction: internal development docs stay local, and
`CLAUDE.md` is the only agent-facing doc that ships. `openspec/` *is* committed — it is project history,
not scratch notes.

## Commands

All day-to-day work goes through `make`; targets wrap docker compose so host PHP/Node versions never matter.

```bash
make up                  # start the stack (dev override applied)
make down
make logs                # tail all services
make setup               # first-run: build, migrate, seed, ClickHouse schema
make sh                  # shell into the api container
make tinker
```

Database:

```bash
make migrate
make fresh               # drop + migrate + seed (Postgres only)
make ch-migrate          # apply ClickHouse schema — separate from Laravel migrations
```

Backend (inside `apps/api`, or `make sh` first):

```bash
composer test                                   # Pest suite
vendor/bin/pest --filter='redirects to destination'   # single test by name
vendor/bin/pest tests/Feature/RedirectTest.php  # single file
vendor/bin/pest --parallel
vendor/bin/pint                                 # format
vendor/bin/phpstan analyse                      # Larastan, level 8
```

Frontend (inside `apps/web`):

```bash
pnpm dev
pnpm build
pnpm lint
pnpm typecheck
pnpm test                       # Vitest
pnpm test -- src/lib/slug.test.ts
pnpm test:e2e                   # Playwright
```

## Architecture

### Redirect hot path

The single most performance-sensitive route. Required shape:

1. Resolve `slug` + host from **Redis only**. A cache hit must not touch Postgres.
2. Negative-cache unknown slugs (short TTL) so slug-scanning cannot hammer the database.
3. Push a raw click envelope onto the Redis `clicks` queue — fire-and-forget, never inline enrichment.
4. Return the redirect.

Cache invalidation is driven by model events on `Link`; never hand-expire keys from controllers.
Multi-domain means the cache key is `(host, slug)`, not `slug` alone.

### Click pipeline

```
redirect ─▶ Redis "clicks" queue ─▶ Horizon worker (batched)
                                      ├─ bot / prefetch filter
                                      ├─ GeoLite2 + UA parse
                                      └─ buffered batch INSERT ─▶ ClickHouse
                                                                    └─ AggregatingMergeTree MVs (rollups)
```

Dashboards read **rollups**, never raw events. Raw events are for drill-down and export only.

### Analytics accuracy

"Accurate" here means *not inflating counts*, and these rules are the reason the pipeline exists:

- Drop prefetches: `Sec-Purpose: prefetch`, `Purpose: prefetch`, `X-Purpose`, and all `HEAD` requests.
- Drop known bots by UA plus datacenter ASN from GeoLite2.
- Unique visitor = hash of `ip + ua + daily-rotating salt`. **Raw IPs are never persisted** — geo is
  resolved during enrichment and the IP is discarded.
- Dedupe a `(visitor_hash, link_id)` pair inside a short window to absorb double-fires.

### ClickHouse

Schema lives in `apps/api/database/clickhouse/` and is applied by a dedicated artisan command — Laravel's
migrator targets Postgres and must not be pointed at ClickHouse. Events use `MergeTree` ordered by
`(link_id, occurred_at)` with a TTL derived from the configured retention. The app reads through a
read-only ClickHouse user.

### Settings, OOBE, and branding

Runtime configuration lives in a Postgres `settings` table, cached in Redis, and is **not** in `.env`.
`.env` holds only infrastructure credentials.

First boot: the stack detects no `installed_at` and unlocks `/setup`. The wizard walks connectivity check
→ admin account → instance identity and primary domain → branding → analytics (MaxMind key, retention,
bot filtering) → registration mode → SMTP → finish, which marks the instance installed and permanently
closes `/setup`. `php artisan shortynah:install` is the headless equivalent for scripted deploys.

Registration mode is a setting, not a build flag: `closed` | `invite` | `open`.

Branding must apply **without a rebuild**. Settings emit CSS custom properties; Tailwind v4 `@theme inline`
maps its tokens onto those properties. Next.js SSRs the branding payload from a public config endpoint so
there is no unstyled flash. One theme only — light/dark are `prefers-color-scheme` plus a `[data-theme]`
override, never a theme system.

## Conventions

- **English only**, everywhere: code, identifiers, comments, commits, UI copy, docs.
- **Comment sparingly.** Comment *why*, never *what*. No file header blocks, no docblocks that restate a
  signature, no section-divider banners.
- **No placeholders.** Never ship `// ...`, `// TODO`, `// rest of the implementation`, a skeleton where a
  full file was asked for, or one example standing in for repeated logic. A partial file is a broken file.
- **No co-authorship trailers** on commits. No `Co-Authored-By:`, no generated-with footers.
- Never commit `docs/`.

### Octane gotchas

The app runs in a long-lived worker; these are real bugs, not style notes.

- No request state in singletons or static properties — it leaks across requests.
- `env()` only inside `config/` files. Config is cached; `env()` elsewhere returns null in production.
- Anything stateful bound into the container needs an explicit reset hook.
- Use `Octane::concurrently()` for genuinely parallel I/O.

### Testing

Pest for the API, with the redirect hot path and the click pipeline covered as feature tests including the
bot/prefetch rejection cases. Vitest for web units, Playwright for the OOBE wizard and the interstitial
beacon. TDD per `superpowers:test-driven-development` — test first.

### Frontend design

The visual direction is settled — see `openspec/changes/bootstrap-url-shortener/design.md`, Interface
direction. **Premium utilitarian minimalism**: warm monochrome, hairline `1px` borders, crisp `8–12px`
radii, effectively no shadows, asymmetric bento grid, colour as a scarce resource. The agency direction
(double-bezel enclosures, `rounded-[2rem]`, glass blur, ambient orbs, scroll reveals everywhere) was
considered and **rejected** for the dashboard; do not reintroduce it piecemeal.

Banned outright: Inter, Roboto, Helvetica, Open Sans. Lucide, Feather, Material, FontAwesome. Emoji in
markup, copy, or alt text. Gradients, neon, glassmorphism, `rounded-full` on large containers or primary
buttons, and Tailwind's stock `shadow-md`/`lg`/`xl`.

Use: a geometric sans for UI, a mono for slugs and counts and timestamps, an editorial serif sparingly for
setup and empty-state headings. Phosphor icons at one standardized weight.

### Motion

Motion is governed by frequency, and the answer is usually less than instinct suggests. A surface seen
100+ times a day gets **no animation** — the command palette and keyboard navigation open instantly. Press
feedback is 100–160ms, tooltips 125–200ms, dropdowns 150–250ms, modals and sheets 200–300ms. The setup
wizard, interstitial page, and empty states are the only surfaces allowed real flourish, and the only ones
with scroll-entry motion. The dashboard has none.

Non-negotiables: curves come from the token layer, never ad hoc; `transform` and `opacity` only
(`clip-path` sanctioned, `height` tolerated for accordions); never `scale(0)`; transitions rather than
keyframes for anything rapidly retriggerable; `transform-origin` at the trigger for popovers, centred for
modals; exit faster than enter and along the entry path; `transition: all` banned. Reduced-motion and
`@media (hover: hover) and (pointer: fine)` gating ship *with* an animation, never as a follow-up.
Reduced motion means gentler, not zero.

Reach for the cheapest tool that works: CSS transition → `@starting-style` → CSS animation → WAAPI →
`motion`. A fade does not justify a motion library.

### Libraries

Settled picks — don't substitute without a reason: base-ui (primitives), cmdk (palette), Sonner (toasts),
motion (springs/layout/exit/gesture), NumberFlow (changing numbers), recharts (charts), Virtuoso
(virtualization), zustand (state), clsx + cva (styling), next-themes (colour mode).

### Skills

Design and motion work in this repo is backed by vendored skills in `.agents/skills/`, surfaced through
symlinks in `.claude/skills/`. Both are git-ignored — they are local tooling, not project content. If
`.claude/skills/` symlinks are dangling, restore with
`cp -R /Users/imaleex/BuFootball/.agents/skills /Users/imaleex/Shorty-Nah/.agents/skills`.

Route by task: `minimalist-ui` and `impeccable` for interface work, `emil-design-eng` for polish and
component craft, `animate` for building a specific animation, `review-animations` before calling motion
done, `pick-ui-library` when a new capability needs a dependency, `ask-sonner` for toast trouble,
`dataviz` before writing any chart. `high-end-visual-design` and `gpt-taste` target marketing pages and
are **not** the direction here.

## Security

- Open-redirect protection on destinations, with an optional blocklist.
- Per-IP rate limiting on the redirect route.
- Link options: password, expiry, max clicks.
- Slugs are CSPRNG base62 (default length 7) with a reserved-word blocklist — a private shortener's slugs
  should not be enumerable. Custom slugs are validated against the same blocklist.
