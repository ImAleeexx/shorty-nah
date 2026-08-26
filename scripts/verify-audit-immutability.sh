#!/usr/bin/env bash
# Verifies the audit log cannot be rewritten by the application.
#
# The guarantee is a missing privilege, not a guard in code, so it has to be
# tested where privileges live. A guard can be bypassed by the next person who
# writes a query; a revoked privilege cannot.
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE=(docker compose -f compose.yaml -f compose.dev.yaml)

set -a
# shellcheck disable=SC1091
[[ -f .env ]] && . ./.env
set +a

violations=0

# A refused statement is the expected outcome of most of these, and psql exits
# non-zero for it. Swallowing that here keeps `set -e` from treating a passing
# check as a script failure.
as_app() {
    "${COMPOSE[@]}" exec -T -e PGPASSWORD="$DB_APP_PASSWORD" postgres \
        psql -tA -U "$DB_APP_USERNAME" -d "$DB_DATABASE" -c "$1" 2>&1 | tail -1 || true
}

# A superuser bypasses every permission check, so if the application connects as
# one, nothing below means anything.
if [[ "$(as_app 'select usesuper from pg_user where usename = current_user;')" != "f" ]]; then
    printf 'the application role is a superuser, so no revoke can constrain it\n' >&2
    exit 1
fi

marker="immutability-probe-$(date +%s)"

if [[ "$(as_app "insert into audit_entries (action, created_at) values ('${marker}', now());")" != "INSERT 0 1" ]]; then
    printf 'the application cannot append to the audit log, which it must be able to do\n' >&2
    exit 1
fi

for statement in \
    "update audit_entries set action='tampered' where action='${marker}';" \
    "delete from audit_entries where action='${marker}';" \
    "truncate audit_entries;"
do
    result=$(as_app "$statement")

    if [[ "$result" != *"permission denied"* ]]; then
        printf 'the application was permitted to run: %s\n    got: %s\n' "$statement" "$result" >&2
        violations=$((violations + 1))
    fi
done

if (( violations > 0 )); then
    printf '\nThe audit log is not append-only: %d statement(s) were permitted.\n' "$violations" >&2
    exit 1
fi

printf 'The audit log is append-only: the application may insert and read, nothing else.\n'
