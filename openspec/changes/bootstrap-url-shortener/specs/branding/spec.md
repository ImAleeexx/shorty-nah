## Purpose

Lets an operator make the instance look like their own product — colour, shape, logo, and typography —
without rebuilding or redeploying anything, across a single interface design with light and dark modes.

## ADDED Requirements

### Requirement: Branding changes apply without a rebuild
The system SHALL apply branding changes to the interface without recompiling assets or restarting any
process.

#### Scenario: Accent colour is changed
- **WHEN** an administrator changes the accent colour and reloads the interface
- **THEN** the new colour is applied throughout without any build step

#### Scenario: Logo is replaced
- **WHEN** an administrator uploads a new logo
- **THEN** the interface and the interstitial hold page both show it on the next request

### Requirement: Branding is present on first paint
The system SHALL deliver branding with the initial document so that no interface renders with default
styling before branded styling is applied.

#### Scenario: First page load
- **WHEN** a visitor loads any branded page for the first time with an empty cache
- **THEN** the branded appearance is present on first paint with no visible restyle

### Requirement: The instance has one design with light and dark modes
The system SHALL provide exactly one interface design, SHALL follow the viewer's system colour-scheme
preference by default, and SHALL allow a viewer to override it. No alternative themes SHALL be
selectable.

#### Scenario: Viewer's system preference is dark
- **WHEN** a viewer with a dark system preference and no stored override loads the interface
- **THEN** the interface renders in dark mode

#### Scenario: Viewer overrides the mode
- **WHEN** a viewer selects light mode explicitly
- **THEN** that choice persists for that viewer and overrides the system preference

### Requirement: Branding assets are constrained
The system SHALL validate uploaded branding assets by type and size, and SHALL reject anything that is
not a supported image format.

#### Scenario: Unsupported asset
- **WHEN** an administrator uploads a branding asset that is not a supported image format
- **THEN** the system rejects it and states the supported formats

#### Scenario: Scriptable image format
- **WHEN** an administrator uploads an SVG, or any format that can carry script or external references
- **THEN** the system rejects it, because branding assets are served from the same origin as the interface

#### Scenario: Format disguised by name or declared type
- **WHEN** an uploaded file's extension or declared content type claims a supported image format but its contents are not that format
- **THEN** the system rejects it, having determined the format by decoding the file rather than trusting the claim

#### Scenario: Dimensions far beyond what is needed
- **WHEN** an uploaded image decodes to pixel dimensions beyond the configured maximum
- **THEN** the system rejects it before allocating memory for the full image

#### Scenario: A stored asset is served
- **WHEN** a branding asset is served
- **THEN** it is stored under a generated name, served with a content type from a server-side allowlist and with sniffing disabled, and the client-supplied filename is never used as a path

#### Scenario: Oversized asset
- **WHEN** an administrator uploads an asset exceeding the configured size limit
- **THEN** the system rejects it and states the limit

### Requirement: Branding cannot produce an unreadable interface
The system SHALL evaluate the contrast of a chosen accent colour against the surfaces it is used on in
both light and dark modes, and SHALL warn the administrator when the result fails the accessibility
threshold.

#### Scenario: Low-contrast accent colour
- **WHEN** an administrator chooses an accent colour that fails the contrast threshold in either mode
- **THEN** the system warns before saving and offers a compliant adjustment

#### Scenario: Text remains legible regardless of accent choice
- **WHEN** any accent colour is applied
- **THEN** body and heading text continue to meet the contrast threshold in both modes

### Requirement: Branding choices are bounded to what stays legible
The system SHALL constrain each branding control to a range the design can express — a single accent hue,
a corner radius within a defined range, and a typeface from a curated list — and SHALL reject values
outside those bounds.

#### Scenario: Radius outside the permitted range
- **WHEN** an administrator submits a corner radius outside the permitted range
- **THEN** the system rejects the value and states the permitted range

#### Scenario: Typeface outside the curated list
- **WHEN** an administrator submits a typeface that is not on the curated list
- **THEN** the system rejects the value and returns the available choices

#### Scenario: Every offered typeface renders identifiers unambiguously
- **WHEN** a slug is displayed in any offered typeface
- **THEN** the characters `0` and `O`, and `1` and `l`, are visually distinguishable

### Requirement: Motion respects viewer preference
The system SHALL suppress non-essential animation for viewers who have requested reduced motion.

#### Scenario: Viewer prefers reduced motion
- **WHEN** a viewer with a reduced-motion preference uses the interface
- **THEN** decorative and transitional animation is suppressed while state changes remain perceivable
