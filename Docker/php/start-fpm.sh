#!/bin/sh
# PHP container entry (SEC3): bring an existing generated HTTPS block up to this
# release's template, then become php-fpm. nginx starts only once FPM is
# healthy, so it boots on the refreshed block; a later restart of this
# container alone is picked up by the nginx watcher. A failed refresh never
# keeps FPM down: the previous block stays and the reason is in the log.
set -u
refresh=/var/www/html/lib/https_refresh.php
if [ -f "$refresh" ]; then
    php "$refresh" || echo "start-fpm: HTTPS block not refreshed" >&2
fi
exec php-fpm "$@"
