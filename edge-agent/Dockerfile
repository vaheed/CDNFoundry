FROM golang:1.26.10-alpine@sha256:a2012b4a1d1f9f7e9f44e0d39b805a901e856b1dd80b4e6ee4338db547050308 AS build
ENV GOMAXPROCS=2 GOFLAGS=-p=2
WORKDIR /src
COPY go.mod ./
COPY . ./
ARG RELEASE_VERSION=development
RUN CGO_ENABLED=0 go test ./... && \
    VERSION_FLAG=$(printf '%s' "${RELEASE_VERSION#v}" | grep -E '^[0-9]+[.][0-9]+[.][0-9]+$' | sed 's/^/-X main.version=/' || true) && \
    CGO_ENABLED=0 go build -trimpath -ldflags="-s -w ${VERSION_FLAG}" -o /edge-agent ./

FROM alpine:3.24@sha256:28bd5fe8b56d1bd048e5babf5b10710ebe0bae67db86916198a6eec434943f8b
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry edge agent" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
RUN apk add --no-cache --upgrade 'libcrypto3>=3.5.8-r0' 'libssl3>=3.5.8-r0'
RUN addgroup -S -g 10101 cdnf && adduser -S -D -H -u 10101 -G cdnf cdnf
RUN mkdir -p /var/lib/cdnfoundry/agent /var/lib/cdnfoundry/runtime && chown -R cdnf:cdnf /var/lib/cdnfoundry
COPY --from=build /edge-agent /usr/local/bin/edge-agent
USER cdnf
ENTRYPOINT ["/usr/local/bin/edge-agent"]
