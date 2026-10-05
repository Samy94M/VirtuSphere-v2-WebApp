# APP_KEY wechseln

Der `APP_KEY` aus der `.env` verschlüsselt die gespeicherten Geheimnisse des
Portals (`lib/crypto.php`, libsodium secretbox). Das sind genau zwei Arten:

- die Passwörter der ESXi- und Ansible-Zugänge (`deploy_credentials.secret_ciphertext`),
- das Passwort des Active-Directory-Suchkontos (`bind_secret_ciphertext`).

Benutzerpasswörter sind gehasht, der Rückkanal-Token ist als SHA-256 gespeichert;
beide hängen nicht am `APP_KEY`.

Ein Werkzeug, das die gespeicherten Geheimnisse mit einem neuen Schlüssel neu
verschlüsselt, gibt es noch nicht (geplant als Paket K22). Bis dahin heißt ein
Wechsel: neuer Schlüssel, danach jedes Geheimnis an der Quelle kennen und im
Portal neu eintragen. Wer die Passwörter nicht mehr kennt, ändert sie zuerst an
der Quelle (ESXi, Ansible-Host, AD).

## Wann

- Der `APP_KEY` ist bekannt geworden oder war schwach.
- Die `.env` ist verloren und kein Backup mit ihr vorhanden: Dann sind die
  gespeicherten Geheimnisse nicht mehr lesbar, und der Wechsel ist der einzige Weg
  zurück.

## Ablauf

1. **Backup vorher.** `sh scripts/backup.sh` ausführen und das Tripel mit
   `scripts/restore_test.sh` prüfen ([Backup und Restore](backup.md)). Das
   Config-Archiv enthält die alte `.env` mit dem alten Schlüssel; nur mit ihr
   lassen sich ältere Backups später wieder lesen. Alte Backups und alter
   Schlüssel bleiben deshalb zusammen aufbewahrt.
2. **Liste der Geheimnisse.** Unter **Zugangsdaten** jeden ESXi- und
   Ansible-Zugang notieren, dazu das Suchkonto unter **Benutzer > Active
   Directory**, falls eingerichtet. Für jeden Eintrag muss das Passwort bekannt
   sein.
3. **Keine Aufträge.** Bereitstellungsdienst pausieren und warten, bis kein
   Auftrag mehr läuft.
4. **Neuen Schlüssel erzeugen** (32 Zufallsbytes, base64), wie in `.env.example`
   beschrieben, etwa `openssl rand -base64 32`, und in der `.env` als
   `APP_KEY=base64:<Wert>` eintragen.
5. **Alle Prozesse neu starten:** `php`, `deploy-worker` und
   `maintenance-worker` (bei der Supervisor-Form zusätzlich mit
   `-f docker-compose.supervisor.yml`). Die Worker lesen die `.env` nur beim Start.
6. **Geheimnisse neu eintragen.** Jeden Zugang bearbeiten und sein Passwort neu
   speichern, danach **Testen**. Das AD-Suchkonto unter **Benutzer > Active
   Directory** mit Passwort neu speichern und testen. Bis dahin scheitern
   Aufträge, Inventarabrufe und Anmeldungen über AD an der Entschlüsselung.
7. **Bereitstellungsdienst fortsetzen** und einen lesenden Weg prüfen, etwa einen
   ESXi-Inventarabruf je Zugang.
8. **Backup nachher.** Ein neues Backup erstellen; es enthält die neue `.env`.

## Grenzen

- Ein Geheimnis, dessen Passwort niemand mehr kennt, lässt sich ohne Werkzeug
  nicht übernehmen; es muss an der Quelle neu gesetzt werden.
- Der Wechsel betrifft nur das Portal. ESXi, Ansible-Host und AD behalten ihre
  Passwörter, solange Schritt 6 dieselben Werte einträgt.
