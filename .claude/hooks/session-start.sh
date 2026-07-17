#!/bin/sh
# SessionStart-Hook: kompakter Kontext (Branch, letzte Migration, Uncommitted)
# plus leise Drift-Checks — Output nur bei Problemen (wenige Tokens, einmalig).
set -eu

branch=$(git branch --show-current 2>/dev/null || echo 'unbekannt')
last_mig=$(grep -oE "'[0-9]{4}_[a-z_]+'" Docker/WebAPI/lib/migrate.php 2>/dev/null | tail -n 1 | tr -d "'" || true)
uncommitted=$(git status --porcelain 2>/dev/null | wc -l | tr -d ' ')
echo "VirtuSphere session — Branch: $branch | Letzte Migration: ${last_mig:-keine} | Uncommitted: $uncommitted Datei(en)"

if command -v php >/dev/null 2>&1 && [ -f scripts/lang-audit.php ]; then
  if ! php scripts/lang-audit.php --quiet >/dev/null 2>&1; then
    echo "VirtuSphere warning: Lang-Audit DE/EN parity gap; run php scripts/lang-audit.php --ci"
  fi
fi

# Drift-Checks (leise; bei Drift eine Warnzeile mit dem Reproduktions-Kommando)
for check in enum-sync php-version-sync doc-hygiene doc-semantics; do
  script="scripts/check-$check.sh"
  if [ -f "$script" ] && ! sh "$script" --quiet >/dev/null 2>&1; then
    echo "VirtuSphere warning: Drift in check-$check; run sh $script"
  fi
done

# PHP-basierter Grenzwert-Check (eigener Zweig: die Schleife oben fasst nur .sh)
if command -v php >/dev/null 2>&1 && [ -f scripts/check-bounds-sync.php ]; then
  if ! php scripts/check-bounds-sync.php --quiet >/dev/null 2>&1; then
    echo "VirtuSphere warning: Drift in check-bounds-sync; run php scripts/check-bounds-sync.php"
  fi
fi
