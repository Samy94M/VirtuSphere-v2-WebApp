# Digest-pinned base (AP8): the tag documents the intended line, the digest
# makes the base of every rebuild byte-identical. 1.31 is nginx mainline: the
# 1.27 tag stopped being rebuilt (37 fixable CVEs frozen in), and even the
# stable 1.28 tag lagged behind on Alpine package fixes.
#
# trivy reads Alpine packages, not the nginx binary itself, so a new nginx
# release with security fixes reaches this image only through a digest bump:
# check https://nginx.org/en/security_advisories.html against NGINX_VERSION.
# 2026-10-25: 1.31.5 to 1.31.8. 1.31.7 fixed, among others, CVE-2026-97629
# (session forgery with automatic TLS session ticket key rotation, which the
# generated HTTPS block enables through `ssl_session_cache shared:` without a
# ticket key file) and CVE-2026-93479 (overflow while evaluating strings with
# variables, as in our log format). Both applied to 1.31.5; with the fixed
# binary the ticket configuration stays as it is.
FROM nginx:1.31-alpine@sha256:a3554c0d0b48177b1413c26e01f695334428020d9df01243fbae3e11cfd17417

# The digest fixes the upstream filesystem; consume all Alpine security
# updates during the controlled rebuild.
#
# This was a named package list until 2026-10 and went stale twice: `libuuid`
# had to join in 2026-09, and on 2026-10-14 the release gate found pcre2 and
# tiff, which the list did not name. A digest bump was no answer either time:
# the newest published build of this tag carried the same three findings
# (libexpat, pcre2, tiff), measured on both digests. An exception in
# `.trivyignore.yaml` would have been wrong for the same reason the file itself
# gives: exceptions are for findings with no available fix.
RUN apk upgrade --no-cache

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
