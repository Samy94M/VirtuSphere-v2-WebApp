#!/bin/sh
# scripts/check-php-version-sync.sh — PHP-Version-SSoT-Check.
#
# Canonical SSoT: die FROM-Zeile in Docker/php/Dockerfile. Alle anderen
# Stellen muessen matchen (AGENTS.md Hard Rule: "PHP target is X.Y everywhere:
# Dockerfile, Composer platform, docs, hooks").
#
# Geprueft:
#   Docker/php/Dockerfile              FROM php:X.Y-fpm        (SSoT)
#   Docker/WebAPI/composer.json        require.php ">=X.Y"
#   Docker/WebAPI/composer.json        config.platform.php "X.Y.0"
#   Docker/WebAPI/lib/constants.php    VIRTUSPHERE_PHP_TARGET = 'X.Y'
#   CLAUDE.md                          "PHP X.Y FPM"
#   AGENTS.md                          "PHP target is X.Y"
#
# Aufrufer:
#   - .claude/hooks/session-start.sh  (Modus --quiet)
#   - scripts/check.ps1 (Fast-Lane) und scripts/test-guards.ps1 (Fixtures)
#   - manuell vor Commit              (kein Argument)
#
# VIRTUSPHERE_CHECK_ROOT uebersteuert das Repo-Root (Guard-Fixtures); die
# [php-version-sync.*]-IDs in Fehlerzeilen sind der stabile Diagnose-Vertrag.
set -eu
cd "${VIRTUSPHERE_CHECK_ROOT:-$(dirname "$0")/..}"

quiet=0
case "${1:-}" in
  --quiet|-q) quiet=1 ;;
  --ci|'') ;;
  --help|-h) echo "Usage: scripts/check-php-version-sync.sh [--quiet|--ci]"; exit 0 ;;
  *) echo "Unknown argument: $1" >&2; exit 2 ;;
esac

ver=$(sed -n 's/^FROM php:\([0-9][0-9.]*\)-fpm.*/\1/p' Docker/php/Dockerfile | head -n 1)
if [ -z "$ver" ]; then
  # Zero-Match darf nie leer gruen werden: fehlende SSoT-Zeile ist ein Fehler.
  echo "FEHLER: [php-version-sync.no-ssot] keine 'FROM php:X.Y-fpm'-Zeile in Docker/php/Dockerfile." >&2
  exit 1
fi

errors=0
expect() { # $1=file $2=grep-fixed-string $3=beschreibung
  if ! grep -qF "$2" "$1"; then
    echo "FEHLER: [php-version-sync.drift] PHP-Version-Drift — $1 enthaelt nicht '$2' ($3, SSoT: $ver)." >&2
    errors=$((errors + 1))
  fi
}

expect Docker/WebAPI/composer.json      "\"php\": \">=$ver\""              "require.php"
expect Docker/WebAPI/composer.json      "\"php\": \"$ver.0\""              "config.platform.php"
expect Docker/WebAPI/lib/constants.php  "VIRTUSPHERE_PHP_TARGET = '$ver'"  "PHP-Target-Konstante"
expect CLAUDE.md                        "PHP $ver FPM"                     "Stack-Beschreibung"
expect AGENTS.md                        "PHP target is $ver"               "Hard Rule"

if [ "$errors" -gt 0 ]; then
  echo "check-php-version-sync: $errors Drift-Fehler (SSoT Dockerfile: PHP $ver)." >&2
  exit 1
fi
[ "$quiet" -eq 1 ] || echo "check-php-version-sync: alle Stellen auf PHP $ver."
