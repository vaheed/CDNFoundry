FROM alpine:3.23@sha256:fd791d74b68913cbb027c6546007b3f0d3bc45125f797758156952bc2d6daf40
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry bounded MMDB updater" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"

RUN apk add --no-cache bash coreutils curl file gzip libmaxminddb tar unzip

COPY entrypoint.sh /usr/local/bin/mmdb-updater
RUN chmod 0755 /usr/local/bin/mmdb-updater

ENTRYPOINT ["/usr/local/bin/mmdb-updater"]
