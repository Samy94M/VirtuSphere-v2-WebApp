# Befundregister: VirtuSphere und PowerShell-Integrationskette

Zugehöriger [Auditplan](2026-09-08-system-chain-audit-plan.md). Dieses Register ist die einzige fortgeschriebene Quelle für Auditfortschritt, Befunde, Entscheidungen und Nachweisverweise dieser Runde. Ausführbare Produktregeln und Gate-Definitionen bleiben an ihren bestehenden Stellen.

## Umsetzungsrunde U01–U04/U11, 09.09.2026

Diese Fortschreibung gehört zum ausdrücklich erweiterten Produktauftrag. Die folgenden Auditabschnitte und ihre Rohbelege bleiben historisch erhalten. Ausgangs-HEAD ist `68c3ea08cbfa183797f6237be6d8c6a68709e6c2`; Arbeitsbranch `codex/audit-round1-u01-u04-u11`. Bestandssicherung und neue Laufbelege liegen unter `qa-artifacts/implementation-round1/20260909/`. Das bereits geänderte Register und der fremde Admin-Workflow-Plan bleiben außerhalb der eigenen Paketcommits erhalten. Keine realen MECM-/ESXi-/AD-Mutationen.

| Paket | Ergebnis | Nachweis / verbleibende Abnahme |
|---|---|---|
| U01 / SC-007 | Historische Create-Wirkung sperrt frische Queue, Stagger, Claim und Worker-Recheck; referenzierte Credential-Ziele sind gegen Wechsel und Löschen geschützt. | HistoricalFence einschließlich Zwei-Verbindungs-, altem Snapshot- und Release-Fällen grün. Reale ESXi-Lost-reply-/Late-visibility-Abnahme offen; vor der Korrektur bereits überschriebene Credential-Zielhistorie ist nicht rekonstruierbar. |
| U02 / SC-011 | VM- und Terminalwrites sind atomar an den ursprünglichen Claim gebunden; Nachfolger und bestätigte MAC-Erfolge bleiben geschützt. Audits erfolgen nach Domaincommit. | `u02-acceptance-escalated`: 66 Tests / 684 Assertions einschließlich tatsächlicher Zwei-Verbindungs-Races und Auditgrenze grün. Vervollständigte Post-Claim-Fixture: `network-preflight-recheck`, 4 Tests / 34 Assertions grün. |
| U03 / SC-001, SC-012 | Terminaldiagnose vor Reaperabschluss; gemischte Charge atomar; vollständige Create-Sperrmenge vor VM-Konvergenz. Cleanupdiagnosen verändern weder Terminaljob noch ursprünglichen Ausgang. | Reaper-/Cleanup-Fälle und Zwei-Verbindungsprüfung mit umgekehrter Heartbeat-Reihenfolge grün in `final-postfix-focused` (43 Tests / 553 Assertions mit weiteren Paketen). |
| U04 / SC-002 | Entscheidbare partielle Create-Jobs setzen im ursprünglichen Modus und Umfang fort; Erfolge werden geprüft/übersprungen. MAC-Teilergebnisse behalten ihren gesonderten Exportpfad. Uncertain bleibt gesperrt. | Neue terminale Retry-Datensätze und gemeinsamer Bestätigungsvertrag grün; `browser-targeted-recheck`: beide betroffenen Browserfälle und zwei Anmeldevorbereitungen grün (4/4). |
| U11 / SC-006 | Additive monotone Konfigurationsversionen für VM/Mission; Portal verlangt den Versionstoken. Legacy-Opt-out, Kind-/VLAN-/Package-Schreiber und Rollback sind definiert. | Gleichsekündige Writer, fehlende/ungültige/veraltete Versionen und Rollback grün; `u11-acceptance`: 55 Tests / 196 Assertions mit Package-Sperrgrenze grün. Frischschema und Migration konvergieren. |

Die abschließende vollständige PHP-Abnahme `final-phpunit-full` besteht mit 2137 Tests / 44374 Assertions ohne Skips. Der Nachlauf `final-static-refresh` besteht mit 4/4 Gates: Lint von 720 PHP-Dateien, PHPStan, DE/EN-Parität einschließlich Platzhaltern und Syntax der acht Portalskripte. Die geänderte E2E-Fixture besteht zusätzlich `node --check`. Die vorherigen Unit-/Static-/PHPStan-Fehler sind damit durch erfolgreiche Gesamtnachläufe ersetzt.

Der vollständige Integrationslauf `final-integration` bleibt als tatsächlich roter Lauf erhalten: 4/7 Gates bestanden (QA-Stack, Migration, Schemavergleich, Health), keine Infrastrukturfehler. PHPUnit meldete zunächst vier unvollständige Claim-Fixtures; deren Ergänzung ändert keine Produktbedingung oder Testassertion. Funktionales E2E: 257 bestanden, sieben fehlgeschlagen. Sechs davon entsprechen dem Ausgangsaudit; der zusätzliche Hinweistextvergleich wurde korrigiert. Die eng begrenzte U16-Korrektur ergänzt ausschließlich gültige Belege in der alten gemischten Create-Fixture, sodass ihre vorhandenen Uncertain-/Retry-Assertions ausführbar sind. Beide betroffenen Browserfälle bestehen anschließend. Die übrigen fünf Ausgangsfälle (SC-022 sowie Takt-, VLAN- und Inventarbeleg-Fixtures) bleiben offen; kein vollständiger E2E-Nachlauf und keine Gesamtfreigabe für Integration/Release. Der visuelle Teil wurde wegen des funktionalen E2E-Fehlers nicht ausgeführt; keine Baselines geändert.

Guard-Harness: 103 nominell proven, zwei bekannte unproven (`probe-incomplete`, `probe-stale`), keine Infrastrukturfehler. Die im Audit SC-024 dokumentierte falsche Frühfehlerursache begrenzt zusätzlich die Aussage von vier nominell grünen Probes. Dieser Auftrag schließt diese Guard-Lücke nicht.

Zwischenstände bleiben nachvollziehbar: `regression-2` besteht mit 139 Tests / 1033 Assertions. Frühere Fixture-/Vertrags-/PHPStan-Diagnosen und deren Nachläufe sind separat erhalten. Der anfängliche Triggeransatz für die Versionsmigration wurde nach dem SUPER-/Binärlogging-Fehler vollständig entfernt, ohne Rechte auszuweiten. Der Docker-Absturz erzeugte in `fast-selected` sechs Infrastrukturfehler; `docker-recovery-qa` besteht nach Neustart mit 2/2. `fast-recovery` ist ein abgebrochener Teilversuch. Der erste gezielte Browsernachlauf nutzte einen falschen lokalen Port und scheiterte vor beiden Zieltests; der korrekte Nachlauf besteht mit 4/4. Daraus werden keine Produktbefunde abgeleitet.

Unabhängige Vertrags-, Drift- und i18n-Reviews sind ohne offene Befunde dokumentiert in `review-final.md`; finale QA-Zusammenstellung in `qa-final.md`. Der festgehaltene Quellstand umfasst 82 eigene Dateien (`source-manifest-final-candidate.json`). Audit-Originale wurden nicht angepasst. Reale MECM-/SYSTEM-/ESXi-/AD-Abnahmen bleiben ausdrücklich offen; keine Standort- oder Performancefreigabe.

Lokale Paketcommits: `6d33b615` (U02/U03: Workerbesitz und Terminalabschluss), `b1bad92c` (U11: Konfigurationsversionen), `044b7e18` (U01/U04: historische Create-Sperre und sichere Fortsetzung). Sie wurden gegen den gemeinsam geprüften Endstand erstellt. Kein Push, Merge oder Deployment. QA-Abschluss und Prozessstatus sind in `qa-final.md` festgehalten; Daten und Nachweise bleiben erhalten.

## Arbeitsstand

- Stand: 08.09.2026, Plan vorbereitet; Audit noch nicht begonnen.
- Aktueller Prüfstand: durch AP00 zu erfassen. HEAD beim Planentwurf: `2635b271c4fab3d46bbac633ac72af3c76d0e643`, Branch `main`, umfangreiche uncommittete Änderungen. Kein Laufzeitnachweis für diesen Stand behauptet.
- Nächster Schritt: AP00, aktuellen Gitstand einschließlich ungetrackter Quelldateien erfassen und mit den bestehenden Auditberichten abgleichen.
- Laufende Auditprozesse: keine durch die Planerstellung gestarteten Produktprüfungen.
- Für dieses Audit geänderte Produkt-/Testdateien: keine.

## Paketstatus

Statuswerte: offen, in Arbeit, geprüft, mit Nachweislücken geprüft. Fehlende Infrastruktur erhält im nächsten Schritt eine konkrete Voraussetzung. Ein Paket mit Nachweislücken ist keine bestandene Laufzeitabnahme. Die Modellempfehlung steht ausschließlich in Abschnitt 5 des Plans; bei der Ausführung hier je Paket das tatsächlich eingesetzte Modell, Reasoning und eine erfolgte Gegenprüfung festhalten.

| Paket | Gegenstand | Status | Nachweis / nächste Arbeit |
|---|---|---|---|
| AP00 | Prüfstand, Vorbefunde, Umgebung | offen | Arbeitsstand erfassen |
| AP01 | Gates und Aussagekraft | offen | Nach AP00 |
| AP02 | Datenflüsse und SSoT | offen | Aktive Schreiber/Verbraucher inventarisieren |
| AP03 | Queue, Worker, Ansible, Create | offen | Nach AP02 |
| AP04 | MECM-Server-PowerShell | offen | Nach AP02 |
| AP05 | Clients, Installer, Pakete | offen | Nach AP02 |
| AP06 | Machine API, DB, Portal-Schreibwege | offen | Mit AP03 bis AP05 verbinden |
| AP07 | Status, Logs, Recovery | offen | Nach Zustands-/Schreibwegprüfung |
| AP08 | Portal-QoL und Skalierung | offen | Synthetische Szenarien vorbereiten |
| AP09 | Performance und Ressourcen aller Schichten | offen | Vergleichbare Baseline und Messprofile erfassen |
| AP10 | Robustheit und Self-Healing | offen | Bestehende Erholungspfade und sichere Kandidaten prüfen |
| AP11 | Wahrheitsprüfung von Doku und Portalhilfe | offen | Behauptungen mit Vertrag, Code und Verhalten abgleichen |
| AP12 | Betrieb, Migration, Distribution, Lab | offen | Nachweise wiederverwenden, Lücken konkretisieren |
| AP13 | Gegenprüfung und Umsetzungspakete | offen | Ergebnisse konsolidieren |

## Prüfstand und Prüfprotokoll

Noch keine Auditausführung. Pro Lauf ergänzen: Lauf-ID, Datum, HEAD und Quelldatei-Manifest, Umgebung/Toolversionen, Befehl, Exitcode, Ergebnis und relativer Artefaktpfad unter `qa-artifacts/system-chain-audit/`. Geheimnisse, ungefilterte Providerantworten und produktive Nutzdaten gehören nicht in dieses Register.

Vorprüfung der Planerstellung am 08.09.2026: Lokale Markdownziele, UTF-8-Ersatzzeichen, nachlaufende Leerzeichen und die Übereinstimmung der Pakete AP00 bis AP13 in Plan und Register wurden geprüft, Ergebnis sauber. Der kanonische Aufruf `powershell -NoProfile -File scripts/check.ps1 -Gate doc-hygiene,doc-semantics -Json qa-artifacts/system-chain-audit/plan-validation/docs.json -KeepArtifacts` meldete beide Gates als `fail`, Runner-Exitcode 1. Die Ausgabe zeigt jedoch einen Startabbruch von `sh.exe`/MSYS mit Exitcode `-1073741502`, keine fachliche Checkdiagnose. Die Doku-Gates gelten deshalb hier als nicht erfolgreich ausgeführt; der Unterschied zwischen Runnerklassifikation und beobachtetem Toolfehler ist in AP01 zu untersuchen. Liveausgabe und JSON liegen unter `qa-artifacts/system-chain-audit/plan-validation/`. Daraus folgt keine bestandene oder fachlich fehlgeschlagene Dokumentationsabnahme.

## Vorbefunde und SSoT-Matrix

Durch AP00/AP02 zu füllen. Vorbefunde behalten ihre ursprüngliche ID und erhalten einen Verweis auf Quelle, damaligen Stand, heutigen Befundstatus und noch fehlende Abnahme.

Für die Fluss-/SSoT-Matrix verwenden: Fachregel/Feld, Sollvertrag, maßgeblicher Owner, Schreiber, Leser, Wire-/DB-Darstellung, Normalisierung/Grenzen, Sperren/Revisionen, bestehender Nachweis und offene Lücke. Aktive PowerShell-Aufgaben und Clientphasen einzeln erfassen.

## Befunde

Noch keine Befunde aus dieser Auditrunde. Neue IDs fortlaufend als `SC-001` vergeben. Mehrere Symptome derselben Ursache unter einer ID mit allen betroffenen Pfaden bündeln.

Pro Befund diese Felder ausfüllen:

- ID, Titel, Paket, Kategorie (Fehler, Logiklücke, Vertragslücke, Drift, SSoT, QoL, Performance, Robustheit/Self-Healing, Doku/Hilfe), Priorität und Evidenzklasse R/K/H/Q/D aus dem Plan.
- Prüfstand und konkrete Datei/Funktion/Zeile; bei mehreren Schichten Sender, Empfänger und DB-Owner.
- Auslöser/Vorbedingungen, fachliches Soll mit Quelle, beobachtetes Ist und Auswirkung.
- Reproduktion oder belegter Kontrollfluss; erwartete und tatsächliche DB-/externe Wirkung einschließlich unveränderlicher Felder.
- Bestehender Test und weshalb er den Fall nicht abdeckt; Gegenprobe/Grenzen der Aussage.
- Korrekturvorschlag am zuständigen Owner, betroffene Verträge, Abhängigkeiten, Aufwand/Risiko und Abnahmekriterium.
- Befundstatus: offen, widerlegt, Entscheidung nötig, zur Umsetzung geplant, implementiert ohne vollständigen Nachweis oder verifiziert behoben.
- Zugeordnete Nachweise, alter Befundverweis und verbleibende Labor-/Infrastrukturvoraussetzung.

QoL-Befunde nennen zusätzlich Benutzeraufgabe, Reibung und messbaren Nutzen. Sie erhalten keinen Defektstatus allein aufgrund einer Präferenz.

## Performance, Self-Healing und dokumentierte Behauptungen

Durch AP09 bis AP11 zu füllen; Befunde bleiben im obigen Befundregister und werden hier über ihre ID referenziert.

- Performanceprofil: Schicht/Benutzeraufgabe, Datensatz und Last, Umgebung, Kalt-/Warmlauf, Messdauer/Wiederholungen, Kennzahlen, bestehende Grenzwertquelle, Engpass und Artefakt. Vorher-/Nachher-Werte nur bei vergleichbarem Profil gegenüberstellen.
- Self-Healing-Fall: Störung, bestehender Owner, Evidenz, zulässige Aktion, Idempotenz/Revision/Lock, Versuch-/Zeit-/Ressourcenbudget, Backoff/Cooldown, Abbruch/Eskalation, beobachtete Recoverydauer und Fehlerstimulationsnachweis. Automatische Wiederholung von manueller Klärung unterscheiden.
- Doku-/Hilfebehauptung: Fundstelle und Sprache, überprüfbare Aussage, Geltungsbereich, Sollvertrag, tatsächlicher Codepfad, Laufzeitnachweis, Urteil (richtig/falsch/unvollständig/nicht nachgewiesen), Korrekturquelle und Befund-ID. Abgedeckten Textumfang und Restumfang festhalten.

## Szenarioabdeckung und offene Abnahmen

Auch Prüfungen ohne Befund hier erfassen: Szenario, Sollvertrag, Stand, Methode, Ergebnis, Artefakt und ausdrücklich nicht abgedeckte Randfälle. Für reale Labfälle ergänzen: Zielumgebung, synthetische Testobjekte, Aktion, Sollzustand, Nachweis und Aufräumschritt.

## Entscheidungen und Umsetzungspakete

Noch nicht erarbeitet. Je Entscheidung die offene Frage, Optionen, Empfehlung und abhängige Befunde erfassen. Je Umsetzungspaket die Befund-IDs, verantwortlichen Dateien/Owner, konkrete Änderung, Regression, Review und Abnahmekriterium benennen. Produktimplementierung und Laborfreigabe getrennt führen.

## Sessionübergabe und Abschluss

Erste Übergabe: Plan und vorbereitetes Register erstellt, einschließlich Performance, Self-Healing und Doku-/Hilfewahrheit. In der Folgesession AP00 beginnen. Keine Produktprüfung, Produktkorrektur oder externe Abnahme wurde durch die Planerstellung ausgeführt. Die getrennte Dokumentstrukturprüfung und der nicht erfolgreich gestartete Doku-Gatelauf sind oben festgehalten; es läuft kein Prüfprozess weiter.

Bei weiteren Übergaben diese Angaben ersetzen: letzter bearbeiteter Schritt, Standänderungen, letzte reale Ergebnisse, laufende Prozesse samt Logs, Hindernisse, nächster konkreter Schritt. Beim Abschluss geprüften Umfang, offene Fehler, QoL, Performance, Self-Healing, Doku-/Hilfewahrheit, Entscheidungen, Nachweislücken und empfohlene Umsetzung in dieser Sektion zusammenführen.
