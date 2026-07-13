#!/bin/sh
# Proves that the fresh schema (Docker/mysql/mysql-init/struktur.sql) and the
# incremental migration path (lib/migrate.php) converge to the same shape, and
# that struktur.sql loads cleanly on a fresh volume.
#
# Requires a running stack: the MySQL and PHP containers from docker-compose.
# Not part of CI (CI has no MySQL); run on the dev host and before shipping.
#
#   sh scripts/check-schema-convergence.sh
#
# Env overrides: MYSQL_CONTAINER, PHP_CONTAINER, MYSQL_ROOT_PASSWORD.
set -eu

# Git Bash on Windows rewrites /var/www/... into a host path; disable that so
# container-absolute paths survive docker exec.
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'

MYSQL_CONTAINER="${MYSQL_CONTAINER:-virtusphere-v2-webapp-mysql-1}"
PHP_CONTAINER="${PHP_CONTAINER:-virtusphere-v2-webapp-php-1}"
STRUKTUR="Docker/mysql/mysql-init/struktur.sql"

if [ -z "${MYSQL_ROOT_PASSWORD:-}" ]; then
  MYSQL_ROOT_PASSWORD="$(grep -E '^MYSQL_ROOT_PASSWORD=' .env | cut -d= -f2-)"
fi
[ -n "$MYSQL_ROOT_PASSWORD" ] || { echo "convergence: MYSQL_ROOT_PASSWORD not set and not in .env" >&2; exit 2; }

myql() { docker exec -i "$MYSQL_CONTAINER" mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$@" 2>/dev/null; }
mydump() { docker exec "$MYSQL_CONTAINER" mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --no-data --skip-comments --skip-dump-date "$1" 2>/dev/null | sed -E 's/ AUTO_INCREMENT=[0-9]+//'; }

A=vs_conv_struktur
B=vs_conv_migrated
cleanup() { myql -e "DROP DATABASE IF EXISTS $A; DROP DATABASE IF EXISTS $B;" 2>/dev/null || true; }
trap cleanup EXIT

echo "convergence: building $A from struktur.sql only"
myql -e "DROP DATABASE IF EXISTS $A; CREATE DATABASE $A CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# struktur.sql must load standalone (this is what docker-entrypoint-initdb.d does).
if ! myql "$A" < "$STRUKTUR"; then
  echo "BLOCK: struktur.sql failed to load on a fresh database (a fresh install would fail to initialize)" >&2
  exit 1
fi

echo "convergence: building $B from struktur.sql + migrate.php"
myql -e "DROP DATABASE IF EXISTS $B; CREATE DATABASE $B CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
myql "$B" < "$STRUKTUR"
docker exec -e DB_NAME="$B" -e DB_USER=root -e DB_PASS="$MYSQL_ROOT_PASSWORD" "$PHP_CONTAINER" \
  php /var/www/html/lib/migrate.php >/dev/null
# migrations must be a no-op on a fresh struktur schema.
docker exec -e DB_NAME="$B" -e DB_USER=root -e DB_PASS="$MYSQL_ROOT_PASSWORD" "$PHP_CONTAINER" \
  php /var/www/html/lib/migrate.php --check | grep -q 'pending=0' \
  || { echo "BLOCK: migrations still pending after applying against a fresh struktur schema" >&2; exit 1; }

if diff -u <(mydump "$A") <(mydump "$B"); then
  echo "convergence: OK - struktur.sql and migrate.php produce an identical schema"
else
  echo "BLOCK: struktur.sql and migrate.php diverge (see diff above)" >&2
  exit 1
fi
