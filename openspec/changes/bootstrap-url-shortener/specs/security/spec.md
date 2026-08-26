## Purpose

Holds the security rules that no single feature owns — how every request is authorized, how the instance
decides who a caller is, what every response asserts to the browser, what gets recorded when something
security-relevant happens, and what must be true of the supply chain before a release ships.

## ADDED Requirements

### Requirement: Every object reference is authorized against the acting identity
The system SHALL authorize each referenced object on every request against the acting identity, and SHALL
derive ownership and scope from server-side state rather than from any client-supplied value.

#### Scenario: Client supplies an owner it does not control
- **WHEN** a request includes an owner, user, or domain identifier that the acting identity is not authorized to act for
- **THEN** the system ignores the supplied value and applies the acting identity's own scope

#### Scenario: Nested resource belonging to another owner
- **WHEN** a caller requests a resource nested under a parent it does not own, such as click events for another owner's link
- **THEN** the system refuses the request without disclosing whether the resource exists

#### Scenario: Unauthorized read of an existing object
- **WHEN** an authenticated caller requests an object that exists but that its role does not permit reading
- **THEN** the system responds `404` rather than `403`, so the response does not confirm the object exists

#### Scenario: Authorization is enforced server-side only
- **WHEN** a caller manipulates a request to claim a higher role or a different scope
- **THEN** the outcome is unchanged, because the decision uses stored role and ownership rather than the request

### Requirement: Publicly exposed identifiers are not enumerable
The system SHALL expose only non-sequential identifiers for links, users, invitations, tokens, domains, and
uploaded assets in URLs, API payloads, and exports.

#### Scenario: Identifier in an API response
- **WHEN** any resource is returned by the API
- **THEN** its exposed identifier is a non-sequential value from which no other valid identifier can be derived

#### Scenario: Attempting to walk the identifier space
- **WHEN** a caller increments or decrements an exposed identifier and requests the result
- **THEN** the request does not resolve to a neighbouring resource

### Requirement: The client address is taken only from a trusted proxy
The system SHALL determine the client address from forwarding headers only when the immediate peer is a
configured trusted proxy, and SHALL otherwise use the peer address.

#### Scenario: Forwarding header from an untrusted peer
- **WHEN** a request arrives directly from an untrusted peer carrying a forwarding header
- **THEN** the system ignores the header and attributes the request to the peer address

#### Scenario: Forwarding header from the edge proxy
- **WHEN** a request arrives from the configured edge proxy carrying a forwarding header
- **THEN** the system attributes the request to the forwarded address

#### Scenario: Spoofed address cannot evade abuse limits
- **WHEN** a caller varies a forwarding header across many requests to appear as different clients
- **THEN** rate limiting continues to attribute every request to the same source

#### Scenario: Spoofed address cannot forge analytics
- **WHEN** a caller varies a forwarding header while requesting a short link
- **THEN** recorded click events attribute the same source, and geographic data is not influenced by the header

### Requirement: Responses assert a hardened browser policy
The system SHALL send, on every interface and interstitial response, a content security policy, strict
transport security, a referrer policy, a frame-ancestors restriction, a permissions policy, and content-type
sniffing protection. It SHALL NOT disclose server or framework versions.

#### Scenario: Interface response headers
- **WHEN** any interface page is served over HTTPS
- **THEN** the response carries a content security policy, strict transport security, a referrer policy, a permissions policy, `nosniff`, and a frame-ancestors restriction that forbids embedding

#### Scenario: Version disclosure
- **WHEN** any response is inspected
- **THEN** no header or body discloses the server, framework, or language version

#### Scenario: Attempted embedding
- **WHEN** a third-party page attempts to embed an interface page or the interstitial in a frame
- **THEN** the browser refuses to render it

### Requirement: The script policy admits no inline allowances
The system SHALL express its content security policy without `unsafe-inline` or `unsafe-eval`, authorising
the interstitial's inline style and script by per-response nonce.

#### Scenario: Interstitial inline script
- **WHEN** the interstitial page is served
- **THEN** its inline style and script carry a nonce matching the response policy, and the policy contains no inline or eval allowance

#### Scenario: Injected inline script
- **WHEN** a script is injected into a page without a valid nonce
- **THEN** the browser refuses to execute it

#### Scenario: Nonce is not reused
- **WHEN** the same page is requested twice
- **THEN** each response carries a different nonce

### Requirement: Unauthenticated surfaces are rate limited
The system SHALL apply an abuse limit to every surface reachable without authentication — sign-in,
registration, invitation redemption, password reset, the setup flow, the link password gate, the redirect
path, and the interstitial beacon — and SHALL apply limits per source and, where an account is named, per
account.

#### Scenario: Repeated attempts against one surface
- **WHEN** a source exceeds the configured limit for an unauthenticated surface
- **THEN** the system refuses further attempts and reports when they may resume

#### Scenario: Distributed attempts against one account
- **WHEN** attempts against a single account arrive from many sources
- **THEN** the per-account limit still applies

#### Scenario: Refused attempts are not counted as activity
- **WHEN** a request is refused by an abuse limit
- **THEN** no click event is recorded and no authentication attempt is registered against the account beyond the refusal itself

### Requirement: Security-relevant events are recorded in an append-only audit log
The system SHALL record an audit entry for authentication outcomes, role changes, invitation issuance and
redemption, token creation and revocation, two-factor enrolment and removal, domain addition and removal,
settings changes, link password changes, analytics exports, and installation completion. Entries SHALL NOT
be editable or deletable through the application.

#### Scenario: A role is changed
- **WHEN** an administrator changes another account's role
- **THEN** the system records the actor, the action, the target, the derived source identifier, and the time

#### Scenario: A failed sign-in
- **WHEN** an authentication attempt fails
- **THEN** the system records the attempt without recording the submitted credential

#### Scenario: Attempting to alter history
- **WHEN** any caller attempts to modify or delete an audit entry through the application
- **THEN** the system refuses

#### Scenario: An operator reviews the log
- **WHEN** an owner views the audit log
- **THEN** entries are listed newest first and filterable by actor, action, and period

### Requirement: Diagnostics never contain secrets or raw addresses
The system SHALL exclude credentials, tokens, session identifiers, link passwords, licence keys, and raw
network addresses from logs, error reports, and exception traces.

#### Scenario: A request carrying a token fails
- **WHEN** a request that carries an API token raises an error
- **THEN** the recorded diagnostic redacts the token

#### Scenario: A settings write fails
- **WHEN** writing a sensitive setting raises an error
- **THEN** the recorded diagnostic redacts the value

#### Scenario: Debug output in production
- **WHEN** the instance runs with debug disabled and an error occurs
- **THEN** the response body contains no stack trace, file path, or configuration value

### Requirement: Model attributes are not mass assignable by default
The system SHALL require each model to declare the attributes that may be filled from request input, and
SHALL reject attempts to set undeclared attributes.

#### Scenario: Request includes an undeclared attribute
- **WHEN** a request body includes an attribute a model has not declared as fillable, such as a role or an owner reference
- **THEN** the attribute is not written

### Requirement: Dependencies and images are scanned before release
The system SHALL fail its release pipeline on a known high or critical severity advisory in a runtime
dependency, on a detected secret in the repository, and on a high or critical vulnerability in a built
image. Base images SHALL be pinned by digest.

#### Scenario: A dependency advisory appears
- **WHEN** the pipeline runs while a runtime dependency carries a known high or critical advisory
- **THEN** the pipeline fails and names the dependency

#### Scenario: A secret is committed
- **WHEN** the pipeline runs against a commit containing a credential-shaped string
- **THEN** the pipeline fails and names the location

#### Scenario: Base image is referenced by tag alone
- **WHEN** a container definition references a base image without a digest
- **THEN** the pipeline fails
