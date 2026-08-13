#!/bin/sh
# scripts/restore_test.sh — Restore-Drill fuer das juengste Backup (AP6/E5).
#
# Ein Backup ist erst dann ein Backup, wenn der Restore bewiesen ist. Der Drill
# stellt das juengste Backup-Tripel (db-/config-/manifest-Datei) in einer
# isolierten Wegwerf-Umgebung wieder her und prueft die volle Kette:
#
#   1. Manifest-Hashes beider Archive (sha256sum -c)
#   2. Dateirechte von .env und SSL-Schluesseln im Config-Archiv
#   3. Import in einen Wegwerf-MySQL (gleiches Image wie der Stack)
#   4. Tabellenzahl des Dumps gegen die wiederhergestellte Datenbank
#   5. Migrationen laufen durch, danach migrate --check mit pending=0
#   6. Schema-Fingerprint: restored+migriert == frisches struktur.sql
#   7. Invarianten, Rowcounts und Credential-Entschluesselung mit dem
#      APP_KEY aus dem gesicherten .env — und erwartetes Scheitern mit
#      einem falschen Schluessel (tests/tools/restore-drill-probe.php)
#   8. App-Smoke gegen die wiederhergestellten Daten: health.php, Portal-Login
#      mit einem Drill-Admin, Machine-API-Ablehnung eines ungueltigen Tokens
#   9. Vollstaendiges Cleanup (Container, Netz, Tempdateien) per Trap
#
# Der laufende Stack wird nicht beruehrt. Kanonischer Backup-Weg ist
# scripts/backup.sh; die frueheren Docker/scripts/{backup,restore}.sh sind
# stillgelegt (E5). Empfohlene Kadenz: Release-Lane (scripts/check.ps1) und
# mindestens monatlich (docs/operations/backup.md).
#
# Exitcodes: 0 Drill bestanden; 1 Befund; 2 Umgebung unvollstaendig.
set -eu
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
cd "$(dirname "$0")/.."

BACKUP_DIR="Docker/backups"
MYSQL_IMAGE="${VIRTUSPHERE_MYSQL_IMAGE:-mysql:8.4-virtusphere}"
PHP_IMAGE="${VIRTUSPHERE_PHP_IMAGE:-virtusphere-v2-webapp-php}"
SUFFIX="$$"
NET="vs-restore-net-$SUFFIX"
MYSQL_NAME="vs-restore-mysql-$SUFFIX"
SMOKE_NAME="vs-restore-web-$SUFFIX"
SMOKE_PORT=8099
PW="restore-drill-$(date +%s)-$SUFFIX"
DRILL_ADMIN_USER="restore-drill-admin"
DRILL_ADMIN_PASS="Drill-Admin-Passw0rd-$SUFFIX"
REPO_MOUNT="$(pwd -W 2>/dev/null || pwd)"
WORKDIR="$(mktemp -d)"

fail() { echo "FEHLER: $*" >&2; exit 1; }
envfail() { echo "FEHLER (Umgebung): $*" >&2; exit 2; }

cleanup() {
  docker rm -f "$SMOKE_NAME" >/dev/null 2>&1 || true
  docker rm -f "$MYSQL_NAME" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  rm -rf "$WORKDIR"
}
trap cleanup EXIT INT TERM

command -v docker >/dev/null 2>&1 || envfail "docker fehlt."
command -v sha256sum >/dev/null 2>&1 || envfail "sha256sum fehlt."
docker image inspect "$PHP_IMAGE" >/dev/null 2>&1 \
  || envfail "PHP-Image $PHP_IMAGE fehlt (Stack einmal bauen oder VIRTUSPHERE_PHP_IMAGE setzen)."
docker image inspect "$MYSQL_IMAGE" >/dev/null 2>&1 \
  || envfail "MySQL-Image $MYSQL_IMAGE fehlt (Stack einmal bauen oder VIRTUSPHERE_MYSQL_IMAGE setzen)."

# --- 0. Juengstes Backup-Tripel finden --------------------------------------
dump=$(ls -1t "$BACKUP_DIR"/db-*.sql.gz 2>/dev/null | head -n 1 || true)
[ -n "$dump" ] || envfail "kein DB-Dump unter $BACKUP_DIR/ gefunden. Erst sh scripts/backup.sh laufen lassen."
ts=$(basename "$dump" | sed 's/^db-//; s/\.sql\.gz$//')
config="$BACKUP_DIR/config-$ts.tar.gz"
manifest="$BACKUP_DIR/manifest-$ts.sha256"
[ -f "$config" ] || fail "Config-Archiv $config fehlt zum Dump $dump; das Backup-Tripel ist unvollstaendig."
[ -f "$manifest" ] || fail "Manifest $manifest fehlt. Ein Backup ohne Hash-Manifest ist nicht verifizierbar; sh scripts/backup.sh erneut laufen lassen."
echo "Restore-Drill mit Backup-Stand: $ts"

# --- 1. Manifest ---------------------------------------------------------------
( cd "$BACKUP_DIR" && sha256sum -c "$(basename "$manifest")" ) \
  || fail "Manifest-Pruefung fehlgeschlagen: mindestens ein Archiv ist veraendert oder unvollstaendig."

# --- 2. Dateirechte im Config-Archiv -------------------------------------------
# Auf einem POSIX-Host ist ein gruppen-/weltlesbares .env oder ein lesbarer
# SSL-Key ein Befund. Git-Bash unter Windows kann POSIX-Modi nicht abbilden
# (alles erscheint als 644/755), dort wird nur gewarnt.
perm_findings=$(tar -tzvf "$config" | awk '
  $NF == ".env" || $NF ~ /ssl\/.*\.key$/ {
    if (substr($1, 5, 6) != "------") print $1, $NF
  }' || true)
if [ -n "$perm_findings" ]; then
  case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*)
      echo "WARNUNG: Dateirechte im Config-Archiv nicht restriktiv (Windows-Host kann POSIX-Modi nicht pruefen):" >&2
      echo "$perm_findings" >&2 ;;
    *)
      echo "$perm_findings" >&2
      fail ".env bzw. SSL-Schluessel im Config-Archiv sind gruppen- oder weltlesbar." ;;
  esac
fi

# --- 3. .env aus dem Config-Archiv lesen ---------------------------------------
tar -xzf "$config" -C "$WORKDIR" .env 2>/dev/null || fail "Config-Archiv enthaelt kein .env."

# --- 3a. Der archivierte Compose-Satz muss fuer sich stehen ---------------------
#
# Der Drill prueft Hashes, Rechte und die Datenbank; dass die archivierte
# Konfiguration einen Stack HOCHBRINGT, hat er nie geprueft. Genau daran ist der
# Befund vorbeigelaufen: docker-compose.override.yml fehlte im Archiv, beide
# Archive waren intakt, und ein Restore auf dem Produktionshost haette nicht
# starten koennen (das Subnetz-Pin dort verhindert, dass die Docker-Bridge das SSH
# abschneidet). `config --quiet` ist die billigste Frage, die das beantwortet: es
# loest Interpolation und Struktur auf, ohne etwas zu starten.
tar -xzf "$config" -C "$WORKDIR" docker-compose.yml 2>/dev/null \
  || fail "Config-Archiv enthaelt kein docker-compose.yml."
# Der Docker-CLI ist unter Git-Bash ein Windows-Programm und liest /tmp/... als
# C:\tmp\..., waehrend MSYS_NO_PATHCONV=1 die Uebersetzung abschaltet. $WORKDIR ist
# die erste Stelle, die einen mktemp-Pfad an docker uebergibt, also braucht sie die
# Host-Form - dieselbe Idiom wie $REPO_MOUNT oben. Ohne das meldet der Drill
# "couldn't find env file" und damit einen Befund, den es nicht gibt.
workdir_host="$(cd "$WORKDIR" && pwd -W 2>/dev/null || echo "$WORKDIR")"
compose_args="-f $workdir_host/docker-compose.yml"
override_present="nein"
if tar -tzf "$config" | grep -qx 'docker-compose.override.yml'; then
  tar -xzf "$config" -C "$WORKDIR" docker-compose.override.yml 2>/dev/null \
    || fail "Config-Archiv listet docker-compose.override.yml, entpacken schlug fehl."
  compose_args="$compose_args -f $workdir_host/docker-compose.override.yml"
  override_present="ja"
fi
# shellcheck disable=SC2086
docker compose $compose_args --env-file "$workdir_host/.env" config --quiet \
  || fail "Der archivierte Compose-Satz laesst sich nicht aufloesen; ein Restore daraus wuerde nicht starten."
echo "OK: archivierter Compose-Satz aufloesbar (Override im Archiv: $override_present)"
env_value() {
  grep -E "^$1=" "$WORKDIR/.env" | head -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//"
}
DB_NAME="$(env_value DB_NAME)"
APP_KEY="$(env_value APP_KEY)"
APP_DB_USER="$(env_value DB_USER)"
APP_DB_PASS="$(env_value DB_PASS)"
[ -n "$DB_NAME" ] || fail "DB_NAME fehlt im gesicherten .env."
[ -n "$APP_KEY" ] || fail "APP_KEY fehlt im gesicherten .env; Credentials waeren nach diesem Restore unlesbar."
[ -n "$APP_DB_USER" ] || fail "DB_USER fehlt im gesicherten .env; die App haette nach diesem Restore keinen DB-Zugang."
[ -n "$APP_DB_PASS" ] || fail "DB_PASS fehlt im gesicherten .env; die App haette nach diesem Restore keinen DB-Zugang."

# --- 4. Wegwerf-MySQL + Import ---------------------------------------------------
# Der Wegwerf-Container entsteht wie der Produktionsstack: der mysql-Entrypoint
# legt den App-User aus der archivierten .env an (MYSQL_USER/MYSQL_PASSWORD/
# MYSQL_DATABASE, wie docker-compose.yml sie setzt). Genau diese Grants muss der
# Import ueberleben, denn die App verbindet nie als root.
docker network create "$NET" >/dev/null
docker run -d --name "$MYSQL_NAME" --network "$NET" \
  -e MYSQL_ROOT_PASSWORD="$PW" \
  -e MYSQL_DATABASE="$DB_NAME" \
  -e MYSQL_USER="$APP_DB_USER" -e MYSQL_PASSWORD="$APP_DB_PASS" \
  "$MYSQL_IMAGE" >/dev/null

echo "Warte auf MySQL im Wegwerf-Container ..."
# Bewusst SELECT 1 statt mysqladmin ping: waehrend der Image-Initialisierung
# antwortet ein temporaerer Server, bei dem das Root-Passwort noch nicht gesetzt
# ist — ping meldet den faelschlich als bereit.
i=0
until docker exec "$MYSQL_NAME" mysql -uroot -p"$PW" -N -e 'SELECT 1' >/dev/null 2>&1; do
  i=$((i + 1))
  [ "$i" -lt 60 ] || envfail "Wegwerf-MySQL wurde nicht bereit."
  sleep 2
done

echo "Spiele Dump ein ..."
gunzip -c "$dump" | docker exec -i "$MYSQL_NAME" mysql -uroot -p"$PW"

ROOT_PW="$PW"
drill_sql() { docker exec "$MYSQL_NAME" mysql -uroot -p"$ROOT_PW" -N -e "$1" 2>/dev/null; }

# --- 4a. Der App-User muss nach dem Import arbeiten koennen ---------------------
#
# Der Drill lief frueher ausschliesslich als root und konnte gruen sein, obwohl
# die App nach dem Restore keinen DB-Zugriff hatte: ein --all-databases-Dump
# traegt die mysql-Grant-Tabellen mit, deren Import ersetzt die Grants des vom
# Entrypoint frisch angelegten App-Users, und nach einer Passwortrotation passt
# der importierte Alt-Stand nicht mehr zur .env. FLUSH PRIVILEGES zuerst, weil
# der Server importierte Grant-Tabellen erst dann liest - genau wie nach dem
# Neustart, der im Ernstfall auf den Import folgt.
docker exec "$MYSQL_NAME" mysql -uroot -p"$ROOT_PW" -N -e "FLUSH PRIVILEGES" 2>/dev/null || true

# Ein Alt-Archiv ersetzt nach dem FLUSH auch das ROOT-Passwort des Zielservers
# durch den archivierten Stand (empirisch: der Drill brach hier still ab). Der
# Ernstfall-Operator kennt es aus der archivierten .env; der Drill genauso.
if ! drill_sql "SELECT 1" >/dev/null; then
  ARCHIVED_ROOT_PW="$(env_value MYSQL_ROOT_PASSWORD)"
  if [ -n "$ARCHIVED_ROOT_PW" ] \
    && docker exec "$MYSQL_NAME" mysql -uroot -p"$ARCHIVED_ROOT_PW" -N -e "SELECT 1" >/dev/null 2>&1; then
    ROOT_PW="$ARCHIVED_ROOT_PW"
    echo "Hinweis: der Grant-Import hat auch das Root-Passwort des Wegwerf-Servers ersetzt; weiter mit dem archivierten MYSQL_ROOT_PASSWORD (Alt-Archiv-Effekt, siehe backup.md)."
  else
    fail "root ist nach dem Grant-Import weder mit dem Wegwerf- noch mit dem archivierten Passwort nutzbar."
  fi
fi
app_can_work() {
  docker exec "$MYSQL_NAME" mysql -u"$APP_DB_USER" -p"$APP_DB_PASS" -N \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME'" >/dev/null 2>&1
}
if zgrep -m1 -q '^-- Current Database: .mysql.$' "$dump" 2>/dev/null \
  || gunzip -c "$dump" | grep -m1 -q '^-- Current Database: .mysql.$'; then
  echo "Alt-Archiv erkannt (--all-databases mit mysql-Grant-Tabellen)."
  if app_can_work; then
    echo "OK: App-User hat den Grant-Import ueberlebt (Passwortstand konsistent)."
  else
    # Der in docs/operations/backup.md dokumentierte Reparaturschritt fuer
    # Alt-Archive: App-User aus der archivierten .env neu setzen. Im Ernstfall
    # macht das der Operator; der Drill beweist, dass der Schritt genuegt.
    echo "App-User nach Grant-Import nicht arbeitsfaehig; stelle Grants aus der archivierten .env wieder her (backup.md, Alt-Archiv-Schritt) ..."
    drill_sql "CREATE USER IF NOT EXISTS '$APP_DB_USER'@'%' IDENTIFIED BY '$APP_DB_PASS'; ALTER USER '$APP_DB_USER'@'%' IDENTIFIED BY '$APP_DB_PASS'; GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$APP_DB_USER'@'%'; FLUSH PRIVILEGES;" \
      || fail "Grant-Reparatur fuer das Alt-Archiv schlug fehl."
  fi
fi
app_can_work \
  || fail "Der App-User $APP_DB_USER kann nach dem Restore nicht arbeiten; die App haette keinen DB-Zugriff, waehrend ein root-Drill gruen bliebe."
echo "OK: App-User $APP_DB_USER kann nach dem Restore arbeiten."

# --- 5. Tabellenzahl Dump vs. Restore ------------------------------------------
dump_tables=$(gunzip -c "$dump" | awk -v db="$DB_NAME" '
  /^-- Current Database:/ { in_db = index($0, "`" db "`") > 0 }
  in_db && /^CREATE TABLE/ { n++ }
  END { print n + 0 }')
restored_tables=$(drill_sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME'")
[ "$dump_tables" -ge 10 ] || fail "Dump enthaelt nur $dump_tables Tabellen fuer $DB_NAME; das ist kein vollstaendiges Backup."
[ "$dump_tables" -eq "$restored_tables" ] \
  || fail "Tabellenzahl weicht ab: Dump $dump_tables, wiederhergestellt $restored_tables."
echo "Tabellen OK: $restored_tables Tabellen in $DB_NAME wiederhergestellt."

# --- 6. Migrationen + Konvergenz -------------------------------------------------
# run_php <app-key> <php-script> [args...] — Projekt-PHP im Drill-Netz mit den
# Restore-Verbindungsdaten; der Schluessel ist Parameter, damit die Krypto-Probe
# denselben Weg einmal mit dem gesicherten und einmal mit einem falschen
# APP_KEY gehen kann. Verbunden wird als App-User aus der archivierten .env,
# nie als root: genau so verbindet der Stack, und genau diese Faehigkeit hat
# der Drill frueher nie bewiesen. MYSQL_ROOT_PASSWORD ist nur gesetzt, weil
# EnvBoot seine Anwesenheit verlangt.
run_php() {
  _key="$1"; shift
  docker run --rm --network "$NET" \
    -v "$REPO_MOUNT:/repo" \
    -e DB_HOST="$MYSQL_NAME" -e DB_PORT=3306 -e DB_NAME="$DB_NAME" \
    -e DB_USER="$APP_DB_USER" -e DB_PASS="$APP_DB_PASS" -e MYSQL_ROOT_PASSWORD="$PW" \
    -e APP_KEY="$_key" "$PHP_IMAGE" php "$@"
}

echo "Lasse Migrationen gegen den Restore laufen ..."
run_php "$APP_KEY" /repo/Docker/WebAPI/lib/migrate.php || fail "Migrationen laufen auf dem Restore nicht durch."
run_php "$APP_KEY" /repo/Docker/WebAPI/lib/migrate.php --check | grep -q 'pending=0' \
  || fail "migrate --check meldet offene Migrationen nach dem Restore."
run_php "$APP_KEY" /repo/Docker/WebAPI/lib/directory_restore_converge.php \
  || fail "AD-Restore-Konvergenz konnte die Verzeichnisanmeldung nicht sicher deaktivieren."

echo "Pruefe Schema-Konvergenz gegen struktur.sql ..."
drill_sql "DROP DATABASE IF EXISTS vs_drill_fresh; CREATE DATABASE vs_drill_fresh CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >/dev/null
docker exec -i "$MYSQL_NAME" mysql -uroot -p"$ROOT_PW" vs_drill_fresh < Docker/mysql/mysql-init/struktur.sql 2>/dev/null \
  || fail "struktur.sql laedt nicht in eine frische Datenbank."
# Fingerprint ueber information_schema statt mysqldump: eine ueber Migrationen
# gewachsene Datenbank hat eine andere physische Spaltenreihenfolge (ADD COLUMN
# haengt hinten an) und explizite per-Spalte-Charsets im SHOW CREATE, ist aber
# formgleich. Verglichen wird die Form: Spalten mit Typ (inkl. order-exakter
# ENUM-Definition), NULL/Default/Extra/Collation, Indizes, FKs samt Regeln und
# Tabellenoptionen — sortiert, damit das Layout keine Rolle spielt.
schema_fingerprint() {
  docker exec -i "$MYSQL_NAME" mysql -uroot -p"$ROOT_PW" -N 2>/dev/null <<SQL
SELECT CONCAT_WS('|', 'col', table_name, column_name, column_type, is_nullable,
                 IFNULL(column_default, '<null>'), extra, IFNULL(collation_name, ''))
  FROM information_schema.columns WHERE table_schema = '$1'
 ORDER BY table_name, column_name;
SELECT CONCAT_WS('|', 'idx', table_name, index_name, non_unique, seq_in_index, column_name)
  FROM information_schema.statistics WHERE table_schema = '$1'
 ORDER BY table_name, index_name, seq_in_index;
SELECT CONCAT_WS('|', 'fk', k.table_name, k.constraint_name, k.column_name,
                 k.referenced_table_name, k.referenced_column_name, r.update_rule, r.delete_rule)
  FROM information_schema.key_column_usage k
  JOIN information_schema.referential_constraints r
    ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
 WHERE k.table_schema = '$1' AND k.referenced_table_name IS NOT NULL
 ORDER BY k.table_name, k.constraint_name, k.ordinal_position;
SELECT CONCAT_WS('|', 'tbl', table_name, engine, IFNULL(table_collation, ''))
  FROM information_schema.tables WHERE table_schema = '$1' AND table_type = 'BASE TABLE'
 ORDER BY table_name;
SQL
}
schema_fingerprint "$DB_NAME" > "$WORKDIR/schema-restored.txt"
schema_fingerprint vs_drill_fresh > "$WORKDIR/schema-fresh.txt"
[ -s "$WORKDIR/schema-fresh.txt" ] || envfail "Schema-Fingerprint der frischen Datenbank ist leer (Abfrage kaputt)."
diff -u "$WORKDIR/schema-fresh.txt" "$WORKDIR/schema-restored.txt" \
  || fail "Schema-Fingerprint weicht vom frischen struktur.sql ab (Drift auf dem gesicherten System)."

# --- 7. Invarianten, Rowcounts, Credential-Krypto -------------------------------
PROBE=/repo/Docker/WebAPI/tests/tools/restore-drill-probe.php
run_php "$APP_KEY" "$PROBE" verify || fail "Invarianten-/Krypto-Probe rot."
WRONG_KEY="base64:$(docker run --rm "$PHP_IMAGE" php -r 'echo base64_encode(random_bytes(32));')"
run_php "$WRONG_KEY" "$PROBE" expect-decrypt-failure \
  || fail "Ein falscher APP_KEY konnte gespeicherte Credentials entschluesseln."

# --- 8. App-Smoke gegen die wiederhergestellten Daten ---------------------------
echo "Starte Smoke-Server (php -S) gegen den Restore ..."
docker run -d --name "$SMOKE_NAME" --network "$NET" \
  -v "$REPO_MOUNT:/repo" -w /repo/Docker/WebAPI \
  -e DB_HOST="$MYSQL_NAME" -e DB_PORT=3306 -e DB_NAME="$DB_NAME" \
  -e DB_USER="$APP_DB_USER" -e DB_PASS="$APP_DB_PASS" -e MYSQL_ROOT_PASSWORD="$PW" \
  -e APP_KEY="$APP_KEY" \
  "$PHP_IMAGE" php -S 0.0.0.0:$SMOKE_PORT -t /repo/Docker/WebAPI >/dev/null

run_php "$APP_KEY" "$PROBE" seed-admin "$DRILL_ADMIN_USER" "$DRILL_ADMIN_PASS" >/dev/null \
  || fail "Drill-Admin liess sich nicht seeden."

docker run --rm --network "$NET" \
  -v "$REPO_MOUNT:/repo" \
  -e DB_HOST="$MYSQL_NAME" -e DB_PORT=3306 -e DB_NAME="$DB_NAME" \
  -e DB_USER=root -e DB_PASS="$PW" -e MYSQL_ROOT_PASSWORD="$PW" \
  -e APP_KEY="$APP_KEY" \
  -e DRILL_ADMIN_USER="$DRILL_ADMIN_USER" -e DRILL_ADMIN_PASS="$DRILL_ADMIN_PASS" \
  "$PHP_IMAGE" php "$PROBE" smoke "http://$SMOKE_NAME:$SMOKE_PORT" \
  || fail "App-Smoke gegen den Restore rot (health/login/machine API)."

# Drill-Spuren in der Wegwerf-DB entfernen, damit ein zweiter Lauf identisch ist.
run_php "$APP_KEY" "$PROBE" cleanup "$DRILL_ADMIN_USER" >/dev/null || true

echo "Restore-Drill OK: Manifest, Schema, Migrationen, Invarianten, APP_KEY-Bindung und App-Smoke bestanden ($restored_tables Tabellen, Stand $ts)."
