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
# ON_ERROR_STOP, or psql reports each failed statement and still exits 0 — which
# would leave a half-written database while this script prints success, the one
# thing its ordering above exists to prevent.
"${COMPOSE[@]}" exec -T -e PGPASSWORD="$DB_PASSWORD" postgres \
    psql --quiet -v ON_ERROR_STOP=1 -U "$DB_USERNAME" -d "$DB_DATABASE" < "$STAGE/postgres.sql" >/dev/null

# The dump is taken with --no-privileges and applied with --clean, so every table
# is dropped and recreated carrying whatever the application role holds by
# default. That silently discards the targeted REVOKE that makes the audit log
# append-only — the enforcement is the missing grant, not a guard in code, so
# losing it turns the audit trail into an ordinary editable table.
printf 'Re-applying the audit log grant policy...\n'

"${COMPOSE[@]}" exec -T -e PGPASSWORD="$DB_PASSWORD" postgres \
    psql --quiet -v ON_ERROR_STOP=1 -U "$DB_USERNAME" -d "$DB_DATABASE" \
    -c "REVOKE UPDATE, DELETE, TRUNCATE ON audit_entries FROM \"${DB_APP_USERNAME}\"" >/dev/null

# Asserted, because Postgres treats a REVOKE issued by a non-owner as a warning
# rather than an error: without this check a restore that failed to re-harden the
# table would report success.
remaining=$("${COMPOSE[@]}" exec -T -e PGPASSWORD="$DB_PASSWORD" postgres \
    psql --quiet --tuples-only --no-align -U "$DB_USERNAME" -d "$DB_DATABASE" \
    -c "select count(*) from information_schema.role_table_grants where table_name='audit_entries' and grantee='${DB_APP_USERNAME}' and privilege_type in ('UPDATE','DELETE','TRUNCATE')" | tr -d '[:space:]')

if [[ "$remaining" != "0" ]]; then
    printf '\nThe audit log could not be made append-only again: %s grant(s) remain.\n' "$remaining" >&2
    exit 1
fi

printf 'Click events...\n'
# Truncated first so a restore is a replacement rather than a merge, and the
# materialized rollups rebuild from the inserted rows.
# The rollups are separate persistent tables fed by materialized views, so they
# are not emptied by truncating the events they were built from. Leaving them
# would double every reported figure when the re-INSERT fires the views again on
# top of what is already there — and dashboards read rollups, so the raw events
# would look perfectly fine.
for table in click_events click_hourly click_by_country click_by_referrer click_by_client; do
    "${COMPOSE[@]}" exec -T clickhouse \
        clickhouse-client --user "$CLICKHOUSE_WRITE_USERNAME" --password "$CLICKHOUSE_WRITE_PASSWORD" \
        -d "$CLICKHOUSE_DATABASE" --query "TRUNCATE TABLE IF EXISTS ${table}"
done

# An instance that has never been clicked backs up an empty event store, and
# ClickHouse refuses an INSERT with nothing in it. Restoring a fresh instance is
# the ordinary case, not an error.
if [[ -s "$STAGE/clickhouse.native" ]]; then
    "${COMPOSE[@]}" exec -T clickhouse \
        clickhouse-client --user "$CLICKHOUSE_WRITE_USERNAME" --password "$CLICKHOUSE_WRITE_PASSWORD" \
        -d "$CLICKHOUSE_DATABASE" --query "INSERT INTO click_events FORMAT Native" \
        < "$STAGE/clickhouse.native"
else
    printf '  the backup holds no click events\n'
fi

printf 'Uploaded assets...\n'
"${COMPOSE[@]}" exec -T api tar -xf - -C /app/storage/app/public < "$STAGE/assets.tar"

printf 'Clearing cached settings and the domain registry...\n'
"${COMPOSE[@]}" exec -T api php artisan cache:clear >/dev/null

printf '\nRestored from %s\n' "$IN_DIR"
