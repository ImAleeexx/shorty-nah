## Purpose

Defines outbound webhooks: how an operator registers an endpoint, what is delivered, how a delivery is
authenticated, and what happens when one fails.

## ADDED Requirements

### Requirement: An operator may register webhook endpoints
The system SHALL allow an operator to register HTTPS endpoints, choose which events each receives, issue
a signing secret shown once at creation, and disable or remove an endpoint.

#### Scenario: An endpoint is registered
- **WHEN** an operator registers an endpoint and selects its events
- **THEN** the system stores it and displays a signing secret once

#### Scenario: The secret is requested again
- **WHEN** an operator views an endpoint after creating it
- **THEN** the signing secret is not shown, and only a newly rotated secret can be seen again

#### Scenario: An endpoint that is not HTTPS
- **WHEN** an operator registers an endpoint that is not HTTPS, or that resolves to a private, loopback or metadata address
- **THEN** the system refuses it

#### Scenario: An endpoint is disabled
- **WHEN** an operator disables an endpoint
- **THEN** no further deliveries are attempted for it, and its delivery history remains readable

### Requirement: Deliveries are signed and verifiable
The system SHALL sign every delivery with an HMAC over the exact request body using the endpoint's
secret, and SHALL include a timestamp within the signed material.

#### Scenario: A delivery is made
- **WHEN** the system delivers an event
- **THEN** the request carries a signature header computed over the body and a timestamp

#### Scenario: A receiver verifies a delivery
- **WHEN** a receiver recomputes the signature from the body and its copy of the secret
- **THEN** the value matches

#### Scenario: The secret is rotated
- **WHEN** an operator rotates an endpoint's secret
- **THEN** subsequent deliveries are signed with the new secret and the previous one stops verifying

### Requirement: Delivery happens outside the request that caused it
The system SHALL deliver webhooks from a queue, and SHALL NOT delay a redirect or an interface response
on a delivery.

#### Scenario: A click triggers a delivery
- **WHEN** a click is recorded for a link and an endpoint subscribes to click events
- **THEN** the redirect completes without waiting for the delivery

#### Scenario: An endpoint is unreachable
- **WHEN** a registered endpoint does not respond
- **THEN** redirects and the interface are unaffected

### Requirement: Failed deliveries are retried, recorded and replayable
The system SHALL retry a failed delivery a bounded number of times with increasing delay, SHALL record
every attempt with its response status, and SHALL allow an operator to replay a delivery.

#### Scenario: A delivery fails and then succeeds
- **WHEN** an endpoint fails once and succeeds on retry
- **THEN** both attempts are recorded and the delivery is marked delivered

#### Scenario: A delivery exhausts its retries
- **WHEN** every attempt fails
- **THEN** the delivery is recorded as failed and is not attempted again automatically

#### Scenario: An operator replays a delivery
- **WHEN** an operator replays a recorded delivery
- **THEN** the system attempts it again and records the new attempt separately

#### Scenario: Delivery history is bounded
- **WHEN** delivery records pass the configured retention period
- **THEN** they are removed on the same schedule that bounds raw click events

### Requirement: Deliveries carry no secrets
The system SHALL NOT include a visitor address, a link password, a session identifier or any issued
secret in a webhook payload.

#### Scenario: A click delivery is inspected
- **WHEN** a click event payload is examined
- **THEN** it carries the link, its domain, the resolved geography and client profile, and no address

#### Scenario: A link event for a protected link
- **WHEN** a link carrying a password is created or changed and delivered
- **THEN** the payload records that it is protected and carries neither the password nor its hash
