#!/usr/bin/env bash
# Fails when a container definition references a base image by tag alone.
# A tag is mutable: the same build can silently pick up different content.
set -euo pipefail

cd "$(dirname "$0")/.."

files=(docker/api/Dockerfile docker/web/Dockerfile compose.yaml compose.dev.yaml)
violations=0

for file in "${files[@]}"; do
    [[ -f "$file" ]] || continue

    while IFS=: read -r line content; do
        # Images built from this repository carry no digest by design.
        [[ "$content" == *shortynah-* ]] && continue
        # Multi-stage references to a local stage are not registry pulls.
        [[ "$content" =~ FROM[[:space:]]+(vendor|build|runtime|deps|development)([[:space:]]|$) ]] && continue
        [[ "$content" == *"@sha256:"* ]] && continue

        printf '%s:%s: unpinned image reference\n    %s\n' \
            "$file" "$line" "$(echo "$content" | sed 's/^[[:space:]]*//')"
        violations=$((violations + 1))
    done < <(grep -nE '^[[:space:]]*(FROM|image:)[[:space:]]+[^[:space:]]+' "$file" || true)
done

if (( violations > 0 )); then
    printf '\n%d unpinned image reference(s). Pin with image:tag@sha256:...\n' "$violations" >&2
    exit 1
fi

echo "All base image references are digest-pinned."
