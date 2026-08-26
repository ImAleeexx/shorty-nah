## Purpose

Records and reports what happened to each short link, with counting rules that reject automated and
speculative traffic so operators can trust the numbers, and without retaining visitors' network
addresses.

## ADDED Requirements

### Requirement: Analytics never delays or breaks a redirect
The system SHALL record click events asynchronously, and SHALL complete a redirect successfully even when
event recording or the event store is unavailable.

#### Scenario: Event store is unavailable
- **WHEN** the click event store cannot be reached and a visitor requests an active link
- **THEN** the visitor is redirected normally
- **AND** the system reports the failure through its own health and logging surfaces

#### Scenario: Event capture is queued
- **WHEN** a visitor is redirected
- **THEN** enrichment and persistence of the click occur after the response has been sent

### Requirement: Speculative and automated requests are not counted
The system SHALL exclude from click counts any request that indicates prefetching or preloading, any
`HEAD` request, and any request identified as automated by user-agent or by originating network.

#### Scenario: Browser prefetch
- **WHEN** a request arrives carrying a header indicating prefetch, preload, or a non-navigational purpose
- **THEN** the system redirects but records no click

#### Scenario: HEAD request
- **WHEN** a `HEAD` request is made for a short link
- **THEN** the system responds without recording a click

#### Scenario: Known automated client
- **WHEN** a request's user agent matches the automated-client list or its network is a known hosting provider
- **THEN** the system records the event classified as automated and excludes it from reported click counts

#### Scenario: Automated traffic remains inspectable
- **WHEN** an operator views a link's report
- **THEN** excluded automated traffic is available as a separate figure rather than being discarded

### Requirement: Visitors are counted without retaining their addresses
The system SHALL derive a visitor identifier by hashing the network address together with the user agent
and a salt that rotates on a fixed interval, and SHALL NOT persist the raw network address.

#### Scenario: Event is stored
- **WHEN** a click event is persisted
- **THEN** the stored record contains the derived visitor identifier and no network address

#### Scenario: Salt rotates
- **WHEN** the salt rotation interval elapses
- **THEN** the same visitor produces a different identifier, and historical identifiers are not recomputable

### Requirement: Duplicate fires within a short window count once
The system SHALL treat repeated events for the same visitor identifier and link within a configured
window as a single click.

#### Scenario: Double submission
- **WHEN** two events arrive for the same visitor and link within the deduplication window
- **THEN** the reported click count increases by one

### Requirement: Events are enriched with geographic and technical context
The system SHALL resolve country, region, city, and autonomous system from the network address during
enrichment, and SHALL parse device type, operating system, and browser from the user agent.

#### Scenario: Address resolves
- **WHEN** enrichment runs for an event whose address is present in the geographic database
- **THEN** the event records country, region, city, and autonomous system

#### Scenario: Address does not resolve
- **WHEN** the address is absent from the geographic database
- **THEN** the event is stored with geographic fields marked unknown and is still counted

#### Scenario: Geographic database is missing
- **WHEN** no geographic database is installed
- **THEN** enrichment proceeds without geographic fields and the system surfaces the missing database as a configuration warning

### Requirement: Interstitial mode records client-side signals
For links in `interstitial` mode the system SHALL additionally record viewport and screen dimensions,
timezone, language, colour-scheme preference, connection type where available, and the dwell time before
navigation.

#### Scenario: Beacon is received
- **WHEN** the hold page submits its measurement for a click
- **THEN** the system attaches the client-side signals to that click's event

#### Scenario: Beacon never arrives
- **WHEN** a visitor closes the hold page before the measurement is submitted
- **THEN** the click remains recorded with server-observable data only

#### Scenario: Beacon cannot be forged for another click
- **WHEN** a client submits a measurement referencing a click it did not originate
- **THEN** the system rejects the submission

### Requirement: Reports are served from aggregates
The system SHALL answer dashboard queries from precomputed aggregates rather than scanning raw events,
and SHALL bucket time series in the operator's configured timezone.

#### Scenario: Viewing a report over a long period
- **WHEN** an operator requests a twelve-month report for a link with a large number of clicks
- **THEN** the system answers from aggregates within the configured response budget

#### Scenario: Timezone affects bucketing
- **WHEN** the instance timezone is not UTC and an operator views a daily breakdown
- **THEN** day boundaries follow the instance timezone

#### Scenario: Drilling into raw events
- **WHEN** an operator inspects individual clicks for a link
- **THEN** the system returns raw events for that link, paginated

### Requirement: Event retention is enforced
The system SHALL delete click events older than the configured retention period, and SHALL retain
aggregates independently so that historical totals survive raw-event expiry.

#### Scenario: Retention period elapses
- **WHEN** events pass the configured retention period
- **THEN** the system removes them from the event store

#### Scenario: Aggregates outlive raw events
- **WHEN** raw events for a period have been removed by retention
- **THEN** reported totals for that period remain available

### Requirement: Analytics can be exported
The system SHALL let an authorized operator export a link's click events for a chosen period in a
machine-readable format.

#### Scenario: Export requested
- **WHEN** an authorized operator exports a link's events for a period
- **THEN** the system produces a file containing those events, excluding any network address
