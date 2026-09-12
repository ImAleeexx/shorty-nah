#!/usr/bin/env bash
# Verifies the edge's proxied mode: the configuration used when another proxy
# terminates TLS in front of this instance and forwards plain HTTP to it.
#
# Three things have to hold, and each has silently failed in some deployment
# somewhere. The edge must not redirect to HTTPS, or the two proxies loop. The
# forwarding headers from the proxy in front must reach the application, or
# every visitor is that proxy and every URL is http. And the same headers from
# anyone else must be discarded, or any client can choose its own address.
set -euo pipefail

cd "$(dirname "$0")/.."

caddy='caddy:2-alpine@sha256:5f5c8640aae01df9654968d946d8f1a56c497f1dd5c5cda4cf95ab7c14d58648'
net=sn-edge-verify
subnet=10.99.0.0/24
proxy_ip=10.99.0.50
stranger_ip=10.99.0.60
domain=app.example.test

remove_containers() {
    docker rm -f "$net-api" "$net-web" "$net-edge" >/dev/null 2>&1 || true
    docker network rm "$net" >/dev/null 2>&1 || true
}
# Leftovers from an interrupted run would collide on name and address.
remove_containers

work=$(mktemp -d)
trap 'remove_containers; rm -rf "$work"' EXIT

# Both upstreams echo the forwarding headers they receive, so the assertions
# read what the application would have seen.
for svc in api:8000 web:3000; do
    name=${svc%%:*}
    port=${svc##*:}
    printf ':%s {\n\trespond "%s proto={header.X-Forwarded-Proto} for={header.X-Forwarded-For}"\n}\n' \
        "$port" "$name" > "$work/$name.Caddyfile"
done

docker network create --subnet "$subnet" "$net" >/dev/null
for name in api web; do
    docker run -d --name "$net-$name" --network "$net" --network-alias "$name" \
        -v "$work/$name.Caddyfile:/etc/caddy/Caddyfile:ro" "$caddy" >/dev/null
done
docker run -d --name "$net-edge" --network "$net" --network-alias edge \
    -v "$PWD/docker/caddy/Caddyfile.proxied:/etc/caddy/Caddyfile:ro" \
    -e APP_DOMAIN="$domain" -e UPSTREAM_PROXIES="$proxy_ip/32" "$caddy" >/dev/null

for _ in $(seq 1 20); do
    if docker logs "$net-edge" 2>&1 | grep -q 'serving initial configuration'; then
        break
    fi
    sleep 0.5
done

# Prints status line and headers, then the body, as one stream.
request() {
    local from=$1
    shift
    docker run --rm --network "$net" --ip "$from" "$caddy" \
        wget -S -q -O - -T 5 "$@" 2>&1 || true
}

failures=0

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

spoofed=(--header="X-Forwarded-Proto: https" --header="X-Forwarded-For: 203.0.113.9")

out=$(request "$proxy_ip" --header="Host: $domain" "${spoofed[@]}" "http://edge/")
expect "interface answers over plain HTTP" "$out" 'HTTP/1.1 200'
refute "interface does not redirect to HTTPS" "$out" 'Location: https'
expect "interface reaches the web app" "$out" '^web '
expect "trusted proxy's scheme is passed through" "$out" 'proto=https'
expect "trusted proxy's client address is passed through" "$out" 'for=203.0.113.9'
expect "strict transport security still reaches the browser" "$out" 'Strict-Transport-Security'

out=$(request "$stranger_ip" --header="Host: $domain" "${spoofed[@]}" "http://edge/")
expect "stranger's spoofed scheme is discarded" "$out" 'proto=http '
expect "stranger's spoofed address is replaced with its own" "$out" "for=$stranger_ip"
refute "stranger's spoofed address does not survive" "$out" '203.0.113.9'

out=$(request "$proxy_ip" --header="Host: $domain" "http://edge/api/links")
expect "reserved paths reach the api" "$out" '^api '

out=$(request "$proxy_ip" --header="Host: go.example.test" "http://edge/abc1234")
expect "another host is a short domain and reaches the api" "$out" '^api '
refute "a short domain never reaches the web app" "$out" '^web '

if (( failures > 0 )); then
    printf '\n%d proxied-edge problem(s).\n' "$failures" >&2
    exit 1
fi

printf 'The proxied edge serves plain HTTP, trusts only the proxy in front, and routes by host.\n'
