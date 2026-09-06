# Digest-pinned base (AP8): the tag documents the intended line, the digest
# makes the base of every rebuild byte-identical. 1.31 is nginx mainline: the
# 1.27 tag stopped being rebuilt (37 fixable CVEs frozen in), and even the
# stable 1.28 tag lagged behind on Alpine package fixes.
FROM nginx:1.31-alpine@sha256:72ba65eb42c10344912a84ff42408db7d34f2feb642204570ab8fc5ffd29f1d3

# The digest fixes the upstream filesystem; consume the relevant Alpine
# security updates during the controlled rebuild.
#
# `libuuid` joined in 2026-09 and is the reason a digest bump alone was not the
# answer: the current published build of this tag still ships util-linux
# 2.42.1-r0 while the seven fixable Critical/High findings (CVE-2026-53612,
# -53613, -53614, -76642, -78408, -78409, -78410) need 2.42.3-r1. Measured on
# both digests before and after. An exception in `.trivyignore.yaml` would have
# been wrong for the same reason the file itself gives: exceptions are for
# findings with no available fix, and this one has one.
RUN apk add --no-cache --upgrade libcrypto3 libssl3 libexpat libuuid

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
