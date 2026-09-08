FROM busybox:1.38.0-musl@sha256:32b5cdad7cce41dfd53d0ae06baebcf8357a147ee7694dc706911c373bc30c37 AS healthcheck

FROM grafana/loki:3.7.7@sha256:d70e4659623f3e109af669cae76fe2a5dd5be54e2298fe8aed380d982fbc2500
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry bounded Loki log store" \
      org.opencontainers.image.licenses="AGPL-3.0-only" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
COPY --from=healthcheck /bin/busybox /usr/local/bin/busybox
COPY loki.yml /etc/loki/loki.yml
ENTRYPOINT ["/usr/bin/loki"]
CMD ["-config.file=/etc/loki/loki.yml", "-config.expand-env=true"]
