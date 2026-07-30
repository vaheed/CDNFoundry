ARG GRAFANA_IMAGE=grafana/grafana:12.3.0@sha256:70d9599b186ce287be0d2c5ba9a78acb2e86c1a68c9c41449454d0fc3eeb84e8
FROM ${GRAFANA_IMAGE}

ARG CLICKHOUSE_PLUGIN_VERSION=4.8.2
ARG CLICKHOUSE_PLUGIN_SHA256=81e824a64b3b2881ca506de798dcc4ba56e11f5f1e3acc7c01faf4b69ac71649
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
