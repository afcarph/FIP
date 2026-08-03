# =============================================================================
#  Fuel Intelligence Platform
#  Run `make` with no target for the list.
# =============================================================================

COMPOSE := docker compose -f infra/docker-compose.yml --env-file .env
API     := $(COMPOSE) exec -T api
AI      := $(COMPOSE) exec -T ai-service
WEB     := $(COMPOSE) exec -T web

.DEFAULT_GOAL := help
.PHONY: help up down restart build logs ps bootstrap migrate fresh seed \
        shell tinker test test-backend test-ai test-frontend lint fix \
        analyse docs key backup restore clean prod-build prod-up

## ----------------------------------------------------------------- help ---

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

## ------------------------------------------------------------ lifecycle ---

up: ## Build and start the whole stack
	@test -f .env || (echo "Copy .env.example to .env first." && exit 1)
	$(COMPOSE) up -d --build
	@echo ""
	@echo "  Web        http://localhost:3000"
	@echo "  API        http://localhost:8000/api/v1"
	@echo "  API docs   http://localhost:8000/api/documentation"
	@echo "  AI service http://localhost:8001/docs"
	@echo "  Mail       http://localhost:8025"
	@echo "  MinIO      http://localhost:9001"
	@echo ""
	@echo "  Next: make bootstrap"

down: ## Stop and remove containers (volumes survive)
	$(COMPOSE) down

restart: ## Restart every service
	$(COMPOSE) restart

build: ## Rebuild images without starting
	$(COMPOSE) build

logs: ## Tail logs — make logs s=api
	$(COMPOSE) logs -f --tail=120 $(s)

ps: ## Show service status
	$(COMPOSE) ps

## ---------------------------------------------------------- bootstrapping ---

bootstrap: key migrate seed ## Prepare a fresh installation end to end
	@echo ""
	@echo "  Ready. Sign in at http://localhost:3000/login"
	@echo "  superadmin@fip.ph / Password123!"

key: ## Generate the application and JWT keys into the root .env
# The keys have to land in the root .env, because that is the file compose
# feeds to every PHP service as real environment variables -- and a real
# environment variable always beats a value in backend/.env. `artisan
# key:generate` writes to backend/.env, which the running containers therefore
# ignore, so generating them there looks like it worked and changes nothing.
	@test -f .env || { echo "No .env at the repository root. Run: cp .env.example .env"; exit 1; }
	@APP_KEY="base64:$$($(API) php -r 'echo base64_encode(random_bytes(32));' | tr -d '\r')"; \
	 JWT_SECRET="$$($(API) php -r 'echo bin2hex(random_bytes(32));' | tr -d '\r')"; \
	 sed -i.bak -e "s|^APP_KEY=.*|APP_KEY=$$APP_KEY|" -e "s|^JWT_SECRET=.*|JWT_SECRET=$$JWT_SECRET|" .env; \
	 rm -f .env.bak; \
	 echo "  APP_KEY and JWT_SECRET written to .env"
# The services read the file once, at start, so they need replacing to see it.
	@$(COMPOSE) up -d --force-recreate api queue scheduler >/dev/null 2>&1
	@echo "  api, queue and scheduler restarted with the new keys"

migrate: ## Run pending migrations
	$(API) php artisan migrate --force

fresh: ## Drop everything and rebuild the schema with demo data
	$(API) php artisan migrate:fresh --seed --force

seed: ## Seed reference and demo data
	$(API) php artisan db:seed --force

## ------------------------------------------------------------------ dev ---

shell: ## Shell into the API container
	$(COMPOSE) exec api bash

tinker: ## Laravel REPL
	$(COMPOSE) exec api php artisan tinker

docs: ## Regenerate the OpenAPI specification
	$(API) php artisan l5-swagger:generate
	@echo "  http://localhost:8000/api/documentation"

## ---------------------------------------------------------------- tests ---

test: test-backend test-ai test-frontend ## Run every test suite

test-backend: ## PHPUnit
	$(API) php artisan test --parallel

test-ai: ## pytest
	$(AI) python -m pytest -q

test-frontend: ## vitest
	$(WEB) npm run test

## --------------------------------------------------------------- quality ---

lint: ## Check formatting and lint rules everywhere
	$(API) ./vendor/bin/pint --test
	$(AI) ruff check app
	$(WEB) npm run lint

fix: ## Apply automatic formatting fixes
	$(API) ./vendor/bin/pint
	$(AI) ruff check --fix app
	$(WEB) npm run format

analyse: ## Static analysis
	$(API) ./vendor/bin/phpstan analyse --memory-limit=1G
	$(AI) mypy app
	$(WEB) npm run typecheck

## ------------------------------------------------------------- operations ---

backup: ## Dump the database to backups/
	@mkdir -p backups
	$(COMPOSE) exec -T mysql sh -c 'exec mysqldump -u root -p"$$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers fip' \
		| gzip > backups/fip-$$(date +%Y%m%d-%H%M%S).sql.gz
	@echo "  Written to backups/"

restore: ## Restore a dump — make restore f=backups/fip-....sql.gz
	@test -n "$(f)" || (echo "Usage: make restore f=backups/fip-....sql.gz" && exit 1)
	gunzip -c $(f) | $(COMPOSE) exec -T mysql sh -c 'exec mysql -u root -p"$$MYSQL_ROOT_PASSWORD" fip'
	@echo "  Restored from $(f)"

clean: ## Remove containers AND volumes — destroys all local data
	@printf "This deletes every local volume. Type 'yes' to continue: " && read answer && [ "$$answer" = "yes" ]
	$(COMPOSE) down -v --remove-orphans

## ------------------------------------------------------------- production ---

prod-build: ## Build the production images
	docker build -t fip/api:latest -f infra/docker/backend.Dockerfile --target production backend
	docker build -t fip/ai:latest -f infra/docker/ai.Dockerfile ai-service
	docker build -t fip/web:latest -f infra/docker/frontend.Dockerfile --target production \
		--build-arg NEXT_PUBLIC_API_URL=$$NEXT_PUBLIC_API_URL \
		--build-arg NEXT_PUBLIC_GOOGLE_MAPS_API_KEY=$$NEXT_PUBLIC_GOOGLE_MAPS_API_KEY \
		frontend

prod-up: ## Start the production stack
	docker compose -f infra/docker-compose.yml -f infra/docker-compose.prod.yml --env-file .env up -d
