# Verification

Where each capability's scenarios are proved, and which are deliberately not.

`specs/` declares **192 scenarios** across nine capabilities. This maps them to
the automated checks that exercise them, and names every exception with the
reason it exists. An exception here is a thing that cannot be proved from this
repository — not a thing that was awkward.

## Where the proof lives

| Capability | Scenarios | Proved by |
|---|---|---|
| `branding` | 17 | `BrandingTest`, `PublicConfigurationTest`; `e2e/foundation.spec.ts`, `e2e/settings.spec.ts`; `src/lib/color.test.ts`, `src/lib/branding.test.ts` |
| `click-analytics` | 21 | `ClickPipelineTest`, `ClickEnrichmentTest`, `ClickHouseConnectionTest`, `AnalyticsReportingTest`, `InterstitialTest`; `e2e/analytics.spec.ts`, `e2e/interstitial.spec.ts` |
| `deployment` | 19 | `VerifyEnvironmentTest`, `HealthTest`, `ClickHouseMigrateTest`; `scripts/verify-clean-host.sh`, `verify-schema-ordering.sh`, `verify-graceful-shutdown.sh`, `verify-restore.sh`, `check-published-ports.sh`, `check-image-secrets.sh`, `check-image-pins.sh` |
| `domains` | 9 | `DomainTest`, `LinkSlugTest`, `RedirectHotPathTest` |
| `identity` | 35 | `AuthenticationTest`, `SessionLifecycleTest`, `RegistrationModeTest`, `RoleAuthorizationTest`, `IssuedSecretTest`, `TwoFactorTest`; `e2e/auth.spec.ts`, `e2e/people.spec.ts`, `e2e/passkey.spec.ts` |
| `instance-setup` | 22 | `SetupFlowTest`, `SetupTokenTest`, `InstallCommandTest`, `SettingsStoreTest`, `SettingsApiTest`; `e2e/setup.spec.ts` |
| `link-management` | 23 | `LinkLifecycleTest`, `LinkSlugTest`, `LinkDestinationTest`, `LinkApiTest`, `RedirectConstraintTest`, `RedirectPasswordTest`; `e2e/links.spec.ts` |
| `redirection` | 16 | `RedirectHotPathTest`, `RedirectConstraintTest`, `RedirectPasswordTest`, `InterstitialTest`; `e2e/interstitial.spec.ts` |
| `security` | 30 | `ObjectAuthorizationSweepTest`, `AuditLogTest`, `TrustedProxyTest`, `MassAssignmentTest`, `LogRedactionTest`, `DiagnosticExposureTest`, `HorizonAccessTest`; `scripts/verify-audit-immutability.sh`, `scan-dependencies.sh`, `scan-secrets.sh`, `scan-images.sh` |

## Deliberate exceptions

Three. Each needs something this repository cannot contain, and each names what
would close it.

### Certificate issuance on first request — `deployment`

> **WHEN** an administrator registers and verifies a new short domain
> **THEN** the system obtains a certificate for it on first request

Automatic issuance needs public DNS pointing at the instance and a real
certificate authority. What is proved instead: the edge asks the API before
issuing, and the API answers `200` for a verified host and `404` for an unknown
one, over the Compose network, with the ask URL matching the registered route
(`DomainTest`, task 6.4). The remaining step is ACME itself.

**Closes when** the instance is deployed on a host with a public DNS name.

### Certificate renewal — `deployment`

> **WHEN** an existing certificate approaches expiry
> **THEN** the system renews it without interrupting service

Renewal is Caddy's, on a timer measured in weeks. There is no way to observe it
in a test that finishes. The configuration that governs it is validated
(`caddy validate` in CI), which is the part this repository owns.

**Closes when** an instance has been running long enough to renew, on the same
public host as above.

### Geographic resolution against a real database — `click-analytics`

> **WHEN** an address resolves
> **THEN** the click is recorded with its country and network

The GeoLite2 databases need a MaxMind licence key, which this instance has none
configured for. What is proved instead: the unresolved and missing-database cases
degrade to non-geographic while still recording the click, and the resolved path
is asserted against a stubbed resolver that also counts lookups — which is what
proves filtered traffic pays for none (`ClickEnrichmentTest`, task 10.4).

**Closes when** a licence key is configured on a deployed instance.

## Not exceptions, though they look like ones

- **A stalled queue** is Horizon's own supervision, configured through
  `config/horizon.php` and surfaced by the `horizon:status` health check. The
  configuration is asserted; the stall itself is a third-party supervisor's
  behaviour, not this application's.
- **Restore onto a clean host** is proved by destroying every volume and bringing
  the instance back from nothing (`verify-restore.sh`). A different physical
  machine would exercise the same code paths.
- **A dependency being down** is proved at the health endpoint (`HealthTest`) and
  again at the setup connectivity step (`SetupFlowTest`), which share one probe.
