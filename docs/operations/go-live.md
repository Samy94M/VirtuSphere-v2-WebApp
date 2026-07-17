# Go-Live-Runbook: Erstinbetriebnahme

Dieses Dokument führt die **erste** produktive Inbetriebnahme der VirtuSphere-WebApp
auf dem Ubuntu-Host durch. Zielgruppe sind Administratoren ohne tiefes Docker- oder
SCCM-Vorwissen. Es ist von oben nach unten abarbeitbar (Abhängigkeitsreihenfolge).

## Wichtig vorab: „committet" heißt nicht „verifiziert"

Der gesamte Anwendungskern (Portal, Deploy-Worker, Ansible-Anbindung) und die
MECM-Integration wurden bisher **nur gegen den lokalen Docker-Stack** geprüft. Die
PowerShell-Skripte (`Powershell-MECM/`) wurden ausschließlich vom Parser
syntaktisch abgenommen, **nie auf einem echten SCCM-Server oder Client ausgeführt**.
Dieses Runbook ist daher zugleich Inbetriebnahme **und** erste echte Verifikation.

| Ebene | Stand | Erste echte Abnahme in |
|---|---|---|
| PHP-Code, Migrationen 0001–0010 | lokal grün, nie produktiv | Schritt 1–2 |
| MECM-Rückkanal, Heartbeats, Statusseite | lokal grün | Schritt 4 |
| Paket-Pipeline (Autoimporter/Sync) | lokal grün | Schritt 5 |
| PowerShell MECM-Server-Skripte | nur Parser-Check | Schritt 4 |
| PowerShell Client-Skripte | nur Parser-Check | Schritt 6 |

## Was NICHT aus `git pull` kommt

Fast alles läuft zur Deploy-Zeit auf dem Host. Nur wenige Dinge liegen außerhalb
des Repos und müssen separat bereitstehen:

| Gebraucht | Wann | Herkunft |
|---|---|---|
| `.env`-Secrets (`APP_KEY`, `DB_PASS`, `MYSQL_ROOT_PASSWORD`) | sofort beim Stack-Start (Schritt 1) | am Host erzeugen (gitignored) |
| SSH-/Konsolenzugang zum Ubuntu-Host | sofort | Infrastruktur |
| MECM-Server-IP | Schritt 4 (ins Portal tippen) | bekannt sein |
| IP des Ansible-Hosts | Schritt 4 (ins Portal tippen) | bekannt sein |
| DNS-Eintrag `virtusphere.lan` | erst Schritt 6 (Client-Rollout) | Netz-Admin, parallel möglich |
| Report-Token (optional) | Schritt 4 | im Portal generiert |

`APP_KEY`, `DB_PASS` und `MYSQL_ROOT_PASSWORD` kommen bewusst nicht per `git pull`
mit; EnvBoot bricht hart ab, wenn sie fehlen oder schwach sind.

---

## Schritt 1: Stack hochfahren

1. Repo auf den Ubuntu-Host holen (`git clone`/`git pull`, Branch `main`).
2. `.env` anlegen (Vorlage: `.env.example`). Starke Werte für `APP_KEY`,
   `DB_PASS`, `MYSQL_ROOT_PASSWORD` setzen; `WEB_HTTP_PORT=8021` bestätigen.
3. Stack starten und **alle fünf** Container prüfen, besonders den in E1b neu
   hinzugekommenen `maintenance-worker`:

   ```bash
   docker compose up -d
   docker compose ps
   # erwartet laufend: php, mysql, webserver, deploy-worker, maintenance-worker
   ```

   Fehlt `maintenance-worker`, ist die Compose-Datei veraltet (`git pull`
   nachziehen).

## Schritt 2: Migrationen gegen die frische Produktions-DB

Dies ist der am höchsten eingeschätzte ungetestete Pfad (`struktur.sql` und
`migrate.php` müssen zur selben Zielform konvergieren).

```bash
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --status
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php        # falls offen
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --check # muss sauber melden
```

Erwartet: Migrationen 0001–0010 angewandt, `--check` ohne Drift.

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

1. **Admin-Konto klären.** Es existiert bereits ein Konto `admin`; das
   Erstpasswort liegt in `Docker/WebAPI/logs/initial-admin-password.txt`.
   Entweder dieses Passwort nutzen oder bewusst zurücksetzen (mit
   `must_change_password=1`). Nach erfolgreichem Login die Datei
   `initial-admin-password.txt` **löschen**.
2. **Portal-Smoke-Test** (rein WebApp, noch ohne MECM): Login → Dashboard →
   Mission anlegen → VM anlegen → Paket verknüpfen → Log-Seite öffnen. Damit
   sind Sessions, CSRF, RBAC und i18n einmal produktiv bestätigt.
3. **IP-Freigaben füllen** (Einstellungen → IP-Freigaben). Die Maschinen-
   Schnittstelle ist per Allowlist gesperrt und antwortet jeder nicht
   freigegebenen IP mit **403**, ohne dass im Portal etwas kaputt aussieht.
   Eine frische Datenbank kennt nur `127.0.0.1`, eine migrierte Bestands-DB
   unter Umständen gar keinen Eintrag. Eintragen:

   | IP | Wofür | Sonst |
   |---|---|---|
   | MECM-/SCCM-Server | `mecm_packages.php`, `mecm_updateid.php`, `mecm_report.php` | Paket-Sync, MECM-IDs und Heartbeats bleiben aus |
   | Ansible-Host | `db_importMAC.php` (`upload_mac_list.py` meldet die MACs zurück) | Deploy läuft, aber keine MAC kommt an, damit keine MECM-Übergabe |

   Die ausgerollten Client-VMs brauchen **keinen** Eintrag: `mecm-api.php` und
   `mecm_report.php` lassen alternativ eine im Portal bekannte MAC-Adresse als
   Ausweis gelten.
4. **HTTP vs. HTTPS** entscheiden: Start ist HTTP-first im LAN; HTTPS läuft
   später über den Admin-Config-Flow (ADR-0012, Runbook
   `docs/operations/https.md`).

## Schritt 5: MECM-Server anbinden (E4, erster echter Funktionstest)

1. **Installer auf dem SCCM-Server** ausführen:
   `Powershell-MECM/install-VirtuSphere-MECM.ps1`. Er schreibt die Registry
   `HKLM:\SOFTWARE\VirtuSphere\MECM` (Vererbung aus, Lesezugriff nur SYSTEM und
   Administratoren), legt Ordner an, kopiert die Skripte und registriert die drei
   selbstheilenden Aufgaben (AtStartup + 15-min-Wiederanlauf, `ExecutionTimeLimit=0`).
   Details: `Powershell-MECM/README.md`.
2. **MECM-Server-IP im Portal freischalten**, falls in Schritt 4 nicht schon
   geschehen: Einstellungen → IP-Freigaben. Der Installer meldet 403 mit genau
   diesem Hinweis, falls vergessen.
3. **Optional: Report-Token** im Portal generieren (Einmal-Anzeige). Den Installer
   ohne `-ReportToken` starten, dann fragt er ihn verdeckt ab (nicht als Klartext-
   Argument übergeben). Ohne Token laufen die Skripte ungehindert. Der Token betrifft
   nur die Server-Heartbeats; die Client-Phasen brauchen ihn nicht (Auth per bekannter
   MAC), er muss also nicht auf die ausgerollten VMs. Absicherung:
   `docs/operations/mecm-integration.md` (Abschnitt „Absicherung des ReportToken").
4. **Verifikation Integrationen-Seite** (Portal → Integrationen): die drei
   Sync-Quellen werden grün, die MECM-Erreichbarkeits-Probe grün, der
   Wartungsdienst grün. Das ist die erste echte Abnahme von E1/E1b/E4.

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

---

## Schritt 0: Kompromittiertes Alt-Credential rotieren (einmalig, vor allem anderen)

Bis zum 2026-07-13 lag im Repository eine Datei `functions.psm1` mit einem **Klartext-MySQL-Passwort** (Benutzer `testkonto`, fester Host, `SslMode=none`). Die Datei war toter WinForms-Code, von nirgends aufgerufen, und ist gelöscht.

Das Passwort bleibt trotzdem kompromittiert: die Datei stammt aus dem **Initial-Commit**, ein `git rm` entfernt sie also nicht aus der Historie, und jeder mit Repo-Zugriff kann sie weiterhin auslesen.

- [ ] Existiert das Konto `testkonto` auf dem genannten MySQL-Host noch? Wenn ja: **löschen** oder Passwort rotieren.
- [ ] Prüfen, ob dieses Passwort anderswo wiederverwendet wurde (der Wert steht in der Git-Historie, `git log -p -- functions.psm1`).
- [ ] Erst danach ist die Frage erledigt. Ein Repo-Rewrite (`filter-repo`) ist nur sinnvoll, wenn das Repo den Kunden je verlässt; die Rotation ist in jedem Fall Pflicht.

## Offene Entscheidungen & Restrisiken

| Punkt | Status |
|---|---|
| Alt-Credential aus der Git-Historie (Schritt 0) | **muss rotiert werden**, unabhängig vom Löschen der Datei |
| Admin-Passwort (Schritt 4) | Entscheidung des Betreibers |
| PowerShell E4/E5 | Restrisiko deutlich gesunken (ADR-0029: PSScriptAnalyzer + Pester in CI, MAC-Kanonisierung sprachübergreifend gepinnt), aber der erste echte SCCM-Lauf bleibt die Bewährungsprobe: Collections, Task Sequences und WMI sind weiter ungetestet |
| Portal auf HTTPS umstellen | Die PS-Skripte können es jetzt (`Scheme`-Registry-Wert, `-Scheme`-Parameter im Installer). Wer HTTP **abschaltet**, muss den Wert auf `https` setzen, sonst steht die MECM-Integration und die PXE-Client-Kette still |
| Frische-DB-Migration (Schritt 2) | zweithöchstes Risiko: Konvergenz nie produktiv gelaufen |
| Deploy-Worker/Ansible gegen echtes ESXi | Teil des ungetesteten Kerns, nicht nur MECM |
| Paket-Schwellwert 30 % | gegen reale Kataloggröße bestätigen (Schritt 6) |

## Verweise

- Betrieb der MECM-Integration im Detail: `docs/operations/mecm-integration.md`
- Backup/Restore: `docs/operations/backup.md`
- MECM-Server-Skripte & Aufgaben: `Powershell-MECM/README.md`
- Client-Anwendungen: `Powershell-MECM/clients/README.md`
