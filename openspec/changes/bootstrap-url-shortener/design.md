## Context

Greenfield repository; see `proposal.md` — Why. Behaviour contracts are in `specs/`.

Three constraints shape everything below:

1. **The redirect is the product's hot path.** It is also the only path a stranger can hit at volume. It
   must be fast, cheap, and immune to the rest of the system being slow.
2. **The interface must be rebrandable at runtime.** That rules out compiling operator colours into CSS,
   which in turn dictates how the design tokens are structured.
3. **Analytics must be trustworthy, not merely plentiful.** The counting rules in
   `specs/click-analytics/` are the specification; the pipeline exists to enforce them.

Target deployment is a single host running Docker Compose. Horizontal scale is a later concern, but
nothing here may make it impossible.

## Goals / Non-Goals

**Goals:**

- A cached redirect that touches Redis and nothing else.
- Click counting that survives the event store being down.
- Runtime rebranding with no build step and no unstyled first paint.
- One command from clean host to setup wizard.
- Octane-safe application code by construction, not by review vigilance.

**Non-Goals:**

- Multi-tenancy. One instance is one organisation; multi-domain is not multi-tenant.
- Horizontal scale-out, ClickHouse clustering, or read replicas.
- A theme system or plugin architecture.
- Link-in-bio pages, A/B splitting, or deep-link routing.
- SSO/SAML. Local accounts and invitations only.

## Decisions

### Two applications behind a shared origin

Laravel serves the API and the redirect; Next.js serves the operator interface. Caddy publishes both on
one origin — `/api/*` and reserved paths to Laravel, everything else to Next.js — while short domains
route entirely to Laravel.

Same-origin matters: it lets the browser authenticate with Laravel via ordinary session cookies, so there
is no CORS surface, no bearer token in JavaScript, and no refresh-token dance. API tokens exist for
programmatic clients only.

*Alternative rejected:* Inertia on a Laravel monolith. Fewer moving parts, but it gives up React Server
Components and the Next.js build pipeline the interface design depends on, and it couples interface
deploys to API deploys.

*Alternative rejected:* Next.js on a separate hostname with token auth. Adds CORS, token storage, and a
class of auth bugs, for no benefit here.

### Octane on FrankenPHP, and the state discipline it forces

FrankenPHP in worker mode keeps the framework booted between requests, which is what makes a cached
redirect cheap. It is also Caddy internally, so the runtime and the edge share one mental model.

*Alternative rejected:* Swoole — faster in microbenchmarks, but coroutine semantics complicate every
third-party client. RoadRunner — comparable, but another Go supervisor to operate alongside Caddy.

Worker reuse is the main source of subtle bugs in this stack, so the codebase constrains it structurally
rather than relying on reviewers:

- Request-scoped values live in the request or in explicitly `scoped` container bindings, never in
  singletons or static properties.
- `env()` appears only in `config/`. Config is cached in the image.
- Anything holding a connection or buffer registers an Octane reset hook.
- A test boots the application twice in one process and asserts no state carries over; this is the
  regression guard for the whole class of bug.

### A separate edge proxy, even though FrankenPHP is Caddy

The application container could terminate TLS itself, but the edge has to route to Next.js as well, and
short-domain certificate issuance must keep working while the application is redeploying. A standalone
Caddy container owns TLS and routing; application containers speak plain HTTP behind it.

Short-domain certificates use Caddy's on-demand TLS with its authorization endpoint pointed at Laravel
(`specs/domains/` — certificate issuance is authorized per hostname). Without that check, any hostname
aimed at the instance would trigger a certificate request and invite rate-limit exhaustion at the
certificate authority. The endpoint answers from the Redis-cached domain list, so it is cheap and stays
available under load.

### Postgres for the application, ClickHouse for clicks

Clicks are append-only, high-volume, and queried as aggregates over time — the opposite of the
relational, mutable, join-heavy application data. A partitioned Postgres table can carry this workload,
but every analytics query then competes with the operator interface for the same buffer cache, and
retention becomes a partition-maintenance chore.

ClickHouse gives column-oriented aggregate reads, `TTL` as declarative retention, and materialized views
that maintain rollups on insert. The cost is a second datastore to deploy, back up, and restore, which is
accepted deliberately and documented in `specs/deployment/`.

*Alternative rejected:* Postgres-only with monthly partitions. Simpler operationally; loses rollup
maintenance, cheap retention, and read isolation.

Consequence: no foreign keys between clicks and links. Click events carry a denormalized `link_id`, and
reports resolve link metadata from Postgres afterwards. Deleting a link therefore does not delete its
events — which `specs/link-management/` requires anyway.

### The redirect path is deliberately not a normal route

The redirect is registered before the application's usual middleware and resolves entirely from Redis:

```
GET {host}/{slug}
  ├─ Redis GET link:{host}:{slug}
  │    ├─ hit  → evaluate constraints → respond
  │    ├─ miss:negative → 404 (no database query)
  │    └─ miss → single-flight lock → Postgres → populate cache → respond
  └─ RPUSH clicks:raw  (fire-and-forget, after response)
```

Cached entries are self-contained: destination, mode, expiry, click limit, password hash presence,
referrer policy. Evaluating constraints requires no further lookup.

Three details that matter:

- **Negative caching** for unknown slugs, with a short TTL. Without it, slug scanning becomes a database
  denial-of-service.
- **Single-flight** on a cold popular slug. A newly published link can take thousands of simultaneous
  requests; without a lock they all query Postgres at once.
- **Write-through invalidation** driven by `Link` model events, never by controllers. Controllers that
  hand-expire cache keys are how stale-redirect bugs happen, and multi-domain means the key is
  `(host, slug)`.

Click-limit enforcement uses a Redis counter incremented on the hot path, reconciled against the event
store by a periodic job. An exact count would require a synchronous authoritative read per redirect; the
specification's guarantee is that a limited link stops resolving, and a counter with reconciliation
delivers that without putting a database on the hot path.

### Clicks travel by queue, and the queue is allowed to lose data

The redirect pushes a raw envelope onto a Redis list and returns. A Horizon worker on a dedicated
`clicks` queue drains it in batches, filters, enriches, and writes to ClickHouse in one batched insert per
batch.

This is what lets a redirect succeed while ClickHouse is down, as `specs/click-analytics/` requires.
Redis persistence is enabled, but a hard crash can still drop a few seconds of unprocessed clicks. That
trade is accepted: a lost click is a rounding error, a failed redirect is a broken product.

*Alternative rejected:* writing to ClickHouse directly from the redirect using asynchronous inserts.
Removes the queue, but couples the hot path to the event store's availability and leaves nowhere to run
enrichment.

Enrichment order is deliberate — cheap rejections first, so filtered traffic never costs a geo lookup:

```
prefetch/HEAD → user-agent bot match → geo+ASN resolve → datacenter ASN check
→ visitor hash → dedupe → batch insert
```

Automated traffic is stored with a classification flag rather than dropped, because
`specs/click-analytics/` requires it to remain inspectable and because misclassification is otherwise
irreversible.

### Rollups are maintained by the database, not by jobs

Click events use `MergeTree` ordered by `(link_id, occurred_at)` — every report is scoped to a link over a
time range, so that ordering key serves them all. Retention is a `TTL` clause derived from the configured
period.

Rollups are `AggregatingMergeTree` materialized views populated on insert: per-link-per-hour totals, and
breakdowns by country, referrer, device, and browser. Dashboards read only these. A scheduled aggregation
job would be simpler to reason about but would add a window in which reports are stale, and would need
its own backfill and failure handling.

Aggregates carry no TTL, so historical totals outlive raw-event expiry as specified.

Schema lives in versioned SQL files applied by `php artisan clickhouse:migrate`, tracked in a ClickHouse
table. Laravel's migrator is never pointed at ClickHouse: it assumes transactional DDL and rollback
semantics that ClickHouse does not provide. The application reads through a read-only ClickHouse user and
writes through a separate one.

The client is a thin internal wrapper over ClickHouse's HTTP interface. Batch inserts are
`JSONEachRow` and reads are parameterized `SELECT`s — a few hundred lines, versus a dependency whose
maintenance we would not control.

### Slugs are random, not derived

Slugs come from a CSPRNG over a 62-character alphabet, default length 7, with bounded retry on collision
and a reserved-word blocklist covering application paths.

A private shortener leaks information if its slugs are guessable: sequential or hash-derived slugs let
anyone walk the corpus. Seven random base62 characters is roughly 3.5×10¹² values — collisions are
negligible at realistic volume, and enumeration is impractical against a rate-limited endpoint.

*Alternative rejected:* Sqids or Hashids over a sequential id. Shorter, reversible, and enumerable —
disqualifying for a private tool.

### Settings in Postgres, cached in Redis, and only settings

`.env` carries infrastructure credentials — datastore URLs, the application key — and nothing else.
Everything an operator can change through the interface lives in a `settings` table with typed casts,
sensitive values encrypted at rest, and the whole set cached in Redis and invalidated on write.

This is what makes the OOBE meaningful: the wizard writes settings, so nothing it collects requires a
container restart. Installation state is a setting too, and it gates the setup routes.

### Interface direction: editorial minimalism, not agency maximalism

Two credible directions were available and they contradict each other, so this picks one outright rather
than averaging them into mush.

**Chosen — premium utilitarian minimalism.** Warm monochrome canvas, hairline `1px` structural borders,
crisp `8–12px` radii, effectively no shadows, asymmetric bento grid, generous internal padding, extreme
typographic contrast, colour as a scarce resource.

**Rejected — the high-end agency direction**: double-bezel nested enclosures, `rounded-[2rem]` squircles,
pill CTAs with button-in-button icons, glass `backdrop-blur`, ambient gradient orbs, 800ms blur-fade scroll
reveals. It is the better choice for a landing page and the wrong choice here:

- It is built around a *variance mandate* — never the same layout twice. A dashboard an operator opens
  every day needs the opposite: total layout stability, so muscle memory works.
- Its depth cues cost GPU on the densest pages we have. `backdrop-blur` is explicitly unsafe on scrolling
  containers, and our two heaviest views are a long virtualized click table and a chart grid.
- Its ambient mesh gradients are hand-tuned per palette. Runtime rebranding cannot hand-tune anything —
  every visual must be derivable from one accent input.
- Its 800ms scroll-entry reveals fail the motion frequency gate below on exactly the surfaces an operator
  visits most.

Minimalism also happens to be what runtime branding can actually deliver: a flat monochrome system with a
single accent has one degree of freedom, and one degree of freedom is what an operator picker can safely
expose.

Where the agency direction is right — the interstitial hold page and the setup wizard, both seen rarely —
those surfaces are allowed more visual ambition and are the only places scroll-entry motion appears.

Hard constraints, enforced in review:

- **Fonts**: a geometric sans for UI and body, a mono for slugs, counts, tokens, and timestamps, and an
  editorial serif used sparingly for setup-wizard and empty-state headings. Never Inter, Roboto, Helvetica,
  or Open Sans.
- **Icons**: Phosphor at one standardized weight — Regular for the interface, Bold reserved for
  active/selected state. Never Lucide, Feather, Material, or FontAwesome. Never emoji, anywhere.
- **No** gradients, neon, glassmorphism, `rounded-full` on large containers or primary buttons, primary-
  coloured section backgrounds, or Tailwind's stock `shadow-md`/`lg`/`xl`.
- Mono is not decorative here. A slug is an identifier a person transcribes and compares character by
  character, so `0`/`O` and `1`/`l` must be visually distinct.

### Branding as CSS custom properties, mapped through Tailwind

The only way to rebrand without a build step is for the compiled CSS to reference variables rather than
literal colours. Tailwind v4's `@theme inline` maps its tokens onto CSS custom properties, so utility
classes resolve to variables that a server-rendered `<style>` block sets per request.

Colours are authored in OKLCH so the accent's derived hover, active, and muted variants can be generated
by lightness and chroma shifts that stay perceptually even across hues — the reason an accent picker can
be trusted without an operator hand-tuning every state. The same OKLCH lightness values drive the
contrast check that `specs/branding/` requires.

The operator's degrees of freedom are deliberately bounded so branding cannot break the direction above:
one accent hue, a radius token clamped to `4–14px`, a logo, a wordmark, and a font choice from a curated
allowlist. An unbounded radius would let an operator reproduce the squircle look this design rejects, and
an unbounded font field would let Inter back in.

Light and dark are one token set redefined under `prefers-color-scheme` and under `[data-theme]`, so an
explicit viewer choice wins in both directions. Neither mode uses pure black or pure white: warm
off-white canvas in light, warm near-black in dark, off-black body text rather than `#000`.

Theme switching uses `next-themes`, whose blocking inline script sets the attribute before first paint.
Branding variables are server-rendered into the document by Next.js. Both mechanisms are required and
they solve different halves of the same requirement — `next-themes` prevents the light/dark flash, SSR
prevents the default-accent flash.

### Motion: the frequency gate decides, and it mostly says no

"Nice animations" for a tool someone opens fifty times a day means *fast and few*, not *plentiful*. The
governing rule is frequency: the more often a viewer sees an animation, the shorter it must be, and past a
threshold it should not exist. Applied here:

| Surface | Frequency | Decision |
| --- | --- | --- |
| Command palette open/close, keyboard navigation | 100+/day | **No animation at all** |
| Table sort, filter, pagination | Tens/day | No transition on the rows; skeleton only on real latency |
| Button press, copy-link, toggle | Constant | Feedback only — `scale(0.97)`, 100–160ms |
| Tooltips | Frequent | 125–200ms, instant on subsequent hovers |
| Dropdowns, selects, popovers | Frequent | 150–250ms, origin-aware |
| Link editor sheet, modals, toasts | Occasional | 200–300ms standard |
| Setup wizard, interstitial page, empty states | Rare or once | Delight permitted, scroll-entry allowed |

This is a deliberate rejection of the scroll-entry-everything pattern both visual skills recommend. On the
dashboard, content is present when the page is.

Implementation contract:

- **Curves are tokens, never ad hoc**: `--ease-out: cubic-bezier(0.23, 1, 0.32, 1)`,
  `--ease-in-out: cubic-bezier(0.77, 0, 0.175, 1)`, `--ease-drawer: cubic-bezier(0.32, 0.72, 0, 1)`.
  Built-in `ease-in` never appears on a UI element — it delays the first moment the viewer is watching.
- **Cheapest tool that works**: CSS transition → `@starting-style` → CSS animation → WAAPI → `motion`.
  A fade does not justify a motion library. `motion` earns its place only for springs, layout animation,
  exit animation, and gesture-driven values.
- **`transform` and `opacity` only**, with `clip-path` sanctioned and `height` tolerated for accordions.
- **Never `scale(0)`** — entrances start at `scale(0.95)` with `opacity: 0`.
- **Transitions, not keyframes**, for anything rapidly retriggerable; keyframes restart from zero while
  transitions retarget. Toasts and toggles are the cases that matter.
- **`transform-origin` at the trigger** for popovers and menus; modals stay centred.
- **Exit faster than enter**, and exit along the path it entered.
- **Reduced motion and hover gating ship with each animation**, not afterwards. Reduced motion means
  gentler, not zero: opacity and colour survive, movement does not. Hover motion sits behind
  `@media (hover: hover) and (pointer: fine)` so a tap does not fire it.
- `transition: all` is banned; the exact properties are always named.

Analytics counters use NumberFlow, but only when a value changes while the viewer is watching. A count-up
on initial page load is decoration on a number someone came to read, and it delays comprehension of the
one thing the page exists to show.

### Library choices

Picked deliberately rather than assembled by habit; hand-rolling any of the first three is how a dashboard
ends up with an inaccessible `<div>` dropdown.

| Need | Choice |
| --- | --- |
| Accessible primitives — dialog, popover, select, tooltip, menu | base-ui |
| Command palette | cmdk |
| Toasts | Sonner |
| Springs, layout and exit animation, gestures | motion |
| Animating changing numbers | NumberFlow |
| Charts | recharts |
| Virtualization for the click drill-down table | Virtuoso |
| Client state | zustand |
| Conditional classes / typed variants | clsx + cva |
| Light-dark switching without a flash | next-themes |

base-ui pairs with the motion contract: it exposes `var(--transform-origin)`, which is what makes
origin-aware popovers a one-line concern instead of a per-component calculation.

Virtuoso is required rather than nice-to-have. The raw drill-down is the one view that can legitimately
render thousands of rows, and `specs/click-analytics/` already requires server pagination — virtualization
handles the rendered window within a page.

### The interstitial page is served by Laravel, self-contained

The hold page is a Blade view with its CSS inlined, compiled from a small dedicated Tailwind entry in the
API image. It is one HTTP response with no additional requests, on the same domain the visitor already
resolved.

Routing it through Next.js would add a network hop and a second render path to the most
latency-sensitive page a visitor ever sees. Branding comes from the same Redis-cached settings, so the
page stays consistent with the interface.

Beacon integrity — a client must not be able to attribute measurements to a click it did not make — uses
a short-lived HMAC-signed click token embedded in the page and redeemed once through a Redis key. A raw
click id would be forgeable and replayable.

Scripting-disabled visitors reach the destination through a `<meta refresh>` fallback and a visible link,
and are recorded with server-observable data only.

### Visitor identity is a rotating hash

The visitor identifier is `HMAC(daily_salt, ip + user_agent)`. The salt rotates on a schedule and prior
salts are discarded, so identifiers cannot be recomputed from an address afterwards. The raw address is
used during enrichment and never persisted — geo and ASN survive, the address does not.

This is the mechanism behind the privacy requirement in `specs/click-analytics/`. It costs cross-day
visitor continuity: a returning visitor tomorrow is a new unique. Unique counts are therefore reported
per period and never summed across periods.

### Testing

Pest for the API. The redirect hot path, the filtering rules, and the constraint evaluations are feature
tests, including an assertion that a cache hit issues zero database queries — the performance guarantee
is a test, not a hope. ClickHouse-touching tests run against a real ClickHouse service in CI; the
enrichment pipeline is unit-tested against fixture envelopes.

Vitest for web units, Playwright for the setup wizard and the interstitial beacon. Larastan level 8 and
Pint on the API; ESLint and `tsc --noEmit` on the web app.

The motion contract is enforced mechanically where it can be. A lint rule bans `transition: all`,
`scale(0)` entrances, `ease-in` on UI elements, and animation of layout-triggering properties, because
those are the failures that reappear every time someone adds a component in a hurry. What lint cannot
judge — whether a crossfade reads as one object or two, whether a spring's settle feels right — is
reviewed by playing the animation at 2–5× duration and stepping it frame by frame, not by reading the
diff.

## Risks / Trade-offs

- **Octane state leakage across requests** → Structural rules above, plus a double-boot test as the
  standing regression guard.
- **A second datastore doubles the operational surface** → Single-node ClickHouse, backup and restore
  covered by `specs/deployment/`, and no cross-store transactions to reason about.
- **Cache and database disagree, serving a stale destination** → Invalidation is driven by model events
  rather than controllers, and cache entries carry a bounded TTL as a backstop.
- **Redis loss drops in-flight clicks** → Persistence enabled; accepted explicitly as the price of never
  blocking a redirect.
- **On-demand TLS abused to force certificate requests** → The authorization endpoint approves only
  registered domains and is itself rate limited.
- **Bot filtering both over- and under-counts** → Classification is stored rather than applied
  destructively, so rules can be revised and traffic reclassified.
- **The interstitial mode adds visitor friction** → Off by default, per-link opt-in, configurable delay.
- **The GeoLite2 licence key is a third-party dependency the operator must obtain** → Enrichment degrades
  to non-geographic rather than failing, and the missing database is surfaced as a configuration warning.
- **Approximate click-limit enforcement** → Redis counter with periodic reconciliation; the specification
  guarantees the link stops resolving, not that the last click is exact.
- **The motion frequency gate will read as "not enough animation" on first look.** A dashboard built to
  this contract feels plain in a demo and correct in daily use → The rare surfaces carry the visual
  ambition instead: the setup wizard, the interstitial page, and empty states. If the built result genuinely
  feels inert, the fix is better feedback and state transitions on the surfaces that already animate, not
  scroll reveals on the dashboard.
- **Committing to one visual direction forecloses the other.** The rejected agency direction is not
  reachable by incremental tweaks — double-bezel enclosures and hairline-bordered flat cards are different
  component architectures → The direction is settled here, before component work starts, precisely because
  discovering the preference later means rebuilding the shell rather than restyling it.
- **Bounded branding will frustrate an operator who wants a look the tokens cannot express** → The bounds
  are what keep every accent choice legible and every derived state correct without hand-tuning. Widening
  them is a deliberate later decision, not an accident of an unvalidated input field.

## Migration Plan

No data migration — the repository is empty. Deployment ordering still matters:

1. Datastores start and pass health checks.
2. Postgres migrations, then `clickhouse:migrate`. Both idempotent.
3. Application, worker, scheduler, and web start; each reports health.
4. Caddy begins accepting traffic once the application is healthy.

Rollback is image-tag based: redeploy the previous tag. Migrations are therefore written to be
backward-compatible with the immediately preceding release — additive columns, no destructive changes in
the same release that stops using them. ClickHouse schema changes are additive only; a column is never
dropped in the release that stops writing it.

## Open Questions

- Default event retention period. The mechanism is specified and configurable, so the shipped default can
  be chosen at implementation time without affecting specs, approach, or task breakdown.

Two questions raised during design are resolved here rather than deferred, because both would have
changed what gets built:

- **Geo enrichment has no hosted fallback.** GeoLite2 is the only source. A hosted fallback would put a
  third-party HTTP call in the enrichment path, add a rate limit and a bill, and send visitor addresses
  off the host — which contradicts the privacy posture the analytics specification is built on.
  Enrichment degrades to non-geographic instead.
- **Horizon's dashboard is exposed to the owner role only**, behind normal session authentication on the
  application domain, and never on a short domain. It reveals queue payloads, so it is not for members.
