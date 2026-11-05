#!/bin/sh
# PHP container entry (SEC3): bring an existing generated HTTPS block up to this
# release's template, then become php-fpm. nginx starts only once FPM is
# healthy, so it boots on the refreshed block; a later restart of this
# container alone is picked up by the nginx watcher. A failed refresh never
# keeps FPM down: the previous block stays and the reason is in the log.
#
# The refresh runs as the pool user (www-data, uid 33), who owns the block after
# a portal upload: the container has no CAP_CHOWN, so root could not hand the
# file back. setpriv only drops privileges (SETUID/SETGID are granted for the
# FPM master anyway). Without setpriv it runs as root; the directory belongs to
# uid 33, so the portal can still replace the file later.
set -u
refresh=/var/www/html/lib/https_refresh.php
if [ -f "$refresh" ]; then
    if command -v setpriv >/dev/null 2>&1; then
        setpriv --reuid=www-data --regid=www-data --clear-groups php "$refresh" || echo "start-fpm: HTTPS block not refreshed" >&2
    else
        php "$refresh" || echo "start-fpm: HTTPS block not refreshed" >&2
    fi
fi
exec php-fpm "$@"
