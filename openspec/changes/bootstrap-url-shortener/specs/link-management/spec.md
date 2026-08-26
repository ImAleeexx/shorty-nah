## Purpose

Owns the short link itself — how its slug is produced, what destinations are permitted, the conditions
under which it stops working, and the guarantee that a change to a link is reflected immediately by the
redirect path.

## ADDED Requirements

### Requirement: Generated slugs are not enumerable
When no custom slug is supplied, the system SHALL generate one from a cryptographically secure random
source over an unambiguous alphabet, at an operator-configured length, and SHALL retry on collision.

#### Scenario: Slug is generated
- **WHEN** a client creates a link without supplying a slug
- **THEN** the system generates a unique slug of the configured length from a cryptographically secure source

#### Scenario: Generated slug collides
- **WHEN** slug generation produces a value already in use on the target domain
- **THEN** the system generates another and the request still succeeds

#### Scenario: Generation cannot find a free slug
- **WHEN** repeated generation attempts all collide up to the retry limit
- **THEN** the system fails the request with an error indicating slug space exhaustion rather than returning a duplicate

### Requirement: Custom slugs are validated
The system SHALL accept operator-chosen slugs only when they match the permitted character set, are not
reserved, and are unused on the target domain.

#### Scenario: Valid custom slug
- **WHEN** a client supplies an unused slug of permitted characters
- **THEN** the system creates the link with that exact slug, preserving case

#### Scenario: Reserved slug
- **WHEN** a client supplies a slug that collides with a reserved application path such as the setup, api, or asset routes
- **THEN** the system rejects the request

#### Scenario: Disallowed characters
- **WHEN** a client supplies a slug containing characters outside the permitted set
- **THEN** the system rejects the request and states the permitted set

### Requirement: Destinations are validated
The system SHALL accept only absolute `http` or `https` destinations, and SHALL reject destinations that
would cause a redirect loop or that match the operator's blocklist.

#### Scenario: Non-web scheme
- **WHEN** a client supplies a destination using a scheme other than `http` or `https`
- **THEN** the system rejects the request

#### Scenario: Destination pointing at this instance's own short domain
- **WHEN** a client supplies a destination whose host is a short domain of this instance
- **THEN** the system rejects the request as a redirect loop

#### Scenario: Blocklisted destination
- **WHEN** a client supplies a destination whose host matches the configured blocklist
- **THEN** the system rejects the request

### Requirement: Destinations may not point at private or infrastructure addresses
The system SHALL reject a destination whose host is a loopback, private, link-local, carrier-grade NAT,
multicast, or reserved address, or a cloud instance-metadata address, whether given literally or reached by
resolving its hostname. Any server-side fetch of a destination SHALL connect only to the address that
passed validation.

#### Scenario: Literal private address
- **WHEN** a client supplies a destination whose host is a loopback, private, link-local, carrier-grade NAT, multicast, or reserved address
- **THEN** the system rejects the request

#### Scenario: Hostname resolving to a private address
- **WHEN** a client supplies a destination whose hostname resolves to any such address
- **THEN** the system rejects the request

#### Scenario: Cloud metadata endpoint
- **WHEN** a client supplies a destination addressing a cloud instance-metadata service
- **THEN** the system rejects the request

#### Scenario: Address changes after validation
- **WHEN** the system fetches a destination and the hostname now resolves to a different address
- **THEN** the fetch connects to the validated address rather than the newly resolved one, or does not proceed

#### Scenario: Non-web scheme disguised in a redirect chain
- **WHEN** a validated destination redirects to a `javascript:`, `data:`, or `file:` target and the system follows redirects server-side
- **THEN** the system stops following and does not fetch the target

### Requirement: Links can be constrained in time, count, and access
The system SHALL support an optional expiry instant, an optional maximum click count, an optional
password, and an enabled/disabled state on every link.

#### Scenario: Setting an expiry in the past
- **WHEN** a client sets an expiry instant that has already passed
- **THEN** the system rejects the request

#### Scenario: Setting a password
- **WHEN** a client sets a link password
- **THEN** the system stores it as a one-way hash and never returns it in any response

#### Scenario: Disabling a link
- **WHEN** an owner disables a link
- **THEN** the link is retained with its analytics and stops resolving

### Requirement: Redirect mode is chosen per link
The system SHALL let each link specify `direct` or `interstitial`, defaulting to the instance-wide
configured mode when unspecified.

#### Scenario: Link created without a mode
- **WHEN** a client creates a link and does not specify a redirect mode
- **THEN** the system applies the instance default

#### Scenario: Instance default changes later
- **WHEN** an administrator changes the instance default redirect mode
- **THEN** links with an explicit mode keep it, and links without one follow the new default

### Requirement: Writes are immediately visible to the redirect path
The system SHALL ensure that creating, editing, disabling, or deleting a link takes effect on the next
redirect request without waiting for a cache expiry.

#### Scenario: Destination is edited
- **WHEN** an owner changes a link's destination and a visitor immediately requests the slug
- **THEN** the visitor is sent to the new destination

#### Scenario: Link is deleted
- **WHEN** an owner deletes a link and a visitor immediately requests the slug
- **THEN** the visitor receives the not-found response

### Requirement: Links can be organized and found
The system SHALL support free-form tags on links, and SHALL let operators search links by slug,
destination, and tag, and filter by domain and owner.

#### Scenario: Searching by destination
- **WHEN** an operator searches for a fragment of a destination URL
- **THEN** the system returns links whose destination contains that fragment, subject to the requester's role

#### Scenario: Deleting a link retains its history
- **WHEN** an owner deletes a link
- **THEN** previously recorded click events remain queryable for reporting until retention removes them
