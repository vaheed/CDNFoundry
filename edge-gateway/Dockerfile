FROM golang:1.27.4-alpine@sha256:70bd7ddea8b6cbe5d6943cde279d6b67feb10cc924fb9100d1d8757c1575b280 AS build
ENV GOMAXPROCS=2 GOFLAGS=-p=2
WORKDIR /src
COPY go.mod *.go ./
ARG RELEASE_VERSION=development
RUN CGO_ENABLED=0 go test ./... && \
    VERSION_FLAG=$(printf '%s' "${RELEASE_VERSION#v}" | grep -E '^[0-9]+[.][0-9]+[.][0-9]+$' | sed 's/^/-X main.version=/' || true) && \
    CGO_ENABLED=0 go build -trimpath -ldflags="-s -w ${VERSION_FLAG}" -o /edge-gateway .

FROM alpine:3.24@sha256:f59abe656b692d901e184be3445e084786602108599d2a51d229c0f5a48c71bf
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry edge gateway" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
RUN apk add --no-cache --upgrade 'libcrypto3>=3.5.8-r0' 'libssl3>=3.5.8-r0' 'musl-utils>=1.2.6-r3' 'zlib>=1.3.2-r1'
RUN addgroup -S -g 10101 gateway && adduser -S -D -H -u 10101 -G gateway gateway
COPY --from=build /edge-gateway /usr/local/bin/edge-gateway
RUN apk add --no-cache libcap \
    && setcap cap_net_bind_service=+ep /usr/local/bin/edge-gateway \
    && apk del libcap
USER gateway
ENTRYPOINT ["edge-gateway"]
