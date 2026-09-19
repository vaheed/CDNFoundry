FROM golang:1.26.8-alpine@sha256:ce864e7223ac17b1775e6fd0b4c0db580c2eb50e7953a427916379e4b92a1628 AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download && go mod verify
COPY main.go ./
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    CGO_ENABLED=0 GOMAXPROCS=2 go build -mod=readonly -trimpath -ldflags='-s -w' -o /caddy .

FROM alpine:3.22.6@sha256:5291449c3df73caf6ed85e649dec1b9e818b39a5d8c871e97afc13e9cd5e8fa8
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry Caddy ingress with patched upstream dependencies" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
RUN apk add --no-cache ca-certificates tzdata
COPY --from=build /caddy /usr/bin/caddy
ENV XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data
WORKDIR /srv
CMD ["caddy", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
