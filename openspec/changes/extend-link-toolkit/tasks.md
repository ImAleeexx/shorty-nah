# Tasks

Five features. Phase 2 is deliberately first among the substantive work: it changes the redirect hot
path, and everything in phase 3 depends on it. A task's verification method is part of the task.

## 1. Baseline

- [x] 1.1 Record a benchmark of the redirect hot path against a cached link before any change, in the same conditions the after-measurement will use, and commit the numbers and the method so the comparison is reproducible rather than remembered. Three runs recorded in `design.md`; the harness drives 256 addresses because one address measures the per-source limiter instead — an early run reported a pass that was really a `429`
- [x] 1.2 State the added-cost budget for geographic resolution in `design.md` as a number, and verify the benchmark harness fails when the budget is exceeded by temporarily setting it below the measured baseline. Confirmed: an impossible budget exits 1 and names the overrun. The budget is 150us and `design.md` states plainly that this cannot resolve the mmdb read itself — run-to-run noise is ~70us — only an order-of-magnitude regression such as a network call arriving on the path

## 2. Geography on the redirect path

- [x] 2.1 Corrected while implementing: the task asked for a per-request reader, which is wrong. `GeoLookup` is a singleton holding its mmdb readers open on purpose — opening one per lookup would dominate the cost — and a handle is a resource cache, not request state, so it is not what the Octane rule forbids. Verify instead that the resolver retains no request state across requests, and that a database refreshed underneath a running worker is picked up without a restart
- [x] 2.2 Compute the visitor hash during the request rather than during enrichment, and verify two clicks by one visitor in one day share a hash while the same visitor after a salt rotation does not Done: the hash is computed in the request and travels on the envelope.
- [x] 2.3 Replace `address` on `ClickEnvelope` with the resolved country, region, city, ASN and visitor hash, and verify the queued payload contains no address by reading the queue directly Done. Asserted over the whole serialised payload rather than one key, so the test proves the address is nowhere in it rather than that a field was renamed.
- [x] 2.4 Stop resolving geography in `ClickEnricher` when the envelope already carries it, and verify by counting resolver calls that a drained envelope performs none Done, with a deliberate fallback: an envelope queued before this change carries an address and no geography, and a deploy with a non-empty queue must drain it rather than write a batch of unknown countries. For those the original ordering still holds.
- [x] 2.5 Read the datacenter-network decision from the envelope's ASN rather than resolving one, and verify a datacenter ASN is still filtered and a residential one is not Done.
- [x] 2.6 Verify the whole pipeline end to end after the move: a redirect produces an enriched ClickHouse row carrying the same geography it did before, with no address at any stage Done against real ClickHouse.
- [x] 2.7 Verify an instance with no geographic databases still redirects, still records clicks, and reports geography as unknown rather than failing Done: unknown country, still a counted click with a usable visitor hash.
- [x] 2.8 Re-run the phase 1 benchmark and verify the added cost is inside the stated budget; record both numbers Done: +104.35us, +76.81us, +45.82us against a 540.26us baseline, inside the 150us budget on every run. Both sets of numbers are in `design.md`, along with why the added cost is more than a single mmdb read.

## 3. Rule-based routing

- [ ] 3.1 Add the `link_rules` table with an explicit position, a condition kind, its value, and a destination, and verify position uniqueness per link
- [ ] 3.2 Validate a rule destination through the same path a link destination uses, and verify a loopback, private, link-local and metadata destination are each refused
- [ ] 3.3 Carry rules in `ResolvedLink` and its cache payload, and verify a rule-carrying link resolves from cache with no database query
- [ ] 3.4 Invalidate the link cache from rule model events, and verify adding, editing, reordering and removing a rule each take effect on the next request
- [ ] 3.5 Implement country matching against the geography resolved in phase 2, and verify a matching country routes to the rule and a non-matching one falls through
- [ ] 3.6 Implement device matching from the user agent, and verify mobile, tablet and desktop each route as configured
- [ ] 3.7 Implement language matching from `Accept-Language`, and verify a weighted list matches on its preferred entry and an absent header matches nothing
- [ ] 3.8 Implement time-window matching in the instance reporting timezone, and verify a request inside the window matches and one outside does not, including a window crossing midnight
- [ ] 3.9 Evaluate rules in position order with first-match-wins, and verify two matching rules resolve to the earlier one
- [ ] 3.10 Verify a country rule on an instance with no geographic databases does not match, and the visitor reaches the link's own destination
- [ ] 3.11 Cap rules per link, and verify the refusal names the cap
- [ ] 3.12 Verify a link with no rules behaves exactly as before, by asserting the direct path's response is unchanged

## 4. QR codes

- [ ] 4.1 Add QR rendering from the instance branding producing PNG and SVG, and verify the encoded payload is the link's short URL on its own domain
- [ ] 4.2 Fall back to ink when the configured accent would render below scannable contrast, and verify the fallback triggers on a low-contrast accent and reports that it did
- [ ] 4.3 Mark the encoded URL so a resulting click is attributed as a scan, and verify the click is counted once in the total and reported separately
- [ ] 4.4 Verify a QR request for a link outside the account's scope answers as though the link does not exist
- [ ] 4.5 Verify a branding change is reflected in a newly requested code without a rebuild

## 5. Campaign parameters

- [ ] 5.1 Implement parameter composition onto a destination, and verify existing unrelated query parameters survive unchanged
- [ ] 5.2 Implement reading parameters back out of a destination, and verify editing one replaces it rather than appending a duplicate
- [ ] 5.3 Add instance-wide presets to the settings store, and verify a preset populates the builder and remains editable before saving
- [ ] 5.4 Verify a destination is stored composed, so the link list and the redirect both show where visitors actually land

## 6. Bulk import and export

- [ ] 6.1 Define the CSV contract with a required header, and verify a file lacking it is refused with a message naming what was expected, without queueing anything
- [ ] 6.2 Implement export honouring account scope and applied filters, and verify a restricted account receives only its own links
- [ ] 6.3 Verify an exported protected link records that it is protected and carries neither password nor hash
- [ ] 6.4 Implement the queued import batch validating each row through `LinkService`, and verify a file of valid rows creates every link
- [ ] 6.5 Verify one invalid row fails alone: the valid rows are created and the result reports the reason against that row
- [ ] 6.6 Verify an imported destination resolving to a private address is refused for the same reason single creation refuses it
- [ ] 6.7 Verify an imported slug already in use is refused and the existing link is left unchanged
- [ ] 6.8 Implement dry run, and verify it reports every row's outcome and creates nothing
- [ ] 6.9 Verify a round trip: an export re-imported onto a clean instance reproduces every link
- [ ] 6.10 Report batch progress to the interface, and verify progress advances and completes for a batch large enough to span several jobs

## 7. Outbound webhooks

- [ ] 7.1 Add the endpoint and delivery tables, storing the signing secret hashed, and verify the secret is shown once at creation and never again
- [ ] 7.2 Refuse an endpoint that is not HTTPS or resolves to a private, loopback or metadata address, and verify each refusal after DNS resolution rather than on the literal string
- [ ] 7.3 Implement signed delivery on its own queue, and verify a receiver recomputing the HMAC over the body with its copy of the secret matches
- [ ] 7.4 Verify a delivery does not delay the request that caused it: a redirect completes with an unreachable endpoint registered
- [ ] 7.5 Fire click deliveries from the drain worker on the enriched click, and verify no delivery is attempted from the redirect path
- [ ] 7.6 Implement bounded retries with increasing delay, and verify a delivery failing once and succeeding records both attempts
- [ ] 7.7 Verify an exhausted delivery is recorded as failed and is not retried again automatically
- [ ] 7.8 Implement replay, and verify a replayed delivery records a new attempt separately from the original
- [ ] 7.9 Verify secret rotation: deliveries sign with the new secret and the previous one stops verifying
- [ ] 7.10 Verify no payload carries an address, a link password, a session identifier or an issued secret
- [ ] 7.11 Bound the delivery log by the existing retention setting, and verify records past it are removed on the same schedule as raw click events
- [ ] 7.12 Record endpoint creation, secret rotation and removal in the audit log, and verify no entry carries the secret

## 8. Interface

- [ ] 8.1 Build the QR panel on the link sheet with PNG and SVG download, and verify by browser test that both download and the code scans to the right URL
- [ ] 8.2 Build the campaign builder on the link sheet, and verify by browser test that editing a parameter on a destination that already carries one replaces it
- [ ] 8.3 Build the routing rules editor with drag ordering, and verify by browser test that reordering changes where a visitor lands
- [ ] 8.4 Build import and export on the links screen, and verify by browser test that a file with one bad row reports that row and creates the rest
- [ ] 8.5 Build the webhooks screen under settings with creation, rotation, the delivery log and replay, and verify by browser test that the secret is shown once
- [ ] 8.6 Verify every new form surfaces a failure that names no field, using `FormError`
- [ ] 8.7 Verify every new surface against the motion contract: no scroll-entry motion on the dashboard, durations from the token layer read through parentheses
- [ ] 8.8 Verify every new surface for keyboard reachability and visible focus, and that no icon arrives from outside the shared module

## 9. Gate

- [ ] 9.1 Extend `verification.md` with every scenario added here and what proves it, and name any that need a deployed host
- [ ] 9.2 Run `make ci` and `make e2e` green, and run `make scan` and record any new finding with a reason or an expiry
- [ ] 9.3 Update `CLAUDE.md` for the revised hot-path guarantee and the envelope's new shape, since both contradict what it currently states
