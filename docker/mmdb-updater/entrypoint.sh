#!/usr/bin/env bash
set -Eeuo pipefail

readonly MMDB_DIR="${MMDB_DIR:-/mmdb}"
MMDB_TARGET_FILE="${MMDB_TARGET_FILE:-GeoLite2-City.mmdb}"
# IPFire is signed and keyless; other providers and authenticated MMDB sources remain supported.
readonly MMDB_PROVIDER="${MMDB_PROVIDER:-ipfire}"
readonly MMDB_IPFIRE_DOWNLOAD_URL="${MMDB_IPFIRE_DOWNLOAD_URL:-https://location.ipfire.org/databases/1/location.db.xz}"
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
# Cache the complete ASN records, including organization names, alongside the city database.
# Sources must supply usable IPv4 and IPv6 records and pass verification before activation.
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
        --connect-timeout 20 --max-time 600 --max-filesize 268435456 --output "$output"
    )
    if [[ "${3:-true}" == true && -n "$MMDB_DOWNLOAD_HEADER" ]]; then args+=(--header "$MMDB_DOWNLOAD_HEADER"); fi
    curl "${args[@]}" "$url"
}

extract_mmdb() (
    local artifact="$1" destination="$2" member
    mkdir -p "$destination" || return 1
    ulimit -f 262144
    # Authenticated download endpoints often have no extension, or put the archive format in a query.
    # Stream just one MMDB out of an archive instead of extracting arbitrary archive paths to disk.
    if tar -tzf "$artifact" > "$destination/members" 2>/dev/null; then
        member="$(sort "$destination/members" | awk 'tolower($0) ~ /\.mmdb$/ {print; exit}')"
        [[ -n "$member" ]] || return 1
        tar -xzOf "$artifact" -- "$member" > "$destination/$MMDB_TARGET_FILE" || return 1
    elif unzip -Z1 "$artifact" > "$destination/members" 2>/dev/null; then
        member="$(sort "$destination/members" | awk 'tolower($0) ~ /\.mmdb$/ {print; exit}')"
        [[ -n "$member" ]] || return 1
        unzip -p "$artifact" "$member" > "$destination/$MMDB_TARGET_FILE" || return 1
    elif file "$artifact" | grep -qi 'gzip compressed'; then
        gzip -dc "$artifact" > "$destination/$MMDB_TARGET_FILE" || return 1
    else
        cp "$artifact" "$destination/$MMDB_TARGET_FILE" || return 1
    fi
    printf '%s\n' "$destination/$MMDB_TARGET_FILE"
)

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
    local candidate="$1" expected="$MMDB_EXPECTED_SHA256" artifact="${2:-}"
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
        if ! printf '%s  %s\n' "$expected" "$candidate" | sha256sum -c - >/dev/null 2>&1; then
            # Providers commonly publish the archive checksum rather than the enclosed MMDB checksum.
            [[ -n "$artifact" ]] && printf '%s  %s\n' "$expected" "$artifact" | sha256sum -c - >/dev/null 2>&1 || {
                log 'checksum mismatch'; return 1;
            }
        fi
    fi
    # Status 6 means a well-formed database with no entry for the probe address; any other failure
    # means the file cannot be read.
    local status=0
    mmdblookup --file "$candidate" --ip 1.1.1.1 >/dev/null 2>&1 || status=$?
    [[ "$status" == 0 || "$status" == 6 ]] || { log 'the candidate is not a readable MMDB'; return 1; }
    status=0
    mmdblookup --file "$candidate" --ip 2606:4700:4700::1111 >/dev/null 2>&1 || status=$?
    [[ "$status" == 0 || "$status" == 6 ]] || { log 'the candidate does not support IPv6'; return 1; }
    if [[ "$ASN_MODE" == true ]]; then
        local address
        for address in 1.1.1.1 2606:4700:4700::1111; do
            mmdblookup --file "$candidate" --ip "$address" autonomous_system_number 2>/dev/null | grep -Eq '[1-9][0-9]* <uint32>' || {
                log 'the ASN candidate has no usable IPv4/IPv6 ASN records'; return 1;
            }
        done
    fi
}

update_once() (
    local work artifact extract candidate url success=false
    work="$(mktemp -d)"
    artifact="$work/artifact"
    extract="$work/extract"
    trap 'rm -rf "$work"' EXIT

    ARTIFACT_VERIFIED=false
    # update_once runs inside an `if`, where `set -e` is off, so every step reports failure explicitly.
    if [[ "$MMDB_PROVIDER" == dbip-npm && -z "$MMDB_DOWNLOAD_URL" && "$ASN_MODE" != true ]]; then
        fetch_npm_database "$artifact" "$extract" || return 1
        candidate="$extract/$MMDB_TARGET_FILE"
    else
        while IFS= read -r url; do
            [[ -n "$url" ]] || continue
            log "downloading the configured MMDB source"
            if fetch "$url" "$artifact"; then success=true; break; fi
            log "configured MMDB download failed"
        done < <(download_urls)

        [[ "$success" == true ]] || return 1
        candidate="$(extract_mmdb "$artifact" "$extract" "$url")" || return 1
    fi
    validate_candidate "$candidate" "$artifact" || return 1

    activate_candidate "$candidate"
)

activate_candidate() {
    local candidate="$1"
    if ! cmp -s "$candidate" "$MMDB_DIR/$MMDB_TARGET_FILE"; then
        install -m 0644 "$candidate" "$MMDB_DIR/.${MMDB_TARGET_FILE}.candidate" || return 1
        mv -f "$MMDB_DIR/.${MMDB_TARGET_FILE}.candidate" "$MMDB_DIR/$MMDB_TARGET_FILE" || return 1
    fi
    # Content stamps let readers reopen a replaced mmap without restarting the cell.
    sha256sum "$candidate" | awk '{print $1}' > "$MMDB_DIR/.${MMDB_TARGET_FILE}.revision" || return 1
    mv -f "$MMDB_DIR/.${MMDB_TARGET_FILE}.revision" "$MMDB_DIR/${MMDB_TARGET_FILE}.revision" || return 1
    timestamp > "$MMDB_DIR/${MMDB_TARGET_FILE}.last-update" || return 1
    rm -f "$MMDB_DIR/${MMDB_TARGET_FILE}.fallback"
    if [[ "$ASN_MODE" != true ]]; then
        timestamp > "$MMDB_DIR/LAST_UPDATE" || return 1
        rm -f "$MMDB_DIR/FALLBACK_IN_USE"
    fi
    log "activated $MMDB_TARGET_FILE"
}

install_fallback() {
    local target="$1" fallback="$2" primary="$3"
    if [[ ! -s "$MMDB_DIR/$target" ]]; then
        install -m 0644 "$fallback" "$MMDB_DIR/.$target.candidate" || return 1
        mv -f "$MMDB_DIR/.$target.candidate" "$MMDB_DIR/$target" || return 1
        timestamp > "$MMDB_DIR/$target.fallback"
        if [[ "$primary" == true ]]; then timestamp > "$MMDB_DIR/FALLBACK_IN_USE"; fi
        log "WARNING: installed the empty fallback for $target; lookups use unknown/default values until recovery"
    fi
}

update_ipfire() (
    local work candidate native_hash output_hash compiler_hash cached_native cached_output cached_compiler cached_file
    work="$(mktemp -d)" || return 1
    trap 'rm -rf "$work"' EXIT
    # Bound expanded downloads and generated files as well as transfer sizes and runtime.
    ulimit -f 262144
    fetch "$MMDB_IPFIRE_DOWNLOAD_URL" "$work/location.db.xz" false || return 1
    xz --memlimit-decompress=128MiB -dc "$work/location.db.xz" > "$work/location.db" || return 1
    timeout 60 python3 /usr/local/bin/ipfire-export "$work/location.db" "$MMDB_DIR/IPFire.db" --verify-only || return 1
    native_hash="$(sha256sum "$work/location.db" | awk '{print $1}')"
    compiler_hash="$({
        sha256sum /usr/local/bin/mmdb-compiler /usr/local/bin/ipfire-export
        python3 -c 'import location; print(location.__version__)'
    } | sha256sum | awk '{print $1}')"
    candidate=""
    if [[ -f "$MMDB_DIR/IPFire.cache" ]]; then
        read -r cached_native cached_output cached_compiler < "$MMDB_DIR/IPFire.cache" || true
        if [[ "$native_hash" == "$cached_native" && "$compiler_hash" == "$cached_compiler" ]]; then
            cached_file="$MMDB_DIR/$MMDB_ASN_TARGET_FILE"
            if [[ -z "$MMDB_DOWNLOAD_URL" ]]; then cached_file="$MMDB_DIR/$MMDB_TARGET_FILE"; fi
            if [[ -s "$cached_file" && "$(sha256sum "$cached_file" | awk '{print $1}')" == "$cached_output" ]]; then
                candidate="$cached_file"
            fi
        fi
    fi
    if [[ -z "$candidate" ]]; then
        candidate="$work/IPFire.mmdb"
        timeout 600 python3 /usr/local/bin/ipfire-export "$work/location.db" "$MMDB_DIR/IPFire.db" \
            | GOMEMLIMIT=768MiB timeout 600 /usr/local/bin/mmdb-compiler "$candidate" || return 1
    fi
    output_hash="$(sha256sum "$candidate" | awk '{print $1}')"
    # Signature verification covers the native input; both generated record layouts must still validate.
    ARTIFACT_VERIFIED=true
    MMDB_EXPECTED_SHA256=""
    MMDB_CHECKSUM_URL=""
    ASN_MODE=true
    validate_candidate "$candidate" || return 1
    if [[ -z "$MMDB_DOWNLOAD_URL" ]]; then
        ASN_MODE=false
        activate_candidate "$candidate" || return 1
    fi
    if [[ -z "$MMDB_ASN_DOWNLOAD_URL" ]]; then
        ASN_MODE=true
        MMDB_TARGET_FILE="$MMDB_ASN_TARGET_FILE"
        activate_candidate "$candidate" || return 1
    fi
    install -m 0644 "$work/location.db" "$MMDB_DIR/.IPFire.db.candidate" || return 1
    mv -f "$MMDB_DIR/.IPFire.db.candidate" "$MMDB_DIR/IPFire.db" || return 1
    printf '%s %s %s\n' "$native_hash" "$output_hash" "$compiler_hash" > "$MMDB_DIR/.IPFire.cache.candidate" || return 1
    mv -f "$MMDB_DIR/.IPFire.cache.candidate" "$MMDB_DIR/IPFire.cache" || return 1
    log 'verified and cached IPFire data under CC BY-SA 4.0; https://www.ipfire.org/location/'
)

update_asn() {
    (
        ASN_MODE=true
        MMDB_TARGET_FILE="$MMDB_ASN_TARGET_FILE"
        MMDB_DOWNLOAD_URL="$MMDB_ASN_DOWNLOAD_URL"
        MMDB_EXPECTED_SHA256="$MMDB_ASN_EXPECTED_SHA256"
        MMDB_CHECKSUM_URL="$MMDB_ASN_CHECKSUM_URL"
        MMDB_FALLBACK_FILE="${MMDB_ASN_FALLBACK_FILE:-/opt/fallback/GeoLite2-ASN.mmdb}"
        if ! update_once; then
            log 'ASN update failed; preserving the existing ASN database'
            install_fallback "$MMDB_TARGET_FILE" "$MMDB_FALLBACK_FILE" false
        fi
    ) || true
}

update_databases() {
    if [[ "$MMDB_PROVIDER" == ipfire && ( -z "$MMDB_DOWNLOAD_URL" || -z "$MMDB_ASN_DOWNLOAD_URL" ) ]]; then
        update_ipfire || log 'IPFire update failed; preserving previous valid databases'
    fi
    if [[ "$MMDB_PROVIDER" != ipfire || -n "$MMDB_DOWNLOAD_URL" ]]; then
        update_once || log 'city update failed; preserving previous valid database'
    fi
    install_fallback "$MMDB_TARGET_FILE" "$MMDB_FALLBACK_FILE" true || return 1
    if [[ "$MMDB_PROVIDER" != ipfire || -n "$MMDB_ASN_DOWNLOAD_URL" ]]; then
        update_asn
    fi
    install_fallback "$MMDB_ASN_TARGET_FILE" "${MMDB_ASN_FALLBACK_FILE:-/opt/fallback/GeoLite2-ASN.mmdb}" false
}

# Let DNS and cells start immediately even if the first provider request times out.
install_fallback "$MMDB_TARGET_FILE" "$MMDB_FALLBACK_FILE" true
install_fallback "$MMDB_ASN_TARGET_FILE" "${MMDB_ASN_FALLBACK_FILE:-/opt/fallback/GeoLite2-ASN.mmdb}" false
update_databases
while true; do
    sleep "$MMDB_DOWNLOAD_INTERVAL_SECONDS"
    update_databases
done
