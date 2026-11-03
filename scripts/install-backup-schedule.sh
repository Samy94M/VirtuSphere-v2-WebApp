#!/bin/sh
# scripts/install-backup-schedule.sh — richtet den Backup-Cron auf dem Docker-Host ein.
#
# Der Zeitplan lebt an genau einer Stelle: /etc/cron.d/virtusphere-backup.
# scripts/backup.sh liest genau diese Datei zur Laufzeit wieder aus und meldet
# den gefundenen Zeitplan in der Statuszeile. Die Backup-Karte im Portal zeigt
# damit den echten Zeitplan und den echten naechsten Lauf; ein zweiter,
# separat gepflegter Sollwert existiert nicht und kann folglich nicht driften
# (ADR-0024).
#
# Aufrufe:
#   sudo sh scripts/install-backup-schedule.sh                       # taeglich 06:00
#   sudo sh scripts/install-backup-schedule.sh --schedule "30 2 * * *"
#   sh scripts/install-backup-schedule.sh --show                     # aktuellen Eintrag zeigen
#   sudo sh scripts/install-backup-schedule.sh --remove
#
# Danach einmal manuell pruefen: sh scripts/backup.sh
set -eu

PROJECT_DIR=$(cd "$(dirname "$0")/.." && pwd)
CRON_FILE="${VIRTUSPHERE_BACKUP_CRON_FILE:-/etc/cron.d/virtusphere-backup}"
LOG_FILE="${VIRTUSPHERE_BACKUP_LOG_FILE:-/var/log/virtusphere-backup.log}"
SCHEDULE="0 6 * * *"
RUN_AS="root"
action="install"

usage() {
  cat <<USAGE
Usage: sh scripts/install-backup-schedule.sh [Optionen]

  --schedule "M H DOM MON DOW"  Cron-Zeitplan (Standard: "$SCHEDULE")
  --user NAME                   Benutzer fuer den Cron-Eintrag (Standard: $RUN_AS)
  --show                        Vorhandenen Eintrag anzeigen und beenden
  --remove                      Eintrag entfernen
  -h, --help                    Diese Hilfe
USAGE
}

while [ $# -gt 0 ]; do
  case "$1" in
    --schedule) [ $# -ge 2 ] || { echo "FEHLER: --schedule braucht einen Wert." >&2; exit 2; }; SCHEDULE="$2"; shift 2 ;;
    --user)     [ $# -ge 2 ] || { echo "FEHLER: --user braucht einen Wert." >&2; exit 2; }; RUN_AS="$2"; shift 2 ;;
    --show)     action="show"; shift ;;
    --remove)   action="remove"; shift ;;
    -h|--help)  usage; exit 0 ;;
    *)          echo "FEHLER: Unbekanntes Argument '$1'." >&2; usage >&2; exit 2 ;;
  esac
done

if [ "$action" = "show" ]; then
  if [ -r "$CRON_FILE" ]; then
    echo "Zeitplan-Datei: $CRON_FILE"
    echo "---"
    cat "$CRON_FILE"
  else
    echo "Kein Eintrag: $CRON_FILE existiert nicht oder ist nicht lesbar." >&2
    echo "Einrichten mit: sudo sh scripts/install-backup-schedule.sh" >&2
    exit 1
  fi
  exit 0
fi

if [ "$(id -u)" -ne 0 ]; then
  echo "FEHLER: $CRON_FILE schreiben erfordert root. Mit sudo erneut aufrufen." >&2
  exit 1
fi

if [ "$action" = "remove" ]; then
  rm -f "$CRON_FILE"
  echo "Entfernt: $CRON_FILE"
  echo "Die Backup-Karte im Portal wird nach Ablauf der Kulanzzeit 'Veraltet' melden."
  exit 0
fi

# 5 Felder, sonst laeuft der Cron nie und die Karte zeigt eine Schaetzung.
field_count=$(printf '%s\n' "$SCHEDULE" | awk '{print NF}')
if [ "$field_count" -ne 5 ]; then
  echo "FEHLER: Zeitplan '$SCHEDULE' hat $field_count statt 5 Felder (Minute Stunde Tag Monat Wochentag)." >&2
  exit 2
fi

if ! id "$RUN_AS" >/dev/null 2>&1; then
  echo "FEHLER: Benutzer '$RUN_AS' existiert nicht." >&2
  exit 2
fi

if [ ! -x "$PROJECT_DIR/scripts/backup.sh" ] && [ ! -r "$PROJECT_DIR/scripts/backup.sh" ]; then
  echo "FEHLER: $PROJECT_DIR/scripts/backup.sh nicht gefunden." >&2
  exit 1
fi

umask 022
cat > "$CRON_FILE" <<CRON
# VirtuSphere Backup — erzeugt von scripts/install-backup-schedule.sh.
# Nicht von Hand doppelt pflegen: dieser Eintrag ist die einzige Quelle des
# Zeitplans. Das Backup-Skript liest ihn zurueck und meldet ihn ans Portal.
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
$SCHEDULE $RUN_AS cd "$PROJECT_DIR" && sh scripts/backup.sh >> "$LOG_FILE" 2>&1
CRON
chmod 0644 "$CRON_FILE"
chown root:root "$CRON_FILE" 2>/dev/null || true

touch "$LOG_FILE" 2>/dev/null || true

echo "Geschrieben: $CRON_FILE"
echo "  Zeitplan:  $SCHEDULE (Zeitzone des Hosts)"
echo "  Benutzer:  $RUN_AS"
echo "  Verzeichnis: $PROJECT_DIR"
echo "  Log:       $LOG_FILE"
echo
echo "Naechster Schritt: einmal 'sh scripts/backup.sh' laufen lassen."
echo "Die Backup-Karte im Portal zeigt danach Zeitplan, Quelle und naechsten Lauf."
