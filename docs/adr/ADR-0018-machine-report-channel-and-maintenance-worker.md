# ADR-0018: Machine-API Rückkanal, Heartbeat & Wartungs-Worker

Date: 2026-07-07
Status: Accepted

## Context

Die MECM-Integration läuft über polling-basierte PowerShell-Skripte (Device-Sync,
Packages-Sync, Autoimporter auf dem SCCM-Server; getinfo/hostname/staticip/disks
auf den ausgerollten Clients). Die WebApp ist heute blind für den realen
Zustand dieser Integration:

- Client-Phasen melden ihren Status nur in die lokale Windows-Registry; der
  Deploy-Fortschritt einer VM ist im Portal nicht sichtbar.
- Stirbt eine Aufgabenplanung auf dem SCCM-Server (Boot-Fehlstart, Absturz,
  manuelles Beenden), fällt das erst auf, wenn Deployments liegen bleiben.
- Es gibt keinen Ort für periodische Wartungsjobs (Retention, aktive
  Erreichbarkeits-Prüfungen); Aufräumarbeiten hängen huckepack an Requests.

Betreiber sind wechselnde Admins ohne SCCM-/Docker-Tiefenwissen; Sichtbarkeit
mit Klartext-Diagnosen ist eine Kernanforderung. Wire-Kontrakte der
bestehenden Machine-Endpoints dürfen nicht verändert werden (ADR-0009,
ADR-0015).

## Decision

1. **Neuer Machine-Endpoint `mecm_report.php`** (eigene Datei nach dem Muster
   von `mecm_updateid.php`, POST-only, JSON):
   - `action=reportPhase`: Clients melden Phasen-Events
     (`getinfo|hostname|staticip|disks` × `started|finished|failed`) per MAC.
     Auth wie `getDeviceInfos` (IP-Allowlist oder MAC-Lookup).
   - `action=heartbeat`: die MECM-Sync-Skripte melden sich je Loop
     (`device-sync|packages-sync|autoimporter`). Auth: IP-Allowlist.
   - Optionaler Shared-Token-Header `X-VirtuSphere-Token`
     (Setting `machine_report_token`; nur geprüft, wenn gesetzt —
     Bestands-Skripte laufen unverändert). Gilt nur für `action=heartbeat`
     (siehe Amendment 2026-07-08); `reportPhase` bleibt MAC-authentifiziert.
2. **Zwei neue Tabellen** statt Überladung von `deploy_vm_status_events`:
   `deploy_client_events` (FK auf `deploy_vms`, ON DELETE CASCADE) und
   `deploy_integration_heartbeats` (eine Upsert-Zeile je Quelle). Wertemengen
   als PHP-Konstanten, keine DB-ENUMs. Zeitstempel = Server-`NOW()`.
3. **Portal**: Statusseite `portal/system_status.php` (alle angemeldeten
   Nutzer, read-only) mit Staleness-Ampel je Quelle; Dashboard-Kachel mit
   Worst-Status; Client-Phasen-Panel in der VM-Detailansicht. Der
   Lifecycle-Seiteneffekt von `getDeviceInfos` bleibt unangetastet;
   `mecm_report.php` ruft nie `repo_set_vm_state` (statisch getestet).
4. **Wartungs-Worker** `lib/maintenance_worker.php` als zweiter Loop-Container
   (`maintenance-worker`, Muster `deploy-worker`): aktive
   TCP-Erreichbarkeits-Probe des MECM-Servers (Ziel automatisch aus dem
   letzten Heartbeat, übersteuerbar per Setting), Retention-Jobs
   (Client-Events, Logs, später Paket-Purge; Log-Aufbewahrungsfenster seit
   2026-07-11 nach Kategorie gestaffelt, siehe ADR-0026), Eigen-Heartbeat.
   DB-Zeilen statt
   Heartbeat-Dateien (bewusste Vereinfachung gegenüber dem Lager-Vorbild).
5. **IP-Allowlist wird im Portal pflegbar** (`deploy_accessToWebAPI`,
   Einstellungen, `system.config`) — Wire-Verhalten unverändert.
6. Neue Log-Kategorie `mecm` für Integrations-Audits; kein Tab-Umbau der
   Log-Seite.

## Consequences

- Deploy-Fortschritt und Integrationsgesundheit sind erstmals im Portal
  sichtbar; „Task tot" vs. „MECM-Server offline" ist per Heartbeat×Probe
  unterscheidbar und wird als Klartext-Handlung angezeigt.
- Bestehende Skripte funktionieren unverändert; neue Skript-Versionen senden
  zusätzlich Heartbeats/Phasen (Etappen 4/5).
- VM-Löschung entfernt die Phasen-Historie mit (CASCADE) — konsistent mit der
  Entscheidung, dass MECM der Single Point of Truth für das Geräte-Lebensende
  ist und die WebApp keine Verwaltungssuite wird.
- Ein separater Watchdog und dateibasierte Heartbeats (Lager-Muster) werden
  bewusst nicht übernommen; Selbstheilung der Scheduled Tasks passiert über
  Task-Scheduler-Trigger (Etappe 4), Sichtbarkeit über diese Heartbeats.
- Out of scope: Fern-Neustart der Tasks aus der WebApp (WinRM), Tombstone-/
  Auto-Cleanup in SCCM, System-Hub mit Disk/TLS/OPcache-Karten.

## Amendment (2026-07-17): SFTP-Upload beschränkt, Cancel-Grenze festgeschrieben

Zwei bewusst offen gelassene AP6-Grenzen werden hier abgeschlossen, damit sie
nicht als stillschweigende Annahmen weiterleben:

- **SFTP-Upload-Timeout.** Der Upload der generierten Deploy-Artefakte
  (`ssh_sftp_upload_directory`) hatte nur einen 15-s-Connect-Timeout, danach
  keinen. Ein nach dem Login stockender Transfer hätte den Worker unbegrenzt
  blockiert, dieselbe Form wie der früher ungebremste Exec-Pfad. Neu: ein
  Per-Operation-Read-Timeout (`VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS`, 120 s) und
  ein Wall-Clock-Deckel über das gesamte Verzeichnis
  (`VIRTUSPHERE_SFTP_TOTAL_TIMEOUT_SECONDS`, 300 s), vor jeder Datei geprüft.
  Beide sind knapp, weil die Dateien wenige KB groß sind.

- **Cancel-Granularität.** Ein Cancel wird ausschließlich an Schrittgrenzen
  geehrt (`deploy_worker_assert_not_cancelled` zwischen Preflight, Upload und
  jedem Playbook), nie mitten in einem laufenden `ansible-playbook`. Das
  Abschießen eines create/powercycle mitten im Lauf hinterließe die ESXi-Seite
  in undefiniertem Zustand (halb geklonte VM, Power-Operation unbekannten
  Ausgangs), über den kein Folgeschritt mehr korrekt entscheiden könnte. Die
  Obergrenze für einen einzelnen hängenden Schritt ist der Transport-Timeout
  (Idle/Total); Cancel ist kooperativ an den Nähten. Stirbt der Worker nach dem
  Cancel, bevor sein Catch die VMs markiert, räumt der Konvergenz-Sweep (L4)
  die in `deploying` zurückgebliebenen VMs auf.

## Amendment (2026-07-08)

Der Token-Header wird nur noch für `action=heartbeat` erzwungen. `action=reportPhase`
authentifiziert ausschließlich über IP-Allowlist bzw. bekannte MAC (wie oben unter
Punkt 1 für reportPhase ohnehin vorgesehen) und verlangt nie einen Token.

Grund: Der Rückkanal-Token muss so nur einmalig auf dem langlebigen SCCM-Server
provisioniert werden (Installer). Die ausgerollten Clients melden ihre Phasen
einmalig während OSD und sind flüchtig; ein Token auf ihnen hätte reinen
Anzeige-Datenverkehr geschützt, aber echten Provisionierungsaufwand erzeugt (ohne
Provisionierung hätten die Client-Phasen bei gesetztem Token 401 bekommen). Die
Wire-Form der Endpoints bleibt unverändert; es ändert sich nur, wann ein `401`
zurückkommt. Der Client-Token-Code (`Get-VsReportToken`, `X-VirtuSphere-Token`) wurde
aus den Phasen-Skripten entfernt.

## Amendment (2026-07-23): Systemstatus und explizites MECM-Prüfziel

`portal/system_status.php` bleibt aus Kompatibilitätsgründen die URL, heißt in
Navigation, Überschrift und Hilfe aber **Systemstatus**. Die Aussage „read-only"
in Entscheidung 3 beschreibt den Reportkanal und dessen VM-Lebenszyklus-Grenze,
nicht jede Bedienmöglichkeit der Seite: berechtigte Benutzer dürfen dort sichere
Diagnose- oder Reparaturaktionen wie eine einmalige TCP-Prüfung, einen
ESXi-Inventarauftrag oder eine bestätigte VLAN-Neuzuweisung auslösen.
`mecm_report.php` bleibt trotzdem strikt display-only und ruft weiterhin nie
`repo_set_vm_state()` auf.

Die bisher implizite Eingabe „leerer MECM-Host = automatisch" wird im Formular
durch `probe_mode=auto|manual` sichtbar gemacht. Aus Kompatibilitätsgründen bleibt
die Speicherung unverändert: leer bedeutet automatisch, ein Host manuell. Der
Automatikmodus verwendet die Absender-IP des letzten Device-Sync-Heartbeats; der
manuelle Modus verlangt DNS, IPv4 oder IPv6. Ein gemeinsamer Probe-Helfer ist die
SSoT für Ziel, Validierung, IPv6-Socket-URI, Timeout und Fehlerkategorien. Das
Ergebnis liegt versioniert und redigiert in `last_detail`; Migration
`0024_mecm_probe_detail_context` erweitert dafür nur die vorhandene Spalte.

Der Systemstatus trennt MECM-Synchronisation und den Netzwerkpfad. Der
Maintenance-Worker ist ein interner Dienst und beeinflusst den MECM-Gesamtzustand
nicht mehr. Dashboard und Systemstatus lesen denselben request-synchronen
Health-Snapshot. Keine Machine-API- oder PowerShell-Wire-Form ändert sich.

## Amendment (2026-07-23): TCP-445-Probe abgelöst durch Ergebnisberichte und Site-Health

Dieses Amendment löst ausdrücklich die TCP-Probe-Hälfte von **Entscheidung 4**
sowie die Probe-, „Netzwerkpfad"- und `probe_mode`-Teile des unmittelbar
vorstehenden Amendments (2026-07-23, „Systemstatus und explizites MECM-Prüfziel")
ab. Die übrigen Aussagen beider bleiben gültig: Der Reportkanal ist strikt
display-only und ruft nie `repo_set_vm_state()`, der Maintenance-Worker ist ein
interner Dienst ohne Einfluss auf den MECM-Gesamtzustand, `system_status.php`
heißt in der Bedienung Systemstatus, und berechtigte Diagnose-/Reparaturaktionen
(ESXi-Inventarauftrag, VLAN-Neuzuweisung) bleiben erlaubt.

**Warum.** Eine erfolgreiche TCP-Verbindung auf Port 445 (direktes SMB über TCP)
beweist weder MECM-Anmeldung noch SMS Provider, WMI, Aufgabenplanung, Katalog-Sync
oder Autoimporter; Port 445 allein ist kein MECM-Gesundheitsnachweis. Der
offizielle zusammengefasste Site-Zustand steht stattdessen über den SMS Provider
in `SMS_SummarizerSiteStatus.Status` bereit und entspricht Microsofts eigenem
Beispielcode.

**Was sich ändert.**

- Die aktive TCP-445-Probe entfällt vollständig; das Portal baut keine ausgehende
  Verbindung mehr zum MECM-Server auf. Es gibt kein MECM-Ziel, keinen Port und
  keinen `probe_mode` mehr in den Einstellungen, und keine Firewallfreigabe
  Portal → MECM:445 wird mehr benötigt.
- `mecm_report.php` erhält additiv `action=reportRun`; `action=heartbeat` und
  `action=reportPhase` bleiben unverändert. Die drei Sync-Aufgaben melden je Lauf
  `started`/`completed` mit einem Ergebnis (`ok`/`warning`/`fail`/`unknown`). Eine
  vierte Aufgabe „VirtuSphere MECM Site Health" meldet ausschließlich `completed`
  mit dem offiziellen Site-Zustand aus `SMS_SummarizerSiteStatus`: 0 = OK (grün),
  1 = Warnung (gelb), 2 = kritisch (rot), jeder andere Rohwert = unbekannt (grau).
- Migration `0025_mecm_result_reporting` erweitert `deploy_integration_heartbeats`
  additiv (u. a. `last_event`, `last_run_id`, `failure_streak`, `last_summary`);
  es gibt **keinen Backfill**. Bestandszeilen behalten `report_version=1`, ihre
  Alt-Zeitstempel heißen „zuletzt gesehen", nie „letzter Erfolg". Die alten
  Probe-Settings und die historische `mecm-server-probe`-Zeile werden für ein
  Rollback nicht gelöscht, aber von keinem aktuellen Leser mehr verwendet.

**Semantik (festgeschrieben).** `last_event` ist der alleinige Treiber der
Anzeige (Legacy vs. V2, laufend vs. abgeschlossen), nicht `report_version`. Ein
frischer V1-Heartbeat ist gelb „Legacy: Ergebnis nicht bestätigt", auch nach einem
Skript-Rollback. Der Client sendet sequenziell ohne Replay-Queue, deshalb ist die
Ankunftsreihenfolge die Wahrheit; jedes `completed` mit neuer `run_id` wird
übernommen, abgelehnt wird nur wegen Validierung oder Authentifizierung, nie wegen
Reihenfolge. Dedup greift einzig bei identischer `run_id` mit
`last_event='completed'` (idempotentes `200 {deduplicated:true}` ohne Zähler-
oder Zeitstempeländerung). Ein laufender Lauf behält den letzten Abschluss als
Badge und zeigt zusätzlich „läuft seit X"; stale greift erst nach
`max(3 × Intervall, 60 s, VIRTUSPHERE_RUN_GRACE_SECONDS = 600)`. Providerfehler
sind grau (unbekannt); Rot ist exklusiv dem MECM-bestätigten kritischen Status 2
vorbehalten. `failure_streak` zählt nur aufeinanderfolgende `fail`-Abschlüsse.
Provider, Site-Code und Berichtsintervall (`MECM_ProviderMachine`,
`SiteHealthIntervalSeconds`) sind Registry-owned in
`HKLM:\SOFTWARE\VirtuSphere\MECM`, keine Portal-Settings. Keine Machine-API- oder
PowerShell-Wire-Form der Bestandsendpunkte ändert sich.
