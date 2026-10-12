# shellcheck shell=sh
# scripts/lib/env_file.sh - Einzelwert aus einer .env-Datei lesen (K19-A3).
#
# Nur Funktionen, keine Wirkung beim Einbinden. backup.sh und restore_test.sh
# binden diese Datei per "." ein, damit es genau einen .env-Parser gibt.
# Erste passende Zeile gewinnt, ein CR am Zeilenende faellt weg, und ein
# umschliessendes Anfuehrungszeichen-Paar wird entfernt.

# env_file_value DATEI SCHLUESSEL: gibt den Wert aus. Fehlt die Datei oder der
# Schluessel, ist die Ausgabe leer.
env_file_value() {
  [ -f "$1" ] || return 0
  tr -d '\r' < "$1" | grep -E "^$2=" | head -n 1 | cut -d= -f2- \
    | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//"
}
