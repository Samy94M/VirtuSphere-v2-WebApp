#!/bin/sh
# scripts/backup.sh — VirtuSphere Vollbackup (DB + Konfiguration).
#
# Schreibt pro Lauf drei Dateien nach Docker/backups/ (gitignored):
#   1. db-<ts>.sql.gz        — die Anwendungsdatenbank (MYSQL_DATABASE aus der
#                              Container-Umgebung; Routines, Events, Trigger).
#                              Bewusst NICHT --all-databases: der fruehere
#                              Volldump nahm die mysql-Grant-Tabellen mit, und
#                              deren Import ueberschreibt auf dem Restore-Host
#                              die Grants des frisch angelegten App-Users -
#                              nach einer Passwortrotation kann die App dann
#                              nicht mehr verbinden, waehrend der Drill als
#                              root grün blieb. Der App-User selbst entsteht
#                              auf dem Zielhost aus der .env (MYSQL_USER/
#                              MYSQL_PASSWORD im Compose), nicht aus dem Dump.
#                              Alte --all-databases-Archive bleiben einspielbar;
#                              restore_test.sh erkennt sie und fuehrt den in
#                              docs/operations/backup.md dokumentierten
#                              Grant-Reparaturschritt aus.
#   2. config-<ts>.tar.gz    — .env, docker-compose.yml, docker-compose.override.yml
#                              (falls vorhanden; host-spezifisch und nicht in Git,
#                              aber ohne sie startet der Produktionsstack nicht),
#                              nginx-Konfiguration/SSL
#   3. manifest-<ts>.sha256  — SHA-256-Hashes beider Archive; der Restore-Drill
#                              (scripts/restore_test.sh) verifiziert sie vor dem
#                              Einspielen, ein Backup ohne Manifest ist fuer den
#                              Drill nicht verifizierbar (AP6/E5)
#
# Verschluesselung (K19, ADR-0017 Nachtrag 2026-10-11): Ist im Portal unter
# Einstellungen > Backup ein Zertifikat hinterlegt, heissen 1. und 2.
# db-<ts>.sql.gz.cms und config-<ts>.tar.gz.cms, jeweils getrennt per
# `openssl cms` an den oeffentlichen Schluessel verschluesselt
# (scripts/lib/backup_crypto.sh). Der private Schluessel liegt nie auf diesem
# Host. Ohne Zertifikat bleibt alles unverschluesselt und das Portal meldet es.
# Optionaler Pin (K19-A3): steht in .env VIRTUSPHERE_BACKUP_RECIPIENT_SHA256, muss
# er zum Zertifikat im Portal passen. Abweichung, fehlendes Zertifikat oder ein
# ungueltiger Wert lassen den Lauf scheitern. Gelesen wird nur .env.
# Klartext-Tempdateien eines frueher abgebrochenen Laufs raeumt der naechste Lauf
# unter dem Lock auf (K19-A1).
#
# Zusaetzlich schreibt jeder Lauf eine JSONL-Statuszeile nach
# Docker/backups/status/backup-status.jsonl. Dieses Status-Verzeichnis (und nur
# dieses, nie die Dumps) wird read-only in den PHP-Container gemountet, damit das
# Portal Backup-Karte + Dashboard-Banner anzeigen kann (ADR-0021).
#
# Das Passwort kommt aus der Container-Umgebung (MYSQL_ROOT_PASSWORD via
# env_file), nichts wird auf der Kommandozeile des Hosts sichtbar.
#
# Retention: von jeder der beiden Dateien bleiben die neuesten $KEEP Laeufe
# erhalten (Default 14). Bei taeglichem Lauf reicht der Rueckgriff also rund
# 14 Tage zurueck. $KEEP ist die SSoT fuer diesen Wert; Portal-Karte und Hilfe
# lesen ihn aus der Statuszeile bzw. nennen ihn als Standard.
#
# Zeitplan (ADR-0024): jeder Lauf meldet in der Statuszeile den Zeitplan mit,
# unter dem er gestartet wurde. Das Portal rechnet daraus den naechsten Lauf
# aus, statt ihn zu schaetzen. Gelesen wird dabei der Ist-Zustand (systemd-Timer
# bzw. Cron-Datei), nicht ein separat gepflegter Sollwert; die Anzeige kann
# also nicht vom tatsaechlichen Zeitplan abweichen.
#
# Einrichten: sh scripts/install-backup-schedule.sh (schreibt /etc/cron.d/virtusphere-backup).
# Restore-Probe: sh scripts/restore_test.sh (siehe docs/operations/backup.md).
set -eu
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname "$0")" && pwd -P)
cd "$SCRIPT_DIR/.."

# Gemeinsame Helfer: .env-Werte lesen (env_file.sh), Verschluesselung und Pin
# (backup_crypto.sh). Eine fehlende Datei ist eine unvollstaendige Installation
# und bricht vor dem ersten Schreiben ab.
for lib in env_file.sh backup_crypto.sh; do
  [ -f "$SCRIPT_DIR/lib/$lib" ] || { echo "FEHLER: scripts/lib/$lib fehlt." >&2; exit 1; }
done
# shellcheck disable=SC1091
. "$SCRIPT_DIR/lib/env_file.sh"
# shellcheck disable=SC1091
. "$SCRIPT_DIR/lib/backup_crypto.sh"

CONTAINER="${VIRTUSPHERE_MYSQL_CONTAINER:-virtusphere-v2-webapp-mysql-1}"
BACKUP_DIR="Docker/backups"
STATUS_DIR="$BACKUP_DIR/status"
STATUS_FILE="$STATUS_DIR/backup-status.jsonl"
LOCK_FILE="$BACKUP_DIR/.backup.lock"
CRON_FILE="${VIRTUSPHERE_BACKUP_CRON_FILE:-/etc/cron.d/virtusphere-backup}"
TIMER_UNIT="${VIRTUSPHERE_BACKUP_TIMER_UNIT:-virtusphere-backup.timer}"
KEEP=14
STATUS_KEEP_LINES=90
BACKUP_PROGRESS_TOTAL=6
backup_progress_position=0
backup_progress_unit=""
db_sql_file=""
db_file=""
config_file=""
manifest_file=""
db_gz_file=""
config_tmp_file=""
cert_dir=""
encrypted=false
recipient_sha256=""
lock_taken=0

# Klartext-Tempdateien (K19-A1): das Praefix steht genau hier. mktemp und das
# Aufraeumen unter dem Lock nutzen dieselben Werte.
TMP_PREFIX_DB=".db-"
TMP_PREFIX_CONFIG=".config-"
TMP_PREFIX_CERT=".cert-"

backup_progress_run() {
  backup_progress_position=$1
  backup_progress_unit=$2
  echo "[$backup_progress_position/$BACKUP_PROGRESS_TOTAL] RUN $backup_progress_unit"
}
backup_progress_result() {
  echo "[$backup_progress_position/$BACKUP_PROGRESS_TOTAL] $1 $backup_progress_unit"
  backup_progress_unit=""
}
backup_fail() {
  [ -z "$backup_progress_unit" ] || backup_progress_result fail
  error=$1
  echo "FEHLER: $error" >&2
  exit 1
}

mkdir -p "$STATUS_DIR"

# Single-run guard: zwei parallele Backups duerfen nie dieselbe Retention/dasselbe
# Volume rennen. flock ist best-effort (fehlt es, laeuft der Lauf trotzdem).
exec 9>"$LOCK_FILE"
if command -v flock >/dev/null 2>&1; then
  if ! flock -n 9; then
    echo "FEHLER: Ein Backup laeuft bereits (Lock $LOCK_FILE)." >&2
    exit 1
  fi
  lock_taken=1
fi

# K19-A1: Ein Lauf, der nicht mehr aufraeumen konnte (Stromausfall, OOM-Killer,
# kill -9), laesst Klartext-Tempdateien zurueck, weil der EXIT-Trap dann nicht
# lief. Der naechste Lauf entfernt sie, aber nur unter der Sperre: ein paralleler
# Lauf wuerde sonst seine eigenen Tempdateien verlieren. Ohne flock wird nur
# gewarnt. Glob statt find (unter Git Bash kann find.exe greifen); Tiefe 1, keine
# Links. Ein .cert-Verzeichnis darf rekursiv weg, aber nur wenn es kein Link ist.
sweep_orphan_plain_temps() {
  set --
  for prefix in "$TMP_PREFIX_DB" "$TMP_PREFIX_CONFIG" "$TMP_PREFIX_CERT"; do
    for entry in "$BACKUP_DIR/$prefix"*; do
      [ -e "$entry" ] || [ -L "$entry" ] || continue
      [ ! -L "$entry" ] || continue
      if [ -f "$entry" ]; then
        set -- "$@" "$entry"
      elif [ -d "$entry" ] && [ "$prefix" = "$TMP_PREFIX_CERT" ]; then
        set -- "$@" "$entry"
      fi
    done
  done
  [ "$#" -gt 0 ] || return 0
  if [ "$lock_taken" -ne 1 ]; then
    echo "WARNUNG: flock fehlt; $# verwaiste Klartext-Tempdatei(en) in $BACKUP_DIR bleiben liegen. Aufraeumen nur unter der Sperre." >&2
    return 0
  fi
  removed=0
  for entry in "$@"; do
    if [ -d "$entry" ]; then
      rm -rf "$entry" || { echo "WARNUNG: verwaiste Klartext-Tempdatei $entry konnte nicht entfernt werden." >&2; continue; }
    else
      rm -f "$entry" || { echo "WARNUNG: verwaiste Klartext-Tempdatei $entry konnte nicht entfernt werden." >&2; continue; }
    fi
    removed=$((removed + 1))
  done
  if [ "$removed" -gt 0 ]; then
    echo "$removed verwaiste Klartext-Tempdateien entfernt. Auch den Backup-Host pruefen (docs/operations/backup.md)."
  fi
  return 0
}
sweep_orphan_plain_temps

start_epoch=$(date +%s)
status="failed"
db_bytes=0
config_bytes=0
error=""
schedule=""
schedule_tz=""
schedule_source=""
next_ts=0

# Minimales JSON-String-Escaping fuer das error-Feld.
json_escape() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' | tr '\t\n\r' '   '
}

# Zeitzone, in der der Scheduler rechnet. Cron nutzt die Systemzone, sofern die
# Cron-Datei nicht per CRON_TZ etwas anderes vorgibt.
detect_timezone() {
  if [ -n "${TZ:-}" ]; then
    printf '%s' "$TZ"
  elif [ -L /etc/localtime ]; then
    readlink /etc/localtime | sed -e 's#.*/zoneinfo/##'
  elif [ -r /etc/timezone ]; then
    head -n 1 /etc/timezone
  else
    # Fallback: fester Offset. DateTimeZone versteht "+02:00" ebenso.
    date +%:z 2>/dev/null || date +%z
  fi
}

# Erste Cron-Zeile einer Datei bzw. eines crontab-Dumps, die backup.sh startet.
# Liefert die Zeitplanfelder (5 Felder oder ein @-Kuerzel), sonst nichts.
cron_line_schedule() {
  line=$(grep -v '^[[:space:]]*#' 2>/dev/null | grep 'backup\.sh' | head -n 1) || true
  [ -n "${line:-}" ] || return 1
  case "$(printf '%s' "$line" | awk '{print $1}')" in
    @*) printf '%s' "$line" | awk '{print $1}' ;;
    *)  printf '%s' "$line" | awk '{print $1, $2, $3, $4, $5}' ;;
  esac
}

# Reihenfolge: explizite Vorgabe > systemd-Timer (kennt den naechsten Lauf
# exakt) > Cron-Datei > Benutzer-crontab. Faellt alles aus, bleibt der Zeitplan
# leer und das Portal zeigt eine Schaetzung, klar als solche gekennzeichnet.
detect_schedule() {
  schedule_tz=$(detect_timezone 2>/dev/null || printf 'UTC')

  if [ -n "${VIRTUSPHERE_BACKUP_SCHEDULE:-}" ]; then
    schedule="$VIRTUSPHERE_BACKUP_SCHEDULE"
    schedule_source="VIRTUSPHERE_BACKUP_SCHEDULE"
    return 0
  fi

  if command -v systemctl >/dev/null 2>&1; then
    next_raw=$(systemctl show "$TIMER_UNIT" -p NextElapseUSecRealtime --value 2>/dev/null || true)
    if [ -n "${next_raw:-}" ] && [ "$next_raw" != "n/a" ]; then
      if next_epoch=$(date -d "$next_raw" +%s 2>/dev/null); then
        next_ts="$next_epoch"
        schedule_source="systemd: $TIMER_UNIT"
        return 0
      fi
    fi
  fi

  if [ -r "$CRON_FILE" ]; then
    if found=$(cron_line_schedule < "$CRON_FILE"); then
      schedule="$found"
      schedule_source="$CRON_FILE"
      # Cron-Dateien duerfen ihre eigene Zeitzone setzen.
      tz_line=$(grep -E '^[[:space:]]*CRON_TZ[[:space:]]*=' "$CRON_FILE" | head -n 1) || true
      if [ -n "${tz_line:-}" ]; then
        schedule_tz=$(printf '%s' "$tz_line" | sed -e 's/.*=[[:space:]]*//' -e 's/[[:space:]]*$//')
      fi
      return 0
    fi
  fi

  if command -v crontab >/dev/null 2>&1; then
    if found=$(crontab -l 2>/dev/null | cron_line_schedule); then
      schedule="$found"
      schedule_source="crontab -l ($(id -un 2>/dev/null || printf '?'))"
      return 0
    fi
  fi

  return 0
}

# Wird via EXIT-Trap immer aufgerufen (Erfolg wie Abbruch) und haengt die
# JSONL-Statuszeile an, dann kappt es die Datei auf die neuesten Zeilen.
write_status() {
  ec=$1
  now=$(date +%s)
  duration=$(( now - start_epoch ))

  disk_free_bytes=0
  disk_free_pct=0
  if df_line=$(df -Pk "$BACKUP_DIR" 2>/dev/null | tail -n 1); then
    avail_kb=$(printf '%s\n' "$df_line" | awk '{print $4}')
    used_kb=$(printf '%s\n' "$df_line" | awk '{print $3}')
    if [ -n "${avail_kb:-}" ] && [ -n "${used_kb:-}" ]; then
      disk_free_bytes=$(( avail_kb * 1024 ))
      total_kb=$(( used_kb + avail_kb ))
      [ "$total_kb" -gt 0 ] && disk_free_pct=$(( avail_kb * 100 / total_kb ))
    fi
  fi

  if [ "$status" != "ok" ] && [ -z "$error" ]; then
    error="Backup fehlgeschlagen (Exit $ec)."
  fi
  esc_error=$(json_escape "$error")
  esc_schedule=$(json_escape "$schedule")
  esc_schedule_tz=$(json_escape "$schedule_tz")
  esc_schedule_source=$(json_escape "$schedule_source")

  printf '{"ts":%s,"status":"%s","db_bytes":%s,"config_bytes":%s,"duration_s":%s,"keep":%s,"disk_free_pct":%s,"disk_free_bytes":%s,"error":"%s","schedule":"%s","schedule_tz":"%s","schedule_source":"%s","next_ts":%s,"encrypted":%s,"recipient_sha256":"%s"}\n' \
    "$now" "$status" "$db_bytes" "$config_bytes" "$duration" "$KEEP" "$disk_free_pct" "$disk_free_bytes" "$esc_error" \
    "$esc_schedule" "$esc_schedule_tz" "$esc_schedule_source" "$next_ts" "$encrypted" "$recipient_sha256" \
    >> "$STATUS_FILE" 2>/dev/null || true

  if [ -f "$STATUS_FILE" ]; then
    tail -n "$STATUS_KEEP_LINES" "$STATUS_FILE" > "$STATUS_FILE.tmp" 2>/dev/null \
      && mv "$STATUS_FILE.tmp" "$STATUS_FILE" || true
  fi
}
finish_backup() {
  ec=$?
  trap - EXIT INT TERM
  [ -z "$backup_progress_unit" ] || backup_progress_result fail
  cleanup_failed=0
  # Klartext-Tempdateien verschwinden immer, auch nach Erfolg: verschluesselt
  # darf kein lesbarer Dump und kein lesbares Config-Archiv liegen bleiben.
  for plain in "$db_sql_file" "$db_gz_file" "$config_tmp_file"; do
    [ -z "$plain" ] || [ ! -e "$plain" ] || rm -f "$plain" || cleanup_failed=1
  done
  if [ -n "$cert_dir" ] && [ -d "$cert_dir" ]; then
    rm -rf "$cert_dir" || cleanup_failed=1
  fi
  if [ "$status" != ok ]; then
    for partial in "$db_file" "$config_file" "$manifest_file"; do
      [ -z "$partial" ] || [ ! -e "$partial" ] || rm -f "$partial" || cleanup_failed=1
    done
  fi
  if [ "$cleanup_failed" -ne 0 ]; then
    status="failed"
    error="Partielle Backup-Artefakte konnten nicht bereinigt werden."
    [ "$ec" -ne 0 ] || ec=1
  fi
  write_status "$ec"
  exit "$ec"
}
trap finish_backup EXIT
trap 'exit 130' INT TERM

# Darf den Backup-Lauf nie verhindern: der Zeitplan ist Anzeige-Metadatum.
detect_schedule || true

ts=$(date +%Y%m%d-%H%M%S)

backup_progress_run 1 database-dump
if ! docker exec "$CONTAINER" true >/dev/null 2>&1; then
  backup_fail "MySQL-Container '$CONTAINER' nicht erreichbar. Stack gestartet?"
fi

db_sql_file=$(umask 077 && mktemp "$BACKUP_DIR/${TMP_PREFIX_DB}${ts}-XXXXXX.sql") \
  || backup_fail "Geschuetzte SQL-Tempdatei konnte nicht erzeugt werden."
chmod 600 "$db_sql_file" \
  || backup_fail "SQL-Tempdatei konnte nicht auf Modus 0600 begrenzt werden."
if ! docker exec "$CONTAINER" sh -c 'exec mysqldump --databases "$MYSQL_DATABASE" --routines --events --triggers --single-transaction -uroot -p"$MYSQL_ROOT_PASSWORD"' > "$db_sql_file"; then
  backup_fail "mysqldump schlug fehl; partieller Dump wird verworfen."
fi
# Auch der komprimierte Dump entsteht zuerst geschuetzt; erst Schritt 4 legt
# ihn - verschluesselt oder nicht - unter seinem endgueltigen Namen ab.
db_gz_file=$(umask 077 && mktemp "$BACKUP_DIR/${TMP_PREFIX_DB}${ts}-XXXXXX.gz") \
  || backup_fail "Geschuetzte Dump-Tempdatei konnte nicht erzeugt werden."
if ! gzip -c "$db_sql_file" > "$db_gz_file"; then
  backup_fail "Komprimierung des DB-Dumps schlug fehl."
fi
rm -f "$db_sql_file" || backup_fail "Roher SQL-Dump konnte nicht entfernt werden."
db_sql_file=""

# Plausibilitaet: ein leerer/abgebrochener Dump darf nicht als Erfolg zaehlen.
size=$(wc -c < "$db_gz_file" | tr -d ' ')
if [ "$size" -lt 10240 ] || ! gunzip -t "$db_gz_file" 2>/dev/null; then
  backup_fail "DB-Dump ist verdaechtig klein ($size Bytes) oder korrupt."
fi
backup_progress_result pass

# K19 (VT-E2): Ist im Portal ein Zertifikat hinterlegt, werden Dump und
# Config-Archiv getrennt daran verschluesselt. Das Config-Archiv traegt den
# APP_KEY, der Dump die damit verschluesselten Zugangsdaten. Kann die Abfrage
# nicht beantwortet werden, schlaegt der Lauf fehl: lieber kein Backup als ein
# unverschluesseltes, das niemand bestellt hat. Ohne Zertifikat bleibt alles
# wie bisher, die Statuszeile meldet dann encrypted=false.
backup_progress_run 2 encryption-key
cert_value=$(docker exec "$CONTAINER" sh -c 'exec mysql -N -B -r -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "$1"' sh \
  "SELECT setting_value FROM deploy_settings WHERE setting_key = 'backup_encryption_certificate'" 2>/dev/null) \
  || backup_fail "Das Zertifikat fuer die Backup-Verschluesselung konnte nicht gelesen werden; es wird nichts unverschluesselt abgelegt."
# K19-A3: Der Fingerabdruck des Zertifikats darf optional in .env festgeschrieben
# sein. Gelesen wird nur .env, nie die Prozessumgebung. Ein gesetzter Wert zaehlt
# immer, auch ein ungueltiger: er ist nie "nicht gesetzt".
pin_raw=$(env_file_value .env VIRTUSPHERE_BACKUP_RECIPIENT_SHA256)
pin_probe=$(printf '%s' "$pin_raw" | tr -d ' \t\r"\047')
pin_sha256=""
if [ -n "$pin_probe" ]; then
  pin_sha256=$(backup_crypto_normalize_pin "$pin_raw")
  [ -n "$pin_sha256" ] \
    || backup_fail "VIRTUSPHERE_BACKUP_RECIPIENT_SHA256 in .env ist kein SHA-256-Fingerabdruck (64 Hexzeichen); es wird nichts abgelegt."
fi
if [ -z "$cert_value" ] && [ -n "$pin_sha256" ]; then
  backup_fail "VIRTUSPHERE_BACKUP_RECIPIENT_SHA256 ist gesetzt, im Portal ist aber kein Zertifikat hinterlegt; es wird nichts unverschluesselt abgelegt."
fi
if [ -n "$cert_value" ]; then
  command -v openssl >/dev/null 2>&1 \
    || backup_fail "openssl fehlt; das hinterlegte Zertifikat verlangt ein verschluesseltes Backup."
  cert_dir=$(umask 077 && mktemp -d "$BACKUP_DIR/${TMP_PREFIX_CERT}${ts}-XXXXXX") \
    || backup_fail "Temporaeres Verzeichnis fuer das Zertifikat konnte nicht erzeugt werden."
  cert_file="$cert_dir/backup-cert.pem"
  printf '%s\n' "$cert_value" > "$cert_file" \
    || backup_fail "Zertifikat konnte nicht zwischengespeichert werden."
  recipient_sha256=$(backup_crypto_fingerprint "$cert_file")
  [ -n "$recipient_sha256" ] \
    || backup_fail "Das hinterlegte Zertifikat ist nicht lesbar; es wird nichts unverschluesselt abgelegt."
  if [ -n "$pin_sha256" ] && [ "$pin_sha256" != "$recipient_sha256" ]; then
    backup_fail "VIRTUSPHERE_BACKUP_RECIPIENT_SHA256 in .env ($pin_sha256) passt nicht zum Zertifikat im Portal ($recipient_sha256); es wird nichts abgelegt."
  fi
  encrypted=true
fi
backup_progress_result pass

backup_progress_run 3 config-archive
config_items="docker-compose.yml"
[ -f .env ] && config_items="$config_items .env"
# docker-compose.override.yml ist host-spezifisch und NICHT in Git. Genau deshalb
# muss es hier mit: der Produktionshost braucht es zum Starten (Subnetz-Pin, damit
# der Docker-Bridge das SSH nicht abschneidet, geleerte Proxy-Umgebung je Service),
# und ein Restore ohne diese Datei bringt den Stack nicht hoch, obwohl beide
# Archive intakt sind. Bedingt, damit ein Dev-Host ohne Override weiter ein
# gueltiges Archiv erzeugt.
[ -f docker-compose.override.yml ] && config_items="$config_items docker-compose.override.yml"
[ -d Docker/nginx/conf.d ] && config_items="$config_items Docker/nginx/conf.d"
[ -d Docker/nginx/ssl ] && config_items="$config_items Docker/nginx/ssl"
config_tmp_file=$(umask 077 && mktemp "$BACKUP_DIR/${TMP_PREFIX_CONFIG}${ts}-XXXXXX.gz") \
  || backup_fail "Geschuetzte Config-Tempdatei konnte nicht erzeugt werden."
# shellcheck disable=SC2086
tar czf "$config_tmp_file" $config_items \
  || backup_fail "Config-Archiv konnte nicht erstellt werden."
backup_progress_result pass

# Endgueltige Namen entstehen erst hier. Verschluesselt heissen sie
# db-<ts>.sql.gz.cms und config-<ts>.tar.gz.cms; die Klartext-Tempdateien
# raeumt finish_backup in jedem Fall weg.
backup_progress_run 4 store-archives
if [ "$encrypted" = true ]; then
  db_file="$BACKUP_DIR/db-$ts.sql.gz.cms"
  config_file="$BACKUP_DIR/config-$ts.tar.gz.cms"
  backup_crypto_encrypt "$cert_file" "$db_gz_file" "$db_file" 2>/dev/null \
    || backup_fail "Verschluesselung des DB-Dumps schlug fehl."
  backup_crypto_encrypt "$cert_file" "$config_tmp_file" "$config_file" 2>/dev/null \
    || backup_fail "Verschluesselung des Config-Archivs schlug fehl."
else
  db_file="$BACKUP_DIR/db-$ts.sql.gz"
  config_file="$BACKUP_DIR/config-$ts.tar.gz"
  mv "$db_gz_file" "$db_file" || backup_fail "DB-Dump konnte nicht abgelegt werden."
  db_gz_file=""
  mv "$config_tmp_file" "$config_file" || backup_fail "Config-Archiv konnte nicht abgelegt werden."
  config_tmp_file=""
fi
db_bytes=$(wc -c < "$db_file" | tr -d ' ') \
  || backup_fail "Groesse des DB-Dumps konnte nicht gelesen werden."
config_bytes=$(wc -c < "$config_file" | tr -d ' ') \
  || backup_fail "Groesse des Config-Archivs konnte nicht gelesen werden."
backup_progress_result pass

backup_progress_run 5 manifest

# Manifest: ohne Hashes kann der Restore-Drill ein manipuliertes oder halb
# kopiertes Archiv nicht von einem intakten unterscheiden.
if ! command -v sha256sum >/dev/null 2>&1; then
  backup_fail "sha256sum fehlt; Backup ohne Manifest ist nicht verifizierbar."
fi
manifest_file="$BACKUP_DIR/manifest-$ts.sha256"
( cd "$BACKUP_DIR" && sha256sum "$(basename "$db_file")" "$(basename "$config_file")" ) > "$manifest_file" \
  || backup_fail "Hash-Manifest konnte nicht erstellt werden."
backup_progress_result pass

backup_progress_run 6 retention

# Retention: je Lauf-Artefakt getrennt nur die neuesten $KEEP Laeufe behalten.
# Klartext- und verschluesselte Laeufe bilden eine Reihe, damit alte
# unverschluesselte Backups nach $KEEP verschluesselten Laeufen verschwinden.
backup_retain() {
  ls -1t "$@" 2>/dev/null | tail -n +$((KEEP + 1)) | while IFS= read -r old; do
    rm -f "$old"
  done
}
backup_retain "$BACKUP_DIR"/db-*.sql.gz "$BACKUP_DIR"/db-*.sql.gz.cms
backup_retain "$BACKUP_DIR"/config-*.tar.gz "$BACKUP_DIR"/config-*.tar.gz.cms
backup_retain "$BACKUP_DIR"/manifest-*.sha256
backup_progress_result pass

status="ok"
echo "Backup OK: $db_file ($db_bytes Bytes), $config_file"
