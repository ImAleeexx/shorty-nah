#!/usr/bin/env bash
# Verifies that the Postgres start guard refuses a half-initialised data
# directory and passes every other one through to the image's own entrypoint.
#
# The image treats the presence of PG_VERSION as "initialised" and skips every
# later step — the network access rule, the database, the application role. A
# first boot interrupted inside initdb therefore starts on the next attempt as a
# cluster that refuses every connection over the network, and nothing names the
# cause. The guard does, and this is what proves it.
set -euo pipefail

cd "$(dirname "$0")/.."

guard=$PWD/docker/postgres/entrypoint.sh
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

# The real entrypoint is stood in for by a stub that reports it was reached and
# with which arguments, so the pass-through cases assert on that.
mkdir -p "$work/bin"
cat > "$work/bin/docker-entrypoint.sh" <<'STUB'
#!/usr/bin/env bash
printf 'image entrypoint reached: %s\n' "$*"
STUB
chmod +x "$work/bin/docker-entrypoint.sh"

failures=0

run_guard() {
    local pgdata=$1
    shift
    PATH="$work/bin:$PATH" PGDATA="$pgdata" bash "$guard" "$@" 2>&1 || printf 'exit=%s\n' "$?"
}

expect() {
    local name=$1 output=$2 pattern=$3
    if ! grep -q -- "$pattern" <<<"$output"; then
        printf 'FAIL %s: expected %q in:\n%s\n\n' "$name" "$pattern" "$output" >&2
        failures=$((failures + 1))
    fi
}

refute() {
    local name=$1 output=$2 pattern=$3
    if grep -q -- "$pattern" <<<"$output"; then
        printf 'FAIL %s: did not expect %q in:\n%s\n\n' "$name" "$pattern" "$output" >&2
        failures=$((failures + 1))
    fi
}

# An empty directory is a first boot: the image initialises it.
mkdir -p "$work/empty"
out=$(run_guard "$work/empty" postgres)
expect "empty directory passes through" "$out" 'image entrypoint reached: postgres'

# A directory the image finished initialising carries the network rule its
# entrypoint appends after initdb.
mkdir -p "$work/complete"
printf '17\n' > "$work/complete/PG_VERSION"
cat > "$work/complete/pg_hba.conf" <<'HBA'
# TYPE  DATABASE        USER            ADDRESS                 METHOD
local   all             all                                     scram-sha-256
host    all             all             127.0.0.1/32            scram-sha-256
host    all             all             ::1/128                 scram-sha-256

host all all all scram-sha-256
HBA
out=$(run_guard "$work/complete" postgres -c shared_buffers=256MB)
expect "complete directory passes through" "$out" 'image entrypoint reached: postgres -c shared_buffers=256MB'

# initdb wrote its own pg_hba.conf, with loopback rules only, and was stopped
# before the entrypoint appended the network rule.
mkdir -p "$work/interrupted"
printf '17\n' > "$work/interrupted/PG_VERSION"
sed '/^host all all all/d' "$work/complete/pg_hba.conf" > "$work/interrupted/pg_hba.conf"
out=$(run_guard "$work/interrupted" postgres)
refute "interrupted directory is refused" "$out" 'image entrypoint reached'
expect "interrupted directory exits non-zero" "$out" 'exit=1$'
expect "refusal names the cause" "$out" 'interrupted'
expect "refusal names the remedy" "$out" 'postgres-data'

# Stopped even earlier: PG_VERSION exists but initdb had not written pg_hba.conf.
mkdir -p "$work/bare"
printf '17\n' > "$work/bare/PG_VERSION"
out=$(run_guard "$work/bare" postgres)
refute "bare directory is refused" "$out" 'image entrypoint reached'
expect "bare directory exits non-zero" "$out" 'exit=1$'

if (( failures > 0 )); then
    printf '\n%d postgres guard problem(s).\n' "$failures" >&2
    exit 1
fi

printf 'The Postgres guard refuses a half-initialised volume and passes the rest through.\n'
