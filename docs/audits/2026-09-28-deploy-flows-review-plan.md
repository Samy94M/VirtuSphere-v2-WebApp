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

## Nächster Schritt

Pull Request des Branches `claude/practical-galileo-d8yx5g` zusammenführen, dann Verweise und Registereintrag setzen. Danach DF-P0 bis DF-P4 wie unter „Einordnung in die Reihenfolge“ abarbeiten.
