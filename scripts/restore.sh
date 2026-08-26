#!/usr/bin/env bash
# Restores a backup onto a running instance.
#
# Every artefact is decrypted before any of them is applied. A restore that
# writes application data and then discovers it cannot read the event store has
# left the instance in a state nobody asked for.
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE=(docker compose -f compose.yaml -f compose.dev.yaml)
IN_DIR="${1:?usage: scripts/restore.sh <backup-directory>}"

# An explicitly supplied key wins over the one in .env. Sourcing unconditionally
# would silently ignore what the operator passed in, which is exactly how a
# wrong-key restore appears to succeed.
INCOMING_BACKUP_KEY="${BACKUP_KEY:-}"

set -a
# shellcheck disable=SC1091
[[ -f .env ]] && . ./.env
set +a

if [[ -n "$INCOMING_BACKUP_KEY" ]]; then
    BACKUP_KEY="$INCOMING_BACKUP_KEY"
fi

if [[ -z "${BACKUP_KEY:-}" ]]; then
    printf 'BACKUP_KEY is not set. Nothing was written.\n' >&2
    exit 1
fi

for artefact in postgres.sql.enc clickhouse.native.enc assets.tar.enc; do
    [[ -f "$IN_DIR/$artefact" ]] || { printf '%s is missing from %s. Nothing was written.\n' "$artefact" "$IN_DIR" >&2; exit 1; }
done

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

decrypt() {
    "${COMPOSE[@]}" exec -T -e BACKUP_KEY="$BACKUP_KEY" api \
        openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_KEY
}

printf 'Decrypting every artefact before applying any...\n'

for artefact in postgres.sql.enc clickhouse.native.enc assets.tar.enc; do
    if ! decrypt < "$IN_DIR/$artefact" > "$STAGE/${artefact%.enc}" 2>/dev/null; then
        printf '\n%s could not be decrypted. The key is wrong, and nothing was written.\n' "$artefact" >&2
        exit 1
    fi
done

printf 'Application data...\n'
"${COMPOSE[@]}" exec -T -e PGPASSWORD="$DB_PASSWORD" postgres \
    psql --quiet -U "$DB_USERNAME" -d "$DB_DATABASE" < "$STAGE/postgres.sql" >/dev/null

printf 'Click events...\n'
# Truncated first so a restore is a replacement rather than a merge, and the
# materialized rollups rebuild from the inserted rows.
"${COMPOSE[@]}" exec -T clickhouse \
    clickhouse-client --user "$CLICKHOUSE_WRITE_USERNAME" --password "$CLICKHOUSE_WRITE_PASSWORD" \
    -d "$CLICKHOUSE_DATABASE" --query "TRUNCATE TABLE click_events"

"${COMPOSE[@]}" exec -T clickhouse \
    clickhouse-client --user "$CLICKHOUSE_WRITE_USERNAME" --password "$CLICKHOUSE_WRITE_PASSWORD" \
    -d "$CLICKHOUSE_DATABASE" --query "INSERT INTO click_events FORMAT Native" \
    < "$STAGE/clickhouse.native"

printf 'Uploaded assets...\n'
"${COMPOSE[@]}" exec -T api tar -xf - -C /app/storage/app/public < "$STAGE/assets.tar"

printf 'Clearing cached settings and the domain registry...\n'
"${COMPOSE[@]}" exec -T api php artisan cache:clear >/dev/null

printf '\nRestored from %s\n' "$IN_DIR"
