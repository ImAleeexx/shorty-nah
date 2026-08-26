# syntax=docker/dockerfile:1

# ---------- Dependencies ----------
FROM node:24-bookworm-slim@sha256:a9f5f7c91a432850b2a8a7797adf5eadb6c733ceed61167806cee7ea7fbc29df AS deps

RUN corepack enable

WORKDIR /app

COPY apps/web/package.json apps/web/pnpm-lock.yaml ./

# --ignore-scripts is deliberate: no dependency's install script runs during our
# build. Nothing here needs one — the only package that asks is eslint's native
# resolver, which falls back to its prebuilt binary and lints fine without it.
RUN --mount=type=cache,id=pnpm-store,target=/pnpm-store \
    pnpm config set store-dir /pnpm-store \
    && pnpm install --frozen-lockfile --ignore-scripts

# ---------- Build ----------
FROM node:24-bookworm-slim@sha256:a9f5f7c91a432850b2a8a7797adf5eadb6c733ceed61167806cee7ea7fbc29df AS build

RUN corepack enable

WORKDIR /app

COPY --from=deps /app/node_modules ./node_modules
COPY apps/web ./

ENV NEXT_TELEMETRY_DISABLED=1

RUN pnpm build

# ---------- Runtime ----------
FROM node:24-bookworm-slim@sha256:a9f5f7c91a432850b2a8a7797adf5eadb6c733ceed61167806cee7ea7fbc29df AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0

# The standalone bundle carries its own traced node_modules, so neither pnpm nor
# the source tree is present at runtime.
COPY --from=build /app/.next/standalone ./
COPY --from=build /app/.next/static ./.next/static
COPY --from=build /app/public ./public

RUN groupadd --gid 10002 nodejs \
    && useradd --uid 10002 --gid nodejs --create-home --shell /usr/sbin/nologin nextjs \
    && chown -R nextjs:nodejs /app

USER nextjs

EXPOSE 3000

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=4 \
    CMD curl -fsS http://127.0.0.1:3000/ || exit 1

CMD ["node", "server.js"]
