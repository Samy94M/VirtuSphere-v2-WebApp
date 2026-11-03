# Hermetic LDAPS test fixture (Plan section 18.3): a real slapd for the
# native PHP/ext-ldap path proof, plus a socat-based TLS/blackhole stub role
# for the certificate-rejection and timeout scenarios that never need a real
# LDAP protocol exchange. Test-only image, never shipped (docker-compose.qa.yml
# only). Digest-pinned base (AP8), consistent with the other Docker/*/Dockerfile.
FROM debian:bookworm-slim@sha256:abd67ffcfa541b485a3dff59865ab629aa048a6c613e639d36e7456b0b229241

RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    slapd \
    ldap-utils \
    socat \
    ca-certificates \
    procps \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /etc/ldap/slapd.d /var/lib/ldap

COPY schema/virtusphere-fixture.schema /etc/ldap/schema/virtusphere-fixture.schema
COPY slapd.conf.template /etc/ldap/slapd.conf.template
COPY seed/base.ldif /etc/ldap/seed/base.ldif
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
