COMPOSE_DEV := docker compose -f compose.dev.yml
COMPOSE_PROD := docker compose --env-file .env.prod -f compose.prod.yml

.PHONY: dev-up dev-down dev-migrate dev-test dev-logs prod-build prod-migrate prod-control prod-dns prod-telemetry prod-edge config-check

dev-up:
	$(COMPOSE_DEV) up -d --build

dev-down:
	$(COMPOSE_DEV) down

dev-migrate:
	$(COMPOSE_DEV) --profile tools run --rm migrate

dev-test:
	$(COMPOSE_DEV) run --rm core php artisan test

dev-logs:
	$(COMPOSE_DEV) logs -f --tail=200

prod-build:
	$(COMPOSE_PROD) build core

prod-migrate:
	$(COMPOSE_PROD) --profile tools run --rm migrate

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

