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
if ! (cd apps/api && composer audit \
        --no-interaction \
        --abandoned=report \
        --ignore-severity=low \
        --ignore-severity=medium); then
    printf '  a high or critical advisory affects an API dependency\n' >&2
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
