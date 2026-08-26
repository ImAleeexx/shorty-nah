#!/usr/bin/env bash
# Fails when anything but the edge publishes a port in the production topology.
#
# Postgres, Redis and ClickHouse hold everything this instance knows and are
# reachable from every application container already. Publishing one to the host
# turns a single-host deployment into an exposed datastore, and the dev override
# does exactly that on purpose — which is why this reads the production file
# alone.
set -euo pipefail

cd "$(dirname "$0")/.."

# Placeholder values: this parses the topology, it does not run it.
export APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
export APP_URL="https://example.test"
export APP_DOMAIN="example.test"
export NEXT_PUBLIC_APP_URL="https://example.test"
export CADDY_ACME_EMAIL="ci@example.test"
export DB_DATABASE="shortynah" DB_USERNAME="shortynah" DB_PASSWORD="placeholder"
export DB_APP_USERNAME="shortynah_app" DB_APP_PASSWORD="placeholder"
export REDIS_PASSWORD="placeholder"
export CLICKHOUSE_DATABASE="shortynah_events"
export CLICKHOUSE_WRITE_USERNAME="w" CLICKHOUSE_WRITE_PASSWORD="placeholder"
export CLICKHOUSE_READ_USERNAME="r" CLICKHOUSE_READ_PASSWORD="placeholder"
export BACKUP_KEY="placeholder"

offenders=$(docker compose -f compose.yaml config --format json 2>/dev/null | python3 -c "
import json, sys

topology = json.load(sys.stdin)
bad = []

for name, service in topology.get('services', {}).items():
    published = [p for p in service.get('ports', []) or [] if p.get('published')]

    if published and name != 'edge':
        for port in published:
            bad.append(f\"{name} publishes {port.get('published')} -> {port.get('target')}\")

print('\n'.join(bad))
")

if [[ -n "$offenders" ]]; then
    printf '%s\n' "$offenders" >&2
    printf '\nOnly the edge may publish a port.\n' >&2
    exit 1
fi

printf 'Only the edge publishes a port; the datastores are reachable on the internal network alone.\n'
