#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)"
BACKUP_DIR="$ROOT_DIR/Docker/backups"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="$BACKUP_DIR/$STAMP"
STATUS="$BACKUP_DIR/status.jsonl"

mkdir -p "$TARGET"

if [ -f "$ROOT_DIR/.env" ]; then
  cp "$ROOT_DIR/.env" "$TARGET/.env"
fi

if [ -d "$ROOT_DIR/Docker/nginx/ssl" ]; then
  tar -C "$ROOT_DIR/Docker/nginx" -czf "$TARGET/ssl.tgz" ssl
fi

docker compose --env-file "$ROOT_DIR/.env" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump -u"$MYSQL_USER" "$MYSQL_DATABASE"' > "$TARGET/db.sql"

printf '{"ts":"%s","type":"backup","path":"%s","status":"ok"}\n' "$STAMP" "$TARGET" >> "$STATUS"
echo "$TARGET"