#!/bin/sh
set -eu

payload="$(cat)"
file_path="$(printf '%s' "$payload" | sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)"

if [ -z "$file_path" ]; then
  exit 0
fi

file_path="$(printf '%s' "$file_path" | sed 's#\\\\#/#g')"
case "$file_path" in
  *.php) ;;
  *) exit 0 ;;
esac

exec scripts/lint-csp-patterns.sh --file "$file_path"
