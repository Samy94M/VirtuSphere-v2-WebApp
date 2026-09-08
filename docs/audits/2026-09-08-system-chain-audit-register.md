# Befundregister: VirtuSphere und PowerShell-Integrationskette

Zugehöriger [Auditplan](2026-09-08-system-chain-audit-plan.md). Dieses Register ist die einzige fortgeschriebene Quelle für Auditfortschritt, Befunde, Entscheidungen und Nachweisverweise dieser Runde. Ausführbare Produktregeln und Gate-Definitionen bleiben an ihren bestehenden Stellen.

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
