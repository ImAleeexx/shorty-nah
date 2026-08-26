## 1. Repository and toolchain

- [x] 1.1 Create the monorepo skeleton (`apps/api`, `apps/web`, `docker/`, root `Makefile`, `.env.example`) and verify `make help` lists every documented target
- [x] 1.2 Install Laravel 13 into `apps/api` with Octane and FrankenPHP, and verify Octane boots on FrankenPHP and serves a request (`octane:status` only reports running state, not the server, so booting it is the real check)
- [x] 1.3 Install Next.js 16 with TypeScript strict and Tailwind v4 into `apps/web`, and verify `pnpm build` and `pnpm typecheck` both pass
- [x] 1.4 Configure the quality and test toolchain — Pint, Larastan level 8, Pest on the API; ESLint, Prettier, Vitest, Playwright on the web — and verify every gate runs clean on the scaffolds (design.md's Testing section already requires Pest, Vitest and Playwright; the original wording named only the linters, which left CI in 1.5 with nothing to run)
- [x] 1.5 Add the CI workflow running API and web quality gates plus tests, and verify every command it runs passes locally (10/10 steps green; the workflow itself first executes on push, and the Playwright job is deferred to phase 2 because it needs the Compose stack)

## 2. Container topology

- [ ] 2.1 Write the API image (PHP 8.4, FrankenPHP, required extensions, non-root user, no baked secrets) and verify `docker build` succeeds and the image runs `php -v`
- [ ] 2.2 Write the web image as a Next.js standalone build and verify the container serves the default page
- [ ] 2.3 Write `compose.yaml` with edge, api, worker, scheduler, web, postgres, redis, clickhouse, and geoipupdate, and verify `docker compose config` validates
- [ ] 2.4 Write `compose.dev.yaml` with source mounts and file watching, and verify an edit to an API file is reflected without a rebuild
- [ ] 2.5 Write the Caddyfile routing `/api/*` and reserved paths to the API, all else to web, and short domains entirely to the API, and verify each route class reaches the right upstream
- [ ] 2.6 Add startup environment validation that exits non-zero naming any missing or malformed required value, and verify a container with an absent required value fails to start with that message
- [ ] 2.7 Add per-service health checks including dependency reachability and queue liveness, and verify `docker compose ps` reports healthy states and reports unhealthy when a datastore is stopped

## 3. API foundation and Octane safety

- [ ] 3.1 Confirm the config contract is enforced — Larastan's `noEnvCallsOutsideOfConfig` rule already fails any `env()` call outside `config/` at level 8 — and verify it by asserting the analyser rejects a deliberate violation fixture
- [ ] 3.2 Register Octane reset hooks for stateful services and verify the double-boot test asserting no state carries between requests passes
- [ ] 3.3 Add the ClickHouse HTTP client wrapper with batch `JSONEachRow` insert and parameterized reads, and verify its unit tests pass against a live ClickHouse service
- [ ] 3.4 Add `clickhouse:migrate` with a version-tracking table, and verify re-running it on an up-to-date instance changes nothing and exits zero
- [ ] 3.5 Configure Horizon with separate `clicks`, `default`, and `mail` queues, restrict its dashboard to the owner role, and verify a non-owner receives `403`

## 4. Settings store

- [ ] 4.1 Implement the typed settings store with encrypted sensitive values and Redis caching invalidated on write, and verify a changed setting is observed on the next request without restart
- [ ] 4.2 Reject unknown setting keys and verify the rejection test passes
- [ ] 4.3 Ensure sensitive settings are never serialized into API responses and verify a test asserting their absence passes
- [ ] 4.4 Expose the unauthenticated public configuration endpoint limited to the interface subset, and verify a test asserts no sensitive or operational keys are present

## 5. Identity

- [ ] 5.1 Implement accounts, roles, and session authentication on the shared origin, and verify sign-in and sign-out feature tests pass
- [ ] 5.2 Enforce the three registration modes and verify the closed, invite, spent-invite, and open scenarios from `specs/identity/` all pass
- [ ] 5.3 Implement invitations with expiry, revocation, and single use, and verify each terminal state is refused
- [ ] 5.4 Implement role authorization including the member-cannot-see-others-links `404` and the last-owner protection, and verify those tests pass
- [ ] 5.5 Add authentication rate limiting and identical responses for unknown addresses, and verify the enumeration-resistance test passes
- [ ] 5.6 Implement scoped API tokens shown once at creation with revocation, and verify in-scope, out-of-scope, and revoked cases behave as specified

## 6. Domains

- [ ] 6.1 Implement the domain resource with primary designation and the refusal to delete the primary or a domain still holding links, and verify both refusals are tested
- [ ] 6.2 Implement domain verification and the refusal to serve unverified domains, and verify a slug on an unverified domain returns not-found
- [ ] 6.3 Implement the certificate authorization endpoint reading the Redis-cached domain list, rate limited, and verify it approves registered hostnames and declines unknown ones
- [ ] 6.4 Wire Caddy on-demand TLS to that endpoint and verify a registered domain obtains a certificate while an unknown hostname triggers no issuance attempt

## 7. Link management

- [ ] 7.1 Implement the link model and Postgres migrations with slug uniqueness scoped per domain, and verify the same-slug-on-two-domains scenario passes
- [ ] 7.2 Implement CSPRNG base62 slug generation with bounded retry and a distinct exhaustion error, and verify collision and exhaustion tests pass
- [ ] 7.3 Implement custom slug validation against charset and reserved words, and verify reserved and malformed slugs are rejected
- [ ] 7.4 Implement destination validation covering scheme, self-referencing loops, and the blocklist, and verify each rejection is tested
- [ ] 7.5 Implement expiry, click limit, password hashing, disabled state, and per-link redirect mode with instance-default fallback, and verify the default-change scenario passes
- [ ] 7.6 Implement model-event-driven cache invalidation keyed by `(host, slug)`, and verify an edited destination and a deleted link both take effect on the immediately following request
- [ ] 7.7 Implement tags and link search by slug, destination, and tag with role scoping, and verify search results respect the requester's role
- [ ] 7.8 Implement the link CRUD API with authorization, and verify the endpoint tests pass

## 8. Redirect hot path

- [ ] 8.1 Register the redirect route ahead of the standard middleware stack and verify a request bypasses session and CSRF middleware
- [ ] 8.2 Implement Redis-only resolution with self-contained cache entries, and verify a cache hit issues zero application database queries
- [ ] 8.3 Implement negative caching for unknown slugs and verify repeated requests for a non-existent slug issue no database query after the first
- [ ] 8.4 Implement single-flight locking on cold resolution and verify concurrent requests for one uncached slug produce a single database query
- [ ] 8.5 Implement direct mode returning `302` with no tracking markup and no-store cache headers, and verify the response-shape test passes
- [ ] 8.6 Implement constraint evaluation for expired, disabled, and limit-reached links, and verify unauthenticated responses for disabled and never-existed slugs are indistinguishable
- [ ] 8.7 Implement the Redis click counter with a reconciliation job against the event store, and verify a limited link stops resolving
- [ ] 8.8 Implement the password gate that reveals nothing before success, with attempt rate limiting, and verify no response header or body leaks the destination
- [ ] 8.9 Implement per-source redirect rate limiting recording no clicks for refused requests, and verify the `429` test passes

## 9. Interstitial mode

- [ ] 9.1 Build the self-contained Blade hold page with inlined CSS from a dedicated Tailwind entry, and verify the rendered response makes no additional network requests
- [ ] 9.2 Implement the HMAC-signed single-use click token and verify a forged or replayed beacon submission is rejected
- [ ] 9.3 Implement the beacon collecting viewport, screen, timezone, language, colour-scheme, connection type, and dwell time, and verify the signals are attached to the click event
- [ ] 9.4 Implement the scripting-disabled fallback via meta refresh and a visible link, and verify the visitor reaches the destination with server-observable data only
- [ ] 9.5 Apply the configured per-link referrer policy on navigation and verify the emitted policy matches the setting
- [ ] 9.6 Verify with a Playwright test that the hold page brands correctly, navigates after the configured delay, and records exactly one click

## 10. Click pipeline

- [ ] 10.1 Implement fire-and-forget enqueue after the response is sent, and verify a redirect succeeds and stays within its latency budget while ClickHouse is stopped
- [ ] 10.2 Implement prefetch and `HEAD` rejection and verify no click is recorded for prefetch headers or `HEAD` requests
- [ ] 10.3 Implement user-agent bot matching and datacenter-ASN classification stored as a flag, and verify automated traffic is excluded from counts yet remains queryable
- [ ] 10.4 Wire the geoipupdate sidecar and shared volume, implement in-process GeoLite2 reading with reload on file change, and verify resolved, unresolved, and missing-database cases all behave as specified
- [ ] 10.5 Implement user-agent parsing for device, operating system, and browser, and verify the parser's unit tests pass
- [ ] 10.6 Implement the rotating-salt visitor hash and verify persisted events contain no network address and that rotation changes the identifier
- [ ] 10.7 Implement the deduplication window and verify two events for one visitor and link within it count as one
- [ ] 10.8 Implement batched ClickHouse inserts draining the queue, and verify a batch of events lands in one insert
- [ ] 10.9 Verify the ordered pipeline end to end: filtered traffic performs no geo lookup, and a real click arrives enriched in ClickHouse

## 11. Analytics storage and reporting

- [ ] 11.1 Write the events table as `MergeTree` ordered by `(link_id, occurred_at)` with a retention `TTL`, and verify expired events are removed while aggregates remain
- [ ] 11.2 Write the `AggregatingMergeTree` materialized views for hourly totals and country, referrer, device, and browser breakdowns, and verify an insert updates them without a job
- [ ] 11.3 Implement reporting queries served only from aggregates with instance-timezone bucketing, and verify a twelve-month report over a large dataset meets the response budget
- [ ] 11.4 Implement paginated raw-event drill-down for a link and verify the endpoint tests pass
- [ ] 11.5 Implement click-event export excluding network addresses and verify the exported file contains the period's events and no address column
- [ ] 11.6 Verify unique counts are reported per period and never summed across periods

## 12. Web foundation, design system, and branding

- [ ] 12.1 Install the chosen libraries (base-ui, cmdk, Sonner, motion, NumberFlow, recharts, Virtuoso, zustand, clsx, cva, next-themes) and verify `pnpm build` and `pnpm typecheck` still pass
- [ ] 12.2 Set up the typeface trio (geometric sans, mono, editorial serif) self-hosted with correct subsetting, and verify no banned face (Inter, Roboto, Helvetica, Open Sans) appears in the built CSS or network requests
- [ ] 12.3 Set up Phosphor icons at one standardized weight behind a single icon module, and verify a lint rule fails on any import from another icon package
- [ ] 12.4 Establish the OKLCH token set mapped through Tailwind `@theme inline` to CSS custom properties, and verify utilities resolve to variables rather than literal colours in the built CSS
- [ ] 12.5 Establish the motion token layer (`--ease-out`, `--ease-in-out`, `--ease-drawer`, the duration scale) and verify no component declares a raw `cubic-bezier` or bare millisecond value
- [ ] 12.6 Add the lint rules banning `transition: all`, `scale(0)` entrances, `ease-in` on UI elements, and animation of layout-triggering properties, and verify each rule fails on a deliberate violation fixture
- [ ] 12.7 Wire `next-themes` with the `[data-theme]` attribute and verify no light/dark flash on a cold load in either system preference
- [ ] 12.8 Implement server-side branding injection into the initial document and verify no default-accent paint occurs before branded styling applies
- [ ] 12.9 Implement derived accent states by OKLCH lightness and chroma shifts, and verify hover, active, and muted variants are generated from a single accent input
- [ ] 12.10 Implement the contrast check warning on accent colours failing the threshold in either mode, and verify body and heading text still pass under any accent
- [ ] 12.11 Enforce the branding bounds (single accent hue, radius clamped to range, typeface from the curated list) and verify out-of-range radius and off-list typeface are both rejected with their permitted values stated
- [ ] 12.12 Implement branding asset upload with type and size validation, and verify unsupported and oversized assets are rejected with the limits stated
- [ ] 12.13 Build the primitive component set on base-ui (dialog, sheet, popover, select, tooltip, menu) with `var(--transform-origin)` wired for trigger-anchored surfaces, and verify keyboard focus, dismissal, and focus return behave correctly in each
- [ ] 12.14 Apply the interaction feedback baseline — `scale(0.97)` press at 100–160ms, hover motion gated behind `@media (hover: hover) and (pointer: fine)` — and verify a tap on a touch device fires no hover state
- [ ] 12.15 Build the asymmetric bento application shell with hairline borders, clamped radii, no stock Tailwind shadows, and generous internal padding, and verify it collapses to a single column below 768px with all span overrides reset
- [ ] 12.16 Add the `prefers-reduced-motion` variants across the component set and verify movement is removed while opacity and colour transitions that aid comprehension remain
- [ ] 12.17 Verify the frequency gate holds in the built interface: the command palette and keyboard navigation have no open/close animation, table sort and filter do not transition rows, and no dashboard surface uses scroll-entry motion

## 13. Setup experience

- [ ] 13.1 Implement installation-state detection gating all routes, and verify an uninstalled instance redirects the interface to setup and returns `503` from authenticated API endpoints
- [ ] 13.2 Implement the dependency connectivity step and verify an unreachable datastore names the dependency and blocks advancement
- [ ] 13.3 Build the wizard steps for administrator, instance identity and primary domain, branding, analytics, registration mode, and mail, and verify a skipped mail step still completes installation
- [ ] 13.4 Implement resumable progress and verify reloading mid-wizard resumes at the first incomplete step
- [ ] 13.5 Implement permanent setup closure and verify the route returns `404` and submissions change nothing after installation
- [ ] 13.6 Implement `shortynah:install` accepting the same configuration, and verify fresh success, non-zero exit on an installed instance, and non-zero exit naming a missing value
- [ ] 13.7 Verify the full wizard with a Playwright run from fresh instance to signed-in dashboard

## 14. Operator interface

- [ ] 14.1 Build authentication screens honouring the active registration mode, and verify the interface hides registration when closed
- [ ] 14.2 Build link creation and editing in a base-ui sheet including custom slug, domain, mode, expiry, password, click limit, and tags, and verify server validation errors surface per field
- [ ] 14.3 Build the link list with search, domain, owner, and tag filtering, and verify results match the API under each role
- [ ] 14.4 Add the cmdk command palette for link search, creation, and navigation with no open/close animation, and verify it opens on the keyboard shortcut and returns focus on dismissal
- [ ] 14.5 Wire Sonner for action feedback with copy-link, save, and delete flows, and verify toasts follow the active colour mode and survive rapid retriggering without restarting
- [ ] 14.6 Build the analytics dashboard on aggregate endpoints with recharts time-series and breakdown views, and verify rendered figures match the API responses
- [ ] 14.7 Apply NumberFlow to counters that change while the viewer is watching, and verify no count-up animation runs on initial page load
- [ ] 14.8 Build raw-click drill-down virtualized with Virtuoso over the paginated endpoint, and verify a page of several thousand rows scrolls without frame drops
- [ ] 14.9 Build click-event export and verify the export downloads for an authorized operator
- [ ] 14.10 Build settings screens for branding, analytics, registration, domains, and mail, and verify each change takes effect without a restart
- [ ] 14.11 Build the branding editor with live preview in both colour modes, and verify the contrast warning appears before an unreadable accent can be saved
- [ ] 14.12 Build user and invitation management restricted by role, and verify a viewer cannot reach write actions
- [ ] 14.13 Design the empty states for links, analytics, and search with the serif heading treatment, and verify each states the next action rather than only reporting absence
- [ ] 14.14 Verify keyboard navigation and screen-reader labelling across primary flows

## 15. Operations

- [ ] 15.1 Implement graceful worker shutdown returning unfinished jobs to the queue, and verify a job interrupted by a termination signal is completed or requeued
- [ ] 15.2 Order deployment so schema is applied before new application processes serve traffic, and verify a version deploy applies both stores' schema first
- [ ] 15.3 Implement backup covering application data, event data, and uploaded assets, and verify it produces all three artefacts on a running instance
- [ ] 15.4 Verify restore onto a clean host yields resolving links and available historical reports
- [ ] 15.5 Verify a built image contains no instance credentials, keys, or licence values in its layers or environment

## 16. Verification and delivery

- [ ] 16.1 Run the full quality gate — Pint, Larastan, Pest, ESLint, typecheck, Vitest, Playwright — and verify every check passes
- [ ] 16.2 Verify every scenario in `specs/` has a corresponding automated test, and list any deliberate exceptions with a reason
- [ ] 16.3 Verify a clean-host bring-up reaches the setup flow with no manual step between the bring-up command and the wizard
- [ ] 16.4 Conduct a motion review over the delivered interface against the frequency gate and the implementation contract, inspecting animations at 2–5× duration, and resolve the findings
- [ ] 16.5 Conduct a code review pass over the delivered implementation and resolve the findings
- [ ] 16.6 Write `README.md` covering the project, its feature set, deployment, and configuration, verified by following its instructions on a clean host
