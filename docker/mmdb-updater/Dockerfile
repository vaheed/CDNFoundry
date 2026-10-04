FROM alpine:3.24@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry bounded MMDB updater" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"

RUN apk add --no-cache --upgrade bash coreutils curl=8.22.0-r0 libcurl=8.22.0-r0 file gzip=1.14-r3 libmaxminddb tar unzip libcrypto3=3.5.8-r0 libssl3=3.5.8-r0

COPY entrypoint.sh /usr/local/bin/mmdb-updater
RUN chmod 0755 /usr/local/bin/mmdb-updater

ENTRYPOINT ["/usr/local/bin/mmdb-updater"]
