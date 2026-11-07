#!/bin/sh
# Namensschutz (NS1, Muster seit NS3): verhindert, dass Servernamen, FQDNs und
# Domainteile, Prod-IPs, DP-Namen, Site-Code, echte MACs und Prod-VM- oder
# Rolloutnamen in Commits dieses oeffentlichen Repos gelangen.
#
# Zwei Listen, beide ohne echte Werte:
#   Musterliste (Pflicht, getrackt): .githooks/name-patterns.txt neben diesem
#     Skript, anderer Ort ueber VIRTUSPHERE_NAME_PATTERNS. Fehlt sie, ist sie
#     unlesbar, ohne Treffermuster oder ungueltig: Exit 2 (fail closed).
#   Private Liste (optional): VIRTUSPHERE_NAME_DENYLIST, sonst
#     $HOME/.config/virtusphere/name-denylist.txt. Fehlt die Datei am
#     Standardort, gilt nur die Musterliste; eine ueber die Variable genannte
#     Datei muss es geben. Vorlage: .githooks/name-denylist.template.txt.
#
# Listenformat (eine Zeile je Eintrag, UTF-8 oder ASCII, CRLF und BOM erlaubt):
#   # Kommentar          Leerzeilen und Zeilen mit '#' am Anfang zaehlen nicht
#   srv-a.example.test   feste Zeichenkette, Teilstring, ohne Gross/Klein
#   word:192.0.2.10      feste Zeichenkette nur als ganzes Wort: kein Buchstabe,
#                        keine Ziffer und kein '_' direkt davor oder danach
#   re:<ERE>             POSIX-ERE ohne Gross/Klein (klein schreiben), Treffer
#                        nur als ganzes Wort wie word:; zusaetzlich zaehlt ein
#                        '.' davor oder danach als Fortsetzung, wenn dahinter
#                        ein Buchstabe oder eine Ziffer steht (OIDs, Versionen)
#   allow:<ERE>          Freigabe: ein re:-Treffer, dessen Text ein allow:-Muster
#                        ganz erfasst, zaehlt nicht. Gilt nicht fuer feste
#                        Zeichenketten.
# Muster ohne Wiederholungsangabe {n,m} (nicht jedes awk kennt sie) und ohne
# Zeichenklassen wie [[:alpha:]]; ein Muster, das die leere Zeichenkette
# trifft, ist ungueltig.
#
# Geprueft werden nur hinzugefuegte Zeilen und die Namen hinzugefuegter oder
# geaenderter Dateien (ohne Umbenennungserkennung: eine umbenannte Datei wird
# vollstaendig geprueft). Binaerdateien werden nur am Namen geprueft und als
# HINWEIS genannt: ein Vergleich ueber Bilddaten traefe kurze Eintraege
# zufaellig, komprimierte Formate verbergen Text ohnehin.
#
# Ausgabe: Fundort und Listendatei:Zeile, nie der gefundene Text. Treffer im
# Fundort selbst (Dateiname) werden als *** maskiert.
#
# Exitcodes: 0 kein Treffer | 1 Treffer | 2 Liste fehlt, ist unlesbar, leer
# oder ungueltig | 3 ungueltiger Aufruf oder git-Fehler.
set -u

usage() {
  cat <<'EOF'
Aufruf:
  sh scripts/check-name-denylist.sh --staged          gestagter Diff (pre-commit, pre-merge-commit)
  sh scripts/check-name-denylist.sh --message <datei> Commit-Nachricht (commit-msg)
  sh scripts/check-name-denylist.sh --range <a..b>    jeder Commit des Bereichs: Nachricht,
                                                      hinzugefuegte Zeilen, Dateinamen
  sh scripts/check-name-denylist.sh --tree <stand>    alle Zeilen und Dateinamen eines Stands
  sh scripts/check-name-denylist.sh --check-list      nur die Listen pruefen
EOF
}

die() {
  echo "FEHLER: [name-denylist.$1] $2" >&2
  exit "$3"
}

mode=''
value=''
case "${1:-}" in
  --staged|--check-list) mode=$1; [ "$#" -eq 1 ] || { usage >&2; exit 3; } ;;
  --message|--range|--tree)
    mode=$1
    [ "$#" -eq 2 ] && [ -n "$2" ] || { usage >&2; exit 3; }
    value=$2 ;;
  --help|-h) usage; exit 0 ;;
  *) usage >&2; exit 3 ;;
esac

patterns=${VIRTUSPHERE_NAME_PATTERNS:-$(dirname "$0")/../.githooks/name-patterns.txt}
if [ ! -f "$patterns" ] || [ ! -r "$patterns" ]; then
  {
    echo "FEHLER: [name-denylist.missing] Musterliste fehlt oder ist nicht lesbar: $patterns"
    echo "  Die Musterliste ist getrackt (.githooks/name-patterns.txt); den Stand des Repos pruefen."
  } >&2
  exit 2
fi

private=${VIRTUSPHERE_NAME_DENYLIST:-}
if [ -n "$private" ]; then
  if [ ! -f "$private" ] || [ ! -r "$private" ]; then
    {
      echo "FEHLER: [name-denylist.missing] VIRTUSPHERE_NAME_DENYLIST nennt keine lesbare Datei: $private"
      echo "  Variable entfernen (die private Liste ist optional) oder die Datei anlegen, Vorlage .githooks/name-denylist.template.txt."
    } >&2
    exit 2
  fi
elif [ -n "${HOME:-}" ] && [ -f "$HOME/.config/virtusphere/name-denylist.txt" ]; then
  private=$HOME/.config/virtusphere/name-denylist.txt
  [ -r "$private" ] || die missing "private Namensliste nicht lesbar: $private" 2
fi

# Anzeigenamen der Listen in Treffern und Fehlern.
patterns_label=$(basename "$patterns")
private_label=''
[ -z "$private" ] || private_label=$(basename "$private")

# awk-Bibliothek: load() liest eine Liste in kind[] (f fest, w word, r re),
# pat[], num[], lab[] (n Eintraege) und alw[] (na Freigaben). Die erste
# ungueltige Zeile landet in bad ("Liste:Zeile Grund").
# Pfade kommen ueber die Umgebung: awk -v wuerde Backslashes eines
# Windows-Pfads als Escape lesen.
lib_awk='
function load(file, label,   line, ln, k) {
  ln = 0
  while ((getline line < file) > 0) {
    ln++; sub(/\r$/, "", line); if (ln == 1) sub(/^\357\273\277/, "", line)
    gsub(/^[ \t]+|[ \t]+$/, "", line)
    if (line == "" || substr(line, 1, 1) == "#") continue
    k = "f"
    if (substr(line, 1, 5) == "word:") { k = "w"; line = substr(line, 6) }
    else if (substr(line, 1, 3) == "re:") { k = "r"; line = substr(line, 4) }
    else if (substr(line, 1, 6) == "allow:") { k = "a"; line = substr(line, 7) }
    gsub(/^[ \t]+/, "", line)
    line = tolower(line)
    if (line == "") { if (bad == "") bad = label ":" ln " ist nach dem Praefix leer"; continue }
    if ((k == "f" || k == "w") && line ~ /^\*+$/) { if (bad == "") bad = label ":" ln " besteht nur aus *"; continue }
    if ((k == "r" || k == "a") && line ~ /[{][0-9,]/) { if (bad == "") bad = label ":" ln " nutzt eine Wiederholungsangabe {n,m}"; continue }
    if (k == "a") { na++; alw[na] = line; anum[na] = ln; alab[na] = label; continue }
    n++; kind[n] = k; pat[n] = line; num[n] = ln; lab[n] = label
  }
  close(file)
}
BEGIN {
  n = 0; na = 0; bad = ""
  load(ENVIRON["NAME_PATTERNS_FILE"], ENVIRON["NAME_PATTERNS_LABEL"]); np = n; nap = na
  if (ENVIRON["NAME_DENYLIST_FILE"] != "") load(ENVIRON["NAME_DENYLIST_FILE"], ENVIRON["NAME_DENYLIST_LABEL"])
}'

run_awk() { # $1 = awk-Programm nach der Bibliothek
  NAME_PATTERNS_FILE=$patterns NAME_PATTERNS_LABEL=$patterns_label \
  NAME_DENYLIST_FILE=$private NAME_DENYLIST_LABEL=$private_label \
    awk "$lib_awk$1"
}

# Listen pruefen. Vor jedem Muster steht "try Liste:Zeile" auf stdout: bricht
# awk an einem unuebersetzbaren Muster ab, nennt die letzte try-Zeile es.
list_state=$(run_awk '
BEGIN {
  if (bad != "") { print "invalid " bad; exit }
  for (i = 1; i <= n; i++) if (kind[i] == "r") {
    print "try " lab[i] ":" num[i]; fflush()
    if ("" ~ ("^(" pat[i] ")$")) { print "invalid " lab[i] ":" num[i] " trifft die leere Zeichenkette"; exit }
  }
  for (i = 1; i <= na; i++) { print "try " alab[i] ":" anum[i]; fflush(); x = ("" ~ ("^(" alw[i] ")$")) }
  print "count " np " " nap " " (n - np) " " (na - nap)
}' 2>/dev/null)
last=$(printf '%s\n' "$list_state" | sed -n '$p')
case "$last" in
  count*) ;;
  invalid*) die invalid "Listenzeile ${last#invalid }." 2 ;;
  try*) die invalid "Listenzeile ${last#try } ist kein gueltiges POSIX-ERE-Muster." 2 ;;
  *) die missing 'Listen nicht lesbar.' 2 ;;
esac
read -r _ pattern_entries pattern_allows private_entries private_allows <<EOF
$last
EOF
[ "$pattern_entries" -gt 0 ] || die empty "Musterliste enthaelt keine Treffermuster (nur Kommentare, Freigaben oder leer): $patterns" 2

if [ "$mode" = --check-list ]; then
  echo "Musterliste lesbar ($patterns_label): $pattern_entries Muster, $pattern_allows Freigabe(n)."
  if [ -n "$private" ]; then
    echo "Private Liste lesbar ($private_label): $private_entries Eintrag/Eintraege, $private_allows Freigabe(n)."
  else
    echo "Private Liste nicht vorhanden (optional)."
  fi
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

# Abgleich gegen beide Listen. Gibt Fundort und Listendatei:Zeile aus, nie
# den gefundenen Text.
match_records() {
  run_awk '
    function isword(c) { return c ~ /[a-z0-9_]/ }
    function fixhit(text, e, w,   p, off, s, before, after, rest) {
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
    # Ganzes Wort fuer re:-Treffer; ein Punkt mit Buchstabe oder Ziffer
    # dahinter setzt das Wort fort (OID, Versionsnummer, Dateiendung).
    function bound(t, st, len,   b, a) {
      b = (st > 1) ? substr(t, st - 1, 1) : ""
      a = substr(t, st + len, 1)
      if (isword(b) || isword(a)) return 0
      if (b == "." && st > 2 && substr(t, st - 2, 1) ~ /[a-z0-9]/) return 0
      if (a == "." && substr(t, st + len + 1, 1) ~ /[a-z0-9]/) return 0
      return 1
    }
    # Erster zaehlender Treffer von r in t: setzt HS/HL und gibt 1 zurueck.
    # Nach einem freigegebenen Treffer geht die Suche hinter ihm weiter (sonst
    # traefe das Ende eines freigegebenen Namens, etwa nach einem '-'), nach
    # einem Treffer ohne Wortgrenze ab dem naechsten Zeichen.
    function rehit(t, r,   s, off, w) {
      off = 0; s = t
      while (s != "" && match(s, r)) {
        HS = off + RSTART; HL = RLENGTH
        if (HL > 0 && bound(t, HS, HL)) {
          if (!(na && substr(t, HS, HL) ~ allowre)) return 1
          off = HS + HL - 1
        } else off = HS
        s = substr(t, off + 1)
      }
      return 0
    }
    function mask(s,   i, p, ls, out, guard) {
      for (i = 1; i <= n; i++) {
        if (kind[i] == "r") {
          guard = 0
          while (guard++ < 50 && rehit(tolower(s), pat[i])) s = substr(s, 1, HS - 1) "***" substr(s, HS + HL)
          continue
        }
        out = ""; ls = tolower(s)
        while ((p = index(ls, pat[i])) > 0) {
          out = out substr(s, 1, p - 1) "***"
          s = substr(s, p + length(pat[i])); ls = substr(ls, p + length(pat[i]))
        }
        s = out s
      }
      return s
    }
    BEGIN {
      # Ein Sammelmuster je Art, damit awk es nur einmal uebersetzt: anyre
      # filtert Zeilen ohne jeden Mustertreffer vorab.
      nre = 0; anyre = ""; allowre = ""
      for (i = 1; i <= n; i++) if (kind[i] == "r") { anyre = anyre (nre++ ? "|" : "") "(" pat[i] ")" }
      for (i = 1; i <= na; i++) allowre = allowre (i > 1 ? "|" : "") "(" alw[i] ")"
      if (na) allowre = "^(" allowre ")$"
      found = 0
    }
    {
      t1 = index($0, "\t"); rk = substr($0, 1, t1 - 1); rest = substr($0, t1 + 1)
      t2 = index(rest, "\t"); loc = substr(rest, 1, t2 - 1); text = tolower(substr(rest, t2 + 1))
      if (rk == "b") { print "HINWEIS: Binaerdatei nur am Namen geprueft: " mask(loc); next }
      for (i = 1; i <= n; i++) if (kind[i] != "r" && fixhit(text, pat[i], kind[i] == "w")) {
        print "TREFFER: " mask(loc) ": " lab[i] ":" num[i]; found++
      }
      if (nre && text ~ anyre)
        for (i = 1; i <= n; i++) if (kind[i] == "r" && rehit(text, pat[i])) {
          print "TREFFER: " mask(loc) ": " lab[i] ":" num[i]; found++
        }
    }
    END { if (found) exit 1 }'
}

report_hit() {
  echo "FEHLER: [name-denylist.hit] Treffer der Musterliste oder der privaten Namensliste. Der gefundene Text wird nie angezeigt; Listendatei und Zeile nennen das Muster. Durch synthetische Werte ersetzen (example.test, 192.0.2.0/24). Ein belegt synthetischer oder oeffentlicher Wert kommt als allow: in .githooks/name-patterns.txt, nie ein unklarer." >&2
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

  --tree)
    require_repo
    git rev-parse -q --verify "$value^{commit}" >/dev/null || die git "Stand nicht aufloesbar: $value" 3
    collect_diff "$empty_tree" "$value" '' >"$tmp/records" || die git "Stand nicht lesbar: $value" 3
    if match_records <"$tmp/records"; then echo "Stand $value ohne Treffer."; exit 0; fi
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
