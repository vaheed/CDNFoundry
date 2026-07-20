#!/bin/sh
set -eu
pg_dump --format=custom --no-owner --no-privileges | restic backup --stdin --stdin-filename control.pgdump --tag cdnfoundry-control --json
