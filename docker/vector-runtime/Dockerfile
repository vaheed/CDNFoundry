FROM timberio/vector:0.58.0-debian@sha256:1c1ea358c617ea0b23003d5af87f7a678b30f8f7096437e680380c47fc13d2d9 AS upstream
FROM ubuntu:26.04@sha256:da6fc2be547864451aa253836dd926da33623312df4a9a243e35dc877c378a78
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry Vector telemetry and journal collector" \
      org.opencontainers.image.licenses="MPL-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates libgcc-s1 zlib1g systemd \
    && rm -rf /var/lib/apt/lists/* && mkdir -p /etc/vector /var/lib/vector
COPY --from=upstream /usr/bin/vector /usr/bin/vector
ENTRYPOINT ["/usr/bin/vector"]
CMD ["--config", "/etc/vector/vector.yaml"]
