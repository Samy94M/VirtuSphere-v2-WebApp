#!/bin/sh
# scripts/check-doc-fragments.sh - CHANGELOG und Register nur noch als Fragmente (B1).
#
# Bis 05.11.2026 haengte fast jedes Paket an zwei grosse Dateien an:
# docs/CHANGELOG.md unter "Noch in keinem Release" und das Register
# docs/audits/2026-09-12-consolidated-session-backlog.md. Fast jeder Merge hatte
# dort Konflikte. Seitdem schreibt jedes Paket eigene Dateien:
#   docs/changelog.d/<PAKET>.md          bis zum Release; der Release-Commit fuehrt
#                                        sie mit scripts/merge-changelog-fragments.sh
#                                        zusammen und loescht sie
#   docs/register/JJJJ-MM-TT-<PAKET>.md  dauerhaft
#
# Der Waechter leitet aus Git ab, was neu ist, statt Dateien oder Abschnitte zu
# listen:
#   1. changelog-head: kein Abschnitt "Noch in keinem Release", kein Eintrag vor
#      der ersten Release-Ueberschrift, die erste Ueberschrift ist ein Release.
#   2. changelog-since-release: Release-Commit ist der letzte Commit, der
#      Docker/WebAPI/lib/VERSION aendert. Seitdem hat docs/CHANGELOG.md keinen
#      Eintrag und keine Ueberschrift dazubekommen, und dieser Release-Commit
#      liess kein Fragment in docs/changelog.d/ zurueck. Ist VERSION im
#      Arbeitsbaum geaendert, entsteht gerade ein Release; die Regel ruht dann.
#   3. register-archive: Archiv ist jede Datei unter docs/audits/, die die Zeile
#      "<!-- register-archive -->" traegt oder je trug (git log -G). Seit dem
#      Commit, der die Zeile einfuehrte, kommt dort keine Zeile mehr dazu;
#      Loeschen bleibt moeglich. Keine Archivdatei ist ein Befund, kein Gruen.
#   4. fragments: Namen und Form der Fragmente (scripts/lib/doc-fragments.sh,
#      dieselbe Regel wie im Zusammenfuehrskript).
#
# Aufrufer: scripts/check.ps1 (Gate doc-fragments), scripts/test-guards.ps1,
# .claude/hooks/session-start.sh (--quiet), manuell vor Commit.
# VIRTUSPHERE_CHECK_ROOT uebersteuert das Repo-Root (muss ein Git-Arbeitsbaum
# sein); die [doc-fragments.*]-IDs sind der stabile Diagnose-Vertrag. Exit 0
# sauber, 1 Befund, 2 ohne Git oder ohne volle Historie (Infrastruktur).
set -eu
lib_dir=$(cd "$(dirname "$0")/lib" && pwd)
cd "${VIRTUSPHERE_CHECK_ROOT:-$(dirname "$0")/..}"
# shellcheck source=scripts/lib/doc-fragments.sh
. "$lib_dir/doc-fragments.sh"

quiet=0
case "${1:-}" in
  --quiet|-q) quiet=1 ;;
  --ci|'') ;;
  --help|-h) echo "Usage: scripts/check-doc-fragments.sh [--quiet|--ci]"; exit 0 ;;
  *) echo "Unknown argument: $1" >&2; exit 2 ;;
esac

changelog='docs/CHANGELOG.md'
version_file='Docker/WebAPI/lib/VERSION'
archive_scope='docs/audits'
archive_marker='<!-- register-archive -->'
archive_marker_re='^<!-- register-archive -->$'
release_heading_re='^## [0-9]{4}\.[0-9]{2}\.[0-9]{1,2} \([0-9]{2}\.[0-9]{2}\.[0-9]{4}\)$'

errors=0
fail() { # fail <id> <text>
  echo "FEHLER: [doc-fragments.$1] $2" >&2
  errors=$((errors + 1))
}
note() { [ "$quiet" -eq 1 ] || echo "  $1"; }

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "FEHLER: [doc-fragments.no-git] $(pwd) ist kein Git-Arbeitsbaum oder git fehlt; ohne Historie laesst sich nicht ableiten, was neu ist." >&2
  exit 2
fi
if [ "$(git rev-parse --is-shallow-repository 2>/dev/null)" = true ]; then
  echo "FEHLER: [doc-fragments.no-git] flacher Klon; der letzte Release-Commit und der Archiv-Commit brauchen die volle Historie (fetch-depth: 0)." >&2
  exit 2
fi

tmp=$(mktemp -d "${TMPDIR:-/tmp}/doc-fragments.XXXXXX")
trap 'rm -rf "$tmp"' EXIT HUP INT TERM

# Alles ab der ersten "## "-Ueberschrift; davor steht nur die Einleitung.
changelog_body() { awk 'f || /^## / { f = 1; print }'; }

unit_total=4
unit_index=0
unit_errors=0
unit_start() {
  unit_index=$((unit_index + 1))
  unit_errors=$errors
  [ "$quiet" -eq 1 ] || echo "[$unit_index/$unit_total] RUN doc-fragments $1"
}
unit_end() {
  if [ "$quiet" -eq 0 ]; then
    result=pass
    [ "$errors" -eq "$unit_errors" ] || result=fail
    echo "[$unit_index/$unit_total] $result doc-fragments $1"
  fi
}

# --- 1. Kopf des CHANGELOG ----------------------------------------------------
unit_start changelog-head
if [ ! -f "$changelog" ]; then
  fail no-ssot "$changelog fehlt."
else
  if grep -n '^## Noch in keinem Release' "$changelog" >&2; then
    fail unreleased-section "$changelog hat wieder einen Abschnitt \"Noch in keinem Release\"; neue Eintraege gehoeren nach $DOC_FRAGMENT_CHANGELOG_DIR/<PAKET>.md."
  fi
  if awk '/^## / { exit } /^[-*] / { printf "%d: %s\n", NR, substr($0, 1, 160); found = 1 } END { exit !found }' "$changelog" >&2; then
    fail preamble-entry "$changelog hat Eintraege vor der ersten Release-Ueberschrift; neue Eintraege gehoeren nach $DOC_FRAGMENT_CHANGELOG_DIR/<PAKET>.md."
  fi
  first_heading=$(grep -m 1 '^## ' "$changelog" || true)
  if ! printf '%s\n' "$first_heading" | grep -qE "$release_heading_re"; then
    fail first-heading "die erste Ueberschrift in $changelog ist '$first_heading', erwartet '## JJJJ.MM.N (TT.MM.JJJJ)'."
  fi
fi
unit_end changelog-head

# --- 2. Seit dem letzten Release-Commit nichts dazu ---------------------------
unit_start changelog-since-release
release_commit=$(git log -1 --format=%H -- "$version_file" 2>/dev/null || true)
if [ -z "$release_commit" ]; then
  fail no-ssot "kein Commit aendert $version_file; ohne Release-Commit ist nicht ableitbar, was seitdem neu ist."
elif ! git diff --quiet HEAD -- "$version_file" 2>/dev/null; then
  note "$version_file ist im Arbeitsbaum geaendert: Release in Arbeit, Regel ruht."
elif [ -f "$changelog" ]; then
  short=$(git rev-parse --short "$release_commit")
  if ! git show "$release_commit:./$changelog" > "$tmp/released.md" 2>/dev/null; then
    fail no-ssot "$changelog ist im Release-Commit $short nicht lesbar."
  else
    changelog_body < "$tmp/released.md" > "$tmp/released-body.md"
    changelog_body < "$changelog" > "$tmp/current-body.md"
    released_entries=$(grep -c '^- ' "$tmp/released-body.md" || true)
    current_entries=$(grep -c '^- ' "$tmp/current-body.md" || true)
    if [ "$current_entries" -gt "$released_entries" ]; then
      diff "$tmp/released-body.md" "$tmp/current-body.md" | grep '^> - ' | cut -c3-162 | head -n 3 >&2 || true
      fail new-entry "$changelog hat seit dem Release-Commit $short $((current_entries - released_entries)) Eintrag/Eintraege mehr; neue Eintraege gehoeren nach $DOC_FRAGMENT_CHANGELOG_DIR/<PAKET>.md, der naechste Release-Commit fuehrt sie zusammen."
    fi
    grep -E '^#{2,6} ' "$tmp/released-body.md" > "$tmp/released-headings" || true
    new_headings=$(grep -E '^#{2,6} ' "$tmp/current-body.md" | grep -vxF -f "$tmp/released-headings" || true)
    if [ -n "$new_headings" ]; then
      printf '%s\n' "$new_headings" | head -n 3 >&2
      fail new-heading "$changelog hat seit dem Release-Commit $short eine neue Ueberschrift; Release-Ueberschriften setzt nur scripts/merge-changelog-fragments.sh im Release-Commit."
    fi
  fi
  stale=$(git ls-tree --name-only "$release_commit" -- "./$DOC_FRAGMENT_CHANGELOG_DIR/" 2>/dev/null | grep -v '/README\.md$' || true)
  if [ -n "$stale" ]; then
    printf '%s\n' "$stale" >&2
    fail stale-fragment "der Release-Commit $short enthielt noch CHANGELOG-Fragmente; sie stehen nicht unter seiner Release-Ueberschrift. Mit scripts/merge-changelog-fragments.sh nachholen."
  fi
fi
unit_end changelog-since-release

# --- 3. Archivregister bekommt nichts dazu ------------------------------------
unit_start register-archive
{
  [ -d "$archive_scope" ] && grep -rlxF --include='*.md' "$archive_marker" "$archive_scope" 2>/dev/null || true
  git log --format= --name-only --relative -G"$archive_marker_re" -- "$archive_scope" 2>/dev/null || true
} | sed '/^$/d' | LC_ALL=C sort -u > "$tmp/archives"
if [ ! -s "$tmp/archives" ]; then
  fail no-archive "keine Datei unter $archive_scope traegt oder trug die Zeile '$archive_marker'; ohne Archiv gibt es nichts zu schuetzen, und ein leerer Scan ist kein Gruen."
fi
while IFS= read -r archive; do
  [ -n "$archive" ] || continue
  if [ ! -f "$archive" ]; then
    fail archive-removed "$archive war Archivregister und fehlt; Links von aussen zeigen darauf."
    continue
  fi
  if ! grep -qxF "$archive_marker" "$archive"; then
    fail archive-marker "$archive trug die Zeile '$archive_marker' und hat sie verloren; damit fiele das Archiv still aus dem Schutz."
    continue
  fi
  frozen=$(git log --format=%H -G"$archive_marker_re" -- "$archive" 2>/dev/null | tail -n 1)
  if [ -z "$frozen" ]; then
    note "$archive: Archivzeile noch nicht committet, Schutz ab dem Commit."
    continue
  fi
  git diff --no-color --no-ext-diff -U0 "$frozen" -- "$archive" | grep '^+' | grep -v '^+++ ' > "$tmp/added" || true
  if [ -s "$tmp/added" ]; then
    cut -c1-160 "$tmp/added" | head -n 3 >&2
    fail archive-entry "$archive ist seit $(git rev-parse --short "$frozen") Archiv und hat $(wc -l < "$tmp/added" | tr -d ' ') neue Zeile(n); neue Registereintraege gehoeren nach $DOC_FRAGMENT_REGISTER_DIR/JJJJ-MM-TT-<PAKET>.md."
  fi
done < "$tmp/archives"
unit_end register-archive

# --- 4. Form der Fragmente ----------------------------------------------------
unit_start fragments
for pair in "changelog $DOC_FRAGMENT_CHANGELOG_DIR" "register $DOC_FRAGMENT_REGISTER_DIR"; do
  kind=${pair%% *}
  dir=${pair#* }
  if [ ! -f "$dir/README.md" ]; then
    fail no-ssot "$dir/README.md fehlt; der Ordner beschreibt dort seine Form."
    continue
  fi
  doc_fragment_list "$dir" > "$tmp/fragments"
  while IFS= read -r fragment; do
    problem=$(doc_fragment_problem "$kind" "$fragment")
    [ -z "$problem" ] || fail fragment-format "$fragment: $problem."
  done < "$tmp/fragments"
  note "$dir: $(wc -l < "$tmp/fragments" | tr -d ' ') Fragment(e)."
done
unit_end fragments

if [ "$errors" -gt 0 ]; then
  echo "check-doc-fragments: $errors Fehler." >&2
  exit 1
fi
[ "$quiet" -eq 1 ] || echo "check-doc-fragments: CHANGELOG und Register nur als Fragmente."
