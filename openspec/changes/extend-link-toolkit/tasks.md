# Tasks

Five features. Phase 2 is deliberately first among the substantive work: it changes the redirect hot
path, and everything in phase 3 depends on it. A task's verification method is part of the task.

## 1. Baseline

- [x] 1.1 Record a benchmark of the redirect hot path against a cached link before any change, in the same conditions the after-measurement will use, and commit the numbers and the method so the comparison is reproducible rather than remembered. Three runs recorded in `design.md`; the harness drives 256 addresses because one address measures the per-source limiter instead — an early run reported a pass that was really a `429`
- [x] 1.2 State the added-cost budget for geographic resolution in `design.md` as a number, and verify the benchmark harness fails when the budget is exceeded by temporarily setting it below the measured baseline. Confirmed: an impossible budget exits 1 and names the overrun. The budget is 150us and `design.md` states plainly that this cannot resolve the mmdb read itself — run-to-run noise is ~70us — only an order-of-magnitude regression such as a network call arriving on the path. Corrected later: the budget was written against the mean and failed two runs in five on unchanged code, because a latency mean is outlier-dominated. It compares the median now, which spreads 30us instead of 160us

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

- [x] 3.1 Add the `link_rules` table with an explicit position, a condition kind, its value, and a destination, and verify position uniqueness per link Done.
- [x] 3.2 Validate a rule destination through the same path a link destination uses, and verify a loopback, private, link-local and metadata destination are each refused Done, through the same `DestinationValidator` a link uses, so a rule cannot become the way around a refusal that applies everywhere else.
- [x] 3.3 Carry rules in `ResolvedLink` and its cache payload, and verify a rule-carrying link resolves from cache with no database query Done. The cold path loads rules in a second statement rather than a join: a link with five rules would multiply the link row and every column with it, and the cold path runs once an hour per slug.
- [x] 3.4 Invalidate the link cache from rule model events, and verify adding, editing, reordering and removing a rule each take effect on the next request Done via `LinkRuleObserver`. The link's own observer cannot see a rule change — nothing on the link row differs — so without this a link keeps routing by yesterday's rules for up to an hour.
- [x] 3.5 Implement country matching against the geography resolved in phase 2, and verify a matching country routes to the rule and a non-matching one falls through Done, including several countries in one rule.
- [x] 3.6 Implement device matching from the user agent, and verify mobile, tablet and desktop each route as configured Done, and the vocabulary is the operator's rather than the parser's: the library says `smartphone`, a rule says `mobile`. Making an operator learn the library's finer vocabulary would be a rule set that fails silently when someone writes the obvious word.
- [x] 3.7 Implement language matching from `Accept-Language`, and verify a weighted list matches on its preferred entry and an absent header matches nothing Done, sorted by quality rather than written order — a header of `en;q=0.5,es` prefers Spanish, and matching the first written tag would send that visitor to the wrong place.
- [x] 3.8 Implement time-window matching in the instance reporting timezone, and verify a request inside the window matches and one outside does not, including a window crossing midnight Done, including a window crossing midnight and a malformed window matching nothing rather than everything.
- [x] 3.9 Evaluate rules in position order with first-match-wins, and verify two matching rules resolve to the earlier one Done.
- [x] 3.10 Verify a country rule on an instance with no geographic databases does not match, and the visitor reaches the link's own destination Done: a condition nobody can evaluate must not silently capture traffic.
- [x] 3.11 Cap rules per link, and verify the refusal names the cap Done at 20.
- [x] 3.12 Verify a link with no rules behaves exactly as before, by asserting the direct path's response is unchanged Done. The assertion is on the Cache-Control directives rather than the header string, because Symfony normalises and reorders it and a literal comparison tests the framework's formatting.

## 4. QR codes

- [x] 4.1 Add QR rendering from the instance branding producing PNG and SVG, and verify the encoded payload is the link's short URL on its own domain Done. Bacon encodes and this draws: the library ships an Imagick back end and the image has GD, but drawing both formats from the one matrix is the only way the PNG and the SVG are guaranteed to be the same code. Verified by sampling every module centre out of the rendered PNG and comparing it to the encoder's matrix — non-vacuous, since shifting the quiet zone by one module fails it.
- [x] 4.2 Fall back to ink when the configured accent would render below scannable contrast, and verify the fallback triggers on a low-contrast accent and reports that it did Done at 4.5:1 against white, higher than the 3:1 the interface enforces for text: a scanner thresholds an image taken under unknown light at an angle, and a code that fails to scan fails silently.
- [x] 4.3 Mark the encoded URL so a resulting click is attributed as a scan, and verify the click is counted once in the total and reported separately Done with a `source` column rather than a referrer convention — a scan has no referrer, because a camera is not a page. `scans` counts beside `counted`, not instead of it.
- [x] 4.4 Verify a QR request for a link outside the account's scope answers as though the link does not exist Done.
- [x] 4.5 Verify a branding change is reflected in a newly requested code without a rebuild Done; nothing is cached to disk, because a stored code is a stale code waiting to be served after a rebrand.

## 5. Campaign parameters

- [x] 5.1 Implement parameter composition onto a destination, and verify existing unrelated query parameters survive unchanged Done in `src/lib/campaign.ts`. A destination's own query string is preserved untouched.
- [x] 5.2 Implement reading parameters back out of a destination, and verify editing one replaces it rather than appending a duplicate Done: `set` rather than `append`, so editing a parameter replaces it. An empty value removes the parameter rather than writing an empty one, and an unparseable half-typed destination is left alone rather than throwing inside a form.
- [x] 5.3 Add instance-wide presets to the settings store, and verify a preset populates the builder and remains editable before saving Done as `link.campaign_presets`, JSON in one setting rather than a table: presets are named defaults with no relations, no per-preset authorization and nothing joining to them. Malformed settings yield no presets rather than breaking the link form.
- [x] 5.4 Verify a destination is stored composed, so the link list and the redirect both show where visitors actually land Inherent in the decision: composition happens when the link is saved, so the destination column is where visitors actually land. The builder itself is task 8.2.

## 6. Bulk import and export

- [x] 6.1 Define the CSV contract with a required header, and verify a file lacking it is refused with a message naming what was expected, without queueing anything Done: one documented header, no dialect detection. A byte-order mark from a spreadsheet is stripped, or the first column silently goes missing on a file that is perfectly valid.
- [x] 6.2 Implement export honouring account scope and applied filters, and verify a restricted account receives only its own links Done.
- [x] 6.3 Verify an exported protected link records that it is protected and carries neither password nor hash Done: the column records `yes`, never the password or its hash — an export is a file that gets emailed around.
- [x] 6.4 Implement the queued import batch validating each row through `LinkService`, and verify a file of valid rows creates every link Done.
- [x] 6.5 Verify one invalid row fails alone: the valid rows are created and the result reports the reason against that row Done. Row by row rather than one transaction: a batch of ten thousand where row 4,000 names a taken slug should create the other 9,999.
- [x] 6.6 Verify an imported destination resolving to a private address is refused for the same reason single creation refuses it Done, through `LinkService`, so an import cannot become the way around a refusal that applies everywhere else.
- [x] 6.7 Verify an imported slug already in use is refused and the existing link is left unchanged Done.
- [x] 6.8 Implement dry run, and verify it reports every row's outcome and creates nothing Done by running each row for real inside a transaction and rolling it back, rather than skipping the work: a rehearsal that takes a different code path rehearses nothing.
- [x] 6.9 Verify a round trip: an export re-imported onto a clean instance reproduces every link Done onto a second domain, which is the case the format exists for.
- [x] 6.10 Report batch progress to the interface, and verify progress advances and completes for a batch large enough to span several jobs Done. The result download carries the operator's own input rows beside their outcomes, because someone fixing a rejected import needs their rows back, not a list of errors detached from them.

## 7. Outbound webhooks

- [x] 7.1 Add the endpoint and delivery tables, storing the signing secret hashed, and verify the secret is shown once at creation and never again Done, but not as written. The task said the secret is stored hashed, copying the API-token contract, and that is impossible: an HMAC needs the value, and a hash cannot sign. It is encrypted at rest instead — the strongest treatment for a key that must be used rather than checked — and `design.md` and the spec now say so. Asserted by reading the raw column.
- [x] 7.2 Refuse an endpoint that is not HTTPS or resolves to a private, loopback or metadata address, and verify each refusal after DNS resolution rather than on the literal string Done through the same resolution-time validator a link destination gets. An endpoint is a URL this instance fetches on a schedule nobody watches, which is the definition of an SSRF target.
- [x] 7.3 Implement signed delivery on its own queue, and verify a receiver recomputing the HMAC over the body with its copy of the secret matches Done. The timestamp is inside the signed material, not merely beside it: signing the body alone leaves a captured delivery replayable against the receiver forever. Verified by recomputing the HMAC exactly as a receiver would.
- [x] 7.4 Verify a delivery does not delay the request that caused it: a redirect completes with an unreachable endpoint registered Done.
- [x] 7.5 Fire click deliveries from the drain worker on the enriched click, and verify no delivery is attempted from the redirect path Done, and only for counted clicks — a bot or a duplicate is not an event anyone asked to hear about. A webhook on the hot path would put an operator's endpoint between a visitor and their destination.
- [x] 7.6 Implement bounded retries with increasing delay, and verify a delivery failing once and succeeding records both attempts Done: 4 tries, 10s/60s/300s backoff, on its own Horizon supervisor so a dead endpoint cannot sit in front of mail.
- [x] 7.7 Verify an exhausted delivery is recorded as failed and is not retried again automatically Done.
- [x] 7.8 Implement replay, and verify a replayed delivery records a new attempt separately from the original Done as a new record rather than a reset of the original: the original is the evidence the operator was looking at.
- [x] 7.9 Verify secret rotation: deliveries sign with the new secret and the previous one stops verifying Done.
- [x] 7.10 Verify no payload carries an address, a link password, a session identifier or an issued secret Done. The payload is a deliberate subset of the row — no visitor hash, which is an identifier this instance chose not to be able to reverse and has no business handing out.
- [x] 7.11 Bound the delivery log by the existing retention setting, and verify records past it are removed on the same schedule as raw click events Done on the same schedule as raw events. A delivery holds a payload, so a busy endpoint accumulates faster than the events do.
- [x] 7.12 Record endpoint creation, secret rotation and removal in the audit log, and verify no entry carries the secret Done.

## 8. Interface

- [x] 8.1 Build the QR panel on the link sheet with PNG and SVG download, and verify by browser test that both download and the code scans to the right URL Done. The preview is fetched as an object URL rather than pointed at with an `<img src>`: the endpoint answers with a download disposition, which is what the buttons want and exactly what a preview must not do. The browser test asserts the image actually decoded, not merely that it is present.
- [x] 8.2 Build the campaign builder on the link sheet, and verify by browser test that editing a parameter on a destination that already carries one replaces it Done. It holds no state of its own — values are read out of the destination and written back into it — so the panel and the destination field cannot disagree.
- [x] 8.3 Build the routing rules editor with explicit move controls, and verify by browser test that reordering changes where a visitor lands Done with move buttons rather than drag ordering, and the task is corrected accordingly: a drag surface needs a pointer and excludes a keyboard, and this list is capped at 20. Ordering is still explicit and still first-match-wins.
- [x] 8.4 Build import and export on the links screen, and verify by browser test that a file with one bad row reports that row and creates the rest Done. The upload performs the CSRF handshake explicitly because a file is not JSON and cannot go through the shared helper — the failure that passes every server-side test and returns 419 in a real browser.
- [x] 8.5 Build the webhooks screen under settings with creation, rotation, the delivery log and replay, and verify by browser test that the secret is shown once Done. The secret is rendered from the creation response and from nowhere else; the browser test reloads and asserts it is absent from the page that replaces it.
- [x] 8.6 Verify every new form surfaces a failure that names no field, using `FormError` Done: `FormError` is on the rules editor, the import sheet and the webhook form.
- [x] 8.7 Verify every new surface against the motion contract: no scroll-entry motion on the dashboard, durations from the token layer read through parentheses Done. The disclosure sections have no animation at all — a form an operator uses many times a day is exactly what the contract says gets none — and the only transition added is the caret's rotation at `duration-(--duration-press)`, read through parentheses.
- [x] 8.8 Verify every new surface for keyboard reachability and visible focus, and that no icon arrives from outside the shared module Done: every control is a button or a labelled field, the move controls carry `aria-label`s naming the rule they act on, and every icon comes from the shared module.

## 9. Gate

- [x] 9.1 Extend `verification.md` with every scenario added here and what proves it, and name any that need a deployed host Done in this change's own `verification.md`. One exception: a delivery walking its backoff to exhaustion takes six minutes of wall clock, which no suite can sit through — the note says what would close it.
- [x] 9.2 Run `make ci` and `make e2e` green, and run `make scan` and record any new finding with a reason or an expiry Done: 656 Pest (1886 assertions), 53 Vitest, 78 browser tests, all green. `make scan` clean — no new dependency, secret or image findings.
- [x] 9.3 Update `CLAUDE.md` for the revised hot-path guarantee and the envelope's new shape, since both contradict what it currently states. Done: the hot-path guarantee now reads "no database query and no network call" rather than "no work", the envelope's new shape is recorded, and the Horizon `tags()` collision is written down as a trap
