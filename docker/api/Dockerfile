# syntax=docker/dockerfile:1

# ---------- Composer dependencies ----------
FROM dunglas/frankenphp:1-php8.4 AS vendor

RUN install-php-extensions @composer intl zip

WORKDIR /app

# Dependencies resolve from the lock file alone, so this layer is reused on any
# change that does not touch it.
COPY apps/api/composer.json apps/api/composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY apps/api ./

# Scripts stay off: the post-autoload-dump hook runs package:discover, which
# belongs in the runtime stage where every extension is present.
RUN composer dump-autoload \
        --no-dev \
        --optimize \
        --classmap-authoritative \
        --no-scripts

# ---------- Runtime ----------
FROM dunglas/frankenphp:1-php8.4 AS runtime

# pcntl is required by Octane, maxminddb makes in-process geo lookups cheap
# enough to run inside the enrichment job.
RUN install-php-extensions \
        bcmath \
        intl \
        maxminddb \
        opcache \
        pcntl \
        pdo_pgsql \
        redis \
        sockets \
        zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

COPY docker/api/php.ini /usr/local/etc/php/conf.d/shortynah.ini

WORKDIR /app

ENV OCTANE_SERVER=frankenphp \
    APP_ENV=production \
    APP_DEBUG=false

COPY --from=vendor /app ./

RUN php artisan package:discover --ansi

COPY docker/api/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# The edge terminates TLS, so this process binds an unprivileged port and never
# needs root.
RUN useradd --uid 10001 --create-home --shell /usr/sbin/nologin shortynah \
    && mkdir -p storage/framework/cache/data \
                storage/framework/sessions \
                storage/framework/views \
                storage/logs \
                bootstrap/cache \
    && chown -R shortynah:shortynah storage bootstrap/cache \
    && chmod -R u+rwX storage bootstrap/cache

USER shortynah

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=4 \
    CMD curl -fsS http://127.0.0.1:8000/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["octane"]
