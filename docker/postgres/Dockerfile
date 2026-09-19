FROM golang:1.26.8-alpine@sha256:ce864e7223ac17b1775e6fd0b4c0db580c2eb50e7953a427916379e4b92a1628 AS build
RUN apk add --no-cache git
RUN git clone https://github.com/tianon/gosu.git /src \
    && git -C /src checkout --detach 6456aaa0f3c854d199d0f037f068eb97515b7513
WORKDIR /src
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    go mod download && go mod verify \
    && CGO_ENABLED=0 GOMAXPROCS=2 go build -mod=readonly -trimpath -ldflags='-s -w' -o /gosu .

FROM postgres:18.6-alpine3.24@sha256:6c538e7206ea40ff740ef27883529390a690b6ead6ba96b44c67a9f7c638e8fd
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry PostgreSQL with patched gosu toolchain" \
      org.opencontainers.image.licenses="PostgreSQL" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
COPY --from=build /gosu /usr/local/bin/gosu
RUN gosu nobody true
