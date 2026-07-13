# Installation Anleitung

Diese Anleitung beschreibt den Ubuntu/Docker-Betrieb der VirtuSphere PHP-Web-App. Sie ist fuer Test- und Staging-Umgebungen gedacht und dokumentiert den aktuellen Backend/Ops-Stand. Die spaetere HTTPS-/Produktivhaertung aus WP7 ersetzt nur den HTTPS-Interim, nicht den grundlegenden Ablauf.

## Begriffe kurz erklaert

- Ubuntu: Linux-Serverbetriebssystem, auf dem Docker laeuft.
- Docker Engine: Dienst, der Container startet.
- Docker Compose: Werkzeug, das mehrere Container zusammen startet, zum Beispiel nginx, PHP und MySQL.
- Image: Vorlage fuer einen Container.
- Container: laufender Dienst aus einem Image.
- `.env`: lokale Konfigurationsdatei mit Secrets und Ports. Diese Datei wird nicht in Git committed.
- `APP_KEY`: geheimer Schluessel fuer serverseitige Verschluesselung, zum Beispiel Credentials.
- Migration: PHP-Skript, das die Datenbankstruktur auf den erwarteten Stand bringt.
- Seed: optionales Anlegen eines ersten Admin-Benutzers.
- ESXi: Hypervisor, auf dem VirtuSphere VMs erzeugt.
- MECM: Microsoft Endpoint Configuration Manager; die Maschinen-API bleibt waehrend der Migration kompatibel.
- Ansible Host: separater Ubuntu-Host, auf dem `ansible-playbook` gegen ESXi ausgefuehrt wird.

## Aktueller Backend/Ops-Stand

Der Backend/Ops-Stand vom 2026-07-05 ist fuer lokale Tests nutzbar:

- `/index.php` leitet auf `/portal/login.php`.
- Unsichere Alt- und Test-Endpunkte (`testdata.php`, `db_cleanup.php`, `upgradeMysqlLatest.php`, `mecm_api_old.php`, `portal/createUser.php`, `portal/create_user.php`, `portal/TESTintern.php`) sind aus dem WebAPI-Webroot entfernt.
- nginx blockiert direkte URL-Zugriffe auf interne Pfade wie `lib/`, `vendor/`, `logs/`, `var/`, `tests/` sowie Composer-/Lock-Dateien.
- `migrate.php --check` prueft EnvBoot, Datenbank, Daten-Preflight und ausstehende Migrationen ohne Schema-Aenderung.
- `/portal/health.php` meldet DB, Log-Schreibbarkeit und Deploy-Worker-Status als JSON.
- VM-Speicherpfade validieren Namen, OS, RAM/CPU, Disks, Interfaces, DNS und MAC serverseitig.
- Credentials validieren Typ, Name, Host/URL, Port, Username, Secret-Pflicht und Duplicate-Namen je Typ.
- Missionen, OS, VLANs und Packages validieren Pflichtfelder, Laengen, Dubletten und fehlende IDs backendseitig.
- Deploy-Jobs werden vor dem Queuen auf User, Mission, Datacenter, Datastore, mindestens eine VM und vollstaendige ESXi-/Ansible-Credentials geprueft.
- Login schuetzt zusaetzlich gegen viele Fehlversuche von derselben IP.

Der Portal-UX-Slice ergaenzt dazu Sticky-Formulare mit Fehlern pro Feld (OS, VLANs, Packages, Credentials, Benutzer, Missionen, Settings und VM-Editor), Suche und IP-Filter im Log-Viewer sowie die Benutzeridentitaet in der Topbar als kompakter Konto-Link. Bewusst offen bleibt nur noch das rein visuelle Portal-Design (Settings-Optik, weitere Formularanordnung und Navigationsfeinschliff), das der Frontend-Kollege auf Basis der bestehenden Portal-Struktur weiterfuehren kann.

Abschlusspruefung: Der Backend/Ops-Plan und das Findings-/SSoT-Update sind im aktuellen Arbeitsbaum umgesetzt. Nicht als erledigt gelten nur die bewusst spaeteren Grenzen: WP7/HTTPS-Finalisierung, E3-Retirement der Legacy-Token-API, rein visuelles Frontend-Design und ein frischer Ubuntu-/Clean-Checkout-Probelauf von `Docker/scripts/setup.sh` als Release-Nachweis. Die lokalen Setup-Aequivalente wurden ueber Docker Build/Start, `migrate.php --check`, Migration und Healthcheck verifiziert.

## Voraussetzungen

Auf dem Ubuntu-Server muessen vorhanden sein:

- Docker Engine.
- Docker Compose Plugin (`docker compose ...`).
- `git`, falls der Code direkt aus einem Repository geholt wird.
- `openssl` fuer lokale Secret-Erzeugung.
- Schreibrechte im Repository fuer `.env`, `Docker/WebAPI/logs`, `Docker/logs/nginx` und `Docker/mysql/mysql-data`.

Vor air-gapped Betrieb muessen benoetigte Docker Images und Composer/vendor-Inhalte bereits lokal vorhanden sein. Das Setup-Skript installiert keine Betriebssystempakete und laedt zur Laufzeit keine externen Pakete nach. Die lokale QA-Basis mit PHPUnit, Hook-Scan und Lang-Audit ist in `docs/QA.md` dokumentiert; Composer-Updates muessen `composer.lock` und passende `Docker/WebAPI/vendor`-Artefakte zusammen halten.

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

Optional fuer den ersten Admin vor dem Setup diese Werte in `.env` setzen:

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

Das Skript fuehrt aus:

- `.env` anlegen, wenn sie fehlt, und dabei frische lokale Secrets (`APP_KEY`, `DB_PASS`, `MYSQL_ROOT_PASSWORD`) mit `openssl` erzeugen.
- Eine bereits vorhandene `.env` bleibt unveraendert; zu schwache Secrets darin bricht EnvBoot beim Start mit Klartextmeldung ab (nicht das Setup-Skript).
- Log- und Datenordner anlegen.
- `docker compose config --quiet` ausfuehren.
- Container bauen und starten.
- `migrate.php --check` ausfuehren.
- Migrationen anwenden.
- optional ersten Admin seeden.

Erwartung: Das Skript endet ohne Fehler. Wenn ein Secret zu schwach ist, bricht EnvBoot mit einer Klartextmeldung ab.

## Manuelle Pruefungen

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

Danach im Browser oeffnen:

```text
http://127.0.0.1:8021/portal/login.php
```

Bei Zugriff von einem anderen Rechner statt `127.0.0.1` die Server-IP verwenden, sofern `APP_BIND_IP` und Firewall das erlauben.

## Betriebsreihenfolge im Portal

1. Anmelden.
2. Unter Credentials je ein `esxi`- und ein `ansible`-Credential anlegen.
3. Ansible Credential testen; der Test prueft SSH und die Ansible-/Python-/community.vmware-Basis.
4. OS, VLANs und Packages pflegen.
5. Mission anlegen und Datacenter/Datastore/WDS VLAN setzen.
6. Mindestens eine VM in der Mission anlegen.
7. Deploy-Job queuen.

Wenn ein Deploy-Job nicht queued werden kann, kommt der Fehler jetzt vor dem Worker zurueck: fehlender User, Template-Mission, fehlendes Datacenter/Datastore, keine VMs oder unvollstaendige Credentials werden backendseitig blockiert.

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

Wenn PHP gar nicht erreicht wird, zuerst nginx pruefen:

```bash
tail -n 100 Docker/logs/nginx/error.log
```

## Wartung

Alle Container laufen mit `restart: unless-stopped`. Nach einem Neustart des Hosts oder einem Absturz starten sie automatisch wieder. Der Deploy-Worker haelt zudem eine MySQL-Unterbrechung aus: er verbindet sich mit Backoff neu, statt abzustuerzen und Deploy-Jobs im Status `queued` haengen zu lassen. Ein manueller Eingriff ist nur noetig, wenn ein Container dauerhaft in einem Fehlerzustand bleibt (`docker compose ps` pruefen).

Migrationen erneut pruefen:

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

Die Skripte liegen unter `Docker/scripts/`:

```bash
Docker/scripts/backup.sh
Docker/scripts/restore.sh Docker/backups/<stamp>
```

`restore.sh` erwartet das Backup-Verzeichnis (den Zeitstempel-Ordner unter `Docker/backups/`), nicht eine Archivdatei; darin liegen `db.sql` und optional `.env`/`ssl.tgz`.

Wichtig: `.env` und `APP_KEY` sind fuer verschluesselte Credentials kritisch. Ohne passenden `APP_KEY` koennen gespeicherte Secrets nicht sinnvoll entschluesselt werden.

Jeder Backup-Lauf schreibt zusaetzlich eine Statuszeile nach `Docker/backups/status/backup-status.jsonl`. Nur dieses `status/`-Unterverzeichnis wird read-only in den `php`-Container gemountet (`./Docker/backups/status:/var/backups/virtusphere-status:ro`), damit das Portal auf der Einstellungen-Seite eine Backup-Karte und bei Problemen ein Dashboard-Banner anzeigt (ADR-0021). Die Dumps und das Config-Tar (mit `.env`) werden nie gemountet. Nach dem ersten Start ohne Backup zeigt die Karte den Zustand `Unbekannt`, bis `scripts/backup.sh` einmal gelaufen ist.

## HTTPS

HTTPS wird komplett im Portal konfiguriert (Einstellungen, Tab "HTTPS": Zertifikats-Upload als PFX oder PEM plus drei Schalter fuer Listener, Umleitung und HSTS; ADR-0027). HTTP-first bleibt der Startzustand: ohne hochgeladenes Zertifikat aendert sich nichts.

Einmalige Host-Voraussetzungen: `WEB_HTTPS_PORT` in `.env` setzen (Vorlage `.env.example`) und `docker compose up -d` ausfuehren, damit Portmapping und Shared-Volume-Mounts entstehen. `Docker/nginx/ssl` und `Docker/nginx/conf.d` muessen fuer uid 33 (`www-data`) schreibbar sein, z. B. `chown 33:33` auf dem Docker-Host. Ablauf, Erneuerung und Stoerungsbilder: `docs/operations/https.md`. Die Maschinen-Schnittstelle bleibt bis zur E3-Entscheidung auf HTTP (ADR-0019, Kandidat 5).

## Air-Gap Hinweis

Das Setup-Skript setzt voraus, dass Docker, das Compose-Plugin, die benoetigten Images und die im Repository vorhandenen Vendor-Dateien bereits verfuegbar sind. Es fuehrt keine Paketinstallation aus dem Internet aus. In einer abgeschotteten Umgebung muessen Images und Quellen vorab in die Umgebung gebracht und dort validiert werden.

## Nacharbeiten fuer den Frontend-Handoff

Die funktionale Portal-UX (Sticky-Formulare, Fehler pro Feld, Log-Suche/-Filter, Settings-POST und Account-Identitaet in der Topbar) ist umgesetzt und Teil dieses Standes. Offen bleibt das rein visuelle Design. Der Kollege fuer das Frontend sollte besonders diese Dateien pruefen, wenn Optik und Layout fortgefuehrt werden:

- `Docker/WebAPI/lib/layout.php` (Navigation, Kopfzeile, Theme)
- `Docker/WebAPI/portal/settings.php` und `Docker/WebAPI/portal/assets/css/*` (Settings-Optik, Formularanordnung)

Backend-seitig sind `deploy_settings`, `APP_PUBLIC_BASE_URL` und die vorhandenen RBAC-/CSP-Helfer die relevanten Anschlussstellen. Neue Portal-POSTs muessen weiter CSRF nutzen, Sticky-Werte ueber `Docker/WebAPI/lib/forms.php` zurueckspielen und duerfen keine Inline-Skripte oder Inline-Styles ohne Nonce einfuehren.
