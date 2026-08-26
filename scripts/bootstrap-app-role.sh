#!/usr/bin/env bash
# Creates the application's database role on a volume that predates it.
#
# The Postgres image runs its init scripts only when the data directory is
# empty, so an instance created before the owner/application split has no
# application role — and every service then fails to start with
# `role "shortynah_app" does not exist`. This is the upgrade path, so that the
# only recovery is not "destroy the database".
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE=(docker compose -f compose.yaml -f compose.dev.yaml)

set -a
# shellcheck disable=SC1091
[[ -f .env ]] && . ./.env
set +a

: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_APP_USERNAME:?DB_APP_USERNAME is required}"
: "${DB_APP_PASSWORD:?DB_APP_PASSWORD is required}"

"${COMPOSE[@]}" exec -T -e PGPASSWORD="$DB_PASSWORD" postgres \
    psql -v ON_ERROR_STOP=1 -U "$DB_USERNAME" -d "$DB_DATABASE" <<SQL
DO \$\$ BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${DB_APP_USERNAME}') THEN
        CREATE ROLE "${DB_APP_USERNAME}" LOGIN PASSWORD '${DB_APP_PASSWORD}';
    END IF;
END \$\$;

GRANT CONNECT ON DATABASE "${DB_DATABASE}" TO "${DB_APP_USERNAME}";
GRANT USAGE ON SCHEMA public TO "${DB_APP_USERNAME}";
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO "${DB_APP_USERNAME}";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO "${DB_APP_USERNAME}";

ALTER DEFAULT PRIVILEGES FOR ROLE "${DB_USERNAME}" IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO "${DB_APP_USERNAME}";
ALTER DEFAULT PRIVILEGES FOR ROLE "${DB_USERNAME}" IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO "${DB_APP_USERNAME}";

-- The blanket grant above would otherwise hand back exactly what makes the
-- audit log append-only.
REVOKE UPDATE, DELETE, TRUNCATE ON audit_entries FROM "${DB_APP_USERNAME}";
SQL

printf '\nThe application role %s exists and holds no write on the audit log.\n' "$DB_APP_USERNAME"
