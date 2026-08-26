#!/bin/sh
set -eu

# Configuration is cached here rather than at build time: baking it into the
# image would capture build-time values and embed instance secrets in a layer.
warm_caches() {
    php artisan config:cache
    php artisan event:cache
    php artisan view:cache
    # route:cache is intentionally absent while any closure-based route exists —
    # it fails on closures, and a caching step that sometimes fails is worse than
    # one that is not there yet. Added once routes are controller-backed.
}

verify_environment() {
    php artisan shortynah:verify-env
}

case "${1:-octane}" in
    octane)
        verify_environment
        warm_caches
        exec php artisan octane:start \
            --server=frankenphp \
            --host=0.0.0.0 \
            --port=8000
        ;;

    worker)
        verify_environment
        warm_caches
        exec php artisan horizon
        ;;

    scheduler)
        verify_environment
        warm_caches
        exec php artisan schedule:work
        ;;

    schema)
        verify_environment
        php artisan migrate --force
        php artisan clickhouse:migrate
        ;;

    *)
        exec "$@"
        ;;
esac
