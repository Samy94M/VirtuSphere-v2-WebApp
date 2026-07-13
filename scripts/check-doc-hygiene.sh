#!/bin/sh
# scripts/check-doc-hygiene.sh — Doku-Hygiene-Guard (verhindert Changelog-Regrowth).
#
# Prueft die immer-geladenen Agenten-Dokus auf:
#   1. Changelog-Marker (datierte Ueberschriften, "Nachtrag"/"Fortschritt") -> Fehler.
#      Historie gehoert ausschliesslich nach docs/CHANGELOG.md.
#   2. Zeilen-Budget -> Fehler. Verhindert, dass die Session-Start-Dokus
#      unbemerkt zu Kontext-Fressern anwachsen.
#
# Budgets (grosszuegig ueber dem Ist-Stand, bewusst unter den ueblichen
# Best-Practice-Grenzen von ~200 Zeilen fuer always-on Agent-Dokus):
#   AGENTS.md 120 | GROK.md 150 | CLAUDE.md 60 | README.md 100
#
# Aufrufer:
#   - .claude/hooks/session-start.sh  (Modus --quiet)
#   - manuell vor Commit              (kein Argument)
set -eu
cd "$(dirname "$0")/.."

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

for file in AGENTS.md GROK.md CLAUDE.md README.md; do
  if [ ! -f "$file" ]; then
    echo "FEHLER: $file nicht gefunden." >&2
    errors=$((errors + 1))
    continue
  fi

  # 1. Changelog-Marker
  if grep -nE '^#{1,3} .*20[0-9]{2}-[0-9]{2}-[0-9]{2}|^\*\*(Nachtrag|Vorher|Aktueller Stand)|Fortschritt 20[0-9]{2}-' "$file" >&2; then
    echo "FEHLER: $file enthaelt Changelog-Marker — Historie gehoert nach docs/CHANGELOG.md." >&2
    errors=$((errors + 1))
  fi

  # 2. Zeilen-Budget
  budget=$(budget_for "$file")
  lines=$(wc -l < "$file" | tr -d ' ')
  if [ "$lines" -gt "$budget" ]; then
    echo "FEHLER: $file hat $lines Zeilen (Budget: $budget). Kuerzen oder nach docs/ auslagern." >&2
    errors=$((errors + 1))
  fi
done

if [ "$errors" -gt 0 ]; then
  echo "check-doc-hygiene: $errors Fehler." >&2
  exit 1
fi
[ "$quiet" -eq 1 ] || echo "check-doc-hygiene: Agenten-Dokus sauber (AGENTS/GROK/CLAUDE/README)."
