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

# Uploaded branding assets live in storage/app/public and are referenced as
# /storage/..., which resolves only through this link.
#
# The image already carries it, and in development that is not enough: the
# compose override bind-mounts the host tree over /app, which replaces public/
# with a copy that has never had the link. Recreated here so both cases are the
# same, and idempotent so it costs nothing on the path that already had it.
link_public_storage() {
    # Already there in the built image, and public/ is root-owned by then, so
    # attempting it again fails with a permission error and — under `set -e` —
    # takes the container down. Only the development bind mount arrives without
    # it.
    [ -e public/storage ] && return 0

    mkdir -p storage/app/public 2>/dev/null || true

    ln -sfn ../storage/app/public public/storage 2>/dev/null || {
        echo "warning: public/storage could not be created; uploaded branding assets will not be served" >&2
        return 0
    }
}

verify_environment() {
    php artisan shortynah:verify-env
}

# The claim gate. Tolerates failure because it needs the settings table, and a
# process must still come up on a host whose schema has not been applied yet —
# the schema step below emits the token as soon as it can.
announce_setup_token() {
    php artisan shortynah:setup-token || true
}

case "${1:-octane}" in
    octane)
        link_public_storage
        verify_environment
        warm_caches
        announce_setup_token
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
        link_public_storage
        verify_environment
        warm_caches
        exec php artisan horizon
        ;;

    clicks)
        link_public_storage
        verify_environment
        warm_caches
        # A dedicated drain loop rather than a queued job per click: the event
        # store wants batches, and a job per click would defeat that.
        exec php artisan shortynah:drain-clicks --daemon --batch=1000 --sleep=1
        ;;

    scheduler)
        link_public_storage
        verify_environment
        warm_caches
        exec php artisan schedule:work
        ;;

    schema)
        verify_environment
        # Applied as the owning role, not the application's. The application's
        # role deliberately cannot alter the audit table, which includes not
        # being able to create it.
        php artisan migrate --database=pgsql_owner --force
        php artisan clickhouse:migrate
        php artisan shortynah:setup-token
        ;;

    *)
        exec "$@"
        ;;
esac
