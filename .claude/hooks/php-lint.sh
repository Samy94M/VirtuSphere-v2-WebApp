#!/bin/sh
# PostToolUse-Hook: php -l fuer genau die editierte Datei. Blockt (exit 2) bei
# Syntax- oder Compile-Fehlern. Lintet mit Host-PHP, sonst im PHP-Tooling-Image
# aus scripts/tool-lock.json (dockerImages.php.ref), die Datei read-only
# gemountet. Nie per docker exec im laufenden App-Container: der sieht den
# Hauptbaum, nicht die bearbeitete Datei eines Worktrees.
# Ohne Host-PHP und ohne Docker still ueberspringen (Air-Gap-/Offline-freundlich).
# Fehlt nur das Image oder scheitert Docker, nicht blockierender Hinweis (exit 1),
# damit ein fehlendes Werkzeug nicht als gruener Lint durchgeht.
set -eu
# Git-Bash/MSYS wuerde /lint/... in Windows-Pfade uebersetzen und den
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

# php -l meldet jeden Syntax- und Compile-Fehler mit "Errors parsing". Nur
# dieser Befund blockt; andere Fehlschlaege sind kein Syntaxbefund.
judge() {
  rc="$1" out="$2" via="$3"
  [ "$rc" -eq 0 ] && exit 0
  case "$out" in
    *"Errors parsing"*)
      echo "BLOCK: PHP-Syntaxfehler in $file_path$via" >&2
      printf '%s\n' "$out" >&2
      exit 2
      ;;
  esac
  echo "php-lint-Hook: $file_path nicht geprueft$via (exit $rc)" >&2
  printf '%s\n' "$out" >&2
  exit 1
}

if command -v php >/dev/null 2>&1; then
  rc=0
  out=$(php -l "$file_path" 2>&1) || rc=$?
  judge "$rc" "$out" ""
fi

command -v docker >/dev/null 2>&1 || exit 0

lock="$(dirname "$0")/../../scripts/tool-lock.json"
php_image=""
if [ -f "$lock" ]; then
  php_image="$(sed -n '/"php"[[:space:]]*:[[:space:]]*{/,/}/s/.*"ref"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$lock" | head -n 1)"
fi
if [ -z "$php_image" ]; then
  echo "php-lint-Hook: kein dockerImages.php.ref in $lock; $file_path nicht geprueft" >&2
  exit 1
fi
if ! docker image inspect "$php_image" >/dev/null 2>&1; then
  echo "php-lint-Hook: Image $php_image nicht verfuegbar (Docker aus oder Image fehlt: docker build --target tooling -t $php_image Docker/php); $file_path nicht geprueft" >&2
  exit 1
fi

lint_dir="$(dirname "$file_path")"
lint_name="$(basename "$file_path")"
rc=0
out=$(docker run --rm --network none -v "$lint_dir:/lint:ro" "$php_image" php -l "/lint/$lint_name" 2>&1) || rc=$?
judge "$rc" "$out" " (Container-Lint $php_image)"
