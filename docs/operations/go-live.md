# Go-Live-Runbook: Erstinbetriebnahme

Dieses Dokument führt die **erste** produktive Inbetriebnahme der VirtuSphere-WebApp
auf dem Ubuntu-Host durch. Zielgruppe sind Administratoren ohne tiefes Docker- oder
MECM-Vorwissen. Es ist von oben nach unten abarbeitbar (Abhängigkeitsreihenfolge).

## Schritt 0: Kompromittiertes Alt-Credential rotieren (einmalig, vor allem anderen)

Bis zum 2026-07-13 lag im Repository eine Datei `functions.psm1` mit einem **Klartext-MySQL-Passwort** (Benutzer `testkonto`, fester Host, `SslMode=none`). Die Datei war toter WinForms-Code, von nirgends aufgerufen, und ist gelöscht.

Das Passwort bleibt trotzdem kompromittiert: Die Datei stammt aus dem **Initial-Commit**, ein `git rm` entfernt sie also nicht aus der Historie, und jeder mit Repo-Zugriff kann sie weiterhin auslesen.

- [ ] Existiert das Konto `testkonto` auf dem genannten MySQL-Host noch? Wenn ja: **löschen** oder Passwort rotieren.
- [ ] Prüfen, ob dieses Passwort anderswo wiederverwendet wurde (der Wert steht in der Git-Historie, `git log -p -- functions.psm1`).
- [ ] Erst danach ist die Frage erledigt. Ein Repo-Rewrite (`filter-repo`) ist nur sinnvoll, wenn das Repo den Kunden je verlässt; die Rotation ist in jedem Fall Pflicht.

## Wichtig vorab: „committet" heißt nicht „verifiziert"

Der Anwendungskern (Portal, Deploy-Worker, Ansible-Anbindung) und die
MECM-Integration sind gegen den lokalen Docker-Stack sowie in einer
Erstinbetriebnahme des Stacks auf dem Produktionshost geprüft. Die
PowerShell-Skripte (`Powershell-MECM/`) durchlaufen Parser-Check **und**
Pester-Verhaltenstests (`VirtuSphere.RunReport.Tests.ps1`, Integration-Lane),
wurden aber **nie auf einem echten MECM-Server oder Client ausgeführt**: die
MECM-Cmdlets selbst sind offline nur als Argumentliste prüfbar. Dieses Runbook
ist daher zugleich Inbetriebnahme **und** erste echte Verifikation der
Maschinenkette.

| Ebene | Stand | Erste echte Abnahme in |
|---|---|---|
| PHP-Code und alle Migrationen | lokal und auf dem Prod-Host grün | Schritt 1–2 |
| MECM-Rückkanal, Ergebnisberichte, Statusseite | lokal grün | Schritt 5 |
| Paket-Pipeline (Autoimporter/Sync) | lokal grün | Schritt 6 |
| PowerShell MECM-Server-Skripte | Parser- und Pester-geprüft, nie gegen echtes MECM | Schritt 5 |
| PowerShell Client-Skripte | Parser- und Pester-geprüft, nie auf echtem Client | Schritt 7 |

## Was NICHT aus `git pull` kommt

Fast alles läuft zur Deploy-Zeit auf dem Host. Nur wenige Dinge liegen außerhalb
des Repos und müssen separat bereitstehen:

| Gebraucht | Wann | Herkunft |
|---|---|---|
| `.env`-Secrets (`APP_KEY`, `DB_PASS`, `MYSQL_ROOT_PASSWORD`) | sofort beim Stack-Start (Schritt 1) | am Host erzeugen (gitignored) |
| SSH-/Konsolenzugang zum Ubuntu-Host | sofort | Infrastruktur |
| MECM-Server-IP | Schritt 4 (ins Portal tippen) | bekannt sein |
| IP des Ansible-Hosts | Schritt 4 (ins Portal tippen) | bekannt sein |
| DNS-Eintrag `virtusphere.lan` oder dokumentierter IP-Fallback | erst Schritt 7 (Client-Rollout) | Netz-Admin; befristete IP-Variante im MECM-Admin-Runbook |
| Report-Token (optional) | Schritt 5 | im Portal generiert |

`APP_KEY`, `DB_PASS` und `MYSQL_ROOT_PASSWORD` kommen bewusst nicht per `git pull`
mit; EnvBoot bricht hart ab, wenn sie fehlen oder schwach sind.

## Ansible-Host vorbereiten (vor Schritt 4)

Der Ansible-Host ist der Linux-Host, auf dem `ansible-playbook` gegen ESXi läuft;
das Portal spricht ESXi nie direkt an, sondern immer über diesen Host (Inventar-
Abruf und Deploy). Er kann dieselbe Ubuntu-Maschine wie der Docker-Stack sein.

Nötig ist ein **dediziertes, unprivilegiertes Konto** (kein sudo/root): das Portal
meldet sich per SSH/SFTP an, lädt die Playbooks pro Auftrag nach
`/tmp/virtusphere-job-*`, führt sie aus und räumt wieder auf. Die Playbooks laufen
gegen `localhost` und rufen nur die ESXi-API; lokale Adminrechte braucht das Konto
nicht. Dieses Konto trägst du im Portal als **Ansible-Zugang** ein (Zugangsdaten).

Dieser Zugang und die **API-Basis-URL** unter Einstellungen sind keine
Alternativen: Der Zugang liefert SSH/SFTP zum Ausführungs-Host, die URL dessen
Rückweg zur WebApp; ein Deploy verwendet beides gemeinsam. Ein im Portal
gespeicherter URL-Wert hat Vorrang vor `APP_PUBLIC_BASE_URL` aus der `.env`.
Nach einem Zurücksetzen gilt die `.env` wieder, und fehlt auch sie, starten keine
Deploy-Jobs. Unter **Einstellungen → Bereitstellung** wird ein Portalwert im
URL-Feld mit dem direkt danebenstehenden Knopf gespeichert. Beispiele und
Verbindungstest sind dort aufklappbar; **Auf .env-Fallback zurücksetzen** ist nur
bei einem gespeicherten Portalwert sichtbar und entfernt ihn. Die Übersicht
**Wirksame Deploy-Konfiguration** zeigt den wirksamen Wert samt Quelle; der
Ansible-Zugang selbst wird beim Einreihen des Auftrags ausgewählt.

Auf dem Host installieren und bereitstellen:

| Braucht | Warum |
|---|---|
| SSH-Login für das Konto, Schreibrecht in `~` und `/tmp` | Job-Arbeitsverzeichnis unter `/tmp/virtusphere-job-*` |
| `ansible-playbook` (ansible-core) und `python3` | führt die Playbooks aus |
| Python-Modul `pyvmomi` | vSphere-Anbindung (`pip install pyvmomi`) |
| Python-Modul `requests` | jedes `community.vmware`-Modul importiert es (`pip install requests`); `pyvmomi` bringt es nicht mit, und ohne es scheitert **jeder** Playbook-Aufruf beim Import, bevor er ein Argument liest |
| Collection `community.vmware` | die `vmware_guest`-Module (`ansible-galaxy collection install -r Ansible/requirements.yml`) |
| Ausgehend zu ESXi (Port 443) | die vmware_guest-Aufrufe |
| Ausgehend zurück zum Portal (API-Basis-URL, z. B. Port 8021) | `upload_mac_list.py` meldet die MACs an `db_importMAC.php` |

„Verbindung und Umgebung prüfen" beim Ansible-Zugang prüft genau das (SSH-Login,
`ansible-playbook`, `python3`, `pyvmomi`, `requests`, `community.vmware`) und benennt bei
einem Fehler die fehlende Komponente. Bei gesetzter API-Basis-URL prüft er
zusätzlich den Rückweg: die Portal-Erreichbarkeit vom Host aus und ob die
Host-IP in den Machine-API IP-Freigaben steht; fehlt die Freigabe, warnt das
Ergebnis inklusive der IP, die freizugeben ist (Schritt 4.4). Die eigentlichen vSphere-Rechte liegen im
**separaten ESXi-Zugang** (VM anlegen/schalten/auslesen; eine freie ESXi-Lizenz
erlaubt keine Schreibzugriffe, das meldet der Systemstatus als Warnung).

Der Systemstatus benennt diesen Nachweis als **manuellen Volltest**. Nach dem
Gültigkeitsfenster steht dort „Test veraltet": Das ist kein gemeldeter Fehler,
sondern fehlender aktueller Gesamtnachweis. Ein erfolgreich beendeter
Missionsauftrag steht mit Zeit und direktem Jobprotokoll getrennt daneben und
erneuert den Volltest nicht, weil sein Modus nur einen Teil der Prüfkette
ausgeführt haben kann. Der Volltest lässt sich direkt in derselben Zeile erneut
starten; sein Audit bleibt unter Protokolle → Sicherheit, Kategorie
`credentials`.

---

## Schritt 1: Stack hochfahren

1. Repo auf den Ubuntu-Host holen (Branch `main`). Der Host erreicht GitHub
   nicht; der Code kommt per Bundle über den internen Gitea, siehe „Code auf den
   Host bringen und Releases nachziehen" am Ende dieses Dokuments.
2. `.env` anlegen, und zwar als **Kopie von `.env.example`**, nicht von Hand
   getippt. Jeden Schlüssel der Vorlage stehen lassen: `docker compose` bricht
   beim Parsen ab, wenn einer fehlt, den es ohne Vorgabewert einsetzt.

   | Schlüssel | Was zu tun ist |
   |---|---|
   | `APP_KEY`, `DB_PASS`, `MYSQL_ROOT_PASSWORD` | starke, eigene Werte; EnvBoot bricht bei schwachen hart ab |
   | `APP_BIND_IP` | siehe unten, der am leichtesten übersehene Wert |
   | `WEB_HTTP_PORT` | `8021` bestätigen (die Portadresse des Portals) |
   | `WEB_HTTPS_PORT`, `PMA_PORT` | stehen lassen; ohne sie startet der Stack nicht, auch wenn HTTPS noch aus ist und phpMyAdmin nicht läuft. `PMA_PORT` ist der Port, auf dem phpMyAdmin **auf dem Host** erreichbar ist, nicht der Datenbankport |
   | `DB_NAME`, `DB_USER`, `DB_HOST`, `DB_PORT` | unverändert übernehmen; MySQL legt die Datenbank daraus an |
   | `APP_PUBLIC_BASE_URL` | auf den echten Hostnamen oder die IP setzen, siehe unten |

   **`APP_BIND_IP` ist der Schritt, der am leichtesten übersehen wird.** Der
   Vorlagenwert ist `127.0.0.1`, und damit bindet der Webserver ausschließlich an
   das Loopback-Interface: der Stack ist vollständig gesund, jede hostlokale
   Probe (`curl http://127.0.0.1:8021/portal/health.php`) antwortet, und aus dem
   LAN ist das Portal trotzdem nicht erreichbar. EnvBoot prüft diesen Wert als
   einzigen nicht, weil jeder Wert technisch gültig ist. Für den produktiven
   Betrieb also die LAN-Adresse des Hosts eintragen (oder `0.0.0.0` für alle
   Interfaces) und in Schritt 4 von einem **anderen** Rechner aus prüfen.

   `APP_PUBLIC_BASE_URL` trägt in der Vorlage den
   Beispielhostnamen `virtusphere.lan`. Dieser Name ist nicht dekorativ: der
   Ansible-Host baut daraus seine Rückrufadresse für die MAC-Meldung. Er muss im
   DNS des Deploy-Netzes auflösbar sein, sonst läuft der Deploy sauber durch und
   die MAC-Adressen kommen nie an. Wenn kein DNS-Eintrag existiert, hier die IP
   eintragen.

   Das erste Admin-Konto entsteht in Schritt 4.1, **nach** den Migrationen. Wer
   `SEED_ADMIN_USER` und `SEED_ADMIN_PASSWORD` schon hier in die `.env` schreibt,
   bekommt es von `Docker/scripts/setup.sh` gleich mit angelegt; auf einem
   Produktionshost ist der Weg in Schritt 4.1 der bessere, weil das Passwort dann
   nicht in einer Datei liegen bleibt.

3. Stack starten und **alle fünf** Container prüfen, besonders den in E1b neu
   hinzugekommenen `maintenance-worker`:

   ```bash
   docker compose up -d
   docker compose ps
   # erwartet laufend: php, mysql, webserver, deploy-worker, maintenance-worker
   ```

   Fehlt `maintenance-worker`, ist die Compose-Datei veraltet (`git pull`
   nachziehen).

## Schritt 1a: Stolpersteine auf einem echten Linux-Host

Auf Docker Desktop (Entwicklung) treten diese drei nicht auf, auf einem nackten
Linux-Host schon. Der Fix lebt in einer lokalen `docker-compose.override.yml`
(gitignored, nicht im Repo) plus einem `chmod`; ohne ihn startet der Stack nicht
oder die SSH-Sitzung friert ein. Die Override-Datei ist ab ihrem ersten Bestehen
Teil des Config-Archivs von `scripts/backup.sh`, denn ein Restore ohne sie bringt
genau diesen Host nicht hoch. Der `chmod` ist es **nicht**: Verzeichnisrechte sind
Hostzustand, den kein Archiv trägt, und deshalb steht er in
`docs/operations/backup.md` als Restore-Schritt.

1. **Bind-Mount-Rechte.** `Docker/WebAPI/logs` (PHP als uid 33) und
   `Docker/logs/nginx` müssen für den Container-User schreibbar sein. Gehören sie
   dem SSH-User, crash-loopen Worker und nginx (`Log directory is not writable`,
   `error.log Permission denied`). Vor dem Start `chmod 0777` auf beide.
   Docker Desktop umgeht das über seine VM, ein Linux-Host nicht. Dieselbe
   uid-33-Eigentümerschaft gilt für alles, was der PHP-Container dort anlegt: zum
   Lesen einer solchen Datei vom SSH-User aus braucht es `sudo cat`.
2. **Docker-Default-Subnetz.** `docker compose up` legt ein Netz aus
   `172.17.0.0/16` an. Liegt die IP, über die du per SSH verbunden bist, in
   diesem Bereich, routet der Host die Antwortpakete in die Docker-Bridge und die
   SSH-Sitzung friert ein. In der Override ein unbenutztes Subnetz pinnen
   (`networks.default.ipam.config.subnet`, z. B. `10.89.7.0/24`). Recovery bei
   bereits eingefrorener Sitzung: neu verbinden, `docker compose down` (entfernt
   das Netz), Override anlegen, neu hoch.
3. **Proxy-Variablen im Container.** Injiziert der Docker-Client
   (`~/.docker/config.json`) einen `HTTP_PROXY`, erben ihn alle Container. Der
   nginx-Healthcheck-`wget` schickt seinen Loopback-Aufruf dann durch den Proxy
   (`502`, webserver dauerhaft unhealthy, obwohl das Portal per `curl` mit `302`
   antwortet); und Deploy-Worker → ESXi/MECM/Ansible (LAN!) liefen fälschlich
   über den Proxy. In der Override die vier Proxy-Variablen
   (`HTTP_PROXY`/`HTTPS_PROXY` groß und klein) je Service leeren und den
   webserver-Healthcheck-Test auf `wget -Y off` setzen.

Start-Reihenfolge, die funktioniert: Log-`chmod`, Override anlegen,
`docker compose up -d --force-recreate --wait`, dann Schritt 2.

## Schritt 2: Migrationen gegen die frische Produktions-DB

Dies ist der am höchsten eingeschätzte ungetestete Pfad (`struktur.sql` und
`migrate.php` müssen zur selben Zielform konvergieren).

```bash
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --status
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php        # falls offen
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --check # muss sauber melden
```

Erwartet: `--check` meldet `migrations pending=0` und keine Drift. Die Anzahl
steht hier bewusst nicht: sie wächst mit jeder Migration, und eine Zahl im
Runbook lässt eine korrekte Installation falsch aussehen. Der Befehl ist die
Aussage. (Auf ubuntu-102 am 2026-07-22 einmal produktiv bestätigt.)

## Schritt 3: Backup einrichten (vor dem ersten echten Datenbestand)

Backup/Restore folgt ADR-0017. Voll-Dump (`--all-databases`) sichert die neuen
Tabellen automatisch mit.

```bash
sh scripts/backup.sh            # Erstsicherung, Ablage/Runbook siehe docs/operations/backup.md
```

Restore-Probe (`scripts/restore_test.sh`) mindestens einmal ausführen, bevor
produktive Daten entstehen.

## Schritt 3a: DB-Anwendungskonto rotieren (bei Bedarf)

Eine Passwortrotation braucht ein Wartungsfenster und ein aktuelles Backup.
Das kurze Fenster zwischen DB-Änderung und Container-Neustart liefert
erwartungsgemäß HTTP 503; deshalb die folgenden Schritte ohne Pause ausführen:

1. Interaktiv in MySQL anmelden und das Anwendungskonto ändern:
   `ALTER USER '<DB_USER>'@'%' IDENTIFIED BY '<neues Passwort>';`
2. `DB_PASS` in der produktiven `.env` sofort auf denselben Wert setzen.
3. Die DB-Clients mit der neuen Umgebung neu erzeugen:

   ```bash
   docker compose up -d --force-recreate --no-deps php deploy-worker maintenance-worker
   docker compose restart webserver
   ```

   Der Webserver-Neustart ist erforderlich: nginx löst den Namen des neu
   erzeugten PHP-Containers beim eigenen Start auf; ohne Neustart kann trotz
   gesundem PHP-Container ein HTTP 502 zurückbleiben.
4. Mit `docker compose ps`, `health.php`,
   `docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --check`
   und den Container-Logs prüfen.
5. Beim Rollback Passwort und `.env` in umgekehrter Reihenfolge zurücksetzen
   und anschließend dieselben Container erneut erzeugen bzw. neu starten.

## Schritt 4: Admin & Portal-Grundfunktion

1. **Erstes Admin-Konto anlegen.** Eine frische Datenbank enthält **keinen**
   Benutzer, und das Portal legt von sich aus keinen an: ohne diesen Schritt gibt
   es niemanden, der sich anmelden kann. Es gibt genau zwei Wege, und bei beiden
   setzt du das Passwort selbst.

   ```bash
   docker exec -it virtusphere-v2-webapp-php-1 \
     php /var/www/html/lib/seed.php admin '<starkes Passwort>' admin@example.lan
   ```

   Der zweite Weg: `SEED_ADMIN_USER` und `SEED_ADMIN_PASSWORD` in der `.env`
   setzen (in `.env.example` als Kommentar vorbereitet), dann legt
   `Docker/scripts/setup.sh` das Konto beim Hochfahren mit an; ohne beide Werte
   überspringt es den Schritt mit einer Meldung. Auf einem Produktionshost ist der
   `docker exec`-Weg der bessere, weil das Passwort danach nicht in einer Datei
   liegen bleibt.

   Beide Wege legen **genau ein** Konto an, Rolle `admin`, mit
   `must_change_password=1`: die erste Anmeldung erzwingt ein neues Passwort, das
   gesetzte gilt also nur für diesen einen Login. Existiert schon ein Benutzer,
   meldet `seed.php` „users already exist" und ändert nichts, der Aufruf ist also
   gefahrlos wiederholbar und kann kein bestehendes Passwort überschreiben.
2. **Portal im Browser öffnen:** `http://<APP_BIND_IP oder Hostname>:<WEB_HTTP_PORT>/`,
   mit den Vorlagenwerten also `http://virtusphere.lan:8021/`. Die Wurzel leitet
   auf die Anmeldung um. **Von einem anderen Rechner aus prüfen**, nicht per
   `curl` auf dem Host: eine hostlokale Probe beantwortet nicht die Frage, ob
   `APP_BIND_IP` aus dem LAN erreichbar ist (Schritt 1.2). Bleibt die Verbindung
   aus, während `docker compose ps` alles gesund zeigt und `curl` auf dem Host
   antwortet, ist genau dieser Wert die Ursache.
3. **Portal-Smoke-Test** (rein WebApp, noch ohne MECM) als vollständigen
   Admin-Klickpfad ausführen: Erstanmeldung und Passwortwechsel → Dashboard →
   Portal-Hilfe → Zugangsdaten → Einstellungen/Bereitstellung → Kataloge und
   Inventar → Mission anlegen → VM anlegen → Paket verknüpfen → Deploy-Liste →
   Systemstatus → Logs. Damit sind Navigation, Sessions, CSRF, RBAC und i18n
   einmal produktiv bestätigt. Die kontextsensitive Portal-Hilfe erklärt die
   Bedienung; die Betriebs-Runbooks liegen im Projektordner auf dem Server.
4. **IP-Freigaben füllen** (Einstellungen → IP-Freigaben). Die Maschinen-
   Schnittstelle ist per Allowlist gesperrt und antwortet jeder nicht
   freigegebenen IP mit **403**, ohne dass im Portal etwas kaputt aussieht.
   Eine frische Datenbank kennt nur `127.0.0.1`, eine migrierte Bestands-DB
   unter Umständen gar keinen Eintrag. Eintragen:

   | IP | Wofür | Sonst |
   |---|---|---|
   | MECM-Server | `mecm_packages.php`, `mecm_updateid.php`, `mecm_report.php` | Paket-Sync, MECM-IDs und Heartbeats bleiben aus |
   | Ansible-Host | `db_importMAC.php` (`upload_mac_list.py` meldet die MACs zurück) | Deploy läuft, aber keine MAC kommt an, damit keine MECM-Übergabe |

   Die ausgerollten Client-VMs brauchen **keinen** Eintrag: `mecm-api.php` und
   `mecm_report.php` lassen alternativ eine im Portal bekannte MAC-Adresse als
   Ausweis gelten. Ob der Ansible-Host-Eintrag stimmt, beweist der Zugangstest
   („Verbindung und Umgebung prüfen" beim Ansible-Zugang): fehlt er, endet die Prüfung als Warnung mit der
   IP, die hier einzutragen ist.
5. **HTTP vs. HTTPS** entscheiden: Start ist HTTP-first im LAN; HTTPS läuft
   später über den Admin-Config-Flow (ADR-0012, Runbook
   `docs/operations/https.md`).

## Schritt 5: MECM-Server anbinden (E4, erster echter Funktionstest)

1. **Installer auf dem MECM-Server** ausführen:
   `Powershell-MECM/install-VirtuSphere-MECM.ps1`. Er schreibt die Registry
   `HKLM:\SOFTWARE\VirtuSphere\MECM` (Vererbung aus, Lesezugriff nur SYSTEM und
   Administratoren), legt Ordner an, kopiert die Skripte und registriert die vier
   selbstheilenden Aufgaben (Devices Sync, Packages Sync, Package Import, Site
   Health; alle SYSTEM, AtStartup, `MultipleInstances IgnoreNew`,
   `ExecutionTimeLimit=PT0S`). Provider und Site-Health-Intervall
   (`MECM_ProviderMachine`, `SiteHealthIntervalSeconds`) sind Registry-Werte, keine
   Portal-Einstellungen. Die von oben nach unten ausführbare Anleitung für
   DNS-/IP-Variante, Freigaben, DP-Gruppe, Server- und Client-Installer steht im
   Abschnitt „Admin-Runbook: MECM erstmals anbinden“ in
   `docs/operations/mecm-integration.md`; technische Details stehen zusätzlich in
   `Powershell-MECM/README.md`.
2. **MECM-Server-IP im Portal freischalten**, falls in Schritt 4 nicht schon
   geschehen: Einstellungen → IP-Freigaben. Der Installer meldet 403 mit genau
   diesem Hinweis, falls vergessen.
3. **Optional: Report-Token** im Portal generieren (Einmal-Anzeige). Den Installer
   ohne `-ReportToken` starten, dann fragt er ihn verdeckt ab (nicht als Klartext-
   Argument übergeben). Ohne Token laufen die Skripte ungehindert. Der Token betrifft
   nur die Server-Berichte (`heartbeat`/`reportRun`); die Client-Phasen brauchen ihn
   nicht (Auth per bekannter MAC), er muss also nicht auf die ausgerollten VMs.
   Absicherung: `docs/operations/mecm-integration.md`
   (Abschnitt „Absicherung des ReportToken").
4. **Verifikation Systemstatus** (Portal → Systemstatus): Alle vier Aufgaben laufen
   als SYSTEM und melden. Die drei Sync-Quellen zeigen `started`/`completed` mit
   korrekten Zählern (Untergruppe „VirtuSphere-MECM-Integration"), die Site-Health-
   Aufgabe zeigt den Rohstatus 0/1/2 korrekt (Untergruppe „MECM-Site-Status"). Es
   wird **kein** MECM-Ziel als Prüfziel ins Portal getippt und **kein** grüner
   Netzwerkpfad erwartet: das Portal spricht MECM nicht aktiv an. Gegenproben:
   - Provider-Zugriff verweigern oder falschen Provider setzen: MECM-Site wird grau
     (Providerfehler), **nicht** „MECM kritisch".
   - Einen Sync-Task beenden: nur die Integration wird stale/rot, MECM-Site bleibt
     unberührt.
   - Site-Health-Task beenden: nur MECM-Site wird stale/rot, die Integration bleibt
     grün.

   Das ist die erste echte Abnahme von E1/E1b/E4.

> **Rollout-Reihenfolge (der Zwischenzustand ist erwartet):** Zuerst Portal und
> Migration `0025_mecm_result_reporting` deployen (der Maintenance-Worker wird dabei
> neu erzeugt, damit kein alter Probe-Code weiterläuft), dann den aktualisierten
> Installer ausführen. Zwischen Portal-Deploy und Installer-Lauf akzeptiert das
> Portal noch alte Heartbeats und zeigt die betroffenen Sync-Quellen gelb als
> „Legacy: Ergebnis nicht bestätigt". Erst nach Bestätigung der vier V2-Quellen und
> der getrennten Badges eine eventuell vorhandene Firewallfreigabe
> Portal → MECM:445 entfernen. Nicht „neue Skripte zuerst": ein altes Portal kennt
> `reportRun` nicht und lehnt die Berichte mit HTTP 400 ab.

## Schritt 6: Paket-Pipeline (E3, hier lag das Datenverlust-Risiko)

1. **Autoimporter + Packages-Sync live** beobachten: `config.json`-Ordner
   (`D:\VirtuSphere\Packages\files`) → Katalog füllt sich in `deploy_packages`.
   Wildcard-Fix und `WhetherOrNotUserLoggedOn` werden hier erstmals real getestet.
2. **Schutzschwelle** gegen die reale Kataloggröße prüfen (Default 30 %, Portal →
   Einstellungen → Paket-Sync-Schutzschwelle) und bei Bedarf anpassen.
3. **Retire statt Löschen** einmal bewusst provozieren (Versionswechsel) und
   prüfen, dass VM-Zuweisungen umgehängt statt vernichtet werden.
4. **ACL der Content-Ordner prüfen (Abnahmepunkt, nicht nur Warnung).** Sowohl die
   paketeigene `install.ps1` (Paket-Pipeline) als auch die vier Client-Skripte
   laufen als SYSTEM mit `-ExecutionPolicy Bypass` aus ihrer ContentLocation. Ist
   einer der Content-Ordner für normale Benutzer beschreibbar, ist das
   Codeausführung als SYSTEM auf jedem Client. Betrifft **beide** Bereiche:
   - Paket-Pipeline: `D:\VirtuSphere\Packages\files`
   - Client-Content: `D:\VirtuSphere\Base\Packages`

   Beide Installer *warnen* nur (sie fassen DP-Freigaben nicht automatisch an); die
   Härtung ist ein manueller Go-live-Schritt. Schreibrechte auf Administratoren und
   SYSTEM begrenzen, `Users`/`Authenticated Users`/`Everyone` höchstens auf Lesen,
   und mit `icacls` gegenprüfen, dass keiner dieser drei ein `(W)`/`(M)`/`(F)` hat:

   ```
   icacls "D:\VirtuSphere\Packages\files"
   icacls "D:\VirtuSphere\Base\Packages"
   ```

   Ist `D:\VirtuSphere` eine Freigabe, gilt effektiv die restriktivere der beiden
   ACL-Ebenen (Freigabe *und* NTFS): eine „Everyone: Vollzugriff"-Freigabe ist
   unkritisch, solange NTFS normale Benutzer auf Lesen beschränkt.

## Schritt 7: Client-Deployment (E5, erster echter Funktionstest)

1. **DNS-Eintrag** `virtusphere.lan` → Ubuntu-Host-IP im Deploy-Netz muss jetzt
   existieren (sonst greift nur die hartkodierte IP-Fallback-Stufe).
2. **Eine Test-VM durch alle vier Phasen** ausrollen
   (`getinfo → hostname → staticip → disks`) und die Zeitleiste im VM-Detail des
   Portals mitverfolgen.
3. Dabei die kritischen E5-Fixes real bestätigen: staticip-Idempotenz (Re-Run
   stirbt nicht mehr), ehrlicher Erfolgsstatus, getinfo-Stale-Registry-Bereinigung,
   Set-VMDisksOnline-try/finally.

## Schritt 8: Datenqualität gegen echten Bestand (E2)

Reale VM-/Missionsnamen und MAC-Adressen gegen die neuen Regeln prüfen:
NetBIOS-Hostname ≤15 Zeichen (Bestandsschutz greift), globale VM-Namen-
Eindeutigkeit, MAC-Kanonisierung und Dubletten-Guard. Prüfen, ob Bestandsdaten
Warnungen auslösen, und diese bereinigen.

## Code auf den Host bringen und Releases nachziehen

Der Produktionshost erreicht GitHub nicht (ausgehender Proxy); interne
LAN-Adressen gehen daran vorbei. Der Code läuft deshalb über den internen Gitea
auf demselben Host, und der Sprung von der Entwicklungsmaschine dorthin geht per
`git bundle` auf einem USB-Stick, nie per `scp -r` des Repo-Ordners: ein
abgebrochener Verzeichnisbaum sieht vollständig aus, und Git legt seine Packs
read-only an, sodass ein zweiter Versuch genau an der Objektdatenbank scheitert.

Auf der Entwicklungsmaschine bündeln. `HEAD` muss mit ins Bundle, sonst klont es
ohne Branch und mit leerem Arbeitsbaum:

```bash
git bundle create virtusphere-main.bundle HEAD main
git bundle verify virtusphere-main.bundle
```

Auf dem Host aus dem Bundle nach Gitea spiegeln. `git clone` setzt `origin` auf
die Bundle-Datei, deshalb `set-url` statt `remote add`:

```bash
git clone ~/virtusphere-main.bundle ~/vs-neu
cd ~/vs-neu
git remote set-url origin <interner-gitea>/VirtuSphere-v2-WebApp.git
git push origin main
```

Danach den scharfen Checkout nachziehen. Das ist zugleich der Weg für jedes
weitere Release:

```bash
cd /opt/VirtuSphere-v2-WebApp
git pull --ff-only
docker compose exec -T php php /var/www/html/lib/migrate.php --check  # offene Migrationen?
docker compose exec -T php php /var/www/html/lib/migrate.php          # anwenden, falls offen
docker compose restart php deploy-worker maintenance-worker webserver
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8021/portal/login.php
```

Der Anwendungscode liegt per Bind-Mount im Container
(`./Docker/WebAPI:/var/www/html`) und ist nach dem Pull sofort wirksam; neue
Images braucht es nur bei geändertem Dockerfile oder geänderter Basis-Version.

Der `webserver` gehört zwingend in dieselbe Neustartzeile. Startet nur `php` neu,
hält nginx die alte Container-Adresse fest, und das Portal antwortet trotz
gesundem PHP-Container mit HTTP 502 (dieselbe Ursache wie in Schritt 3a). Ein 502
unmittelbar nach einem Update heißt fast immer, dass dieser Neustart fehlt; der
Smoke-Test oben muss 200 liefern.

Ändert ein Release den Machine-API-Vertrag, gilt die Reihenfolge: erst Portal und
Migration, dann die Skripte auf den Integrationsservern. Ein altes Portal lehnt
neue Aktionen mit HTTP 400 ab, ein neues Portal nimmt alte Meldungen weiter an.

## Offene Entscheidungen & Restrisiken

| Punkt | Status |
|---|---|
| Alt-Credential aus der Git-Historie (Schritt 0) | **muss rotiert werden**, unabhängig vom Löschen der Datei |
| Admin-Passwort (Schritt 4) | Entscheidung des Betreibers |
| PowerShell E4/E5 | Restrisiko deutlich gesunken (ADR-0029: PSScriptAnalyzer + Pester in CI, MAC-Kanonisierung sprachübergreifend gepinnt), aber der erste echte MECM-Lauf bleibt die Bewährungsprobe: Collections, Task Sequences und WMI sind weiter ungetestet |
| Portal auf HTTPS umstellen | Die PS-Skripte können es jetzt (`Scheme`-Registry-Wert, `-Scheme`-Parameter im Installer). Wer HTTP **abschaltet**, muss den Wert auf `https` setzen, sonst steht die MECM-Integration und die PXE-Client-Kette still |
| Frische-DB-Migration (Schritt 2) | zweithöchstes Risiko: Konvergenz nie produktiv gelaufen |
| Deploy-Worker/Ansible gegen echtes ESXi | Teil des ungetesteten Kerns, nicht nur MECM |
| Paket-Schwellwert 30 % | gegen reale Kataloggröße bestätigen (Schritt 6) |

## Verweise

- Betrieb der MECM-Integration im Detail: `docs/operations/mecm-integration.md`
- Backup/Restore: `docs/operations/backup.md`
- MECM-Server-Skripte & Aufgaben: `Powershell-MECM/README.md`
- Client-Anwendungen: `Powershell-MECM/clients/README.md`
