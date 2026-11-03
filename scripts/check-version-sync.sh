#!/bin/sh
# scripts/check-version-sync.sh - Release-Nummer-SSoT-Check (JJJJ.MM.N).
#
# Canonical SSoT: Docker/WebAPI/lib/VERSION mit genau einer Zeile (z. B. 2026.10.1).
# Form: JJJJ.MM.N, Monat 01-12, N 1-99 (ERE siehe unten).
#
# Geprueft:
#   Docker/WebAPI/lib/VERSION          genau eine Zeile, Form JJJJ.MM.N   (SSoT)
#   Powershell-MECM/VERSION            zeichengleich zur SSoT             (MECM-Updatepaket)
#
# Aufrufer:
#   - scripts/lib/check/gates-fast.ps1 (Gate version-sync, Modus --ci)
#   - scripts/test-guards.ps1          (Fixtures)
#   - manuell vor Commit               (kein Argument)
#
# VIRTUSPHERE_CHECK_ROOT uebersteuert das Repo-Root (Guard-Fixtures); die
# [version-sync.*]-IDs in Fehlerzeilen sind der stabile Diagnose-Vertrag.
set -eu
cd "${VIRTUSPHERE_CHECK_ROOT:-$(dirname "$0")/..}"

quiet=0
case "${1:-}" in
  --quiet|-q) quiet=1 ;;
  --ci|'') ;;
  --help|-h) echo "Usage: scripts/check-version-sync.sh [--quiet|--ci]"; exit 0 ;;
  *) echo "Unknown argument: $1" >&2; exit 2 ;;
esac

# JJJJ.MM.N: Monat 01-12, N 1-99. Single-quoted, damit die Regex unveraendert bleibt.
re='^[0-9]{4}\.(0[1-9]|1[0-2])\.[1-9][0-9]?$'
ssot_file=Docker/WebAPI/lib/VERSION
copy_file=Powershell-MECM/VERSION

count_lines() { # $1=file: nichtleere Zeilen ohne CR; fehlende Datei zaehlt als 0
  if [ -f "$1" ]; then
    tr -d '\r' < "$1" | grep -c . || true
  else
    echo 0
  fi
}

first_line() { # $1=file: erste nichtleere Zeile ohne CR
  if [ -f "$1" ]; then
    tr -d '\r' < "$1" | grep . | head -n 1 || true
  fi
}

ssot_lines=$(count_lines "$ssot_file")
if [ "$ssot_lines" -eq 0 ]; then
  # Zero-Match darf nie leer gruen werden: fehlende oder leere SSoT ist ein Fehler.
  echo "FEHLER: [version-sync.no-ssot] keine Release-Nummer in $ssot_file (Datei fehlt oder ist leer)." >&2
  exit 1
fi
ssot=$(first_line "$ssot_file")

errors=0
if [ "$ssot_lines" -ne 1 ] || ! printf '%s\n' "$ssot" | grep -Eq "$re"; then
  echo "FEHLER: [version-sync.format] Release-Format in $ssot_file: erwartet genau eine Zeile JJJJ.MM.N (Monat 01-12, N 1-99), gefunden: '$ssot' ($ssot_lines Zeile(n))." >&2
  errors=$((errors + 1))
fi

# Die Kopie wird gegen die SSoT-Zeile verglichen. Eine fehlerhafte Kopie, die sich
# von der SSoT unterscheidet, meldet deshalb genau einmal als Drift.
copy_lines=$(count_lines "$copy_file")
if [ ! -f "$copy_file" ]; then
  echo "FEHLER: [version-sync.drift] Release-Drift: $copy_file fehlt (SSoT $ssot_file: '$ssot')." >&2
  errors=$((errors + 1))
else
  copy=$(first_line "$copy_file")
  if [ "$copy_lines" -ne 1 ] || [ "$copy" != "$ssot" ]; then
    echo "FEHLER: [version-sync.drift] Release-Drift: $copy_file enthaelt '$copy' ($copy_lines Zeile(n)), SSoT $ssot_file enthaelt '$ssot'." >&2
    errors=$((errors + 1))
  fi
fi

if [ "$errors" -gt 0 ]; then
  echo "check-version-sync: $errors Fehler (SSoT $ssot_file: '$ssot')." >&2
  exit 1
fi
[ "$quiet" -eq 1 ] || echo "check-version-sync: Release $ssot in beiden Kopien."
