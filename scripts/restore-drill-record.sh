#!/bin/sh
# Restore-Drill mit Nachweis fuer das Portal (docs/operations/backup.md,
# Abschnitt "Nachweis im Portal").
#
#   sudo sh scripts/restore-drill-record.sh
#   sudo VIRTUSPHERE_RESTORE_BACKUP_DIR=<Pfad> sh scripts/restore-drill-record.sh
#
# Startet scripts/restore_test.sh unveraendert, mit denselben Argumenten und
# derselben Umgebung, und reicht Ausgabe und Exitcode durch. Eigene
# Restore-Logik gibt es hier nicht (ADR-0017: restore_test.sh ist der Drill).
#
# Nur bei Exitcode 0 haengt es eine Zeile an <Backupverzeichnis>/status/
# restore-drill.jsonl an: Zeitpunkt, gepruefter Backupstand und Pruefroot-Commit.
# Das Backupverzeichnis liest es aus der Zeile "Restore-Drill Quelle" des Drills,
# also genau das Verzeichnis, aus dem der Drill gelesen hat. Ein Befund (1) oder
# eine fehlende Voraussetzung (2) schreibt nichts: Rot im Migrations-Probelauf
# ist ein Befund am neuen Stand, kein gescheiterter Restore.
#
# Exitcodes: die des Drills; zusaetzlich 2, wenn der Drill bestand, der
# Nachweis aber nicht geschrieben werden konnte (das Portal bleibt dann bei
# "unbekannt").
set -eu
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname "$0")" && pwd -P)
DRILL="$SCRIPT_DIR/restore_test.sh"
STATUS_NAME="restore-drill.jsonl"
STATUS_KEEP_LINES=90

envfail() {
  echo "FEHLER (Umgebung): $*" >&2
  exit 2
}

[ -f "$DRILL" ] || envfail "[drill-record.drill-missing] scripts/restore_test.sh fehlt neben diesem Skript."

work=$(umask 077 && mktemp -d "${TMPDIR:-/tmp}/virtusphere-drill-record.XXXXXX") \
  || envfail "[drill-record.tempdir] Arbeitsverzeichnis nicht anlegbar."
trap 'rm -rf "$work"' EXIT
trap 'exit 130' INT TERM

# Nur stdout wird mitgelesen; stderr bleibt unveraendert beim Aufrufer.
{ rc=0; sh "$DRILL" "$@" || rc=$?; echo "$rc" > "$work/rc"; } | tee "$work/out" || true
[ -s "$work/rc" ] || envfail "[drill-record.no-exit] Exitcode des Drills nicht ermittelbar."
rc=$(cat "$work/rc")
[ "$rc" = 0 ] || exit "$rc"

source_line=$(grep '^Restore-Drill Quelle: ' "$work/out" | tail -n 1) || true
select_line=$(grep '^Restore-Drill Auswahl: ' "$work/out" | tail -n 1) || true
backup_dir=$(printf '%s\n' "$source_line" | sed -n 's/^Restore-Drill Quelle: .*; Backupverzeichnis=\(.*\) (aus [A-Za-z_]*)$/\1/p')
root_commit=$(printf '%s\n' "$source_line" | sed -n 's/^Restore-Drill Quelle: Pruefroot-Commit=\(.*\); Backupverzeichnis=.*$/\1/p')
backup_ts=$(printf '%s\n' "$select_line" | sed -n 's/^Restore-Drill Auswahl: .*; Backup-Stand=\([0-9]\{8\}-[0-9]\{6\}\)$/\1/p')

notwritten() {
  echo "FEHLER (Umgebung): [drill-record.not-written] Der Drill hat bestanden, der Nachweis fuer das Portal wurde aber nicht geschrieben: $*" >&2
  exit 2
}
if [ -z "$backup_dir" ] || [ ! -d "$backup_dir" ]; then
  notwritten "Backupverzeichnis nicht aus der Drill-Ausgabe lesbar."
fi
[ -n "$backup_ts" ] || notwritten "Backupstand nicht aus der Drill-Ausgabe lesbar."

json_escape() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' | tr '\t\n\r' '   '
}

status_dir="$backup_dir/status"
status_file="$status_dir/$STATUS_NAME"
mkdir -p "$status_dir" || notwritten "Statusverzeichnis nicht anlegbar: $status_dir"
tmp=$(mktemp "$status_dir/.restore-drill.XXXXXX") || notwritten "Tempdatei nicht anlegbar: $status_dir"
{
  [ ! -f "$status_file" ] || tail -n $((STATUS_KEEP_LINES - 1)) "$status_file"
  printf '{"ts":%s,"status":"ok","backup_ts":"%s","root_commit":"%s"}\n' \
    "$(date +%s)" "$backup_ts" "$(json_escape "$root_commit")"
} > "$tmp" \
  && chmod 0644 "$tmp" \
  && mv -f "$tmp" "$status_file" \
  || { rm -f "$tmp"; notwritten "$status_file nicht schreibbar."; }

echo "Restore-Drill Nachweis: $status_file (Backup-Stand $backup_ts)"
