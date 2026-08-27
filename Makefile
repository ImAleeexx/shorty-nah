# Shorty-Nah task entrypoint.
# Every target runs through Docker Compose so host PHP and Node versions never matter.

SHELL := /bin/bash
.DEFAULT_GOAL := help

COMPOSE      := docker compose -f compose.yaml -f compose.dev.yaml
COMPOSE_PROD := docker compose -f compose.yaml
API          := $(COMPOSE) exec api
API_RUN      := $(COMPOSE) run --rm --no-deps api
WEB          := $(COMPOSE) exec web

.PHONY: help up down restart logs ps setup build sh tinker migrate fresh ch-migrate setup-token token-dir bootstrap-app-role \
        test test-api test-web lint lint-api lint-web format analyse typecheck e2e ci install check-pins lint-syntax e2e-fixture \
        queue-status backup restore e2e-setup e2e-setup-fixture check-secrets check-ports verify-schema verify-audit verify-shutdown verify-restore verify-clean-host scan scan-dependencies scan-secrets scan-images

help: ## List available targets
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[1m%-14s\033[0m %s\n", $$1, $$2}'

## --- Stack ---

up: token-dir ## Start the stack with the dev override applied
	$(COMPOSE) up -d

# The setup token is written by the api container's own user, whose uid does not
# match the host's, so the bind mount has to be writable by it. Sticky, not
# plain 0777: another local account can create files here but cannot unlink or
# replace the token, and whoever holds that token owns the instance.
token-dir:
	@mkdir -p run && chmod 1777 run

down: ## Stop the stack and remove its containers
	$(COMPOSE) down

restart: ## Restart every service
	$(COMPOSE) restart

logs: ## Tail logs for all services
	$(COMPOSE) logs -f --tail=100

ps: ## Show service state and health
	$(COMPOSE) ps

build: ## Rebuild the application images
	$(COMPOSE) build

setup: token-dir ## First run: build, start, apply both schemas, seed
	$(COMPOSE) build
	$(COMPOSE) up -d
	$(MAKE) migrate
	$(MAKE) ch-migrate
	$(API) php artisan db:seed

## --- Shells ---

sh: ## Shell into the api container
	$(API) bash

setup-token: ## Show the first-boot setup token
	$(API) php artisan shortynah:setup-token

# The Postgres init script only runs on an empty data directory, so an instance
# that predates the two-role split has no application role and nothing starts.
bootstrap-app-role: ## Create the application's database role on an existing volume
	./scripts/bootstrap-app-role.sh

tinker: ## Open a Laravel REPL
	$(API) php artisan tinker

queue-status: ## Show Horizon queue state
	$(API) php artisan horizon:status

## --- Databases ---

# Applied as the owning role. The application's role cannot alter the audit
# table, which includes not being able to create it.
migrate: ## Apply Postgres migrations
	$(API) php artisan migrate --database=pgsql_owner --force

fresh: ## Drop, migrate and seed Postgres (destructive)
	$(API) php artisan migrate:fresh --database=pgsql_owner --seed

ch-migrate: ## Apply the ClickHouse event schema
	$(API) php artisan clickhouse:migrate

backup: ## Back up application data, event data and uploaded assets (encrypted)
	./scripts/backup.sh $(DIR)

restore: ## Restore a backup directory onto this instance
	./scripts/restore.sh $(DIR)

## --- Local tooling ---

install: ## Install host-side dependencies for both apps
	cd apps/api && composer install
	cd apps/web && pnpm install --frozen-lockfile --ignore-scripts

## --- Quality ---

test: test-api test-web ## Run every test suite

test-api: ## Run the Pest suite
	$(API_RUN) ./vendor/bin/pest

test-web: ## Run the Vitest suite
	$(WEB) pnpm test --run

e2e-fixture: ## Seed the fixture the browser suite drives
	$(API) php artisan shortynah:e2e-fixture
	# The redirect is rate limited per address, so a corpus large enough to
	# exercise the virtualized drill-down cannot be produced by driving it.
	$(API) php artisan shortynah:e2e-clicks --count=3000

e2e-setup-fixture: ## Return the instance to first boot (destructive, dev only)
	$(API) php artisan shortynah:e2e-setup-reset

e2e-setup: e2e-setup-fixture ## Walk the setup wizard in a browser from first boot
	cd apps/web && pnpm exec playwright test --grep @firstboot

# Ordered, because the wizard suite needs an uninstalled instance and everything
# else needs an installed one. The wizard leaves it installed, then the fixture
# seeds the domain and links the rest of the suite drives.
e2e: e2e-setup ## Run the whole browser suite, wizard first
	$(MAKE) e2e-fixture
	cd apps/web && pnpm exec playwright test --grep-invert "@firstboot|@security"
	# Last, alone, and single-worker: it turns on the instance-wide second-factor
	# requirement, and every other spec signs in as the same operator. Running it
	# alongside them locks them out mid-test.
	cd apps/web && pnpm exec playwright test --grep @security --workers=1

lint: lint-api lint-web ## Run every linter

lint-syntax: ## Verify every PHP file compiles
	./scripts/lint-php-syntax.sh

lint-api: lint-syntax ## Check PHP syntax and formatting
	$(API_RUN) ./vendor/bin/pint --test

lint-web: ## Lint the web app and its stylesheets
	$(WEB) pnpm lint
	$(WEB) pnpm lint:css
	# CI checks formatting and the local gate did not, so a run could be green
	# here and fail there on nothing but whitespace. A gate that does not match
	# the one it stands in for is not a gate.
	$(WEB) pnpm format:check

format: ## Apply PHP and web formatting
	$(API_RUN) ./vendor/bin/pint
	$(WEB) pnpm format

analyse: ## Run Larastan static analysis
	$(API_RUN) ./vendor/bin/phpstan analyse

typecheck: ## Typecheck the web app
	$(WEB) pnpm typecheck

check-pins: ## Verify every base image is digest-pinned
	./scripts/check-image-pins.sh

check-secrets: ## Verify no instance credentials are baked into an image
	./scripts/check-image-secrets.sh

check-ports: ## Verify only the edge publishes a port in production
	./scripts/check-published-ports.sh

verify-schema: ## Verify schema is applied before anything serves traffic
	./scripts/verify-schema-ordering.sh

verify-audit: ## Verify the audit log cannot be rewritten by the application
	./scripts/verify-audit-immutability.sh

## --- Supply chain (slow; run before a release, and in CI) ---

scan: scan-dependencies scan-secrets scan-images ## Run every supply-chain scan

scan-dependencies: ## Fail on a high or critical dependency advisory
	./scripts/scan-dependencies.sh

scan-secrets: ## Fail on a credential-shaped string in history or the tree
	./scripts/scan-secrets.sh

scan-images: ## Fail on a fixable high or critical image vulnerability
	./scripts/scan-images.sh

## --- Operations checks (need a running stack) ---

verify-shutdown: ## Verify a worker finishes or requeues its job on termination
	./scripts/verify-graceful-shutdown.sh

verify-restore: ## Destroy this instance and prove the backup restores it
	./scripts/verify-restore.sh

verify-clean-host: ## Destroy everything and prove one command reaches the wizard
	./scripts/verify-clean-host.sh

ci: lint analyse typecheck test check-pins check-secrets check-ports verify-schema verify-audit ## Run the full quality gate

bench: ## Measure the redirect hot path against the recorded baseline
	$(API) php artisan shortynah:bench-redirect --iterations=2000 --warmup=400 \
		--compare=storage/bench/redirect-baseline.json --budget-us=150

bench-record: ## Record a new redirect baseline (do this before changing the hot path)
	$(API) php artisan shortynah:bench-redirect --iterations=2000 --warmup=400 \
		--record=storage/bench/redirect-baseline.json
