#!/bin/sh
# Starts Alertmanager only when a notifier is configured, so an install can
# never run with alerts that go nowhere.
set -eu

url="${ALERT_WEBHOOK_URL:-}"
if [ -z "$url" ]; then
    echo 'ALERT_WEBHOOK_URL is required: configure a notifier before starting Alertmanager' >&2
    exit 1
fi
case "$url" in
    https://*) ;;
    http://*)
        [ "${ALERT_WEBHOOK_ALLOW_HTTP:-false}" = true ] || {
            echo 'ALERT_WEBHOOK_URL must use https:// unless ALERT_WEBHOOK_ALLOW_HTTP=true' >&2
            exit 1
        }
        ;;
    *)
        echo 'ALERT_WEBHOOK_URL must be an http:// or https:// URL' >&2
        exit 1
        ;;
esac
umask 077
printf '%s' "$url" > /tmp/alert-webhook-url
exec /bin/alertmanager --config.file=/etc/alertmanager/alertmanager.yml --storage.path=/alertmanager "$@"
