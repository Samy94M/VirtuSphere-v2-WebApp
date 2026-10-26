# gosu is the privilege dropper the upstream entrypoint re-execs itself through
# (`exec gosu mysql ...`, its only call site). Oracle ships it built against Go
# 1.24.6, which trivy reports with 22 fixable Critical/High stdlib findings, and
# the newest published mysql:8.4 build still carries that same binary: measured
# 2026-09-07 and again 2026-10-25 on the 8.4.13 build pinned below (gosu 1.19,
# go1.24.6), which docker.io/library/mysql:8.4 resolved to on that day.
# So the fix has to come from here. This stage rebuilds gosu from source against
# the current Go toolchain; the module has no semver tags, so the pin is the
# exact pseudo-version. The Go toolchain comes from the builder digest, so a
# new Go stdlib fix means bumping that digest (2026-10-14: Go 1.27.1 to 1.27.2,
# three High findings; 2026-10-25: Go 1.27.2 to 1.27.3, CVE-2026-111943 in
# crypto/x509). CGO_ENABLED=0 is load-bearing and not a habit: a
# cgo-linked build from Alpine would be musl-linked and unusable in the
# glibc-based Oracle Linux stage below.
FROM golang:1-alpine@sha256:b8c9b82affbbb82a020bbcf998dad2ba9516944ac7e883524642a481a9505e9c AS gosu
ENV CGO_ENABLED=0
RUN go install -trimpath -ldflags="-s -w" \
        github.com/tianon/gosu@v0.0.0-20260606051551-40506998e34a

# The upstream bytes stay reviewable even though Compose runs the locally built
# child image. MySQL Shell is an optional administration client; the server
# entrypoint and VirtuSphere use mysql/mysqladmin instead. Removing its RPM also
# removes the bundled Python runtime whose cryptography dependency cannot be
# upgraded to the fixed major without breaking the bundled OCI/pyOpenSSL pins.
#
# trivy reads the RPM database, so a MySQL server release with security fixes
# that Oracle publishes only as a new image reaches us through a digest bump.
# 2026-10-25: 8.4.11 to 8.4.13 (three 8.4.13 security notes without CVE IDs,
# newer bundled libraries). A patch step inside 8.4 LTS needs no
# migration; the server upgrades its system tables on the first start.
FROM mysql:8.4@sha256:76f0358884cb92d9a086d24e5671c38048d3fef40f2588703d73eb55392d6b69

# The RPM leaves generated, unowned __pycache__ files behind.
RUN microdnf update -y \
    && microdnf remove -y mysql-shell \
    && microdnf clean all \
    && rm -rf /usr/lib/mysqlsh

# Same path, same mode, same owner as the binary it replaces (root:root 0755,
# no setuid). The entrypoint calls it unchanged.
COPY --from=gosu --chown=root:root --chmod=0755 /go/bin/gosu /usr/local/bin/gosu

# Root- und App-DB-Passwort gehoeren zum Datenbank-Bootstrap. PHP/Worker sehen
# das Rootsecret nicht; deshalb validiert der MySQL-Owner beide Werte selbst,
# bevor der unveraenderte Upstream-Entrypoint irgendeinen Server startet.
COPY --chown=root:root --chmod=0755 validate-secrets.sh /usr/local/bin/virtusphere-mysql-entrypoint.sh
ENTRYPOINT ["virtusphere-mysql-entrypoint.sh"]
# Re-state the server command explicitly. The currently pinned upstream image
# publishes no inherited Config.Cmd, so an empty Compose command would otherwise
# validate both secrets and then exit successfully without starting MySQL.
CMD ["mysqld"]
