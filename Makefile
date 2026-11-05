COMPOSE_DEV := docker compose -f compose.dev.yml
COMPOSE_TEST := $(COMPOSE_DEV) -f compose.test.yml
COMPOSE_PROD := docker compose --env-file .env.prod -f compose.prod.yml
COMPOSE_PROD_EXAMPLE := docker compose --env-file .env.prod.example -f compose.prod.yml

.PHONY: backend-test contract-check dev-assets dev-control-up dev-up dev-edge-up dev-edge-status dev-scale-up dev-down dev-migrate dev-clickhouse-migrate dev-pdns-migrate dev-test dev-e2e dev-waf-image dev-waf-e2e dev-gateway-e2e dev-cache-e2e dev-compression-e2e dev-origin-failover-e2e dev-analytics-e2e dev-backup-e2e dev-recovery-e2e dev-upgrade-e2e dev-throughput-e2e dev-mmdb-e2e dev-scale-e2e dev-grafana-smoke dev-observability-seed dev-grafana-browser dev-operational-logs-smoke dev-production-qualification dev-logs prod-pull prod-migrate prod-clickhouse-migrate prod-pdns-migrate prod-control prod-dns prod-telemetry prod-edge prod-logs config-check openapi-check version-check check-no-screenshots docs-dev docs-build docs-check fleet-test qualification-tools-check supply-chain-check

backend-test: dev-test

contract-check: config-check openapi-check
	python3 scripts/ci/generate_phpstan_schema.py --check
	python3 -m unittest tests/contract/test_edge_rejection_reasons.py tests/contract/test_cache_key_vectors.py tests/observability/test_grafana_contract.py tests/observability/test_metrics_contract.py tests/observability/test_grafana_bootstrap.py tests/observability/test_operational_logging_contract.py

fleet-test:
	python3 -m pytest -q tests/fleet

qualification-tools-check:
	python3 -m unittest discover -s tests/supply_chain
	python3 -m unittest discover -s tests/qualification

supply-chain-check: qualification-tools-check
	python3 scripts/supply-chain-policy.py

dev-assets:
	install -d -m 0755 ./core/public/build
	docker build --target frontend-assets-export --output type=local,dest=./core/public/build ./core

dev-control-up: dev-assets
	$(COMPOSE_DEV) up -d --build control-db redis core web

dev-up: dev-assets
	$(COMPOSE_DEV) up -d --build

dev-edge-up: dev-assets
	@test -f .env.dev || { echo 'Copy .env.dev.example to .env.dev and add the two UI edge IDs and one-time bootstrap tokens.' >&2; exit 1; }
	docker compose --env-file .env.dev -f compose.dev.yml up -d --build edge-control edge-a edge-a-quarantine edge-agent-a edge-gateway-a edge-b edge-b-quarantine edge-agent-b edge-gateway-b

dev-edge-status:
	docker compose --env-file .env.dev -f compose.dev.yml ps edge-control edge-a edge-a-quarantine edge-agent-a edge-gateway-a edge-b edge-b-quarantine edge-agent-b edge-gateway-b

dev-scale-up: dev-control-up

dev-down:
	$(COMPOSE_DEV) down

dev-migrate:
	$(COMPOSE_DEV) run --rm migrate

dev-clickhouse-migrate:
	$(COMPOSE_DEV) run --rm clickhouse-migrate

dev-pdns-migrate:
	$(COMPOSE_DEV) run --rm pdns-migrate

dev-test: dev-assets
	$(COMPOSE_DEV) run --rm --no-deps vendor-init
	$(COMPOSE_DEV) run --rm --no-deps dev-pki
	$(COMPOSE_TEST) run --rm --no-deps core php artisan test

dev-e2e: dev-waf-image
	python3 tests/e2e/e2e.py
	python3 tests/e2e/dns_authoritative.py
	python3 tests/e2e/geo_dns.py
	python3 tests/e2e/edge_distribution.py
	python3 tests/e2e/edge_control_mtls.py
	python3 tests/e2e/managed_tls.py
	python3 tests/e2e/edge_security.py
	python3 tests/e2e/telemetry_analytics.py
	python3 tests/e2e/backup_restore.py
	python3 tests/e2e/managed_waf.py
	python3 tests/e2e/origin_failover.py
	python3 tests/e2e/openresty_runtime.py

dev-waf-image:
	docker build -f docker/openresty/Dockerfile -t cdnfoundry/edge-runtime:waf-qualification .

dev-waf-e2e: dev-waf-image
	python3 tests/e2e/managed_waf.py

dev-gateway-e2e:
	docker build -t cdnfoundry/edge-gateway:qualification edge-gateway
	python3 tests/e2e/gateway_ingress.py

dev-cache-e2e:
	python3 tests/e2e/openresty_runtime.py

dev-compression-e2e:
	python3 tests/e2e/openresty_runtime.py

dev-origin-failover-e2e:
	python3 tests/e2e/origin_failover.py

dev-scale-e2e:
	python3 tests/e2e/control_plane_scale.py

dev-analytics-e2e:
	python3 tests/e2e/telemetry_analytics.py

dev-backup-e2e:
	python3 tests/e2e/backup_restore.py

dev-recovery-e2e:
	python3 tests/e2e/offhost_recovery.py

dev-upgrade-e2e:
	python3 tests/e2e/mixed_version_upgrade.py

dev-throughput-e2e:
	python3 tests/e2e/cell_throughput.py

dev-mmdb-e2e:
	python3 tests/e2e/mmdb_provider_outage.py

dev-production-qualification:
	python3 tests/e2e/production_qualification.py

dev-grafana-smoke:
	$(COMPOSE_DEV) up -d --build clickhouse prometheus grafana-control-db-provision grafana
	python3 tests/e2e/grafana_observability.py

dev-observability-seed:
	python3 scripts/observability/seed.py --scenario $(or $(SCENARIO),normal)

dev-grafana-browser:
	tests/browser/run-grafana.sh $(ARGS)

dev-operational-logs-smoke:
	python3 tests/e2e/operational_logging.py

dev-logs:
	$(COMPOSE_DEV) logs -f --tail=200

prod-pull:
	$(COMPOSE_PROD) pull

prod-migrate:
	$(COMPOSE_PROD) --profile control up -d --wait --wait-timeout 120 control-db redis
	$(COMPOSE_PROD) --profile tools run --rm migrate

prod-clickhouse-migrate:
	$(COMPOSE_PROD) --profile telemetry up -d --wait --wait-timeout 180 clickhouse
	$(COMPOSE_PROD) --profile tools run --rm clickhouse-migrate

prod-pdns-migrate:
	$(COMPOSE_PROD) --profile dns up -d --wait --wait-timeout 120 pdns-db
	$(COMPOSE_PROD) --profile tools run --rm pdns-migrate

prod-control:
	$(COMPOSE_PROD) --profile control up -d

prod-dns:
	$(COMPOSE_PROD) --profile dns up -d

prod-telemetry:
	$(COMPOSE_PROD) --profile telemetry up -d

prod-edge:
	$(COMPOSE_PROD) --profile edge up -d

prod-logs:
	$(COMPOSE_PROD) --profile logs up -d log-collector

config-check:
	$(COMPOSE_DEV) config --quiet
	$(COMPOSE_TEST) config --quiet
	$(COMPOSE_PROD_EXAMPLE) config --quiet
	bash tests/config/generate-production-env.sh
	./scripts/validate-production-overrides.sh
	$(COMPOSE_DEV) run --rm --no-deps vendor-init
	$(COMPOSE_TEST) run --rm --no-deps core php artisan test --filter=QueueTimingContractTest

version-check:
	python3 scripts/ci/version_check.py

check-no-screenshots:
	python3 scripts/ci/check_no_screenshots.py

openapi-check:
	$(COMPOSE_DEV) run --rm --no-deps vendor-init
	$(COMPOSE_TEST) run --rm --no-deps core php artisan cdnf:api:openapi --check

docs-dev:
	npm --prefix docs run dev

docs-build:
	npm --prefix docs run build

docs-check:
	python3 tests/docs/check_tokens.py
	python3 scripts/ci/generate_fleet_cli_reference.py --check
	python3 tests/docs/check_links.py
	python3 tests/docs/run_test_blocks.py
	npm --prefix docs run lint
	$(MAKE) docs-build
	python3 tests/docs/check_built_site.py
