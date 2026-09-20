# Every stage uses the same digest-pinned PHP 8.4 base. Build dependencies live
# only in extension-builder; the long-lived services use runtime, while Composer
# and VCS/archive tools are available only in the explicitly built tooling stage.
FROM php:8.4-fpm@sha256:59fa733c9af643a122f8a9976119460e35ce76dd0a3f2b9c8f75af8e361a54e2 AS extension-builder

RUN apt-get update && apt-get install -y --no-install-recommends \
    linux-libc-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libldap2-dev \
    libxml2-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && LDAP_MULTIARCH="$(dpkg-architecture -qDEB_HOST_MULTIARCH)" \
    && docker-php-ext-configure ldap --with-libdir="lib/${LDAP_MULTIARCH}" \
    # pcntl is bundled with PHP (no download, air-gap safe). The deploy
    # supervisor is PID 1 and needs a real SIGTERM handler so Docker does not
    # SIGKILL it and its active playbook after the grace period.
    && docker-php-ext-install gd ldap mysqli pcntl zip \
    && rm -rf /var/lib/apt/lists/*

FROM php:8.4-fpm@sha256:59fa733c9af643a122f8a9976119460e35ce76dd0a3f2b9c8f75af8e361a54e2 AS runtime

# Shared libraries only. Compilers, headers, Composer, git and archive tools
# stay out of this stage. libfcgi-bin carries cgi-fcgi for the FPM healthcheck.
# hadolint ignore=SC2086
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng16-16t64 \
    libjpeg62-turbo \
    libfreetype6 \
    libldap2 \
    libzip5 \
    libfcgi-bin \
    && apt-mark manual libpng16-16t64 libjpeg62-turbo libfreetype6 libldap2 libzip5 libfcgi-bin \
    # The upstream php:fpm image exposes its bootstrap tool list through
    # PHPIZE_DEPS. Purging that exact owner-provided set removes compilers and
    # headers from the final filesystem without maintaining a second list.
    # Word splitting is intentional: PHPIZE_DEPS is the upstream-owned,
    # whitespace-separated apt package list, not an application value.
    && apt-get purge -y --auto-remove ${PHPIZE_DEPS} \
    && rm -rf /var/lib/apt/lists/*

COPY --from=extension-builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extension-builder /usr/local/etc/php/conf.d/docker-php-ext-*.ini /usr/local/etc/php/conf.d/

COPY conf.d/zz-virtusphere.ini /usr/local/etc/php/conf.d/
COPY fpm.d/zz-virtusphere.conf /usr/local/etc/php-fpm.d/zz-virtusphere.conf

# Shared HTTPS volumes may be empty named volumes (QA/fresh installs). Docker
# copies mount-point ownership from whichever image initializes the volume
# first, so both PHP and nginx declare the same contract: PHP-FPM's uid 33 owns
# the directories and root (nginx init/watcher) keeps group write access.
RUN mkdir -p /etc/nginx/ssl /etc/nginx/virtusphere-conf.d \
    && chown 33:0 /etc/nginx/ssl /etc/nginx/virtusphere-conf.d \
    && chmod 0770 /etc/nginx/ssl /etc/nginx/virtusphere-conf.d

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]

FROM runtime AS tooling

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 /usr/bin/composer /usr/local/bin/composer
