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

DOC_FRAGMENT_CHANGELOG_DIR='docs/changelog.d'
DOC_FRAGMENT_REGISTER_DIR='docs/register'
# Paketkennung wie im Projekt ueblich: SEC2, RL1-D7, K21-F, BK-N5.
DOC_FRAGMENT_ID_RE='[A-Z][A-Z0-9]*(-[A-Z0-9]+)*'

# doc_fragment_problem <changelog|register> <datei>
# Schreibt eine Zeile mit dem ersten Formfehler nach stdout, sonst nichts.
doc_fragment_problem() {
  kind=$1
  file=$2
  name=$(basename "$file")
  case "$kind" in
    changelog) name_re="^$DOC_FRAGMENT_ID_RE\\.md\$" ;;
    register) name_re="^20[0-9]{2}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])-$DOC_FRAGMENT_ID_RE\\.md\$" ;;
    *) echo "unbekannte Fragmentart '$kind'"; return 0 ;;
  esac
  if ! printf '%s\n' "$name" | grep -qE "$name_re"; then
    if [ "$kind" = changelog ]; then
      echo "Dateiname '$name' passt nicht zu <PAKET>.md (Paketkennung in Grossbuchstaben, z. B. SEC2.md, RL1-D7.md)"
    else
      echo "Dateiname '$name' passt nicht zu JJJJ-MM-TT-<PAKET>.md (Startdatum des Pakets, z. B. 2026-11-05-B1.md)"
    fi
    return 0
  fi
  if [ ! -f "$file" ]; then
    echo "keine regulaere Datei"
    return 0
  fi
  if [ ! -s "$file" ] || ! grep -q '[^[:space:]]' "$file"; then
    echo "Fragment ist leer"
    return 0
  fi
  # CRLF ist kein Befund: `* text=auto` checkt .md unter Windows mit CRLF aus,
  # und Git speichert LF. Die Pruefung liest deshalb ohne CR, und das
  # Zusammenfuehrskript schreibt die Eintraege ohne CR.
  if [ "$(head -c 3 "$file" | od -An -tx1 | tr -d ' \n')" = efbbbf ]; then
    echo "Fragment beginnt mit einer UTF-8-BOM"
    return 0
  fi
  if [ "$kind" = changelog ]; then
    first=$(tr -d '\r' < "$file" | grep -m 1 '[^[:space:]]')
    case "$first" in
      '- **'*) ;;
      *) echo "erste Zeile ist kein Eintrag der Form '- **Titel.** Text'"; return 0 ;;
    esac
    # Ein Eintrag ist eine Zeile '- **...'; Fortsetzungszeilen sind eingerueckt.
    # Ueberschriften, Absaetze und andere Listenformen gehoeren nicht hinein:
    # das Skript setzt die Zeilen unveraendert unter die Release-Ueberschrift.
    bad=$(tr -d '\r' < "$file" | grep -n '[^[:space:]]' | grep -vE '^[0-9]+:(- \*\*|  )' | head -n 1 || true)
    if [ -n "$bad" ]; then
      echo "Zeile ${bad%%:*} ist weder ein Eintrag '- **...' noch eine eingerueckte Fortsetzung"
      return 0
    fi
  else
    id=$(printf '%s\n' "$name" | sed -E 's/^[0-9]{4}-[0-9]{2}-[0-9]{2}-//; s/\.md$//')
    first=$(head -n 1 "$file" | tr -d '\r')
    case "$first" in
      "# $id" | "# $id "* | "# $id:"* | "# $id,"*) ;;
      *) echo "erste Zeile muss die Ueberschrift '# $id ...' sein"; return 0 ;;
    esac
    if [ "$(grep -c '[^[:space:]]' "$file")" -lt 2 ]; then
      echo "Registereintrag hat nur die Ueberschrift"
      return 0
    fi
  fi
  return 0
}

# doc_fragment_list <ordner> - Fragmentdateien eines Ordners, stabil nach Name.
# README.md zaehlt nicht dazu; alles andere (auch Nicht-.md) wird geprueft.
doc_fragment_list() {
  [ -d "$1" ] || return 0
  for entry in "$1"/* "$1"/.[!.]*; do
    [ -e "$entry" ] || continue
    [ "$(basename "$entry")" = README.md ] && continue
    printf '%s\n' "$entry"
  done | LC_ALL=C sort
}
