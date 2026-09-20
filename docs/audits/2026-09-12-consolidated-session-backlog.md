# Gemeinsamer konsolidierter Abarbeitungsplan

Stand: 14.09.2026, fortgeschrieben nach Powercycle-Umsetzung und vollständiger Fast-Bestandsaufnahme. Der aktuelle Auftrag integriert deren Ergebnis in Ziel, Etappen und Restumfang; er startet keine weitere Produktimplementierung, Container-Neuerstellung, Veröffentlichung oder Standortinstallation. Statusangaben beruhen auf den verlinkten Nachweisen und sind keine neue Laufzeitabnahme. Der Nutzer verlangt für alle Abläufe denselben strengen Qualitätsmaßstab; Prioritäten bestimmen die Reihenfolge. Aktuell arbeitet ein Admin allein. Die elf ausgewählten Funktionen und der Powercycle-Strang bleiben vollständig im Umfang.

## Quellen und Umfang

| Quelle | Session | Übernommener Umfang |
|---|---|---|
| S1 | [MECM-Aufgabenstatus korrekt anzeigen](codex://threads/01a08fe0-03c1-7f10-add3-274cccfeadf1) | Autoimporter, lokaler CIM-Zugriff, Offline-DPs, Versionen/Altanwendungen, getrennte Statusanzeigen, Veröffentlichung und Produktivnachweis |
| S2 | [Codebase und Docker optimieren](codex://threads/01a04d27-a5b8-7fc0-82f6-8be82970feb0) | Alle vorgeschlagenen Code-/Docker-/Performanceoptimierungen; damalige Umsetzung wurde vor Beginn gestoppt |
| S3 | [Admin-Portal gezielt erweitern](codex://threads/01a082e0-1ac5-7033-bc41-eb2b6829282e) | Genau die elf ausgewählten Funktionen F01 bis F11 einschließlich Planreview |
| S4 | [Orchestrate VirtuSphere audit](codex://threads/01a082bc-4ba5-7471-8ccb-73f2f99e411e) | Restabnahmen nach U01 bis U17 und der späteren gemeinsamen QA; maßgeblich ist die letzte Fortschreibung, nicht ein früheres Zwischenfazit |
| S5 | [Powercycle-Detailplan](2026-09-14-powercycle-sequential-plan.md) und [QA-Abschlussbericht](../../qa-artifacts/powercycle-sequential/sol-medium/report.md) | Sequenzieller Zyklus je VM, lokale Ablauf-/Identitätsnachweise, direkte PC03-Restkorrektur, getrennte Fast-Blocker sowie spätere ESXi-/Releaseabnahme |

Fachliche Detailowner bleiben der [Admin-Funktionsplan](2026-09-08-admin-workflow-feature-plan.md), das [Auditregister](2026-09-08-system-chain-audit-register.md), der [U13-Messplan](2026-09-10-u13-measurement-plan.md) und der [gemeinsame QA-Plan](2026-09-10-u13-u14-qa-plan.md). Dieses Dokument bündelt Reihenfolge, Restumfang und Abschlusskriterien; es erfindet keine zweite technische Vertragsquelle.

Dieses Dokument besitzt die gemeinsame Modell-/Ausführungsregel, die QoL-Anforderungen QL01 bis QL05, die zusätzlichen Bedienpakete UX01 bis UX06 sowie den paketübergreifenden Abschlussvertrag. UX01/UX02 betreffen Blocker und Statuskarten; die zusätzlich angenommenen UX03 bis UX06 betreffen Arbeitskontext, ungespeicherte Eingaben, wirksame Einstellungen und Aktionsfolgen. Fachliche Details bleiben beim jeweiligen Detailplan und technischen Owner; aktuelle Ergebnisse beim Auditregister. Ein überholter Befund wird dort ausdrücklich als fortgeschrieben markiert. Referenzen nennen Dokument plus ID, etwa `Adminplan:R10` oder `Gesamtplan:R01`, damit gleichlautende IDs keine Aufgaben vermischen. Der ursprüngliche [Auditplan](2026-09-08-system-chain-audit-plan.md) dient der gezielten Fortsetzung, nicht dem erneuten Start aller abgeschlossenen Auditpakete.

## Modell- und Ausführungsvertrag

Die Modellnamen sind `gpt-5.6-terra`, `gpt-5.6-sol` und `gpt-6-astra`. Die Zuordnung ist eine projektspezifische Arbeitsentscheidung auf Basis der [offiziellen Modellübersicht](https://developers.openai.com/api/docs/models/compare), am 12.09.2026 geöffnet, und der hier verfügbaren Modelleinstellungen. Sie verspricht keine feste Tokenersparnis und überträgt API-Preise nicht auf Codex-Kontingente. Markdown ändert kein laufendes Modell; der jeweilige Auftrag wird mit der zugeordneten Einstellung gestartet oder ausdrücklich an einen passenden Agenten übergeben.

| Modell / Effort | Konkreter Auftrag | Erwartetes Ergebnis und Grenze |
|---|---|---|
| Terra / Low | Begrenztes Quellen-/Linkinventar, Artefaktzusammenfassung, Dokumentationsprüfungen und redaktionelle Nachführung nach entschiedener Fachlogik | Abweichungen mit Datei/Zeile, Ergebnisstatus und fehlendem Nachweis. Keine eigenständige Entscheidung über Schreib-, Sicherheits- oder Recoveryverträge. |
| Terra / Medium | Eng begrenzte Umsetzung vorhandener UI-Muster, etwa F04; mechanische Planpflege | Kleiner vollständiger Diff samt gezielter Abnahme, fachliche Gegenprüfung durch Sol. Ungeklärte Semantik mit konkretem Gegenbeispiel an Sol geben. |
| Sol / Medium | Hauptkoordination, ausführbarer Paketauftrag, exklusiver QA-/Performancebetrieb, Fortschrittsbeobachtung, Ergebnisregister; sämtliche Commit-/Pushprüfungen und entsprechende Gitoperationen bei bestehender einschlägiger Autorisierung | Vollständiger Lauf-/Gitnachweis am tatsächlichen Stand. Lang laufende Tools selbst halten; keinen Agenten nur zum Warten einsetzen. Veröffentlichungsfreigabe aus dem bestehenden Auftrag prüfen. |
| Sol / High | Fachliche Implementierung, Fehleranalyse, Messdesign, Migration-/Upgradeplanung und Review der Terra-Arbeit | Ursache, Owneränderung, Gegenbeispiele und passende Nachweise. Schwierige neue Vertragsentscheidungen gezielt zur unabhängigen Gegenprüfung vorbereiten. |
| Astra / High | Zeitlich und fachlich abgegrenzte Vertragsentscheidung oder Gegenprüfung: atomare Writes, Identität, externe Teilwirkung, Recovery und komplexe Messkausalität | Konkrete Befunde oder begründetes Ergebnis anhand Originalcode und ausgewählter Evidenz; anschließend Rückgabe an Sol. Kein allgemeiner erneuter Repositoryreview. |

**Verbindliche Nutzergrenze:** Astra führt keine Commit-/Pushprüfung, Stagingkontrolle, Gitoperation, CI-/Gateausführung oder Fortschritts-/Logbeobachtung für die Veröffentlichung durch. Es wird dafür auch nicht als Hauptagent gehalten, der nur Sol-Unteragenten überwacht. Alle diese Schritte laufen unter Sol Medium, mit Terra Low für klar begrenzte Vorarbeit. Neue schwierige Produktbefunde werden als eigenes fachliches Paket aus der Veröffentlichung herausgelöst; nur dessen konkrete Fachfrage darf bei Bedarf vor einer neuen Übergabe an Astra gehen. Ein bereits ausreichender Fachreview wird vor Commit nicht allein aus Routine wiederholt.

Für diese Planung bleibt auch außerhalb der Veröffentlichung die gesamte QA-/Benchmarkausführung und laufende Fortschritts-/Logbeobachtung bei Sol. Astra liest nur die zur konkreten Fachgegenprüfung nötigen Originalquellen und ausgewählten Belege; es betreibt keine routinemäßigen QA-Läufe oder Wartephasen.

Astra prüft die in den Paketkarten benannten neuen kritischen Verträge unabhängig vor deren Abnahme; QA führt danach Sol aus. Für übrige Pakete übernimmt Sol die fachliche Gegenprüfung, auch bei gleichem eigenen Implementierungsmodell in einem getrennten, begrenzten Reviewauftrag. Die fachliche Strenge und die erforderlichen Tests sind bei allen Modellen gleich. XHigh ist erst nach einem dokumentierten ungelösten Gegenbeispiel zulässig; Max/Ultra sind keine Standardstufen. Ist ein vorgesehenes Modell nicht verfügbar, benennt Sol die Lücke und organisiert einen geeigneten Ersatz; ein fehlender Review gilt nicht als bestanden.

Übergaben enthalten nur Paket-ID, Ziel, abgegrenzte Dateien, erforderliche Quellen, Quellmanifest, Entscheidungen, offene Gegenbeispiele und Artefaktlinks. Rohlogs bleiben auf Platte. Niemand setzt fremde Änderungen zurück. Ein Schreibowner je gemeinsamer Datei; höchstens ein aktiver Owner am QA-Stack. Unabhängige Quellen-/Doku-/Implementierungsarbeit darf parallel laufen, Benchmarks und mutierende QA am gemeinsamen Stack nicht. Slots werden nur bei konkreten unabhängigen Aufträgen besetzt. Diese Pläne erstellen selbst keine neuen Benutzertasks.

## Verbindliche QoL-Anforderungen

| ID | Erwartetes Verhalten | Pflichtgegenprobe und Nachweis |
|---|---|---|
| QL01 | Nach einem Fehler bleiben zulässige Auswahl, Eingaben, Filter und Sortierung erhalten. Der sichtbare Zustand entspricht dem erneut geprüften Scope. | Validierungsfehler, abgelaufene Vorschau, Browser-Zurück und Paralleländerung mit abgewählten Checkboxen, Vererbung und dynamischen Zeilen. Entfernte/gesperrte Ziele sichtbar markieren; keine Scopeerweiterung oder automatische Wiederholung. Geheimnisse und nach Rechteentzug unzulässige Daten nicht zur Komfortspeicherung vervielfältigen. |
| QL02 | Nach Sammelaktionen erscheint eine Ergebnisübersicht mit ausgewählt, geändert/erfolgreich, unverändert, blockiert/übersprungen und fehlgeschlagen, soweit für die Aktion zutreffend, sowie passenden Direktlinks. | Gemischte Ausgangswerte, No-op, bestehende Bulk-Skip-Aktion und vollständiger Transaktionsrollback. Mengen ergeben den tatsächlichen vollständigen Scope; nach Rollback keine Zeile als gespeichert ausweisen. Unklarer Commit bleibt als unklar erkennbar. |
| QL03 | Alte oder unvollständige Information nennt Herkunft, letzte belegte Beobachtung und den fachlichen Geltungsbereich. | Alter Scan während neuem Lauf, ausgefallene Teilquelle, fehlender Zeitbeleg, Retention und Cache/Refresh. Seitenladezeit ist keine neue Beobachtung; fehlende Daten sind weder Null noch Erfolg. |
| QL04 | Ein Konflikt erklärt, welche fachlich relevanten Werte oder Voraussetzungen sich seit der Vorschau geändert haben. Eigene Eingaben bleiben zur bewussten Überarbeitung erhalten. | Zwei Tabs, Kindtabellen-/Katalogänderung, Rechteverlust und während der Vorschau gestarteter Job. Nur weiterhin berechtigte Werte anzeigen; bei fehlender Vergleichsbasis die Grenze nennen. Keine Secrets oder fremde Auditdetails im Konfliktdiff. |
| QL05 | Bei unbekanntem Ergebnis gibt es einen konkreten lesenden Prüfweg zum Vorgang, zu aktuellen Ergebnissen oder zum passenden Protokoll. | Antwortverlust nach Commit, nicht erreichbare DB, verlorene Remoteantwort und verschwundener Job. Kein automatischer erneuter Write oder Create; erst Ergebnis auflösen, danach nur eine durch den zuständigen Owner erlaubte Folgeaktion anbieten. |

Die Anforderungen sind Teil jeder betroffenen Etappe, einschließlich der passenden DE/EN-Hilfe und Fehlertexte. Sie sind kein optionales späteres Komfortpaket. Nicht betroffene Fälle werden in der Ablaufkarte begründet ausgeschlossen; ein pauschales „QoL geprüft“ reicht nicht.

**Zusätzlich angenommen am 12.09.2026:** UX03 bis UX06 erweitern den Bedienvertrag auch auf erfolgreiche Navigation und den Zeitraum vor dem Absenden. Gemeinsame Bausteine für Formzustand, Navigation, wirksame Einstellungen und Ergebnisanzeigen sind Teil dieser Lieferung. Diagnose und Hilfe folgen dem Erklärungsmuster „Was ist passiert? Was bedeutet das für meinen Auftrag? Was kann ich jetzt tun?“ mit passenden Detail-/Protokoll-/Hilfelinks. Die unten genannten Modelle, Abhängigkeiten und Gegenproben gehören zum Auftrag. Eine Umordnung in Basis-/Expertenformulare und eine zusätzliche Suche wurden mit dieser Auswahl nicht beauftragt; die bestehende F05-Liste bleibt im Umfang.

Die nur vorgeschlagenen, nicht ausgewählten Admin-Ideen gehören nicht zusätzlich zum Auftrag: globale Suche, zentrale Handlungsbedarfsseite, Rollout-Gesamtübersicht, Favoriten, Übergabebericht, Änderungen seit letztem Besuch, Stilllegungsassistent, Ticketdiagnose und persönliche Spaltenansichten. Die lesende Gesamt-VM-Liste für F05 ist dagegen eine notwendige Featurevoraussetzung.

## Gesicherter Ausgangsstand

- Hauptcheckout: `8f40351`, Branch `codex/audit-round2-u05-u06-u15-u16`.
- Separater Autoimporter-Checkout: `qa-artifacts/autoimporter-local-cim-publish`, Branch `codex/fix-autoimporter-local-cim`, sauber bei `dfdb4caf003833efcd56ccf4c4cb3d93f416fdba`.
- Der lokal gespeicherte `origin/main` zeigt ebenfalls auf `dfdb4ca`. Die direkte Remoteabfrage scheiterte an der lokalen Netzwerk-/Proxyverbindung. Ein frischer Remotezustand ist daher nicht bestätigt.
- `8acbc9f` enthält den lokalen CIM-Fix; `dfdb4ca` enthält die erweiterte Verteilverfolgung, Portaltrennung, Tests, DE/EN-Hilfe und Dokumentation. Die alte S1-Aufgabe „noch committen/pushen“ ist anhand der lokalen Gitreferenzen überholt.
- Im Hauptcheckout liegen fremde Änderungen an `Powershell-MECM/mecm/VirtuSphere-Common.ps1`, `tests/powershell/VirtuSphere.RunReport.Tests.ps1` und dem Auditregister sowie der unversionierte Bericht `2026-09-11-full-pipeline-start-stagger-audit.md`. Die ersten beiden Diffs enthalten den bereits separat veröffentlichten CIM-Fix/Test. Nichts davon pauschal erneut übernehmen, zurücksetzen oder löschen.
- Das Auditregister bestätigt U01 bis U17 als implementiert und eine umfangreiche, begrenzte lokale QA. Die nachfolgenden Restnachweise sind weiter offen. Die dortige frühere Gleichheit von HEAD und `origin/main` ist eine historische Beobachtung.

## Bereits erledigt oder nur teilweise offen

| Ursprünglicher Punkt | Aktueller Befund | Verbleibende Arbeit |
|---|---|---|
| N+1 bei `getVMs()` | `lib/repo/vms_legacy.php` lädt Relationen inzwischen in begrenzten Batches. SC-025/U13 implementiert. | P01: fachlich gültige Vorher-/Nachhermessung und Querynachweise, kein zweiter Batching-Umbau |
| Sessionlock beim JSON-/Raw-Joblog | `portal/deploy_log.php` gibt die Session frei; U13 hat außerdem den Liveblockerpfad bearbeitet. | P01/Q01: Wirkung und Regression abnehmen |
| Volumenfixtures | S/T/L mit 100 Missionen, 1.099 VMs insgesamt, 1.000 Ziel-VMs und 10.000 Logs sowie korrigierte Lastprofile vorhanden. | P01: ausführen; frühere VM-Latenzen aus fehlgeleiteten Requests nicht verwenden |
| Große Portalmodule trennen | Deploy/Settings/VM-Editor/VM-Repository besitzen die fokussierten Module und Registrierungen. | Kein erneuter pauschaler Abbau der früheren Etappen 12 bis 15; Größenratchet bei neuen Features einhalten |
| Logfilter/Korrelation | Strukturierte Filter und Korrelationspfad vorhanden. | O07: Listenpagination benutzt weiterhin OFFSET; Exportcursor ist kein Nachweis für Keyset im Listenreader |
| phpMyAdmin optional | `tools`-Profil vorhanden. | O04: separates optionales Offline-Tools-Bundle und dessen Nachweis noch prüfen/abschließen |
| Assetversionierung | `layout_asset_url()` ergänzt bereits einen Dateizeitstempel. | O05: sichere Cacheinvalidierung, gzip/Cacheheader und seitengerechte Assets noch offen |
| Kopierfunktion | F04 lokal abgeschlossen: Der gemeinsame Renderer und Handler decken sichtbare VM-/Rolloutnamen, konfigurierte IPs, MACs und Job-IDs sowie aktuelle Editorwerte ab. | Funktionaler Chromiumlauf und Unit-/Static-Suite sind grün; Bericht: `qa-artifacts/consolidated-session-backlog/20260913-f04-report.md` |
| Zugangsdaten und `cancelling` | `repo_delete_credential()` nutzt inzwischen `repo_deploy_create_fence_credential_change()` mit `VIRTUSPHERE_DEPLOY_JOB_ACTIVE_STATUSES`. | F08/E0: R10 des alten Featureplans aktualisieren und Raceabnahme erhalten; keinen bereits ersetzten queued/running-Check erneut reparieren |
| Autoimporter-Anzeige/Offline-DP-Fix | Implementiert in `dfdb4ca`, drei Bereiche für Lauf, letztes Ergebnis und gemeldete Verteilhinweise. | M01/M02/Q01: Prüfstand bereinigen und reale Wirkung abnehmen. Keine vollständige Live-DP-Quote vorhanden |
| PHP-Stop, E9-Registry, P3-VLAN-Kommentar | Laut neuestem Auditregister korrigiert; PHP-Fix im QA-Service nachgewiesen. | B01: Aktivierung im Entwicklungscontainer noch bestätigen; externe E9-Wirkung unter L02 |
| Powercycle pro VM | PC01 und PC02 sind lokal umgesetzt und belegt: 14/14 Offline-Fälle, darunter 15 sequenzielle Zyklen und eine echte 5-Sekunden-Pause. | PC03: `loop_control.loop_var` im PHP-Vertragsspiegel und abweichenden `hw_name` ergänzen. PC04: getrennte Fast-Blocker, vollständiges Fast, danach Integration/Release und L03-ESXi-Lab. |

Pfade mit `lib/` und `portal/` beziehen sich auf `Docker/WebAPI/`.

## Reihenfolge

1. **R00, Sol Medium mit Terra Low: Arbeitsbasis und Ablaufkarten sichern.** Quellstände zuordnen, benötigte U13-Vorherquellen vor Änderungen sichern und jeden betroffenen Einstieg erfassen.
2. **M01/M03/Q01, Sol High für Fehlerklärung und Sol Medium für Ausführung: Ausgangsabnahme.** Offene Prüfprobleme und die bestätigten M03-Commitbefunde bearbeiten, danach eine belegte Funktions-/Visualbasis herstellen. M03-Providerfragen mit M02/L01 vorbereiten. Diese frühe Abnahme ersetzt keine Prüfung späterer Änderungen.
3. **P01, Sol High für Messdesign und Sol Medium für Ausführung: U13 exklusiv messen.** Fachliche Grundlagen E0a und X01 können währenddessen lesend vorbereitet werden; keine konkurrierenden Laufzeitprüfungen oder Quelländerungen am Messstand.
4. **X01 und O01 bis O06: Änderungen einzeln liefern.** Vor Schema-/Runtime-/Lieferänderungen den Kompatibilitätsvertrag festlegen. O04 in drei Teilpakete zerlegen; O07/O08 anhand vorab definierter Nutzen- und Gleichheitskriterien entscheiden. O01 kann nach R00 vorgezogen werden, mit eigenen betroffenen Nachweisen.
5. **PC03/PC04 und aktuelle Fast-Blocker: den bereits begonnenen Powercycle-Strang lokal schließen.** Zuerst den direkten `loop_control.loop_var`-Vertrag und den abweichenden-`hw_name`-Gegenfall korrigieren. Danach die im selben Fast-Lauf sichtbar gewordenen unabhängigen Fehler an ihren eigenen Ownern schließen: Deploy-Fassade/Logtyp, Ansible-Testintervall samt README-/Browserabdeckung/PHPStan, Dateigrößen und CSP. Die sechs UX02-Sollbilder bleiben im persönlichen Baselineprozess. Gezielt betroffene Gates vorziehen, anschließend Fast vollständig auf neuem Manifest abschließen; 26 grüne Gates nicht ohne Quellenänderung aus Routine wiederholen.
6. **UX01 bis UX06 sowie E0a/E0b/E0c und E1 bis E6: Bedienung und Features nach tatsächlichen Abhängigkeiten liefern.** Innerhalb der Portalverbesserungen zuerst Blocker und Überläufe (UX01/UX02), danach Kontext und Eingabeschutz (UX03/UX04), anschließend wirksame Werte und Aktionsfolgen (UX05/UX06). UX01 kann nach Q01 eigenständig vorgezogen werden; UX02 bereits nach R00 mit eigener gezielter UI-Ausgangsevidenz. UX03/UX04 brauchen nur ihre vorhandenen Form-/URL-/Bestätigungsowner; UX05/UX06 den jeweiligen Feld-/Wirkungsausschnitt aus E0a. Anschluss an F10/F01/F09 nutzt deren E0b-Vertrag, ohne die Verbesserung bestehender Abläufe bis zur vollständigen Featurelieferung aufzuhalten. Änderungen an gemeinsamem Deploycode oder Messquellen warten auf das Ende einer laufenden P01-Messung. F04 braucht vorhandene UI-/Kopierverträge; F05/F08/F02 die Lese-/Feldgrundlage E0a; F10/F01/F09 zusätzlich E0b; F03/F11/F06/F07 jeweils ihren Teil von E0c. F02 setzt kein fertig ausgeliefertes F10 voraus. F07 Zuständigkeiten und F11 Kalender folgen im Einzeladminbetrieb nach diesen Bediengrundlagen und den übrigen ausgewählten Features; beide bleiben vollständig im Umfang. Fachliche Abhängigkeiten und frühe Fehlerkorrekturen haben Vorrang vor dieser Nutzenreihenfolge. Die Modelle stehen in den Paketkarten und im Admin-Detailplan.
7. **B01/B02, D01, L01 bis L03: Abnahmen passend zur Lieferung ausführen.** Doku/Help und betroffene Regressionen schließen jede Etappe ab. Standorttermine früh vorbereiten, M02 mit L01 bündeln und die reale Powercycle-Folge in L03 abnehmen. Fehlende externe Systeme blockieren unabhängige lokale Pakete nicht.
8. **Q02, R01 und gegebenenfalls G01 unter Sol Medium:** Endstand einschließlich PC04 abnehmen, Nachweisgrenzen ausweisen und nur bei gültigem Veröffentlichungsauftrag committen/pushen. Kein erneuter Astra-Review allein wegen dieses Gitabschlusses.

Diese Reihenfolge ist ein Umsetzungsvorschlag. Frühere auf einzelne QA-Runden begrenzte Ausnahmen für Performance und Visuals bleiben als historische Nachweisgrenzen sichtbar; der vorliegende Plan führt diese bisher ausgesetzten Arbeiten als eigene Folgepakete.

## R00: Quellen, Nachweise und Arbeitsbasis

- [ ] Bei Arbeitsbeginn tatsächliche Branches, Arbeitsbäume und Remotezustand erneut feststellen; die derzeit blockierte Remoteverbindung bei Bedarf über den regulären genehmigten Zugang prüfen.
- [ ] Neue Arbeit auf einer eindeutig festgehaltenen Basis mit mindestens den benötigten Änderungen bis `dfdb4ca` beginnen. Hauptcheckout nicht blind auf main umstellen. Vorhandene Registerfortschreibung und fremden Pipelinebericht bewahren; sachlich zugehörige Diffs einzeln zuordnen.
- [ ] U13-Vorherkopien, Hashes und Messharness sichern. HEAD allein beschreibt keinen Arbeitsbaum mit uncommitteten Quellen.
- [ ] Je Nachweis Quellmanifest, Umgebung, Gate, Ergebnis und Artefaktpfad festhalten. Alte rote Läufe bleiben erhalten; spätere Teilprüfungen ersetzen nur ihren belegten Umfang.

**Abnahme:** Ein eindeutiger Umsetzungs-/Prüfstand; keine verlorene oder doppelt übernommene Änderung; alle folgenden Pakete besitzen eine Quelle und ein überprüfbares Ergebnis.

### Ablaufkarte und Evidenz je Paket

Vor dem ersten Produktdiff ergänzt der federführende Agent beim fachlichen Paket: Modell/Effort, Eingangsvoraussetzungen, genaue Datei-/Schreibzuständigkeit, sämtliche betroffenen Einstiege, erlaubte Zustandsänderungen, notwendige Gegenfälle, erwartetes Ergebnis, Doku-/Helpowner und Nachweisartefakte. Ein Reviewer prüft diese Karte vor kritischer Implementierung. Bestehende Prüfungen dürfen referenziert werden; eine zweite Liste aller Gates wird nicht gepflegt.

Die Einstiegsliste umfasst je nach Änderung normalen Editor, Vorlagen/Missionen, Klonen, Import/Transfer, Sammelaktionen, Queue/Staffelung/Retry, Worker, Machine-Callbacks und Maintenance. Nicht betroffene Einstiege ausdrücklich begründen. Ein neuer Namensschutz muss alle Erzeuger erreichen; ein neuer Felddeskriptor muss Vorschau, Vergleich und relevante Writer abdecken.

Jede Ablaufkarte prüft gültig/ungültig/leer, Grenzen darunter/gleich/darüber, direkte Requests, Rollen/CSRF/Sitzungswechsel, Parallelität und Lockreihenfolge, Abbruch vor/nach Commit, Antwortverlust/Wiederholung, Quelle ausgefallen/veraltet/gelöscht, Migration/Restore sowie die zutreffenden QL01 bis QL05. Erwartete DB-/Remote-Deltas und sichtbare Ergebnisse sind vor Ausführung beschrieben. Nicht anwendbare Fälle brauchen eine fachliche Begründung, keine pauschale Freistellung.

SSoT-Gegenbeispiele: Ein neues editierbares Feld muss eine fehlende Vorschau-/Vergleichsklassifikation sichtbar machen; eine neue Statusvariante darf keine abweichende Meldungs-/Chronikdarstellung erzeugen; eine neue Grenze muss beim zuständigen Bounds-Owner und den passenden Grenzfällen auffallen. Anzeigeordnung, exakte Identität und fachliche Gleichheit bleiben getrennte Regeln.

## MECM und Autoimporter

### M01: Prüflücken des veröffentlichten Autoimporterstands klären

**Priorität P1, abhängig von R00.** Die S1-Zusammenfassungen allein belegen keinen grünen Gesamtabschluss. Vorhandene Artefakte zeigen erfolgreiche Portal-/Driftteilprüfungen, aber auch rote Gesamtprüfungen:

- `qa-artifacts/autoimporter-three-axes-final.log`: sechs ausgewählte Gates bestanden.
- `qa-artifacts/autoimporter-final-php-native.json`: fünf ausgewählte Gates bestanden.
- `qa-artifacts/autoimporter-final-fast.json`: drei pass, sechs fail, 21 infrastructure_error, ein not_applicable; keine vollständige Fast-Freigabe.
- `qa-artifacts/autoimporter-publish-powershell-unrestricted.log`: 685 reguläre Tests bestanden, 16 fehlgeschlagen; Hinweise unter anderem auf fehlendes `sh` und `playwright-core`. Das historische Härtungsregister meldet zusätzlich E8 als fehlgeschlagen, 34 bestanden und fünf Handproben.
- E8 erwartet im ersten gefundenen `if` die Tokenparameterprüfung, findet inzwischen `$Upgrade`. Das ist ein konkreter Prüfkonflikt; ob das Verhalten fehlerhaft oder der Test strukturell veraltet ist, muss am Installer geklärt werden.

- [ ] Jede Fehlerklasse dem richtigen Owner zuordnen; Infrastruktur vervollständigen, Produkt- und Testfehler getrennt korrigieren.
- [ ] E8 mit Fällen Upgrade, interaktiver Wiederaufruf, Token beibehalten, rotieren und explizit entfernen fachlich prüfen. Eine veraltete AST-Annahme nur mit einem aussagekräftigen Ersatz ändern.
- [ ] PowerShell 5.1/7 und die betroffenen PHP-/Portalverträge auf dem vollständigen finalen Quellstand nachweisen. Auch unversionierte/neue Tests müssen tatsächlich entdeckt werden.

**Abnahme:** Erklärte und geschlossene Fehlerklassen, vollständige passende Gateergebnisse; keine Umdeutung von fehlenden Werkzeugen zu bestandenen Tests. Q01 bündelt die gemeinsame Regression, statt sie doppelt auszuführen.

### M02: Air-Gap-Update und reale Verteilwirkung

**Priorität P1; gemeinsam mit L01.** Der lokale CIM-Fix wurde in S1 am Standort installiert und weiterer Scanfortschritt belegt. Die spätere Gesamterweiterung aus `dfdb4ca` ist dort noch nicht nachweislich abgenommen.

- [ ] Installierten PowerShell- und Portalstand mit der vorgesehenen Lieferung vergleichen; vollständiges PowerShell-Paket über den bestehenden Upgradepfad und passende Portalversion übernehmen.
- [ ] Lange Scans, fehlende Abschlussmeldung, vorheriges Ergebnis und separaten MECM-Site-Zustand korrekt unterscheiden. Anzeige und Logs müssen denselben Lauf meinen.
- [ ] Offline-DPs bleiben Zielmitglieder: neue/geänderte Inhalte dürfen angefordert werden, ohne jeden Scan dieselbe Verteilung neu anzustoßen. Vollständigkeit erst nach aktuellem Nachweis aller erforderlichen Ziele bestätigen; einen wiederkehrenden DP nachprüfen.
- [ ] Ändert sich die DP-Mitgliedschaft während einer Verteilung, den zuständigen Zielmengenvertrag erneut anwenden. Alte Kopierzeiten, zurückkehrende Ziele und neue Contentversionen dürfen keinen falschen aktuellen Vollständigkeitsnachweis ergeben. QL03 nennt Scope und Beobachtungszeit; QL05 führt bei unklarem Intent zur lesenden Diagnose.
- [ ] Gültige/ungültige Versionen, Änderungen der Dateiliste während des Scans, unlesbare Identität, Providerfehler und historische U09-Trackingstände prüfen. Keine automatische Freigabe oder Löschung alter Trackingstände.
- [ ] Ersetzte Altanwendungen verständlich anzeigen und dokumentieren. `removeOldVersion=true` ist die ausdrückliche JSON-Anforderung zur automatischen Entfernung; ohne dieses Flag löscht der Importer keine Altobjekte. Der belegte, unmittelbar revalidierte Plan entfernt alte Deployments, Applications und Collections, sobald der neue Deploymentpfad bereit ist, auch wenn DPs noch ausstehen.
- [ ] Standortbefund „MECM meldet kritisch“ getrennt anhand der betroffenen Site-/Komponentenmeldung klären. Offline-DPs oder Air-Gap sind allein kein Ursachenbeweis. Frühere Versionsmeldungen anhand neuer Scans schließen, nicht ungeprüft erneut umbenennen.

**Abnahme:** Versions-/Installationsbeleg, aufeinanderfolgende Scanresultate, korrekte Portalzuordnung, Nachweis ausstehender und später konvergierender DP-Ziele. Eine aktuelle Übersicht „4/6“ bleibt eine optionale Erweiterung mit eigenem Telemetrievertrag; aus den jetzigen Hinweisen darf keine Quote erfunden werden.

**Lokaler Umsetzungsstand 15.09.2026:** `autoimporter/2.2` trennt zusätzlich
Deploymentfreigabe und vollständigen Verteilnachweis. Nach bestätigter Annahme
des Erst- oder Updateauftrags läuft der idempotente Collection-/Deploymentabgleich
auch bei null erfolgreichen beziehungsweise vollständig ausgefallenen DPs; der
offene Contentbefund und der Manifestabschluss bleiben an die vollständige
Ziel- und Kopierevidenz gebunden. `removeOldVersion=true` fordert dagegen den
automatischen Schema-3-Retirement-Plan an: Der bestätigte und an die exakte
Content-ID gebundene Ersatzauftrag, sichere Zielevidenz und das geprüfte
Deployment genügen; auch null erfolgreiche DPs sind zulässig. Der Plan wird
unmittelbar erneut erhoben und entfernt alte Deployments, Applications und
Collections in dieser Reihenfolge. Unbestätigte Aufrufe, fehlende DP-Gruppe,
unbekannte Identität oder Projektion, Zielverlust und Löschzustände bleiben
harte Sperren. Der aktuelle kanonische PowerShell-Lauf belegt auch
Schema-1/2-Ablehnung, Deploymentreihenfolge/-hash, Referenzsperren und
unvollständige IDs; Syntax und Analyzer sind grün, 724 von 725 ausgeführten
Pester-Tests bestanden bei 79,15 Prozent Coverage. Der einzige PowerShell-Fehler
ist der unabhängige vorhandene Visual-Baseline-Drift (Manifest/Runner passen
nicht, 12 statt 18 Bilder, sechs Systemstatus-Baselines fehlen). PHP-Lint,
DE/EN-Parität sowie beide Doku-Gates sind grün; das gesamte PHPUnit-Gate hat
weiterhin vier unabhängige Fehler im übrigen schmutzigen Arbeitsstand (1792
Tests, 42065 Assertions). Artefakte:
`qa-artifacts/qa-auto-package-retirement-powershell-current.json`,
`qa-artifacts/qa-auto-package-retirement-portal-docs-final-elevated.json` und
`qa-artifacts/qa-auto-package-retirement-docs-current.json`. Offen bleiben Upgrade und echte
MECM-Lab-Abnahme mit mehreren offline/online wechselnden DPs, insbesondere die
generationenübergreifenden Abschlussfragen M03-Q01/Q02; kein Commit oder Push.

### M03: Fachreview des letzten lokal bekannten Commits

**Reviewstand 12.09.2026:** Geprüft wurde `dfdb4caf003833efcd56ccf4c4cb3d93f416fdba`, „fix: harden autoimporter distribution tracking“, vom 11.09.2026, 21:28 Uhr. Dieser Commit ist der neueste lokal vorliegende Stand und Ziel des zusätzlichen Nutzerauftrags. Der Hauptcheckout liegt weiterhin bei `8f40351`; die Prüfung las deshalb die Gitobjekte von `dfdb4ca` samt relevanten unveränderten Aufrufern und Datenownern. Ein frischer Remoteabgleich ist damit nicht belegt. Dies ist ein fachlicher Review eines vorhandenen Commits, keine Commit-/Pushfreigabe und kein erneuter vollständiger Review von `8f40351`.

**Abdeckung:** Der Review der 14 geänderten Dateien hatte die Schwerpunkte Contentcontroller, gemeinsame Evidenz-/Versionshelfer, Run-Cause-Encoding, Portalpräsentation, DE/EN-Aussagen und vorhandene Testfälle. Vier reine Versions-/Auswahlfunktionen wurden isoliert aus dem Commit extrahiert und mit einem synthetischen Gegenbeispiel ausgeführt; keine Modulinitialisierung, Registry-, MECM-, Datenbank- oder Produktdateiänderung. Keine neue MECM-/Browser-/PS5.1-/PS7-Gesamtabnahme. Die folgenden Quellzeilen beziehen sich auf `dfdb4ca`, nicht auf abweichende Dateien des älteren Hauptcheckouts. M03 besitzt diese neu beauftragte Reviewkarte; die spätere Umsetzung übernimmt ihre IDs mit Verweis ins Ergebnisregister, ohne eine zweite unabhängig gepflegte Befundfassung anzulegen.

| ID / Priorität | Bestätigter Befund und konkreter Gegenfall | Offene Korrektur und Abnahme |
|---|---|---|
| M03-F01 / P2 | **Legacy-Heartbeat verfälscht das letzte Scanergebnis.** `lib/repo/heartbeats.php:50` setzt beim Heartbeat `last_status=ok` und ersetzt `last_detail`, erhält aber `last_result_at`, Dauer, Fehlerkategorie und Zähler. Der neue Renderer `lib/system_status_mecm_panels.php:234` deutet jedes vorhandene `last_result_at` zusammen mit diesem Status als Scanergebnis. Ablauf: V2-Abschluss `fail` → Legacy-Heartbeat ergibt einen grünen Scanbadge mit altem Fehlerzeitpunkt/-zählern; auch Verteilhinweise können mit falscher zeitlicher Herkunft erscheinen. | Ergebnis, Detail, Zeitpunkt und Herkunft nur als zusammengehörigen belegten Abschluss darstellen. Bei verlorener Zuordnung ehrlich unbekannt, keinen historischen Erfolg rekonstruieren. Folgen `completed fail → heartbeat`, `completed warning → heartbeat → started` und anschließend frischer `completed` prüfen; nur ein Legacy-Sichtbarkeitsfilter genügt nach erneutem Start nicht. `AutoimporterStatusPresentationTest` prüft bisher Legacy mit leerem Ergebniszeitpunkt; diesen Fall um den echten Heartbeat-Writer-/Rendererpfad erweitern. QL03/QL05 und X01-Rollback gelten; Machine-Wire unverändert lassen. |
| M03-F02 / P2 | **Optionale Altobjektsuche kann den ganzen Scan abbrechen.** Die neuen `-ErrorAction Stop` bei Collection-/Application-Suche in `Powershell-MECM/mecm/mecm_autoimporter.ps1:188` und `:191` liegen vor der Contentbearbeitung und besitzen keinen lokalen Catch. Ein Collection-Lesefehler springt in den äußeren Catch `:629`; aktuelles Paket und alle folgenden Pakete bleiben unbearbeitet, Providerkontext wird zurückgesetzt. Das betrifft auch eine Collection-Leseberechtigung, während Application-/Contentzugriff zulässig wäre. | Fehler beim zusätzlichen Altobjektinventar lokal als unvollständigen Nachweis melden, keine Lösch-/Bereinigungsentscheidung ableiten und unabhängige Pakete weiterbearbeiten. Contentarbeit nur dann fortsetzen, wenn ihre eigenen Identitäts-/Providerprüfungen tragen. Gegenprobe mit fehlerhafter Cleanup-Lesequelle und zwei weiterhin bearbeitbaren Paketen; genau ein Abschluss, passende Ursache und kein verlorener früherer Befund. Kein Rückfall auf stilles Verschlucken unter `SilentlyContinue`. |
| M03-F03 / P2 | **Fehlende DP-Gruppe wird als gemeldeter DP-Fehler erklärt.** Der bestehende Zweig `mecm_autoimporter.ps1:466` erzeugt bei leerem DP-Gruppennamen und null Zielen `package_content_failed`. Die neue Erklärung `lang/de/system_status.php:145` beziehungsweise der gleichnamige EN-Key behauptet dagegen, mindestens ein DP habe einen Verteilfehler gemeldet. Der Code beweist in diesem Fall nur eine fehlende Voraussetzung. | Den historischen groben Ursachencode im bestehenden Wire beibehalten, seine Erklärung auf die belegte gemeinsame Aussage begrenzen. Spezifische DP-Diagnose nur mit passender Evidenz. Producer-/Presenter-Gegenprobe für fehlende Gruppe, echten DP-Fehler und unbekannte Projektion; DE/EN-Hilfe und lesender Prüfweg nennen die jeweils tragfähige Diagnose. |
| M03-F04 / P3 | **Eine höhere Version wird bei gesperrter Zielauswahl zum Altobjekt erklärt.** `Get-VsPackageRetainedNames()` in `Powershell-MECM/mecm/VirtuSphere-Common.ps1:1888` schützt höhere Versionen nur bei `Selection.State=ready`. Die isolierte Probe mit Quellen `1`, `1.0` und Bestand `Agent-1`, `Agent-1.0`, `Agent-3` ergab `SelectionState=blocked`, aber `ReportedOldObjects=[Agent-3]`. Der Importer meldet daraus `package_cleanup_failed`. Es erfolgt keine Löschung; der Diagnosehinweis ist falsch eingeordnet. | Bei mehrdeutiger oder nicht numerisch ordnungsfähiger Zielauswahl keine unbelegte Alt-/Höher-Einordnung erzeugen. Mehrdeutigkeit und ungeklärten Bestand erklären; nur nachweislich ältere Objekte als solche benennen. Gegenproben mit beiden Quellreihenfolgen, numerisch gleichen und nicht numerischen Versionen sowie niedrigerem, gleichem und höherem Bestand; Retained-Name-Tests um den gesperrten Selection-Fall erweitern. |

**Weitere konkrete Gegenproben, noch keine bestätigten Laufzeitdefekte:** Diese Fragen präzisieren M02/L01 und bleiben getrennt von den vier belegten Befunden.

| ID | Noch zu klärende Logik-/Nachweisgrenze | Entscheidung und geforderter Beleg |
|---|---|---|
| M03-Q01 | Nach Supersede eines offenen Auftrags kann eine spät eintreffende Kopie des alten Contents ebenfalls `LastCopied` gegenüber der neuen Baseline erhöhen. `Test-VsDistributionCopyEvidence()` kennt DP-Identität, State und Zeit, aber keine Content-ID pro DP; die aktuelle DT-Content-ID wird separat gelesen. Der neue Fixturetest erhöht die Kopierzeit und nennt das neuen Content, ohne die Kopie selbst dieser Content-ID zuzuordnen. | Den zeitversetzten Ablauf alter DP-Abschluss → neuer DT-Stand → verzögerte neue Kopie gezielt modellieren und im MECM-Labor prüfen. Keine neue Contentgeneration mit alter Kopie quittieren. Falls die Providerfelder diese Zuordnung nicht beweisen, konservativen Abschlussvertrag beziehungsweise erforderliche zusätzliche Evidenz festlegen und dokumentieren; weder automatische Wiederholung noch fremde Contentübernahme. |
| M03-Q02 | Bei Erstverteilung bindet `mecm_autoimporter.ps1:415` die erste nichtleere Kopierprojektion mit Nullticks. Übereinstimmende Aggregat-/Projektionsanzahl beweist nur deren Übereinstimmung, nicht automatisch die vollständige Zielmenge der angeforderten DP-Gruppe. | Verzögert sichtbare Gruppenziele und einen noch vor erster Bindung fehlenden DP prüfen. Die angeforderte Zielmenge und zulässige Beobachtungsgrenze explizit entscheiden; Teilprojektionen dürfen keinen behaupteten Gesamtabschluss liefern. Die vorhandene Probe „Ziel verschwindet nach Bindung“ ersetzt den Vor-Bindung-Fall nicht. |
| M03-Q03 | **Bereits bestehende Ablaufgrenze:** Nach fehlerfreiem Scan merkt sich die äußere Schleife den Dateistamp; bei unverändertem Quellbaum werden Provider-/Tracking-/Collectionprüfungen übersprungen (`mecm_autoimporter.ps1:128`, `:623`). Reine Änderungen an DPs oder MECM-Objekten lösen dadurch allein keinen neuen Abgleich aus. Bei tatsächlich ausgeführtem Scan mit zusätzlichen Zielen und unverändertem Manifest verlangt die Completeprüfung weiterhin exakt die alte Zielmenge. | Festlegen, welche Änderungen ohne Dateianpassung erkannt und selbstständig nachgezogen werden müssen, einschließlich erfolgreicher zusätzlicher DPs nach früherem Abschluss. Falls verlangt, begrenzte lesende Neubewertung unabhängig vom Dateihash planen; keine Redistribution pro Intervall und kein Vollhash als Ersatz für einen Remote-Nachweis. Prüfung über die äußere Schleife und mehrere Scans; der isolierte Contentcontroller deckt den Skip nicht ab. Frische/Hilfe und Performancegrenze gemeinsam nachführen. |

**Ausführung:** Sol High besitzt Fehlerklärung, Owneränderungen und gezielte Gegenreviews; Terra Low darf die entschiedenen DE/EN-/Plan-/Linkaussagen nachführen. Astra High nur für die konkrete neue generationenübergreifende Content-/Abschlussfrage M03-Q01/Q02, sofern Sol sie nicht anhand vorhandener Verträge auflösen kann. Sol Medium führt die passenden PS5.1-/PS7-/PHP-/Browserproben über `scripts/check.ps1` aus und ordnet Standortbelege M02/L01 sowie die gemeinsame Regression Q01/Q02 zu. M03-F01/F03 erhalten zusammengehörige Producer-/Datenowner-/Renderer-Gegenproben, M03-F02 einen vollständigen Scan mit Fehlerisolation, M03-F04 einen reinen Helferfall. D01 und die betroffenen Betriebs-/Hilfetexte schließen jede Korrektur ab. G01 bleibt vollständig bei Sol und braucht eine eigene gültige Veröffentlichungsautorisierung.

**Abnahme:** Vier bestätigte Befunde einzeln korrigiert und nachgewiesen oder bei inzwischen geändertem Stand nachvollziehbar widerlegt; drei offene Fragen durch konkret begrenzten Vertrag und passende Evidenz entschieden. Keine doppelte Neuerfassung derselben M02-/L01-Fälle. Die in dieser Planung ergänzten Findings sind noch nicht implementiert.

## Powercycle pro VM: PC01 bis PC04

Detailowner ist der [Powercycle-Plan](2026-09-14-powercycle-sequential-plan.md),
Ergebnisowner das Auditregister. Der [QA-Bericht](../../qa-artifacts/powercycle-sequential/sol-medium/report.md)
deckt alle 31 Fast-Gates in zwei nicht überlappenden Läufen ab: 26 pass, 5 fail,
keine Infrastrukturfehler und keine Skips.

| Paket | Aktueller Stand | Nächste Etappe und Abschlussgrenze |
|---|---|---|
| PC01 | Lokal umgesetzt und offline nachgewiesen. | Reale ESXi-Wirkung bleibt L03; harter Prozessabbruch und externe Bedienung bleiben dokumentierte Systemgrenzen. |
| PC02 | 14/14 Offline-Fälle grün, einschließlich 15 sequenzieller Zyklen und echter 5-Sekunden-Pause. | Vorhandenen, aber abweichenden `hw_name` als eigenen Negativfall ergänzen und Gate gezielt wiederholen. |
| PC03 | Direkte Upload-, Identitäts-, Hygiene-, Doku-, Sprach- und Fortschrittsnachweise grün; allgemeiner Variablenvertrag rot. | `loop_control.loop_var` als lokale Bindung spiegeln, eine wirklich ungebundene Variable weiter ablehnen und `phpunit-unit` gezielt abnehmen. |
| PC04 | Fast vollständig ausgeführt, aber 26/31 statt vollständig grün; Integration, Release und ESXi fehlen. | Unabhängige Blocker nicht PC03 zuschlagen, sondern ihren Ownerpaketen zuordnen; danach vollständiges Fast auf neuem Manifest, später Q02 und L03. |

Die zum QA-Abschluss bestätigten 37/37 Hashes sind ein historischer Beleg des
damaligen Standes. Diese Planfortschreibung verändert bewusst nur Auditdokumente;
sie verlangt eine neue Dokumentabnahme, aber keine pauschale Wiederholung der
unverändert gebliebenen grünen Produktgates. Sobald Produkt-, Test-, Runner- oder
Vertragsquellen geändert werden, ist ein neues vollständiges PC04-Manifest nötig.

## Technische Optimierungen aus S2

| ID / Priorität / Status | Arbeit und Owner | Konkrete Abnahme |
|---|---|---|
| O01 / P1 / lokal erledigt | `MYSQL_ROOT_PASSWORD` aus PHP/Workern entfernen. Runtimevalidierung von Setup-/DB-/Backupvalidierung trennen; Umgebungen, Dotenv-Mounts und Archive im Runtimezugriff gemeinsam prüfen. | PHP, beide Worker, Health und Migration funktionieren ohne lesbares Rootsecret; MySQL/Setup/Backup erhalten ausschließlich benötigte Secrets. Kein Ersatzsecret oder schwacher Fallback. Dev/QA/Supervisor/Fresh/Upgrade, Compose-/EnvBoot-Tests, Doku und SSoT stimmen überein. |
| O02 / P1 / lokal erledigt | Containerlogs und nginx-Ausgaben begrenzen; Docker besitzt die gemeinsame Rotation und nginx schreibt nach stdout/stderr. | Definierter Größen-/Aufbewahrungsrahmen, Rotation während Schreibzugriff, Neustart, volles Dateisystem und fehlende Rechte. Diagnose bleibt erreichbar oder ihr Ausfall wird erkennbar. stdout/stderr oder Dateirotation samt Backup-/Betriebsweg bewusst festlegen. |
| O03 / P2 / lokal erledigt | MySQL-Binlogpolitik ist passend zum logischen Backupvertrag explizit deaktiviert; es existiert kein Replikations-/PITR-Verbraucher. | Wirksame MySQL-Konfiguration, dokumentierter Daten-/Recoveryumfang und Restoreprobe. Historische Binlogs nicht per Dateilöschung aus dem Datenverzeichnis entfernen. |
| O04a / P1 / lokal erledigt | Gemeinsame versionierte PHP-Imagereferenz für FPM/Worker und deren Build-/Lieferowner vereinheitlichen. | Bestehendes Bundle dedupliziert bereits per Image-ID (`scripts/build-offline-bundle.sh`). Referenz-/Digestauflösung und Buildwiederverwendung belegen; keine Platzersparnis allein aus einem gemeinsamen Tag behaupten. X01 bindet auch gemounteten Quellcode und Konfiguration. |
| O04b / P1 / lokal erledigt | Builder-/QA- und schlankes Runtime-Target trennen. Compiler/Composer/Buildtools aus Runtime entfernen; Extensions, Shared Libraries, Rechte und Signalverhalten erhalten. | Vorher-/Nachhergröße und funktionale Gleichheit messen; QA/Composer/Bundler/CI verwenden das geeignete Target. Erhaltene Modulmenge, Health, Worker/Supervisor, TLS und Offlineabhängigkeiten belegen. |
| O04c / P2 / implementiert, Releasebeleg offen | Optionales phpMyAdmin-Air-Gap-Tools-Bundle aus dem gemeinsamen Lieferowner ableiten. | Kerninstallation ohne Tools und spätere optionale Installation ohne Netz nachweisen. Gemeinsame Pins, vollständige Kern-/Tools-Manifeste, Upgrade und Entzug des Tools; kein zweiter driftender Paketresolver. |
| O05 / P2 / lokal erledigt | gzip und Cache-Control für statische Assets in HTTP und generiertem HTTPS ergänzen. Cacheinvalidierung bei Offlineupdates verwendet SHA-256 statt `filemtime`. | Komprimierte CSS/JS, korrekte Header, alte offene Browserseiten und neue Assets nach Update mit erhaltenen Zeitstempeln; Fehler bei fehlenden Assets sichtbar. Dynamische/authentisierte Inhalte bekommen keine langlebige Assetcachepolitik. |
| O06 / P2 / lokal erledigt | Seitenspezifische Assets werden über die geschlossene Layoutregistrierung ausgewählt. Gemeinsame Shellassets bleiben global; Tabellen-, Status- und Featuredateien nennen ihre Verbraucher. | Nachweis tatsächlich benötigter Dateien je Seite; Modulreihenfolge, Login, Dialoge, Themes, neue Features und responsive Darstellung funktionieren. `status.css` bleibt wegen der nachgewiesenen gemeinsamen Fact-/Detailregeln bei Systemstatus und Deploy-Log klassifiziert. |
| O07 / P2 / lokal abgeschlossen | Der vorab fixierte 100.000-Zeilen-Messlauf löste das strukturelle Nutzenkriterium aus. Die Auditliste verwendet jetzt begrenzte `before`-/`after`-Keysetnavigation in globaler `id DESC`-Ordnung; Filterwechsel, gleichzeitige Inserts, veraltete Cursor und der unabhängige CSV-Snapshot haben explizite Verträge. Migration und Frischschema besitzen denselben `(category, id)`-Index. | Messplan, EXPLAIN-/Lastwerte, Implementierungsgrenzen und vollständige Fast-/Integrations-/Chromium-Nachweise stehen in `qa-artifacts/consolidated-session-backlog/20260913-o07-report.md`. Zugriffsschutz, Retention, Ereignistaxonomie, Writer und Exportformat bleiben unverändert. |
| O08 / P2 / lokal entschieden: beibehalten | Exakte JUnit-Identitäten belegen die vollständige Überschneidung der Unit/Static-Fälle; ihr Anteil am Vollrun blieb jedoch unter dem vor der Auswertung festgelegten Änderungskriterium. Der Runner bleibt deshalb unverändert. Kein DB-Sharding und kein neuer persistenter QA-Zustandscache. | Entscheidung, Rohzahlen, Umgebungsvergleich und künftiger Trigger stehen in `qa-artifacts/consolidated-session-backlog/20260913-o08-report.md`. Gate-IDs, Lanes, Exitcodes, JSON und Fortschritt bleiben unverändert; `phpunit-full` bedeutet auch einzeln weiterhin die vollständige Suite. |

Für O04b/O06/O07/O08 legt Sol vor der Messung ein Nutzenkriterium und unveränderliche Funktions-/Sicherheitsgrenzen fest. Eine begründete Entscheidung „beibehalten“ braucht gültige Messung, untersuchte Alternative und dokumentierte Konsequenz; sie ist keine unerledigt verschwundene Aufgabe. Vorhandene Lastschwellen werden nicht nachträglich an Ergebnisse angepasst. Bei noch fehlendem Budget wird es vor dem Vergleich fachlich festgelegt.

### P01: Ausstehende Performanceabnahme

- [x] Gesicherten U13-Messplan mit identischem korrigiertem Harness vor/nach den Änderungen durchführen: S/T/L, Queryanzahl, fachliche Ergebnisgleichheit, verifizierte Zielseite und tatsächliche VM-Zeilen. Lokal belegt durch `qa-artifacts/consolidated-session-backlog/p01-u13-20260912-final14/` und den zugehörigen P01-Bericht.
- [x] Kaltstartbeleg, Warm-up und mehrere warme Wiederholungen trennen; Session-A/B mit bewiesenen gleichen beziehungsweise unterschiedlichen Sitzungen. Keine Authwerte in Artefakte schreiben. Der 63-Einheiten-Lauf trennte diese Fälle; die kontrollierte Artefaktsuche fand keine Auth-/Cookie-/Session-/Passwort-/CSRF-Werte.
- [x] Vorhandene Lastschwellen unverändert bewerten. Die gesicherten Vorherquellen waren gültig; bei L verletzten alle drei Vorher-Warmreihen das unveränderte Gate, während alle drei Nachher-Reihen bestanden.
- [ ] Verbleibende AP09-Teile ergänzen: große Joblogs/DOM/Hidden-Tab-Verhalten, Healthlast, FPM-/Lock-/Transaktionsanteile und repräsentative Ressourcenmessung. Externer Durchsatz gehört zu den Laboren.
- [ ] Nach O-Paketen nur die betroffenen Messungen erneuern; keine pauschale Ressourcenerhöhung ohne Engpassbeleg.

**Abnahme:** Gültige Messreihen und Rohbelege, nachvollziehbarer Nutzen beziehungsweise verbleibender Engpass. Die alten kleinen p95-Werte aus S2 sind keine belastbare VM-Baseline; der korrigierte Lasttest dokumentiert den damaligen Redirectfehler.

### X01: Updateverträglichkeit und Wiederherstellung

**Eingang:** R00-Quellmanifest, benötigte O-/Featureänderungen, vorhandene Installations-/Migrations-/Workerverträge. **Federführung:** Sol High; Astra High prüft einmal die neuen kritischen Kompatibilitäts- und Recoveryentscheidungen. Sol Medium führt die festgelegten synthetischen Proben aus.

- [x] Zulässige Kombinationen von Portalquellen, DB-Schema, Worker/Supervisor, PowerShell-Paket, PHP-Runtime, Konfiguration und Assets tabellieren. Releaseidentität enthält Quellenhash, Migrationen, Images und Liefermanifest; der Image-Digest allein bezeichnet bei Bind-Mounts keinen vollständigen Appstand. Ausgeführt in `docs/operations/upgrade-recovery.md`.
- [x] Upgradefolge, Vorprüfungen, Umgang mit aktiven Jobs/Claims und erforderliche Wartung festlegen. Alte Callbacks/Revisionsfences, offene Browserformulare, Entwürfe, Lesestände und noch nicht bestätigte Vorgänge dürfen durch gemischte Versionen nicht falsch angewendet werden. Das Runbook lehnt gemischte Stände und veraltete Schreibabsichten ausdrücklich ab.
- [x] Pro Migration unterscheiden: rückwärts lesbar, nur vorwärts reparierbar oder Rückkehr ausschließlich per passendem Restore. Kein pauschales Datenbank-Downgrade und kein blindes Wiederholen einer unklar abgeschlossenen Migration. Die Migrationsklassen des geprüften Stands sind im Runbook einzeln aufgeführt.
- [x] Unterbrechungen an Paketinstallation, Schemaübergang, Runtime-/Workerwechsel und Assetauslieferung prüfen. Die vorhandenen Paket-/Restore-Fehlerproben bleiben grün; zusätzlich wurden Webserver/Asset, PHP und beide Worker im exakten synthetischen QA-Stack gestoppt und kontrolliert wiederhergestellt. Assethash, getrennte nginx-Eigenhealth, geschlossener Portalfehler, Portalverfügbarkeit bei Workerstop sowie gemeinsames gesundes Runtime-Image sind im O04/X01-Bericht belegt. Externe Wirkung wurde nicht erzeugt oder wiederholt.
- [ ] Restore mit älterem konsistentem Stand und noch geöffneten neueren Browserseiten prüfen; Sitzungs-, Vorgangs- und Callbackgrenzen müssen veraltete Schreibversuche erkennen. Schlüssel-/Konfigurationszuordnung und erforderliche Standort-AD-Neufreigabe dokumentieren. Der aktuelle isolierte Restore-Drill ist grün; Altstand, offene neuere Browserseite und Standort-AD bleiben offen.

**Ergebnis:** Eine ausführbare Kompatibilitätsmatrix samt erlaubtem Update-/Recoverypfad, Ablehnung unzulässiger Kombinationen und konkreten Proben. **Übergabe:** Sol Medium erhält die entschiedene Matrix für B02/Q02 und die Fachowner erhalten betroffene Doku-/Helpänderungen. Standortnachweise bleiben getrennt. Jede spätere Änderung einer hier geprüften Komponente nennt die dadurch zu erneuernden Nachweise.

### Modellaufträge für technische Pakete

Jede Zeile verwendet die Ablaufkarte aus R00 und endet mit Ergebnissen, Doku-/Helpnachweis, verbleibenden Lücken und Übergabe an den genannten Prüfer. Ausführung von Gates, Benchmarks und Abschlusslogik liegt stets bei Sol Medium; Terra darf nur klar abgegrenzte Dokumentationsprüfungen selbst ausführen. „Astra bei neuer Vertragsfrage“ bedeutet keine Pflicht zu einem erneuten Review bereits ausreichend geprüfter unveränderter Verträge.

| Paket | Bearbeitung / Eingang | Fachliche Gegenprüfung / konkretes Übergabeergebnis |
|---|---|---|
| R00 | Terra Low inventarisiert die freigegebenen Quellen und Artefakte; Sol Medium ordnet sie zu. | Sol Medium bestätigt die Arbeitsbasis und vollständigen Ablaufkarten. |
| M01 | Sol High trennt vorhandene Produkt-, Test- und Infrastrukturfehler anhand Originalcode und Logs. | Sol High prüft fachliche Gegenfälle; Astra High nur bei neuer ungeklärter Machine-/Ownershipfrage. Sol Medium erhält den gezielten Prüfauftrag. |
| M03 | Sol High bearbeitet die vier Commitbefunde und klärt die drei genau benannten Nachweisfragen aus `dfdb4ca`. | Sol High prüft Fehlerisolation, Ergebnisherkunft und Ursachenzuordnung; Astra High nur eine verbleibende neue Content-/Abschlussfrage. Sol Medium erhält konkrete PS-/PHP-/Browser-/Laborfälle, D01 die betroffenen Fachaussagen. |
| M02 / L01 | Sol High erstellt Provider-/DP-/Membership-/Updatefälle aus den Verträgen. | Astra High bei neuer externer Identitäts-/Teilerfolgsentscheidung; Sol Medium führt autorisierte Proben, fasst Standortbelege zusammen und hält Lücken offen. |
| O01 | Sol High bearbeitet Secretzugriff über EnvBoot, Compose und Mounts nach X01. | Astra High prüft die neue Secretgrenze vor der QA. Nachweis: erlaubte Leser und negative Zugriffsfälle. |
| O02 / O03 | Sol High legt Log-/Binlog-/Backupverhalten fest. | Sol High prüft Rotation/Ausfall/Restore; neue Änderung des Recoveryvertrags gegebenenfalls an Astra High. Ergebnis: wirksame Politik und nachgewiesene Diagnose-/Recoverygrenze. |
| O04a / O04b / O04c | Sol High entwirft Build-, Runtime- und Lieferänderung einzeln; Terra Low inventarisiert Pins und Verbraucher. | Sol High prüft Verbraucher/Targets; Astra High nur neue X01-/Secret-/Prozessvertragsfragen. Sol Medium misst Größe und führt Build-/Offlineabnahme aus. |
| O05 / O06 | Sol High entscheidet Cache-/Assetvertrag, Sol Medium setzt bekannte Muster um. | Sol High prüft HTTP/HTTPS, altes Browserdokument und Registryabdeckung; Terra Low führt zugeordnete Doku-/Linkänderungen nach. |
| O07 | Sol High verbindet globalen Sortier-/Filter-/Cursorvertrag und Messdesign. | Astra High nur bei ungeklärter Konsistenz-/Berechtigungsfrage; Sol High prüft Standardfälle. Ergebnis: Entscheidung mit UX-/EXPLAIN-/Lastbeleg. |
| O08 | Sol High analysiert Suitewirkung und isolierte Ausführung. | Sol High überprüft Fehlergegenproben/Entdeckung; Sol Medium misst und führt Gates aus. Keine Astra-Publikationsprüfung. |
| P01 | Sol High prüft Messdesign/Vorherquellen; Sol Medium besitzt den exklusiven Messlauf. | Astra High nur für konkret unklare mehrschichtige Kausalität; Ergebnis sind gültige getrennte Reihen samt Aussagegrenzen. |
| B01 | Sol Medium liest installierten Containerstand und führt einen autorisierten regulären Rollout aus. | Sol High beurteilt neue Abweichungen; bestehenden PHP-Fix nicht erneut erfinden. Ergebnis: Konfigurations-/Health-/betroffener Stopnachweis. |
| B02 / X01 | Sol High definiert Upgrade-/Restorefälle und zulässige Rückkehr. | Astra High prüft neue Daten-/Schlüssel-/Recoveryverträge vor Ausführung; Sol Medium liefert tatsächliche Proben. |
| D01 | Terra Low erstellt vollständiges Text-/Linkinventar und markiert geänderte Aussagen. | Sol High prüft fachliche Behauptungen gegen Owner; Sol Medium führt Bedien-/DE/EN-/No-JS-/Rollenproben aus. |
| L02 / L03 | Sol High schreibt begrenzte Laborfälle für SYSTEM/Storage beziehungsweise ESXi/AD/HTTPS. | Astra High für neue ungeklärte externe Zustands-/Identitätsfragen; Sol Medium führt autorisierte Proben und Belege zusammen. |
| H01 | Sol High reproduziert die drei benannten Hypothesen eng. | Bestätigte kritische neue Verträge an Astra High; widerlegte Hypothesen begründet schließen. Kein neuer Defekt allein aus Vermutung. |
| Q01 / Q02 / R01 / G01 | Sol Medium besitzt Test-/Releaseausführung, Ergebnisbewertung nach entschiedenen Kriterien und alle Publikationsschritte; Terra Low unterstützt mit Inventaren. | Sol High klärt neue fachliche Befunde in eigenem Paket. Kein Astra-Schritt im Commit-/Push-/Freigabeprüflauf. |

## Admin-Funktionen: E0 bis E6

Alle elf Features sind weiterhin umzusetzen und abzunehmen. Bestehende Helfer werden verwendet; `Adminplan:R01` bis `Adminplan:R12` bleiben Grundlage, mit den oben beschriebenen Aktualisierungen zu `edit_version`, Kopierhilfe und Zugangssperre.

| Paket | Feature und offene Umsetzung | Abhängigkeit / Abnahme |
|---|---|---|
| E0a / lokal abgeschlossen | Gemeinsame Feld-/Wirkungsbeschreibung und Leseverträge einschließlich Sortierung, Identität, RBAC und Frische. Alte Codeverweise aktualisieren. | Grundlage für F05/F08/F02; keine zweite Schreiballowlist. Vollständige Matrix: `qa-artifacts/consolidated-session-backlog/20260913-e0a-contract-inventory.md`. |
| E0b | Vollständige serverseitige Entwürfe, erlaubte Writer/Erzeuger, Locks, Bounds, Bestätigung, Ergebnisretention, Cleanup und Schema konkretisieren. | F10/F01/F09; Entwürfe erkennen relevante Kindtabellen-/Rolloutänderungen zusätzlich zu `edit_version`. Bestätigtes Ergebnis überlebt den benötigten Wiederholungszeitraum; X01 gilt. |
| E0c | Getrennte Teilverträge für Ereignisquellen/Zeiten, Auftragsbeobachtung und Problemepisoden mit Retention. | F03/F11/F06/F07 benötigen jeweils ihren Teilvertrag; kein vollständiger Chronikausbau als pauschale Voraussetzung für Beobachtung oder Vermerke. |
| E1 / F04 / lokal abgeschlossen | Kopierbuttons für exakten VM-Namen, Soll-/Rollouthostname, konfigurierte IP, MAC und Job-ID verwenden den gemeinsamen sichtbaren Wert-/Feldowner. | Mehrere NICs sind eindeutig, leere beziehungsweise DHCP-inaktive Werte haben keine Aktion, aktueller Feldwert, Tastaturfokus und ehrlicher HTTP-/Clipboard-Fallback sind automatisiert geprüft. |
| E1 / F05 | Dashboardzahlen „VMs“/„MECM ausstehend“ verlinken; lesende Gesamt-VM-Liste mit passendem Scope, Filter, Sortierung und Pagination. | Zähler und Treffer verwenden dieselbe Auswahl einschließlich definierter Vorlagenbehandlung; Rechte vor Query/Anzahl; globale Ordnung vor LIMIT. |
| E2 / F08 | Verwendungsnachweise für Paket, OS, VLAN und Zugangsdaten mit direkten/mittelbaren/historischen Referenzen und Links. | IDs versus Namen, unbekannte Herkunft, aktive Jobs einschließlich cancelling und ungeklärte Create-Historie erhalten. Keine Secrets oder neue Löschfreigabe aus einer unvollständigen Liste. |
| E3 / F10 | Vorschau beim normalen Speichern mit Alt/Neu/Wirkung, Bearbeiten/Zurück und verbindlichem Entwurf. | E0a/E0b; vollständiger Fingerprint, TTL, Doppel-Submit und verlorene Erfolgsantwort, RBAC/CSRF und Versionskonflikt. Keine vermeintliche Remote-Rekonfiguration aus einem gespeicherten Sollwert versprechen. |
| E3 / F01 | Atomare Sammelbearbeitung einer expliziten VM-Auswahl: CPU/RAM, OS und Pakete hinzufügen/entfernen/ersetzen. | E0a/E0b und gemeinsame Vorschaukomponenten, keine vollständige F10-Lieferung als Voraussetzung; unverändert/leeren getrennt, blockierte/fremde/verschwundene IDs und Maximalumfang prüfen. Keine Teilwrites, Scopeerweiterung oder unnötiges Ersetzen unveränderter Kindtabellen. |
| E4 / F02 | Lesender Missions-/Vorlagenvergleich mit eindeutiger 1:1-Paarung, vollständigem Sollvergleich und Unterschiedsfilter. | Felddarstellung aus E0a; kein fertig geliefertes F10 nötig. IDs/Runtimezustand nicht als Sollabweichung zählen, exakte Namen und Multiplizität erhalten, unklare Paarung sichtbar. |
| E4 / F09 | Vorlagen-Assistent mit getrennten ESXi-/Windows-Namensvorschauen, Nummerierung und bewussten Zielzuordnungen. | E0a/E0b und gemeinsame Vorschaukomponenten; bestehenden Clone-Owner erweitern, Quelle konsistent prüfen, konkurrierende Namensvergabe für alle Erzeuger absichern. Atomare Anlage, keine MAC-/MECM-Laufzeitübernahme, kein automatischer Deploy. |
| E5 / F03 | Gemeinsame VM-Chronik aus belegbaren Status-, Job-, Client- und Auditereignissen mit Filtern und Cursor. | Quellberechtigungen vor Query/Count, eindeutige Zeit-/ID-Ordnung; keine erfundene historische Job-/Rolloutzuordnung. Retention/fehlende Quellen erklären. |
| E6 / F06 | Aufträge beobachten, Abschlussmeldung im Portal, gelesen markieren und Beobachtung beenden. | Eigener E0c-Teilvertrag; begrenztes Polling, Sessionfreigabe, idempotentes Abonnement, Offline-/Lösch-/Purgeverhalten. Retry bleibt eigene Job-ID; keine automatische Mail-/Pushzustellung. |
| E6 / F07, spätere Lieferung | Zuständigkeit und Bearbeitungsvermerk an definierten offenen Problemen, mit direkter Selbstübernahme für den Einzeladminbetrieb. | Eigener E0c-Teilvertrag; Chronikanschluss F03 kann separat folgen, Ereignis-/Auditvertrag vorher festlegen. Stabile Episode, CAS, deaktivierte Nutzer, Rollen und Retention. Vermerk ändert keinen technischen Befund; neue Episode erbt keine alte Erledigung. |
| E5 / F11, spätere Lieferung | Tages-/Wochenzeitachse für geplante Aufträge, Staffelung und tatsächliche Ausführung. | Geeignete Jobzeitquellen beziehungsweise additive Zeitpunkte über alle Terminalwriter. Frühester Start ist kein garantierter Start; keine Dauer aus `updated_at`; UTC/DST und Abbruch vor Claim testen. |

**Gemeinsame Abnahme:** Je Feature die fachlichen Fälle des Detailplans, sinnvolle Fehlerzustände und passende automatisierte Nachweise erfüllen. Neue Migrationen konvergieren mit dem frischen Schema. DE/EN-Hilfe, Form-/CSS-/Modal-/Bestätigungs-/Deep-Link-/Assetverträge und erzwungene responsive Umbrüche gehören zur jeweiligen Lieferung.

**F04-Status 13.09.2026:** Lokal vollständig umgesetzt und automatisiert
geprüft. VM-Liste und Editor kopieren den exakten VM-Namen sowie gewünschten
Windows-Hostnamen; ein abweichender aktiver Rolloutname bleibt getrennt. Jede
konfigurierte IP und MAC ist über ihre Netzwerkkartenposition benannt, und der
Editor liest den aktuellen Feldwert statt eines Render-Snapshots. Leere und
DHCP-inaktive IP-Werte bieten keine Aktion. Erfolg wird erst nach erfüllter
Clipboard-Promise gemeldet; fehlende oder verweigerte Browserrechte zeigen den
manuellen Fallback, während Wert und Fokus erhalten bleiben. Die Deploy-
Detailansicht nennt und kopiert die Auftrags-ID. Die Unit-/Static-Suite bestand
1.787 Tests mit 41.543 Assertions ohne Skip. Der vollständige funktionale
Chromiumlauf ist laut Playwright-Endstatus grün; das kombinierte Gate endete erst
danach am bekannten UX02-Baseline-Manifest `12/18`. Der QA-Stack wurde entfernt.
Der nach dem Read-only-DHCP-Gegenreview erneuerte Lauf
`20260913-f04-review-final.json` bestand 4/4 Gates.

**E0a-Status 13.09.2026:** Lokal als fachliche Grundlage abgeschlossen. Die
Bestandsaufnahme ordnet alle elf Features ihren vorhandenen Routen, Readern,
Writern, Audit-/URL-Ownern und vor der Query anzuwendenden Rechten zu. Sie
klassifiziert alle editierbaren Missions-, VM- und Kindwerte nach gespeichertem
und wirksamem Sollwert, Herkunft, Vergleich und belegbarer Wirkung, ohne eine
zweite Schreiballowlist einzuführen. F02/F03/F05/F08 besitzen konkrete
Snapshot-, Fehler-, Frische-, Sortier- und Paginationverträge. Alle heutigen
Create-/Save-/Clone-/Aktivierungs-/Importeinstiege sowie SQL-Kollision, rohe
ESXi-Identität und MECM-Claim sind getrennt inventarisiert. Beleg:
`qa-artifacts/consolidated-session-backlog/20260913-e0a-contract-inventory.md`.
Details: `qa-artifacts/consolidated-session-backlog/20260913-f04-report.md`.
Der finale Paketlauf bestand 7/7 ausgewählte Fast-Gates ohne Skip oder
Infrastrukturfehler.

## UX01: Bereitstellung vorbereiten: Blocker verständlich bündeln

**Zusätzlicher Nutzerauftrag, Priorität P1, lokal vollständig umgesetzt und automatisiert geprüft; persönliche Bedienabnahme noch offen.** Der gemeinsame Vorbereitungskopf, die kurze Liveansage, der fokussierbare erste Grund und die rein lesende No-JS-Neuprüfung sind umgesetzt, ohne die vollständige Queueentscheidung zu verändern. Ein serverseitig neu bestimmter Abhilfe-POST hält ausschließlich erlaubte Queuefelder als einmaligen Sitzungsentwurf; externe Abhilfe, Identity-Adopt und Vorschau-Abbruch kehren ohne Eingabeverlust zurück. Rollen ohne Zielrecht behalten den Grund und verlieren nur die Aktion. Fast ist vollständig grün; alle 273 funktionalen Chromium-Fälle sind grün. Das kombinierte Browsergate stoppt erst danach am separat offenen menschlichen UX02-Sollbildsatz. Offen bleibt die vorgeschriebene beobachtete Bedienprobe. Entwurf und Nachweise: `qa-artifacts/consolidated-session-backlog/20260913-ux01-design.md` und `20260913-ux01-report.md`.

**Ziel:** Vor dem Einreihen erkennt der Admin den geprüften Umfang, ob er fortfahren kann, welche unabhängigen Voraussetzungen fehlen und welche Handlung zuerst sinnvoll ist. Weniger wiederholte Meldungen und weniger Suchaufwand sind die Abnahmekriterien. Ein pauschal grüner Gesamtstatus darf unbekannte oder gesperrte Teilzustände nicht verdecken.

**Erste Istkarte aus lesender Codeprüfung am 12.09.2026:** Diese Befunde belegen die Implementierungsstruktur; die konkrete visuelle Wirkung wird erst im Browser und mit dem Nutzer abgenommen.

| Belegter Iststand | Konsequenz für UX01 |
|---|---|
| `lib/deploy_queue_panel.php` platziert die Blockerzone vor dem Formular. `lib/deploy_queue_blocker_view.php` rendert Zähler, „Zum ersten Blocker“ und gleichrangige rote Alerts mit wiederholtem Blockerlabel. | Wortwiederholung und gleichrangige Hervorhebung gezielt abbauen; Ursachen in einen kompakten Bereich mit sinnvollem nächsten Schritt überführen. |
| Eine fehlende Mission erscheint in der Blockerzone und im VM-Bereich. Fehlende ESXi- und Ansible-Zugänge sind zwei verschiedene Voraussetzungen mit demselben Linkziel. | Den ersten Fall auf echte redundante Darstellung prüfen; beim zweiten Fall beide fachlichen Anforderungen sichtbar halten, auch wenn ihre gemeinsame Abhilfe nur einmal verlinkt wird. |
| Netzwerkhinweise, Missionsabweichungen, Host- und Capabilitywarnungen kommen aus getrennten Renderpfaden. | Umfang, Quelle und Sperrwirkung je Hinweis inventarisieren. Gleichheit oder Überflüssigkeit ist hier noch keine erwiesene Tatsache. |
| Abhilfelinks transportieren den aktuellen Formularzustand nicht; Identity-Adopt führt nur Mission/ESXi mit, Vorschau-Abbrechen nur die Mission. Die Vorschau lässt das vollständige Formular weiter sichtbar. | Rückkehr, Übernahme und Abbruch ohne Eingabeverlust prüfen und eindeutig zwischen Vorschau bestätigen und Formular überarbeiten führen; kein zweiter konkurrierender Schreibweg. |
| Die No-JS-Probe verwendet bereits vollständig gültige Querywerte. Bei zuvor gesperrtem Formular können geänderte Selects ohne JS den deaktivierten Queuebutton nicht neu bewerten. Der Sprunglink führt auf ein nicht fokussierbares `div`. | Einen lesenden serverseitigen Prüfschritt für das aktuelle Formular vorsehen, ohne Queue-/Remote-Nebenwirkung; reale Tastatur-/Fokusabnahme ergänzen. |
| Liveprüfungen sind bereits verzögert gebündelt, auf einen laufenden Request begrenzt und gegen veraltete Antworten geschützt. Ein sichtbarer Prüfzustand fehlt; die ganze `aria-live=polite`-Zone wird ersetzt. | Vorhandenen Race-Schutz erhalten, sichtbare Aktualität und kurze zustandsbezogene Ansage ergänzen. Wiederholtes Vorlesen ist eine zu prüfende Wirkung, kein bereits gemessener Befund. |

1. **Ein kompakter Vorbereitungsbereich am Formular und seiner Aktion.** Er nennt den tatsächlich geprüften Modus und Scope sowie eine klare Zustandsaussage. Ein Entwurfsbeispiel ist „2 Voraussetzungen offen · 4 VMs betroffen“. Voraussetzungen zählen belegbar eindeutige Ursachenidentitäten, betroffene VMs die eindeutigen VM-IDs im normalisierten Scope. Globale Voraussetzungen werden keiner erfundenen VM-Menge zugerechnet; ohne vollständige Zuordnung entfällt diese Zahl mit erklärter Grenze. „Bereit“, „Voraussetzungen fehlen“, „wird geprüft“ und „nicht verlässlich geprüft“ sind Darstellungszustände aus dem vorhandenen Entscheidungs-/Requestzustand, keine neuen gespeicherten Jobstatus.
2. **Jede unabhängige Sperrursache bleibt ohne Aufklappen verständlich.** Eine kurze Zeile erklärt Ursache und passende Abhilfe; ausführliche Belege, VM-Beispiele und technische Hintergründe dürfen aufklappen. Semantisch identische Wiederholungen an mehreren Stellen werden am gemeinsamen Präsentationsowner zusammengeführt. Gleicher Meldungstext oder dasselbe Linkziel allein beweist keine gemeinsame Ursache. Keine Meldungsquote und kein „nur die ersten drei“, das weitere Sperrgründe verschwinden lässt.
3. **Ein nachvollziehbarer nächster Schritt.** Echte Voraussetzungen stehen vor ihren Folgefehlern; ohne nachgewiesene Abhängigkeit gilt eine stabile, erklärte Anzeigeordnung. Es wird keine künstliche Reihenfolge zum Abarbeiten erzwungen. Der zuerst empfohlene Link führt möglichst zum betroffenen Feld, sonst über die vorhandenen URL-Helfer zur richtigen Seite und Sektion. Alle übrigen Abhilfen bleiben erreichbar. Rollen ohne Änderungsrecht erhalten weiterhin eine verständliche Ursache; Aktionslinks richten sich nach der Zielberechtigung.
4. **Warnungen und zusätzliche Informationen getrennt und ruhig.** Nicht sperrende Hinweise dürfen keinen gesperrten Aktionszustand suggerieren. Andere Darstellungen desselben Zustands werden auf Mehrwert geprüft; eigenständige Service-/Inventar-/Jobbefunde behalten Quelle und Geltungsbereich. Der Nutzer braucht keinen zusätzlichen Assistenten, keine weitere Bestätigung und keine dekorative Checkliste über bereits vorhandenen Meldungen.
5. **Aktualisierung ohne Unruhe oder Datenverlust.** Formzustand einschließlich Auswahl, abgewählter Checkboxen, deaktivierter gefüllter Felder, Filter und Sortierung bleibt erhalten. Liveantworten müssen zum aktuellen normalisierten Formular gehören; verspätete Antworten dürfen einen neueren Zustand nicht überschreiben. Kein springender Fokus, wiederholtes Vorlesen ganzer Meldungslisten oder dauerhaft blinkender Status. Vorübergehender Ausfall erklärt den letzten belegten Prüfstand und den zulässigen erneuten Prüfweg, ohne daraus eine aktuelle Freigabe abzuleiten. Nach Rückkehr von einer Abhilfeseite, Identity-Adopt und Vorschau-Abbrechen Zustand erhalten und Voraussetzungen erneut prüfen. Den vorhandenen sicheren Formstateweg erweitern; keine Secrets oder CSRF-Tokens in Rückkehr-URLs schreiben.
6. **Vollständiger Kernablauf ohne JavaScript.** Aus einer zunächst ungültigen Auswahl muss eine korrigierte Auswahl über einen lesenden serverseitigen Prüfschritt erreichbar sein. Ein deaktivierter Button darf keine Sackgasse erzeugen. Die Prüfung erhält Eingaben und legt keinen Job an; erst eine ausdrücklich gewählte Queue-/Staffelaktion darf nach ihrer eigenen aktuellen Prüfung schreiben. Mit JavaScript genügt die bestehende automatische Liveprüfung, ohne zusätzlichen Pflichtklick.

**Geschützte Fachverträge:** `deploy_queue_blockers()` bleibt die vollständige Entscheidung für Serverrendering, Liveendpoint, Vorschau und unmittelbare Prüfung vor dem Write. Der Browser baut keine eigene Sperrlogik, Mode-/Prioritätsliste oder alternative VM-Auswahl. Queue/Staffelung und Worker-/Retry-Nachprüfungen behalten ihre zuständigen Owner und Transaktionsgrenzen. Verdichtung ändert weder Grundmenge noch Grenzen, Blockier-/Warnwirkung, Rechte oder externe Voraussetzungen. Die vollständigen Counts und Auslassungsangaben kommen weiter aus `lib/deploy_preflight_bounds.php`; Renderdetails entscheiden niemals über Freigabe. Die drei Achsen des Deployservicestatus bleiben in jeder Detailansicht getrennt. Neue dauerhafte Darstellungskonventionen werden mit den bestehenden UI-/Blockerverträgen und ihren Tests abgestimmt.

Die serverseitige Präsentationsabbildung muss jede Variante der vorhandenen Entscheidungsunion abdecken; ein neuer Variantentyp ohne Darstellung fällt in der Vertragsprüfung auf. Ein trotzdem unbekannter oder nicht darstellbarer Befund bleibt als neutral lokalisierter Grund sichtbar und darf niemals aus der Zusammenfassung verschwinden oder „Bereit“ ergeben. Fehlende Präsentationsabdeckung wird von einer ausgefallenen fachlichen Quelle unterschieden. Beide Grenzen führen zu einer ehrlichen Anzeige ohne neue Freigabeentscheidung im Browser.

Queue, Staffelung und Retry erhalten jeweils einen Eintrag mit Formular/Aktion, Scopequelle, aktuellem Fachowner, Rücksprung und betroffener Darstellung. UX01a legt fest, welche Oberfläche verändert wird und welche vorhandene Oberfläche mit begründetem Regressionsnachweis bleibt. Retry erhält keine zusätzliche Queueentscheidung als Ersatz für seine eigene Remote-/Identitäts-/Netzwerk-/Voraussetzungsprüfung; gemeinsame Darstellung darf diese Unterschiede nicht verdecken.

| Etappe / Modell | Eingang und begrenzte Arbeit | Ergebnis, Abnahme und Übergabe |
|---|---|---|
| UX01a / Sol High; Terra Low für Textinventar | Q01-Quellstand, tatsächliche Server-/Live-/Vorschauausgaben, Form-/Blocker-/Preflightowner und bestehende Tests. Terra erfasst nur Wortlaute, Orte und vorhandene Links. Sol ordnet Ursachen, Scope, Sperr-/Warnwirkung, Unknown, Quelle, Gruppierungsidentität und Abhilfe fachlich zu; Queue/Staffelung/Retry getrennt erfassen. | Belegte Dopplungen von unabhängigen Befunden trennen; zwei konkrete Vorher-/Nachherabläufe entwerfen: viele gleichzeitige Voraussetzungen und Änderung von Modus/VM-Auswahl. Vor Implementierung Darstellung und stabile Ordnung festlegen. |
| UX01b / Sol High | Abgenommene Ablaufkarte, vorhandene Renderer, Formstate, URL-/A11y-/Assethelfer und begrenzter Dateiumfang. Sol setzt semantische Darstellung und Liveverhalten um; Terra Medium darf vorhandene Rendering-/DE/EN-Muster in klar zugeordneten Dateien nachführen. | Gemeinsamer Präsentationsowner, alle Ursachen abgedeckt, korrekte Count-Einheiten, Formular-/Rechte-/Frischeverträge erhalten. Sol High führt einen getrennten fachlichen Gegenreview aus; Astra High nur für eine konkret neue ungelöste Vertragsfrage. |
| UX01c / Sol Medium | Implementierung und gezielter Prüfauftrag aus UX01b, unverändert vollständige Fachentscheidung als Referenz. | Bedien-, Browser-, No-JS-, Rollen- und DE/EN-Nachweise am synthetischen QA-Stand, erzwungener mobiler Umbruch, Tastatur/Fokus und angemessen begrenzte Liveansage. Passende bestehende Gates über `scripts/check.ps1`; Visuals nur nach geltendem Baselinevertrag. |
| UX01d / Terra Low für Nachführung; Sol High für Fachaussagen, Sol Medium für Bedienabnahme | Beobachtetes Endverhalten, belegte Wortwahl/Links, Grenzen und Vorher-/Nachherfälle. | Bereitstellungshilfe, Fehlersuche und betroffene DE/EN-Texte erklären den tatsächlichen Weg von Voraussetzung zur erneuten Prüfung. Ergebnis in D01/Q02 übernehmen; G01 bleibt ausschließlich bei Sol. |

**Pflichtgegenfälle:** kein/ein/viele Blocker; mehrere VMs mit gleicher Ursache und eine VM mit mehreren Ursachen; Warnung ohne Blockierung; unbekannte/veraltete Quelle; Detailbounds einschließlich ausgelassener Einträge; fehlende oder währenddessen entzogene Zielrechte; alle betroffenen Deploymodi, Queue/Staffelung und die jeweilige Retrydarstellung; schneller Modus-/Auswahlwechsel mit Antworten in umgekehrter Reihenfolge; Liveausfall; Serverfehler, Browser-Zurück, Identity-Adopt, Vorschau-Abbrechen und Rückkehr nach Abhilfe; No-JS-Korrektur eines anfänglich gesperrten Formulars ohne Write; Änderung nach Vorschau unmittelbar vor Submit. QL01, QL03, QL04 und QL05 gelten direkt, QL02 für anschließende Sammelergebnisse. Kein UX-Test darf nur die Zahl der sichtbaren Boxen prüfen und damit fehlende Gründe als Verbesserung werten.

Bestehende Nachweise gezielt erweitern: `DeployBlockerContractTest`, `DeployQueueBlockersTest`, `DeployPrerequisiteNoticesTest`, `NetworkMacContractTest`, `etappe12-ux.spec.js`, `deploy-actions.spec.js`, `deploy-form-state.spec.js` und `deploy-blocker-session.spec.js`. Diese Referenzliste beschreibt vorhandene Ausgangspunkte, keinen zweiten Runner oder Beleg für schon bestandene UX01-Abnahme.

**Bedienabnahme:** Mit denselben synthetischen Vorher-/Nachherfällen festhalten, ob der Admin Ursache, betroffenen Scope und nächsten Schritt richtig erkennt, wie viele doppelte Meldungen und notwendige Seitenwechsel verbleiben und ob er ohne Formularverlust zurückkehrt. Mindestens einen Ablauf mit dem Nutzer durchspielen; visuelles Gefallen allein ersetzt die Fachgegenproben nicht. Falls echtes UI-Verhalten noch nicht beobachtet wurde, bleiben angenommene Reibungspunkte Hypothesen. Die aktuelle Planprüfung verändert keine Portaloberfläche.

## UX02: Statuskarten ohne Textüberlauf

**Nutzerbefund vom 12.09.2026, Priorität P2, Produktkorrektur lokal abgeschlossen; persönliche Sollbildfreigabe offen.** Der beigefügte Screenshot der Systemstatusübersicht zeigte die rote Statusmarke des Bereitstellungsdienstes über den Kartenrand hinaus; „Ansible-Test“ wurde im Namen umgebrochen. Der Renderer ordnet Titel und Status nun lokal vertikal, die vollständige Statusmarke darf innerhalb dieser Karte umbrechen. Unit/Static, PHPStan und die vollständige Chromium-Suite einschließlich Geometrie, Fokus, 320–1600 Pixel und 200 Prozent Textgröße bestanden. Der visuelle Vertrag enthält den neuen Systemstatus-Zielausschnitt; dessen sechs Hell-/Dunkel-Sollbilder dürfen ausschließlich über den persönlichen Writer nach menschlicher Prüfung angenommen werden und bleiben deshalb offen. Details: `qa-artifacts/consolidated-session-backlog/20260913-ux02-report.md`.

| Belegte Quelle | Ursache beziehungsweise Prüflücke |
|---|---|
| `portal/assets/css/status.css:92`, `:101` | Das Raster erlaubt `auto-fit`-Spalten ab 180 CSS-Pixeln. Jede Karte enthält eine Flexzeile ohne `flex-wrap`; Titel und Status konkurrieren um die Breite. `min-width:0` an der äußeren Karte allein hält deren Kinder nicht innerhalb der Karte. |
| `portal/assets/css/feedback.css:7` | `.badge` verwendet global `white-space:nowrap`. Die Statusmarke beansprucht damit ihre ungebrochene Textbreite auch bei schmaler Karte. Eine pauschale globale Änderung würde viele andere Portalansichten betreffen. |
| `lib/system_status_panels.php:42` | Ein gemeinsamer Renderer erzeugt sechs feste und gegebenenfalls eine siebte AD-Karte. Der vollständige Dienststatus stammt aus `deploy_service_summary_label()`; „Manuelle Klärung nötig“ darf durch Layoutverdichtung keine andere Bedeutung erhalten. |
| `tests/e2e/specs/system-status.spec.js:323` | Vorhandene Geometrieprüfungen zählen nur Kartenreihen bei 1600, 1000 und 500 Pixeln. Sie beweisen weder die Lesbarkeit des Inhalts noch fehlende Überlappung oder vollständige Fokusrahmen. Die feste Forderung „alle Karten bei 1600 in einer Reihe“ darf die fachliche Lesbarkeit nicht verdrängen. |
| `tests/e2e/visual/runner-contract.json` | Der aktuelle Screenshotkatalog enthält nur Missionen und Bereitstellungen. Ein grüner bisheriger Visuallauf deckt die Systemstatuskarte nicht ab. |
| `status.css:365` und benachbarte Komponenten | Ein Kommentar begründet die Breakpoints noch mit fünf Karten; aktuell sind es sechs oder sieben. `.status-row-head` und `.capability-badges` erlauben zwar Zeilenwechsel zwischen Kindern, doch eine einzelne lange `nowrap`-Marke ist dadurch noch nicht abgesichert. Dies sind zusätzliche Prüfkandidaten, keine im Screenshot bewiesenen weiteren Fehler. |

**Bevorzugter Entwurf:** Titel oben, Status darunter, beides linksbündig innerhalb derselben anklickbaren Karte. Die Höhe ergibt sich aus dem Inhalt; das Raster streckt benachbarte Karten derselben Reihe gleichmäßig. Abstände bleiben auf den bestehenden Tokens. Es gibt keine pauschal große Mindesthöhe und keine erzwungene einzeilige Darstellung. Die jeweils verfügbare Containerbreite bestimmt, wie viele Karten gut lesbar nebeneinander passen; sechs oder sieben Karten sind keine feste Spaltenvorgabe.

**Alternative:** Titel und Status bleiben bei ausreichend breiten Karten nebeneinander und wechseln bei Platzmangel vollständig untereinander. Das spart bei kurzen Texten Höhe, erzeugt aber leichter wechselnde Positionen und unterschiedlich hohe Karten. Nur wählen, wenn derselbe Varianten-/Zoomvergleich mindestens ebenso gute Zuordnung und Lesbarkeit belegt. Bloßes Verbreitern der Karten löst den gemeinsamen Textvertrag nicht vollständig.

**Verbindliches Verhalten und Änderungsgrenze:**

- Titel und Status müssen innerhalb der gepaddeten Kartenfläche bleiben und dürfen sich weder gegenseitig noch Nachbarkarten überdecken. Status darf an Wortgrenzen umbrechen; sehr lange ungetrennte Inhalte erhalten einen begrenzten CSS-Umbruch als letzten Ausweg. Zuerst die Spaltenzahl und Inhaltsanordnung sinnvoll wählen, bevor gewöhnliche Titel unnötig in Wortfragmente zerfallen.
- Die flexible Statusdarstellung gilt zunächst gezielt für die Übersicht in `status.css`. Weitere Verbraucher nur nach bestätigtem Befund mitziehen; eine gemeinsame Variante braucht einen klaren Owner. Kein globaler `nowrap`-Umbau und keine zweite Definition von Statusfarben, Texten, Counts oder Berechtigungen. `portal_badge()`, Status-/Countowner, `system_status_url()` und die getrennten Detailachsen bleiben erhalten.
- Inhalt wird vollständig zugänglich angezeigt. Abschneiden, Ellipse, kleinere Schrift als Platzreparatur oder ausschließlich ein Tooltip erfüllen diese Anforderung für entscheidungsrelevante Zustände nicht. Kein `overflow:hidden`, das lediglich den Beweis des Überlaufs oder den Fokusrahmen entfernt. Technische Werte und kopierte Identitäten bleiben unverändert; Layout darf keine Zeichen in die Fachdaten einfügen.
- Jede Karte bleibt ein zusammenhängender Link mit korrektem Ziel und sichtbarem Tastaturfokus, ohne verschachtelte Bedienelemente. Quellreihenfolge, Leserichtung und Statuszuordnung bleiben stabil. Die nächste Sektion erhält auch nach mehrzeiligem Raster den gemeinsamen Interblockabstand.
- Die zusätzliche Prüfung betrachtet die gleichartigen Titel-/Statuskombinationen in Systemstatusdetails, Dashboard-Signalzeilen und Bereitstellung. Bereits vorhandenen Umbruchschutz erhalten; nur belegte weitere Fehler als abgegrenzte Folgediffs aufnehmen. Breite Datentabellen haben einen eigenen Scrollvertrag und werden nicht über einen pauschalen seitenweiten Overflowtest umgebaut.

| Etappe / Modell | Eingang und abgegrenzte Arbeit | Ergebnis / Abnahme |
|---|---|---|
| UX02a / Sol High; Terra Low für Wortlaut-/Verbraucherinventar | R00-Quellstand, Screenshotbefund, Renderer, echte Status-/Countvarianten in DE/EN und bestehende CSS-/Testowner. Screenshotmaß nicht als CSS-Viewport übernehmen. | Enger Entwurf mit Titel über Status und Vergleich zur adaptiven Alternative; betroffene Verbraucher, überprüfbare Layoutregeln und tatsächlicher Textumfang festgelegt. Kein neuer fachlicher Statusvertrag. |
| UX02b / Terra Medium; Sol High als getrennter Reviewer | Entschiedener Entwurf und benannte CSS-/Rendererdateien. Vorhandene Muster umsetzen, Statusformatierung lokal optieren, veraltete Kommentare korrigieren. | Vollständige Texte innerhalb jeder Karte, natürliche Höhe, gültige Klassen-/Token-/Linkverträge. Sol prüft Cascade, optionale siebte Karte und Folgen für gemeinsame Verbraucher. Astra ist für dieses begrenzte Layoutpaket nicht vorgesehen. |
| UX02c / Sol Medium | Synthetische Zustände aus den wirklichen Renderern und DE/EN-Katalogen; korrekter QA-/Browser-/Fontstand. | Gezielte Geometrie-, Fokus-, Reflow- und Browserabnahme über `scripts/check.ps1`; Systemstatus in den kanonischen Visualvertrag und synthetische Fixtures aufnehmen. Kein Baselineupdate durch den Agenten; neue Sollbilder nur über den bestehenden persönlichen Writer mit Begründung und geprüftem Runner. |
| UX02d / Terra Low; Sol High für Fachaussagen | Beobachtete Enddarstellung und Abnahmebelege. | Geänderte Positions-/Bedienhinweise der Systemstatushilfe in DE/EN, QA-Doku und gegebenenfalls ADR-0013 gemeinsam nachführen. Bei unveränderter Fachaussage keine zweite Statusbeschreibung erzeugen. Q01/Q02/D01 übernehmen den Nachweis; G01 bleibt bei Sol. |

**Prüfmatrix:** Sechs und sieben Karten mit den tatsächlich erlaubten Rollen-/AD-Konfigurationen; alle relevanten Statuslängen einschließlich „Manuelle Klärung nötig“, unbekannt, nicht geprüft, Legacy und mehrstelligen Abweichungszahlen; DE/EN, hell/dunkel, Tastatur, erhöhte Textgröße, 100/150/200 Prozent Browserzoom und schmale bis breite Container. Als Ausgangspunkte 320/360, 768, 1024, 1366, 1600 und 1920 CSS-Pixel sowie unmittelbar unter/an/über jedem tatsächlich verwendeten Umbruch wählen. Zoom und CSS-Viewport dokumentieren; eine andere Gerätepixeldichte allein ersetzt keine Zoom-/Textvergrößerungsprobe. Nicht jeden Variantenfaktor blind kartesisch vervielfachen: die längsten/kleinsten kritischen Kombinationen vollständig und die übrigen Ownerzustände mit gezielten Gegenfällen prüfen.

**Abnahme misst Inhalt, nicht nur Raster:** Titel-/Badgeboxen und nötigenfalls Textzeilen liegen im nutzbaren Kartenrechteck; keine Überlappung, kein verdeckter Text und kein horizontaler Scrollzwang dieser Navigation. `scrollWidth/clientWidth` allein genügt nicht, weil zwei Kinder innerhalb eines Elternrechtecks überlappen können. Wortlaut und semantische Statuszuordnung vor/nach Umbruch vergleichen, Fokus und Sprungziel tatsächlich bedienen und Abstand zur Folgesektion messen. Bestehende `SystemStatusOverviewContractTest`, `StatusSpacingContractTest`, `CssClassContractTest` und Browserfälle gezielt erweitern. Bekannte lange Zustände müssen synthetisch tatsächlich erzeugt werden; sechs grüne Kurzlabels ersetzen diese Probe nicht. Screenshots ausschließlich im vorgesehenen `visual`-Projekt gegen `virtusphere-qa` und niemals mit realen Betriebsdaten. Diese Planänderung setzt noch keine CSS-/Produktkorrektur um.

## Gemeinsame Bedienbausteine für UX03 bis UX06

**Umsetzungsstand 13.09.2026:** UX03 bis UX06 sind für ihre festgelegten bestehenden Verbraucher lokal vollständig umgesetzt und automatisiert geprüft. Die vier Pakete verbessern vorhandene Abläufe und werden zugleich von den betroffenen F01-bis-F11-Funktionen konsumiert. Der Gesamtplan besitzt die Bedienanforderungen; E0a besitzt Feld-/Wirkungsbeschreibungen und E0b ausschließlich den bestätigungsgebundenen Entwurfs-/Writevertrag. Es entsteht keine parallele fachliche Vertragsquelle und kein neues Frontendframework.

| Baustein | Bestehende Grundlage und Änderungsgrenze |
|---|---|
| Formzustand und Eingabeschutz | `lib/forms.php`, `lib/deploy_form_state.php`, registrierte Form-/Deployassets und der gemeinsame Bestätigungsdialog. Gültige Eingaben, Fehler, ungespeicherte Änderungen und bestätigte Entwürfe bleiben verschiedene Zustände. Gemeinsame Darstellung und Übergänge mit fokussierten Adaptern; keine zweite Formular-API und kein pauschaler Umbau aller Writer. |
| Kontext und Rückkehr | Vorhandene URL-Helfer für Mission, VM, Auftrag, Protokoll, Einstellungen und Hilfe sowie `lib/portal_sort.php`. Ein begrenzter gemeinsamer Kontextvertrag ergänzt diese Helfer; Filter, Zielscope und Rückkehr sind keine Berechtigung und keine Schreibfreigabe. |
| Wirksame Einstellungen | Feldbeschreibung aus E0a und vorhandene Default-, Vererbungs-, Standort-, Netzwerk- und Rolloutowner. Gemeinsame Präsentation konsumiert deren Entscheidungen samt Herkunft; keine Berechnung derselben Priorität in PHP-View und JavaScript. |
| Aktionsfolgen und Diagnose | Vorhandene Write-/Ergebnisbelege, Status-/Terminalpresenter, `portal_badge()`, Zeit- und URL-Helfer. Gemeinsame Ergebnisdarstellung verwendet fachlich belegte Aussagen; keine neue gespeicherte Statusachse oder globale Erfolgsliste. |

Vor Implementierung die tatsächlichen Verbraucher und ihre Owner in der Ablaufkarte zuordnen. Einen gemeinsamen Baustein zuerst an zwei passenden bestehenden Abläufen nachweisen, dann die übrigen betroffenen Verbraucher einbinden. Bereits sichere Sonderverträge, insbesondere vollständiger Deploy-Formstate, Scope, Bestätigung und Rolloutidentität, bleiben erhalten. Neue Module nur über die bestehenden Asset-/Modulregistrierungen einführen. Die gemeinsame Grundlage darf kleine, unabhängige Lieferungen nicht an einen vollständigen Portalumbau koppeln.

### UX03: Arbeitskontext und Rückkehr erhalten

**Status 13.09.2026:** Lokal abgeschlossen. Der geschlossene URL-Kontext ist an Missions-/Vorlagenliste, Missionsdetails, VM-Liste und VM-Editor angeschlossen; Erfolg und serverseitiger Rückweg verwenden stabile Zeilenfragmente. Zwei getrennte Tabs, Speichern, direkter beziehungsweise manipulierter Einstieg und No-JS sind automatisiert geprüft. Der vollständige funktionale Chromiumlauf erreichte anschließend den Visual-Harness; dessen unveränderte UX02-Lücke von sechs persönlich freizugebenden Systemstatusbildern bleibt separat offen. Bericht: `qa-artifacts/consolidated-session-backlog/20260913-ux03-report.md`.

**Ziel:** Auf dem Weg Mission beziehungsweise Vorlage → VM → Auftrag → Protokoll bleiben Objekt und Zusammenhang eindeutig. Rückkehr nach Erfolg, Abbruch, Hilfe oder Behebung einer Voraussetzung führt zur passenden Ausgangsansicht mit zulässigen Filtern, Sortierung, Seite und expliziter Auswahl. QL01 gilt weiterhin für Fehler; UX03 ergänzt die erfolgreichen Wege.

- Kontext zeigt tatsächliche Objektidentitäten und verwendet bestehende Zielhelfer. Missionlose Systemjobs erhalten keine erfundene Mission. Direktaufrufe ohne Herkunft zeigen einen sinnvollen berechtigten Elterneinstieg.
- Rückkehrzustand wird pro Arbeitsablauf/Tab getrennt und begrenzt geführt; ein globaler letzter Sessionwert darf andere Tabs nicht überschreiben. Lebensdauer, Maximalumfang, Logout, alte Links und Rechteverlust vor der Umsetzung festlegen. URLs enthalten nur dafür geeignete nicht geheime Navigationsparameter; keine Passwörter, CSRF-Tokens oder vollständigen Formularbundles. Falls eine serverseitige Referenz nötig ist, ist sie nutzergebunden, befristet und beim Lesen erneut berechtigt.
- Kein frei übernommenes Rücksprungziel und keine offene Weiterleitung. Ziele und Parameter werden durch den jeweiligen URL-/Scopeowner validiert. Entfernte oder inzwischen gesperrte ausgewählte IDs werden sichtbar behandelt; eine leere oder abgelaufene Auswahl wird nie zu „alle“. Erfolgreiche Navigation darf keinen Write wiederholen.
- Listenposition möglichst anhand einer stabilen Zeilenidentität wiederherstellen, andernfalls zur erhaltenen Ansicht mit erklärter Änderung zurückkehren. Eine nach Löschung ungültige Seite wird sinnvoll begrenzt. Wiederhergestellter Scroll darf den Tastaturfokus nicht auf ein falsches oder unsichtbares Element setzen. Scrollwiederherstellung bleibt Komfort, der sichere serverseitige Rückweg funktioniert auch ohne JavaScript.
- Ein Abhilfelink aus einem bearbeiteten Formular koordiniert UX04 und den vorhandenen Formstateweg: erlaubte Eingaben erhalten oder vor tatsächlichem Verlust warnen. Eine Auswahlrückkehr allein gilt nicht als vollständige Formularwiederherstellung.

**Abnahme:** Zwei Tabs mit verschiedenen Missionen/Filtern, Browser-Zurück/Vorwärts, neuer Tab, direkter Loglink, Erfolg/Abbruch, paginierte Liste nach Löschung, entfernte VM, Rollenwechsel und abgelaufener Kontext. Objektbezug, Auswahl, Zielrecht und Fokus bleiben nachvollziehbar; No-JS erhält den fachlichen Rückweg.

### UX04: Ungespeicherte Änderungen schützen

**Status 13.09.2026:** Für Missionseinstellungen und VM-Editor lokal abgeschlossen. Ein ausschließlich flüchtiger, secret-sparsamer Formularvergleich erkennt Änderung, exakte Rücknahme und dynamische Zeilen. Verlassende Links verwenden den einen gemeinsamen Dialog; Browsernavigation den nativen Schutz. Fehler-Rerender bleiben ungeklärt/dirty und werden nur über einen erfolgreichen GET-Vergleich aufgelöst; No-JS macht keine Statusbehauptung. 1.768 Unit-/Static-Tests ohne Skip sowie alle übrigen Fast-Gates sind grün, der gezielte Browserlauf bestand 5/5. Der kanonische Gesamtbrowserlauf erreichte nach allen funktionalen Fällen den Visual-Harness und endete nur an den sechs separat offenen UX02-Systemstatusbaselines. Bericht: `qa-artifacts/consolidated-session-backlog/20260913-ux04-report.md`.

**Ziel:** Schon vor dem ersten Absenden ist erkennbar, ob ein Formular ungespeicherte Änderungen enthält. Beim tatsächlichen Verlassen mit solchen Änderungen gibt es eine Rückfrage; nach nachgewiesenem Speichern entfällt sie. Der Schutz wird in bestehenden Editoren eingeführt und später an F01/F09/F10 angeschlossen.

- Gegen den passenden bestätigten Ausgangszustand vergleichen, nicht nur das erste `change`-Event merken. Änderung und exakte Rücknahme ergeben wieder unverändert. Dynamische Zeilen, abgewählte Checkboxen, erlaubte deaktivierte Werte und programmatische Formaktualisierungen berücksichtigen. Die Erkennung ist eine Komfortfunktion, keine zweite fachliche Normalisierung, Validierung oder Konfliktprüfung.
- Interne Navigation verwendet den gemeinsamen Dialog-/Fokusowner, ohne `window.confirm()` oder zweiten Modalaufbau. Abgebrochene Navigation erhält Werte und Fokus. Sprung innerhalb derselben Seite und Öffnen eines Links in einem neuen Tab verlassen das Formular nicht. Vorschau, erneute Prüfung und bestätigtes Absenden sind eigene Übergänge; kein zweiter Bestätigungsdialog nach der bereits vorgesehenen Aktionsbestätigung.
- Reload, Tab-/Fensterschließen und Navigation außerhalb des Portals verwenden, soweit der Browser es unterstützt, dessen nativen Verlassensschutz. Keine Zusage für frei formulierte Browserwarnungen, Prozessabbruch, Browserabsturz oder deaktiviertes JavaScript. Ohne JS bleiben die vorhandenen serverseitigen Fehler-/Vorschauwege nutzbar; eine Erkennung vor dem ersten Absenden wird dann nicht behauptet. Hilfe nennt diese Grenzen.
- Ein Klick auf Speichern oder Öffnen der Vorschau setzt das Formular nicht als gespeichert. Validierungsfehler und abgebrochene Bestätigung erhalten den Änderungszustand; ein verlorener Commit bleibt gemäß QL05 unklar, bis er lesend aufgelöst ist. Nach PRG/erneuter Darstellung unterscheiden sich bestätigte Ausgangswerte und erhaltene Benutzereingaben weiterhin. Sitzungsablauf und erneute Anmeldung verwenden den bestehenden Sitzungsfluss, ohne eine endlose Verlassenswarnung.
- Keine allgemeine Autosave- oder Wiederanlaufgarantie einführen. Geheimnisse und Dateiinhalt nicht für den Änderungsvergleich in URL, Browserstorage oder zusätzliche Entwürfe kopieren; für Secretfelder genügt eine Änderungserkennung ohne zweite Wertablage. Falls Dateien nach einer Navigation neu ausgewählt werden müssen, sagt die Anzeige dies ausdrücklich. Bestehende E0b-Entwürfe bleiben gesondert geregelt.

**Abnahme:** Ändern/zurücksetzen, echte No-ops, mehrere Formulare und Tabs, dynamische Zeilen, Vorschau/Bearbeiten, Bestätigung verwerfen, Serverfehler, Commit-Antwortverlust, Rückkehr aus Browsercache und Sitzungsablauf. Keine Fehlwarnung für unveränderte Formulare und kein stilles „gespeichert“ bei ungeklärtem Ergebnis; Browsergrenzen werden getrennt von Produktfehlern ausgewiesen.

### UX05: Wirksame Werte und Herkunft anzeigen

**Status 13.09.2026:** Für den VM-Editor lokal abgeschlossen. Datastore, Datacenter, Autostart sowie Start- und Stoppverzögerung verwenden die bestehenden Ansible-/Repository-Owner und zeigen wirksamen Sollwert, Herkunft und eine vorhandene VM-Überschreibung, ohne einen ESXi-Istwert zu behaupten. Leere Werte, `0`, fehlende Quellen und das Missionsautostarttor bleiben unterscheidbar. Zulässiges Zurücksetzen ändert nur das Formular, löst UX04 aus und speichert nicht; ohne JavaScript oder Schreibrecht gibt es keine inerte Aktion. Der gezielte Browserlauf bestand 5/5, die finale Fast-Lane 6/6 Gates ohne Skip. Der kanonische Gesamtbrowserlauf erreichte nach den funktionalen Fällen den Visual-Harness und endete nur an den sechs separat offenen UX02-Systemstatusbaselines. Bericht: `qa-artifacts/consolidated-session-backlog/20260913-ux05-report.md`.

**Ziel:** Betroffene Editoren und Vergleiche zeigen den wirksamen Sollwert, seine Herkunft und eine vorhandene Überschreibung verständlich. Beispiel: „Datastore: DS02 · von Mission übernommen“ oder „DS03 · VM überschreibt Missionswert DS02“. Ein gespeicherter Sollwert ist kein behaupteter ESXi-Istwert.

- E0a ordnet je Feld gespeicherten Wert, wirksamen Wert, Herkunft, mögliche Vererbung und zulässiges Zurücksetzen dem vorhandenen Fachowner zu. Leere Zeichenfolge, `null`, `0` und „übernehmen“ behalten ihre jeweilige Semantik. Ein kopierter Vorlagenwert darf nicht als fortlaufende Vererbung angezeigt werden.
- Editoren, F02-Vergleich, F09-Vorschau und F10/F01-Wirkung verwenden denselben Präsentationsbaustein. Entwurfswerte sind vorläufig und bleiben von gespeicherten Werten sowie eingefrorenen MECM-Rollouts getrennt. Der Browser erfindet weder Defaults noch eine zweite Vererbungskette.
- „Auf Missionswert zurücksetzen“ nur dort anbieten, wo der Feldvertrag dies erlaubt. Die Aktion ändert zunächst die beabsichtigte Formeingabe; der vorhandene bestätigte Schreibweg prüft Rechte, Version und Abhängigkeiten. Kein stiller Sofortwrite oder Umgehen der Netzwerk-/Job-/Rolloutsperren.
- Fehlende beziehungsweise unlesbare Herkunft sichtbar erklären, ohne einen Default zu erfinden. Nach Änderung eines Elternwerts oder einer Voraussetzung gilt die aktuelle Prüfung des zuständigen Owners; alte Vorschauen werden nicht als aktuell ausgegeben. Geschützte Quellenwerte bleiben auch im Herkunftshinweis verborgen.

**Abnahme:** Geerbt/überschrieben/zurückgesetzt, leere Werte und Nullwerte, geklonte Vorlage, Elternänderung bei offenem Editor, fehlende Quelle, Rollenverlust, Netzwerksonderregeln und gespeicherter Sollhostname gegenüber eingefrorenem Rollouthostname. Anzeige, Vorschau und tatsächlich gespeicherte Wirkung stimmen nach denselben fachlichen Regeln überein.

### UX06: Aktionsfolgen und nächste Schritte erklären

**Status 13.09.2026:** Für Deploy-Queue/Abbruch/Retry und VM-Sammelaktionen lokal abgeschlossen. Queue- und Terminmeldungen nennen Job-ID beziehungsweise exakten Staffelscope und behaupten keine Ausführung; Abbruch behauptet keinen Rollback, Retry benennt den neuen Auftrag. Bulk-Ergebnisse nennen bearbeitet/ausgewählt, werden bei jedem Skip zur Warnung und trennen Portal-, Hypervisor- und MECM-Wirkung. Strukturierte Folgelinks führen zum vorhandenen lesenden Owner. Fast bestand 6/6 Gates, der gezielte Browserlauf 18/18. Der kanonische Gesamtlauf erreichte nach den funktionalen Fällen den Visual-Harness und endete nur an den sechs separat offenen UX02-Systemstatusbaselines. Bericht: `qa-artifacts/consolidated-session-backlog/20260913-ux06-report.md`.

**Ziel:** Vor einer Aktion sind Ziel, Umfang und beabsichtigte Wirkung klar; danach nennt die Anzeige das belegte Ergebnis und den nächsten sinnvollen Schritt. Dieses Muster gilt für einzelne Änderungen und Sammelaktionen, bestehende Queue-/Staffel-/Retrywege sowie die ausgewählten Features.

- „Konfiguration gespeichert“, „Auftrag eingeplant“, „Ausführung läuft“, „extern bestätigt“ und „Ergebnis unbekannt“ werden nur im passenden fachlichen Geltungsbereich verwendet. Dies sind Beispiele für Aussagen, keine neue Statusliste. Ein erfolgreicher Portalwrite ist kein Remoteerfolg, ein erfolgreicher Teilschritt kein Abschluss des ganzen Auftrags. Quelle und Beobachtungszeit folgen QL03.
- Ergebnisse zeigen Ziel beziehungsweise expliziten Scope und die für diese Aktion zutreffenden Mengen nach QL02. Vorhandene Status-/Ergebnisowner liefern belegte Teilresultate, Rollback, übersprungene Ziele und Unknown. Bekannte Teilerfolge bleiben sichtbar, ohne unbekannte Restwirkung zu beschönigen. Lange Details dürfen aufklappen; entscheidungsrelevante Grenzen bleiben sichtbar.
- Diagnose und Hilfe beantworten zusammenhängend: **Was ist passiert? Was bedeutet das für meinen Auftrag? Was kann ich jetzt tun?** Das ist ein Inhaltsmuster, keine Pflicht zu drei zusätzlichen Meldungsboxen. Bei einfachen Erfolgen genügt ein kurzer Satz. Keine ungeprüfte Ursache aus einem generischen Fehlercode ableiten.
- Der nächste Schritt führt über vorhandene URL-/Hilfe-/Loghelfer zum konkreten Ziel und berücksichtigt dessen Berechtigung. Ohne Recht zur Behebung bleibt die Erklärung lesbar. Bei unbekanntem Ergebnis führt die Aktion ausschließlich zum lesenden Prüfweg nach QL05; kein vorgeschlagener Retry, solange der Fachowner ihn nicht erlaubt. Bei weiterhin korrekter Konfiguration kann der nächste Schritt auch Abwarten mit begründetem Prüfzeitpunkt sein.
- Die gleiche Aussage erscheint am passenden Ort einmal; zusätzliche Toasts, Badges oder Diagnosebereiche brauchen eigenen Mehrwert. Vorhandene Details und Direktlinks liefern die Vertiefung. Eine zusätzliche große Diagnoseoberfläche gehört nicht zu diesem Auftrag.

**Abnahme:** Lokales Speichern ohne externe Änderung, Einreihen vor Claim, Teilwirkung, Abbruch vor Remoteausführung, Rollback, verlorene Antwort und später aufgelöstes Ergebnis. Portalanzeige, Ergebnisbeleg, Protokoll und Hilfe widersprechen sich nicht; Rechte, Zeit, Scope und zulässige Folgeaktion sind belegbar. F10 behält seine bereits vereinbarte Vermeidung doppelter Bestätigung.

### Modellaufträge und Lieferung für UX03 bis UX06

| Etappe / Modell | Eingang und begrenzte Arbeit | Ergebnis / Abnahme |
|---|---|---|
| UX03a bis UX06a / Sol High; Terra Low für Verbraucherinventar | R00 und jeweiliger bestehender Owner; pro Paket Zustandsübergänge, Ziele, Felder, Darstellungen und Hilfeorte erfassen. UX05/UX06 konsumieren den benötigten E0a-Ausschnitt. | Eine konkrete Ablaufkarte je Paket mit Vorher-/Nachherweg und Gegenfällen. Sol entscheidet Tab-/Kontextgrenzen, Änderungszustand, Herkunft und Ergebnisbedeutung. Keine neue Persistenz aus einem bloßen Darstellungswunsch ableiten. |
| UX03b / Sol High; Terra Medium für entschiedene Link-/Kontextdarstellung | Aktuelle URL-/Form-/Scopeowner und UX03a. | Gemeinsamer begrenzter Rückkehrvertrag, vorhandene Helfer angepasst, zwei unabhängige Tabs sowie direkter Einstieg geprüft. Anschluss an UX01-Abhilfelinks ohne Formularverlust. |
| UX04b / Sol High; Terra Medium für entschiedene Labels/Markierung | Aktuelle Form-/Bestätigungs-/Sitzungsowner und UX04a; UX03-Übergang abgestimmt. | Gemeinsame Änderungserkennung und Verlassensübergänge, vorhandener Dialog, keine doppelte Bestätigung. E0b nur für den späteren Anschluss an bestätigungsgebundene Vorschauen erforderlich. |
| UX05b / Sol High; Terra Medium für entschiedene Wert-/Herkunftsdarstellung | E0a-Feld-/Wirkungsbeschreibung und UX05a. | Vorhandene Auflösung konsumieren, Herkunft einheitlich darstellen und bestehende Editoren zuerst anbinden. F02/F09/F10/F01 übernehmen die Komponente mit ihrer jeweiligen Lieferung. |
| UX06b / Sol High; Terra Medium für entschiedene Ergebnis-/DE/EN-Darstellung | Bestehende Ergebnis-/Statusowner, E0a-Wirkungsausschnitt und UX06a. | Gemeinsame beleggebundene Ergebnisdarstellung und Diagnose-/Hilfetexte. Vorhandene Fehler-/Unknownpfade erhalten; kein neuer Write-/Statusautomat. |
| UX03c bis UX06c / Sol High für getrennten fachlichen Gegenreview; Sol Medium für QA | Abgegrenzter Implementierungsstand und jeweilige Ablaufkarte; Terra Low darf entschiedene Dokuverweise nachführen. | Gezielte Fach-/Browser-/No-JS-/Rollen-/DE/EN-Prüfungen aus dem QA-Plan sowie vollständiger Bedienweg je Paket. Astra nur bei einer konkret neuen ungelösten kritischen Vertragsfrage vor QA; keine routinemäßige Astra-Abnahme. |

**Reihenfolge und Liefergrenze:** Nach UX01/UX02 folgen UX03/UX04, anschließend UX05/UX06. Bestehende Abläufe können mit ihrer eigenen Ausgangsevidenz vor den neuen Schreibfeatures verbessert werden. Die Fachprüfung neuer atomarer Entwürfe bleibt bei E0b und wird hier nicht ein zweites Mal beauftragt. F03-Chronik und F06-Beobachtung bleiben eigenständig lieferbar; F07-Zuständigkeiten und F11-Kalender folgen später. Alle elf Features bleiben im Zielumfang. Die paketübergreifenden Bedienwege und Hilfetexte gehören zu D01; Commit-/Pushprüfungen bleiben unverändert ausschließlich bei Sol Medium.

## Restabnahmen aus S4 und gemeinsamer Abschluss

| ID | Offener Umfang | Abschlussbeleg |
|---|---|---|
| Q01 | Gemeinsame Ausgangsregression einschließlich Visuals auf festgehaltenem Stand; M01 integrieren. Der letzte große Auditlauf blieb bei 37 pass/2 fail mit späteren gezielten Nachläufen. | Sol Medium führt passende vollständige Fast-/Integration-/Browsernachweise mit End-JSON und PowerShell 5.1/7 aus. Visuals ausschließlich im synthetischen `virtusphere-qa` mit gültigen Metadaten/Sollbildern. Nachweise werden an Quellen und Konfiguration gebunden; spätere betroffene Änderungen erfordern Nachprüfung. |
| B01 | PHP-Capability-Fix im Entwicklungsbetrieb aktivieren beziehungsweise seinen inzwischen erfolgten regulären Rollout prüfen. | Exakte Containerkonfiguration vor/nach regulärer Neuerstellung, Health und betroffener Stopnachweis. Aus QA-Erfolg nicht auf den laufenden Entwicklungscontainer schließen. |
| B02 | Restore mit vorhandenen synthetischen Missionen/VMs und verschlüsselten Credentials sowie restriktiven POSIX-Rechten ergänzen; Upgrade von unterstütztem Altstand. | Daten-/Schlüsselroundtrip, Rechte, Migration und Schema-Konvergenz. Der bisherige 9/9-Restore mit leeren Fachdaten deckt diese Fälle nicht ab. Standort-AD-Neufreigabe unter L03. |
| D01 | Breitere DE/EN-/No-JS-/Rollen-/Fokus-/Bedien- und Textabnahme; verbleibender aktiver Installations-, HTTPS-, AD- und Befehlskorpus. | Behauptungsmatrix mit Quelle, Verhalten und Beleg. Pro geändertem Ablauf den Weg Hilfe → Voraussetzung → Aktion → Fehler/Ergebnis → nächster Link tatsächlich durchspielen, einschließlich QL01 bis QL05 und UX01 bis UX06. Kontextverlust, erneute Eingaben und unklare Entscheidungen im vollständigen Bedienweg erfassen. Diagnose-/Hilfemuster aus UX06 fachlich prüfen. Parität/Linkexistenz allein beweisen keine richtige Anleitung. Historische Stichproben nicht als Vollprüfung ausgeben. |
| L01 | Reales MECM/DP/Share/Membership, Installer-Upgrade/Rollback, Providerfehler, Summarization, aktuelle Contentversionen, verlorene Antworten, Journal/Provenienz und alte U09-Zustände. M02 hier integrieren. | Fallbezogene Standort-/Laborprotokolle; keine automatische Auflösung mehrdeutiger Altzustände. |
| L02 | Windows unter SYSTEM: Registry-/32/64-Bit-Sicht, Snapshotpublikation, Detection/Task-Sequence, ACK-Antwortverlust, Hostname/Reboot, Netz/DHCP und Storage-Crash/Reboot. | Isolierte Windows-VMs und Wegwerfdatenträger; tatsächlich beobachtete externe Wirkung. Fünf offene Handproben einzeln führen: E9 DHCP-Rückumstellung, E3 bestehende Deployment-Types, E7 alte Kommandozeile, E11.6 Einrückung, E11.11 Kommentarblock. Die letzten beiden sind redaktionelle Handprüfungen und benötigen kein Windows-Labor. |
| L03 | ESXi/Ansible: exakte Identität, Lost-reply/Async/Create/Partial/Recovery, verzögerte MAC-V2-Callbacks und Powercycle-Reihenfolge Ein/Pause/Aus je UUID. Standort-AD/LDAPS, HTTPS und AD-Vertrauen nach Restore. | Belegte Laborfälle; keine zweite Createausführung bei unklarem Ergebnis. Powercycle mit mehreren VMs, bereits an/suspendiert/bekannter MAC, externer Einschaltung sowie Start-/Pause-/Transport-/Ausschaltfehler prüfen. Keine folgende VM nach einem unklaren oder nicht bereinigten Teilfehler. Fehlende Umgebung bleibt als einzelne Nachweislücke sichtbar. |
| Q02 | Vollständige Auslieferungs-/Release-/Supply-Chain-/Air-Gap-Abnahme des tatsächlichen Endstands unter Sol Medium. | Erforderliche finale gemeinsame Regression sowie Release-Lane, SBOM/CVE-Bewertung, Pins/Digests/Quellenmanifest, Kern-/Tools-Bundle, Fresh/Upgrade ohne Runtime-Downloads. X01-/QL-Nachweise einbeziehen. Erfolgreiche unverändert gültige Gates nicht aus Routine doppelt starten; niemals einen frühen Teilgate als Endfreigabe verwenden. |
| R01 | Konsolidierter Endbericht und Pflege der betroffenen Plan-/Registerstände unter Sol Medium. | Pro Paket Umsetzung, lokale Abnahme, Veröffentlichung, Installation und externe Abnahme getrennt ausweisen. Offene externe Nachweise können einen lokalen Abschluss begrenzen, gelten aber nie als vollständiger Gesamtabschluss. Artefakte, Risiken, Lieferstand und Prozess-/Containerstatus dokumentieren. |

Bereits bestandene Supervisor-/DB-/Signalproben, Guardprüfungen und Migrationen 0052/0053 werden nicht allein wegen ihres Alters wiederholt. Wiederholung nur bei relevanter Änderung, einem Befund oder einer notwendigen gemeinsamen Schlussabnahme. Fehlende Laborzugänge halten unabhängige lokale Pakete nicht auf.

### H01: Noch unbelegte Hypothesen und fachliche Entscheidungen einordnen

Die drei im Auditregister verbliebenen Hypothesen sind offene Prüffragen, keine bestätigten Fehler: falsche komplexe JSON-Feldtypen mit möglichem HTTP 500 statt 400 an Machine-Endpoints; eine syntaktisch gültige, aber fachlich falsche HTTP-200-ACK-Antwort mit möglicher zu früher Journalquittierung; eine direkt gestartete Paketvorlage mit ungültigem `InstallationBehaviorType` und HKLM-Fallback. Jeweils den aktuellen Quellstand und zulässigen Eintrittspfad prüfen, eng reproduzieren und erst bei bestätigtem Defekt ein Korrekturpaket anlegen. Der normale Autoimporter verwirft die letztgenannte Eingabe bereits.

Die alten Entscheidungen D-01 bis D-05 gegen die fertigen U-Pakete abgleichen, statt sie pauschal wieder zu öffnen. Noch relevante Nachweisgrenzen sind: Clientphasen nach Reset ohne erfundene Rolloutzuordnung (F03/D01), ausgelassene versus bestätigt leere Provider-Objektart ohne stille Änderung des Massretirevertrags (L01/D01) und begrenzter Rollback je Netzwerkadapter statt ungeprüfter globaler Atomizität (L02). Neue fachliche Anforderungen ausdrücklich als solche ausweisen.

## Doku, Hilfe und dauerhafte Verträge

- Pro Admin-Feature gilt die vollständige Doku-/Hilfematrix im Detailplan: zugehöriger Help-Renderer, DE/EN-Kataloge, Glossar und betroffene Betriebsdokumente gemeinsam mit der Implementierung ändern.
- UX01: Bereitstellungshilfe, Voraussetzungen-/Blockertexte, Abhilfelinks und Fehlersuche zusammen mit der vereinfachten Darstellung nachführen; zusätzliche Details gehören zum passenden Befund und müssen über die Hilfe wieder auffindbar sein.
- UX02: Systemstatushilfe und Positions-/Bedienhinweise in DE/EN gegen die tatsächliche Kartenanordnung prüfen; den Visual-/Geometrienachweis und die bei Bedarf angepasste Darstellungskonvention an ihren bestehenden QA-/ADR-Ownern dokumentieren.
- UX03 bis UX06: Gemeinsame Bedienerklärung zu Rückkehr, ungespeicherten Änderungen samt Browsergrenzen, wirksamen Werten/Herkunft und belegten Aktionsfolgen an einem passenden Hilfeowner führen; betroffene Missions-/Vorlagen-/VM-/Deploy-/Statushilfen verlinken dorthin und erklären nur ihre Besonderheiten. DE/EN-Labels und fachlich betroffene Fehlersuche gemeinsam liefern. Diagnose folgt dem UX06-Erklärungsmuster, vertiefende Protokolle und Hilfe bleiben direkt erreichbar. Neue dauerhafte Komponentenverträge in den bestehenden UI-/Form-/Link-/Statusdokumenten beschreiben; keine zweite Defaults-, Status- oder Berechtigungsreferenz.
- O01 bis O04: EnvBoot-/Compose-Verträge, Installation/Deployment, Backup, Offlinebetrieb, Image-/Toolpins und notwendige ADR-Amendments. O05/O06: Layout-/Assetregistrierung, HTTPS-Konfiguration und UI-Abnahme. O08: QA-/Quality-Gates-Dokumentation und Fortschrittsvertrag.
- M01/M02/L01/L02: `Powershell-MECM/README.md`, Client-README, `docs/operations/mecm-integration.md`, Troubleshooting und passende Systemstatus-/Clienthilfe am tatsächlichen Verhalten halten.
- Neue persistente Admin-Funktionen: Entwurfs-/Beobachtungs-/Episodenvertrag, Migration, Maintenance-Cleanup, Backup/Restore und Upgradefolge vor Lieferung dokumentieren. ADR-Nummern erst anhand des dann aktuellen Index vergeben.
- `AGENTS.md`, `GROK.md`, README und Dateigrößenbudgets nur bei tatsächlich neuen dauerhaften Ownern/Verträgen aktualisieren. Aktive Hilfe beschreibt ausgelieferte Funktionen; geplante Funktionen bleiben im Plan.

## Arbeitsweise und Definition „fertig“

Prüfungen laufen über `scripts/check.ps1`; die Auswahl richtet sich nach dem tatsächlichen Diff. Ein Owner nutzt den gemeinsamen synthetischen QA-Stack. Performance konkurriert mit keiner anderen Suite. Lange Läufe erhalten vorher einen live lesbaren Fortschrittslog; mindestens minütlich die tatsächlich beobachtete `[n/total]`-Zeile berichten. End-JSON, Exitcode und Detailbelege gehören zusammen.

Vorhandene Quellen und Nachweise bewahren. Zuständigkeiten für neue Arbeit eindeutig halten. Kritische Schreib-/Machine-/PowerShell-/Migrations-/Deployverträge gemäß Repositoryregeln gegenprüfen. Automatische Visual-Baselineänderungen sind ausgeschlossen; nötige neue Zielbilder durchlaufen den vorgeschriebenen persönlichen Writer mit Begründung.

Ein Paket ist erst fertig, wenn Umfang, passende Tests, Hilfe/Dokumentation und gegebenenfalls reale Wirkung belegt sind. „Implementiert“, „lokal geprüft“, „veröffentlicht“, „installiert“ und „im Labor/Produktivsystem abgenommen“ bleiben getrennte Aussagen.

Ein Nachweis nennt Quellmanifest, relevante Konfiguration/Schema/Tools, getestete Fälle, Ergebnis und Einschränkungen. Änderungen am Fachowner invalidieren betroffene Fachtests; Renderer/Assets/Texte invalidieren betroffene UI-/QL-/Hilfenachweise; Schema-/Runtime-/Compose-/Lieferänderungen invalidieren die betroffenen Upgrade-/Betriebs-/Performancebelege. Sol dokumentiert den Zusammenhang und wählt gezielte Nachprüfungen sowie die erforderliche gemeinsame Schlussabnahme. Eine unveränderte Datei macht ihren Nachweis nicht automatisch gültig, wenn sich eine Abhängigkeit geändert hat.

### G01: Commit, Push und zugehörige Prüfungen

**Ausschließlicher Owner: Sol Medium.** Terra Low darf Artefakt-/Dateilisten vorbereiten. Astra ist weder Ausführender noch Reviewer oder überwachender Hauptagent dieser Phase. Eingang sind abgeschlossene fachliche Paketreviews, Quellen-/Testnachweise und ein konkreter gültiger Veröffentlichungsauftrag. Der aktuelle Auftrag zur Planänderung ist keine Commit-/Pushfreigabe.

1. Sol prüft Zielbranch und vorhandene Autorisierung, tatsächlichen Arbeitsbaum, vollständigen Diff einschließlich neuer Tests/Dateien, Fremdänderungen und den zum Diff passenden Nachweisumfang. Kein pauschales Staging des Arbeitsbaums.
2. Neue Produkt- oder Vertragsbefunde gehen in ein eigenes Umsetzungspaket zurück. Dessen Review erfolgt vor erneuter Übergabe; es wird kein Astra-Gitabschlussreview daraus.
3. Erforderliche Commit-/Push-/Hookprüfungen über die bestehenden Runner/Verträge ausführen, Fortschritt selbst beobachten und Ergebnisse sichern. Bestandene unverändert gültige Prüfungen wiederverwenden; fehlende Endnachweise oder Infrastrukturfehler nicht als Erfolg auslegen.
4. Bei vorhandener Freigabe aktuellen Remotezielstand regulär prüfen/holen, Änderungen sicher integrieren und betroffene Nachweise erneuern. Semantische Konflikte bearbeitet Sol High als Facharbeit. Keine fremden Änderungen verlieren, kein Force-Push und keine Hooks umgehen.
5. Nur geprüfte eigene Änderungen committen und auf den freigegebenen Branch pushen. Bei unklarer Netzwerkantwort erst Remotezustand lesen, bevor ein weiterer Publikationsschritt folgt. Danach Commit-Hash, Pushresultat, tatsächliche Gateergebnisse, Nachweisgrenzen und verbleibenden Installationsbedarf berichten.

Ist kein Veröffentlichungsauftrag vorhanden, endet G01 mit dem konkret geprüften Übergabestand; eine erforderliche Freigabe wird erst dann eingeholt. Eine bereits vorhandene einschlägige Freigabe wird nicht erneut erfragt. Ein erfolgreicher Push beweist keine Standortinstallation.

## Sauberer Zwischenstopp, fortgeschrieben 14.09.2026

Der Arbeitsstand ist absichtlich nicht committet oder gepusht; dafür liegt keine
ausdrückliche Veröffentlichungsfreigabe vor. Es läuft kein synthetischer
`virtusphere-qa`-Stack. F04 und E0a sind lokal abgeschlossen. Der nächste lokale
Einstieg ist die direkte PC03-Nacharbeit (`loop_control.loop_var` und abweichender
`hw_name`), danach die getrennte Bereinigung der übrigen aktuellen Fast-Blocker.
Erst anschließend folgt E0b (serverseitiger Entwurfs-/Writevertrag für
F10/F01/F09), danach E0c und die davon abhängigen Features. Vor neuer Produktarbeit zuerst
Arbeitsbaum und dieses Register lesen; bestandene Belege nur wiederverwenden,
wenn Quellen und Abhängigkeiten unverändert sind.
Die E0a-Dokumentabnahme bestand im autorisierten Nachlauf 2/2 Gates
(`20260913-e0a-stop-final-rerun.json`); der erste Sandboxlauf ist wegen des
bekannten `sh.exe`-/`ntdll.dll`-Hostfehlers als Infrastrukturbeleg erhalten.

Unverändert offen bleiben die vier technischen Nachweise „später Fehler oberhalb
der Detailgrenze“, „Replay nach Bereinigung“, „vollständige Reporter-Verteilung“
und „reale PowerShell-5.1-Zeitbudgets“ sowie die zugehörigen M03-Befunde. Lokal
offen sind außerdem F01 bis F03 und F05 bis F11, die paketübergreifenden
Dateigrößen-/CSP-Befunde, Q02/R01/D01 und die noch fehlenden P01-/O04c-/X01-
Abschlüsse. Persönlich beziehungsweise extern offen bleiben sechs UX02-
Systemstatusbaselines, Standort-/MECM-/SYSTEM-/ESXi-/AD-/HTTPS-Laborfälle und
die Powercycle-ESXi-Folge und die spätere Installations-/Releaseabnahme. Q01 besitzt frühere
Ausgangsnachweise, muss aber für den tatsächlichen Endstand erneut passend
bewertet werden.

Für die Planüberarbeitung sind keine Rückfragen nötig. Bei der späteren Ausführung werden nur tatsächlich fehlende Standortinformationen erhoben: installierter Versionsstand, erreichbare Testsysteme und Wartungsfenster. Diese Informationen dürfen nicht als bereits bestätigt angenommen werden.

Die Aufnahme des Powercycle-Strangs in Ziel und Etappen wurde ausschließlich in
Plan und Register vorgenommen. Die Dokumentabnahme bestand im autorisierten
Nachlauf 2/2 Gates (`20260914-powercycle-plan-integration-rerun.json`); der erste
Lauf bleibt mit dem bekannten `sh.exe`-/`ntdll.dll`-Hostabbruch als gesonderter
Infrastrukturbeleg erhalten. Beim Halt liefen weder Subagenten noch Container des
synthetischen `virtusphere-qa`-Projekts. Produktcode, Baselines, Commit, Push,
Deployment und Standortsysteme wurden in dieser Etappe nicht verändert.

## Ergänzung 14.09.2026: Formularausrichtung und Ansible-Volltestintervall

Nutzerauftrag: Inventarhinweis soll Modus/Protokoll nicht verschieben; RAM ausrichten; automatischer Ansible-Volltest, standardmäßig 24 Stunden, unter Kataloge und Inventar konfigurierbar. Zusätzlich Randfälle, SSoT, Logik, QoL und Dokumentationsdrift durchsehen. Die damalige Grenze „keine Testläufe“ galt für diese Umsetzungsetappe; der spätere Powercycle-QA-Auftrag hat anschließend den vollständigen Fast-Bestand erhoben.

Im Worktree umgesetzt: Feldgruppen/Abstände korrigiert; getrennt überwachtes Diagnosekind am freien Deploy-Loop; gemeinsamer Test-/Ergebnisowner; transaktionale Fälligkeitsbuchung samt Migration 0055; Einstellungs-/Status-/Audit-/Help-Integration in DE/EN. Keine VM-Aktion und keine neue Ubuntu-Zeitplanung. Ergebnis und Quellmanifest: `qa-artifacts/consolidated-session-backlog/20260914-ansible-schedule-layout-review.md` und `20260914-ansible-schedule-layout-sources.json` im selben Verzeichnis.

Nachweise: Die ursprüngliche Etappe besaß nur Quelltext-/Aufrufketten-Durchsicht. Der spätere vollständige Fast-Lauf deckte die Quellen mit ab und fand drei direkt zuzuordnende Restpunkte: README verlinkt `docs/operations/ansible-full-test.md` noch nicht, `settings.php:save_ansible_test_interval` besitzt weder Browsernachweis noch offen registrierte Schuld, und PHPStan beanstandet den negierten booleschen Ausdruck in `lib/repo/ansible_test_schedule.php`. Diese Punkte werden in Etappe 5 gemeinsam mit den übrigen, getrennt geownerten Fast-Blockern korrigiert. Migration, Worker-Neustart, Veröffentlichung und reale Hostprüfung sind weiterhin nicht erfolgt; responsive/Prozess-/Konkurrenznachweise bleiben danach erforderlich.

## Ergänzung 14.09.2026: VM-Identität nach externer Löschung, nur Planung

Nutzerauftrag: Nach dem Vorfall 563 einen ausführlichen begründeten Umsetzungsplan schreiben und anschließend Dokumentation, Hilfe, Edge Cases, SSoT, Drifts, Logik und QoL gegenprüfen. Produktänderungen sind damit nicht beauftragt.

Der [Detailplan zur VM-Identität und Ersatzbereitstellung](2026-09-14-vm-identity-replacement-plan.md) besitzt die Befunde IDR-F01 bis F08, Pakete IDR-P00 bis P08, Zustandsmatrix, Daten-/Konkurrenzmodell, 35 Randfälle, QoL-Anforderungen und Dokumentations-/Help-Matrix. Befunde und Paketstatus nur dort pflegen; dieses Register bleibt der Einstieg.

Ergebnis: Plan erstellt und statisch gegen die betroffenen Quellen nachgeprüft. Bestätigt ist der zu späte UUID-Konflikt nach externer Löschung. Die Nachprüfung ergänzt Anforderungen an Rolloutrevision, Jobartefakte und geschlossene Fehlercodes. Keine Produktimplementierung, keine ausgeführten QA-Gates, keine Migration und keine Produktionsaktion. Installierter Commit und externe Laborabnahme bleiben offen. Nächster Schritt bei späterem Implementierungsauftrag: IDR-P00/P01, danach konservative Vorprüfung IDR-P02; keine erneute Erstellung oder automatische Übernahme aus diesem Plan ableiten.
