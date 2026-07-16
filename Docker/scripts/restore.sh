#!/bin/sh
set -eu

if [ "$#" -ne 1 ]; then
  echo "usage: Docker/scripts/restore.sh Docker/backups/<stamp>" >&2
  exit 2
fi

ROOT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"
SOURCE="$1"
STATUS="$ROOT_DIR/Docker/backups/status.jsonl"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

if [ ! -f "$SOURCE/db.sql" ]; then
  echo "missing db.sql in $SOURCE" >&2
  exit 1
fi

if [ -f "$SOURCE/.env" ]; then
  cp "$SOURCE/.env" "$ROOT_DIR/.env"
fi

if [ -f "$SOURCE/ssl.tgz" ]; then
  tar -C "$ROOT_DIR/Docker/nginx" -xzf "$SOURCE/ssl.tgz"
fi

docker compose --env-file "$ROOT_DIR/.env" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" "$MYSQL_DATABASE"' < "$SOURCE/db.sql"

printf '{"ts":"%s","type":"restore","path":"%s","status":"ok"}\n' "$STAMP" "$SOURCE" >> "$STATUS"
echo "restore ok: $SOURCE"