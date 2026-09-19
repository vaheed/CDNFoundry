FROM alpine:3.24@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6 AS build
RUN apk add --no-cache build-base curl pkgconf py3-yaml boost-dev luajit-dev openssl-dev libsodium-dev libedit-dev fstrm-dev re2-dev
WORKDIR /src
RUN curl --fail --retry 5 --retry-all-errors --connect-timeout 20 -L https://downloads.powerdns.com/releases/dnsdist-2.1.2.tar.xz -o source.tar \
    && echo '9fcb469d7a1b5116606f2563761343d1c595523c1fd67808835fa4edc03c24ce  source.tar' | sha256sum -c - \
    && tar -xf source.tar --strip-components=1
RUN ./configure --prefix=/usr --sysconfdir=/etc/dnsdist --with-lua=luajit --with-re2 --disable-yaml \
    && make -j2 && make DESTDIR=/output install

FROM alpine:3.24@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry patched dnsdist runtime" \
      org.opencontainers.image.licenses="GPL-2.0-only" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"
RUN apk add --no-cache ca-certificates libstdc++ tini luajit libcrypto3 libssl3 libsodium libedit fstrm re2 abseil-cpp-vlog-config-internal python3 \
    && addgroup -S -g 953 pdns && adduser -S -D -H -u 953 -G pdns pdns \
    && mkdir -p /var/run/pdns /etc/powerdns /etc/dnsdist \
    && chown pdns:pdns /var/run/pdns
COPY --from=build /output/usr/ /usr/
USER pdns
ENTRYPOINT ["/sbin/tini", "--", "/usr/bin/dnsdist"]
CMD ["--supervised", "--disable-syslog"]
