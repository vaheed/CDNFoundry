#!/bin/sh
# Restores the usage rollups into ClickHouse from the newest complete rollup snapshot. A snapshot is complete
# when its export ends with the completion line. The target tables must be empty: they sum duplicate keys,
# so loading over existing rows would double the totals.
# CLICKHOUSE_RESTORE_USER and CLICKHOUSE_RESTORE_PASSWORD name an account allowed to insert (the ingest account).
set -eu
requested="${1:-latest}"
case "$requested" in latest) ;; *[!a-f0-9]*|'') exit 64 ;; esac
url="${CLICKHOUSE_URL:?CLICKHOUSE_URL is required}"
user="${CLICKHOUSE_RESTORE_USER:?CLICKHOUSE_RESTORE_USER is required}"
password="${CLICKHOUSE_RESTORE_PASSWORD:?CLICKHOUSE_RESTORE_PASSWORD is required}"
query() { curl --silent --show-error --fail --max-time 3000 --user "$user:$password" "$@"; }
complete() { [ "$(restic dump "$1" rollups.jsonl 2>/dev/null | tail -n 1)" = '-- complete' ]; }

snapshot_id=''
if [ "$requested" = latest ]; then
    # `restic snapshots` lists oldest first; walk it newest first.
    for candidate in $(restic snapshots --tag cdnfoundry-rollups --json | grep -o '"short_id":"[a-f0-9]*"' | sed 's/.*:"\(.*\)"/\1/' | sed '1!G;h;$!d'); do
        if complete "$candidate"; then snapshot_id="$candidate"; break; fi
        echo "snapshot $candidate is incomplete; skipping it" >&2
    done
    [ -n "$snapshot_id" ] || { echo "no complete rollup snapshot exists" >&2; exit 1; }
else
    complete "$requested" || { echo "snapshot $requested is not a complete rollup snapshot" >&2; exit 1; }
    snapshot_id="$requested"
fi
for table in edge_hourly dns_hourly edge_daily dns_daily; do
    rows="$(query --get --data-urlencode "query=SELECT count() FROM cdnf.${table}" "$url/")"
    [ "$rows" = 0 ] || { echo "cdnf.${table} already holds $rows rows; refusing to load over it" >&2; exit 1; }
done
# One streaming insert per table. The account stays out of the process list: curl reads it from the environment.
export CH_USER="$user" CH_PASSWORD="$password" CH_URL="$url"
counts="$(mktemp)"
restic dump "$snapshot_id" rollups.jsonl | awk -v counts="$counts" '
function finish() { if (command != "") { close(command); print table, rows > counts; command = "" } }
/^-- table: / {
    finish(); table = substr($0, 11); rows = 0
    if (table !~ /^(edge_hourly|dns_hourly|edge_daily|dns_daily)$/) { exit 2 }
    command = "curl --silent --show-error --fail --max-time 3000 --user \"$CH_USER:$CH_PASSWORD\" --data-binary @- \"$CH_URL/?query=INSERT%20INTO%20cdnf." table "%20FORMAT%20JSONEachRow\""
    next
}
/^-- complete$/ || $0 == "" { next }
command != "" { print $0 | command; rows++ }
END { finish() }'
close_counts="$(cat "$counts")"
rm -f "$counts"
# A table that was streamed rows must now hold rows. (Background merges may combine duplicate keys, so an exact
# count would not be stable.)
printf '%s\n' "$close_counts" | while read -r name expected; do
    actual="$(query --get --data-urlencode "query=SELECT count() FROM cdnf.${name}" "$url/")"
    [ "${expected:-0}" -eq 0 ] || [ "$actual" -gt 0 ] || { echo "cdnf.${name} is still empty after $expected rows were streamed into it" >&2; exit 1; }
done
echo "usage rollups restored from $snapshot_id"
