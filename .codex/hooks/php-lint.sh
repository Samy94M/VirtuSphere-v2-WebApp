#!/bin/sh
# PostToolUse-Hook: php -l fuer die editierte Datei. Blockt (exit 2) bei
# Syntaxfehlern. Nutzt Host-PHP, sonst den PHP-Container fuer Dateien unter
# Docker/WebAPI/; ohne beides still ueberspringen (Air-Gap-/Offline-freundlich).
set -eu
# Git-Bash/MSYS wuerde /var/www/... in Windows-Pfade uebersetzen und den
# Container-Lint damit brechen.
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'

payload="$(cat)"
file_path="$(printf '%s' "$payload" | sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)"
[ -n "$file_path" ] || exit 0
file_path="$(printf '%s' "$file_path" | sed 's#\\\\#/#g')"

case "$file_path" in
  *vendor/*) exit 0 ;;
  *.php) ;;
  *) exit 0 ;;
esac
[ -f "$file_path" ] || exit 0

if command -v php >/dev/null 2>&1; then
  if ! out=$(php -l "$file_path" 2>&1); then
    echo "BLOCK: PHP-Syntaxfehler in $file_path" >&2
    echo "$out" >&2
    exit 2
  fi
  exit 0
fi

case "$file_path" in
  *Docker/WebAPI/*)
    rel="${file_path##*Docker/WebAPI/}"
    if docker exec virtusphere-v2-webapp-php-1 php -l "/var/www/html/$rel" >/dev/null 2>&1; then
      exit 0
    fi
    # Container nicht erreichbar vs. echter Syntaxfehler unterscheiden:
    if ! docker exec virtusphere-v2-webapp-php-1 true >/dev/null 2>&1; then
      exit 0
    fi
    echo "BLOCK: PHP-Syntaxfehler in $file_path (Container-Lint)" >&2
    docker exec virtusphere-v2-webapp-php-1 php -l "/var/www/html/$rel" >&2 || true
    exit 2
    ;;
esac

# Guard-Harness fixtures and other edited PHP files outside Docker/WebAPI are
# not mounted into the running app container. Reuse the already-built project
# image with a narrow read-only mount instead of requiring host PHP.
php_image="virtusphere-v2-webapp-php"
if command -v docker >/dev/null 2>&1 && docker image inspect "$php_image" >/dev/null 2>&1; then
  lint_dir="$(dirname "$file_path")"
  lint_name="$(basename "$file_path")"
  if ! out=$(docker run --rm -v "$lint_dir:/lint:ro" "$php_image" php -l "/lint/$lint_name" 2>&1); then
    echo "BLOCK: PHP-Syntaxfehler in $file_path (Container-Lint)" >&2
    echo "$out" >&2
    exit 2
  fi
fi
exit 0
