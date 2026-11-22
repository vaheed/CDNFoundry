#!/bin/sh
# Runs as root over SSH before a release is transferred. --plan only reads os-release.
set -eu
. "${CDNF_OS_RELEASE:-/etc/os-release}"
family=''
for candidate in "$ID" ${ID_LIKE:-}; do
    case "$candidate" in
        ubuntu|debian) family=apt-get; break ;;
        fedora|rhel|centos|rocky|almalinux|ol) family=dnf; break ;;
        alpine) family=apk; break ;;
        arch|manjaro) family=pacman; break ;;
        opensuse*|sles|suse) family=zypper; break ;;
    esac
done
unsupported() {
    echo "Unsupported Linux distribution for automatic package installation: $ID. Install Docker Engine, Compose v2+, Python 3, OpenSSL, CA certificates, tar, curl, bash, iproute2, iptables and util-linux before retrying." >&2
    exit 1
}
echo "Host: $ID ${VERSION_ID:-rolling}; package manager: ${family:-unavailable}"
if [ "${1:-}" = --plan ]; then
    [ -n "$family" ] || unsupported
    exit 0
fi
need=0
docker_needed=0
for tool in docker python3 openssl tar ss flock bash curl iptables ip6tables; do command -v "$tool" >/dev/null || need=1; done
if ! docker compose version >/dev/null 2>&1; then need=1; docker_needed=1; fi
if [ "$need" = 0 ] && timeout 30 docker info >/dev/null 2>&1; then
    echo 'Host dependencies and Docker daemon are already ready.'
    exit 0
fi
[ "$(id -u)" = 0 ] || { echo 'Host preparation requires root.' >&2; exit 1; }
if [ "$need" = 1 ] && [ -z "$family" ]; then unsupported; fi
if command -v systemctl >/dev/null && [ -d /run/systemd/system ]; then
    init=systemd
elif command -v rc-service >/dev/null && command -v rc-update >/dev/null; then
    init=openrc
else
    echo 'Unsupported service manager: Docker needs systemd or OpenRC. No packages were changed.' >&2
    exit 1
fi
if [ "$need" = 1 ]; then
    case "$family" in
        apt-get)
            export DEBIAN_FRONTEND=noninteractive
            apt-get update -qq
            apt-get install -y -qq python3 openssl ca-certificates tar iproute2 util-linux curl bash iptables
            if [ "$docker_needed" = 1 ]; then
                if apt-cache show docker-compose-v2 >/dev/null 2>&1; then
                    if ! command -v docker >/dev/null; then apt-get install -y -qq docker.io; fi
                    apt-get install -y -qq docker-compose-v2
                else
                    distro=debian; codename=${VERSION_CODENAME:-}
                    case " $ID ${ID_LIKE:-} " in *' ubuntu '*) distro=ubuntu; codename=${UBUNTU_CODENAME:-$codename} ;; esac
                    case "$codename" in ''|*[!a-z0-9]*) echo 'Cannot determine the Debian/Ubuntu base release for the Docker repository.' >&2; exit 1 ;; esac
                    install -m 0755 -d /etc/apt/keyrings
                    curl -fsSL "https://download.docker.com/linux/$distro/gpg" -o /etc/apt/keyrings/cdnfoundry-docker.asc
                    chmod 0644 /etc/apt/keyrings/cdnfoundry-docker.asc
                    printf 'deb [arch=%s signed-by=/etc/apt/keyrings/cdnfoundry-docker.asc] https://download.docker.com/linux/%s %s stable\n' "$(dpkg --print-architecture)" "$distro" "$codename" > /etc/apt/sources.list.d/cdnfoundry-docker.list
                    apt-get update -qq
                    if ! command -v docker >/dev/null; then apt-get install -y -qq docker-ce docker-ce-cli containerd.io; fi
                    apt-get install -y -qq docker-compose-plugin
                fi
            fi
            ;;
        dnf)
            manager=dnf; command -v dnf >/dev/null || manager=yum
            "$manager" install -y python3 openssl ca-certificates tar iproute util-linux bash iptables
            command -v curl >/dev/null || "$manager" install -y curl
            if [ "$docker_needed" = 1 ]; then
                distro=centos
                case "$ID" in fedora) distro=fedora ;; rhel|rocky|almalinux|ol) distro=rhel ;; esac
                curl -fsSL "https://download.docker.com/linux/$distro/docker-ce.repo" -o /etc/yum.repos.d/cdnfoundry-docker.repo
                if ! command -v docker >/dev/null; then "$manager" install -y docker-ce docker-ce-cli containerd.io; fi
                "$manager" install -y docker-compose-plugin
            fi
            ;;
        apk)
            if [ "$docker_needed" = 1 ] && ! grep -Eq '^[^#].*/community/?$' /etc/apk/repositories; then
                community=$(sed -n 's@/main/*$@/community@p' /etc/apk/repositories | grep -v '^#' | head -n 1)
                [ -n "$community" ] || { echo 'Cannot locate the matching Alpine community repository.' >&2; exit 1; }
                printf '\n%s\n' "$community" >> /etc/apk/repositories
            fi
            apk add --no-cache python3 openssl ca-certificates tar iproute2 util-linux curl bash iptables
            if [ "$docker_needed" = 1 ]; then apk add --no-cache docker docker-cli-compose docker-openrc; fi
            ;;
        pacman)
            pacman -S --needed --noconfirm python openssl ca-certificates tar iproute2 util-linux curl bash iptables
            if [ "$docker_needed" = 1 ]; then pacman -S --needed --noconfirm docker docker-compose; fi
            ;;
        zypper)
            zypper --non-interactive install python3 openssl ca-certificates tar iproute2 util-linux curl bash iptables
            if [ "$docker_needed" = 1 ]; then zypper --non-interactive install docker docker-compose; fi
            ;;
    esac
fi
if [ "$init" = systemd ]; then
    systemctl enable --now docker
else
    rc-update add docker default
    rc-service docker start
fi
for tool in docker python3 openssl tar ss flock bash curl iptables ip6tables; do command -v "$tool" >/dev/null || { echo "Missing required command after installation: $tool" >&2; exit 1; }; done
docker compose version
timeout 30 docker info >/dev/null
echo 'Host dependencies and Docker daemon are ready.'
