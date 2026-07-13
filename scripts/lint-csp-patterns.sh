#!/bin/sh
set -eu

usage() {
  cat >&2 <<'USAGE'
Usage: scripts/lint-csp-patterns.sh --file <path>... | --all-changed
USAGE
  exit 2
}

[ "$#" -gt 0 ] || usage
mode=""
files=""

while [ "$#" -gt 0 ]; do
  case "$1" in
    --file)
      mode="file"
      shift
      [ "$#" -gt 0 ] || usage
      while [ "$#" -gt 0 ] && [ "${1#--}" = "$1" ]; do
        files="$files $1"
        shift
      done
      ;;
    --all-changed)
      mode="all-changed"
      shift
      ;;
    *)
      usage
      ;;
  esac
done

if [ "$mode" = "all-changed" ]; then
  files="$(git diff --name-only -- '*.php' 2>/dev/null || true)"
fi

[ -n "$files" ] || exit 0
hard=0

has_allow() {
  check="$1"
  file="$2"
  grep -n "csp-allow: ${check}" "$file" >/dev/null 2>&1
}

warn() {
  printf 'WARN: %s\n' "$1" >&2
}

block() {
  check="$1"
  file="$2"
  message="$3"
  if has_allow "$check" "$file"; then
    warn "$message (allowed by csp-allow: $check)"
    return
  fi
  hard=1
  printf 'BLOCK: %s\n' "$message" >&2
}

check_pattern() {
  severity="$1"
  check="$2"
  file="$3"
  pattern="$4"
  message="$5"
  if grep -nE "$pattern" "$file" >/dev/null 2>&1; then
    if [ "$severity" = "hard" ]; then
      block "$check" "$file" "$message"
    else
      warn "$message"
    fi
  fi
}

for file in $files; do
  [ -f "$file" ] || continue
  case "$file" in
    *.php) ;;
    *) continue ;;
  esac
  case "$file" in
    */vendor/*|vendor/*|*/tests/*|tests/*|Docker/WebAPI/vendor/*|Docker/WebAPI/tests/*) continue ;;
  esac

  if command -v php >/dev/null 2>&1; then
    if ! php -l "$file" >/dev/null; then
      block php-l "$file" "php -l failed for $file"
    fi
  fi

  check_pattern hard interpolated-sql "$file" 'query\([^;]*"[^"]*\$[A-Za-z_]' "possible interpolated SQL in $file"
  check_pattern hard interpolated-sql "$file" "query\\([^;]*'[^']*\\\$[A-Za-z_]" "possible interpolated SQL in $file"
  check_pattern hard interpolated-sql "$file" 'mysqli_query\([^;]*"[^"]*\$[A-Za-z_]' "possible interpolated mysqli_query SQL in $file"
  check_pattern hard interpolated-sql "$file" "mysqli_query\\([^;]*'[^']*\\\$[A-Za-z_]" "possible interpolated mysqli_query SQL in $file"
  check_pattern hard mysqli-password "$file" 'new mysqli\([^)]*pass' "possible hardcoded mysqli password in $file"
  check_pattern hard secret-fallback "$file" 'getenv\([^)]*\)[[:space:]]*\?:' "secret fallback pattern in $file"
  check_pattern hard external-asset "$file" '(src|href)=["'"'"']https?://' "external runtime asset in $file"
  check_pattern hard inline-handler "$file" '<[^>]+ on[a-zA-Z]+=' "inline event handler in $file"

  if grep -nE '<(script|style)([ >])' "$file" | grep -v 'nonce=' >/dev/null 2>&1; then
    block nonce "$file" "script/style without nonce in $file"
  fi

  check_pattern soft short-tag "$file" '<\?([^p=]|$)' "PHP short tag in $file"
  check_pattern soft inline-style "$file" '<[^>]+ style=' "inline style attribute in $file"

  case "$file" in
    Docker/WebAPI/portal/*.php|Docker/WebAPI/portal/*/*.php|Docker/WebAPI/lib/layout.php)
      check_pattern soft raw-getmessage "$file" 'flash_set\('"'"'error'"'"',[[:space:]]*\$exception->getMessage\(\)\)|form_remember\([^;]*\$exception->getMessage\(\)|\$error[[:space:]]*=[[:space:]]*\$exception->getMessage\(\)' "raw exception message may be user-facing in $file"
      check_pattern soft hardcoded-text "$file" "layout_header\\('[^']*[[:alpha:]][^']*'|flash_set\\('(success|info)',[[:space:]]*'[^']*[[:alpha:]][^']*'|<h[1-6][^>]*>[[:alpha:]]|<label[^>]*>[[:alpha:]]|<button[^>]*>[[:alpha:]]" "possible hardcoded visible portal text in $file"
      ;;
  esac

  lines="$(wc -l < "$file" | tr -d ' ')"
  if [ "$lines" -gt 400 ]; then
    warn "$file has $lines lines; consider extracting modules"
  fi
done

if [ "$hard" -ne 0 ]; then
  exit 2
fi
exit 0
