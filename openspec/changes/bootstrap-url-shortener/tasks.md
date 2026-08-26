## 1. Repository and toolchain

- [x] 1.1 Create the monorepo skeleton (`apps/api`, `apps/web`, `docker/`, root `Makefile`, `.env.example`) and verify `make help` lists every documented target
- [x] 1.2 Install Laravel 13 into `apps/api` with Octane and FrankenPHP, and verify Octane boots on FrankenPHP and serves a request (`octane:status` only reports running state, not the server, so booting it is the real check)
- [x] 1.3 Install Next.js 16 with TypeScript strict and Tailwind v4 into `apps/web`, and verify `pnpm build` and `pnpm typecheck` both pass
- [x] 1.4 Configure the quality and test toolchain — Pint, Larastan level 8, Pest on the API; ESLint, Prettier, Vitest, Playwright on the web — and verify every gate runs clean on the scaffolds (design.md's Testing section already requires Pest, Vitest and Playwright; the original wording named only the linters, which left CI in 1.5 with nothing to run)
- [x] 1.5 Add the CI workflow running API and web quality gates plus tests, and verify every command it runs passes locally (10/10 steps green; the workflow itself first executes on push, and the Playwright job is deferred to phase 2 because it needs the Compose stack)

## 2. Container topology

- [x] 2.1 Write the API image (PHP 8.4, FrankenPHP, required extensions, non-root user, no baked secrets) and verify `docker build` succeeds and the image runs `php -v`
- [x] 2.2 Write the web image as a Next.js standalone build and verify the container serves the default page (re-verified after the phase 12 dependency set landed: builds, serves 200 as uid 10002, and falls back to default branding when the API is unreachable)
- [x] 2.3 Write `compose.yaml` with edge, api, worker, scheduler, web, postgres, redis, clickhouse, and geoipupdate, and verify `docker compose config` validates
- [x] 2.4 Write `compose.dev.yaml` with source mounts and file watching, and verify an edit to an API file is reflected without a rebuild (FrankenPHP does not honour `--max-requests` for worker recycling, so dev uses a `development` image stage carrying Node and chokidar for Octane's `--watch`)
- [x] 2.5 Write the Caddyfile routing `/api/*` and reserved paths to the API, all else to web, and short domains entirely to the API, and verify each route class reaches the right upstream
- [x] 2.6 Add startup environment validation that exits non-zero naming any missing or malformed required value, and verify a container with an absent required value fails to start with that message
- [x] 2.7 Add per-service health checks including dependency reachability and queue liveness, and verify `docker compose ps` reports healthy states and that stopping a datastore makes `/up` return 500 and the probe fail (the container flips to unhealthy after the configured 4 consecutive failures, which is deliberate anti-flap behaviour rather than an instant transition)

- [x] 2.8 Add an image-processing extension to the API image for branding upload re-encoding, and verify a decoded-and-re-encoded raster image round-trips inside the container
- [x] 2.9 Emit the hardened response headers at the edge — HSTS, referrer policy, permissions policy, `nosniff`, frame-ancestors deny — and strip server and framework version headers, and verify each header is present and no version is disclosed
- [x] 2.10 Keep Postgres, Redis and ClickHouse on the internal network with no published ports, and verify only the edge publishes ports
- [x] 2.11 Pin every base image by digest, and verify a tag-only reference fails the pipeline check

## 3. API foundation and Octane safety

- [x] 3.1 Confirm the config contract is enforced — Larastan's `noEnvCallsOutsideOfConfig` rule already fails any `env()` call outside `config/` at level 8 — and verify it by asserting the analyser rejects a deliberate violation fixture
- [x] 3.2 Register Octane reset hooks for stateful services and verify the double-boot test asserting no state carries between requests passes
- [x] 3.3 Add the ClickHouse HTTP client wrapper with batch `JSONEachRow` insert and parameterized reads, and verify its unit tests pass against a live ClickHouse service
- [x] 3.4 Add `clickhouse:migrate` with a version-tracking table, and verify re-running it on an up-to-date instance changes nothing and exits zero
- [x] 3.5 Configure Horizon with separate `clicks`, `default`, and `mail` queues, and verify a supervisor resolves per queue with the clicks supervisor scaling on queue depth. The dashboard gate is closed to everyone here — granting it to owners needs the role model, so that half moved to 5.13
- [x] 3.6 Configure the trusted-proxy contract to the edge's network address only, and verify a forwarding header from an untrusted peer is ignored while one from the edge is honoured
- [x] 3.7 Add diagnostic redaction for credentials, tokens, session identifiers, link passwords and licence keys, and verify a failing request carrying a token records it redacted and returns no stack trace with debug disabled
- [x] 3.8 Close mass assignment by default across models, and verify an undeclared attribute such as a role or owner reference is not written

## 4. Settings store

- [x] 4.1 Implement the typed settings store with encrypted sensitive values and Redis caching invalidated on write, and verify a changed setting is observed on the next request without restart
- [x] 4.2 Reject unknown setting keys and verify the rejection test passes
- [x] 4.3 Ensure sensitive settings are never serialized into API responses and verify a test asserting their absence passes
- [x] 4.4 Expose the unauthenticated public configuration endpoint limited to the interface subset, and verify a test asserts no sensitive or operational keys are present

## 5. Identity

- [x] 5.1 Implement accounts, roles, and session authentication on the shared origin, and verify sign-in and sign-out feature tests pass
- [x] 5.2 Enforce the three registration modes and verify the closed, invite, spent-invite, and open scenarios from `specs/identity/` all pass
- [x] 5.3 Implement invitations with expiry, revocation, and single use, and verify each terminal state is refused
- [x] 5.4 Implement role authorization including the last-owner protection and `404`-not-`403` for an unauthorized read, verified against the user resource — a forbidden account and a never-issued identifier return byte-identical responses. The link-specific instance of the same rule lands with links in 7.8 and the endpoint sweep in 16.5
- [x] 5.5 Add authentication rate limiting and identical responses for unknown addresses, and verify the enumeration-resistance test passes
- [x] 5.6 Implement scoped API tokens shown once at creation with revocation, and verify in-scope, out-of-scope, and revoked cases behave as specified

- [x] 5.7 Hash passwords with a memory-hard algorithm at a tuned cost, rehash on sign-in when the cost rises, and verify no plaintext reaches any log
- [x] 5.8 Enforce the password policy against length and a bundled commonly-used-password list, and verify a weak password is rejected with the requirement stated
- [x] 5.9 Implement session lifecycle — new identifier on authentication and privilege change, secure/HTTP-only/same-site cookies, other sessions invalidated on password change, sign-out-everywhere — and verify each case
- [x] 5.10 Require recent authentication for sensitive operations, and verify a stale session is challenged while a fresh one proceeds
- [x] 5.11 Store API tokens, invitations, reset tokens and recovery codes as hashes only, and verify a reused reset token is refused and an unknown-address reset is indistinguishable
- [x] 5.12 Prevent self-role changes and grants above the actor's role, and verify both are refused
- [x] 5.13 Grant the Horizon dashboard to the owner role only, and verify an owner reaches it while a non-owner receives `403` (moved from 3.5, which could not authorize against a role model that did not exist yet)

## 6. Domains

- [x] 6.1 Implement the domain resource with primary designation and the refusal to delete the primary or a domain still holding links, and verify the primary refusal by test. The link-count guard is implemented and schema-aware rather than stubbed to zero, but its refusal cannot be exercised until the links table exists — asserted in 7.11
- [x] 6.2 Implement domain verification and the refusal to serve unverified domains, and verify a slug on an unverified domain returns not-found
- [x] 6.3 Implement the certificate authorization endpoint reading the Redis-cached domain list, rate limited, and verify it approves registered hostnames and declines unknown ones
- [x] 6.4 Wire Caddy on-demand TLS to that endpoint and verify the edge container reaches it over the compose network, receiving 200 for a verified host and 404 for an unknown one, with the ask URL matching the registered route. Live ACME issuance needs public DNS and a real certificate authority, so it is verified on a deployed host in 19.3 rather than claimed here

## 7. Link management

- [x] 7.1 Implement the link model and Postgres migrations with slug uniqueness scoped per domain, and verify the same-slug-on-two-domains scenario passes
- [x] 7.2 Implement CSPRNG base62 slug generation with bounded retry and a distinct exhaustion error, and verify collision and exhaustion tests pass
- [x] 7.3 Implement custom slug validation against charset and reserved words, and verify reserved and malformed slugs are rejected
- [x] 7.4 Implement destination validation covering scheme, self-referencing loops, and the blocklist, and verify each rejection is tested
- [x] 7.5 Implement expiry, click limit, password hashing, disabled state, and per-link redirect mode with instance-default fallback, and verify the default-change scenario passes
- [x] 7.6 Implement model-event-driven cache invalidation keyed by `(host, slug)`, verified at the cache layer: create, destination edit, slug change (both old and new keys) and delete each evict the entry. The HTTP-level assertion that the next request observes it belongs to the redirect route and is covered in 8.2 and 8.6
- [x] 7.7 Implement tags and link search by slug, destination, and tag with role scoping, and verify search results respect the requester's role
- [x] 7.8 Implement the link CRUD API with authorization, and verify the endpoint tests pass

- [x] 7.9 Add ULID public identifiers to every exposed resource while keeping integer primary keys, and verify serialised payloads never contain the primary key and that incrementing an exposed identifier resolves to nothing
- [x] 7.10 Reject destinations resolving to loopback, private, link-local, carrier-grade NAT, multicast, reserved or cloud-metadata addresses, and verify literal and DNS-resolved cases are both refused
- [x] 7.11 Verify the domain deletion guard from 6.1 now that links exist: deleting a domain holding links is refused unless deletion is confirmed, and the refusal reports how many links are affected

## 8. Redirect hot path

- [x] 8.1 Register the redirect route ahead of the standard middleware stack and verify a request bypasses session and CSRF middleware
- [x] 8.2 Implement Redis-only resolution with self-contained cache entries, and verify a cache hit issues zero application database queries
- [x] 8.3 Implement negative caching for unknown slugs and verify repeated requests for a non-existent slug issue no database query after the first
- [x] 8.4 Implement single-flight locking on cold resolution and verify concurrent requests for one uncached slug produce a single database query
- [x] 8.5 Implement direct mode returning `302` with no tracking markup and no-store cache headers, and verify the response-shape test passes
- [x] 8.6 Implement constraint evaluation for expired, disabled, and limit-reached links, and verify unauthenticated responses for disabled and never-existed slugs are indistinguishable
- [x] 8.7 Implement the Redis click counter with a reconciliation job, and verify a limited link stops resolving and stays closed after the counter is lost — the cache entry carries the persisted count as a floor. Reconciliation currently persists the counter into Postgres; sourcing it from the event store arrives with the pipeline in 11.7
- [x] 8.8 Implement the password gate that reveals nothing before success, with attempt rate limiting, and verify no response header or body leaks the destination
- [x] 8.9 Implement per-source redirect rate limiting recording no clicks for refused requests, and verify the `429` test passes

## 9. Interstitial mode

- [x] 9.1 Build the self-contained Blade hold page with hand-inlined CSS, and verify the rendered response makes no additional network requests. A dedicated Tailwind entry was rejected: it would put a Node toolchain in the production API image to emit ~2KB of CSS, and branding's runtime custom properties already share the tokens (recorded in design.md)
- [x] 9.2 Implement the HMAC-signed single-use click token and verify a forged or replayed beacon submission is rejected
- [x] 9.3 Implement the beacon collecting viewport, screen, timezone, language, colour-scheme, connection type, and dwell time, and verify the signals are recorded against the click identifier with out-of-range values refused. Attachment to the persisted event happens where events exist, in 10.3
- [x] 9.4 Implement the scripting-disabled fallback via meta refresh and a visible link, and verify the visitor reaches the destination with server-observable data only
- [x] 9.5 Apply the configured per-link referrer policy on navigation and verify the emitted policy matches the setting
- [x] 9.6 Verify with a Playwright test that the hold page brands correctly, navigates after the configured delay, and records exactly one click

## 10. Click pipeline

- [x] 10.1 Implement fire-and-forget enqueue after the response is sent, and verify a redirect succeeds and stays within its latency budget while ClickHouse is stopped
- [x] 10.2 Implement prefetch and `HEAD` rejection and verify no click is recorded for prefetch headers or `HEAD` requests
- [x] 10.3 Implement user-agent bot matching and datacenter-ASN classification stored as a flag, and verify automated traffic is excluded from counts yet remains queryable
- [x] 10.4 Wire the geoipupdate sidecar and shared volume, implement in-process GeoLite2 reading with reload on file change, and verify the unresolved and missing-database cases degrade to non-geographic while still recording the click. The resolved case needs a real MaxMind licence key, which this instance has none configured for; asserted against a stubbed resolver and covered on a keyed host in 19.3
- [x] 10.5 Implement user-agent parsing for device, operating system, and browser, and verify the parser's unit tests pass
- [x] 10.6 Implement the rotating-salt visitor hash and verify persisted events contain no network address and that rotation changes the identifier
- [x] 10.7 Implement the deduplication window and verify two events for one visitor and link within it count as one
- [x] 10.8 Implement batched ClickHouse inserts draining the queue, and verify a batch of events lands in one insert
- [x] 10.9 Verify the ordered pipeline end to end: filtered traffic performs no geo lookup, and a real click arrives enriched in ClickHouse

## 11. Analytics storage and reporting

- [x] 11.1 Write the events table as `MergeTree` ordered by `(link_id, occurred_at)` with a retention `TTL`, and verify expired events are removed while aggregates remain
- [x] 11.2 Write the `AggregatingMergeTree` materialized views for hourly totals and country, referrer, device, and browser breakdowns, and verify an insert updates them without a job
- [x] 11.3 Implement reporting queries served only from aggregates with instance-timezone bucketing, and verify a twelve-month report over 300,000 events answers in under a second, and that no report query reads the raw events table — asserted against the tables each query actually touched, not against its text
- [x] 11.4 Implement paginated raw-event drill-down for a link and verify the endpoint tests pass
- [x] 11.5 Implement click-event export excluding network addresses and verify the exported file contains the period's events and no address column
- [x] 11.6 Verify unique counts are reported per period and never summed across periods
- [x] 11.7 Reconcile the Redis click counters against the event store rather than against the persisted count, and verify a counter drifting from recorded events converges to the event total

## 12. Web foundation, design system, and branding

- [x] 12.1 Install the chosen libraries (base-ui, cmdk, Sonner, motion, NumberFlow, recharts, Virtuoso, zustand, clsx, cva, next-themes) and verify `pnpm build` and `pnpm typecheck` still pass
- [x] 12.2 Set up the typeface trio (Geist, Geist Mono, Instrument Serif) self-hosted from npm with no build-time network, and verify in a browser that the resolved font stack names no banned face. A lint rule additionally forbids `next/font/google`, which would reintroduce a build-time fetch
- [x] 12.3 Set up Phosphor icons at one standardized weight behind a single icon module, and verify a lint rule fails on any import from another icon package
- [x] 12.4 Establish the OKLCH token set mapped through Tailwind `@theme inline` to CSS custom properties, and verify utilities resolve to variables rather than literal colours in the built CSS
- [x] 12.5 Establish the motion token layer (`--ease-out`, `--ease-in-out`, `--ease-drawer`, the duration scale) and verify no component declares a raw `cubic-bezier` or bare millisecond value
- [x] 12.6 Add the lint rules banning `transition: all`, `scale(0)` entrances, `ease-in` on UI elements, and animation of layout-triggering properties, and verify each rule fails on a deliberate violation fixture
- [x] 12.7 Wire `next-themes` with the `[data-theme]` attribute and verify no light/dark flash on a cold load in either system preference
- [x] 12.8 Implement server-side branding injection into the initial document and verify no default-accent paint occurs before branded styling applies
- [x] 12.9 Implement derived accent states by OKLCH lightness and chroma shifts, and verify hover, active, and muted variants are generated from a single accent input
- [x] 12.10 Implement the contrast check warning on accent colours failing the threshold in either mode, and verify body and heading text still pass under any accent
- [x] 12.11 Enforce the branding bounds (single accent hue, radius clamped to range, typeface from the curated list) and verify out-of-range radius and off-list typeface are both rejected with their permitted values stated
- [x] 12.12 Implement branding asset upload with type and size validation, and verify unsupported and oversized assets are rejected with the limits stated
- [x] 12.13 Build the primitive component set on base-ui (dialog, sheet, popover, select, tooltip, menu) with `var(--transform-origin)` wired for trigger-anchored surfaces, and verify keyboard focus, dismissal, and focus return behave correctly in each
- [x] 12.14 Apply the interaction feedback baseline — `scale(0.97)` press at 100–160ms, hover motion gated behind `@media (hover: hover) and (pointer: fine)` — and verify a tap on a touch device fires no hover state
- [x] 12.15 Build the asymmetric bento application shell with hairline borders, clamped radii, no stock Tailwind shadows, and generous internal padding, and verify it collapses to a single column below 768px with all span overrides reset
- [x] 12.16 Add the `prefers-reduced-motion` variants across the component set and verify movement is removed while opacity and colour transitions that aid comprehension remain
- [x] 12.17 Verify the frequency gate holds in the built interface: the command palette and keyboard navigation have no open/close animation, table sort and filter do not transition rows, and no dashboard surface uses scroll-entry motion

- [x] 12.18 Plumb per-request CSP nonces through the web app and emit a policy with no inline or eval allowance, and verify an injected script without a nonce does not execute and that nonces differ between responses
- [x] 12.19 Determine branding upload format by decoding, refuse SVG, cap pixel dimensions before full decode, re-encode to strip metadata, and store under a generated name, and verify a renamed non-image and an oversized image are both refused

## 13. Setup experience

- [x] 13.1 Implement installation-state detection gating all routes, and verify an uninstalled instance redirects the interface to setup and returns `503` from authenticated API endpoints. The gate is ordered ahead of authentication through the framework's middleware priority list, not just the route group: declaring it first on the group alone still let `auth:sanctum` answer `401` to a caller no credential could satisfy
- [x] 13.2 Implement the dependency connectivity step and verify an unreachable datastore names the dependency and blocks advancement. The probes are shared with the health listener rather than duplicated, and failure reasons come from a fixed vocabulary because a driver's own message carries the DSN, and a DSN carries a password
- [x] 13.3 Build the wizard steps for administrator, instance identity and primary domain, branding, analytics, registration mode, and mail, and verify a skipped mail step still completes installation. A step is refused while any predecessor is outstanding, so the finish line is unreachable without an owner account. The connectivity step reports its result and waits for the operator rather than advancing on success — the requirement is to report *and* unlock, and auto-advancing meant the check was never seen
- [x] 13.4 Implement resumable progress and verify reloading mid-wizard resumes at the first incomplete step. Progress is recorded explicitly in the settings store rather than inferred from the data each step wrote: branding and analytics have defaults, so "has a value" cannot tell a submitted step from an untouched one
- [x] 13.5 Implement permanent setup closure and verify the route returns `404` and submissions change nothing after installation, asserted at the API and again in the browser against the rendered page
- [x] 13.6 Implement `shortynah:install` accepting the same configuration, and verify fresh success, non-zero exit on an installed instance, and non-zero exit naming a missing value. The missing-value case is asserted under `--no-interaction`, which is what deployment automation actually runs with and the signal the command reads
- [x] 13.7 Verify the full wizard with a Playwright run from fresh instance to signed-in dashboard. The run reads the token from the host-mounted file rather than the log, so it exercises the recovery path the design promises. `make e2e` is now ordered — the wizard suite runs against an uninstalled instance and leaves it installed, then the fixture seeds what the rest of the suite drives. The browser run caught a real defect the API tests could not: the wizard's client performed no CSRF handshake, which only fails outside the testing environment

- [x] 13.8 Generate the setup token on first boot, emit it to the log and a host-mounted file, require it before the wizard accepts configuration, invalidate it on completion, and verify it survives a restart while uninstalled and grants nothing afterwards. Only the digest is stored; the plaintext exists in the log and the `0600` host file, which is what makes it recoverable without database access
- [x] 13.9 Restrict the connectivity step to configured dependencies, and verify a supplied host or connection string is ignored — asserted by confirming the supplied host is never dialled, not merely that the response looked the same

## 14. Operator interface

- [x] 14.1 Build authentication screens honouring the active registration mode, and verify the interface hides registration when closed — the route returns `404` rather than rendering a refusal, and flipping the setting makes it appear and disappear with no restart. Needed one API addition the phase did not describe: `GET /api/v1/auth/user`, because every authenticated screen has to know the viewer's role and the browser should not be asked to remember it across a reload
- [x] 14.2 Build link creation and editing in a base-ui sheet including custom slug, domain, mode, expiry, password, click limit, and tags, and verify server validation errors surface per field. Creation and editing are one surface — identical fields, one word different — and the per-field assertion drives a destination the API refuses after DNS resolution, so it exercises the real refusal rather than a shape test
- [x] 14.3 Build the link list with search, domain, owner, and tag filtering, and verify results match the API under each role. Filtering resolves against the page the server already sent rather than round-tripping per keystroke, which is the one thing a server-first interface does badly; the API's own filters remain for cases needing the whole corpus. Write actions are absent, not merely disabled, for an account that cannot write
- [x] 14.4 Add the cmdk command palette for link search, creation, and navigation with no open/close animation, and verify it opens on the keyboard shortcut and returns focus on dismissal. Focus restoration is deferred by a frame: the dialog runs its own restoration on close and a synchronous call is simply overwritten by it, which the browser test caught
- [x] 14.5 Wire Sonner for action feedback with copy-link, save, and delete flows, and verify toasts follow the active colour mode and survive rapid retriggering without restarting. Each flow uses a stable toast id, so hammering an action replaces the toast in place instead of queueing duplicates. Colour mode is asserted against the rendered background matching the page's own resolved surface token, not against a class name
- [x] 14.6 Build the analytics dashboard on aggregate endpoints with recharts time-series and breakdown views, and verify rendered figures match the API responses. Clicks and unique visitors are small multiples rather than one chart with two scales — they are different measures, and the alternative to a second axis is two figures. Every chart is therefore single-series, which means no categorical palette is needed at all: the operator's accent carries magnitude and the validator passes it against both surfaces. Breakdowns are plain markup, since one measure over one dimension with no axis does not justify a charting runtime
- [x] 14.7 Apply NumberFlow to counters that change while the viewer is watching, and verify no count-up animation runs on initial page load — the figure a viewer came to read is present immediately and asserted not to move afterwards
- [x] 14.8 Build raw-click drill-down virtualized with Virtuoso over the paginated endpoint, and verify a page of several thousand rows scrolls without frame drops. Verified against 3000 events with the property that makes it scroll — only the window is ever in the document, never the corpus — rather than a frame-rate sample, which is not reproducible in CI. The corpus is inserted directly by `shortynah:e2e-clicks` because the redirect is rate limited per address and cannot produce thousands of events from one host
- [x] 14.9 Build click-event export and verify the export downloads for an authorized operator, asserting the CSV also carries no address column — raw addresses are never persisted, so an export must not be able to contain one
- [x] 14.10 Build settings screens for branding, analytics, registration, domains, and mail, and verify each change takes effect without a restart. The screens needed API work the phase did not describe: only branding had a write endpoint, so `GET`/`PUT /api/v1/settings` was added, authorised against the registry rather than an allowlist of its own — a setting carries a `writable` flag the way it already carries `exposed`, so one added later is inert until its definition says otherwise. Branding stays on its own endpoint because it has bounds to enforce, and two write paths with different rules is how one becomes the way round the other. A rejected request writes nothing from the same payload, and a sensitive value submitted as null is left alone rather than cleared
- [x] 14.11 Build the branding editor with live preview in both colour modes, and verify the contrast warning appears before an unreadable accent can be saved. Both modes are judged at once rather than only the one being rendered, because an accent that is legible on one surface and invisible on the other is the common failure. The warning is not advisory: saving is refused, and the suggested alternative moves lightness only, so the hue the operator chose survives
- [x] 14.12 Build user and invitation management restricted by role, and verify a viewer cannot reach write actions — the surface answers 404 rather than rendering a refusal. An issued invitation code is shown once and is gone after a reload, because only its hash is stored. Revocation is presented as a state the record keeps rather than a deletion, which is what the API already does: who was offered access stays part of the history. Membership changes require recent authentication, so a 423 is surfaced as a request to sign in again rather than as an error
- [x] 14.13 Design the empty states for links, analytics, and search with the serif heading treatment, and verify each states the next action rather than only reporting absence — asserted by the presence of the action itself, and by the heading resolving to the editorial face rather than merely carrying a class that claims it
- [x] 14.14 Verify keyboard navigation and screen-reader labelling across primary flows: sign-in is completed without a pointer, every field on the link sheet is reachable by its visible label, no icon-only control lacks an accessible name, each page exposes exactly one first-level heading, the current page is marked in navigation, and keyboard focus keeps a visible ring

- [ ] 14.15 Build the audit log viewer for owners with actor, action and period filtering, and verify entries list newest first and cannot be edited or deleted from the interface. **Blocked**: the audit log itself is phase 16 and has not been built, so there is nothing to view. This task closes with 16, not with the rest of 14

## 15. Operations

- [ ] 15.1 Implement graceful worker shutdown returning unfinished jobs to the queue, and verify a job interrupted by a termination signal is completed or requeued
- [ ] 15.2 Order deployment so schema is applied before new application processes serve traffic, and verify a version deploy applies both stores' schema first
- [ ] 15.3 Implement backup covering application data, event data, and uploaded assets, and verify it produces all three artefacts on a running instance
- [ ] 15.4 Verify restore onto a clean host yields resolving links and available historical reports
- [ ] 15.5 Verify a built image contains no instance credentials, keys, or licence values in its layers or environment

## 16. Authorization and audit

- [ ] 16.1 Implement the authorization layer so every object reference is checked against the acting identity and scope is derived server-side, and verify a client-supplied owner or domain reference is ignored
- [ ] 16.2 Return `404` rather than `403` for unauthorized reads of existing objects, and verify the response does not distinguish a forbidden object from a missing one
- [ ] 16.3 Create the append-only audit table and revoke `UPDATE` and `DELETE` from the application's database role, and verify the application cannot alter an entry even through a direct query
- [ ] 16.4 Record audit entries for authentication outcomes, role changes, invitations, tokens, second-factor changes, domain changes, settings changes, link password changes, exports and installation, and verify each event produces one entry with actor, action, target, derived source and time
- [ ] 16.5 Sweep every endpoint against the IDOR checklist in `specs/security/`, and verify each nested and cross-owner access path is covered by a test

## 17. Two-factor authentication

- [ ] 17.1 Implement authenticator-app enrolment with confirmation, and verify a wrong confirmation code does not activate the factor
- [ ] 17.2 Enforce the second factor during sign-in so no session is established until it is satisfied, and verify a correct password alone grants nothing
- [ ] 17.3 Reject replayed one-time codes within their validity window, and verify a second submission of an accepted code is refused
- [ ] 17.4 Issue, hash and consume single-use recovery codes, and verify a used code cannot be reused and the remaining count is reported
- [ ] 17.5 Implement WebAuthn passkey registration and authentication, and verify a registered credential authenticates and is listed with its creation date
- [ ] 17.6 Implement the instance-wide second-factor requirement, and verify an account without one is confined to enrolment and cannot remove its only factor while the requirement is active

## 18. Supply chain and release gates

- [ ] 18.1 Add dependency advisory scanning for both applications to CI, failing on high or critical, and verify it fails against a deliberately vulnerable pinned dependency
- [ ] 18.2 Add repository secret scanning to CI, and verify it fails on a planted credential-shaped string
- [ ] 18.3 Add built-image vulnerability scanning, failing on high or critical, and verify it reports against a known-vulnerable base
- [ ] 18.4 Add a check that every base image is digest-pinned, and verify it fails on a tag-only reference
- [ ] 18.5 Add automated dependency update proposals, and verify a proposal opens against an outdated dependency

## 19. Verification and delivery

- [ ] 19.1 Run the full quality gate — Pint, Larastan, Pest, ESLint, typecheck, Vitest, Playwright — and verify every check passes
- [ ] 19.2 Verify every scenario in `specs/` has a corresponding automated test, and list any deliberate exceptions with a reason
- [ ] 19.3 Verify a clean-host bring-up reaches the setup flow with no manual step between the bring-up command and the wizard
- [ ] 19.4 Conduct a motion review over the delivered interface against the frequency gate and the implementation contract, inspecting animations at 2–5× duration, and resolve the findings
- [ ] 19.5 Conduct a code review pass over the delivered implementation and resolve the findings
- [ ] 19.6 Write `README.md` covering the project, its feature set, deployment, and configuration, verified by following its instructions on a clean host
