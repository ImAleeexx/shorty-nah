## Purpose

Governs who can reach the instance and what they may do once inside, including the operator-selectable
registration mode that makes the same build usable as a private tool or an open service.

## ADDED Requirements

### Requirement: Registration mode controls account creation
The system SHALL support three registration modes — `closed`, `invite`, and `open` — configurable at
runtime, and SHALL enforce the active mode on every account-creation attempt.

#### Scenario: Registration while closed
- **WHEN** a visitor attempts to register and the mode is `closed`
- **THEN** the system rejects the attempt and no account is created

#### Scenario: Registration with a valid invitation
- **WHEN** a visitor registers with an unexpired, unused invitation token and the mode is `invite`
- **THEN** the system creates the account with the role recorded on the invitation and marks the token used

#### Scenario: Registration with a spent invitation
- **WHEN** a visitor registers with an invitation token that is expired, revoked, or already used
- **THEN** the system rejects the attempt and no account is created

#### Scenario: Registration while open
- **WHEN** a visitor registers with a valid email address and a password meeting the policy and the mode is `open`
- **THEN** the system creates the account with the default member role

#### Scenario: Mode change does not affect existing accounts
- **WHEN** an administrator switches the mode from `open` to `closed`
- **THEN** existing accounts retain access and only new registrations are refused

### Requirement: Roles determine authorization
The system SHALL assign every account exactly one of the roles `owner`, `admin`, `member`, or `viewer`,
and SHALL authorize every action against that role.

#### Scenario: Member accesses another member's link
- **WHEN** a member requests a link owned by a different account
- **THEN** the system responds `404` and does not disclose the link's existence

#### Scenario: Administrator accesses any link
- **WHEN** an administrator requests any link on the instance
- **THEN** the system returns it

#### Scenario: Viewer attempts a write
- **WHEN** an account with the viewer role attempts to create, edit, or delete a link
- **THEN** the system responds `403` and changes nothing

#### Scenario: The last owner cannot be removed
- **WHEN** an owner attempts to delete or demote the only remaining owner account
- **THEN** the system rejects the request

### Requirement: Authentication resists guessing
The system SHALL rate-limit authentication attempts per account and per source address, and SHALL respond
identically whether an email address exists or not.

#### Scenario: Repeated failed sign-in
- **WHEN** sign-in fails more than the configured number of times for one account
- **THEN** the system temporarily refuses further attempts for that account and reports when it may be retried

#### Scenario: Sign-in with an unregistered address
- **WHEN** a visitor attempts to sign in with an email address that has no account
- **THEN** the system returns the same failure response and timing characteristics as a wrong password

### Requirement: Programmatic access uses scoped tokens
The system SHALL allow accounts to issue named API tokens with explicit scopes and optional expiry, and
SHALL show a token's value only once, at creation.

#### Scenario: Token used within its scope
- **WHEN** a client calls an endpoint using a token whose scopes cover that endpoint
- **THEN** the system authorizes the request as the token's owner, bounded by that owner's role

#### Scenario: Token used outside its scope
- **WHEN** a client calls an endpoint not covered by the token's scopes
- **THEN** the system responds `403`

#### Scenario: Token is revoked
- **WHEN** an owner revokes a token
- **THEN** subsequent requests using it are rejected
