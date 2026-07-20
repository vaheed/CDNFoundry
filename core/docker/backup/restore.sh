#!/bin/sh
set -eu
snapshot_id="${1:-}"
case "$snapshot_id" in *[!a-f0-9]*|'') exit 64 ;; esac
restic dump "$snapshot_id" control.pgdump | pg_restore --clean --if-exists --no-owner --no-privileges --exit-on-error --dbname="$PGDATABASE"
