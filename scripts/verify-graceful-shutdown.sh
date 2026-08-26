#!/usr/bin/env bash
# Verifies that a worker asked to stop finishes the job it is holding.
#
# The failure this guards against is silent: a worker killed mid-job loses the
# work, and nothing reports it. So the check dispatches a job that takes long
# enough to still be running when the signal arrives, stops the worker, and then
# asks whether the work actually happened.
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE=(docker compose -f compose.yaml -f compose.dev.yaml)
MARKER="graceful-shutdown-$(date +%s)"

fail() { printf '\n%s\n' "$1" >&2; exit 1; }

printf 'Dispatching a job that outlives the signal...\n'

# A real job class, not a queued closure: the closure serializer reflects on the
# file that defined it, and code passed to tinker has none.
"${COMPOSE[@]}" exec -T api php artisan tinker --execute="
Tests\\Support\\SleepingJob::dispatch('${MARKER}')->onQueue('default');
echo 'queued';
" >/dev/null

# Long enough that the worker has certainly picked it up and is inside the sleep.
sleep 3

printf 'Stopping the worker while it is mid-job...\n'
"${COMPOSE[@]}" stop worker >/dev/null 2>&1

state=$("${COMPOSE[@]}" exec -T api php artisan tinker --execute="
echo Illuminate\\Support\\Facades\\Cache::get('${MARKER}') ?? 'absent';
" 2>/dev/null | tr -d '[:space:]')

printf 'Restarting the worker...\n'
"${COMPOSE[@]}" start worker >/dev/null 2>&1

if [[ "$state" == *finished* ]]; then
    printf '\nThe in-flight job completed before the worker exited.\n'
    exit 0
fi

# The other acceptable outcome: the job went back to the queue for someone else.
printf 'Job did not complete in place; checking it returned to the queue...\n'
sleep 12

requeued=$("${COMPOSE[@]}" exec -T api php artisan tinker --execute="
echo Illuminate\\Support\\Facades\\Cache::get('${MARKER}') ?? 'absent';
" 2>/dev/null | tr -d '[:space:]')

if [[ "$requeued" == *finished* ]]; then
    printf '\nThe job returned to the queue and was completed by the restarted worker.\n'
    exit 0
fi

fail "The job was neither finished nor requeued: the work was lost on shutdown."
