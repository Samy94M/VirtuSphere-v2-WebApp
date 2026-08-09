# phpMyAdmin remains an opt-in tools-profile service. The upstream release is
# pinned byte-for-byte; the child layer consumes Debian Security updates for the
# already installed kernel headers and leaves no package index in the artifact.
FROM phpmyadmin:5.2.3@sha256:5e2289bcd500868ed4ac9261ddd7cbcc6e20036f83031ade72667b15dca31c60

RUN apt-get update \
    && apt-get install -y --no-install-recommends linux-libc-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
