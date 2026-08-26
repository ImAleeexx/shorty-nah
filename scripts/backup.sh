#!/usr/bin/env bash
# Backs up everything an instance cannot be rebuilt without: application data,
# click events, and uploaded branding assets.
#
# Artefacts are encrypted. They contain the settings store — whose sensitive
# values are encrypted with the application key, which is also in here — plus
# sessions and issued-secret hashes. An unencrypted copy of this is the whole
# instance.
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE=(docker compose -f compose.yaml -f compose.dev.yaml)
OUT_DIR="${1:-backups/$(date -u +%Y%m%dT%H%M%SZ)}"

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
    printf 'BACKUP_KEY is not set. A backup that cannot be encrypted is not taken.\n' >&2
    exit 1
fi

mkdir -p "$OUT_DIR"

# Encryption runs inside the api container: it is the only image here carrying
# openssl, and this way the host needs nothing but docker.
encrypt() {
    "${COMPOSE[@]}" exec -T -e BACKUP_KEY="$BACKUP_KEY" api \
        openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:BACKUP_KEY
}

printf 'Application data...\n'
"${COMPOSE[@]}" exec -T -e PGPASSWORD="$DB_PASSWORD" postgres \
    pg_dump --clean --if-exists --no-owner --no-privileges -U "$DB_USERNAME" -d "$DB_DATABASE" \
    | encrypt > "$OUT_DIR/postgres.sql.enc"

printf 'Click events...\n'
"${COMPOSE[@]}" exec -T clickhouse \
    clickhouse-client --user "$CLICKHOUSE_WRITE_USERNAME" --password "$CLICKHOUSE_WRITE_PASSWORD" \
    -d "$CLICKHOUSE_DATABASE" --query "SELECT * FROM click_events FORMAT Native" \
    | encrypt > "$OUT_DIR/clickhouse.native.enc"

printf 'Uploaded assets...\n'
# Trailing dot so the archive holds the directory's contents, not the directory.
"${COMPOSE[@]}" exec -T api tar -cf - -C /app/storage/app/public . \
    | encrypt > "$OUT_DIR/assets.tar.enc"

cat > "$OUT_DIR/manifest.txt" <<MANIFEST
Shorty-Nah backup
taken: $(date -u +%Y-%m-%dT%H:%M:%SZ)
artefacts: postgres.sql.enc, clickhouse.native.enc, assets.tar.enc
cipher: aes-256-cbc, pbkdf2, 200000 iterations
restore: scripts/restore.sh $OUT_DIR
MANIFEST

printf '\nBacked up to %s\n' "$OUT_DIR"
ls -la "$OUT_DIR"
