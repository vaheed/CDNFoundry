#!/bin/sh
set -eu

target=/pki
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
echo "Development-only PKI initialized in the persistent Compose volume."
