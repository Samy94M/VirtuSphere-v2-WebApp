# Auditplan: VirtuSphere, PowerShell und die vollständige Integrationskette

Erstellt: 08.09.2026; fortgeschrieben: 12.09.2026. Auftrag der ersten Runde: Fehler, Logikprobleme, fehlende Verträge, Drift, fehlende oder umgangene SSoT sowie konkrete QoL-Verbesserungen aufdecken. Zusätzlich den gesamten Stack auf Performance, Robustheit und geeignetes Self-Healing prüfen sowie Dokumentation und Portalhilfe fachlich mit dem tatsächlichen Verhalten abgleichen.

Dieser Plan ist für die gezielte Fortsetzung in einer neuen Session bestimmt. Er beschreibt die Auditmethode und Arbeitspakete. Fortschritt, Befunde, Entscheidungen und Nachweise der ursprünglichen Auditpakete werden ausschließlich im [Befundregister](2026-09-08-system-chain-audit-register.md) fortgeschrieben. Die Erstellung des Plans ist noch keine Produktprüfung.

**Fortsetzungsstand:** AP00 bis AP13 sind am dokumentierten Auditstand bereits durchgeführt. U01 bis U17 sind implementiert; ihre und die übrigen offenen Laufzeit-, Gesamt-QA-, Standort- und Releaseabnahmen stehen im Befundregister sowie im [konsolidierten Session-Backlog](2026-09-12-consolidated-session-backlog.md). Dieser Plan verlangt deshalb keinen vollständigen Neuanfang. Eine spätere Session prüft nur den von Änderungen oder einer offenen Abnahme betroffenen Pfad erneut und hält die historische Evidenz von der aktuellen Handlungsanweisung getrennt.

Der zusätzliche Fachreview des neueren Commits `dfdb4ca` steht als
[Gesamtplan:M03](2026-09-12-consolidated-session-backlog.md#m03-fachreview-des-letzten-lokal-bekannten-commits)
im Gesamtplan. Vier bestätigte Befunde betreffen AP04/AP07/AP11;
drei offene Nachweisfragen präzisieren AP04/AP10/AP12 und M02/L01.
Diese Reviewkarte besitzt die neuen IDs; die spätere Ergebnisfortschreibung
referenziert sie ohne doppelte Befundpflege. Eine pauschale Wiederholung
aller Auditpakete folgt daraus nicht.

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

Die erste Runde lieferte belegte Befunde und ausführbare Umsetzungspakete. Sie umfasste Quellprüfung, vorhandene Prüfungen, isolierte Reproduktionen und gezielte Test-Fixtures. Die daraus entstandenen Produktkorrekturen U01 bis U17 sind implementiert; ein nachfolgender Auftrag entscheidet jeweils über die noch fehlenden Abnahmen. Frühere Audit- oder Umsetzungsfreigaben werden durch diesen Plan weder erneut erteilt noch erweitert.

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

Die Reihenfolge AP00 bis AP13 beschreibt die bereits abgeschlossene Gesamtprüfung. Für eine erneute gezielte Prüfung bestimmt der konkrete Änderungsumfang oder die offene Abnahme das Paket. AP09 erfasst eine Performance-Baseline nur bei einer messrelevanten Änderung; Dokumentationsbehauptungen aus AP11 werden bei der jeweiligen Fachprüfung gesammelt. Abhängige Schlussfolgerungen bleiben offen. Ein Folgepaket endet mit aktualisiertem Register, Evidenz und einem konkreten nächsten Schritt.

| Paket | Lead bei erneuter gezielter Prüfung | Unabhängige Gegenprüfung | Eingang | Ausgabe |
|---|---|---|---|---|
| AP00 | Sol Medium | Terra Low prüft Inventar/Referenzen | aktueller Gitstand, Register, aktive Änderungen | abgegrenzter Prüfstand und Wiederholungsgrund |
| AP01 | Sol Medium, exklusiver QA-Owner | Terra Low ordnet vorhandene Logs ein | Diff, Runner, frühere End-JSONs | tatsächliche Gateaussage oder klarer Infrastrukturfehler |
| AP02 | Sol High | Astra High nur bei strittigem Vertrags- oder Kausalpfad | betroffene Writer/Reader und bestehende Matrix | aktualisierte SSoT-/Flussentscheidung |
| AP03 | Sol High | Astra High bei ungelöster Create-/Cancel-/Retry-Kausalität | betroffener Job-, Worker- und Ansiblepfad | begrenzter Nebenläufigkeitsnachweis oder Laborlücke |
| AP04 | Sol High | Astra High bei MECM-Vertragskonflikt | Serveraufgabe, API-Vertrag, sichere Evidenz | Fehlerpfad-/Provenienznachweis oder Standortfall |
| AP05 | Sol High | Astra High bei ACK-, Reboot-, Netz- oder Storagekausalität | Client-/Installerpfad und Paketvertrag | konkrete SYSTEM-/Laborabnahme oder Lücke |
| AP06 | Sol High | Astra High bei Lock-, Revision- oder Callbackkonflikt | Endpunkt, DB-Delta, einschlägige Tests | atomarer Vertragsnachweis oder Befund |
| AP07 | Sol Medium | Astra High bei strittiger Evidenz- oder Recoveryaussage | Status-/Logowner und historische Evidenz | lesbare aktuelle Zustands- und Handlungsaussage |
| AP08 | Sol Medium | Terra Low für Links, Eingaben, Filter und redaktionelle Checks | Operatorfall und betroffene Portalansicht | reproduzierbarer QoL-/Bediennachweis |
| AP09 | Sol Medium, exklusiver QA-Owner; Sol High für Messdesign | Astra High nur für ungeklärte mehrschichtige Messkausalität | identisches Profil, Rohmessungen, Vergleichsstand | belastbare Messreihe oder begrenzte Messlücke |
| AP10 | Sol High | Astra High bei begrenzter Selbstheilungsentscheidung | konkreter Fehlerpfad, Owner, Abbruchgrenze | sichere Wiederanlaufentscheidung oder manueller Fall |
| AP11 | Sol Medium | Terra Low für Behauptungen, Links und DE/EN; Astra High nur bei Vertragswiderspruch | sichtbarer Text, Owner, tatsächlicher Codepfad | Behauptungsmatrix mit Korrekturquelle |
| AP12 | Sol Medium, exklusiver QA-Owner bei Stackarbeit | Astra High bei Datenverlust-, Migrations- oder Vertrauensfrage | gezielter Betriebs-/Laborfall und Runbook | Laborprotokoll oder offen benannte Standortabnahme |
| AP13 | Sol Medium | Astra High begrenzt vor QA/Publikation auf schwierige Fach-/Vertrags-/Kausalitätsgegenprüfung | betroffene Befunde, Gegenbeispiele, Rohbelege | konsolidierte Abnahmeentscheidung ohne Doppelprüfung |

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
6. **QL01:** Bei einem Fehler bleiben Auswahl, Eingaben und Filter sichtbar beziehungsweise wiederherstellbar, damit der Operator den konkreten Fall korrigieren kann. **QL02:** Sammelaktionen erhalten eine Ergebnisübersicht pro explizit ausgewähltem Element, einschließlich Erfolg, Blockierung und nächster Handlung. **QL04:** Ein Versionskonflikt zeigt die konkrete fachliche Alt-/Neu-Änderung, jedoch keine Secrets oder fremden geschützten Werte. Alle drei Fälle bewahren RBAC, CSRF, `edit_version`, Scope und Formularvertrag.

[Gesamtplan:UX01 „Bereitstellung vorbereiten: Blocker verständlich bündeln“](2026-09-12-consolidated-session-backlog.md#ux01-bereitstellung-vorbereiten-blocker-verständlich-bündeln) gehört zu AP08 und AP11: Der Nutzer bemängelt zu viele Meldungen und eine unklare Priorität; ein eigener Auftrag zur Vereinfachung fehlte bislang im Plan und ist nun ergänzt. Unabhängige Sperrursachen bleiben sichtbar, Details kompakt und die Entscheidung beim vorhandenen SSoT.

[Gesamtplan:UX02 „Statuskarten ohne Textüberlauf“](2026-09-12-consolidated-session-backlog.md#ux02-statuskarten-ohne-textüberlauf)
ergänzt den im Screenshot belegten Inhaltsoverflow und die fehlende
Inhalts-/Visualabnahme der Systemstatusübersicht. Unter AP08 werden tatsächliche
Textgrenzen, Zoom und sechs/sieben Karten geprüft; AP11 führt geänderte
Positions-/Bedienhinweise nach. Weitere ähnliche Komponenten sind gezielte
Prüfkandidaten, keine pauschal bestätigten Fehler.

Die zusätzlich angenommenen [Gesamtplan:UX03 bis UX06](2026-09-12-consolidated-session-backlog.md#gemeinsame-bedienbausteine-für-ux03-bis-ux06)
ergänzen AP08 um Kontext/Rückkehr auch nach Erfolg, Schutz ungespeicherter
Änderungen vor dem Absenden, wirksame Werte samt Herkunft und beleggebundene
Aktionsfolgen. Gemeinsame Form-/Navigations-/Wert-/Ergebnisbausteine konsumieren
die bestehenden Fachowner. Zwei Tabs, verlorener Kontext, Rücknahme einer
Änderung, Elternänderung, Vorschau versus Speichern und unklarer Commit sind
verbindliche Gegenfälle aus dem QA-Plan. Browsergrenzen des Verlassensschutzes
und No-JS-Verhalten getrennt ausweisen. Sol High prüft die Fachabbildung,
Sol Medium führt die QA aus; Terra bearbeitet abgegrenzte Muster-/Dokuaufträge.
Die Nutzenreihenfolge lautet UX01/UX02, UX03/UX04, UX05/UX06; F07 und F11
folgen später und bleiben vollständig beauftragt. Abhängigkeiten und
Fehlerkorrekturen bleiben maßgeblich.

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
8. **QL03:** Jede als alt markierte Information nennt lesbar Zeit und Herkunft der letzten Evidenz. **QL05:** Ein unbekanntes Ergebnis führt zu einem lesenden Prüfweg mit klarer Evidenzquelle und nächster Handlung; dieser Weg darf keinen zweiten Write oder eine verdeckte Wiederholung auslösen. Beide Aussagen werden gegen den zuständigen Status-/Logowner und den tatsächlichen Berechtigungspfad belegt.
9. **Gesamtplan:UX03–UX06:** Diagnose und Hilfe folgen dem gemeinsamen Inhaltsmuster aus UX06: Was ist passiert? Was bedeutet das für meinen Auftrag? Was kann ich jetzt tun? Vertiefende Hilfe-/Protokolllinks führen zum richtigen Objekt und Abschnitt. Bestehende Erklärungen erweitern; keine neue große Diagnoseoberfläche. Rückkehr, ungespeicherte Änderungen samt Browsergrenzen, wirksame Werte/Herkunft und lokale versus externe Wirkung an vollständigen Bedienwegen nach D01 prüfen. Gleiche Meldungen nicht durch zusätzliche Kästen oder neue Statuslisten vervielfältigen.

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

## 5. Modell- und Ausführungsvertrag

Die verbindliche aktuelle Zuordnung steht im [konsolidierten Session-Backlog, Abschnitt „Modell- und Ausführungsvertrag“](2026-09-12-consolidated-session-backlog.md#modell--und-ausführungsvertrag). Sie wird hier nicht erneut als globale Empfehlung definiert. Die Tabelle in Abschnitt 4 konkretisiert diese Politik allein für eine möglicherweise nötige gezielte AP-Fortsetzung.

Terra Low bearbeitet Inventar-, Log-, Link- und redaktionelle Prüfungen. Sol Medium führt als Hauptagent Orchestrierung und QA mit exklusivem Stack, eigener Logbeobachtung sowie Git-, Commit- und Pushprüfungen nur bei bereits bestehender Autorisierung. Sol High übernimmt komplexe Umsetzung, Fehleranalyse und Messdesign. Astra High ist ausschließlich eine begrenzte schwierige Fach-, Vertrags- oder Kausalitätsgegenprüfung vor QA oder Publikation; es führt keine Gitoperationen, Commit-/Pushprüfungen, QA-Läufe oder Logwarten aus. XHigh ist nur für einen begründeten ungelösten Analysefall zulässig; Max und Ultra sind kein Standard.

Bei einer Übergabe erhält der nächste Bearbeiter Prüfstand, relevante Owner/Codepfade, konkrete Vorbedingungen, sichere Rohbelege, offene Gegenbeispiele und die ungeklärte Frage. Der Modellwechsel ersetzt keinen Laufzeitnachweis. Die tatsächlich eingesetzten Modelle und Reasoning-Stufen werden im Register nur dann festgehalten, wenn die Folgearbeit stattfindet.

## 6. Fortsetzung nach Unterbrechung

Vor dem Ende jeder Session im Register festhalten: aktuelles Paket, geprüfter Stand, letzte tatsächliche Ergebnisse, noch laufende Prozesse/Logs, veränderte Testdateien, verbleibende Voraussetzungen und der nächste konkrete Befehl oder Leseschritt. Laufende QA-Prozesse kontrolliert abschließen oder klar übergeben.

Die nächste Session liest zuerst Plan und Register, prüft anschließend den aktuellen Gitstand und setzt beim nächsten offenen Schritt fort. Nachweise nur wiederholen, wenn Änderungen, Fehler oder ungeklärte Randbedingungen dies rechtfertigen. Eine fehlende Umgebung blockiert nur abhängige Prüfungen; selbstständige Quellprüfung und Labvorbereitung gehen weiter.

## 7. Startauftrag für eine neue Session

Die neue Session im Projekt `C:\projekte\VirtuSphere-v2-WebApp` mit **GPT-5.6 Sol / Medium** starten und den folgenden Text als Auftrag verwenden. Die Modellauswahl des Hauptagenten erfolgt vor dem Start im Client; der Text allein wechselt dessen Modell nicht. Es wird keine neue Benutzertask angelegt.

```text
Übernimm als Sol-Medium-Hauptagent die Orchestrierung einer gezielten
Fortsetzung des VirtuSphere-Audits.

Projekt: C:\projekte\VirtuSphere-v2-WebApp
Plan: C:\projekte\VirtuSphere-v2-WebApp\docs\audits\2026-09-08-system-chain-audit-plan.md
Register: C:\projekte\VirtuSphere-v2-WebApp\docs\audits\2026-09-08-system-chain-audit-register.md

Lies zuerst Plan, Register, den konsolidierten Session-Backlog und die
aktuellen Repositoryregeln. AP00 bis AP13 sind abgeschlossen, U01 bis U17
implementiert. Beginne beim konkret dokumentierten offenen Abnahmefall oder
bei einem durch einen neuen Diff betroffenen Pfad; starte keine vollständige
Auditkampagne erneut. Halte historische Nachweise von aktuellen
Handlungsanweisungen getrennt.

DELEGATION UND MODELLE
Delegiere ausschließlich unabhängige, konkrete Teilaufgaben mit klarer
Frage, Owner, Eingabe und Ausgabe. Verwende nur interne Subagenten dieser
Aufgabe und lege keine neue Benutzertask an. Jede Delegation folgt dem
Modell- und Ausführungsvertrag im konsolidierten Session-Backlog. Astra High
kommt nur für eine begrenzte schwierige Fach-, Vertrags- oder
Kausalitätsgegenprüfung vor QA oder Publikation in Betracht und führt weder
Gitoperationen noch Commit-/Pushprüfungen, QA-Läufe oder Logwarten aus.

ABHÄNGIGKEITEN UND VERANTWORTUNG
Sichere für den konkreten Fall Prüfstand und Isolation nach AP00. Verwende
die vorhandene Fluss-/SSoT-Karte aus AP02. Alle Abläufe, auch delegierte,
halten dieselben Repository-, Evidenz-, Isolations- und Fortschrittsregeln
ein; abhängige Schlussfolgerungen warten auf ihre Evidenz.

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
Es gibt genau einen exklusiven QA-Owner für den gemeinsamen
virtusphere-qa-Stack. Keine überlappenden Stackstarts, Migrationen,
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
Hole Astra High nur für die begrenzte Gegenfrage ein, wenn ein schwieriger
Fach-, Vertrags- oder Kausalitätsfall vor QA oder Publikation ungelöst bleibt.
Dokumentiere die tatsächlich eingesetzten Modelle und Reasoning-Stufen.

Richte vor langen Prüfungen live lesbare Logs ein. Prüfe mindestens
einmal pro Minute die letzte echte [n/total]-Zeile und berichte sie.
Aktualisiere das Register nach jedem Paket und integriere Ergebnisse
fortlaufend. Warte auf alle für eine Schlussfolgerung relevanten Agenten;
führe währenddessen unabhängige eigene Arbeit weiter.

ZIEL UND ABSCHLUSS
Führe ausschließlich die beauftragte gezielte Abnahme oder Diff-Folgeprüfung
aus. Das Ergebnis sind aktuelle Belege, Abdeckungs-/Nachweislücken und ein
konkreter nächster Schritt. Historische Freigaben werden nicht erneut erteilt;
Commit, Push und Gitoperationen erfolgen nur bei bereits bestehender
Autorisierung. Externe Abnahmen ohne Zielumgebung bleiben ausführbare
Laborfälle.

Prüfe Gegenbeispiele und die für den gezielten Fall nötigen Zusammenhänge.
Bei Unterbrechung sichere im Register den Prüfstand, echte Ergebnisse,
Agenten-/Prozessstatus, Artefakte und den nächsten konkreten Schritt. Gib
abschließend den geprüften Umfang und die verbleibenden Grenzen an. Starte
jetzt mit dem Lesen der Dateien und dem dokumentierten offenen Abnahmefall.
```
