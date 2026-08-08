#!/usr/bin/env sh
set -eu

ready() {
  command -v python3 >/dev/null 2>&1 \
    && python3 -c 'import yaml' >/dev/null 2>&1 \
    && command -v openssl >/dev/null 2>&1 \
    && command -v docker >/dev/null 2>&1 \
    && docker compose version >/dev/null 2>&1
}
ready && exit 0
[ "$(id -u)" -eq 0 ] || { echo "Run this prerequisite installer as root." >&2; exit 1; }

if command -v apt-get >/dev/null 2>&1; then
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y \
    python3 python3-yaml openssl ca-certificates curl docker.io
  if ! docker compose version >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y docker-compose-v2 \
      || DEBIAN_FRONTEND=noninteractive apt-get install -y docker-compose-plugin
  fi
elif command -v dnf >/dev/null 2>&1; then
  dnf install -y python3 python3-pyyaml openssl ca-certificates curl docker docker-compose-plugin
elif command -v apk >/dev/null 2>&1; then
  apk add --no-cache python3 py3-yaml openssl ca-certificates curl docker docker-cli-compose
else
  echo "Unsupported package manager. Install Python 3, PyYAML, OpenSSL, Docker Engine, and Docker Compose v2." >&2
  exit 1
fi

if command -v systemctl >/dev/null 2>&1; then
  systemctl enable --now docker
elif command -v rc-update >/dev/null 2>&1; then
  rc-update add docker default || true
  rc-service docker start || true
fi

ready || {
  echo "Prerequisite installation is incomplete. Install Docker Engine and Docker Compose v2 from a supported package repository." >&2
  exit 1
}
