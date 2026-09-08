# phpMyAdmin remains an opt-in tools-profile service. It bundles its own vendor
# tree, and upstream stopped releasing: as of 2026-09-07 the tags latest, 5.2
# and 5.2.3 all resolve to the digest pinned below, still shipping twig 3.11.3
# (9 fixable Critical/High findings, four of them Critical) and symfony/cache
# 5.4.46 (one more). A digest bump cannot answer that, so this stage updates
# exactly those two packages against phpMyAdmin's own constraint
# (`twig/twig: ^3.3.5`) and copies only the result forward. Composer, git and
# unzip stay in the builder: the shipped image gets the vendor tree, not the
# toolchain that produced it. The tree is handed on read-only again, the way
# the base image ships it.
FROM phpmyadmin:5.2.3@sha256:3a8a8d6b5289091f959ba0293f21163b3a2fc5741991a53de70b3497fe8d31db AS vendor
COPY --from=composer:2@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip; \
    chmod -R u+w composer.json composer.lock vendor; \
    COMPOSER_ALLOW_SUPERUSER=1 composer update twig/twig symfony/cache \
        --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader; \
    chmod -R a-w vendor composer.lock

# The upstream release is pinned byte-for-byte; the child layer consumes Debian
# Security updates for the already installed kernel headers and leaves no
# package index in the artifact.
FROM phpmyadmin:5.2.3@sha256:3a8a8d6b5289091f959ba0293f21163b3a2fc5741991a53de70b3497fe8d31db

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libssl3t64 \
        linux-libc-dev \
        openssl \
        openssl-provider-legacy \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Ownership matches what the base image ships (www-data), so the runtime user
# reads the updated tree exactly as it read the bundled one.
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor /var/www/html/vendor
COPY --from=vendor --chown=www-data:www-data /var/www/html/composer.lock /var/www/html/composer.lock

# With cap_drop ALL, root cannot write the www-data-owned configuration
# directory or drop Apache's uid/gid. Start as its existing owner instead:
# configuration generation and Apache need no capabilities or writable vendor.
USER www-data:www-data
