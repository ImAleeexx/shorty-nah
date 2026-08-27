## Why

The instance is complete as a shortener and thin as a toolkit. An operator can create a link, brand
the instance and read accurate analytics, but cannot hand anyone a QR code, cannot tag a destination
for a campaign without hand-writing query strings, cannot move an existing corpus of links onto the
instance at all, cannot get a click into their own systems without polling the API, and cannot vary a
destination by who is asking.

Those five gaps share a property: each is ordinary in the category and each is currently impossible
rather than merely inconvenient. Bulk import is the sharpest — without it the instance cannot be
adopted by anyone who already has links somewhere else.

## What Changes

- Generate a QR code for any link, rendered from the instance's own branding, downloadable as PNG or
  SVG, with scans distinguishable from ordinary clicks in reporting.
- Add a UTM builder to link creation and editing, with instance-wide presets, writing the parameters
  onto the destination rather than storing them beside it.
- Add bulk import from CSV and bulk export to CSV, import running as a queued batch that validates
  every row against the same rules as single creation and reports per-row outcomes.
- Add outbound webhooks: operator-registered endpoints receiving signed, retried deliveries when a
  click is recorded or a link changes, with a delivery log and a replay control.
- Add rule-based routing: a link may carry an ordered list of rules matching on country, device,
  language or time window, each with its own destination, falling back to the link's own destination
  when none match.
- **Move geographic resolution onto the redirect path.** Country rules cannot be evaluated after the
  fact, so geo is resolved before the redirect returns rather than during enrichment.
- **Stop putting raw addresses on the click queue.** With geo resolved at redirect time the envelope
  carries the resolved country, ASN and visitor hash instead of the address, so a raw IP no longer
  exists anywhere outside the request that carried it.

## Non-goals

- No hosted or third-party integrations. Webhooks post to endpoints the operator names; nothing calls
  out to a vendor.
- No visual QR customisation beyond the instance branding already configured. One code style, derived.
- No rule conditions beyond the four named. Referrer, cookie and query-parameter matching are
  deliberately excluded: each invites a rule set that cannot be reasoned about from the link list.
- No A/B testing, weighting or rotation. Rules are deterministic and ordered; the first match wins.
- No import from a named competitor's export format. CSV with a documented header, and nothing else.

## Impact

The redirect hot path changes for the first time since it was written, and it is the one route the
project states must never regress. Every redirect gains an in-process MaxMind lookup, whether or not
the link carries a rule — chosen deliberately over resolving lazily, so that enrichment stops
re-resolving geo and the address leaves the queue entirely. The lookup is a memory-mapped read with
no network call, and the change is net-negative work overall, but it is added work on the path that
matters most and it is measured rather than assumed.
