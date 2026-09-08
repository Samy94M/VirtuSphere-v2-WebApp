# Auditplan: VirtuSphere, PowerShell und die vollständige Integrationskette

Erstellt: 08.09.2026. Auftrag: Fehler, Logikprobleme, fehlende Verträge, Drift, fehlende oder umgangene SSoT sowie konkrete QoL-Verbesserungen aufdecken. Zusätzlich den gesamten Stack auf Performance, Robustheit und geeignetes Self-Healing prüfen sowie Dokumentation und Portalhilfe fachlich mit dem tatsächlichen Verhalten abgleichen.

Dieser Plan ist für die Fortsetzung in einer neuen Session bestimmt. Er beschreibt die Auditmethode und Arbeitspakete. Fortschritt, Befunde, Entscheidungen und Nachweise werden ausschließlich im [Befundregister](2026-09-08-system-chain-audit-register.md) fortgeschrieben. Die Erstellung des Plans ist noch keine Produktprüfung.

## 1. Ziel, Umfang und Arbeitsweise

Geprüft wird die vollständige Kette:

```text
Portal -> Repository/DB -> Queue/Worker -> Ansible/ESXi
                                <- MAC-Rückmeldung über PHP-API
DB -> PHP-Machine-API -> PowerShell auf MECM -> MECM-Geräte/Collections/Pakete
                                      <- PHP-API <- Client-PowerShell
Rückmeldungen -> PHP-API -> DB -> Status, Logs und Handlungen im Portal
```

Die Pfeile sind eine Prüfkarte, keine Behauptung direkter SQL-Zugriffe durch PowerShell. Tatsächliche Kommunikations- und Schreibwege werden in AP02 nachgewiesen. Serveraufgaben, Clientphasen, Installer, Paketvorlage und ausgelieferte gemeinsame Module gehören ausdrücklich zum Umfang.

Die erste Runde liefert belegte Befunde und ausführbare Umsetzungspakete. Sie umfasst Quellprüfung, vorhandene Prüfungen, isolierte Reproduktionen und bei Bedarf gezielte neue Test-Fixtures. Produktkorrekturen werden daraus als nachgelagerte Pakete beschrieben; ein zusätzlicher Umsetzungsauftrag kann sie anschließend freigeben. Das Audit selbst wird ohne wiederholte Bestätigungsfragen innerhalb dieses Umfangs abgearbeitet.

Erwartetes fachliches Verhalten zuerst aus Anforderungen und zuständigen Verträgen ableiten, danach mit Implementierung und Tests vergleichen. Stimmen Code und Test miteinander überein, beweist das allein noch keine korrekte Fachentscheidung. Unklare Anforderungen als konkrete Entscheidungsfrage erfassen.

## 2. Maßgebliche Quellen

Beim Start die aktuellen Fassungen lesen; dieser Plan ersetzt keine Produktregel:

- [AGENTS.md](../../AGENTS.md), [GROK.md](../../GROK.md) und gegebenenfalls enger geltende Agentenanweisungen.
- [QA-Bedienung](../QA.md), [Gate-Semantik](../QUALITY-GATES.md), [Testplan-Index](../TESTPLAN.md) und [kanonischer Runner](../../scripts/check.ps1).
- [ADR-Index](../adr/README.md), insbesondere die aktuellen Entscheidungen zu Machine API, Rückkanal/ACK, PowerShell, Korrelation, Cancellation, MECM-Provenienz, Identität, Remoteausführung, Create, Supervisor und Rolloutnamen.
- [PowerShell-Server](../../Powershell-MECM/README.md), [PowerShell-Clients](../../Powershell-MECM/clients/README.md), [MECM-Betrieb](../operations/mecm-integration.md), [Deploy-Kette](../operations/deploy-chain.md).
- [Lastprofile und Messmethodik](../../tests/load/README.md) samt ausführbaren Profilen unter `tests/load/`; Portalhilfe unter `Docker/WebAPI/lib/help/` und die tatsächlich verwendeten DE/EN-Kataloge.
- Vorhandene Berichte [YAML/Runtime/CI](2026-09-08-yaml-runtime-ci-review-plan.md), [Systemstatus-Plan](2026-09-08-system-status-hardening-and-qol-plan.md) und [Systemstatus-Umsetzung](2026-09-08-system-status-hardening-and-qol-implementation.md).

Alte Berichte sind Hinweise auf bereits untersuchte Fälle. Ihre Abschlussaussagen werden mit Referenzstand und vorhandenem Nachweis abgeglichen; historische Befunde werden nicht ungeprüft erneut als aktuelle Fehler gemeldet. Weitere einschlägige Berichte über das Dateiinventar finden.

## 3. Regeln für belastbare Ergebnisse

### Prüfstand und Isolation

- AP00 erfasst HEAD, Branch, Änderungen einschließlich ungetrackter Quelldateien und laufende Arbeiten. Beim Schreiben dieses Plans war HEAD `2635b271c4fab3d46bbac633ac72af3c76d0e643` auf `main` mit umfangreichen Änderungen; das ist nur der Ausgangshinweis.
- Für jede Reproduktion den tatsächlich geprüften Stand festhalten. Bei unveröffentlichten Änderungen zusätzlich ein Manifest der relevanten Quelldateien mit Hashes erstellen; keine Geheimnisse oder Laufzeitdaten aufnehmen. Ein Worktree von HEAD enthält diese Änderungen nicht automatisch.
- Fremde Änderungen erhalten. Bei Änderungen während einer Prüfung nur betroffene Nachweise als veraltet markieren und erneut prüfen. Einen erforderlichen isolierten Prüfstand gezielt aus Quellcode aufbauen, ohne Entwicklungsdaten oder `.env` zu kopieren.
- Datenbankmutationen, Fehlerstimulation und Browserprüfungen verwenden synthetische Daten im vorgesehenen QA-Stack. Produktive MECM-, ESXi-, AD- und Windows-Mutationen sind kein impliziter Bestandteil dieses Auditauftrags.
- Laufende produktive Server-/Clientskripte nicht zum Zweck des Einlesens dot-sourcen. Insbesondere Installer, Endlosschleifen sowie Hostname-, Netzwerk- und Datenträgeraktionen nur in dafür vorgesehenen Testumgebungen ausführen.
- Screenshotvertrag einschließlich QA-Identität, Workerzustand und Runner-/Fontmetadaten aus AGENTS.md befolgen. Ein Infrastrukturfehler bleibt ein Infrastrukturfehler. Zielbilder werden im Audit nicht neu geschrieben.

### Evidenz und Priorität

- **R:** isoliert reproduziert, mit Befehl/Test, Eingabe, erwartetem und beobachtetem Ergebnis.
- **K:** konkreter Kontrollfluss belegt den Widerspruch; Vorbedingungen und nicht ausgeführte Laufzeitnachweise benennen.
- **H:** Hypothese; noch kein bestätigter Defekt.
- **Q:** QoL-Vorschlag mit beobachtbarem Bedienproblem und Abnahmekriterium.
- **D:** offene fachliche oder technische Entscheidung mit Optionen und Auswirkungen.

Evidenz ist unabhängig vom Bearbeitungsstatus und von der Priorität. P1: Datenverlust, falsche externe Mutation, doppelte Ausführung, Berechtigungsbruch oder blockierter Kernablauf. P2: Zuverlässigkeit, falsche Zustandsanzeige, Recovery- oder Diagnoseproblem. P3: Bedienkomfort und Wartbarkeit. Unmittelbare Gefahren werden sofort gemeldet und im Register vorgezogen.

Fehlende Tools, fehlende Labore und nicht ausgeführte Tests werden als Nachweislücken geführt. Sie sind weder bestandene Prüfungen noch ohne weiteren Beleg Produktfehler. Auch untersuchte Szenarien ohne Befund werden mit ihrem Umfang dokumentiert.

## 4. Arbeitspakete

Die Reihenfolge ist AP00 bis AP13. Nach AP02 können unabhängige Quellprüfungen vorgezogen werden, falls eine Laufzeitumgebung fehlt. AP09 erfasst die Performance-Baseline vor jeder späteren Optimierung. Dokumentationsbehauptungen aus AP11 werden bereits bei der jeweiligen Fachprüfung gesammelt. Abhängige Schlussfolgerungen bleiben offen. Ein Paket endet jeweils mit aktualisiertem Register, Evidenz und einem konkreten nächsten Schritt.

### AP00: Prüfstand, Vorbefunde und Umgebung

1. Maßgebliche Quellen lesen und Prüfstand gemäß Abschnitt 3 festhalten.
2. Vorhandene Audit-IDs samt Umsetzung und offenen Abnahmen gegen aktuellen Code abgleichen; Duplikate verlinken.
3. Tatsächliche Verfügbarkeit von Docker, PHP, Windows PowerShell 5.1, PowerShell 7, Pester, Browser und gepinnten Toolimages prüfen. Installiert, ausführbar und für diesen Test geeignet unterscheiden.
4. QA-Nutzung und bereits laufende QA-Prüfungen ermitteln. Geteilte QA-Ressourcen nicht gleichzeitig durch mehrere Runner verändern.

Abnahme: reproduzierbarer Prüfstand, Liste wiederzuverwendender Nachweise, offene Infrastrukturbedingungen und nächste ausführbare Prüfung stehen im Register.

### AP01: Vorhandene Gates und Aussagekraft

1. Gates über `scripts/check.ps1 -Lane Fast -List` und `-Lane Integration -List` ermitteln; keine zweite Gate-Liste im Audit pflegen.
2. Fast und anschließend Integration gegen den dokumentierten Prüfstand ausführen. Voraussetzung und Seiteneffekte vorher anhand des Runners prüfen. Falls Teile nicht ausführbar sind, unabhängige Gates und Quellprüfungen fortsetzen.
3. Vor langen Läufen live lesbares Log unter `qa-artifacts/system-chain-audit/<lauf-id>/` einrichten, Ausgabe dorthin tee-en und Runner-JSON speichern. Mindestens einmal pro Minute die letzte tatsächlich beobachtete `[n/total]`-Zeile prüfen und berichten. Gate-Exitcode und JSON gemeinsam auswerten; ein erfolgreiches Tee ist kein erfolgreicher Test.
4. Guard-Harness mit positiven, negativen und Zero-Match-Fällen auswerten. Unterscheiden, ob ein Test nur Quelltextformen oder tatsächlich Verhalten prüft.
5. Die PowerShell-Matrix unter Windows PowerShell 5.1 einschließlich Registry-Tests ausdrücklich belegen; Ergebnisse einer anderen Engine ersetzen sie nicht. Vorhandene Pester-/Analyzer-Konfiguration konsumieren.

Abnahme: aktuelles Ergebnis pro ausgeführtem Gate, Infrastrukturfehler und Testlücken; keine Aussage über eine vollständige Lane aus einer Teilmenge. Release-spezifische Prüfungen folgen in AP12, soweit für das Audit erforderlich.

### AP02: Datenflüsse, Fachentscheidungen und SSoT

1. Aktive Einstiegspunkte aus dem Dateisystem und vorhandenen Registries ableiten: Portalaktionen, PHP-Endpunkte, Worker, Playbooks, PowerShell-Aufgaben und Installer. Historische Dateien getrennt einordnen.
2. Pro wichtigem Zustand/Feld eine Zeile im Register anlegen: fachliche Bedeutung, maßgebliche Quelle, alle Schreiber, Leser, transportierte Darstellung, Sperren/Revisionen und Test.
3. Producer und Consumer gemeinsam prüfen: Felder, Datentypen, Null/Leer/fehlend, Unicode, Zeitbezug, Größenlimits, Antwortformen und Fehlerklassen. Zulässige fachliche Unterschiede nicht als Drift behandeln.
4. Nach fehlenden Verantwortlichen, umgangenen Helfern, abweichenden Standardwerten, mehrfachen Entscheidungen und ungesicherten Spiegeln suchen. Die bewusste Spiegelung ausgelieferter Server-/Clientmodule gesondert auf Version und Parität prüfen.
5. Für jede Zentralisierung benennen, welche eine Fachregel mehrfach gepflegt wird, wo ihr Owner liegen sollte und wie alle Verbraucher ihn nutzen. Keine Abstraktion nur wegen ähnlicher Syntax vorschlagen.

Abnahme: nachvollziehbare Fluss- und SSoT-Matrix mit allen gefundenen Schreibwegen; ungeklärte Zuständigkeiten besitzen eine Befund- oder Entscheidungs-ID.

### AP03: Queue, Worker, Ansible und Create

1. Auswahl, Normalisierung, Preview, Queue, Stagger, Claim und unmittelbare Prüfungen vor Remoteausführung gemeinsam verfolgen.
2. Create-Einheiten über Prepare, Launch, Status, Cleanup und DB-Commit betrachten. Tatsächliche externe Wirkung, belegte Identität und gemeldetes Ergebnis auseinanderhalten.
3. Cancel, Retry, Teilerfolg und ungeklärte Ausführung unter konkurrierendem Worker, Wartungsprozess und Portalzugriff prüfen. Ein verschwundener ausgewählter Datensatz darf die Auswahl nicht unbemerkt erweitern.
4. Verlorene Antwort nach externem Erfolg, DB-Ausfall vor/nach Commit, Worker-Neustart, Supervisorwechsel und verspäteten Callback isoliert untersuchen. Die gültigen ADR-Zustandsautomaten sind der Sollvertrag.
5. Übergänge zu Netzwerk-/MAC-Prüfung und MECM mit AP04/AP06 verbinden; bestehende YAML-/Runtimebefunde am aktuellen Stand nachprüfen.

Abnahme: Zustands- und Nebenläufigkeitsmatrix, Ergebnis-/Persistenznachweise sowie klar bezeichnete offene reale ESXi-Fälle.

### AP04: PowerShell auf dem MECM-Server

Scope: `Powershell-MECM/mecm/`, alle darin aktiven Aufgaben sowie Common-, Logging-, Packaging- und MembershipJournal-Module.

1. Geräteübernahme mit `mecm_new-device-sync.ps1` vom `getDeviceList` bis zum Rückschreiben der ResourceID verfolgen. Rolloutname, Revision, Provenienz, Zuordnung und Wiederanlauf nach Erfolg in MECM bei verlorener API-Antwort prüfen.
2. Collectionzuordnungen und MembershipJournal einschließlich Teilfehler, Wiederholung, extern angelegter Mitgliedschaften und sicherer Reconciliation untersuchen. Vorhandene Zuständigkeiten mit ADR-0034 abgleichen.
3. `mecm_Packages-TaskSeq-sync.ps1` und `mecm_autoimporter.ps1` gemeinsam mit Katalog-API, Paketvorlage und erzeugten MECM-Objekten prüfen: unvollständige Inventare, fehlgeschlagene Providerabfragen, leere Ergebnisse und Wiederholbarkeit.
4. `mecm_site-health.ps1`, Runberichte und Heartbeats prüfen: Providerfehler gegenüber bestätigter MECM-Störung, fehlender Abschluss, doppelte Meldung und Neustart zwischen Beginn und Ende.
5. HTTP- und PowerShell-Fehlersemantik erfassen: Transportfehler, gültiges JSON mit fachlichem Fehler, ungültige Antwort, Konflikt, Autorisierung und vorübergehender Serverfehler. Retries dürfen nicht blind externe Mutationen wiederholen.
6. Mehrfachstart von Tasks, Laufzeit über dem Intervall, unbegrenzte Wartepfade, nicht terminierende PowerShell-Fehler, Exitcodes, Konfiguration und Ausführung als SYSTEM betrachten.

Abnahme: Jede aktive Serveraufgabe ist mit ihrem API-/DB-Gegenstück, Fehlerpfad und Nachweis erfasst. Pester-Mocks gelten nicht als realer Nachweis des MECM-Providers.

### AP05: PowerShell-Clients, Installer und ausgelieferte Pakete

Scope: `Powershell-MECM/clients/`, beide `install-VirtuSphere-*.ps1`, `Package_Vorlage/` und serverseitige Client-Paketerzeugung.

1. `client_getinfo`, `client_hostname`, `client_staticip` und `Set-VMDisksOnline` gemäß tatsächlicher MECM-Abhängigkeits- und Rebootkette prüfen. Pro Phase Registryzustand, Detection, Erfolgskriterium, Exitcode und Wiederholung erfassen.
2. Client-ready-ACK an seinem tatsächlichen Aufrufpunkt prüfen: bekannte MAC, aktuelle Rolloutrevision, verlorene Antwort, Wiederholung und nachgelagerte Phasen. Den ACK nicht ohne Vertragsbeleg mit dem Erfolg sämtlicher Hostname-/Netzwerk-/Datenträgeraktionen gleichsetzen.
3. Adressfindung, DNS/IP-Kandidaten, Schema, TLS-Vertrauen, Registryansicht und fehlende/teilweise Konfiguration prüfen. Unicode-/JSON-Transport unter Windows PowerShell 5.1 und SYSTEM mit abdecken.
4. Mutierende Clientphasen nur mit sicheren Fixtures bzw. in einer Wegwerf-Windows-VM prüfen: mehrere NICs, unveränderte Konfiguration, Neustart und stabile Datenträgeridentität. Den Entwicklungsrechner nicht als Clientziel verwenden.
5. Erstinstallation, erneute Installation, Versionswechsel und Abbruch während Staging/Übernahme verfolgen. Paketmanifest, Hashes, Modulversionen, Pfade/UNC, ACLs, Taskdefinitionen und von MECM ausgelieferte Dateimengen abgleichen.
6. Logging getrennt für Server und Client nachweisen: gleiche versionierte Semantik, Ausfall/fehlende Berechtigung des Sinks, Rotation/Throttling, Geheimnisredaktion und rein diagnostische Korrelation.

Abnahme: Phase-/Installer-Matrix einschließlich Abbruch und Wiederanlauf. SYSTEM-/MECM-Labfälle besitzen konkrete Schritte und erwartete Ergebnisse, solange sie nicht ausführbar sind.

### AP06: PHP-Machine-API, Datenbank und Portal-Schreibwege

1. Alle aktuellen Machine-Endpunkte aus AGENTS.md mit ihren PowerShell-/Ansible-Aufrufern durchgehen. HTTP-Methode, Authentisierung, Eingabegrenzen, Wireantworten, Idempotenz und atomare Schreibmenge prüfen.
2. `updated`, `mecm_id`, Rolloutrevision, eingefrorenen Hostnamen, Identität, MAC und Lebenszyklus über Portaländerung, Reset, MECM-Übernahme, Report und ACK verfolgen. Telemetrie und maßgebliche Zustandsänderung auseinanderhalten.
3. Alte/abweichende Rückmeldungen, identische Wiederholung, parallelen Reset, gelöschte VM, fremde Mission sowie Case-/Whitespace-/Unicode-Grenzen prüfen. Ablehnung muss die zugesicherten Daten unverändert lassen.
4. Transaktionsgrenzen, Lockreihenfolge, CAS, Rollback und nicht atomar koppelfähige externe Schritte prüfen. Für Konkurrenzfälle eine konkrete Reihenfolge zweier Ausführungen dokumentieren.
5. Portal-Schreibwege einschließen: VM-/Missionsänderung, Klonen, Import/Export, Netzwerk/VLAN, Katalog, Benutzer/Rollen, Einstellungen und Credentials. RBAC, CSRF, Objektzugriff und optimistische Konflikte serverseitig prüfen.

Abnahme: Sender-/Empfänger-Vertrag mit erwarteten DB-Deltas und unveränderlichen Feldern je wichtigem Erfolgs-, Fehler- und Konfliktfall. Fehlende direkte SQL-Schreiber ebenso ausdrücklich vermerken wie gefundene.

### AP07: Status, Logs, Evidenz und Recovery

1. Dashboard, Deployansicht, Systemstatus, Jobdetails und Healthendpoint mit ihren maßgeblichen Snapshots abgleichen.
2. Aktuellen Nachweis, historische Evidenz, fehlenden Nachweis, bestätigte Leere und Fehler unterscheiden; gleiche Auswertungszeit und Grenzwerte der Owner prüfen.
3. Handlungsempfehlungen bis zum tatsächlich existierenden, berechtigten Ziel verfolgen. Jede Recovery muss die behauptete Wirkung und verbleibende Sperren korrekt beschreiben.
4. Audit-/Logproduzenten, Korrelation, Retention, Polling, Sessionlocks und Cursor prüfen. Diagnosefehler dürfen weder Erfolg vortäuschen noch Domainzustand verändern.

Abnahme: Anzeige und nächste Handlung sind für repräsentative Fehler- und Recoveryzustände mit ihrer Evidenz belegbar; alte Systemstatusbefunde sind abgeglichen.

### AP08: Portal-QoL, Bedienung und größere Bestände

1. Kernabläufe aus Operatorsicht durchspielen: Vorbereitung, Queue, Blocker beheben, Fortschritt, Fehlerdiagnose, Wiederaufnahme und MECM-Status verstehen.
2. Eingaben, Auswahl, Filter und Sortierung bei Validierungsfehlern, Navigation, Reload, Sessionablauf und mehreren Tabs prüfen. Rechtewechsel, No-JavaScript-Fälle und veraltete Browserantworten einschließen.
3. Leere, typische und große synthetische Bestände prüfen: Listenbegrenzung, vollständige Zähler, stabile Sortierung, Queryzahl und Antwortgröße. Vorhandene Lastprofile und Grenzen konsumieren.
4. DE/EN, Tastatur, Fokus, Feldfehler und umgebrochene Layouts prüfen; visuelle Nachweise ausschließlich über den vorgeschriebenen Visualpfad.
5. Jeder QoL-Vorschlag benennt Benutzeraufgabe, konkrete Reibung, vorgeschlagene Änderung, Nutzen, Aufwand/Risiko und messbare Abnahme. Reine Geschmacksänderungen niedriger priorisieren.

Abnahme: priorisierte QoL-Liste mit reproduzierbarem Bedienproblem; technische Details erscheinen im Portal nur, wenn sie eine Benutzerentscheidung unterstützen.

### AP09: Performance und Ressourcen des gesamten Stacks

1. Repräsentative synthetische Profile definieren: kleine/typische/große Bestände, mehrere unabhängige Operatorsitzungen, mehrere Tabs derselben Sitzung, Monitoring, Logpolling und Schreiblast. Vorhandene Lastprofile/Grenzen konsumieren und verwendete Datenmengen, Parallelität sowie Hardware-/Containergrenzen im Laufartefakt erfassen. Fehlende fachliche Zielwerte als Entscheidung ausweisen; alte Messwerte nicht als aktuelle Baseline übernehmen.
2. Nach Warm-up wiederholte vergleichbare Messungen aufnehmen; Kaltstart separat messen. Latenzen p50/p95/p99, Fehlerrate, Durchsatz, ausgelassene Lastiterationen, CPU, Arbeitsspeicher und I/O erfassen. Nachweisen, dass authentifizierte Seiten und die beabsichtigten Aktionen gemessen werden. Mindestdauer/Umfang so wählen, dass Perzentile interpretierbar sind, und Schwankungen berichten.
3. Portal/PHP/DB: Queryzahl pro Request, N+1-Muster, Querypläne, Indizes, gelesene Zeilen, Lockwartezeiten, Transaktionsdauer, Sessionsperren, wiederholte Snapshotberechnungen und Antwortgrößen untersuchen. PHP-FPM/OPcache und nginx/Containergrenzen anhand beobachteter Engpässe bewerten. Schreibende Queryanalysen ausschließlich mit QA-Daten ausführen.
4. Worker/Ansible: Queuewartezeit getrennt von Ausführungszeit, externe Roundtrips, Uploadmengen, Polltakt, seriell notwendige Schritte und Wartungskonkurrenz messen. Eine höhere Parallelität nur als Vorschlag zulassen, wenn Identitäts-, Lock- und Createverträge erhalten bleiben.
5. PowerShell/MECM: Providerabfragen, vollständige Inventarabrufe, API-Aufrufzahl, JSON-Mengen, Laufdauer gegenüber Taskintervall, wiederholte Modulinitialisierung und Ressourcenverbrauch je Aufgabe erfassen. Clientphasen einschließlich Reboot-/Netzwartezeiten gesondert messen; ein Mockbenchmark belegt keine MECM-Laufzeit.
6. Browser/Logs: Pollfrequenz, parallele/veraltete Requests, unsichtbare Tabs, DOM-Menge, Logwachstum und Retention betrachten. Cache-/Batchingvorschläge müssen Invalidierung, Revision, Frische, Berechtigung und Größenbegrenzung benennen; geringere Aktualität darf nicht still als Beschleunigung verkauft werden.
7. Für jeden Optimierungsvorschlag Engpass, Messbeleg, zuständigen Owner, erwarteten Nutzen, Nebenwirkungen und identisches Vorher-/Nachher-Profil festhalten. Grenzwerte und Baselines bleiben bei den bestehenden Lasttests bzw. Laufartefakten, nicht als zusätzliche Produktkonstanten in diesem Plan.

Abnahme: reproduzierbare Baseline oder konkrete Messlücke für jede Schicht sowie priorisierte Optimierungspakete. Eine Verbesserung gilt erst nach vergleichbarer Nachmessung und unveränderter fachlicher Korrektheit als belegt. Keine Last-/Störungstests gegen produktive Fremdsysteme.

### AP10: Robustheit, Konvergenz und gezieltes Self-Healing

1. Vorhandene Mechanismen zuerst inventarisieren: Worker-Reconnect, Supervisor, Jobreaper/Konvergenz, Remote-Recovery, Inventarwiederholung, PowerShell-Retries und Taskneustart. Auslöser, Owner, Evidenz, Grenzen und sichtbaren Zustand erfassen; keinen zweiten Reparaturprozess neben einen bestehenden Owner planen.
2. Je Fehlerbild entscheiden, was sicher automatisch möglich ist: erneut beobachten, eine idempotente Operation wiederholen, unter aktuellen Locks/Revisionen einen eindeutig belegten Zustand abgleichen oder bis zur manuellen Klärung stoppen. Beobachtung und Reparatur besitzen unterschiedliche Wirkungen und Nachweise.
3. Eine Self-Healing-Kandidatenmatrix erstellen: Fehlererkennung, maßgebliche Evidenz, Vorbedingungen, erlaubte Aktion, Idempotenz, CAS/Ownership, maximale Versuche, Zeit-/Ressourcenbudget, Backoff/Jitter, Cooldown, Abbruch/Eskalation und Erfolgskontrolle. Vorhandene Grenzwerte und Mechanismen wiederverwenden; neue Werte nur begründet am zuständigen Owner vorschlagen.
4. Vorübergehende und dauerhafte Fehler trennen. Ein Authentisierungs-, Zertifikats-, Konfigurations- oder Identitätsproblem wird nicht durch dauerndes Wiederholen geheilt. Eine DB-Störung ist nach ADR-0042 kein Beleg, dass ein lebender Worker ersetzt werden darf. Eine unklare externe Create-Wirkung erlaubt keinen neuen Createversuch.
5. Mit isolierter Fehlerstimulation prüfen: Verbindungsabbruch vor/nach externer Wirkung, fehlende Antwort, verspätete/doppelte Rückmeldung, DB-Neustart, Kindprozess ohne Fortschritt, API-429/5xx, volles/nicht beschreibbares Logziel und konkurrierende manuelle Aktion. Dabei Nachweisverlust und tatsächlichen Fehlschlag getrennt behandeln.
6. Konvergenz nachweisen: fachlich richtiger Endzustand, keine doppelte Mutation, begrenzte Wiederholungen und Last, kein wachsender Rückstau, keine unendliche Neustartschleife. Bei wiederholten Störungen muss ein verständlicher begrenzter Zustand entstehen. Reparatur darf keine Ursache, Historie oder ungeklärte Ausführung durch ein grünes Statuslabel verdecken.
7. Recoverydauer und zulässigen Rückstau je unterstütztem Fehlerbild messen bzw. ein Ziel zur Entscheidung vorschlagen. Erfolgreiche Wiederherstellung und Ende des Versuchsbudgets im Portal/Log verständlich unterscheiden; Korrelation bleibt Diagnose und erzeugt keine zusätzliche Reportflut.

Abnahme: belegte Bewertung bestehender Erholungspfade, sichere konkrete Self-Healing-Kandidaten und klar begründete Fälle für manuelle Klärung. Neue Automatik ist ein Umsetzungspaket mit Fehlerstimulation und Betriebsabnahme, kein pauschaler Neustartmechanismus.

### AP11: Wahrheitsprüfung von Dokumentation, Hilfe und Bedienhinweisen

1. Aktive Dokumentation inventarisieren: README, Installation, Deployment, Betrieb/Fehlersuche, QA/Release/Offline, PowerShell-READMEs und Skripthilfe sowie eingebettete Portalhilfe, Tooltips, Blocker, Status- und Recoverytexte in DE/EN. Historische Auditberichte und abgelöste ADR-Aussagen als Historie kenntlich einordnen.
2. Im Register eine Behauptungsmatrix führen: Fundstelle/Sprache, konkrete überprüfbare Aussage, Geltungsbereich/Voraussetzung, zugehöriger Vertrag/Owner, tatsächlicher Codepfad, Laufzeitnachweis und Urteil. Besonders Aussagen wie automatisch, nur, immer, sicher, abgeschlossen oder erneut versuchen einzeln untersuchen.
3. Voraussetzungen, Standardwerte/Grenzen, Rechte, Pfade/Ports, Installerparameter, Task-/Clientreihenfolge, Wirefelder, Statusbedeutung, Frische, Retry/Recovery und Nebenwirkungen mit ihren echten Quellen abgleichen. Befehle auf dem dokumentierten unterstützten Ziel sicher nachvollziehen; externe Schritte als Labprüfung vorbereiten.
4. Hilfe im tatsächlichen Kontext prüfen: Ist die beschriebene Funktion vorhanden? Erfüllt der empfohlene Schritt die genannte Voraussetzung? Existiert das Sprungziel für diese Rolle und dieses Panel? Bleibt die Aussage nach einer Statusänderung richtig? Stimmen DE/EN fachlich überein, auch wenn Schlüssel und Platzhalter bereits paritätisch sind?
5. Die Gegenrichtung prüfen: Fehlt für vorhandene Funktionen, Fehlerpfade, Grenzen oder notwendige manuelle Schritte eine Erklärung? Kann ein neuer Operator mit der Anleitung zum beschriebenen Ergebnis gelangen? Unbelegte Support-/Kompatibilitätsversprechen und nicht nachgewiesene Laborannahmen benennen.
6. Bei Widerspruch nicht automatisch die Doku an den Code anpassen: Sollvertrag und Nutzeranforderung bestimmen, ob Produktcode, Text oder die Entscheidung geändert werden muss. Historische Befunde nicht nachträglich in einen erfundenen früheren Erfolg umschreiben.
7. Automatisch prüfbare Beziehungen an bestehende Link-, Registry-, Sprach-, Bounds- und Semantikguards anschließen. Rein sprachliche oder fachliche Aussagen benötigen passende Verhaltensnachweise; ein Stringtest allein beweist ihre Wahrheit nicht.

Abnahme: Behauptungsmatrix mit richtig, falsch, unvollständig oder nicht nachgewiesen; konkrete Korrekturquelle und Abnahme pro Abweichung. Stichproben sind als Stichproben mit Restumfang bezeichnet und gelten nicht als Vollprüfung sämtlicher Texte.

### AP12: Betrieb, Migration, Distribution und verbleibende Grenzen

1. Frischinstallation und Migration eines älteren unterstützten Stands auf Schemakonvergenz und relevante Altwerte prüfen. Backup/Restore einschließlich verschlüsselter Konfiguration nach bestehendem Runbook in isolierter Umgebung nachweisen.
2. Worker-/Datenbankneustart, Wartung, Retention, Zeit-/Schedulinggrenzen und Ausfälle mit dem Betriebsvertrag vergleichen; bestehende AP03-/AP07-Nachweise wiederverwenden.
3. Build, Compose, CI-Artefakte und Offline-Bundle hinsichtlich tatsächlich ausgelieferter Versionen, Mountpfade, Manifest/Hashes und benötigter Dateien prüfen. Aktiven Quellcode von alten lokalen Kopien unterscheiden.
4. Geeignete Release-Gates über den kanonischen Runner auswählen; keine Releasefreigabe aus einer Teilmenge ableiten. Fehlende aktuelle Supply-Chain-Prüfungen als Nachweislücke kennzeichnen.
5. Reale MECM-/Task-Sequence-, SYSTEM-, ESXi-/Idempotenz- und AD-Abnahmen aus den bisherigen Paketen konsolidieren. Für jeden Fall Voraussetzung, Zielumgebung, Aktion, Sollzustand und benötigte Artefakte beschreiben.

Abnahme: Betriebs-/Distributionsbefunde und ein ausführbares Laborprotokoll. Offlineprüfungen werden nicht als Bestätigung fremder Systeme ausgegeben.

### AP13: Gegenprüfung und Umsetzungspakete

1. Befunde auf aktuellen Prüfstand, Doppelzählung, Gegenbeispiele, gültige Ausnahmen und Beweisstärke prüfen. Auch für einen vorgeschlagenen SSoT-Umbau nachweisen, dass die Verbraucher dieselbe Fachregel benötigen.
2. Priorisierte Umsetzungspakete bilden: betroffene Owner/Dateien, Änderung, Abhängigkeiten, Verträge, Regressionstest, Reviewbedarf und Abnahme. Jede behobene Fehlerklasse möglichst am fachlichen Verhalten absichern; Quelltextguards nur passend ergänzen. Performancepakete benötigen Vorher-/Nachher-Messung, Self-Healing-Pakete begrenzte Fehlerstimulation und Dokumentationspakete die Verifikation ihrer Behauptungen.
3. Vorhandene Vertrags-, Drift-, i18n- und QA-Reviewpflichten für die spätere Umsetzung je Paket benennen. Kein pauschales Refactoring und kein neuer Test nur zur Spiegelung der Implementierung.
4. Abschlussbericht im Register ausfüllen: bestätigte Befunde, QoL, Performance, Robustheit/Self-Healing, Doku-/Hilfewahrheit, Entscheidungen, geprüfte Bereiche ohne Befund, offene Nachweise und empfohlene Reihenfolge.

Abnahme: Jeder Auditbereich hat belegten Prüfstatus oder eine konkret begründete Lücke. Ein abgeschlossenes Audit kann offene Produktfehler enthalten; deren Behebung und eine Releasefreigabe sind eigene Aussagen.

## 5. Modellempfehlung für die Auditpakete

Stand der Onlineprüfung: 08.09.2026. Sol bezeichnet hier `gpt-5.6-sol`, Astra `gpt-6-astra`. Die Zuordnung ist eine aus Aufgabenkomplexität und Fehlerfolgen abgeleitete Empfehlung, kein gemessener Modellvergleich für VirtuSphere. Sie ändert weder Modellkonfigurationen noch fachliche Abnahmekriterien.

OpenAI beschreibt Sol als leistungsfähiges Modell für komplexe professionelle Arbeit und Astra als sein leistungsfähigstes Modell für besonders anspruchsvolle vollständige Abläufe. Beide unterstützen die hier empfohlenen Reasoning-Stufen. Quellen: [Sol-Modellbeschreibung](https://developers.openai.com/api/docs/models/gpt-5.6-sol), [Astra-Modellbeschreibung](https://developers.openai.com/api/docs/models/gpt-6-astra).

Die [Modellauswahl für Codex/ChatGPT Work](https://learn.chatgpt.com/docs/models) empfiehlt Astra für zusammenhängende Abläufe mit anhaltendem Reasoning und Urteilsvermögen; Sol eignet sich ebenfalls für komplexe offene Aufgaben. Die [Astra-Modellführung](https://developers.openai.com/api/docs/guides/latest-model) beschreibt bessere Kohärenz über lange Aufgaben und teils geringeren Tokenbedarf. Daraus folgt keine feste Ersparnis je Auditpaket. Die verwendeten Quellen sind Herstellerdokumentation, kein unabhängiger VirtuSphere-Benchmark.

| Paket | Federführung | Reasoning zum Start | Verteilung und Grund |
|---|---|---|---|
| AP00 | Sol | Medium | Gitstand, Vorbefunde und Umgebung strukturiert erfassen. |
| AP01 | Sol | Medium | Bestehende Gates ausführen und echte Ergebnisse sichern; unklare fachliche Testlücken gezielt an Astra zur Gegenprüfung geben. |
| AP02 | Astra | High | Fachregeln, Schreibwege, fehlende Owner und widersprüchliche SSoT über alle Schichten zusammenführen. Sol kann das Dateiinventar vorbereiten. |
| AP03 | Astra | Extra High | Create, Cancel, Retry und externe Wirkung unter konkurrierenden Ausführungen sind der anspruchsvollste Zustandsautomatenbereich. Sol kann definierte Reproduktionen ausführen. |
| AP04 | Astra | High | MECM-Provenienz, MembershipJournal, API-Konflikte und externe Teilerfolge gemeinsam beurteilen. Pester-/Analyzerläufe sind geeignete Sol-Arbeit. |
| AP05 | Sol | High | Client-/Installerinventar, Packaging, Registry, Logging und vorhandene Tests abarbeiten. Astra High prüft anschließend die kritischen ACK-, Reboot-, Netzwerk- und Datenträgerübergänge anhand Code und Evidenz. |
| AP06 | Astra | Extra High | Atomare Schreibmengen, Lockreihenfolgen, Revisionen, Identität und verspätete Callbacks verbinden. |
| AP07 | Astra | High | Aussagekraft von Evidenz, Status und Recovery gegen den fachlichen Zustand prüfen. Sol kann Text-/Linkinventare vorbereiten. |
| AP08 | Sol | High | Reproduzierbare Bedienabläufe, Eingabeerhalt, Berechtigungsdarstellung, Layout und QoL prüfen. Fachliche Zustandswidersprüche mit AP07 verbinden. |
| AP09 | Sol | High | Messprofile, Baseline, Query-/Ressourcenbefunde und Nachmessungen führen. Astra High bewertet mehrschichtige Ursachen und Vorschläge, die Caching, Parallelität oder Konsistenz verändern. |
| AP10 | Astra | Extra High | Self-Healing nur mit belastbarer Evidenz, begrenzten Aktionen, Idempotenz und Konvergenz beurteilen. Sol führt zuvor definierte Fehlerstimulation aus. |
| AP11 | Sol | High | Behauptungen erfassen, Befehle/Links prüfen, DE/EN und dokumentierte Werte abgleichen. Astra High beurteilt Widersprüche zwischen Sollvertrag, Implementierung und behaupteter Recovery-/Statuswirkung. |
| AP12 | Sol | High | Bestehende Betriebs-, Restore-, Distributions- und Releaseprüfungen ausführen. Astra High prüft neue Datenverlust-, Migrations-, Wiederanlauf- oder Vertrauensprobleme. |
| AP13 | Astra | High | Befunde gegenprüfen, Lücken zwischen Paketen suchen und tragfähige Umsetzungspakete priorisieren. Extra High bei ungelösten schichtenübergreifenden Widersprüchen. |

Für übersichtliche Ausführung ohne häufige Wechsel: AP00/AP01 mit Sol beginnen; AP02 bis AP07 als zusammenhängenden Astra-Analyseblock bearbeiten, wobei AP05 auch durch Astra erledigt werden kann. Danach Sol für AP08/AP09 und die Bestandsarbeit von AP11/AP12 verwenden; Astra übernimmt AP10 und die zusammengefasste Gegenprüfung in AP13. Abhängigkeiten aus Abschnitt 4 bleiben maßgeblich. Klar definierte Mess- und Testläufe benötigen keinen Modellwechsel allein wegen langer Laufzeit.

Bei einer Übergabe erhält das nächste Modell den Prüfstand, relevante Owner/Codepfade, konkrete Vorbedingungen, rohe sichere Testevidenz, offene Gegenbeispiele und die noch ungeklärte Frage. Astra soll auch negative Ergebnisse und ausgewählte als sauber bewertete kritische Pfade prüfen; die reine Bestätigung einer Sol-Zusammenfassung reicht nicht. Ein zweites Modell ersetzt keinen Laufzeitnachweis.

Die empfohlenen Reasoning-Stufen sind Startwerte. Die [offizielle Auswahlhilfe](https://learn.chatgpt.com/docs/models) empfiehlt die niedrigste Stufe, die die nötige Qualität erreicht; höhere Stufen benötigen mehr Zeit und Tokens. Max oder Ultra sind hier kein Standard. Nach den ersten Paketen anhand bestätigter Befunde, Fehlalarmen, übersehenen Gegenbeispielen, Nacharbeit und beobachtetem Verbrauch nachjustieren. Im Register pro Paket tatsächlich eingesetztes Modell/Reasoning und Gegenprüfung vermerken. Die Tabelle ist eine Planungshilfe und wechselt selbst kein Modell. Der Startauftrag in Abschnitt 7 fordert die interne Delegation ausdrücklich an; daraus entsteht kein Auftrag zur Anlage eigenständiger neuer Benutzertasks.

## 6. Fortsetzung nach Unterbrechung

Vor dem Ende jeder Session im Register festhalten: aktuelles Paket, geprüfter Stand, letzte tatsächliche Ergebnisse, noch laufende Prozesse/Logs, veränderte Testdateien, verbleibende Voraussetzungen und der nächste konkrete Befehl oder Leseschritt. Laufende QA-Prozesse kontrolliert abschließen oder klar übergeben.

Die nächste Session liest zuerst Plan und Register, prüft anschließend den aktuellen Gitstand und setzt beim nächsten offenen Schritt fort. Nachweise nur wiederholen, wenn Änderungen, Fehler oder ungeklärte Randbedingungen dies rechtfertigen. Eine fehlende Umgebung blockiert nur abhängige Prüfungen; selbstständige Quellprüfung und Labvorbereitung gehen weiter.

## 7. Startauftrag für eine neue Session

Die neue Session im Projekt `C:\projekte\VirtuSphere-v2-WebApp` mit **GPT-6 Astra / High** starten und den folgenden Text als Auftrag verwenden. Die Modellauswahl des Hauptagenten erfolgt vor dem Start im Client; der Text allein wechselt dessen Modell nicht.

Parallele Subagenten und unterschiedliche Modell-/Reasoning-Einstellungen sind laut [offizieller Subagenten-Dokumentation](https://learn.chatgpt.com/docs/agent-configuration/subagents) unterstützt. In der vorbereitenden Session standen insgesamt vier Agentenplätze einschließlich Hauptagent zur Verfügung. Die neue Session muss ihre tatsächlich verfügbaren Werkzeuge, Modelle und Grenzen beachten. Mehrere Agenten benötigen zusätzliche Tokens; der Nutzen entsteht durch unabhängige Arbeit und klare Grenzen.

```text
Übernimm als Hauptagent die Orchestrierung des vollständigen VirtuSphere-Audits.

Projekt: C:\projekte\VirtuSphere-v2-WebApp
Plan: C:\projekte\VirtuSphere-v2-WebApp\docs\audits\2026-09-08-system-chain-audit-plan.md
Register: C:\projekte\VirtuSphere-v2-WebApp\docs\audits\2026-09-08-system-chain-audit-register.md

Lies zuerst Plan, Register und die aktuellen Repositoryregeln. Beginne bei
AP00 oder beim dokumentierten nächsten offenen Schritt und arbeite bis AP13.
Der Auftrag umfasst die gesamte Portal-/DB-/Worker-/Ansible-/ESXi-Kette,
MECM-Server-PowerShell, Clientskripte, Installer und Machine APIs sowie
Performance, Robustheit, geeignetes Self-Healing, QoL und die fachliche
Wahrheit von Dokumentation und DE/EN-Portalhilfe.

DELEGATION UND MODELLE
Ich beauftrage dich ausdrücklich, passende unabhängige Teilaufgaben an
Subagenten zu delegieren und parallel auszuführen. Verwende interne
Subagenten dieser Aufgabe, keine eigenständigen neuen Benutzertasks.
Nutze bis zu drei Subagenten gleichzeitig neben dir, begrenzt durch das
tatsächlich verfügbare Sitzungslimit. Halte Agenten nicht künstlich
beschäftigt; verwende sie für konkrete, abgegrenzte Arbeit und nutze sie
für passende Folgeaufgaben erneut. Unterdelegation koordinierst nur du.

Verwende gpt-5.6-sol und gpt-6-astra mit den Reasoning-Einstellungen aus
Abschnitt 5 des Plans. Gib Modell und Effort beim Start ausdrücklich an,
soweit unterstützt. Prüfe Rollenprofile vor Nutzung auf abweichende
Modelle, veraltete Anweisungen und unpassende QA-Ziele. Behaupte keinen
Modellwechsel ohne tatsächliche Toolunterstützung. Fehlt ein gewünschtes
Modell, benenne die Abweichung und setze geeignete Arbeit mit verfügbaren
Mitteln fort; kritische fehlende Gegenprüfungen bleiben offen.

ABHÄNGIGKEITEN UND VERANTWORTUNG
Sichere zuerst Prüfstand und Isolation aus AP00. Danach dürfen vorhandene
Prüfungen aus AP01 und unabhängige Quellanalysen parallel laufen.
Verwende die gemeinsame Fluss-/SSoT-Karte aus AP02 für weitere Pakete.
Beachte die Abhängigkeiten des Plans; unabhängige Teilprüfungen dürfen
vorziehen, abhängige Schlussfolgerungen erst nach Vorliegen der Evidenz.

Jeder Subagent erhält eine konkrete Frage, seinen Umfang, relevante
Verträge und Quellen, den Prüfstand, klare Datei-/Artefaktzuständigkeit,
zulässige Werkzeuge und ein überprüfbares Abnahmekriterium. Teile ihm mit,
dass andere Agenten im selben Repository arbeiten und deren Änderungen
zu erhalten sind. Vermeide doppelte Vollanalysen; Gegenprüfungen erhalten
eine ausdrücklich andere Frage oder einen konkreten kritischen Pfad.

Nur du pflegst das zentrale Register und vergibst endgültige Befund-IDs.
Subagenten liefern ihre Kandidaten und Evidenz zurück. Weise Test-Fixtures
und Artefakten eindeutige Pfade und genau einen schreibenden Owner zu.
Bewahre vorhandene Änderungen einschließlich ungetrackter Quelldateien.

QA UND PARALLELITÄT
Vergib die Nutzung des gemeinsamen virtusphere-qa-Stacks exklusiv an
jeweils einen Agenten. Keine überlappenden Stackstarts, Migrationen,
Fixtures, Worker-Stopps, Browserprüfungen oder Restoreläufe. Performance-
Messungen laufen ohne konkurrierende Tests; reduziere nötigenfalls auch
andere Last auf dem Messhost. Quellanalysen dürfen parallel weiterlaufen.
Beachte den vorgeschriebenen Visualvertrag und die vorhandenen Runner.
Nutze ausschließlich synthetische Daten und vorgesehene Testumgebungen.

NACHWEISE UND FORTSCHRITT
Fordere je Ergebnis: Prüfstand, Datei/Funktion/Zeile, Sollvertrag,
Auslöser, tatsächliches Verhalten, Reproduktion oder Kontrollfluss,
Evidenzklasse, Auswirkung, Priorität und fehlende Abnahmen. Unterscheide
Fehler, Hypothesen, QoL, Entscheidungen und Infrastrukturprobleme.
Prüfe kritische Sol-Ergebnisse sowie ausgewählte als sauber bewertete
Pfade mit Astra anhand Originalcode und sicherer Testevidenz nach.
Dokumentiere die tatsächlich eingesetzten Modelle und Reasoning-Stufen.

Richte vor langen Prüfungen live lesbare Logs ein. Prüfe mindestens
einmal pro Minute die letzte echte [n/total]-Zeile und berichte sie.
Aktualisiere das Register nach jedem Paket und integriere Ergebnisse
fortlaufend. Warte auf alle für eine Schlussfolgerung relevanten Agenten;
führe währenddessen unabhängige eigene Arbeit weiter.

ZIEL UND ABSCHLUSS
Führe das Audit innerhalb des Plans ohne wiederholte Bestätigungsfragen
aus. Das Ergebnis sind belegte Befunde, Abdeckungs-/Nachweislücken und
priorisierte konkrete Umsetzungspakete. Produktkorrekturen bleiben der
nachgelagerten Umsetzung vorbehalten; geeignete isolierte Reproduktionen
und Test-Fixtures gehören zum Audit. Externe Abnahmen ohne verfügbare
Zielumgebung als ausführbare Laborfälle vorbereiten und offen ausweisen.

Beende die Arbeit nicht nach der ersten Befundliste. Prüfe Gegenbeispiele,
Zusammenhänge und Vollständigkeit gemäß AP13. Bei Unterbrechung sichere
im Register den Prüfstand, echte Ergebnisse, Agenten-/Prozessstatus,
Artefakte und den nächsten konkreten Schritt. Gib abschließend den
geprüften Umfang, priorisierte Befunde und verbleibende Grenzen an.
Starte jetzt mit dem Lesen der Dateien und AP00.
```
