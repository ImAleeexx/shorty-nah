## Purpose

Extends link management with a QR code per link, a UTM builder over the destination field, and bulk
import and export.

## ADDED Requirements

### Requirement: Every link has a QR code
The system SHALL generate a QR code encoding a link's short URL, render it using the instance's
configured accent and corner radius, and offer it for download as both PNG and SVG.

#### Scenario: Requesting a code for a link
- **WHEN** an operator requests the QR code for a link
- **THEN** the system returns an image encoding that link's short URL on its own domain

#### Scenario: Branding changes
- **WHEN** the instance accent is changed and a QR code is requested again
- **THEN** the newly rendered code uses the new accent without a rebuild

#### Scenario: Contrast is insufficient to scan
- **WHEN** the configured accent would render a code below the contrast a scanner requires
- **THEN** the system renders the code in ink rather than the accent, and reports that it did

#### Scenario: A code is requested for a link the account cannot read
- **WHEN** an account requests the QR code for a link outside its scope
- **THEN** the system responds as though the link does not exist

### Requirement: Scans are distinguishable from other clicks
The system SHALL mark the short URL encoded in a QR code so that a resulting click is attributed to the
code, and SHALL report scans separately from other clicks without inflating the total.

#### Scenario: A scan is recorded
- **WHEN** a visitor follows a short URL from a QR code
- **THEN** the click is recorded once, counted once in the total, and attributed as a scan

### Requirement: Destinations may be composed with campaign parameters
The system SHALL offer a builder that writes campaign parameters onto a link's destination, SHALL store
the result as the destination itself, and SHALL read existing parameters back out of a destination when
one is edited.

#### Scenario: Parameters are applied
- **WHEN** an operator supplies campaign parameters for a destination carrying none
- **THEN** the stored destination carries those parameters as query parameters

#### Scenario: A destination already carries parameters
- **WHEN** an operator edits a link whose destination already carries campaign parameters
- **THEN** the builder shows the existing values, and changing one replaces it rather than appending a duplicate

#### Scenario: A destination carries unrelated query parameters
- **WHEN** campaign parameters are applied to a destination carrying its own query parameters
- **THEN** the existing parameters are preserved unchanged

#### Scenario: A preset is applied
- **WHEN** an operator selects a saved campaign preset
- **THEN** the preset's values populate the builder and may be edited before saving

### Requirement: Links can be imported in bulk
The system SHALL accept a CSV upload of links, validate every row against the same rules that govern
single creation, process the batch outside the request, and report the outcome of every row.

#### Scenario: A valid file is imported
- **WHEN** an operator uploads a CSV of valid rows and confirms the target domain
- **THEN** the system queues the batch and reports progress until every row is processed

#### Scenario: One row is invalid
- **WHEN** a file contains rows that are valid and one that is not
- **THEN** the valid rows are created, the invalid row is not, and the result reports the reason against that row

#### Scenario: A row names a destination the instance refuses
- **WHEN** an imported row's destination resolves to a private or loopback address
- **THEN** that row is refused for the same reason single creation refuses it

#### Scenario: A row claims a slug already in use
- **WHEN** an imported row names a slug that already exists on the target domain
- **THEN** that row is refused and the existing link is left unchanged

#### Scenario: The operator asks for a dry run
- **WHEN** an operator imports with a dry run requested
- **THEN** the system reports the outcome of every row and creates nothing

#### Scenario: The file is not a CSV the system can read
- **WHEN** an uploaded file cannot be parsed, or lacks the required header
- **THEN** the system refuses the upload and names what was expected, without queueing a batch

### Requirement: Links can be exported in bulk
The system SHALL export links as CSV, honouring the requesting account's scope and any filters applied,
in a format the import accepts.

#### Scenario: An export is requested
- **WHEN** an operator exports links
- **THEN** the system returns a CSV of the links that account may read

#### Scenario: An export is re-imported
- **WHEN** an exported file is imported onto another instance
- **THEN** every row is accepted, subject to slug availability on the target domain

#### Scenario: An export is requested by a restricted account
- **WHEN** an account that may read only its own links requests an export
- **THEN** the file contains only those links

#### Scenario: A protected link is exported
- **WHEN** a link carrying a password is exported
- **THEN** the file records that it is protected and does not carry the password or its hash
