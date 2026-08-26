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

### Requirement: Passwords are stored and checked safely
The system SHALL hash passwords with a memory-hard algorithm at a tuned cost, SHALL compare candidate
passwords in constant time, and SHALL NOT store, log, or transmit a password in recoverable form.

#### Scenario: A password is stored
- **WHEN** an account's password is set or changed
- **THEN** only a memory-hard hash is persisted, and the plaintext appears in no log or diagnostic

#### Scenario: A weak password is submitted
- **WHEN** a password shorter than the configured minimum, or one appearing in the bundled list of commonly
  used passwords, is submitted
- **THEN** the system rejects it and states the requirement

#### Scenario: Hash cost is raised later
- **WHEN** the configured hash cost is increased and an account signs in successfully with its existing password
- **THEN** the stored hash is transparently upgraded to the new cost

### Requirement: Sessions are bound to a single privilege level
The system SHALL issue a new session identifier on authentication and on any change of privilege, SHALL
mark session cookies as secure, HTTP-only, and same-site, and SHALL invalidate an account's other sessions
when its password changes.

#### Scenario: Signing in
- **WHEN** an account authenticates successfully
- **THEN** the prior session identifier is discarded and a new one is issued

#### Scenario: Password is changed
- **WHEN** an account changes its password
- **THEN** every other active session for that account stops being accepted, and the current one continues

#### Scenario: Signing out everywhere
- **WHEN** an account requests that all other sessions end
- **THEN** the system invalidates them and records the event

#### Scenario: Session cookie attributes
- **WHEN** a session cookie is issued over HTTPS
- **THEN** it is marked secure, HTTP-only, and same-site

### Requirement: Accounts can require a second factor
The system SHALL support authenticator-app one-time codes and WebAuthn passkeys as second factors, SHALL
issue single-use recovery codes when a second factor is first enrolled, and SHALL allow an operator to
require a second factor for every account.

#### Scenario: Enrolling an authenticator app
- **WHEN** an account enrols an authenticator app and confirms a generated code
- **THEN** the second factor becomes active and single-use recovery codes are issued once

#### Scenario: Enrolling a passkey
- **WHEN** an account registers a WebAuthn credential
- **THEN** the credential becomes usable as a second factor and is listed with the date it was added

#### Scenario: Signing in with a second factor active
- **WHEN** an account with a second factor authenticates with a correct password
- **THEN** the session is not established until the second factor is satisfied

#### Scenario: Reusing a one-time code
- **WHEN** a previously accepted authenticator code is submitted again within its validity window
- **THEN** the system refuses it

#### Scenario: Using a recovery code
- **WHEN** an account authenticates with a recovery code
- **THEN** the code is consumed, cannot be used again, and the account is told how many remain

#### Scenario: Operator requires a second factor
- **WHEN** an operator enables the instance-wide requirement and an account without a second factor signs in
- **THEN** the account may only reach second-factor enrolment until it has one

#### Scenario: Removing the last second factor while required
- **WHEN** an account attempts to remove its only second factor while the instance-wide requirement is active
- **THEN** the system refuses

### Requirement: Sensitive operations require recent authentication
The system SHALL require the acting account to have authenticated recently before it changes an email
address or password, enrols or removes a second factor, issues an API token, or deletes a domain.

#### Scenario: Sensitive action with a stale session
- **WHEN** an account attempts a sensitive operation without having authenticated within the configured window
- **THEN** the system requires the password again before proceeding

#### Scenario: Sensitive action shortly after signing in
- **WHEN** an account attempts a sensitive operation within the configured window
- **THEN** the operation proceeds without a further prompt

### Requirement: Issued secrets are stored only as hashes
The system SHALL store API tokens, invitation tokens, password-reset tokens, and recovery codes as
one-way hashes, generate them from a cryptographically secure source, and expire reset tokens on a short
window and on first use.

#### Scenario: A token is issued
- **WHEN** any API token, invitation, reset token, or recovery code is created
- **THEN** only its hash is persisted, and the value is displayed to the requester once

#### Scenario: A reset token is reused
- **WHEN** a password-reset token is submitted after it has already been used
- **THEN** the system refuses it

#### Scenario: Requesting a reset for an unknown address
- **WHEN** a password reset is requested for an address with no account
- **THEN** the response is indistinguishable from a request for an existing account

### Requirement: Roles cannot be escalated by the acting account
The system SHALL prevent an account from changing its own role and from granting a role above its own.

#### Scenario: Account edits its own role
- **WHEN** an account submits a change to its own role
- **THEN** the system refuses

#### Scenario: Granting a role above the actor's
- **WHEN** an administrator attempts to grant the owner role
- **THEN** the system refuses

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
