# Verification

What proves each requirement in this change, and the one that cannot be proved
here.

## Redirection — rule-based routing

| Requirement | Proved by |
|---|---|
| A link may route by rule | `LinkRoutingRuleTest` — matching, falling through, and first-match-wins, all driven through the real redirect route |
| Rule conditions are limited to four kinds | `LinkRoutingRuleTest`, including a malformed value for each kind and `referrer` refused outright |
| Rules are evaluated without a database query | `LinkRoutingRuleTest` counts queries through `DB::listen` on a warm cache and asserts zero |
| Rule changes are recorded in the audit log | `LinkRoutingRuleTest` |
| The hot path performs no database query on a cache hit | `RedirectHotPathTest`, plus `make bench` against a recorded baseline |

The benchmark deserves its own note. The budget is 150us on the **median**, and
`design.md` records why it is not the mean: five runs of identical code spread
+33us to +195us on the mean, so the gate failed two runs in five with nothing
changed. On the median the same five runs spread 30us. Measured cost of
everything this change puts on the redirect path: **+65 to +94us** against a
530.67us baseline.

## Click analytics — geography moved

| Requirement | Proved by |
|---|---|
| Raw visitor addresses are never persisted | `ClickEnvelopeGeographyTest` asserts over the whole serialised queue payload, not one key — the address is nowhere in it, rather than a field having been renamed |
| The visitor hash is computed before the address is discarded | `ClickEnvelopeGeographyTest`, and the salt-rotation case in `ClickEnrichmentTest` |
| Enrichment does not repeat work already done | `ClickEnvelopeGeographyTest` counts resolver calls and asserts none |
| End to end after the move | `ClickPipelineTest` against real ClickHouse: a redirect produces a row carrying the resolved country, with no address at any stage |
| No geographic databases present | `ClickPipelineTest` — unknown country, still a counted click with a usable visitor hash |

## Link management — QR, campaign parameters, bulk transfer

| Requirement | Proved by |
|---|---|
| Every link has a QR code | `QrCodeTest`, including sampling every module centre out of the rendered PNG against the encoder's own matrix |
| Contrast is insufficient to scan | `QrCodeTest` — falls back to ink and says so in a header |
| Scans are distinguishable | `QrCodeTest` — the scan is marked, and an ordinary click is not |
| Destinations may be composed with campaign parameters | `campaign.test.ts` (unit) and `toolkit.spec.ts` (browser) |
| Links can be imported in bulk | `LinkTransferTest` — every row, one bad row alone, a private destination, a taken slug, a dry run, and a round trip |
| Links can be exported in bulk | `LinkTransferTest` — scope honoured, and a protected link records that it is protected without its password or hash |

The QR assertion is non-vacuous by construction: shifting the quiet zone by one
module makes it fail, which was confirmed by doing it.

## Integrations — webhooks

| Requirement | Proved by |
|---|---|
| An operator may register endpoints | `WebhookTest`; the secret is shown once and absent from every later representation |
| Deliveries are signed and verifiable | `WebhookTest` recomputes the HMAC exactly as a receiver would, over the timestamp and the raw body |
| Delivery happens outside the request | `WebhookTest` — queued, on its own queue, and a click delivery fires from the drain worker rather than the redirect |
| Failed deliveries are retried, recorded and replayable | `WebhookTest` |
| Deliveries carry no secrets | `WebhookTest` — no address, no visitor hash, no password, no secret |

The signing secret is stored **encrypted, not hashed**, and `WebhookTest` reads
the raw column to prove a database dump alone yields nothing. This is a
deliberate departure from how every other issued secret here is stored, and the
reason is in `design.md`: an HMAC needs the value, and a hash cannot sign.

## Interface

`toolkit.spec.ts` drives all five features through a browser: composing campaign
parameters onto the destination, reading them back without duplicating them,
rendering a QR code that actually decodes, saving and reordering rules, an import
that reports the row it refused, and a webhook secret that is gone after a reload.

## Exception

### A delivery that exhausts its retries over real time — `integrations`

> **WHEN** every attempt fails
> **THEN** the delivery is recorded as failed and is not attempted again automatically

What is proved: a failing endpoint records each attempt with its status, the job
throws so the queue retries it, the backoff is configured at 10s/60s/300s, and
`failed()` marks the delivery failed. What is not proved in a sitting is the
queue walking that backoff to exhaustion, because doing so takes six minutes of
wall clock per delivery and the test suite runs under ten seconds.

**Closes when** a delivery is watched to exhaustion against a running Horizon, or
the backoff is made injectable so the same path can be driven in milliseconds —
the second option is cheap and probably right, and is deliberately not being done
inside this change.
