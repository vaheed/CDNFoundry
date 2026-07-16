#!/usr/bin/env bash
set -Eeuo pipefail

readonly MMDB_DIR="${MMDB_DIR:-/mmdb}"
readonly MMDB_TARGET_FILE="${MMDB_TARGET_FILE:-GeoLite2-City.mmdb}"
readonly MMDB_PROVIDER="${MMDB_PROVIDER:-dbip-jsdelivr}"
readonly MMDB_DOWNLOAD_URL="${MMDB_DOWNLOAD_URL:-}"
readonly MMDB_DOWNLOAD_HEADER="${MMDB_DOWNLOAD_HEADER:-}"
readonly MMDB_EXPECTED_SHA256="${MMDB_EXPECTED_SHA256:-}"
readonly MMDB_DOWNLOAD_RETRIES="${MMDB_DOWNLOAD_RETRIES:-5}"
MMDB_DOWNLOAD_INTERVAL_SECONDS="${MMDB_DOWNLOAD_INTERVAL_SECONDS:-86400}"

timestamp() { date -u +'%Y-%m-%dT%H:%M:%SZ'; }
log() { printf '[%s] [mmdb-updater] %s\n' "$(timestamp)" "$*"; }

require_uint() {
    local name="$1" value="$2"
    [[ "$value" =~ ^[0-9]+$ ]] || { log "$name must be an unsigned integer"; exit 2; }
}

require_uint MMDB_DOWNLOAD_INTERVAL_SECONDS "$MMDB_DOWNLOAD_INTERVAL_SECONDS"
require_uint MMDB_DOWNLOAD_RETRIES "$MMDB_DOWNLOAD_RETRIES"
if (( MMDB_DOWNLOAD_INTERVAL_SECONDS < 300 )); then
    log 'download interval below 300 seconds; clamping to 300'
    MMDB_DOWNLOAD_INTERVAL_SECONDS=300
fi

mkdir -p "$MMDB_DIR"

month() { date -u -d "$(date -u +%Y-%m-15) $1 month" +'%Y-%m'; }

download_urls() {
    if [[ -n "$MMDB_DOWNLOAD_URL" ]]; then
        printf '%s\n' "$MMDB_DOWNLOAD_URL"
        return
    fi

    case "$MMDB_PROVIDER" in
        dbip-jsdelivr)
            printf '%s\n' 'https://cdn.jsdelivr.net/npm/dbip-city-lite/dbip-city-lite.mmdb.gz'
            ;;
        dbip-official)
            printf 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz\n' "$(month 0)" "$(month -1)"
            ;;
        ip66)
            printf '%s\n' 'https://downloads.ip66.dev/db/ip66.mmdb'
            ;;
        generic)
            log 'MMDB_DOWNLOAD_URL is required for the generic provider'
            return 1
            ;;
        *)
            log "unsupported provider: $MMDB_PROVIDER"
            return 1
            ;;
    esac
}

fetch() {
    local url="$1" output="$2" args
    args=(
        --fail --location --silent --show-error
        --retry "$MMDB_DOWNLOAD_RETRIES" --retry-delay 5 --retry-all-errors
        --connect-timeout 20 --max-time 600 --output "$output"
    )
    [[ -z "$MMDB_DOWNLOAD_HEADER" ]] || args+=(--header "$MMDB_DOWNLOAD_HEADER")
    curl "${args[@]}" "$url"
}

extract_mmdb() {
    local artifact="$1" destination="$2" source_url="$3"
    mkdir -p "$destination"
    case "${source_url,,}" in
        *.tar.gz|*.tgz) tar -xzf "$artifact" -C "$destination" ;;
        *.zip) unzip -q "$artifact" -d "$destination" ;;
        *.mmdb.gz) gzip -dc "$artifact" > "$destination/$MMDB_TARGET_FILE" ;;
        *)
            if file "$artifact" | grep -qi 'gzip compressed'; then
                gzip -dc "$artifact" > "$destination/$MMDB_TARGET_FILE"
            else
                cp "$artifact" "$destination/$MMDB_TARGET_FILE"
            fi
            ;;
    esac
    find "$destination" -type f -iname '*.mmdb' -print0 | sort -z | head -z -n 1 | tr -d '\0'
}

validate_candidate() {
    local candidate="$1"
    [[ -s "$candidate" ]] || { log 'download contained no non-empty MMDB'; return 1; }
    if [[ -n "$MMDB_EXPECTED_SHA256" ]]; then
        printf '%s  %s\n' "$MMDB_EXPECTED_SHA256" "$candidate" | sha256sum -c - >/dev/null
    fi
    mmdblookup --file "$candidate" --ip 1.1.1.1 >/dev/null
}

update_once() {
    local work artifact extract candidate url success=false
    work="$(mktemp -d)"
    artifact="$work/artifact"
    extract="$work/extract"
    trap 'rm -rf "$work"' RETURN

    while IFS= read -r url; do
        [[ -n "$url" ]] || continue
        log "downloading from $url"
        if fetch "$url" "$artifact"; then success=true; break; fi
        log "download failed for $url"
    done < <(download_urls)

    [[ "$success" == true ]] || return 1
    candidate="$(extract_mmdb "$artifact" "$extract" "$url")"
    validate_candidate "$candidate"

    install -m 0644 "$candidate" "$MMDB_DIR/.${MMDB_TARGET_FILE}.candidate" || return 1
    mv -f "$MMDB_DIR/.${MMDB_TARGET_FILE}.candidate" "$MMDB_DIR/$MMDB_TARGET_FILE" || return 1
    timestamp > "$MMDB_DIR/LAST_UPDATE" || return 1
    log "activated $MMDB_TARGET_FILE"
}

if ! update_once; then
    log 'initial update failed; preserving existing database'
    [[ -s "$MMDB_DIR/$MMDB_TARGET_FILE" ]] || exit 1
fi

while true; do
    sleep "$MMDB_DOWNLOAD_INTERVAL_SECONDS"
    update_once || log 'update failed; preserving previous valid database'
done
