# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Status

Phases 1–15 of `openspec/changes/bootstrap-url-shortener` are implemented on
`feat/bootstrap-foundation`: **134 of 157 tasks**. The stack runs, a link
redirects, clicks land in ClickHouse enriched, reports answer from rollups, a
fresh instance can be walked from first boot to a signed-in dashboard, and an
operator can sign in and manage links, analytics, settings, branding and people
through the interface.

Phase 16 (authorization and audit) is next. One task of phase 14 is deliberately
open: 14.15, the audit log viewer, is blocked on the audit log itself, which
phase 16 builds — it closes there.

Operations checks live in `scripts/` and are wired into `make`: `check-secrets`
and `verify-schema` run in `make ci`; `verify-shutdown` and `verify-restore` need
a running stack and are run on demand. `verify-restore` is destructive by design —
it removes every volume, because anything short of that is not a restore test.

**The interface is server-first with client islands** — a page's initial data is
fetched by a server component forwarding the session cookie, and everything an
operator touches is a client component that calls the API and asks the router to
re-render. Filtering resolves in the browser against the page already sent. The
reasoning, and the two rejected alternatives, are in `design.md`.

Read `tasks.md` for what is done and what is not — several tasks carry a note
explaining what was verified and what was deliberately deferred, and those notes
are the record. `docs/HANDOFF.md` holds the session-level context behind them.

Work is OpenSpec-first: the spec and design are the contract, and a task's
verification method is part of the task. When implementation contradicts the plan,
update the artifact and say so rather than quietly diverging — several tasks have
been reworded for exactly that reason.

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

## Working environment

Facts that cost time to rediscover.

**Two `.env` files, on purpose.** The root one feeds Docker Compose; `apps/api/.env`
feeds Laravel when it runs on the host (tests, artisan). Inside containers the
Compose environment wins. Host-run tests need credentials in `apps/api/.env`, and
they are not interchangeable.

**Test environment invariants live in `tests/bootstrap.php`, not `phpunit.xml`.**
PHPUnit's `<env>` writes `putenv()` and `$_ENV` only; Laravel's `env()` reads
`$_SERVER` first, and under the CLI SAPI the Compose environment lands there. So
inside the container every `<env>` value lost to `DB_CONNECTION=pgsql` and
`CACHE_STORE=redis` — `force="true"` included, since force only governs the
layers PHPUnit owns. The suite pointed `RefreshDatabase` at the development
Postgres and emptied it. The bootstrap sets all three layers, and
`TestEnvironmentGuardTest` asserts the result so a regression fails a test
instead of destroying data. Anything environment-specific must stay out of it.

**Service endpoints follow the environment**, deliberately unforced: `apps/api/.env`
on the host (ClickHouse `8124`, Redis `6380` — the dev stack's published ports),
the Compose environment inside the container. `make up` must be running or those
tests skip. Redis backs the click queue as a list, not a cache entry, so
`CACHE_STORE=array` does not cover it.

**The container keeps its own PHPStan cache** (`api-phpstan-cache`). The cache
stores absolute paths and a resolved DI container, so sharing
`storage/framework/cache/phpstan` through the bind mount made host and container
runs corrupt each other — a `rename()` into a directory the other side had just
replaced, reported as a crashed parallel worker rather than as a cache problem.
Host PHPStan also needs `--memory-limit=1G`; the container's `php.ini` already
allows it.

**The dev web container has its own `node_modules` volume.** Installing a package
on the host does not put it in the container — restart `web` and wait for its
install, or the app 500s on a missing module.

**A PHP compile error produces no test output at all.** Pest's reporter swallows
it: no failure, no error, no JUnit file, exit 1. Run `./scripts/lint-php-syntax.sh`
first — PHPStan's parser accepts constructs PHP itself rejects.

**Pest helper functions share one global scope.** A `function foo()` in one test
file collides with another file's, and with Pest's and Laravel's own helpers
(`test`, `visit`, `validator`, `record` are all taken). Name them distinctively.

**A browser-side write needs the CSRF handshake.** The API runs a session on
every route, so a cookie-authenticated `POST` without an `X-XSRF-TOKEN` header is
refused with `419`. A client must `GET /sanctum/csrf-cookie` first and echo the
`XSRF-TOKEN` cookie back in that header — `src/lib/setup.ts` is the worked
example. This never fails in Pest, because CSRF is inert in the testing
environment; it fails the moment a real browser tries it.

**Short domains need a dotted host.** Hostname validation requires a dot, so
`localhost` is refused. Use `go.localhost` — browsers resolve `*.localhost` to
loopback, which is how the browser suite reaches the redirect path. The dev
Caddyfile splits on `Host`: `APP_DOMAIN` and `127.0.0.1` reach the interface,
anything else is treated as a short domain.

**Destination validation refuses loopback**, so a fixture pointing at
`http://localhost:8080/` must be written with `forceFill`, not through
`LinkService`. `make e2e-fixture` does this.

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

## Services

| Service | Role |
|---|---|
| `edge` | Caddy: TLS, routing, on-demand certs gated by the API |
| `api` | Octane/FrankenPHP — HTTP, including the redirect hot path |
| `worker` | Horizon: `clicks`, `default`, `mail` queues |
| `clicks` | The click drain daemon — batches envelopes into ClickHouse |
| `scheduler` | `schedule:work` |
| `web` | Next.js |
| `postgres` / `redis` / `clickhouse` | Datastores, none published to the host |
| `geoipupdate` | MaxMind sidecar; exits cleanly with no licence key |

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

Full contract in `openspec/changes/bootstrap-url-shortener/specs/security/spec.md`. Breaking one of these
is a defect, not a style choice:

- **Trusted proxies are the edge's address, never `*`.** Trusting every peer lets anyone spoof
  `X-Forwarded-For`, which defeats redirect rate limiting *and* makes every geographic figure forgeable.
- **No `unsafe-inline` or `unsafe-eval` in the CSP.** Inline style and script are authorised by per-request
  nonce — which is why pages carrying inline markup render dynamically.
- **Unauthorized reads return `404`, never `403`.** A `403` confirms the object exists.
- **Public identifiers are ULIDs.** Integer primary keys never appear in a URL, payload, or export.
- **The audit table has no `UPDATE`/`DELETE` grant** for the application role. Enforcement is the missing
  grant, not a guard in code.
- **Issued secrets are stored hashed only** — API tokens, invitations, reset tokens, recovery codes — and
  shown once at creation.
- **No SVG uploads.** Format is decided by decoding the file, never by extension or declared type, and
  images are re-encoded before storage.
- **The setup flow requires the first-boot token** until installation completes. Without it, the first
  stranger to find the host owns the instance.
- **Destinations resolving to loopback, private, link-local, CGNAT, multicast, reserved, or cloud-metadata
  addresses are refused** — checked after DNS resolution, not just on the literal string.
- **Diagnostics never carry** credentials, tokens, session identifiers, link passwords, or raw addresses.
- Sensitive operations (email, password, second factor, API token, domain deletion) require recent
  authentication.

Product-level protections: open-redirect blocklist, per-IP redirect rate limiting, link password/expiry/max
clicks, and CSPRNG-generated slugs over an unambiguous 58-character alphabet (letters and digits minus `0`, `O`,
`I`, `l`; default length 7) with a reserved-word blocklist. Operator-chosen slugs use a wider URL-safe set
(letters, digits, hyphen, underscore) — the unambiguous set is for values nobody picked and everyone must
transcribe, and would otherwise reject every word containing an `l`.
