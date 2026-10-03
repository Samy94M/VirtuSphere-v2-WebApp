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

### Grundprüfung gegen den Code (FC2, 03.10.2026)

Alle Diagramme beider Dokumente erneut Knoten für Knoten gegen den ausgelieferten Code gelesen: Systemstatus gegen `lib/status*.php`, `lib/deploy_service_health.php`, `lib/esxi_inventory_display.php`, `lib/directory_status.php` und die Panel-Dateien; Worker und Create gegen `lib/deploy_worker_mission.php`, `deploy_worker_create*.php`, `deploy_worker_finish.php`, `lib/ssh.php` und `lib/ansible_command_preflight.php`; die Playbook-Abschnitte gegen `Ansible/*.yml` (Prüfung durch einen Sonnet-5.5-Agenten, jede Zeilenangabe hier nachgelesen). Keine Diagrammaussage war falsch im Sinne einer umgekehrten Regel. Ungenau oder lückenhaft war Folgendes, und das ist in denselben Dokumenten bereits korrigiert:

- Systemstatus: Bereichsnamen jetzt gleich den Kachelbeschriftungen der Seite (Bereitstellungsdienst, Ansible-Test, ESXi-Inventar, Interne Dienste, Active Directory); die beiden Anker-Verweise aus `esxi-inventory.md` und `ansible-full-test.md` mitgezogen. Fehlende Zweige „Zeitstempel fehlt, unlesbar oder mehr als `VIRTUSPHERE_STATUS_EVIDENCE_FUTURE_SKEW_SECONDS` in der Zukunft ergibt Unbekannt“ für MECM-Sync, Site-Health und Ansible-Test ergänzt. Abkühlphase nur unter Supervisor. ESXi und Active Directory mit den echten Badge-Beschriftungen. Active Directory: sichtbar nur mit gespeicherter Konfiguration und Recht `users.manage`; „Letzter Test“ war falsch benannt, es zählt jede Beobachtung (Anmeldung, Sitzungsprüfung, Test); „alle veraltet“ ergibt gesamt Eingeschränkt; der unzugelassene Controller heißt „Nicht getestet“, nicht „Deaktiviert“.
- Bereitstellung: Modustabelle `inventory` mit der Portalbezeichnung „Inventar abrufen“ und allen drei Erzeugern. Toter Verweis „Abschnitt ESXi-Inventar“ im Worker-Diagramm ersetzt. Der Worker-Preflight prüft das Modul `vmware_guest` (der Volltest `vmware_host_auto_start`), und die IP-Freigabe sperrt dort nicht (DF-L8). Create je VM: Budgetprüfung schon vor Prepare, `verify_skip` mit allen vier Ausgängen, Prepare mit Transportabriss (weiter) und unlesbarer Antwort (Create-Phase stoppt), Launch mit unlesbarer Antwort oder ungültiger Job-ID (uncertain), Abfragetakt als Konstantenname statt „30 Sekunden“. Playbooks: Identitätsmatrix bricht bei Lesefehler ohne Ergebniszeile ab; createVMStatus nennt `async_state_missing` und `protocol_error` getrennt, der Zustand kommt aus der Statusdatei; Energiezustand nicht lesbar (DF-L9); powercycle: gescheitertes hartes Ausschalten bricht ab und kann eine VM eingeschaltet lassen; autostart: Stoppaktion und Heartbeat bleiben `systemDefault`.

Neue Befunde im Code:

| ID | Stelle | Befund | Maßnahme |
|---|---|---|---|
| DF-L6 | `lib/deploy_worker_finish.php` (`deploy_worker_conclude_sequence`), `lib/deploy_worker_create.php` (`deploy_worker_conclude_create_section`) | Der Inventarabruf nach einem Deploy hängt am Endstatus `succeeded` oder `partial`. Ein `full`-Auftrag, dessen Create-Phase VMs angelegt hat und der danach an Power-Cycle, Export oder Start scheitert oder abgebrochen wird, reiht keinen ein; ebenso eine Create-Phase ohne einzigen Erfolg (`deploy_worker_create_job_status()` ergibt dann `failed`), deren `uncertain`-Einheit ihre VM auf ESXi trotzdem angelegt haben kann. Bis zum nächsten Intervall (bei Intervall 0 nie) fehlen die neuen VMs im Cache: Identitätssperre, Abweichungen und Kapazitätsvorschau rechnen mit dem alten Stand. | Abruf in jedem Endzustand von `create` und `full` einreihen, sobald eine Create-Einheit den Launch erreicht hat (Job-ID gesetzt), unabhängig vom Endstatus. Fail-soft wie heute. Paket DF-P2. |
| DF-L7 | `lib/esxi_inventory_display.php` (`esxi_inventory_ampel`) | Liest `last_success_at` per `strtotime()` ohne die Zukunftsgrenze, die alle anderen Nachweise über `virtusphere_evidence_timestamp()` haben. Ein künftiger Zeitstempel (Uhrabweichung zwischen Datenbank und PHP) altert nie, die Kachel bleibt grün. | `virtusphere_evidence_timestamp()` nutzen; künftiger oder unlesbarer Zeitstempel ergibt keinen Erfolgsnachweis. Zusammen mit DF-L3 in derselben Funktion, Paket DF-P2. |
| DF-L8 | `lib/deploy_worker_mission.php` (Preflight, Kommentar „The portal/allowlist probes gate exactly the modes …“) | Für Modi mit MAC-Export läuft die Freigabeprobe mit, ihr Urteil wird aber nie gelesen; die Probe endet immer mit 0. Bei abgewiesener IP legt ein `full`-Auftrag alle VMs an, schaltet sie ein und aus und scheitert erst am Ende mit „no usable MAC import result“. Der Volltest meldet denselben Befund als „bestanden mit Einschränkung“. Der Kommentar behauptet eine Sperre, die es nicht gibt. | Entscheidung offen: (a) im Worker das Urteil auswerten und MAC-Modi vor dem Upload als `configuration_blocked` mit Verweis auf die Freigabeliste beenden, oder (b) nur eine Warnzeile und die Ursache in der Endmeldung. Empfehlung (a), weil der Ausgang vorher feststeht und nichts auf ESXi geändert werden muss, um ihn zu erfahren. Kommentar in jedem Fall korrigieren. |
| DF-L9 | `Ansible/createVMStatus-ESXi_playbook.yml` (Energiezustand per `vmware_guest_info`, ohne `ignore_errors`) | Nach nachgewiesen erfolgreichem Async-Job und bestandener Identitätsprüfung macht ein vorübergehender Lesefehler des Energiezustands die Einheit `uncertain` und stoppt die ganze Create-Phase. Der Energiezustand ist nur Information; das Feld hat bereits den Ersatzwert `unknown`. | Lesefehler tolerieren und `unknown` melden; Vertragstest der Create-Marker mitziehen. Paket DF-P4 (Create-Vertrag). |
| DF-D9 | Kommentar in `lang/de/status.php` und `lang/en/status.php` über `mode_inventory` | „nur der Scheduler erzeugt ihn“: Auch **Alle aktualisieren** und jeder `create`- oder `full`-Auftrag reihen ihn ein. | Kommentar korrigieren. Paket DF-P0. |

DF-D1 ist bestätigt: Der Kommentar in `Ansible/exportVMs-Informations-ESXi_playbook.yml` (Zeilen 31 bis 34) erlaubt eine leere gespeicherte UUID, der Code darunter verlangt eine.

DF-S4 ist mit diesem Stand geliefert: `DeployFlowsDocContractTest` leitet Modi, deutsche Portalbezeichnungen, Playbook-Reihenfolge je Modus (mit und ohne Autostart) und die Playbook-Dateien ab; `SystemStatusChecksDocContractTest` leitet die Bereiche aus den gerenderten Abschnitten, die Übersichtskacheln und deren deutsche Beschriftungen ab. Beide prüfen beide Richtungen, lassen keinen Nulltreffer durch und beweisen jede Regel mit einem Negativfall.

### Bereits geplant, hier nur verknüpft

- Modusnamen DE/EN und doppelter MAC-Export im Hilfetext von `full`: geliefert in `0dc5172`; `DeployModeTextTest` leitet seither jede Modusliste der Hilfe ab. DF-D2 und DF-D3 betrifft das nicht, sie beschreiben das Verhalten falsch, nicht den Namen.
- Suche per Name in Powercycle, Export, Start und Autostart, bei Namensdubletten sicherer Abbruch statt richtiger VM: [Identitätsplan](2026-09-14-vm-identity-replacement-plan.md), Abschnitt 16.2.
- Alle VMs starten nach einer gemeinsamen Pause gleichzeitig: [Staffelungsaudit](2026-09-11-full-pipeline-start-stagger-audit.md), P1 bis P7.

## Pakete

| Paket | Inhalt | Voraussetzung |
|---|---|---|
| DF-P0 | Drift und Aufräumen: DF-D1 bis DF-D7, DF-D9, DF-S1, DF-S2 (DF-S3 und DF-S4 sind erledigt) | keine |
| DF-P1 | Systemstatus: DF-L4 mit DF-E4 und DF-E5 | keine |
| DF-P2 | ESXi-Nachweisalter: DF-L3 mit DF-E3, dazu DF-L5, DF-L6, DF-L7 und DF-D8 | vor DF-P3, weil DF-P3 auf aktuelle Befunde angewiesen ist |
| DF-P3 | Freie Lizenz sperrt schreibende Modi: DF-L2 mit DF-E2 | DF-P2 |
| DF-P4 | Create auf laufender eigener VM nur prüfen: DF-L1 mit DF-E1, DF-D3; dazu DF-L9 | Create-Vertrag (Maschinenprotokoll der Create-Marker) unverändert lassen oder gemeinsam mit seinen Vertragstests ändern |
| DF-P5 | IP-Freigabe vor dem Upload auswerten: DF-L8 | Entscheidung des Nutzers zwischen (a) und (b); reine Portal-Arbeit, keine Installersperre |

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

## Nächster Schritt

PR #2 ist zusammengeführt, Verweise und Registereintrag sind gesetzt (`1c87571`), FC1 und FC2 sind geliefert (Abschnitt „Grundprüfung gegen den Code“). Offen: Entscheidung zu DF-L8 (DF-P5), dann DF-P0 bis DF-P5 wie unter „Einordnung in die Reihenfolge“; FC3 (übrige Diagrammkorrekturen aus dem PowerShell-Audit) und FC4 (neue Ablaufdokumente) laut Register.
