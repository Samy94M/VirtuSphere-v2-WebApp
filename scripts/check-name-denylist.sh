#!/bin/sh
# Namensschutz (NS1): verhindert, dass Eintraege der privaten Namensliste
# (Servernamen, FQDNs und Domainteile, Prod-IPs, DP-Namen, Site-Code, echte
# MACs, Prod-VM-/Rolloutnamen) in Commits dieses oeffentlichen Repos gelangen.
#
# Die Liste ist privat und liegt AUSSERHALB des Repos:
#   $VIRTUSPHERE_NAME_DENYLIST, sonst $HOME/.config/virtusphere/name-denylist.txt
# Vorlage: .githooks/name-denylist.template.txt (nur Kommentare).
#
# Listenformat (eine Zeile je Eintrag, UTF-8 oder ASCII, CRLF und BOM erlaubt):
#   # Kommentar          Leerzeilen und Zeilen mit '#' am Anfang zaehlen nicht
#   srv-a.example.test   feste Zeichenkette, Teilstring, ohne Gross/Klein
#   word:192.0.2.10      nur als ganzes Wort: kein Buchstabe, keine Ziffer
#                        und kein '_' direkt davor oder danach
#
# Aufruf:
#   sh scripts/check-name-denylist.sh --staged          gestagter Diff (pre-commit, pre-merge-commit)
#   sh scripts/check-name-denylist.sh --message <datei> Commit-Nachricht (commit-msg)
#   sh scripts/check-name-denylist.sh --range <a..b>    jeder Commit des Bereichs: Nachricht,
#                                                       hinzugefuegte Zeilen, Dateinamen
#   sh scripts/check-name-denylist.sh --check-list      nur die Liste pruefen
#
# Geprueft werden nur hinzugefuegte Zeilen und die Namen hinzugefuegter oder
# geaenderter Dateien (ohne Umbenennungserkennung: eine umbenannte Datei wird
# vollstaendig geprueft). Binaerdateien werden nur am Namen geprueft und als
# HINWEIS genannt: ein Feststring-Vergleich ueber Bilddaten traefe kurze
# Eintraege zufaellig, komprimierte Formate verbergen Text ohnehin.
#
# Ausgabe: Fundort und Listenzeile, nie der gefundene Name. Eintraege, die im
# Fundort selbst (Dateiname) vorkommen, werden als *** maskiert.
#
# Exitcodes: 0 kein Treffer | 1 Treffer | 2 Liste fehlt, ist unlesbar, leer
# oder ungueltig | 3 ungueltiger Aufruf oder git-Fehler.
set -u

usage() {
  sed -n '17,21p' "$0" | sed 's/^# \{0,1\}//'
}

die() {
  echo "FEHLER: [name-denylist.$1] $2" >&2
  exit "$3"
}

mode=''
value=''
case "${1:-}" in
  --staged|--check-list) mode=$1; [ "$#" -eq 1 ] || { usage >&2; exit 3; } ;;
  --message|--range)
    mode=$1
    [ "$#" -eq 2 ] && [ -n "$2" ] || { usage >&2; exit 3; }
    value=$2 ;;
  --help|-h) usage; exit 0 ;;
  *) usage >&2; exit 3 ;;
esac

list=${VIRTUSPHERE_NAME_DENYLIST:-}
if [ -z "$list" ]; then
  [ -n "${HOME:-}" ] || die missing 'HOME ist nicht gesetzt und VIRTUSPHERE_NAME_DENYLIST fehlt.' 2
  list=$HOME/.config/virtusphere/name-denylist.txt
fi

if [ ! -f "$list" ] || [ ! -r "$list" ]; then
  {
    echo "FEHLER: [name-denylist.missing] Namensliste fehlt oder ist nicht lesbar: $list"
    echo "  Anlegen: mkdir -p \"\$HOME/.config/virtusphere\" && cp .githooks/name-denylist.template.txt \"\$HOME/.config/virtusphere/name-denylist.txt\""
    echo "  Dann die Namen eintragen (Format im Dateikopf, Einrichtung in docs/QA.md, Abschnitt Name Denylist Hook)."
  } >&2
  exit 2
fi

# Liste auswerten: Anzahl Eintraege oder die erste ungueltige Listenzeile.
list_state=$(awk '
  { line = $0; sub(/\r$/, "", line); if (NR == 1) sub(/^\357\273\277/, "", line)
    gsub(/^[ \t]+|[ \t]+$/, "", line)
    if (line == "" || substr(line, 1, 1) == "#") next
    if (substr(line, 1, 5) == "word:") { line = substr(line, 6); gsub(/^[ \t]+/, "", line) }
    if (line == "" || line ~ /^\*+$/) { print "invalid " NR; exit }
    n++ }
  END { if (n == "") n = 0; print "count " n }' "$list") || die missing "Namensliste nicht lesbar: $list" 2
case "$list_state" in
  invalid*) die invalid "Listenzeile ${list_state#invalid } ist leer oder besteht nur aus '*'." 2 ;;
  'count 0') die empty "Namensliste enthaelt keine Eintraege (nur Kommentare oder leer): $list" 2 ;;
esac
entries=${list_state#count }

if [ "$mode" = --check-list ]; then
  echo "Namensliste lesbar: $entries Eintrag/Eintraege."
  exit 0
fi

tmp=$(mktemp -d "${TMPDIR:-/tmp}/name-denylist.XXXXXX") || die tmp 'temporaeres Verzeichnis nicht anlegbar.' 3
trap 'rm -rf "$tmp"' EXIT
trap 'exit 3' HUP INT TERM

# Datensaetze fuer den Abgleich: "c<TAB>fundort<TAB>text" wird geprueft,
# "b<TAB>fundort<TAB>" meldet eine nur am Namen gepruefte Binaerdatei.

# Unified Diff mit -U0 -> hinzugefuegte Zeilen mit Datei und Zeilennummer.
diff_records() { # $1 = Praefix des Fundorts
  awk -v pre="$1" '
    /^diff --git / { hdr = 1; path = ""; next }
    hdr && /^\+\+\+ / {
      p = substr($0, 5); sub(/\t$/, "", p)
      if (p == "/dev/null") path = ""; else { sub(/^b\//, "", p); path = p }
      next }
    /^@@ / { s = $0; sub(/^@@ -[0-9,]+ \+/, "", s); sub(/[ ,].*$/, "", s); ln = s + 0; hdr = 0; next }
    hdr { next }
    /^\+/ { if (path != "") print "c\t" pre path ":" ln "\t" substr($0, 2); ln++ }'
}

# Dateinamen und Binaerdateien aus --numstat (-<TAB>-<TAB>pfad = binaer).
name_records() { # $1 = Praefix des Fundorts
  awk -v pre="$1" '
    { t1 = index($0, "\t"); rest = substr($0, t1 + 1); t2 = index(rest, "\t")
      added = substr($0, 1, t1 - 1); path = substr(rest, t2 + 1)
      print "c\t" pre path " (Dateiname)\t" path
      if (added == "-") print "b\t" pre path "\t" }'
}

message_records() { # $1 = Fundort-Praefix, liest die Nachricht von stdin
  awk -v pre="$1" '
    { line = $0; sub(/\r$/, "", line) }
    line ~ /^. -+ >8 -+$/ { exit }
    { print "c\t" pre ":" NR "\t" line }'
}

# Diff zwischen $1 (Baum/Commit) und $2 (Commit oder leer = Index).
collect_diff() { # $1 = Basis, $2 = Ziel oder '', $3 = Praefix
  if [ -n "$2" ]; then set -- "$1" "$2" "$3" "$1" "$2"; else set -- "$1" "" "$3" --cached "$1"; fi
  git -c core.quotePath=false diff "$4" "$5" --no-color --no-ext-diff --no-textconv --no-renames \
    --diff-filter=AMT -U0 --src-prefix=a/ --dst-prefix=b/ >"$tmp/diff" || return 1
  git -c core.quotePath=false diff "$4" "$5" --no-renames --diff-filter=AMT --numstat >"$tmp/numstat" || return 1
  diff_records "$3" <"$tmp/diff" && name_records "$3" <"$tmp/numstat"
}

# Abgleich gegen die Liste. Gibt Fundort und Listenzeile aus, nie den Namen.
match_records() {
  # Pfad ueber die Umgebung: awk -v wuerde Backslashes eines Windows-Pfads
  # als Escape lesen.
  NAME_DENYLIST_FILE=$list awk '
    function isword(c) { return c ~ /[a-z0-9_]/ }
    function hit(text, e, w,   p, off, s, before, after, rest) {
      off = 0; rest = text
      while ((p = index(rest, e)) > 0) {
        if (!w) return 1
        s = off + p
        before = (s > 1) ? substr(text, s - 1, 1) : ""
        after = substr(text, s + length(e), 1)
        if (!isword(before) && !isword(after)) return 1
        off = s; rest = substr(text, s + 1)
      }
      return 0
    }
    function mask(s,   i, p, ls, out) {
      for (i = 1; i <= n; i++) {
        out = ""; ls = tolower(s)
        while ((p = index(ls, ent[i])) > 0) {
          out = out substr(s, 1, p - 1) "***"
          s = substr(s, p + length(ent[i])); ls = substr(ls, p + length(ent[i]))
        }
        s = out s
      }
      return s
    }
    BEGIN {
      listfile = ENVIRON["NAME_DENYLIST_FILE"]
      while ((getline line < listfile) > 0) {
        ln++; sub(/\r$/, "", line); if (ln == 1) sub(/^\357\273\277/, "", line)
        gsub(/^[ \t]+|[ \t]+$/, "", line)
        if (line == "" || substr(line, 1, 1) == "#") continue
        w = 0
        if (substr(line, 1, 5) == "word:") { w = 1; line = substr(line, 6); gsub(/^[ \t]+/, "", line) }
        n++; ent[n] = tolower(line); wrd[n] = w; num[n] = ln
      }
      close(listfile)
      found = 0
    }
    {
      t1 = index($0, "\t"); kind = substr($0, 1, t1 - 1); rest = substr($0, t1 + 1)
      t2 = index(rest, "\t"); loc = substr(rest, 1, t2 - 1); text = tolower(substr(rest, t2 + 1))
      if (kind == "b") { print "HINWEIS: Binaerdatei nur am Namen geprueft: " mask(loc); next }
      for (i = 1; i <= n; i++)
        if (hit(text, ent[i], wrd[i])) { print "TREFFER: " mask(loc) ": Listenzeile " num[i]; found++ }
    }
    END { if (found) exit 1 }'
}

report_hit() {
  echo "FEHLER: [name-denylist.hit] Eintraege der privaten Namensliste gefunden. Der Name wird nie angezeigt; die Listenzeile nennt den Eintrag. Durch synthetische Werte ersetzen (example.test, 192.0.2.0/24)." >&2
}

# Nur die Diff-Modi brauchen ein Repository; --message prueft jede Textdatei.
require_repo() {
  git rev-parse --git-dir >/dev/null 2>&1 || die git 'kein Git-Repository im aktuellen Verzeichnis.' 3
  empty_tree=$(git hash-object -t tree /dev/null) || die git 'leerer Baum nicht ermittelbar.' 3
}

case "$mode" in
  --staged)
    require_repo
    base=HEAD
    git rev-parse --verify -q HEAD >/dev/null || base=$empty_tree
    collect_diff "$base" '' '' >"$tmp/records" || die git 'gestagter Diff nicht lesbar.' 3
    if match_records <"$tmp/records"; then exit 0; fi
    report_hit; exit 1 ;;

  --message)
    [ -f "$value" ] && [ -r "$value" ] || die usage "Nachrichtendatei nicht lesbar: $value" 3
    message_records 'Commit-Nachricht' <"$value" >"$tmp/records"
    if match_records <"$tmp/records"; then exit 0; fi
    report_hit; exit 1 ;;

  --range)
    require_repo
    git rev-list --reverse "$value" -- >"$tmp/commits" 2>"$tmp/err" || die git "Bereich nicht aufloesbar: $value" 3
    total=$(awk 'END { print NR }' "$tmp/commits")
    if [ "$total" -eq 0 ]; then echo "Bereich $value enthaelt keine Commits."; exit 0; fi
    echo "[0/$total] Namensschutz ueber $total Commit(s)"
    i=0; failed=0
    while read -r c; do
      i=$((i + 1))
      short=$(git rev-parse --short "$c") || die git "Commit nicht lesbar: $c" 3
      echo "[$i/$total] RUN commit $short"
      parent=$(git rev-parse -q --verify "$c^1") || parent=$empty_tree
      git log -1 --format=%B "$c" >"$tmp/msg" || die git "Nachricht nicht lesbar: $short" 3
      message_records "$short Nachricht" <"$tmp/msg" >"$tmp/records"
      collect_diff "$parent" "$c" "$short " >>"$tmp/records" || die git "Diff nicht lesbar: $short" 3
      if match_records <"$tmp/records"; then
        echo "[$i/$total] pass commit $short"
      else
        failed=$((failed + 1))
        echo "[$i/$total] fail commit $short"
      fi
    done <"$tmp/commits"
    if [ "$failed" -gt 0 ]; then
      echo "$failed von $total Commit(s) mit Treffer."
      report_hit; exit 1
    fi
    echo "$total Commit(s) ohne Treffer."
    exit 0 ;;
esac
