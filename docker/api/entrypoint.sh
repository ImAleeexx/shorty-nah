#!/bin/sh
set -eu

# Configuration is cached here rather than at build time: baking it into the
# image would capture build-time values and embed instance secrets in a layer.
# Caching is skipped locally so a mounted source edit is visible on the next
# request instead of being frozen into a cache file.
warm_caches() {
    if [ "${APP_ENV:-production}" = "local" ]; then
        php artisan config:clear
        php artisan event:clear
        php artisan view:clear
        # The mounted tree may carry a package manifest generated against a
        # different dependency set than the one present here.
        php artisan package:discover --ansi
        return 0
    fi

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
        # Worker count and recycling must be passed explicitly; Octane does not
        # read them from the environment.
        # Worker count and recycling must be passed explicitly; Octane does not
        # read them from the environment.
        set -- php artisan octane:start \
            --server=frankenphp \
            --host=0.0.0.0 \
            --port=8000 \
            --workers="${OCTANE_WORKERS:-auto}" \
            --max-requests="${OCTANE_MAX_REQUESTS:-500}"

        # FrankenPHP does not recycle workers on a request count, so a mounted
        # edit only takes effect when the watcher reloads them.
        if [ "${APP_ENV:-production}" = "local" ]; then
            set -- "$@" --watch
        fi

        exec "$@"
        ;;

    worker)
        verify_environment
        warm_caches
        exec php artisan horizon
        ;;

    clicks)
        verify_environment
        warm_caches
        # A dedicated drain loop rather than a queued job per click: the event
        # store wants batches, and a job per click would defeat that.
        exec php artisan shortynah:drain-clicks --daemon --batch=1000 --sleep=1
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
