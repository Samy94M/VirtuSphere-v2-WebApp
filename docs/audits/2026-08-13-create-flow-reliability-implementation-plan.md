# Implementierungsplan: zuverlässiger sequenzieller VM-Create-Ablauf

Stand: 2026-08-13

Status: Am 13.08.2026 fachlich freigegeben. Es wurde noch nichts aus diesem Plan implementiert.

Diese Datei ist die ausführende Spezifikation für ausschließlich den per-VM-Create-Ablauf. Sie ersetzt keine allgemeinen Architekturentscheidungen. Bei einer Überschneidung mit `docs/audits/2026-08-11-deploy-reliability-master-plan.md` ist diese Datei nur für die hier ausdrücklich genannten Create-Einheiten, Ansible-Async-JIDs, Create-Identität, Create-Fortschritt und das vierstündige Create-Gesamtbudget genauer. Der zusammengeführte Plan `docs/audits/2026-08-13-mac-import-vlan-ambiguity-qol-implementation-plan.md` ist dagegen Owner von Remote-Handle, Worker-Lease/Fencing, Reaper/Recovery, Cleanupfreigabe, Dienstzustand, Netzwerkblocker und Retry-Präzedenz.

Verbindliche Integrationsfolge: Create/Full werden erst als Masterplan-Etappe 14B nach der dortigen Etappe 14A aktiviert. Jede Create-Einheit hängt unter genau einem generischen Remote-Handle; ein neuer Retry übernimmt niemals eine noch aktive JID. Die früheren anderslautenden Passagen in 10.2, 11.2 und 11.5 sind in dieser Fassung korrigiert.

Der Plan ist absichtlich entscheidungsorientiert geschrieben. Eine ausführende Person soll weder aus einem Fehlertext Geschäftslogik ableiten noch zwischen mehreren technisch möglichen Varianten wählen müssen. Die fünf Produktentscheidungen in Abschnitt 0 wurden am 13.08.2026 vollständig freigegeben. Es gibt in diesem Plan keine offene Produktentscheidung mehr; alle übrigen Festlegungen gelten ohne weitere Interpretation.

---

## 0. Freigegebene Produktentscheidungen

Alle fünf Empfehlungen wurden vom Nutzer ausdrücklich übernommen. Die markierten Entscheidungen sind verbindliche Eingangsvoraussetzung der Umsetzung.

### F1: Gilt die Reparatur auch für den Create-Schritt der vollständigen Pipeline?

- [x] **Entschieden: Ja.** Derselbe `createVMs-ESXi_playbook.yml`-Pfad wird in `create` und `full` ausgeführt. Beide Modi verwenden danach dieselbe sequenzielle Create-Orchestrierung, dieselben per-VM-Ergebnisse und dieselbe Identitätsbindung. Die nachfolgenden Schritte der vollständigen Pipeline bleiben fachlich unverändert.
- Nicht gewählt: Nur den Modus `create` umstellen. Damit würde derselbe technische Defekt im ersten Schritt von `full` bestehen bleiben.

### F2: Bleibt das Gesamtzeitbudget eines Create-Auftrags bei vier Stunden?

- [x] **Entschieden: Ja.** `VIRTUSPHERE_SSH_TOTAL_TIMEOUT_SECONDS = 14400` bleibt die Ausgangs-SSoT für den neuen Create-Job-Gesamtdeckel. Regelmäßige Poll-Ausgabe verhindert nur einen falschen Idle-Abbruch; sie hebt den Gesamtdeckel nicht auf.
- Nicht gewählt: ein anderer Gesamtdeckel.

### F3: Darf „Create only“ den fachlichen VM-Lifecycle verändern?

- [x] **Entschieden: Nein.** Der Modus erstellt oder konvergiert ausschließlich die ESXi-Hardware und bindet deren Identität. Er setzt `deploy_vms.lifecycle_state`, `mecm_sync_state`, den eingefrorenen Legacy-Status, `updated` und `mecm_id` weder beim Start noch bei Erfolg oder Fehler um. Der sichtbare Fortschritt stammt aus den neuen Create-Ergebnissen des Auftrags. `full` behält dagegen seinen bisherigen Pipeline-Lifecycle.
- Nicht gewählt: Create-only verändert Lifecycle- oder MECM-Zustände.

### F4: Wie wird ein unklarer Ausgang ohne wiederauffindbare Ansible-Job-ID behandelt?

- [x] **Entschieden: Fail-closed.** Kein automatischer neuer Create. Das Portal verlangt zunächst einen erfolgreichen, nach dem unklaren Ergebnis abgeschlossenen Inventarpull für dasselbe ESXi-Credential. Existiert die VM, muss ihre Identität nach Prüfung ausdrücklich übernommen werden. Existiert sie nicht, prüft ein Administrator zusätzlich im ESXi Host Client die „Recent Tasks“ und bestätigt mit einer benannten, protokollierten Aktion „Als nicht erstellt freigeben“, dass kein Create-/Reconfigure-Task mehr läuft. Erst danach darf ein Retry diese VM neu anlegen.
- Die verworfene Alternative „nach Wartezeit automatisch noch einmal erstellen“ bleibt ausgeschlossen, weil ein vSphere-Task nach einem Automations-Timeout weiterlaufen kann.

### F5: Soll ein bestätigter Fehler einer VM die restlichen VMs stoppen?

- [x] **Entschieden: Nein; bei einem eindeutig abgeschlossenen per-VM-Fehler wird mit der nächsten VM fortgefahren.** Es läuft weiterhin nie mehr als eine Create-Einheit gleichzeitig. Bei Transportverlust, unklarem Async-Zustand, verlorenem Workerbesitz, Datenbank-Endausfall oder überschrittenem Gesamtbudget wird dagegen sofort gestoppt, weil noch externe Arbeit laufen kann oder kein Ergebnis sicher gespeichert werden kann.
- Nicht gewählt: Fail-fast bei einem bestätigten Modul- oder Identitätsfehler.

### Entscheidungsblock

```text
F1 = Ja, Reparatur gilt für create und full
F2 = Ja, Gesamtbudget bleibt 14400 Sekunden
F3 = Nein, Create-only verändert keinen Lifecycle-/MECM-Zustand
F4 = Fail-closed ohne wiederauffindbare JID
F5 = Nein, bestätigter per-VM-Fehler stoppt die restlichen VMs nicht
Freigegeben von = Nutzer, ausdrückliche Übernahme aller Empfehlungen im Chat
Freigegeben am = 2026-08-13
```

---

## 1. Ziel und Erfolgskriterium

Ein Auftrag mit mehreren VMs erstellt die VMs weiterhin streng nacheinander. Jede VM ist eine eigene, dauerhaft nachvollziehbare Arbeitseinheit. Der Worker kennt vor dem Start deren Gesamtzahl und schreibt unmittelbar vor und nach jeder Einheit den kanonischen Fortschritt:

```text
[1/15] RUN create ATeP04-001
[1/15] POLL create ATeP04-001 running
[1/15] DONE create ATeP04-001 created
[2/15] RUN create ATeP04-002
[2/15] DONE create ATeP04-002 unchanged
```

Eine lange Eager-Zeroed-Thick-Erstellung darf nicht deshalb nach 1.800 Sekunden als fehlgeschlagen gelten, weil Ansible seine Standardausgabe puffert. Der Worker pollt einen asynchron gestarteten Ansible-Job in festen Abständen. Er startet die nächste VM erst, wenn die aktuelle VM nachweislich beendet und ihr Ergebnis dauerhaft gespeichert ist.

Ein erfolgreicher Create liefert zusätzlich eine belastbare MOID und Instance-UUID. Der Worker bindet diese Identität unmittelbar an genau die Portal-VM-ID, die er gestartet hat. Dadurch ist ein späterer Retry sicher idempotent und wird nicht allein über einen Namen begründet.

Der Auftrag darf nie einen Gesamterfolg melden, wenn für eine ausgewählte VM kein terminales, dauerhaft gespeichertes Create-Ergebnis existiert. Ein Transportverlust darf nie pauschal behaupten, alle VMs seien fehlgeschlagen. Er muss zwischen bereits bestätigten, bestätigten fehlgeschlagenen, noch nicht begonnenen und unklaren Einheiten unterscheiden.

---

## 2. Ausdrückliche Nicht-Ziele

Folgendes wird nicht implementiert:

- keine parallele Erstellung von zwei, drei oder mehr VMs;
- keine Änderung an VAAI, VMFS, RAID, Local Cisco Disk, UCSC RAID Controller oder ESXi-Hardwareeinstellungen;
- keine automatische Wahl eines anderen Festplattentyps;
- keine automatische Konvertierung bestehender Platten;
- keine erfundene Prozentanzeige innerhalb einer VM-Erstellung;
- kein hartes Beenden eines laufenden vSphere-Create-Tasks;
- keine Änderung der MECM-Machine-API, der fünf Legacy-Statusstrings oder des MAC-Import-Wire-Vertrags;
- kein neuer Cloud-, CDN-, Telemetrie- oder Runtime-Download;
- keine Reaktivierung der entfernten Desktop-Token-API;
- keine allgemeine Neugestaltung aller Deploy-Modi;
- keine automatische Adoption einer namensgleichen VM;
- keine Interpretation freier Ansible-Logtexte als fachliche Ergebnis-SSoT.

Die Joblog-Vollständigkeit und die Create-Fortschrittsanzeige sind in diesem Plan enthalten, weil der Produktionsfehler in der aktuellen Oberfläche hinter der 1.000-Zeilen-Grenze verborgen war und sichtbarer Fortschritt ein Kernbestandteil der Reparatur ist.

---

## 3. Nachgewiesener Ausgangszustand

### 3.1 Produktionsvorfall

Der erste Auftrag begann den Create-Task am 13.08.2026 um 10:19:30 und endete am 13.08.2026 um 10:49:32 mit:

```text
Remote command produced no output for 1800 seconds (idle timeout).
(playbook step: createVMs-ESXi_playbook.yml)
```

Der Fehler beweist einen ausbleibenden Ausgabestrom, nicht das Ende des ESXi-Tasks. Vierzehn der fünfzehn VMs erschienen später auf ESXi. Der zweite Lauf konnte die fehlende VM nachziehen.

### 3.2 Aktueller Codepfad

- `Ansible/createVMs-ESXi_playbook.yml` führt einen einzigen `vmware_guest`-Task mit `loop: "{{ vm_configurations }}"` aus.
- `Docker/WebAPI/lib/ansible_command.php` startet `ansible-playbook ... 2>&1` ohne `PYTHONUNBUFFERED=1`.
- `Docker/WebAPI/lib/ssh.php` setzt das Idle-Budget nach jeder empfangenen Ausgabe zurück, nicht nach dem DB-Heartbeat.
- `Docker/WebAPI/lib/deploy_worker_mission.php` sieht nur den Ausgang des vollständigen Remote-Befehls, nicht einen dauerhaften Ausgang je Create-VM.
- `Docker/WebAPI/lib/deploy_worker_finish.php` besitzt für Create-only kein per-VM-Ergebnis und behandelt deshalb einen Fehler pauschal.
- `Docker/WebAPI/portal/deploy_log.php` rendert initial höchstens die ersten 1.000 Zeilen. Ein bereits terminaler Auftrag startet den Browser-Poller nicht mehr.
- Der Preflight schreibt durch `ansible-doc` die vollständige Moduldokumentation ins Joblog und erzeugte im Vorfall bereits ungefähr 1.450 Zeilen vor dem ersten Create-Ergebnis.
- Die Produktion meldete Ansible Core 2.16.3. Das Repository pinnt für QA Core 2.19.11; `community.vmware` 6.2.0 verlangt Core 2.19 oder neuer.

### 3.3 Reproduzierter Buffering-Befund

Im gepinnten QA-Image wurden drei sequenzielle Schleifen-Items ausgeführt. Ohne `PYTHONUNBUFFERED=1` trafen die Item-Ergebnisse gesammelt am Ende ein. Mit `PYTHONUNBUFFERED=1` traf jedes Ergebnis unmittelbar nach seinem Item ein. Daraus folgt verbindlich:

1. `PYTHONUNBUFFERED=1` wird zentral auf jeden durch VirtuSphere gestarteten `ansible-playbook`-Prozess angewandt.
2. Ein neuer Progress-Marker ohne ungebufferte Controllerausgabe gilt nicht als Reparatur.
3. `stdbuf` wird nicht als Ersatz verwendet, weil Python oberhalb der C-stdio-Schicht puffern kann.

### 3.4 Primärquellen

- Ansible Async und Polling: <https://docs.ansible.com/projects/ansible/latest/playbook_guide/playbooks_async.html>
- Ansible `async_status` einschließlich `mode: cleanup`: <https://docs.ansible.com/projects/ansible/latest/collections/ansible/builtin/async_status_module.html>
- Ansible-Core-Portinghinweis: benutzerdefiniertes Async-Verzeichnis seit Core 2.12 über die Variable `ansible_async_dir`, nicht über `ANSIBLE_ASYNC_DIR` im Task-Environment: <https://docs.ansible.com/projects/ansible/latest/porting_guides/porting_guide_core_2.12.html>
- Ansible-Playbook-Schlüssel `async` und `poll`: <https://docs.ansible.com/projects/ansible/latest/reference_appendices/playbooks_keywords.html>
- Ansible erzeugt per Schleifen-Item Runner-Callbacks: <https://github.com/ansible/ansible/blob/stable-2.19/lib/ansible/executor/task_executor.py>
- Ansible Display schreibt ohne erzwungenen Flush an dieser Stelle: <https://github.com/ansible/ansible/blob/stable-2.19/lib/ansible/utils/display.py>
- Python `PYTHONUNBUFFERED`: <https://docs.python.org/3/using/cmdline.html#envvar-PYTHONUNBUFFERED>
- `vmware_guest state: present` erstellt fehlende und konvergiert vorhandene VMs: <https://docs.ansible.com/projects/ansible/latest/collections/community/vmware/vmware_guest_module.html>
- `vmware_vm_info` liefert eine Trefferliste samt VM-Identitätsfeldern und erlaubt dadurch auch die explizite Null-/Mehrfachtrefferprüfung: <https://docs.ansible.com/projects/ansible/latest/collections/community/vmware/vmware_vm_info_module.html>
- `vmware_guest_info` liefert für einen eindeutigen MOID ausdrücklich `instance_uuid` und `moid`: <https://docs.ansible.com/projects/ansible/latest/collections/community/vmware/vmware_guest_info_module.html>
- `community.vmware` 6.2.0 verlangt `ansible-core >= 2.19.0`: <https://raw.githubusercontent.com/ansible-collections/community.vmware/6.2.0/meta/runtime.yml>
- Broadcom zu Automations-Timeout bei weiterlaufender Eager-Zeroed-Thick-Arbeit: <https://knowledge.broadcom.com/external/article/431859/virtual-machine-deployment-fails-with-co.html>
- Broadcom zu Thick/Eager-Zeroed-Thick: <https://knowledge.broadcom.com/external/article/308992/virtual-machine-disk-types-thick-lazy-ze.html>

---

## 4. Verbindliche Begriffe

| Begriff | Exakte Bedeutung |
|---|---|
| Create-Einheit | Genau eine Portal-VM innerhalb genau eines Deploy-Jobs und genau eines Create-Schritts. |
| ausgewählte Menge | Die beim Einreihen unter Mission-Lock aufgelöste, alphabetisch nach `vm_name` geordnete VM-Menge. Eine leere `vm_ids`-Auswahl wird einmal zu dieser konkreten Menge materialisiert. |
| vorbereitet | Die Live-Identitätsprüfung der Einheit war erfolgreich; noch kein Create-Async-Job ist als gestartet bestätigt. |
| Ansible-Async-Job | Genau ein mit `async` und `poll: 0` gestarteter `vmware_guest`-Aufruf für genau eine Create-Einheit. `poll: 0` bedeutet hier nicht Parallelbetrieb; der Worker startet keinen zweiten Job, solange der erste nicht terminal ist. |
| bestätigt erfolgreich | Async meldet Erfolg, die anschließende Live-Abfrage liefert genau eine VM mit nicht leerer Instance-UUID, und die Workertransaktion hat Ergebnis sowie Identität gespeichert. |
| bestätigt fehlgeschlagen | Async meldet ein terminales Modul-/Ansible-Ergebnis ungleich Erfolg oder die Identitätsprüfung schlägt deterministisch fehl. Es läuft kein bekannter Async-Job dieser Einheit mehr. |
| unklar | Es kann externe Arbeit laufen oder bereits abgeschlossen sein, aber VirtuSphere besitzt weder ein terminales Async-Ergebnis noch einen sicheren Identitätsbeweis. `uncertain` ist niemals ein Synonym für `failed`. |
| übersprungen | Ein Retry übernimmt einen zuvor bestätigten Erfolg samt passender Identität und startet für diese Einheit keinen neuen Create-Job. |
| bearbeitet | Ergebnisstatus ist `succeeded`, `failed`, `uncertain` oder `skipped`. `pending`, `prepared` und `running` sind nicht bearbeitet. |
| erfolgreich für Create | Ergebnisstatus ist `succeeded` oder `skipped`. |
| Gesamtbudget | Maximale Wandzeit des Create-Anteils eines Deploy-Jobs ab erster Create-Einheit, unabhängig von Logausgabe oder Polling. |

---

## 5. Zielarchitektur

### 5.1 Ownership

Der Deploy-Worker besitzt die Reihenfolge, Persistenz, Zeitbudgets, Abbruchgrenzen und Retry-Entscheidung. Ansible besitzt nur die Operation für eine einzelne Ziel-VM und den Status ihres Async-Jobs. ESXi bleibt Autorität über den tatsächlichen VM-Bestand.

```text
Deploy-Worker
  -> materialisiert N Create-Ergebniszeilen
  -> wählt genau die nächste Einheit
  -> Live-Identitätsprüfung für diese Einheit
  -> startet genau einen Ansible-Async-Job
  -> speichert dessen JID
  -> pollt dieselbe JID
  -> verifiziert die VM live
  -> speichert Ergebnis und Identität atomar
  -> beginnt erst dann die nächste Einheit
```

### 5.2 Warum keine Schleife im Create-Playbook verbleibt

Eine Ansible-Schleife kann RUN/DONE lesbar ausgeben, gibt dem PHP-Worker aber weiterhin keine sichere Persistenzgrenze. Bei einem Prozess-, SSH- oder Datenbankfehler weiß der Worker nicht belastbar, welche Schleifen-Items bereits abgeschlossen waren. Deshalb mutiert das Create-Playbook pro Aufruf genau eine VM. Die Reihenfolge liegt im Worker.

### 5.3 Warum Worker-gesteuertes Async verwendet wird

Ein einzelnes `async` mit positivem `poll` hält den Ansible-Prozess offen und erzeugt Lebenszeichen, verliert bei einem Transportabbruch aber weiterhin die Controllerverbindung. Der gewählte Vertrag trennt Start und Statusabfrage:

1. Start mit `poll: 0`, genau eine Einheit.
2. JID sofort dauerhaft speichern.
3. Dieselbe JID mit kurzen, einzelnen Statusaufrufen pollen.
4. Nie eine zweite Einheit starten, solange die erste läuft oder unklar ist.

Damit kann ein neuer SSH-Kanal denselben Async-Job weiter beobachten. `poll: 0` ist ausdrücklich kein Parallelisierungsmerkmal; die Ein-Job-Invariante wird im Worker und in Tests erzwungen.

### 5.4 Geltungsbereich nach Modus

- `create`: verwendet nur die neue Create-Orchestrierung und beendet danach den Job.
- `full`: verwendet zuerst verbindlich dieselbe neue Create-Orchestrierung und danach die unveränderte Reihenfolge Power-Cycle, Export, Start und optional Autostart.
- Alle anderen Modi: keine Create-Ergebniszeilen, keine Create-Async-Verzeichnisse und keine Verhaltensänderung.

---

## 6. Persistentes Datenmodell

### 6.1 Neue Tabelle

Es entsteht eine fokussierte Tabelle `deploy_create_vm_results`. Zusätzlich erhält `deploy_jobs` die nullable Spalte `create_started_at DATETIME NULL`. Sie wird nur für Create-fähige Modi beim ersten tatsächlichen Eintritt in den Create-Abschnitt gesetzt und ist die persistente Quelle für dessen Gesamtbudget. `deploy_jobs.result_json` bleibt ausschließlich der bestehende MAC-Import-/Pipeline-Ergebnisvertrag und wird nicht mit Create-Zwischenständen überladen.

Die Migration erhält bei Umsetzung mechanisch den nächsten freien vierstelligen Schlüssel:

1. Schlüssel aus `VIRTUSPHERE_MIGRATIONS` und dem aktuellen Arbeitsbaum lesen.
2. Höchste numerische Präfixzahl plus eins verwenden.
3. Vor dem Edit erneut prüfen, dass der Schlüssel frei ist.
4. Bei einer Parallelkollision stoppen und neu auflösen; niemals zwei Migrationen unter derselben Nummer zusammenführen.

Zielstruktur:

| Spalte | Typ | Vertrag |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Primärschlüssel. |
| `job_id` | `INT NOT NULL` | FK auf `deploy_jobs`, `ON DELETE CASCADE`. |
| `vm_id` | `INT NULL` | FK auf `deploy_vms`, `ON DELETE SET NULL`; aktive Jobs verhindern VM-Löschung, historische Ergebnisse behalten den Snapshot. |
| `vm_name` | `VARCHAR(191) NOT NULL` | Unveränderter Anzeigename zum Zeitpunkt des Einreihens. Nicht als Identität verwenden. |
| `position` | `INT UNSIGNED NOT NULL` | Einsbasierte Position in der materialisierten Auswahl. |
| `total` | `INT UNSIGNED NOT NULL` | Für alle Zeilen eines Jobs identisch. |
| `action` | `ENUM('create','verify_skip') NOT NULL` | Auszuführender geschlossener Zweig eines neuen Jobs: erstellen oder früheren Erfolg live verifizieren. Recovery ist kein neuer Aktionstyp und bleibt an derselben Zeile/JID. |
| `status` | `ENUM('pending','prepared','running','succeeded','failed','uncertain','skipped')` | Geschlossener Zustandsautomat. |
| `outcome` | `ENUM('created','updated','unchanged') NULL` | Nur bei `succeeded` oder `skipped`. |
| `changed` | `TINYINT(1) NULL` | Nur bei `succeeded` oder `skipped`; `1` bei `created`/`updated`, `0` bei `unchanged`. |
| `existed_before` | `TINYINT(1) NULL` | Ergebnis der unmittelbar vorgeschalteten Live-Prüfung. |
| `precheck_moid` | `VARCHAR(64) NULL` | MOID der erlaubten vorhandenen VM, sonst NULL. |
| `precheck_instance_uuid` | `VARCHAR(64) NULL` | UUID der erlaubten vorhandenen VM, sonst NULL. |
| `async_jid` | `VARCHAR(191) NULL` | Ansible-Job-ID. Nie in eine Shell interpolieren, bevor sie gegen die erlaubte Zeichenklasse validiert wurde. |
| `async_dir` | `VARCHAR(512) NULL` | Unveränderlicher absoluter Pfad `<remote-dir>/async` des über `remote_execution_id` gebundenen Handles. Kein globaler Async-Basispfad. |
| `remote_execution_id` | `BIGINT UNSIGNED NULL` | FK auf genau das generische Remote-Handle `create.vm.<position>` desselben Jobs/Attempts; vor Launch gesetzt und danach unveränderlich. |
| `async_deadline_at` | `DATETIME NULL` | UTC-Ende des Async-Budgets; ein Retry verlängert es nicht. |
| `async_cleaned_at` | `DATETIME NULL` | UTC-Zeit des nachgewiesenen erfolgreichen Cleanup. JID und historischer Pfad bleiben für die Diagnose erhalten. |
| `cleanup_attempts` | `INT UNSIGNED NOT NULL DEFAULT 0` | Kumulative Anzahl tatsächlich gestarteter gezielter Cleanup-Versuche; wird nie zurückgesetzt. |
| `cleanup_auto_attempts` | `INT UNSIGNED NOT NULL DEFAULT 0` | Automatische Versuche seit der letzten auditierten manuellen Freigabe; nur dieser Zähler wird durch `Cleanup erneut versuchen` auf `0` gesetzt. |
| `cleanup_retry_at` | `DATETIME NULL` | Frühester UTC-Zeitpunkt des nächsten begrenzten Cleanup-Versuchs. |
| `cleanup_last_error` | `TEXT NULL` | Redigierter letzter Cleanup-Fehler; niemals Secret- oder Modulargumentdump. |
| `vm_moid` | `VARCHAR(64) NULL` | Live verifizierte MOID nach Erfolg. |
| `vm_instance_uuid` | `VARCHAR(64) NULL` | Live verifizierte dauerhafte Identität nach Erfolg. |
| `error_code` | `VARCHAR(32) NULL` | Wert aus der geschlossenen Create-Fehlercode-SSoT. |
| `error_detail` | `TEXT NULL` | Redigierte technische Kurzursache, keine Zugangsdaten und kein vollständiges Modulargument-Dump. |
| `resumed_from_result_id` | `BIGINT UNSIGNED NULL` | Optionaler FK auf dieselbe Tabelle, `ON DELETE SET NULL`. |
| `started_at` | `DATETIME NULL` | UTC, beim Übergang aus `pending`. |
| `finished_at` | `DATETIME NULL` | UTC, bei einem bearbeiteten Status. |
| `updated_at` | `TIMESTAMP` | Automatisch aktualisiert. |

Indizes und Eindeutigkeiten:

- `UNIQUE (job_id, position)`;
- `UNIQUE (job_id, vm_id)`; `vm_id` ist während des aktiven Jobs nicht NULL;
- `UNIQUE (remote_execution_id)`; NULL bleibt für noch nicht vorbereitete beziehungsweise historische Legacyzeilen erlaubt;
- `INDEX (job_id, status, position)` für Fortschritt und nächste Einheit;
- `INDEX (async_cleaned_at, cleanup_retry_at, async_deadline_at)` für fällige Async-Bereinigung;
- FK auf `job_id`, `vm_id`, `remote_execution_id` und `resumed_from_result_id` wie oben festgelegt; ein Contracttest beweist identischen Job/Attempt/Step-Key.

`deploy_jobs.create_started_at` wird beim Claim nicht gesetzt. Der Worker setzt es unmittelbar vor der ersten Create-Einheit per `COALESCE(create_started_at, UTC_TIMESTAMP())`; Recovery desselben Jobs verändert den Wert nicht. Ein zulässiger späterer Retry ist ein neuer Job und erhält einen neuen Wert. Die unveränderliche Deadline einer laufenden JID bleibt am Quelljob und wird niemals verlängert oder kopiert.

### 6.2 Materialisierung der Auswahl

Die Ergebniszeilen werden in derselben Queue-Transaktion angelegt, die den Job erzeugt:

1. Mission wird wie bisher `FOR UPDATE` gesperrt.
2. Die ausgewählten VM-IDs werden validiert.
3. Eine leere Auswahl wird jetzt für Create-fähige Modi auf die aktuelle vollständige Missionsmenge materialisiert.
4. Ist auch diese materialisierte Missionsmenge leer, wird das Einreihen mit der lokalisierten Meldung „Die Mission enthält keine VMs“ abgelehnt; es entstehen weder Job noch Ergebniszeilen.
5. Sortierung ist ausschließlich `ORDER BY vm_name, id`.
6. `payload_json.vm_ids` wird für Create-fähige Jobs mit der materialisierten ID-Liste gespeichert, nicht mehr als leeres „alle später“.
7. `position` und `total` werden aus genau dieser Liste erzeugt; neue Nicht-Retry-Zeilen erhalten `action=create`.
8. Job und Ergebniszeilen committen gemeinsam oder gar nicht.

Damit kann eine später hinzugefügte VM den Umfang eines bereits eingereihten oder geplanten Jobs nicht still erweitern. Gelöschte ausgewählte VMs werden durch den bestehenden Aktivjobschutz verhindert; ein Verstoß führt vor Mutation zu einem klaren Jobfehler und niemals zur Ausweitung auf die ganze Mission.

### 6.3 Konstanten-SSoT

Ein neues fokussiertes Modul `Docker/WebAPI/lib/deploy_create_constants.php` besitzt:

- alle Ergebnisstatuswerte und ihre geordnete Menge;
- die zwei Aktionswerte `create`, `verify_skip`; Recovery wird aus Status/JID/Remote-Handle desselben Jobs abgeleitet und ist kein Aktionswert;
- alle Outcomewerte;
- alle Create-Fehlercodes;
- Poll-Intervall;
- Controller-/Statuskommando-Idle- und Gesamtbudget;
- erlaubte JID-Zeichenklasse und Maximallänge;
- Async-Basisverzeichnisname;
- Markerpräfix und Protokollversion;
- Ableitung des Create-Gesamtbudgets aus `VIRTUSPHERE_SSH_TOTAL_TIMEOUT_SECONDS = 14400`.

`deploy_constants.php` lädt dieses Modul als Fassade. ENUM-Reihenfolge in Migration und Frischschema wird durch den bestehenden Enum-Sync-Guard oder dessen Erweiterung geprüft. Es entsteht keine zweite handgepflegte Statusliste in Portal, Worker oder Tests.

Die Startwerte sind kein Implementierungsspielraum, sondern Teil der SSoT:

| Konstante | Wert | Zweck |
|---|---:|---|
| `VIRTUSPHERE_CREATE_POLL_INTERVAL_SECONDS` | `30` | Abstand zwischen erfolgreichen `running`-Polls. |
| `VIRTUSPHERE_CREATE_CONTROL_IDLE_TIMEOUT_SECONDS` | `120` | Idle-Grenze eines kurzen Prepare-/Launch-/Poll-/Cleanup-Aufrufs. |
| `VIRTUSPHERE_CREATE_CONTROL_TOTAL_TIMEOUT_SECONDS` | `300` | Gesamtgrenze eines einzelnen kurzen Steueraufrufs. |
| `VIRTUSPHERE_CREATE_JID_DISCOVERY_INTERVAL_SECONDS` | `5` | Abstand bei JID-Wiederfindung nach verlorener Startantwort. |
| `VIRTUSPHERE_CREATE_JID_DISCOVERY_TIMEOUT_SECONDS` | `90` | Maximales Wiederfindefenster; danach `launch_unconfirmed`. |
| `VIRTUSPHERE_CREATE_TRANSPORT_BACKOFF_MIN_SECONDS` | `5` | Erster Poll-Wiederholabstand bei Transportfehler. |
| `VIRTUSPHERE_CREATE_TRANSPORT_BACKOFF_MAX_SECONDS` | `60` | Maximaler Poll-Wiederholabstand; Verdopplung mit Deckel. |
| `VIRTUSPHERE_REMOTE_CLEANUP_BATCH_SIZE` | `25` | Generische Remote-SSoT: maximal bearbeitete Cleanup-Einheiten je Worker-Runde. |
| `VIRTUSPHERE_REMOTE_CLEANUP_MAX_AUTO_ATTEMPTS` | `8` | Generische Remote-SSoT: danach sichtbare manuelle Freigabe statt weiterer Hot-Loop. |
| `VIRTUSPHERE_REMOTE_CLEANUP_BACKOFF_MIN_SECONDS` | `300` | Generische Remote-SSoT: erster Cleanup-Retry. |
| `VIRTUSPHERE_REMOTE_CLEANUP_BACKOFF_MAX_SECONDS` | `86400` | Generische Remote-SSoT: maximaler Retry-Abstand; Verdopplung mit Deckel. |

Das Create-Gesamtbudget ist verbindlich `VIRTUSPHERE_SSH_TOTAL_TIMEOUT_SECONDS = 14400` Sekunden. Guards erzwingen zusätzlich: Discovery-Intervall kleiner Discovery-Timeout, Control-Total kleiner `VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS`, Poll-Intervall kleiner Stale-Grenze, Min-Backoff nicht größer Max-Backoff und alle Werte positiv.

### 6.4 Geschlossene Fehlercodes

Die Create-SSoT enthält exakt:

| Code | Bedeutung | Retry automatisch möglich? |
|---|---|---|
| `identity_conflict` | Name vorhanden, gespeicherte UUID fehlt oder widerspricht. | Nein, erst prüfen/adoptieren. |
| `module_failed` | Async-Job endete mit bestätigtem Modulfehler. | Ja, nach Behebung. |
| `launch_unconfirmed` | Startantwort/JID konnte nicht bestätigt oder im dedizierten Verzeichnis gefunden werden. | Nein, Fail-closed-Reconciliation aus 10.5. |
| `async_state_missing` | Gespeicherte JID besitzt keinen lesbaren Async-Status. | Nein, Fail-closed-Reconciliation aus 10.5. |
| `identity_result_invalid` | Terminaler Erfolg, aber Live-Ergebnis fehlt, ist doppelt oder widerspricht der UUID. | Nein, erst prüfen. |
| `transport_lost` | Poll-/Steuertransport ging verloren; JID bleibt vorhanden und wiederaufnehmbar. | Wiederaufnahme derselben JID, kein neuer Create. |
| `job_timeout` | Gesamtbudget erreicht. | Nur Wiederaufnahme/Prüfung, nie blinder Neustart. |
| `protocol_error` | Marker, Resultatdatei oder Zustandsübergang verletzt den Vertrag. | Nein, Softwarefehler beheben. |
| `ownership_lost` | Worker besitzt den Job nicht mehr. | Dieser Worker schreibt keinen Ausgang; Recovery desselben Jobs übernimmt. |
| `operator_released` | Administrator hat nach Prüfung „nicht erstellt“ bestätigt. | Ja. |

Freie Ansible-Texte bestimmen nie den Code. Der Code entsteht aus dem kontrollierten Zweig, der den Fehler feststellt. `error_detail` darf eine redigierte Modulursache ergänzen.

---

## 7. Zustandsautomat pro Create-Einheit

### 7.1 Erlaubte Übergänge

```text
pending  -> prepared
pending  -> failed            (Live-Identitäts-, Skip-Verifikations- oder Eingabefehler)
pending  -> skipped           (nur `action=verify_skip` nach aktueller Live-Prüfung)

prepared -> running           (JID dauerhaft gespeichert)
prepared -> uncertain         (Launch nicht bestätigbar)
prepared -> failed            (Launch sicher abgelehnt, kein Async-Job aktiv)

running  -> running           (POLL, updated_at wird aktualisiert)
running  -> succeeded         (Async-Erfolg + Live-Identität + DB-Commit)
running  -> failed            (terminaler Async-Fehler)
running  -> uncertain         (Status verloren oder Gesamtbudget erreicht)

uncertain -> running          (dieselbe JID wiedergefunden/wiederaufgenommen)
uncertain -> succeeded        (Live-Identität nach ausdrücklicher/gesicherter Reconciliation)
uncertain -> failed           (bestätigte Operatorfreigabe „nicht erstellt“)

succeeded, failed, skipped -> keine weitere Änderung innerhalb desselben Jobs
```

Jeder Übergang ist ein Repository-CAS gegen bisherigen Status, `job_id`, `vm_id`, Jobstatus und Workerbesitz. Ein doppeltes Poll-Ergebnis oder ein alter Worker darf einen terminalen Zustand nicht überschreiben. Ein idempotenter Replay desselben terminalen Ergebnisses liefert denselben Zustand ohne zweite Status-/Auditzeile.

### 7.2 Invarianten

Für jede Datenbanktransaktion und jeden Test gelten:

1. Pro aktivem Missionsjob höchstens eine Create-Zeile mit `status = 'running'`.
2. `running` verlangt nicht leere, validierte `async_jid`, `async_dir` und `async_deadline_at`.
3. `succeeded` verlangt `outcome`, `changed`, `vm_moid`, `vm_instance_uuid` und `finished_at`.
4. `failed` und `uncertain` verlangen `error_code`, `error_detail` und `finished_at`.
5. `skipped` verlangt `action = 'verify_skip'`, `resumed_from_result_id`, kopierte verifizierte Identität, ein erfolgreiches Quellergebnis und eine im aktuellen Job erneut bestätigte Live-UUID.
6. `created` verlangt `existed_before = 0` und `changed = 1`.
7. `updated` verlangt `existed_before = 1` und `changed = 1`.
8. `unchanged` verlangt `existed_before = 1` und `changed = 0`.
9. `existed_before = 0` zusammen mit `changed = 0` ist kein Erfolg, sondern `identity_result_invalid`.
10. Die nächste VM darf erst starten, wenn keine Zeile `prepared`, `running` oder `uncertain` ist.
11. Ein `uncertain` stoppt den aktuellen Job immer. Es wird nie zur nächsten VM weitergegangen.
12. Ein bestätigtes `failed` fährt nach Ownership-/Cancelprüfung mit der nächsten VM fort. Nur die geschlossenen globalen Stopklassen aus 9.7 verhindern dies.
13. `action=create` beginnt höchstens einen neuen Async-Job; `action=verify_skip` beginnt nie einen Async-Job. Wiederaufnahme ist kein materialisierter Retry-Aktionstyp, sondern ein Zustandsübergang derselben Quellzeile mit derselben JID.
14. `pending`, `prepared` und `running` haben `finished_at = NULL`. Bei der seltenen Reconciliation `uncertain -> running` werden `finished_at`, `error_code` und `error_detail` im selben CAS geleert; die frühere Unsicherheit bleibt in Audit und Joblog erhalten.
15. `outcome`, `changed`, MOID und Instance-UUID sind nur für `succeeded`/`skipped` gesetzt. Fehler- und Unklar-Zeilen dürfen keinen angeblichen Outcome tragen.

---

## 8. Ansible- und Worker-Protokoll

### 8.1 Generierte Ziel-ID

`ansible_serverlist_yml()` ergänzt jedes `vm_configurations`-Element um:

```yaml
portal_vm_id: 123
```

Die ID ist der Selektor. Der VM-Name bleibt nur Anzeige und ESXi-Suchadresse. Jedes Create-Steuerplaybook filtert auf `portal_vm_id` und assertiert vor jedem Modulaufruf exakt ein Ergebnis. Null oder mehr als eins ist `protocol_error` und führt zu keiner Mutation.

### 8.2 Playbook-Aufteilung

Die Umsetzung verwendet diese Dateien:

- `Ansible/createVMPrepare-ESXi_playbook.yml`: read-only Live-Prüfung genau einer VM; gibt einen strukturierten `prepared`-Marker aus und startet keine Mutation;
- `Ansible/createVMs-ESXi_playbook.yml`: wiederholt dieselbe Live-Prüfung, verlangt exakte Übereinstimmung mit dem gespeicherten Prepare-Ergebnis und startet erst dann genau eine VM mit `async`/`poll: 0`;
- `Ansible/createVMStatus-ESXi_playbook.yml`: genau eine Statusabfrage für eine JID, bei Erfolg anschließende Live-Identitätsprüfung;
- `Ansible/createVMCleanup-ESXi_playbook.yml`: gezieltes `async_status mode=cleanup` für genau eine validierte JID;
- `Ansible/emit_create_result.py`: liest eine lokale Resultatdatei, validiert das geschlossene Schema und gibt genau einen versionierten Base64url-Marker aus.

Die gemeinsame Identitätsabfrage liegt genau einmal in `Ansible/tasks/create_identity_check.yml` und wird von Prepare, Launch und terminalem Status inkludiert. Markeraufbau/-validierung liegt in `emit_create_result.py`. Es gibt keine kopierte zweite Identitätsmatrix in den drei Playbooks. Kein neues Playbook darf wieder über `vm_configurations` mutierend loopen.

Die doppelte Prüfung ist beabsichtigt: Der Prepare-Aufruf schafft die persistierbare Grenze `prepared`; die Prüfung im Launch-Aufruf schließt Änderungen zwischen Prepare und Mutation so weit wie mit der vorhandenen Standalone-ESXi-Schnittstelle möglich. Das Launch-Playbook vergleicht `existed_before`, MOID und Instance-UUID mit den vom Worker übergebenen gespeicherten Prepare-Werten. Jede Abweichung führt vor `vmware_guest` zu einem strukturierten `rejected`-Marker mit `error_code=identity_conflict`; es gibt dann keine JID und keine Mutation. Freier Fehlertext wird dafür nicht ausgewertet.

Der mutierende Modulaufruf hat genau zwei geschlossene Selektionszweige:

- `existed_before=false`: `vmware_guest` erhält den Snapshotnamen und keine UUID. Das ist ausschließlich der Create-Zweig.
- `existed_before=true`: `vmware_guest` erhält ausschließlich die bereits gebundene Instance-UUID als Selektor sowie `use_instance_uuid=true`; der Name wird nicht als Modul-Selektor übergeben. Dadurch kann eine nach der Prüfung verschwundene eigene VM nicht wegen der Ansible-Regel „UUID wird bei Neuerstellung ignoriert“ versehentlich unter ihrem Namen neu entstehen.

Ein unbekannter dritter Zweig ist `protocol_error`. Dass der Name im Absent-Zweig außerhalb VirtuSphere noch im Mikrofenster nach dem zweiten Check belegt werden kann, bleibt die ausdrücklich dokumentierte Supportgrenze aus Abschnitt 18.

### 8.3 Async-Verzeichnis

Das Async-Verzeichnis ist kein globaler Home-Unterordner, sondern genau der fest benannte Unterordner des vor dem Launch persistierten generischen Remote-Handles `create.vm.<position>`:

```text
<remote-dir>/async
```

Regeln:

- Der kanonische absolute `<remote-dir>` stammt ausschließlich aus dem generischen Remote-Handle und ist bereits an Instance-ID, zufällige Generation, Job, Attempt, Step-Key und Run-Token gebunden. Das Portal liefert keinen Pfad.
- Der Launcher erstellt `async/` ohne Symlinkfolge mit Modus `0700`.
- Resultat-/Statusdateien werden als geheim behandelt und erhalten höchstens `0600`.
- Ein Löschbefehl darf nur den vorher per `lstat`/Realpath/Owner/Mode validierten exakten Unterordner dieses Handles entfernen.
- Kein `rm -rf` arbeitet mit leerem Wert, `~`, `$HOME`, Workspace-Root oder Glob.
- Nach terminalem JID-Ergebnis und committed Live-Identitäts-/DB-/Evidence-Ausgang erfolgt `async_status mode=cleanup`; erst danach darf `async/` entfernt und `async_cleaned_at` gesetzt werden.
- Bei `uncertain` bleibt der Ordner unabhängig vom bloßen Ablauf von `async_deadline_at` erhalten. Cleanup ist erst nach abgeschlossener Reconciliation oder append-only dokumentierter manueller Resolution zulässig. Eine Sicherheitsmarge allein ist kein Löschbeweis.

`async/` ist ausdrücklich nicht das Arbeitsverzeichnis eines kurzen Poll-/Cleanup-Controllers. Der Create-Launcher schreibt den persistierten absoluten Pfad als `ansible_async_dir` in die allowlist-validierte Extra-Var-Datei des Handles; freie CLI-Interpolation und `ANSIBLE_ASYNC_DIR` im Task-`environment` sind verboten. Spätere Poll-/Cleanup-Aufrufe erhalten ein frisches, ausschließlich diesem kurzen Aufruf gehörendes Arbeitsverzeichnis, setzen dieselbe validierte `ansible_async_dir`-Variable und entfernen ihr Arbeitsverzeichnis am Ende. Sie setzen weder frühere `accounts.yml` noch ein globales `~/.ansible_async` voraus. Secretinputs des Launch-Handles werden nach bewiesenem Controllerende getrennt von `async/` bereinigt.

### 8.4 Strukturierter Marker

Der einzige maschinenlesbare stdout-Vertrag lautet:

```text
::virtusphere-create:: v1 <base64url-ohne-padding>
```

Der dekodierte JSON-Inhalt besitzt je Ereignistyp exakt diese Felder:

Vorbereitet:

```json
{
  "event": "prepared",
  "portal_vm_id": 123,
  "vm_name": "ATeP04-001",
  "existed_before": false,
  "precheck_moid": null,
  "precheck_instance_uuid": null
}
```

Start:

```json
{
  "event": "launched",
  "portal_vm_id": 123,
  "vm_name": "ATeP04-001",
  "async_jid": "123456789.123",
  "async_dir": "/home/virtusphere/.local/state/virtusphere/i-abcd/g-9f2/jobs/105/1/create.vm.1/r-7ac/async",
  "existed_before": false,
  "precheck_moid": null,
  "precheck_instance_uuid": null
}
```

Noch laufend:

```json
{
  "event": "running",
  "portal_vm_id": 123,
  "async_jid": "123456789.123"
}
```

Erfolgreich:

```json
{
  "event": "succeeded",
  "portal_vm_id": 123,
  "vm_name": "ATeP04-001",
  "async_jid": "123456789.123",
  "changed": true,
  "moid": "vm-123",
  "instance_uuid": "503c...",
  "power_state": "poweredOff"
}
```

Fehlgeschlagen:

```json
{
  "event": "failed",
  "portal_vm_id": 123,
  "async_jid": "123456789.123",
  "error": "redigierte technische Ursache"
}
```

Vor dem Launch abgelehnt:

```json
{
  "event": "rejected",
  "portal_vm_id": 123,
  "vm_name": "ATeP04-001",
  "error_code": "identity_conflict",
  "error": "redigierte technische Ursache"
}
```

Der Parser akzeptiert:

- genau Protokollversion `v1`;
- genau einen Marker je Steueraufruf;
- nur die sechs Ereignisse `prepared`, `launched`, `running`, `succeeded`, `failed`, `rejected` und deren jeweils exakt bekannte Felder;
- passende `portal_vm_id`, JID und bei vorhandener Namensangabe den Snapshotnamen;
- Base64url-Zeichen ohne Padding;
- begrenzte Nutzlastgröße;
- MOID/UUID/JID nur innerhalb ihrer Zeichen- und Längengrenzen.

Fehlender, doppelter, zu großer oder widersprüchlicher Marker ist `protocol_error`. Er wird nicht aus normalen Ansible-Zeilen rekonstruiert.

### 8.5 Secret-Vertrag

- `accounts.yml` liegt ausschließlich unter `secrets/` des gebundenen Handles beziehungsweise im frischen Verzeichnis eines kurzen Folgecontrollers, bleibt Modus `0600` und wird nach bewiesenem Controllerende durch den generischen Secret-Cleanup entfernt. Von den geheimen Launchinputs überlebt nichts; nicht geheime Handle-Marker und die separate Ansible-Async-Statusdatei bleiben als Recovery-Evidenz erhalten.
- Passwortwerte erscheinen ausschließlich als Modulargument beziehungsweise geschützte Variable.
- `emit_create_result.py` darf nur die oben aufgelisteten sicheren Felder lesen und ausgeben.
- Kein `debug` gibt registrierte Gesamtobjekte, `invocation.module_args`, Environment oder `accounts.yml` aus.
- Jeder technische Fehler durchläuft zusätzlich `deploy_worker_redact_secrets()` mit ESXi- und Ansible-Secret, bevor er DB, Joblog oder Portal erreicht.
- Async-Dateien werden als potenziell geheim behandelt, auch wenn die verwendete Ansible-Version bekannte `no_log`-Felder maskiert.

---

## 9. Exakter Worker-Ablauf

### 9.1 Vorbereitungsphase je Job

1. Job wie bisher per Ownership-CAS claimen.
2. Payload lesen und Modus normalisieren.
3. Materialisierte Create-Zeilen laden und prüfen: fortlaufende Positionen, identisches `total`, VM-IDs gehören zur Mission, keine Duplikate.
4. Bei Modus `create` keine VM-Lifecycle- oder MECM-Schreibvorgänge ausführen.
5. Bei `full` den bisherigen Pipeline-Lifecycle markieren.
6. Ansible- und ESXi-Zugang laden, Secrets entschlüsseln, API-Basis-URL wie bisher auflösen.
7. Preflight einmal je Job ausführen.
8. Unveränderliche Serverlist-/Variableninhalte einmal je Job erzeugen und im Worker halten. Prepare/Launch verwendet das vor Mutation persistierte generische Remote-Handle; kurze Poll-/Cleanup-Aufrufe erhalten ein frisches Controller-Arbeitsverzeichnis und entfernen nur dieses am Aufrufende. Weder `<remote-dir>/async` noch Handle-Marker werden von einem Aufruf-Trap erfasst.
9. `deploy_jobs.create_started_at` per CAS/`COALESCE` setzen; maßgeblich ist diese persistierte UTC-Zeit, nicht Prozessspeicher, Claimzeit oder `updated_at`.
10. Vor der ersten VM erneut Ownership und Cancelstatus prüfen.

### 9.2 Auswahl der nächsten Einheit

Reihenfolge ist `position ASC`.

- `skipped` und `succeeded`: bereits im aktuellen Job terminal; nur Fortschritt anzeigen, nicht ausführen.
- `failed`: bei einem Retry als `pending` materialisiert; innerhalb desselben Jobs nicht erneut ausführen.
- `pending/action=create`: normal vorbereiten und bei erfolgreicher Prüfung neu/erneut starten.
- `pending/action=verify_skip`: `[n/total] RUN create <vm_name>` schreiben und read-only live prüfen. Genau eine VM mit derselben nicht leeren Instance-UUID führt per CAS zu `skipped` und `[n/total] DONE ... skipped`; kein Treffer, leerer oder abweichender UUID-Beweis führt zu `failed/identity_conflict`, nie zu einem automatischen Create.
- `prepared`: Launch wiederaufnehmen, ohne die Vorprüfung als Erfolg umzudeuten.
- `running/action=create`: vorhandene JID pollen; nach Leasewechsel bindet Recovery genau diese Zeile/JID wieder an und darf den Launchpfad nicht aufrufen.
- `uncertain`: Job stoppen. Nur die definierten Reconciliation-Wege dürfen den Zustand ändern.

Vor jeder neuen Einheit gilt die Datenbankinvariante „keine andere prepared/running/uncertain-Zeile dieses Jobs“. Ein Verstoß ist `protocol_error`, beendet den Job und startet nichts Neues.

### 9.3 RUN und Live-Identitätsprüfung

1. `[n/total] RUN create <vm_name>` als SYSTEM-Zeile schreiben.
2. Read-only `createVMPrepare-ESXi_playbook.yml` für genau die Portal-VM-ID aufrufen. Fehlender, doppelter oder ungültiger Marker ist `protocol_error`; es folgt keine Mutation.
3. Das Prepare-Playbook verwendet `vmware_vm_info` mit dem exakten Snapshotnamen und `vm_type=vm`, filtert die zurückgegebene Liste nochmals exakt auf `guest_name` und zählt sie. Bei genau einem Treffer liest es anschließend `vmware_guest_info` über dessen MOID und entnimmt ausschließlich `instance.moid` und `instance.instance_uuid`. Dadurch wird weder das in `vmware_vm_info` je Collectionversion unterschiedlich dokumentierte UUID-Feld geraten noch die `first/last`-Mehrfachtrefferlogik von `vmware_guest_info` verwendet.
4. Null Treffer: `existed_before=0`, Mutation erlaubt.
5. Genau ein Treffer und gespeicherte Portal-UUID entspricht ohne Beachtung der Groß-/Kleinschreibung der Live-Instance-UUID: `existed_before=1`, Mutation erlaubt.
6. Mehrere Treffer, leere gespeicherte UUID bei vorhandenem Treffer, leere Live-UUID oder abweichende UUID: `identity_conflict`, keine Mutation.
7. MOID allein ist nie Identitätsbeweis.
8. Das Prüfergebnis wird zusammen mit `started_at` per CAS `pending -> prepared` dauerhaft gespeichert.

Die Prüfung liegt unmittelbar vor jeder VM, nicht einmal gesammelt vor allen VMs. Dadurch wird eine zwischen VM 1 und VM 15 extern erschienene Namensgleichheit vor der betroffenen Mutation erkannt.

### 9.4 Async-Launch

1. Restliches Gesamtbudget aus persistiertem Startzeitpunkt und SSoT berechnen.
2. Bei `remaining <= 0`: `job_timeout`, kein Launch.
3. Dediziertes Async-Verzeichnis erstellen und Pfad in der Create-Zeile speichern.
4. Vor Launch das gebundene Remote-Handle `create.vm.<position>` committen. Dessen Launcher startet `PYTHONUNBUFFERED=1 ansible-playbook createVMs-ESXi_playbook.yml` in einer systemd-User-Unit mit `Type=exec`, `ExitType=cgroup`, `KillMode=control-group`, der in einer `0600`-Extra-Var-Datei gesetzten und aus dem Manifest validierten Variable `ansible_async_dir=<remote-dir>/async`, numerischer Ziel-ID, gespeicherten Prepare-Werten und ganzzahligem `async = remaining`, `poll = 0`.
5. Das Playbook wiederholt die Live-Prüfung. Weicht `existed_before`, MOID oder Instance-UUID von den gespeicherten Prepare-Werten ab, gibt es vor der Mutation genau einen `rejected/identity_conflict`-Marker aus. Der Worker setzt `prepared -> failed`; eine JID darf in diesem Zweig nicht existieren.
6. Im normalen Zweig Exitcode und genau einen `launched`-Marker verlangen. Dessen Ziel-ID, Name und Prepare-Werte müssen exakt den gespeicherten Werten entsprechen.
7. JID syntaktisch validieren.
8. Create-Zeile per CAS `prepared -> running` einschließlich JID und unveränderlicher Deadline schreiben.
9. Das Remote-`launch_result.json` bestätigt nur den Launchcontroller. Solange Cgroup oder JID aktiv ist, bleibt die generische Remote-Ausführung `active`; ausschließlich terminaler `async_status` plus Live-Identität darf sie und die Create-Zeile abschließen.
10. Wenn der Startkanal nach möglichem Launch verloren geht, das ausschließlich dieser Einheit gehörende Async-Verzeichnis höchstens 90 Sekunden lang alle 5 Sekunden über kurze neue SSH-Kanäle prüfen und Workerheartbeat/Cancel zwischen den Versuchen aktualisieren. Danach gilt:
   - genau eine: JID speichern und dieselbe Arbeit pollen;
   - keine: `launch_unconfirmed` und `uncertain`;
   - mehr als eine oder ungültiger Name: `protocol_error` und `uncertain`.
11. Nie auf Verdacht ein zweites `vmware_guest` starten.

### 9.5 Poll-Schleife

1. Vor jedem Poll DB-Kanal stabilisieren und Ownership lesen.
2. Bei `running`: genau die gespeicherte JID und das gespeicherte Verzeichnis abfragen.
3. Ein Statusaufruf ist kurz und verwendet eigene Controller-Idle-/Gesamtbudgets aus der SSoT.
4. `running`-Antwort:
   - `[n/total] POLL create <vm_name> running` loggen;
   - Ergebniszeile `updated_at` per CAS aktualisieren;
   - Worker-Heartbeat über den bestehenden DB-Kanal aktualisieren;
   - bis zum nächsten Poll-Intervall warten, ohne einen zweiten Remoteaufruf parallel zu starten.
5. Erfolgsantwort: Abschnitt 9.6.
6. Fehlerantwort: Abschnitt 9.7.
7. Transportfehler mit vorhandener JID:
   - `transport_lost` technisch loggen;
   - keine zweite VM starten;
   - denselben Poll mit begrenztem Backoff und derselben JID wiederholen, solange Gesamtbudget und Workerbesitz gelten;
   - erst bei Gesamtbudget oder nicht mehr auffindbarem Async-Status `uncertain` setzen.
8. Cancelstatus `cancelling` beendet die laufende VM nicht hart. Der Worker pollt sie bis terminal oder unklar, speichert den Ausgang und bestätigt danach `cancelled`, ohne die nächste Einheit zu beginnen.
9. Datenbankausfall beendet den Async-Job nicht. Der Worker startet aber keine nächste Einheit, bevor der Ausgang der aktuellen Einheit dauerhaft gespeichert werden konnte.

Das Poll-Intervall ist exakt 30 Sekunden; das unabhängige Gesamtbudget beträgt 14.400 Sekunden. Der Guard erzwingt unter anderem:

```text
0 < VIRTUSPHERE_CREATE_POLL_INTERVAL_SECONDS
VIRTUSPHERE_CREATE_POLL_INTERVAL_SECONDS < VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS
VIRTUSPHERE_CREATE_CONTROL_TOTAL_TIMEOUT_SECONDS < VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS
```

### 9.6 Erfolgsabschluss

Nach terminalem Async-Erfolg:

1. Dieselbe gemeinsame `create_identity_check.yml`-Taskfolge für den exakten Snapshotnamen ausführen und das Ergebnis weiterhin der unveränderten Portal-VM-ID zuordnen.
2. Genau eine VM verlangen.
3. Nicht leere MOID und Instance-UUID verlangen.
4. Bei `existed_before=1` muss die UUID der gespeicherten/pregeprüften UUID entsprechen.
5. Bei `existed_before=0` muss `changed=true` sein. Andernfalls kein automatisches Binden.
6. Outcome deterministisch ableiten:
   - absent vorher + changed = `created`;
   - vorhanden vorher + changed = `updated`;
   - vorhanden vorher + unchanged = `unchanged`.
7. Eine DB-Transaktion sperrt Job, Create-Zeile und Portal-VM:
   - Workerbesitz und zulässigen Jobstatus prüfen;
   - terminalen Replay erkennen;
   - gespeicherte UUID leer: Live-UUID setzen;
   - gespeicherte UUID gleich: MOID auffrischen;
   - gespeicherte UUID abweichend: keine VM- oder Ergebnis-Erfolgsschreibung, `identity_result_invalid`;
   - Create-Zeile `running -> succeeded` mit Ergebnisdaten setzen;
   - im Modus `create` kein Lifecycle-Statusereignis schreiben; im Modus `full` nur das Statusereignis des tatsächlich ausgeführten bestehenden Pipeline-Lifecycle-Übergangs schreiben;
   - committen.
8. Erst nach Commit `[n/total] DONE create <vm_name> <outcome>` loggen.
9. Async-Status gezielt bereinigen.
10. Ownership/Cancel erneut prüfen.
11. Erst dann nächste Einheit wählen.

### 9.7 Bestätigter Fehler

1. Modul-/Ansible-Fehler redigieren und Create-Zeile `running -> failed` mit `module_failed` setzen.
2. Identitätsfehler vor Launch setzt `pending/prepared -> failed` mit `identity_conflict`.
3. `[n/total] FAIL create <vm_name> <error_code>` loggen.
4. Async-Status bereinigen, wenn er terminal ist.
5. Ownership/Cancel prüfen und nach einem bestätigten per-VM-Fehler die nächste `pending`-Einheit starten.
6. Bei einem globalen Sicherheits-/Protokollfehler keine nächste Einheit starten.

Globale Stop-Fehler, die trotz der Fortsetzungsentscheidung immer stoppen:

- `protocol_error`;
- `ownership_lost`;
- nicht wiederherstellbarer DB-Ausfall nach terminalem Remoteausgang;
- `job_timeout`;
- jede Form von `uncertain`;
- fehlende/ungültige Zugangsdaten oder fehlgeschlagener gemeinsamer Preflight.

### 9.8 Jobabschluss nach Create

Create-only:

| Ergebnisbestand | Jobstatus |
|---|---|
| alle Zeilen `succeeded` oder `skipped` | `succeeded` |
| mindestens eine Create-erfolgreiche Zeile und mindestens eine `failed`, `uncertain` oder terminal verbliebene `pending` | `partial` |
| keine Create-erfolgreiche Zeile und mindestens eine `failed`/`uncertain` | `failed` |
| Cancel angefordert | `cancelled`; per-VM-Zeilen bleiben wahr und unverändert |

Full:

- Nur wenn alle Create-Zeilen `succeeded` oder `skipped` sind, beginnen Power-Cycle/Export/Start/Autostart.
- Ein Create-Teilfehler beendet die vollständige Pipeline vor Power-Cycle.
- Der Jobstatus folgt dann derselben Create-Matrix.
- Erst der bestehende MAC-Import-/Pipelinevertrag darf später `partial` aufgrund eines Exportteilfehlers setzen.
- `result_json` bleibt das MAC-Ergebnis; Create-Zusammenfassung wird aus `deploy_create_vm_results` gelesen.

Der Jobabschluss schreibt eine einzige Systemzusammenfassung, zum Beispiel:

```text
Create summary: total=15 created=14 updated=0 unchanged=0 skipped=0 failed=0 uncertain=1 not_started=0
```

Die Schlüssel sind technische, stabile Logfelder und werden nicht lokalisiert. Sichtbare Portalbezeichnungen kommen aus DE/EN-Katalogen.

---

## 10. Retry- und Reconciliation-Vertrag

### 10.1 Allgemeine Regel

Ein Retry erzeugt wie bisher einen neuen Job. Der alte Job, seine Create-Zeilen und sein Log bleiben unverändert. Der neue Job verlinkt die Quelle dauerhaft über die Ergebniszeilen und zusätzlich wie bisher im Systemlog.

### 10.2 Initialisierung eines Create-Retry

Für jede VM der ursprünglichen materialisierten Auswahl:

| Quellstatus | Neue Zeile | Aktion |
|---|---|---|
| `succeeded` | `pending`, `action=verify_skip` | Auf die echte Erfolgszeile verweisen und deren Identität/Outcome als erwarteten Wert kopieren. Der neue Worker bestätigt die UUID live, bevor die neue Zeile `skipped` wird. Kein neuer Create. |
| `skipped` | `pending`, `action=verify_skip` | `resumed_from_result_id` auf die letzte echte `succeeded`-Quelle auflösen. Keine Skip-Kette als Identitätsbeweis verwenden; Live-Prüfung im neuen Job bleibt Pflicht. |
| `failed` | `pending`, `action=create` | Nach Fehlerbehebung neu ausführen. |
| `pending` | `pending`, `action=create` | War nicht begonnen, normal ausführen. |
| `prepared` | Retry blockieren | Derselbe aktive Quelljob wird über sein Remote-Handle wiederangebunden. Erst ein bewiesener Nichtstart oder eine abgeschlossene Reconciliation darf ihn terminalisieren und einen neuen Retryplan erzeugen. |
| `running` mit JID | Retry blockieren | JID, Async-Verzeichnis und Deadline bleiben ausschließlich beim aktiven Quelljob. Recovery desselben Jobs pollt weiter; es entsteht keine neue Ergebniszeile in einem neuen Job. |
| `uncertain` mit gültiger JID | Retry blockieren | Am selben Quelljob wird `recovery_requested_at` gesetzt und dessen generisches Remote-Handle erhält `reconciliation_state=pending`; Recovery pollt die JID oder versöhnt live. Erst der bewiesene Ausgang erlaubt einen neuen Job. |
| `uncertain` ohne wiederauffindbare JID | Retry blockieren | Fail-closed-Reconciliation aus 10.5 erforderlich. |

Die Retry-Erzeugung führt keine angebliche Live-Prüfung innerhalb der Queue-HTTP-Anfrage aus. Vor der Materialisierung muss der gemeinsame `deploy_retry_blockers()`-Aggregator beweisen, dass keine Remote-Ausführung/Reconciliation mehr aktiv oder ungeklärt ist, die VM-Identität geklärt ist und der Netzwerkvertrag des tatsächlichen Retrys-Scope erfüllt ist. Danach materialisiert sie den geschlossenen Aktionsplan. `verify_skip` wird später durch den Worker über denselben read-only Prepare-Vertrag wie jede andere Identitätsprüfung ausgeführt. Ein neuer Job erhält ausschließlich für neu zulässige Einheiten sein eigenes Gesamtbudget; eine laufende alte Deadline wird weder kopiert noch verlängert.

### 10.3 Full-Retry

Bei `full` bleibt die ursprüngliche vollständige VM-Auswahl im neuen Payload:

- bereits bestätigte Create-Erfolge werden im neuen Create-Abschnitt `skipped`;
- nur nach abgeschlossener Recovery als sicher fehlgeschlagen oder sicher nicht gestartet bestätigte Create-Einheiten werden im neuen Job mit eigener neuer JID erstellt; eine Wiederaufnahme bleibt ausschließlich beim aktiven Quelljob;
- sobald der gesamte Create-Abschnitt erfolgreich ist, laufen die nachfolgenden Playbooks für die gesamte ursprüngliche Auswahl;
- dadurch erhalten auch die im ersten Job bereits erstellten VMs ihren Power-Cycle, Export und Start;
- `identity_unbound_allowed` wird nach erfolgreicher Einführung entfernt oder überall `false`, weil jede Create-Erfolgseinheit ihre Identität sofort bindet.

### 10.4 Legacy-Retry ohne Create-Ergebnisse

Ein älterer fehlgeschlagener Job ohne `deploy_create_vm_results` besitzt keinen per-VM-Beweis:

- kein automatisches Erfinden von Erfolgen;
- vorhandene ungebundene Namensgleichheiten blockieren wie ADR-0036;
- Operator prüft ESXi und adoptiert echte eigene VMs ausdrücklich;
- danach wird ein frischer Job über das normale Formular eingereiht;
- der alte „Erneut ausführen“-Knopf erklärt, warum keine automatische Wiederaufnahme möglich ist, statt einen voraussichtlich blockierenden Job zu erzeugen.

### 10.5 Unklarer Ausgang ohne JID

Aufgrund der freigegebenen Fail-closed-Entscheidung existieren genau zwei Freigabewege:

1. **VM ist auf ESXi vorhanden:** Inventar aktualisieren, VM in ESXi prüfen, vorhandene bestätigte Aktion „Identität übernehmen“ verwenden. Ein anschließender Retry erkennt die gebundene Live-UUID und behandelt die Create-Einheit als bereits erfolgreich/übersprungen.
2. **VM ist nicht vorhanden:** Administrator startet über den bestehenden Systemstatus-Pfad einen Inventarpull mit genau `credential_esxi_id` des Quelljobs. Die Freigabe wird erst angeboten, wenn der Pull erfolgreich endete, die VM-Kind-Abfrage dieses Pulls nachweislich beantwortet wurde und `last_success_at` sowie die VM-Kind-Frische strikt neuer als `MAX(source_job.updated_at, result.updated_at)` sind. Im Cache dieses Credentials darf weder der Snapshotname noch die gespeicherte Instance-UUID vorkommen. Zusätzlich darf die Remote-Execution-Prüfung keine passende aktive Unit/Cgroup mehr sehen. Danach prüft der Administrator im ESXi Host Client, dass unter „Recent Tasks“ kein Create-/Reconfigure-Task für diese VM läuft. Die Aktion läuft über die gemeinsame Seite `Externe Prüfung dokumentieren` aus dem kombinierten Plan, verlangt `system.config`, `deploy.run` und `vms.write`, CSRF, Optimistic Lock, Bestätigung mit VM-Name, Pflichtbegründung/Referenz, das Kontrollkästchen „Recent Tasks geprüft; kein passender Task läuft“, keinen anderen aktiven Job derselben Mission und einen CAS auf weiterhin `manual_required`. Sie schreibt die append-only Resolution, Audit und Quelljoblog. Der Create-Ergebnisübergang ist `uncertain -> failed` mit `operator_released`; erst danach kann ein separater Retry entstehen.

Die Freigabeaktion löscht keine VM, ändert keine Hardware und adoptiert nichts. Sie erlaubt lediglich einem späteren Retry, wieder einen Create zu starten. Ist im maßgeblichen frischen Inventar eine Namens-/UUID-Gleichheit vorhanden, fehlt die erfolgreiche VM-Kind-Antwort, ist der Pull nicht neuer als der unklare Ausgang oder hat sich der Ergebnisstatus parallel geändert, wird die Aktion abgelehnt. Die manuelle Recent-Tasks-Bestätigung wird im Audit mit Benutzer, UTC-Zeit, Job-ID, Ergebnis-ID und VM-Snapshotname festgehalten; sie wird nicht fälschlich als technisch abgefragter Taskbeweis bezeichnet.

---

## 11. Abbruch, Workerabsturz, DB-Ausfall und Zeitbudgets

### 11.1 Abbruch

- `queued -> cancelled`: keine Create-Zeile hat gestartet; Ergebniszeilen bleiben `pending` als historischer Umfang.
- `running -> cancelling`: aktuelle Einheit wird nicht hart beendet.
- Während `cancelling` wird dieselbe JID weiter gepollt.
- Nach terminalem/unklarem Ausgang der aktuellen Einheit wird das Ergebnis gespeichert, danach bestätigt der Worker `cancelled`.
- Keine weitere `pending`-Einheit startet.
- Das Portal sagt ausdrücklich „Abbruch nach der aktuellen VM“, nicht „sofort“, solange ein Async-Job läuft.

### 11.2 Workerprozess stirbt

- JID, Async-Verzeichnis und Deadline sind vor dem ersten Poll dauerhaft gespeichert.
- Der Reaper behält Observer-Grace, Stale-Grenze, `FOR UPDATE SKIP LOCKED` und beide Aufrufer, ändert aber bei vorhandenem oder möglichem Remote-/JID-Handle keinen fachlichen Terminalstatus. Er löst ausschließlich die alte Lease, setzt `recovery_requested_at` und lässt den Job `running` beziehungsweise `cancelling`.
- Der neue berechtigte Worker claimt Recovery vor normalen Queuejobs und bindet dieselbe Remote-Ausführung und dieselbe JID wieder an. `recovery_count` steigt; `attempts` bleibt unverändert.
- Nur wenn Handle, Unit, Started-Marker und JID nachweislich nie existierten, darf der Reaper einen sicheren Nichtstart terminalisieren. Started ohne beweiskräftiges Resultat führt zu Reconciliation, nicht zu einer geratenen Jobmatrix.
- Create-Zeilen `pending`, `succeeded`, `failed`, `uncertain` und `skipped` bleiben wahr. `prepared/running` werden nicht allein wegen des fehlenden Jobheartbeats umgeschrieben; erst JID-/Live-Reconciliation entscheidet ihren Übergang.
- Ein zuvor `cancelling` Job wird erst nach terminalem/versöhntem Ausgang der aktuellen Create-Einheit `cancelled`. Ein Full-Job wird nie allein aus Create-Zeilen als Gesamterfolg abgeleitet; MAC-/Pipelinebeweis bleibt maßgeblich.
- Für `create` werden keine Lifecyclezustände konvergiert. Für `full` bleibt die bestehende Lifecycle-Konvergenz auf `failed` für noch nicht durch die vollständige Pipeline abgeschlossene VMs bestehen; sie darf die neuen Create-Ergebnisbeweise nicht verändern.
- Ein Retry übernimmt niemals dieselbe JID. Solange sie läuft oder ungeklärt ist, ist Retry gesperrt.
- Ein alter Worker, der später zurückkehrt, scheitert am Job-/Result-CAS und überschreibt nichts.

### 11.3 Datenbankausfall

- Polling und Remote-Arbeit dürfen durch eine Log-/Heartbeat-DB-Störung nicht beendet werden.
- Die neue fachliche Resultatpersistenz darf jedoch nicht nur im begrenzten Textlog-Spool liegen.
- Der Worker hält das letzte strukturierte Ergebnis separat im Speicher und nutzt nach Remoteende den bestehenden begrenzten DB-Recoverypfad.
- Solange der terminale Ausgang nicht persistiert ist, startet keine weitere VM.
- Verliert der Worker Besitz, verwirft er seinen Schreibversuch; die persistierte JID ermöglicht dem neuen Besitzer die Reconciliation.
- `--once` bleibt begrenzt und meldet ausdrücklich, wenn das terminale Ergebnis nicht persistiert werden konnte.

### 11.4 Idle- und Gesamtbudget

- Das SSH-Idle-Budget schützt nur einzelne kurze Steuerkommandos.
- Jeder `ansible-playbook`-Aufruf läuft mit `PYTHONUNBUFFERED=1`.
- Poll-Zeilen halten die Operatoranzeige aktuell, setzen aber nicht das Create-Gesamtbudget zurück.
- Das Create-Gesamtbudget läuft ab dem ersten `RUN create` des Jobs.
- Bei Ablauf wird kein neuer Async-Job gestartet.
- Eine laufende JID wird `uncertain/job_timeout`; der vSphere-Task kann weiterlaufen.
- Das Portal darf „Zeitbudget erreicht“ sagen, aber nicht „VM-Erstellung wurde auf ESXi beendet“.

### 11.5 Async-Bereinigung

Der Deploy-Worker führt vor dem Claim eines neuen normalen Jobs eine begrenzte Cleanup-Runde aus. Die generische Remote-Execution-Zeile, nicht Alter oder Jobterminalstatus allein, erteilt die Cleanup-Freigabe:

1. ausschließlich Create-Zeilen lesen, deren Remote-Controller beendet, Reconciliation abgeschlossen, Callback-/Identitybeweis persistiert, Cleanup-Lease erworben und Diagnosefrist abgelaufen ist;
2. Credential und exakt validierten Pfad auflösen;
3. falls JID noch läuft, nicht löschen und nächsten Versuch terminieren;
4. falls terminal, `async_status mode=cleanup` und danach Einheitenordner entfernen;
5. Erfolg in der Zeile durch Leeren von `async_dir`/JID nicht vortäuschen: historische JID und Pfad bleiben gespeichert; nur `async_cleaned_at` bestätigt Cleanup;
6. bei jedem tatsächlich gestarteten Versuch `cleanup_attempts` und `cleanup_auto_attempts` erhöhen; bei Fehler `cleanup_last_error` redigiert setzen und `cleanup_retry_at` mit dem generischen begrenzten Backoff planen;
7. bei gelöschtem Credential oder dauerhaftem Authfehler eine redigierte Systemzeile mit genauem manuellen Pfad ausgeben; nach `VIRTUSPHERE_REMOTE_CLEANUP_MAX_AUTO_ATTEMPTS` bleibt der Rest sichtbar auf „manuell zu bereinigen“ und wird nicht heiß wiederholt. Die generische, auditierte Aktion `Cleanup erneut versuchen` setzt ausschließlich `cleanup_auto_attempts=0` und erteilt bei unveränderter Evidenz eine neue CAS-Berechtigung;
8. keine unendliche Hot-Loop.

Secretinputs des generischen Remote-Runs werden nach bewiesenem Controllerende getrennt und früher gelöscht; JID-/Resultatbeweise bleiben bis zur abgeschlossenen Reconciliation erhalten. Die Cleanup-Runde ist kein offener Runner; sie verwendet ein festes Batchlimit aus einer SSoT-Konstante. Für bekannte Batchgröße gelten die Repository-Fortschrittszeilen. Jobretention darf die Ownerzeilen nicht vor `async_cleaned_at` beziehungsweise bewusst archiviertem Cleanupfehler purgen.

---

## 12. Joblog und Portal-QoL

### 12.1 Preflight-Spam entfernen

Der Modulnachweis wird geändert von einer vollständigen Dokumentausgabe zu:

```sh
ansible-doc -t module community.vmware.vmware_guest >/dev/null
```

stdout ist auf Erfolg leer; stderr bleibt im erfassten Stream. Der Stage-Marker benennt weiterhin `community.vmware`. Der Test beweist Erfolg, Fehlerdetail und dass keine Moduldokumentation im Joblog landet.

### 12.2 Runtime-Versionen hart prüfen

Der Preflight prüft nicht nur Vorhandensein:

- installierter `ansible-core` erfüllt die aus dem gepinnten QA-/Collectionvertrag abgeleitete Mindestversion;
- installierte `community.vmware` entspricht dem Pin aus `Ansible/requirements.yml`;
- `pyvmomi` und `requests` importieren im Interpreter des Ansible-Prozesses;
- Async-Verzeichnis kann mit `0700` angelegt, mit `0600` beschrieben und wieder entfernt werden;
- ein lokaler kurzer Async-Selbsttest startet genau einen ungefährlichen Sleep, pollt ihn und bereinigt seine Statusdatei.

Die erwarteten Versionen werden beim Erzeugen des Preflightbefehls aus den bestehenden Pin-Dateien gelesen. Es entsteht kein zweites fest codiertes Versionspaar in PHP.

### 12.3 Fortschrittskarte

`deploy_log.php` zeigt oberhalb des Rohlogs für Create-fähige Jobs eine serverseitige Karte:

- „VM-Erstellung“;
- `bearbeitet / total`;
- getrennte Zähler für erstellt, aktualisiert, unverändert, übersprungen, fehlgeschlagen, unklar, nicht begonnen;
- aktuelle VM mit Position und Startzeit;
- kein Prozentwert innerhalb der aktuellen VM;
- bei `uncertain` eine rote/gelbe Handlungsbox mit genau dem passenden nächsten Schritt und Link zur Identitäts-/Inventarstelle, permission-gated;
- bei Retry ein Link zum Quelljob über `deploy_job_log_url()`.

Die Joblog-Pollantwort enthält zusätzlich eine Create-Zusammenfassung aus derselben Tabelle. Der Browser berechnet keine fachlichen Zähler aus Textzeilen.

### 12.4 Vollständiger Logtail

Die JSON-Antwort liefert `has_more`, indem sie intern `limit + 1` liest. Der Browservertrag lautet:

1. Solange `has_more=true`, sofort die nächste Seite holen, ohne zwei Sekunden zu warten.
2. Ein terminaler Job stoppt erst, wenn `has_more=false`.
3. Doppelte Sequenzen werden anhand `seq` verworfen.
4. Eine Antwortlücke wird nicht übersprungen.
5. Initial terminale Jobs mit mehr als 1.000 Zeilen laden den fehlenden Tail nach.
6. Ohne JavaScript gibt es serverseitige Vor-/Zurück-Pagination; der letzte Fehler ist über „Letzte Seite“ direkt erreichbar.
7. Ein RBAC-geschützter Rohtextdownload liefert alle noch retained Zeilen chronologisch. Er verwendet denselben Jobreader und keine neue Log-SSoT.
8. Der Live-DOM wird begrenzt. Entfernte ältere Bildschirmzeilen werden durch einen sichtbaren Hinweis und den Rohdownload zugänglich gehalten.
9. Automatisches Scrollen erfolgt nur, wenn der Benutzer bereits nahe am Ende war.
10. In einem unsichtbaren Browser-Tab wird das Polling pausiert; beim Zurückkehren läuft genau eine Anfrage und füllt die Sequenzlücke auf.

### 12.5 Sichtbare Sprache

Alle Portaltexte erhalten DE/EN-Parität und verwenden reale deutsche Umlaute. Technische Marker, Fehlercodes, Ansible-Statuswerte und Machine-Wire-Felder bleiben unübersetzt. Kein sichtbarer Text behauptet:

- Poll sei ein prozentualer ESXi-Fortschritt;
- Timeout habe den ESXi-Task beendet;
- `unchanged` beweise allein über den Namen die Identität;
- ein Retry starte immer alle VMs neu;
- Eager Zeroed Thick habe eine feste Dauer.

---

## 13. Datei- und Modulplan

Die genaue Ownership nach Umsetzung ist verbindlich. Neue PHP-Module bleiben unter 400 physischen Zeilen.

### 13.1 Strukturhunk vor Semantik

`Docker/WebAPI/lib/ansible_command.php` liegt bereits über dem Zielbudget und wird vor der fachlichen Änderung verhaltensgleich zerlegt:

- `ansible_command.php`: Require-Fassade;
- `ansible_modes.php`: Modus- und Playbookfolge;
- `ansible_step_markers.php`: bestehende Playbookmarker;
- `ansible_preflight.php`: Checks und Preflightkommando;
- `ansible_remote_command.php`: Shellquoting und Remoteaufrufe.

Öffentliche Funktionsnamen, Signaturen, Marker und Commandstrings bleiben im Strukturhunk identisch. `CliRequireClosureContractTest`, `AnsibleStepMarkerTest`, `CorrelationIdTest`, `EsxiTrustModeTest` und relevante Static-Ownerregistries werden vor/nach dem Split ausgeführt.

Der Joblog-Poller wird verhaltensgleich aus `portal/assets/deploy.js` nach `portal/assets/deploy-log.js` extrahiert. Formular-, Storage- und Capabilitylogik bleibt zunächst in `deploy.js`. Die zentrale Assetreihenfolge lädt beide deterministisch. Erst nach `node --check` und E2E-Parität beginnt die QoL-Änderung.

### 13.2 Neue Dateien

- `Docker/WebAPI/lib/deploy_create_constants.php`
- `Docker/WebAPI/lib/deploy_create_result.php` für reinen Zustands-/Zusammenfassungs-Code
- `Docker/WebAPI/lib/deploy_worker_create.php` für die sequenzielle Orchestrierung
- `Docker/WebAPI/lib/ansible_create_protocol.php` für Markerbau/-parser und Resultatschema
- `Docker/WebAPI/lib/repo/deploy_create_results.php` für SQL und CAS-Übergänge
- `Docker/WebAPI/lib/deploy_create_progress.php` für Portal-Viewmodel/Renderer
- `Ansible/tasks/create_identity_check.yml`
- `Ansible/createVMPrepare-ESXi_playbook.yml`
- `Ansible/createVMStatus-ESXi_playbook.yml`
- `Ansible/createVMCleanup-ESXi_playbook.yml`
- `Ansible/emit_create_result.py`
- gezielte Unit-/Integration-/Static-/E2E-Tests gemäß Abschnitt 15

Falls eine Datei während der Implementierung das Budget erreicht, wird nach der hier beschriebenen Fachgrenze geteilt, nicht durch Entfernen von Kommentaren oder Zusammenpressen unabhängiger Logik.

### 13.3 Zu ändernde Dateien

- `Ansible/createVMs-ESXi_playbook.yml`
- `Docker/WebAPI/lib/ansible_yaml.php`
- `Docker/WebAPI/lib/ansible.php` für die aufrufspezifische Artefakterzeugung
- `Docker/WebAPI/lib/ansible_paths.php` für die verbindliche Liste/Quelle der hochzuladenden Playbooks und Tasks
- `Docker/WebAPI/lib/deploy_constants.php` als Fassade
- `Docker/WebAPI/lib/deploy_worker_mission.php`
- `Docker/WebAPI/lib/deploy_worker_finish.php`
- `Docker/WebAPI/lib/deploy_worker_reaper.php`
- `Docker/WebAPI/lib/deploy_worker_vm_state.php` für die unveränderte Full-Pipeline-Lifecycle-Integration; Create-only erhält dort ausdrücklich keinen Lifecycle-Übergang
- `Docker/WebAPI/lib/repo/deploy_job_input.php`
- `Docker/WebAPI/lib/repo/deploy_job_maintenance.php`
- `Docker/WebAPI/lib/repo/deploy_job_queue.php`
- `Docker/WebAPI/lib/repo/deploy_job_queries.php`
- `Docker/WebAPI/lib/repo/deploy_job_modules.php`
- `Docker/WebAPI/portal/deploy.php`
- `Docker/WebAPI/portal/deploy_log.php`
- `Docker/WebAPI/portal/assets/deploy-log.js`
- zentrale Assetregistry/-ladung
- nächster freier Migrationshunk in `Docker/WebAPI/lib/migrate.php`
- `Docker/mysql/mysql-init/struktur.sql`
- `Docker/WebAPI/lang/{de,en}/deploy.php`
- `Docker/WebAPI/lang/{de,en}/help_deploy.php`
- `Docker/WebAPI/lang/{de,en}/help_missions.php`
- Tests, Hilfe und Doku aus Abschnitt 16

### 13.4 Ausdrücklich unveränderte Verträge

- `db_importMAC.php` Payload und Transaktion;
- `mecm-api.php`, `mecm_updateid.php`, `mecm_packages.php`, `mecm_report.php`, `mecm_client_ack.php`;
- fünf Legacy-Statusstrings;
- MAC-Normalisierung;
- IP-Allowlist und optionaler Report-Token;
- PowerShell-MECM-Skripte;
- Disk-Type-Wire-Tokens;
- RBAC-Grundmodell;
- direkter Standalone-ESXi-Supportbereich aus ADR-0036.
- `Ansible/requirements.yml`: Der vorhandene Pin 6.2.0 bleibt bestehen. Weicht der installierte Artefaktvertrag unerwartet davon ab, stoppt Etappe D und der Plan wird mit einer eigenen Versionsentscheidung ergänzt; keine opportunistische Versionsanhebung.

---

## 14. Umsetzungsetappen

Jede Etappe endet mit Soll/Ist-Diffprüfung, gezielten Tests, Doku-/Help-/Logprüfung und einem aktualisierten Abnahmeblock. Kein roter Test wird als „bestehend“ übernommen, ohne Pfad, Fehlermeldung und nachgewiesene Fremdursache zu nennen. Commit und Push sind durch diesen Plan nicht automatisch autorisiert; sie erfolgen nur auf ausdrücklichen Nutzerauftrag.

### Etappe A: Freigabe und Charakterisierung

1. Den bereits freigegebenen Entscheidungsblock aus Abschnitt 0 als unveränderte Eingangsvoraussetzung verifizieren.
2. `git status --short` und alle Diffs der Zielfiles lesen; fremde Arbeiten erhalten.
3. Aktuelle Migrationen und Dateigrößen erfassen.
4. Charakterisierungstests für aktuellen Commandstring, Schleifen-Playbook, Retry, Lifecycle und Logpoller ergänzen, ohne Verhalten zu ändern.
5. Den Buffering-Reproduktionstest als deterministischen QA-Harness eincheckbar machen: gepufferter Kontrollfall und ungebufferter Erfolgsfall.
6. Produktions-/Staging-Runtimeversionen dokumentieren, ohne Secrets oder reale Hostnamen in Artefakte zu schreiben.

Abnahme: Nur Tests/Fixtures/Plan-Nachweis, keine fachliche Änderung.

### Etappe B: Verhaltensgleiche Modulsplits

1. `ansible_command.php` gemäß Abschnitt 13.1 teilen.
2. Joblog-Poller aus `deploy.js` extrahieren.
3. Ownerregistries und Require-Closure aktualisieren.
4. Command-, Marker-, Asset- und Browserparität beweisen.

Abnahme: Byte-/Verhaltensparität der öffentlichen Funktionen und sichtbaren Ausgabe.

### Etappe C: SSoT, Migration und Repositories

1. Create-Konstantenmodul anlegen.
2. Nächste freie Migration mechanisch bestimmen.
3. `deploy_create_vm_results` und `deploy_jobs.create_started_at` in Migration und Frischschema identisch anlegen.
4. Repo-Funktionen für Materialisierung, CAS-Übergänge, Zusammenfassung, Retry-Kopie, Reconciliation und Cleanup-Auswahl implementieren.
5. Queue-Transaktion materialisiert die Create-Menge.
6. Alte Jobs ohne Zeilen bleiben lesbar und werden als Legacy erkannt.
7. Migration `--check`, Schemafingerprint und Enum-/Bounds-Sync prüfen.

Abnahme: DB-Integration einschließlich Rollback bei halber Initialisierung, Duplikaten und Parallel-CAS.

### Etappe D: Strukturierter Ansible-Einzel-VM-Vertrag

1. `portal_vm_id` in Serverlist ergänzen.
2. Read-only Prepare-Playbook und Create-Playbook auf exakt eine Ziel-ID umstellen; Launch wiederholt und vergleicht den gespeicherten Identitätscheck vor Mutation.
3. Async-Start, Einmal-Poll, Cleanup und Resultatemitter implementieren.
4. `PYTHONUNBUFFERED=1` zentral für alle Playbookaufrufe setzen.
5. Preflight-Spam entfernen und Runtime-/Async-Selbsttest ergänzen.
6. Secret- und Markerparser-Tests ausführen.
7. QA-Image testet Launch/Poll/Cleanup lokal mit einem Sleep-Modulstub, ohne ESXi.

Abnahme: Ein aktiver Async-Job, live eintreffende Poll-Zeilen, terminales strukturiertes Resultat, leeres Cleanup-Verzeichnis.

### Etappe E: Worker-Orchestrierung und Identitätscommit

1. Create-Orchestrator in den Missionsworker integrieren.
2. Ablauf aus Abschnitt 9 vollständig implementieren.
3. DB-Ausfallpfad und Ownership-CAS integrieren.
4. Identität nach jedem Erfolg atomar speichern.
5. Create-only ohne Lifecycle-/MECM-Änderung und Full mit der neuen Create-Orchestrierung sowie bestehendem Pipeline-Lifecycle umsetzen.
6. Jobzusammenfassung und Terminalstatusmatrix umsetzen.
7. Reaper-Transaktion modusabhängig um Create-Ergebnisse erweitern; Deploy- und Maintenance-Worker benutzen weiterhin denselben Reaperpfad.
8. Kein nachfolgendes Full-Playbook vor vollständigem Create-Erfolg.

Abnahme: deterministische 15er-Fixture, Fehler an Positionen 1/8/15, Transportverlust, Cancel und DB-Ausfall.

### Etappe F: Retry und unklarer Ausgang

1. Modusabhängige Retry-Matrix implementieren.
2. Skip-Ergebniszeilen für bestätigte Erfolge erzeugen; laufende/unklare JIDs blockieren den Retry und bleiben beim Quelljob.
3. Full-Retry über gesamte ursprüngliche Auswahl führen.
4. Legacy-Retry fail-closed erklären.
5. Fail-closed-Reconciliation aus 10.5 und bestätigte Freigabeaktion implementieren.
6. Confirm-, RBAC-, CSRF-, Audit-, Live-Inventar- und Race-Tests ergänzen.

Abnahme: kein doppelter Create, keine automatische Adoption, Recovery desselben Jobs setzt dieselbe JID fort; erst nach bewiesenem Ausgang darf ein Retry offene Einheiten neu materialisieren.

### Etappe G: Portalfortschritt und vollständiger Logtail

1. Fortschrittskarte aus DB-SSoT rendern.
2. JSON-Pollantwort um `has_more` und Create-Summary erweitern.
3. Terminaltail vollständig nachladen.
4. JS-less Pagination, letzte Seite und Rohdownload ergänzen.
5. DOM-Grenze, höfliches Autoscroll und Visibility-Pause umsetzen.
6. DE/EN-Texte und Help-Links ergänzen.

Abnahme: E2E mit mehr als 1.500 Logzeilen, terminalem Fehler im Tail und Fortschritt 14/15 + 1 unklar.

### Etappe H: Doku, Gates und kontrolliertes Staging

1. Alle Dateien aus Abschnitt 16 synchron aktualisieren.
2. Fast- und Integration-Lane vollständig ausführen.
3. Contract-Review vor Commit wegen Migration, Deploy-/Statuslogik und Ansible-Vertrag.
4. Drift-, i18n-, QA- und Release-Checks gemäß Repositoryregeln.
5. Kontrollierte Standalone-ESXi-Stagingfälle aus Abschnitt 15.6 ausführen.
6. Rollout in der Reihenfolge aus Abschnitt 17.

Abnahme: alle automatisierten Gates grün, reale Stagingnachweise vollständig, keine Produktivdaten im Testartefakt.

---

## 15. Verbindliche Testmatrix

### 15.1 Reine Unit-Tests

- Create-Zustandsautomat: jeder erlaubte Übergang grün, jeder nicht erlaubte rot.
- Terminaler Replay idempotent, widersprechender Replay abgelehnt.
- Outcome-Matrix `created/updated/unchanged` vollständig.
- `absent + changed=false` abgelehnt.
- Markerparser: gültige sechs Events; unbekannte Version, Event, Feld, ID, JID, Base64, Größe und Duplikat abgelehnt.
- Secret-Sentinel in Resultat/Fehler wird vor Log/DB redigiert.
- Pollintervall-/Idle-/Stale-/Gesamtbudget-Invarianten.
- Restbudget an exakter Grenze vor, auf und nach Ablauf.
- Retry-Matrix für jeden Quellstatus, `create` und `full`.
- Skip-Plan verweist auf echte erfolgreiche Quelle, nicht auf eine beliebige Skip-Kette; `verify_skip` wird erst nach aktueller passender Live-UUID terminal `skipped`.
- Jobsummary zählt jede Kategorie exakt einmal.
- Jobstatusmatrix für 0/N, alle Erfolg, gemischt, nur Fehler, unklar, Cancel.
- Legacy-Job ohne Create-Zeilen.
- Async-Pfadvalidator lehnt leer, Root, Home, Traversal, Glob, falsches Präfix und fremde ID ab.
- JID-Validator lehnt Shellzeichen, Slash, Whitespace und Überlänge ab.
- Preflightversionsvergleich an Untergrenze und darunter.

Alle Zeitfälle verwenden Fake Clock und keinen realen langen Sleep.

### 15.2 Static-/Contract-Tests

- Create-Mutation enthält keinen Loop über `vm_configurations`.
- Jedes Create-Steuerplaybook selektiert und assertiert genau eine `portal_vm_id`.
- Jeder `ansible-playbook`-Command enthält zentral `PYTHONUNBUFFERED=1`.
- `poll: 0` kommt nur im Einzel-VM-Launch vor; ein Guard beweist Worker-Einzelaktivität.
- Passwort erscheint in keinem Debug-/Marker-/Resultatfeld.
- Neue Repo-/Worker-/Ansible-Module sind in Fassaden und Ownerregistries enthalten.
- Migration/Freshschema/Constants-Enums sind synchron.
- Neue POST-Aktion ist bestätigt, benannt, CSRF-/RBAC-geschützt und E2E-klassifiziert.
- Neue Help-Keys existieren in DE und EN mit Platzhalterparität.
- Kein handgeschriebener Deploy-Joblog-Link.
- Keine neue Datei überschreitet das Größenbudget.
- Progressformat `[n/total] RUN|POLL|DONE|FAIL` wird als Vertrag geprüft.

### 15.3 Datenbank-Integration

- Job und N Ergebniszeilen committen atomar.
- Leere VM-Auswahl wird einmal materialisiert und später nicht erweitert.
- Sortierung bei gleichen/nicht ASCII VM-Namen bleibt `vm_name, id` nach DB-Kollation und wird als Snapshot gespeichert.
- VM-Löschung während aktivem Job bleibt blockiert; historische Zeile überlebt erlaubte spätere Löschung.
- Zwei Worker können nicht dieselbe Einheit starten.
- Reaper löst für `prepared/running` nur die alte Lease und fordert Recovery an; erst JID-/Live-Reconciliation verändert die Ergebniszeile oder den Jobterminalstatus. Beide Workeraufrufer benutzen denselben Pfad.
- Zwei terminale Pollantworten erzeugen einen Commit und eine DONE-Zeile.
- Identitätscommit bindet leere UUID, frischt passende MOID auf und lehnt Widerspruch vollständig ab.
- Fehler an VM 15 lässt die 14 bestätigten Ergebnisse unangetastet.
- Vertrag „Create-only verändert weder Lifecycle noch MECM-Zustand“.
- Full-Pipeline startet Folgephase erst nach vollständigem Create-Erfolg.
- Cancel während VM 8: 1-7 wahr, 8 terminal/unklar, 9-15 pending, Job cancelled.
- Recovery desselben Jobs übernimmt JID und unveränderte Deadline; ein Retry ist bis zum bewiesenen Ausgang gesperrt.
- Operatorfreigabe verlangt einen erfolgreichen neueren VM-Kind-Inventarpull desselben Credentials, Namens-/UUID-Abwesenheit, Recent-Tasks-Attestierung, Berechtigung, CSRF, Confirm, CAS und keinen konkurrierenden aktiven Job.
- Retention löscht Ergebniszeilen mit dem Job, nie vorher unabhängig.
- DB-Ausfall/Reconnect schreibt terminales Ergebnis vor Start der nächsten VM.

### 15.4 Ansible-/Python-Harness

Im gepinnten QA-Ansible-Image:

- Buffering-Kontrollfall zeigt gesammelt eintreffende Ausgabe ohne Unbuffered.
- Produktivcommand zeigt sofortige Ausgabe mit `PYTHONUNBUFFERED=1`.
- Einzelner Async-Sleep wird gestartet, JID geschrieben, mehrfach `running`, danach `succeeded`.
- Statuspoll mit falscher JID ergibt geschlossenen Fehler.
- Gepinntes `vmware_vm_info` liefert eine Liste für den exakten Namen; `vmware_guest_info` über eindeutigen MOID liefert nicht leere `instance.moid` und `instance.instance_uuid`. Fehlendes/umbenanntes Feld lässt den Contracttest scheitern statt auf ein anderes UUID-Feld auszuweichen.
- Dediziertes Async-Verzeichnis ist wiederauffindbar und wird bereinigt.
- Verlorene Startantwort mit genau einer JID-Datei wird wiederaufgenommen.
- Null beziehungsweise zwei JID-Dateien werden unklar/protocol_error, nie neuer Launch.
- Resultatemitter akzeptiert nur das geschlossene Schema.
- Namen mit Unicode und zulässigen Sonderzeichen überleben JSON/Base64url unverändert.
- Secret-Sentinel aus Modulargumenten erscheint in keinem Marker.
- `ansible-lint --strict`, Syntaxcheck und Modulvertrag bleiben grün.

### 15.5 Browser-E2E

- Laufender Create zeigt aktuelle VM und exakte Zähler.
- Fortschritt 14/15 zeigt vierzehn bestätigte Erfolge und die konkrete offene VM.
- `uncertain` zeigt keinen falschen „fehlgeschlagen“-Beweis.
- Retry-Bestätigung nennt Quelle und offenen Umfang.
- Fail-closed-Aktion: Abbrechen verändert nichts; Bestätigen unter erlaubten Bedingungen schreibt Zustand/Audit; fremde VM blockiert.
- Initial terminaler Job mit mehr als 1.000 Zeilen lädt bis zum Fehler im Tail.
- Mehr als eine JSON-Seite wird ohne Warte-Lücke geleert.
- Hintergrundtab pausiert und erzeugt nach Rückkehr keine parallelen Requests/Duplikate.
- Hochgescrollter Benutzer wird nicht ans Ende gezwungen.
- JS-less Pagination erreicht erste und letzte Seite.
- Rohdownload ist vollständig, chronologisch, escaped/als Text und RBAC-geschützt.
- Responsive Fortschrittskarte bei engem Viewport ohne Überlauf; Geometrie-/Screenshotabnahme an der Wrap-Grenze.

### 15.6 Reales Standalone-ESXi-Staging

Diese Tests laufen nur auf einem ausdrücklich benannten Wegwerf-/Stagingbestand. Sie erstellen echte VMs und Datastorebelegung. Vorher Zielhost, Datastore, VM-Präfix und Löschverantwortlichen protokollieren.

1. Eine kleine Thin-Test-VM: Grundvertrag und Identitätsbindung.
2. Eine vorhandene gebundene VM: zweiter Lauf endet `unchanged`.
3. Eine vorhandene gebundene VM mit erlaubter Hardwareabweichung: `updated`.
4. Fremde Namensgleichheit: vor Mutation blockiert.
5. Zwei Namensgleichheiten: vor Mutation blockiert.
6. Repräsentative Eager-Zeroed-Thick-VM mit Laufzeit über dem alten Idle-Fenster: regelmäßige Polls, kein 30-Minuten-Idle-Abbruch.
7. Mehrfachjob mit exakt 15 VMs: vSphere Recent Tasks beweist maximal einen gleichzeitig aktiven Create-/Reconfigure-Task dieses Jobs.
8. Kontrollierter SSH-Abbruch nach gespeicherter JID: neuer Kanal pollt dieselbe JID, keine zweite Create-Aufgabe.
9. Kontrollierter Abbruch vor bestätigter JID: JID-Verzeichnissuche oder `uncertain`, kein Doppelstart.
10. Cancel während langer VM: aktuelle Einheit endet/bleibt unklar, keine nächste VM.
11. Workerrestart während laufender JID: Recovery desselben Jobs übernimmt, alter Worker kann nicht finalisieren; kein Retryjob entsteht.
12. Zweiter kompletter Lauf: alle VMs `unchanged`, keine fremde Mutation.
13. Cleanup: keine zu diesem Test gehörenden Async-Dateien oder Accounts-Dateien bleiben zurück.

Nach jedem Test werden nur die vorher protokollierten Wegwerf-VMs manuell/gezielt entfernt. Keine automatische rekursive Löschung anhand eines bloßen Namenspräfixes.

---

## 16. Hilfe- und Dokumentationsmatrix

Jede Zeile wird in derselben Umsetzung aktualisiert oder im Abnahmeprotokoll mit konkreter Begründung als nicht betroffen markiert.

| Datei/Bereich | Verbindlicher Inhalt |
|---|---|
| `lang/{de,en}/help_deploy.php` | Sequenzieller Create, RUN/POLL/DONE, kein Prozentwert, Cancel nach aktueller VM, Retry/Skip/Resume, unklarer Ausgang und Identitätsprüfung. |
| `lib/help/deploy.php` | Neue Abschnitte/Platzhalter aus SSoT; Links zu erfüllbaren nächsten Schritten. |
| `lang/{de,en}/help_missions.php` | EZT kann lange dauern; Poll verhindert falsche Stille, nicht den Gesamtdeckel; keine feste Laufzeitbehauptung. |
| `docs/DEPLOYMENT.md` | Runtimeversion, unbuffered Command, Async-Verzeichnis/Rechte, Einzel-VM-Protokoll, Datenmodell, Gesamtbudget, Cleanup und Identitätsbindung. |
| `docs/operations/deploy-chain.md` | Worker->Ansible->ESXi Nachweis je VM; Ergebnis- und Rückkanal; unklarer Ausgang. |
| `docs/operations/troubleshooting.md` | 30-Minuten-Idle-Vorfall, JID vorhanden/fehlend, Recent Tasks, Adoption/Freigabe, kein blinder Retry. |
| `docs/QA.md` | Buffering-, Async-, State-Machine-, Retry-, Logtail- und ESXi-Stagingtests. |
| `docs/QUALITY-GATES.md` | Bedeutung eines neuen Create-Progress-/Async-Guards, falls eigener Gate entsteht. |
| `docs/INSTALLATION-ANLEITUNG.md` | Ansible Core/Collection, Home-/Async-Rechte, Preflight und Offlinevoraussetzungen. |
| `docs/operations/offline-install.md` | Gepinnte Wheels/Collection, kein Runtime-Download, Async-Selbsttest. |
| `docs/operations/go-live.md` | Ansible-Preflight, langer Create-Smoke, Logtail und Cleanup vor Produktivfreigabe. |
| `docs/adr/ADR-0036-vm-identity-and-standalone-support-boundary.md` | Create-only bindet Identität sofort; Full braucht `identity_unbound_allowed` nicht mehr; Retry-/Uncertain-Grenze. |
| neue ADR mit beim Edit mechanisch nächster freier Nummer | Worker-gesteuertes Async, sichere Abbruchgrenze pro VM und persistierte JID; ADR-0033 und ADR-0038 werden als verwandte Entscheidungen verlinkt, aber nicht nachträglich umgedeutet. |
| `docs/CHANGELOG.md` | Erst bei Umsetzung: Nutzerwirkung, Migration, Rolloutreihenfolge, keine Parallelisierung. |
| `AGENTS.md` / `.claude/rules/ansible.md` / `.claude/rules/webapi.md` | Dauerhaften Einzel-VM-/Progress-/JID-/Resultat-SSoT-Vertrag verbindlich ergänzen; alle drei Regelorte werden in derselben Dokuetappe auf semantische Parität geprüft. |

Nicht anzupassen, sofern der Diff dies bestätigt:

- MECM PowerShell-Dokumentation;
- Machine-API-Wire-Dokumentation;
- Backup-/Restorefachlogik, außer Aufnahme der neuen Tabelle in bestehende generische Schema-/Rowcountprüfungen;
- VAAI-/Storage-Hardwareanleitung.

---

## 17. Rollout und Rückbau

### 17.1 Rolloutreihenfolge

1. Vollständiges DB-Backup und Restore-Drill nach bestehendem Runbook.
2. Ansible-Host offline auf die gepinnte Core-/Collection-/Python-Umgebung bringen.
3. Manuellen Volltest ausführen; Runtimeversion und Async-Selbsttest müssen grün sein.
4. Migration und Frischschema im QA-/Stagingstack prüfen.
5. WebAPI, Deploy-Worker und Ansible-Artefakte als eine kompatible Releaseeinheit ausrollen. Kein neuer Worker gegen alte Playbooks und kein neues Playbook gegen alten Worker.
6. Deploy- und Maintenance-Workerstatus prüfen.
7. Thin-Smoke, dann langer EZT-Smoke, dann Mehrfachlauf.
8. Erst danach Produktion freigeben.
9. Ersten Produktivlauf live beobachten: Fortschrittskarte, maximal eine aktive vSphere-Aufgabe, vollständiger Logtail, Async-Cleanup.

### 17.2 Kompatibilitätsgate

Der neue Worker verweigert Create, wenn:

- Resultattabelle/Migration fehlt;
- erforderliches Create-Playbook/Emitter fehlt;
- Ansible Core unter Mindestversion liegt;
- Collection nicht dem Pin entspricht;
- Async-Selbsttest fehlschlägt;
- Resultatprotokollversion nicht `v1` ist.

Er fällt nicht still auf die alte 15er-Schleife zurück.

### 17.3 Rückbau

- Anwendungscode kann auf die vorherige Releaseversion zurückgerollt werden, solange die additive Ergebnistabelle bestehen bleibt.
- Die Migration wird im Incident-Rollback nicht destruktiv zurückgebaut.
- Alte Worker ignorieren die additive Tabelle.
- Vor Rückbau prüfen, ob Create-Async-Jobs laufen. Laufende JIDs zuerst auslaufen lassen oder als unklar dokumentieren; kein Entfernen ihres Async-Verzeichnisses während der Arbeit.
- Nach Rückbau werden keine neuen Create-Ergebniszeilen geschrieben; vorhandene bleiben bis zur normalen Jobretention.
- Ein Rollback darf nie bereits gebundene Instance-UUIDs löschen.

---

## 18. Edge-Case- und Lückenprüfung

| Fall | Verbindliche Reaktion | Beweis |
|---|---|---|
| VM braucht länger als 30 Minuten | JID weiter pollen; Idle nicht erreicht; Gesamtbudget bleibt. | Fake-Clock + realer EZT-Stagingtest. |
| Gesamter 15er-Lauf braucht länger als vier Stunden | Keine neue VM; laufende Einheit `uncertain/job_timeout`; keine Behauptung eines ESXi-Abbruchs. | Boundary-Unit + Staging mit verkürztem Testbudget. |
| Ansible puffert stdout | Zentral `PYTHONUNBUFFERED=1`; Harness beweist zeitversetzte Zeilen. | QA-Harness. |
| Startantwort geht verloren | Dediziertes Verzeichnis nach genau einer JID durchsuchen; sonst unklar. | Async-Harness. |
| Poll-SSH fällt kurz aus | Dieselbe JID mit Backoff erneut pollen; kein neuer Create. | Worker-Unit/Integration. |
| Async-Statusdatei fehlt | `async_state_missing`, unklar, Fail-closed-Reconciliation aus 10.5. | Harness. |
| Worker stirbt nach ESXi-Erfolg vor DB-Commit | JID/Status oder Live-Reconciliation; alter Worker-CAS blockiert. | Integration + Staging. |
| DB fällt während Poll aus | Remote weiter beobachten; keine nächste VM vor Ergebniscommit. | DB-Channel-Integration. |
| DB bleibt nach terminalem Ergebnis weg | `--once` begrenzt; Loop-Recovery; keine nächste VM. | bestehender Kanal + neuer Resultatfall. |
| Operator klickt Cancel | aktuelle VM sicher beenden/beobachten, danach cancelled. | E2E + Integration. |
| Fremde VM mit gleichem Namen | vor Mutation blockieren; nie automatisch adoptieren. | Identity-Integration + Staging. |
| Fremde VM erscheint zwischen zwei Einheiten | Live-Prüfung unmittelbar je Einheit erkennt sie. | Ansible-Contractfixture. |
| Fremde VM erscheint im Mikrofenster zwischen Check und Launch | Kein vollständiger verteilter Namenslock vorhanden. Erfolg wird nur mit JID, changed und Live-UUID akzeptiert; widersprüchlicher/ungeklärter Fall wird nicht automatisch gebunden. Diese enge Out-of-band-Race-Grenze bleibt ausdrücklich unsupported und wird in ADR/Hilfe genannt. | Negativfixture; kein falscher Erfolgscommit. |
| Vorhandene eigene UUID, neue MOID | erlauben, MOID auffrischen. | Identity-Test. |
| Vorhandene eigene VM unverändert | `unchanged`, kein neuer Datastore-Create. | zweiter ESXi-Lauf. |
| Vorhandene eigene VM braucht Hardwareangleichung | `updated`, nur nach UUID-Beweis. | ESXi-Staging. |
| VM-Erfolg liefert keine UUID | `identity_result_invalid`; nicht binden. | Unit/Ansible fixture. |
| Fehler bei VM 1 | Ergebnis wahr; nach Ownership-/Cancelprüfung sequenziell mit VM 2 fortfahren; keine Parallelität. | 15er-Fixture. |
| Fehler bei VM 15 | 1-14 bleiben bestätigt; nur 15 failed. | Integration. |
| Unklar bei VM 15 | 1-14 bestätigt, 15 unklar, kein Doppelretry. | Incident-Regression. |
| Neue VM wird nach Queueing zur Mission hinzugefügt | nicht Teil des materialisierten Jobs. | Queue-Integration. |
| Mission enthält beim Queueing keine VM | lokalisierte Ablehnung; kein leerer Job und keine Ergebniszeile. | Queue-Integration/E2E. |
| Ausgewählte VM wird gelöscht | Aktivjobschutz; keine Ausweitung auf alle. | Delete-race Integration. |
| Retry nach teilweisem Create-only | nur nach abgeschlossener Recovery; Erfolge skipped, bestätigt fehlgeschlagene/nicht gestartete Einheiten neu. | Retry-Matrix. |
| Retry nach teilweisem Full | Create-Erfolge skipped, danach Folgepipeline für ursprüngliche Gesamtmenge. | Integration. |
| Früher erfolgreicher Skip-Kandidat wurde extern gelöscht/ersetzt | `verify_skip` scheitert geschlossen mit `identity_conflict`; weder Skip noch automatischer Neu-Create. | Retry-/Identity-Integration. |
| Legacy-Job ohne per-VM-Beweis | kein automatisches Raten; Adoption/frischer Job. | Legacy-Test/E2E. |
| Workerheartbeat wird nach Prepare/Launch stale | Reaper löst die Lease, hält Job/Zeile aktiv und fordert Recovery an; derselbe Run wird reattached, nichts neu gestartet. | Reaper-Integration über beide Aufrufer. |
| Zwei Browser-Tabs pollen | Sequenzdedup; keine doppelten DB-Schreibvorgänge, reine Reads. | E2E. |
| Terminaljob hat über 1.000 Logzeilen | Tail wird vollständig geladen; letzte Seite/Rohdownload. | E2E. |
| `ansible-doc` erzeugt über 1.000 Zeilen | stdout auf Erfolg unterdrückt. | Commandcontract. |
| Async-Verzeichnis enthält Secretmaterial | 0700/0600, nie rendern, gezielt bereinigen. | Hygiene-/Filesystem-Harness. |
| Credential wird vor Cleanup gelöscht | sichtbarer manueller Restpfad, kein breites Löschen. | Repo/Worker-Test. |
| Cleanup schlägt wiederholt fehl | begrenzter Backoff und Maximalversuche; JID/Pfad bleiben historisch, Rest wird sichtbar manuell. | Fake-Clock-/Cleanup-Test. |
| Auftrag wird wiederholt, während alter Async-Job läuft | Retry ist gesperrt; Recovery des Quelljobs übernimmt dieselbe JID. | Integration. |
| Bestätigter per-VM-Fehler darf fortsetzen, Fehler ist jedoch global | geschlossene globale Stopklasse stoppt trotzdem. | Unitmatrix. |
| Power-/MAC-/MECM-Verträge | unverändert; Full beginnt erst nach Create-Gesamterfolg. | bestehende Wire-/MAC-Tests. |

### Bewusst verbleibende Supportgrenze

Standalone ESXi bietet VirtuSphere keinen atomaren, externen Namensreservierungsmechanismus über die aktuelle `vmware_guest`-Schnittstelle. Eine gleichzeitig außerhalb VirtuSphere angelegte VM mit exakt demselben Namen im Mikrofenster zwischen Live-Prüfung und Create-Start kann nicht durch Raten sicher zugeordnet werden. Der Plan minimiert das Fenster, bindet nur mit JID/Live-UUID/Resultatvertrag und fällt bei Widerspruch geschlossen aus. Eine vollständige Lösung würde einen anderen vSphere-Create-Vertrag mit einem atomar gesetzten, unveränderlichen Ownershipmerkmal erfordern und liegt außerhalb dieses Punkts.

---

## 19. Definition of Done

Die Reparatur ist erst abgeschlossen, wenn jede Aussage erfüllt und nachgewiesen ist:

- [ ] Die fünf freigegebenen Entscheidungen aus Abschnitt 0 sind unverändert umgesetzt.
- [ ] Ein Create-Aufruf mutiert exakt eine Portal-VM.
- [ ] Zu keinem Zeitpunkt laufen zwei VirtuSphere-Create-Async-Jobs desselben Jobs parallel.
- [ ] Jede Einheit besitzt genau eine Position, einen Snapshotnamen und einen dauerhaften Zustand.
- [ ] Jede laufende Einheit besitzt vor dem ersten Poll eine gespeicherte JID oder wird unklar und stoppt.
- [ ] Alle Playbookprozesse laufen ungebuffert.
- [ ] Ein Lauf über 30 Minuten erzeugt Poll-Lebenszeichen und scheitert nicht am alten Idle-Fenster.
- [ ] Das freigegebene Gesamtbudget bleibt wirksam.
- [ ] Erfolg bindet Instance-UUID und MOID atomar an die Portal-VM-ID.
- [ ] Fremde/ungebundene Namensgleichheiten werden nicht verändert.
- [ ] 14 bestätigte Erfolge bleiben nach einem unklaren 15. Ergebnis bestätigt.
- [ ] Retry startet bestätigte Erfolge nicht erneut und ist bei laufenden/ungeklärten JIDs gesperrt; Recovery bleibt beim Quelljob.
- [ ] Unklar ohne JID führt niemals zu automatischem Doppel-Create.
- [ ] Create-only verändert weder Lifecycle noch MECM-Zustand.
- [ ] Full beginnt Folgeplaybooks nur nach vollständigem Create-Erfolg.
- [ ] Cancel startet keine nächste VM.
- [ ] DB-Ausfall beendet die externe Arbeit nicht und erlaubt keine nächste VM vor Ergebniscommit.
- [ ] Terminale Logs zeigen den tatsächlichen Tail auch oberhalb 1.000 Zeilen.
- [ ] Preflight schreibt keine vollständige Moduldokumentation mehr.
- [ ] Portal zeigt exakte Einheitenzähler, aber keinen erfundenen VM-Prozentwert.
- [ ] Async-Dateien und Remote-Artefakte werden sicher bereinigt oder als konkreter Rest sichtbar gemeldet.
- [ ] Migration und Frischschema konvergieren.
- [ ] DE/EN, Help, ADR, Deployment, Runbooks, QA und Changelog sind synchron.
- [ ] Alle gezielten Tests sowie Fast-/Integration-/Release-Gates sind grün oder ein echter Infrastrukturblocker ist mit unverändertem Codezustand dokumentiert.
- [ ] Reales Standalone-ESXi-Staging beweist Einzelaktivität, langen EZT-Lauf, Transportwiederaufnahme und zweiten idempotenten Lauf.
- [ ] Machine-API-, MECM-, PowerShell- und Legacy-Statusverträge sind unverändert grün.
- [ ] Vollständiger Diff wurde auf fremde Hunks, Secrets, temporäre Dateien und unregistrierte neue Owner geprüft.

---

## 20. Abnahmeprotokollvorlage

Diese Tabelle wird erst während der Umsetzung fortgeführt. Ein leeres Feld bedeutet „nicht geprüft“, nicht „nicht betroffen“.

| Etappe | Ergebnis | Code/SSoT | Tests | Help/Doku | Logs/Protokolle | Diff/Fremdhunks | Commit/Push, falls beauftragt |
|---|---|---|---|---|---|---|---|
| A Freigabe/Charakterisierung | grün | Entscheidungsblock F1 bis F5 unverändert übernommen, keine fachliche Änderung. Ausgangsstand gemessen statt angenommen: letzte Migration `0046_network_mac_contract`, nächster freier Schlüssel `0047`; `ansible_command.php` 30 Zeilen (bereits Fassade über `ansible_command_{shell,modes,preflight}.php`), `deploy_worker_mission.php` 367, `deploy_worker_finish.php` 320, `deploy_log.php` 315, `deploy_log.js` 488, `ssh.php` 337. Drei Forderungen des Plans sind durch spätere Etappen bereits erfüllt: Preflight-Spam entfernt (12.1), Modulsplit von `ansible_command.php` und Joblog-Poller (13.1), vollständiger Logtail mit `has_more`/`caught_up` (12.4 Punkte 1 bis 5, Etappe 10A). `PYTHONUNBUFFERED` fehlt weiterhin vollständig, das Create-Playbook mutiert weiterhin die ganze Auswahl in einem Schleifentask, und es gibt kein per-VM-Ergebnis. | `CreateFlowBaselineContractTest` `6/6`, 25 Assertions: gepufferter Einzelaufruf, Schleifentask ohne `async`, fehlende Ergebnistabelle, Retry über die gesamte Auswahl, Lifecycle-Klammer von Create-only, bereits vorhandener Tailvertrag. Jede Zusicherung nennt die Teiletappe, die sie umschreibt. Neues Gate `ansible-output-buffering` (Fast/Integration/Release) grün: ohne `PYTHONUNBUFFERED` kamen alle drei Item-Zeilen bei 9,57 s von 9,82 s an, mit ihr bei 4,11 s, 6,70 s und 9,30 s von 9,44 s; Schwellen sind Anteile der jeweiligen Gesamtdauer. Der Kontrollfall wird mitgeprüft, weil eine Laufzeit ohne Pufferung sonst jede Unbuffered-Behauptung durchwinkt. Unit/Static-Suite `1328/1328` grün ohne Skips, `yamllint` auf der neuen Fixture grün. | `docs/QA.md` Abschnitt „Etappe 14B baseline" mit den Messwerten, den drei bereits erfüllten Forderungen und den Laufzeitversionen; `docs/QUALITY-GATES.md` Zeile für das neue Gate. Keine Portaltexte, deshalb keine i18n-Änderung. | nicht betroffen: keine Audit-, Joblog- oder Containerlogzeile, kein Schema, kein Machine-API-Wire-Vertrag berührt. | Fremde Arbeit im Baum vor Etappenbeginn geprüft und als eigener Commit `f16a26d` abgeschlossen (14A-Nacharbeit), danach nur eigene Dateien angefasst. | `97565e9` „Etappe 14B-A: Den Create-Pfad vor der Reparatur festhalten", `origin/main` (GitHub `Samy94M/VirtuSphere-v2-WebApp`); gepusht 2026-09-02 08:46 zusammen mit B/C und D, Upstream-Hashgleichheit danach bestätigt (`origin/main...main` = 0/0, HEAD `4169018`). |
| B Modulsplits | grün ohne Codeänderung | Beide in 13.1 geforderten Splits sind seit dem Planungsdatum durch Etappe 8 und 12/13 erfolgt, nur unter anderen Namen: `ansible_command.php` ist eine 30-Zeilen-Fassade über `ansible_command_{shell,modes,preflight}.php`, und die Markerfunktionen liegen in `ansible_command_modes.php` (283 Zeilen, unter Budget) statt in einer eigenen Datei. Der Joblog-Poller liegt als `portal/assets/deploy_log.js` getrennt neben `deploy_form.js`, `deploy_storage.js`, `deploy_blockers.js` und `deploy_warnings.js`; eine Datei `deploy.js` gibt es nicht mehr. `lib/ansible_command_modules.php` ist die Ownerregistry, die statische Verträge laufen bereits über sie statt über einen Dateinamen, und `lib/layout.php` lädt die Assets in fester Reihenfolge. **Abweichung:** Die Dateinamen aus 13.1 (`ansible_modes.php`, `ansible_step_markers.php`, `ansible_remote_command.php`, `deploy-log.js`) werden NICHT nachgezogen. Ein Umbenennen wäre reine Bewegung: es müsste Ownerregistry, Require-Closure, Assetladung und mehrere Contracttests anfassen, ohne eine Frage zu beantworten, und die vorhandene Aufteilung erfüllt die Fachgrenze des Plans. | Keine neuen Tests, weil kein Verhalten bewegt wurde. Die Paritätsbeweise aus B.4 existieren und liefen im Rahmen von Teiletappe A grün mit: `phpunit-unit` `1328/1328` ohne Skips (darunter `AnsibleStepMarkerTest`, `AnsibleCommandModuleContractTest`, `CliRequireClosureContractTest`), `file-size` grün, `js-syntax` und `php-lint` grün. | nicht betroffen: keine Doku-Aussage geändert, die Modulaufteilung ist in AGENTS.md/ADR-0006 bereits als Regel beschrieben. | nicht betroffen. | Kein Diff außer dieser Protokollzeile. | Teil des Commits `e97cf50` von Teiletappe C; gepusht 2026-09-02 08:46, Upstream-Hashgleichheit bestätigt. |
| C Migration/Repo | grün | `lib/deploy_create_constants.php` ist die SSoT (Status, Aktionen, Outcomes, zehn Fehlercodes, Zeitgrenzen, JID-Klasse, Markerpräfix); das Gesamtbudget wird aus `VIRTUSPHERE_SSH_TOTAL_TIMEOUT_SECONDS` abgeleitet statt wiederholt. `lib/deploy_create_result.php` hält den Zustandsautomaten ohne SQL, `lib/repo/deploy_create_results.php` Speicherung und CAS. Die Reconciliation ist kein eigener Aktionstyp, sondern sind die `uncertain`-Ausgänge desselben CAS; die bestätigte Operatorfreigabe daraus ist Teiletappe F. Die Cleanup-Auswahl liest den Zustand des gebundenen Handles und lässt eine `uncertain`-Einheit ungeachtet ihrer Frist ausdrücklich aus, weil deren Async-Verzeichnis der einzige verbliebene Beweis über eine VM ist, deren Ausgang niemand festgestellt hat. Migration `0047_deploy_create_results` und das Frischschema legen `deploy_create_vm_results` und `deploy_jobs.create_started_at` identisch an; die Queue-Transaktion materialisiert die Auswahl für create-fähige Modi (abgeleitet über `ansible_mode_creates_vms()`, nicht gelistet) und schreibt die aufgelöste ID-Liste in den Payload, sodass eine später angelegte VM einen wartenden Auftrag nicht mehr erweitert. **Drei bewusste Abweichungen vom Plan, alle in dieselbe Richtung:** (1) Async-Verzeichnis, Cleanup-Zähler, Cleanup-Backoff und letzter Cleanup-Fehler stehen NICHT auf der Create-Zeile; sie gehören dem generischen Remote-Handle aus 8R-O, das es zum Planungszeitpunkt noch nicht gab, und die Zeile erreicht sie über `remote_execution_id`. Der Plan nennt diese Konstanten selbst „generische Remote-SSoT"; sie liegen jetzt in `remote_execution_constants.php`. (2) `async_dir` entfällt und wird aus dem unveränderlichen `remote_dir` des Handles abgeleitet. (3) Zwei Invariantenteile stehen in PHP statt im CHECK, weil MySQL eine Spalte nicht gleichzeitig in einem CHECK und in einem FK mit referenzieller Aktion erlaubt; `ON DELETE SET NULL` bleibt die richtige Aktion, weil RESTRICT eine Jobbereinigung je nach Kaskadenreihenfolge abbrechen könnte. Neun weitere Invarianten sind CHECK-Constraints, darunter die vierte Beweiskombination (nichts da, nichts geändert), die kein stiller Erfolg sein darf. | `DeployCreateResultStateTest` `10/10`, 78 Assertions, ohne Datenbank: geschlossene Übergangstabelle, terminale Zustände, die 14-von-15-Form des Vorfalls, `uncertain` nie als `failed` gezählt, Retry-Sperre bei ungeklärter Einheit. `DeployCreateConstantsContractTest` `7/7`, 68 Assertions: Zeitrelationen, Ableitung des Budgets (fand sofort die ausgeschriebene Zahl im eigenen Kommentar), Vollständigkeit der Statusklassen, JID-Klasse gegen Shell-Metazeichen, ENUM-Spiegel in beiden Quellen. `DeployCreateResultsIntegrationTest` `9/9`, 57 Assertions gegen echtes MySQL: Reihenfolge nach `vm_name`, gemeinsamer Commit von Job und Einheiten, sechs vom Schema abgewiesene unmögliche Zeilen, zwei konkurrierende Worker mit genau einem Gewinner, fremder Worker schreibt nichts, wiederaufgenommene `uncertain`-Einheit verliert ihre Abschlussmarken im selben Statement. `check-enum-sync.sh` um tabellenweite Paare erweitert (`status` ist mehrdeutig, neue Migrationen liegen nicht mehr in `migrate.php`) und in drei Driftrichtungen gegengeprüft; Guard-Harness `6/6` proven, darunter zwei neue Fälle. Dabei eine Altlücke geschlossen: `directory_constants.php` fehlte in der Fixture-Liste, wodurch deren Mutationsfälle aus dem falschen Grund rot wurden. Migration angewandt, `--check` pending=0, `schema-convergence` grün. | `docs/operations/deploy-chain.md` erklärt Materialisierung, Positionssemantik, `uncertain` und die bewusste Eigentümergrenze zum Remote-Handle; Planzeilen B und C. Keine Portaltexte, deshalb keine i18n-Änderung. | nicht betroffen: keine neue Audit-, Joblog- oder Containerlogzeile, kein Machine-API-Wire-Vertrag. Der Queue-Logsatz nennt jetzt bei create/full die aufgelöste VM-Zahl, weil er sie ab hier kennt. | Nur eigene Dateien. `CreateFlowBaselineContractTest` aus Teiletappe A planmäßig umgeschrieben; die Ownerregistry von `ansible_command_modes.php` und die Ausnahme für `migrate.php` (1248->1250) nachgezogen. | `e97cf50` „Etappe 14B-B/C: Jede Create-VM bekommt eine dauerhafte Zeile", `origin/main` (GitHub `Samy94M/VirtuSphere-v2-WebApp`); 20 Dateien, gepusht 2026-09-02 08:46, Upstream-Hashgleichheit danach bestätigt. |
| D Ansible-Protokoll | grün | `portal_vm_id` ist der Selektor in der Serverlist; `PYTHONUNBUFFERED=1` steht in der Präambel jedes Playbookaufrufs aller Modi. Vier Steuerplaybooks (Prepare read-only, Launch mit genau einem `vmware_guest` unter `async`/`poll: 0`, Status mit einer Abfrage, Cleanup gezielt für eine Job-ID) plus die eine gemeinsame Identitätsprüfung in `create_identity_check_tasks.yml`. `emit_create_result.py` und `lib/ansible_create_protocol.php` sind die beiden Hälften eines geschlossenen Markervertrags: sechs Ereignisse, exakte Feldlisten, jede ID gegen ihre Zeichenklasse, kein Rekonstruieren aus Ansible-Prosa. Der Preflight prüft zusätzlich die installierte Collection gegen den Pin aus `requirements.yml` und den installierten `ansible-core` gegen das `requires_ansible` genau dieser installierten Collection, sowie die Anlegbarkeit eines 0700-Async-Verzeichnisses mit 0600-Datei. **Drei Abweichungen:** (1) Das mutierende Playbook heißt `createVMLaunch-ESXi_playbook.yml` statt `createVMs-ESXi_playbook.yml`; das bestehende Schleifen-Playbook bleibt unverändert in Betrieb, bis Teiletappe E den Worker umstellt, weil sonst genau eine Etappe lang jeder Create-Auftrag auf dem Host stirbt. (2) Die geteilten Tasks liegen flach statt unter `tasks/`, weil der SFTP-Upload nur die oberste Ebene kopiert und ein Unterverzeichnis still fehlen würde. (3) Ein `rejected`-Marker trägt auch den Fall `async_state_missing`; das Ereignis ist die Form für „nichts getan, hier ist der geschlossene Grund", und ein siebtes Ereignis hätte den Vertrag geöffnet. Der host-seitige Async-Selbsttest entfällt: das Gate `ansible-create-async` beweist dieselbe Naht im gepinnten Image, und die neue Kernversionsprüfung schließt den Unterschied zwischen Host und Image an der Stelle, an der er entstehen konnte. | `test_emit_create_result.py` 13 Fälle innerhalb `python-client-tests` `47/47`; `AnsibleCreateProtocolTest` `12/12`, 43 Assertions, inklusive Feldlistenabgleich gegen die Python-Seite; `CreateFlowPlaybookContractTest` `9/9`, 84 Assertions (keine Schleife, genau ein mutierender Aufruf, UUID-Zweig, kein `module_args`, 0600-Resultatdateien, eine Identitätsmatrix, Statusentscheidung an der Statusdatei, Cleanup ohne Pfadlöschung); `AnsiblePlaybookVariableContractTest` um die dritte Quelle Extra-Vars und um eingebundene Task-Dateien erweitert, in beiden Richtungen. Neues Gate `ansible-create-async` grün: Start mit `poll: 0`, wiederauffindbare JID, laufend/fertig, gezielter Cleanup ohne Rest. `ansible-lint --strict`, `ansible-syntax` (11 Playbooks) und `ansible-module-contract` grün. **Zwei Befunde beim Bauen, beide gemessen statt vermutet:** `async_status` meldet eine verschwundene Job-ID als `finished` ohne `failed`, weshalb die Entscheidung auf die Statusdatei umgestellt und dieses Verhalten in der Fixture festgehalten wurde; und die eingebetteten Shell-/Python-Quellen trugen CRLF aus dem Windows-Checkout in die Remote-Shell, was `TERM` zu einem ungültigen Signal und `exit 1` zu einer illegalen Zahl machte. Beide Proben laufen jetzt in beiden Richtungen nachgewiesen. | `docs/operations/deploy-chain.md` erklärt Unbuffered-Ausgabe, die vier Steuerplaybooks, den Markervertrag und die beiden gemessenen Eigenschaften; `docs/QUALITY-GATES.md` beschreibt das neue Gate. Keine Portaltexte, deshalb keine i18n-Änderung. | nicht betroffen: kein neuer Audit-Code, keine Joblogzeile (der Marker wird erst in Teiletappe E gelesen), kein Machine-API-Wire-Vertrag. | Nur eigene Dateien. Ownerregistry von `ansible_command_modes/preflight` und die Fixture-Gateliste nachgezogen. | `4169018` „Etappe 14B-D: Einen Create-Aufruf je VM sprechfähig machen", `origin/main` (GitHub `Samy94M/VirtuSphere-v2-WebApp`); 31 Dateien, gepusht 2026-09-02 08:46 als Kopf des Fast-Forward `0536a67..4169018`, Upstream-Hashgleichheit danach bestätigt. |
| E Worker/Identität | grün | Der Missionsworker treibt die Create-Einheiten selbst: `deploy_worker_run_create_section()` liest die materialisierten Zeilen, prüft sie vor dem ersten Start (fortlaufende Positionen, gleiches `total`, keine doppelte VM, keine VM-lose Zeile), setzt `create_started_at` per COALESCE und arbeitet dann Einheit für Einheit. Die nächste VM startet ausschließlich, wenn keine Zeile `prepared`, `running` oder `uncertain` ist, und diese Antwort kommt aus den Zeilen statt aus dem Prozessgedächtnis; `uncertain` stoppt den Auftrag immer. Vier Steuerplaybooks je Einheit über `ansible_command_create.php`; die Identität wird nach jedem Erfolg in EINER Transaktion gebunden (`repo_deploy_create_commit_success()`: Job, Zeile und VM gesperrt, leere UUID binden, gleiche UUID nur MOID auffrischen, abweichende UUID schreibt nichts, vierte Beweiskombination `identity_result_invalid`). Terminalstatusmatrix in `deploy_worker_create_job_status()` (alles erfolgreich/übersprungen -> `succeeded`, mindestens ein Erfolg -> `partial`, keiner -> `failed`); Create-only lässt den Lifecycle netto unverändert (Klammer aus F3 erhalten), Full bricht vor dem Powercycle ab und konvergiert wie bisher. **Fünf bewusste Abweichungen, jede aus einem gemessenen Grund:** (1) Kein gebundenes Remote-Handle. `repo_prepare_remote_inventory_execution()` ist inventory-only und hart auf eine Standortaktivierung (`pilot_remote`/`remote_enabled`) gegated, die ohne 8R-S unerreichbar bleibt; Create/Full haben nicht einmal eine O5-Policy. `remote_execution_id` bleibt deshalb NULL, die Pflicht dafür fällt aus `deploy_create_assert_transition_fields()` (die DB-CHECK verlangte sie ohnehin nie), und das Async-Verzeichnis wird aus dem bereits deterministischen `ansible_remote_dir()` abgeleitet: `<remote-dir>/create.vm.<position>/async`. Damit findet ein neu gestarteter Worker denselben Zustand ohne zweite Kopie in der Datenbank, und der Legacy-Transport wird nicht heimlich zum Remote-Vertrag. (2) Der Legacy-Claim mintet jetzt `lock_token`/`worker_epoch`. Der CAS aus Teiletappe C vergleicht alle drei Fence-Teile, und keine Codestelle setzte sie: ohne diese Änderung hätte keine einzige Einheit je starten können. Eine Worker-ID überlebt einen Neustart, ein Zufallstoken je Claim nicht, und das ist genau der zurückkehrende alte Worker, gegen den der Fence steht. Alle sieben Stellen, die `locked_by` freigeben, leeren die zwei Spalten mit. (3) Die drei Identitäts-Asserts wandern aus `create_identity_check_tasks.yml` in geschlossene Fakten (`vs_identity_conflict`, `..._code`, `..._reason`), die Prepare, Launch und Status als `rejected`-Marker melden. Eine fremde Namensgleichheit ist der häufigste echte Betriebsfall; als abgebrochener Aufruf ohne Marker wäre sie beim Worker als `protocol_error` angekommen und hätte den Operator an die falsche Stelle geschickt. Ein abgebrochener Aufruf bedeutet jetzt genau eines: Transport, Zugang oder Modul kaputt. (4) `VIRTUSPHERE_PLAYBOOKS['create']` zeigt auf `createVMLaunch-ESXi_playbook.yml`, `ansible_remote_steps()` lässt den Create-Schritt aus, und `createVMs-ESXi_playbook.yml` ist gelöscht: kein Legacy-Fallback, weil ein zweiter Create-Pfad ein zweiter Beweis wäre. Ein Auftrag ohne materialisierte Zeilen wird mit einer Anweisung abgelehnt statt still über den alten Pfad geschickt. (5) `CreateSettleSeconds` entfällt samt Konstante, Serverlist-Feld und Golden-Fixture. Es war eine blinde 60-Sekunden-Pause nach der ganzen Auswahl; die Wartezeit ist durch Beweis ersetzt, weil jede Einheit bis zum Ende gepollt und ihre Live-Identität zurückgelesen wird. Der Reaper konvergiert in derselben Transaktion nur die noch fliegenden Einheiten nach `uncertain/ownership_lost` (`repo_deploy_create_converge_reaped()`) und lässt bestätigte Erfolge, Fehler und Skips unangetastet; ohne Recovery-Claim wäre ein stehengelassenes `running` die Behauptung eines Polls, den niemand mehr ausführt. | `CreateFlowWorkerContractTest` `12/12`, 55 Assertions, ohne DB und ohne SSH: kein Cleanup-Trap im Steuerkommando (die Naht, an der ein Trap den einzigen Beweis über eine laufende VM löschen würde), 0700-Anlage ohne Symlinkfolge, Entfernen des Vorgängerdokuments, Extra-Vars als typisiertes JSON in beiden Richtungen, Budgetgrenze bei -1/0/+1 und bei fehlender Startzeit, In-Flight schlägt niedrigere Pending-Position, vier Materialisierungsdefekte, Statusmatrix und die beiden Logzeilenformate. `DeployCreateWorkerFlowTest` `6/6`, 173 Assertions gegen echtes MySQL: die deterministische 15er-Fixture mit Fehlern an Position 1, 8 und 15 (zwölf bestätigte Erfolge bleiben unangetastet, Job `partial`, die unklare Einheit ist die aktuelle), bestätigter Fehler läuft weiter und `uncertain` sperrt, Identitätscommit in allen fünf Zweigen inklusive Replay und Groß-/Kleinschreibung, fremder Fence schreibt nichts, Reaper konvergiert genau die eine fliegende Einheit und ist idempotent, Skip-Kette löst auf die echte Erfolgsquelle auf. `DeployCreateResultStateTest`, `CreateFlowBaselineContractTest` und `AnsibleVmIdentityContractTest` planmäßig umgeschrieben. **Fünf Wächter mussten erweitert statt umgangen werden:** `AnsiblePlaybookVariableContractTest` kannte nur `item.<key>` und hätte `vm_instance_uuid` als unbenutzt gemeldet, genau als der Schlüssel in den schleifenlosen Pfad zog (jetzt zusätzlich `vs_target.<key>` plus die eingebundenen Taskdateien); `AnsiblePlaybookUploadTest` prüft statt einer durch die Deduplizierung leeren Aussage die Größe der jetzt beabsichtigten Überlappung; `EsxiTrustModeTest` zählt acht statt neun VMware-Playbooks und prüft den Trust-Vertrag dort, wo Create heute läuft; `AnsibleCommandModuleContractTest` und `DeployWorkerModuleContractTest` haben neue Owner-/Surface-Einträge; `check-doc-semantics.sh` liest die Hardware-Version aus dem neuen mutierenden Playbook. Fast-Lane vollständig `[31/31] pass`, 0 fail/infra/skip; Unit/Static `1392/1392`, 30.343 Assertions ohne Skips; `ansible-lint --strict`, `ansible-syntax` (10 Playbooks), `ansible-create-async` und `ansible-output-buffering` weiterhin grün. Zwei PHPStan-Befunde und vier Größenbefunde aus dem ersten Lauf wurden an der Ursache behoben, nicht per Ausnahme: `deploy_worker_create_unit.php` und `deploy_worker_create_poll.php` sind entlang ihrer Fachgrenzen in `_launch` (was vor der Mutation wahr sein muss) und `_remote` (was der Worker den Host fragt) geteilt, und `deploy_worker_conclude_create_section()` liegt bei der Create-Domäne statt bei den übrigen Abschlüssen. **Nicht bewiesen und ausdrücklich offen:** Transportverlust, Cancel während einer laufenden VM und DB-Ausfall sind an den reinen Funktionen und an den Zeilen geprüft, nicht am echten SSH-Transport; die 15.6-Standortfälle bleiben Teiletappe H beziehungsweise Standortabnahme. | Keine Portaltexte, deshalb keine i18n-Änderung; `lang-parity` grün. Betriebsdoku (`deploy-chain.md`, `DEPLOYMENT.md`, Runbooks, Hilfe) ist Teiletappe H und in dieser Teiletappe bewusst nicht angefasst; einzige Doku-nahe Änderung ist der SSoT-Pfad in `check-doc-semantics.sh`, ohne den der Wächter über eine gelöschte Datei geurteilt hätte. | Neue SYSTEM-Zeilen im Joblog, alle technisch und unlokalisiert: `[n/total] RUN|POLL|DONE|FAIL|HOLD create <name>` und eine Abschlusszeile `Create summary: total=… created=… …`. Der Reaper schreibt zusätzlich eine Zeile, wenn er offene Einheiten als ungeklärt festhält. Keine neue Audit-Kategorie, kein neuer Auditcode, kein Machine-API-Wire-Vertrag berührt; der Jobabschluss benutzt die vorhandene `deploy.outcome`-Zeile mit ihren bestehenden Ergebnissen. | Nur eigene Dateien; vor dem Etappenstart war der Baum sauber und `main` == `origin/main` (`126a164`). Kein fremder Hunk im Diff. | |
| F Retry/Reconciliation | grün | Die Create-Ergebnisse sind ab hier die erste Frage jedes Retrys: `deploy_create_retry_findings()` steht in `deploy_retry_blockers()` VOR Identität und Netz, weil sie etwas anderes beantwortet als beide, nämlich ob Arbeit des QUELLJOBS noch auf dem Host laufen kann. Drei geschlossene Antworten in der Reihenfolge ihrer Gewissheit: eine Einheit in `prepared`, `running` oder `uncertain` sperrt den ganzen Retry (`retry_create_unresolved`); eine VM der ursprünglichen Auswahl, die es nicht mehr gibt, sperrt ebenfalls (`retry_create_vm_missing`), weil der Retry sonst eine Einheit materialisiert, die der Worker bei seiner ersten Prüfung ablehnt; und ein create-fähiger Auftrag ganz ohne Ergebniszeilen wird fail-closed abgelehnt (`retry_create_results_missing`, Plan 10.4), weil ihm der per-VM-Beweis fehlt und ein Retry ihn nur erfinden könnte. Die Materialisierung bleibt genau eine: `repo_create_deploy_job()` bekommt die Quellzeilen als Parameter und ruft dann `repo_deploy_create_materialize_retry()` statt der frischen Materialisierung, sodass ein bestätigter Erfolg als `verify_skip` mit Verweis auf die beweisende Zeile entsteht. Der neue Payload trägt die VOLLSTÄNDIGE ursprüngliche Auswahl (10.3), nicht die gescheiterte Hälfte: die Folgeplaybooks müssen jede VM anfassen, für die der Auftrag eingereiht wurde. Die Freigabe aus 10.5 ist die dritte Aktion in `deploy_recovery_actions.php` und nicht eine neue Seite: „Externe Prüfung dokumentieren“ ist die Stelle, die der Plan meint, und ihr append-only Beweistisch existiert. `deploy_create_release_blockers()` ist die eine Entscheidung mit acht geschlossenen Gründen; sie verlangt einen erfolgreichen Pull genau dieses ESXi-Zugangs, dessen VM-Kind nachweislich geantwortet hat (`kind_freshness_json`, nicht die Zeilen selbst: deren Abwesenheit heißt „nicht beantwortet“ oder „nichts gefunden“ und das ist nicht dasselbe), der STRIKT neuer ist als Job und Ergebnis, und in dem weder der Snapshotname noch die gespeicherte Instance-UUID vorkommt. `repo_deploy_create_release_unit()` entscheidet alles unter dem Lock neu und schreibt Resolution und `uncertain -> failed/operator_released` in einer Transaktion. **Fünf bewusste Abweichungen:** (1) Der Export-Nachlauf eines partiellen Auftrags bleibt unangetastet: er erzeugt keine Create-Einheiten und behauptet nichts über sie, also greift die Create-Matrix erst, wenn der effektive Modus wieder VMs erstellt. (2) Migration 0048 erweitert `deploy_recovery_resolutions` um `create_result_id` und den Scope `create_unit`, statt die Freigabe als `legacy_job` abzulegen: ein Auftrag kann mehrere unklare Einheiten haben, und ein Beweis, der nicht sagt welche, ist keiner. Die Scope-Regel für die neue Spalte steht wieder in PHP, weil MySQL eine Spalte nicht gleichzeitig in CHECK und FK mit referenzieller Aktion erlaubt. (3) Im Frischschema wandert der `deploy_create_vm_results`-Block vor `deploy_recovery_resolutions`, weil der neue FK sonst auf eine noch nicht existierende Tabelle zeigt. (4) KEINE Zeile im Joblog des Quelljobs. Der Append-Pfad lehnt sie ab, und das ist die richtige Ablehnung: ein Joblog ist der Bericht dessen, was der AUFTRAG getan hat, und eine Entscheidung, die Stunden später ein Mensch trifft, ist nicht während des Laufs passiert. Die Freigabe steht dreifach woanders (Ergebniszeile, Resolution, Audit). (5) Der Recent-Tasks-Nachweis ist ein Kontrollkästchen, kein Check: das Portal kann die Aufgabenliste des Hosts nicht abfragen, und der Begleittext sagt genau das, statt eine Prüfung zu behaupten, die niemand ausgeführt hat. | `DeployCreateRetryMatrixTest` `6/6`, 22 Assertions, ohne DB: bestätigter Erfolg und bestätigter Skip werden verifiziert statt neu erstellt, jeder der drei fliegenden Zustände sperrt den ganzen Retry, der Retry-Scope ist die vollständige Auswahl, und jeder der acht Freigabegründe hat einen Text in beiden Katalogen (ein Blocker, den die Seite nicht benennen kann, ist eine Sackgasse). `DeployCreateReleaseTest` `4/4`, 42 Assertions gegen echtes MySQL: kein Pull, ein zu alter Pull, ein Pull mit unbeantworteter VM-Abfrage, ein Namensgleicher in genau diesem Pull und die gespeicherte UUID unter anderem Namen lehnen jeweils ab; die erfolgreiche Freigabe schreibt Resolution mit Scope, Code, Ergebnis-ID, Begründung und Beleg, setzt `operator_released`, fasst die VM nicht an, schreibt keine Joblogzeile und lehnt den zweiten Versuch auf derselben Einheit ab; eine leere Begründung ist eine `ValidationException`. `DeployJobRetryFlowTest` um zwei Fälle erweitert (Legacy-Ablehnung, unklare Einheit sperrt) und in drei bestehenden Fällen auf die neue Welt gehoben: die Fixture sagt jetzt ausdrücklich, ob ihr Auftrag Create-Zeilen hat, statt eine der beiden Welten zufällig zu treffen. Integration-Suite vollständig `377/377` grün (15 Skips sind die vorhandenen LDAP-Fixture-Skips, keiner davon neu). Unit/Static `1398/1398`, 30.571 Assertions, ohne Skips. Fast-Lane `[31/31] pass`, 0 fail/infra/skip. Migration angewandt, `migrate.php --check`: `pending=0`. Vier Wächter forderten ihre Einträge und bekamen sie an der Ursache: Audit-Registry (Kontextfelder `position`/`blocker`), Audit-Presenter (der Satz nennt, was der Operator FESTGESTELLT hat, nie was das System geprüft hätte), die Repo-Fassaden-Surface und die Confirm-Klassifizierung als Row-Action mit `:name`. | DE/EN je 19 neue Schlüssel für Freigabekasten, Begründungsfelder, Attestierung und alle acht Blocker; `lang-parity` grün, echte Umlaute, keine Gedankenstriche. Betriebsdoku und Hilfe bleiben Teiletappe H. | Neuer Auditcode `deploy.create_released` mit Erfolg UND Fehlschlag: eine Ablehnung heißt, dass sich der Nachweis zwischen Ansehen und Bestätigen bewegt hat, und das ist genau das Rennen, gegen das die Aktion abgesichert ist. Kontext trägt Position, Ergebnis-ID beziehungsweise den ersten Blocker; die Begründung des Operators steht ausschließlich in der append-only Resolution hinter `system.config`. Keine Joblogzeile, kein Machine-API-Wire-Vertrag berührt. | Nur eigene Dateien; Baum vor F sauber, `main` == `origin/main` + Commit `5d7db17`. Der einzige Eintrag in der bis dahin leeren `PENDING_ACTIONS`-Liste ist der Browsernachweis der Freigabeaktion, ausdrücklich Teiletappe G zugeschrieben statt mit einem Marker behauptet. | |
| G Portal/Logtail | grün | Soll/Ist zuerst: von den sechs Planpunkten waren drei bereits durch Etappe 10A und 13 erfüllt (vollständiges Nachladen bis `caught_up`, DOM-Grenze, höfliches Autoscroll, Visibility-Pause, `has_more`), einer war halb erfüllt (Rohdownload ja, Pagination nein). Neu ist deshalb nur, was fehlte. `lib/deploy_create_progress.php` ist die eine Sicht: `deploy_create_progress_from_rows()` liefert das Viewmodel, `..._payload_from_view()` dessen Drahtform, und Serverrender wie Pollantwort lesen dieselbe Funktion, damit Karte und Live-Aktualisierung nicht zwei Meinungen über einen Auftrag werden. Jede Zahl kommt aus `deploy_create_vm_results`, nie aus dem Protokolltext darüber. Ein Prozentwert innerhalb einer VM wird bewusst nirgends gezeigt: das meldet keine Komponente, und ein trotzdem gefüllter Balken wäre dieselbe Erfindung wie der Idle-Timeout, der den Vorfall ausgelöst hat. Die Kopfzeile zählt `concluded` = bearbeitet minus ungeklärt und ausdrücklich nicht `deploy_create_summary()['processed']`: dessen Bedeutung („der Worker ist drangekommen") ist workerseitig richtig und bei 15 festgenagelt, vor einem Menschen sagt sie „15 von 15" über einer Zeile „Ungeklärt 1" | Neu `DeployCreateProgressTest` (9 Tests, 93 Assertions, ohne Datenbank: 14+1, gestoppter Auftrag mit Nichtbegonnenen, ungeklärt ist nicht „läuft", kein Rohtoken auf der Leitung, fehlende Startzeit) und `DeployCreateProgressContractTest` (2 Tests, 44 Assertions, Verdrahtung in beide Richtungen mit Nullmatch-Probe). Neu `tests/e2e/specs/deploy-create-progress.spec.js` mit der vom Plan geforderten Lage: 1.601 Protokollzeilen, terminaler Fehler am Ende des Tails, Fortschritt 14/15 mit einer ungeklärten Einheit; 5/5 grün. Der JS-lose Pfad wird in einem Kontext mit `javaScriptEnabled: false` geprüft, nicht per Klick mit laufendem Skript: der Handler war nie das Fehlende | Neuer Hilfeabschnitt `help-create-progress` im Deploy-Panel, in `VIRTUSPHERE_HELP_SECTIONS` registriert und über `help_url('deploy', 'help-create-progress')` verlinkt; er erklärt, dass „ungeklärt" weder Fehler noch Erfolg ist, dass vor einer Wiederholung der Bestand zu prüfen ist und dass eine Wiederholung eine ungeklärte Einheit nicht von selbst übernimmt. Neunzehn Portalschlüssel und vier Hilfeabsätze in DE und EN, Parität über 32 Module grün, keine ausgeschriebene Konstante, kein Gedankenstrich. `docs/QA.md` nennt die neue Spec und warum ihre zwei Kernaussagen nur im Browser entscheidbar sind | Keine neue Auditkategorie, kein neues Ereignis, keine Änderung an Joblogpersistenz, Streamwerten oder Retention. Das Polling-JSON wächst additiv um `create_progress`; `null` bedeutet „dieser Auftrag hat keinen Create-Abschnitt" und der Browser entfernt die Karte, statt sie auf null zu zeigen. Maschinen-API und Wire-Verträge unberührt | Zwei Befunde am fremden Bestand, beide innerhalb der Etappe behoben statt vertagt, weil das Frischschema sonst gar nicht startet: `deploy_recovery_resolutions` in `struktur.sql` trug den Fremdschlüssel `fk_deploy_recovery_resolution_create_result`, aber die Spalte `create_result_id` fehlte (Frischinstallation bricht bei `ERROR 1072 at line 644` ab, Migration 0048 war korrekt), und dieselbe Tabelle schrieb ihre CHECK-Literale ohne `_utf8mb4`, während die Migration sie mit Präfix schreibt, was die Konvergenzprüfung als `_latin1` gegen `_utf8mb4` meldete. Beides nachgezogen, `schema-convergence` und `migrate-check` wieder grün. Ein dritter Fremdbefund kam aus dem Wächterlauf: `guard-harness` meldete 88 bewiesen, 0 unbewiesen und 16 infra, alle mit derselben Ursache, dass `Ansible/createVMs-ESXi_playbook.yml` nicht mehr existiert. Teiletappe D hat es in vier Playbooks zerlegt und `check-doc-semantics.sh` korrekt auf `createVMLaunch-ESXi_playbook.yml` mitgezogen, die Fixture in `scripts/test-guards.ps1` aber nicht: die Prüfung funktionierte weiter, der Beweis, dass sie greift, war seit Teiletappe D weg, und sechzehn Wächter belegten nichts mehr. Sichtbar wurde das nur, weil die Harness infra nicht als bewiesen zählt und mit Exit 2 endet statt sich grün zu melden. Fixture auf den Namen gezogen, den die Prüfung liest, mit dem Grund daneben; Gate danach `[1/1] pass` mit 104 bewiesen, 0 unbewiesen, 0 infra. Sonst ausschließlich Etappe-G-Pfade | Wird mit den lokal noch nicht gepushten Teiletappen E und F gemeinsam auf `origin/main` gebracht; Push ohne Force, danach Hashgleichheit von `HEAD` und Upstream geprüft |
| H Doku/Gates/Staging | Punkte 1 bis 4 grün; Punkt 5 (Standalone-ESXi-Staging) offen, braucht echte Hardware | Diese Teiletappe ist Doku- und Vertragsarbeit; sie ändert keine Fachlogik, keine Migration und kein Playbook. Zwei Quelltextänderungen waren trotzdem nötig, weil sie Aussagen sind: `lib/ansible_yaml.php` nannte in seinem Kommentar das in E gelöschte `createVMs-ESXi_playbook.yml` als einzigen Leser von `item.datastore_name`, und `lib/help/missions.php` musste den neuen `:hours`-Platzhalter aus `deploy_create_total_budget_seconds()` speisen, statt die Zahl in den Text zu schreiben. **Soll/Ist gemessen statt angenommen:** Von den Zeilen der Doku-Matrix, die ich für offen hielt, war eine bereits erledigt (`docs/QUALITY-GATES.md` beschreibt `ansible-create-async` und `ansible-output-buffering` vollständig; sie kamen mit Teiletappe E). `docs/QA.md` beschrieb dagegen eine Version von `CreateFlowBaselineContractTest`, die es seit E nicht mehr gibt: der Text nannte sechs Aussagen über den ALTEN Pfad, während die Datei sie längst umgeschrieben enthält. Zwei Dateien außerhalb der Matrix nannten das gelöschte Sammelplaybook ebenfalls (`docs/adr/ADR-0023`, `docs/operations/esxi-inventory.md`) und wurden mitgezogen: ein gemeldeter Fall ist eine Stichprobe. | Fast-Lane `[31/31]`: 31 pass, 0 fail, 0 infrastructure_error, 0 not_applicable, 0 skip, Exit 0. Integration-Lane `[38/38]`: 38 pass, 0 fail, 0 infrastructure_error, 0 not_applicable, 0 skip, Exit 0 (darin `phpunit-full` 330,4 s, `schema-convergence` 18,5 s, `e2e-portal` 960,2 s, `guard-harness` 739,3 s). Zusätzlich einzeln grün: `check-doc-semantics.sh`, `check-doc-hygiene.sh`, `check-enum-sync.sh`, `check-php-version-sync.sh`, `check-bounds-sync.php`, `scripts/lang-audit.php --ci`. **Browsergegenprobe statt Quelltextvertrauen:** Die Hilfeseite wurde angemeldet abgerufen und die vier geänderten Absätze im gerenderten HTML geprüft; die Konstanten lösen sichtbar zu „30 Minuten" und „4 Stunden" auf, es bleibt kein `:platzhalter` stehen und kein `__t()`-Key echot sich selbst. | Alle Zeilen der Matrix aus Abschnitt 16 abgearbeitet. `deploy-chain.md` trug zwei Abschnitte mit dem Titelzusatz „(Etappe 14B, vorbereitet)", die beide ausdrücklich sagten, der neue Ablauf werde noch nicht ausgeführt; beide beschreiben jetzt das laufende System, und ein neuer Abschnitt erklärt, wie der Worker eine Create-VM treibt. `DEPLOYMENT.md` nannte an drei Stellen das gelöschte Playbook und dokumentierte `CreateSettleSeconds` als ausgeliefertes Feld; dazu kam ein eigener Abschnitt mit Laufzeitversionen, unbuffered Ausgabe, abgeleitetem Async-Verzeichnis, Budget, Cleanup und Identitätsbindung. `troubleshooting.md` bekam drei Symptomzeilen (halbstündige Stille, ungeklärte Einheit mit `HOLD`, gesperrte Wiederholung), jede mit der Unterscheidung „JID vorhanden" gegen „JID fehlt" und mit den beiden tatsächlichen Portalaktionen beim Namen. `go-live.md` verlangt einen Create-Rauchtest vor der Freigabe, `offline-install.md` den Async-Selbsttest und die gepinnten Wheels, `INSTALLATION-ANLEITUNG.md` die Versionsuntergrenzen und ein neustartfestes `/tmp`. `help_deploy` erklärt die feinere Abbruchgrenze und die Reihenfolge samt `RUN`/`POLL`/`DONE`/`FAIL`/`HOLD`, `help_missions` sagt beim Plattentyp, dass der Poll die falsche Stille verhindert und nicht den Gesamtdeckel. Neu: `ADR-0041` mit Indexeintrag, Amendment in `ADR-0036`, Changelogeintrag, dauerhafte Regeln in `AGENTS.md`, `GROK.md`, `.claude/rules/ansible.md` und `.claude/rules/webapi.md`. | nicht betroffen: Diese Teiletappe schreibt keine neue Log-, Audit- oder Wire-Zeile. Sie beschreibt die in E und G eingeführten SYSTEM-Zeilen (`[n/total] RUN\|POLL\|DONE\|FAIL\|HOLD create <Name>` und `Create summary: …`) erstmals in Betriebsdoku und Hilfe, ohne ihr Format anzufassen. | Nur eigene Dateien: 23 modifizierte plus `docs/adr/ADR-0041-worker-driven-per-vm-create.md`. Kein fremder Hunk im Diff, kein `git add -A`; vor dem Etappenstart war der Baum sauber und `main` == `origin/main` (`9d0269e`). | |
| H Befund, bewusst nicht hier behoben | offen, verschoben | Die Doku-Matrix verlangt für `ADR-0036` die Aussage „Full braucht `identity_unbound_allowed` nicht mehr". Sie stimmt nicht. `serverlist.yml` wird EINMAL bei der Auftragsvorbereitung erzeugt (`lib/ansible.php`), bevor das erste Playbook läuft; die UUID, die ein späterer Schritt desselben `full`-Auftrags aus dieser Datei liest, ist deshalb der Stand VOR dem Create, egal was die Datenbank inzwischen weiß. Das Flag bleibt tragend. Es zu entfernen hieße, das Artefakt zwischen den Schritten neu zu erzeugen, und das ist eine Verhaltensänderung mit eigenem Hostnachweis, keine Dokuarbeit. Die wahre Aussage steht jetzt im `ADR-0036`-Amendment. | | | | | |
