ARG GRAFANA_IMAGE=grafana/grafana:12.4.11@sha256:3ea272e5cab64a4a62240c682e2c62433b25614d956d44c299a10cb6994f6f2e
FROM golang:1.27.0-alpine@sha256:4c9fe60190a2a3350ddc51de80d0224b8a6698d12bdfc999fee45ea9d6c46dbc AS clickhouse-plugin
RUN apk add --no-cache git
RUN git clone --filter=blob:none --no-checkout https://github.com/grafana/clickhouse-datasource.git /src \
    && git -C /src checkout --detach 551f9c4e32359f3844f659ce7ffd51fe7df77a07
WORKDIR /src
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    go mod download && go mod verify \
    && CGO_ENABLED=0 GOMAXPROCS=2 GOMEMLIMIT=1GiB go build -p=2 -mod=readonly -trimpath -ldflags='-s -w' -o /plugin ./pkg

FROM golang:1.27.0-alpine@sha256:4c9fe60190a2a3350ddc51de80d0224b8a6698d12bdfc999fee45ea9d6c46dbc AS backend
RUN apk add --no-cache git patch
WORKDIR /src
ADD --checksum=sha256:07622e9c2b67eded2a9c2ad52d8d8e26cb6f17e594152046f447302aed975ef1 \
    https://github.com/grafana/grafana/archive/05757e789657299d00314f8f96d49d1aca569f33.tar.gz /tmp/grafana.tar.gz
RUN tar -xzf /tmp/grafana.tar.gz --strip-components=1 -C /src && rm /tmp/grafana.tar.gz
COPY dependencies.patch /tmp/dependencies.patch
RUN patch --batch --forward -p1 < /tmp/dependencies.patch
ENV CGO_ENABLED=0 GOMAXPROCS=2 GOMEMLIMIT=2GiB GOFLAGS=-p=2
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    go build -mod=readonly -buildvcs=false -trimpath -tags=oss \
       -ldflags='-s -w -X main.version=12.4.11+cdnfoundry -X main.commit=05757e789657299d00314f8f96d49d1aca569f33 -X main.buildBranch=cdnfoundry -X main.buildstamp=1789487640' \
       -o /out/grafana ./pkg/cmd/grafana \
    && go mod verify

FROM ${GRAFANA_IMAGE}

ARG CLICKHOUSE_PLUGIN_VERSION=4.21.3
ARG CLICKHOUSE_PLUGIN_SHA256=53c0602214e7ca1af1733962779293587386b0b9a8c544ecead80b819e5f560b
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry Grafana observability" \
      org.opencontainers.image.licenses="AGPL-3.0-only" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
USER root
COPY --from=backend --chmod=0755 /out/grafana /usr/share/grafana/bin/grafana
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
COPY --from=clickhouse-plugin --chmod=0755 /plugin /usr/share/grafana/cdnfoundry-plugins/grafana-clickhouse-datasource/gpx_clickhouse_linux_amd64
# Owner-approved: this one rebuilt plugin is protected by the signed release image.
RUN rm /usr/share/grafana/cdnfoundry-plugins/grafana-clickhouse-datasource/MANIFEST.txt
ENV GF_PLUGINS_ALLOW_LOADING_UNSIGNED_PLUGINS=grafana-clickhouse-datasource
USER 472
