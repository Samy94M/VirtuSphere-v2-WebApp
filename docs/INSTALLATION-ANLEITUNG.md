# Installationsanleitung

Diese Anleitung beschreibt die Installation und den Betrieb der VirtuSphere PHP-Web-App auf Ubuntu mit Docker. Der Stack startet bewusst über HTTP; HTTPS wird anschließend im Portal aktiviert (Abschnitt „HTTPS“), ohne den grundlegenden Ablauf zu ändern.

## Begriffe kurz erklärt

- Ubuntu: Linux-Serverbetriebssystem, auf dem Docker läuft.
- Docker Engine: Dienst, der Container startet.
- Docker Compose: Werkzeug, das mehrere Container zusammen startet, zum Beispiel nginx, PHP und MySQL.
- Image: Vorlage für einen Container.
- Container: laufender Dienst aus einem Image.
- `.env`: lokale Konfigurationsdatei mit Secrets und Ports. Diese Datei wird nicht in Git committed.
- `APP_KEY`: geheimer Schlüssel für serverseitige Verschlüsselung, zum Beispiel Zugangsdaten.
- Migration: PHP-Skript, das die Datenbankstruktur auf den erwarteten Stand bringt.
- Seed: optionales Anlegen eines ersten Admin-Benutzers.
- ESXi: Hypervisor, auf dem VirtuSphere VMs erzeugt.
- MECM: Microsoft Endpoint Configuration Manager; die Maschinen-API bleibt während der Migration kompatibel.
- Ansible-Host: Linux-Host, auf dem `ansible-playbook` gegen ESXi ausgeführt wird. Er kann mit dem Portal-Host identisch sein.

## Funktionsumfang

Die Installation stellt den gehärteten Portal- und Worker-Stack bereit:

- `/index.php` leitet auf `/portal/login.php`.
- Unsichere Alt- und Test-Endpunkte (`testdata.php`, `db_cleanup.php`, `upgradeMysqlLatest.php`, `mecm_api_old.php`, `portal/createUser.php`, `portal/create_user.php`, `portal/TESTintern.php`) sind aus dem WebAPI-Webroot entfernt.
- nginx blockiert direkte URL-Zugriffe auf interne Pfade wie `lib/`, `vendor/`, `logs/`, `var/`, `tests/` sowie Composer-/Lock-Dateien.
- `migrate.php --check` prüft EnvBoot, Datenbank, Daten-Preflight und ausstehende Migrationen ohne Schemaänderung.
- `/portal/health.php` meldet DB, Log-Schreibbarkeit und Deploy-Worker-Status als JSON.
- VM-Speicherpfade validieren Namen, OS, RAM/CPU, Disks, Interfaces, DNS und MAC serverseitig.
- Credentials validieren Typ, Name, Host/URL, Port, Username, Secret-Pflicht und Duplicate-Namen je Typ.
- Missionen, OS, VLANs und Packages validieren Pflichtfelder, Längen, Dubletten und fehlende IDs backendseitig.
- Deploy-Jobs werden vor dem Einreihen auf Benutzer, Mission, mindestens eine VM und vollständige ESXi-/Ansible-Zugänge geprüft. Datacenter und Datastore werden nur für Modi verlangt, die einen Standort lesen; der Modus `autostart` schreibt allein die Startreihenfolge des Hosts und kommt ohne beide aus.
- Login schützt zusätzlich gegen viele Fehlversuche von derselben IP.

Das Portal ergänzt dazu Sticky-Formulare mit Fehlern pro Feld, Suche und IP-Filter im Log-Viewer sowie die Benutzeridentität in der Topbar. Die funktionale und visuelle Basis ist umgesetzt und wird durch die Portal-Vertragstests abgesichert.

Die Maschinen-API verwendet weiterhin die dokumentierten Legacy-Drahtverträge. Die frühere Desktop-Token-API ist entfernt (ADR-0035); der optionale Report-Token schützt ausschließlich den Rückkanal. Release-Evidenz wird pro Version nach `PRE-SHIP-CHECKLIST.md` erzeugt und nicht als dauerhafte Eigenschaft dieser Anleitung behauptet.

## Voraussetzungen

Auf dem Portal-Host müssen vorhanden sein:

- Docker Engine.
- Docker Compose Plugin (`docker compose ...`).
- `git`, falls der Code direkt aus einem Repository geholt wird.
- `openssl` für lokale Secret-Erzeugung.
- Schreibrechte im Repository für `.env`, `Docker/WebAPI/logs`, `Docker/logs/nginx` und `Docker/mysql/mysql-data`.

Auf dem Ansible-Ausführungs-Host müssen `python3`, `ansible-playbook` aus `ansible-core`, die Python-Module `pyvmomi` und `requests` sowie die Collection `community.vmware` vorhanden sein. Der dedizierte SSH-Benutzer benötigt Schreibrechte in seinem Home-Verzeichnis und unter `/tmp`; ausgehend müssen ESXi auf Port 443 und die konfigurierte Portal-API-Basis-URL erreichbar sein.

Vor dem Air-Gap-Betrieb müssen benötigte Docker-Images, Composer-/Vendor-Inhalte und die Ansible-Toolchain bereits lokal vorhanden sein. Das Setup-Skript installiert keine Betriebssystempakete und lädt zur Laufzeit keine externen Pakete nach. Die lokale QA-Basis mit PHPUnit, Hook-Scan und Lang-Audit ist in `docs/QA.md` dokumentiert; Composer-Updates müssen `composer.lock` und passende `Docker/WebAPI/vendor`-Artefakte zusammenhalten.

## Frischer Checkout

Beispiel mit Git:

```bash
git clone <repository-url> VirtuSphere-v2-WebApp
cd VirtuSphere-v2-WebApp
```

Wenn der Server keinen Internetzugriff hat, kopiere den bereits vorbereiteten Projektordner und die Docker Images offline auf den Server. Danach in den Projektordner wechseln:

```bash
cd VirtuSphere-v2-WebApp
```

## Konfiguration mit `.env`

Schneller Standardweg:

```bash
cp .env.example .env
sed -i "s#^APP_KEY=.*#APP_KEY=base64:$(openssl rand -base64 32)#" .env
sed -i "s#^DB_PASS=.*#DB_PASS=$(openssl rand -base64 32)#" .env
sed -i "s#^MYSQL_ROOT_PASSWORD=.*#MYSQL_ROOT_PASSWORD=$(openssl rand -base64 32)#" .env
```

Optional für den ersten Admin vor dem Setup diese Werte in `.env` setzen:

```bash
SEED_ADMIN_USER=admin
SEED_ADMIN_PASSWORD=<lange-zufaellige-passphrase>
SEED_ADMIN_EMAIL=admin@localhost
```

Ohne diese Werte wird kein Benutzer automatisch angelegt.

## Setup starten

```bash
Docker/scripts/setup.sh
```

Das Skript führt aus:

- `.env` anlegen, wenn sie fehlt, und dabei frische lokale Secrets (`APP_KEY`, `DB_PASS`, `MYSQL_ROOT_PASSWORD`) mit `openssl` erzeugen.
- Eine bereits vorhandene `.env` bleibt unverändert; bei zu schwachen Secrets bricht EnvBoot beim Start mit Klartextmeldung ab (nicht das Setup-Skript).
- Log- und Datenordner anlegen.
- `docker compose config --quiet` ausführen.
- Container bauen und starten.
- `migrate.php --check` ausführen.
- Migrationen anwenden.
- optional ersten Admin seeden.

Erwartung: Das Skript endet ohne Fehler. Wenn ein Secret zu schwach ist, bricht EnvBoot mit einer Klartextmeldung ab.

## Manuelle Prüfungen

```bash
docker compose config --quiet
docker compose ps
docker compose exec -T php php /var/www/html/lib/migrate.php --check
docker compose exec -T php php /var/www/html/lib/migrate.php --status
curl -fsS http://127.0.0.1:8021/portal/health.php
```

Erwartung:

- `docker compose config --quiet` gibt nichts aus und endet mit Exit 0.
- `migrate.php --check` meldet Env, Datenbank, Daten-Preflight und Migrationen als ok.
- `health.php` liefert JSON mit `status: "ok"`, solange Logs und Worker-Zustand gesund sind.

## Erster Admin ohne Auto-Seed

Wenn kein Admin per `.env` angelegt wurde:

```bash
docker compose exec -T php php /var/www/html/lib/seed.php admin '<lange-zufaellige-passphrase>' admin@localhost
```

Danach im Browser öffnen:

```text
http://127.0.0.1:8021/portal/login.php
```

Bei Zugriff von einem anderen Rechner statt `127.0.0.1` die Server-IP verwenden, sofern `APP_BIND_IP` und Firewall das erlauben.

## Betriebsreihenfolge im Portal

1. Anmelden.
2. Unter `Einstellungen -> Bereitstellung` die API-Basis-URL prüfen. Entweder gilt `APP_PUBLIC_BASE_URL` aus der `.env`, oder im Portal wird ein vorrangiger Wert eingetragen und mit dem direkt danebenstehenden Knopf gespeichert. Die Übersicht "Wirksame Deploy-Konfiguration" zeigt Wert und Quelle. Beispiele und der Verbindungstest sind aufklappbar. "Auf .env-Fallback zurücksetzen" erscheint nur bei einem gespeicherten Portalwert und entfernt diesen.
3. Unter Credentials je ein `esxi`- und ein `ansible`-Credential anlegen. Das `ansible`-Credential ist ausschließlich der SSH/SFTP-Zugang zum Ausführungs-Host; die API-Basis-URL ist die separate Rückadresse. Ein Deploy verwendet beides gemeinsam.
4. Beim Ansible-Zugang „Verbindung und Umgebung prüfen" ausführen; geprüft werden SSH-Login, Toolchain (ansible-playbook, python3, pyvmomi, requests, community.vmware), ein SFTP-Schreibtest in /tmp und, bei gesetzter API-Basis-URL, die Portal-Erreichbarkeit. Ein Fehler nennt die betroffene Komponente; Ergebnis und Zeitpunkt bleiben als **manueller Volltest** unter Zugangsdaten und im Abschnitt „Ansible-Host" des Systemstatus sichtbar. Nach dem Gültigkeitsfenster heißt er „Test veraltet" (kein gemeldeter Fehler, aber kein heutiger Gesamtnachweis). Der letzte vom Worker bearbeitete Missionsauftrag wird dort mit Ausgang und Jobprotokoll getrennt angezeigt und erneuert den Volltest nicht; ein vor dem Start abgebrochener Auftrag erscheint dort nicht. Der Volltest kann direkt in dieser Statuszeile wiederholt werden. Nach dem Bearbeiten des Zugangs wird sein Ergebnis verworfen („Nicht getestet"); danach erneut prüfen. Bei mehreren Ansible-Zugängen unter Einstellungen → Kataloge und Inventar den globalen Zugang für ESXi-Inventaraufträge wählen.
5. OS, VLANs und Packages pflegen.
6. Mission anlegen und Datacenter/Datastore/WDS VLAN setzen.
7. Mindestens eine VM in der Mission anlegen.
8. Deploy-Job queuen.

Wenn ein Deploy-Job nicht eingereiht werden kann, kommt der Fehler vor dem Worker zurück: fehlender Benutzer, Template-Mission, keine VMs oder unvollständige Zugänge werden backendseitig blockiert. Ein fehlendes Datacenter oder Datastore blockiert nur die Modi, die einen Standort lesen, nicht `autostart`; Portal und Backend prüfen dabei dieselbe Bedingung.

## Logs und Fehlerreferenzen

Persistente Logs liegen hier:

```text
Docker/WebAPI/logs/error.log
Docker/WebAPI/logs/php-error.log
Docker/WebAPI/logs/fail.log
Docker/logs/nginx/access.log
Docker/logs/nginx/error.log
```

Bei einer Fehlerseite die angezeigte Referenz-ID kopieren und suchen:

```bash
grep '<referenz-id>' Docker/WebAPI/logs/error.log
```

Wenn PHP gar nicht erreicht wird, zuerst nginx prüfen:

```bash
tail -n 100 Docker/logs/nginx/error.log
```

## Wartung

Alle Container laufen mit `restart: unless-stopped`. Nach einem Neustart des Hosts oder einem Absturz starten sie automatisch wieder. Der Deploy-Worker hält zudem eine MySQL-Unterbrechung aus: Er verbindet sich mit Backoff neu, statt abzustürzen und Deploy-Jobs im Status `queued` hängen zu lassen. Ein manueller Eingriff ist nur nötig, wenn ein Container dauerhaft in einem Fehlerzustand bleibt (`docker compose ps` prüfen).

Jeder Dienst hat einen Healthcheck: `docker compose ps` zeigt hinter dem Status `(healthy)`, und ein Start mit `docker compose up -d --wait` kehrt erst zurück, wenn die ganze Kette (MySQL, PHP-FPM, nginx, beide Worker) wirklich Anfragen annimmt. Zeigt ein Container `(unhealthy)`, nennt `docker inspect --format '{{json .State.Health}}' <container>` die letzten Prüfergebnisse.

### phpMyAdmin (optional)

phpMyAdmin ist reines Admin-Werkzeug und startet nicht mehr automatisch mit dem Stack. Bei Bedarf:

```bash
docker compose --profile tools up -d phpmyadmin   # starten (nur 127.0.0.1:PMA_PORT)
docker compose --profile tools rm -sf phpmyadmin  # wieder entfernen
```

Hinweis für Updates von älteren Ständen: Ein früher dauerhaft laufender phpMyAdmin-Container bleibt nach dem Update zurück, weil `docker compose down` Profil-Dienste nicht anfasst. Einmalig mit `docker compose --profile tools rm -sf phpmyadmin` entfernen; danach gilt der On-Demand-Weg oben.

Migrationen erneut prüfen:

```bash
docker compose exec -T php php /var/www/html/lib/migrate.php --check
```

Migrationen anwenden:

```bash
docker compose exec -T php php /var/www/html/lib/migrate.php
```

Container stoppen:

```bash
docker compose stop
```

Container und Netz entfernen, Daten aber behalten:

```bash
docker compose down
```

## Backup und Restore

Kanonisch sind die Skripte unter `scripts/` (die früheren `Docker/scripts/backup.sh` und `Docker/scripts/restore.sh` sind stillgelegt und brechen absichtlich mit einem Hinweis ab):

```bash
sh scripts/backup.sh        # DB-Dump, Config-Archiv und SHA-256-Manifest nach Docker/backups/
sh scripts/restore_test.sh  # Restore-Drill in einer Wegwerf-Umgebung (berührt den Stack nicht)
```

Der Drill verifiziert das Manifest, spielt den jüngsten Dump in einen Wegwerf-MySQL ein, lässt die Migrationen laufen, vergleicht das Schema gegen `struktur.sql`, prüft Invarianten und die Entschlüsselung der Zugangsdaten mit dem gesicherten `APP_KEY` und fährt einen App-Smoke (Health, Portal-Login, Machine API). Der Ernstfall-Ablauf steht in `docs/operations/backup.md` (Disaster Recovery).

Wichtig: `.env` und `APP_KEY` sind für verschlüsselte Zugangsdaten kritisch. Ohne passenden `APP_KEY` können gespeicherte Secrets nicht sinnvoll entschlüsselt werden; genau das beweist der Drill in beide Richtungen.

Jeder Backup-Lauf schreibt zusätzlich eine Statuszeile nach `Docker/backups/status/backup-status.jsonl`. Nur dieses `status/`-Unterverzeichnis wird read-only in den `php`-Container gemountet (`./Docker/backups/status:/var/backups/virtusphere-status:ro`), damit das Portal auf der Einstellungen-Seite eine Backup-Karte und bei Problemen ein Dashboard-Banner anzeigt (ADR-0021). Die Dumps und das Config-Tar (mit `.env`) werden nie gemountet. Nach dem ersten Start ohne Backup zeigt die Karte den Zustand `Unbekannt`, bis `scripts/backup.sh` einmal gelaufen ist.

## HTTPS

HTTPS wird nach dem HTTP-Erststart im Portal konfiguriert (Einstellungen, Tab „HTTPS“: Zertifikats-Upload als PFX oder PEM plus Schalter für Listener, Umleitung und HSTS; ADR-0027). Ohne hochgeladenes Zertifikat und aktivierten Listener bleibt HTTP der wirksame Zustand.

Einmalige Host-Voraussetzungen: `WEB_HTTPS_PORT` in `.env` setzen (Vorlage `.env.example`) und `docker compose up -d` ausführen, damit Portmapping und Shared-Volume-Mounts entstehen. `Docker/nginx/ssl` und `Docker/nginx/conf.d` müssen für uid 33 (`www-data`) schreibbar sein, z. B. per `chown 33:33` auf dem Docker-Host. Ablauf, Erneuerung und Störungsbilder stehen in `docs/operations/https.md`. Die Maschinen-Schnittstelle wird nie umgeleitet; ihre Skripte können HTTPS und wechseln erst mit, wenn sie ausdrücklich umgestellt werden. Ein HTTPS-Zwang für die Maschinen-API bleibt eine eigene Entscheidung (ADR-0019, Kandidat 5).

## Air-Gap Hinweis

Das Setup-Skript setzt voraus, dass Docker, das Compose-Plugin, die benötigten Images und die im Repository vorhandenen Vendor-Dateien bereits verfügbar sind. Es führt keine Paketinstallation aus dem Internet aus. In einer abgeschotteten Umgebung müssen Images und Quellen vorab in die Umgebung gebracht und dort validiert werden.

## Frontend-Wartung

Die funktionale und visuelle Portal-Basis ist umgesetzt. Für spätere Wartung von Optik und Layout sind besonders diese Stellen relevant:

- `Docker/WebAPI/lib/layout.php` (Navigation, Kopfzeile, Theme)
- `Docker/WebAPI/portal/settings.php` und `Docker/WebAPI/portal/assets/css/*` (Settings-Optik, Formularanordnung)

Backend-seitig sind `deploy_settings`, `APP_PUBLIC_BASE_URL` und die vorhandenen RBAC-/CSP-Helfer die relevanten Anschlussstellen. Neue Portal-POSTs müssen weiter CSRF nutzen, Sticky-Werte über `Docker/WebAPI/lib/forms.php` zurückspielen und dürfen keine Inline-Skripte oder Inline-Styles ohne Nonce einführen.
