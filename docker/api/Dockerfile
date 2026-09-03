# syntax=docker/dockerfile:1

# ---------- Composer dependencies ----------
FROM dunglas/frankenphp:1-php8.4@sha256:77bc2d40a58ace3a9425e4cbb0c40d044188dfd850e44a943ed043d743625df3 AS vendor

# The vendor stage needs the extensions the lock file's platform requirements
# name, or composer refuses the install. Horizon asks for pcntl and posix.
RUN install-php-extensions @composer intl zip pcntl posix

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
FROM dunglas/frankenphp:1-php8.4@sha256:77bc2d40a58ace3a9425e4cbb0c40d044188dfd850e44a943ed043d743625df3 AS runtime

# pcntl is required by Octane, maxminddb makes in-process geo lookups cheap
# enough to run inside the enrichment job.
RUN install-php-extensions \
        bcmath \
        gd \
        intl \
        maxminddb \
        opcache \
        pcntl \
        pdo_pgsql \
        redis \
        sockets \
        zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends curl procps \
    && rm -rf /var/lib/apt/lists/*

COPY docker/api/php.ini /usr/local/etc/php/conf.d/shortynah.ini

WORKDIR /app

ENV OCTANE_SERVER=frankenphp \
    APP_ENV=production \
    APP_DEBUG=false \
    XDG_DATA_HOME=/var/lib/shortynah \
    XDG_CONFIG_HOME=/var/lib/shortynah/config

COPY --from=vendor /app ./

RUN php artisan package:discover --ansi

COPY docker/api/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# The edge terminates TLS, so this process binds an unprivileged port and never
# needs root.
RUN useradd --uid 10001 --create-home --shell /usr/sbin/nologin shortynah \
    && mkdir -p /var/lib/shortynah/config \
    && chown -R shortynah:shortynah /var/lib/shortynah \
    && mkdir -p storage/framework/cache/data \
                storage/framework/sessions \
                storage/framework/views \
                storage/logs \
                bootstrap/cache \
    && chown -R shortynah:shortynah storage bootstrap/cache \
    && chmod -R u+rwX storage bootstrap/cache \
    # Uploaded branding assets are written to storage/app/public and referenced
    # as /storage/..., which resolves only through this link. Made here rather
    # than by `artisan storage:link` at boot because storage/app is a volume and
    # public/ is not: the link lives in the image, so every container has it and
    # no start-up step can forget it. Relative, so it survives the path changing.
    && ln -sfn ../storage/app/public public/storage \
    && mkdir -p storage/app/public \
    && chown -R shortynah:shortynah storage/app

USER shortynah

EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=4 \
    CMD curl -fsS http://127.0.0.1:8000/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["octane"]

# ---------- Development ----------
# Octane's --watch spawns a Node file watcher. Node and chokidar live only in
# this stage so the production image carries neither.
FROM runtime AS development

USER root

RUN apt-get update \
    && apt-get install -y --no-install-recommends nodejs npm \
    && rm -rf /var/lib/apt/lists/* \
    && npm install -g chokidar@4 \
    && npm cache clean --force

# Octane's watch script resolves chokidar from NODE_PATH, which keeps it out of
# the bind-mounted application tree.
ENV NODE_PATH=/usr/local/lib/node_modules

USER shortynah
