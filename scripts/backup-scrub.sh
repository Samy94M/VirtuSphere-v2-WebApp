#!/bin/sh
# Pruefsummen-Scrub der vorhandenen Backups (docs/operations/backup.md,
# Abschnitt "Pruefsummen-Scrub").
#
#   sudo sh scripts/backup-scrub.sh
#
# Liest jedes Manifest Docker/backups/manifest-<ts>.sha256 und vergleicht die
# Ist-Hashes der gelisteten Archive mit den Hashes, die scripts/backup.sh beim
# Lauf geschrieben hat. Ein Satz gilt nur, wenn sein Manifest genau den Dump und
# das Konfigurationsarchiv desselben Zeitstempels nennt (wie im Restore-Drill).
#
# Nur lesend: kein Backup wird geaendert, verschoben oder geloescht, nichts wird
# entschluesselt (das Manifest hasht bei verschluesselten Saetzen die
# .cms-Dateien). Geschrieben wird nur das Ergebnis, atomar ersetzt, nach
# Docker/backups/status/backup-scrub.json. Das Portal zeigt es im Systemstatus
# unter "Interne Dienste" (lib/backup_evidence.php).
#
# Keine Backup-Sperre: backup.sh bricht ab, wenn die Sperre belegt ist, und ein
# Scrub darf nie ein Backup verhindern. Rotiert backup.sh waehrend des Scrubs
# einen alten Satz weg, zaehlt dieser Satz nicht (Manifest ebenfalls weg).
#
# Exitcodes: 0 alle Saetze stimmen; 1 mindestens ein Satz weicht ab oder ist
# unvollstaendig; 2 Umgebung unvollstaendig (kein Satz, sha256sum fehlt, Datei
# nicht lesbar, Ergebnis nicht schreibbar). Bei 2 bleibt das alte Ergebnis stehen.
set -eu
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname "$0")" && pwd -P)
cd "$SCRIPT_DIR/.."

BACKUP_DIR="Docker/backups"
STATUS_DIR="$BACKUP_DIR/status"
STATUS_FILE="$STATUS_DIR/backup-scrub.json"

envfail() {
  echo "FEHLER (Umgebung): $*" >&2
  exit 2
}

command -v sha256sum >/dev/null 2>&1 \
  || envfail "[scrub.sha256sum-missing] sha256sum fehlt; ohne Hash ist kein Satz pruefbar."
[ -d "$BACKUP_DIR" ] \
  || envfail "[scrub.no-backup] Backupverzeichnis fehlt: $BACKUP_DIR"

total=0
for manifest in "$BACKUP_DIR"/manifest-*.sha256; do
  [ -e "$manifest" ] || continue
  total=$((total + 1))
done
[ "$total" -gt 0 ] \
  || envfail "[scrub.no-backup] Kein Manifest in $BACKUP_DIR; es gibt nichts zu pruefen."

start_epoch=$(date +%s)
position=0
checked=0
failed=0
failed_sets=""
echo "[0/$total] Pruefsummen-Scrub: $total Saetze in $BACKUP_DIR"

# Prueft einen Satz. Rueckgabe 0 stimmt, 1 Befund, 3 Satz waehrend des Laufs
# wegrotiert. Nicht lesbare Dateien enden sofort als Umgebungsfehler.
scrub_set() {
  manifest=$1
  set_ts=$2
  [ -r "$manifest" ] \
    || envfail "[scrub.unreadable] Manifest nicht lesbar: $manifest (mit sudo starten)."
  lines=0
  db_seen=0
  config_seen=0
  result=0
  while IFS= read -r line || [ -n "$line" ]; do
    [ -n "$line" ] || continue
    lines=$((lines + 1))
    expected=$(printf '%s' "$line" | cut -c1-64 | tr 'A-F' 'a-f')
    name=$(printf '%s' "$line" | cut -c67-)
    case "$expected" in
      *[!0-9a-f]*) echo "Satz $set_ts: Manifestzeile ohne SHA-256-Hash." >&2; result=1; continue ;;
    esac
    [ "${#expected}" -eq 64 ] || { echo "Satz $set_ts: Manifestzeile ohne SHA-256-Hash." >&2; result=1; continue; }
    case "$name" in
      "db-$set_ts.sql.gz" | "db-$set_ts.sql.gz.cms") db_seen=$((db_seen + 1)) ;;
      "config-$set_ts.tar.gz" | "config-$set_ts.tar.gz.cms") config_seen=$((config_seen + 1)) ;;
      *) echo "Satz $set_ts: Manifest nennt eine fremde Datei: $name" >&2; result=1; continue ;;
    esac
    path="$BACKUP_DIR/$name"
    if [ ! -e "$path" ]; then
      [ -e "$manifest" ] || return 3
      echo "Satz $set_ts: $name fehlt." >&2
      result=1
      continue
    fi
    [ -r "$path" ] \
      || envfail "[scrub.unreadable] Archiv nicht lesbar: $path (mit sudo starten)."
    actual=$(sha256sum < "$path" | cut -c1-64) \
      || envfail "[scrub.unreadable] Archiv nicht lesbar: $path"
    if [ "$actual" != "$expected" ]; then
      echo "Satz $set_ts: $name weicht vom Manifest ab." >&2
      result=1
    fi
  done < "$manifest"
  if [ "$lines" -ne 2 ] || [ "$db_seen" -ne 1 ] || [ "$config_seen" -ne 1 ]; then
    echo "Satz $set_ts: Manifest nennt nicht genau Dump und Konfigurationsarchiv." >&2
    result=1
  fi
  return "$result"
}

for manifest in "$BACKUP_DIR"/manifest-*.sha256; do
  [ -e "$manifest" ] || continue
  position=$((position + 1))
  set_ts=$(basename "$manifest" .sha256)
  set_ts=${set_ts#manifest-}
  echo "[$position/$total] RUN $set_ts"
  rc=0
  scrub_set "$manifest" "$set_ts" || rc=$?
  case "$rc" in
    0)
      checked=$((checked + 1))
      echo "[$position/$total] pass $set_ts"
      ;;
    3)
      echo "[$position/$total] skip $set_ts (waehrend des Laufs wegrotiert)"
      ;;
    *)
      checked=$((checked + 1))
      failed=$((failed + 1))
      failed_sets="$failed_sets${failed_sets:+,}\"$set_ts\""
      echo "[$position/$total] fail $set_ts"
      ;;
  esac
done

[ "$checked" -gt 0 ] \
  || envfail "[scrub.no-backup] Alle Saetze wurden waehrend des Laufs wegrotiert; nichts geprueft."

status=ok
[ "$failed" -eq 0 ] || status=failed
now=$(date +%s)
duration=$((now - start_epoch))

mkdir -p "$STATUS_DIR" || envfail "[scrub.status-write] Statusverzeichnis nicht anlegbar: $STATUS_DIR"
tmp=$(mktemp "$STATUS_DIR/.backup-scrub.XXXXXX") \
  || envfail "[scrub.status-write] Tempdatei im Statusverzeichnis nicht anlegbar: $STATUS_DIR"
printf '{"ts":%s,"status":"%s","sets_checked":%s,"sets_failed":%s,"failed_sets":[%s],"duration_s":%s}\n' \
  "$now" "$status" "$checked" "$failed" "$failed_sets" "$duration" > "$tmp" \
  && chmod 0644 "$tmp" \
  && mv -f "$tmp" "$STATUS_FILE" \
  || { rm -f "$tmp"; envfail "[scrub.status-write] Ergebnis nicht schreibbar: $STATUS_FILE"; }

if [ "$status" = ok ]; then
  echo "Pruefsummen-Scrub OK: $checked Saetze stimmen mit ihrem Manifest ueberein."
  exit 0
fi
echo "Pruefsummen-Scrub BEFUND: $failed von $checked Saetzen weichen ab. Diese Saetze nicht fuer einen Restore verwenden." >&2
exit 1
