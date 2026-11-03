# Audit: Staffelung am abschließenden Start der Full Pipeline

Stand: 2026-09-11. Status: Prüfung und vorgeschlagener Zielvertrag, noch nicht implementiert.
Grundlage: aktueller Arbeitsbaum; keine Ausführung auf ESXi oder MECM.

## Ergebnis

Für das Ziel, die abschließenden Einschaltungen zur Installation zu verteilen,
gehört die Full-Staffelung in die Startphase nach Create, Power-Cycle und
MAC-Export. Das entkoppelt den gewünschten Abstand von Eager-Thick-Anlegezeiten
und vermeidet die wiederholte MECM-Startwartezeit pro Ein-VM-Auftrag.
Es ist nicht in jedem Fall besser: Die erste Installation beginnt später,
ein unvollständiger Create-Abschnitt verhindert die nachfolgenden Schritte für
die gesamte Auswahl, und Zeit- sowie Mengengrenzen gelten dann gemeinsam.

## Bestätigtes Ist-Verhalten

- `lib/deploy_actions.php`: gesetzte Staffelung verwendet
  `repo_enqueue_deploy_group()`, sonst entsteht ein Missionsauftrag.
- `lib/repo/deploy_job_queue.php::repo_enqueue_deploy_group()`: ein Auftrag pro
  VM, `scheduled_at = base + index * interval`, gemeinsames `group_id`.
- `lib/repo/deploy_job_worker.php::repo_claim_next_deploy_job()`: Termine sind
  früheste Übernahmezeitpunkte. Überfällige Termine werden nicht neu verteilt.
- `lib/deploy_worker_loop.php::deploy_worker_run_once()`: ein Worker verarbeitet
  einen Auftrag bis zur Rückkehr, bevor er den nächsten übernimmt.
- `lib/deploy_worker_mission.php`: alle Create-Einheiten müssen erfolgreich sein,
  bevor die übrigen Playbooks für die gesamte Auswahl laufen.
- `ansible/startVMs-ESXi_playbook.yml`: Identitäten lesen, einmal
  `StartWaitSeconds` warten, danach die Auswahl ohne Staffelintervall einschalten.

Die `lib/`-Pfade in diesem Audit beziehen sich auf `Docker/WebAPI/lib/`.

## Befunde und notwendige Entscheidungen

| ID | Gewicht | Befund / Randfall | Konsequenz für die Umsetzung |
| --- | --- | --- | --- |
| S01 | P1 | `deploy_job_payload()` und `ansible_job_payload()` speichern bzw. lesen keine Startstaffelung. Das Formularfeld wirkt ausschließlich auf die Queue-Aufteilung. | Ein bloßes Verschieben der Pause reicht nicht. Neue Startsemantik explizit im Auftrag persistieren und bis zum ausführenden Owner durchreichen. |
| S02 | P1 | `deploy_create_total_budget_seconds()` übernimmt aktuell 14400 Sekunden aus `VIRTUSPHERE_SSH_TOTAL_TIMEOUT_SECONDS`; die Zeit läuft ab `create_started_at` für den gesamten Create-Abschnitt. | Aus 15 getrennten Budgets würde ein gemeinsames Vierstundenbudget. Bei angenommenen 20 Minuten Create je VM benötigen 15 VMs bereits 300 Minuten. Budgetentscheidung ausdrücklich treffen; nicht still erhöhen oder pro VM zurücksetzen. |
| S03 | P1 | Die Staffelgruppe ist von der gemeinsamen Scope-Grenze ausgenommen, weil jedes Mitglied einen eigenen Callback erzeugt. Aktuell gelten 40 VMs und 10 Interfaces pro VM. | Bei einem gemeinsamen Full-Auftrag muss die gesamte Auswahl gegen dieselbe SSoT geprüft werden. Portalblocker, Live-Endpunkt, Preview, Queue, Retry und Worker müssen dieselbe Auftragsform verstehen. 15 VMs liegen innerhalb der VM-Grenze. |
| S04 | P1 | Der Start ist derzeit ein Playbook-Schritt. Abbruch wird zwischen Schritten geprüft. Eine lange Pause zwischen dessen VM-Starts würde die Abbruchwirkung bis zum Ende der gesamten Schleife verschieben. | Vor jedem weiteren Einschalten und während der Wartezeit Abbruch und Ownership prüfen. Bereits eingeschaltete VMs bleiben an; kein nächster Start nach bestätigtem Abbruch. Keine zweite unabhängige Cancellation-Implementierung. |
| S05 | P1 | `deploy_job_retry_plan()` macht aus einem fehlgeschlagenen Auftrag mit erfolgreichem MAC-Ergebnis einen Export-Retry. `repo_deploy_evaluate_retry()` verwendet diesen Plan auch nach späterem Startfehler. | Ein Retry wäre keine Fortsetzung ausstehender Starts. Startfortschritt und Wiederaufnahme müssen separat vom MAC-Ergebnis belegbar sein. Remote-/Generations-/Identitätsfences behalten Vorrang. |
| S06 | P1 | `exportVMs-Informations-ESXi_playbook.yml` akzeptiert Uploader-Rückgabecode 20 als Teilerfolg. Der Worker kann danach ins Startplaybook gehen und bewertet das MAC-Ergebnis erst am Sequenzende. | Vor dem gestaffelten Full-Start explizit den dauerhaften MAC-Befund auswerten. Vorgeschlagen: bei unvollständigem Export keine abschließenden Starts; Erfolg einzelner MAC-Imports nicht als vollständige Bereitschaft darstellen. Eine Fortsetzung nur für erfolgreiche VMs wäre eine eigene Scope-/Retry-Entscheidung. |
| S07 | P1 | Staffelabstände sind bis 120 Minuten erlaubt; SSH-Idle ist 30 Minuten und ein SSH-Befehl hat vier Stunden Gesamtbudget. | Keine einzelne zweistündige Ansible-Pause einbauen. 15 VMs ergeben bei maximalem Abstand bereits 28 Stunden reine Zwischenzeiten. Unterbrechbare, beobachtbare Wartephasen und ein explizites Gesamtbudget sind erforderlich. |
| S08 | P2 | Die Preview berechnet heute absolute Zeiten ab Auftragsbeginn. Create-Dauer, Export und Startwartezeit sind darin nicht enthalten. | Für neue Full-Aufträge nur Auftragsbeginn und relative Startabstände anzeigen. Tatsächliche Einschaltzeiten erst aus Ausführungsbelegen anzeigen; keine festen Uhrzeiten vor abgeschlossener Vorbereitung versprechen. |
| S09 | P2 | `repo_deploy_group_vm_list()` behält explizite Auswahlreihenfolge; eine Gesamtauswahl und die regulären VM-Leser sortieren nach VM-Name. Die Create-Einheiten haben ihre eigene dauerhafte Position. | Eine kanonische, beim Einreihen gespeicherte Startreihenfolge mit stabilem ID-Tiebreaker verwenden. Preview, Log, Ausführung und Retry dürfen nicht unabhängig sortieren. Keine neue Auswahl durch einen geänderten Namen. |
| S10 | P2 | Alte wartende Gruppen enthalten weiterhin komplette Full-Aufträge je VM und keine neue Startpolicy. | Alte Aufträge nicht umdeuten oder zusammenlegen. Neue Semantik kenntlich persistieren; fehlende neue Felder behalten historische Bedeutung. Historische Gruppenansicht und Gruppenabbruch bleiben lesbar/funktionsfähig. |
| S11 | P2 | Der Parser castet vor der Bereichsprüfung auf Integer; negative Werte können wie ausgeschaltet behandelt, Dezimalwerte abgeschnitten werden. | Gemeinsame strikte Normalisierung: leer/0 = aus, sonst ganze Minuten innerhalb der SSoT-Grenzen; auch manipulierte POSTs und gespeicherte Payloads testen. |
| S12 | P2 | Die Formularhilfe verspricht VM-Startabstände und „Leer = alle zusammen“. Die ausführliche Hilfe nennt Ein-VM-Aufträge, stellt deren Abstand aber ebenfalls ohne die Verzögerung durch laufende Aufträge dar. | Bereits heute missverständliche Produktaussage. Beim Umbau beide Sprachen, Vorschau, Auftragsdetails, Abbruch und Retry gemeinsam auf den tatsächlich implementierten Vertrag bringen. |

## Vorgeschlagener Zielvertrag

1. Für neue Full-Aufträge entsteht ein Auftrag mit materialisierter Auswahl.
   Create bleibt genau eine workergetriebene Einheit pro VM nach ADR-0041.
2. Erst nach vollständig bestätigtem Create laufen benötigte MAC-Power-Cycles
   und MAC-Export. `uncertain` bleibt eine harte Sperre, nie automatischer Neustart.
3. Vor der abschließenden Startphase muss ein vollständiger, zum aktiven Auftrag
   gehörender MAC-Erfolg belegt sein. Danach einmal die Startwartezeit abwarten.
   Diese Pause ist keine bestätigte MECM-/Task-Sequence-Bereitschaft.
4. Die erste startbereite, ausgeschaltete VM einschalten. Nach bestätigtem
   Einschalten mindestens das eingestellte Intervall bis zum nächsten neuen
   Einschalten warten. Langsame ESXi-Aufrufe verlängern den Abstand.
   Keine Nachholwelle nach Verzögerung, Worker-Neustart oder DB-Ausfall.
5. Bereits eingeschaltete VMs als beobachtet/protokolliert überspringen;
   sie verbrauchen keinen neuen Startslot. Suspendierte VMs nicht implizit
   wie ausgeschaltete behandeln: sichtbare Policy bzw. Blocker definieren.
6. Leer oder 0 bedeutet keine zusätzliche Zwischenpause. Eine einzelne VM
   verursacht keine Staffelwartezeit; nach der letzten VM keine weitere Pause.
7. Pro VM dauerhafte Startbelege mit Position, Identität und Ergebnis, technisch
   protokolliert als `[n/total] RUN ...` und `[n/total] <Ergebnis> ...`.
   Ein API-Timeout nach einer möglichen Einschaltung ist ungeklärt, kein
   Beweis für „nicht gestartet“. Vor Fortsetzung live nach UUID abgleichen.
8. Beim späteren Start jeder VM ihre Identität erneut prüfen. Der heutige
   Startcode liest alle Identitäten vor der Wartezeit; bei langer Staffelung
   würde dieser Abstand noch wachsen. Kein Name-Fallback und kein Recreate.
9. Startwarten muss Heartbeats/Ownership und Abbruch bedienen. Bei einem
   monolithisch wartenden Worker sind auch andere Missionen und Inventarabrufe
   blockiert; diese Betriebsfolge sichtbar machen. Freigabe des Workers wäre
   ein größerer Scheduler-Umbau mit fortbestehenden Missions-/Job-Fences.
10. Full und Start sollten für die abschließende Einschaltung dieselbe Policy
    verwenden. Powercycle hat keinen abschließenden Installationsstart und
    braucht eine ausdrücklich getrennte Bedeutung bzw. keine solche Option.
    Create, Export und ESXi-Autostart bekommen keine erfundene Startphase.

Der zur MAC-Erzeugung erforderliche Power-Cycle schaltet bereits vor Schritt 4
kurz ein. Das ist keine Garantie, dass vorher keinerlei PXE-Anfrage entsteht.
Die neue Funktion staffelt die abschließenden Einschaltungen, nicht jeden
möglichen Bootvorgang und nicht die Zahl gleichzeitig laufender Installationen.

## SSoT und betroffene Dokumentation

| Owner / Dokument | Zu bewahrender oder anzupassender Vertrag |
| --- | --- |
| `lib/ansible_command_modes.php` | Modusfähigkeiten aus der Playbooksequenz ableiten; keine zweite fest codierte Full/Start-Liste. |
| `lib/deploy_constants.php`, `lib/defaults.php` | Einheiten, Grenzen und Standardwerte besitzen je genau eine Quelle. Minuten nur an der Eingangsgrenze in Sekunden umrechnen. |
| `lib/repo/deploy_job_input.php`, `lib/ansible_command_modes.php`, `lib/ansible_yaml.php` | Eine gemeinsame Normalisierung und dieselbe persistierte Policy für Queue, Worker, Artefakte und Retry. |
| `lib/deploy_blockers.php`, `lib/repo/vm_network.php` | Eine Scope-/Netzwerkentscheidung passend zur tatsächlich erzeugten Auftragsform. |
| `lib/repo/deploy_job_retry.php`, `lib/deploy_worker_finish.php` | Startbelege und MAC-Ergebnis getrennt bewerten; kein neuer Writer der MECM-/Client-Lifecycle-Zustände. |
| `lib/deploy_display.php`, Preview, Auftragskarten, Logansicht | Intervall, Startphase und verbleibende VMs anzeigen; historisch gespeicherte technische Werte erhalten. |
| `lang/de/deploy.php`, `lang/en/deploy.php` | Feldhilfe, Modussperre, Preview, Gruppen-/Auftragsbeschriftung. |
| `lang/de/help_deploy.php`, `lang/en/help_deploy.php` | Zeitplan, Grenzen, Full-Reihenfolge, Wartezeit, Retry, Abbruch und Unterschied zu ESXi-Autostart. |
| `lib/help/deploy.php`, Hilfe zu Festplattentypen | Grenzen aus Konstanten interpolieren. Eager-Thick-Dauer nicht als feste Prognose darstellen; Typ wirkt bei Erstellung und wird nicht als realisiert zurückgelesen. |
| ADR-0022 | Entscheidung durch eine neue ADR nachvollziehbar ergänzen/ablösen; alte Gruppen nicht rückwirkend als neue Startstaffelung beschreiben. |
| ADR-0030, ADR-0033, ADR-0041 | Teilresultate, Abbruchgrenzen, Recovery und gemeinsames Create-Budget berücksichtigen. |
| `docs/DEPLOYMENT.md`, `docs/QA.md`, `docs/CHANGELOG.md` | Betrieb und Abnahme aktualisieren; historische Changelog-Einträge als Historie erhalten. |
| `AGENTS.md`, `GROK.md` | Die Aussage „Staffelung = Ein-VM-Aufträge“ ist heute Teil des Scope-Vertrags. Nach einer Umsetzung neuen Full-Pfad und historische Gruppen unterscheiden. |

Zusätzlicher bestehender Dokumentationsdrift: `docs/DEPLOYMENT.md` spricht im
Serverlist-Abschnitt noch von „three timing values“, obwohl derselbe Absatz
das entfernte `CreateSettleSeconds` erklärt und nur zwei Wartewerte nennt.

## Abnahmematrix für die Umsetzung

- Full: 15 langsame Create-Einheiten, schneller Create, 1 VM, leere Auswahl,
  explizite Teilmenge, Create-Teilerfolg und `uncertain`; kein Start vor dem Gate.
- Timing mit kontrollierter Uhr: leer, 0, 1, 2, 120 Minuten; keine erste/letzte
  Zusatzpause; langsamer API-Call, Uhrsprung und überfälliger Termin ohne Aufholwelle.
- Eingaben: negative Werte, Dezimalzahl, Text, oberhalb Maximum; identisches
  Ergebnis in Formular, POST, Repo, gespeichertem Payload und Worker.
- MAC: vollständig, teilweise, fehlend, verlorene HTTP-Antwort, falscher Versuch,
  Runtimegeneration/Handle-Konflikt; keine unerlaubte Callback- oder Scope-Änderung.
- Power: aus, bereits an, suspendiert, verschwunden, andere UUID unter gleichem
  Namen; Starttimeout mit tatsächlich erfolgter Einschaltung.
- Abbruch während Create, einmaliger Startwartezeit, Staffelwartezeit und
  laufendem Start; kein weiterer Start, bestehende VMs nicht ausschalten.
- Neustart, DB-/SSH-Ausfall und Ownership-Verlust zwischen Start und Beleg;
  keine doppelte Ausführung, keine Erzeugung als Start-Recovery.
- Retry nach MAC-Erfolg plus Startfehler; nur belegbar ausstehende Starts,
  Intervall erhalten, erfolgreicher MAC-Import nicht als Startbeleg verwenden.
- 40/41 VMs und 10/11 Interfaces; gemeinsames Create-Budget sowie lange
  Startstaffelung; Legacy-Gruppen neben neuen Aufträgen.
- DE/EN, Accessibility der Feldhinweise, Moduswechsel und Sticky-Form-State;
  Preview/Log/Reihenfolge identisch und Uhrzeiten korrekt als geplant/real getrennt.
- Bestehende Tests aus `DeployScheduleParseTest`, `DeployScheduleEnqueueTest`,
  `DeployModeGateTest`, `DeployCreateTerminalRetryTest`,
  `AnsiblePauseBudgetContractTest`, `NetworkMacContractTest` und den
  Deploy-Form-State-/Accessibility-Browserspezifikationen erweitern.

## Weitere Probleme und priorisierte QoL-Verbesserungen

Die folgenden Punkte sind Vorschläge. Sie ändern den laufenden Auftrag nicht.

| Priorität | Verbesserung | Nutzen und Vertragsgrenze |
| --- | --- | --- |
| Mit der Semantikänderung | Feld für Full/Start als „Abstand zwischen VM-Starts“ beschriften; „Wartezeit vor dem ersten Start“ getrennt erklären. | Die zwei Wartearten sind direkt unterscheidbar. Modusspezifische Hilfe aus derselben Policy ableiten, einschließlich DE/EN und Accessibility. |
| Mit der Semantikänderung | In der Vorschau „15 VMs vorbereiten, danach im Abstand von 2 Minuten starten“ und „mindestens 28 Minuten zwischen erstem und letztem neuen Start“ zeigen. | Der zweite Wert ist reine Staffelspanne bei 15 tatsächlich neu einzuschaltenden VMs, keine geschätzte Gesamtdauer. Bereits laufende/übersprungene VMs können die Anzahl ändern. |
| Mit der Semantikänderung | Fortschritt in Vorbereitung, MAC-Export, einmalige Startwartezeit und gestaffelte Starts aufteilen. | Die bestehende Create-Fortschrittskarte in `lib/deploy_create_progress.php` weiterverwenden. Startfortschritt aus dauerhaften Ergebnissen ableiten, nicht aus Logtext oder einem zweiten Browser-Zähler. |
| Mit der Semantikänderung | „7/15 eingeschaltet; nächste VM: …; frühestens in …“ mit Aktualitätsanzeige. | Countdown aus Serverzeit und gespeichertem nächsten zulässigen Start ableiten. Bei Verbindungsverlust „Stand veraltet“ zeigen; der Browser löst niemals selbst einen Start aus. Keine erfundene Prozentanzeige der Eager-Thick-Nullung. |
| Mit der Semantikänderung | Teilabbruch erklärt, welche VMs bereits laufen und welche ausgeschaltet bleiben; Wiederaufnahme nennt ihre konkrete Auswahl. | Aktion nur aus dem serverseitig geprüften Retry-/Fortsetzungsplan anbieten. Keine komplette Full-Wiederholung als vermeintliche Reparatur einzelner fehlender Starts. |
| Mit der Semantikänderung | Auftragsdetails zeigen gespeicherten Abstand, Reihenfolge und gewählte Semantik. | `lib/deploy_display.php` ergänzt die bisherige Modus-/Anzahl-/Verbose-Zusammenfassung. Alte Gruppen werden sichtbar als alte Auftragsstaffelung behandelt. |
| Danach | Hinweise auf Laufzeitgrenzen und fehlende/alte Storage-Beobachtungen mit direkten Links zur zuständigen Seite. | Bestehende Speicherbedarfsanzeige ist eine Momentaufnahme und keine Reservierung. Eager-Thick-Dauer nicht aus Größe allein prognostizieren; Budget und Messunsicherheit transparent darstellen. |
| Danach, eigener Vertrag | „Weitere Starts pausieren“ und „Fortsetzen“ zwischen VM-Starts. | Der vorhandene globale Claim-Stopp beendet keine Arbeit im laufenden Auftrag. Eine echte Startpause braucht dauerhaften Zustand, RBAC, CSRF, Audit, sichere Ownership und erneute Prüfung beim Fortsetzen. Pause ist nicht Abbruch und nicht „Worker freigeben“. |
| Separates späteres Feature | Maximale gleichzeitige Installationen statt nur Zeitabstand. | Zwei Minuten Startabstand begrenzen keine Parallelität. Bei 30 Minuten Installationsdauer können nach 28 Minuten alle 15 Installationen gleichzeitig laufen. Eine echte Grenze benötigt verlässliche, aktuelle Fertig-/Fehlerbelege und eine Ausfallpolicy; sie darf nicht aus Einschaltstatus oder veraltetem MECM-Badge geraten werden. |

### Weitere betriebliche Randfälle

- **MECM-Verzögerung:** Die einmalige Startwartezeit gibt der Umgebung Zeit,
  bestätigt aber weder Collection-Mitgliedschaft noch eine angebotene Task
  Sequence. Keine automatische „MECM bereit“-Aussage aus Ablauf eines Timers.
- **Ressourcenlast:** Bei Vorbereitung aller VMs wird sämtlicher benötigter
  Plattenplatz vor dem abschließenden Rollout beansprucht. Die Nullung bleibt
  sequenziell, kann aber weiterhin Storage belasten. Startstaffelung ist weder
  Storage-Reservierung noch RAM-/CPU-Zulassungskontrolle.
- **Fehler in später VM:** Scheitert Create bei VM 15, können VM 1 bis 14 bereits
  existieren, ohne abschließend gestartet worden zu sein. Diesen Zustand als
  teilweise erledigte Vorbereitung sichtbar machen, nicht als „nichts passiert“.
- **Externe Änderungen:** Während langer Vorbereitung oder Wartephasen können
  Administratoren ESXi-VMs extern starten, löschen oder ändern. Vor jedem Start
  aktuellen Zustand und genaue Identität prüfen; einen fremden Start nicht als
  eigenen Erfolg mit erfundenem Startzeitpunkt verbuchen.
- **Reihenfolge:** Namen wie VM1, VM10, VM2 haben nicht automatisch eine
  natürliche numerische Reihenfolge. Die Vorschau muss die tatsächliche
  kanonische Reihenfolge zeigen. Manuelle Priorisierung wäre ein eigenes Feld
  mit demselben dauerhaften Owner, keine ad-hoc Browser-Sortierung.
- **Termin:** Der geplante Zeitpunkt startet weiterhin die Vorbereitung. Wer
  „alle Installationen ab 10:00“ verlangt, braucht einen gesonderten Vertrag;
  Eager-Thick-Dauer darf nicht rückwärts als sichere Vorlaufzeit geraten werden.
- **Autostart:** Ein Host-Neustart kann seine eigene ESXi-Autostartpolicy
  auslösen. Das Formularintervall steuert diese Policy nicht. Bereits laufende
  VMs bei Fortsetzung erkennen und den verbleibenden Ablauf neu prüfen.

## Prüfnachweise

Code- und Vertragsprüfung wie oben. Der kanonische Runner wurde mit diesen
ausgewählten Fast-Gates ausgeführt:

```powershell
powershell -NoProfile -File scripts/check.ps1 -Lane Fast -Gate phpunit-unit,lang-parity,enum-sync,php-version-sync,bounds-sync,doc-hygiene,doc-semantics,csp-patterns -NoNetwork -KeepArtifacts -Json qa-artifacts/qa-full-start-stagger-audit-verified.json
```

Ergebnis: 8/8 Gates bestanden; Unit/Static: 1721 Tests, 40221 Assertions,
keine Skips. Fortschritt bis `[8/8] pass csp-patterns` protokolliert in
`qa-artifacts/full-start-stagger-audit-verified-progress.log`.
Der erste Sandbox-Lauf hatte keinen nutzbaren Docker-/PHP-Zugriff und
Git-Bash-Prozessstartfehler; er ist kein fachlicher Drift-Nachweis. Der
anschließende Lauf mit Zugriff auf die Prüfumgebung ist der gültige Nachweis.

Die Gates bestätigen die bestehenden Verträge. Sie widerlegen weder die
oben beschriebenen semantischen Lücken noch beweisen sie das vorgeschlagene
neue Verhalten. Der Textfehler „three timing values“ liegt beispielsweise
außerhalb der maschinellen Doku-Regeln.

Reale ESXi-Laufzeiten, Power-Verhalten und MECM-Bereitschaft wurden nicht
gemessen; keine Integrations- oder Browserabnahme des Zielverhaltens.
Das Bereitstellungsverhalten wurde in diesem Audit nicht geändert.

## Umsetzungsplan vom 14. September 2026: eigenständiges VM-Starten

Status: ausführlicher Plan, keine Implementierung. Dieser Abschnitt ist das
Arbeitsregister für den hier konkretisierten Startauftrag. Die früheren
Full-Vorschläge bleiben historisch lesbar und werden dadurch nicht umgesetzt.
Die dortigen historischen QA-Ergebnisse gelten nicht als Nachweis dieses Plans
oder des zukünftigen Zielverhaltens.

### Auftrag, Grenzen und Entscheidungen

Der Nutzer hat bestätigt: „VMs starten“ soll vorhandene VMs einschalten, sonst
nichts. Bereits eingeschaltete und suspendierte VMs sollen unverändert bleiben,
übersprungen, protokolliert und sichtbar markiert werden. Gewünschte Staffelung:
zwei Minuten zwischen neuen Einschaltungen. Bislang war ausschließlich Prüfung
autorisiert; der aktuelle Auftrag ergänzt die Erstellung dieses Plans.

| ID | Verbindliches Ziel |
| --- | --- |
| START-01 | Der Modus Start führt weder Create, Powercycle, MAC-Export, MECM-Warten noch ESXi-Autostart-Konfiguration aus. |
| START-02 | Eine ausgeschaltete, eindeutig gebundene VM wird eingeschaltet. Auf Gastbetriebssystem, VMware Tools, PXE oder Installation wird nicht gewartet. |
| START-03 | Bereits laufende VMs erhalten den sichtbaren Befund „Übersprungen – VM läuft bereits“. |
| START-04 | Suspendierte VMs erhalten „Übersprungen – VM ist angehalten“. Kein Resume, Force, Reset oder Ausschalten. |
| START-05 | Übersprungene VMs erzeugen keinen neuen Startslot und keinen erfundenen Einschaltzeitpunkt. |
| START-06 | Pro ausgewählter VM bleiben Ergebnis, Grund und Beobachtung nachvollziehbar; Logs und Portal widersprechen sich nicht. |
| START-07 | Startauftrag und Einschaltfehler dürfen keine unbelegten Installations- oder MECM-Zustände schreiben. |
| START-08 | Identität, Ownership, Abbruch und ungeklärte Remote-Ausführung behalten ihre Schutzwirkung. |
| START-09 | Zeitabstand, Reihenfolge, Eingabeprüfung und Ergebnisinterpretation besitzen gemeinsame SSoT-Owner. |

Nicht enthalten: Implementierung der gesamten Full-Neugestaltung, Änderung der
Powercycle-Semantik, automatische Identitätsübernahme, Reparatur von MECM,
Installationsparallelitätsbegrenzung, neue manuelle VM-Priorisierung oder
Produktivinstallation. Die Nachbarpläne
[Powercycle](2026-09-14-powercycle-sequential-plan.md) und
[Identitätsersatz](2026-09-14-vm-identity-replacement-plan.md) haben eigene
Verantwortung. Gemeinsame Dateien vor Umsetzung auf neue Änderungen prüfen.

### Belastbare Ausgangslage und offene Diagnose

- Gruppe `49071c909f61` enthält 15 Einzelaufträge. Auftrag 587 scheiterte nach
  300 Sekunden Pause mit einem ESXi-Zustandsfehler „Powered on“.
- Auftrag 589, Position 3, war für 15:38:29 Berliner Zeit eingeplant und wurde
  erst 15:45:42 übernommen. Er wartete ebenfalls 300 Sekunden. Der spätere
  Datenbankauszug bestätigt seinen erfolgreichen Abschluss um 15:51:14.
- Im gelieferten Gruppenstatus waren 587 fehlgeschlagen, 588/589 erfolgreich,
  590 laufend und 591–601 wartend. Das ist eine historische Momentaufnahme.
- Die NDJSON-Datei 563 beschreibt eine frühere Full-Erstellung und ist kein
  Nachweis der Ausführung dieser Startgruppe.
- Das Playbook übernimmt die konfigurierte Startwartezeit ohne Unterscheidung
  zwischen Full und Start. Ein Worker verarbeitet einen Auftrag bis zur
  Rückkehr, bevor er den nächsten übernimmt.
- Der öffentliche Ansible-Modulcode behandelt einen bereits erreichten
  Energiezustand grundsätzlich ohne erneuten PowerOn-Aufruf. Deshalb ist
  „läuft bereits“ allein keine abschließende Ursachenanalyse für Fehler 587.
  Installierte Collection-Version und ESXi-Task-/Ereignisfolge fehlen für den
  Nachweis eines konkurrierenden Zustandswechsels oder Modulfehlers.
- Der gemeinsame Fehlerpfad kann im aktuellen Arbeitsbaum Lifecycle und
  MECM-Sync auf failed setzen. Ein tatsächlicher historischer Datenbankwechsel
  bei VM-05111 wurde nicht gelesen und wird nicht behauptet.

Quellen der Diagnose: vom Nutzer bereitgestellte Jobprotokolle 587/589 und
Gruppenabfrage, aktueller Start-/Queue-/Worker-Code sowie
[Ansible Powerstate-Implementierung](https://raw.githubusercontent.com/ansible-collections/community.vmware/main/plugins/module_utils/vmware.py),
[Ansible pause](https://docs.ansible.com/projects/ansible/latest/collections/ansible/builtin/pause_module.html)
und [Microsoft Collection-Auswertung](https://learn.microsoft.com/en-us/intune/configmgr/core/clients/manage/collections/collection-evaluation).
Online-main ist keine Bestätigung der installierten Modulversion. MECM kann
Zeit zur Collection-Auswertung benötigen; daraus folgt keine Pause für Start.

### Zielablauf und Ergebnisregeln

1. Auswahl und Reihenfolge beim Einreihen materialisieren; explizit leere
   Auswahl nicht versehentlich zur gesamten Mission erweitern. Später neu
   hinzugefügte VMs gehören nicht zum Auftrag. Termin in UTC speichern.
2. Modus, Scope, Berechtigung und bestehende Remote-/Identitätsfences prüfen.
   Nur Start-relevante Voraussetzungen anwenden; fehlende MECM-Bereitschaft
   darf keinen zusätzlichen Startblocker erzeugen.
3. Für die nächste VM Live-Identität und Energiezustand prüfen. Keine automatische
   Neuanlage, neue Bindung oder Auswahl eines namensgleichen Ersatzes.
4. Laufende oder suspendierte VM mit dem jeweiligen Skip-Grund abschließen.
   Auch wenn alle VMs übersprungen werden, sind sämtliche Einheiten sichtbar.
5. Bei ausgeschalteter VM die verbleibende Intervallfrist bedienen. Während
   jeder Wartephase Heartbeat, Abbruch und Ownership prüfen. Danach Identität
   und Zustand erneut prüfen, bevor ein PowerOn gesendet wird.
6. Einschaltung ausführen und Ergebnis anhand des gebundenen VMware-Tasks bzw.
   einer frischen Live-Beobachtung bewerten. Kein Warten auf Gastbereitschaft.
7. Ergebnis dauerhaft speichern, Fortschritt aus diesem Ergebnis ableiten,
   nächsten Kandidaten bearbeiten. Keine zusätzliche Pause nach der letzten VM.

| Beobachtung | Ergebnis und Aktion |
| --- | --- |
| Identität stimmt, poweredOn vor eigener Mutation | Übersprungen: läuft bereits; keine eigene Einschaltung behaupten. |
| Identität stimmt, suspended | Übersprungen: angehalten; bleibt suspendiert. |
| Identität stimmt, poweredOff | Frühestens nach Intervallfreigabe einschalten. |
| Fehlend, widersprüchliche Identität oder nicht lesbarer Zustand | Kein PowerOn; begründeten Fehler bzw. ungeklärten Befund speichern. |
| Eigene Einschaltung bestätigt | Eingeschaltet; Bestätigungszeit und Befund speichern. |
| Fremder Start zwischen Prüfung und Aufruf | Frisch abgleichen; läuft nachweislich ohne bestätigten eigenen Start: als extern erreicht kennzeichnen, keinen eigenen Zeitpunkt erfinden. |
| API/SSH-Timeout nach möglicher Mutation | Ungeklärt; keine blinde Wiederholung und kein einfaches Ignorieren des Fehlers. |
| Abbruch vor Mutation | Nicht gestartet, Abbruchgrund; vorhandenen Energiezustand unverändert lassen. |

Ein bloßer Textvergleich auf „Powered on“ darf niemals jede Fehlermeldung in
Erfolg umwandeln. Erfolgreiches Überspringen bedeutet keine Änderung und keine
Aussage über den Gesundheitszustand des Betriebssystems.

### Staffelung, Restart und vorgeschlagene Fehlerpolitik

Vorgeschlagenes präzises Intervall: frühester nächster eigener PowerOn-Aufruf
liegt mindestens N Minuten nach der bestätigten vorherigen eigenen Einschaltung.
Langsame Aufrufe dürfen den Abstand verlängern. Bereits laufende/suspendierte
VMs setzen diese Uhr nicht zurück; nach übersprungenen Kandidaten gilt trotzdem
eine noch offene Frist seit der letzten eigenen Einschaltung. Keine Nachholwelle
nach Stau, Neustart oder Datenbankausfall. Leer/0 bedeutet keine zusätzliche
Zwischenpause, nicht garantierte Gleichzeitigkeit aller Starts.

Ein eindeutiger, nur diese VM betreffender Fehler soll vorgeschlagen die anderen
unabhängigen Kandidaten nicht verhindern. Ein globaler Host-/Authentifizierungs-
oder Ownershipfehler beendet weitere Mutationen. Bei ungeklärter möglicher
Einschaltung vorerst keine weiteren Starts, bis der Zustand sicher abgeglichen
ist. So bleibt der Abstand belegbar. Diese Fehlerpolitik ist eine
Planempfehlung, keine bereits bestätigte zusätzliche Nutzerentscheidung.

Recovery muss aktive Remote-Tasks zuerst abgleichen. Ein später beobachtetes
poweredOff beweist nicht, dass vorher nie eingeschaltet wurde. Fehlt die
historische Zeit, konservativ eine neue Intervallfrist ab Abgleich verwenden
oder ungeklärt bleiben; niemals einen exakten historischen Start erfinden.
Eine nach erfolgreichem Start extern ausgeschaltete VM wird innerhalb derselben
abgeschlossenen Einheit nicht automatisch wieder eingeschaltet.

Abbruch bedeutet: keine weitere Mutation nach bestätigtem Abbruch; ein bereits
abgesendeter VMware-Task kann noch fertig werden und muss als solcher sichtbar
bleiben. Bereits eingeschaltete VMs werden nicht zurückgerollt. Globale
Claim-Pause, Gruppenabbruch und Abbruch des aktiven Auftrags bleiben verschiedene
Aktionen und dürfen im Portal nicht gleich bezeichnet werden.

### Architektur und SSoT-Zuordnung

Zielvorschlag für neue Startaufträge: ein Auftrag mit dauerhaften Starteinheiten
pro VM und explizit gespeicherter Startpolicy. Ein bloßes Verkürzen der Pause
in 15 unverändert terminierten Einzelaufträgen erfüllt START-05/09 nicht.
Die technische Ausführung erfolgt in begrenzten Einheiten, damit zwischen VMs
Abbruch/Ownership/Recovery geprüft werden können. Bestehende Remote-Ausführungs-
owner wiederverwenden, keinen unabhängigen zweiten Scheduler bauen.

| Gegenstand | Bestehender Owner und geplante Änderung |
| --- | --- |
| Modusfähigkeiten | `lib/ansible_command_modes.php`: Startwartebedarf von bloßer Verwendung des Startplaybooks unterscheiden; gemeinsame Fähigkeit für Formular, Export und Worker. |
| Defaults/Grenzen | `lib/defaults.php`, `lib/deploy_constants.php`: Sekunden/Minuten und zulässige Werte zentral halten. Keine zweite Zahlensammlung in JS oder Texten. |
| Eingaben/Payload | `lib/repo/deploy_job_input.php`, `lib/ansible_command_modes.php`: gemeinsame strikte Normalisierung; versionierte Startpolicy, Auswahl und Intervall persistieren. Ungültigen Modus nicht als Full ausführen. |
| Queue/Preview | `lib/repo/deploy_job_queue.php`, `lib/deploy_actions.php`, bestehende Preview-/Blockerowner: gleiche Auswahl, Reihenfolge, Policy und Grenzen. |
| Ausführung | `lib/deploy_worker_mission.php`, Startplaybook und bestehende Remote-Owner: begrenzte VM-Einheiten, Live-Abgleich, kein Start-Puffer. Neue Ansible-Hilfsdateien flach in der bestehenden Artefaktregistry registrieren. |
| Persistenz | Nach Routing über `docs/ai/reference/database.md` einen dedizierten Startbefund in der vorhandenen Repository-/Migrationsstruktur einführen. Keine Create-Ergebnisse oder MECM-Sync-Felder dafür umdeuten. |
| Abschluss/Retry | `lib/deploy_worker_finish.php`, `lib/repo/deploy_job_retry.php`: gemeinsame Start-Ergebnisauswertung; getrennte abgeschlossene, übersprungene, fehlende und ungeklärte Einheiten. |
| VM-Status | `lib/deploy_worker_vm_state.php`: reiner Start schreibt keine Installations-/MECM-Fehler. Historische Fehlmarkierungen nicht automatisch massenhaft zurücksetzen. |
| Portal/Sprachen | `lib/deploy_queue_panel.php`, `lib/deploy_display.php`, Log-/Fortschrittsowner und DE/EN: aus gespeicherten Befunden rendern; Browser löst keine Starts aus. |
| Verträge | ADR-0022 nachvollziehbar ergänzen; Deploy-/Ansible-/Portalreferenzen, DEPLOYMENT und Hilfe angleichen. Dieser Plan ist Arbeitsregister, kein zweiter dauerhafter Produktvertrag. |

Minimaler fachlicher Starteinheitsdatensatz: Job/Position/Portal-VM-ID,
gebundene Zielidentität und ESXi-Scope, Bearbeitungsstand, typisiertes Ergebnis
und Grund, Beobachtungszeit, eigene bestätigte Einschaltung falls belegt,
zugehöriger Remote-Ausführungsbeleg. Jobpolicy enthält Semantikversion,
Intervall und geordnete Auswahl. Exakte Feldnamen, Constraints und Writer werden
im Persistenzpaket gegen bestehende Tabellen festgelegt. Doppelte Ergebnisse,
fremde Generationen und verspätete Worker dürfen keine Einheit überschreiben.

Aggregationsvorschlag: vollständig abgearbeitet ohne Fehler/Unklarheit = Erfolg,
auch bei reinen Skips; Mischung aus erledigten und eindeutig fehlgeschlagenen
Einheiten = Teilerfolg; nur Fehler = fehlgeschlagen. Unklarheit bleibt ausdrücklich
sichtbar und sperrt automatische Wiederholung nach den bestehenden Remote-
Verträgen. Bestehende Jobstatus nicht ohne Not um ein konkurrierendes Enum erweitern.

### Umsetzungspakete und Reihenfolge

| Paket | Arbeit und Abschlusskriterium | Abhängigkeit |
| --- | --- | --- |
| P1 Vertrag/Bestandsabgleich | Aktuellen Arbeitsbaum und Nachbarpläne abgleichen. Startpolicy, Aggregation, Abbruch, Kompatibilität und Fehlerpolitik festhalten. Installierte Collection für Diagnose 587 bestimmen, soweit verfügbar; ungeklärte Ursache separat halten. | Keine |
| P2 Persistenz/Normalisierung | Migration und genau einen Startbefund-Writer, Policyversion, strikte Eingaben und Recovery-Lesemodell umsetzen. Constraints gegen Duplikate/fremde Ownership beweisen. | P1 |
| P3 Reines Einschalten | Keine MECM-Pause im Startmodus; Live-Identität, poweredOff-Start und beide Skip-Arten. Strukturierte Remote-Ergebnisse statt Logparsing. Full-Warteverhalten nicht beiläufig entfernen. | P2 |
| P4 Staffelung/Abbruch/Recovery | Neue Startaufträge als geordnete Einheiten ausführen; Fristen dauerhaft, keine Nachholstarts; unterbrechbare Wartephasen und unbekannte Outcomes testen. | P3 |
| P5 Status/Retry | Start von Lifecycle/MECM-Schreibpfaden entkoppeln. Retry-Auswahl und Umfang aus Startbefunden ableiten, historische Semantik berücksichtigen. | P2–P4 |
| P6 Portal/SSoT | Startwartefeld für Start entfernen/deaktivieren, richtige Vorschau, pro-VM-Marker und Ergebniszählung, klare Abbruch-/Retry-Hinweise; DE/EN und Referenzen angleichen. | P3–P5 |
| P7 Gemeinsame Abnahme | Gezielte unabhängige Vertragsprüfung, kanonische finale QA und isolierte synthetische Operator-Flows; offene externe Lab-Nachweise getrennt dokumentieren. | P1–P6 |

Je Datei genau ein Implementierungsowner; QA-Stack exklusiv durch einen Owner.
Für einen später beauftragten Terra/Sol/Astra-Ablauf gelten die Modellrollen aus
`docs/ai/workflow.md`. Keine Subagenten nur zum Warten oder für doppelte
Bestandsaufnahmen. Kritische unabhängige Prüfung vor finaler QA: Kann ein alter
Worker nach Abbruch/Neustart mutieren oder einen fremden/unklaren Start als eigenen
Erfolg verbuchen? Keine pauschale Astra-Zuweisung für Routineprüfungen.

### Prüffälle und Abnahmekriterien

| Fall | Erwarteter Nachweis |
| --- | --- |
| Eine ausgeschaltete VM | Genau eine Einschaltung, keine MECM-Pause, kein Gast-/Tools-Warten. |
| Alle laufen / alle suspendiert / gemischt | Passende Skip-Marker, null PowerOn für Skips, keine künstlichen Intervallpausen ohne vorherigen eigenen Start. |
| 15 ausgeschaltete VMs, zwei Minuten | Reihenfolge stimmt; jeder folgende eigene Aufruf erst nach Frist; keine Pause nach letzter VM. Mindestens 28 Minuten Staffelspanne, keine Gesamtlaufzeitgarantie. |
| Skips zwischen zwei Starts | Offene Frist bleibt erhalten; Skips verlängern sie nicht um neue Slots. |
| Langsamer Start / bereits überfällige Aufträge | Kein Nachholen mehrerer Starts ohne Mindestabstand. |
| Externer Start/Suspend während Wartephase | Erneute Prüfung, korrekter Skip, keine Wiederaufnahme suspendierter VM. |
| Löschen/Ersetzen/Umbenennen/Duplikatname | Nur gebundene Identität; kein Namensfallback, kein Create, begründeter Befund. |
| Einzelner ESXi-Fehler, globaler Authfehler | Vorgeschlagene lokale/global unterscheidende Fehlerpolitik; keine falsche Erfolgssumme. |
| Timeout vor/nach möglichem PowerOn | Unklarheit und Remote-Beleg bleiben erhalten; kein automatischer Doppelstart. |
| Abbruch beim Warten / nach Versand | Kein weiterer Start nach bestätigtem Abbruch; bereits laufender Task wird korrekt abgeglichen. |
| Worker-/DB-Ausfall, alte Generation | Frist/Einheiten bleiben erhalten; fremde/stale Writer werden abgewehrt. |
| Retry nach Teilresultat | Scope aus Befunden; bekannte Skips/Erfolge nicht blind erneut mutieren; neue Live-Prüfung für berechtigte Kandidaten. |
| Startfehler bei installiertem System | MECM-Sync und Installationsstatus bleiben unverändert. |
| Eingaben leer/0/negativ/dezimal/Text/Grenzen | Eine serverseitige strikte Regel; keine stillen Casts zu anderem Verhalten. |
| Alte Gruppen/neue Policy | Alte Aufträge nicht nachträglich als neue Staffelung interpretieren; alle historischen Logs lesbar. |
| DE/EN, veraltete Browseransicht | Gleiche Gründe/Zählung, keine sichere Uhrzeit aus bloßer Planung, Aktualität sichtbar. |

Unit-/Integrationsfälle verwenden kontrollierte Zeit und synthetische Remote-
Antworten, damit Rennen und Ausfälle gezielt geprüft werden. Für reale Power-
Semantik zusätzlich ein autorisiertes synthetisches ESXi-Lab verwenden; fehlendes
Lab ist eine offene Evidenzlücke und kein erfolgreicher Integrationstest.
Vor allem Fehler 587 nicht durch einen nur nachgebauten Mock als gelöst erklären.

QA ausschließlich über `scripts/check.ps1`, Auswahl aus dessen aktueller Registry.
Pro Paket passende Prüfungen wählen; nach zusammengeführtem Stand erforderliche
Fast-/Integration-Abdeckung, vor Auslieferung erforderliche Release-Abdeckung.
Kein zweiter verbindlicher Gatekatalog in diesem Plan. Relevante Themen sind
Payload/DB/Ownership, Ansible-Artefakte und Module, Sprache, Grenzen, Dokumente,
Portalflüsse sowie Recovery. Visuals nur auf der exakten synthetischen
`virtusphere-qa`-Umgebung; Baselineänderungen nur durch den menschlichen Writer.

Bekannte Mehrfacharbeit meldet `[n/total] RUN <Einheit>` und direkt danach das
Ergebnis. Lange Runner erhalten vorher ein live lesbares Log; mindestens einmal
pro Minute den tatsächlichen Zähler prüfen. Neue kanonische Runnerpfade erweitern
den ProgressReporting-Vertragstest. Keine erfundenen Erfolgszahlen.

### Kompatibilität, Rollout und offene Punkte

- Neue Semantik explizit versionieren. Bestehende queued/running Gruppen nicht
  heimlich zusammenführen oder ihre gespeicherten Wartewerte überschreiben.
- Vor Installation aktive Aufträge und Remote-Tasks erfassen; kontrollierten
  Übergang planen. Ein bloßer globaler Claim-Stopp stoppt keinen laufenden Task.
- Alter Code darf neue Payloads nicht als Full oder als alte Gruppen ausführen.
  Rollback mit neuen offenen Jobs braucht vorab definierte Sperr-/Drain-Regeln.
- Eine Schemaerweiterung allein ist kein Beweis für sichere Rückwärtskompatibilität.
  Migration, Restore und gemischte Versionszustände gezielt prüfen.
- Historische MECM-/Lifecycle-Schäden nur mit separatem belegtem Reparaturauftrag
  korrigieren. Kein Rücksetzen aller roten VMs aus dem Startfix heraus.
- Vollständige alte Full-Staffelung bleibt ein separates Vorhaben. Gemeinsame
  Helper dürfen vorbereitet werden, aber nicht nebenbei Full/Powercycle ändern.
- Laufende Betriebsaufträge nicht als Testmaterial verwenden. Commit, Push und
  Installation sind durch die reine Planerstellung nicht autorisiert.

Noch fachlich abzustimmen, bevor davon abhängige Umsetzung beginnt: vorgeschlagene
Fortsetzung bei eindeutig VM-lokalem Fehler; Behandlung einer ungeklärten Einheit
im Gesamtabschluss; gewünschte Betriebsgrenze langer Staffelaufträge. Technisch
zu entscheiden in P1/P2: persistente Einheitenausführung und Worker-Belegung unter
bestehenden Gesamtbudgets. Kein stilles Erhöhen von Zeitlimits. Suspendierte VMs
überspringen und markieren ist dagegen bereits entschieden und nicht erneut zu fragen.

### Arbeitsregister und nächster Schritt

| Paket | Stand | Nachweis / verbleibende Arbeit |
| --- | --- | --- |
| Planung | Geschrieben; beide Dokumentationsgates bestanden | `qa-artifacts/qa-start-plan-docs-2026-09-14-verified.json`; keine Produktabnahme. |
| P1–P7 | Nicht begonnen | Kein Produktcode, keine Migration, keine Produktivaktion aus diesem Plan. |

Bei Umsetzung nach jedem Paket ergänzen: Anforderungs-IDs, tatsächliche Änderungen,
Quellmanifest mit Hashes, Testscope/Umgebung/Ergebnisse, Artefaktpfade, unabhängige
Reviewbefunde, externe Lücken und nächster Schritt. Historische grüne Checks nur
bei unveränderten relevanten Quellen und Umgebung wiederverwenden.

Nächster Schritt nach Umsetzungsauftrag: P1 auf dem dann aktuellen Arbeitsbaum,
einschließlich Abgleich der parallelen Identitäts-/Powercycle-Arbeiten. Für diesen
Planungsauftrag ist damit abgeschlossen. Der erste Prüflauf scheiterte am
Git-Bash-Prozessstart in der Sandbox (vom Runner als zwei fail klassifiziert,
kein fachlicher Dokumentbefund). Die Wiederholung außerhalb der Sandbox bestand
mit `[2/2] pass doc-semantics`, insgesamt 2/2 pass. Keine Produkt-, ESXi- oder
MECM-Abnahme daraus ableiten.
