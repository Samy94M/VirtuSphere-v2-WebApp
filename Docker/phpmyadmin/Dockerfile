# phpMyAdmin remains an opt-in tools-profile service. The upstream release is
# pinned byte-for-byte; the child layer consumes Debian Security updates for the
# already installed kernel headers and leaves no package index in the artifact.
FROM phpmyadmin:5.2.3@sha256:3a8a8d6b5289091f959ba0293f21163b3a2fc5741991a53de70b3497fe8d31db

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libssl3t64 \
        linux-libc-dev \
        openssl \
        openssl-provider-legacy \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
