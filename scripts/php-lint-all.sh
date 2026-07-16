#!/bin/sh
# scripts/php-lint-all.sh — php -l ueber alle First-Party-PHP-Dateien.
#
# Aufrufer: scripts/check.ps1 (Gate php-lint). Eigenes Skript statt sh -c,
# weil PowerShell 5.1 eingebettete Anfuehrungszeichen in nativen Argumenten
# nicht escaped und das Snippet sonst zerlegt wird.
#
# Exitcodes: 0 sauber | 1 Syntaxfehler | 9 keine Dateien gefunden (Zero-Match,
# der Runner wertet 9 als infrastructure_error).
set -u
cd "${VIRTUSPHERE_CHECK_ROOT:-$(dirname "$0")/..}" || exit 9

files=$(find Docker/WebAPI scripts -name '*.php' -not -path '*/vendor/*' 2>/dev/null)
[ -n "$files" ] || { echo 'no php files found'; exit 9; }

command -v php >/dev/null 2>&1 || { echo 'php not available'; exit 9; }

rc=0
n=0
for f in $files; do
  n=$((n + 1))
  php -l "$f" >/dev/null || rc=1
done
echo "linted $n file(s)"
exit $rc
