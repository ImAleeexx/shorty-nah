## Why

There is no product yet — this change creates Shorty-Nah from an empty repository. The goal is a
self-hosted URL shortener that an operator can deploy on one host and run privately, with analytics
accurate enough to trust for real decisions and branding deep enough that it does not look like
someone else's tool. Existing hosted shorteners require handing over destination URLs and visitor
data; existing self-hosted ones generally trade away either analytics depth or deployment simplicity.

## What Changes

- Establish a two-application monorepo: a Laravel 13 API on Octane/FrankenPHP and a Next.js 16 web
  client, orchestrated by Docker Compose behind a Caddy edge.
- Introduce link management: creation, editing, CSPRNG slugs, custom slugs with a reserved-word
  blocklist, expiry, password protection, and click limits.
- Introduce two redirect modes selectable per link — `direct` (a `302` on a Redis-only hot path) and
  `interstitial` (a branded hold page whose beacon collects client-side signals before navigating).
- Introduce a click analytics pipeline: queued capture, GeoLite2 and user-agent enrichment, bot and
  prefetch rejection, batched writes into ClickHouse, and aggregated rollups for reporting.
- Introduce a first-boot setup experience (OOBE) plus a headless `shortynah:install` equivalent, both
  writing to a runtime settings store rather than to `.env`.
- Introduce runtime branding: colours, radius, logo, wordmark, and typography change without a
  rebuild, across one theme with light and dark modes.
- Introduce authentication with a configurable registration mode (`closed`, `invite`, `open`) and
  role-based authorization.
- Introduce multi-domain support: several short domains served by one instance, with a validation
  endpoint that gates Caddy's on-demand certificate issuance.
- Store no raw visitor IP addresses; geo and ASN are resolved during enrichment and the address is
  discarded.
- Gate the setup flow behind a token generated at first boot and readable only from the container log or a
  host-mounted file, so an instance reachable before installation cannot be claimed by a stranger.
- Introduce two-factor authentication with authenticator-app codes and WebAuthn passkeys, optional per
  account and enforceable instance-wide.
- Introduce an append-only audit log of security-relevant events.
- Establish instance-wide security rules: authorization of every object reference, non-enumerable public
  identifiers, a trusted-proxy contract for client addresses, hardened response headers with a
  nonce-based content security policy, abuse limits on every unauthenticated surface, and supply-chain
  scanning that blocks a release.

## Capabilities

### New Capabilities

- `instance-setup`: First-boot detection, the setup wizard, headless installation, and the runtime
  settings store that every other capability reads its configuration from.
- `identity`: Accounts, sessions, API tokens, roles, invitations, and the configurable registration
  mode.
- `domains`: Registration and verification of short domains, and the certificate-authorization
  endpoint the edge proxy consults before issuing a certificate.
- `link-management`: The link resource and its lifecycle — slug generation, custom slugs, validation
  of destinations, expiry, passwords, click limits, and cache invalidation on write.
- `redirection`: Resolution of an incoming `(host, slug)` request and the behaviour of both redirect
  modes, including the guarantees that keep the direct path fast.
- `click-analytics`: Click event capture, the enrichment and filtering rules that define an accurate
  count, event storage and retention, and the reporting surface built on rollups.
- `branding`: Operator-controlled visual identity applied at runtime, and light/dark behaviour.
- `deployment`: The container topology, health checks, migration and schema application flow, backups,
  and the environment contract for a single-host deploy.
- `security`: The rules no single feature owns — authorization of object references, identifier
  enumeration, the trusted-proxy contract, response hardening and the script policy, abuse limits, the
  audit log, diagnostic redaction, and supply-chain scanning.

### Modified Capabilities

None — this is the first change in the repository.

## Impact

- **New code**: `apps/api` (Laravel), `apps/web` (Next.js), `docker/` (images, Caddyfile,
  entrypoints), root Compose files and a `Makefile`.
- **Datastores**: Postgres 17 for application data, ClickHouse for click events, Redis for cache,
  sessions, and queues. ClickHouse schema is applied by a dedicated command, separate from Laravel's
  migrator.
- **Runtime processes**: Octane/FrankenPHP worker, Horizon queue worker, scheduler, Next.js server,
  Caddy edge, and a `geoipupdate` sidecar.
- **External dependencies**: a MaxMind account for a GeoLite2 licence key, and public DNS pointing at
  the host for automatic certificates.
- **Operational commitments**: because Octane keeps workers alive across requests, request-scoped
  state must not be held in singletons or static properties; this constrains how services are bound
  throughout the API.
- **Security posture**: the trusted-proxy contract is load-bearing for both rate limiting and analytics
  accuracy, and the nonce-based content security policy constrains how the interstitial and the
  interface emit inline style and script. Both are far cheaper to establish now than to retrofit.
