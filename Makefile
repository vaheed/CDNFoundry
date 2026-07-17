COMPOSE_DEV := docker compose -f compose.dev.yml
COMPOSE_PROD := docker compose --env-file .env.prod -f compose.prod.yml

.PHONY: dev-up dev-down dev-migrate dev-pdns-migrate dev-test dev-e2e dev-logs prod-build prod-migrate prod-pdns-migrate prod-control prod-dns prod-telemetry prod-edge config-check openapi-check

dev-up:
	$(COMPOSE_DEV) --profile devtools up -d --build

dev-down:
	$(COMPOSE_DEV) down

dev-migrate:
	$(COMPOSE_DEV) --profile tools run --rm migrate

dev-pdns-migrate:
	$(COMPOSE_DEV) --profile tools run --rm pdns-migrate

dev-test:
	$(COMPOSE_DEV) run --rm -e APP_ENV=testing -e APP_CONFIG_CACHE=/tmp/cdnfoundry-test-config.php -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array -e QUEUE_CONNECTION=sync core php artisan test

dev-e2e:
	python3 tests/e2e/e2e.py

dev-logs:
	$(COMPOSE_DEV) logs -f --tail=200

prod-build:
	$(COMPOSE_PROD) build core
	$(COMPOSE_PROD) build edge-agent

prod-migrate:
	$(COMPOSE_PROD) --profile tools run --rm migrate

prod-pdns-migrate:
	$(COMPOSE_PROD) --profile tools run --rm pdns-migrate

prod-control:
	$(COMPOSE_PROD) --profile control up -d

prod-dns:
	$(COMPOSE_PROD) --profile dns up -d

prod-telemetry:
	$(COMPOSE_PROD) --profile telemetry up -d

prod-edge:
	$(COMPOSE_PROD) --profile edge up -d

config-check:
	$(COMPOSE_DEV) config --quiet
	$(COMPOSE_PROD) config --quiet

openapi-check:
	$(COMPOSE_DEV) run --rm core php artisan api:openapi --check
