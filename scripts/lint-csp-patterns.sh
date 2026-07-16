#!/bin/sh
# scripts/lint-csp-patterns.sh — Forbidden-Pattern-Scan fuer PHP-Dateien.
#
# Modi (AP3: eindeutig getrennt):
#   --file <path>...      genau diese Dateien pruefen (Hooks, CI-Dateilisten)
#   --worktree            staged + unstaged + untracked First-Party-PHP-Dateien
#   --range <base> <head> zwischen zwei Commits geaenderte PHP-Dateien (CI)
#   --all-changed         dokumentierter Alias fuer --worktree
#
# Harte Befunde melden "BLOCK: [csp.<id>] ..." und beenden mit Exit 2, weiche
# Befunde bleiben "WARN: [csp.<id>] ..."-Zeilen. Die IDs sind stabil und werden
# vom Guard-Harness (scripts/test-guards.ps1) als Diagnose-Vertrag geprueft.
#
# VIRTUSPHERE_CHECK_ROOT uebersteuert das Repo-Root (Guard-Fixtures).
set -eu

usage() {
  cat >&2 <<'USAGE'
Usage: scripts/lint-csp-patterns.sh --file <path>... | --worktree | --range <base> <head> | --all-changed
USAGE
  exit 2
}

cd "${VIRTUSPHERE_CHECK_ROOT:-$(dirname "$0")/..}"

[ "$#" -gt 0 ] || usage
mode=""
files=""
range_base=""
range_head=""

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
    --worktree|--all-changed)
      mode="worktree"
      shift
      ;;
    --range)
      mode="range"
      shift
      [ "$#" -ge 2 ] || usage
      range_base="$1"
      range_head="$2"
      shift 2
      ;;
    *)
      usage
      ;;
  esac
done

# Git-Modi duerfen ohne funktionierendes Git nie leer gruen werden (Zero-Match).
if [ "$mode" != "file" ] && ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "BLOCK: [csp.no-git] --$mode braucht ein Git-Repo und git im PATH" >&2
  exit 2
fi

case "$mode" in
  worktree)
    # Staged, unstaged UND untracked: der fruehere --all-changed sah via
    # `git diff` nur unstaged Aenderungen und liess staged/neue Dateien durch.
    files="$( {
      git diff --name-only -- '*.php'
      git diff --cached --name-only -- '*.php'
      git ls-files --others --exclude-standard -- '*.php'
    } 2>/dev/null | sort -u || true)"
    ;;
  range)
    if ! git rev-parse --verify --quiet "$range_base^{commit}" >/dev/null 2>&1 \
      || ! git rev-parse --verify --quiet "$range_head^{commit}" >/dev/null 2>&1; then
      echo "BLOCK: [csp.bad-range] --range braucht zwei aufloesbare Commits (base=$range_base head=$range_head)" >&2
      exit 2
    fi
    files="$(git diff --name-only "$range_base" "$range_head" -- '*.php' 2>/dev/null || true)"
    ;;
esac

[ -n "$files" ] || exit 0
hard=0

has_allow() {
  check="$1"
  file="$2"
  grep -n "csp-allow: ${check}" "$file" >/dev/null 2>&1
}

warn() {
  check="$1"
  message="$2"
  printf 'WARN: [csp.%s] %s\n' "$check" "$message" >&2
}

block() {
  check="$1"
  file="$2"
  message="$3"
  if has_allow "$check" "$file"; then
    warn "$check" "$message (allowed by csp-allow: $check)"
    return
  fi
  hard=1
  printf 'BLOCK: [csp.%s] %s\n' "$check" "$message" >&2
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
      warn "$check" "$message"
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
    */vendor/*|vendor/*|*/tests/*|tests/*) continue ;;
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
    Docker/WebAPI/portal/*.php|Docker/WebAPI/lib/layout.php)
      check_pattern soft raw-getmessage "$file" 'flash_set\('"'"'error'"'"',[[:space:]]*\$exception->getMessage\(\)\)|form_remember\([^;]*\$exception->getMessage\(\)|\$error[[:space:]]*=[[:space:]]*\$exception->getMessage\(\)' "raw exception message may be user-facing in $file"
      check_pattern soft hardcoded-text "$file" "layout_header\\('[^']*[[:alpha:]][^']*'|flash_set\\('(success|info)',[[:space:]]*'[^']*[[:alpha:]][^']*'|<h[1-6][^>]*>[[:alpha:]]|<label[^>]*>[[:alpha:]]|<button[^>]*>[[:alpha:]]" "possible hardcoded visible portal text in $file"
      ;;
  esac

  lines="$(wc -l < "$file" | tr -d ' ')"
  if [ "$lines" -gt 400 ]; then
    warn line-budget "$file has $lines lines; consider extracting modules"
  fi
done

if [ "$hard" -ne 0 ]; then
  exit 2
fi
exit 0
