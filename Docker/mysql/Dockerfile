# The upstream bytes stay reviewable even though Compose runs the locally built
# child image. MySQL Shell is an optional administration client; the server
# entrypoint and VirtuSphere use mysql/mysqladmin instead. Removing its RPM also
# removes the bundled Python runtime whose cryptography dependency cannot be
# upgraded to the fixed major without breaking the bundled OCI/pyOpenSSL pins.
FROM mysql:8.4@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb

# The RPM leaves generated, unowned __pycache__ files behind.
RUN microdnf remove -y mysql-shell \
    && microdnf clean all \
    && rm -rf /usr/lib/mysqlsh
