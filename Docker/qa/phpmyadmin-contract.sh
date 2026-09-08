#!/bin/sh
# No configuration contents, cookies or credentials enter the QA artifact.
set -eu
test "$(id -u)" = 33
test "$(id -g)" = 33
test "$(stat -c '%u:%g:%a' /etc/phpmyadmin)" = 33:33:755
test -s /etc/phpmyadmin/config.secret.inc.php
test -f /etc/phpmyadmin/config.user.inc.php
test -z "$(find /var/www/html/vendor -perm /222 -print -quit)"
grep -q 'CapEff:[[:space:]]*0000000000000000' /proc/1/status
printf 'configuration owner/mode, generated files, vendor modes, zero capabilities verified\n'
