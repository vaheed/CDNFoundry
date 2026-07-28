FROM openresty/openresty:1.31.1.1-0-alpine
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry"

RUN apk add --no-cache lua5.1-maxminddb \
    && addgroup -S -g 10101 cdnf && adduser -S -D -H -u 10101 -G cdnf cdnf \
    && mkdir -p /var/cache/nginx/content/small /var/cache/nginx/content/standard /var/cache/nginx/content/large /var/cache/nginx/content/streaming /var/lib/cdnfoundry/runtime /var/lib/nginx/tmp/client_body /var/lib/nginx/tmp/proxy \
    && chown -R cdnf:cdnf /var/cache/nginx /var/lib/cdnfoundry/runtime /var/lib/nginx/tmp

COPY docker/nginx/openresty.conf /usr/local/openresty/nginx/conf/nginx.conf
COPY docker/nginx/edge-runtime.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/proxy-cache.conf /etc/nginx/proxy-cache.conf
COPY docker/nginx/cache-upstream.conf /etc/nginx/cache-upstream.conf
COPY docker/nginx/origin-proxy.conf /etc/nginx/origin-proxy.conf
COPY docker/openresty/runtime.lua /etc/cdnfoundry/runtime.lua
COPY --chmod=755 docker/openresty/entrypoint.sh /usr/local/bin/cdnfoundry-edge-entrypoint

ENTRYPOINT ["/usr/local/bin/cdnfoundry-edge-entrypoint"]
CMD ["/usr/local/openresty/bin/openresty", "-g", "daemon off;"]
