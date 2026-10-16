# System aktualisieren (Update-Runbook)

Dieses Runbook bringt eine **laufende** VirtuSphere-Installation auf einen neueren
Stand. Es ist für Administratoren geschrieben, die das System nicht täglich
betreuen: Jeder Schritt sagt, was er tut, was danach zu sehen sein muss und wann
anzuhalten ist. Es gilt für jede Installation, ob mit oder ohne Internetzugang,
ob der Code aus Git oder aus einem Archiv kommt.

Nicht hier: die **Erstinstallation**. Dafür gelten
[Installationsanleitung](../INSTALLATION-ANLEITUNG.md) (Host mit Netz),
[Offline-Installation](offline-install.md) (Host ohne Netz) und danach
[Go-Live](go-live.md). Welche Kombinationen aus altem und neuem Stand zulässig
sind und warum, steht in [Upgrade und Recovery](upgrade-recovery.md); dieses
Runbook setzt genau diesen Vertrag in Befehle um.

## Begriffe

| Begriff | Bedeutung |
|---|---|
| Docker-Host | der Linux-Rechner mit Portal, Datenbank und den beiden Workern (Docker Compose) |
| Ansible-Host | der Linux-Rechner, auf dem die Playbooks gegen ESXi laufen; kann derselbe Rechner sein |
| MECM-Server | der Windows-Server mit den vier VirtuSphere-Aufgaben in der Aufgabenplanung |
| Projektordner | das Verzeichnis mit `docker-compose.yml` auf dem Docker-Host. In den Befehlen steht dafür `/opt/VirtuSphere-v2-WebApp`; auf einem anderen Host den eigenen Pfad einsetzen |
| Betriebskonto | das Linux-Konto, unter dem du Docker-Befehle ausführst und dem der Projektordner gehört (nicht `root`) |
| Release | ein ausgelieferter Stand mit Release-Nummer `JJJJ.MM.N` aus genau einem Git-Commit. Die Nummer steht in `Docker/WebAPI/lib/VERSION`, im Portal unter Einstellungen > Version und im Offline-Bundle in `provenance.json`; den Commit nennt `git log -1` oder `provenance.json` |
| Offline-Bundle | das Paket aus `scripts/build-offline-bundle.sh` mit fertigen Images, Quellarchiv, Prüfsummen und `verify.sh` |
| Wartungsfenster | die Zeit, in der Portal und Worker gestoppt sind; Aufträge laufen in dieser Zeit nicht |

## Welcher Weg passt zu meinem Host?

Zwei Fragen entscheiden, welche Varianten der Blöcke unten gelten:

| Frage | Variante | Kennzeichen |
|---|---|---|
| Woher kommt der Code? | **Git mit Remote**: `git fetch` erreicht das Repository | `git remote -v` zeigt eine erreichbare Adresse |
| | **Git ohne Remote**: der Code kommt als `git bundle` | Host ohne Zugang zum Repository; oft ein interner Git-Server dazwischen |
| | **Archiv**: der Code kommt als `source.tar.gz` aus dem Offline-Bundle | im Projektordner gibt es kein `.git` |
| Woher kommen die Images? | **Laden** aus dem Offline-Bundle | Host ohne Internet, kein Bauen möglich |
| | **Bauen** auf dem Host (`docker compose build`) | Host mit Internet, Basis-Images erreichbar |

Auf einem Host **ohne** Internet nie bauen: Die Basis-Images sind per Digest
gepinnt und liegen nicht im Bundle. `Docker/scripts/setup.sh` baut und ist nur
für die Erstinstallation gedacht, nicht für ein Update (es startet die neuen
Worker vor der Migration).

## Grundregeln, die bei jedem Update gelten

1. **Nichts im Projektordner mit `sudo` entpacken oder bearbeiten.** Der
   Projektordner gehört dem Betriebskonto. Wer dort mit `sudo` entpackt, legt
   Dateien als `root` an, und das nächste Update scheitert daran. Ausnahmen sind
   nur `Docker/WebAPI/logs` (Modus 0777), `Docker/mysql/mysql-data` (gehört dem
   MySQL-Container), `Docker/backups` (gehört `root`, weil der Backup-Zeitplan
   als `root` läuft) sowie `Docker/nginx/ssl` und `Docker/nginx/conf.d`, die bei
   eingerichtetem HTTPS uid 33 gehören ([HTTPS](https.md)). Diese Ordner nie dem
   Betriebskonto zurückgeben.
2. **Immer neu erzeugen, nie nur neu starten.** `docker compose up -d --no-build
   --force-recreate --wait` übernimmt Images, `.env` und Compose-Datei.
   `docker compose restart` behält die alten Container, und ein Neustart nur von
   `php` liefert HTTP 502, weil nginx die alte Adresse des PHP-Containers hält.
3. **Vor dem Austausch der Images die laufenden sichern.** Laden wie Bauen hängen
   den Namen eines Images auf das neue Image um. Ohne Sicherung findet der
   Rückweg das alte Image nicht mehr.
4. **Erst Backup und Restore-Drill, dann alles andere.** Migrationen laufen nur
   vorwärts; das geprüfte Backup ist der einzige Rückweg.
5. **Integrationen erst nach Portal und Schema.** MECM-Skripte, Ansible-Host und
   Runner folgen dem Portal, nie umgekehrt.
6. **Bei einer unerwarteten Ausgabe anhalten.** Unter jedem Block steht, was zu
   sehen sein muss. Weicht es ab: nicht weitermachen, nicht improvisieren, erst
   klären.

## Schritt 0: Was ändert dieses Release?

Vor jedem Update klären, was das neue Release mitbringt. Am einfachsten auf einem
Rechner mit dem Git-Repository; `ALT` ist der laufende, `NEU` der Ziel-Commit:

```bash
ALT=<laufender-commit>
NEU=<ziel-commit>
git log --oneline "$ALT..$NEU" | wc -l
git diff --stat "$ALT" "$NEU" -- Docker/php Docker/mysql Docker/nginx Docker/phpmyadmin docker-compose.yml
git diff "$ALT" "$NEU" -- .env.example
git diff --name-status "$ALT" "$NEU" -- Docker/WebAPI/lib/migrations
git diff --stat "$ALT" "$NEU" -- Powershell-MECM Ansible/requirements.yml Ansible/host-cleanup Ansible/runner
```

Was die Ausgaben bedeuten:

| Ausgabe | Folge für das Update |
|---|---|
| Änderungen unter `Docker/php`, `Docker/mysql`, `Docker/nginx` oder an `image:`-Zeilen in `docker-compose.yml` | neue Images nötig (laden oder bauen); ein reines `git pull` reicht nicht |
| neue Schlüssel in `.env.example` | vor dem Start in die `.env` des Hosts übernehmen |
| neue Dateien unter `Docker/WebAPI/lib/migrations` | Wartungsfenster mit Backup und Drill; die Rückkehrklasse jeder Migration steht in [Upgrade und Recovery](upgrade-recovery.md), Abschnitt „Migrationsklassen“. Steht eine neue Migration dort in keiner Klasse, gilt sie als Klasse C |
| Änderungen unter `Powershell-MECM` | Schritt 4 (MECM) beachten; erst prüfen, ob der Installer dieses Stands überhaupt freigegeben ist |
| Änderungen an `Ansible/requirements.yml`, `Ansible/host-cleanup` oder `Ansible/runner` | Schritt 4 (Ansible-Host) |

Das Ergebnis kurz notieren (Commits, neue Images ja oder nein, neue `.env`-Schlüssel,
neue Migrationen mit Klasse, MECM-Teil, einmalige Aufgaben). Die Notiz gehört zur
Releaseidentität aus [Upgrade und Recovery](upgrade-recovery.md#releaseidentität).

## Schritt 1: Bestandsaufnahme (ändert nichts)

Auf dem Docker-Host als Betriebskonto:

```bash
cd /opt/VirtuSphere-v2-WebApp
git log -1 --oneline                  # nur bei Git: der laufende Commit
git status --short                    # nur bei Git: muss leer sein
docker compose ps
docker image ls
docker compose exec -T php php /var/www/html/lib/migrate.php --check
df -h /opt /var/lib/docker
sh scripts/install-backup-schedule.sh --show
find . -path ./.git -prune -o -path ./Docker/mysql/mysql-data -prune -o -path ./Docker/backups -prune -o -path ./Docker/nginx/ssl -prune -o -path ./Docker/nginx/conf.d -prune -o -type d ! -writable -print 2>/dev/null
```

Erwartet:

- `git status --short` ist leer. Stehen dort nur Dateien unter
  `Docker/WebAPI/vendor/`, stammen sie aus einem früher entpackten
  `vendor.tar.gz`; das Verzeichnis ist versioniert und wird in Schritt 3.5 auf den
  Git-Stand zurückgesetzt. Alles andere erst klären.
- Alle Dienste laufen und sind `healthy`; `migrate.php --check` endet mit `check: ok`.
- Freier Platz für die neuen Images (das Bundle nennt die Größe; großzügig das
  Doppelte einplanen).
- `--show` zeigt einen Backup-Zeitplan. Fehlt er, läuft kein automatisches
  Backup: nach dem Update einrichten ([Backup](backup.md), „Zeitplan einrichten“).
- Die `find`-Zeile gibt nichts aus. Nennt sie Ordner, gehören diese nicht dem
  Betriebskonto, und Git oder das Entpacken würden dort scheitern. Nur diese
  Ordner zurückgeben, zum Beispiel
  `sudo chown -R "$(id -un)":"$(id -gn)" Docker/WebAPI/vendor`, nie den ganzen
  Projektordner (die Ausnahmen aus den Grundregeln müssen bleiben).

Außerdem die `docker-compose.override.yml` des Hosts ansehen und sichern, falls
es sie gibt. Sie beschreibt nur diesen Host (Subnetz, Proxy-Variablen,
Healthcheck). Jeder Eintrag darin muss zur neuen `docker-compose.yml` passen; ein
Mount oder Dienst, den das neue Release entfernt hat, gehört dort heraus.

## Schritt 2: Release bereitstellen (kein Ausfall)

### 2.1 Offline-Bundle prüfen (nur bei Bundle)

Das Bundle in ein eigenes Verzeichnis außerhalb des Projektordners legen, zum
Beispiel `/home/<betriebskonto>/release-<commit>`. Das Bundle des **laufenden**
Release nicht löschen: Es ist der Rückweg und der Vergleichsstand für Archiv-Updates.

```bash
cd /pfad/zum/bundle
sh verify.sh
grep '"commit"' provenance.json
```

Erwartet: `verify.sh` endet mit `OK: Bundle vollstaendig offline verifiziert.`,
`provenance.json` nennt den Ziel-Commit. Bricht `verify.sh` ab, ist die
Übertragung unvollständig: neu übertragen.

### 2.2 Code bereitstellen

**Git mit Remote:** jetzt nur holen, noch nicht zusammenführen.

```bash
cd /opt/VirtuSphere-v2-WebApp
git fetch origin
git log --oneline -1 origin/main      # Ziel-Commit
```

**Git ohne Remote:** Auf dem Entwicklungsrechner ein Delta-Bundle erzeugen. Die
Spitze muss ein **Branch** sein, der auf dem Ziel-Commit steht, sonst kann der
Host ihn nicht unter einem Namen holen:

```bash
git checkout main && git pull --ff-only
git bundle create vs.bundle <laufender-commit>..main
git bundle list-heads vs.bundle       # muss refs/heads/main mit dem Ziel-Commit zeigen
```

Das Bundle auf den Host bringen (Datei, nie den Repository-Ordner per `scp -r`
kopieren: ein abgebrochener Ordner sieht vollständig aus). Gibt es einen internen
Git-Server, das Bundle in einem eigenen Klon dorthin schieben; der Projektordner
bleibt dabei unberührt:

```bash
git clone <interner-git-server>/VirtuSphere-v2-WebApp.git /home/<betriebskonto>/vs-transfer
cd /home/<betriebskonto>/vs-transfer
git bundle verify /pfad/zu/vs.bundle
git fetch /pfad/zu/vs.bundle main
git merge --ff-only FETCH_HEAD
git push origin main
```

`git bundle verify` muss in einem Repository laufen. Meldet es „Repository lacks
these prerequisite commits“, steht der Klon nicht auf dem laufenden Commit: abbrechen.
Ohne internen Git-Server holt der Projektordner das Bundle in Schritt 3.5 direkt.

**Archiv:** Das neue Quellarchiv liegt im Bundle (`source.tar.gz`). Für Schritt 3.5
die Dateiliste des alten und des neuen Archivs vergleichen; Dateien, die nur im
alten Release existieren, müssen nach dem Entpacken entfernt werden, sonst läuft
alter Code neben neuem:

```bash
tar -tzf /pfad/zum/alten/bundle/source.tar.gz | sort > /home/<betriebskonto>/release-alt.lst
tar -tzf /pfad/zum/bundle/source.tar.gz | sort > /home/<betriebskonto>/release-neu.lst
comm -23 /home/<betriebskonto>/release-alt.lst /home/<betriebskonto>/release-neu.lst
```

### 2.3 Images bereitstellen

Die Images werden erst im Wartungsfenster getauscht (Schritt 3.3 und 3.4), weil
der Restore-Drill vorher noch mit den laufenden Images arbeitet.

## Schritt 3: Wartungsfenster

### 3.1 Aufträge anhalten

Im Portal unter **Systemstatus > Bereitstellungsdienst** „Nach aktuellem Auftrag
pausieren“ wählen. Offene, laufende, abbrechende und `uncertain` Aufträge
notieren. Laufende Aufträge bis zu ihrem Ende laufen lassen; einen Auftrag mit
unklarem Ausgang nicht abbrechen und nicht neu einreihen. Erst weiter, wenn kein
Auftrag mehr läuft.

### 3.2 Backup und Restore-Drill

Noch mit dem laufenden Stand. Beide Skripte mit `sudo`, weil `Docker/backups`
`root` gehört und Dump und Konfigurationsarchiv nur für `root` lesbar sind:

```bash
cd /opt/VirtuSphere-v2-WebApp
sudo sh scripts/backup.sh
sudo sh scripts/restore_test.sh
```

Erwartet: `backup.sh` endet mit `Backup OK`, der Drill zählt bis zum letzten
Schritt hoch und meldet „Restore-Drill OK“ mit dem Zeitstempel des eben
geschriebenen Backups. **Ohne grünen Drill kein Update.** Den Zeitstempel
notieren; er ist der Rückweg.

### 3.3 Laufende Images sichern

Für jeden laufenden Container wird das Image, auf dem er gerade läuft, unter einem
zusätzlichen Namen gesichert und in einer Liste festgehalten. Der Block nimmt das
Image des Containers, nicht den Namen. Er ist deshalb wiederholbar, auch nach dem
Laden der neuen Images, solange die Container noch nicht neu erzeugt sind (also bis
vor Schritt 3.7); danach würde er die neuen Images als „vorher“ eintragen. `-a`
erfasst auch die in Schritt 3.5 gestoppten Container:

```bash
cd /opt/VirtuSphere-v2-WebApp
ALT=$(git rev-parse --short HEAD 2>/dev/null || echo vorher)
LISTE="$HOME/virtusphere-images-$ALT.txt"
for c in $(docker compose ps -a -q); do
  ref=$(docker inspect --format '{{.Config.Image}}' "$c")
  id=$(docker inspect --format '{{.Image}}' "$c")
  docker tag "$id" "${ref%%:*}:vorher-$ALT"
  echo "$ref $id" >> "$LISTE"
done
sort -u "$LISTE"
echo "Liste: $LISTE"
```

Erwartet: je Image eine Zeile mit Name und `sha256:`-ID, zuletzt der Pfad der
Liste. Diesen Pfad notieren, der Rückweg braucht ihn. `docker image ls` zeigt
zusätzlich die Namen mit `vorher-<commit>`.

### 3.4 Neue Images bereitstellen

**Laden (Bundle):**

```bash
cd /pfad/zum/bundle
for f in images/*.tar.gz; do gunzip -c "$f" | docker load; done
docker image ls
```

Erwartet: je Archiv `Loaded image: …`. Meldet `docker load`, dass ein vorhandener
Name umbenannt wird, ist das der Fall aus Schritt 3.3 und erwartet. Jedes Image
hat danach Namen und Tag; `<none>:<none>` darf nicht erscheinen. **Kein
`docker image prune`**: es entfernt die Rückweg-Images. Das optionale
phpMyAdmin unter `tools/` wird hier nicht geladen (siehe Schritt 4).

**Bauen (Host mit Internet):** Gebaut wird aus dem neuen Quellstand, also nach
Schritt 3.5 und vor Schritt 3.6: `docker compose build --no-cache`.

### 3.5 Worker und Webserver stoppen, Quellen umstellen

Erst die Worker stoppen, dann den Code tauschen. Der Webserver bleibt bis nach
der Migration aus, damit niemand das Portal mit halb neuem Stand benutzt:

```bash
cd /opt/VirtuSphere-v2-WebApp
docker compose stop deploy-worker maintenance-worker webserver
```

Danach je nach Variante:

**Git mit Remote oder über einen internen Git-Server:**

```bash
git checkout -- Docker/WebAPI/vendor  # nur nötig, wenn Schritt 1 dort Änderungen zeigte
git status --short                    # leer
git pull --ff-only
```

**Git ohne Remote, Bundle direkt:**

```bash
git bundle verify /pfad/zu/vs.bundle
git fetch /pfad/zu/vs.bundle main
git merge --ff-only FETCH_HEAD
```

**Archiv:** als Betriebskonto über den Projektordner entpacken, die
Produktionsabhängigkeiten darüberlegen und dann die Dateien entfernen, die nur
das alte Release hatte (Listen aus Schritt 2.2). `.env`,
`docker-compose.override.yml` und die Datenverzeichnisse liegen nicht im Archiv
und bleiben:

```bash
tar -xzf /pfad/zum/bundle/source.tar.gz -C /opt/VirtuSphere-v2-WebApp
tar -xzf /pfad/zum/bundle/deps/vendor.tar.gz -C /opt/VirtuSphere-v2-WebApp/Docker/WebAPI
cd /opt/VirtuSphere-v2-WebApp
comm -23 /home/<betriebskonto>/release-alt.lst /home/<betriebskonto>/release-neu.lst | grep -v '/$' > /home/<betriebskonto>/release-entfernen.lst
cat /home/<betriebskonto>/release-entfernen.lst
xargs -d '\n' -a /home/<betriebskonto>/release-entfernen.lst rm -f --
```

Die Liste vor dem Löschen ansehen: Sie darf nur Produktdateien nennen
(`Docker/WebAPI/…`, `Ansible/…`, `scripts/…`, `docs/…`), nie `.env`, die
Override-Datei oder Datenverzeichnisse; die liegen ohnehin nicht im Archiv.

Bei Git **nicht** `deps/vendor.tar.gz` entpacken: `vendor/` ist versioniert und
kommt mit dem Pull. Das Archiv würde den Git-Stand verändern und das nächste
Update blockieren.

### 3.6 Neuen Stand prüfen

```bash
cd /opt/VirtuSphere-v2-WebApp
git log -1 --oneline                  # nur bei Git: Ziel-Commit
git status --short                    # nur bei Git: leer
docker compose config --quiet; echo "config exit=$?"
grep -o '^[A-Z_]*=' .env.example | sort > /home/<betriebskonto>/env-vorlage.lst
grep -o '^[A-Z_]*=' .env | sort > /home/<betriebskonto>/env-host.lst
comm -23 /home/<betriebskonto>/env-vorlage.lst /home/<betriebskonto>/env-host.lst
```

Erwartet: `config exit=0` ohne jede Warnung. Die letzte Zeile nennt Schlüssel,
die in der Vorlage stehen, aber in der `.env` des Hosts fehlen. Jeden davon
bewusst setzen; eine Warnung „variable is not set“ von `config` ist ein Abbruch,
denn Compose setzt den Wert sonst still leer.

### 3.7 Datenbank und PHP neu erzeugen, dann migrieren

Zuerst nur MySQL und PHP mit den neuen Images. So läuft die Migration mit neuem
Code im neuen Image, und ein Startproblem des neuen MySQL-Images zeigt sich vor
jeder Schemaänderung:

```bash
docker compose up -d --no-build --force-recreate --wait mysql php
docker compose exec -T php php /var/www/html/lib/migrate.php --check
```

Erwartet: beide `Healthy`; `--check` meldet `env ok`, `database ok`,
`data preflight ok` und die offenen Migrationen. Ein roter Daten-Preflight ist ein
Abbruch (siehe „Wenn etwas schiefgeht“, Fall vor der Migration).

Erst jetzt migrieren, **genau einmal**:

```bash
docker compose exec -T php php /var/www/html/lib/migrate.php
docker compose exec -T php php /var/www/html/lib/migrate.php --check
```

Erwartet: jede offene Migration `applied`, danach `pending=0` und `check: ok`.
Bricht die Migration mit einem Fehler ab: **nicht erneut starten**. Dann gilt
[Upgrade und Recovery](upgrade-recovery.md#unterbrechung-und-rückkehr).

### 3.8 Ganzen Stack erzeugen

```bash
docker compose up -d --no-build --force-recreate --wait
docker compose ps
IP=$(sed -n 's/^APP_BIND_IP=//p' .env)
PORT=$(sed -n 's/^WEB_HTTP_PORT=//p' .env)
curl -s -o /dev/null -w 'login %{http_code}' "http://$IP:$PORT/portal/login.php"; echo
curl -s "http://$IP:$PORT/portal/health.php"; echo
```

Erwartet: alle Dienste `healthy` auf den neuen Images, `login 200`, `health.php`
mit `"status":"ok"` und `"db":"ok"`. Adresse und Port kommen aus der `.env`:
Bindet der Webserver an eine LAN-Adresse, antwortet `localhost` nicht
([Go-Live](go-live.md), `APP_BIND_IP`).

### 3.9 Prüfen und freigeben

1. Im Browser anmelden, alte Tabs neu laden (alte Formulare werden sonst abgelehnt).
2. Systemstatus ansehen: keine neuen roten Bereiche. Neue Hinweise lesen; sie
   nennen oft eine einmalige Aufgabe des Release (Schritt 5).
3. Einen lesenden Integrationsweg auslösen: ESXi „Inventarabruf starten“ je
   Zugang, beim Ansible-Zugang „Verbindung und Umgebung prüfen“.
4. **Einstellungen > Version** ansehen: Das Portal nennt die Nummer des neuen
   Release, die Datenbank „passt“. Die MECM-Zeilen passen erst nach Schritt 4, die
   Ansible-Zeile nach dem Test aus Punkt 3.
5. Erst danach unter **Systemstatus > Bereitstellungsdienst** „Fortsetzen“.
6. Ein Backup mit dem neuen Skript: `sudo sh scripts/backup.sh`.

## Schritt 4: Integrationen nachziehen

**Ansible-Host.** Nur wenn Schritt 0 Änderungen unter `Ansible/requirements.yml`,
`Ansible/host-cleanup` oder `Ansible/runner` zeigte:

- neue Python-Pakete und Collections offline aus dem Bundle installieren
  ([Offline-Installation](offline-install.md), Schritt 3, Abschnitt „Auf dem
  Ansible-Host“);
- den Aufräum-Timer neu installieren (der Befehl ist wiederholbar):
  `sudo sh Ansible/host-cleanup/install-secret-cleanup.sh <ansible-konto>`. Das
  `<ansible-konto>` ist der **Benutzername des Ansible-Zugangs** im Portal
  (Zugangsdaten, Spalte Benutzername), nicht das eigene Anmeldekonto;
- der Runner ist kein In-place-Update ([Offline-Installation](offline-install.md), Schritt 4).

**MECM-Server.** Zuerst prüfen, ob der Installer dieses Stands freigegeben ist:

```bash
grep -n "throw 'MC01" Powershell-MECM/install-VirtuSphere-MECM.ps1 Powershell-MECM/install-VirtuSphere-Clients.ps1
```

Jede Ausgabezeile ist eine Sperre des genannten Installers: Er bricht vor jeder
Änderung ab. Dann bleiben die vorhandenen Skripte und Aufgaben unverändert in
Betrieb: Das neue Portal nimmt ihre Meldungen weiter an, neue MECM-Funktionen
bleiben aus, bis ein freigegebenes Release kommt. Die Skripte **nicht** von Hand
auf den MECM-Server kopieren; damit würde genau diese Sperre umgangen.
Ausnahme: Nennt die Zeile des Serverinstallers `-Upgrade -ServerOnly`, ist genau
dieses reine Serverupdate freigegeben ([MECM-Integration](mecm-integration.md),
Abschnitt 4b). Es tauscht Serverskripte und Aufgaben und lässt die Paketvorlage
unverändert. Ist der Installer ganz freigegeben, gilt
[MECM-Integration](mecm-integration.md), Abschnitt „Admin-Runbook“. In beiden
Fällen erst jetzt, nach Portal und Schema.

**phpMyAdmin** (nur wenn genutzt): aus dem entpackten Bundle
`sh tools/install.sh /opt/VirtuSphere-v2-WebApp`. Ohne Pfadangabe sucht das
Skript das Projekt im Bundle-Ordner, also an der Stelle einer Neuinstallation.

## Schritt 5: Einmalige Aufgaben nach dem Update

Manche Releases verlangen einmalig eine Handlung. Sie stehen in den Hinweisen des
Systemstatus, auf der Seite Zugangsdaten und in Schritt 0. Wiederkehrende Beispiele:

| Hinweis | Was zu tun ist |
|---|---|
| Ansible-Zugang „Vorläufig angeheftet, bitte bestätigen“ | Host-Schlüssel am Ansible-Host messen und bestätigen, siehe [Verbindungen und Vertrauensanker](trust-flows.md), Abschnitt „Betrieb“ |
| „Einträge ohne Rolle“ bei den IP-Freigaben | jedem Eintrag seine Rolle geben ([Go-Live](go-live.md), Schritt 4.4) |
| „Backups unverschlüsselt“ | optional ein Zertifikat hinterlegen ([Backup](backup.md), „Verschlüsselung“) |
| Ansible-Zugangstest meldet fehlenden Aufräum-Timer | Timer installieren wie in Schritt 4 |

## Wenn etwas schiefgeht

**Vor der Migration** (bis einschließlich `migrate.php --check` in Schritt 3.7) ist
das Schema unverändert. Zurück auf den alten Stand:

```bash
cd /opt/VirtuSphere-v2-WebApp
LISTE=<pfad-der-liste-aus-schritt-3.3>
git checkout --detach <laufender-commit>          # bei Git
while read ref id; do docker tag "$id" "$ref"; done < "$LISTE"
docker compose up -d --no-build --force-recreate --wait
```

Erwartet: alle Dienste `healthy` auf den alten Image-IDs
(`docker inspect --format '{{.Image}}' $(docker compose ps -q)` gegen die Liste).
Der Projektordner steht danach ohne Branch auf dem alten Commit. Vor dem nächsten
Update zuerst `git checkout main`, sonst lehnt `git pull --ff-only` in Schritt 3.5
ab.

Bei einem Archiv statt `git checkout` das alte `source.tar.gz` (und sein
`deps/vendor.tar.gz`) wie in Schritt 3.5 über den Projektordner entpacken und die
nur im neuen Release vorhandenen Dateien entfernen (`comm -13` statt `comm -23`).
Das gilt, solange kein neuer externer Vorgang begonnen hat.

**Ab dem Start der Migration** führt der Rückweg nur über den Restore des Backups
aus Schritt 3.2, zusammen mit `.env`, Override und dem alten Quellstand. Zuerst
den alten Quellstand und die alten Images wie oben zurückholen, dann den Restore
nach dem [Backup-Runbook](backup.md) **dieses alten Stands** ausführen (Abschnitt
„Echter Restore“); ältere Stände kennen nicht alle Schritte des neuen Runbooks.

| Symptom | Ursache | Maßnahme |
|---|---|---|
| `git pull` oder `git checkout` meldet „Permission denied“ | Ordner im Projekt gehören `root` (früher mit `sudo` entpackt) | Schritt 1, `find`-Zeile; nur die genannten Ordner dem Betriebskonto zurückgeben |
| `git pull` meldet „local changes would be overwritten“ | Dateien unter `Docker/WebAPI/vendor` stammen aus einem entpackten `vendor.tar.gz` | `git checkout -- Docker/WebAPI/vendor`, dann erneut |
| `docker compose config` warnt „variable is not set“ | ein neuer Schlüssel fehlt in `.env` | Schritt 3.6, Schlüssel setzen |
| MySQL startet nach dem Neuerzeugen nicht | Startprüfung der Datenbankpasswörter (zu kurz oder Vorlagenwert) | `docker compose logs mysql`; Passwortwechsel ist ein eigener Vorgang ([Go-Live](go-live.md), Schritt 3a) |
| Portal antwortet mit 502 | nur `php` neu gestartet, nginx hält die alte Adresse | `docker compose up -d --no-build --force-recreate --wait` |
| Drill rot | Backup unvollständig oder Restore-Weg gestört | anhalten; ohne grünen Drill kein Update |

## Nach dem Update festhalten

Für die nächste Person, die dieses System betreut: Release-Nummer und Ziel-Commit, Zeitstempel des
Backups aus Schritt 3.2, Liste der gesicherten Images aus Schritt 3.3, neue
`.env`-Schlüssel, erledigte und offene einmalige Aufgaben sowie den Stand des
MECM-Teils. Die vollständige Liste der Angaben steht in
[Upgrade und Recovery](upgrade-recovery.md#releaseidentität).

## Am Terminal arbeiten

- Befehle Block für Block ausführen und die Ausgabe mit dem „Erwartet“ darunter
  vergleichen, bevor der nächste Block kommt.
- Werte in spitzen Klammern (`<laufender-commit>`) vor dem Ausführen ersetzen,
  samt Klammern; die Shell liest `<` und `>` sonst als Umleitung.
- Ausgaben nicht zurück in das Terminal einfügen; Zeilen wie `[1/9] RUN …` würden
  sonst als Befehle ausgeführt.
- Manche Kopierwege setzen vor `~`, `*` oder `_` einen Backslash. Dann scheitern
  Pfade mit `~` und Muster mit `*`. Volle Pfade wie `/home/<betriebskonto>/…`
  sind robuster als `~`.
