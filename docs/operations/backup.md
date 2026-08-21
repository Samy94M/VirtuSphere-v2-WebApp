# Backup und Restore

VirtuSphere schreibt pro Lauf zwei Dateien nach `Docker/backups/` (gitignored):

1. `db-<ts>.sql.gz`: kompletter MySQL-Dump (alle Datenbanken, Routines, Events, Trigger, `--single-transaction`).
2. `config-<ts>.tar.gz`: `.env`, `docker-compose.yml`, `docker-compose.override.yml` (falls vorhanden), nginx-Konfiguration und (falls vorhanden) SSL-Material.

Die Override-Datei ist host-spezifisch und nicht in Git, und genau deshalb liegt sie im Archiv: der Produktionshost braucht sie zum Starten (Subnetz-Pin, damit die Docker-Bridge das SSH nicht abschneidet, geleerte Proxy-Umgebung je Service). Ein Restore ohne sie bringt den Stack nicht hoch, obwohl beide Archive intakt sind.

Nicht enthalten: `Docker/mysql/mysql-data/` (wird aus dem Dump wiederhergestellt), Laufzeit-Logs und die **Rechte der Log-Verzeichnisse**. Letztere sind Hostzustand, den kein Archiv erfasst: nach einem Restore auf einem Linux-Host `chmod 0777 Docker/WebAPI/logs Docker/logs/nginx` setzen, sonst kann der Fehlerhandler nicht schreiben (Begruendung in `docs/operations/go-live.md`, Schritt 1a).

## Backup ausführen

```sh
sh scripts/backup.sh
```

Voraussetzung: der Compose-Stack läuft (`virtusphere-v2-webapp-mysql-1`; abweichender Containername via `VIRTUSPHERE_MYSQL_CONTAINER`). Das Skript validiert den Dump (Mindestgröße, gzip-Integrität) und behält von jeder der beiden Dateien die neuesten Läufe (`KEEP=14` in `scripts/backup.sh`, die SSoT für diesen Wert). Bei täglichem Lauf reicht der Rückgriff damit etwa 14 Tage zurück.

Die Backup-Dateien enthalten Secrets (`.env`, DB-Inhalte inkl. verschlüsselter Credentials). `Docker/backups/` gehört auf ein zugriffsbeschränktes Ziel (`chmod 700`) und sollte zusätzlich auf einen zweiten Host synchronisiert werden (Pull vom Backup-Host, nicht Push vom App-Host).

## Zeitplan einrichten (ADR-0024)

Der Zeitplan lebt an genau einer Stelle: dem Cron-Eintrag auf dem Docker-Host. Ein Befehl schreibt ihn:

```sh
sudo sh scripts/install-backup-schedule.sh                        # täglich 06:00
sudo sh scripts/install-backup-schedule.sh --schedule "30 2 * * *"
sh scripts/install-backup-schedule.sh --show                      # aktuellen Eintrag zeigen
sudo sh scripts/install-backup-schedule.sh --remove
```

Das Ergebnis liegt in `/etc/cron.d/virtusphere-backup` und startet `scripts/backup.sh` im Projektverzeichnis; die Ausgabe geht nach `/var/log/virtusphere-backup.log`.

Kein zweiter Sollwert wird gepflegt: `backup.sh` liest bei jedem Lauf genau diese Datei zurück (inklusive `CRON_TZ`) und meldet den gefundenen Zeitplan in der Statuszeile mit. Das Portal rechnet daraus den nächsten Lauf. Erkennungsreihenfolge im Skript:

1. `VIRTUSPHERE_BACKUP_SCHEDULE` (explizite Vorgabe, überstimmt alles).
2. systemd-Timer (`VIRTUSPHERE_BACKUP_TIMER_UNIT`, Standard `virtusphere-backup.timer`): meldet mit `NextElapseUSecRealtime` den nächsten Lauf exakt.
3. `/etc/cron.d/virtusphere-backup` bzw. `VIRTUSPHERE_BACKUP_CRON_FILE`.
4. `crontab -l` des aufrufenden Benutzers.

Greift nichts davon, bleiben die Felder leer; die Karte kennzeichnet den nächsten Lauf dann ausdrücklich als Schätzung aus dem letzten Lauf.

### Andere Hintergrundaufgaben

Das Backup ist der einzige Cron-Job. Deploy-Worker (alle 5 s) und Wartungs-Worker (alle 15 s, darin die Retention-Aufräumjobs und der ESXi-Inventar-Abruf nach eingestelltem Intervall; der Wartungs-Worker führt keine MECM-Probe mehr aus) sind Dauerläufer im Compose-Stack und brauchen keinen Zeitplan.

## Statuskanal und Portal-Anzeige (ADR-0021)

Jeder Lauf hängt eine JSON-Zeile an `Docker/backups/status/backup-status.jsonl` an: `ts` (Unix-Epoch), `status` (`ok`/`failed`), `db_bytes`, `config_bytes`, `duration_s`, `keep`, `disk_free_pct`, `disk_free_bytes`, `error` sowie den Zeitplan-Kanal aus ADR-0024: `schedule` (Cron-Ausdruck), `schedule_tz`, `schedule_source` (Fundort) und `next_ts` (exakter nächster Lauf, nur bei systemd-Timer). Die Datei wird auf die neuesten 90 Zeilen gekappt und ist reine Metadaten (wird selbst nicht mitgesichert). Zeilen aus der Zeit vor ADR-0024 haben die Zeitplanfelder nicht; der Reader fällt dann auf die Intervall-Schätzung zurück.

Nur dieses Unterverzeichnis wird read-only in den PHP-Container gemountet (`./Docker/backups/status:/var/backups/virtusphere-status:ro`), nie die Dumps oder das Config-Tar (das die `.env` mit dem DB-Root-Passwort enthält). Ein Dump-Download über das Portal gibt es bewusst nicht. Der Missions-Export (JSON) ist ein Transportweg zwischen Umgebungen, der Listen-Export (CSV) nur ein Tabellen-Export für Berichte; beides ist kein Backup und kein Restore-Pfad.

Das Portal liest den Kanal an genau einer Stelle (`lib/backup_status.php`) und zeigt:

- **Backup-Karte** auf der Einstellungen-Seite (`settings.php`, Gate `system.config`): Zustand, Zeitplan samt Fundort, letzter Lauf, Alter, nächster Lauf, Überfälligkeitsgrenze, Größen, freier Speicher, Aufbewahrung. Darunter stehen nur der Einrichtungs-Befehl für den Cron-Zeitplan und ein Link in die Hilfe (Tab "Architektur"), die Ablauf, Ablageort und Restore-Weg erklärt; der frühere aufklappbare Erklärtext war ein Duplikat dieser Hilfe-Abschnitte.
- **Dashboard-Banner** für Admins, sobald der Zustand nicht `ok` ist (fail-soft; ein defekter Reader legt keine Seite lahm).
- **health.php**: informatives Feld `backup` (ändert den HTTP-Code nicht).

Abgeleitete Zustände (Schwere `failed` > `stale` > `disk_low`): `ok`, `failed` (letzter Lauf gescheitert), `stale` (der erwartete Lauf blieb samt Kulanzzeit aus), `disk_low` (freier Speicher unter 10 %), `unknown` (kein lesbarer Status: Skript lief nie oder der Mount fehlt).

Die Überfälligkeitsgrenze ist der *erwartete* Lauf plus `VIRTUSPHERE_BACKUP_GRACE_SECONDS` (2 h), nicht ein festes Alter. Ist ein Zeitplan gemeldet, stammt der erwartete Lauf daraus; sonst aus `VIRTUSPHERE_BACKUP_INTERVAL_SECONDS` (24 h). Ein wöchentliches Backup gilt damit nicht mehr dauerhaft als veraltet.

## Restore-Drill

Ein Backup ist erst dann ein Backup, wenn der Restore bewiesen ist:

```sh
sh scripts/restore_test.sh
```

Der Drill arbeitet vollständig in einer Wegwerf-Umgebung (eigenes Docker-Netz, Wegwerf-MySQL mit dem Stack-Image `mysql:8.4`, Projekt-PHP-Image) und prüft die ganze Kette. Der Wegwerf-Server entsteht dabei wie der Produktionsstack, mit dem App-User aus der archivierten `.env` (`MYSQL_USER`/`MYSQL_PASSWORD`), und **alles ab den Migrationen verbindet als dieser App-User, nie als root**: genau so verbindet die App, und genau diese Fähigkeit hatte der Drill früher nie bewiesen.

1. SHA-256-Manifest beider Archive (`manifest-<ts>.sha256`, schreibt `scripts/backup.sh` bei jedem Lauf)
2. Dateirechte von `.env` und SSL-Schlüsseln im Config-Archiv (auf Windows-Hosts nur Warnung, POSIX-Modi sind dort nicht abbildbar)
3. Import des jüngsten Dumps, danach `FLUSH PRIVILEGES` (wie der Neustart im Ernstfall) und die Arbeitsprobe des App-Users; ein Alt-Archiv (`--all-databases`) wird erkannt und durchläuft den unten dokumentierten Grant-Reparaturschritt
4. Tabellenzahl Dump gegen Restore, Migrationen bis `pending=0`, danach Schema-Fingerprint gegen das frische `struktur.sql`
5. Invarianten und Rowcounts (Benutzer, Migrationstracking, keine verwaisten Interfaces/VMs/Jobs)
6. Credential-Entschlüsselung mit dem `APP_KEY` aus dem gesicherten `.env`, und erwartetes Scheitern mit einem falschen Schlüssel
7. App-Smoke gegen die wiederhergestellten Daten: `health.php`, Portal-Login mit einem Drill-Admin, Machine-API-Ablehnung einer nicht freigegebenen IP (eingefrorene 403-Antwort von `mecm-api.php`)
8. Vollständiges Cleanup per Trap; der laufende Stack wird nie berührt

Kadenz: Release-Lane des kanonischen Runners (`scripts/check.ps1 -Lane Release`, Gate `restore-drill`), nach jedem Schema-Meilenstein und mindestens monatlich.

Die früheren `Docker/scripts/backup.sh` und `Docker/scripts/restore.sh` sind stillgelegt (E5): sie waren ein zweiter, unvalidierter Pfad und brechen jetzt absichtlich mit einem Verweis hierher ab.

## Echter Restore (Desaster-Fall)

1. Stack stoppen: `docker compose down`.
2. Defekte Daten wegräumen: `Docker/mysql/mysql-data/` sichern/leeren.
3. Stack starten und warten, bis MySQL initialisiert ist: `docker compose up -d mysql`.
4. Dump einspielen:
   ```sh
   gunzip -c Docker/backups/db-<ts>.sql.gz | docker exec -i virtusphere-v2-webapp-mysql-1 mysql -uroot -p"$MYSQL_ROOT_PASSWORD"
   ```
4a. **Nur bei einem Alt-Archiv** (Dump aus der Zeit vor dem Formatwechsel, erkennbar an `-- Current Database: mysql` im Dump; neue Dumps enthalten nur die Anwendungsdatenbank): der Import hat die mysql-Grant-Tabellen mitgebracht und ersetzt damit die Konten des Zielservers durch den archivierten Stand. Zwei Folgen, beide mit dem archivierten `.env` behebbar. Erstens kann nach dem nächsten `FLUSH PRIVILEGES`/Neustart das **Root-Passwort** das aus der archivierten `.env` sein statt des aktuellen. Zweitens passt der **App-User** nach einer Passwortrotation nicht mehr zur aktuellen `.env`; dann die Grants aus der wirksamen `.env` neu setzen:
   ```sh
   docker exec -i virtusphere-v2-webapp-mysql-1 mysql -uroot -p"<wirksames Root-Passwort>" <<'SQL'
   CREATE USER IF NOT EXISTS '<DB_USER>'@'%' IDENTIFIED BY '<DB_PASS>';
   ALTER USER '<DB_USER>'@'%' IDENTIFIED BY '<DB_PASS>';
   GRANT ALL PRIVILEGES ON `<DB_NAME>`.* TO '<DB_USER>'@'%';
   FLUSH PRIVILEGES;
   SQL
   ```
   `scripts/restore_test.sh` führt genau diesen Schritt beim Alt-Archiv automatisch vor und beweist, dass er genügt.
5. Konfiguration aus `config-<ts>.tar.gz` zurückspielen, **inklusive `docker-compose.override.yml`, falls das Archiv sie enthält**, danach auf einem Linux-Host `chmod 0777 Docker/WebAPI/logs Docker/logs/nginx` setzen und erst dann `docker compose up -d`. Ohne die Override-Datei startet der Produktionsstack nicht; ohne die Rechte läuft er, kann aber nicht protokollieren (siehe `go-live.md`, Schritt 1a).
6. AD-Restore-Konvergenz ausführen: `docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/directory_restore_converge.php`. Das deaktiviert die Verzeichnisanmeldung und entwertet alte Controllerprüfungen; Details stehen in `docs/operations/active-directory.md`.
7. Verifizieren: `docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --check` und `portal/health.php` prüfen.

## Was deckt das Backup ab, was kommt aus Git

Für wechselndes Adminpersonal: der DB-Dump erfasst automatisch jede neue Tabelle und Spalte, der Config-Tar die Betriebskonfiguration einschließlich der host-spezifischen Override-Datei. Alles andere ist versionierter Code aus Git **mit einer Ausnahme**: die Rechte der Log-Verzeichnisse sind Hostzustand und stehen in keinem Archiv.

| Bereich | Quelle im Restore |
|---|---|
| Missionen, VMs, Interfaces, Disks, Pakete, Deploy-Jobs (inkl. `scheduled_at`/`group_id`), ESXi-Inventar-Cache, VLAN-Katalog, VM-Hotplug-Flags, alle `deploy_settings` (Zeitzone, Intervalle) | DB-Dump (nur die Anwendungsdatenbank; die DB-Konten entstehen aus der `.env` des Zielhosts, nie aus dem Dump) |
| `.env`, `docker-compose.yml` (inkl. Status-Mount), nginx-Konfiguration/SSL | Config-Tar |
| `docker-compose.override.yml` (host-spezifisch, nicht in Git; Produktionshost startet ohne sie nicht) | Config-Tar, falls auf dem Host vorhanden |
| Rechte der Log-Verzeichnisse (`0777`) | **kein Archiv**; nach dem Restore per `chmod` setzen, siehe `go-live.md` Schritt 1a |
| Playbooks, PHP-Code, CSS, Hilfe-Texte, Migrationen | Git |
| ESXi-Inventar-Cache | selbstheilend, der nächste Pull baut ihn neu |
| `backup-status.jsonl` | Metadaten, nicht gesichert |

## Störungsbilder (Backup)

Kurzfassung auch im Hilfe-Panel `stack`; hier die ausführlichen Maßnahmen.

| Symptom im Portal | Ursache | Maßnahme |
|---|---|---|
| Banner/Karte `failed` | Letzter Lauf gescheitert | Cron-Log (`/var/log/virtusphere-backup.log`) und `error`-Feld der Karte lesen; Stack läuft? Platz frei? |
| Banner/Karte `stale` | Erwarteter Lauf blieb samt Kulanzzeit aus | `sh scripts/install-backup-schedule.sh --show`, dann `sh scripts/backup.sh` manuell testen |
| Karte: Zeitplan „Nicht gemeldet" | Kein Cron-Eintrag gefunden (Backup lief von Hand) | `sudo sh scripts/install-backup-schedule.sh` ausführen; danach zeigt die Karte Zeitplan und nächsten Lauf |
| Banner/Karte `disk_low` (<10 %) | Backup-Volume voll | Ältere Läufe/`KEEP` prüfen, Volume vergrößern |
| Karte zeigt `unknown` nach Update | Status-Mount fehlt oder Skript lief nie | `docker-compose.yml`-Mount vorhanden? Container neu erstellt? Einmal `sh scripts/backup.sh` laufen lassen |
| Import bricht ab | Fehlende VLANs, falsche Version, kein JSON | Fehlliste in der Vorschau lesen; VLANs zuerst anlegen; Datei aus passender Version exportieren |
| Vorschau zeigt Befunde, Bestätigen ist grau | Grau machen ausschließlich Befunde in der Datei: fehlende VLANs, global vergebene VM-Namen, VM-Namen doppelt in derselben Datei, ungültige Angaben zur Mission oder zu VMs sowie kaputte Listen oder Einträge. Ein ungültiger oder bereits vergebener **Zielname** gehört ausdrücklich nicht dazu | Befunde in der Exportdatei beheben und neu hochladen. Fehlende Pakete blockieren nicht, sie werden übersprungen |
| Vorschau zeigt einen Namensfehler, Bestätigen bleibt aktiv | Der Zielname ist leer, enthält Leerzeichen, trägt das Vorlagenpräfix oder ist bereits vergeben | Anderen Namen ins Feld der Vorschau eintragen und erneut bestätigen; die Prüfung läuft gegen den gerade eingetippten Namen. Nach einem gescheiterten Bestätigen steht genau dieser Name wieder im Feld |
| Meldung nennt eine Position in der Datei, etwa `interfaces[2].vlan` | Ein bekanntes Feld hat die falsche Form (Liste statt Wert, Wert statt Liste) oder ein Eintrag ist kein Objekt | Genau diese Stelle in der Exportdatei korrigieren. Zusatzfelder und MAC-Adressen sind nie die Ursache, sie werden gar nicht gelesen |
| Datei wird als zu groß gemeldet | Die Datei überschreitet das Anwendungslimit für den Mission-Import | Mission verkleinern oder in mehrere Missionen aufteilen. Grenze steht in der Hilfe und im Uploadhinweis |
| Upload endet ohne Portalmeldung, Browser zeigt einen Server- oder Verbindungsfehler | Der Body lag über `post_max_size` oder über nginx `client_max_body_size`. PHP sieht dann weder Aktion noch CSRF-Token noch Payload, deshalb kann das Portal dafür keine eigene Meldung erzeugen | Datei verkleinern. Die Werte stehen in `Docker/php/conf.d/zz-virtusphere.ini` und `Docker/nginx/default.conf`; sie werden nur gemeinsam mit dem Anwendungslimit geändert |
| „Die Datei wurde nur teilweise übertragen" | Der Upload ist unterwegs abgebrochen (Netz, Browser, Reverse Proxy) | Vorgang wiederholen; keine Serverkonfiguration nötig |
| „Die Vorschau ist abgelaufen" | Der Zwischenstand dieses Links lebt noch, seine Frist ist aber abgelaufen | Datei erneut hochladen |
| „Zu diesem Link gibt es keine Vorschau mehr" | Hinter diesem Link liegt kein Zwischenstand: nach einem erfolgreich bestätigten Import mit Browser-Zurück, bei einem Link aus einer fremden Sitzung oder wenn in derselben Sitzung inzwischen eine neue Datei hochgeladen wurde. Welcher Fall es war, steht nicht fest, deshalb sagt die Meldung es auch nicht. Die zuletzt hochgeladene Vorschau bleibt dabei unberührt | Nur wenn der Import wirklich noch aussteht: Datei erneut hochladen. Nach einem neuen Upload ist der neue Vorschaulink der gültige |
| „Abbrechen" gedrückt, Browser-Zurück zeigt die Vorschau wieder | Erwartetes Verhalten: Abbrechen verlässt nur die Ansicht und verwirft den Zwischenstand nicht sofort | Nichts tun. Der Zwischenstand läuft mit der Vorschaufrist ab oder wird vom nächsten Upload ersetzt |
| Meldung nennt eine Referenz für die Administration | Ein unerwarteter Fehler wurde abgefangen, etwa eine nicht erreichbare Datenbank oder ein Uploadproblem auf dem Server | Referenz im **PHP-Fehlerlog** des Portals suchen (`Docker/WebAPI/logs/php-error.log`, Zeilen mit `[virtusphere:mission-import]`), nicht im Deploy-Joblog. Die Zeile nennt Phase, Referenz und Fehlerklasse; Dateiinhalte, Dateinamen und Sitzungstoken stehen bewusst nicht darin und gehören auch nicht in ein Ticket |
| CSV in Excel einspaltig | Alt-Datei mit Komma statt Semikolon | Aktuellen Export nutzen (Semikolon-Trennung, UTF-8-BOM); Symptom tritt mit dem aktuellen Export nicht auf |
