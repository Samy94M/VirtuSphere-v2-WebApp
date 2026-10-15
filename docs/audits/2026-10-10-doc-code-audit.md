# Doku-gegen-Code-Audit 2026-10

Register des Abgleichs der aktiven Betriebs- und Agentendoku gegen den Code.
Prüfeinheit ist die einzelne prüfbare Aussage (Pfad, Befehl, `.env`-Schlüssel,
Default, Zahl, Schrittfolge, Portal-Beschriftung, Statuswert, Fehlermeldung),
nicht das Dokument als Ganzes.

## Quellstand

- Basis: `3557b3d` plus nicht committete Arbeit im Baum.
- Zurückgestellt bis zum Commit des laufenden Deploy-Pakets, weil Code oder Doku
  dort gerade in Bearbeitung sind: Cluster B (Deploy) mit `docs/operations/deploy-chain.md`,
  `docs/operations/deploy-flows.md`, `docs/DEPLOYMENT.md`, `docs/ai/contracts/deploy.md`,
  außerdem `docs/ai/contracts/portal.md`, `docs/ai/reference/portal.md`,
  `docs/ai/reference/webapi.md` und `help_deploy`.
- Nicht im Umfang: `docs/audits/` und `docs/CHANGELOG.md` (Historie). ADRs nur auf
  abgelöste Entscheidungen, die aktive Doku noch als gültig verlinkt.

## Urteile

| Urteil | Bedeutung |
|---|---|
| bestätigt | Code belegt die Aussage (`datei:zeile`) |
| falsch | Code widerspricht der Aussage |
| veraltet | Aussage stimmte früher, Code ist weiter |
| laborgebunden | nur gegen echtes ESXi/MECM/AD prüfbar, nicht geraten |
| zurückgestellt | hängt an Dateien des laufenden Deploy-Pakets |

Ein Befund „falsch“ oder „veraltet“ gilt erst nach eigener Nachmessung durch den
Koordinator. Weichen Doku und Code ab, entscheidet der Betreiber, welche Seite
gilt; der Code ist nicht automatisch richtig.

## Cluster

| Cluster | Doku | Status |
|---|---|---|
| A Installation/Betrieb | README, INSTALLATION-ANLEITUNG, go-live, offline-install, upgrade-recovery, backup, https, app-key-rotation, PRE-SHIP-CHECKLIST | geprüft, nachgemessen |
| B Deploy | siehe Quellstand | geprüft, nachgemessen (15.10.2026, Abschnitt „Cluster B“) |
| C1 MECM-Betrieb | mecm-integration, mecm-scheduled-tasks | geprüft, nachgemessen |
| C2 PowerShell | Powershell-MECM/README, clients/README, reference/powershell | geprüft, nachgemessen |
| D Maschine/Ansible/ESXi | contracts/machine, reference/machine-api, reference/ansible, trust-flows, esxi-inventory, ansible-full-test, flows | geprüft, nachgemessen |
| E Portal/Status | system-status-checks, logging, troubleshooting, active-directory, edit-concurrency, vm-progress-observation, GLOSSARY, contracts/core, reference/i18n, security/asvs-wstg-matrix | geprüft, nachgemessen |
| F QA/Agenten | QA, QUALITY-GATES, TESTPLAN, contracts/qa, AGENTS, CLAUDE, GROK, ai/workflow, ai/review-workflow, .claude/agents | geprüft, nachgemessen |
| G Datenbank/Architektur/ADR | reference/database, contracts/architecture, adr/README und ADR-Status | geprüft, nachgemessen |
| H1 Hilfe Status/Einstellungen | help_system_status, help_settings, help_credentials, help_users (DE/EN) | geprüft, nachgemessen |
| H2 Hilfe Missionen/Stack | help_missions, help_stack, help_packages, help_overview (DE/EN) | geprüft, nachgemessen |

## Befunde

Nur Befunde, die der Koordinator nachgemessen hat. Verworfene Agentenbefunde stehen
am Ende mit Grund.

### Korrigierbar ohne Entscheidung

| ID | Fundstelle | Befund | Beleg |
|---|---|---|---|
| A-04/A-05 | `go-live.md:111-120`, `app-key-rotation.md:41`, `upgrade-recovery.md:82-83`, Geschwister `go-live.md` Release-Neustartzeile | „PHP-FPM liest die `.env` je Anfrage“ und „`docker compose restart` genügt“ sind falsch: Werte kommen per `environment:`-Interpolation beim Erzeugen des Containers; eine geänderte `.env` (auch `APP_PUBLIC_BASE_URL`, `APP_KEY`) wirkt erst nach `docker compose up -d` | `docker-compose.yml:1-9,114-117,159-162`; kein `env_file` (Zeile 41) |
| A-01 | `go-live.md:311` | „Voll-Dump (`--all-databases`)“; gedumpt wird nur die App-Datenbank | `scripts/backup.sh:7,263` |
| A-02 | `backup.md:59` | `health.php` hat kein Feld `backup` | `portal/health.php:94-98` |
| A-03 | `offline-install.md:78` | „`vendor/` nicht im Git-Archiv“; 2197 vendor-Dateien sind getrackt, `deps/vendor.tar.gz` ersetzt sie durch den no-dev-Stand | `git ls-files Docker/WebAPI/vendor`, `build-offline-bundle.sh:14,214` |
| A-06 | `upgrade-recovery.md:55-57` | Migrationen 0054 bis 0058 fehlen in den Klassen A/N/C; Einordnung braucht Analyse der Migrationen | `lib/migrate.php` |
| A-07 | `PRE-SHIP-CHECKLIST.md:19` | `CreateSettleSeconds` entfernt | `lib/ansible_yaml.php:200` |
| A-08 | `backup.md:87` | Drill nutzt `mysql:8.4-virtusphere` | `scripts/restore_test.sh:84` |
| A-09 | `go-live.md:27,34,384,420`, `install-VirtuSphere-MECM.ps1:1135` | „Einstellungen → IP-Freigaben“ ist kein Tab (siehe C1-05) | `lang/de/settings.php:13,37` |
| A-10 | `go-live.md:140` | Zugangstest prüft zusätzlich Laufzeitversionen und den Async-Arbeitsbereich | `lib/ansible_command_preflight.php:63-98` |
| A-11 | `INSTALLATION-ANLEITUNG.md:195` | „Jeder Dienst hat einen Healthcheck“; das optionale phpMyAdmin nicht | `docker-compose.yml:256-297` |
| A-D5 | `scripts/build-offline-bundle.sh:367-368` (erzeugte `INSTALL.md`) | `tools/install.sh` liegt im Bundle-Verzeichnis, nicht im entpackten Projekt | `build-offline-bundle.sh:394` |
| A-D6 | `go-live.md:410-412`, `help_stack` `stack_a11_li6` | MECM-Aufgaben haben zwei Trigger: AtStartup und stündliche Wiederholung | `install-VirtuSphere-MECM.ps1:1074-1091` |
| A-x1 | `docs/operations/offline-install.md` Schritt 7 | `docker exec virtusphere-v2-webapp-php-1` nach Entpacken in `virtusphere/`: Compose setzt keinen Projektnamen, der Container heißt dort `virtusphere-php-1`, der Befehl trifft nichts | `docker-compose.yml` ohne `name:`, kein `COMPOSE_PROJECT_NAME` in `.env.example` |
| C2-02 | `Powershell-MECM/README.md:721` | Skriptname `Set-VMDisksOnline` statt `client_VMDisksOnline.ps1` | `Powershell-MECM/clients/` |
| C2-03 | `Powershell-MECM/README.md:676-688` | „Laufzeit nicht aktiviert, sendet nichts, `transport_not_integrated`“ veraltet; Wrapper startet den Reporter und sendet `started`/`step_result`/`completed` | `Package_Vorlage/install.ps1:810-875,938,993`; `transport_not_integrated` in keinem Skript |
| C2-04 | `Powershell-MECM/README.md:530,546` | „Alle vier Dienste … nach 3 Fehlern Site-Drive verwerfen“ gilt nur für Devices- und Packages-Sync | `mecm_new-device-sync.ps1:798`, `mecm_Packages-TaskSeq-sync.ps1:170` |
| C2-05 | `Powershell-MECM/README.md:560` | „erkennt die Änderung (mtime)“; dasselbe Dokument nennt in Zeile 411 korrekt das SHA-256-Manifest | `VirtuSphere-Common.ps1:912-941` |
| C1-01 | `mecm-integration.md:353-355` | `getDeviceInfos` bekommt nicht „genau ein“ Feld, sondern auch `device_generation`, `acceptance_generation` | `mecm-api.php:115-117` |
| C1-02 | `mecm-integration.md:669-672` | Umhängen nur, wenn der Nachfolger in diesem Payload neu ist und eine höhere Version hat | `mecm_packages.php:86-92` |
| C1-03 | `mecm-integration.md:542` | Label „Wartungsdienst (WebApp)“ statt „Wartungsdienst (Portal)“ | `lang/de/system_status.php:165` |
| C1-04 | `mecm-integration.md:1268` | Im Log steht „Katalogabgleich abgewiesen“; „Katalog-Sync abgelehnt“ ist nur der 409-Text | `lang/de/logs.php:140`, `mecm_packages.php:83` |
| C1-05 | `mecm-integration.md:105,397`, Geschwister `go-live.md` 4.4 und 5.2 | Menüpfad heißt Einstellungen → Machine-API → „Zugriff auf das Portal (eingehend)“ | `lang/de/settings.php:13,37` |
| C1-06 | `mecm-integration.md:462` | Abschnitt „Client-Phasen“ liegt auf „VM bearbeiten“, nicht „VM-Detail“ | `lang/de/help_missions.php:35` |
| D-01/H1-01 | `esxi-inventory.md:87-97`, `help_credentials` `credentials_cadence_p1` DE/EN | drei statt vier Abrufblocker; `no_deploy_worker` fehlt | `lib/deploy_constants.php:287-294`, `lib/esxi_automation.php:36-48` |
| H1-04 | `help_credentials` `credentials_tests_p1` | „Test veraltet nach 7 Tagen“; gilt das Maximum aus 7 Tagen und doppeltem Intervall (`k13_status` sagt es richtig) | `lib/status.php:234` |
| H1-05 | `help_settings` `settings_backup_p1/_p2` | Zeitplan aus Cron; Code bevorzugt systemd-Timer, Cron ist Rückfall | `scripts/backup.sh:140-157` |
| H1-06 | `help_system_status` `logs_p2`, `logs_p3` | Bereich „Sicherheit“ und sein Aufbewahrungsfenster enthalten auch AD und abgewiesene Maschinenzugriffe | `lib/constants.php:321-332` |
| H1-03 | `help_settings` `settings_https_p1/_p4` | „jeder Schritt umkehrbar“, „Rückweg immer offen“ gilt nicht bei aktiver AD-Anmeldung | `lang/de/settings.php:201` |
| H1-02 | `help_users` `usersmgmt_safety_2` | bei aktiver AD-Anmeldung ist der letzte aktive *lokale* Admin geschützt (Präzisierung) | `lib/users_admin.php:112-123` |
| H1-D3 | `help_settings` `settings_token_p1` EN | EN lässt den Satz zu Heartbeats/`reportRun` weg | `mecm_report.php:46` |
| H2-01..03 | `help_missions` `naming_p4`, `status_stuck_2`; `help_stack` `stack_a5_p2` | „WDS-Portgruppe für PXE“ statt Feldname „WDS-Portgruppe (PXE)“ | `lang/de/mission_details.php:11` |
| E-01 | `system-status-checks.md:105,238` | Buttons heißen „Volltest jetzt starten“ bzw. „Verbindung und Umgebung prüfen“ | `lang/de/system_status.php:284`, `lang/de/credentials.php:46` |
| E-02 | `system-status-checks.md:25-48` | Stufen heißen Arbeitet/Beeinträchtigt/Wartet vor Neustart/Nicht erreichbar | `lang/de/system_status.php:12-17` |
| E-03 | `system-status-checks.md:60,77,82,193` | Labels „Erwartet, nie gemeldet“, „Noch keine Daten“, „Legacy“ | `lang/de/system_status.php:154-156` |
| E-04 | `active-directory.md:47-51` | Gelb auch bei ungetesteten oder nicht vollständig grünen Controllern | `lib/directory_status.php:133-139` |
| E-07 | `GLOSSARY.md:7` | 1/5 wird nie geschrieben, aber als Rückfall angezeigt (Präzisierung) | `lib/status.php:75-91` |
| E-08/E-09 | `troubleshooting.md:71,72,82,88,92,96,106,110` | Protokoll-Reiter heißen Sicherheit/Ressourcen/Deployment/System & Integrationen; Systemstatus-Karten- und Tabnamen | `lang/de/logs.php:12-15`, `lang/de/settings.php:13` |
| F-01 | `docs/QA.md:746` | erwartet `4M 6M`, konfiguriert ist `4M 20M` | `Docker/php/conf.d/zz-virtusphere.ini:113-114` |
| F-02 | `docs/QA.md:376-380` | 60 s/15 s je Firefox/WebKit gibt es nicht; alle Engines 30 s/7 s | `tests/e2e/playwright.config.js:46-47` |
| F-03/F-04 | `docs/QA.md:986-995` | Integrationstests laufen im QA-Stack (`phpunit-full`), nicht „gegen den Dev-Stack, skip in CI“; `HttpsConfigTest` liegt in `tests/Unit/` | `Docker/WebAPI/tests/Unit/HttpsConfigTest.php` |
| F-05 | `docs/QA.md:911` | Sessionstart ruft `check-audit-contract.php` nicht auf | `.claude/hooks/session-start.sh:9,18` |
| F-06 | `docs/QA.md:925` | Umlaut-Ausnahme für `INSTALLATION-ANLEITUNG.md` existiert nicht mehr | `scripts/check-doc-semantics.sh:270-276` |
| F-07 | `docs/QA.md:929` | vier statt drei Zero-Match-Fälle | `scripts/check-audit-contract.php:91,104,175,329` |
| F-08 | `docs/QA.md:1139-1141` | `-MinimumVersion 5.5.0`; Runner verlangt exakt die Pester-Version aus `tool-lock.json` | `scripts/run-pester.ps1:107-111` |
| F-09 | `docs/QA.md:1217-1221` | Hook ruft `scripts/lint-csp-patterns.sh --file` direkt, nicht per `docker run … --all-changed` | `.claude/hooks/lint-csp-patterns.sh:16` |
| F-10 | `docs/QUALITY-GATES.md:11-55` | Gates `file-size` und `visual-contract` fehlen | `gates-fast.ps1:99`, `gates-integration.ps1:290` |
| F-12 | `docs/QA.md:91` | `-NoNetwork`-Aufzählung unvollständig | Gate-Registry `check.ps1` |
| F-13 | `GROK.md:103` | ADR-Inventar endet bei ADR-0017; Startdatei sollte nur auf den Index zeigen | `docs/adr/README.md` |
| G-x1 | `lib/repo/vms_validation.php:244` (Code-Kommentar) | „only applied at ESXi creation time“ widerspricht FC2-15 im Generator | `lib/ansible_yaml.php:154` |

### Entscheidung nötig

| ID | Frage |
|---|---|
| X-01 Client-Phasen | Ausgerollt ist noch der alte Graph (`staticip` vor `disks`), der MC01-Zielgraph (`VMDisksOnline` vor `staticip`) ist im Packaging verdrahtet, aber nicht freigegeben. Welche Reihenfolge nennt die Doku, und soll die Portal-Anzeigereihenfolge (`lib/constants.php:361-366`) mit MC01 wechseln? Betroffen: `go-live.md:490`, `Powershell-MECM/README.md:697`, `help_system_status` `clientphases_p1`. |
| X-02 ADR-Status | ADR-0003 und ADR-0008 stehen nach ADR-0035 noch auf Accepted; als abgelöst markieren? |
| X-03 VM-Übernahme | `troubleshooting.md:74` und `asvs-wstg-matrix.md:129,183` nennen die zurückgebaute Adoption; Beleg teils im laufenden Deploy-Paket, daher mit Cluster B klären. |

| X-04 Health-Matrix | `docs/QUALITY-GATES.md:86` sagt „jede Portalseite“; `tests/e2e/lib/pages.js` fehlen `package_reports.php` und `package_run.php`, und kein Wächter leitet die Liste aus `portal/` ab. Test ergänzen und Vollständigkeit ableiten, oder Doku einschränken? |

Entscheidungen des Betreibers (2026-10-10):

- X-01: Die Doku nennt beide Graphen getrennt, den heute ausgerollten und den
  MC01-Zielgraphen. Die Portal-Anzeigereihenfolge bleibt bis zur MC01-Freigabe.
- X-02: ADR-0003 und ADR-0008 werden als durch ADR-0035 abgelöst markiert.
- X-03: wird mit Cluster B geklärt.
- X-04: `package_reports.php` und `package_run.php` kommen in die Seitenliste, und
  ein Wächter leitet die Vollständigkeit aus `portal/` ab.
- Umfang: alle bestätigten Abweichungen, einschließlich Hilfekatalogen,
  Bundle-Text, Installer-Meldung und Code-Kommentar.

### Verworfen

| ID | Grund |
|---|---|
| G-01 | `[check.case]` ist Platzhalterschreibweise für `[<check>.<fall>]`, die echten IDs folgen ihr |
| F-Dublette Dev-Container | `docs/QA.md:79` stellt die Einzelbefehle ausdrücklich als menschliche Fehlersuche neben den Runner; das Agentenverbot widerspricht dem nicht |

## Korrekturstand

Alle Befunde unter „Korrigierbar ohne Entscheidung“ sind korrigiert und vom
Koordinator gegen den Code nachgeprüft (W1 bis W5; W1, W2, W3 und W5 brachen am
Sitzungslimit ab und wurden vom Koordinator zu Ende geführt). Zusätzlich:

- E-x1: `## Hinweise zur Bedienung` in `system-status-checks.md` (seit `751fefb`)
  verletzte den Wächter `SystemStatusChecksDocContractTest` (jeder `##`-Abschnitt
  ist ein Portalbereich mit Flowchart). Die drei Absätze stehen jetzt im Abschnitt
  „Ansible-Test“, in `https.md` und in `vm-progress-observation.md`.
- A-06: 0054 und 0055 in Klasse A, 0056, 0057 und 0058 in Klasse C.
- X-02 abweichend von der Vorlage: Nur ADR-0003 ist `Superseded by ADR-0035`.
  ADR-0008 trägt das weiterhin gültige Statusmodell (`lifecycle_state`,
  `mecm_sync_state`) und bleibt `Accepted` mit Hinweis auf ADR-0035.
- X-04: `package_reports.php` und `package_run.php` stehen in `PORTAL_PAGES`,
  `deploy_blockers.php` (JSON) in `NON_PAGE_ENDPOINTS`, `deploy_log.php` mit Grund
  in `MATRIX_DEFERRED_PAGES` (braucht einen geseedeten Deploy-Auftrag; mit Cluster
  B nachziehen). `PortalPageInventoryContractTest` leitet die Vollständigkeit aus
  `portal/` ab. Der e2e-Lauf für die zwei neuen Seiten steht aus.

Vorbestehend rot, nicht durch dieses Audit verursacht (Lauf vor den Korrekturen,
19 Failures, 2 Errors): Deploy-, Ansible-, Powercycle- und Repo-Modulverträge
sowie `DeployFlowsDocContractTest` (fehlende Abschnitte für
`export_vm_info.yml`, `powercycle_vm_info.yml`); alle im Bereich des laufenden
Deploy-Pakets beziehungsweise von HEAD `3557b3d`.

## Nächster Schritt

Gates (Lane Fast, ausgewählt): grün sind php-lint, lang-parity, doc-hygiene,
doc-semantics, js-syntax, powershell-syntax, shellcheck. Rot und vorbestehend
(Deploy-Paket bzw. HEAD `3557b3d`, nicht durch dieses Audit): phpunit-unit (20,
vorher 21; `SystemStatusChecksDocContractTest` jetzt grün, der neue
`PortalPageInventoryContractTest` grün), phpstan (6 Befunde, keiner in Audit-Dateien),
file-size (4 Dateien), powershell-tests (1 Fall zu Powercycle-/Export-Playbooks).

Korrekturen committet als `8a9a041` (10.10.2026). `e2e-portal` am 11.10.2026 im
Blockabschluss K13 bis K16 bestanden: Health-Matrix und Axe für `package_reports.php` und
`package_run.php` in beiden Themes erwartet (`qa-artifacts/block-k13-k16/green-2/e2e/`).
Die oben als vorbestehend rot genannten Gates sind mit diesem Blockabschluss grün.

Offen waren danach Cluster B, X-03 und `deploy_log.php` in der e2e-Matrix (Deploy-Paket
K13 bis K16 seit 11.10.2026 auf `main`). Abgearbeitet am 15.10.2026, siehe unten.

## Cluster B, X-03 und Matrixrest (15.10.2026)

Quellstand: `main` `16cde1d` (ESXi-Hardwareversion aus der Hostversion) zusammengeführt.
Inventur je Doku-Datei durch fünf nur lesende Haiku-Agenten (Effort xhigh); jeder der 46
umsetzbaren Agentenbefunde vom Koordinator am Code nachgemessen. Zeilen unten nach der
Korrektur.

### Korrigiert

| ID | Fundstelle (korrigiert) | Befund | Beleg |
|---|---|---|---|
| B-01 | `deploy-chain.md:43` | „liest der aktive Worker diese Grundlage noch nicht“; der Claim liest Runtime-Generation, Lease-Epoch und Claim-Pause, Vertrag bleibt `legacy` | `lib/repo/deploy_job_worker.php:92-107,216` |
| B-02 | `deploy-chain.md:191`, Geschwister `DEPLOYMENT.md` keine | „Test veraltet“ nach 7 Tagen; gilt das Maximum aus 7 Tagen und doppeltem Intervall | `lib/status.php:234` |
| B-03 | `deploy-chain.md:230` | Trap auf HUP/INT/TERM nur in Modusschritten, Create-Steueraufrufe bewusst ohne | `lib/ansible_command_modes.php:286`, `lib/ansible_command_create.php:16-20` |
| B-04 | `deploy-chain.md:378`, `DEPLOYMENT.md:117` | „jeder `ansible-playbook` mit `PYTHONUNBUFFERED=1`“; der Inventarabruf setzt es nicht | `lib/ansible_inventory_artifacts.php:46-57`, `lib/ansible_command_create.php:126`, `lib/ansible_command_modes.php:303` |
| B-05 | `deploy-chain.md:415` | „mindestens ein Erfolg“: übersprungene Einheiten zählen mit | `lib/deploy_worker_create.php:271-277` |
| B-06 | `deploy-flows.md:19` | Reihenfolge aus `ansible_playbooks_for_mode()` gilt nur für Missionsmodi; Create-Erkennung über Playbooks, kein Modusliteral | `lib/ansible_command_modes.php:94-112,171-174`, `lib/deploy_worker_mission.php:40,153` |
| B-07 | `deploy-flows.md:32` | Zeitplan darf bis 300 s in der Vergangenheit liegen | `lib/repo/deploy_job_input.php:240`, `lib/deploy_constants.php:191` |
| B-08 | `deploy-flows.md:179-183` | Launch ohne lesbare Antwort ergibt `protocol_error`, nicht `launch_unconfirmed`; dieses nur bei fehlender Job-ID nach Transportabriss, mehrere Job-IDs ergeben `protocol_error` | `lib/deploy_worker_create_launch.php:258-260,292-306`, `lib/deploy_worker_create_remote.php:154-178` |
| B-09 | `deploy-flows.md:222` | Create-Fehler `ownership_lost` hat einen Writer (Ablaufbereinigung) | `lib/repo/deploy_create_identity.php:257-285` |
| B-10 | `deploy-flows.md:358` | abgewiesene Touch-Bestätigung bricht den ganzen Export ab | `Ansible/export_vm_info.yml:22-31` |
| B-11 | `DEPLOYMENT.md:14` | sieben von acht Inventarabfragen unter `ignore_errors` | `Ansible/inventoryESXi_playbook.yml:23-138` |
| B-12 | `DEPLOYMENT.md:27`, `help_deploy` `deploy_warn_p3` DE/EN | freie Lizenz sperrt schreibende Modi beim Einreihen, nicht nur Hinweis; dazu sperrt jetzt der Create-Befund der Hostversion | `lib/esxi_capabilities.php:178-185`, `lib/deploy_capability_blockers.php:27-31`, `lib/esxi_create_compatibility.php:125-168` |
| B-13 | `DEPLOYMENT.md:39`, `:413` | Labels „Check connection and environment“, „Catalog and inventory“ | `lang/en/credentials.php:46`, `lang/en/settings.php:14` |
| B-14 | `DEPLOYMENT.md:50,52` | Generator liegt in `lib/ansible_yaml.php`; `accounts.yml` trägt auch `esxi_validate_certs`, `esxi_ca_bundle_path` | `lib/ansible_yaml.php:18,30-38` |
| B-15 | `DEPLOYMENT.md:107` | `VIRTUSPHERE_CREATE_ARTIFACTS` hat sieben Einträge | `lib/deploy_create_constants.php:231-239` |
| B-16 | `DEPLOYMENT.md:165` | MAC-Upload wiederholt bis zu dreimal (5/15/45 s), auch bei Netzfehlern; protokolliert das begrenzte `error`-Feld | `Ansible/upload_mac_list.py:44,269-292` |
| B-17 | `DEPLOYMENT.md:326` | Step-Policy wird vom MAC-Rückruf geladen (`remote_step_callback_expectation()`) | `lib/mac_import_callback.php:10,125`, `db_importMAC.php:185` |
| B-18 | `DEPLOYMENT.md:347` | `setup.sh` legt Verzeichnisse vor der `.env` an | `Docker/scripts/setup.sh:37-47` |
| B-19 | `DEPLOYMENT.md:351` | `health.php` liest den Dienst-Snapshot statt eigener Heartbeat-Abfrage; Pause ist nicht `degraded` | `portal/health.php:44-74,95` |
| B-20 | `help_deploy` `service_p3` DE/EN | „Beeinträchtigt“ auch bei unbekanntem oder widersprüchlichem Prozessvertrag und fehlendem Arbeitsprozess der Aufsicht | `lib/deploy_service_health.php:52-83` |
| B-21 | `help_deploy` `deploy_cancel_p2` DE/EN | nicht die Aufsicht, sondern die Ablaufbereinigung schließt den Abbruch, frühestens nach 600 s | `lib/repo/deploy_job_maintenance.php:132-136`, `lib/deploy_worker_loop.php:139`, `lib/maintenance_tasks.php:181`, `lib/deploy_constants.php:92` |
| B-22 | `ai/reference/portal.md` R14, R29, R26 | Owner `lib/layout_presenters.php`, `lib/system_status_urls.php`; Pluralbeispiel existierte nicht | `lib/layout_presenters.php:14`, `lib/system_status_urls.php:12`, `lang/de/deploy.php:65-66` |
| B-23 | `ai/reference/portal.md` R23, R47 | `forced-colors.css` steht nach `status.css`; `status.css` hat drei Seiten | `lib/layout.php:120-136` |
| B-24 | `ai/reference/portal.md` R31 | `log_filter_repo_args()` existiert nicht; Tabelle, Zählung und Export reichen dieselbe Filterstruktur weiter | `portal/logs.php:28,47,50`, `lib/logs_export.php:73` |
| B-25 | `ai/contracts/portal.md` A27 | `portal_format_timestamp()` liegt in `lib/layout_response.php` | `lib/layout_response.php:10`, `lib/portal_time.php:52` |
| B-26 | `ai/contracts/deploy.md` A42, A48 | MAC-Zuweisungssperren in `lib/repo/vms_mac_assignments.php`; „five machine status strings“ = die fünf Legacy-Statuswerte | `lib/repo/vms_mac_assignments.php:23,61`, `lib/vm_network_mac_assignments.php:16`, `lib/constants.php:62-66` |
| B-27 | `ai/reference/webapi.md` R3, `GROK.md:18` | Ausnahmen von `repo_transaction()`: Lesesnapshot des Rohprotokolls in `lib/`, `mecm_client_ack.php` fehlte; GROK verweist jetzt auf R3 (spart 356 Byte Budget) | `lib/repo/deploy_job_queries.php:208`, `tests/Static/DeployJobLogReadContractTest.php:76`, `mecm_client_ack.php:63` |
| B-28 | `ai/reference/webapi.md:81` | Ereigniscode heißt `vm.rollout_hostname_changed` | `lib/audit_event_definitions.php:44` |
| X-03 | `troubleshooting.md:75-76`, `asvs-wstg-matrix.md:47,101`, `PRE-SHIP-CHECKLIST.md:20` | „Vorhandene VM übernehmen“/Adoption ist zurückgebaut; eine vorhandene VM einer ungeklärten Einheit hat noch keine positive Auflösung; Create ersetzt nur eine extern gelöschte gebundene VM, eine umbenannte sperrt | ADR-0036 Nachtrag WM-E2a, `lib/deploy_blockers.php:86-100`, `lib/repo/deploy_create_identity.php:32-42`, `lib/deploy_create_constants.php:111`, `lang/de/help_deploy.php` `create_progress_p3` |
| X-04-Rest | `tests/e2e/lib/pages.js`, `tests/e2e/lib/matrix-seed.js`, `docs/QUALITY-GATES.md:92` | `deploy_log.php` in `PORTAL_PAGES` mit geseedetem fehlgeschlagenem Export-Auftrag samt drei Protokollzeilen; `MATRIX_DEFERRED_PAGES` leer | `PortalPageInventoryContractTest` |

### Verworfen

| ID | Grund |
|---|---|
| B-v1 `deploy-flows.md:17` inventory „Braucht Datacenter/Datastore: nein“ | stimmt für die Laufzeit: der Systempfad verzweigt vor jedem Standort-Gate (`lib/deploy_worker_mission.php:40`); `virtusphere_deploy_mode_needs_location()` antwortet nur formal |
| B-v2 `DEPLOYMENT.md:30` vCenter „deploy no“ | Supportentscheidung der Matrix, kein Codeanspruch |

### Laborgebunden (nicht geändert)

Power-Cycle nach hartem Prozessabbruch (`deploy-flows.md:354`), Messwerte zu SIGQUIT,
30,4 s und 0,4 s (`deploy-chain.md`, `DEPLOYMENT.md`), Ausgabeumfang von `ansible-doc`,
VMware-Verhalten der Autostart-Hilfe (`autostart_limit_*`, `autostart_heartbeat_*`).

### Agenteneinsatz

Fünf Haiku-Agenten, Effort xhigh, nur lesend, je 8 bis 12 min, rund 1,48 Mio. Tokens.
Von 46 umsetzbaren Befunden hielten 37 der Nachmessung stand, 2 waren falsch (B-v1,
B-v2), 7 nur im Labor prüfbar. Übersehen hat die Inventur `DEPLOYMENT.md:27` (freie
Lizenz), gefunden über den Hilfebefund B-12. Alle Formulierungen schrieb der Koordinator.
