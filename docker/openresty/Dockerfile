FROM openresty/openresty:1.31.1.1-0-alpine AS brotli-builder

ARG NGX_BROTLI_COMMIT=a71f9312c2deb28875acc7bacfdd5695a111aa53
RUN apk add --no-cache build-base cmake git linux-headers openssl-dev pcre2-dev perl zlib-dev \
    && git clone https://github.com/google/ngx_brotli.git /tmp/ngx_brotli \
    && git -C /tmp/ngx_brotli checkout "${NGX_BROTLI_COMMIT}" \
    && git -C /tmp/ngx_brotli submodule update --init --recursive \
    && cmake -S /tmp/ngx_brotli/deps/brotli -B /tmp/ngx_brotli/deps/brotli/out -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF \
    && cmake --build /tmp/ngx_brotli/deps/brotli/out --config Release --target brotlienc \
    && wget -q "https://openresty.org/download/openresty-1.31.1.1.tar.gz" -O /tmp/openresty.tar.gz \
    && tar -xzf /tmp/openresty.tar.gz -C /tmp \
    && cd /tmp/openresty-1.31.1.1/bundle/nginx-1.31.1 \
    && ./configure --with-compat --add-dynamic-module=/tmp/ngx_brotli \
    && make modules

FROM openresty/openresty:1.31.1.1-0-alpine
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry"

RUN apk add --no-cache lua5.1-maxminddb \
    && addgroup -S -g 10101 cdnf && adduser -S -D -H -u 10101 -G cdnf cdnf \
    && mkdir -p /var/cache/nginx/content/small /var/cache/nginx/content/standard /var/cache/nginx/content/large /var/cache/nginx/content/streaming /var/lib/cdnfoundry/runtime /var/lib/nginx/tmp/client_body /var/lib/nginx/tmp/proxy \
    && chown -R cdnf:cdnf /var/cache/nginx /var/lib/cdnfoundry/runtime /var/lib/nginx/tmp

COPY docker/nginx/openresty.conf /usr/local/openresty/nginx/conf/nginx.conf
COPY --from=brotli-builder /tmp/openresty-1.31.1.1/bundle/nginx-1.31.1/objs/ngx_http_brotli_filter_module.so /usr/local/openresty/nginx/modules/
COPY --from=brotli-builder /tmp/openresty-1.31.1.1/bundle/nginx-1.31.1/objs/ngx_http_brotli_static_module.so /usr/local/openresty/nginx/modules/
COPY docker/nginx/edge-runtime.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/proxy-cache.conf /etc/nginx/proxy-cache.conf
COPY docker/nginx/cache-upstream.conf /etc/nginx/cache-upstream.conf
COPY docker/nginx/origin-proxy.conf /etc/nginx/origin-proxy.conf
COPY docker/openresty/runtime.lua /etc/cdnfoundry/runtime.lua
COPY --chmod=755 docker/openresty/entrypoint.sh /usr/local/bin/cdnfoundry-edge-entrypoint

ENTRYPOINT ["/usr/local/bin/cdnfoundry-edge-entrypoint"]
CMD ["/usr/local/openresty/bin/openresty", "-g", "daemon off;"]
