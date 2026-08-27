## Purpose

Revises the click pipeline now that geography is resolved before the redirect returns, and removes the
visitor address from the queue.

## MODIFIED Requirements

### Requirement: Raw visitor addresses are never persisted
The system SHALL resolve geography and network from a visitor's address during the request that carried
it, SHALL place the resolved values rather than the address onto the click queue, and SHALL NOT write an
address to any store.

#### Scenario: A click is queued
- **WHEN** the redirect path records a click
- **THEN** the queued envelope carries the resolved country, region, city and ASN, and carries no address

#### Scenario: The queue is inspected
- **WHEN** the click queue is read directly
- **THEN** no entry contains a visitor address

#### Scenario: A click is persisted
- **WHEN** an enriched click is written to the event store
- **THEN** the stored row carries no address

### Requirement: The unique visitor hash is computed from the address before it is discarded
The system SHALL compute the visitor hash during the request that carried the address, using the same
daily-rotating salt, and SHALL carry the hash rather than its inputs onto the queue.

#### Scenario: Two clicks from one visitor in one day
- **WHEN** the same visitor follows two links within one day
- **THEN** both clicks carry the same visitor hash

#### Scenario: The salt rotates
- **WHEN** the daily salt rotates and the same visitor returns
- **THEN** the visitor hash differs from the previous day's

### Requirement: Enrichment does not repeat work already done
The system SHALL NOT resolve geography during enrichment for a click whose envelope already carries it.

#### Scenario: An enriched click is processed
- **WHEN** the drain worker processes an envelope carrying resolved geography
- **THEN** it performs no geographic lookup

#### Scenario: Bot filtering still uses the network
- **WHEN** the drain worker decides whether a click came from a datacenter network
- **THEN** it reads the ASN carried on the envelope rather than resolving one
