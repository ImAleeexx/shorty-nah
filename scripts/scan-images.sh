#!/usr/bin/env bash
# Fails on a high or critical vulnerability in a built image.
#
# Scans what is actually shipped rather than the Dockerfile: a base image picks
# up advisories after it is written, and a pinned digest is exactly what makes
# that invisible without a scan.
set -euo pipefail

cd "$(dirname "$0")/.."

TRIVY="aquasec/trivy@sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969"

IMAGES=("$@")
if (( ${#IMAGES[@]} == 0 )); then
    IMAGES=("shortynah-api:dev" "shortynah-web:dev")
fi

# The database is large and changes daily; caching it keeps a re-run from
# downloading it again.
CACHE="${TRIVY_CACHE_DIR:-$HOME/.cache/trivy}"
mkdir -p "$CACHE"

failures=0

for image in "${IMAGES[@]}"; do
    if ! docker image inspect "$image" >/dev/null 2>&1; then
        printf 'skipping %s: not built\n' "$image"
        continue
    fi

    printf 'Scanning %s\n' "$image"

    report=$(mktemp)

    # --ignore-unfixed: an advisory with no available fix is not something a
    # release can act on, and failing on it only teaches people to pass --force.
    # Findings are judged below rather than by trivy's exit code, so the policy
    # that excludes a package is stated once, in code, with its reason.
    docker run --rm \
        -v /var/run/docker.sock:/var/run/docker.sock \
        -v "$CACHE:/root/.cache/trivy" \
        -v "$PWD/.trivyignore.yaml:/.trivyignore.yaml:ro" \
        "$TRIVY" image \
        --ignorefile /.trivyignore.yaml \
        --severity HIGH,CRITICAL \
        --ignore-unfixed \
        --no-progress \
        --scanners vuln \
        --format json \
        "$image" > "$report" 2>/dev/null

    findings=$(python3 - "$report" <<'PY'
import json, sys

# linux-libc-dev ships Linux kernel headers. Its advisories describe kernel
# vulnerabilities, and a container does not run its own kernel — it runs the
# host's. The package is present because the base image compiles PHP extensions.
# Excluded by package rather than by identifier because a new kernel advisory
# appears most weeks and a list of ids would be stale before it was reviewed.
EXCLUDED_PACKAGES = {"linux-libc-dev"}

with open(sys.argv[1]) as handle:
    report = json.load(handle)

rows = []

for result in report.get("Results", []) or []:
    for vulnerability in result.get("Vulnerabilities", []) or []:
        if vulnerability["PkgName"] in EXCLUDED_PACKAGES:
            continue

        rows.append(
            f"  {vulnerability['Severity']:8} {vulnerability['VulnerabilityID']:22} "
            f"{vulnerability['PkgName']} "
            f"{vulnerability.get('InstalledVersion', '')} -> {vulnerability.get('FixedVersion', '')}"
        )

print("\n".join(sorted(set(rows))))
PY
)

    rm -f "$report"

    if [[ -n "$findings" ]]; then
        printf '%s\n' "$findings" >&2
        printf '  %s carries a fixable high or critical vulnerability\n' "$image" >&2
        failures=$((failures + 1))
    else
        printf '  clean\n'
    fi
done

if (( failures > 0 )); then
    printf '\n%d image(s) carry a fixable high or critical vulnerability.\n' "$failures" >&2
    exit 1
fi

printf '\nNo fixable high or critical vulnerabilities in the built images.\n'
