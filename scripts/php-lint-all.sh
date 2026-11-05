#!/bin/sh
# scripts/php-lint-all.sh — php -l ueber alle First-Party-PHP-Dateien.
#
# Aufrufer: scripts/check.ps1 (Gate php-lint). Eigenes Skript statt sh -c,
# weil PowerShell 5.1 eingebettete Anfuehrungszeichen in nativen Argumenten
# nicht escaped und das Snippet sonst zerlegt wird.
#
# QL2: die Dateien laufen ueber xargs -P parallel, je Datei ein eigener
# php -l. VIRTUSPHERE_PHP_LINT_JOBS setzt die Zahl gleichzeitiger Prozesse
# (1 bis 32, Standard 8; 1 = nacheinander). Jede Datei wird geprueft, auch
# nach einem Fehler: der Wrapper macht aus php's Exit 255 eine 1, weil xargs
# bei 255 sonst keine weiteren Dateien mehr startet.
#
# Exitcodes: 0 sauber | 1 Syntaxfehler | 9 keine Dateien gefunden, kein php
# oder kein paralleles xargs (Zero-Match bzw. Umgebung, der Runner wertet 9
# als infrastructure_error).
set -u
cd "${VIRTUSPHERE_CHECK_ROOT:-$(dirname "$0")/..}" || exit 9

files=$(find Docker/WebAPI scripts -name '*.php' -not -path '*/vendor/*' 2>/dev/null)
[ -n "$files" ] || { echo 'no php files found'; exit 9; }

command -v php >/dev/null 2>&1 || { echo 'php not available'; exit 9; }

jobs="${VIRTUSPHERE_PHP_LINT_JOBS:-8}"
case "$jobs" in
  ''|*[!0-9]*) echo "VIRTUSPHERE_PHP_LINT_JOBS must be a whole number from 1 to 32, got '$jobs'"; exit 9 ;;
esac
if [ "$jobs" -lt 1 ] || [ "$jobs" -gt 32 ]; then
  echo "VIRTUSPHERE_PHP_LINT_JOBS must be a whole number from 1 to 32, got '$jobs'"; exit 9
fi
printf 'probe\0' | xargs -0 -n 1 -P 2 true 2>/dev/null || { echo 'xargs -0 -P not available'; exit 9; }

# Eine Datei je Zeile, wie die fruehere for-Schleife (Pfade ohne Leerzeichen).
n=$(printf '%s\n' "$files" | wc -l)
n=$((n))

rc=0
printf '%s\n' "$files" | tr '\n' '\0' \
  | xargs -0 -n 1 -P "$jobs" sh -c 'php -l "$1" >/dev/null || exit 1' php-lint || rc=$?
case "$rc" in
  0) ;;
  123) rc=1 ;;
  *) echo "xargs ended with exit $rc before every file was checked"; rc=9 ;;
esac
echo "linted $n file(s)"
exit $rc
