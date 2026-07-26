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
cd "$(dirname "$0")/.."

CONTAINER="${VIRTUSPHERE_MYSQL_CONTAINER:-virtusphere-v2-webapp-mysql-1}"
BACKUP_DIR="Docker/backups"
STATUS_DIR="$BACKUP_DIR/status"
STATUS_FILE="$STATUS_DIR/backup-status.jsonl"
LOCK_FILE="$BACKUP_DIR/.backup.lock"
CRON_FILE="${VIRTUSPHERE_BACKUP_CRON_FILE:-/etc/cron.d/virtusphere-backup}"
TIMER_UNIT="${VIRTUSPHERE_BACKUP_TIMER_UNIT:-virtusphere-backup.timer}"
KEEP=14
STATUS_KEEP_LINES=90

mkdir -p "$STATUS_DIR"

# Single-run guard: zwei parallele Backups duerfen nie dieselbe Retention/dasselbe
# Volume rennen. flock ist best-effort (fehlt es, laeuft der Lauf trotzdem).
exec 9>"$LOCK_FILE"
if command -v flock >/dev/null 2>&1; then
  if ! flock -n 9; then
    echo "FEHLER: Ein Backup laeuft bereits (Lock $LOCK_FILE)." >&2
    exit 1
  fi
fi

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
  ec=$?
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

  printf '{"ts":%s,"status":"%s","db_bytes":%s,"config_bytes":%s,"duration_s":%s,"keep":%s,"disk_free_pct":%s,"disk_free_bytes":%s,"error":"%s","schedule":"%s","schedule_tz":"%s","schedule_source":"%s","next_ts":%s}\n' \
    "$now" "$status" "$db_bytes" "$config_bytes" "$duration" "$KEEP" "$disk_free_pct" "$disk_free_bytes" "$esc_error" \
    "$esc_schedule" "$esc_schedule_tz" "$esc_schedule_source" "$next_ts" \
    >> "$STATUS_FILE" 2>/dev/null || true

  if [ -f "$STATUS_FILE" ]; then
    tail -n "$STATUS_KEEP_LINES" "$STATUS_FILE" > "$STATUS_FILE.tmp" 2>/dev/null \
      && mv "$STATUS_FILE.tmp" "$STATUS_FILE" || true
  fi
}
trap write_status EXIT

# Darf den Backup-Lauf nie verhindern: der Zeitplan ist Anzeige-Metadatum.
detect_schedule || true

ts=$(date +%Y%m%d-%H%M%S)

if ! docker exec "$CONTAINER" true >/dev/null 2>&1; then
  error="MySQL-Container '$CONTAINER' nicht erreichbar. Stack gestartet?"
  echo "FEHLER: $error" >&2
  exit 1
fi

db_file="$BACKUP_DIR/db-$ts.sql.gz"
docker exec "$CONTAINER" sh -c 'exec mysqldump --databases "$MYSQL_DATABASE" --routines --events --triggers --single-transaction -uroot -p"$MYSQL_ROOT_PASSWORD"' \
  | gzip > "$db_file"

# Plausibilitaet: ein leerer/abgebrochener Dump darf nicht als Erfolg zaehlen.
size=$(wc -c < "$db_file" | tr -d ' ')
if [ "$size" -lt 10240 ] || ! gunzip -t "$db_file" 2>/dev/null; then
  error="DB-Dump verdaechtig klein ($size Bytes) oder korrupt."
  echo "FEHLER: DB-Dump $db_file ist verdaechtig klein ($size Bytes) oder korrupt." >&2
  exit 1
fi
db_bytes=$size

config_file="$BACKUP_DIR/config-$ts.tar.gz"
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
# shellcheck disable=SC2086
tar czf "$config_file" $config_items
config_bytes=$(wc -c < "$config_file" | tr -d ' ')

# Manifest: ohne Hashes kann der Restore-Drill ein manipuliertes oder halb
# kopiertes Archiv nicht von einem intakten unterscheiden.
if ! command -v sha256sum >/dev/null 2>&1; then
  error="sha256sum fehlt; Backup ohne Manifest ist nicht verifizierbar."
  echo "FEHLER: $error" >&2
  exit 1
fi
( cd "$BACKUP_DIR" && sha256sum "db-$ts.sql.gz" "config-$ts.tar.gz" ) > "$BACKUP_DIR/manifest-$ts.sha256"

# Retention: je Lauf-Artefakt getrennt nur die neuesten $KEEP Laeufe behalten.
for pattern in 'db-*.sql.gz' 'config-*.tar.gz' 'manifest-*.sha256'; do
  # shellcheck disable=SC2086
  ls -1t "$BACKUP_DIR"/$pattern 2>/dev/null | tail -n +$((KEEP + 1)) | while IFS= read -r old; do
    rm -f "$old"
  done
done

status="ok"
echo "Backup OK: $db_file ($size Bytes), $config_file"
