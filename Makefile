# Shorty-Nah task entrypoint.
# Every target runs through Docker Compose so host PHP and Node versions never matter.

SHELL := /bin/bash
.DEFAULT_GOAL := help

COMPOSE      := docker compose -f compose.yaml -f compose.dev.yaml
COMPOSE_PROD := docker compose -f compose.yaml
API          := $(COMPOSE) exec api
API_RUN      := $(COMPOSE) run --rm --no-deps api
WEB          := $(COMPOSE) exec web

.PHONY: help up down restart logs ps setup build sh tinker migrate fresh ch-migrate \
        test test-api test-web lint lint-api lint-web format analyse typecheck e2e ci install check-pins lint-syntax e2e-fixture \
        queue-status backup

help: ## List available targets
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[1m%-14s\033[0m %s\n", $$1, $$2}'

## --- Stack ---

up: ## Start the stack with the dev override applied
	$(COMPOSE) up -d

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

setup: ## First run: build, start, apply both schemas, seed
	$(COMPOSE) build
	$(COMPOSE) up -d
	$(MAKE) migrate
	$(MAKE) ch-migrate
	$(API) php artisan db:seed

## --- Shells ---

sh: ## Shell into the api container
	$(API) bash

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

e2e: e2e-fixture ## Seed the fixture and run the Playwright suite
	cd apps/web && pnpm exec playwright test

lint: lint-api lint-web ## Run every linter

lint-syntax: ## Verify every PHP file compiles
	./scripts/lint-php-syntax.sh

lint-api: lint-syntax ## Check PHP syntax and formatting
	$(API_RUN) ./vendor/bin/pint --test

lint-web: ## Lint the web app
	$(WEB) pnpm lint

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
