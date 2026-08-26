#!/usr/bin/env bash
# Proves a backup is restorable onto a clean host.
#
# Destructive by necessity: the only honest way to test a restore is to destroy
# the instance first. Every volume is removed, the stack is brought back up from
# nothing, and the backup is applied. Then it asks the two questions that
# matter — does a link still resolve, and are the historical reports there.
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE=(docker compose -f compose.yaml -f compose.dev.yaml)
DIR="backups/restore-check"

set -a
# shellcheck disable=SC1091
[[ -f .env ]] && . ./.env
set +a

# Checked after .env is read, not before. APP_ENV lives in that file rather than
# the shell, so guarding first reads the `local` default on the very host this
# is meant to protect — and then destroys its volumes.
if [[ "${APP_ENV:-local}" == "production" ]]; then
    printf 'Refusing to destroy a production instance.\n' >&2
    exit 1
fi

fail() { printf '\n%s\n' "$1" >&2; exit 1; }

query_pg() {
    "${COMPOSE[@]}" exec -T -e PGPASSWORD="$DB_PASSWORD" postgres \
        psql -tA -U "$DB_USERNAME" -d "$DB_DATABASE" -c "$1" 2>/dev/null | tr -d '[:space:]'
}

query_ch() {
    "${COMPOSE[@]}" exec -T clickhouse \
        clickhouse-client --user "$CLICKHOUSE_WRITE_USERNAME" --password "$CLICKHOUSE_WRITE_PASSWORD" \
        -d "$CLICKHOUSE_DATABASE" -q "$1" 2>/dev/null | tr -d '[:space:]'
}

printf 'Recording the state to be restored...\n'
links_before=$(query_pg "select count(*) from links;")
events_before=$(query_ch "select count() from click_events")
printf '  %s links, %s click events\n' "$links_before" "$events_before"

[[ "$links_before" -gt 0 ]] || fail "Nothing to restore: seed the instance first with make e2e-fixture."

printf 'Taking the backup...\n'
rm -rf "$DIR"
./scripts/backup.sh "$DIR" >/dev/null

printf 'Destroying the host, volumes and all...\n'
"${COMPOSE[@]}" down -v >/dev/null 2>&1

printf 'Bringing up a clean instance...\n'
make up >/dev/null 2>&1

# The schema service has to finish before the datastores will accept anything.
for _ in $(seq 1 60); do
    [[ "$(query_pg "select count(*) from information_schema.tables where table_schema='public';")" -gt 0 ]] && break
    sleep 2
done

fresh_links=$(query_pg "select count(*) from links;")
[[ "$fresh_links" == "0" ]] || fail "The host was not clean: it still has ${fresh_links} links."

printf 'Restoring...\n'
./scripts/restore.sh "$DIR" >/dev/null

links_after=$(query_pg "select count(*) from links;")
events_after=$(query_ch "select count() from click_events")

[[ "$links_after" == "$links_before" ]] || fail "Links did not come back: ${links_after} of ${links_before}."
[[ "$events_after" == "$events_before" ]] || fail "Click events did not come back: ${events_after} of ${events_before}."

printf 'Checking a link actually resolves...\n'
slug=$(query_pg "select slug from links where deleted_at is null order by id limit 1;")
status=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: go.localhost' "http://127.0.0.1:8080/${slug}")

# Both are a resolution: a direct link answers 302, an interstitial one answers
# 200 with the hold page. What would mean the restore failed is 404.
[[ "$status" == "302" || "$status" == "200" ]] \
    || fail "A restored link did not resolve: /${slug} answered ${status}."

printf 'Checking historical reports survived...\n'
rollup=$(query_ch "select count() from click_hourly")
[[ "${rollup:-0}" -gt 0 ]] || fail "Rollups are empty, so historical reports are gone."

printf '\nRestored onto a clean host: %s links, %s events, /%s resolves, reports available.\n' \
    "$links_after" "$events_after" "$slug"
