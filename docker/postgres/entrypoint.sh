#!/usr/bin/env bash
# Refuses to start on a data directory whose first boot was interrupted.
#
# The image's entrypoint takes PG_VERSION as proof of an initialised cluster and
# skips everything it would otherwise do after initdb: append the network access
# rule, create the database, run the init scripts that create the application
# role. A first `up` stopped inside initdb — a Ctrl-C, a lost session — leaves
# exactly that, and every later start comes up as a cluster that answers each
# connection from another container with "no pg_hba.conf entry for host" and
# never says why. initdb's own pg_hba.conf covers loopback only, so the rule the
# entrypoint appends is the one piece of evidence that init got past initdb.
set -euo pipefail

: "${PGDATA:=/var/lib/postgresql/data}"

if [ -s "$PGDATA/PG_VERSION" ] \
    && ! grep -Eq '^host[[:space:]]+all[[:space:]]+all[[:space:]]+all[[:space:]]' "$PGDATA/pg_hba.conf" 2>/dev/null; then
    cat >&2 <<MESSAGE

Postgres refuses to start: the data directory was initialised only partway.

$PGDATA holds PG_VERSION, which the image takes as a finished cluster, but not
the network access rule the image adds once initdb has returned. The first start
was interrupted inside initdb — stopped, killed or Ctrl-C'd — and every later
start would skip initialisation and refuse each connection from another
container with "no pg_hba.conf entry for host". No database or role from that
first boot exists, so there is nothing here to keep.

Remove the volume and start again:

    docker compose down
    docker volume rm <project>_postgres-data    # <project> is the directory name unless you set one
    docker compose up -d

MESSAGE
    exit 1
fi

exec docker-entrypoint.sh "$@"
