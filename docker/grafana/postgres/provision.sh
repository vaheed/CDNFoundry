#!/bin/sh
set -eu

: "${GRAFANA_POSTGRES_PASSWORD:?GRAFANA_POSTGRES_PASSWORD is required}"
exec psql --set=grafana_password="$GRAFANA_POSTGRES_PASSWORD" --file=/provision/provision.sql
