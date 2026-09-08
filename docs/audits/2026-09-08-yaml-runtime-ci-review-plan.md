# Prüfbericht und Änderungsplan: YAML, Ansible, CI und Portal

Stand: 08.09.2026. Referenzcommit: `8936e95a85307f860d83a6b2ea042a6c00a3a4c1`.
Status: geprüft und geplant, noch nicht umgesetzt. Diese Sitzung ändert ausschließlich diesen Prüfbericht. Produktcode, YAML-Konfiguration, aktive Betriebsdokumentation, Hilfetexte und Tests bleiben unverändert.

## 1. Ergebnis und Aussagekraft

Die YAML-Syntax ist am Referenzstand sauber. Die Funktionsfähigkeit ist trotzdem durch Fehler an den Übergängen zwischen Compose, PHP, Ansible und CI eingeschränkt. Der kritischste Datenfehler ist eine Statusabfrage, die einen fehlgeschlagenen Modulaufruf als Erfolg meldet und erfolgreiche Änderungen als unverändert transportiert. Ein zweiter Fehler verhindert im vorgesehenen Containerlayout bereits die Konstruktion des Ansible-Preflights.

Die Bestandsprüfung erfasste 89 vorhandene YAML-Dateien einschließlich ignorierter Altstände und Abhängigkeiten. Alle ließen sich mit PyYAML parsen. Der kanonische Runner erfasste 22 aktive Projektdateien; alle bestanden YAML-Lint. Die übrigen Dateien wurden damit nicht als aktuelle Deploymentkonfiguration oder unabhängig lauffähige Anwendung abgenommen. Insbesondere sind Workflowdateien unter `vendor/` keine aktiven GitHub-Workflows dieses Projekts.

Die folgenden 16 ausgewählten Gates bestanden in der vorangegangenen Prüfung dieser Sitzungskette: `compose-config`, `compose-hardening`, `enum-sync`, `php-version-sync`, `bounds-sync`, `doc-hygiene`, `doc-semantics`, `yaml-lint`, `actionlint`, `ansible-syntax`, `ansible-lint`, `ansible-module-contract`, `ansible-powercycle-selection`, `ansible-create-async`, `ansible-output-buffering`, `yaml-roundtrip`. Das sind ausgewählte Gates, keine vollständig ausgeführte Fast-, Integration- oder Release-Lane. Alle zehn aktiven `*_playbook.yml` bestanden die Syntaxprüfung. Basis-Compose plus QA-Override und Basis-Compose plus Supervisor-Override bestanden zusätzlich `config --quiet`.

Die vertiefte Nachprüfung benutzte `ansible-core 2.19.11`, `community.vmware 6.2.0`, `vmware.vmware 2.9.0` aus dem lokal vorhandenen QA-Image. Die PHP-Reproduktionen liefen im vorhandenen PHP-Projektimage mit den Compose-Mountpfaden, ohne Netz und ohne Datenbank. Die Playbook-Reproduktion lief in einem netzlosen Wegwerfcontainer mit synthetischen Async-Dateien und festen Antworten ausschließlich für die lesenden VMware-Module. Keine ESXi-Mutation, kein Zugang zu einem produktiven Host, keine Datenbankkorrektur und kein vollständiger GitHub-Lauf wurden ausgeführt.

Evidenzklassen: **R** = isoliert reproduziert; **K** = durch konkreten Kontrollfluss belegt; **E** = Erweiterung; **D** = vor Umsetzung zu entscheidende Vertrags- oder Infrastrukturfrage. P1 bezeichnet eine blockierte Kernfunktion oder falsch verbuchte Ausführung, P2 Zuverlässigkeit und Diagnose, P3 nachgelagerte Verbesserung. Die Zahlen sind historische Messwerte dieses Berichts, keine zukünftigen Test-Sollzahlen.

## 2. Bestätigte Befunde

### F01 · P1 · R/K · Versionsdatei am falschen Ort und falsche Fehlerursache

`docker-compose.yml` mountet `Ansible/` für PHP und Deploy-Worker nach `/var/www/ansible-src` und setzt `ANSIBLE_SOURCE_DIR` auf diesen Pfad. `ansible_pinned_collection_version()` in `Docker/WebAPI/lib/ansible_command_probes.php` benutzt stattdessen `dirname(__DIR__, 3) . '/Ansible/requirements.yml'`. Im Container ergibt das `/var/Ansible/requirements.yml`.

Reproduktion mit beiden tatsächlichen Mounts: `/var/www/ansible-src/requirements.yml` ist lesbar; `ansible_preflight_checks()` wirft dennoch `RuntimeException: The community.vmware pin could not be read from Ansible/requirements.yml.` Die Fehlersituation entsteht beim Bau des Kommandos, bevor dessen entfernte Ausführung beginnt. Betroffen sind Missionsjobs, Inventarjobs und der manuelle Ansible-Test, sobald dieser nach erfolgreicher SSH-Anmeldung seinen Preflight konstruiert.

Zusätzlich reproduziert: `ansible_connection_error_category()` klassifiziert genau diese untypisierte Exception als `ansible_transport`; eine `SshTransportConfigurationException` mit demselben Text als `config`. Der Inventarworker hat vor dem Kommandobau bereits die Phase `SSH` gesetzt. Ein lokaler Dateifehler wird dadurch als Problem des entfernten Transportwegs dargestellt.

Warum die bisherigen Tests das übersehen: `AnsiblePreflightTest` arbeitet im Repositorylayout, in dem die relative Elternpfadauflösung funktioniert. Er pinnt zusätzlich die konkrete Version als Literal. Die vorgesehene Container-Mountstruktur ist nicht Gegenstand dieses Tests.

### F02 · P1 · R/K · Async-Status überschreibt fachliche Ergebnisse

`Ansible/createVMStatus-ESXi_playbook.yml` registriert `vs_status` mit `failed_when: false` und `changed_when: false`. Später entscheidet das Playbook anhand genau dieser überschriebenen Felder über Modulfehler und schreibt `changed` in den Erfolgsmarker.

Die vertiefte Probe kopierte dieses Playbook und `create_identity_check_tasks.yml` bytegleich in ein temporäres Verzeichnis. Nur `vmware_vm_info` und `vmware_guest_info` wurden durch synthetische lesende Modulantworten ersetzt. Alle fünf Aufrufe endeten mit Exitcode 0:

| Synthetischer Async-Zustand | Tatsächlicher Marker | Bewertung |
|---|---|---|
| Erfolgreiche Neuanlage, `changed=true`, Live-Identität vorhanden | `succeeded`, `changed=false` | Änderungsinformation verloren |
| Erfolgreiche Änderung einer vorhandenen VM, `changed=true` | `succeeded`, `changed=false` | Wird als unverändert weitergereicht |
| Terminaler Modulfehler, vorhandene VM weiterhin lesbar | `succeeded`, `changed=false` | Fehler wird als Erfolg ausgegeben |
| Noch laufender Async-Job | `running` | Korrekt |
| Fehlende Async-Datei | `rejected`, `async_state_missing` | Korrekt |

Die Unterscheidung neu/vorhanden liegt im gespeicherten Prepare-Ergebnis des Workers; sie wird nicht als zusätzliche Status-Extra-Var übergeben. Die beiden Erfolgsfälle prüfen deshalb denselben Statuspfad mit anschließend unterschiedlichen PHP-Eingaben.

Die echte PHP-Funktion `deploy_create_outcome_for()` bestätigte: `(existed_before=false, changed=false)` wirft `DomainException`; `(true,false)` ergibt `unchanged`; `(false,true)` ergibt `created`; `(true,true)` ergibt `updated`. `repo_deploy_create_commit_success()` fängt den ersten Fall ab und liefert `identity_result_invalid`. Der Worker verbucht ihn als fehlgeschlagene Einheit und bereinigt anschließend die Async-Datei. Eine real entstandene neue VM kann dadurch ohne korrekt gebundene Identität zurückbleiben; die Folgepipeline läuft nicht weiter. Bei einer vorhandenen, passend gebundenen VM kann ein Modulfehler dagegen als unveränderter Erfolg committed werden.

Die vorhandene Async-Fixture beweist Start, laufenden Zustand, terminalen Erfolg und verschwundene JID. Sie beweist nicht, dass das Produktionsplaybook `failed` und `changed` bis zum Marker erhält. Der statische Playbooktest prüft Dateiexistenz und Verzicht auf Meldungstext als Entscheidung, aber nicht diese Ergebnissemantik.

Die installierte Implementierung von `async_status` wurde zusätzlich gelesen: fehlende Dateien melden terminalen Fehler, unlesbare bzw. noch unvollständig geschriebene JSON-Dateien können als laufend erscheinen. Ein Reparaturentwurf darf deshalb einen Fehler der Statusabfrage nicht mit einem bewiesenen Fehler des ursprünglichen VM-Moduls gleichsetzen. Auch das Zeitfenster zwischen `stat` und anschließendem Lesen ist zu berücksichtigen.

### F03 · P1 · R/K · Visual-Vertrag und CI-Plattform widersprechen sich

`.github/workflows/ci.yml` führt die Integration auf `ubuntu-latest` aus. `e2e-portal` in `scripts/lib/check/gates-integration.ps1` ruft nach der funktionalen Chromium-Suite verpflichtend `Invoke-VisualBaselines` auf. Der eingecheckte `tests/e2e/visual/runner-contract.json` verlangt dagegen `win32`, `x64`, Windows-Release `10.0.26200` und konkrete Segoe-Font-Hashes.

Die echte Funktion `compareMetadata()` liefert schon bei ausschließlich auf `linux` geändertem Plattformwert: `platform: expected "win32", got "linux"`. `requireMatchingMetadata()` behandelt dies als `infrastructure_error`; der Runner übernimmt diese Klasse. Sobald die Ubuntu-Integration die Visualprüfung erreicht, kann sie diesen Vertrag nicht erfüllen. Ein fehlender Browser oder ein früherer Testfehler könnte den Ablauf vorher beenden, würde den Widerspruch aber nicht lösen.

### F04 · P2 · K · CI-Berichte werden an veralteten Pfaden gesucht

`scripts/check.ps1` normalisiert bloße Dateinamen wie `qa-fast.json` nach `qa-artifacts/qa-fast.json`. Die drei JSON-Uploads in `.github/workflows/ci.yml` suchen weiterhin im Repositoryroot. `Invoke-PlaywrightSuite` setzt den Berichtspfad auf `playwright-report-chromium`, während der Workflow `tests/e2e/playwright-report` hochlädt. Die Visual-Artefakte liegen separat unter `qa-artifacts/visual-baselines-*` und sind ebenfalls in der Artefaktstrategie zu berücksichtigen.

Der JSON-Upload schlägt standardmäßig bei fehlender Datei nicht fehl; die gepinnte Action warnt lediglich. Beim Browserbericht ist `if-no-files-found: ignore` ausdrücklich gesetzt. Ein fehlendes Diagnoseartefakt kann deshalb unbemerkt bleiben. YAML-Lint und actionlint prüfen diese Verbindung zwischen Erzeuger und Verbraucher nicht.

### F05 · P2 · K · Namensauflösung nach einer früheren Identitätsprüfung

`startVMs-ESXi_playbook.yml` liest und validiert die UUID, wartet anschließend standardmäßig 300 Sekunden und mutiert dann über `name`. `powercycleVMs-ESXi_playbook.yml` mutiert ebenfalls über `name`, auch im harten Ausschalten des `always`-Blocks. Die zuvor geprüfte Live-Identität wird für die Auswahl der Mutation nicht verwendet.

Bei externer Umbenennung, Entfernung oder Austausch zwischen Prüfung und Aktion kann ein anderer Namensinhaber angesprochen werden. Das ist ein aus dem Code belegtes Zeitfenster; eine reale Fehlbedienung am ESXi wurde nicht reproduziert. Die gepinnte Implementierung von `vmware_guest_powerstate` unterstützt bereits `uuid` und `use_instance_uuid` sowie `moid`; ein Collectionwechsel ist zur Reparatur nicht erforderlich.

Zusätzliche Grenze: `powercycle_to_start` enthält die ursprünglich ausgeschalteten Kandidaten, nicht eine registrierte Liste bewiesener eigener Starts. Der Kommentar, der Cleanup schalte ausschließlich vom Lauf gestartete VMs aus, ist stärker als diese Implementierung. Ein externer Start im Zeitfenster ist gesondert zu testen. Kein Plan darf aus einer erfolgreichen Namensabfrage atomare Kontrolle über parallele ESXi-Bedienung ableiten.

### F06 · P2 · R/K · Transitive Collection bleibt ungepinnt

`Ansible/requirements.yml` pinnt `community.vmware 6.2.0`. Deren installiertes und veröffentlichtes Manifest fordert `vmware.vmware >=2.5.0`. Lokal wurde `vmware.vmware 2.9.0` aufgelöst. QA-Imageaufbau und Offline-Bundle-Download konsumieren die Requirementsdatei, dürfen dadurch aber bei einem späteren Aufbau eine andere transitive Version beziehen.

Das ist ein Reproduzierbarkeitsdefekt, kein Nachweis, dass die aktuell installierte Kombination funktionslos wäre. Ein bereits gebautes, vollständig exportiertes Offline-Bundle wird durch einen späteren Galaxy-Release nicht nachträglich verändert. Der Drift betrifft den erneuten Aufbau und unkontrolliert vorbereitete Ansible-Hosts.

### F07 · P2 · R/K · Hilfe verspricht eine nicht vorhandene Retry-Freigabe

`lang/{de,en}/help_deploy.php`, Schlüssel `create_progress_p3`, empfiehlt bei vorhandener VM die Identitätsübernahme und stellt anschließend eine mögliche Wiederholung in Aussicht. `repo_adopt_vm_identity()` sperrt die Mission gegen aktive Jobs und schreibt über `repo_vm_identity_adopt_locked()` nur MOID/UUID der Portal-VM. Die ungeklärte Ergebniszeile des ursprünglichen Create-Auftrags wird nicht aufgelöst.

Reproduktion der echten Retry-Planfunktion mit einer `uncertain`-Zeile und bereits gesetzter UUID: `blocked=true`, `blocking_positions=[1]`. Die vorhandene Übernahme hebt diese Sperre nicht auf. Die Freigabe als nicht erstellt ist bei vorhandenem Namen oder gespeicherter UUID im Inventar ausdrücklich ausgeschlossen.

Präzisierung: Diese Sperre ist kein versehentlicher Backendfehler. ADR-0041 verlangt genau diese Sicherheit. Es gibt außerdem eine generische, gefencete Transition `uncertain -> running` für einen noch aktiven Auftrag; deren Existenz ist kein öffentlich angebotener Recoveryweg für einen terminalen Auftrag. Die Übernahmefunktion ruft sie nicht auf. Ein zusätzlicher positiver Auflösungsweg verlangt eine eigene Vertragsentscheidung und hinreichende Ausführungsevidenz.

### F08 · P3 · K · Dokumentationsreste und begrenzte QoL-Lücken

`docs/DEPLOYMENT.md` spricht nach Entfernung von `CreateSettleSeconds` noch von drei Timingwerten und nennt veraltete Inventarabfragezahlen. Kommentare in `Ansible/requirements.yml` nennen zehn verwendete Module, das Modul-Gate erfasst elf. Der Kommentar zum toleranten Preflight für ältere Collections widerspricht inzwischen der unbedingten Prüfung auf die exakte installierte Collectionversion.

Unter `bin/Debug`, `bin/Release` und historischen QA-Arbeitskopien liegen alte Playbooks, darunter der frühere synchrone Create-Loop. Der aktive Runner schließt diese Verzeichnisse bewusst aus; `ansible_required_files()` und der konfigurierte Quellpfad bestimmen den aktuellen Upload. Es wurde kein aktiver Rückfall auf diese Kopien nachgewiesen. Sie sind ein Verwechslungsrisiko für manuelle Nutzung, kein zusätzlicher Beleg für einen produktiven zweiten Createpfad.

## 3. Was ausdrücklich nicht als Fehler bestätigt wurde

- Die Syntax der aktiven YAML-Dateien, die Compose-Zusammenführung und die aktuell installierten Modulargumente bestanden die genannten Prüfungen. Das ersetzt keinen Start- oder ESXi-Funktionstest.
- Hardwareversion 21 und die dokumentierte Create-Untergrenze ESXi 8.0 Update 2 stimmen überein. Ein zusätzlicher früher Portalhinweis ist QoL, keine Korrektur einer falschen Supportmatrix.
- Die vorhandenen Anzeigen `created`, `updated`, `unchanged`, `failed`, `uncertain`, `skipped` und `not_started` benötigen keine neue parallele Statuswelt. Die Create-Fortschrittskarte liest bereits gespeicherte Ergebnisse.
- VM-Editor, MECM-Rolloutname und Maschinen-API benötigen aus diesen Befunden keine neuen Felder oder Wire-Änderungen. Eine editierbare UUID wäre keine sachgerechte Reparatur.
- Deprecation von `vmware_guest_powerstate` ist bereits erfasst. Ein Modulwechsel gehört nicht automatisch in die Fehlerkorrektur, solange die gepinnte Version unterstützt wird und die vorhandenen Selektoren genügen.
- `.hadolint.yaml` und die leere Ausnahmeliste `.trivyignore.yaml` begründen keinen neuen Konfigurationsbefund. Ein aktueller vollständiger CVE-Scan war nicht Teil dieser Nachprüfung.

## 4. Verbindliche Quellen und Grenzen der Umsetzung

| Thema | Bestehender Eigentümer | Vorgabe |
|---|---|---|
| Lokaler Ansible-Quellpfad | `lib/ansible_paths.php`, `ansible_source_dir()` | Versionsdatei und Playbooks aus derselben wirksamen Quelle lesen |
| Collectionversionen | `Ansible/requirements.yml` | Direkte und transitive Pins gemeinsam verwalten; bestehende PHP-Fassade erhalten |
| Pythonlaufzeit | `Docker/qa-ansible/requirements.txt` | Keine ungeprüfte Versionsanhebung neben der Reparatur |
| Create-Protokoll und Ergebnisse | `ansible_create_protocol.php`, `deploy_create_result.php`, zugehörige Repositories | Fehlende Evidenz nicht in Erfolg oder neu startbare Arbeit umdeuten |
| Retry und Freigabe | `deploy_create_retry_plan()`, `deploy_create_release_blockers()` | Sperren, Scope, Lockreihenfolge und CAS erhalten |
| Portaltexte | `lang/de`, `lang/en`, `__t()` | Beide Sprachen und Platzhalter gemeinsam ändern |
| Portalverweise | `help_url()`, `deploy_job_log_url()`, `system_status_url()`, `settings_url()` | Keine neuen handgebauten Deep Links |
| Prüfungen | `scripts/check.ps1` und registrierte Module | Keine zweite öffentliche Runnerliste und keine stillen Skips |
| Visuals | Runnervertrag, Manifest und reviewte Sollbilder | Plattform-/Fontprüfung und Nulltoleranz erhalten; kein automatisches Baselineupdate |

## 5. Arbeitspakete

### A01 · P1 · Preflight-Quelle und lokale Fehlerklassifikation reparieren

**Warum:** Ohne diesen Fix erreicht die vorgesehene Containerinstallation den eigentlichen Ansible-Lauf nicht; die Fehlermeldung führt zusätzlich zum falschen System.

**Was ändern:** `ansible_pinned_collection_version()` über `ansible_source_dir()` auflösen. Importabhängigkeiten zwischen `ansible_command_probes.php`, `ansible_paths.php` und den Facaden bewusst ordnen, ohne Zyklus. Fehlende oder unlesbare Requirements bleiben ein harter lokaler Konfigurationsfehler, vorzugsweise über den vorhandenen Typ `SshTransportConfigurationException`, den alle betroffenen Fehlerklassifizierer bereits kennen. Kein zusätzlicher Compose-Mount nach `/var/Ansible` als Ersatz für die gemeinsame Pfadauflösung. Funktionsname für bestehende Aufrufer erhalten. Die Quellauflösung soll vor entfernten Schritten feststehen, sofern der jeweilige Aufrufpfad dies zulässt.

**Dateien:** `lib/ansible_command_probes.php`, `lib/ansible_paths.php`; bei Bedarf die Importregistrierung und Preflight-Aufrufstellen in `ssh.php`, `deploy_worker_inventory.php`, `deploy_worker_mission.php`. Kommentare zum alten toleranten Versionsverhalten korrigieren.

**Abnahme:** Repositorylayout, tatsächliches Compose-Layout und explizites alternatives `ANSIBLE_SOURCE_DIR` funktionieren. Fehlende und beschädigte Requirements ergeben `config` und keine SSH-/SFTP-Ausführung. Ein unerwarteter Pin wird nicht durch eine Vorgabe ersetzt. `AnsiblePreflightTest`, `AnsibleCommandModuleContractTest` und Fehlerklassifikation erweitern; die bestehende Literal-Assertion der Collectionversion auf den Dateivertrag umstellen. Den Container-Mountfall als ausführbare Regression in den kanonischen Prüfpfad aufnehmen.

### A02 · P1 · Async-Ergebnis bis zum Commit unverfälscht erhalten

**Warum:** Der aktuelle Code kann real geschaffene VMs als fehlgeschlagen verbuchen und fehlgeschlagene Konvergenz als Erfolg behandeln.

**Was ändern:** Im Statusplaybook die pauschalen Überschreibungen von `failed` und `changed` beseitigen. Ein Modulfehler darf den Steueraufruf nicht vor dem strukturierten Marker abbrechen; `ignore_errors` kann dabei das Originalergebnis erhalten, ist aber allein noch keine vollständige Lösung für fehlende Zustandsdateien. Die Statusdatei bleibt strukturelle Evidenz. Statuslesefehler, fehlende Datei und terminaler VM-Modulfehler müssen unterscheidbar bleiben, auch wenn die Datei zwischen Existenzprüfung und Abfrage verschwindet. Keine Entscheidung über englische Fehlermeldungen. Ein unlesbarer oder widersprüchlicher Zustand darf höchstens innerhalb des bestehenden Budgets erneut beobachtet werden und muss danach ungeklärt bleiben.

Die Implementierung soll möglichst beim vorhandenen Async-Statuspfad bleiben. Falls für eine belastbare Unterscheidung ein strukturierter Dateisnapshot erforderlich ist, erhält genau eine kleine, registrierte Hilfsfunktion die Interpretation. Kein zweites asynchrones Ausführungsverfahren und kein ungebundener Dateipfad. Erfolg weiterhin nur mit JID, unverfälschtem terminalem Ergebnis und verifizierter Live-Identität melden. Cleanup erst nach fachlich gültigem Commit; Diagnose eines widersprüchlichen Erfolgs präzisieren, statt pauschal eine fremde VM zu behaupten.

**Dateien:** `Ansible/createVMStatus-ESXi_playbook.yml`, `Docker/qa-ansible/create-async-fixtures.yml`, zugehöriger Gate-Code; bei Bedarf `lib/deploy_worker_create_poll.php` und die bestehende Create-Protokollregistrierung. `createVMLaunch` und der eine `vmware_guest`-Aufruf bleiben erhalten.

**Abnahme:** Erfolgreiche Neuanlage ergibt `created`, echte Änderung `updated`, Konvergenz ohne Änderung `unchanged`; terminaler Modulfehler erzeugt `failed` und wird nicht durch vorhandene Live-Identität geheilt. Laufend bleibt laufend. Fehlende, leere, unlesbare, beschädigte und während der Abfrage entfernte Async-Dateien ergeben niemals erfundenen Erfolg. Falsche/fehlende Live-UUID verhindert Commit. Verlust des Owner-Fence und gleichzeitige Cancellation behalten Vorrang. Neue Tests müssen das Produktionsplaybook bis zum Marker ausführen, nicht bloß dieselbe Logik erneut in einer Fixture formulieren.

Bestehende Testorte: `CreateFlowPlaybookContractTest`, `AnsibleCreateProtocolTest`, `DeployCreateResultStateTest`, `DeployCreateWorkerFlowTest`, `DeployCreateResultsIntegrationTest`, `DeployCreateRetryMatrixTest` sowie das Gate `ansible-create-async`. Mehrfachproben bekommen beobachtbare RUN-/Ergebniszeilen und bei neuem kanonischem Pfad eine Erweiterung von `VirtuSphere.ProgressReporting.Tests.ps1`.

### A03 · P2 · Pflichtberichte an ihren tatsächlichen Ausgabeorten hochladen

**Warum:** Ein roter Lauf muss verwertbare Belege hinterlassen; fehlende JSON-Dateien dürfen nicht als erfolgreicher Upload durchgehen.

**Was ändern:** In `.github/workflows/ci.yml` für die drei JSON-Lanes explizit `qa-artifacts/qa-*.json` sowohl als Runnerziel als auch als Uploadquelle verwenden. Beim zu diesem Schritt gehörigen Pflicht-JSON `if-no-files-found: error` setzen. Vorherige Setupfehler dürfen zusätzlich den Uploadfehler erklären, aber nicht den ursprünglichen Fehler verbergen. Funktionale Playwright-Berichte aus dem tatsächlichen projektbezogenen Ordner und Visual-Diagnosen aus ihrem Artefaktordner aufnehmen. Browserberichte dürfen fehlen, wenn der Browserabschnitt nie begann; diese Bedingung ausdrücklich modellieren.

**Dateien:** Workflow, falls nötig gemeinsame Ausgabe-Pfadfunktion im Runner; `VirtuSphere.CheckRunner.Tests.ps1`, passender Contracttest für Workflow-Erzeuger/-Verbraucher. Keine zusätzliche Laufzeit- oder Statuslogik im Workflow.

**Abnahme:** Nachweise für grünen Lauf, Gatefehler, Browserfehler und Setupabbruch. JSON-Pfad und Uploadpfad stimmen überein; fehlender Pflichtbericht wird erkannt. Ein Browserfehler liefert den richtigen Bericht, Visualfehler ihre Metadaten/Diffs. Artefakte enthalten keine `.env`, `accounts.yml` oder pauschal eingesammelten Arbeitsverzeichnisse.

### A04 · P1/D · Visualprüfung auf eine passende CI-Umgebung legen

**Warum:** Der derzeitige Ubuntu-Job ist mit dem eingecheckten Windows-Vertrag unvereinbar.

**Empfohlene Zielentscheidung:** Den reviewten Windows-Vertrag erhalten und die Integration einschließlich Visualprüfung auf einem dedizierten, dafür provisionierten Windows-Runner mit passendem OS-/Font-/Browserstand und Linux-Containerengine ausführen. Den gesamten bestehenden kanonischen Integrationseinstieg dort zu verwenden, vermeidet zunächst eine neue Aufteilung der Lane. Fast kann auf Ubuntu bleiben. Ein beliebiges `windows-latest` erfüllt weder den exakten OS-/Fontvertrag noch automatisch die benötigte Linux-Containerumgebung.

**Vor Umsetzung festlegen:** Verfügbarkeit und tatsächliche Runnerlabels, reproduzierbare Provisionierung, Zugang zur Containerengine sowie Wartung des gepinnten Runnerstands. Solange diese Infrastruktur fehlt, bleibt dieses Paket offen; der Plan behauptet keinen vorhandenen Runner. Falls stattdessen auf Linux-Visuals umgestellt werden soll, ist das eine alternative bewusste Entscheidung mit separat durch eine Person erzeugten und reviewten Sollbildern. Ein Metadatenfehler berechtigt niemals zur automatischen Baselineänderung.

**Dateien:** Workflow, `docs/TESTPLAN.md`, betroffene CI-/Visual-Betriebsanleitung und gegebenenfalls ADR-0013/ADR-0031-Amendment. Änderungen am Runnervertrag und Manifest nur bei tatsächlich beschlossener Plattformmigration. Bei späterer Aufteilung in Jobs müssen alle Pflichtgates weiterhin über `scripts/check.ps1` erfasst und die Gesamtabnahme nachvollziehbar bleiben.

**Abnahme:** Echte CI-Ausführung erreicht und besteht Metadatenprüfung und unveränderte reviewte Sollbilder. Negativfall falsche Plattform/Fonts bleibt `infrastructure_error`. QA-Isolation, Stilllegung nur der zuvor laufenden QA-Worker und Wiederherstellung in `finally` bleiben erhalten. Keine Änderung der Nulltoleranz und keine Aufnahme realer Daten in Screenshots.

### A05 · P2 · Power-Aktionen an die geprüfte Identität binden

**Warum:** Eine frühe UUID-Prüfung schützt die spätere erneute Namensauflösung nicht.

**Was ändern:** Start und Powercycle übernehmen die validierte Live-Instance-UUID in die konkrete Mutationsauswahl und verwenden `uuid` mit `use_instance_uuid: true` anstelle erneuter Namenswahl. Bei `full` kann der YAML-Snapshot noch eine leere gespeicherte UUID tragen; deshalb nicht blind `item.vm_instance_uuid` einsetzen, sondern die tatsächlich geprüfte Live-Identität verwenden. Vor dem Ein- und Ausschalten die maßgebliche Zuordnung erhalten. Fehler bei verschwindender ursprünglicher VM dürfen keinen Wechsel auf einen neuen Namensinhaber auslösen.

Für den Powercycle gesondert festlegen und testen, wie eigene erfolgreiche Starts erfasst werden und wie ein Fehler nach eventuell erfolgtem Einschalten behandelt wird. Die ursprüngliche Kandidatenliste ist dafür kein ausreichender Nachweis. Die Bedingung, vorbestehend laufende oder suspendierte VMs unverändert zu lassen, erhalten. Die Autostart-Mutation ebenfalls auf die Lücke zwischen Identitätsprüfung und Namenswahl prüfen; nur einen von der gepinnten Collection tatsächlich unterstützten Selektor benutzen und Restgrenzen dokumentieren.

**Dateien:** `startVMs-ESXi_playbook.yml`, `powercycleVMs-ESXi_playbook.yml`, gegebenenfalls `autostartVMs-ESXi_playbook.yml`; bestehende Identity-/Powercycle-Contracttests und Fixtures. Gemeinsame Identitätsregeln über einen passenden bestehenden bzw. registrierten Eigentümer ausdrücken, statt zusätzliche widersprüchliche Namensmatrizen anzulegen.

**Abnahme:** Gleichnamiger Ersatz nach Prüfung bleibt unberührt; fehlende ursprüngliche VM verursacht Fehler statt Fallback; neue VM aus `full` verwendet die frisch verifizierte UUID. Vorher laufende/suspendierte VM wird nicht ausgeschaltet. Externer Start im Zeitfenster und teilfehlgeschlagene Einschaltfolge werden explizit geprüft. Abschließend kontrollierte ESXi-Abnahme, denn Stubtests belegen keine atomare Semantik paralleler Bedienung am realen Host.

### A06 · P2 · Vollständigen Collectionstand verbindlich machen

**Warum:** QA, erneuter Offline-Aufbau und Betrieb sollen dieselben Collectionbytes bzw. Versionen verwenden.

**Was ändern:** `vmware.vmware` explizit in `Ansible/requirements.yml` pinnen. Ausgangskandidat ist die tatsächlich geprüfte Version 2.9.0, kein ungeprüftes Upgrade. QA-Dockerfile und Offline-Bundle bleiben Verbraucher dieser einen Datei. Der Preflight soll den erwarteten Collectionstand prüfen und Abweichungen verständlich benennen. Abhängigkeiten beider Collections und deren Ansible-Anforderungen beim Build erfassen; eine spätere neue transitive Abhängigkeit darf nicht unbemerkt ungepinnt bleiben. Hash-/Manifestnachweise im bestehenden Bundleverfahren erhalten.

**Dateien:** Requirementsdatei, `ansible_command_probes.php`, `Docker/qa-ansible/module-contract.sh` und Tests, `scripts/build-offline-bundle.sh` nur soweit dessen bestehende Erfassung nicht genügt; `docs/DEPLOYMENT.md`, `docs/operations/offline-install.md`.

**Abnahme:** Frisch gebautes QA-Image meldet exakt den erwarteten vollständigen Collectionstand. Fehlende und abweichende transitive Versionen schlagen gezielt fehl. Installation aus neu gebautem Bundle funktioniert ohne Netzwerk. Gegen den neuen expliziten Pin alle aktuell verwendeten Module prüfen. Versionszahlen in Hilfe und Betriebsanweisung nicht als zweite Installationsliste pflegen.

### A07 · P2 · Hilfe, bestehende Portalansichten und Betriebsdoku gemeinsam abgleichen

**Warum:** Nach der Reparatur muss die Oberfläche die richtige Ursache und den tatsächlich verfügbaren nächsten Schritt nennen. Eine neue Anzeige allein kann falsche gespeicherte Ergebnisse nicht korrigieren.

**Pflichtumfang Hilfe:** `lang/{de,en}/help_deploy.php` korrigiert die Wiederholungszusage: Identität übernehmen beendet keinen ungeklärten Create-Auftrag. Bei vorhandenem Objekt darf die Hilfe weder zur Freigabe als nicht erstellt noch zur Löschung als allgemeiner Reparatur raten. Für Abwesenheit gelten frischer erfolgreicher VM-Inventarnachweis, passende Zeitgrenzen und dokumentierte Operatoraussage. Voraussetzungen und Identitätsbeschreibung nach A01/A02/A05/A06 gegenprüfen. Die bestehende Beschreibung eines vorhandenen Recoverywegs nur dann erweitern, wenn dieser tatsächlich implementiert und abgenommen wurde.

**Pflichtumfang Bereitstellungen:** Ergebnisse und Zähler aus `deploy_create_vm_results` beibehalten; keine zweite Ableitung aus Logs. In `deploy_create_progress.php`, Retry-/Release-Ansichten und den DE/EN-Katalogen überprüfen, dass Modulfehler, ungeklärte Beobachtung, lokaler Konfigurationsfehler und fachliche Identitätskollision unterscheidbar sind. Nur tatsächlich hilfreiche, berechtigungsgeprüfte Links anbieten. Besonders den Fall „Identität übernommen, ursprünglicher Auftrag weiterhin ungeklärt“ verständlich darstellen. Bereits passende Anzeigeelemente nicht unnötig umbauen.

**Pflichtumfang Zugangsdaten/Systemstatus:** A01 muss in manuellem Test und Inventarjob zur gleichen lokalen Fehlerursache führen. Bestehende Message-/Action-Helper nutzen. Ein entfernt fehlendes Pythonmodul bleibt hingegen ein entfernter Preflightfehler. `help_credentials.php` und `help_system_status.php` nur dort ändern, wo die tatsächliche Aussage unvollständig oder falsch ist.

**VM-Bearbeitung:** Aus den bestätigten Befunden folgt keine neue Pflichtmaske. RAM-/CPU-/Disk-/Netzwerkvalidierung und MECM-Rolloutregeln bleiben fachlich eigenständig. Als optionale QoL-Erweiterung kann die VM-Seite den zuständigen ungeklärten Bereitstellungsauftrag mit Link zeigen; sie muss dann denselben Ergebnis- und Berechtigungsbesitzer benutzen. Weder editierbare UUID noch Hardwareversionsauswahl als Nebenprodukt einführen.

**Betriebsdoku:** `docs/DEPLOYMENT.md` (Mountpfad, Installationsvertrag, Status-/Recoverysemantik, zwei verbliebene Timingwerte), `docs/operations/offline-install.md` (vollständiger Collectionstand), `docs/TESTPLAN.md` und CI-/Visual-Anleitung (Runner und Artefakte), einschlägige ADR-Amendments bei Vertragsänderungen. Überholte Modulzahlen möglichst durch eine Beschreibung des ausführbaren Checks ersetzen. Historische Auditberichte als historische Belege erhalten.

**Abnahme:** DE/EN-Katalog- und Platzhalterparität, echte Umlaute, vorhandene Übersetzungsschlüssel und Deep-Link-Registrierung. Browserprüfung der bestehenden Create-Karte für Erfolg, Änderung, unverändert, Teilfehler, ungeklärt und Retry-Sperre. Hilfe verspricht keinen nicht implementierten Übergang. Responsive Geometrie/Visuals nur bei tatsächlicher Layoutänderung erweitern; keine Baselineänderung für reine Backendkorrekturen voraussetzen.

### A08 · P1/P2 · Bereits betroffene Ergebnisse vor Wiederholung bewerten

**Warum:** A02 verhindert neue Fehlklassifikationen, repariert aber keine historisch falsch verbuchten Aufträge. Insbesondere kann eine neue VM vorhanden sein, obwohl die Einheit als fehlgeschlagen gilt und ihre Async-Datei bereits bereinigt wurde.

**Was vorsehen:** Zunächst eine lesende Bestandsprüfung betroffener Create-Aufträge mit Fehlercode, Prepare-Evidenz, gespeicherter Identität, Ausführungszeit und etwaig verbliebenem Remote-Nachweis. Treffer sind Verdachtsfälle, keine automatisch reparierbaren Datensätze. Neu eingeholtes Inventar beweist Existenz und Identität, aber allein weder den ursprünglichen Modulerfolg noch vollständige Hardwarekonvergenz. Keine pauschale SQL-Umschreibung von `failed` nach `succeeded`, kein automatisches Wiederholen und kein Löschen vorhandener VMs.

**Abnahme:** Ein nachvollziehbarer Betriebsweg für betroffene Aufträge ist dokumentiert. Automatisierte Datenkorrekturen wären ein eigenes, mit konkreten Datensätzen und Evidenz zu prüfendes Vorhaben. Neue Softwareabnahme und historische Datenbereinigung werden getrennt ausgewiesen.

## 6. Entscheidungen und optionale Erweiterungen

**D01: Visual-Infrastruktur.** A04 benötigt eine tatsächlich verfügbare passende Ausführungsumgebung. Empfohlen ist der bestehende Windows-Vertrag. Eine Linux-Neubaseline ist eine Alternative, nicht ein stiller Reparaturschritt.

**D02: Positive Auflösung einer vorhandenen, ungeklärten VM.** Pflicht ist zunächst die ehrliche Hilfe aus A07. Soll das Portal zusätzlich einen vollständigen Recoveryweg anbieten, ist vor dessen Code ein ADR-0041-Amendment nötig: welche ursprüngliche JID/Handle-Evidenz terminale Ausführung beweist, wie Live-Identität und relevante Konvergenz geprüft werden, welche Locks/Fences gelten, welche Rolle bestätigen darf, wie der Befund auditierbar bleibt und was bei weiter laufendem Task geschieht. Eine UUID-Übernahme oder ein Inventory-Treffer allein reicht nicht. Der jetzige Plan autorisiert keine Abschwächung der bestehenden Sperre und behauptet keine vollständige Recoverylösung.

**E01: VM-Editor-Verweis.** Ein lesender Hinweis auf einen konkret betroffenen Create-Auftrag kann Suchwege verkürzen. Keine Datenbankänderung voraussetzen, keine pauschale Warnung an jeder VM. Zieljob über bestehenden Repository-/URL-Besitzer auflösen.

**E02: Frühere Hostkompatibilitätsanzeige.** Die dokumentierte Create-Untergrenze kann im Bereitstellungsformular anhand frischer Capabilitydaten erklärt werden. Wird daraus ein neuer Blocker, gehört er in die gemeinsame Queueentscheidung und den Worker-Recheck; gleiche Semantik für Formular, Live-Endpunkt, Preview und Pre-Write. Unbekannte Versionsdaten nicht durch Schätzung ersetzen. Das ist eine Erweiterung und benötigt eine bewusst definierte Unknown-Policy.

**E03: Altstände kenntlich machen.** Manuelle Betriebsanweisung soll ausschließlich den aktuellen `Ansible/`-Quellpfad nennen. Alte lokale `bin/`- oder QA-Verzeichnisse nicht automatisch löschen und nicht durch Synchronisieren zu weiteren SSoT-Kopien machen. Bei Packagingprüfungen sicherstellen, dass der aktuelle Lieferumfang aus den registrierten Quellen stammt.

## 7. Reihenfolge und Abschlusskriterien

1. A01 und A02 zuerst durch reproduzierende Tests absichern und reparieren. Anschließend A08 als Betriebsrisiko bewerten, bevor vorhandene Fehleraufträge wiederholt werden.
2. A03 früh umsetzen, damit spätere CI-Fehler Belege liefern. D01 entscheiden und A04 real ausführbar machen; Syntaxgrün reicht dafür nicht.
3. A05 und A06 umsetzen; neue Builds und die kontrollierte ESXi-Abnahme gegen genau diese Versionen ausführen.
4. A07 je betroffenem Paket mitziehen und am Ende als zusammenhängenden Bedienablauf prüfen. D02/E01/E02/E03 ausdrücklich mit eigenem Status führen.
5. Abschluss nur mit allen Pflichtpaketen und nachvollziehbaren Tests. Fehlende Runner- oder ESXi-Abnahme bleibt offen und darf nicht als Implementierungserfolg mitgezählt werden.

Vor Implementierung aktuellen Gitstand und geltende AGENTS-/GROK-/ADR-Regeln erneut lesen. Dieser Bericht ist der Maßnahmenbesitzer für diese Befunde; Status, Testnachweis und offene Abnahmen hier fortschreiben, statt einen zweiten konkurrierenden Plan anzulegen. Keine Umsetzung ist durch das Vorliegen dieses Plans bereits erfolgt.

Alle regulären Prüfungen laufen über `scripts/check.ps1`; zunächst `-List` für den aktuellen Gateumfang verwenden. Gezielt betroffene Gates zuerst, danach Fast und die erforderliche Integration auf passendem Runner; Release nur für die tatsächliche Auslieferungsabnahme. Bei Langläufen vor Start einen live lesbaren Fortschrittspfad bereitstellen und spätestens jede Minute die letzte echte `[n/total]`-Zeile berichten. Ein neu eingeführter kanonischer Mehrfachpfad erweitert den Progress-Contracttest. Nach PHP-/JS-/Portaltextänderungen passende Syntax-, PHPUnit-, Sprach-, CSP-, SSoT- und Browserchecks durchführen; Guards mit Positiv-/Negativ-/Zero-Match-Fällen absichern.

Die reale ESXi-Abnahme umfasst mindestens: eine neue VM, eine eigene vorhandene VM mit echter Änderung, eine unveränderte eigene VM, einen kontrollierten Modulfehler, eine fremde namensgleiche VM, Austausch der Namenszuordnung im kontrollierten Zeitfenster, eine verlorene Statusbeobachtung und einen Abbruch während einer Einheit. Nur synthetische Daten in der dafür vorgesehenen Testumgebung verwenden. Kein Test darf eine echte Kunden-VM als Wegwerfobjekt behandeln.

## 8. Quellen und Nachweise

- Ansible erklärt, dass `failed_when` und `changed_when` die jeweilige Taskbewertung festlegen; `ignore_errors` erlaubt dagegen das Weiterlaufen bei erhaltenem Fehlerergebnis. [Offizielle Fehlerbehandlung](https://docs.ansible.com/projects/ansible/latest/playbook_guide/playbooks_error_handling.html). Maßgeblicher Laufzeitnachweis für F02 ist zusätzlich die oben dokumentierte Reproduktion mit installiertem core 2.19.11.
- `community.vmware 6.2.0` deklariert `vmware.vmware >=2.5.0`. [Manifest des tatsächlich gepinnten Tags](https://raw.githubusercontent.com/ansible-collections/community.vmware/6.2.0/galaxy.yml). Das lokal installierte Manifest ergab denselben Bereich und eine aufgelöste Version 2.9.0.
- Die gepinnte Upload-Action warnt standardmäßig bei nicht gefundenen Dateien. [upload-artifact v4.6.2](https://github.com/actions/upload-artifact/tree/v4.6.2#customization-if-no-files-are-found).
- UUID-Selektion ist im Powerstate-Modul vorgesehen. [Offizielle Moduldokumentation](https://docs.ansible.com/projects/ansible/latest/collections/community/vmware/vmware_guest_powerstate_module.html). Die Parameter wurden zusätzlich direkt aus der lokal installierten Collection 6.2.0 gelesen; die Online-Latest-Dokumentation ersetzt diesen Versionsnachweis nicht.
- Hardwareversion 21 setzt ESXi 8.0 U2 voraus. [Broadcom-Hardwarematrix](https://knowledge.broadcom.com/external/article/315655/virtual-machine-hardware-versions.html) und [Kompatibilitätstabelle](https://knowledge.broadcom.com/external/article/312100/esxi-hosts-and-compatible-virtual-machin.html) stützen die bereits richtige Create-Untergrenze.
- Compose behandelt `command` und `healthcheck.test` als Ersetzungen und Volumes anhand ihrer eindeutigen Ziele. [Offizielle Merge-Regeln](https://docs.docker.com/reference/compose-file/merge/). Beide vorgesehenen Override-Kombinationen wurden zusätzlich lokal validiert.

Die Shell-/Containerreproduktionen erzeugten nur temporäre Dateien außerhalb der Produktquellen. Das Ergebnislog der ursprünglichen 16 Gates lag unter `C:/Users/Samy/AppData/Local/Temp/virtusphere-qa-20260908-130540`; dieser temporäre Pfad ist kein dauerhafter Abnahmenachweis für eine spätere Umsetzung. Die oben enthaltenen Eingaben, beobachteten Marker, Funktionsnamen und Versionen machen die entscheidenden Fälle nachvollziehbar und erneut prüfbar.
