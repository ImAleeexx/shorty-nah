## Purpose

Lets one instance serve short links on several hostnames, and provides the authorization signal the edge
proxy needs before it will request a certificate for a hostname it has never seen.

## ADDED Requirements

### Requirement: An instance serves multiple short domains
The system SHALL allow administrators to register more than one short domain, and SHALL scope slug
uniqueness to a single domain so that the same slug may exist independently on two domains.

#### Scenario: The same slug on two domains
- **WHEN** a link exists with slug `launch` on `a.example` and an administrator creates a link with slug `launch` on `b.example`
- **THEN** the system creates the second link
- **AND** each hostname resolves `launch` to its own destination

#### Scenario: Duplicate slug on the same domain
- **WHEN** an administrator creates a link whose slug already exists on the same domain
- **THEN** the system rejects the request and identifies the conflict

### Requirement: Domains are verified before use
The system SHALL treat a registered domain as unverified until it observes that the domain resolves to
this instance, and SHALL refuse to serve links on an unverified domain.

#### Scenario: Verification succeeds
- **WHEN** an administrator triggers verification for a domain whose DNS points at the instance
- **THEN** the system marks the domain verified and begins serving its links

#### Scenario: Request to an unverified domain
- **WHEN** a visitor requests a slug on a registered but unverified domain
- **THEN** the system does not redirect and returns the not-found response

### Requirement: Certificate issuance is authorized per hostname
The system SHALL expose an endpoint the edge proxy consults before obtaining a certificate for a
hostname, approving only hostnames registered on this instance.

#### Scenario: Certificate requested for a registered domain
- **WHEN** the edge proxy asks whether it may obtain a certificate for a verified registered domain
- **THEN** the system approves the request

#### Scenario: Certificate requested for an unknown hostname
- **WHEN** the edge proxy asks about a hostname that is not registered on this instance
- **THEN** the system declines
- **AND** no certificate is requested from the certificate authority

### Requirement: One domain is the primary domain
The system SHALL designate exactly one verified domain as primary, use it for links created without an
explicit domain, and never leave the instance without a primary.

#### Scenario: Creating a link without naming a domain
- **WHEN** a client creates a link and supplies no domain
- **THEN** the system assigns the primary domain

#### Scenario: Deleting the primary domain
- **WHEN** an administrator attempts to delete the primary domain
- **THEN** the system rejects the request until another verified domain is promoted to primary

### Requirement: Domains with links cannot be silently removed
The system SHALL refuse to delete a domain that still has links, unless the operator explicitly
confirms deletion of those links.

#### Scenario: Deleting a domain that still has links
- **WHEN** an administrator deletes a domain holding links without confirming link deletion
- **THEN** the system rejects the request and reports how many links would be affected
