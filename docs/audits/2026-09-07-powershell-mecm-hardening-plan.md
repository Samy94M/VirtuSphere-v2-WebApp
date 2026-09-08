# Ausführungsplan: PowerShell, MECM und VirtuSphere

**Aktueller Stand 08.09.2026:** A01–A18 sind lokal umgesetzt (Abschlussprotokoll unten, Referenz cf75676). Die ursprüngliche Startanweisung ist historisch und kein Auftrag zur erneuten Umsetzung. Die drei offenen Einstellungshilfen wurden jetzt fachlich korrigiert. U01–U07 und echte MECM-/Windows-Abnahmen bleiben offen.

Stand: 07.09.2026. Ziel der Umsetzung: neue Session mit **SOL High**.

Referenzstand der Prüfung: `7fe9b187eb827db6833e2fc298bd535ac22e2253`.
Dieses Dokument ist ein Arbeitsplan, keine Beschreibung bereits implementierter Verbesserungen. In der Planungssession wurde kein Produktcode geändert.

Übergabestand: Der vorhandene Plan wurde am 07.09.2026 um den belegten Redigierungsfehler beider Loggingmodule, konkrete Wiederanlaufentscheidungen, Doku-Abnahmen und eine ausführbare Paketabhängigkeit ergänzt. Pflichtreparaturen und optionale neue Portaloberflächen bleiben getrennt. Die Umsetzung soll diesen Plan fortschreiben, keinen zweiten konkurrierenden Maßnahmenkatalog anlegen.

## 1. Auftrag, Ergebnis und Grenzen

Die PowerShell-Integration soll zuverlässig wiederholbar werden, Teilfehler korrekt melden und keine fremden MECM-Objekte, Netzwerkkonfigurationen oder Datenträger durch vermeintliche Reparaturen verändern. Die größten Risiken liegen in verlorener Mitgliedschaftsprovenienz, voreiliger Paketbereinigung und Erfolgsmeldungen trotz unvollständiger Clientkonfiguration. Dazu kommen konkrete Fehler beim MECM-Verteilstatus und bei Installationswiederholungen.

Der Pflichtumfang umfasst die Arbeitspakete A01–A18 einschließlich der zugehörigen Tests, Betriebsdokumentation und vorhandenen Portal-Hilfen. A17 enthält ausdrücklich zunächst zu verifizierende Verdachtsfälle: Dort ist ein sauber belegtes „keine Änderung erforderlich“ ein gültiges Ergebnis. Die Portal-Erweiterungen U01–U07 sind priorisierte Folgepakete. Sie sind keine Voraussetzung für die Reparatur der PowerShell-Skripte und dürfen deren Fertigstellung nicht verzögern.

Keine produktiven Installer, Netzwerk-, Storage-, Lösch- oder MECM-Schreiboperationen während der Codeumsetzung ausführen. Die hier vorgesehene Implementierung und lokale Prüfung ist von der gesonderten Betriebsabnahme in einer dafür vorgesehenen Testumgebung zu unterscheiden. Eine fehlende MECM-/Windows-Testumgebung als offene Abnahme ausweisen, niemals durch großzügigere Mocks oder übersprungene Gates grün erklären.

### Startanweisung für SOL High

1. Aktuelle `AGENTS.md`, `GROK.md`, die betroffenen ADRs und diesen Plan lesen. `git status` und aktuellen Commit erfassen. Andere Änderungen erhalten; bei abweichendem Stand die Befunde am aktuellen Code nachprüfen.
2. Je Arbeitspaket zuerst den konkreten Fehler reproduzieren oder einen sinnvollen Regressionstest hinzufügen. Anschließend die kleinste vollständige Reparatur einschließlich Dokumentation durchführen.
3. Entscheidungen mit Vertragswirkung vor dem abhängigen Code in einem ADR-Amendment oder neuen ADR festhalten. Keine bestehende Sicherheitsentscheidung beiläufig durch einen Refactor ersetzen.
4. Nach jedem abgeschlossenen Paket in diesem Dokument Status, geänderte Dateien, Testnachweis und offene Betriebsabnahme ergänzen. Keine vollständige Umsetzung behaupten, solange Pflichtpakete fehlen.
5. Ausschließlich `scripts/check.ps1` als öffentlichen Prüfeinstieg verwenden; Gate-Namen und Lanes zunächst mit `-List` aus dem aktuellen Runner lesen. Keine zweite Teststeuerung anlegen.
6. Lange Läufe mit live lesbarem Log starten und spätestens jede Minute die tatsächlich zuletzt beobachtete `[n/total]`-Zeile melden. Bekannte Mehrfacharbeit braucht RUN und Ergebnis pro Einheit. Produktive Endlosschleifen erhalten keine erfundene Gesamtsumme.
7. Keine pauschale Umstellung auf PowerShell 7, kein neues Cloud-/Download-Erfordernis und keine spontane Massenzerlegung der Common-Dateien. Produktionsziel bleibt Windows PowerShell 5.1.

## 2. Aussagekraft der bisherigen Prüfung

Erfasst wurden 39 versionierte PowerShell-Dateien: 16 unter `Powershell-MECM`, 11 Werkzeugdateien unter `scripts`, 11 Pester-Dateien und `PSScriptAnalyzerSettings.psd1`. Die Syntaxprüfung erfasste zusätzlich Kopien in ignorierten QA-Artefakten und kam dadurch auf 147 Dateien. Das ist ein Runner-Befund, kein größerer Produktumfang.

Am Referenzstand bestanden die Syntaxprüfung und PSScriptAnalyzer. Unter Windows PowerShell 5.1/Pester 5.7.1 bestanden 491 Tests, 0 fehlgeschlagen, 0 übersprungen; daneben wurden 38 als `NotRun` ausgewiesen. Die Coverage betrug 82,8 % bei einer konfigurierten Untergrenze von 55 %. Das separat ausgeführte Härtungsregister meldete 33 bestanden und 5 bewusst manuelle/übersprungene Fälle. Diese Zahlen sind eine historische Ausgangsmessung, keine zukünftigen Sollzahlen.

Ein erster Testlauf scheiterte am Sandbox-Zugriff auf Pesters temporäre HKCU-Testregistry. Der erlaubte Wiederholungslauf bestand. Die anfänglichen Fehler waren Infrastrukturfehler. Ergebnislog dieser Sitzung: `C:\Users\Samy\AppData\Local\Temp\virtusphere-qa-20260907-112733\powershell-tests.log`; temporäre Logs sind kein dauerhaftes Abnahmeartefakt für die Folgesession.

Grüne Tests decken die folgenden Fehler nicht zuverlässig ab: Einige Tests prüfen Quelltextformen, andere modellieren selbst falsche MECM-Ausgabefelder. Reproduziert wurden insbesondere die falsche Verteilstatusauswertung, der verlorene `type` bei Membership-Removes, die zusätzliche Logzeile im Resolver-Rückgabewert und unterschiedliche Behandlung doppelter Desired-Einträge. Weitere Befunde ergeben sich aus den beschriebenen Kontrollflüssen. Reales SYSTEM-, MECM-, Netzwerk- und Storage-Verhalten wurde nicht als bestanden abgenommen.

**Evidenzklassen:** B = im Code bzw. durch gezielte Reproduktion belegt; V = Verdacht mit vorgeschalteter Verifikation; E = Erweiterung. Priorität P1 = Daten-/Betriebsrisiko oder blockierte Kernfunktion; P2 = Zuverlässigkeit/Diagnose; P3 = nachgelagerte Optimierung. Keine Priorität ersetzt die Abhängigkeiten.

**Ergänzender Nachweis:** In beiden Loggingmodulen wird zuerst redigiert und danach ANSI entfernt. Ein synthetisches `pass` + ESC + `[31mword=AuditSentinel_OnlySynthetic` wird dadurch zu lesbarem `password=AuditSentinel_OnlySynthetic`, statt den Wert zu maskieren. A06 umfasst beide Fehler. Die Zahlen oben stammen aus der vorherigen Ausgangsprüfung; sie sind keine erneute Messung dieser Planergänzung. Die Folgesession muss ihren eigenen aktuellen Lauf belegen.

## 3. Verbindliche SSoT und Vertragsgrenzen

| Thema | Autoritative Quelle | Konsequenz für die Umsetzung |
|---|---|---|
| Windows-Laufzeitkonfiguration | Registry; auf dem Server `HKLM:\SOFTWARE\VirtuSphere\MECM` | Installer löst Eingaben einmal auf. Portal darf später Vorlagen erzeugen, aber keine zweite wirksame Konfiguration vortäuschen. |
| Paketdefinition und Content | Paketquellen mit `config.json`; MECM für tatsächlich veröffentlichte Objekte | Dateiquelle, importierter Stand und verteilte SourceVersion getrennt führen. |
| VM-Soll und Rolloutidentität | Portal-Repositories, ADR-0043 | `vm_name` bleibt ESXi-Identität, `vm_hostname` der eingefrorene Rolloutname auf dem Wire. Mutationen bleiben revisionsgefenced. |
| Mitgliedschaftsprovenienz | `deploy_vm_mecm_rules` und ADR-0034 | Vorhandensein in MECM beweist kein Eigentum. Ein Journal transportiert Operationen, ersetzt aber nicht die Portal-Provenienz. |
| Live-Mitgliedschaften | Erfolgreiche MECM-Abfrage | Fehler ist unbekannt, niemals eine leere, erfolgreiche Bestandsaufnahme. Portalvorschau ist kein Live-Bestand. |
| Reconciliation-Algorithmus | PHP und PowerShell mit gemeinsamen Vektoren | Gleiche Semantik über Typen, Duplikate, Reihenfolge und Namensvergleich; keine ungeprüfte zweite Variante. |
| Client-Erkennung | `VirtuSphere-ClientPackaging.ps1` und gepinnter Skriptvertrag | Marker nur nach nachgewiesener vollständiger Arbeit; 32-/64-Bit-Sicht wirklich testen. |
| Logging | Getrennt ausgelieferte Client-/Servermodule, ein versionierter gespiegelter Vertrag | Nicht zu einer nur auf einem Host verfügbaren Datei vereinigen. Keine Fachwerte auf dem Log-Ausgabestrom verlieren. |
| Machine-API | Endpoint-/Repository-Verträge und gemeinsame Konstanten | Bestehende Envelopes, HTTP-Bedeutungen, Statusstrings und `updated` erhalten. Kein Token-API-Comeback. |
| Lebenszyklus 5/5 | `mecm_client_ack.php` | `reportPhase` und `reportRun` bleiben ausschließlich Anzeige/Telemetrie. 5/5 ist kein Nachweis aller nachfolgenden Clientphasen. |
| Zeit, Bounds, Ursachen und Darstellung | Bestehende Konstanten, Defaults, Registry-Intervallvertrag, Display-Helper | Grenzen nicht in Hilfe abschreiben; Platzhalter verwenden. Fehlende Daten neutral darstellen. |
| Prüfungen | `scripts/check.ps1`, Tool-Lock und registrierte Module | Keine separaten Gate-Listen, Resolver, Rückgabecodes oder stillen Baseline-Updates. |

## 4. Reihenfolge und Abhängigkeiten

| Etappe | Arbeitspakete | Fertig, wenn |
|---|---|---|
| 1: Nachweis und akute Fehler | A01, A02, A03, A06, A10 | Falscher Verteilstatus, verlorener Remove-Typ, verdeckte Abfragefehler, Ausgabeverunreinigung und Tokenüberschreiben sind regressionsfest repariert. |
| 2: Wiederanlauf und Eigentum | A04, A05, A07, A08, A09 | Sync- und Clientzustände haben eindeutige Wiederanlaufregeln; unklare Operationen werden nicht automatisch als erfolgreich/owned angenommen. |
| 3: Installer und Paketlebenszyklus | A11, A12, A13, A14 | Installationsabbrüche reparierbar, aktuelle Inhalte nachweisbar, Bereinigung hinter sicherem Plan. A14 hängt von A01/A13 ab. |
| 4: Laufzeit, Absicherung, Doku | A15, A16, A17, A18 | Diagnosen belastbar, Verifikationsfälle entschieden und alle aktiven Anleitungen konsistent. Dokumentationsarbeit läuft schon in Etappe 1 mit. |
| 5: Portal-QoL | U01–U07 | Gesonderte Umsetzung nach stabilen Quellen; Anzeigen behaupten nur tatsächlich gemeldete Fakten. |

A04 braucht eine vorgezogene Architekturentscheidung; seine Umsetzung nicht als beiläufigen Teil von A02 behandeln. A08 und A09 benötigen echte Windows-Abnahme. Ein Netzwerkrückfall darf keine fremde Konfiguration zurückrollen, ein Storage-Retry keine unbekannten Partitionen formatieren. A11 darf den vorhandenen funktionierenden Client-Paketswap nicht durch eine schwächere Kopierlösung ersetzen.

### Ausführbare Unterteilung und vorgezogene Schutzmaßnahmen

Die Etappentabelle ist eine thematische Gruppierung. Folgende Abhängigkeiten haben bei der tatsächlichen Ausführung Vorrang:

1. **A06 vollständig, A10 und A14a zuerst:** Logs sicher machen, Tokenresolver reparieren und die gefährliche pauschale Versionslöschung entfernen. A14a bedeutet Altobjekte erhalten und den Grund melden; der spätere sichere Bereinigungsplan A14b wartet auf A01/A13. Die akute Löschgefahr darf nicht bis Etappe 3 bestehen bleiben.
2. **A01/A02/A03/A05 vor A04:** Korrekte Adapter, Reportformen, Unbekannt-Zustände und Identitätsvergleiche sind Voraussetzungen des Journals. A04 nicht auf einem anschließend geänderten Reconciliation-Vertrag aufbauen.
3. **A17-Verifikation passend vorziehen:** Bootstrap und Detection vor A07/A12, Transportbytes und Fehlerantworten vor A04-Replay. A17 ist kein Schlussblock, dessen Ergebnis bereits fertiggestellte Pakete wieder entwerten soll. Unabhängige TLS-Prüfung kann später folgen.
4. **A07 vor A08 und zusammen mit seinen Lesern ausliefern:** Snapshot, Hostname, Netzwerk und Detection müssen denselben veröffentlichten Stand verstehen. A09 kann fachlich unabhängig entstehen, muss aber vor dem gemeinsamen Clientpaket-Upgrade fertig sein.
5. **A11/A12/A13 anschließend integrieren:** Journal-/Snapshot-Hilfsmodule bereits beim Hinzufügen in ihre Lieferlisten aufnehmen. A12 wartet nur für geänderte Detection auf deren A17-Nachweis. A14b darf erst mit nachgewiesenem Ersatz, Eigentum und aktuellen Verteilinformationen aktiviert werden.
6. **A15/A16 und A18 fortlaufend:** Regressionstests und Doku sind Teil jedes Pakets. Den Quellscan aus A18 früh korrigieren, falls Artefaktkopien die Prüfaussage verfälschen. Abschließend Gesamt-Diff und reale offene Abnahmen prüfen; U-Pakete beginnen nicht automatisch.

Keine Implementierung darf einen halbfertigen Vertragswechsel als eigenständig auslieferbares Paket markieren. Besonders betroffen: gespiegelte Loggingversionen, Journal und Reporter, Snapshot und Leser, neue Hilfsmodule und Paketmanifest. Kleine nachvollziehbare Änderungen sind erwünscht; funktional unvollständige Zwischenstände müssen ausdrücklich als solche gekennzeichnet bleiben.

## 5. Pflicht-Arbeitspakete

### A01 · MECM-Verteilstatus richtig lesen — P1, B

**Dateien:** `Powershell-MECM/mecm/VirtuSphere-Common.ps1`, `mecm_autoimporter.ps1`; Pester `VirtuSphere.ErrorPaths.Tests.ps1`, `VirtuSphere.Autoimporter.Tests.ps1`.

**Befund:** `Get-VsContentDistributionState` verwendet etwa ab Zeile 515 `Get-CMDistributionStatus -Name`, alternativ `-Id` mit einer CI_ID, und liest `NumberInstalled`. Der offizielle Cmdlet-Vertrag kennt hier `-InputObject` oder die PackageID; der Erfolgszähler heißt `NumberSuccess`. Neue und bereits vollständig verteilte Anwendungen werden dadurch falsch bewertet. Die bisherigen Mocks bestätigen denselben Irrtum. Siehe Quellen Q1/Q2.

**Ändern/entfernen:** Nicht existierenden Parameterpfad und falsches Property entfernen. Den aktuellen Application-Datensatz eindeutig auflösen; einen dokumentierten Aufruf über `-InputObject` oder seine tatsächliche `PackageID` zentral kapseln. Frisch erstellte Objekte vor der Statusabfrage erneut lesen. Fehlende IDs/Felder und ungültige Zähler ergeben `unknown`, keine implizite Null und kein Erfolg.

**Hinzufügen:** Schemagetreue Fixtures für `Targeted`, `NumberSuccess`, `NumberErrors`, `NumberInProgress`, `NumberUnknown`, `SourceVersion` und Identität. Erfolgsregel für vollständig bekannten, widerspruchsfreien Stand definieren. Nicht aus einem globalen Aggregat behaupten, exakt die konfigurierte DP-Gruppe sei fertig: bestehende Ein-Gruppen-Grenze ausdrücklich erhalten oder gruppengenaue Evidenz separat implementieren.

**Abnahme:** 0 Ziele, 1/n Ziele, vollständiger Erfolg, Teilfehler, unbekannte Zähler, ungültige Zahlen, fehlende Application, verzögerte MECM-Summarization und Providerfehler. Alte SourceVersion darf nach A13 keinen Erfolg für neuen Content liefern. Originalausgabe einer Testanwendung in MECM lesend mit dem Adapter vergleichen. ADR-0034 und Runbook im selben Paket korrigieren.

### A02 · Membership-Removes mit vollständiger Provenienz melden — P1, B

**Dateien:** `mecm_new-device-sync.ps1` ab ca. 361–420; `Get-VsMembershipPlan` in Server-Common; `Docker/WebAPI/lib/mecm_plan.php`, `lib/repo/mecm_provenance.php`, `mecm_updateid.php`; `Docker/WebAPI/tests/fixtures/mecm-plan-vectors.json`.

**Befund:** Der Plan übernimmt für `remove` den beobachteten Datensatz ohne `type`. Der Reporter sendet einen leeren Typ; PHP lehnt den gesamten Report ab, da nur `os|package|mission` zulässig ist. Gleichzeitig gemeldete Adds gehen damit ebenfalls verloren.

**Ändern:** Remove anhand der CollectionID mit dem autoritativen Owned-Datensatz anreichern. Name/Typ der Provenienz bewusst behandeln; fremden Present-Regeln keinen erfundenen Typ aufzwingen. Den ganzen Report vor der ersten Remote-Mutation validieren, soweit die benötigten Daten bereits bekannt sind.

**Abnahme:** Remove jeder Typklasse, gemischte Adds/Removes, fehlende oder beschädigte Provenienz, gleiche Namen bei unterschiedlichen IDs, erfolgreiche idempotente Wiederholung. Die reale PHP-Validierung muss die von PowerShell erzeugte Form akzeptieren. Nicht lediglich den Endpoint lockern, damit kaputte Reports durchgehen.

### A03 · Abfragefehler von „nicht vorhanden“ trennen — P1, B

**Dateien:** `mecm_new-device-sync.ps1`, Server-Common; angrenzende `Get-CM*`-Abfragen der vier Serverprozesse.

**Befund:** Direct-Membership-Abfragen mit `-ErrorAction SilentlyContinue` können eine Providerstörung als fehlende Regel behandeln und damit `stale_owned` erzeugen. Auch Erstell-/Nachlesepfade müssen auf dieselbe Fehlerklasse untersucht werden.

**Ändern:** Kleine Adapter mit drei Ergebnissen: erfolgreich vorhanden, erfolgreich abwesend, unbekannt wegen Fehler. Kritische Lesecmdlets terminierend ausführen und Fehler am fachlich passenden Rand behandeln. Bei unbekanntem Bestand die betroffene VM blockieren; keine Membership hinzufügen/entfernen, keine Provenienz zurückziehen, nicht als fertig aus der Warteschlange nehmen.

**Abnahme:** Providertimeout, Access denied, teilweise verfügbare Caches, verschwundene Collection zwischen Planung und Write, leere erfolgreiche Antwort. Keine pauschale Entfernung aller `SilentlyContinue`: harmlose optionale Diagnose-/Cleanup-Pfade gesondert bewerten.

### A04 · Verlorene Membership-Meldungen und Absturzfenster schließen — P1, B

**Dateien:** Device-Sync und neue fokussierte Journal-/Replay-Helfer, falls nötig; Packaging-/Installationslisten; eventuell additive Endpoint-/Repository-Erweiterung nach ADR-Entscheidung.

**Befund:** Nach einem erfolgreichen MECM-Add kann `reportMembership` scheitern. Im nächsten Lauf ist die Regel vorhanden, aber ohne gespeicherte Provenienz. Sie wird als manuell erhalten und die VM kann anschließend durch `updateDevice` die Warteschlange verlassen. „Im nächsten Lauf idempotent wiederholen“ repariert diesen Ablauf nicht.

**Ziel:** Remote-Operation und Portalquittung sind zwei verschiedene dauerhafte Zustände. Registrierung erst, wenn alle zum aktuellen Rollout gehörenden notwendigen Operationsmeldungen geklärt sind.

**Architekturentscheidung vor Code:** Ein lokales, atomar geschriebenes Journal/Outbox kann bestätigte Operationen zuverlässig nachmelden. Es beweist nach einem Crash zwischen MECM-Write und lokaler Bestätigung aber nicht allein, wer eine inzwischen vorhandene Regel angelegt hat. Dieses Fenster ausdrücklich als `uncertain` behandeln oder ein zusätzliches, belastbares Ownership-Protokoll entwickeln. Ein nur nach dem Remote-Write geschriebenes Journal genügt nicht. Eine vorgelagerte Portal-Intent-Quittung beweist ebenfalls nicht automatisch die Urheberschaft eines konkurrierenden MECM-Writes.

**Hinzufügen:** Schema-Version, Operations-ID, VM-ID, rollout_revision, ResourceID, CollectionID, Typ, gewünschte Änderung, Intent, bestätigtes Remote-Ergebnis und Portal-ACK. Vor Remote-Write Intent dauerhaft speichern; nur sicher bestätigte Ergebnisse replayen. Atomare Ersetzung, exklusive Instanzsperre, restriktive ACL, Größen-/Altersgrenzen, Quarantäne beschädigter Einträge und diagnostizierbarer Speicherfehler. Ungeklärte Operationen nicht allein wegen Alters löschen.

**Wiederanlauf:** Altes Rollout oder fremde ResourceID nicht unter die aktuelle Revision umetikettieren. 409 neu beurteilen, 404 nach definierter Retention behandeln, verlorene ACK-Antwort idempotent wiederholen. Unklare Eigentümerschaft braucht belegte manuelle Auflösung; keine stille Adoption. Journaldateien ohne Secrets und ohne Fachzustandswrites über `reportRun`.

**Entscheidungstabelle für den Journalvertrag:** Die Bezeichnungen sind geplante interne Zustände, keine neuen Machine-Statusstrings. Ein fokussierter Owner definiert Zustände und erlaubte Übergänge; Caller sollen sie nicht neu ableiten.

| Dauerhafte Evidenz beim Neustart | Erlaubtes Verhalten | Nicht erlaubt |
|---|---|---|
| Kein Intent vorhanden | Aktuelle Revision/Identität lesen und frisch planen. | Aus irgendeiner vorhandenen Regel neue Provenienz ableiten. |
| Intent gespeichert, kein bestätigtes Remote-Ergebnis | Bestand erneut lesen; Ausgang als ungeklärt behandeln, wenn die Operation bereits erfolgt sein könnte. Selbst aktuell fehlende Regeln können zwischenzeitlich angelegt und wieder entfernt worden sein. | Remote-Write allein wegen fehlendem ACK blind wiederholen oder das Ergebnis aus aktuellem Vorhandensein als eigene Aktion rekonstruieren. |
| Remote-Ergebnis bestätigt, Portal-ACK fehlt | Exakt die belegte Meldung mit ihrer ursprünglichen Identität wiederholen; aktuellen Fence prüfen. | Erneuter MECM-Write oder Umetikettierung auf eine neue Revision. |
| Portal-Commit möglich, Antwort verloren | Gleiches Replay; Endpoint muss Gleichheit und Konflikt unterscheiden können. Bestehenden Vertrag zuerst prüfen, nötige Ergänzung additiv entscheiden. | HTTP-Timeout als Beweis für fehlenden Commit behandeln. |
| ACK bestätigt | Nur bestätigte Journalposition abschließen; VM erst bei vollständig geklärtem Soll weiterstellen. | Einen Teil-ACK als Abschluss anderer Operationspositionen werten. |
| Journal beschädigt, Speicher voll oder Instanzsperre fehlt | Betroffenen mutierenden Weg vor dem nächsten Remote-Write blockieren und Ursache melden. | Best effort ohne Journal fortfahren oder ungeklärte Dateien per Retention löschen. |

Ein Eintrag braucht einen stabilen fachlichen Schlüssel einschließlich VM, Revision, ResourceID, CollectionID, Typ und Operation; die Operations-ID bleibt bei Transport-Retry gleich. Eine geänderte Sollkonfiguration erzeugt eine neue Planung und darf ausstehende Belege nicht überschreiben. Zwei Reports dürfen denselben Eintrag nicht unterschiedlich finalisieren. Speicherort, Versionsmigration, Sperre und Größenbegrenzung in einem Owner festlegen; Grenzen auslesen und in der Doku referenzieren. Bei erschöpfter Kapazität kontrolliert blockieren statt alte ungeklärte Evidenz zu verwerfen. Für manuelle Auflösung zuerst einen lokalen lesenden Diagnoseweg und dokumentierte Entscheidung mit Beleg vorsehen; eine neue Portaloberfläche ist dafür keine Voraussetzung.

**Abnahme:** Prozessabbruch vor Intent, nach Intent, nach Remote-Write, nach lokaler Bestätigung, nach serverseitigem Commit vor ACK-Empfang; HTTP 400/403/404/409/500; zwei Instanzen; voller Datenträger; beschädigtes Journal; Rolloutreset während Retry. Hand-Regeln bleiben in jedem Fall unangetastet. ADR-0034s bisherige Aussage zu verlorenen Reports ersetzen und konkrete Wiederherstellungsschritte dokumentieren.

### A05 · Reconciliation und Identitätsauflösung vereinheitlichen — P2, B

**Dateien:** Server-Common, Device-Sync, `lib/mecm_plan.php`, gemeinsame Plan-/MAC-/Rollout-Fixtures.

**Befund:** PHP dedupliziert Desired-Einträge; PowerShell iteriert teilweise die Originalmenge und erzeugt doppelte Adds. PowerShell-Hashtables vergleichen standardmäßig ohne Beachtung der Groß-/Kleinschreibung; PHP-Schlüssel können anders arbeiten. Namenscaches können gleichnamige Collections mit „letzter Treffer gewinnt“ auflösen.

**Ändern:** Vergleichs-, Deduplikations- und Sortiervertrag pro Identität festlegen. MECM-IDs bevorzugen, Namen nur eindeutig auflösen. Mehrdeutige Namen blockieren statt zufällig wählen. Windows-Hostnamen, MECM-Collectionnamen und bytegenaue ESXi-Identitäten nicht unter eine pauschale Normalisierungsregel zwingen.

**Abnahme:** Gemeinsame Vektoren für doppelte Desired-Einträge, Case-Varianten, Unicode, Leerzeichen, mehrere Collections gleichen Namens, gleiche Collection mit widersprüchlichem Typ und unterschiedliche Eingabereihenfolge. Die tatsächlich vom Device-Sync erzeugten Datenformen müssen Teil der Vektoren sein, nicht nur idealisierte Hand-Fixtures.

### A06 · Logging-Rückgaben und Redigierungsreihenfolge reparieren — P1, B

**Dateien:** Client-Common, Client-Logging, gespiegelter Server-Logging-Vertrag; `VirtuSphere.Logging.Tests.ps1`, `VirtuSphere.ErrorPaths.Tests.ps1`.

**Befund:** Der Clientlogger nutzt `Write-Output`. Ein Log im HTTP-Fehlerpfad von `Resolve-VsWebApi` gelangt zusammen mit der Adresse auf den Success-Stream. Das Ergebnis kann ein Array statt einer Adresse sein.

**Ändern:** Benutzerdiagnosen auf einen geeigneten Information-/Host-/Warning-Stream legen; strukturierte Rückgaben allein auf Stream 1. Alle datengebenden Helper und verschachtelten Logaufrufe auf dieselbe Verunreinigung prüfen. Keine globale Unterdrückung aller Ausgaben, die echte Rückgabewerte verschluckt.

**Zweiter belegter Fehler:** `ConvertTo-VsLogField` in `mecm/VirtuSphere-Logging.ps1` und `ConvertTo-VsClientLogField` in `clients/VirtuSphere-Client-Logging.ps1` rufen den Redactor vor dem Entfernen von ANSI-Sequenzen auf. Durch ANSI unterbrochene bekannte Secret-Schlüssel werden erst nach der Prüfung wieder zusammengesetzt und mit unmaskiertem Wert ausgegeben. Das betrifft den Dateisink unabhängig vom Resolverproblem.

**Ändern/entfernen:** Die Reihenfolge „Redactor → ANSI entfernen“ ersetzen. Terminalformatierung auf einer Arbeitskopie vor der maßgeblichen Redigierung normalisieren; nachfolgende Umformungen dürfen keine ungeprüfte Secret-Schreibweise erzeugen. Zeilengrenzen nicht voreilig verlieren: Ein mehrzeiliger Header darf nach dem Zusammenziehen keine unmaskierte Restzeile hinterlassen. Bestehende Schlüssel-/Headerliste und UTF-8-Limiter behalten; die Feldlänge zuletzt begrenzen. Die konkrete Pipeline durch kombinierte Vektoren absichern, nicht nur zwei Aufrufe mechanisch vertauschen. Gültige Unicode-Namen erhalten. Keine pauschale URL-Dekodierung beliebiger Logtexte einführen.

**Hinzufügen:** Dieselben synthetischen Vektoren gegen beide Module ausführen: ANSI innerhalb von `password` und `X-VirtuSphere-Token`, innerhalb eines Werts, quoted Werte mit Leerzeichen, CR/LF, C0-Steuerzeichen, Feldtrenner, URL-kodierte bekannte Parameter und Abschneiden an Mehrbytezeichen. OSC-Sequenzen und unvollständige Escape-Sequenzen zusätzlich prüfen und eine sichere, begrenzte Behandlung festlegen. Soweit eine Form nach der Normalisierung als unterstütztes Secret erkannt wird, darf weder das vollständige Testsecret noch ein durch Escape-Trennung zurückgebliebener Wertteil im finalen Feld stehen. Normale Diagnosefelder und Korrelations-IDs müssen weiter lesbar bleiben. Das ist keine Garantie, beliebigen unbeschrifteten Freitext als Secret erkennen zu können.

**Sink-/Stream-Abnahme:** Jeder nach außen geschriebene Diagnosepfad verwendet redigierte Daten, einschließlich Warnungen über Sinkfehler. Fehlende Schreibrechte oder Retentionfehler bleiben lokal gedrosselt und nicht fatal; Erholung setzt die Drossel nach dem bestehenden Vertrag zurück. Kein zusätzlicher `reportRun`/Auditaufruf wegen Logfehlern. Resolver-Tests getrennt für Stream 1 und Diagnoseausgabe durchführen, nicht durch `*>&1` selbst ein Array erzeugen. Logging-Sicherheitszusagen im Server-/Client-README sowie ADR-0029/0032 gegen die tatsächlichen Grenzen abgleichen.

**Abnahme:** Resolver liefert bei jedem akzeptierten HTTP-/Fallback-Pfad genau einen String; Log erscheint dennoch wie vorgesehen. Nicht schreibbares Log, Redigierung, UTF-8-Grenzen, Throttling und Korrelation bleiben funktional. Vertragsversion nur ändern, wenn der publizierte Vertrag dies verlangt; dann beide Pakete, Facades und Installer synchron aktualisieren.

### A07 · Getinfo als vollständigen lokalen Snapshot veröffentlichen — P1, B

**Dateien:** `client_getinfo.ps1`, Client-Common, `client_hostname.ps1`, `client_staticip.ps1`, ClientPackaging und Erkennungstests.

**Befund:** Registry-Writes können nicht terminierend fehlschlagen; `Save-VsValue` überspringt leere Werte. Nur Interfaces werden vorher entfernt, andere alte VM-Felder können bleiben. ACK und Erfolgsmarker sind dadurch nicht an einen nachgewiesen vollständigen lokalen Stand gekoppelt. Folgephasen prüfen dessen Vollständigkeit nicht ausreichend.

**Ändern:** Antwort vollständig validieren und in einer definierten lokalen Snapshot-Struktur vorbereiten. Den alten Erfolgsmarker vor Änderungen ungültig machen. Ausschließlich dem Snapshot gehörende veraltete Felder entfernen; WebAPI-Bootstrap, Token, Logs und fremde Registrywerte erhalten. Schreibfehler terminierend behandeln, den veröffentlichten Stand nachlesen, dann ACK senden, erst nach bestätigter Antwort `SetupState=complete` setzen.

**Hinzufügen:** Expliziter Vollständigkeits-/Schema-Marker und konsistenter Lesepfad für Folgephasen. Lokale Konsistenzprüfung zur gespeicherten Rolloutantwort ist zulässig; sie ersetzt niemals die serverseitige Revision-Fence und erfindet keine Revision für Legacy-Daten. Veröffentlichung per versioniertem Snapshot mit finalem Zeiger/Marker oder gleichwertigem atomaren Lesekonzept; ein beliebiger Registry-Mehrfachwrite ist keine Transaktion.

**Abnahme:** Schreibrecht verweigert an jedem relevanten Schritt; weniger NICs; entfernte optionale Werte; ungültige MAC/Hostname/Revision; alte `complete`-Markierung; ACK-Timeout nach serverseitigem Commit; 409; Markerwrite nach erfolgreichem ACK fehlgeschlagen; gleichzeitig startende Folgephase; konsistente 32-/64-Bit-Sicht. Keine Folgephase verwendet einen teilweise veröffentlichten Snapshot.

### A08 · Netzwerkkonfiguration vollständig und schonend anwenden — P1, B

**Dateien:** `client_staticip.ps1`, Client-Common für reine Validierung; gegebenenfalls gemeinsame Vektoren mit `lib/vm_network_contract.php`, ohne dessen Domainregeln neu zu besitzen.

**Befunde:** Erfolg wird derzeit schon bei mindestens einem angewandten Adapter und null gezählten Fehlern gemeldet; fehlende Solladapter können ungezählt bleiben. `Remove-NetIPAddress` ist teilweise nur nach InterfaceIndex eingeschränkt und kann IPv6-Adressen mit entfernen. Gute Konfiguration wird bei Wiederholung entfernt und neu gebaut. Gatewaywahl hängt von der Iterationsreihenfolge ab; leeres DNS und `Tentative` werden nicht abschließend sauber behandelt.

**Ändern:** Vor dem ersten Write eine vollständige, deterministisch geordnete Soll-/Ist-Liste erzeugen. Eindeutige normalisierte MAC-Zuordnung, fehlende/mehrdeutige/disabled/down Adapter und ungültige Zielwerte getrennt klassifizieren. Jedes erforderliche Sollinterface muss ein Ergebnis bekommen. Gesamterfolg nur bei erfülltem definiertem Soll; leere Sollmenge als eigenen dokumentierten Fall behandeln.

**Entfernen:** Ungerichtetes Löschen aller IP-Adressen und blindes Entfernen bereits passender Werte. IPv4-Änderungen explizit auf die verwalteten IPv4-Werte begrenzen; IPv6 und fremde Adressen/Routen erhalten. Erst nach vollständiger Validierung schreiben und anschließend Prefix, DNS, DHCP-Modus und relevante Routen nachlesen.

**Entscheiden und dokumentieren:** Bedeutung von leerem DNS, mehreren gewünschten Gateways und DHCP-Default-Routen. Keine heimliche „erster Adapter gewinnt“-Regel. Bei widersprüchlichem Soll vor Änderungen blockieren, solange kein ausdrücklich definierter Mehrgatewayvertrag existiert. WDS-/Portgruppenwahl bleibt im bestehenden Portal-/Ansible-Vertrag; dieses Skript konfiguriert IP-Einstellungen und wechselt nicht selbst die ESXi-Portgruppe.

**Hinzufügen:** Begrenztes Warten auf nutzbaren DAD-Zustand, klare Timeout-/Duplicate-Diagnose. Lokale Konfiguration und Portal-Erreichbarkeit als zwei Aussagen behandeln: ein isoliertes Zielnetz ist nicht automatisch eine fehlerhafte IP-Konfiguration. Rückrollversuch nur für nachweislich eigene Änderungen anhand eines vorherigen Snapshots; nicht über zwischenzeitliche Fremdänderungen hinweg.

**Abnahme:** Zwei Soll-NICs, nur eine vorhanden; Down-NIC; doppelte MAC; bereits korrekter Zustand ohne Write; DHCP→statisch und zurück; leerer DNS-Wunsch; IPv6 bleibt exakt erhalten; Duplicate/Tentative/Timeout; mehrere Gateways; Fehler nach jedem Write; erneut starten nach Teilabbruch. Echte Windows-NetTCPIP-Abnahme nach Q3/Q4 ist erforderlich.

### A09 · Disk-Verarbeitung sicher wiederaufnehmen — P1, B

**Dateien:** `clients/Set-VMDisksOnline.ps1`, Client-Common nur für wiederverwendbare Status-/Journalhilfe; Packaging-Erkennungsvertrag.

**Befund:** Nach `Set-Disk -IsOffline $false` kann Initialisieren/Partitionieren/Formatieren fehlschlagen. Beim nächsten Lauf fällt die nun online befindliche, unvollständige Platte aus der Offline-Auswahl. „Keine Offline-Datenträger“ meldet dann fälschlich Erfolg.

**Ändern:** Kandidatenauswahl von offenen eigenen Arbeitsschritten trennen. Disknummer allein ist keine dauerhafte Identität; geeignete stabile Merkmale und erwartete Eigenschaften erfassen. Vor Initialisierung/Formatierung Eigentum und Zielumfang festlegen. Unbekannte online-RAW-Platten nicht pauschal neu in eine Formatierautomatik aufnehmen.

**Hinzufügen:** Dauerhafter Zustand vor irreversiblen Schritten, Nachlesen tatsächlicher Disk-/Partitions-/Volume-Ergebnisse und konservative Wiederaufnahme nur eindeutig eigener Operationen. Abbruch mit konkreter manueller Diagnose bei zweifelhafter Zuordnung. Vorhandene Datenpartitionen bleiben erhalten. Boot/System, schreibgeschützte, gemeinsam verwendete und größenlose Datenträger explizit behandeln.

**Abnahme:** Fehler nach Online, Initialize, New-Partition und Format; Neustart und geänderte Disknummer; vorhandene GPT-/MBR-Datenplatte; RAW mit Größe 0; keine Zusatzplatte; offene alte Operation trotz leerer Offline-Liste; fehlender Laufwerksbuchstabe; Log-/Markerfehler. „Optional, keine Arbeit erforderlich“ und „erforderliche Platte nicht bearbeitet“ dürfen nicht dieselbe pauschale Erfolgsbegründung bekommen. Ausschließlich Wegwerf-Datenträger für echte Storage-Tests.

### A10 · Installer-Eingaben einmal auflösen, Tokenrotation reparieren — P1, B

**Dateien:** `install-VirtuSphere-MECM.ps1`, passende ErrorPaths-/Installer-Regressionstests.

**Befund:** Ein interaktiv neu eingegebener `ReportToken` wird später in der Bestandserhaltung wieder durch den alten Registrywert ersetzt, weil `$PSBoundParameters` die interaktive Eingabe nicht enthält. Mehrere ähnliche Erhaltungs-/Defaultschleifen machen die Herkunft eines Werts unklar.

**Ändern:** Ein Resolver liefert den endgültigen Konfigurationssatz samt interner Herkunft: expliziter Parameter, interaktive Änderung, bewusst behalten, bewusst geleert, Default. Anschließend nur noch validieren und diesen Satz schreiben. Keine nachgelagerte pauschale Bestandserhaltung. Die Bedeutung eines explizit leeren Providers ebenso festlegen; nicht still auf den alten Wert zurückfallen.

**Abnahme:** Erstinstallation; Re-Run ohne Eingabe; interaktiv ersetzen; interaktiv leer = behalten gemäß bisherigem UX-Vertrag; explizit leer = löschen, soweit dokumentiert; vorhandene Intervalle erhalten; ungültiger Wert schreibt nichts. Geheimniswerte in Logs/Fehlern/Artefakten redigieren. Tokenrotation im Portal und anschließender Server-Installer müssen zusammen tatsächlich wieder erfolgreiche Reports ermöglichen.

### A11 · Server-Upgrade und Startdiagnose belastbar machen — P1/P2, B

**Dateien:** Server-Installer, Server-Packaginglisten, `VirtuSphere-ClientPackaging.ps1` als Referenz des bereits vorhandenen Client-Swaps, Release-Bundle-Tests.

**Befund:** Serverdateien werden nach `Stop-ScheduledTask` und zwei Sekunden Wartezeit einzeln verschoben. Damit fehlen sowohl ein positiver Nachweis des alten Prozessendes als auch ein kompletter Rollback bei halber Aktivierung. Die Log-Baseline wird erst nach Taskstart erfasst; 40 Sekunden Beobachtung passen nicht zu allen Intervallen und Leerlaufpfaden.

**Ändern:** Vollständigen Paketsatz und Konfiguration vor Aktivierung validieren. Task-Neustarts sperren, eigene Prozesse über belastbare Task-/PID-/Startzeitzuordnung beenden und ihr Ende begrenzt nachweisen. Erst danach aktivieren. Keine zweite laufende Skriptgeneration. Bei nicht beendetem Prozess mit Diagnose stoppen, keinen gleichzeitigen Ersatz starten.

**Hinzufügen:** Aktivierungs-/Rollbackplan für Dateien, Registry und Aufgabenstatus, der den vorhandenen Altstand konsistent wiederherstellt. Nur aufgelöste, erlaubte Installationspfade anfassen; Junctions/Reparse Points und konkurrierende Installer beachten. Vorhandenen atomaren Client-Verzeichnisswap erhalten und seine Grenzen bei offenem Content/UNC separat testen.

**Diagnose:** Baseline vor Start erfassen. Prozess gestartet, lokale Initialisierung erfolgreich und Portalreport angenommen getrennt melden. Frische Logzeile nicht als alleinigen Betriebsnachweis verwenden. Kurzer Installercheck darf bei einem 300-Sekunden-Reporter „Bestätigung ausstehend“ ausgeben, statt einen unbelegten Blocker zu erzeugen. Public `health.php` beweist weder Machine-IP-Freigabe noch ReportToken-Gültigkeit. Abschlussmarker nur mit dokumentierter Bedeutung schreiben; Teilinstallation nicht als vollständig erfolgreich ausgeben.

**Abnahme:** Erstinstallation/Upgrade/Re-Run; blockierter Taskstop; aktiv laufender Sync; Datei gesperrt; Move-/Registry-/Taskfehler an jeder Aktivierungsgrenze; alte Logdatei, schneller Start vor bisheriger Baseline, Device-Leerlauf, lange Site-Health-Cadence; konsistenter Altstand nach Fehler.

### A12 · Client-Anwendungen tatsächlich reparieren — P2, B

**Dateien:** `install-VirtuSphere-Clients.ps1`, `VirtuSphere-ClientPackaging.ps1`, Client-Deployment-/Detection-Verträge.

**Befund:** Fehler nach Application-Erstellung, aber vor Deployment-Type-Erstellung, hinterlassen eine vorhandene halbe App. Der nächste Lauf überspringt sie. Dependencies werden vor allem für neu erzeugte Apps gesetzt; Fehler gelten nur als Hinweis. Das ist keine vollständige Selbstheilung.

**Ändern:** Ist-Definitionen der vier verwalteten Applications, Deployment Types, Detection Rules, Return Codes, Inhalte und Abhängigkeiten vergleichen. Fehlende eigene Teile gezielt ergänzen. Fremde oder manuell abweichende Definitionen sichtbar machen; keine blinde Überschreibung allein aufgrund eines Namens. Pflegegrenze eindeutig dokumentieren.

**Hinzufügen:** Abhängigkeitsgraph aus einer Datentabelle; Prüfung bei jedem Re-Run, auch bei bestehenden Apps. Fehlende notwendige Dependency ist ein Blocker, sofern nicht ein ausdrücklich unterstützter manueller Modus gewählt wurde. Inhalt am tatsächlichen `ContentShare` per vollständigem Manifest prüfen; bloße Existenz eines alten Dateinamens reicht nicht.

**Abnahme:** Halbe App; fehlender/widersprüchlicher DT; fehlende Dependency; zyklischer/fremder Graph; falscher Contentpfad; alte Dateien unter gültigem UNC; Re-Run ohne Änderungen; Erkennung unter echtem SYSTEM in der richtigen Registryansicht. `Is64Bit` erst nach A17-Verifikation ändern. Der Installer legt weiterhin nicht ungefragt Deployments an Collections an.

### A13 · Paketänderungen bis zum aktuellen Content verfolgen — P1/P2, B

**Dateien:** Autoimporter, Server-Common (`Test-VsTemplateScriptCurrent`), `Package_Vorlage`, Konfigurationsbeispiele und Autoimporter-Tests.

**Befund:** Der Scan-Stamp berücksichtigt vor allem config-/Template-Mtime; reine Payloadänderungen können übersehen werden. Eine fehlende generierte `install.ps1` kann als aktuell gelten. Kopieren einer neuen Wrapperdatei beweist keine Aktualisierung des MECM-Contents. Bei ausgeschalteter eigener Collection kann der Verteilpfad trotz `DeployTo` fehlen.

**Ändern:** Fehlende erwartete Datei ist nicht aktuell. Content-Änderungserkennung über deterministisches Manifest der relevanten Dateien und Templateversion; Schreibpfade und generierte/temporäre Dateien klar ausschließen. Dateisystem-Mtime allein ist kein Inhaltsbeweis. Contentaktualisierung für bestehende Deployment Types anstoßen und aktuelle MECM-SourceVersion bis zur Statusabnahme verfolgen.

**Hinzufügen:** Getrennte Zustände für Quelle gelesen, App/DT gepflegt, Contentversion angefordert, verteilt und Deployment geprüft. Stamp erst nach allen erforderlichen Erfolgen setzen. Distribution unabhängig davon planen, ob eine eigene Device-Collection erzeugt wird. Den bisherigen Konfigurationsschlüssel `generateOwnDeviceColletion` kompatibel behandeln; eine korrigierte Schreibweise allenfalls additiv mit eindeutiger Konfliktregel und Migration einführen.

**Abnahme:** Nur Payload geändert, unveränderte Mtime bei anderem Inhalt, Datei gelöscht/neu hinzugefügt, Wrapper fehlt, alte SourceVersion erfolgreich/neue noch offen, kein DP, fehlgeschlagene Verteilung, UNC temporär weg, zwei Paketordner ändern sich während des Scans. Fehler nicht durch endlose blinde Redistribution kaschieren; bisherige manuelle Fehlerbehebung erhalten, bis ein begrenzter Retry fachlich und live geprüft ist.

### A14 · Automatische Versionsbereinigung ersetzen — P1, B

**Dateien:** Autoimporter ca. 186–220; reine Versions-/Bereinigungsplanung; Paketbeispiele; gegebenenfalls neues Ownershipschema nach ADR.

**Befund:** Andere `Name-Version`-Objekte werden als alte Versionen behandelt, ohne deren Version gegen den aktuellen Ordner zu ordnen. Liegen mehrere Versionen in der Quelle, können sie einander löschen. Die Entfernung von Deployment, Collection und App geschieht vor nachgewiesener Bereitschaft des Ersatzes. Name/Ordner allein beweisen keine Eigentümerschaft.

**Entfernen:** Löschschleife „alles außer aktuellem fullName“ im normalen Importdurchlauf. Bis eine sichere Ersatzlogik vollständig ist, Altobjekte erhalten und einen konkreten Bereinigungsbedarf melden.

**Hinzufügen:** Quellen zunächst vollständig lesen, pro Produkt gruppieren und genau einen eindeutigen Zielstand ermitteln. Beliebige Versionsstrings nicht ungeprüft mit `System.Version` oder lexikalisch ordnen. Vorhandene `catalog_pick_highest_version`-Semantik auf Eignung prüfen; bei gleicher Fachbedeutung gemeinsame Vektoren, bei anderer Bedeutung die Trennung begründen. Nicht interpretierbare Versionen blockieren die automatische Bereinigung.

**Sichere Bereinigung:** Konkreter Plan mit IDs, Eigentumsnachweis, Referenzen, Ersatz und Verteilnachweis. Neue App/DT/Content/Deployment müssen zuerst bereit sein. Plan unmittelbar vor Ausführung gegen aktuellen Bestand prüfen. Fremde Objekte, benötigte alte Deployments und ungeklärte Verknüpfungen erhalten. Zuordnungen alter Paket-Collections nicht beiläufig auf eine neue Version migrieren. Portal-Retirement und tatsächliches MECM-Löschen sind zwei getrennte Vorgänge.

**Abnahme:** 1.9/1.10, 2/10, freie Versionsstrings, parallele Quellversionen, gleichnamige Fremdapp, unvollständiger Ersatz, veralteter Plan, referenzierte Altversion, Fehler nach einer Teilentfernung. Löschplan zunächst in Test-MECM trocken prüfen; echte Löschung nur in freigegebener Testmenge. Keine allgemeinen produktiven Cleanup-Befehle als Test ausführen.

### A15 · Wiederholungslogik und Site-Health entkoppeln — P2, B

**Dateien:** Device-Sync, Server-Common (`Get-VsMecmSiteHealth`), `mecm_site-health.ps1`, Report-Tests; übrige Sync-Loops auf identische Muster prüfen.

**Befund:** Device-Sync setzt `$consecutiveErrors` nach erfolgreichem API-Lesen zurück, bevor MECM-Arbeit erfolgreich war. Ein wiederholter MECM-Fehler erreicht deshalb die vorgesehene Reinitialisierungsschwelle nicht. Site-Health kann bei fehlender konfigurierter Site auf die erste andere Zeile zurückfallen; ein fehlender Rohstatus kann durch Integercast zu 0 werden.

**Ändern:** Fehlerzähler je tatsächlicher Fehlerdomäne oder erst nach vollständigem Erfolg des zugehörigen Abschnitts zurücksetzen. Einzelne fehlerhafte VM ist kein Beweis für einen kaputten SMS Provider. Begrenztes Backoff und Wiederinitialisierung nachvollziehbar loggen. Für Site-Health exakt die konfigurierte Site auflösen; fehlende, mehrdeutige und unlesbare Werte ergeben unknown. Rot bleibt ausschließlich bestätigter Rohstatus 2.

**Abnahme:** API erfolgreich/MECM dreimal defekt; Erholung; einzelne ungültige VM; keine konfigurierte Site im Ergebnis; mehrere Sites; null/fehlender/unbekannter Rohstatus; Providerrechte fehlen. Laufzeit plus Pause und gemeldetes Intervall nicht fälschlich als exakte Wandzeitkadenz darstellen. Sehr lange Laufzeit darf nicht durch einen überlaufenden Millisekunden-Integer den Fehlerpfad selbst abbrechen.

### A16 · Paketwrapper und Rechteprüfungen korrigieren — P2, B/V

**Dateien:** `Package_Vorlage/install.ps1`, beide Installer, Logging-/ACL-Tests, Paketkonfigurationsbeispiele.

**Befunde:** Wrapperlogs unter Program Files passen nicht zu jeder `InstallForUser`-Ausführung. Registry-Schreibfehler können nicht terminieren. Die konfigurierte Stop-on-error-Regel greift bei nichtnull Exitcodes anders als bei Exceptions. Ein bereits ausgelöster Reboot mit 1641 darf nicht einfach zur nächsten Nutzlast führen. ACL-Prüfungen anhand englischer Gruppennamen sind auf deutschen Systemen unzuverlässig; Entfernen geerbter Registry-ACEs entfernt keine schon vorhandenen breiten expliziten ACEs.

**Ändern:** Pro Benutzer geeigneten eigenen Log-/Zustandsort, für SYSTEM einen geschützten Maschinenort verwenden. Keine allgemeinen Schreibrechte auf SYSTEM-ausgeführte Skripte vergeben. Ausführungsfehler, Exceptions und Markerwrites nach einem einheitlichen Ergebnisvertrag behandeln. Stop/Continue gilt für alle Fehlertypen; „Continue“ darf den Gesamtfehler nicht löschen. 1641/3010/0 unterscheiden und Wiederanlauf nach Reboot sauber definieren.

**Hinzufügen:** Falls Schritte nach einem Reboot wiederaufgenommen werden: dauerhafte, inhaltlich/versionell gebundene Schrittresultate; irreversible fremde Skripte bei unbekanntem Ausgang nicht blind wiederholen. Externe Skripte müssen ihren Exitcodevertrag selbst erfüllen; bloße Textausgabe oder `$?` des falschen nachfolgenden Kommandos ist kein Erfolgssignal.

**ACL:** SID-basierte Prüfung tatsächlich gefährlicher Schreib-/Leserechte, Vererbung und expliziter ACEs. Die dedizierte Secret-Registry soll die dokumentierten Principals zulassen; benötigte Dienstidentitäten zuerst prüfen. Keine pauschale rekursive ACL-Reparatur an fremden Shares. Vorhandene explizite Fremd-ACEs müssen diagnostiziert oder an der ausdrücklich verwalteten Stelle korrigiert werden.

**Abnahme:** User/SYSTEM, DE/EN Windows, readonly Logpfad, explizite Users-ACE, Deny/Allow, Registryfehler, Childexit 1, Exception, 1641/3010, Pfade mit Leerzeichen/Unicode, bestehendes Schrittresultat mit geändertem Payload. Konkrete Reboot-/Childprozess-Semantik vor Änderung gegen vorhandene Aufrufkonvention prüfen.

### A17 · Bootstrap, HTTP/TLS und Randverträge verifizieren — P2, V mit belegtem SSoT-Anteil

**Dateien:** Client-/Server-Common, beide Installer, ClientPackaging, HTTP-/Encoding-/Detection-Tests.

**Bootstrap:** Die aktive Anleitung fordert Änderungen an `$VsDefaultDnsApi`/`$VsFallbackIpApi` im Clientquelltext. Das widerspricht dem Ziel umgebungsunabhängiger ausgelieferter Skripte. Einen expliziten Bootstrapweg über Installer/Task-Sequence-Paketkonfiguration definieren, der vor der ersten Adressauflösung die Registry befüllt. Ein Paketmanifest darf Bootstrapwerte transportieren, bleibt aber kein zweiter konkurrierender Laufzeitleser. Bereits installierte Registrywerte erhalten; DNS-Fallback/IP/Scheme und Migration ohne Quelltextpatch dokumentieren.

**HTTP-Encoding, Verdacht:** Stringbasierte JSON-Bodies ohne explizite UTF-8-Behandlung unter PS 5.1 mit lokalem HTTP-Empfänger prüfen. Tatsächliche Bytes für Umlaute, Unicode-Collectionnamen und Sonderzeichen aufnehmen. Falls fehlerhaft: UTF-8-Bytes mit passendem Content-Type zentral senden. Root-Array bei 0/1/n, Datentypen, Tiefe und Bytebounds erhalten. Keine alleinige Ableitung aus PS-7-Verhalten.

**Fehlerdetails:** Prüfen, ob ErrorRecord.ErrorDetails bereits den Body enthält und ein erneutes Lesen des Response-Streams leer bleibt. Strukturierte, begrenzte und redigierte Fehlermeldung erhalten, ohne HTML-Fehlerseiten oder Secrets unkontrolliert ins Portal zu kopieren.

**TLS, Verdacht:** Bestehende Semantik von Thumbprint, gültiger PKI und Self-Signed-Ausnahme unter Windows PowerShell 5.1 nachweisen. Ein Pin als Trust-Ausnahme ist etwas anderes als zwingendes Pinning auch bei gültiger PKI. Policy erst festlegen, dann gegebenenfalls korrigieren. Prozessweite Callbackreste und .NET-Callback-/Runspaceverhalten mit lokalem HTTPS-Test prüfen. Keine unbewiesene PS-7-Kompatibilität behaupten, kein Accept-all als allgemeiner Reparaturpfad.

**Resolver:** Beliebige HTTP-Antwort ist höchstens ein Erreichbarkeitsindikator, kein Portal-/Auth-Nachweis. Definieren, welche bestehende lesende Antwort die richtige Gegenstelle erkennen lässt, ohne ein degradiertes Portal falsch auszusortieren. Kein mutierender Diagnoseaufruf.

**MECM-Detection:** `New-CMDetectionClauseRegistryKeyValue -Is64Bit`, Deployment-Type-Prozessarchitektur und tatsächliche SYSTEM-Registryansicht in einer Test-App prüfen. Die dokumentierte Parameterbezeichnung allein genügt nicht für eine Änderung; serialisierte Detection Rule und echte Erkennung müssen übereinstimmen.

**Weitere gezielte Prüfung:** Device-Auswahl der Boot-/DHCP-MAC gegen den jetzigen WDS-Vertrag verfolgen. Nicht ohne Nachweis eine neue Auswahlheuristik oder andere MAC an den ACK senden. Findet sich hier eine Abweichung, gemeinsamen Identitätsvertrag reparieren und Vektoren ergänzen; sonst als geprüft dokumentieren.

### A18 · Prüfwerkzeuge, Regressionstiefe und Lieferumfang nachziehen — P2, B

**Dateien:** `scripts/check.ps1`, `scripts/lib/check/runtime.ps1`, `gates-fast.ps1`, `registry.ps1`, `scripts/run-pester.ps1`, Tool-Lock, Pester-Suites, Offline-Bundle-Verträge.

**Befund:** `Get-CheckFiles` erfasst QA-Artefaktkopien. Ein allgemeiner Toolaufruf puffert Kindprozessausgaben; Livefortschritt muss am nützlichen Rand tatsächlich sichtbar bleiben. Mehrere Härtungstests pinnen nur die bisherige Syntax und belegen keinen Wiederanlauf.

**Ändern:** Eigene Ausgabeordner aus Quellscans ausschließen und die Traversierung früh beschneiden. Nicht bloß auf `git ls-files` umstellen, wenn dadurch unversionierte neue Quellen oder `VIRTUSPHERE_CHECK_ROOT`-Fixtures verloren gehen. Positive/negative/Zero-Match-Fälle und plattformabhängige Pfade behalten.

**Hinzufügen:** Funktionale Fehler-/Wiederanlaufprüfungen für A01–A17. Mocks akzeptieren nur echte Parameter und dokumentierte Datenformen. Shell-/Registry-/Netzwerk-/Storage-Adapter so schneiden, dass reine Entscheidungen separat testbar sind, ohne nur die Implementierung nochmals abzuschreiben. Logs und Fortschritt nicht in strukturierte Rückgaben mischen. Fortschrittsvertrag in `VirtuSphere.ProgressReporting.Tests.ps1` für neue kanonische Mehrfacheinheiten erweitern.

**Lieferumfang:** Jedes neue ausgelieferte Hilfsmodul muss in Server-/Clientinstaller, Staging, Hashprüfung, Versionsprüfung und Offline-Bundle vorkommen. Bestehende separate Loggingpakete erhalten. Pester und Analyzer aus `scripts/tool-lock.json` auflösen; keine zweite abweichende Versionsanweisung in der Doku. File-Size-Grenzen nicht mit beliebig erhöhten Allowances umgehen.

**Abnahme:** Artefaktkopie mit Syntaxfehler wird nicht als Quelle geprüft, neue echte Quelldatei schon; Fixture-Root funktioniert; fehlende echte Quellen sind Infrastrukturfehler; Live-RUN erscheint vor Ende eines langsamen Kindprozesses. Aktuelle Fast-Gates bestehen, erforderliche Integration-/Release-Gates werden nach tatsächlichem Diff ausgewählt. Die fünf manuellen Härtungsfälle bleiben ausdrücklich offen, bis reale Evidenz vorliegt.

## 6. Dokumentation und Portal-Hilfe: konkrete Überarbeitung

Dokumentation jeweils zusammen mit dem zugehörigen Verhalten ändern. Ein Fix darf nicht wochenlang eine falsche aktive Anleitung stehen lassen. Historische ADRs erhalten ein datiertes Amendment oder einen Verweis auf den ersetzenden ADR; die ursprüngliche Entscheidung nicht kommentarlos umschreiben. Diese Auditdatei bleibt als historische Planung erkennbar.

| Dokument/Quelle | Gefundene Lücke oder erforderliche Änderung | Zugehörige Pakete |
|---|---|---|
| `Powershell-MECM/README.md` | Tokenbehalten/-ersetzen; echte Blocker vs. ausstehender Startnachweis; Stop-/Upgrade-/Rollbackverhalten; Dependencies nicht pauschal best effort; Paketbereinigung und Contentstatus; keine Quelltextänderung für Clientadressen. | A01, A04, A10–A17 |
| `Powershell-MECM/clients/README.md` | Versprechen „nie halbe Daten“ und „idempotent“ nur mit neuer Implementierung; Bootstrap über Registry statt editierte Common-Datei; genaue Rename-/Reboot-Ausnahmen; Disk-Teilfehler; Detection und Wiederanlauf. Bestehenden realen Client-Paketswap weiterhin korrekt beschreiben. | A06–A09, A12, A16, A17 |
| `docs/operations/mecm-integration.md` | Chronologischer Erstinstallations-/Upgrade-/Recovery-Ablauf; fehlgeschlagener Report und unklare Ownership; 40-Sekunden-/Logbeweis ersetzen; Verteilung/SourceVersion; Versionsbereinigung; Netz-/Disk-Recovery; genaue Zuständigkeiten. Die spätere zweite Installationsbeschreibung mit dem frühen Runbook konsistent halten oder auf die autoritative Stelle verlinken. | alle |
| `docs/adr/ADR-0034-mecm-provenance-and-reconciliation.md` | Falsches CI_ID-Versprechen und unvollständige Aussage über verlorene Reports korrigieren; Intent/ACK/uncertain, Planformen, Vergleichssemantik, Ersatz-/Bereinigungspolitik entscheiden. Fixture-Verweis eindeutig auf `Docker/WebAPI/tests/fixtures/mecm-plan-vectors.json` beziehen. | A01–A05, A14 |
| `docs/adr/ADR-0029-powershell-integration-client-checks.md` | Neue Verhaltensregressionen und Liefermodule ergänzen; PS 5.1, getrennte Logging-/MAC-Pakete erhalten. Loggingvertragsänderung nur mit Versions-/Upgradeentscheidung. | A06, A11, A18 |
| `docs/adr/ADR-0043-mecm-rollout-hostname.md` | Journal-Replay an Revision/ResourceID; lokale Snapshotkonsistenz von serverseitiger Fence unterscheiden. Für spätere Phasentelemetrie aktuellen und historischen Rollout sauber trennen. | A04, A07, U03 |
| `docs/adr/ADR-0018-machine-report-channel-and-maintenance-worker.md` | Site-Health nur completed; unknown bei fehlender Site; optionale strukturierte Diagnosen und Retention nur additiv. Keine Lebenszykluswrites oder Portal-MECM-Probe. | A15, U01–U07 |
| `docs/adr/ADR-0019-e3-machine-api-retirement-candidates.md` | ACK nach vollständig veröffentlichten Daten erläutern; Retry/Fence erhalten; 5/5 nicht als Abschluss von hostname/staticip/disks bezeichnen. | A07, U03 |
| `docs/adr/ADR-0032-correlation-id.md` | Diagnoseheader weiter rein diagnostisch; Operations-ID, Rollout-ID und Prozesskorrelation als unterschiedliche Identitäten erklären. Kein Zusatztraffic nur wegen Logfehlern. | A04, A06, U07 |
| `docs/adr/ADR-0031-canonical-check-runner-lanes-and-exit-codes.md` | Nur wenn Quellscan-/Fortschrittsvertrag erweitert wird: Ausgabeausschluss und beobachtbare Kindprozesse dokumentieren. Öffentlichen Runner erhalten. | A18 |
| `docs/QA.md`, `docs/QUALITY-GATES.md`, `docs/TESTPLAN.md` | Schemaechte MECM-Fixtures, Crashmatrix und Windows-Testgrenzen; alte `Install-Module -MinimumVersion`-Anleitung gegen exakten Tool-Lock abgleichen. Statische Pins sind kein SYSTEM-Smoke. | alle, besonders A18 |
| `PRE-SHIP-CHECKLIST.md` | „Kein Datenträger online“ genauer unterscheiden: keine optionale Arbeit ist nicht automatisch Fehler. Neue Abnahmen für teilweise bearbeitete Disk, fehlende zweite NIC, Unicode-HTTP, verlorenen Membership-ACK und Upgradeabbruch. | A04, A08, A09, A11, A17 |
| `docs/INSTALLATION-ANLEITUNG.md`, `docs/DEPLOYMENT.md`, Root-`README.md` | Nur betroffene Zusammenfassungen, Logpfade, Installationslinks, Runtime-/MECM-Prerequisites aktualisieren. Details auf das Runbook verweisen, nicht erneut kopieren. | A11, A12, A16–A18 |
| `docs/adr/ADR-0020-deploy-catalog-mecm-owned-read-only.md`, ADR-0035 | Gegen neue Paketansichten/-bereinigung prüfen: Katalog bleibt MECM-owned; keine entfernte Desktop-/Token-API zurückbringen. Amendment nur bei echter Vertragsänderung. | A14, U05 |
| Paket-`config.json`-Beispiele, Script-Kommentarhilfe | Schlüsselalias, Versionspolitik, User/SYSTEM, Stop-on-error, Rebootcodes, Source-/Share-Trennung und neue optionale Einstellungen konsistent dokumentieren. | A13, A14, A16, A17 |

### Konkrete bestehende Hilfetexte

Die folgenden Keys sind in `Docker/WebAPI/lang/de/` belegt; die jeweilige EN-Datei immer synchron bearbeiten. Renderer unter `Docker/WebAPI/lib/help/` nur ändern, wenn Struktur, dynamische Werte oder Links hinzukommen.

| Hilfe/Keys | Problem und gewünschte Aussage |
|---|---|
| `help_settings.php`: `settings_token_p1` | „Nur Server-Heartbeats“ ist zu eng: Token gilt für die dafür vorgesehenen Servermeldungen einschließlich `reportRun`, nicht für Client-Phasen/ACK. IP-Freigabe bleibt zusätzlich erforderlich. |
| `help_settings.php`: `settings_token_p2` | Interaktive Rotation erst nach A10 als zuverlässig beschreiben. „Nur für Administratoren lesbar“ präzisieren: vorgesehene SYSTEM-/Admin-Principals und tatsächlich gesetzte ACL, keine falsche Exklusivitätsbehauptung. |
| `help_settings.php`: `settings_reporting_p1` | Drei Sync-Aufgaben melden started/completed; Site-Health meldet completed-only. Der aktuelle Satz behauptet Beginn und Ergebnis für alle vier. |
| `help_system_status.php`: `system_status_source_1` | Device-Sync legt nicht in jedem Fall neu an; Bindung, Tombstone und Konflikt können blockieren. Ursache mit dem passenden Ziel zur Behebung verbinden. |
| `help_system_status.php`: `system_status_source_3` | App/Collection/Deployment hängen von Paketoptionen und Bereitschaft ab; nicht aus jedem Ordner pauschal alle Objekte versprechen. |
| `help_system_status.php`: `mecmfolders_p1`, `mecmfolders_p2`, `mecmfolders_p3` | Ordnerstruktur hilft beim Finden, ist aber kein Ownershipbeweis. Versions-/Cmdlet-Prerequisite im tatsächlichen Test-MECM prüfen; manuell verschobene Objekte nicht automatisch als VirtuSphere-owned darstellen. |
| `help_system_status.php`: `clientphases_step2` | Rollout-Snapshot statt beliebigem aktuellem Sollnamen; Domainmitglied/bereits richtiger Name kann Rename und Reboot überspringen. |
| `help_system_status.php`: `clientphases_step3` | IP-/DNS-/Routingumstellung erklären. Das Clientskript ändert nicht selbst den ESXi-VLAN-/Portgruppenanschluss. Erreichbarkeit kann sich ändern. |
| `help_system_status.php`: `clientphases_p2`, `clientphases_p3` | Ausbleibende Abschlussmeldung beweist weder Erfolg noch Harmlosigkeit. Auch ohne explizites failed kann ein Prozess abgebrochen sein. „Bestätigung ausstehend“ als unbekannten Abschluss mit Log-/Diagnoseschritten erklären. |
| `help_system_status.php`: `clientphases_step4`, `clientphases_p4` | Optionale leere Diskarbeit von unvollständiger eigener Operation unterscheiden; Logpfade/Schema nur ändern, falls das jeweilige Modul tatsächlich betroffen ist. |
| `help_stack.php`: `stack_a3_p2`, `stack_a3_p4` und einschlägige MECM-/Clientantworten | Quelle, Registrykonfiguration, MECM-Bestand, Provenienz und beobachtete Telemetrie getrennt erklären; „alle Abbilder können nicht widersprüchlich sein“ nicht als technische Garantie verkaufen. |
| `help_packages.php`, `help_missions.php`, `help_deploy.php`, VM-Hinweise | Aktuelle Aussagen zu Entfernen/Übertragen/Versionen mit A04/A14 abgleichen. Portal-Löschen ist kein MECM-Objektlöschen; Vorschau ohne Liveabfrage als Portalsicht kennzeichnen. |

**Hilfen-Abnahme:** DE/EN- und Placeholder-Parität; echte Umlaute; keine Em-Dashes in neuer Portalprosa; dynamische Grenzen aus SSoT. `help_url()`, `settings_url()`, `log_category_url()` und andere vorhandene Deep-Link-Helper nutzen. Neue Sections in `lib/help_page.php` und zugehörigen Registries anmelden. Ursachen dürfen auch ohne Reparaturrecht sichtbar sein, Aktionen nur mit Zielberechtigung. Browserprüfung für sichtbare/erreichbare Hilfelinks und semantische Übereinstimmung mit Statuskarten ergänzen, wenn die Darstellung geändert wird.

### Arbeitsfolge für Doku und Script-Help

1. Pro Paket die konkrete bisherige Aussage mit Datei und Key/Funktion im Umsetzungsnachweis erfassen. Nach dem Fix die Aussage über das resultierende Verhalten formulieren; keine künftigen U-Features als vorhanden beschreiben.
2. Betriebsabläufe primär in `docs/operations/mecm-integration.md` pflegen. READMEs erklären Einstieg und verlinken Details. ADRs besitzen die Entscheidung und deren Grenzen. Script-Kommentarhilfe besitzt die tatsächlich verfügbaren Parameter und Exit-/Reboot-Bedeutungen. Portalhilfe erklärt die Bedienhandlung und führt zum passenden vorhandenen Ziel.
3. Insbesondere die Ausgabe am Ende von `install-VirtuSphere-MECM.ps1` prüfen: Sie fordert aktuell ebenfalls zur Bearbeitung von `$VsDefaultDnsApi` im Client-Common auf. Nach A17 genügt eine README-Korrektur nicht; Installer-Abschlussausgabe, Common-Dateikopf, Client-README und Runbook müssen gemeinsam auf den Registry-Bootstrapweg zeigen.
4. Beispiele für Erstinstallation, unveränderten Re-Run, Tokenrotation, Upgradeabbruch und Recovery mit der tatsächlichen Parameterauflösung abgleichen. Nur synthetische Werte verwenden; kein Transcript mit echten Tokens als Beleg speichern. `.PARAMETER`, `.EXAMPLE`, `.OUTPUTS` und Hinweise zu Rechten/Registryansicht dort ergänzen, wo sie für das betreffende Skript gelten. Keine erfundenen Parameter dokumentieren.
5. Eine kompakte Recovery-Tabelle im Runbook pflegen: Symptom → belegter Zustand → lesende Prüfung → erlaubte Wiederaufnahme → wann ungeklärt. Pflichtzeilen: verlorener Membership-ACK, Journal voll/beschädigt, Snapshot nicht veröffentlicht, fehlende zweite NIC, teilweise initialisierte Disk, halbe Client-App, veraltete Contentversion, ausstehender Startnachweis. Ein Retry darf nicht als universelle Reparatur empfohlen werden.
6. Zum Paketabschluss Querverweise und Textvorkommen der ersetzten Aussage suchen. Dazu gehören Code-Kommentare und Installertexte, nicht nur Markdown. Historische Auditbefunde als Historie erhalten; aktive Anleitungen müssen widerspruchsfrei sein. Sprachtests beweisen Parität, aber nicht inhaltliche Richtigkeit: Die oben genannten Sätze zusätzlich fachlich gegen den implementierten Ablauf lesen.

## 7. Portal-Erweiterungen für Admins und QoL

Diese Features bauen auf reparierten Skripten auf. Das Portal soll vorhandene Betriebsseiten ergänzen, keine zweite Parallelkonsole mit eigenen Zustandsregeln werden. Laufstatus, Inhaltserfolg, Aktualität und tatsächlicher MECM-Sitezustand getrennt halten. Unbekannte oder ältere Daten neutral anzeigen; der technische Rohwert bleibt in Transport/Persistenz unverändert.

| Paket | Optische Ergänzung und Platzierung | Nutzen | Datenlage / notwendige Vorarbeit |
|---|---|---|---|
| U01 · Handlungsbedarf, zuerst | Systemstatus: kompakte Liste mit Ursache, betroffener VM/Mission, letztem Nachweis und direktem passenden Link; Dashboard-KPI führt dorthin. | Admin sieht den nächsten sinnvollen Schritt statt nur einer roten Zeile. | Bestehende Integrations-/VM-Daten für ein begrenztes MVP. Vollständige Einzelfehler brauchen begrenzte strukturierte Ursachecodes und Ziel-IDs aus dem Sync; Freitext nicht rückwärts parsen. |
| U02 · MECM-Warteliste | VM-Liste/Filter: wartet seit, blockierender Schritt, letzte Prüfung, Übertragung/Details. | Hängende und lediglich noch nicht abgearbeitete VMs unterscheiden. | `updated` allein liefert keinen exakten Eintrittszeitpunkt. Eigenen autoritativen Queuezeitpunkt erst bei der passenden Zustandsänderung schreiben; `updated_at` nicht als Ersatz verwenden. |
| U03 · Clientverlauf je Rollout | Bestehender VM-Phasenabschnitt: aktueller Rollout, frühere Rollouts eingeklappt, Versuch und letzte Bestätigung. | Alte grüne Phasen nach Reset nicht als neuen Erfolg lesen. | Aktuelle Events werden nach VM/Phase zusammengefasst. Additive Revision-/Versuchszuordnung und Migration nötig; Altzeilen ohne Revision historisch/unzugeordnet belassen. ACK bleibt unabhängig. |
| U04 · Netzwerk Soll/Ist | VM-Details: je MAC eine Zeile für Soll, zuletzt gemessenen Iststand, Ergebnis und Zeitpunkt; Unterschiede mit Text und Symbol. | Fehlende zweite NIC, DNS-/Gatewayabweichung und ausstehende Rückmeldung sofort erkennen. | A07/A08 plus begrenzte strukturierte Beobachtung. Kein Live-Ist vortäuschen, wenn nur Sollwerte oder eine freie Phase-Detailmeldung vorliegen. |
| U05 · Paketbereitschaft | Paketdetail: Quelle → App/DT → aktuelle Contentversion → Verteilung → Deployment; je Schritt offen/erfolgreich/fehlgeschlagen/unbekannt. | Paket erscheint im Katalog, ist aber noch nicht installierbar: Ursache wird sichtbar. | A01/A12/A13. Katalog bleibt read-only. DP-Gruppenerfolg nur mit gruppengenauem Nachweis; sonst Aggregat klar benennen. |
| U06 · Bereinigungs- und Setupvorschau | Paketdetail zeigt geplante Altobjekte/Ersatz; Einstellungen bieten Registry-/Bootstrapvorlage mit Kopier-/Downloadfunktion. | Destruktive Folgen prüfen und Installation ohne Quelltextbearbeitung vorbereiten. | A10/A14/A17. Vorschau ist kein Remote-Löschauftrag. Setupvorlage ist Eingabehilfe, wirksamer Stand bleibt WindowsRegistry; Secrets nicht erneut aus dem Portal auslesbar machen. |
| U07 · Diagnose und Versionsdrift | Systemstatus: gemeldete Paket-/Manifestversion, letzte Beobachtung, lokale/Portal-Korrelationshinweise; begrenzter redigierter Diagnoseexport. | Gemischte Skriptstände und konkrete Störungen schneller eingrenzen. | Ein konstanter SCRIPT_VERSION-Wert beweist keinen Hashstand. Manifest-/Konfigurationsfingerprint ohne Secrets und mit eindeutigem Owner; History-/Retention-/Exportrechte definieren. Kein zusätzlicher Report allein wegen Logsinkfehlern. |

**Priorisierung:** U01 zuerst; anschließend U03 und U05; U02/U04 nach ihren Datenverträgen; U06/U07 nach stabiler Installation und Ownership. Keine anlasslose neue Datenbanktabelle je Statuskarte. Gemeinsam genutzte strukturierte Beobachtungen und Snapshothelper bevorzugen, aber nicht Fachzustände und Telemetrie verschmelzen.

**UI-Abnahme:** Bestehende Theme-/Badge-/Timestamp-/Sort-/Formhelper, RBAC, CSRF und Confirmvertrag verwenden. Jede CSS-Klasse registriert/styled; Tastatur, Bildschirmleser und enge Viewports prüfen. Insbesondere umbrechende Titel-/Aktionszeilen brauchen nachfolgenden Abstand. Screenshots nur im synthetischen `virtusphere-qa`-Stack durch das `visual`-Projekt; keine echten Daten. Baselines nur durch den vorgesehenen menschlich aufgerufenen Writer, nie als Reparatur eines Testfehlers automatisch ändern.

## 8. Gemeinsame Test- und Abnahmematrix

| Ebene | Verpflichtende Fälle | Was sie nicht beweist |
|---|---|---|
| Pure Pester-Vektoren | Plan-/Versions-/Statusmapping, Unicode/MAC/Case, Duplikate, Bounds, Snapshotvalidierung | Echtes MECM-Cmdlet- oder Storageverhalten |
| PowerShell 5.1 mit kontrollierter Registry/HTTP-Testgegenstelle | Markerfehler, Tokenresolver, JSON-Bytes, Logstreams, Journalcrashpunkte und Replay | Rechte und Richtlinien eines echten SYSTEM-Deployments |
| Schemaechte MECM-Adaptertests | Zulässige Parameter, echte Ausgabeproperties, absent vs. unknown, SourceVersion | Tatsächliche DP-Gruppenbereitschaft/Providerlatenz |
| PHP Unit/Integration bei API-/DB-Änderung | Vollständiger PS-Payload, atomare Validation, 404/409/Replay, Rolloutreset, Transaktion und bestehende Machine-Envelopes | Zustand auf einem externen MECM-Server |
| Windows SYSTEM-Wegwerf-VM | Clientkette, Detection, Netz/Disk-Teilabbruch, Reboot, User/SYSTEM und Registryansicht | Andere Hardware-/Treiber-/Sitekonfigurationen |
| MECM-Staging | Import/Re-Run, fremde Hand-Regel bleibt, verlorene Reportantwort, DP-Stände, halbe App, vorbereitete Versionsbereinigung | Freigabe für produktive Löschung |
| Installer-/Releaseprüfung | Neue Module vollständig, Staging/Hash/Version, Upgrade/Rollback, Air-Gap ohne Downloads | Erfolgreiche Produktionsinstallation |
| Portal/Doku bei geändertem Diff | Sprache/Bounds/Links/Statussemantik, PHP/JS, erforderliche E2E-Geometrie/Visuals | Aktualität nicht gemeldeter externer Daten |

Vor jedem End-to-End-Test Ausgangszustand und erwartete erlaubte Seiteneffekte festhalten. Nach zweitem identischem Lauf dürfen keine zusätzlichen Memberships, App-DTs, Deployments, Partitionen oder unnötigen Netzänderungen entstehen. Bei unbekanntem Ausgang muss der Test einen erklärten Blocker sehen, keinen stillen Erfolg.

Die konkreten Lane-/Gate-Namen dem aktuellen Runner entnehmen. Für den PowerShell-Einstieg am geprüften Stand:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/check.ps1 -Lane Fast -List
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/check.ps1 -Lane Fast -Gate powershell-syntax,powershell-tests -KeepArtifacts
```

Vor längeren Aufrufen einen live lesbaren Logpfad einrichten. Für geänderte PHP-/API-/Hilfepfade die zugehörigen PHP-, Sprach-, Drift-, Bounds-, Contract- und Integrationgates hinzufügen; am Ende die durch den Gesamt-Diff erforderlichen Lanes ausführen. Keine Qualitätsuntergrenze absenken, keine Quelltextpins löschen, ohne deren Sicherheitszweck durch bessere Tests abzudecken.

## 9. Umfangsinventar und Änderungszuordnung

Die folgende Liste verhindert, dass „sämtliche PowerShell-Skripte“ bei der Umsetzung nur als Device-Sync verstanden wird. Prüfung bedeutet nicht, dass jede Datei geändert werden muss.

| Produktdatei unter `Powershell-MECM/` | Arbeitspakete / Prüfschwerpunkt |
|---|---|
| `mecm/VirtuSphere-Common.ps1` | A01–A06, A13, A15, A17; zentrale Daten-/Transport-/Planadapter |
| `mecm/VirtuSphere-Logging.ps1` | A06/A18; gespiegelter Vertrag, keine unnötige Zusammenlegung |
| `mecm/VirtuSphere-ClientPackaging.ps1` | A07/A11/A12/A17/A18; Definitionen, Snapshotmodule, vollständiger Swap |
| `mecm/mecm_new-device-sync.ps1` | A02–A05/A15/A17; Ownership, Readfehler, Replay, Identität |
| `mecm/mecm_autoimporter.ps1` | A01/A13/A14; Verteilung, Quellen, Bereinigung |
| `mecm/mecm_Packages-TaskSeq-sync.ps1` | A03/A15/A17; vollständige Caches, Teilfehler, Unicode, Catalogschutz; bestehende Schutzschwellen erhalten |
| `mecm/mecm_site-health.ps1` | A15; exakte Site, unknown, completed-only |
| `install-VirtuSphere-MECM.ps1` | A10/A11/A16/A17; Konfigresolver, ACL, Prozessende, Aktivierung |
| `install-VirtuSphere-Clients.ps1` | A12/A16/A17; tatsächliche Reparatur, Dependencies, ContentShare |
| `clients/VirtuSphere-Client-Common.ps1` | A06/A07/A17; Rückgabestream, Bootstrap, Transport |
| `clients/VirtuSphere-Client-Logging.ps1` | A06; Streamkorrektur mit unverändert best-effort Dateisink |
| `clients/client_getinfo.ps1` | A07; vollständiger Snapshot und ACK |
| `clients/client_hostname.ps1` | A07/A16; Snapshotlesen, Domain-/No-op-/Rebootpfade |
| `clients/client_staticip.ps1` | A08; Sollvollständigkeit, IPv4-Isolation, Nachweis |
| `clients/Set-VMDisksOnline.ps1` | A09; sichere Teiloperation und Wiederanlauf |
| `Package_Vorlage/install.ps1` | A13/A16; Contentversion, User/SYSTEM, Fehler/Reboot |

Werkzeugumfang: `scripts/check.ps1`, `check-compose-hardening.ps1`, `run-pester.ps1`, `test-guards.ps1`, `update-visual-baselines.ps1` und `scripts/lib/check/{gates-fast,gates-integration,gates-release,qa-identity,registry,runtime}.ps1`. A18 besitzt nur die tatsächlich betroffenen Runnerstellen. Compose-/QA-Isolation und Baselinewriter bleiben geschützt; kein pauschaler Umbau nur wegen ihrer Dateiendung.

Testumfang: `tests/powershell/VirtuSphere.{Autoimporter,CheckRunner,Common,ErrorPaths,Haertung2026-08,Logging,ProgressReporting,QaWorkerIsolation,ReleaseBundleContract,RolloutIdentity,RunReport}.Tests.ps1` sowie `PSScriptAnalyzerSettings.psd1`. Neue fachlich getrennte Suites sind möglich; vorhandene Contracttests und Schutzabsichten erhalten.

## 10. Quellen und gezielt verbleibende Unsicherheiten

Die Empfehlungen kombinieren den geprüften Projektcode mit Microsoft-Primärquellen. Online-Dokumentation beschreibt nicht automatisch die vor Ort installierte ConfigurationManager-Modulversion; Staging deshalb mit Versionsnachweis ausführen. Externe Quellen sind Recherchematerial, keine Runtimeabhängigkeit für das Air-Gap-Produkt.

- **Q1:** [Microsoft: Get-CMDistributionStatus](https://learn.microsoft.com/en-us/powershell/module/configurationmanager/get-cmdistributionstatus?view=sccm-ps). Belegt die zulässigen Parameter, PackageID und das Beispiel mit NumberSuccess; Grundlage A01.
- **Q2:** [Microsoft: SMS_ObjectContentExtraInfo](https://learn.microsoft.com/en-us/intune/configmgr/develop/reference/core/servers/console/sms_objectcontentextrainfo-server-wmi-class). Unabhängige WMI-Schemabeschreibung derselben Ausgabedaten, einschließlich SourceVersion und Zählern; Grundlage A01/A13.
- **Q3:** [Microsoft: Remove-NetIPAddress](https://learn.microsoft.com/en-us/powershell/module/nettcpip/remove-netipaddress?view=windowsserver2025-ps). Selektoren einschließlich AddressFamily und Wirkung auf passende Adressen; Grundlage A08.
- **Q4:** [Microsoft: New-NetIPAddress](https://learn.microsoft.com/en-us/powershell/module/nettcpip/new-netipaddress?view=windowsserver2025-ps). DHCP-/DAD-Verhalten bei neuer statischer Adresse; Grundlage der echten Windows-Abnahme A08.
- **Q5:** [Microsoft: Invoke-RestMethod, Windows PowerShell 5.1](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.utility/invoke-restmethod?view=powershell-5.1) und [PowerShell 7.5](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.utility/invoke-restmethod?view=powershell-7.5). Versionsabhängige Encodingregeln rechtfertigen den Byte-Test in A17. Der erneute Abruf der 5.1-Seite war in der Planungssitzung zeitweise nicht verfügbar; tatsächliche Bytes sind die entscheidende Abnahme.
- **Q6:** [Microsoft: New-CMDetectionClauseRegistryKeyValue](https://learn.microsoft.com/en-us/powershell/module/configurationmanager/new-cmdetectionclauseregistrykeyvalue?view=sccm-ps) und [Add-CMScriptDeploymentType](https://learn.microsoft.com/en-us/powershell/module/configurationmanager/add-cmscriptdeploymenttype?view=sccm-ps). Ausgangspunkt zur Architektur-/Detectionprüfung; ohne live geprüfte serialisierte Rule keine Behauptung, der bestehende Is64Bit-Wert sei falsch.
- **Q7:** [Microsoft PowerShell-5.1-Quellendokumentation: Write-Output](https://github.com/MicrosoftDocs/PowerShell-Docs/blob/main/reference/5.1/Microsoft.PowerShell.Utility/Write-Output.md) und [Write-Host](https://github.com/MicrosoftDocs/PowerShell-Docs/blob/main/reference/5.1/Microsoft.PowerShell.Utility/Write-Host.md). Bestätigen die Trennung von Success- und Information-Stream für A06. Die Microsoft-Learn-Seite zu Output Streams in der 5.1-Ansicht war bei der Ergänzung nicht abrufbar; deshalb die versionsspezifischen offiziellen Dokumentationsquellen. Q1/Q2 wurden für diese Ergänzung erneut abgeglichen. Der ANSI-Redigierungsbefund beruht auf dem lokalen Code und der synthetischen Reproduktion, nicht auf einer externen Behauptung.

Die lokalen Entscheidungen sind umgesetzt: Journal und `uncertain` bleiben fail-closed, alte Autoimporter-Objekte brauchen einen exakten Eigentumsmarker, Versionen sind ausschließlich kanonische punktgetrennte Dezimalzahlen, widersprüchliche Gatewayziele blockieren, Bootstrapwerte kommen vor `getinfo` aus dem Installerpaket, und HTTPS verwendet normale PKI-Prüfung mit eng begrenzter Pin-Ausnahme. Die Boot-/DHCP-MAC-Prüfung ergab keinen Änderungsbedarf: Der Client probiert normalisierte IP-aktive Adapter und verwendet für Bericht und ACK exakt die MAC, deren Datenbankabfrage die VM aufgelöst hat; der Serververtrag akzeptiert jede bekannte VM-Schnittstellen-MAC. Offen bleiben ausschließlich die unten konkret benannten Abnahmen an echtem MECM, Windows/SYSTEM, Netzwerk und Wegwerfdatenträgern.

## 11. Abschlusskriterien und Übergabeprotokoll

- Alle Pflichtpakete sind mit Beleg abgeschlossen oder als konkrete externe Abnahme mit reproduzierbarem Testauftrag ausgewiesen. Verdachtsfälle haben Ergebnis und Begründung, keine stillschweigende Umsetzung.
- Keine verlorene bestätigte Membership-Meldung durch normales Retry; unklare Urheberschaft blockiert statt automatisch zu adoptieren. Fremde Hand-Regeln überleben.
- Kein Client-Erfolgsmarker bei erforderlicher unvollständiger Arbeit. Netz- und Disk-Wiederholungen ändern keine fremden Ressourcen.
- Paketbereitstellung verwendet echte aktuelle MECM-Evidenz. Bereinigung entfernt keine Objekte nur wegen eines anderen Namenssuffixes.
- Installer bewahrt oder ersetzt Konfiguration entsprechend der tatsächlichen Eingabe und hinterlässt nach Aktivierungsfehlern einen diagnostizierbaren konsistenten Stand.
- Dokumentation, Script-Help und DE/EN-Portalhilfe beschreiben das implementierte Verhalten. Keine neue Hilfe behauptet „fertig“, wenn nur ein Startreport oder alter Snapshot vorliegt.
- Vollständige Liefermodule, passende bestandene Gates und offene reale Testumgebungsnachweise sind dokumentiert. Optionales U-Backlog wird separat übergeben.

Für jedes Paket ergänzen:

| Paket | Status | Umsetzung/Entscheidung | Tests und Artefakte | Offene externe Abnahme |
|---|---|---|---|---|
| A01 | lokal verifiziert / externe Abnahme offen | Distribution-Adapter nutzt eindeutiges Application-Objekt, `-InputObject` und schemagetreue Zähler; ungültige Identität/Schemata bleiben `unknown`. ADR-0034 und Runbook angepasst. | Schema-, Identitäts- und SourceVersion-Vektoren im vollständigen Fast-Lauf und in der vollständigen PHPUnit-Suite grün. | Echte MECM-Ausgabe, Summarization und später A13-SourceVersion in Staging prüfen. |
| A02 | lokal verifiziert / externe Abnahme offen | Removes übernehmen ID, Namen und Typ aus der autoritativen Provenienz; vollständiger Plan wird vor Remote-Write validiert. PHP-/PS-Plan und gemeinsame Fixture ergänzt. | PHP-Unit, gemeinsame Fixture und Pester im vollständigen Fast-/Integration-Abschluss grün. | Gemischter Add/Remove gegen Staging-Endpoint und MECM. |
| A03 | lokal verifiziert / externe Abnahme offen | Direct-Membership-Leser ist dreiwertig; Providerfehler und mehrdeutige Collectionnamen blockieren Mutation, Provenienzrückzug und Registrierung. | Pester present/absent/unknown und statische Reihenfolge grün. | Timeout/Access denied und verschwindende Collection am echten Provider. |
| A04 | lokal verifiziert / externe Abnahme offen | Versioniertes lokales Journal mit stabilem Operationsschlüssel, Intent vor Write, `remote_confirmed`-Replay, Prozesslock, Bounds, atomarer Ersetzung und Quarantäne; Installer-Lieferliste, ADR, Runbook und Hilfe angepasst. | Neue Journal-Pester-Suite; reparierte Guardabweichungen und Gesamtzwischenlauf grün. | Uncertain-Auflösung, Crashpunkte, 404/409 und zwei reale Instanzen in MECM-Staging abnehmen. |
| A05 | lokal verifiziert / externe Abnahme offen | Ordinaler Vergleich, Desired-Deduplizierung, Typkonflikt- und Namensmehrdeutigkeitsblocker; gemeinsame Case-/Duplikatvektoren. | Gemeinsame PHP-/Pester-Fixtures im vollständigen Fast-/Integration-Abschluss grün. | Unicode-/Whitespace-Namen und echte doppelte Collectionnamen im Staging. |
| A06 | lokal verifiziert / externe Abnahme offen | Clientlogger verunreinigt Stream 1 nicht; beide Loggingmodule entfernen ANSI/OSC vor Redigierung und begrenzen danach UTF-8-sicher. README, Runbook, Hilfe und ADR-0029 angepasst. | Gemeinsame synthetische Secret-/Terminalvektoren und Resolver-Einzelstringtests grün. | Nicht schreibbarer Sink unter echtem SYSTEM bleibt Betriebsabnahme. |
| A07 | lokal verifiziert / externe Abnahme offen | Getinfo validiert Antwort/Interfaces und veröffentlicht einen versionierten Registry-Snapshot per finalem Zeiger; Folgephasen lesen nur `published` plus `SetupState=complete`. Doku angepasst. | Snapshot-/Leserregressionen und ausgewähltes Gesamtgate grün. | Registryfehler an jeder Grenze, parallele Phase und 32/64-Bit-Sicht unter SYSTEM. |
| A08 | lokal verifiziert / externe Abnahme offen | Vollständiger Netzwerkplan wird vor dem ersten Write validiert; MAC-Auflösung, Adapterzustand, Namen, IPv4, Gateway und DNS sind fail-closed. Nur exakt in diesem Lauf bzw. zuvor verwaltete IPv4-Werte werden geändert oder zurückgerollt; IPv6 und fremde manuelle IPv4-Werte bleiben erhalten. README, Runbook und ADR-0029 angepasst. | Pure Planvektoren, statische Storage-/NetTCPIP-Vertragsprüfungen und ausgewähltes Gesamtgate: 531/531 bestanden, Analyzer ohne Befund. | Isolierte Windows-VM mit mehreren echten Adaptern, DAD-/Treiberfehlern und SYSTEM-Registrysicht. |
| A09 | lokal verifiziert / externe Abnahme offen | Versioniertes Registry-Journal schreibt stabile Identität und Intent vor dem ersten RAW-Write. Offene Operationen werden unabhängig von Disknummer/Offlinezustand eindeutig aufgelöst und anhand realer Disk-, GPT-Partition- und NTFS-Volumezustände fortgesetzt. Unbekannte online-RAW-, fremde, Boot/System-, Read-only-, Cluster-, größenlose oder mehrdeutige Platten blockieren fail-closed; GPT/MBR-Bestand wird nie formatiert. README, Runbook, Hilfe, Checkliste und ADR-0029 angepasst. | Pure Identitätsvektoren und A09-Vertragspins; `[2/2] pass`, 539/539 Pester, Coverage 82,6 %, Analyzer ohne Befund. PHP-Lint, DE/EN-Parität, Doku-Hygiene und -Semantik ebenfalls grün. | Abbruch nach Online/Initialize/Partition/Format, Reboot/Renumbering, SYSTEM-Registry, Lock und Markerfehler ausschließlich mit Wegwerfdatenträgern. |
| A10 | lokal verifiziert / externe Abnahme offen | Ein zentraler Resolver bestimmt Wert und Herkunft; interaktive Rotation bleibt erhalten, interaktiv leer behält, explizit leer löscht Token/Provider. Doku angepasst. | Installer-AST-/Resolverregressionen grün. | Echter interaktiver Re-Run und anschließender Report mit rotiertem Token. |
| A11 | lokal verifiziert / externe Abnahme offen | Globaler Mutex, exakter reparse-freier Installationspfad und ein gemeinsamer Rollbackkontext sichern vor dem ersten Write alle Registrywerte samt Typ und ACL sowie XML/Laufzustand der vier Tasks. Der vollständige Altdateisatz bleibt bis nach Abschlussmarker erhalten; Rollback quiesziert neue Prozesse und restauriert Dateien, Registry und zuletzt Tasks. Alte laufende Tasks starten nur nach vollständig belegtem Rollback. README, Runbook und Portalhilfe angepasst. | Syntax 41/41, 544/544 Pester, Coverage 82,6 %, Analyzer ohne Befund. | Echte Datei-/Registry-/Task-/Prozesssperren und Abbrüche an jeder Transaktionsgrenze auf einem MECM-Stagingserver. |
| A12 | lokal verifiziert / externe Abnahme offen | Datentabelle und Graph werden vor Writes validiert. Der Clientinstaller vergleicht das vollständige lokale/UNC-Manifest, verlangt Marker oder engen Legacy-Ordnernachweis und prüft genau einen DT samt Detection, System-/Rebootvertrag, Standard-Returncodes und tatsächlichem Dependency-Ziel. Fehlende eigene Teile werden ergänzt; Fremd-/Driftdefinitionen bleiben unverändert und blockieren. README und Runbook angepasst. | Syntax 41/41, 549/549 Pester, Coverage 83,0 %, Analyzer ohne Befund; nach A13 im Gesamtzwischenlauf weiterhin grün. | Halbe Legacy-App, serialisiertes DT-/Detection-Schema, leere/fremde Dependency-Gruppe und SYSTEM-Registryansicht im MECM-Staging. |
| A13 | lokal verifiziert / externe Abnahme offen | Per-Paket-SHA-256-Manifest und dauerhaftes Registry-Tracking trennen `intent`, `pending` und `complete`. Intent samt SourceVersion-Baseline liegt vor Start/Update; ein Crash wird nicht blind redistributiert. Nur eine einheitliche, neuere, vollständig erfolgreiche SourceVersion bestätigt das Manifest. Contentpflege ist von der eigenen Collection unabhängig; mehrdeutige App/DT-Identität blockiert. Runbook und Portalhilfe angepasst. | Syntax 41/41, 555/555 Pester, Coverage 81,1 %, Analyzer ohne Befund. | Echte SourceVersion-Inkremente, Summarization-Latenz, kein DP, fehlgeschlagene Verteilung, UNC-Race und Intent-Abbruch im MECM-Staging. |
| A14 | lokal verifiziert / externe Abnahme offen | Normale Importläufe behalten Altobjekte. Kanonische punktgetrennte Dezimalversionen werden ohne Integerüberlauf ordinal verglichen; freie/mehrdeutige Versionen blockieren. Ein Bereinigungsplan verlangt vollständigen Quell- und Referenzscan, exakte Marker, referenzfreie Kandidaten und einen eindeutig eigenen, vollständig verteilten Ersatz. Planhash und Bereitschaft werden unmittelbar vor dem ersten freigegebenen Einzelschritt erneut geprüft; Teilfehler stoppen den Rest mit sichtbarem `[n/total]`. ADR-0034, Runbook und Hilfe angepasst. | Version-/Eigentums-/Referenz-/Stale-Plan-/Teilfehlervektoren im Abschlusszwischenlauf grün; `[2/2] pass`, 569/569 Pester, Coverage 80,5 %, Analyzer ohne Befund. | Trockenen Plan gegen echte MECM-Referenzen prüfen; eine ausdrücklich freigegebene Altversion im Staging löschen und Teilfehler beobachten. |
| A15 | lokal verifiziert / externe Abnahme offen | Site-Health löst ausschließlich den konfigurierten Sitecode auf; fehlende, fremde, mehrdeutige oder unlesbare Werte sind `unknown`. Alle vier Laufzeiten werden vor dem Int32-Cast auf den zulässigen Bereich begrenzt; Device-Sync setzt den Fehlerzähler erst nach vollständig erfolgreichem Portal-/MECM-Abschnitt zurück. ADR-0018, Runbook und Hilfe angepasst. | Site-/Provider-/Fehlerzyklus-/Laufzeitvektoren im Abschlusszwischenlauf grün; `[2/2] pass`, 569/569 Pester. | Reale Providerrechte, drei aufeinanderfolgende Fehlerzyklen und Erholung gegen den konfigurierten Sitecode. |
| A16 | lokal verifiziert / externe Abnahme offen | Paketwrapper nutzt inhaltsgebundene Schrittmarker, getrennte User-/SYSTEM-Logs, konservative Stop/Continue- und Rebootcodes und setzt Detection erst nach Abschluss. Beide Installer prüfen breite Schreibrechte über Well-Known-SIDs statt lokalisierter Namen; der Server-Registrykey entfernt fremde explizite Regeln und erlaubt nur SYSTEM und Administratoren. ADR-0029, READMEs und Runbook angepasst. | Wrapper-, Installer-, SID-/ACL- und Progressregressionen im Abschlusszwischenlauf grün; `[2/2] pass`, 569/569 Pester. | User/SYSTEM, deutsche/englische Windows-ACLs, Markerfehler und Rebootcodes auf Wegwerfhosts prüfen. |
| A17 | lokal verifiziert / externe Abnahme offen | Bootstrap wird vor der ersten Auflösung aus dem installererzeugten Manifest in fehlende Registrywerte übernommen, ohne vorhandene Werte oder Clientquellen umzuschreiben. JSON wird in PS 5.1 explizit als UTF-8-Bytes mit Charset gesendet; Fehlerenvelopes werden bevorzugt und begrenzt geloggt. HTTPS nutzt normale PKI-Prüfung und nur bei Zertifikatsfehlern einen exakten SHA-1-Pin; ohne Pin gibt es keinen Callback. Der Resolver akzeptiert nur das VirtuSphere-Healthschema. Die Boot-/DHCP-MAC verfolgt jede normalisierte IP-aktive NIC und verwendet exakt die erfolgreiche Treffer-MAC für Bericht und ACK; keine neue Heuristik erforderlich. ADR-0029, READMEs und Runbook angepasst. | Bootstrap-, Unicode-Byte-, ErrorDetails-, TLS-/Resolver-, Detection-/Manifest- und MAC-Vertragstests im Abschlusszwischenlauf grün; `[2/2] pass`, 569/569 Pester. | Lokaler HTTP-/HTTPS-Empfänger, PKI-/Pin-Fehlerfälle, Installerbootstrap und Detection unter echtem SYSTEM in der richtigen Registryansicht. |
| A18 | lokal verifiziert / externe Abnahme offen | Quellscan beschneidet Artefaktordner früh und behält echte unversionierte Quellen; Pester-Kindausgabe wird live gestreamt und zugleich als Gate-Artefakt gehalten. Neue Mehrfacheinheiten besitzen den verbindlichen Fortschrittsvertrag. Der Durable-Runner-Payload ist über `.gitattributes` vollständig auf LF gepinnt, damit sein geschlossenes `SHA256SUMS`-Manifest auch nach einem Windows-Checkout verifizierbar bleibt. | Fast `[31/31] pass`; diffbezogene Integration `[8/8] pass` einschließlich vollständiger PHPUnit- und Chromium-Suite, Nulltoleranz-Baselines beider Themes und Guard-Harness; Secret-Scan ohne Fund; Offline-Bundle in einer bytegenauen, isolierten und sauberen Kopie des uncommittierten Stands gebaut und offline verifiziert. Aktueller Schlusslauf: `[6/6] pass`, PowerShell 570/570, Coverage 80,5 %, Analyzer ohne Befund. | Die fünf im Härtungsregister ausgewiesenen Handproben sowie die je Paket benannten echten MECM-/Windows-/SYSTEM-Abnahmen bleiben bis zu realer Evidenz offen. |
| U01–U07 | Folgeumfang | Nach Datenverträgen separat umsetzen. | Keine UI-Implementierung in dieser Planungssitzung. | Umfang bei Beginn der jeweiligen Folgearbeit konkretisieren. |

Empfohlener Starttext für die neue Session:

> Setze `docs/audits/2026-09-07-powershell-mecm-hardening-plan.md` mit SOL High um. Beginne mit den Pflichtpaketen A01–A18 in der angegebenen Abhängigkeit, prüfe die Befunde am aktuellen Stand und aktualisiere Dokumentation sowie vorhandene DE/EN-Hilfen im jeweiligen Paket. Halte Machine-API, Ownership, Registry-SSoT und PS-5.1-Verträge ein. Führe keine produktiven MECM-, Netzwerk-, Disk- oder Installeroperationen aus. Dokumentiere echte externe Abnahmen getrennt von lokalen Tests. U01–U07 bleiben zunächst ein gesondertes Folgebacklog. Berichte belegte Fertigstellung und offene Punkte je Paket.

### Einheitlicher Paketnachweis für die Folgesession

Für jedes A-Paket eine eigene Zeile statt der Sammelzeile führen. Zulässige Angaben unterscheiden: `geplant`, `in Arbeit`, `lokal verifiziert / externe Abnahme offen`, `abgenommen`, `belegt nicht erforderlich` (nur für echte Verifikationsanteile). Eine fehlende Testumgebung ist keine bestandene Abnahme. Zu jedem abgeschlossenen Paket festhalten:

- **Was und warum:** Ausgangsfehler, auslösender Fall, neues Verhalten und bewusst erhaltene Vertragsgrenze.
- **Geändert / entfernt / hinzugefügt:** Tatsächliche Dateien und fachliche Owner; entfernte Fallbacks/Heuristiken ausdrücklich nennen. Neue Schema-/Versionswerte und Migrationen auf ihre SSoT verlinken.
- **Regression:** Testname, vorher beobachteter Fehler, Ergebnis nach Änderung, Runnerbefehl und dauerhaft nutzbarer Artefaktverweis. Ein ersetzter Mock muss begründen, warum seine neue Form dem echten API entspricht.
- **Doku und Help:** Aktualisierte Abschnitte/Keys und Kommentarhilfe oder ein konkreter Grund, warum die betreffende Darstellung nicht betroffen ist. Kein pauschales „Doku geprüft“.
- **Wiederanlauf und Lieferung:** Verhalten bei Teilabbruch, Altdaten, Re-Run und gemischtem Paketstand; vollständige Manifest-/Installerabdeckung neuer Module.
- **Offene Evidenz:** Exakte externe Testvoraussetzung, synthetischer Ausgangszustand, Schritte, erwarteter Nachweis und erlaubte Seiteneffekte. Keine produktive Freigabe aus einem lokalen Pester-Erfolg ableiten.

Die Planerstellung verlangt aktuell keine Rückfrage. Für die Umsetzung gelten die konservativen Ausgangsregeln dieses Dokuments. Sollte eine notwendige Produktentscheidung darüber hinausgehen, etwa automatische Adoption ungeklärter MECM-Regeln oder eine neue Löschpolitik für Altbestand, zuerst die konkrete Entscheidung mit Alternativen und Folgen vorlegen; unabhängige Pflichtreparaturen weiterführen.
