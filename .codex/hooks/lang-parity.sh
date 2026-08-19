#!/bin/sh
# PostToolUse-Hook: DE/EN-Katalog-Paritaet nach Edits unter Docker/WebAPI/lang/.
# Meldet Paritaetsluecken als Blocker (exit 2), damit der Agent den zweiten
# Katalog direkt nachzieht. Ohne Host-PHP still ueberspringen.
set -eu

payload="$(cat)"
file_path="$(printf '%s' "$payload" | sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)"
[ -n "$file_path" ] || exit 0
file_path="$(printf '%s' "$file_path" | sed 's#\\\\#/#g')"

case "$file_path" in
  *Docker/WebAPI/lang/*) ;;
  *) exit 0 ;;
esac

command -v php >/dev/null 2>&1 || exit 0
[ -f scripts/lang-audit.php ] || exit 0

if ! out=$(php scripts/lang-audit.php --ci 2>&1); then
  echo "BLOCK: DE/EN-Katalogparitaet verletzt nach Edit von $file_path — fehlende Keys im jeweils anderen Katalog ergaenzen." >&2
  echo "$out" >&2
  exit 2
fi
exit 0
