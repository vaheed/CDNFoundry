FROM openresty/openresty:1.31.1.1-0-alpine@sha256:23520ed7708e21bbc49229eae9bb1baf4ed915a262f84a9235ebf47fbf9cc3ac AS brotli-builder

ARG NGX_BROTLI_COMMIT=a71f9312c2deb28875acc7bacfdd5695a111aa53
ARG MODSECURITY_COMMIT=a555e5a44573e50f04ad997b3b24c214c42f8e29
ARG MODSECURITY_NGINX_VERSION=v1.0.4
ARG OWASP_CRS_VERSION=v4.26.0
RUN apk add --no-cache autoconf automake build-base cmake curl-dev git libtool linux-headers lmdb-dev libxml2-dev openssl-dev pcre-dev pcre2-dev perl yajl-dev zlib-dev
RUN git clone https://github.com/google/ngx_brotli.git /tmp/ngx_brotli \
    && git -C /tmp/ngx_brotli checkout "${NGX_BROTLI_COMMIT}" \
    && git -C /tmp/ngx_brotli submodule update --init --recursive \
    && cmake -S /tmp/ngx_brotli/deps/brotli -B /tmp/ngx_brotli/deps/brotli/out -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF \
    && cmake --build /tmp/ngx_brotli/deps/brotli/out --config Release --target brotlienc
RUN git clone https://github.com/owasp-modsecurity/ModSecurity.git /tmp/ModSecurity \
    && git -C /tmp/ModSecurity checkout "${MODSECURITY_COMMIT}" \
    && git -C /tmp/ModSecurity submodule update --init --depth 1 others/libinjection \
    && git -C /tmp/ModSecurity submodule update --init --recursive --depth 1 others/mbedtls \
    && sed -i '1i#ifdef __cplusplus\n#include <cstdint>\n#endif' /tmp/ModSecurity/headers/modsecurity/collection/collection.h \
    && cd /tmp/ModSecurity \
    && ./build.sh \
    && ./configure --disable-doxygen-doc --disable-examples \
    && make -j2 \
    && make install

FROM brotli-builder AS module-builder
ARG MODSECURITY_NGINX_COMMIT=3f4b57df10ce43b1f1c722141f7621dc64838be8
ARG OWASP_CRS_COMMIT=955649c1221633cc3ea63674904e94fbc5fb6356
ARG OPENRESTY_SHA256=65b78baadd3f0984055de89bf13f4a1932e5bfe9c31932037a134ea2b1a0ce42
RUN git clone https://github.com/owasp-modsecurity/ModSecurity-nginx.git /tmp/ModSecurity-nginx \
    && git -C /tmp/ModSecurity-nginx checkout "${MODSECURITY_NGINX_COMMIT}" \
    && git clone https://github.com/coreruleset/coreruleset.git /tmp/coreruleset \
    && git -C /tmp/coreruleset checkout "${OWASP_CRS_COMMIT}" \
    && wget -q "https://openresty.org/download/openresty-1.31.1.1.tar.gz" -O /tmp/openresty.tar.gz \
    && echo "${OPENRESTY_SHA256}  /tmp/openresty.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/openresty.tar.gz -C /tmp \
    && cd /tmp/openresty-1.31.1.1/bundle/nginx-1.31.1 \
    && LD_LIBRARY_PATH=/usr/local/modsecurity/lib MODSECURITY_INC=/usr/local/modsecurity/include MODSECURITY_LIB=/usr/local/modsecurity/lib \
       ./configure --with-compat --add-dynamic-module=/tmp/ngx_brotli --add-dynamic-module=/tmp/ModSecurity-nginx \
    && make modules

FROM openresty/openresty:1.31.1.1-0-alpine@sha256:23520ed7708e21bbc49229eae9bb1baf4ed915a262f84a9235ebf47fbf9cc3ac
ARG SOURCE_REVISION=development RELEASE_VERSION=development BUILD_DATE=1970-01-01T00:00:00Z
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry" \
      org.opencontainers.image.revision="${SOURCE_REVISION}" org.opencontainers.image.version="${RELEASE_VERSION}" \
      org.opencontainers.image.created="${BUILD_DATE}" org.opencontainers.image.description="CDNFoundry OpenResty edge runtime" \
      org.opencontainers.image.licenses="Apache-2.0" org.opencontainers.image.documentation="https://vaheed.github.io/CDNFoundry/"

RUN apk add --no-cache libcurl libgcc libstdc++ lmdb lua5.1-maxminddb libxml2 pcre yajl \
    && addgroup -S -g 10101 cdnf && adduser -S -D -H -u 10101 -G cdnf cdnf \
    && mkdir -p /var/cache/nginx/content/small /var/cache/nginx/content/standard /var/cache/nginx/content/large /var/cache/nginx/content/streaming /var/lib/cdnfoundry/runtime /var/lib/nginx/tmp/client_body /var/lib/nginx/tmp/proxy \
    && chown -R cdnf:cdnf /var/cache/nginx /var/lib/cdnfoundry/runtime /var/lib/nginx/tmp

COPY docker/nginx/openresty.conf /usr/local/openresty/nginx/conf/nginx.conf
COPY --from=module-builder /tmp/openresty-1.31.1.1/bundle/nginx-1.31.1/objs/ngx_http_brotli_filter_module.so /usr/local/openresty/nginx/modules/
COPY --from=module-builder /tmp/openresty-1.31.1.1/bundle/nginx-1.31.1/objs/ngx_http_brotli_static_module.so /usr/local/openresty/nginx/modules/
COPY --from=module-builder /tmp/openresty-1.31.1.1/bundle/nginx-1.31.1/objs/ngx_http_modsecurity_module.so /usr/local/openresty/nginx/modules/
COPY --from=module-builder /usr/local/modsecurity/lib/libmodsecurity.so* /usr/lib/
COPY --from=module-builder /tmp/ModSecurity/modsecurity.conf-recommended /etc/cdnfoundry/modsecurity.conf
COPY --from=module-builder /tmp/ModSecurity/unicode.mapping /etc/cdnfoundry/unicode.mapping
COPY --from=module-builder /tmp/coreruleset/crs-setup.conf.example /etc/cdnfoundry/crs-setup.conf
COPY --from=module-builder /tmp/coreruleset/rules /etc/cdnfoundry/crs/rules
COPY docker/openresty/waf.conf /etc/cdnfoundry/waf.conf
RUN sed -i 's/^SecRuleEngine .*/SecRuleEngine On/' /etc/cdnfoundry/modsecurity.conf \
    && sed -i 's/^SecAuditEngine .*/SecAuditEngine RelevantOnly/' /etc/cdnfoundry/modsecurity.conf \
    && sed -i 's/^SecAuditLogParts .*/SecAuditLogParts AHZ/' /etc/cdnfoundry/modsecurity.conf \
    && sed -i 's/^SecAuditLogType .*/SecAuditLogType Serial/' /etc/cdnfoundry/modsecurity.conf \
    && sed -i 's#^SecAuditLog .*#SecAuditLog /dev/stderr#' /etc/cdnfoundry/modsecurity.conf \
    && sed -i 's/^SecDebugLogLevel .*/SecDebugLogLevel 0/' /etc/cdnfoundry/modsecurity.conf \
    && sed -i 's#^SecUnicodeMapFile .*#SecUnicodeMapFile /etc/cdnfoundry/unicode.mapping 20127#' /etc/cdnfoundry/modsecurity.conf \
    && find /etc/cdnfoundry/crs/rules -type f -name '*.conf' -exec sed -i -E \
       -e 's/phase:([1-5]),/phase:\1,nolog,/g' \
       -e 's/^([[:space:]]*)log,\\$/\1nolog,\\/' {} +
COPY docker/nginx/edge-runtime.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/proxy-cache.conf /etc/nginx/proxy-cache.conf
COPY docker/nginx/cache-upstream.conf /etc/nginx/cache-upstream.conf
COPY docker/nginx/origin-proxy.conf /etc/nginx/origin-proxy.conf
COPY docker/openresty/runtime.lua /etc/cdnfoundry/runtime.lua
COPY --chmod=755 docker/openresty/entrypoint.sh /usr/local/bin/cdnfoundry-edge-entrypoint

ENTRYPOINT ["/usr/local/bin/cdnfoundry-edge-entrypoint"]
CMD ["/usr/local/openresty/bin/openresty", "-g", "daemon off;"]
