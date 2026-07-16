FROM alpine:3.23

RUN apk add --no-cache bash coreutils curl file gzip libmaxminddb tar unzip

COPY entrypoint.sh /usr/local/bin/mmdb-updater
RUN chmod 0755 /usr/local/bin/mmdb-updater

ENTRYPOINT ["/usr/local/bin/mmdb-updater"]
