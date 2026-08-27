## Context

Five features, one of which touches the redirect hot path. The other four are additive and carry no
architectural risk; this document is mostly about the one that does, and about the two decisions in
the rest that could reasonably have gone the other way.

## Decision: geo resolves on the redirect path, on every request

Country rules cannot be evaluated during enrichment, because enrichment happens after the visitor has
already been sent somewhere. So a country rule requires the address to be resolved before the redirect
returns.

Three options were considered.

**Resolve lazily, only when the resolved link carries a country rule.** Cheapest: links without rules
pay nothing. Rejected because it leaves two code paths that resolve geo — the hot path for rule-using
links and the enricher for everything else — and because it keeps the raw address on the queue, which
is the thing worth removing.

**Resolve always, on every redirect.** Chosen. Every redirect pays one memory-mapped MaxMind read.
There is no network call and no database query; the reader is opened once per Octane worker and reused.
In exchange the enricher stops resolving geo entirely, so the total work per click goes *down* — it
just moves from the queue worker to the request. The decisive argument is not performance: it is that
the envelope can now carry a country and an ASN instead of an address, so a raw IP never enters Redis.

**Resolve nowhere; drop country as a condition.** Rejected by the operator after the trade was put to
them explicitly.

The reader must be resolved from the container per request and never held in a property of a singleton
— an mmdb handle in a long-lived worker is exactly the kind of state the Octane rules exist for.

### The measured baseline, and what the budget can actually prove

Recorded before any change, on the development stack, with:

```
php artisan shortynah:bench-redirect --iterations=2000 --warmup=400 \
    --record=storage/bench/redirect-baseline.json
```

Three consecutive runs, mean microseconds per request through the HTTP kernel
against a cached link: **470.92, 532.00, 540.26** (p50 447.75, 521.67, 530.67).

Run-to-run variance is therefore around 70us, or roughly 15%. **The budget is
150us on the mean**, and it is worth being explicit about what that can and
cannot establish. A memory-mapped MaxMind read costs single-digit to low
double-digit microseconds — comfortably inside the noise, so this harness cannot
measure the lookup itself and does not claim to. What it can catch is the failure
that actually matters: a network call, a socket, or a database query arriving on
this path, each of which costs milliseconds and would clear 150us by an order of
magnitude. That is the regression worth a gate.

The benchmark drives 256 addresses across eight real public prefixes. One address
would measure the per-source rate limiter refusing the request rather than the
redirect serving it — which is exactly how an early run of this harness reported
a pass that was really a `429`.

### The guarantee that replaces "no work on the hot path"

The original guarantee — a cache hit touches nothing but Redis — is preserved and narrowed: a cache hit
performs no *database* query and no *network* call. A memory-mapped read of a local file is admitted to
the path, and a benchmark task asserts the added cost against a stated budget rather than leaving it to
judgement.

When the databases are absent, `GeoLookup` already answers `unknown`. A country rule against an instance
with no MaxMind data therefore never matches, and the link falls through to its own destination. That is
the correct failure: a rule that cannot be evaluated must not silently capture traffic.

## Decision: rules are baked into the cached payload

A rule set is part of `ResolvedLink` and travels in the cache entry, so evaluating rules costs no lookup.
This is the same reasoning that put expiry, click limits and the referrer policy there.

The consequence is that editing a rule must invalidate the link's cache entry, which the existing model
events on `Link` already do — but rules live in their own table, so those events must fire from the rule
rows as well. A rule change that does not invalidate is a link that keeps routing by yesterday's rules
for an hour.

Rules are evaluated in explicit `position` order and the first match wins. No weighting, no randomness:
an operator reading the list top to bottom must be able to say where a given visitor lands.

## Decision: UTM parameters are written onto the destination

Two options: store the parameters as columns and compose the final URL at redirect time, or write them
into the destination when the link is saved.

Composing at redirect time is more flexible and was rejected: it adds string work to the hot path for a
feature that is entirely an authoring convenience, and it makes the stored destination disagree with
where visitors actually land — which is the field an operator reads when auditing a link.

Writing them at save time means the destination column is the truth, the hot path is untouched, and the
builder is a pure interface affordance over a field that already exists. The cost is that editing a UTM
value after the fact means re-parsing the destination, which the builder does.

## Decision: webhook delivery is a queued job per endpoint per event

Deliveries go through Horizon on their own queue, so a slow or dead endpoint cannot delay clicks or mail.
Each delivery is signed with an HMAC over the raw body using a per-endpoint secret, shown once at
creation and stored hashed — the same contract as API tokens.

Retries are bounded and backed off, and a delivery that exhausts them is recorded as failed rather than
discarded, because an operator debugging a missed event needs to see the attempt. The delivery log is
capped by the same retention setting that bounds raw click events.

Click webhooks fire from the drain worker on the enriched click, not from the redirect. A webhook that
fired on the hot path would put an operator's endpoint between a visitor and their destination.

## Decision: import is a queued batch with per-row outcomes

A synchronous import of ten thousand rows either times out or holds a request open for minutes. The
upload is accepted, validated for shape, and queued; the operator watches progress and downloads a
result CSV that carries every input row plus its outcome.

Rows are validated individually against `LinkService` — the same path single creation uses, including
destination resolution and the loopback refusal — so an import cannot become a way around a rule that
applies everywhere else. One bad row fails that row, not the batch.

## Risks

- **The hot path regresses.** Mitigated by a benchmark task with a stated budget, run before and after.
- **A rule set becomes unreadable.** Mitigated by capping rules per link and by ordering being explicit
  rather than implied.
- **An import creates thousands of links against a wrong domain.** Mitigated by requiring the target
  domain to be chosen once for the whole batch, and by a dry-run mode that reports outcomes without
  writing.
