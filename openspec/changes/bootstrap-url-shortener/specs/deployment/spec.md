## Purpose

Defines what an operator must supply to run the instance and what the instance guarantees in return —
a single-host bring-up, honest health reporting, safe schema changes, and recoverable data.

## ADDED Requirements

### Requirement: The instance starts from a single command
The system SHALL bring up every required service from one command on a host with a container runtime,
requiring no manual step between that command and reaching the setup flow.

#### Scenario: Bringing up a fresh host
- **WHEN** an operator supplies the required environment values and runs the bring-up command on a clean host
- **THEN** every service starts, schema is applied, and the setup flow is reachable

### Requirement: Required configuration is validated at startup
The system SHALL verify that all required environment values are present and well-formed before accepting
traffic, and SHALL fail loudly rather than starting in a partially configured state.

#### Scenario: A required value is missing
- **WHEN** a required environment value is absent
- **THEN** the affected service exits non-zero with a message naming the missing value
- **AND** the service does not begin serving requests

#### Scenario: A value is malformed
- **WHEN** a required value is present but not parseable, such as a malformed datastore URL
- **THEN** the service exits non-zero naming the offending value

### Requirement: Every service reports its own health
The system SHALL expose a health check per service that reflects that service's ability to do its work,
including reachability of the dependencies it uses.

#### Scenario: A dependency is down
- **WHEN** a service's required datastore is unreachable
- **THEN** that service's health check reports unhealthy and identifies the dependency

#### Scenario: Queue processing has stalled
- **WHEN** queued work is not being consumed
- **THEN** the worker's health check reports unhealthy

#### Scenario: Container orchestration observes health
- **WHEN** a service reports unhealthy
- **THEN** the container runtime observes that state through the service's configured health check

### Requirement: Schema changes are idempotent and separately applied per store
The system SHALL apply application schema and event-store schema through distinct, repeatable
operations, each safe to run when already up to date.

#### Scenario: Re-running schema application
- **WHEN** an operator applies schema on an instance already up to date
- **THEN** the operation succeeds and changes nothing

#### Scenario: Event store schema is applied
- **WHEN** an operator applies event-store schema
- **THEN** the operation targets only the event store and does not run application migrations

#### Scenario: Deploying a new version
- **WHEN** an operator deploys a newer version
- **THEN** schema is applied before the new application processes begin serving traffic

### Requirement: Workers shut down without losing work
The system SHALL allow in-flight queued work to finish, up to a configured grace period, when a worker is
asked to stop, and SHALL return unfinished work to the queue.

#### Scenario: Worker is asked to stop mid-job
- **WHEN** a worker receives a termination signal while processing a job
- **THEN** it finishes the job within the grace period, or the job returns to the queue for another worker

### Requirement: Certificates are obtained without operator action
The system SHALL obtain and renew certificates automatically for the application domain and for every
registered short domain whose DNS points at the instance.

#### Scenario: A new short domain is registered
- **WHEN** an administrator registers and verifies a new short domain
- **THEN** the system obtains a certificate for it on first request without further operator action

#### Scenario: Certificate is nearing expiry
- **WHEN** an existing certificate approaches expiry
- **THEN** the system renews it without interrupting service

### Requirement: Data can be backed up and restored
The system SHALL provide a documented, operator-runnable backup covering application data, event data,
and uploaded branding assets, and a restore path that produces a working instance.

#### Scenario: Backup is taken
- **WHEN** an operator runs the backup operation on a running instance
- **THEN** it produces artefacts covering application data, event data, and uploaded assets

#### Scenario: Restore onto a clean host
- **WHEN** an operator restores those artefacts onto a clean host and brings the instance up
- **THEN** links resolve and historical reports are available

### Requirement: Datastores are not reachable from outside the host
The system SHALL keep Postgres, Redis, and ClickHouse on an internal network without publishing their ports
to the host by default, and SHALL require authentication on each.

#### Scenario: Inspecting published ports
- **WHEN** an operator lists the published ports of a running instance
- **THEN** only the edge proxy's ports are published

#### Scenario: Redis without a password
- **WHEN** Redis is configured without a password
- **THEN** the dependent services fail their environment validation and do not start

### Requirement: Backups are encrypted at rest
The system SHALL encrypt backup artefacts, because they contain the settings store including encrypted
secrets, session data, and the application key material needed to read them.

#### Scenario: A backup is produced
- **WHEN** the backup operation completes
- **THEN** its artefacts are encrypted and cannot be read without the configured backup key

#### Scenario: Restoring without the key
- **WHEN** a restore is attempted without the backup key
- **THEN** the operation fails without partially writing data

### Requirement: Images contain no instance secrets
The system SHALL supply all credentials and instance-specific values at runtime, and SHALL NOT embed them
in container images.

#### Scenario: Inspecting a built image
- **WHEN** an operator inspects a built image's layers and environment
- **THEN** no instance credentials, keys, or licence values are present
