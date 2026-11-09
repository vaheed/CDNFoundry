#!/usr/bin/env bash
set -Eeuo pipefail

readonly MMDB_DIR="${MMDB_DIR:-/mmdb}"
MMDB_TARGET_FILE="${MMDB_TARGET_FILE:-GeoLite2-City.mmdb}"
# dbip-npm verifies itself: the npm registry publishes the SHA-512 of the package, so no checksum has to be configured.
readonly MMDB_PROVIDER="${MMDB_PROVIDER:-dbip-npm}"
readonly MMDB_NPM_REGISTRY_URL="${MMDB_NPM_REGISTRY_URL:-https://registry.npmjs.org}"
MMDB_DOWNLOAD_URL="${MMDB_DOWNLOAD_URL:-}"
readonly MMDB_DOWNLOAD_HEADER="${MMDB_DOWNLOAD_HEADER:-}"
MMDB_EXPECTED_SHA256="${MMDB_EXPECTED_SHA256:-}"
# An HTTPS URL of a published SHA-256 (the first field of its first line is used).
MMDB_CHECKSUM_URL="${MMDB_CHECKSUM_URL:-}"
# In production a download is activated only when a checksum vouches for it.
readonly MMDB_REQUIRE_VERIFICATION="${MMDB_REQUIRE_VERIFICATION:-false}"
MMDB_FALLBACK_FILE="${MMDB_FALLBACK_FILE:-/opt/fallback/GeoLite2-City.mmdb}"
readonly MMDB_DOWNLOAD_RETRIES="${MMDB_DOWNLOAD_RETRIES:-5}"
MMDB_DOWNLOAD_INTERVAL_SECONDS="${MMDB_DOWNLOAD_INTERVAL_SECONDS:-86400}"
# The autonomous-system database is optional enrichment. It is updated the same way as the city
# database, but a failure never stops the updater and is never reported as unhealthy.
readonly MMDB_ASN_TARGET_FILE="${MMDB_ASN_TARGET_FILE:-GeoLite2-ASN.mmdb}"
readonly MMDB_ASN_DOWNLOAD_URL="${MMDB_ASN_DOWNLOAD_URL:-}"
readonly MMDB_ASN_EXPECTED_SHA256="${MMDB_ASN_EXPECTED_SHA256:-}"
readonly MMDB_ASN_CHECKSUM_URL="${MMDB_ASN_CHECKSUM_URL:-}"
ASN_MODE=false
ARTIFACT_VERIFIED=false

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
    if [[ "$ASN_MODE" == true ]]; then
        case "$MMDB_PROVIDER" in
            dbip-jsdelivr) printf '%s\n' 'https://cdn.jsdelivr.net/npm/dbip-asn-lite/dbip-asn-lite.mmdb.gz' ;;
            dbip-official) printf 'https://download.db-ip.com/free/dbip-asn-lite-%s.mmdb.gz\n' "$(month 0)" "$(month -1)" ;;
            *) return 1 ;;
        esac
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

# Download the dbip-city-lite package from the npm registry, check it against the SHA-512 integrity the
# registry publishes for that exact version, and stream the database out of it.
fetch_npm_database() {
    local artifact="$1" destination="$2" meta tarball integrity version actual
    meta="$(curl --fail --silent --show-error --location --max-time 60 --retry "$MMDB_DOWNLOAD_RETRIES" --retry-delay 5 --retry-all-errors \
        "$MMDB_NPM_REGISTRY_URL/dbip-city-lite/latest")" || { log 'could not read the npm registry'; return 1; }
    tarball="$(grep -o '"tarball"[[:space:]]*:[[:space:]]*"[^"]*"' <<<"$meta" | head -n 1 | cut -d'"' -f4)"
    integrity="$(grep -o '"integrity"[[:space:]]*:[[:space:]]*"sha512-[^"]*"' <<<"$meta" | head -n 1 | cut -d'"' -f4)"
    version="$(grep -o '"version"[[:space:]]*:[[:space:]]*"[^"]*"' <<<"$meta" | head -n 1 | cut -d'"' -f4)"
    [[ "$tarball" == "$MMDB_NPM_REGISTRY_URL/dbip-city-lite/-/dbip-city-lite-"*.tgz ]] || { log 'the registry returned an unexpected package address'; return 1; }
    [[ "$integrity" =~ ^sha512-[A-Za-z0-9+/]{86}==$ ]] || { log 'the registry published no SHA-512 integrity for the package'; return 1; }
    log "downloading dbip-city-lite ${version} from $tarball"
    fetch "$tarball" "$artifact" || return 1
    actual="sha512-$(sha512sum "$artifact" | awk '{print toupper($1)}' | basenc --base16 -d | base64 -w0)"
    [[ "$actual" == "$integrity" ]] || { log 'package integrity mismatch'; return 1; }
    mkdir -p "$destination"
    tar -xzOf "$artifact" package/dbip-city-lite.mmdb.gz | gzip -dc > "$destination/$MMDB_TARGET_FILE" || { log 'the package contains no database'; return 1; }
    ARTIFACT_VERIFIED=true
}

validate_candidate() {
    local candidate="$1" expected="$MMDB_EXPECTED_SHA256"
    [[ -s "$candidate" ]] || { log 'download contained no non-empty MMDB'; return 1; }
    if [[ -z "$expected" && -n "$MMDB_CHECKSUM_URL" ]]; then
        expected="$(curl --fail --silent --show-error --location --max-time 60 "$MMDB_CHECKSUM_URL" | head -n 1 | awk '{print $1}')"
        [[ "$expected" =~ ^[0-9a-fA-F]{64}$ ]] || { log 'the published checksum is not a SHA-256'; return 1; }
    fi
    if [[ -z "$expected" && "$MMDB_REQUIRE_VERIFICATION" == true && "$ARTIFACT_VERIFIED" != true ]]; then
        log 'refusing an unverified download: set MMDB_EXPECTED_SHA256 or MMDB_CHECKSUM_URL'
        return 1
    fi
    if [[ -n "$expected" ]]; then
        printf '%s  %s\n' "$expected" "$candidate" | sha256sum -c - >/dev/null || { log 'checksum mismatch'; return 1; }
    fi
    # Status 6 means a well-formed database with no entry for the probe address; any other failure
    # means the file cannot be read.
    local status=0
    mmdblookup --file "$candidate" --ip 1.1.1.1 >/dev/null 2>&1 || status=$?
    [[ "$status" == 0 || "$status" == 6 ]] || { log 'the candidate is not a readable MMDB'; return 1; }
}

update_once() {
    local work artifact extract candidate url success=false
    work="$(mktemp -d)"
    artifact="$work/artifact"
    extract="$work/extract"
    trap 'rm -rf "$work"' RETURN

    ARTIFACT_VERIFIED=false
    # update_once runs inside an `if`, where `set -e` is off, so every step reports failure explicitly.
    if [[ "$MMDB_PROVIDER" == dbip-npm && -z "$MMDB_DOWNLOAD_URL" && "$ASN_MODE" != true ]]; then
        fetch_npm_database "$artifact" "$extract" || return 1
        candidate="$extract/$MMDB_TARGET_FILE"
    else
        while IFS= read -r url; do
            [[ -n "$url" ]] || continue
            log "downloading from $url"
            if fetch "$url" "$artifact"; then success=true; break; fi
            log "download failed for $url"
        done < <(download_urls)

        [[ "$success" == true ]] || return 1
        candidate="$(extract_mmdb "$artifact" "$extract" "$url")" || return 1
    fi
    validate_candidate "$candidate" || return 1

    install -m 0644 "$candidate" "$MMDB_DIR/.${MMDB_TARGET_FILE}.candidate" || return 1
    mv -f "$MMDB_DIR/.${MMDB_TARGET_FILE}.candidate" "$MMDB_DIR/$MMDB_TARGET_FILE" || return 1
    timestamp > "$MMDB_DIR/LAST_UPDATE" || return 1
    rm -f "$MMDB_DIR/FALLBACK_IN_USE"
    log "activated $MMDB_TARGET_FILE"
}

if ! update_once; then
    log 'initial update failed; preserving existing database'
    if [[ ! -s "$MMDB_DIR/$MMDB_TARGET_FILE" ]]; then
        # DNS must still start. Geo-DNS answers fall back to each record's default until a real
        # database is installed; FALLBACK_IN_USE marks the degraded state for operators.
        [[ -s "$MMDB_FALLBACK_FILE" ]] || exit 1
        install -m 0644 "$MMDB_FALLBACK_FILE" "$MMDB_DIR/.${MMDB_TARGET_FILE}.candidate"
        mv -f "$MMDB_DIR/.${MMDB_TARGET_FILE}.candidate" "$MMDB_DIR/$MMDB_TARGET_FILE"
        timestamp > "$MMDB_DIR/FALLBACK_IN_USE"
        log 'WARNING: installed the empty fallback database; Geo-DNS uses default answers until an update succeeds'
    fi
fi

update_asn() {
    (
        ASN_MODE=true
        MMDB_TARGET_FILE="$MMDB_ASN_TARGET_FILE"
        MMDB_DOWNLOAD_URL="$MMDB_ASN_DOWNLOAD_URL"
        MMDB_EXPECTED_SHA256="$MMDB_ASN_EXPECTED_SHA256"
        MMDB_CHECKSUM_URL="$MMDB_ASN_CHECKSUM_URL"
        MMDB_FALLBACK_FILE="${MMDB_ASN_FALLBACK_FILE:-/opt/fallback/GeoLite2-ASN.mmdb}"
        if [[ "$MMDB_PROVIDER" == dbip-npm && -z "$MMDB_DOWNLOAD_URL" ]]; then
            # No verified autonomous-system source is published for this provider, so nothing is attempted.
            if [[ ! -s "$MMDB_DIR/$MMDB_TARGET_FILE" && -s "$MMDB_FALLBACK_FILE" ]]; then
                install -m 0644 "$MMDB_FALLBACK_FILE" "$MMDB_DIR/$MMDB_TARGET_FILE"
                log 'no ASN source is configured (set MMDB_ASN_DOWNLOAD_URL and a checksum); events carry ASN 0'
            fi
        elif ! update_once; then
            log 'ASN update failed; preserving the existing ASN database'
            if [[ ! -s "$MMDB_DIR/$MMDB_TARGET_FILE" && -s "$MMDB_FALLBACK_FILE" ]]; then
                install -m 0644 "$MMDB_FALLBACK_FILE" "$MMDB_DIR/$MMDB_TARGET_FILE"
                log 'installed the empty ASN fallback; events carry ASN 0 until an update succeeds'
            fi
        fi
    ) || true
}

update_asn

while true; do
    sleep "$MMDB_DOWNLOAD_INTERVAL_SECONDS"
    update_once || log 'update failed; preserving previous valid database'
    update_asn
done
