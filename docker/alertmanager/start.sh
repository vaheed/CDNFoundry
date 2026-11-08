#!/bin/sh
# Starts Alertmanager with the configured notifier. The notifier is optional: with no
# ALERT_WEBHOOK_URL it starts with receivers that send nothing, so alerts still fire and show in
# Prometheus and Alertmanager, and a webhook can be added later by setting the value and restarting.
set -eu

url="${ALERT_WEBHOOK_URL:-}"
config=/etc/alertmanager/alertmanager.yml
if [ -z "$url" ]; then
    echo 'no notifier configured: alerts are evaluated but sent nowhere. Set ALERT_WEBHOOK_URL to receive them.' >&2
    sed -e '/^    webhook_configs:$/d' -e '/^      - url_file:/d' -e '/^        send_resolved:/d' "$config" > /tmp/alertmanager-no-notifier.yml
    exec /bin/alertmanager --config.file=/tmp/alertmanager-no-notifier.yml --storage.path=/alertmanager "$@"
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
exec /bin/alertmanager --config.file="$config" --storage.path=/alertmanager "$@"
