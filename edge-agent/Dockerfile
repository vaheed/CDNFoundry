FROM golang:1.27.1-alpine@sha256:8a5910f31396cd4d89662f56c68b3ae31d374308270a1c3bd96672ee5ed43414 AS build
ENV GOMAXPROCS=2 GOFLAGS=-p=2
WORKDIR /src
COPY go.mod ./
COPY . ./
RUN CGO_ENABLED=0 go test ./... && CGO_ENABLED=0 go build -trimpath -ldflags='-s -w' -o /edge-agent ./

FROM alpine:3.24@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry edge agent" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
RUN apk add --no-cache --upgrade libcrypto3=3.5.8-r0 libssl3=3.5.8-r0
RUN addgroup -S -g 10101 cdnf && adduser -S -D -H -u 10101 -G cdnf cdnf
RUN mkdir -p /var/lib/cdnfoundry/agent /var/lib/cdnfoundry/runtime && chown -R cdnf:cdnf /var/lib/cdnfoundry
COPY --from=build /edge-agent /usr/local/bin/edge-agent
USER cdnf
ENTRYPOINT ["/usr/local/bin/edge-agent"]
