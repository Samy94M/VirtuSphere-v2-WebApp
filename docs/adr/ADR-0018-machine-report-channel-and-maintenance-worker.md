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
3. **Portal**: Statusseite `portal/integrations.php` (alle angemeldeten
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
