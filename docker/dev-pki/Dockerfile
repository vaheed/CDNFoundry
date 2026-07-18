FROM alpine:3.22

RUN apk add --no-cache openssl

COPY scripts/generate-production-certificates.sh /usr/local/bin/generate-production-certificates
COPY scripts/ensure-development-certificates.sh /usr/local/bin/ensure-development-certificates
RUN chmod 0755 /usr/local/bin/generate-production-certificates /usr/local/bin/ensure-development-certificates

ENTRYPOINT ["/usr/local/bin/ensure-development-certificates"]
