#!/bin/sh
# Starts Alertmanager. Every receiver posts to the control plane's private alert receiver, which hands alerts to the
# notifier. The receiver URL defaults to the control web container on the shared private network.
set -eu

url="${ALERT_RECEIVER_URL:-http://web:8080/internal/alertmanager}"
case "$url" in
    http://* | https://*) ;;
    *)
        echo 'ALERT_RECEIVER_URL must be an http:// or https:// URL' >&2
        exit 1
        ;;
esac
umask 077
printf '%s' "$url" > /tmp/alert-receiver-url
exec /bin/alertmanager --config.file=/etc/alertmanager/alertmanager.yml --storage.path=/alertmanager "$@"
