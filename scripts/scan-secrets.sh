#!/usr/bin/env bash
# Fails when a credential-shaped string is committed to the repository.
#
# Runs over the working tree and the history: a secret removed in a later commit
# is still a leaked secret, and the fix is rotation rather than a tidy diff.
set -euo pipefail

cd "$(dirname "$0")/.."

IMAGE="zricethezav/gitleaks@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f"

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    printf 'Pulling the secret scanner...\n'
    docker pull --quiet "$IMAGE" >/dev/null
fi

# --redact so a finding names the location without reprinting the secret into a
# build log, which is often less protected than the repository itself.
scan() {
    docker run --rm -v "$PWD:/repo:ro" -w /repo "$IMAGE" \
        "$@" --config=/repo/.gitleaks.toml --redact --no-banner --verbose
}

failures=0

# History. A secret removed in a later commit is still a leaked secret, and the
# fix for it is rotation rather than a tidy diff.
printf 'Scanning history\n'
scan git /repo || failures=$((failures + 1))

# The working tree. The history scan cannot see a file that has not been
# committed, which is exactly when catching it is still cheap.
printf '\nScanning the working tree\n'
scan dir /repo || failures=$((failures + 1))

if (( failures > 0 )); then
    printf '\nA credential-shaped string is present. Rotate it, then remove it.\n' >&2
    exit 1
fi

printf '\nNo credential-shaped strings in history or the working tree.\n'
