#!/bin/bash
set -euo pipefail

# The image's own environment creates the superuser. Migrations run as that
# account and own every table; the application connects as a separate,
# unprivileged role.
#
# This split is what makes the audit log append-only. A superuser bypasses
# permission checks entirely, so a REVOKE against one is decoration — the
# privilege has to be missing from a role that cannot simply grant it back.
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE ROLE "${DB_APP_USERNAME}" LOGIN PASSWORD '${DB_APP_PASSWORD}';

GRANT CONNECT ON DATABASE "${POSTGRES_DB}" TO "${DB_APP_USERNAME}";
GRANT USAGE ON SCHEMA public TO "${DB_APP_USERNAME}";

-- Applies to tables the migrator creates from here on, which is all of them.
ALTER DEFAULT PRIVILEGES FOR ROLE "${POSTGRES_USER}" IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO "${DB_APP_USERNAME}";

ALTER DEFAULT PRIVILEGES FOR ROLE "${POSTGRES_USER}" IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO "${DB_APP_USERNAME}";
SQL
