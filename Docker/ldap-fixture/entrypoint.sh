#!/bin/sh
# Hermetic LDAP fixture entrypoint. FIXTURE_ROLE picks one of three tiny
# roles so a single image covers every scenario Plan section 18.3 asks for:
#   dc        - a real slapd (native PHP/ext-ldap path proof: bind, search,
#               objectGUID round trip, referral, monitor bind counters).
#   tls-stub  - a socat OpenSSL listener presenting one fixture cert, used
#               for the certificate-rejection scenarios (unknown CA, expired,
#               wrong name) where the client never gets past the TLS
#               handshake, so no real LDAP protocol is needed above it.
#   blackhole - a socat TCP listener that accepts and then never answers,
#               proving the deadline/timeout path actually fires.
set -eu

ROLE="${FIXTURE_ROLE:?FIXTURE_ROLE is required (dc|tls-stub|blackhole)}"
FIXTURES_DIR="${FIXTURES_DIR:-/fixtures/ldap}"

case "$ROLE" in
dc)
    CERT_NAME="${CERT_NAME:?CERT_NAME is required for FIXTURE_ROLE=dc}"
    STATE_DIR=/var/lib/ldap-fixture
    rm -rf "$STATE_DIR"
    mkdir -p "$STATE_DIR/data"

    CONF="$STATE_DIR/slapd.conf"
    sed \
        -e "s#@@CERT_FILE@@#${FIXTURES_DIR}/${CERT_NAME}.crt.txt#" \
        -e "s#@@KEY_FILE@@#${FIXTURES_DIR}/${CERT_NAME}.key.txt#" \
        -e "s#@@CA_FILE@@#${FIXTURES_DIR}/root-a.crt.txt#" \
        /etc/ldap/slapd.conf.template > "$CONF"

    slaptest -f "$CONF" -F "$STATE_DIR/config-check" >/dev/null 2>&1 || true
    slapadd -f "$CONF" -l /etc/ldap/seed/base.ldif
    chown -R openldap:openldap "$STATE_DIR"

    # ldap:/// (plaintext) is loopback-only in practice: nothing publishes
    # port 389 outside the container network, and it exists purely so the
    # compose healthcheck can prove the process is alive without needing a
    # trusted client cert. Real test traffic always goes over ldaps:///.
    exec slapd -f "$CONF" -h "ldap:/// ldaps:///" -d 0 -u openldap -g openldap
    ;;
tls-stub)
    CERT_NAME="${CERT_NAME:?CERT_NAME is required for FIXTURE_ROLE=tls-stub}"
    PORT="${FIXTURE_PORT:-636}"
    exec socat -d \
        "OPENSSL-LISTEN:${PORT},reuseaddr,fork,cert=${FIXTURES_DIR}/${CERT_NAME}.crt.txt,key=${FIXTURES_DIR}/${CERT_NAME}.key.txt,verify=0" \
        SYSTEM:"cat >/dev/null"
    ;;
blackhole)
    PORT="${FIXTURE_PORT:-636}"
    # Accepts the TCP connection and then never writes a byte: the TLS
    # ClientHello goes unanswered, exercising the network-timeout deadline
    # rather than an immediate connection-refused transport failure.
    exec socat -d "TCP-LISTEN:${PORT},reuseaddr,fork" SYSTEM:"sleep 3600"
    ;;
*)
    echo "unknown FIXTURE_ROLE: $ROLE" >&2
    exit 1
    ;;
esac
