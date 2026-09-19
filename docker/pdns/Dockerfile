FROM alpine:3.24@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6 AS build
RUN apk add --no-cache build-base curl curl-dev pkgconf boost-dev libpq-dev luajit-dev openssl-dev libsodium-dev libmaxminddb-dev yaml-cpp-dev
WORKDIR /src
RUN curl --fail --retry 5 --retry-all-errors --connect-timeout 20 -L https://downloads.powerdns.com/releases/pdns-5.1.4.tar.bz2 -o source.tar \
    && echo 'f8a10edbf60e49d8c160e93121989d5ebcdad838d0e0b747f26ef7e89fd220c0  source.tar' | sha256sum -c - \
    && tar -xf source.tar --strip-components=1
RUN ./configure --prefix=/usr --sysconfdir=/etc/powerdns --libdir=/usr/lib/pdns --with-modules= --with-dynmodules="gpgsql geoip" --enable-tools --disable-static --with-libcrypto=/usr \
    && make -j2 && make DESTDIR=/output install

FROM alpine:3.24@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry patched pdns runtime" \
      org.opencontainers.image.licenses="GPL-2.0-only" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
RUN apk add --no-cache ca-certificates libstdc++ tini libcurl libpq luajit libcrypto3 libsodium libmaxminddb yaml-cpp \
    && addgroup -S -g 953 pdns && adduser -S -D -H -u 953 -G pdns pdns \
    && mkdir -p /var/run/pdns /etc/powerdns /etc/dnsdist \
    && chown pdns:pdns /var/run/pdns
COPY --from=build /output/usr/ /usr/
USER pdns
ENTRYPOINT ["/sbin/tini", "--", "/usr/sbin/pdns_server"]
CMD ["--disable-syslog", "--daemon=no", "--guardian=no"]
