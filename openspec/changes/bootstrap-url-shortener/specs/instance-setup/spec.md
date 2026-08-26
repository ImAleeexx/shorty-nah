## Purpose

Turns a freshly deployed instance into a configured one, and owns the runtime settings store that every
other capability reads its configuration from so that operators can reconfigure the product without
editing files or restarting containers.

## ADDED Requirements

### Requirement: Uninstalled instance directs operators to setup
An instance that has never completed installation SHALL treat setup as the only reachable destination
on the application domain.

#### Scenario: First visit to an uninstalled instance
- **WHEN** an operator opens any application route on an instance that has not completed installation
- **THEN** the system redirects to the setup route

#### Scenario: API is unavailable before installation
- **WHEN** a client calls any authenticated API endpoint on an uninstalled instance
- **THEN** the system responds `503` with a body indicating that setup is incomplete

### Requirement: Setup cannot be claimed without proof of host access
On first boot the system SHALL generate a high-entropy setup token, emit it to the container log and write
it to a file inside a host-mounted location, and SHALL require that token before the setup flow accepts any
configuration. The token SHALL be invalidated when installation completes.

#### Scenario: Setup reached without the token
- **WHEN** a visitor opens the setup flow on an uninstalled instance and does not supply the setup token
- **THEN** the system presents only the token prompt and accepts no configuration, creates no account, and reveals nothing about the instance beyond that it is uninstalled

#### Scenario: Setup reached with the token
- **WHEN** a visitor supplies the setup token generated for this instance
- **THEN** the system admits them to the wizard

#### Scenario: Incorrect token
- **WHEN** an incorrect setup token is submitted
- **THEN** the system refuses, records the attempt, and rate-limits repeated attempts from that source

#### Scenario: Token after installation
- **WHEN** installation has completed
- **THEN** the token no longer grants access to anything and is removed from the host-mounted location

#### Scenario: Token survives a restart before installation
- **WHEN** the instance restarts while still uninstalled
- **THEN** the same token remains valid, rather than a new one being generated

### Requirement: Setup verifies its dependencies before accepting configuration
The setup flow SHALL confirm that Postgres, Redis, and ClickHouse are reachable, and SHALL NOT allow the
operator to continue past the connectivity step while any of them is unreachable.

#### Scenario: A datastore is unreachable
- **WHEN** the operator requests the connectivity check and ClickHouse cannot be reached
- **THEN** the system reports which dependency failed and the reason
- **AND** the operator cannot advance to the next step

#### Scenario: All datastores reachable
- **WHEN** the operator requests the connectivity check and every dependency responds
- **THEN** the system reports each dependency as healthy and unlocks the next step

### Requirement: The connectivity check probes only configured dependencies
The system SHALL check only the datastores named by its own environment configuration, and SHALL NOT accept
a host, port, or connection string supplied through the setup flow.

#### Scenario: Caller supplies a host to check
- **WHEN** a request to the connectivity step includes a host or connection string
- **THEN** the system ignores it and checks only its configured dependencies

### Requirement: Setup collects the instance's initial configuration
The setup flow SHALL collect, in order, an administrator account, the instance identity and its primary
domain, branding, analytics configuration, the registration mode, and outbound mail settings. Mail
settings SHALL be skippable.

#### Scenario: Administrator account is created
- **WHEN** the operator submits a name, email address, and password meeting the password policy
- **THEN** the system creates the first account with the owner role

#### Scenario: Optional steps are skipped
- **WHEN** the operator skips the mail step
- **THEN** setup completes and features requiring mail report themselves as unconfigured rather than failing silently

#### Scenario: Setup is resumed after interruption
- **WHEN** the operator reloads the setup flow after completing some steps
- **THEN** the system restores the previously completed steps and resumes at the first incomplete one

### Requirement: Completing setup permanently closes it
Once installation completes, the system SHALL record the completion and SHALL refuse all further access
to the setup flow.

#### Scenario: Setup route after installation
- **WHEN** anyone requests the setup route on an installed instance
- **THEN** the system responds `404`

#### Scenario: Setup submission after installation
- **WHEN** a client submits data to any setup endpoint on an installed instance
- **THEN** the system rejects the request and does not modify any configuration

### Requirement: Installation is possible without a browser
The system SHALL provide a headless installation command accepting the same configuration as the wizard,
so that instances can be provisioned by deployment automation.

#### Scenario: Headless installation of a fresh instance
- **WHEN** the operator runs the installation command with all required values on an uninstalled instance
- **THEN** the system applies the configuration, creates the owner account, and marks the instance installed

#### Scenario: Headless installation of an installed instance
- **WHEN** the operator runs the installation command on an already installed instance
- **THEN** the command exits non-zero and changes nothing

#### Scenario: Missing required value
- **WHEN** the operator runs the installation command without a required value and without an interactive terminal
- **THEN** the command exits non-zero naming the missing value, and changes nothing

### Requirement: Settings are runtime state, not deployment configuration
Product configuration SHALL be stored in the settings store and SHALL take effect without restarting any
process. Infrastructure credentials SHALL NOT be settings.

#### Scenario: A setting is changed
- **WHEN** an administrator changes a setting
- **THEN** subsequent requests observe the new value without any process restart

#### Scenario: Sensitive settings at rest
- **WHEN** a setting is marked sensitive, such as the mail password or the geo licence key
- **THEN** the system encrypts it at rest and never returns its value in an API response

#### Scenario: Unknown setting key
- **WHEN** a client attempts to write a setting key the system does not define
- **THEN** the system rejects the request

### Requirement: Public configuration is available unauthenticated
The system SHALL expose the subset of settings required to render the interface — instance name,
branding, registration mode, and installation state — without authentication, and SHALL exclude every
other setting.

#### Scenario: Fetching public configuration
- **WHEN** an unauthenticated client requests the public configuration
- **THEN** the system returns the interface subset and no sensitive or operational values
