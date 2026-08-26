#!/usr/bin/env bash
# Verifies that a clean host reaches the setup wizard with nothing done by hand
# between the bring-up command and the wizard.
#
# Destructive: every volume and every image built from this repository is
# removed first, because a bring-up that reuses an applied schema or a warm
# image is not a clean-host bring-up.
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE=(docker compose -f compose.yaml -f compose.dev.yaml)

fail() { printf '\n%s\n' "$1" >&2; exit 1; }

set -a
# shellcheck disable=SC1091
[[ -f .env ]] && . ./.env
set +a

# Checked after .env is read, not before. APP_ENV lives in that file rather than
# the shell, so guarding first reads the `local` default on the very host this
# is meant to protect — and then removes its volumes and its setup token.
if [[ "${APP_ENV:-local}" == "production" ]]; then
    printf 'Refusing to destroy a production instance.\n' >&2
    exit 1
fi

BASE="http://127.0.0.1:${HTTP_PORT:-8080}"

printf 'Removing every volume and locally built image...\n'
"${COMPOSE[@]}" down -v --rmi local >/dev/null 2>&1 || true
rm -rf run

printf 'One command from a clean host:\n\n    make setup\n\n'
make setup >/tmp/clean-host-setup.log 2>&1 \
    || fail "make setup failed. Its output is in /tmp/clean-host-setup.log"

printf 'Waiting for the edge to answer...\n'
for _ in $(seq 1 60); do
    code=$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/" 2>/dev/null || echo 000)
    [[ "$code" != "000" ]] && break
    sleep 5
done

[[ "$code" != "000" ]] || fail "The instance never answered on ${BASE}."

# The interface must send an uninstalled instance to the wizard, and it must be
# the wizard rather than a sign-in page or an error.
location=$(curl -s -o /dev/null -w '%{redirect_url}' "${BASE}/")

[[ "$location" == *"/setup" ]] \
    || fail "The root did not redirect to setup; it answered ${code} for ${location:-no redirect}."

setup_code=$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/setup")

[[ "$setup_code" == "200" ]] || fail "The setup route answered ${setup_code}."

# Matched on what the server renders. The wizard's first step is a client
# component, so its heading is not in this HTML — that step is driven by the
# @firstboot browser suite, which is the right tool for it.
curl -s "${BASE}/setup" | grep -qi '<title>Setup' \
    || fail "The setup route answered 200 but did not render the setup page."

# The claim gate must already exist, without anyone having run a command for it.
[[ -s run/setup-token ]] \
    || fail "No setup token was written to the host-mounted path."

installed=$(curl -s "${BASE}/api/v1/config" | grep -o '"installed":[a-z]*' || true)

[[ "$installed" == '"installed":false' ]] \
    || fail "The instance does not report itself uninstalled: ${installed}."

printf '\nA clean host reached the setup wizard from one command, with a setup token\nalready written to %s and nothing done by hand.\n' "$(pwd)/run/setup-token"
