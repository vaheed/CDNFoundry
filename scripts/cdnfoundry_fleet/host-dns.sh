#!/bin/sh
# Only move systemd-resolved's stub when DNSdist needs a wildcard bind on this host.
set -eu
role=${1:?node role is required}
case "$role" in dns|dns-edge) ;; *) exit 0 ;; esac
case "${2:-0.0.0.0},${3:-}" in 0.0.0.0,*|*,::) ;; *) exit 0 ;; esac
listeners=$(ss -H -ltnup 'sport = :53')
printf '%s' "$listeners" | grep -q 'systemd-resolve' || exit 0
systemctl is-active --quiet systemd-resolved
upstream=/run/systemd/resolve/resolv.conf
if ! grep -Eq '^nameserver[[:space:]]+' "$upstream" || grep -Eq '^nameserver[[:space:]]+127\.' "$upstream"; then
    echo 'Cannot release the DNS stub: systemd-resolved has no usable upstream resolv.conf.' >&2
    exit 1
fi
config=/etc/systemd/resolved.conf.d/cdnfoundry.conf
if [ -e "$config" ]; then
    echo 'The Fleet DNS resolver drop-in already exists, but port 53 is still occupied. Check systemd-resolved.' >&2
    exit 1
fi
original=$(readlink /etc/resolv.conf || true)
if [ "$original" != /run/systemd/resolve/stub-resolv.conf ] && [ "$original" != "$upstream" ]; then
    echo 'Cannot release the DNS stub: /etc/resolv.conf is managed by another resolver. Keep its configuration and use explicit DNS bind addresses.' >&2
    exit 1
fi
rollback() {
    [ "$committed" = 0 ] || return 0
    rm -f "$config"
    ln -sfn "$original" /etc/resolv.conf
    systemctl restart systemd-resolved || echo 'Previous resolver settings restored, but systemd-resolved did not restart. Check host DNS before retrying.' >&2
}
committed=0
trap rollback EXIT
trap 'exit 1' HUP INT TERM
mkdir -p /etc/systemd/resolved.conf.d
printf '[Resolve]\nDNSStubListener=no\n' > "$config"
ln -sfn "$upstream" /etc/resolv.conf
if ! systemctl restart systemd-resolved || ! timeout 15 getent hosts download.docker.com >/dev/null; then
    echo 'Resolver preparation failed; restoring its previous settings.' >&2
    exit 1
fi
committed=1
echo 'Released systemd-resolved port 53; host DNS still uses its upstream resolver configuration.'
