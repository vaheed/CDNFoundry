ARG GRAFANA_IMAGE=grafana/grafana:12.4.11@sha256:3ea272e5cab64a4a62240c682e2c62433b25614d956d44c299a10cb6994f6f2e
FROM ${GRAFANA_IMAGE}

ARG CLICKHOUSE_PLUGIN_VERSION=4.21.3
ARG CLICKHOUSE_PLUGIN_SHA256=53c0602214e7ca1af1733962779293587386b0b9a8c544ecead80b819e5f560b
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry Grafana observability" \
      org.opencontainers.image.licenses="AGPL-3.0-only" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
USER root
ADD --checksum=sha256:${CLICKHOUSE_PLUGIN_SHA256} \
    https://grafana.com/api/plugins/grafana-clickhouse-datasource/versions/${CLICKHOUSE_PLUGIN_VERSION}/download?os=linux&arch=amd64 \
    /tmp/grafana-clickhouse-datasource.zip
RUN apk add --no-cache --upgrade libcrypto3=3.5.8-r0 libssl3=3.5.8-r0 \
    && mkdir -p /usr/share/grafana/cdnfoundry-plugins \
    && unzip -q /tmp/grafana-clickhouse-datasource.zip -d /usr/share/grafana/cdnfoundry-plugins \
    && rm /tmp/grafana-clickhouse-datasource.zip \
    && find /usr/share/grafana/cdnfoundry-plugins -type d -exec chmod 0755 {} + \
    && find /usr/share/grafana/cdnfoundry-plugins -type f -exec chmod 0644 {} + \
    && chmod 0755 /usr/share/grafana/cdnfoundry-plugins/grafana-clickhouse-datasource/gpx_clickhouse_linux_amd64
USER 472
