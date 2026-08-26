# Shorty-Nah task entrypoint.
# Every target runs through Docker Compose so host PHP and Node versions never matter.

SHELL := /bin/bash
.DEFAULT_GOAL := help

COMPOSE      := docker compose -f compose.yaml -f compose.dev.yaml
COMPOSE_PROD := docker compose -f compose.yaml
API          := $(COMPOSE) exec api
API_RUN      := $(COMPOSE) run --rm --no-deps api
WEB          := $(COMPOSE) exec web

.PHONY: help up down restart logs ps setup build sh tinker migrate fresh ch-migrate setup-token token-dir \
        test test-api test-web lint lint-api lint-web format analyse typecheck e2e ci install check-pins lint-syntax e2e-fixture \
        queue-status backup e2e-setup e2e-setup-fixture

help: ## List available targets
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[1m%-14s\033[0m %s\n", $$1, $$2}'

## --- Stack ---

up: token-dir ## Start the stack with the dev override applied
	$(COMPOSE) up -d

# The setup token is written by the api container's own user, so the bind mount
# has to be writable by it. The token file itself is created 0600.
token-dir:
	@mkdir -p run && chmod 0777 run

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

tinker: ## Open a Laravel REPL
	$(API) php artisan tinker

queue-status: ## Show Horizon queue state
	$(API) php artisan horizon:status

## --- Databases ---

migrate: ## Apply Postgres migrations
	$(API) php artisan migrate --force

fresh: ## Drop, migrate and seed Postgres (destructive)
	$(API) php artisan migrate:fresh --seed

ch-migrate: ## Apply the ClickHouse event schema
	$(API) php artisan clickhouse:migrate

backup: ## Back up application data, event data and uploaded assets
	$(API) php artisan shortynah:backup

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
	cd apps/web && pnpm exec playwright test --grep-invert @firstboot

lint: lint-api lint-web ## Run every linter

lint-syntax: ## Verify every PHP file compiles
	./scripts/lint-php-syntax.sh

lint-api: lint-syntax ## Check PHP syntax and formatting
	$(API_RUN) ./vendor/bin/pint --test

lint-web: ## Lint the web app and its stylesheets
	$(WEB) pnpm lint
	$(WEB) pnpm lint:css

format: ## Apply PHP and web formatting
	$(API_RUN) ./vendor/bin/pint
	$(WEB) pnpm format

analyse: ## Run Larastan static analysis
	$(API_RUN) ./vendor/bin/phpstan analyse

typecheck: ## Typecheck the web app
	$(WEB) pnpm typecheck

check-pins: ## Verify every base image is digest-pinned
	./scripts/check-image-pins.sh

ci: lint analyse typecheck test check-pins ## Run the full quality gate
