FROM golang:1.26.8-alpine@sha256:ce864e7223ac17b1775e6fd0b4c0db580c2eb50e7953a427916379e4b92a1628 AS build
RUN apk add --no-cache git curl bash gzip
RUN git clone --filter=blob:none --no-checkout https://github.com/prometheus/alertmanager.git /src \
    && git -C /src checkout --detach 73c6bfe7393929211294c1954f30d8ed78e4d0ad
WORKDIR /src
COPY upstream.go.mod ./go.mod
COPY upstream.go.sum ./go.sum
RUN curl --fail --retry 5 --retry-all-errors --connect-timeout 20 -L https://github.com/prometheus/alertmanager/releases/download/v0.34.1/alertmanager-web-ui-0.34.1.tar.gz -o /assets.tar.gz \
    && echo '881fe0f7beb9573f35be47dd233af6220e72c8db72c03bcd1780402eb8dc1307  /assets.tar.gz' | sha256sum -c - \
    && tar -xf /assets.tar.gz -C ui/app
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    go mod download && go mod verify \
    && CGO_ENABLED=0 GOMAXPROCS=2 GOMEMLIMIT=1GiB go build -p=2 -mod=readonly -trimpath -tags=netgo,builtinassets \
       -ldflags='-s -w -X github.com/prometheus/common/version.Version=0.34.1 -X github.com/prometheus/common/version.Revision=73c6bfe7393929211294c1954f30d8ed78e4d0ad' -o /out/alertmanager ./cmd/alertmanager \
    && CGO_ENABLED=0 GOMAXPROCS=2 GOMEMLIMIT=1GiB go build -p=2 -mod=readonly -trimpath -tags=netgo,builtinassets \
       -ldflags='-s -w -X github.com/prometheus/common/version.Version=0.34.1 -X github.com/prometheus/common/version.Revision=73c6bfe7393929211294c1954f30d8ed78e4d0ad' -o /out/amtool ./cmd/amtool

FROM prom/alertmanager:v0.34.1@sha256:e9733bafb1bdef9b00e25a21f8f99dc26a22224bf16641ad754d1649f4c3357a
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry patched alertmanager runtime" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
COPY --from=build /out/alertmanager /bin/alertmanager
COPY --from=build /out/amtool /bin/amtool
