#!/bin/sh
set -eu

target=/pki
# The secret that proves edge identity headers came from edge-control's nginx. It is not part of
# the required set below, so an existing volume gains it without being rejected as incomplete.
ensure_proxy_secret() {
    if [ ! -s "$target/edge-proxy-secret.conf" ]; then
        printf 'fastcgi_param HTTP_X_EDGE_PROXY_SECRET "%s";\n' "$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')" > "$target/edge-proxy-secret.conf"
    fi
    chgrp 82 "$target/edge-proxy-secret.conf"
    chmod 640 "$target/edge-proxy-secret.conf"
}

required="edge-identity-ca.crt edge-identity-ca.key edge-server-ca.crt edge-control-server.crt edge-control-server.key edge-runtime.crt edge-runtime.key tls.crt tls.key"
complete=true
for file in $required; do
    if [ ! -s "$target/$file" ]; then
        complete=false
    fi
done

if [ "$complete" = true ]; then
    chgrp 82 "$target/edge-identity-ca.key"
    chmod 640 "$target/edge-identity-ca.key"
    openssl verify -CAfile "$target/edge-server-ca.crt" \
        "$target/edge-control-server.crt" "$target/edge-runtime.crt" >/dev/null
    openssl verify -CAfile "$target/edge-identity-ca.crt" "$target/edge-identity-ca.crt" >/dev/null
    ensure_proxy_secret
    echo "Development PKI is present and valid."
    exit 0
fi

if find "$target" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
    echo "Development PKI volume is incomplete; refusing to combine unrelated certificate material." >&2
    exit 1
fi

generated=/tmp/cdnfoundry-development-pki
generate-production-certificates "$generated" edge-control edge-runtime
cp "$generated"/* "$target"/
cp "$generated/edge-runtime.crt" "$target/tls.crt"
cp "$generated/edge-runtime.key" "$target/tls.key"
chmod 600 "$target"/*.key
chgrp 82 "$target/edge-identity-ca.key"
chmod 640 "$target/edge-identity-ca.key"
chmod 644 "$target"/*.crt
ensure_proxy_secret
echo "Development-only PKI initialized in the persistent Compose volume."
