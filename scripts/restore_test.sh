#!/bin/sh
# scripts/restore_test.sh — Restore-Probe fuer das juengste DB-Backup.
#
# Ein Backup ist erst dann ein Backup, wenn der Restore bewiesen ist. Dieses
# Skript startet einen Wegwerf-MySQL-Container (gleiches Image wie der Stack,
# air-gap-freundlich), spielt den neuesten Dump aus Docker/backups/ ein und
# prueft, dass die Applikationstabellen ankommen. Danach wird der Container
# entfernt. Der laufende Stack wird nicht beruehrt.
#
# Empfohlene Kadenz: nach jedem Schema-Meilenstein und mindestens monatlich
# (siehe docs/operations/backup.md).
set -eu
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
cd "$(dirname "$0")/.."

BACKUP_DIR="Docker/backups"
NAME="virtusphere-restore-test"
IMAGE="mysql:8.4"
PW="restore-test-only-$(date +%s)"
MIN_TABLES=10

dump=$(ls -1t "$BACKUP_DIR"/db-*.sql.gz 2>/dev/null | head -n 1 || true)
if [ -z "$dump" ]; then
  echo "FEHLER: kein DB-Dump unter $BACKUP_DIR/ gefunden. Erst sh scripts/backup.sh laufen lassen." >&2
  exit 1
fi
echo "Restore-Probe mit: $dump"

cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT INT TERM
cleanup

docker run -d --name "$NAME" -e MYSQL_ROOT_PASSWORD="$PW" "$IMAGE" >/dev/null

echo "Warte auf MySQL im Wegwerf-Container ..."
# Bewusst SELECT 1 statt mysqladmin ping: waehrend der Image-Initialisierung
# antwortet ein temporaerer Server, bei dem das Root-Passwort noch nicht gesetzt
# ist — ping meldet den faelschlich als bereit.
i=0
until docker exec "$NAME" mysql -uroot -p"$PW" -N -e 'SELECT 1' >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    echo "FEHLER: Wegwerf-MySQL wurde nicht bereit." >&2
    exit 1
  fi
  sleep 2
done

echo "Spiele Dump ein ..."
gunzip -c "$dump" | docker exec -i "$NAME" mysql -uroot -p"$PW"

tables=$(docker exec "$NAME" mysql -uroot -p"$PW" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema NOT IN ('mysql','sys','information_schema','performance_schema');")
vms_table=$(docker exec "$NAME" mysql -uroot -p"$PW" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'deploy_vms';")

if [ "$tables" -lt "$MIN_TABLES" ] || [ "$vms_table" -lt 1 ]; then
  echo "FEHLER: Restore unvollstaendig — $tables App-Tabellen, deploy_vms vorhanden: $vms_table." >&2
  exit 1
fi

echo "Restore-Probe OK: $tables Applikationstabellen wiederhergestellt (inkl. deploy_vms)."
