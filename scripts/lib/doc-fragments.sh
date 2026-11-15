# shellcheck shell=sh
# scripts/lib/doc-fragments.sh - Form der CHANGELOG- und Register-Fragmente (B1).
#
# Wird von scripts/check-doc-fragments.sh (Waechter) und
# scripts/merge-changelog-fragments.sh (Release-Commit) per `.` geladen, damit
# beide dieselbe Regel anwenden: ein Fragment, das der Waechter durchlaesst,
# muss das Zusammenfuehrskript auch annehmen, und umgekehrt.
#
# Ablage:
#   docs/changelog.d/<PAKET>.md         CHANGELOG-Eintraege eines Pakets bis zum Release
#   docs/register/JJJJ-MM-TT-<PAKET>.md Registereintrag eines Pakets, bleibt dauerhaft
# README.md in beiden Ordnern beschreibt die Form und ist kein Fragment.

# Die beiden Ordner lesen die ladenden Skripte (SC2034 sieht nur diese Datei).
# shellcheck disable=SC2034
DOC_FRAGMENT_CHANGELOG_DIR='docs/changelog.d'
# shellcheck disable=SC2034
DOC_FRAGMENT_REGISTER_DIR='docs/register'
# Paketkennung wie im Projekt ueblich: SEC2, RL1-D7, K21-F, BK-N5.
DOC_FRAGMENT_ID_RE='[A-Z][A-Z0-9]*(-[A-Z0-9]+)*'

# doc_fragment_problem <changelog|register> <datei>
# Schreibt eine Zeile mit dem ersten Formfehler nach stdout, sonst nichts.
#
# Ein awk-Aufruf je Datei (QL4): die fruehere Form startete je Fragment rund
# zehn Prozesse (basename, grep, head, od, tr, sed), unter Git Bash etwa 0,45 s
# je Datei und 12 der 16 s des Gates. Reihenfolge und Wortlaut der Befunde sind
# unveraendert: Name, regulaere Datei, leer, BOM, dann die Form je Art. Die
# Ausdruecke kommen ohne Intervall-Quantoren aus, damit auch mawk und BusyBox
# awk sie lesen; LC_ALL=C laesst awk Bytes vergleichen (BOM). CR zaehlt wie
# bisher nicht: jede Zeile wird ohne CR gelesen.
doc_fragment_problem() {
  kind=$1
  file=$2
  name=${file##*/}
  case "$kind" in
    changelog | register) ;;
    *) echo "unbekannte Fragmentart '$kind'"; return 0 ;;
  esac
  # Keine regulaere Datei: awk liest dann nichts, der Name entscheidet aber
  # weiter zuerst, welche Meldung kommt.
  regular=0
  input=/dev/null
  if [ -f "$file" ]; then
    regular=1
    input=$file
  fi
  LC_ALL=C awk -v kind="$kind" -v name="$name" -v regular="$regular" -v id_re="$DOC_FRAGMENT_ID_RE" '
    BEGIN {
      if (kind == "changelog") {
        name_re = "^" id_re "\\.md$"
      } else {
        name_re = "^20[0-9][0-9]-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])-" id_re "\\.md$"
      }
      if (name !~ name_re) {
        if (kind == "changelog") {
          print "Dateiname '\''" name "'\'' passt nicht zu <PAKET>.md (Paketkennung in Grossbuchstaben, z. B. SEC2.md, RL1-D7.md)"
        } else {
          print "Dateiname '\''" name "'\'' passt nicht zu JJJJ-MM-TT-<PAKET>.md (Startdatum des Pakets, z. B. 2026-11-05-B1.md)"
        }
        done = 1
        exit
      }
      if (!regular) {
        print "keine regulaere Datei"
        done = 1
        exit
      }
      id = name
      sub(/^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]-/, "", id)
      sub(/\.md$/, "", id)
    }
    NR == 1 { bom = (substr($0, 1, 3) == "\357\273\277") }
    {
      line = $0
      gsub(/\r/, "", line)
      if (NR == 1) first_line = line
      if (line ~ /[^[:space:]]/) {
        filled++
        if (first_filled == "") first_filled = line
        if (bad == "" && line !~ /^(- \*\*|  )/) bad = NR
      }
    }
    END {
      if (done) exit
      if (filled == 0) { print "Fragment ist leer"; exit }
      if (bom) { print "Fragment beginnt mit einer UTF-8-BOM"; exit }
      if (kind == "changelog") {
        if (substr(first_filled, 1, 4) != "- **") {
          print "erste Zeile ist kein Eintrag der Form '\''- **Titel.** Text'\''"
          exit
        }
        # Ein Eintrag ist eine Zeile "- **..."; Fortsetzungszeilen sind
        # eingerueckt. Ueberschriften, Absaetze und andere Listenformen gehoeren
        # nicht hinein: das Skript setzt die Zeilen unveraendert unter die
        # Release-Ueberschrift.
        if (bad != "") print "Zeile " bad " ist weder ein Eintrag '\''- **...'\'' noch eine eingerueckte Fortsetzung"
        exit
      }
      if (first_line != "# " id && index(first_line, "# " id " ") != 1 && index(first_line, "# " id ":") != 1 && index(first_line, "# " id ",") != 1) {
        print "erste Zeile muss die Ueberschrift '\''# " id " ...'\'' sein"
        exit
      }
      if (filled < 2) print "Registereintrag hat nur die Ueberschrift"
    }
  ' "$input"
  return 0
}

# doc_fragment_list <ordner> - Fragmentdateien eines Ordners, stabil nach Name.
# README.md zaehlt nicht dazu; alles andere (auch Nicht-.md) wird geprueft.
doc_fragment_list() {
  [ -d "$1" ] || return 0
  for entry in "$1"/* "$1"/.[!.]*; do
    [ -e "$entry" ] || continue
    [ "${entry##*/}" = README.md ] && continue
    printf '%s\n' "$entry"
  done | LC_ALL=C sort
}
