#!/usr/bin/env bash
# Fails when a built image carries an instance's credentials.
#
# Three separate questions, because a secret reaches an image three ways: baked
# into the environment, copied in as a file, or captured in a build argument
# that lives on in the layer history.
set -euo pipefail

cd "$(dirname "$0")/.."

IMAGES=("${@:-}")
if [[ -z "${IMAGES[0]:-}" ]]; then
    IMAGES=("shortynah-api:dev" "shortynah-web:dev")
fi

set -a
# shellcheck disable=SC1091
[[ -f .env ]] && . ./.env
set +a

violations=0

# The values this instance actually holds. Searching for these beats searching
# for a pattern: a real secret is found even when it looks like ordinary text.
secrets=()
for name in APP_KEY DB_PASSWORD REDIS_PASSWORD CLICKHOUSE_WRITE_PASSWORD \
            CLICKHOUSE_READ_PASSWORD BACKUP_KEY CADDY_ACME_EMAIL; do
    value="${!name:-}"
    # Short values produce false positives; a real credential is not four characters.
    [[ -n "$value" && ${#value} -ge 12 ]] && secrets+=("$name=$value")
done

for image in "${IMAGES[@]}"; do
    if ! docker image inspect "$image" >/dev/null 2>&1; then
        printf 'skipping %s: not built\n' "$image"
        continue
    fi

    printf 'Inspecting %s\n' "$image"

    # 1. Environment baked into the image.
    while IFS= read -r entry; do
        key="${entry%%=*}"
        value="${entry#*=}"

        [[ -z "$value" ]] && continue

        if [[ "$key" =~ (PASSWORD|SECRET|KEY|TOKEN|LICENSE|LICENCE|DSN|CREDENTIAL) ]]; then
            # Paths are not credentials, and GPG_KEYS is the PHP image's own
            # list of public release-signing fingerprints — published values
            # whose whole purpose is to be distributed with the image.
            case "$key" in
                GEOIP_PATH|SETUP_TOKEN_PATH|GPG_KEYS|PHP_ASC_URL|PHP_SHA256) continue ;;
            esac

            printf '  image environment carries %s\n' "$key" >&2
            violations=$((violations + 1))
        fi
    done < <(docker image inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$image")

    # 2. A committed .env is the classic way an instance's whole configuration
    #    ends up in a layer.
    if docker run --rm --entrypoint sh "$image" -c '[ -f /app/.env ]' 2>/dev/null; then
        printf '  image contains /app/.env\n' >&2
        violations=$((violations + 1))
    fi

    # 3. This instance's real values, anywhere in the layers or the history.
    if (( ${#secrets[@]} == 0 )); then
        # Said out loud rather than passed over. With no values to look for, the
        # only checks that ran were the environment and the dotfile — and a run
        # that reports success without saying so reads as a full scan.
        printf '  no local credentials to search layers for; environment and dotfile only
'
    else
        history=$(docker history --no-trunc --format '{{.CreatedBy}}' "$image" 2>/dev/null || true)

        # Saved once to disk rather than held in a shell variable: an image is
        # gigabytes, and buffering it kills the process rather than failing the
        # check honestly.
        archive=$(mktemp)
        if ! docker save "$image" > "$archive" 2>/dev/null; then
            printf '  could not export %s to scan its layers\n' "$image" >&2
            rm -f "$archive"
            violations=$((violations + 1))
            continue
        fi

        for pair in "${secrets[@]}"; do
            name="${pair%%=*}"
            value="${pair#*=}"

            if printf '%s' "$history" | grep -qF -- "$value"; then
                printf '  build history contains the value of %s\n' "$name" >&2
                violations=$((violations + 1))
            fi

            if grep -qaF -- "$value" "$archive"; then
                printf '  image layers contain the value of %s\n' "$name" >&2
                violations=$((violations + 1))
            fi
        done

        rm -f "$archive"
    fi
done

if (( violations > 0 )); then
    printf '\n%d secret(s) present in built images.\n' "$violations" >&2
    exit 1
fi

printf '\nNo instance credentials, keys, or licence values in the built images.\n'
