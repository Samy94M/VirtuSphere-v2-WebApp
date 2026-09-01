# Digest-pinned base (AP8): the tag documents the intended line, the digest
# makes the base of every rebuild byte-identical. 1.31 is nginx mainline: the
# 1.27 tag stopped being rebuilt (37 fixable CVEs frozen in), and even the
# stable 1.28 tag lagged behind on Alpine package fixes.
FROM nginx:1.31-alpine@sha256:db35bfc6b2951e7f8a72db5db120288c127ffaeeb4a6d4b95a26fead017d5913

# The digest fixes the upstream filesystem; consume the relevant Alpine
# security updates during the controlled rebuild.
RUN apk add --no-cache --upgrade libcrypto3 libssl3 libexpat

COPY ./default.conf /etc/nginx/conf.d/default.conf

# Keep this identical to Docker/php/Dockerfile. Named shared volumes inherit
# the mount-point metadata from the first image that initializes them.
RUN mkdir -p /etc/nginx/ssl /etc/nginx/virtusphere-conf.d \
    && chown 33:0 /etc/nginx/ssl /etc/nginx/virtusphere-conf.d \
    && chmod 0770 /etc/nginx/ssl /etc/nginx/virtusphere-conf.d

COPY init.sh /init.sh
RUN chmod +x /init.sh

ENTRYPOINT ["/init.sh"]

EXPOSE 8080 8443
