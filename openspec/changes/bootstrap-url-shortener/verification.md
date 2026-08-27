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
| `click-analytics` | 21 | `ClickPipelineTest`, `ClickEnrichmentTest`, `GeoResolutionTest`, `ClickHouseConnectionTest`, `AnalyticsReportingTest`, `InterstitialTest`; `e2e/analytics.spec.ts`, `e2e/interstitial.spec.ts` |
| `deployment` | 19 | `VerifyEnvironmentTest`, `HealthTest`, `ClickHouseMigrateTest`; `scripts/verify-clean-host.sh`, `verify-schema-ordering.sh`, `verify-graceful-shutdown.sh`, `verify-restore.sh`, `check-published-ports.sh`, `check-image-secrets.sh`, `check-image-pins.sh` |
| `domains` | 9 | `DomainTest`, `LinkSlugTest`, `RedirectHotPathTest` |
| `identity` | 35 | `AuthenticationTest`, `SessionLifecycleTest`, `RegistrationModeTest`, `RoleAuthorizationTest`, `IssuedSecretTest`, `TwoFactorTest`; `e2e/auth.spec.ts`, `e2e/people.spec.ts`, `e2e/passkey.spec.ts` |
| `instance-setup` | 22 | `SetupFlowTest`, `SetupTokenTest`, `InstallCommandTest`, `SettingsStoreTest`, `SettingsApiTest`; `e2e/setup.spec.ts` |
| `link-management` | 23 | `LinkLifecycleTest`, `LinkSlugTest`, `LinkDestinationTest`, `LinkApiTest`, `RedirectConstraintTest`, `RedirectPasswordTest`; `e2e/links.spec.ts` |
| `redirection` | 16 | `RedirectHotPathTest`, `RedirectConstraintTest`, `RedirectPasswordTest`, `InterstitialTest`; `e2e/interstitial.spec.ts` |
| `security` | 30 | `ObjectAuthorizationSweepTest`, `AuditLogTest`, `TrustedProxyTest`, `MassAssignmentTest`, `LogRedactionTest`, `DiagnosticExposureTest`, `HorizonAccessTest`; `scripts/verify-audit-immutability.sh`, `scan-dependencies.sh`, `scan-secrets.sh`, `scan-images.sh` |

## Deliberate exceptions

One. It needs something this repository cannot contain, and names what would
close it.

Certificate issuance used to be one of them, on the assumption that it needed a
publicly reachable host. It does not: the DNS-01 challenge is answered with a TXT
record in the zone rather than a connection to the instance, so a host with no
open ports can hold a certificate from a real authority. Verified end to end —
`go.bfsqd.es` was issued by Let's Encrypt against an instance publishing nothing,
and an unregistered hostname produced no ACME order at all, because the edge asks
the application first.

Geographic resolution used to be another. It is now proved against the real
GeoLite2 databases by `GeoResolutionTest`, which reads what the sidecar
downloads and skips where they are absent — an instance without a MaxMind
licence is a supported configuration, not a broken one.

### Certificate renewal — `deployment`

> **WHEN** an existing certificate approaches expiry
> **THEN** the system renews it without interrupting service

Renewal is Caddy's, on a timer measured in weeks. What is proved: the certificate
for `go.bfsqd.es` is held with a renewal window the authority itself supplied
through ACME Renewal Information, and Caddy scheduled a time inside it — so the
renewal is not merely assumed to be configured, it is booked. What is not proved
is the renewal happening, because the certificate is valid for ninety days and
Caddy acts around thirty days out. Nobody can watch that in a sitting.

**Closes when** an instance has been running long enough to renew, or against a
test authority issuing minute-long certificates — which would make the loop
observable without waiting, at the cost of not being Let's Encrypt.

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
