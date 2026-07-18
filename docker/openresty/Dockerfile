FROM openresty/openresty:1.31.1.1-0-alpine
LABEL org.opencontainers.image.source="https://github.com/vaheed/CDNFoundry"

COPY docker/nginx/openresty.conf /usr/local/openresty/nginx/conf/nginx.conf
COPY docker/nginx/edge-runtime.conf /etc/nginx/conf.d/default.conf
COPY docker/openresty/runtime.lua /etc/cdnfoundry/runtime.lua
