#!/usr/bin/env bash
# Compiles every PHP file.
#
# PHPStan does not catch this class of defect: its parser accepts constructs PHP
# itself rejects at compile time. An unparenthesized `a ? b : c ?: d` passed
# static analysis, then killed the test runner with no output at all — the file
# could not be compiled, so nothing ran and nothing was reported.
set -euo pipefail

cd "$(dirname "$0")/../apps/api"

failed=0

while IFS= read -r file; do
    if ! output=$(php -l "$file" 2>&1); then
        printf '%s\n' "$output"
        failed=$((failed + 1))
    fi
done < <(find app config database routes tests bootstrap -name '*.php' -type f)

if (( failed > 0 )); then
    printf '\n%d file(s) do not compile.\n' "$failed" >&2
    exit 1
fi

echo "All PHP files compile."
