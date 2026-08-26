#!/usr/bin/env bash
# Verifies that schema is applied before any application process serves traffic.
#
# Checked in the topology rather than by watching a deploy: an ordering that
# holds because a container happened to be slow is not an ordering.
set -euo pipefail

cd "$(dirname "$0")/.."

config=$(docker compose -f compose.yaml config)
violations=0

# Every long-running application service must wait for the schema one-shot to
# have exited successfully.
for service in api worker clicks scheduler; do
    block=$(printf '%s' "$config" | awk -v svc="  ${service}:" '
        $0 == svc { inside = 1; next }
        inside && /^  [a-z]/ { inside = 0 }
        inside { print }
    ')

    if ! printf '%s' "$block" | grep -q 'schema:'; then
        printf '%s does not depend on the schema service\n' "$service" >&2
        violations=$((violations + 1))
        continue
    fi

    if ! printf '%s' "$block" | grep -A2 'schema:' | grep -q 'service_completed_successfully'; then
        printf '%s depends on schema but not on its successful completion\n' "$service" >&2
        violations=$((violations + 1))
    fi
done

# The schema service must apply both stores, not just Postgres.
if ! grep -q 'clickhouse:migrate' docker/api/entrypoint.sh; then
    printf 'the schema step does not apply the event store schema\n' >&2
    violations=$((violations + 1))
fi

# Matched on the guarantee rather than the exact flags: the connection it runs
# as is a separate concern, checked below.
if ! grep -qE 'artisan migrate .*--force' docker/api/entrypoint.sh; then
    printf 'the schema step does not apply the application schema\n' >&2
    violations=$((violations + 1))
fi

# Migrations run as the owning role. The application's role holds no UPDATE or
# DELETE on the audit table, which includes not being able to create it.
if ! grep -q 'database=pgsql_owner' docker/api/entrypoint.sh; then
    printf 'the schema step does not apply migrations as the owning role\n' >&2
    violations=$((violations + 1))
fi

if (( violations > 0 )); then
    printf '\n%d schema-ordering problem(s).\n' "$violations" >&2
    exit 1
fi

printf 'Schema is applied before any application process serves traffic.\n'
