# Base and composer are pinned by digest (AP8): the tag documents the intended
# line, the digest makes the base of every rebuild byte-identical. Bumping a
# pin is a deliberate commit, never a side effect of a rebuild.
FROM php:8.4-fpm@sha256:3661dc717d73bdb0ec58bcdc96af36af90f8894c66c09ac74721a012b01da6f4

# libfcgi-bin carries cgi-fcgi for the FPM ping healthcheck (docker-compose.yml
# + fpm.d/zz-virtusphere.conf).
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libfcgi-bin \
    git \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mysqli zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 /usr/bin/composer /usr/local/bin/composer
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
