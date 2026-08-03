ARG GRAFANA_IMAGE=grafana/grafana:12.4.6@sha256:950e5b5bd535b4414642e4034c9087958778f8119aa2af90934c6a191e30dde9
FROM ${GRAFANA_IMAGE}

ARG CLICKHOUSE_PLUGIN_VERSION=4.20.0
ARG CLICKHOUSE_PLUGIN_SHA256=4da9c1803e753ad483a37c1e75d202d6ad0cdfc6f9271800c88b69d604b4ebf1
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry Grafana observability" \
      org.opencontainers.image.licenses="AGPL-3.0-only" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
USER root
ADD --checksum=sha256:${CLICKHOUSE_PLUGIN_SHA256} \
    https://github.com/grafana/clickhouse-datasource/releases/download/v${CLICKHOUSE_PLUGIN_VERSION}/grafana-clickhouse-datasource-${CLICKHOUSE_PLUGIN_VERSION}.linux_amd64.zip \
    /tmp/grafana-clickhouse-datasource.zip
RUN mkdir -p /usr/share/grafana/cdnfoundry-plugins \
    && unzip -q /tmp/grafana-clickhouse-datasource.zip -d /usr/share/grafana/cdnfoundry-plugins \
    && rm /tmp/grafana-clickhouse-datasource.zip \
    && find /usr/share/grafana/cdnfoundry-plugins -type d -exec chmod 0755 {} + \
    && find /usr/share/grafana/cdnfoundry-plugins -type f -exec chmod 0644 {} + \
    && chmod 0755 /usr/share/grafana/cdnfoundry-plugins/grafana-clickhouse-datasource/gpx_clickhouse_linux_amd64
USER 472
