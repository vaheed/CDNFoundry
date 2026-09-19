FROM golang:1.26.8-alpine@sha256:ce864e7223ac17b1775e6fd0b4c0db580c2eb50e7953a427916379e4b92a1628 AS build
RUN apk add --no-cache git curl bash gzip
RUN git clone --filter=blob:none --no-checkout https://github.com/prometheus/node_exporter.git /src \
    && git -C /src checkout --detach 6044da783597cc3b57aef7580ddcdcff58a4ee99
WORKDIR /src
COPY upstream.go.mod ./go.mod
COPY upstream.go.sum ./go.sum
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    go mod download && go mod verify \
    && CGO_ENABLED=0 GOMAXPROCS=2 GOMEMLIMIT=1GiB go build -p=2 -mod=readonly -trimpath -tags=netgo,builtinassets \
       -ldflags='-s -w -X github.com/prometheus/common/version.Version=1.12.1 -X github.com/prometheus/common/version.Revision=6044da783597cc3b57aef7580ddcdcff58a4ee99' -o /out/node_exporter .

FROM prom/node-exporter:v1.12.1@sha256:1b4e4438faca4dd7e001dd445d161a4a2091b0fededa84093b3a8dfeae1f1be0
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry patched node-exporter runtime" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
COPY --from=build /out/node_exporter /bin/node_exporter
