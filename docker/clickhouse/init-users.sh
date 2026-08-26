#!/bin/bash
set -euo pipefail

# The image's own environment creates the write account. The reporting path gets
# a separate read-only account so a query cannot mutate the event store.
clickhouse client -n <<SQL
CREATE USER IF NOT EXISTS '${CLICKHOUSE_READ_USERNAME}'
    IDENTIFIED BY '${CLICKHOUSE_READ_PASSWORD}';

GRANT SELECT ON ${CLICKHOUSE_DB}.* TO '${CLICKHOUSE_READ_USERNAME}';
SQL
