#!/bin/sh
# scripts/merge-changelog-fragments.sh - CHANGELOG-Fragmente im Release-Commit zusammenfuehren (B1).
#
# Jedes Paket schreibt seine CHANGELOG-Eintraege nach docs/changelog.d/<PAKET>.md.
# Der Release-Commit hebt beide VERSION-Kopien an und ruft dieses Skript auf. Es
# setzt "## JJJJ.MM.N (TT.MM.JJJJ)" ueber die bisher oberste Release-Ueberschrift,
# darunter den optionalen Update-Absatz und die Eintraege aller Fragmente, und
# loescht die Fragmente. Den Rest des Release-Commits (Update-Absatz pruefen,
# stagen, committen) macht der Mensch oder die Release-Session.
#
# Reihenfolge: nach Dateiname (LC_ALL=C), also nach Paketkennung. Das Ergebnis
# haengt damit nur vom Inhalt des Ordners ab, nicht von Commit-Daten, die ein
# Rebase oder Cherry-Pick verschiebt, und der Trockenlauf zeigt genau, was der
# echte Lauf schreibt. Wer eine andere Reihenfolge will, ordnet vor dem Commit
# von Hand um.
#
# Aufruf (im Arbeitsbaum des Release-Commits, nach dem Anheben von VERSION):
#   sh scripts/merge-changelog-fragments.sh --dry-run                 zeigt den neuen Abschnitt
#   sh scripts/merge-changelog-fragments.sh --intro <datei>           schreibt und loescht
#   Optionen: --version JJJJ.MM.N (Vorgabe: Docker/WebAPI/lib/VERSION)
#             --date TT.MM.JJJJ   (Vorgabe: heute)
#             --intro <datei>     Update-Absatz unter der Ueberschrift
#
# Fortschritt: [0/N] vor dem ersten Fragment, [n/N] RUN und [n/N] pass|fail je
# Fragment. Ein leeres oder kaputtes Fragment bricht ab, bevor irgendetwas
# geschrieben wird; die Formregel teilt es mit scripts/check-doc-fragments.sh.
# VIRTUSPHERE_CHECK_ROOT uebersteuert das Repo-Root (Tests, Trockenlauf auf
# einer Kopie). Exit 0 fertig, 1 Befund (nichts geschrieben), 2 Aufruf.
set -eu
lib_dir=$(cd "$(dirname "$0")/lib" && pwd)
# shellcheck source=scripts/lib/doc-fragments.sh
. "$lib_dir/doc-fragments.sh"

usage() { echo "Usage: scripts/merge-changelog-fragments.sh [--dry-run] [--version JJJJ.MM.N] [--date TT.MM.JJJJ] [--intro <datei>]"; }
die() { # die <id> <text> [exit]
  echo "FEHLER: [changelog-merge.$1] $2" >&2
  exit "${3:-1}"
}

dry_run=0
version=''
release_date=''
intro=''
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) dry_run=1 ;;
    --version) [ $# -ge 2 ] || die usage '--version braucht einen Wert.' 2; version=$2; shift ;;
    --date) [ $# -ge 2 ] || die usage '--date braucht einen Wert.' 2; release_date=$2; shift ;;
    --intro) [ $# -ge 2 ] || die usage '--intro braucht eine Datei.' 2; intro=$2; shift ;;
    --help|-h) usage; exit 0 ;;
    *) usage >&2; die usage "unbekanntes Argument: $1" 2 ;;
  esac
  shift
done

# --intro gilt relativ zum Aufrufort, nicht zum Repo-Root.
case "$intro" in
  ''|/*|[A-Za-z]:*) ;;
  *) intro="$(pwd)/$intro" ;;
esac
cd "${VIRTUSPHERE_CHECK_ROOT:-$(dirname "$0")/..}"

changelog='docs/CHANGELOG.md'
version_file='Docker/WebAPI/lib/VERSION'
fragment_dir=$DOC_FRAGMENT_CHANGELOG_DIR

if [ -z "$version" ]; then
  [ -f "$version_file" ] || die no-version "$version_file fehlt; --version angeben."
  version=$(tr -d '\r' < "$version_file" | head -n 1)
fi
printf '%s\n' "$version" | grep -qE '^20[0-9]{2}\.(0[1-9]|1[0-2])\.([1-9]|[1-9][0-9])$' \
  || die version "Release-Nummer '$version' hat nicht die Form JJJJ.MM.N (Monat 01-12, N 1-99)." 2
[ -n "$release_date" ] || release_date=$(date +%d.%m.%Y)
printf '%s\n' "$release_date" | grep -qE '^(0[1-9]|[12][0-9]|3[01])\.(0[1-9]|1[0-2])\.20[0-9]{2}$' \
  || die date "Datum '$release_date' hat nicht die Form TT.MM.JJJJ." 2
if [ -n "$intro" ]; then
  [ -f "$intro" ] || die intro "Update-Absatz '$intro' fehlt." 2
  tr -d '\r' < "$intro" | grep -q '[^[:space:]]' || die intro "Update-Absatz '$intro' ist leer." 2
fi

[ -f "$changelog" ] || die no-changelog "$changelog fehlt."
if grep -q '^## Noch in keinem Release' "$changelog"; then
  die unreleased-section "$changelog hat noch den Abschnitt \"Noch in keinem Release\"; seine Eintraege zuerst als Fragmente nach $fragment_dir/ verschieben."
fi
heading="## $version ($release_date)"
if grep -qF "## $version (" "$changelog"; then
  die duplicate "$changelog hat schon eine Ueberschrift fuer $version; das Skript lief fuer dieses Release bereits."
fi
grep -q '^## ' "$changelog" || die no-changelog "$changelog hat keine \"## \"-Ueberschrift, unter die der Abschnitt gehoert."

tmp=$(mktemp -d "${TMPDIR:-/tmp}/changelog-merge.XXXXXX")
trap 'rm -rf "$tmp"' EXIT HUP INT TERM

doc_fragment_list "$fragment_dir" > "$tmp/list"
total=$(wc -l < "$tmp/list" | tr -d ' ')
[ "$total" -gt 0 ] || die no-fragments "keine Fragmente unter $fragment_dir/; ein Release ohne Eintraege ist fast immer ein Fehler (Fragmente auf einem anderen Branch?)."

echo "[0/$total] merge-changelog $version: $total Fragment(e), Reihenfolge nach Dateiname"
index=0
broken=0
: > "$tmp/entries"
while IFS= read -r fragment; do
  index=$((index + 1))
  name=$(basename "$fragment")
  echo "[$index/$total] RUN merge-changelog $name"
  problem=$(doc_fragment_problem changelog "$fragment")
  if [ -n "$problem" ]; then
    echo "FEHLER: [changelog-merge.fragment-format] $fragment: $problem." >&2
    echo "[$index/$total] fail merge-changelog $name"
    broken=$((broken + 1))
    continue
  fi
  # Ohne CR: ein Windows-Checkout liefert CRLF, der CHANGELOG im Objektspeicher LF.
  tr -d '\r' < "$fragment" | grep '[^[:space:]]' >> "$tmp/entries"
  echo "[$index/$total] pass merge-changelog $name"
done < "$tmp/list"
[ "$broken" -eq 0 ] || die fragment-format "$broken Fragment(e) kaputt; nichts geschrieben."

{
  printf '%s\n\n' "$heading"
  if [ -n "$intro" ]; then
    tr -d '\r' < "$intro" \
      | awk 'NF { last = NR } { line[NR] = $0 } END { for (i = 1; i <= last; i++) if (started || line[i] ~ /[^[:space:]]/) { started = 1; print line[i] } }'
    printf '\n'
  fi
  cat "$tmp/entries"
  printf '\n'
} > "$tmp/section"

if [ "$dry_run" -eq 1 ]; then
  echo "--- Trockenlauf: neuer Abschnitt in $changelog ---"
  cat "$tmp/section"
  echo "--- Trockenlauf: wuerde loeschen ---"
  cat "$tmp/list"
  echo "merge-changelog: Trockenlauf, nichts geschrieben."
  exit 0
fi

awk -v section="$tmp/section" '
  !done && /^## / {
    while ((getline line < section) > 0) print line
    close(section)
    done = 1
  }
  { print }
' "$changelog" > "$tmp/CHANGELOG.md"
cat "$tmp/CHANGELOG.md" > "$changelog"
while IFS= read -r fragment; do
  rm -f -- "$fragment"
done < "$tmp/list"
echo "merge-changelog: $total Fragment(e) unter \"$heading\" eingefuegt und geloescht."
echo "  Weiter: Abschnitt pruefen, dann git add -A $changelog $fragment_dir und committen."
