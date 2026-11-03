# shellcheck shell=sh
# scripts/lib/backup_crypto.sh — Backup-Verschluesselung (K19, VT-E2).
#
# Nur Funktionen, keine Wirkung beim Einbinden. backup.sh und restore_test.sh
# binden diese Datei per "." ein; Tests rufen die Funktionen einzeln auf.
#
# Verschluesselt wird an einen oeffentlichen Schluessel (X.509-Zertifikat,
# RSA): CMS EnvelopedData mit AES-256-CBC, DER-kodiert. Der private Schluessel
# liegt nie auf dem App-Host. Die Verschluesselung schuetzt die Vertraulichkeit,
# nicht die Echtheit: wer das Zertifikat hat, kann gueltige Umschlaege bauen.
# Beschaedigung erkennt weiterhin das Manifest. CBC statt GCM, damit auch ein
# Restore-Rechner mit OpenSSL 1.1 entschluesseln kann.

# SHA-256-Fingerabdruck des Zertifikats $1 als Kleinbuchstaben-Hex ohne
# Trennzeichen; gleiche Form wie openssl_x509_fingerprint() im Portal.
backup_crypto_fingerprint() {
  openssl x509 -in "$1" -noout -fingerprint -sha256 2>/dev/null \
    | sed -e 's/^[^=]*=//' -e 's/://g' | tr 'ABCDEF' 'abcdef'
}

# Normalisiert den Pin aus .env ($1, Rohwert) zur selben Form wie der Fingerabdruck
# oben: 64 Kleinbuchstaben-Hex ohne Trennzeichen. Akzeptiert werden Grossbuchstaben,
# Doppelpunkte, CR, Anfuehrungszeichen und das Praefix "SHA256 Fingerprint="
# (Ausgabe von openssl x509 -fingerprint, auch in Kleinschrift). Ausgabe leer,
# wenn das Ergebnis nicht genau 64 Hexzeichen sind.
backup_crypto_normalize_pin() {
  _pin=$(printf '%s' "$1" | tr -d '\r"\047' | tr 'A-Z' 'a-z')
  _pin=$(printf '%s' "$_pin" | sed -e 's/^[[:space:]]*//' -e 's/^sha256[[:space:]]*fingerprint=//')
  _pin=$(printf '%s' "$_pin" | tr -d ': \t')
  if printf '%s' "$_pin" | grep -Eq '^[0-9a-f]{64}$'; then
    printf '%s' "$_pin"
  fi
  return 0
}

# Verschluesselt Datei $2 nach $3 fuer das Zertifikat $1.
backup_crypto_encrypt() {
  openssl cms -encrypt -binary -aes256 -stream -outform DER -in "$2" -out "$3" "$1"
}

# Entschluesselt Datei $2 nach $3 mit dem privaten Schluessel $1.
backup_crypto_decrypt() {
  openssl cms -decrypt -binary -inform DER -in "$2" -out "$3" -inkey "$1"
}
