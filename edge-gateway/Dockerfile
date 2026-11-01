FROM golang:1.27.3-alpine@sha256:d200d2dd76b03a99b52651fd1325aff7dcd35422c35c01bac56571b0507f3deb AS build
ENV GOMAXPROCS=2 GOFLAGS=-p=2
WORKDIR /src
COPY go.mod main.go main_test.go scale_test.go ./
ARG RELEASE_VERSION=development
RUN CGO_ENABLED=0 go test ./... && \
    VERSION_FLAG=$(printf '%s' "${RELEASE_VERSION#v}" | grep -E '^[0-9]+[.][0-9]+[.][0-9]+$' | sed 's/^/-X main.version=/' || true) && \
    CGO_ENABLED=0 go build -trimpath -ldflags="-s -w ${VERSION_FLAG}" -o /edge-gateway .

FROM alpine:3.24@sha256:be2656061b536c1883c4034790e21d0a09c5aaaf51968b89368a3c0371ea2c9a
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry edge gateway" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
RUN apk add --no-cache --upgrade 'libcrypto3>=3.5.8-r0' 'libssl3>=3.5.8-r0'
RUN addgroup -S -g 10101 gateway && adduser -S -D -H -u 10101 -G gateway gateway
COPY --from=build /edge-gateway /usr/local/bin/edge-gateway
RUN apk add --no-cache libcap \
    && setcap cap_net_bind_service=+ep /usr/local/bin/edge-gateway \
    && apk del libcap
USER gateway
ENTRYPOINT ["edge-gateway"]
