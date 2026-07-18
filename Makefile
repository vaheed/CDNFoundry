COMPOSE_DEV := docker compose -f compose.dev.yml
COMPOSE_PROD := docker compose --env-file .env.prod -f compose.prod.yml
COMPOSE_PROD_EXAMPLE := docker compose --env-file .env.prod.example -f compose.prod.yml

.PHONY: dev-up dev-down dev-migrate dev-pdns-migrate dev-test dev-e2e dev-scale-e2e dev-logs prod-pull prod-migrate prod-pdns-migrate prod-control prod-dns prod-telemetry prod-edge config-check openapi-check

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
	python3 tests/e2e/phase2_dns.py
	python3 tests/e2e/phase3_geo_dns.py
	python3 tests/e2e/phase4_control_plane.py
	python3 tests/e2e/phase4_mtls.py
	python3 tests/e2e/phase4_runtime.py

dev-scale-e2e:
	python3 tests/e2e/phase2_scale.py

dev-logs:
	$(COMPOSE_DEV) logs -f --tail=200

prod-pull:
	$(COMPOSE_PROD) pull

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
	$(COMPOSE_PROD_EXAMPLE) config --quiet

openapi-check:
	$(COMPOSE_DEV) run --rm core php artisan api:openapi --check
