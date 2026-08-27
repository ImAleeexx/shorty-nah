#!/usr/bin/env bash
# Fails on a known high or critical advisory in either application's
# dependencies.
#
# Low and medium are reported but do not fail. A gate that fires on everything
# gets switched off, and the ones that matter go with it.
set -euo pipefail

cd "$(dirname "$0")/.."

failures=0

printf 'API dependencies\n'

# Composer has no severity threshold, only an ignore list, so the levels below
# high are named explicitly.
# --locked audits the lock file rather than an installed tree. It is what a
# supply-chain scan wants anyway — the declared versions, not whatever happens to
# be on this machine — and without it the audit refuses to run at all on a fresh
# checkout, exiting non-zero with "No installed packages found". That exit was
# being reported below as a high or critical advisory, which is a scan claiming
# a finding it never made.
if ! (cd apps/api && composer audit \
        --locked \
        --no-interaction \
        --abandoned=report \
        --ignore-severity=low \
        --ignore-severity=medium); then
    printf '  the API dependency audit reported a high or critical advisory, or could not run\n' >&2
    failures=$((failures + 1))
fi

printf '\nWeb dependencies\n'

if ! (cd apps/web && pnpm audit --audit-level high); then
    printf '  a high or critical advisory affects a web dependency\n' >&2
    failures=$((failures + 1))
fi

if (( failures > 0 )); then
    printf '\n%d dependency advisory scan(s) failed.\n' "$failures" >&2
    exit 1
fi

printf '\nNo high or critical advisories in either application.\n'
