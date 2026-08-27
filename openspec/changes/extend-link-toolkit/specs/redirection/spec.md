## Purpose

Extends redirection with rule-based routing, and revises the hot path's performance guarantee to admit
the geographic lookup that country rules require.

## ADDED Requirements

### Requirement: A link may route by rule
The system SHALL allow a link to carry an ordered list of routing rules, each matching on country,
device, language or time window and each naming its own destination, and SHALL send a visitor to the
destination of the first rule that matches.

#### Scenario: A rule matches
- **WHEN** a visitor matching a link's first rule requests it
- **THEN** the system redirects to that rule's destination

#### Scenario: No rule matches
- **WHEN** a visitor matches none of a link's rules
- **THEN** the system redirects to the link's own destination

#### Scenario: More than one rule matches
- **WHEN** a visitor matches two of a link's rules
- **THEN** the system redirects to the destination of whichever comes first in the list

#### Scenario: Rules are reordered
- **WHEN** an operator changes the order of a link's rules
- **THEN** the next request is routed by the new order

#### Scenario: A rule names a destination the instance refuses
- **WHEN** a rule is saved with a destination resolving to a private, loopback or metadata address
- **THEN** the system refuses it for the same reason it refuses such a link destination

#### Scenario: A link carries no rules
- **WHEN** a visitor requests a link with no rules at all
- **THEN** the system redirects to its destination as before, with no change in behaviour

### Requirement: Rule conditions are limited to four kinds
The system SHALL support matching on country, device class, language and time window, and SHALL NOT
offer matching on referrer, cookie, or query parameter.

#### Scenario: Device rule
- **WHEN** a visitor on a mobile device requests a link whose rule matches mobile
- **THEN** the system redirects to that rule's destination

#### Scenario: Language rule
- **WHEN** a visitor whose accepted languages include the rule's language requests the link
- **THEN** the system redirects to that rule's destination

#### Scenario: Time window rule
- **WHEN** a visitor requests a link inside a rule's configured window, evaluated in the instance reporting timezone
- **THEN** the system redirects to that rule's destination

#### Scenario: Time window rule outside its window
- **WHEN** the same visitor requests the link outside that window
- **THEN** the rule does not match

#### Scenario: Country rule with no geographic data available
- **WHEN** a country rule is evaluated on an instance with no geographic databases present
- **THEN** the rule does not match and the visitor reaches the link's own destination

### Requirement: Rule changes are recorded in the audit log
The system SHALL record a change to a link's routing rules in the audit log, naming the acting account
and the link.

#### Scenario: Rules are changed
- **WHEN** an operator writes a link's rules
- **THEN** an audit entry records who did it and against which link

#### Scenario: Why this is audited at all
- **WHEN** a link's rules send traffic somewhere other than its own destination
- **THEN** the link row itself is unchanged, so the audit log is the only record that it was repointed

### Requirement: Rules are evaluated without a database query
The system SHALL carry a link's rules in the same cache entry that carries the link, and SHALL evaluate
them without querying the database.

#### Scenario: A rule-carrying link is served from cache
- **WHEN** a cached rule-carrying link is requested
- **THEN** the system performs no database query

#### Scenario: A rule is edited
- **WHEN** an operator adds, edits, reorders or removes a rule
- **THEN** the link's cached entry is invalidated and the next request reflects the change

## MODIFIED Requirements

### Requirement: The redirect hot path performs no database query on a cache hit
The system SHALL resolve a cached link without querying the database and without making any network
call. A memory-mapped read of a local geographic database is permitted on this path; a query, a socket
and a remote call are not.

#### Scenario: Cached link is requested
- **WHEN** a visitor requests a link already in the cache
- **THEN** the system responds without querying the database

#### Scenario: Geographic resolution on the path
- **WHEN** the system resolves a visitor's country during a redirect
- **THEN** it does so by reading the local database in-process, without a network call

#### Scenario: The measured cost of a redirect
- **WHEN** the redirect path is benchmarked against a cached link
- **THEN** the added cost of geographic resolution is within the budget stated in the design
