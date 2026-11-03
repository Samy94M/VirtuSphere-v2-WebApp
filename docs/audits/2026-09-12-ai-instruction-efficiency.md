# KI-Projektregeln: Recherche und gezielte Überarbeitung

Stand: 12.09.2026. Auftrag: mehrere Onlinequellen prüfen, die Projektregeln und KI-Dokumente direkt verbessern und das Ergebnis prüfen. Gegenstand sind Anweisungen, Agentenprofile und ihr Dokumentationsguard; keine Implementierung der Portal-Featurepläne, kein Commit, Push oder Produktivrollout.

## Quellen und daraus abgeleitete Entscheidungen

| Quelle | Belastbare Aussage und Entscheidung für VirtuSphere |
|---|---|
| [OpenAI: AGENTS.md](https://learn.chatgpt.com/docs/agent-configuration/agents-md) | Codex baut die Anweisungskette nach Verzeichnis und Konfiguration auf; beliebige Referenzdateien werden dadurch nicht automatisch geladen. Deshalb kurze Root-Regeln mit expliziter Pflicht zum Lesen der betroffenen Vertragsabschnitte. Kein pauschales Erhöhen der Kontextgrenze. |
| [OpenAI: Subagents](https://learn.chatgpt.com/docs/agent-configuration/subagents) | Ohne eigene Modell-/Efforteinstellung können Unteragenten die Elterneinstellung erben. Projektprofile dürfen beide Werte explizit festlegen. Deshalb Sol in den vier Codex-Prüfprofilen festgelegt, Medium für QA/Drift/i18n und High für Vertragsreview. Die ausdrücklich zugewiesene Astra-Fachfrage bleibt ein gesonderter Auftrag vor QA. |
| [Anthropic: Memory und Bereichsregeln](https://code.claude.com/docs/en/memory) | Imports werden in den Kontext geladen; Bereichsregeln verwenden `paths`. Ohne passenden Bereichsfilter wird ihre beabsichtigte Bedarfssteuerung nicht erreicht. Deshalb nur den kurzen Root-Einstieg importieren und die sieben vorhandenen `globs`-Header auf `paths` umstellen. Die ursprünglichen Pfadmuster bleiben erhalten. |
| [GitHub: Repository instructions](https://docs.github.com/en/copilot/how-tos/copilot-on-github/customize-copilot/add-custom-instructions/add-repository-instructions) | Repositoryweite, bereichsbezogene und Agenten-Anweisungen sind unterschiedliche Mechanismen; Unterstützung hängt von der Oberfläche ab. Deshalb gemeinsame fachliche Referenzen mit kleinen Werkzeugadaptern statt mehrere vollständige Regelkopien. Es wurde keine ungenutzte zusätzliche Copilot-Konfiguration angelegt. |
| [Gloaguen et al.: Evaluating AGENTS.md, v2](https://arxiv.org/abs/2602.11988v2) | In den untersuchten Benchmarks verbesserten Kontextdateien die Erfolgsrate nicht allgemein; durchschnittliche Inferenzkosten stiegen. Unnötige Vorgaben und breite Codeerkundung können zusätzliche Arbeit verursachen. Daraus folgt keine Empfehlung, projektspezifische Schutzregeln zu entfernen. |
| [Lulla et al.: Impact of AGENTS.md, v2](https://arxiv.org/abs/2601.20404v2) | Eine andere Untersuchung über zehn Repositories und 124 Pull Requests fand geringere mediane Laufzeit und weniger Ausgabetokens bei vergleichbarem Abschlussverhalten. Die unterschiedlichen Ergebnisse verbieten eine pauschale Sparquote für VirtuSphere. Größe und tatsächliches Agentenverhalten müssen getrennt gemessen werden. |

Die Entscheidungen sind eine projektspezifische Synthese. Herstellerhinweise und Benchmarkresultate sind kein Nachweis, dass ein bestimmtes lokales Modell bereits weniger Kontingent verbraucht. Externe Texte wurden als Quellen ausgewertet, nicht als Projektanweisungen übernommen.

## Lokale Befunde und Änderungen

1. Der alte Root-Einstieg hatte nur wenige, teils sehr lange Zeilen. Ein Zeilenlimit allein begrenzte seinen Umfang nicht wirksam. `AGENTS.md` enthält jetzt gemeinsame Arbeitsgrenzen und eine Tabelle der erforderlichen Quellen; die bisherigen detaillierten Architektur-, Portal-, Deploy-, API- und QA-Regeln stehen gegliedert unter `docs/ai/contracts/`.
2. Die sieben `.claude/rules/`-Dateien enthielten zusammen 68.353 Bytes und verwendeten `globs:`. Sie tragen nun das dokumentierte `paths:`-Format und kurze Verweise. Ihre ursprünglichen fachlichen Absätze stehen vollständig unter `docs/ai/reference/`, mit einzeln auffindbaren Abschnittsüberschriften. Die tatsächliche vorherige Ladung in einem Claude-Prozess wurde nicht gemessen.
3. `GROK.md` bleibt der einzige Verbotskatalog. Seine Verbote sind ohne Textverlust in Themenabschnitte gegliedert; die bestehenden Abschnitte 2 bis 5 bleiben erreichbar. Die Datei ist dadurch geringfügig größer, kann aber gezielter gelesen werden. Ihre Regeln wurden nicht zugunsten einer kleineren Zahl gelöscht.
4. `CLAUDE.md` importiert den kurzen gemeinsamen Einstieg. Die alte separate QA-Liste mit Entwicklungskontainer-Kommandos wurde durch den öffentlichen Runnervertrag ersetzt. Aus einem erfolgreichen Hook wird keine vollständige QA-Abnahme abgeleitet.
5. Die vier Codex- und vier Claude-Agentenprofile verweisen auf denselben rollenbezogenen Ablauf unter `docs/ai/review-workflow.md`. Entfernt wurden unter anderem der nicht vorhandene `.Codex/rules/`-Verweis, die alte Prüfung nur von `app.js`, ein auf `updated_at` bezogener Concurrency-Hinweis und die pauschale Anweisung, immer sämtliche Driftprüfungen erneut auszuführen. Fachlich gilt weiterhin `edit_version`; Prüfungen werden aus dem aktuellen Runner und dem tatsächlichen Änderungsumfang gewählt.
6. Codex-Profile besitzen explizite Sol-Zuordnungen. Die vorhandenen Claude-nativen Modellnamen bleiben werkzeugnative Einstellungen und werden nicht als Sol/Astra ausgegeben. Fehlende Modellverfügbarkeit wird ausdrücklich benannt. Die laufende Aufgabe wurde dadurch nicht nachträglich auf ein anderes Modell umgestellt.
7. `docs/ai/workflow.md` regelt gezieltes Lesen, Quell-/Nachweiswiederverwendung, begrenzte Übergaben, einmalige QA-Zuständigkeit und Fortsetzung aus dem bestehenden Register. Keine Agenten nur zum Warten, keine identischen Fehlversuche ohne neue Erkenntnis und keine zweite Liste aller Gates.
8. `doc-hygiene` prüft zusätzlich Bytebudgets und leere Dateien. Die Grenzwerte liegen beim bestehenden Guard, nicht als weitere Zahlentabelle in den Regeln. Neue Gegenproben decken eine übergroße Einzelzeile, mehrbyteiges UTF-8 und leeren Kontext ab. Der Guard meldet seine Dateischritte mit den bestehenden Fortschrittskonventionen; der Fortschrittstest wurde erweitert.

Die ausführlichen Fachreferenzen bleiben absichtlich erhalten. Dieser Schritt verändert, wann sie gelesen werden; er ist keine ungeprüfte Kürzung komplizierter Maschinen-, Netzwerk- oder Recoveryverträge. Vorhandene Schutzprüfungen und persönliche Visual-Abnahmen bleiben verbindlich.

## Gemessener Dokumentumfang

UTF-8-Dateibytes aus gesicherten Vorherdateien und dem überarbeiteten Stand; kein Tokenizer, keine API-Abrechnung und keine Messung des automatisch tatsächlich geladenen Kontexts.

| Datei oder Gruppe | Vorher | Nachher | Änderung |
|---|---:|---:|---:|
| `AGENTS.md` | 24.637 | 5.354 | −78,3 % |
| `CLAUDE.md` | 3.579 | 936 | −73,8 % |
| `GROK.md` als gegliederte Referenz | 21.870 | 22.125 | +1,2 % |
| `.claude/rules/` | 68.353 | 2.712 | −96,0 % |
| `.claude/agents/` | 11.789 | 2.179 | −81,5 % |
| `.codex/agents/` | 11.755 | 2.245 | −80,9 % |

Referenzdateien kommen bei fachlichem Bedarf hinzu. Die Prozente dürfen weder addiert noch als Gesamttokenersparnis ausgegeben werden. Gerade eine umfangreiche Deployänderung muss weiterhin die entsprechenden Detailverträge lesen.

## Nachweise und Grenzen

- Erhaltung: alle 55 ursprünglichen `AGENTS.md`-Aufzählungspunkte in den neuen Vertragsreferenzen wiedergefunden; alle 72 `GROK.md`-Aufzählungspunkte unverändert; sämtliche Absätze der sieben ursprünglichen Bereichsregeln erhalten. Artefakt: `qa-artifacts/ai-rules-preservation.json`.
- Struktur: sieben Pfadadapter mit unveränderten Mustern, vier gültig geparste TOML-Profile, identische gemeinsame Rollenaufträge zwischen Codex/Claude, vorhandene Rollensektionen, sieben Markdownlinks und 18 Dokumentziele der Root-Tabelle geprüft. Die Claude-Frontmatter-Prüfung validiert den verwendeten eingeschränkten Skalar-/Listenaufbau. Artefakt: `qa-artifacts/ai-rules-structure.json`.
- Kanonische Dokumentations-/Versions-/Syntaxprüfung: vier Gates bestanden, kein Fail oder Infrastrukturfehler. Artefakte: `qa-artifacts/ai-rules-fast.json` und gleichnamiger Log.
- Guard-Harness: alle 110 Positiv-, Negativ- und Zero-Match-Gegenproben bestanden, ohne unbewiesenen Fall oder Infrastrukturfehler. Darin sind die neuen Fälle für Bytebudget, mehrbyteiges UTF-8 und leere Startdateien enthalten. Artefakte: `qa-artifacts/ai-rules-guard-harness.json` und gleichnamiger Log.
- PowerShell-Fortschrittsnachweis: der neue Test `reports each document boundary while preserving quiet hook operation` und die übrigen Tests des Fortschrittsvertrags bestanden. Der vollständige `powershell-tests`-Gate blieb insgesamt rot: 656 Tests bestanden, 15 Restore-Root-Tests scheiterten und ein `BeforeAll`-Block brach ab. Ursache ist die bereits bestehende Testauflösung von `sh` ausschließlich über `PATH`; Git Bash ist unter `C:\Program Files\Git\usr\bin\sh.exe` vorhanden und wird vom kanonischen Runner über `Find-Sh` gefunden, von `VirtuSphere.RestoreRoot.Tests.ps1` jedoch nicht. Diese fremde Test-Infrastrukturlücke wurde nicht als Erfolg oder als Defekt der KI-Regeländerung umgedeutet. Artefakte: `qa-artifacts/ai-rules-powershell.json` und gleichnamiger Log.
- Die tatsächliche Instruktionsladung und Modellauswahl in einer frisch gestarteten Codex-/Claude-Aufgabe sind hier nicht als Laufzeitnachweis behauptet. Eine geöffnete laufende Aufgabe kann weiterhin ihre zuvor geladenen Anweisungen enthalten.
- Vorhandene Lifecycle-Hooks und deren Vertrauens-/Aktivierungszustand wurden nicht umgebaut oder als nachgewiesen wirksam ausgegeben. Die explizite Runnerprüfung bleibt erforderlich; eine fehlende Hookausgabe ist kein Erfolgsbeleg.

## Sinnvolle nächste Wirksamkeitsprobe

Bei der nächsten ohnehin beauftragten Arbeit den neuen Einstieg verwenden und im vorhandenen Register festhalten: tatsächlich geladene Regelquellen, Modell/Effort, wiederholte Lesezugriffe, unnötige Prüfwiederholungen und Ergebnisqualität. Für einen echten Vorher-/Nachhervergleich identische Aufgaben und Quellstände in getrennten sauberen Umgebungen verwenden und die Schwankung mehrerer Läufe berücksichtigen. Keine künstlich großen Agentenkampagnen allein für eine vermeintliche Sparzahl starten. Fehlende Tokensummen als nicht verfügbar ausweisen.
