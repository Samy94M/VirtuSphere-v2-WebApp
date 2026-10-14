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

- Der `APP_KEY` ist bekannt geworden oder war schwach. Der Wechsel schützt nur
  Chiffrate, die noch niemand kopiert hat: Er versiegelt dieselben Passwörter neu.
  Können Datenbank, ein Dump oder ein Backup zusammen mit dem alten Schlüssel
  abgeflossen sein, zusätzlich die Passwörter an der Quelle ändern (ESXi,
  Ansible-Host, AD-Suchkonto) und im Portal neu speichern. Ältere Backups tragen
  alten Schlüssel und Chiffrate gemeinsam; sie bleiben bis zum Ende ihrer
  Aufbewahrung Teil des Risikos.
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
  Lauf vor dem Commit ab, bleibt der alte Stand vollständig erhalten.
- **Wiederholbar.** Ein Wert, der sich schon mit dem neuen Schlüssel lesen lässt,
  bleibt unverändert und zählt als `already`. Ein zweiter Lauf ist deshalb
  gefahrlos.
- **Prüfungen vor dem Schreiben.** Das Werkzeug verweigert den Lauf, solange ein
  Auftrag läuft oder abgebrochen wird, wenn alter und neuer Schlüssel gleich sind
  und wenn ein Wert mit keinem der beiden Schlüssel lesbar ist. Wartende und
  geplante Aufträge sperren nicht: Sie lesen das Chiffrat erst beim Start, nach
  dem Wechsel also mit dem neuen Schlüssel. `--apply` verlangt zusätzlich ein
  frisches Backup (siehe Schritt 2).
- **Audit.** Ein Lauf mit `--apply`, der mindestens einen Wert neu verschlüsselt,
  schreibt eine Audit-Zeile „Zugangsdaten mit neuem APP_KEY verschlüsselt“ mit den
  Anzahlen, nie mit Werten. Revisionen der Zugänge und des Verzeichnisses bleiben
  unverändert; ein vor dem Wechsel bestandener Zugangstest bleibt bestanden.

## Ablauf

Die Schritte 3, 6, 7 und 9 laufen in einer Root-Shell (`sudo -i`): Die Umleitungen
`>` und `<` führt die aufrufende Shell aus, ein vorangestelltes `sudo` reicht dafür
nicht.

1. **Dienst pausieren.** Bereitstellungsdienst pausieren und warten, bis kein
   Auftrag mehr läuft. Wartende Aufträge dürfen stehen bleiben; sie starten erst
   nach Schritt 8.
2. **Backup vorher.** `sh scripts/backup.sh` ausführen und das Tripel mit
   `scripts/restore_test.sh` prüfen ([Backup und Restore](backup.md)). Das
   Werkzeug verlangt für `--apply`, dass die letzte Zeile der Backup-Statusdatei
   `ok` meldet und höchstens 2 Stunden alt ist; Schritt 3 bis 7 gehören deshalb in
   ein Wartungsfenster. Bei verschlüsselten Backups läuft der Drill auf dem Rechner
   mit dem privaten Schlüssel; die Zeit dafür gegen die 2 Stunden einplanen. Das
   Config-Archiv dieses Backups enthält die alte `.env`; nur mit ihr lassen sich
   ältere Backups später wieder lesen.
3. **Alten Schlüssel sichern,** mit demselben `.env`-Leser wie Backup und Drill
   (erste Zeile, ohne Anführungszeichen), in eine Datei, die nur root lesen kann:
   ```sh
   ( umask 077; . scripts/lib/env_file.sh; env_file_value .env APP_KEY > /root/app_key_alt )
   ```
   Die Datei enthält danach genau eine Zeile `base64:…` ohne Anführungszeichen.
4. **Neuen Schlüssel eintragen** (32 Zufallsbytes, base64), wie in
   `.env.example` beschrieben:
   ```sh
   sed -i "s#^APP_KEY=.*#APP_KEY=base64:$(openssl rand -base64 32)#" .env
   ```
5. **Alle Prozesse neu erzeugen:** `docker compose up -d --force-recreate --no-deps php deploy-worker maintenance-worker`,
   danach `docker compose restart webserver` (bei der Supervisor-Form zusätzlich mit
   `-f docker-compose.supervisor.yml`). Compose übernimmt die `.env` nur beim
   Erzeugen eines Containers; ein `restart` behält den alten Schlüssel. Ab hier
   bis zum Ende von Schritt 7 gilt das Wartungsfenster:
   - Aufträge, Inventarabrufe, Zugangstests und Anmeldungen über AD scheitern an
     der Entschlüsselung, sicher und ohne Folgeschaden.
   - Keine Zugänge bearbeiten und nichts testen (Zugangstest, Ansible-Volltest,
     AD-Test): Ein Test im Fenster scheitert und bleibt als Fehlschlag gespeichert.
   - Kein Backup zwischen Schritt 4 und 7: Es enthielte die neue `.env` mit
     Chiffraten unter dem alten Schlüssel und taugt nicht als Rückweg. Die Zeit
     des Backup-Timers oder Cron-Eintrags vorher prüfen.
   Prüfen, dass alle drei Container den neuen Schlüssel tragen; die drei Werte
   müssen gleich sein:
   ```sh
   for s in php deploy-worker maintenance-worker; do docker compose exec -T "$s" sh -c 'printf %s "$APP_KEY" | sha256sum'; done
   ```
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
8. **Prüfen und fortsetzen.** Das AD-Suchkonto unter **Benutzer > Active
   Directory** testen und jeden Ansible-Zugang unter **Zugangsdaten** **Testen**.
   Dann den Bereitstellungsdienst fortsetzen; erst danach laufen der ESXi-Test je
   Zugang (er ist ein Inventarabruf) und die wartenden Aufträge.
9. **Backup nachher.** Ein neues Backup erstellen und mit `restore_test.sh`
   prüfen; es enthält die neue `.env`. Erst danach die Datei mit dem alten
   Schlüssel vernichten: `shred -u /root/app_key_alt`. Ältere Backups tragen ihre
   eigene `.env` und bleiben damit lesbar.

## Wenn das Werkzeug ablehnt

Das Werkzeug endet mit 0 bei Erfolg, mit 1 bei Ablehnung oder Fehler und mit 2
bei falschem Aufruf. Eine Ablehnung (`refused …`) hat nichts geschrieben.

| Meldung | Bedeutung und nächster Schritt |
|---|---|
| `backup_missing`, `backup_failed`, `backup_too_old` | Kein frisches erfolgreiches Backup. Schritt 2 wiederholen, dann Schritt 7. |
| `active_jobs` | Ein Auftrag läuft noch oder wird abgebrochen. Warten, bis er endet, dann erneut. |
| `same_key` | Der laufende Container hat noch den alten Schlüssel: `.env` prüfen und Schritt 5 wiederholen. Oder die Datei aus Schritt 3 enthält schon den neuen Schlüssel, weil Schritt 3 erst nach Schritt 4 lief: Den alten Wert dann aus der `.env` im Config-Archiv des Backups aus Schritt 2 holen. |
| `unreadable` mit Zeilen wie `deploy_credentials:12` | Diese Werte lassen sich mit keinem der beiden Schlüssel lesen. Meist ist die Datei aus Schritt 3 falsch (aus einer anderen `.env`). Ist ein einzelner Wert wirklich verloren, sein Passwort im Portal neu eintragen; danach zählt er als `already`, und der Lauf geht durch. |
| `FAIL re-encrypt` ohne `refused` | Ein Fehler, meist der Datenbank. Bricht die Verbindung genau beim Commit ab, ist offen, ob geschrieben wurde: Den Trockenlauf wiederholen. Er zeigt den Stand (`already`), danach bei Bedarf Schritt 7 erneut. |
| Aufruf mit Exit 2 | STDIN leer, Datei aus Schritt 3 enthält keinen gültigen Schlüssel, oder ein unbekanntes Argument. |

**Zurück vor Schritt 7:** Den alten Wert aus `/root/app_key_alt` wieder als
`APP_KEY` in die `.env` schreiben und Schritt 5 wiederholen. Ein Wert, der im
Fenster doch gespeichert wurde, ist danach unlesbar und wird im Portal neu
eingetragen. Nach Schritt 7 gibt es keinen Rückweg ohne Werkzeug: Ein erneuter
Wechsel läuft wie oben, mit dem dann alten Schlüssel aus Schritt 4.

## Grenzen

- Ohne den alten Schlüssel kann das Werkzeug nichts übernehmen. Dann gilt der
  Weg ohne Werkzeug: neuer Schlüssel wie in Schritt 4 und 5, danach jeden Zugang
  bearbeiten und sein Passwort neu speichern, das AD-Suchkonto mit Passwort neu
  speichern, jeweils **Testen**. Wer ein Passwort nicht mehr kennt, ändert es
  zuerst an der Quelle (ESXi, Ansible-Host, AD).
- Der Wechsel macht abgeflossene Kopien nicht wertlos (siehe „Wann“). ESXi,
  Ansible-Host und AD behalten ihre Passwörter, solange sie dort nicht geändert
  werden.
- Der Log-Export trägt im Audit einen Fingerprint des Filters, gebildet mit dem
  `APP_KEY` (`log_filter_fingerprint()`). Fingerprints von vor und nach dem
  Wechsel lassen sich nicht miteinander vergleichen; neu berechnen lassen sie sich
  nicht.
