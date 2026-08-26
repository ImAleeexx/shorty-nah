# Shorty-Nah

A self-hosted URL shortener for people who would rather not hand their
destination URLs and visitor data to somebody else.

Single tenant, multi-domain, and private by default. It runs on one host behind
Docker Compose, sets itself up through a browser on first boot, and can be
branded to the point where nobody would guess what it started as.

## What it does

**Two redirect modes, chosen per link.** `direct` is a `302` served from Redis
without touching Postgres — the fast path, and the one that must never regress.
`interstitial` holds on a branded page while a beacon collects what a server
cannot see, then navigates. The default is instance-wide; any link may override
it.

**Analytics you can trust, which mostly means counts that are not inflated.**
Prefetches are dropped — `Sec-Purpose`, `Purpose`, `X-Purpose`, and every `HEAD`
request. Known bots are dropped by user agent and by datacenter network. A
repeated click from one visitor inside a short window is absorbed. What is left
is people.

**No raw addresses, anywhere.** Geography and network are resolved during
enrichment and the address is discarded. A unique visitor is a hash of address,
user agent, and a salt that rotates daily — not reversible, and not comparable
across rotations. A dump of the event store cannot be turned back into a list of
who visited.

**Branding without a rebuild.** One accent colour drives every derived state, a
radius, a typeface, a logo and a wordmark. The editor judges contrast in light
and dark at once and refuses to save an accent that cannot be read in either.

**Links that expire, count down, or ask for a password.** Generated slugs come
from a 58-character alphabet with the ambiguous characters removed, because
somebody will read one aloud. Slugs you choose yourself use a wider set, because
`launch` contains an `l`.

**Accounts, roles, and a second factor.** Authenticator apps and passkeys, with
single-use recovery codes. Registration is closed, invitation-only, or open — a
setting, not a build flag. Every security-relevant event lands in an audit log
the application's own database role cannot rewrite.

## Requirements

- A host with Docker and Docker Compose
- A domain name pointing at it, if you want automatic certificates
- Roughly 2 GB of memory to be comfortable

A [MaxMind](https://www.maxmind.com/en/geolite2/signup) licence key is optional
and free. Without one, geography is simply absent rather than wrong.

## Getting started

```bash
git clone <this repository>
cd Shorty-Nah
cp .env.example .env
```

Fill in `.env`. Every value it asks for is infrastructure — credentials, hosts,
the domain. Everything an operator can change later lives in the settings store,
not here.

Generate the application key and the passwords:

```bash
docker run --rm dunglas/frankenphp:1-php8.4 php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
openssl rand -hex 24   # once for each password in .env
```

Then bring it up:

```bash
make setup
```

That builds the images, starts every service, applies both schemas, and prints
your setup token. Open `https://your-domain/` and the wizard is waiting.

### The setup token

An instance is reachable the moment DNS resolves, and its certificate reaches the
public transparency logs within seconds. The wizard's first step creates the
owner — so without a gate, the first stranger to find the host owns the instance.

First boot therefore generates a token and refuses all configuration until it is
presented. It is printed to the container log and written to `run/setup-token` on
the host. To see it again:

```bash
make setup-token
```

It stops working the moment installation completes.

### Without a browser

For scripted deployments:

```bash
docker compose exec api php artisan shortynah:install \
  --no-interaction \
  --admin-name="Your Name" \
  --admin-email="you@example.com" \
  --admin-password="a long passphrase you will remember" \
  --instance-name="Links" \
  --domain="go.example.com"
```

## Configuration

Two kinds, kept apart deliberately.

**`.env` is infrastructure.** Datastore credentials, the application key, the
domain, the trusted-proxy range. Changing one needs a restart.

**Everything else is a setting**, stored in Postgres, cached in Redis, and
changed through the interface. Branding, retention, bot filtering, registration
mode, mail, the default redirect mode. None of it needs a restart, and none of it
belongs in a file.

A few values in `.env` are worth understanding:

| Value | Why it matters |
|---|---|
| `TRUSTED_PROXIES` | The edge's network, never `*`. Trusting every peer lets any client spoof its address, which defeats rate limiting and forges every geographic figure. |
| `DB_USERNAME` / `DB_APP_USERNAME` | Two roles on purpose. The first owns the schema; the application connects as the second, which holds no `UPDATE` or `DELETE` on the audit table. That missing privilege is what makes the audit log append-only. |
| `BACKUP_KEY` | Encrypts backups. They contain the settings store and the key that decrypts its secrets, so losing this makes every backup unreadable — which is the point. |

## Running it

```bash
make up          # start
make down        # stop
make logs        # follow everything
make ps          # what is running, and whether it is healthy
make backup      # encrypted application data, click events and uploads
make restore DIR=backups/20260827T090000Z
```

Backups write to `backups/`. Restore decrypts all three artefacts before applying
any of them, so a wrong key fails having written nothing.

## Architecture

```
                    ┌─────────┐
   visitor ────────▶│  Caddy  │──── short domain ─────▶ Laravel (redirect)
                    │  (TLS)  │                              │
   operator ───────▶│         │──── /api/* ──────────▶ Laravel (API)
                    └─────────┘──── everything else ──▶ Next.js
                                                             │
        Redis ◀── click envelope ── (fire and forget) ───────┘
          │
          └──▶ Horizon worker ──▶ bot filter, GeoLite2, UA parse ──▶ ClickHouse
                                                                        │
                                                          rollups ◀─────┘
```

Postgres holds the application. ClickHouse holds click events and the aggregates
reports are built from — dashboards read rollups, never raw events. Redis carries
the cache, sessions, and the click queue.

The redirect path resolves `(host, slug)` from Redis alone. A cache hit never
touches Postgres, unknown slugs are negatively cached so scanning cannot hammer
the database, and the click envelope is pushed and forgotten. A failed analytics
write must never become a failed redirect.

## Development

```bash
make up          # the stack, with source mounted
make ci          # every gate: lint, static analysis, types, tests, invariants
make e2e         # the browser suite, wizard first
make scan        # dependency, secret and image scanning
```

More detail — including the traps that cost real time — is in `CLAUDE.md`.

## Security

The contract is in `openspec/changes/bootstrap-url-shortener/specs/security/`.
The parts worth knowing without reading it:

- Unauthorized reads answer `404`, never `403`. A `403` confirms the object
  exists.
- Public identifiers are ULIDs. Integer keys never appear in a URL or a payload.
- Issued secrets — API tokens, invitations, recovery codes, the setup token — are
  stored hashed and shown once.
- The content security policy carries no `unsafe-inline`. Inline style and script
  are authorised per request by nonce.
- Destinations resolving to loopback, private, link-local, CGNAT, multicast,
  reserved or cloud-metadata addresses are refused, after DNS resolution rather
  than on the literal string.
- Backups are encrypted. Images carry no instance credentials, and CI fails if
  one appears.

## Licence

Not yet chosen.
