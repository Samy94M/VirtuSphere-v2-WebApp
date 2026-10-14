# APP_KEY wechseln

Der `APP_KEY` aus der `.env` verschlüsselt die gespeicherten Geheimnisse des
Portals (`lib/crypto.php`, libsodium secretbox). Das sind genau zwei Arten:

- die Passwörter der ESXi- und Ansible-Zugänge (`deploy_credentials.secret_ciphertext`),
- das Passwort des Active-Directory-Suchkontos (`deploy_ad_config.bind_secret_ciphertext`).

Benutzerpasswörter sind gehasht, der Rückkanal-Token ist als SHA-256 gespeichert;
beide hängen nicht am `APP_KEY`.

Das Werkzeug `lib/app_key_rotate.php` verschlüsselt beide Arten mit einem neuen
Schlüssel neu, ohne dass jemand die Passwörter kennen muss. Es braucht dafür den
alten Schlüssel. Ist der verloren, bleibt nur der Weg ohne Werkzeug am Ende dieser
Seite.

## Wann

- Der `APP_KEY` ist bekannt geworden oder war schwach.
- Die `.env` ist verloren und kein Backup mit ihr vorhanden: Dann sind die
  gespeicherten Geheimnisse nicht mehr lesbar, und nur der Weg ohne Werkzeug führt
  zurück.

## Wie das Werkzeug arbeitet

- **Neuer Schlüssel ist immer der laufende.** Zuerst kommt der neue Schlüssel in
  die `.env`, dann werden die Container neu erzeugt, erst danach läuft das
  Werkzeug. Es nimmt den `APP_KEY` des laufenden Containers als neuen Schlüssel
  und liest den alten von STDIN, nie aus einem Argument oder der Umgebung. So kann
  nichts mit einem Schlüssel verschlüsselt werden, der nicht in der Konfiguration
  steht. Die `.env` fasst das Werkzeug nicht an.
- **Trockenlauf zuerst.** Ohne `--apply` läuft dieselbe Transaktion, am Ende wird
  sie zurückgerollt. Geschrieben wird nur mit `--apply`.
- **Alles oder nichts.** Alle Werte werden in einer Transaktion neu verschlüsselt
  und vor dem Commit mit dem neuen Schlüssel gelesen und verglichen. Bricht der
  Lauf an irgendeiner Stelle ab, bleibt der alte Stand vollständig erhalten.
- **Wiederholbar.** Ein Wert, der sich schon mit dem neuen Schlüssel lesen lässt,
  bleibt unverändert und zählt als `already`. Ein zweiter Lauf ist deshalb
  gefahrlos.
- **Prüfungen vor dem Schreiben.** Das Werkzeug verweigert den Lauf, solange ein
  Auftrag aktiv ist, wenn alter und neuer Schlüssel gleich sind und wenn ein Wert
  mit keinem der beiden Schlüssel lesbar ist. `--apply` verlangt zusätzlich ein
  frisches Backup (siehe Schritt 2).
- **Audit.** Ein Lauf mit `--apply`, der mindestens einen Wert neu verschlüsselt,
  schreibt eine Audit-Zeile „Zugangsdaten mit neuem APP_KEY verschlüsselt“ mit den
  Anzahlen, nie mit Werten. Revisionen der Zugänge und des Verzeichnisses bleiben
  unverändert; ein bestandener Zugangstest bleibt bestanden.

## Ablauf

1. **Keine Aufträge.** Bereitstellungsdienst pausieren und warten, bis kein
   Auftrag mehr läuft.
2. **Backup vorher.** `sh scripts/backup.sh` ausführen und das Tripel mit
   `scripts/restore_test.sh` prüfen ([Backup und Restore](backup.md)). Das
   Werkzeug verlangt für `--apply`, dass die letzte Zeile der Backup-Statusdatei
   `ok` meldet und höchstens 2 Stunden alt ist; Schritt 3 bis 8 gehören deshalb in
   ein Wartungsfenster. Das Config-Archiv dieses Backups enthält die alte `.env`;
   nur mit ihr lassen sich ältere Backups später wieder lesen.
3. **Alten Schlüssel sichern.** Den bisherigen Wert in eine Datei schreiben, die
   nur root lesen kann, außerhalb des Repositorys:
   ```sh
   ( umask 077; grep '^APP_KEY=' .env | cut -d= -f2- > /root/app_key_alt )
   ```
4. **Neuen Schlüssel eintragen** (32 Zufallsbytes, base64), wie in
   `.env.example` beschrieben:
   ```sh
   sed -i "s#^APP_KEY=.*#APP_KEY=base64:$(openssl rand -base64 32)#" .env
   ```
5. **Alle Prozesse neu erzeugen:** `docker compose up -d --force-recreate --no-deps php deploy-worker maintenance-worker`,
   danach `docker compose restart webserver` (bei der Supervisor-Form zusätzlich mit
   `-f docker-compose.supervisor.yml`). Compose übernimmt die `.env` nur beim
   Erzeugen eines Containers; ein `restart` behält den alten Schlüssel. Ab hier
   scheitern Aufträge, Inventarabrufe, Zugangstests und Anmeldungen über AD an der
   Entschlüsselung, bis Schritt 7 durch ist. In dieser Zeit keine Zugänge
   bearbeiten.
6. **Trockenlauf:**
   ```sh
   docker compose exec -T php php /var/www/html/lib/app_key_rotate.php < /root/app_key_alt
   ```
   Die letzte Zeile nennt die Anzahlen, etwa
   `[2/2] PASS re-encrypt (dry run): credentials=4 directory=1 already=0`.
   `credentials` und `directory` sind die Werte, die neu verschlüsselt würden.
7. **Anwenden:** derselbe Befehl mit `--apply`:
   ```sh
   docker compose exec -T php php /var/www/html/lib/app_key_rotate.php --apply < /root/app_key_alt
   ```
   Erwartet ist `[2/2] PASS re-encrypt (applied): …` mit denselben Anzahlen wie
   im Trockenlauf.
8. **Prüfen.** Unter **Zugangsdaten** jeden Zugang **Testen**, das AD-Suchkonto
   unter **Benutzer > Active Directory** testen. Dann den Bereitstellungsdienst
   fortsetzen und einen lesenden Weg prüfen, etwa einen ESXi-Inventarabruf je
   Zugang.
9. **Backup nachher.** Ein neues Backup erstellen und mit `restore_test.sh`
   prüfen; es enthält die neue `.env`. Erst danach die Datei mit dem alten
   Schlüssel vernichten: `shred -u /root/app_key_alt`. Ältere Backups tragen ihre
   eigene `.env` und bleiben damit lesbar.

## Wenn das Werkzeug ablehnt

Das Werkzeug endet mit 0 bei Erfolg, mit 1 bei Ablehnung oder Fehler und mit 2
bei falschem Aufruf. Abgelehnt oder abgebrochen hat es nichts geschrieben.

| Meldung | Bedeutung und nächster Schritt |
|---|---|
| `backup_missing`, `backup_failed`, `backup_too_old` | Kein frisches erfolgreiches Backup. Schritt 2 wiederholen, dann Schritt 7. |
| `active_jobs` | Ein Auftrag läuft noch. Warten, bis er endet, dann erneut. |
| `same_key` | Der laufende Container hat noch den alten Schlüssel. `.env` prüfen und Schritt 5 wiederholen. |
| `unreadable` mit Zeilen wie `deploy_credentials:12` | Diese Werte lassen sich mit keinem der beiden Schlüssel lesen. Meist ist die Datei aus Schritt 3 falsch (aus einer anderen `.env`). Ist ein einzelner Wert wirklich verloren, sein Passwort im Portal neu eintragen; danach zählt er als `already`, und der Lauf geht durch. |
| Aufruf mit Exit 2 | STDIN leer, Datei aus Schritt 3 enthält keinen gültigen Schlüssel, oder ein unbekanntes Argument. |

**Zurück vor Schritt 7:** Den alten Wert aus `/root/app_key_alt` wieder als
`APP_KEY` in die `.env` schreiben und Schritt 5 wiederholen. Nach Schritt 7 gibt
es keinen Rückweg ohne Werkzeug: Ein erneuter Wechsel läuft wie oben, mit dem
dann alten Schlüssel aus Schritt 4.

## Grenzen

- Ohne den alten Schlüssel kann das Werkzeug nichts übernehmen. Dann gilt der
  Weg ohne Werkzeug: neuer Schlüssel wie in Schritt 4 und 5, danach jeden Zugang
  bearbeiten und sein Passwort neu speichern, das AD-Suchkonto mit Passwort neu
  speichern, jeweils **Testen**. Wer ein Passwort nicht mehr kennt, ändert es
  zuerst an der Quelle (ESXi, Ansible-Host, AD).
- Der Log-Export trägt im Audit einen Fingerprint des Filters, gebildet mit dem
  `APP_KEY` (`log_filter_fingerprint()`). Fingerprints von vor und nach dem
  Wechsel lassen sich nicht miteinander vergleichen; neu berechnen lassen sie sich
  nicht.
- Der Wechsel betrifft nur das Portal. ESXi, Ansible-Host und AD behalten ihre
  Passwörter.
