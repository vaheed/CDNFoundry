FROM golang:1.26.8-alpine@sha256:ce864e7223ac17b1775e6fd0b4c0db580c2eb50e7953a427916379e4b92a1628 AS build
RUN apk add --no-cache git curl bash gzip
RUN git clone --filter=blob:none --no-checkout https://github.com/prometheus/prometheus.git /src \
    && git -C /src checkout --detach b273ae3adeb64ad630d65ef7f16440df95658410
WORKDIR /src
COPY upstream.go.mod ./go.mod
COPY upstream.go.sum ./go.sum
RUN curl --fail --retry 5 --retry-all-errors --connect-timeout 20 -L https://github.com/prometheus/prometheus/releases/download/v3.13.3/prometheus-web-ui-3.13.3.tar.gz -o /assets.tar.gz \
    && echo '2647eb2e0b5dbc2bb16bbcec2cea78e4b8fc36f4564f6ce6569627b47f3432bd  /assets.tar.gz' | sha256sum -c - \
    && tar -xf /assets.tar.gz -C web/ui
RUN bash scripts/compress_assets.sh
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    go mod download && go mod verify \
    && CGO_ENABLED=0 GOMAXPROCS=2 GOMEMLIMIT=1GiB go build -p=2 -mod=readonly -trimpath -tags=netgo,builtinassets \
       -ldflags='-s -w -X github.com/prometheus/common/version.Version=3.13.3 -X github.com/prometheus/common/version.Revision=b273ae3adeb64ad630d65ef7f16440df95658410' -o /out/prometheus ./cmd/prometheus \
    && CGO_ENABLED=0 GOMAXPROCS=2 GOMEMLIMIT=1GiB go build -p=2 -mod=readonly -trimpath -tags=netgo,builtinassets \
       -ldflags='-s -w -X github.com/prometheus/common/version.Version=3.13.3 -X github.com/prometheus/common/version.Revision=b273ae3adeb64ad630d65ef7f16440df95658410' -o /out/promtool ./cmd/promtool

FROM prom/prometheus:v3.13.3@sha256:6976aa8a60fec930796ce5772b8d12da7a318a5daa8d40d69c5c7819a05eeed7
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry patched prometheus runtime" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
COPY --from=build /out/prometheus /bin/prometheus
COPY --from=build /out/promtool /bin/promtool
