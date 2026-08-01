FROM alpine:3.22@sha256:14358309a308569c32bdc37e2e0e9694be33a9d99e68afb0f5ff33cc1f695dce

RUN apk add --no-cache openssl

COPY scripts/generate-production-certificates.sh /usr/local/bin/generate-production-certificates
COPY scripts/ensure-development-certificates.sh /usr/local/bin/ensure-development-certificates
RUN chmod 0755 /usr/local/bin/generate-production-certificates /usr/local/bin/ensure-development-certificates

ENTRYPOINT ["/usr/local/bin/ensure-development-certificates"]
