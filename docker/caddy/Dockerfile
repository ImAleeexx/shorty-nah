# Caddy with a DNS provider compiled in.
#
# The stock image carries no DNS modules, and the ACME HTTP-01 and TLS-ALPN-01
# challenges both need the instance reachable from the public internet on 80 or
# 443. A single-host deployment behind a router usually is not, so certificates
# are obtained through DNS-01 instead: the authority is satisfied by a TXT
# record in the zone, and nothing has to be exposed.
FROM caddy:2-builder-alpine@sha256:7bac9be4072f7c4db2ccc7350750e0705004bf02da2ac7d96b1469ca4f28bb7c AS builder

RUN xcaddy build \
    --with github.com/caddy-dns/cloudflare

FROM caddy:2-alpine@sha256:5f5c8640aae01df9654968d946d8f1a56c497f1dd5c5cda4cf95ab7c14d58648

COPY --from=builder /usr/bin/caddy /usr/bin/caddy
