FROM golang:1.26.11-alpine@sha256:ae52b5bc6ed045a9ed26ab03dc19327a802a9cc9aa8b27ed57f5c6b60579b0bb AS compiler
WORKDIR /build
COPY compiler/go.mod compiler/go.sum ./
RUN go mod download
COPY compiler/*.go ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /mmdb-compiler .

FROM alpine:3.24@sha256:f59abe656b692d901e184be3445e084786602108599d2a51d229c0f5a48c71bf AS location-build
RUN apk add --no-cache --upgrade build-base pkgconf python3-dev openssl-dev gettext-dev \
    autoconf automake libtool intltool linux-headers
ADD --checksum=sha256:222260d6f900dcec736671011037904e25be615df870b16a3163829be9432de0 \
    https://source.ipfire.org/releases/libloc/libloc-0.9.20.tar.gz /tmp/libloc.tar.gz
RUN mkdir /build && tar -xzf /tmp/libloc.tar.gz -C /build --strip-components=1 \
    && cd /build && ./autogen.sh && ./configure --prefix=/usr --disable-perl --disable-lua \
       --disable-man-pages --with-systemd=no --disable-static \
    && make -j2 && make DESTDIR=/out install

FROM alpine:3.24@sha256:f59abe656b692d901e184be3445e084786602108599d2a51d229c0f5a48c71bf
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry bounded MMDB updater" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"

RUN apk add --no-cache --upgrade python3 bash ca-certificates coreutils \
    'curl>=8.22.0-r0' 'libcurl>=8.22.0-r0' 'libexpat>=2.10.0-r0' file \
    'gzip>=1.14-r3' libmaxminddb tar unzip xz \
    'libcrypto3>=3.5.8-r0' 'libssl3>=3.5.8-r0' 'musl-utils>=1.2.6-r3'
COPY --from=location-build /out/usr /usr
RUN rm -f /usr/var/lib/location/database.db /usr/etc/cron.daily/location-update \
    && python3 -c 'import location; assert location.__version__ == "0.9.20"'
COPY --from=compiler /mmdb-compiler /usr/local/bin/mmdb-compiler
COPY ipfire-export.py /usr/local/bin/ipfire-export
# Public verification key from the official libloc 0.9.20 release; changes require image review.
COPY ipfire-signing-key.pem /usr/local/share/ipfire-signing-key.pem

COPY entrypoint.sh /usr/local/bin/mmdb-updater
COPY build-fallback.py /tmp/build-fallback.py
# A database that finds nothing, so DNS can start on a host with no route to a GeoIP source.
RUN mkdir -p /opt/fallback && python3 /tmp/build-fallback.py /opt/fallback/GeoLite2-City.mmdb && cp /opt/fallback/GeoLite2-City.mmdb /opt/fallback/GeoLite2-ASN.mmdb && rm /tmp/build-fallback.py
RUN chmod 0755 /usr/local/bin/mmdb-updater

ENTRYPOINT ["/usr/local/bin/mmdb-updater"]
