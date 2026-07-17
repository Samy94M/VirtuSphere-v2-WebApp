# Digest-pinned base (AP8): the tag documents the intended line, the digest
# makes the base of every rebuild byte-identical. 1.31 is nginx mainline: the
# 1.27 tag stopped being rebuilt (37 fixable CVEs frozen in), and even the
# stable 1.28 tag lagged behind on Alpine package fixes.
FROM nginx:1.31-alpine@sha256:7068961d45b07b2af510ac002e9daa63a1d3eba2111202d6768798690800fffd
COPY ./default.conf /etc/nginx/conf.d/default.conf

RUN mkdir -p /etc/nginx/ssl /etc/nginx/virtusphere-conf.d

COPY init.sh /init.sh
RUN chmod +x /init.sh

ENTRYPOINT ["/init.sh"]

EXPOSE 8080 8443