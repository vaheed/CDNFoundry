FROM golang:1.26.11-alpine@sha256:ae52b5bc6ed045a9ed26ab03dc19327a802a9cc9aa8b27ed57f5c6b60579b0bb AS compiler
WORKDIR /build
COPY compiler/go.mod compiler/go.sum ./
RUN go mod download
COPY compiler/*.go ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /mmdb-compiler .

FROM debian:trixie-slim@sha256:8aa5a7bf5a1955bddbe7d74fcdd0b91ab30e74362a8b73af672b0481b35b44a0 AS location-build
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential pkg-config python3-dev libssl-dev gettext ca-certificates \
    && rm -rf /var/lib/apt/lists/*
RUN apt-get update && apt-get install -y --no-install-recommends autoconf automake libtool intltool autopoint \
    && rm -rf /var/lib/apt/lists/*
ADD --checksum=sha256:222260d6f900dcec736671011037904e25be615df870b16a3163829be9432de0 \
    https://source.ipfire.org/releases/libloc/libloc-0.9.20.tar.gz /tmp/libloc.tar.gz
RUN mkdir /build && tar -xzf /tmp/libloc.tar.gz -C /build --strip-components=1 \
    && cd /build && ./autogen.sh && ./configure --prefix=/usr/local --disable-perl --disable-lua \
       --disable-man-pages --with-systemd=no --disable-static \
       pythondir=/usr/local/lib/python3.13/dist-packages pyexecdir=/usr/local/lib/python3.13/dist-packages \
    && make -j2 && make DESTDIR=/out install

FROM debian:trixie-slim@sha256:8aa5a7bf5a1955bddbe7d74fcdd0b91ab30e74362a8b73af672b0481b35b44a0
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry bounded MMDB updater" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"

RUN apt-get update && apt-get install -y --no-install-recommends \
    bash ca-certificates coreutils curl file gzip mmdb-bin python3 libssl3t64 tar unzip xz-utils \
    && rm -rf /var/lib/apt/lists/*
COPY --from=location-build /out/usr/local /usr/local
RUN mkdir -p /usr/local/lib/python3.13/dist-packages \
    && mv /usr/local/lib/python3.13/site-packages/* /usr/local/lib/python3.13/dist-packages/ \
    && rmdir /usr/local/lib/python3.13/site-packages \
    && rm -f /usr/local/var/lib/location/database.db /usr/local/etc/cron.daily/location-update \
    && ldconfig && python3 -c 'import location; assert location.__version__ == "0.9.20"'
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
