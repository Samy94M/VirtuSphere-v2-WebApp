#!/bin/sh
# scripts/check-doc-hygiene.sh — Doku-Hygiene-Guard (verhindert Changelog-Regrowth).
#
# Prueft die immer-geladenen Agenten-Dokus auf:
#   1. Changelog-Marker (datierte Ueberschriften, "Nachtrag"/"Fortschritt") -> Fehler.
#      Historie gehoert ausschliesslich nach docs/CHANGELOG.md.
#   2. Zeilen- und UTF-8-Bytebudget -> Fehler. Lange Einzelzeilen duerfen
#      das Kontextbudget nicht umgehen. Bytes sind kein exakter Tokenzaehler.
#
# Zeilenbudgets fuer Einstieg und referenzierte Projektdokumente:
#   AGENTS.md 120 | GROK.md 150 | CLAUDE.md 60 | README.md 100
#
# Aufrufer:
#   - .claude/hooks/session-start.sh  (Modus --quiet)
#   - scripts/check.ps1 (Fast-Lane) und scripts/test-guards.ps1 (Fixtures)
#   - manuell vor Commit              (kein Argument)
#
# VIRTUSPHERE_CHECK_ROOT uebersteuert das Repo-Root (Guard-Fixtures); die
# [doc-hygiene.*]-IDs in Fehlerzeilen sind der stabile Diagnose-Vertrag.
set -eu
cd "${VIRTUSPHERE_CHECK_ROOT:-$(dirname "$0")/..}"

quiet=0
case "${1:-}" in
  --quiet|-q) quiet=1 ;;
  --ci|'') ;;
  --help|-h) echo "Usage: scripts/check-doc-hygiene.sh [--quiet|--ci]"; exit 0 ;;
  *) echo "Unknown argument: $1" >&2; exit 2 ;;
esac

errors=0

budget_for() {
  case "$1" in
    AGENTS.md) echo 120 ;;
    GROK.md)   echo 150 ;;
    CLAUDE.md) echo 60 ;;
    README.md) echo 100 ;;
  esac
}

byte_budget_for() {
  case "$1" in
    AGENTS.md) echo 8000 ;;
    GROK.md)   echo 26000 ;;
    CLAUDE.md) echo 2000 ;;
    README.md) echo 16000 ;;
  esac
}

file_index=0
for file in AGENTS.md GROK.md CLAUDE.md README.md; do
  file_index=$((file_index + 1))
  before_errors=$errors
  [ "$quiet" -eq 1 ] || echo "[$file_index/4] RUN doc-hygiene $file"
  if [ ! -f "$file" ]; then
    echo "FEHLER: [doc-hygiene.missing-file] $file nicht gefunden." >&2
    errors=$((errors + 1))
    [ "$quiet" -eq 1 ] || echo "[$file_index/4] fail doc-hygiene $file"
    continue
  fi

  # 1. Changelog-Marker
  if grep -nE '^#{1,3} .*20[0-9]{2}-[0-9]{2}-[0-9]{2}|^\*\*(Nachtrag|Vorher|Aktueller Stand)|Fortschritt 20[0-9]{2}-' "$file" >&2; then
    echo "FEHLER: [doc-hygiene.changelog-marker] $file enthaelt Changelog-Marker — Historie gehoert nach docs/CHANGELOG.md." >&2
    errors=$((errors + 1))
  fi

  # 2. Zeilen-Budget
  budget=$(budget_for "$file")
  lines=$(wc -l < "$file" | tr -d ' ')
  if [ "$lines" -gt "$budget" ]; then
    echo "FEHLER: [doc-hygiene.line-budget] $file hat $lines Zeilen (Budget: $budget). Kuerzen oder nach docs/ auslagern." >&2
    errors=$((errors + 1))
  fi

  bytes=$(wc -c < "$file" | tr -d ' ')
  byte_budget=$(byte_budget_for "$file")
  if [ "$bytes" -eq 0 ]; then
    echo "FEHLER: [doc-hygiene.empty-file] $file ist leer." >&2
    errors=$((errors + 1))
  elif [ "$bytes" -gt "$byte_budget" ]; then
    echo "FEHLER: [doc-hygiene.byte-budget] $file hat $bytes Bytes (Budget: $byte_budget). Fachdetails gezielt auslagern." >&2
    errors=$((errors + 1))
  fi
  if [ "$quiet" -eq 0 ]; then
    result=pass
    [ "$errors" -eq "$before_errors" ] || result=fail
    echo "[$file_index/4] $result doc-hygiene $file"
  fi
done

if [ "$errors" -gt 0 ]; then
  echo "check-doc-hygiene: $errors Fehler." >&2
  exit 1
fi
[ "$quiet" -eq 1 ] || echo "check-doc-hygiene: Agenten-Dokus sauber (AGENTS/GROK/CLAUDE/README)."
