# Bereitstellung und Systemstatus: Ablaufprüfung

## Auftrag

Der Nutzer wollte Ablaufdiagramme für alle Ansible-YAML-Dateien, die Bereitstellungsmodi und die Prüfungen des Systemstatus. Danach sollte er sie auf SSoT, Drifts, Logik, Probleme, Bedienkomfort, Doku und Hilfe prüfen. Ergebnis sind die beiden Betriebsdokumente [Bereitstellung: Abläufe](../operations/deploy-flows.md) und [Systemstatus: Prüfungen](../operations/system-status-checks.md). Diese Datei hält Befunde, Entscheidungen und Pakete fest.

Quelle ist Codelesung am Stand `9139971` (MC04), nachgeprüft gegen `a364047`: Die dazwischen gelieferten Commits (T4, MC03, Modusbezeichnungen, AV-P0 Teil 1, MECM-Diagramme) erledigen keinen der Befunde unten und verschieben keine genannte Funktion. Kein Befund ist durch einen Testlauf bestätigt. Jeder Fix beginnt mit einem Test, der den Befund zeigt.

Die Mermaid-Blöcke der beiden Betriebsdokumente sind die einzige Quelle der Diagramme; eine Ansichtsseite wird daraus erzeugt, nie umgekehrt. Die Betriebsdokumente zeigen nur ausgelieferten Stand. Die Änderungen unten ziehen ihr Diagramm im Commit ihrer Umsetzung nach.

## Entscheidungen des Nutzers (28.09.2026)

| ID | Frage | Entscheidung |
|---|---|---|
| DF-E1 | Ein neuer `create`- oder `full`-Auftrag trifft auf eine bereits ausgerollte, eigene VM (gebundene UUID) | Ist die VM eingeschaltet, wird sie nur geprüft und als unverändert gemeldet. Hardware wird nur bei ausgeschalteter VM angeglichen. Eine angehaltene VM (suspended) zählt als eingeschaltet. |
| DF-E2 | Freie ESXi-Lizenz (API nur lesend) | Mit aktuellem Lizenzbefund sperrt das Portal `create`, `full`, `powercycle`, `start` und `autostart` schon beim Einreihen und im Worker erneut. `export` und der Inventarabruf bleiben erlaubt. Ein veralteter oder fehlender Befund warnt nur. |
| DF-E3 | ESXi-Nachweis bei Intervall 0 | Ein erfolgreicher Inventarabruf altert auch ohne Automatik, wie der Ansible-Volltest nach einer festen Frist. Veraltet ist grau. Sperren aus Host-Eigenschaften (DF-E2, Autostart) stützen sich nur auf aktuelle Befunde. |
| DF-E4 | Site-Health-Reporter meldet sich nicht mehr | Seine Zeile wird gelb „Überfällig“ wie bei den Sync-Aufgaben. Der letzte MECM-Site-Befund bleibt sichtbar und als historisch markiert. |
| DF-E5 | Beschriftung des veralteten Site-Befunds | Badge „Veraltet“ statt „Unbekannt“; die Legende erklärt den Zustand. |

## Befunde

### Logik und Probleme

| ID | Stelle | Befund | Maßnahme |
|---|---|---|---|
| DF-L1 | `Ansible/createVMLaunch-ESXi_playbook.yml`, `lib/repo/deploy_create_results.php` | Ein neuer `create`- oder `full`-Auftrag materialisiert jede VM mit Aktion `create`. Existiert die eigene VM, gleicht `vmware_guest state: present` per UUID CPU, RAM, Disks, Netze und Firmware an, auch bei laufender VM. Das kann eine produktive VM umkonfigurieren oder am Modul scheitern (Hardwareversion, Firmware, Disk-Verkleinerung). | DF-E1 umsetzen: Den Energiezustand liefert `vmware_vm_info` in der Identitätsprüfung bereits mit (`power_state`), ein zusätzlicher Aufruf ist nicht nötig. Das Prepare-Ergebnis trägt ihn als neues Feld des Create-Markers; bei eingeschalteter oder angehaltener eigener VM wird die Einheit `skipped` mit `unchanged` statt Launch. Weicht die Portal-Hardware ab, nennt das Auftragsprotokoll die nicht angeglichene VM, damit die Abweichung nicht still bleibt. Launch prüft den Zustand erneut, weil die VM zwischen Prepare und Launch eingeschaltet werden kann. Hilfe (`deploy_mode_create`, `deploy_mode_full`) nennt die Regel. |
| DF-L2 | `lib/deploy_worker_mission.php` (`deploy_worker_autostart_preflight`), `lib/deploy_blockers.php`, `lib/deploy_view_model.php` | Eine freie Lizenz sperrt früh nur Aufträge, die Autostart schreiben. `create`, `full`, `powercycle` und `start` zeigen auf der Deploy-Seite nur eine Warnung und scheitern später am Modul, weil die API nur lesend ist. | DF-E2 umsetzen: eine Capability-Sperre für schreibende Modi in `deploy_queue_blockers()` und im Repository-Einreihen. Alle drei Einreihwege brauchen sie: Einzelauftrag, Staffelgruppe (`repo_enqueue_deploy_group()`) und Wiederholung (über `repo_create_deploy_job()`). Dieselbe Prüfung läuft im Worker vor dem Upload, weil ein geplanter Auftrag erst Tage später starten kann. Meldung mit Verweis auf die ESXi-Karte im Systemstatus. |
| DF-L3 | `lib/esxi_inventory_display.php` (`esxi_inventory_ampel`), `lib/esxi_capabilities.php` (`esxi_capabilities_fresh`) | Bei Intervall 0 altert ein erfolgreicher Abruf nie: Die Ampel bleibt grün, und Host-Eigenschaften gelten unbegrenzt als aktuell. Die Autostart-Sperre kann sich damit auf einen Monate alten Lizenzbefund stützen, obwohl der Kommentar an `esxi_capabilities_fresh()` genau das ausschließen will. Der Ansible-Volltest folgt der Gegenregel (`VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS`, unabhängig vom Zeitplan). | DF-E3 umsetzen: eigene Frist als Konstante; Ampelzustand `stale` für ESXi (grau) in `VIRTUSPHERE_ESXI_AMPEL_STATES`, Badge, Legende und Hilfe, mit demselben Rang wie heute `unknown`; `esxi_capabilities_fresh()` nutzt dieselbe Frist. Die Zugangsdatenseite und die Dashboard-Kachel lesen dieselbe Funktion und ziehen automatisch mit. Doku in `esxi-inventory.md` und Hilfe `esxi_inv_ampel_p1` nachziehen, dort steht heute ausdrücklich „bei Intervall 0 entfällt die Veraltet-Warnung“. |
| DF-L4 | `lib/status_evidence.php` (`virtusphere_site_completed_state`), `lib/layout_presenters.php` (`heartbeat_badge`), `lib/constants.php` (`VIRTUSPHERE_HEARTBEAT_STATES`) | Ein veralteter Site-Befund liefert den Zustand `stale`. `heartbeat_badge()` kennt ihn nicht und beschriftet ihn „Unbekannt“; die Legende erklärt „Unbekannt“ als „hat sich nie gemeldet“, was hier falsch ist. `stale` fehlt in `VIRTUSPHERE_HEARTBEAT_STATES`, obwohl diese Konstante genau diese Legendenlücke verhindern soll. Ein ausgefallener Reporter färbt die MECM-Kachel höchstens grau. | DF-E4 und DF-E5 umsetzen: Reporteralter (gelb „Überfällig“) und Site-Befund (historisch) trennen; `stale` in Zustandsliste, Badge, Legende DE/EN und Hilfe. |
| DF-L5 | `lib/repo/vm_identity.php` (`repo_vm_identity_conflicts`), Aktion „Identität übernehmen“ | Die Identitätssperre beim Einreihen und die Übernahme lesen den Inventar-Cache ohne Altersgrenze. Ein veralteter Eintrag kann das Einreihen sperren, und die Übernahme kann MOID und UUID einer VM binden, die es auf dem Host nicht mehr gibt. Die spätere Live-Prüfung scheitert dann sicher, aber erst im Auftrag. `docs/DEPLOYMENT.md` spricht von „fresh VM inventory“. | Vorschlag zu DF-E3, im Paket bestätigen lassen: Die Sperre bleibt, sie ist die sichere Richtung, zeigt aber das Alter des Inventars und einen Verweis zum Aktualisieren. Die Übernahme verlangt ein aktuelles Inventar. |

### Drift zwischen Code, Doku und Hilfe

| ID | Stelle | Befund | Maßnahme |
|---|---|---|---|
| DF-D1 | `docs/DEPLOYMENT.md` (Abschnitt VM identity, letzte zwei Sätze des Absatzes zu Adopt identity), Kommentar in `Ansible/exportVMs-Informations-ESXi_playbook.yml` | Beide sagen noch, `full` dürfe eine ungebundene VM nach seinem Create tragen und der Export-Rückruf binde die UUID. Seit IDR-R2 schreibt der Worker `serverlist.yml` nach dem Create neu, und jedes spätere Playbook verlangt eine gebundene UUID; DEPLOYMENT.md sagt das im Abschnitt zu den generierten Variablen selbst. | Beide Stellen auf den heutigen Stand korrigieren. |
| DF-D2 | Hilfe `deploy_mode_export` DE/EN | „liest MAC-Adressen bereits laufender VMs aus“. Der Export liest auch ausgeschaltete VMs, sobald ESXi die MAC vergeben hat, also nach dem ersten Einschalten. | Text korrigieren. |
| DF-D3 | Hilfe `deploy_mode_create` DE/EN | „eine fremde oder noch unbekannte namensgleiche VM blockiert den Lauf“. Beim Einreihen sperrt eine im Inventar bekannte Namensgleiche tatsächlich; zur Laufzeit scheitert nur die betroffene VM, die übrigen laufen weiter und der Auftrag endet `partial`. | Text präzisieren, zusammen mit DF-E1. |
| DF-D4 | `docs/DEPLOYMENT.md`, Abschnitt Runtime setup | Die Liste der Volltest-Komponenten nennt `ansible-playbook`, `python3`, `pyvmomi`, `requests`, `community.vmware`. Der Code prüft zusätzlich Laufzeitversionen und den Async-Arbeitsbereich. | Liste ergänzen. |
| DF-D5 | Kommentar in `Ansible/requirements.yml` | Nennt `powercycleVMs-ESXi_playbook.yml` als Nutzer von `vmware_guest_powerstate`; der Aufruf steht heute in `powercycle_vm_tasks.yml`. Das Gate liest alle YAML-Dateien und ist nicht betroffen. | Kommentar korrigieren. |
| DF-D6 | Kommentare in `lib/esxi_inventory_display.php` und `lib/esxi_capabilities.php` | Der erste nennt `esxi_credential_state()` die Ampel des Portals; der Systemstatus ruft `esxi_inventory_ampel()` direkt, `esxi_credential_state()` ist nur ein Alias. Der zweite sagt, im HA-Cluster laufe kein Deploy; laut Supportmatrix geht Deploy, nur Autostart nicht. | Alias auflösen oder einheitlich nutzen; Kommentare korrigieren. |
| DF-D7 | Kopfkommentar `Ansible/inventoryESXi_playbook.yml` | Nennt die Markerzeile `VirtuSphere_inventory`; ausgegeben wird `VIRTUSPHERE_INVENTORY_B64_BEGIN`. | Kommentar korrigieren. |
| DF-D8 | `docs/DEPLOYMENT.md`, Abschnitt VM identity | „Queueing is blocked when the selected credential's fresh VM inventory already contains a namesake“: Der Code prüft kein Alter (DF-L5). | Mit DF-L5 angleichen. |

### SSoT und Aufräumen

| ID | Stelle | Befund | Maßnahme |
|---|---|---|---|
| DF-S1 | `lib/ansible_paths.php` (`ansible_required_files`) | `powercycle_vm_tasks.yml` steht als Literal, die Create-Dateien dagegen als Konstanten. | Konstante neben `VIRTUSPHERE_CREATE_IDENTITY_TASKS`. |
| DF-S2 | `Ansible/powercycleVMs-ESXi_playbook.yml`, `Ansible/startVMs-ESXi_playbook.yml` | `default(5)` und `default(300)` doppeln `VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT` und `VIRTUSPHERE_START_WAIT_SECONDS_DEFAULT`. Der Worker schreibt beide Werte immer; ein Default würde nur einen Emitterfehler verdecken. | Default entfernen und einen fehlenden Wert laut scheitern lassen, oder vom Pausenbudget-Vertragstest mitprüfen lassen. |
| DF-S3 | `Ansible/test-linux_playbook.yml` | Gehört zu keinem Modus, wird nie hochgeladen und legt beim Ausführen Ordner und Dateien im Home-Verzeichnis des Ansible-Benutzers an. | Entfernt. Der Nutzer hat am 28.09.2026 bestätigt, dass er die Datei nicht benutzt. |
| DF-S4 | `docs/operations/deploy-flows.md`, `docs/operations/system-status-checks.md` | Nur die Regel im Dokumentkopf hält Diagramm und Code zusammen. Ein neues Playbook oder ein neuer Modus kann ohne Diagramm ausgeliefert werden. | Wächtertest nach dem Muster von `MecmScheduledTasksDocContractTest` (Liste aus dem Code ableiten, nie im Test aufzählen): Jede Datei `Ansible/*_playbook.yml` hat einen Abschnitt in `deploy-flows.md`, jeder Modus aus `virtusphere_deploy_modes()` eine Zeile in der Modustabelle, jeder Bereich der Systemstatus-Übersicht einen Abschnitt in `system-status-checks.md`. |

### Bereits geplant, hier nur verknüpft

- Modusnamen DE/EN und doppelter MAC-Export im Hilfetext von `full`: geliefert in `0dc5172`; `DeployModeTextTest` leitet seither jede Modusliste der Hilfe ab. DF-D2 und DF-D3 betrifft das nicht, sie beschreiben das Verhalten falsch, nicht den Namen.
- Suche per Name in Powercycle, Export, Start und Autostart, bei Namensdubletten sicherer Abbruch statt richtiger VM: [Identitätsplan](2026-09-14-vm-identity-replacement-plan.md), Abschnitt 16.2.
- Alle VMs starten nach einer gemeinsamen Pause gleichzeitig: [Staffelungsaudit](2026-09-11-full-pipeline-start-stagger-audit.md), P1 bis P7.

## Pakete

| Paket | Inhalt | Voraussetzung |
|---|---|---|
| DF-P0 | Drift und Aufräumen: DF-D1 bis DF-D7, DF-S1, DF-S2, DF-S4 (DF-S3 ist bereits erledigt) | keine |
| DF-P1 | Systemstatus: DF-L4 mit DF-E4 und DF-E5 | keine |
| DF-P2 | ESXi-Nachweisalter: DF-L3 mit DF-E3, dazu DF-L5 und DF-D8 | vor DF-P3, weil DF-P3 auf aktuelle Befunde angewiesen ist |
| DF-P3 | Freie Lizenz sperrt schreibende Modi: DF-L2 mit DF-E2 | DF-P2 |
| DF-P4 | Create auf laufender eigener VM nur prüfen: DF-L1 mit DF-E1, DF-D3 | Create-Vertrag (Maschinenprotokoll der Create-Marker) unverändert lassen oder gemeinsam mit seinen Vertragstests ändern |

Jedes Paket zieht das betroffene Diagramm in [Bereitstellung: Abläufe](../operations/deploy-flows.md) oder [Systemstatus: Prüfungen](../operations/system-status-checks.md) nach.

## Einordnung in die Reihenfolge

Vom Nutzer am 28.09.2026 bestätigt, bezogen auf die Reihenfolge der Register-Entscheidungen vom selben Tag:

- DF-P0 läuft als nächstes Doku-Paket nach dem Reporter- und MC03-Strang. Die ursprünglich geplante Kopplung an die Modusnamen entfällt, weil diese in `0dc5172` schon geliefert sind.
- DF-P1, DF-P2 und DF-P3 folgen auf das Autoimporter-Versionsmodell (AV). Sie sind reine Portal-Arbeit und von der MECM-Installersperre nicht betroffen. DF-P2 kommt vor DF-P3.
- DF-P4 läuft mit IDR-P06 und der UUID-Suche in allen Playbooks, weil alle drei das Create- und Identitätsverhalten ändern.

## Doku und Hilfe

Verweise auf die beiden neuen Betriebsdokumente fehlen noch an diesen Stellen:
- `docs/DEPLOYMENT.md`, Abschnitt Deploy modes
- `docs/operations/deploy-chain.md`, nach der Übergabetabelle
- `docs/operations/esxi-inventory.md`
- `docs/operations/ansible-full-test.md`
- `docs/operations/troubleshooting.md`
- Portalhilfe `help_deploy` und `help_system_status` DE/EN, im bestehenden Muster „liegt im Projektordner auf dem Server“

Diese Dateien bearbeitet parallel die lokale Session. Die Verweise setzt deshalb sie, nachdem dieser Branch zusammengeführt ist. Dieselbe Session trägt auch den Verweis auf diesen Plan in das [Register](2026-09-12-consolidated-session-backlog.md) ein.

## CI-Paket auf demselben Branch (28.09.2026)

Der Nutzer hat freigegeben, die roten Jobs auf `main` als eigenes Paket zu beheben. Ursachen und Behebung:

| Nr. | Ursache | Behebung |
|---|---|---|
| CI-1 | pwsh auf Linux expandiert ein einzelnes `*` im Argument-Array zu Dateinamen. `docker compose --profile *` bekam dadurch Dateinamen statt eines Profils (Compose-Gate, Guard-Harness, Release-Imageliste). | `--profile=*` als ein Argument in `scripts/check-compose-hardening.ps1` und `scripts/lib/check/gates-release.ps1`. |
| CI-2 | `tests/e2e/specs/crud-vm.spec.js`: `waitForURL` löst sofort aus, wenn die Seite schon auf der Ziel-URL steht; die Datenbankabfrage lief vor dem Ende des POST. | Auf die POST-Antwort von `vms.php` warten, danach die URL prüfen. |
| CI-3 | Pester unter pwsh auf Linux: Registry-Laufwerk, `Get-CimInstance`, `Get-Acl`, `Get-ScheduledTask`, `powershell` und Win32-Jobobjekte fehlen; versteckte Punktdateien; JSON-Zeitstempel werden zu DateTime und Ganzzahlen zu Int64. | Stubs für fehlende Befehle, damit Pester sie mocken kann. Registry- und Windows-Kernfälle nach dem `$HasRegistry`-Muster nur unter Windows definiert; der PS-5.1-Job beweist sie. Fixtures engine-neutral gelesen (`tests/powershell/VirtuSphere.TestJson.ps1`). Im Produkt: Quittungsprüfung akzeptiert `schema_version` als Int64 wie schon `event_seq`; Step- und Completed-Bericht übernehmen die geprüften Zeitstempel statt des JSON-Rückwegs; der Verzeichnisvergleich im Serverinstaller und der FileSystem-Ort des Client-Preflights sind trennzeichen- und engine-neutral. Auf Windows ändert sich kein Verhalten. |
| CI-4 | Seit Etappe 11 rief `powershell-tests` den visuellen Vertragstest `tests/e2e/tests/visual-contract.test.js` auf. Er braucht `playwright-core` und einen installierten Chromium der Lockfile-Revision; die Fast-Lane ist laut `docs/QA.md` browserfrei. Das Integration-Gate `visual-contract` führt dieselbe Datei bereits aus. | Entscheidung des Nutzers (28.09.2026): in die Integration-Lane verlegen. Der doppelte Aufruf in Pester entfällt; ein statischer Fall prüft stattdessen, dass das Gate `visual-contract` die Suite in der Integration-Lane ausführt und nicht in der Fast-Lane steht. |
| CI-5 | Die UX02-Sollbilder für den Systemstatus fehlen (Manifest und Vertrag passen nicht zusammen). | Offen beim Nutzer: Sollbilder erzeugt und prüft nur der Mensch mit dem Baseline-Werkzeug. Bis dahin bleibt das Gate `visual-contract` der Integration-Lane an genau dieser Stelle rot. |

Die Integration-Lane und der Windows-Job laufen nicht auf Pull Requests. CI-2 und der Windows-Anteil von CI-3 sind deshalb erst nach dem Zusammenführen auf `main` bewiesen. CI-1 zeigt sich schon in der Fast-Lane (Compose-Gate).

Nebenbefunde, nicht in diesem Paket behoben:

- **CI-N1 (SSoT):** Drei Stellen wechseln für UNC-Zugriffe auf einen FileSystem-Ort, jede anders: Client-Preflight, `Invoke-VsClientFileSystemScope` in den Client-Bundles und die Client-Auslieferung. Ein gemeinsamer Helfer gehört in den MC03-Strang, der diese Dateien gerade bearbeitet.
- **CI-N2 (Engine):** `Initialize-VsClientBootstrap` prüft `Schema -is [int]`. Unter Windows PowerShell 5.1 ist das richtig, unter pwsh 7 wäre der Wert Int64. Angleichen an das Muster `[int]` oder `[long]` wie im Reporter.
- **CI-N3 (Engine):** Der Reporter-Host liest jede Pipe-Zeile mit `ConvertFrom-Json`; unter pwsh 7 würden Zeitstempel zu DateTime. Nur relevant, falls der Host je außerhalb von 5.1 läuft.

## FC2: Abgleich der Diagramme mit dem Code (03.10.2026)

Paket FC2 aus dem [Register](2026-09-12-consolidated-session-backlog.md): [Bereitstellung: Abläufe](../operations/deploy-flows.md) und [Systemstatus: Prüfungen](../operations/system-status-checks.md) gegen den Code, entlang der Edge-Case-Checkliste des Registers. An jedem Pfad drei Fragen: Was ist auf ESXi oder in MECM geschehen, in welchem Zustand endet die VM, und wo liest der Operator den Grund? Quellstand `3b37025` (`main`). Nur Codelesung, kein Testlauf; die Mengen in FC2-04 sind aus dem Playbook-Aufbau geschätzt. Jeder Fix beginnt mit einem Test, der den Befund zeigt.

**Nutzerentscheid 03.10.2026:** In dieser Sitzung keine Änderung an Code, Diagrammen oder Hilfe; die Befunde werden nur in diesen Plan und das Register aufgenommen.

**Prüftiefe:** Selbst hergeleitet wurden der Worker-Ablauf (`lib/deploy_worker_mission.php`, `lib/deploy_worker_finish.php`, `lib/deploy_worker_loop.php`), die Create-Phase mit Prepare, Launch, Statusabfrage und Freigabe, die Einreihsperren, die Playbooks Power-Cycle, Export und Start sowie die Systemstatus-Regeln für Deploy-Dienst, MECM, ESXi und Intern. Nicht Knoten für Knoten geprüft: die Playbook-Diagramme `createVMPrepare`, `createVMLaunch`, `createVMStatus` und `createVMCleanup`, die Identitätsmatrix, `autostartVMs`, `inventoryESXi`, die Vorschau beim Einreihen und die Systemstatus-Bereiche Ansible, Abweichungen und Verzeichnis. Dieser Rest von FC2 bleibt offen.

Die DF-Befunde oben und die FC3-Lücken des Registers bestehen am selben Stand fort und werden hier nicht wiederholt. Stichprobe: DF-D5 im Kommentar von `Ansible/requirements.yml`, DF-D7 im Kopfkommentar von `Ansible/inventoryESXi_playbook.yml`, DF-S1 in `lib/ansible_paths.php`, der tote Verweis „Abschnitt ESXi-Inventar“ im Worker-Diagramm.

### Grenzfälle und Logik

| ID | Prio | Stelle | Befund | Maßnahme |
|---|---|---|---|---|
| FC2-01 | P1 | `lib/deploy_worker_finish.php` (`deploy_worker_handle_failure`), `lib/deploy_worker_vm_state.php` (`deploy_worker_fail_locked_job_vms`, `deploy_worker_confirm_owned_cancel`), `lib/deploy_worker_mission.php` | `deploy_worker_handle_failure()` setzt jede VM des Auftragsumfangs auf Lifecycle `failed` und MECM `failed`; leere Auswahl heißt ganze Mission. `deploy_worker_mark_vms_deploying()` hat vorher in jedem Modus alle VMs auf `deploying` gesetzt. Betroffen sind damit auch `start` und `autostart` und jeder Modus, der scheitert, bevor ein Playbook ESXi berührt: Collection-Sperre, Host-Preflight, SFTP, HA-Host im Modus `autostart`. Abbruch (alle noch `deploying`), Reaper und Konvergenz-Sweep färben genauso. Auf dem Erfolgsweg ohne Export stellt der Worker den vorigen Lifecycle wieder her, auf dem Fehlerweg nicht; den vorigen MECM-Zustand merkt sich nichts. Folgen für in MECM registrierte VMs: `repo_mark_vm_for_mecm_resync()` lehnt „An MECM übertragen“ ab, und `repo_mission_has_mecm_active_vms()` hebt die Umbenennungssperre der Mission auf, sodass die gleichnamige MECM-Collection verwaisen kann. Zurück kommt die VM nur über einen neuen Export. Der [Staffelplan](2026-09-11-full-pipeline-start-stagger-audit.md), P5, deckt davon nur `start` ab. | Entscheid FC2-E1. Vorschlag: `start` und `autostart` schreiben nie Lifecycle oder MECM-Zustand; scheitert ein anderer Modus, bevor ein Playbook ESXi berührt hat, gilt wieder der vorige Lifecycle; `failed/failed` nur für VMs, die ein Playbook dieses Auftrags tatsächlich angefasst hat (im Paket zu präzisieren). Integrationstests je Modus und Fehlerstelle, auch für Abbruch, Reaper und Sweep. Bereits gefärbte VMs nur über einen eigenen, belegten Reparaturauftrag, wie im Staffelplan festgelegt. |
| FC2-02 | P2 | `lib/deploy_worker_create_poll.php` (`deploy_worker_create_finish_unit`), `lib/repo/deploy_create_identity.php` (`repo_deploy_create_commit_success`) | Meldet `createVMStatus` Erfolg und lehnt `repo_deploy_create_commit_success()` das Binden ab (`identity_result_invalid`), endet die Einheit `failed`, obwohl auf ESXi eine VM angelegt oder geändert wurde. MOID und Instance-UUID aus dem Marker werden nicht gespeichert, `error_detail` ist ein allgemeiner Satz, danach löscht `createVMCleanup` die Async-Statusdatei, und der Marker steht nur Base64-kodiert im Auftragsprotokoll. Welche VM auf dem Host entstanden ist, lässt sich danach nicht mehr zuordnen. | Live-MOID und -UUID in `error_detail` und in eine eigene Protokollzeile schreiben, mit dem Hinweis, die VM im ESXi Host Client zu prüfen und zu übernehmen oder zu löschen. Test: Ein abgelehnter Commit behält beide Werte. |
| FC2-03 | P2 | `lib/deploy_worker_finish.php` (`deploy_worker_conclude_sequence`), `lib/deploy_worker_create.php` (`deploy_worker_conclude_create_section`), `lib/deploy_log_create_release.php` | Der Inventarabruf nach `create` oder `full` wird nur eingereiht, wenn der Auftrag `succeeded` oder `partial` endet, auf dem Sequenzweg auch nach bestätigtem Abbruch. Gescheiterte oder abgebrochene Aufträge, die schon VMs angelegt haben, lösen keinen aus: etwa `full` mit erfolgreichem Create und gescheitertem Power-Cycle, ein Abbruch nach einigen VMs oder ein Auftrag, dessen erste Einheit `uncertain` wurde. Die Freigabe einer `uncertain`-Einheit verlangt aber einen Abruf, der neuer ist als Auftrag und Einheit; das Freigabe-Panel nennt diese Sperre, bietet aber weder Link noch Knopf. Bei Intervall 0 kommt der Abruf nie von selbst. | Abruf einreihen, sobald die Create-Phase eine Einheit begonnen hat, unabhängig vom Endzustand. Im Freigabe-Panel ein Link auf den Inventarabruf des Zugangs, wie ihn `deploy_queue_blockers()` mit `system_status_url()` schon baut. |

### Fehlerlogging und Bedienkomfort

| ID | Prio | Stelle | Befund | Maßnahme |
|---|---|---|---|---|
| FC2-04 | P2 | `lib/ansible_command_create.php` (`ansible_create_control_command`), `lib/deploy_worker_create_poll.php`, `lib/deploy_worker_stream.php` | Jede Statusabfrage einer laufenden Einheit (Takt `VIRTUSPHERE_CREATE_POLL_INTERVAL_SECONDS`) ist ein vollständiger `ansible-playbook`-Lauf mit Standard-Callback. `Ansible/createVMStatus-ESXi_playbook.yml` hat rund 30 Tasks; gedruckt werden auch alle `skipping`-Zeilen, und jede Zeile wird eine Zeile in `deploy_job_logs`, dazu je Abfrage eine Zeile `POLL … running`. Bei einem Auftrag mit 15 VMs und langer Anlage sind das geschätzt mehrere zehntausend Zeilen. Logansicht und Suche liefern höchstens `VIRTUSPHERE_DEPLOY_LOG_QUERY_LIMIT_MAX` Zeilen je Abfrage; die eigentliche Fehlerzeile ist darin schwer zu finden. Eine `ansible.cfg` oder Callback-Einstellung gibt es nicht. | Entscheid FC2-E2. Vorschlag: für Steueraufrufe `ANSIBLE_DISPLAY_SKIPPED_HOSTS=false` und `ANSIBLE_DISPLAY_OK_HOSTS=false`, außer bei ausführlicher Ausgabe; volle Ausgabe beim Endergebnis oder Fehler der Einheit; die `POLL`-Zeile mit Laufzeit und Restbudget und nicht bei jeder Abfrage. Vorher an einem echten Auftragsprotokoll messen. |
| FC2-05 | P3 | `lib/deploy_worker_mission.php` (Schrittfehler), `lib/ansible_command_modes.php` (`ansible_step_failure_suffix`) | Ein gescheiterter Playbook-Schritt endet mit „Ansible command failed with exit code N (playbook step: X)“. Der gescheiterte Task und seine Meldung bleiben im Protokoll, auch dort, wo das Playbook eine klare Ursache schreibt (Power-Cycle: „fail Powercycle <VM> (<UUID>) … Zustand dieser UUID im ESXi Host Client prüfen“). | Die letzte `fatal:`- oder `FAILED!`-Zeile (Task und Meldung, begrenzt und geschwärzt) als Detail des Endgrunds speichern und in der Auftragsansicht mit Sprung zur Protokollstelle zeigen. |
| FC2-06 | P3 | `lib/deploy_blockers.php` (Sperre `active_job`), `lang/de/deploy.php` und `lang/en/deploy.php` (`err_active_job`) | Die Sperre „Für diese Mission läuft oder wartet bereits ein Bereitstellungsauftrag“ nennt weder Auftrag noch Modus noch geplanten Start und hat keinen Link. Ein geplanter Auftrag sperrt die Mission bis zu `VIRTUSPHERE_DEPLOY_SCHEDULE_HORIZON_DAYS`. | Auftragsnummer, Modus und geplanten Start nennen; Link auf Protokoll und Abbruch. |
| FC2-07 | P3 | `lib/deploy_view_model.php` | Die Deploy-Seite zeigt den Volltest des gewählten Ansible-Zugangs nicht. Ein roter oder veralteter Test fällt erst am Host-Preflight des Auftrags auf, bei einem geplanten Auftrag unter Umständen Tage später. | Hinweis, keine Sperre: Zustand und Alter des Volltests mit Link auf **Jetzt vollständig testen**, aus derselben Funktion wie die Systemstatus-Karte. |
| FC2-08 | P3 | `lib/deploy_worker_create_unit.php` (`deploy_worker_create_terminate_unit`) | `error_detail` der Create-Einheiten wird nur mit den allgemeinen Mustern geschwärzt (`deploy_worker_redact_secrets()` mit leerer Geheimnisliste). Transport- und Protokollfehler sind vorher schon gegen beide Zugangsgeheimnisse geschwärzt, die Fehlertexte aus dem Marker (vom Ansible-Host, bis 1024 Zeichen) nicht. Das Auftragsprotokoll schwärzt jede Zeile gegen beide. | Die Geheimnisse aus dem Kontext übergeben; Test mit einem Geheimnis im Fehlertext des Markers. |

### Drift zwischen Diagramm und Code

| ID | Prio | Stelle | Befund | Maßnahme |
|---|---|---|---|---|
| FC2-09 | P3 | `deploy-flows.md`, Diagramm „Create je VM“; `lib/deploy_worker_create_launch.php`, `lib/deploy_worker_create_unit.php` | Prepare zeigt nur `rejected` und `prepared`. Es fehlen `transport_lost` (Einheit `failed`, die nächste VM folgt) und `protocol_error` (Einheit `failed`, die Create-Phase stoppt, weil der Code in `VIRTUSPHERE_CREATE_GLOBAL_STOP_ERROR_CODES` steht). Bei `verify_skip` stimmt „keine Antwort → failed → nächste Einheit“ nur für `transport_lost`; `protocol_error` stoppt. Ein Launch ohne oder mit ungültigem Marker oder mit ungültiger Job-ID endet `uncertain`; der Pfeil fehlt. Das Budget wird auch vor Prepare geprüft, nicht nur zwischen Prepare und Launch. Der Knoten nennt „alle 30 Sekunden“, der Fließtext die Konstante. | Mit FC3 nachziehen. |
| FC2-10 | P3 | `deploy-flows.md`, Diagramm „Worker: ein Auftrag“; `lib/deploy_worker_mission.php`, `lib/deploy_worker_finish.php` | Die Fehlerendknoten (Collection-Sperre, Host-Preflight, freie Lizenz, HA-Host im Modus `autostart`, Schrittfehler, Abbruch) zeigen nicht, was mit den VMs geschieht (FC2-01), und nicht das Aufräumen der Arbeitsverzeichnisse, das im `finally` auf jedem Weg läuft. Kasten Z nennt den Inventarabruf nur für `succeeded` oder `partial`; auf dem Sequenzweg folgt er auch einem bestätigten Abbruch (FC2-03). | Mit FC3 nachziehen, nach Entscheid FC2-E1. |
| FC2-11 | P3 | `system-status-checks.md`, Diagramm MECM, Zweig „started“; `lib/status.php` (`virtusphere_run_running_state`) | Laut Diagramm wird ein offener Lauf über der Gefahrschwelle rot. Der Code bewertet erst nach dem Maximum aus Warnschwelle und `VIRTUSPHERE_RUN_GRACE_SECONDS`; erst dann entscheidet die Gefahrschwelle zwischen Gelb und Rot. Gelb ist nur erreichbar, wenn das Zehnfache des Intervalls diese Laufschonfrist übersteigt, mit den Standardtakten von Devices Sync (10 s), Packages Sync und Autoimporter (je 60 s) also nie. Ein hängender Devices-Sync-Lauf zeigt bis zum Ende der Laufschonfrist „Lauf offen“ in der Farbe des vorigen Ergebnisses und springt dann direkt auf Rot. | Entscheid FC2-E3: nur das Diagramm korrigieren oder eine Laufschonfrist je Aufgabe einführen. |
| FC2-12 | P3 | `deploy-flows.md`, Absatz unter „Modi auf einen Blick“; `lib/deploy_constants.php`, `lib/deploy_worker_create.php` | Der Absatz nennt die Staffelung als aus `ansible_playbooks_for_mode()` abgeleitet. MAC-Erwartung und Create-Zeilen sind abgeleitet (`ansible_mode_expects_mac_result()`, `ansible_mode_creates_vms()`), die Staffelung nicht: `VIRTUSPHERE_DEPLOY_STAGGER_MODES` ist eine feste Liste, ebenso `VIRTUSPHERE_DEPLOY_INVENTORY_REFRESH_MODES`, und `deploy_worker_conclude_create_section()` vergleicht mit dem Literal `'create'`. Heute stimmen die Listen mit den Playbook-Folgen überein; kein Test prüft das. | Aus der Playbook-Folge ableiten (Modi, die VMs einschalten, und Modi mit Create) oder den Absatz korrigieren; zusammen mit DF-S1 und DF-S2. |
| FC2-13 | P3 | alle drei Ablaufdokumente; geplanter FC1-Wächter | In den Diagrammen fehlen Werte geschlossener Codelisten: die Create-Codes `transport_lost`, `launch_unconfirmed` und `operator_released` sowie die Endgründe `stale_heartbeat`, `timeout`, `execution_failed` und `partial_result`. Ein Wächter, der nur Abschnitte je Playbook und Zeilen je Modus verlangt, hätte FC2-09 und FC2-10 nicht gefunden. | FC1 um einen Vokabular-Abgleich erweitern: Codelisten aus den Konstanten ableiten (`VIRTUSPHERE_CREATE_ERROR_CODES`, Schlüssel von `VIRTUSPHERE_DEPLOY_TERMINAL_REASON_STATUSES`, Ampelzustände) und jeden Wert im zuständigen Diagramm oder in einer begründeten Ausnahmeliste des Dokuments verlangen. |

### Doku und Hilfe

- Die Fehlerendknoten der Diagramme „Worker: ein Auftrag“ und „Create je VM“ sollen den VM-Zustand danach nennen; der Inhalt folgt aus FC2-E1.
- Die Portalhilfe verweist für die Abläufe auf `docs/operations` im Projektordner auf dem Server (`deploy_mode_p2`, `system_status_fix_4`); im Portal selbst sind die Diagramme nicht erreichbar (FC2-E5).

### Offene Entscheidungen (Nutzer)

| ID | Frage | Vorschlag |
|---|---|---|
| FC2-E1 | Welchen Zustand behält eine VM nach einem gescheiterten oder abgebrochenen Auftrag, der sie nicht angefasst hat (FC2-01)? Werden bereits gefärbte VMs repariert? Kommt der Fix vor FC1 bis FC3 oder mit Staffelplan P5? | Wie unter FC2-01; Reparatur nur als eigener, belegter Auftrag. Reihenfolge offen. |
| FC2-E2 | Ausgabe der Statusabfragen reduzieren oder vollständig als Nachweis behalten (FC2-04)? | Reduzieren; volle Ausgabe bei ausführlicher Ausgabe und am Endergebnis. |
| FC2-E3 | „Lauf offen“ (FC2-11): nur das Diagramm korrigieren oder eine Laufschonfrist je Aufgabe einführen, etwa kürzer für Devices Sync als für den Autoimporter? | offen |
| FC2-E4 | Die vorgeschlagenen Flowchart-Regeln des Registers übernehmen (`accTitle`, Ergebnisklassen per `classDef`, Fehlerpfade immer zeichnen, große Diagramme teilen), dazu den Vokabular-Abgleich aus FC2-13? | Übernehmen. |
| FC2-E5 | Diagramme in der Portalhilfe zeigen, mit lokal ausgeliefertem Mermaid oder vorgerenderten SVGs, oder nur im Repo führen? | offen |
| FC2-E6 | `full`: statt fester `StartWaitSeconds` warten, bis Devices Sync alle VMs des Auftrags als `registered` gemeldet hat, mit der Wartezeit als Obergrenze? `registered` belegt gesetzte Mitgliedschaften; die Collection-Auswertung in MECM kann danach noch dauern. Der Staffelplan schließt nur ein „MECM bereit“ aus einem abgelaufenen Timer aus. | offen |

### Einordnung

- FC2-09 bis FC2-13 gehen in FC1 (Vokabular-Abgleich) und FC3 (Diagramme und Doku); die Fehlerendknoten aus FC2-10 erst nach FC2-E1.
- FC2-01 ist der einzige P1-Befund; Paket und Reihenfolge nach FC2-E1.
- FC2-02 ändert Create- und Identitätsverhalten und passt zu DF-P4 und IDR-P06. FC2-03 und FC2-08 sind kleine Worker-Fixes. FC2-04 bis FC2-07 bilden ein Bedienpaket nach FC2-E2.
- Der nicht Knoten für Knoten geprüfte Rest von FC2 (siehe Prüftiefe) folgt vor FC3.

**Nachtrag:** FC2-03 verletzt die Regel R11 in `docs/ai/reference/portal.md` („A message that names a prerequisite, an instruction or another page carries the link to it“): Das Freigabe-Panel fordert einen Inventarabruf ohne Link. FC2-06 nennt eine Voraussetzung ohne Link und verletzt R11 nach dieser Lesart ebenfalls. Beide sind damit Vertragsabweichungen, nicht nur Bedienkomfort.

## Abdeckung der Abläufe und Prüfinstrumente (03.10.2026)

Fragen des Nutzers: Welche Abläufe im Portal und zwischen Portal, MECM, Ubuntu-Host und ESXi hat kein Flowchart, und welche Dokumente oder Instrumente fehlen, um die Befunde zu verifizieren und weitere Lücken aufzudecken? Quelle ist ein Inventar aus dem Code am Stand `3b37025`: Portal-Aktionen aus den Action-Handlern, Endpunkte der Maschinen-API mit ihren `action`-Zweigen, Wartungsjobs, Playbooks und PowerShell-Skripte, abgeglichen mit den drei Ablaufdokumenten und der FC4-Planung im [Register](2026-09-12-consolidated-session-backlog.md). Nur Codelesung. Nutzerentscheid 03.10.2026: keine Codeänderung, nur Aufnahme.

Gezeichnet sind heute die Bereitstellung, der Systemstatus und die vier MECM-Serveraufgaben. Clientphasen, Paketwrapper und Reporter, die Serverseite der Maschinen-API mit Rollout-Sperre, der VM-Lebenszyklus, Auftragszustände mit Wiederherstellung und Supervisor sowie der Wartungsdienst sind als FC4 geplant; MAC-Rückruf, Missionstransfer und Anmeldung als P2, Backup/Restore, HTTPS, QA-Lanes und Durable Runner als P3, die MECM-Installer nach dem Cutover. Die Lücken unten stehen in keiner dieser Planungen.

### Lücken außerhalb jeder Planung

| ID | Prio | Bereich | Befund | Vorschlag |
|---|---|---|---|---|
| AB-01 | P1-Kandidat | Portal → Ubuntu-Host (SSH, SFTP) | Kein Abgleich des Host-Schlüssels. `lib/ssh.php` (Zugangstest und `ssh_execute_command()`) und `lib/ssh_sftp.php` bauen `phpseclib3`-Verbindungen auf und melden sich mit Passwort an, ohne den Server-Schlüssel (`getServerPublicHostKey()`) gegen einen gespeicherten Wert zu prüfen. Darüber gehen das Passwort des Ansible-Zugangs und per SFTP `accounts.yml` mit den ESXi-Zugangsdaten. Jeder andere Abschnitt hat einen Vertrauensanker: ESXi `strict`, Zertifikats-Pin des MAC-Rückrufs, Zertifikats-Pin der MECM-Skripte, CA bei LDAPS. Weder ADR noch Doku behandeln den Punkt. | Entscheid AB-E1. Vorschlag: Host-Schlüssel je Ansible-Zugang anheften wie den ESXi-Strict-Modus: Der Zugangstest zeigt den Fingerprint, der Admin bestätigt ihn, jede SSH- und SFTP-Verbindung prüft ihn. Probe vorab: den Zugang auf einen Host mit anderem Schlüssel zeigen lassen (PI-09). |
| AB-02 | P2 | Gesamtkette | Keine Übersicht über alle Systeme. Portal, Datenbank, Worker, Ubuntu-Host, ESXi, MAC-Rückruf, Devices Sync, PXE und WDS, Task Sequence, Clientphasen und Paketbericht stehen nur als Tabelle „Kette im Überblick“ in `deploy-chain.md`. | Sequenzdiagramm mit Kanälen, Authentisierung beider Seiten, Zeitbudgets und Rückkanälen als Einstieg in alle Ablaufdokumente; dazu eine Übersicht des Paketwegs vom Autoimporter über die Zuweisung bis zum Paketbericht und zur Bereinigung. |
| AB-03 | P2 | ESXi-Inventar, Worker-Seite | Gezeichnet ist nur das Playbook. Fälligkeit, Fehlereinordnung, Pause bei Anmeldefehlern und Fortsetzen beim Speichern des Zugangs, Cache-Regeln je Art (geleert oder behalten), Host-Eigenschaften, VLAN-Katalog (zurückziehen, wieder aktivieren) und Datacenter-Auflösung stehen nur als Text in `esxi-inventory.md`. | Abschnitt mit Diagramm, der den toten Verweis „Abschnitt ESXi-Inventar“ aus FC3 ersetzt. |
| AB-04 | P2 | Zugänge und Vertrauensmodus | Ohne Diagramm: ESXi `legacy_insecure` → Probe per Inventarabruf → `activate_strict` oder `use_legacy`, die Zugangstests für ESXi und Ansible, Token und IP-Freigabe der Maschinen-API, die API-Basis-URL mit ihren Verbrauchern (MAC-Rückruf, Portal-Erreichbarkeit im Preflight). | Diagramm „Zugänge und Vertrauen“, zusammen mit AB-01 und PI-03. |
| AB-05 | P2 | Portal-Aktionen mit Wirkung auf MECM oder ESXi | „MECM-ID zurücksetzen“ einzeln und gesammelt (Revision, Tombstone, Hostname-Reservierung, Ablehnungsgründe aus `VIRTUSPHERE_MECM_RESET_BLOCKERS`), „An MECM übertragen“, „Identität übernehmen“, Vorlage speichern und klonen, „Beobachtung neu starten“. Die geplante `vm-lifecycle.md` zeigt Zustände, nicht Auslöser und Ablehnungen. | Je Aktion Vorbedingung, Ablehnungsgrund, Wirkung und nächster MECM-Schritt, in `vm-lifecycle.md` oder `machine-api-flows.md`. |
| AB-06 | P2 | VM löschen und außer Betrieb nehmen | Das Portal löscht nur seinen Datensatz (`repo_delete_vm_by_id()`); VM auf ESXi und Gerät in MECM bleiben. „VM außer Betrieb nehmen“ in `mecm-integration.md` nennt nur die MECM-Schritte, nicht ESXi. Die Einzel-Löschabfrage (`confirm_delete` in `lang/de/vms.php`) sagt nicht, was die Sammelabfrage sagt: dass auf dem Hypervisor nichts gelöscht wird. Spätere Folge: Identitätssperre durch die verbliebene namensgleiche VM oder MAC-Konflikt im Devices Sync. | Ablauf über Portal, ESXi und MECM zeichnen; Doku um den ESXi-Schritt ergänzen; Einzelabfrage an die Sammelabfrage angleichen. |
| AB-07 | P3 | Auftrag wiederholen und freigeben | Retry-Regeln (Einheiten mit `verify_skip`, Teilwiederholung nach MAC-Teilergebnis, Sperre `retry_create_unresolved`), Freigabe von `uncertain`-Einheiten, erneutes Aufräumen auf dem Ubuntu-Host und Prüfdokument. FC3 und FC4 nennen das nur pauschal als Wiederherstellung. | Eigenes Diagramm im FC4-Abschnitt zu Auftragszuständen. |
| AB-08 | P3 | Upgrade und Migration | `upgrade-recovery.md` (Releaseidentität, zulässige Kombinationen, Upgradefolge, Unterbrechung) fehlt in der P3-Liste. | In P3 aufnehmen. |
| AB-09 | P3 | Fehlersuche | `troubleshooting.md` hat eine Symptomtabelle, aber keinen Weg vom sichtbaren Zustand (Fortschrittsstufe, Ampel, Auftragsstatus) zum zuständigen Diagramm und Nachweis. | Entscheidungsbaum „Wo hängt meine VM?“ nach FC4. |

Ohne eigenes Diagramm ausreichend: reine Verwaltungsseiten (Pakete, Betriebssysteme, VLAN-Liste, Benutzer anlegen), Dashboard, Hilfe, das einmalige `retire-VirtuSphere-LegacyGetInfo.ps1` und die Runbooks für Installation und MECM-Erstanbindung, die als nummerierte Schritte lesbar sind.

### Prüfinstrumente (Vorschlag, Nutzerentscheid offen)

Flowcharts zeigen den Steuerfluss einer Komponente. Die Befunde dieses Plans lagen dagegen in Zustandswirkungen auf Fehlerwegen (FC2-01), verlorenen Belegen (FC2-02), Vertrauen zwischen Systemen (AB-01), Zeitschwellen (FC2-11) und Meldungen (FC2-04 bis FC2-06). Dafür fehlen diese Instrumente:

| ID | Instrument | Prüft oder findet | Stand im Repo |
|---|---|---|---|
| PI-01 | Abdeckungsinventar aus dem Code | Leitet Portal-Aktionen, `action`-Zweige der Endpunkte, Wartungsjobs, Playbooks, Serveraufgaben, Clientphasen und Einstellungen aus dem Code ab und verlangt je Eintrag einen Abschnitt in einem Ablaufdokument oder eine begründete Ausnahme. Hätte AB-03 bis AB-07 gefunden. | FC1 plant das nur für Playbooks, Modi und Systemstatus-Bereiche; `MecmScheduledTasksDocContractTest` ist das Muster. |
| PI-02 | Zustands- und Schreibermatrix | Für `lifecycle_state`, `mecm_sync_state`, Auftragsstatus und Create-Einheiten: Ausgangszustand, Auslöser und Modus, schreibende Funktion, Sperre, Zielzustand. Ein Wächter verlangt jede schreibende Stelle in der Matrix. Prüft FC2-01 und findet weitere Schreiber mit zu großer Reichweite. | Fehlt; `docs/ai/reference/database.md` nennt Schreiber nur für einzelne Spalten, etwa die Hostname-Reservierung. |
| PI-03 | Verbindungs-, Vertrauens- und Geheimnismatrix | Je Verbindung Quelle, Ziel, Protokoll, Authentisierung von Server und Client, übertragene Geheimnisse, Anker oder Pin, Prüftest; je Geheimnis Speicherung, Übertragung, Dateien auf dem Host und Schwärzung. Dazu ein Test, der einen bekannten Testwert durch alle Senken schickt und in keiner Tabelle, keinem Log und keinem Ergebnis wiederfinden darf. Hätte AB-01 gefunden; prüft FC2-08. | Fehlt; kein Bedrohungsmodell. Einzelne Schwärzungen sind getestet (`LogRedactionTest`), ein senkenübergreifender Test wurde nicht gefunden. |
| PI-04 | Zeitbudget- und Schwellenmatrix mit Invarianten | Alle Takte, Budgets und Schwellen über alle Systeme (SSH-Leerlauf und -Gesamtbudget, Create-Budget, Abfragetakt, Heartbeats, Reaper, Laufschonfrist, Ampelfaktoren, MECM-Takte, Rückruf-Timeouts) und daraus prüfbare Ungleichungen, etwa „jeder Ampelzustand ist mit Standardwerten erreichbar“ oder „keine Pause im Playbook überschreitet das SSH-Leerlaufbudget“. Prüft FC2-11 und S07 aus dem [Staffelaudit](2026-09-11-full-pipeline-start-stagger-audit.md). | Teilstücke in `deploy-chain.md` und `system-status-checks.md`. |
| PI-05 | Fehlermodus-Tabelle je Übergang mit Testbezug | Die Edge-Case-Checkliste des Registers als Tabelle: Fehlermodus, Erkennung, Zustand danach (VM, Auftrag, MECM), Signal für den Operator, Test. Diagrammkanten tragen IDs, Tests nennen sie, ein Wächter meldet Kanten ohne Test. Leere Zellen sind Lücken; der Fehlerweg von `start` aus FC2-01 wäre eine gewesen. | Checkliste vorhanden, Tabelle und Kanten-IDs fehlen. |
| PI-06 | Gezielter Mutationslauf | Infection auf `lib/deploy_worker_*.php`, `lib/status.php` und `lib/status_evidence.php`, für die Serverskripte wie in S6 mit Pester. Zeigt Entscheidungen ohne verhaltensprüfenden Test. | Infection ist konfiguriert (`Docker/WebAPI/infection.json5.dist`), laut `docs/QA.md` aber nie als Baseline gelaufen; braucht einen Entwicklungsrechner mit Coverage-Treiber. |
| PI-07 | ESXi-Stellvertreter für Ende-zu-Ende-Läufe | vSphere-Simulator (`vcsim` aus govmomi) als lokales Image im QA-Stack oder ein Labor-ESXi: Create, Power-Cycle, Export, Start und Inventar laufen offline durch; misst FC2-04 und prüft FC2-01 bis FC2-03 im ganzen Ablauf. Grenze: Der Simulator bildet Laufzeiten, Async-Verhalten und Plattenformate nicht echt ab. | Fehlt; `docs/QA.md` führt Läufe gegen echtes ESXi als nicht durchgeführt. `Docker/qa-ansible` prüft Module und Async-Verträge ohne ESXi. |
| PI-08 | Ist-Pfad-Rekonstruktion | Lesende Abfrage oder Seite, die für eine VM oder Korrelations-ID alle Spuren zeitlich ordnet: Auftrag, Protokoll, Create-Einheiten, Statusereignisse, Audit, Meldungen der Maschinen-API, Clientphasen, Paketberichte. Legt einen echten Lauf neben das Diagramm. | Teilweise: Korrelationspanel der Logseite (Audit und Aufträge) und Statushistorie je VM. |
| PI-09 | Laborprobenkatalog für ESXi, Ubuntu-Host und Portal | Probe je Befund mit Befehl und erwartetem Ergebnis: SSH-Zugang auf Host mit anderem Schlüssel (AB-01), Zeilenzahl eines echten Create-Auftrags je Stream aus `deploy_job_logs` (FC2-04), `start`-Auftrag mit absichtlich falscher Ansible-Anmeldung nur auf einer Testmission (FC2-01), Portal-Löschung einer ausgerollten Test-VM mit erneutem Anlegen (AB-06). | MC07-Proben im MECM-Plan vorhanden; ESXi-, Ubuntu- und Portalproben fehlen. |
| PI-10 | Konfigurationsmatrix | Je Einstellung Verbraucher, Zeitpunkt der Wirkung (sofort, nächster Auftrag, nächster Lauf der MECM-Aufgabe), Prüfung und Hilfe. Findet Querwirkungen wie die der API-Basis-URL auf MAC-Rückruf und Preflight. | Fehlt. |

### Offene Entscheidungen (Nutzer)

| ID | Frage | Vorschlag |
|---|---|---|
| AB-E1 | Wie wird AB-01 behandelt, und kommt es vor FC1 bis FC4? | Host-Schlüssel anheften wie oben; als Sicherheitsbefund vorziehen. |
| AB-E2 | Welche von AB-02 bis AB-09 kommen zu FC4? | Alle; AB-02 zuerst als Einstieg. |
| AB-E3 | Welche Instrumente in welcher Reihenfolge? | PI-01, PI-02, PI-03 und PI-09 vor dem Zeichnen von FC4; PI-04 mit FC3; PI-05, PI-06 und PI-08 danach; PI-07 erst nach Aufwandsschätzung für ein Air-Gap-taugliches Image; PI-10 bei Bedarf. |

## Darstellungsarten und Doku-Struktur (03.10.2026)

Online-Recherche auf Wunsch des Nutzers: Wie sinnvoll sind Flowcharts, welche weiteren Dokumentationsarten machen Funktionen und Code verständlich, was muss noch abgedeckt werden, und wie bleibt das übersichtlich? Keine Codeänderung.

### Entscheidungen des Nutzers (03.10.2026)

| ID | Frage | Entscheidung |
|---|---|---|
| DA-E1 | Für wen sind die Ablaufdokumente zuerst? | Zuerst für die Entwickler, danach für die Admins im Betrieb. |
| DA-E2 | Weitere Darstellungsarten neben Flowcharts? | Vorläufig ja: Zustandsdiagramme, Sequenzdiagramme und Entscheidungstabellen, unter denselben Regeln wie Flowcharts (Wächter, Ansichtsseite, `accTitle`). Die Einzelregeln aus dem Register (FC2-E4) folgen mit DS-E1. |
| DA-E3 | Kontextbild der Systeme | Als normales Mermaid-Flowchart mit Gruppen, nicht in der experimentellen C4-Syntax. |
| DA-E4 | Aufnahme | Nur als Doku in Plan und Register, ohne Code. |

### Ergebnis der Recherche

- Flowcharts helfen bei verzweigter Logik: Scanlan (1989) maß schnelleres Verstehen und weniger Fehler als mit Pseudocode. Auf Codeebene helfen sie nicht: Shneiderman (1977) fand keinen Nutzen detaillierter Flowcharts, Ramsey und Kollegen (1978) sahen Pseudocode vorn, weil er näher am Code liegt.
- Diagramme verbessern bei komplexen Änderungen die Korrektheit, sparen aber keine Zeit, und ihre Pflege kostet (Arisholm und Kollegen 2006; „Viskosität“ nach Green und Petre). Veraltete, verstreute und mehrdeutige Doku gehört zu den häufigsten Dokuproblemen (Aghajani und Kollegen 2019).
- Gestaltung nach Moody (2009): ein Symbol je Begriff, Text und Grafik zusammen, Darstellung passend zum Leser. Screenreader lesen Mermaid ohne `accTitle`, `accDescr` und begleitenden Text als Wortsalat.
- Für andere Fragen gibt es passendere Arten: Zustandsdiagramme (daraus lassen sich Tests mit Übergangsabdeckung ableiten), Sequenzdiagramme (arc42 „Runtime View“, C4 „Dynamic“), Entscheidungstabellen (Vollständigkeit und Erreichbarkeit prüfbar, DMN), Swimlanes für Übergaben, Datenfluss mit Vertrauensgrenzen für Sicherheit (STRIDE), ein Kontextbild nach C4. Diátaxis ordnet Doku nach Lesebedarf; Google SRE beziffert für Playbooks je Alarm etwa dreimal schnellere Behebung.
- Quellen: [Shneiderman](https://en.wikipedia.org/wiki/Ben_Shneiderman), [Ramsey und Kollegen](https://journals.sagepub.com/doi/abs/10.1177/1071181378022001186), [Scanlan](https://modeling-languages.com/structured-flowcharts-outperform-pseudocode/), [Arisholm und Kollegen](https://www.semanticscholar.org/paper/The-impact-of-UML-documentation-on-software-an-Arisholm-Briand/a6d9ae2f374025928c003b37e12b24bd905732c5), [Green und Petre](https://en.wikipedia.org/wiki/Cognitive_dimensions_of_notations), [Aghajani und Kollegen](https://www.semanticscholar.org/paper/Software-Documentation-Issues-Unveiled-Aghajani-Nagy/55b6029411225a6eee4f88b3fc4bf3869e667e01), [Moody](https://research.utwente.nl/en/publications/the-physics-of-notations-toward-a-scientific-basis-for-constructi), [Mermaid und Screenreader](https://pulibrary.github.io/2023-03-29-accessible-mermaid), [C4](https://c4model.com/diagrams), [Mermaid C4](https://mermaid.js.org/syntax/c4.html), [arc42 Runtime View](https://docs.arc42.org/section-6/), [Diátaxis](https://diataxis.fr/), [Flowchart und Zustandsautomat](https://www.stateworks.com/technology/flowchart-is-not-state-machine/), [DMN-Entscheidungstabellen](https://arxiv.org/pdf/1603.07466), [Swimlane](https://en.wikipedia.org/wiki/Swimlane), [OWASP Threat Modeling](https://owasp.org/www-community/Threat_Modeling_Process), [Übergangsabdeckung](https://www.researchgate.net/publication/282711162_Coverage_Criteria_for_State_Transition_Testing_and_Model_Checker-Based_Test_Case_Generation), [Living Documentation](https://www.infoq.com/articles/book-review-living-documentation/), [Google SRE](https://sre.google/sre-book/introduction/).

### Darstellungsart je Frage

| Frage | Darstellung |
|---|---|
| Was passiert, wenn …? (Entscheidungen eines Akteurs) | Flowchart |
| In welchem Zustand ist etwas, und was darf von hier aus geschehen? | Zustandsdiagramm mit Übergangstabelle, die je Übergang den Schreiber nennt |
| Wer ruft wen in welcher Reihenfolge, mit welchem Zeitlimit? | Sequenzdiagramm |
| Welches Ergebnis bei welcher Kombination von Bedingungen? | Entscheidungstabelle |
| Wer, Mensch oder System, macht welchen Schritt? | Swimlane |
| Welche Verbindung trägt welches Geheimnis, mit welchem Vertrauensanker? | Datenfluss mit Vertrauensgrenzen |

### Strukturvorschlag gegen einen Doku-Dschungel (Nutzerentscheid DS-E1 offen)

| ID | Regel |
|---|---|
| DS-01 | Ein Einstieg: Übersichtsseite `docs/operations/flows.md` mit Kontextbild, Gesamtkette als Sequenz, Landkarte (Thema → Dokument → Abschnitt), gemeinsamer Legende, kurzem Begriffsverzeichnis und der Tabelle „Darstellungsart je Frage“. Sie ist der erste Tab der Ansichtsseite. |
| DS-02 | Nach Thema ordnen, nicht nach Darstellungsart: ein Dokument je Bereich (Dokumentkarte unten), darin jede Darstellungsart, die zur Frage passt. Ein neues Ablaufdokument nur mit Entscheidung, sonst ein Abschnitt in einem vorhandenen. |
| DS-03 | Ein Ort je Ablauf: Jeder Ablauf wird genau einmal gezeichnet; andere Dokumente verlinken den Abschnitt, statt ihn erneut zu beschreiben. Wo ein Diagramm Fließtext ersetzt, wird der Text gekürzt. |
| DS-04 | Ein Gerüst je Abschnitt: Zweck in einem Satz, Diagramm, „Wenn etwas schiefgeht“ (Fehlerwege mit dem Zustand von VM, Auftrag und MECM danach), „Im Code“ (Einstiegsfunktionen und Konstanten), „Prüfung“ (Tests). In der Admin-Phase kommt „Betrieb“ hinzu, als Link auf Hilfe oder Runbook. |
| DS-05 | Kleine Diagramme: eine feste Obergrenze für Knoten je Diagramm; größere in verlinkte Teilabläufe teilen. Kurze Knotentexte, Erklärung im Text. |
| DS-06 | Eine Optik: Formen und Ergebnisklassen (`classDef`) nur aus der gemeinsamen Legende; `accTitle` und `accDescr` in jedem Diagramm. |
| DS-07 | Ist und Plan getrennt: Ablaufdokumente zeigen nur Ausgeliefertes; Befunde und Pläne bleiben unter `docs/audits/`. |
| DS-08 | Die Maschine hält Ordnung: Der FC1-Wächter, erweitert um PI-01 und FC2-13, prüft Abdeckung oder begründete Ausnahme, Obergrenze, Legende, `accTitle`, Gerüst und Links. |
| DS-09 | Bewusst weglassen: Die Übersichtsseite nennt, was kein Diagramm bekommt (reine Verwaltungsseiten, Dashboard, Hilfe, einmalige Skripte, nummerierte Runbooks). |

**Dokumentkarte (Vorschlag):**

| Dokument | Inhalt | Darstellungsarten |
|---|---|---|
| `flows.md` (neu) | Einstieg: Kontextbild (DA-E3), Gesamtkette (AB-02), Landkarte, Legende, Begriffe | Flowchart mit Gruppen, Sequenz |
| `deploy-flows.md` | Einreihen, Worker, Create, Playbooks; dazu Auftrags- und Einheitenzustände, Wiederholen und Freigeben (AB-07), ESXi-Inventar auf Worker-Seite (AB-03), später der Durable Runner | Flowchart, Zustand, Sequenz |
| `system-status-checks.md` | Ampelregeln des Systemstatus | Entscheidungstabelle oder Flowchart (DA-E5) |
| `mecm-scheduled-tasks.md` | MECM-Serveraufgaben, nach dem Cutover die Installer | Flowchart |
| `client-flows.md` (FC4) | Clientphasen, Paketwrapper, Reporter | Flowchart, Sequenz |
| `machine-api-flows.md` (FC4) | Endpunkte, Rollout-Sperre, MAC-Rückruf (P2) | Flowchart, Sequenz, Entscheidungstabelle |
| `vm-lifecycle.md` (FC4) | VM-Zustände und die Aktionen, die sie ändern (AB-05), Löschen und Außerbetriebnahme (AB-06), Missionstransfer (P2) | Zustand mit Übergangstabelle, Swimlane |
| `maintenance-flows.md` (FC4) | Wartungsjobs | Flowchart |
| `trust-flows.md` (neu) | Verbindungen, Vertrauensanker und Geheimnisse (AB-01, PI-03), Zugänge und Vertrauensmodus (AB-04), Anmeldung und Rechte (P2) | Datenfluss mit Vertrauensgrenzen, Zustand, Matrix |
| vorhandene Runbooks | Diagramm am Ort statt neuer Dateien: `upgrade-recovery.md` (AB-08), `backup.md` und `https.md` (P3), `troubleshooting.md` mit Entscheidungsbaum (AB-09, Admin-Phase) | Flowchart, Swimlane |

### Offene Entscheidungen (Nutzer)

| ID | Frage | Vorschlag |
|---|---|---|
| DS-E1 | Strukturvorschlag DS-01 bis DS-09 mit Dokumentkarte übernehmen, und damit die Einzelregeln aus FC2-E4? | Übernehmen. |
| DA-E5 | Ampelregeln im Systemstatus als Entscheidungstabelle statt als Flowchart, oder beides? Eine Tabelle zeigt auf einen Blick, wenn ein Ergebnis nie eintreten kann; so wäre FC2-11 aufgefallen. | Tabelle als einzige Quelle; ein kurzes Flowchart nur dort, wo die Reihenfolge der Prüfungen selbst die Aussage ist. |

### Einordnung

Nach DA-E1 kommen zuerst PI-01 bis PI-03, die Einstiegsseite sowie Zustands- und Sequenzdiagramme; Entscheidungsbaum (AB-09), Swimlanes und Playbooks folgen in der Admin-Phase. Die Reihenfolge FC1, FC2, FC3, PS1 bis PS3 bleibt.

## Nächster Schritt

PR #2 ist zusammengeführt, die Verweise und der Registereintrag stehen (Register, 28.09.2026). Reihenfolge laut Register: FC1, FC2, FC3, danach PS1 bis PS3; DF-P0 geht in FC3 auf, DF-P1 bis DF-P4 wie unter „Einordnung in die Reihenfolge“. Für FC2 zuerst die Entscheide FC2-E1 bis FC2-E6 einholen und den offenen Rest prüfen, für die Abdeckung die Entscheide AB-E1 bis AB-E3, für Darstellung und Struktur DS-E1 und DA-E5; jede Umsetzung beginnt mit einem Rot-vor-Fix-Test.
