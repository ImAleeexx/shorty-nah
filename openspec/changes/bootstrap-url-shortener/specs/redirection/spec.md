## Purpose

Defines what happens between a visitor requesting a short link and arriving at its destination, including
the two redirect modes and the performance guarantees that keep the fast path fast.

## ADDED Requirements

### Requirement: Requests resolve by hostname and slug together
The system SHALL resolve an incoming request using both the requested hostname and the slug, and SHALL
NOT resolve a slug belonging to a different domain.

#### Scenario: Slug requested on the wrong domain
- **WHEN** a visitor requests a slug that exists only on another registered domain
- **THEN** the system returns the not-found response

#### Scenario: Slug requested on its own domain
- **WHEN** a visitor requests a slug on the domain it belongs to
- **THEN** the system resolves the link

### Requirement: Direct mode performs a plain redirect
In `direct` mode the system SHALL respond with an HTTP redirect to the destination and a body no larger
than necessary, without rendering an interface.

#### Scenario: Direct redirect
- **WHEN** a visitor requests an active link in `direct` mode
- **THEN** the system responds with a `302` whose `Location` is the destination
- **AND** the response contains no tracking markup or script

#### Scenario: Redirect responses are not cached
- **WHEN** the system responds to any redirect request
- **THEN** the response instructs intermediaries and browsers not to store it

### Requirement: Interstitial mode renders a hold page before navigating
In `interstitial` mode the system SHALL return a branded page that reports a client-side measurement and
then navigates to the destination, and SHALL still reach the destination when scripting is unavailable.

#### Scenario: Interstitial with scripting available
- **WHEN** a visitor requests an active link in `interstitial` mode with scripting enabled
- **THEN** the system renders the hold page, submits the client measurement, and navigates to the destination after the configured delay

#### Scenario: Interstitial without scripting
- **WHEN** a visitor requests an active link in `interstitial` mode with scripting disabled
- **THEN** the visitor still reaches the destination, and only server-observable data is recorded

#### Scenario: Destination is never exposed to referrer leakage rules unintentionally
- **WHEN** the hold page navigates to the destination
- **THEN** the system applies the configured referrer policy for that link

### Requirement: A cached resolution performs no application database query
The system SHALL resolve a cached link without querying the application database, and SHALL cache the
absence of a slug so that requests for non-existent slugs also avoid the database.

#### Scenario: Repeated request for the same slug
- **WHEN** a slug is requested a second time after being resolved once
- **THEN** the system resolves it from cache and issues no application database query

#### Scenario: Repeated requests for a non-existent slug
- **WHEN** the same non-existent slug is requested repeatedly
- **THEN** the system serves the not-found response from the negative cache and issues no application database query after the first request

### Requirement: Constrained links stop resolving when their constraints are met
The system SHALL refuse to redirect an expired link, a disabled link, or a link that has reached its
maximum click count, and SHALL distinguish these from a slug that never existed only to authorized
operators.

#### Scenario: Expired link
- **WHEN** a visitor requests a link whose expiry has passed
- **THEN** the system does not redirect and renders the expired-link response

#### Scenario: Click limit reached
- **WHEN** a visitor requests a link that has reached its maximum click count
- **THEN** the system does not redirect

#### Scenario: Unauthenticated visitor cannot distinguish states
- **WHEN** an unauthenticated visitor requests a disabled link and a slug that never existed
- **THEN** both responses are indistinguishable to that visitor

### Requirement: Password-protected links require the password before redirecting
The system SHALL prompt for a password on a protected link and SHALL NOT disclose the destination until
the correct password is supplied.

#### Scenario: Protected link without a password
- **WHEN** a visitor requests a password-protected link
- **THEN** the system renders a password prompt and no response header or body reveals the destination

#### Scenario: Correct password
- **WHEN** a visitor submits the correct password
- **THEN** the system proceeds with the link's configured redirect mode

#### Scenario: Incorrect password
- **WHEN** a visitor submits an incorrect password
- **THEN** the system re-renders the prompt and rate-limits repeated attempts from that source

### Requirement: The redirect path is rate limited without penalising legitimate traffic
The system SHALL rate-limit redirect requests per source address at an operator-configured threshold, and
SHALL exclude the redirect path from limits that would apply to authenticated application traffic.

#### Scenario: Source exceeds the redirect threshold
- **WHEN** a single source address exceeds the configured redirect rate
- **THEN** the system responds `429` and records no click event for the refused requests
