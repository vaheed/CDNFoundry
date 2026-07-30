FROM busybox:1.37.0-musl@sha256:222ad6d973c0d198014546a65cd02c5fdedcc172123c5b4c2bf0af636550bd94 AS healthcheck

FROM grafana/loki:3.7.2@sha256:191d4fdfb7264f16989f0a57f320872620a5a7c2ceeec6229212c4190ec49b86
COPY --from=healthcheck /bin/busybox /usr/local/bin/busybox
COPY loki.yml /etc/loki/loki.yml
ENTRYPOINT ["/usr/bin/loki"]
CMD ["-config.file=/etc/loki/loki.yml", "-config.expand-env=true"]
