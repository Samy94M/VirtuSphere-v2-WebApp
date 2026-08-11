# Masterplan: Deploy-Zuverlässigkeit, Fehlerherkunft und Portal-UX

Stand: 2026-08-11, zusammengeführte ausführbare Fassung nach Repository-Review und Online-Faktenprüfung.

Dieser Masterplan verbindet drei bisher getrennte Arbeitsstränge:

1. die Korrektur der im Review gefundenen Lücken an Reaper/DB-Ausfall, Ansible-Aktivitätsnachweis, CLI-SSoT, Festplattentexten, Doku und Fast-Gate;
2. die bereits detailliert geplante eindeutige Fehlerherkunft des ESXi-Inventar-Abrufs;
3. den kritisch geprüften UX-Plan für verständliche Zustände, handlungsfähige Deploy-Blocker, barrierearme Formulare, Operatorfilter und reproduzierbare visuelle QA.

Die Reihenfolge ist verbindlich: Zuerst wird der bereits veränderte Bestand stabil und ehrlich beobachtbar gemacht, danach baut die Inventar-Fehlertaxonomie auf diesem Worker-/Logvertrag auf. Erst anschließend werden Portalvokabular, Bedienung und Design geändert, damit die UX keine noch instabilen Betriebsverträge kaschiert. Diese Datei ersetzt getrennte Restlisten und die vorherigen Fassungen als ausführende SSoT vollständig; der geprüfte UX-Einzelplan bleibt nur als ausführliche Reviewspur erhalten.

---

## 0. Ausführungsvertrag

Diese Datei liegt bewusst im Repository. Eine ausführende Sitzung liest zuerst `CLAUDE.md`, `AGENTS.md`, `GROK.md` sowie die berührten Regeln unter `.claude/rules/` und führt das Abnahmeprotokoll am Ende dieser Datei fort.

Der Arbeitsbaum ist parallel verändert. Vor jeder Etappe gilt deshalb:

1. `git status --short` lesen.
2. Den ungestageten Diff jeder betroffenen Datei und besonders der betroffenen Funktion lesen.
3. Fremde Änderungen erhalten; keine Datei auf einen früheren Stand zurücksetzen.
4. Nur eigene Hunks stagen, falls die ausführende Sitzung ausdrücklich committen soll.
5. `git add -A`, `git reset --hard` und ein pauschales Checkout veränderter Dateien sind ausgeschlossen.

Aktuell überlappen unter anderem `lib/deploy_constants.php`, `lib/deploy_worker.php`, `lib/deploy_worker_outcome.php`, die DE/EN-Dateien für `help_deploy`, `help_system_status` und `system_status`, `SshStreamHardeningTest.php`, `SystemStatusPanelBranchTest.php`, `docs/CHANGELOG.md` und `docs/operations/esxi-inventory.md`. Auch die nur gelesenen Breitenquellen `struktur.sql` und `lib/migrate.php` sind bereits verändert. Diese Liste ist nur ein Startpunkt; maßgeblich ist immer der dann aktuelle Diff.

Es gibt keine akzeptierten roten Tests und keine feste Testanzahl. Der kanonische Runner ist `scripts/check.ps1`. Die Fast-Lane führt Unit/Static bereits mit vollem Repo-Mount und `--fail-on-skipped` aus; ein zweiter identischer Repo-Root-Lauf ist kein zusätzlicher Nachweis.

Eine Etappe ist nur abgeschlossen, wenn Code/SSoT, passende Tests, sichtbare Texte und Hilfe, technische und betriebliche Dokumentation sowie betroffene Protokoll-/Logpfade innerhalb **derselben Etappe** zusammenpassen. Hilfe, Doku, Changelog, ADR/Runbook, Audit, Joblog, Containerlog oder Wire-Vertrag sind keine nachgelagerte Sammelarbeit. Was eine Etappe fachlich verändert, wird in dieser Etappe vollständig nachgezogen und abgenommen. Ist ein Bereich nachweislich nicht betroffen, trägt das Abnahmeprotokoll dafür `nicht betroffen` samt kurzer Begründung; ein leeres Feld ist kein Nachweis.

### Verbindlicher Etappenabschluss

Nach **jeder** Etappe wird die Umsetzung angehalten und gegen den Plan geprüft. Erst ein grüner Soll/Ist-Abgleich gibt die nächste Etappe frei:

1. Die Anforderungen und Negativabgrenzungen der Etappe erneut lesen und jede einzelne als umgesetzt, getestet oder begründet nicht betroffen markieren.
2. `git status --short` und den vollständigen Diff der Etappe lesen; dabei auch neu entstandene Dateien, indirekte Aufrufer, Spiegel/SSoT-Verbraucher und versehentlich mitgeänderte Fremdhunks prüfen.
3. Mit Repository-Suche nach alten Kategorien, Texten, Links, Zahlen, duplizierten Mappings und alternativen Codepfaden suchen. Ein grüner Test ersetzt diesen Vollständigkeitsabgleich nicht.
4. Betroffene Portaltexte, DE/EN-Hilfe, `docs/`, Changelog, ADR/Runbook, QA-Anleitung und dauerhafte Agent-Regeln in derselben Etappe aktualisieren. Bereits richtige Stellen werden ausdrücklich als geprüft protokolliert.
5. Audit-, Job-, Container- und Fehlerlogs sowie technische Protokolle/Wire-Contracts prüfen: Quelle und Kategorie, Wortlaut und Ursache, Redigierung von Secrets, RBAC/Deep-Link, Retention, Maschinenvertrag und Rückwärtskompatibilität. Notwendige Änderungen gehören in dieselbe Etappe; bei unverändertem Vertrag wird auch das als geprüft festgehalten.
6. Die gezielten positiven, negativen und Zero-Match-Tests der Etappe ausführen. Mehrteilige Runner folgen der `[n/total] RUN`-/`[n/total] PASS|FAIL`-Vorgabe aus `AGENTS.md`; bei gepufferter Ausgabe wird blockweise ausgeführt oder der Fortschritt beobachtbar gepollt.
7. Das Abnahmeprotokoll mit Soll/Ist-Nachweis, Testnachweis, Help/Doku-Nachweis und Logs/Protokolle-Nachweis fortführen. Entdeckte Lücken werden noch in derselben Etappe geschlossen und erneut geprüft, nicht auf eine spätere Etappe oder den Gesamtabgleich verschoben.

Der Gesamtabgleich am Ende ist damit eine unabhängige Gegenprüfung, keine vorgesehene Reparaturphase. Findet er eine Lücke, wird die verursachende Etappe wieder geöffnet, korrigiert und mit ihren gezielten Nachweisen erneut abgenommen.

---

## A. Vorgelagerte Korrekturen aus dem Änderungsreview

### Etappe 1: Rotes Fast-Gate, CLI-SSoT und Arbeitsbaumhygiene

Diese Etappe stellt zuerst einen belastbaren Ausgangspunkt her. Sie ändert noch keine fachliche Fehlerklassifikation.

Umsetzung:

1. `disk_type_label()` erhält einen statisch beweisbaren Literaltyp (`'thin'|'thick'|'eagerzeroedthick'`) für `$type`. Das exhaustive `match` bleibt ohne `default`, damit ein neuer SSoT-Wert ohne sichtbare Bezeichnung weiterhin den Build bricht.
2. `CliRequireClosureContractTest` bekommt eine vollständige, zentral erklärte Entry-Point-Menge einschließlich `lib/seed.php`. Bevorzugt wird eine explizite SSoT-Registry beziehungsweise ein eindeutig prüfbarer CLI-Marker; eine neue zweite handgepflegte Liste ist nur zulässig, wenn der Test ihre Vollständigkeit selbst beweist. Bewusste Ausnahmen nennen Entry-Point und Grund.
3. Aussagen wie „every CLI entrypoint“ in `docs/QA.md` und `.claude/rules/webapi.md` werden erst verwendet, wenn der Vertrag sie tatsächlich beweist. Die starre Aussage über genau drei oberhalb des Container-Mounts lesende Tests wird durch eine ableitbare oder bewusst generische Beschreibung ersetzt.
4. `tests/e2e/shot.tmp.js` wird als lokales temporäres Artefakt entfernt und nicht committet. Falls seine Funktion dauerhaft gebraucht wird, entsteht ein benanntes Tool unter der bestehenden E2E-Konfiguration und liest Benutzer/Passwort aus `tests/e2e/lib/auth.js` beziehungsweise den vorhandenen Umgebungsvariablen.
5. `git status`, Migrationstatus und der noch offene Index `0039_ansible_activity_index` werden als Basis protokolliert. Die Migration wird nicht beiläufig in dieser Etappe angewandt; ihr Schema-/Query-Nachweis gehört zu Etappe 3.

Help/Doku/Logs/Protokolle in derselben Etappe:

- `docs/QA.md`, `.claude/rules/webapi.md` und gegebenenfalls der Changelog beschreiben nur den wirklich bewiesenen CLI-Vertrag.
- Die reine PHPDoc-/Guardkorrektur erzeugt keine neue Portalhilfe, kein Audit, keinen Joblog und keinen Wire-Contract. Diese vier Punkte werden dennoch jeweils als `nicht betroffen` mit Begründung protokolliert.

Gezielte Abnahme:

- PHPStan für `lib/defaults.php` ist grün, ohne einen `default` im `match`.
- `DiskTypeLabelTest` beweist alle SSoT-Tokens und den unbekannten Wert.
- `CliRequireClosureContractTest` beweist positive, negative und Zero-Match-Fälle sowie `seed.php`.
- Doku-Suche findet keine veraltete feste Testanzahl und keine überbreite CLI-Behauptung.
- Der Arbeitsbaum enthält `shot.tmp.js` nicht mehr; fremde ungetrackte Dateien bleiben unangetastet.

### Etappe 2: Aktiver DB-Ausfall, Reaper und belegbare Ursachen

Die Observer-Grace bleibt eine Schutzregel für einen Reaper, der die vergangene Stille nicht selbst beobachten konnte. Sie ist **keine** Wiederaufnahme eines bereits laufenden Jobs und darf nicht mehr so dokumentiert oder getestet werden.

Umsetzung des laufenden Workerpfads:

1. Ein kleiner, testbarer `DeployWorkerDbChannel` (Name darf der lokalen Konvention folgen) besitzt die aktuell verwendete `mysqli`-Verbindung und den Connector. Stream-, Log- und Heartbeat-Callbacks greifen über diesen veränderbaren Kanal zu; keine Closure hält länger eine tote `mysqli`-Kopie fest.
2. Der erste `mysqli_sql_exception` während eines aktiven SSH-/SFTP-Laufs markiert den Kanal als getrennt, schreibt genau eine gedrosselte, redigierte STDERR-Zustandsmeldung und darf den SSH-Stream nicht allein wegen des ausgefallenen Nebenkanals schließen.
3. Während der Störung werden fertige, bereits redigierte Joblogzeilen in einer größenbegrenzten FIFO-Spool gehalten. Ein Überlauf wird deterministisch zusammengefasst und später mit einer SYSTEM-Zeile ausgewiesen; weder unbeschränkter Speicherverbrauch noch stiller Zeilenverlust sind zulässig.
4. Weitere Stream-/Silence-Ticks versuchen höchstens einen zeitlich begrenzten Reconnect, wenn der Backoff fällig ist, und lesen danach den SSH-Stream weiter. Innerhalb eines Callbacks läuft keine unendliche Reconnectschleife. Der dateibasierte Container-Heartbeat bleibt währenddessen aktuell.
5. Nach erfolgreichem Reconnect ersetzt der Kanal seine Verbindung, prüft zuerst `id`, `locked_by` und aktiven Status, schreibt dann den Jobheartbeat und leert anschließend die Spool in Reihenfolge. Hat der Job seine Ownership verloren, wird der entfernte Lauf beendet, ohne den fremden terminalen Zustand zu überschreiben.
6. Endet der Remote-Befehl während der DB-Störung, wird sein Exitcode lokal festgehalten. Der Loop-Worker wartet anschließend mit dem vorhandenen Backoff auf die DB, prüft Ownership und finalisiert genau einmal. `--once` bleibt begrenzt/fail-fast und meldet den nicht persistierbaren Ausgang ausdrücklich.
7. Dasselbe Kanalprinzip gilt für Mission- und Inventarjobs; es gibt keinen zweiten, semantisch abweichenden Reconnectpfad.

Umsetzung des Reapers:

- Der Reaper protokolliert pro Job nur Beobachtbares: Job-ID, `locked_by`, Alter des letzten Heartbeats und den daraus folgenden Übergang. Der aktuelle Singleton-Systemstatus darf als **aktueller separater Zustand** genannt werden, beweist aber weder Tod noch Überleben des damaligen Jobbesitzers.
- Die Sätze „it did not die“, „stopped reporting as well“ und „database outage is the usual one“ entfallen als Ursachenbehauptungen. Ein Neustart kann eine frische Singleton-Zeile erzeugen und darf einen alten Besitzer nicht rückwirkend gesund erklären.
- `--once` führt ohne beobachtetes Grace-Fenster bewusst keinen Reap aus. Dieser Werkzeugvertrag wird explizit getestet und dokumentiert; ein später gewünschtes erzwungenes Reaping bräuchte einen eigenen benannten Operator-Schalter.
- Der Holdoff bleibt einmal pro Verbindung im Containerlog sichtbar. Das Troubleshooting nennt den exakten `docker compose logs deploy-worker maintenance-worker`-Pfad; ein zusätzlicher dauerhafter Audit-Eintrag entsteht nur, wenn er gedrosselt ist und eine Operatorhandlung belegt.

Gezielte Abnahme:

- Ein deterministischer Test simuliert DB-Ausfall in Stream-Logger und Silence-Heartbeat, mehrere Output-Chunks, Reconnect und erfolgreiche Finalisierung, ohne den SSH-Stream zu schließen.
- Weitere Tests beweisen Ownership-Verlust während der Störung, begrenzte Spool/Überlaufzeile, Logreihenfolge, Secret-Redigierung, `--once`, Reconnect-Backoff und exakt eine Zustandsmeldung je Störung.
- Reaper-Integrationstests unterscheiden `current service reporting`, `not reporting`, Neustart und fremdes `locked_by`, ohne daraus eine unbelegte Ursache zu formulieren.
- ADR-0033, Deployment-, QA-, Deploy-Chain-, Troubleshooting-, Changelog- und Agentregel werden in dieser Etappe gegen das tatsächlich implementierte Verhalten korrigiert.

### Etappe 3: Aussagekräftiger Ansible-Aktivitätsnachweis

`deploy_jobs` bleibt die einzige SSoT. Die Anzeige darf aber keinen vor Start abgebrochenen Wunsch als ausgeführtes Playbook verkaufen.

Umsetzung:

1. `repo_latest_completed_ansible_mission_jobs()` berücksichtigt nur Missionsjobs mit `attempts > 0`. Ein `queued -> cancelled`-Job mit `attempts = 0` darf den letzten vom Worker bearbeiteten Job nicht verdrängen.
2. Die Anzeige heißt in DE/EN sinngemäß „Letzter vom Worker bearbeiteter Missionsauftrag“. Sie behauptet weder einen vollständigen Zugangstest noch, dass in jedem Fehlerfall bereits ein Playbook lief. Status, Modus, Mission, Zeit und Joblog zeigen, wie weit der konkrete Auftrag kam.
3. Aktive und missionslose Systemjobs bleiben ausgeschlossen. `updated_at DESC, id DESC` bleibt die deterministische Terminalreihenfolge; eine Missionsumbenennung wird als aktueller Name, nicht als historischer Snapshot dokumentiert.
4. Migration 0039 und Frischschema bleiben synchron. Nach Anwendung im QA-Stack wird der Query mit `EXPLAIN` gegen repräsentative Historie geprüft. Temporäre Tabelle/Filesort oder ein Scan über die gesamte unbegrenzt aufbewahrte Missionshistorie wird nicht nur mit einem kleinen Testbestand akzeptiert; Query oder Index werden anhand des Plans angepasst.
5. Der manuelle Volltest bleibt getrennt und erzeugt weiterhin genau eine bestehende `credentials`-Auditzeile. Die Missionsanzeige verlinkt ausschließlich über `deploy_job_log_url()` und erzeugt weder Statuskopie noch neue Logkategorie.

Gezielte Abnahme:

- Integrationstest: neuer `attempts=0/cancelled`-Job verdeckt einen älteren bearbeiteten Erfolg nicht.
- Fälle für failed preflight, running/queued/system job, reaped/cancelled nach Claim, Zeitgleichheit und mehrere Credentials.
- Static-/E2E-Fixtures setzen realistische `attempts`; keine Testzeile beweist Ausführung mit dem Schema-Default 0.
- DE/EN-Hilfe, Credentials-/Systemstatus-Text, Glossar, Deployment, Installation, Go-live, Troubleshooting und Changelog verwenden dieselbe vorsichtige Semantik.
- Migrationstatus und `EXPLAIN` kommen mit Ergebnis ins Abnahmeprotokoll.

### Etappe 4: Festplattenstandard, Label-SSoT und belastbare Hilfe

Der gewünschte Standard `eagerzeroedthick` bleibt erhalten, wird aber als angeforderte Provisionierungsart und nicht als pauschales Performanceversprechen beschrieben.

Umsetzung:

1. Alle sichtbaren Typnamen laufen über `disk_type_label()`, einschließlich `lib/help/deploy.php`; gespeicherte und an Ansible übergebene Werte bleiben die drei Wire-Tokens aus `VIRTUSPHERE_DISK_TYPES`.
2. DE/EN verwenden konsequent „erster Schreibzugriff/first write“. Thin beschreibt bedarfsgerechte Allokation, Lazy Zeroed Thick reservierten Platz mit Nullung beim ersten Schreiben und Eager Zeroed Thick vorab genullten reservierten Platz.
3. Aussagen „Thin ist immer am langsamsten“, „der Unterschied bleibt dauerhaft“, „beide sind danach gleich schnell“, „VAAI dauert Sekunden“ und „eine einzelne VM bricht nach exakt N Minuten ab“ werden entfernt. Die Hilfe sagt stattdessen, dass Auswirkung und tatsächliche Provisionierung von Storage, VAAI/NFS-Unterstützung, Größe und Workload abhängen.
4. `VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS` wird korrekt als Budget für einen stillen Remote-Schritt beschrieben, nicht als garantierte Gesamtdauer einer einzelnen VM-Erstellung. Der separate Gesamtlaufzeitvertrag bleibt unverändert.
5. Die Hilfe nennt den betrieblichen Trade-off: EZT kann die Erstellung großer oder mehrerer Platten deutlich verlängern und Automatisierungsbudgets erreichen; Thin/Lazy vermeiden nicht pauschal jedes Risiko. Bestehende VMs/Disks werden durch eine Auswahländerung nicht konvertiert.
6. Bestehende Create-/Update-Audits behalten den technischen Token. Es entsteht kein neues Maschinenfeld und keine zweite Typ-SSoT; falls ESXi den angeforderten Typ storagebedingt anders realisiert, behauptet das Portal ohne Rücklese-Beweis keinen tatsächlichen Endzustand.

Gezielte Abnahme:

- `DiskTypeLabelTest`, VM-Formtests, Golden-/Roundtrip-Fixtures, Lang-Audit und PHPStan sind grün.
- Suche findet keinen roh sichtbaren `eagerzeroedthick`-Wert und keine der entfernten Absolutaussagen außerhalb bewusst technischer Code-/Wire-Stellen.
- DE/EN-Platzhalterparität und Hilfeansicht werden geprüft.
- Deployment/Changelog erklären Default, Bestandsschutz, Erstellungsrisiko und unveränderten Wire-/Auditvertrag.

---

## 1. Fehlerbild und Ziel

Der Inventar-Abruf verbindet zwei unterschiedliche Wege:

- Portal → Ansible-Host: SSH, SFTP, Ansible-Preflight und der gestartete Ansible-Prozess.
- Ansible-Host → ESXi/vCenter: HTTPS-Aufrufe der Inventory-Playbooks.

Heute landen beide Wege in derselben unqualifizierten Fehler-Vokabel. Das Portal rendert den Code anschließend auf der ESXi-Karte und interpoliert dabei die ESXi-Adresse. Dadurch entstehen falsche Aussagen und eine falsche Nebenwirkung:

- Ansible-DNS wird als DNS-Fehler des ESXi-Hosts angezeigt.
- Ansible-Auth wird als ESXi-Auth gespeichert und pausiert den ESXi-Zugang.
- eigene SSH-/SFTP-Zeitbudgets werden anhand ihres Texts als Fremdtimeout oder generischer SSH-Fehler geraten;
- ein ausgeführter, aber fehlgeschlagener Ansible-Preflight wird als Verbindungsfehler bezeichnet;
- ein DB-Fehler aus Stream-Logger oder Heartbeat kann während einer Transportphase als Ansible-Netzwerkfehler erscheinen.

Ziel ist ein dauerhaft eindeutiger Fehlercode, der Ursache und betroffene Schicht wahr benennt, ohne das Machine-API-Verhalten, die fünf Legacy-Statusstrings oder den Deploy-Wire-Contract zu verändern.

---

## 2. Entschiedenes Herkunftsmodell

### 2.1 Neue Ansible-Codes

Die Kategorie trägt die Herkunft überall dort, wo eine konkrete Gegenstelle oder Reparaturstelle genannt wird:

| Code | Bedeutung | Automatische ESXi-Pause |
|---|---|---:|
| `ansible_dns` | Ansible-Hostname nicht auflösbar | nein |
| `ansible_unreachable` | Verbindung zum Ansible-Host kam nicht zustande | nein |
| `ansible_auth` | Anmeldung am Ansible-Host wurde abgelehnt | nein |
| `ansible_authz` | Anmeldung stand, aber Sitzung/Aktion war nicht erlaubt | nein |
| `ansible_preflight` | Ansible-Host erreichbar, Toolchain-Vorprüfung fehlgeschlagen | nein |
| `ansible_config` | Playbook, Modul, Collection oder Ansible-Ausführungsumgebung unvollständig | nein |
| `ansible_sftp` | SFTP-Subsystem, Pfad, Rechte, Speicher oder Dateiübertragung fehlgeschlagen | nein |
| `ansible_timeout` | eigenes SSH-/SFTP-Zeitbudget nach aufgebauter Ansible-Verbindung überschritten | nein |
| `ansible_transport` | sonstiger Ansible-SSH-/Transportfehler | nein |

Alle Werte passen in `deploy_esxi_inventory_state.last_error_category VARCHAR(32)`. Es ist keine Schemaänderung und keine Migration nötig.

### 2.2 Bestehende Codes

| Codes | Herkunft/Verwendung |
|---|---|
| `dns`, `unreachable`, `certificate`, `tls`, `auth`, `authz` | eindeutig ESXi-/vCenter-seitige Playbook-Ergebnisse |
| `config` | Portal-/Auftragskonfiguration vor dem entfernten Lauf |
| `worker` | Worker- oder Datenbankfehler |
| `parse` | unerwarteter Marker bzw. nicht sicher zuordenbarer Ausgabe-/Vertragsfehler |
| `ssh`, `http` | nur noch lesbare Legacy-Werte; werden nicht neu geschrieben |

Nur der exakte Code `auth` pausiert einen ESXi-Zugang. `ansible_auth` und `ansible_authz` dürfen weder Pause noch ESXi-Auth-Audit auslösen.

### 2.3 Playbook-Ausgabe ist nicht pauschal ESXi

`ansible_categorize_inventory_error()` bleibt für eindeutige ESXi-/vCenter-Antworten zuständig, wird aber an eng belegbaren Ansible-eigenen Stellen korrigiert:

- fehlendes Playbook, nicht auflösbares Modul/Action, fehlende Collection oder eindeutig fehlende Controller-/Interpreter-Abhängigkeit → `ansible_config` statt `config`; die heutige breite Nadel `could not be found` wird dabei durch konkrete Ansible-Diagnoseformen wie `ERROR! the playbook:` ersetzt, damit ein fehlendes ESXi-Objekt nicht als Ansible-Konfiguration endet;
- die bekannte Form `Timeout waiting for privilege escalation` → `ansible_config`, bevor die allgemeine `timed out`-Nadel auf `unreachable` treffen kann.

Jede neue Nadel erhält ein eigenes Positivbeispiel und einen nahen Negativfall; ein bloßes Wort wie `module`, `python` oder `collection` reicht nicht. Der allgemeine Fallback bleibt `parse`, weil ohne belastbare Evidenz weder Ansible noch ESXi beschuldigt werden darf.

### 2.4 Altbestand und Rollback

Alte Zeilen mit `unreachable`, `auth` oder `ssh` können historisch von einem Ansible-Fehler stammen. Ein sicherer Backfill ist unmöglich, weil nur der Code, nicht die Herkunft gespeichert wurde.

- Ein neuer erfolgreicher Einzelabruf korrigiert die Zeile und hebt eine Pause auf.
- Eine bestehende Fehlpause kann alternativ durch erneutes Speichern des ESXi-Zugangs gelöst werden.
- Die Automatik allein heilt eine pausierte Zeile nicht, weil sie diese überspringt.
- Bei einem Code-Rollback fallen neue Werte im alten Code auf `conn_unknown`; das ist generisch, aber sicherer als eine falsche Ursache.

`ssh` und `http` bleiben in Vokabular, Texten, Hilfe und Betriebsdoku explizit als Legacy lesbar.

---

## 3. Verbindliche Klassifikationsreihenfolge

`deploy_worker_classify_inventory_failure()` nimmt künftig `(string $phase, Throwable $exception)`.

Die Reihenfolge ist:

1. `mysqli_sql_exception` wird phasenunabhängig `worker`. Das umfasst DB-Fehler aus Stream-Logger und Heartbeat.
2. `SshTransportConfigurationException` wird phasenunabhängig `config`; fehlende lokale Bibliothek, Zugangsfelder oder Arbeitsartefakte sind kein Fehler des Ansible-Hosts.
3. Phase `CONFIG` liefert `certificate`, wenn die bestehende enge Zertifikatserkennung greift, sonst `config`.
4. Phase `DB` liefert `worker`.
5. Phase `MARKER` liefert `parse`.
6. Nur in `SSH`, `SFTP` und `TRANSPORT` wird `SshTransportBudgetExceeded` zu `ansible_timeout`.
7. Ein `SftpTransportFailed` wird `ansible_sftp`, bevor sein Text ausgewertet wird. So kann ein entferntes „permission denied“ nicht wieder als abgelehnte Anmeldung erscheinen.
8. Sonstige Transporttexte werden erst generisch klassifiziert und anschließend über die gemeinsame Ansible-Abbildung qualifiziert.
9. In der Phase `SFTP` wird ein sonstiger `ansible_transport`-Fallback zu `ansible_sftp` spezialisiert.
10. Eine unbekannte Phase ist ein Coding-/Workerfehler und liefert `worker`.

Der Preflight-Exitcode ungleich null durchläuft diesen Throwable-Klassifikator nicht: der Worker setzt vor dem Wurf ausdrücklich `ansible_preflight`. Der bereits ermittelte Komponentenname bleibt im technischen Jobtext.

Diese Reihenfolge löst den früheren Widerspruch: Ein künstlich in `CONFIG` platzierter Budgettyp bleibt `config`, ein echter `mysqli_sql_exception` bleibt auch in einer Transportphase `worker`.

---

## 4. Etappe 5: Vokabular- und SSoT-Vertrag

Neu: `Docker/WebAPI/tests/Static/InventoryErrorVocabularyContractTest.php`.

Der Test liest die realen Quellen und prüft:

1. `VIRTUSPHERE_INVENTORY_ERROR_CATEGORIES` ist nicht leer, eindeutig und besteht nur aus `^[a-z][a-z0-9_]*$`.
2. Jeder Code passt in die tatsächliche Breite von `deploy_esxi_inventory_state.last_error_category`.
3. Frischschema (`Docker/mysql/mysql-init/struktur.sql`) und Tabellenerzeugung in `lib/migrate.php` stimmen für diese Spaltenbreite überein.
4. Exakte Mengengleichheit mit `array_keys(VIRTUSPHERE_CONNECTION_MESSAGE_KEYS)`.
5. Exakte Mengengleichheit mit allen `help_system_status.esxi_cause_fix_<code>`-Suffixen je Locale aus `Lang::LOCALES`.
6. Jeder qualifizierte Message-Key wird anhand seines Modulpräfixes dynamisch in jeder Locale aufgelöst.
7. Exakte Mengengleichheit mit den Codes der ersten Spalte der Fehlerbildtabelle in `docs/operations/esxi-inventory.md`; bloßes Vorkommen irgendwo im Dokument reicht nicht.
8. Alle extrahierten Mengen besitzen einen Zero-Match-Schutz.

Der Validator im Test nimmt die Mengen als Parameter und liefert eine Fehlerliste. Neben dem echten Repo-Vertrag bekommt er negative Fixtures für leere Liste, fehlenden Schlüssel, zusätzlichen Schlüssel, ungültigen Token und zu langen Wert. Damit ist die Negativwirkung dauerhaft bewiesen; eine Kategorie muss nicht probeweise im schmutzigen Arbeitsbaum eingefügt und zurückgedreht werden.

Ergänzend prüft der Vertrag:

- alle `ansible_*`-Codes werden durch `inventory_error_is_ansible()` als Ansible erkannt;
- `ssh` und `http` sind explizit Legacy;
- nur `auth` ist pausefähig.

Noch in Etappe 5 werden die neuen Kategorien in `common.php`, der Systemstatus-Hilfe und der Fehlerbildtabelle des Inventar-Runbooks vollständig angelegt, DE/EN- und Platzhalterparität hergestellt und die Legacy-/Pauseaussagen korrigiert. Der Changelog-Eintrag wird mit dem Vokabular- und SSoT-Anteil begonnen. Audit-/Joblogkategorien und Machine-API-Wire-Contracts werden auf Auswirkungen geprüft; diese Etappe führt keine zweite Fehlercode- oder Log-SSoT ein. Etappe 5 endet erst, wenn der neue Vertrag gegen die echten Texte, Hilfen und Dokumentationsquellen grün ist und ihr Etappenabschluss protokolliert wurde.

---

## 5. Etappe 6: Gemeinsamer Budgettyp und vollständige Producer

### 5.1 Exception-Datei

Neu: `Docker/WebAPI/lib/ssh_transport_exceptions.php`:

```php
final class SshTransportBudgetExceeded extends RuntimeException
{
}

final class SftpTransportFailed extends RuntimeException
{
}

final class SshTransportConfigurationException extends RuntimeException
{
}
```

Alle drei Typen erben von `RuntimeException`, damit der Missions-Deploy sie weiterhin in seinem vorhandenen Transport-Catch erfasst. Keiner erbt von `DeployWorkerCancelled`.

- `SshTransportBudgetExceeded` bezeichnet ausschließlich ein von VirtuSphere gesetztes SSH-/SFTP-Zeitbudget.
- `SftpTransportFailed` bezeichnet einen Fehler des SFTP-Subsystems oder einer entfernten SFTP-Operation, nachdem der vorgelagerte SSH-/Preflight-Weg bereits funktioniert hat.
- `SshTransportConfigurationException` bezeichnet ausschließlich lokale Voraussetzungen wie fehlende phpseclib-Klasse, leere Pflichtfelder oder ein verschwundenes lokales Arbeitsverzeichnis.

`connection_errors.php`, `ssh_sftp.php`, `ssh.php` und `deploy_worker_outcome.php` requiren die kleine Datei direkt. Ein neuer `SshTransportExceptionRequireContractTest` prüft alle Require-Zeilen und lädt `deploy_worker_outcome.php` in einem separaten Prozess, ohne vorher `ssh.php` zu laden; danach müssen alle drei Klassen ohne Autoload bekannt sein.

Der bestehende `CliRequireClosureContractTest` wird dafür nicht als Beweis verwendet: er indexiert Funktionen, keine Klassen oder `instanceof`-Referenzen.

### 5.2 SSH-Producer

Die beiden Würfe aus `ssh_stream_command_output()` werden auf den konkreten Typ umgestellt:

- Idle-Budget;
- Gesamtlaufzeitbudget.

Ihre technischen Texte bleiben erhalten. `SshStreamHardeningTest` erwartet in beiden Fällen den finalen Typ und weiterhin den jeweiligen Text. Eine gewöhnliche `RuntimeException` mit identischem Text darf nicht als eigenes Budget gelten.

### 5.3 SFTP-Gesamtbudget

Das SFTP-Gesamtbudget wird als echte Dauer mit einer monotonen Uhr gemessen. Es beginnt nach erfolgreichem Login vor der Verzeichnis-/Uploadarbeit und wird mindestens geprüft:

- vor jeder entfernten SFTP-Operation;
- unmittelbar nach jeder entfernten SFTP-Operation;
- vor dem erfolgreichen Rücksprung.

Vor jeder Operation wird das phpseclib-Timeout auf `min(VIRTUSPHERE_SFTP_OP_TIMEOUT_SECONDS, verbleibendes Gesamtbudget)` gesetzt. Damit kann eine kurz vor Ablauf gestartete Operation die Gesamtgrenze nicht noch um das volle Einzelbudget überziehen; Rundung und Grenzfall `remaining <= 0` werden im Helper zentral behandelt. Die letzte Datei kann das Gesamtbudget weder während noch nach ihrem Upload unbemerkt überschreiten. Der bestehende technische Text bleibt erhalten und der Wurf verwendet `SshTransportBudgetExceeded`.

Die Zeitentscheidung wird in einen kleinen, rein testbaren Helper mit injizierbarem `now` ausgelagert. Kein Test wartet reale 300 Sekunden.

### 5.4 SFTP-Operationsbudget

Die SFTP-Funktionen werden dafür aus der bereits 366 Zeilen großen `ssh.php` nach `lib/ssh_sftp.php` geschnitten; `ssh.php` lädt dieses Modul. Der Schnitt ist nach Transportdomäne und hält beide Dateien unter dem ADR-0006-Warnwert, statt neue Guard- und Testseams in die bestehende Datei zu drücken.

Die installierte phpseclib-Version stellt `isTimeout()` bereit. Eine entfernte Operation kann bei einem abgelaufenen Paketbudget `false` liefern oder eine Exception wegen des fehlenden erwarteten Pakettyps werfen. Deshalb entsteht dort ein testbarer Operations-Guard, den Verzeichnisaufbau, `put()` und die Probe mit `put()`/`delete()` benutzen:

1. Die konkrete Operation innerhalb `try/catch` ausführen.
2. Bei `false` oder `Throwable` sofort `isTimeout()` prüfen, bevor `disconnect()` oder ein weiterer SFTP-Aufruf den Zustand verändert.
3. Bei Timeout `SshTransportBudgetExceeded` mit einem technischen Operationsbudget-Text werfen.
4. Bei anderer Exception `SftpTransportFailed` mit der ursprünglichen Exception als `previous` werfen.
5. Bei sonstigem, für diese Operation nicht erlaubtem `false` ebenfalls `SftpTransportFailed` werfen.

Ein legitimes `false` von `is_dir()` wird nur dann als „Verzeichnis fehlt“ behandelt, wenn `isTimeout()` falsch ist; anschließend wird das Ergebnis von `mkdir()` zwingend geprüft. Der Guard nimmt ein `SFTP`-Objekt und ist mit einem PHPUnit-Mock ohne Netzwerk testbar. Getrennte Fälle beweisen Timeout, Rechte-/Statusfehler und fremde Exception. Der äußere Upload bzw. die Probe besitzt den einzigen `try/finally`-Cleanup und trennt die Verbindung genau einmal; der Guard selbst disconnectet nicht und kann dadurch den Timeoutzustand nicht vor der Klassifikation löschen. Logger-/Heartbeat-Callbacks laufen außerhalb des SFTP-Guards, damit insbesondere ein `mysqli_sql_exception` unverändert bis zum Worker gelangt.

Ein fehlgeschlagener SFTP-Login nach dem bereits grünen SSH-/Preflight-Test sowie alle anschließenden Operationsfehler werden `SftpTransportFailed`. `ssh_sftp_probe()` benutzt dieselbe Auswertung und prüft auch das Ergebnis des Löschens, damit keine scheinbar grüne Probe Dateien zurücklässt. Der synchrone Zugangstest mappt einen `SshTransportBudgetExceeded` aus der Probe auf `ansible_timeout`; andere SFTP-Probleme bleiben `VIRTUSPHERE_CREDENTIAL_TEST_SFTP`. Eine `SshTransportConfigurationException` bleibt dagegen `config`.

Der direkte `false`-Rückgabepfad von `SSH2::login()` im SSH-Zugangstest wird ausdrücklich von `auth` auf `ansible_auth` umgestellt; er läuft heute nicht durch `credential_test_ssh_failure()` und darf deshalb nicht nur indirekt eingeplant werden.

Etappe 6 zieht gleichzeitig alle von den neuen Exceptiontypen und Zeitbudgets betroffenen Hilfe-/Dokusätze nach. Technische Fehltexte bleiben für Job-/Containerlogs aussagekräftig, werden auf Secret-Redigierung und falsche Ursachenbehauptungen geprüft und verändern keinen Machine-API- oder MECM-Wire-Contract. Require-Closure, Timeouttexte, Betriebsanweisung und Logwirkung werden im Etappenabschluss gemeinsam gegen den Diff geprüft; nichts davon wird zur späteren Text- oder Dokuetappe zurückgestellt.

---

## 6. Etappe 7: Gemeinsame Ansible-Abbildung

Die source-spezifische Abbildung lebt nicht im Worker-Modul. Sie kommt verbindlich in das bereits dafür zuständige, dependency-arme `lib/connection_errors.php`.

Eine gemeinsame Funktion, etwa `ansible_connection_error_category(Throwable $exception)`, wird von `deploy_worker_outcome.php` und `ssh.php` verwendet:

| generischer Befund | Ansible-Code |
|---|---|
| Budgettyp | `ansible_timeout` |
| typisierter SFTP-Transport | `ansible_sftp` |
| `dns` | `ansible_dns` |
| `unreachable` | `ansible_unreachable` |
| `auth` | `ansible_auth` |
| `authz` | `ansible_authz` |
| `certificate`, `tls`, `parse` | `ansible_transport` |

Die Typprüfungen stehen vor der Textklassifikation. Die anschließende Abbildung der tatsächlich möglichen Rückgaben von `connection_error_category()` ist exhaustiv und besitzt keinen stillen `default`. `connection_error_category()` selbst bleibt der generische Fremdtext-Klassifikator; seine `timeout`-Nadeln bleiben bestehen, weil ein Timeout beim Verbindungsaufbau weiterhin korrekt `ansible_unreachable` ist.

`ConnectionErrorTest` beweist die generische Klassifikation und die Ansible-Qualifizierung getrennt. `credential_test_ssh_failure()` verwendet ausschließlich die gemeinsame Ansible-Funktion, sodass Zugangstest und Inventarworker nicht auseinanderlaufen können.

Die reinen Prädikate auf gespeicherten Codes leben bei deren Konstanten in `deploy_constants.php`, damit das Repository-Modul keine Sprach-/Darstellungsabhängigkeit laden muss:

- `inventory_error_is_ansible(string $category): bool` basiert auf dem stabilen Präfix `ansible_`;
- `inventory_error_pauses_esxi(string $category): bool` ist ausschließlich bei `auth` wahr;
- Legacy-Erkennung für `ssh` und `http` wird an einer Stelle gehalten, falls sie außerhalb von Hilfe/Doku gebraucht wird.

Etappe 7 aktualisiert zugleich alle Verbraucherbeschreibungen dieser gemeinsamen Abbildung: Hilfe und Betriebsdoku unterscheiden Ansible- und ESXi-Ursprung, Changelog und gegebenenfalls ADR/QA nennen die neue Ownership, und Audit-/Pauseprotokolle werden auf die gemeinsame Prädikat-SSoT umgestellt oder nachweislich als unverändert bestätigt. Der Abschluss sucht ausdrücklich nach einer zweiten Mapping-Tabelle, alten `auth`-Sonderfällen und Texten, die weiterhin den falschen Host oder die falsche Reparaturstelle nennen.

---

## 7. Etappe 8: Worker-Wiring, Pause und Logging

### 7.1 Phasen

Zu den vorhandenen Phasen kommt `VIRTUSPHERE_DEPLOY_PHASE_SFTP`.

- Vor Inventar-Preflight: `SSH`.
- Vor Upload und während der Upload-Callbacks: `SFTP`.
- Vor dem gestarteten Inventory-Playbook: `TRANSPORT`.
- Vor Marker-Parsing: `MARKER`.
- Vor Cache-/Statusschreibpfad: `DB`.

### 7.2 Throwable statt Text

Der Inventar-Catch übergibt `$phase, $exception`, nicht `$phase, $exception->getMessage()`. `PhaseCContractTest` pinnt die vollständige Übergabe. Die Verhaltenstests bleiben der primäre Beweis; der Static-Test beweist nur das Wiring.

### 7.3 Preflight

Ein Exitcode ungleich null setzt `ansible_preflight`. Die technische Jobzeile enthält weiterhin Exitcode und, sofern vorhanden, die von `ansible_preflight_failed_component()` erkannte Komponente.

Der Inventar-Preflight prüft bewusst keinen Portal-Rückkanal; Texte und Doku behaupten für diesen Pfad deshalb nicht, dass `health.php` oder die Machine-API-Allowlist geprüft wurden.

### 7.4 Playbook-Klassifikation

`ansible_categorize_inventory_error()` erhält die beiden engen Ansible-Konfigurationsfälle aus Abschnitt 2.3. Eindeutige ESXi-/vCenter-Antworten behalten ihre bestehenden Codes. Der Exitcodepfad setzt `$failCategory` weiterhin vor dem Wurf, damit die Playbook-Evidenz nicht durch den äußeren Transportklassifikator überschrieben wird.

### 7.5 Pause und Audit

`repo_esxi_inventory_record_failure()` verwendet `inventory_error_pauses_esxi()` oder den äquivalenten exakten SSoT-Vergleich. Integrationstests beweisen:

- `auth` setzt die Pause;
- `ansible_auth`, `ansible_authz`, `ansible_timeout` und `ansible_unreachable` setzen sie nicht;
- Erfolg löscht Fehlercode, Failure-Streak und Pause wie bisher;
- Speichern des ESXi-Zugangs hebt eine bestehende Legacy-Fehlpause wie bisher auf.

Die vorhandene Once-per-Onset-Semantik der Audit-Zeile „ESXi inventory auto-pull paused“ bleibt erhalten: Sie entsteht nur, wenn der exakte Code `auth` die Pause von 0 auf 1 setzt, nie bei `ansible_auth` und nie erneut für eine schon pausierte Zeile.

### 7.6 DB-Callbackfehler

Ein transienter `mysqli_sql_exception` aus Stream-Logger oder Heartbeat wird zuerst durch den in Etappe 2 gebauten aktiven DB-Kanal behandelt und erreicht den Klassifikator nach erfolgreichem Reconnect nicht. Nur ein nicht wiederherstellbarer beziehungsweise im begrenzten `--once`-Pfad weitergereichter DB-Fehler wird phasenunabhängig `worker`. Wenn selbst nach dem Remote-Ausgang keine DB-Verbindung hergestellt werden kann, verspricht der Plan keine unmögliche dauerhafte Jobzeile; der lokale Ausgang und die redigierte Zustandsmeldung bleiben erhalten, bis der gemeinsame Reconnect-/Ownershippfad finalisieren oder kontrolliert abbrechen kann.

Die technische Fehlermeldung im Joblog bleibt durch `deploy_worker_redact_secrets()` gegen ESXi- und Ansible-Secret redigiert. `logs/error.log` wird nicht als Speicherort des Originalfehlers dokumentiert.

Etappe 8 ist erst abgeschlossen, wenn Workerzustand, Pause, Audit, Joblog und Containerlog als zusammenhängender Beobachtbarkeitsvertrag geprüft sind. In derselben Etappe werden die dazugehörigen Hilfesätze, das Inventar-Runbook, QA-/Deployment-Aussagen und der Changelog-Abschnitt aktualisiert. Der Soll/Ist-Abgleich beweist außerdem, dass kein Fehler als erfolgreich persistiert wird, kein Secret in einem dauerhaften oder flüchtigen Log landet und keine neue Kategorie den Machine-API-Wire-Contract erreicht.

---

## 8. Etappe 9: Anzeige, handlungsfähige Links und Zugangstest

Die Basistexte, Hilfe und Betriebsdoku wurden bereits in den Etappen 5 bis 8 zusammen mit ihrem jeweiligen Verhalten erstellt und geprüft. Etappe 9 integriert diese vorhandenen SSoT-Texte in die sichtbaren Portalzweige, ergänzt die handlungsfähigen Links und gleicht den synchronen Zugangstest ab. Sie ist ausdrücklich kein Auffangbecken für zuvor ausgelassene Texte oder Doku. Alle in dieser Etappe neu oder anders sichtbaren Texte entstehen in DE und EN mit `__t()` und gleicher Platzhaltermenge; ihre Hilfe-, Log- und Doku-Auswirkung wird wiederum in Etappe 9 abgeschlossen.

### 8.1 Abgleich der in Etappe 5 eingeführten Basissätze

Diese Sätze werden nicht erst in Etappe 9 angelegt. Etappe 9 prüft sie gegen die nun vollständig verdrahteten Anzeigezweige und korrigiert innerhalb dieser Etappe nur Abweichungen, die durch deren konkrete Darstellung entstehen:

Die `common.conn_*`-Sätze nennen Ursache und betroffene Schicht, aber keinen Anzeigekanal:

- `conn_ansible_dns`: Ansible-Host nicht auflösbar; Ansible-Hostname und Portal-DNS prüfen.
- `conn_ansible_unreachable`: Netzwerk/Port/Firewall zwischen Portal und Ansible-Host prüfen.
- `conn_ansible_auth`: Anmeldung am Ansible-Host abgelehnt; Ansible-Benutzer, Secret und Kontostatus prüfen. Nicht behaupten, das Secret sei sicher falsch.
- `conn_ansible_authz`: Anmeldung stand, aber die benötigte Sitzung/Aktion war nicht erlaubt; Rechte und SSH-/SFTP-Policy prüfen.
- `conn_ansible_preflight`: Verbindung stand, Toolchain-Vorprüfung fehlgeschlagen.
- `conn_ansible_config`: Playbook, Modul, Collection oder Ansible-Ausführungsumgebung fehlt bzw. ist unvollständig.
- `conn_ansible_sftp`: SSH-Anmeldung stand, aber die SFTP-Übertragung scheiterte.
- `conn_ansible_timeout`: Vorgang überschritt nach aufgebauter Ansible-Verbindung sein Zeitbudget.
- `conn_ansible_transport`: sonstiger Transportfehler zum Ansible-Host.

Keiner dieser Texte interpoliert die ESXi-Adresse. Ein Unit-Test ruft jeden Ansible-Code mit `['host' => 'esxi-should-not-appear']` auf und beweist, dass dieser Wert nicht im Satz erscheint.

`common.conn_ssh` und `esxi_cause_fix_ssh` bleiben als Legacy verständlich und sagen, dass ein neuer Abruf den Befund durch einen präziseren Ansible-Code ersetzt.

### 8.2 Systemstatus-Hilfe und Anzeige

Für jede Kategorie existiert `help_system_status.esxi_cause_fix_<code>`.

Zusätzlich werden korrigiert:

- `esxi_inv_p2`: Nur eine abgelehnte ESXi-/vCenter-Anmeldung pausiert die Automatik; Ansible-Auth nicht.
- `esxi_cause_p1`: Der Joblog-Link existiert nur solange der Auftrag aufbewahrt wird und nur mit `deploy.run`.
- Timeout-Hilfe nennt nur lesende Inventarursachen: ausgelasteter Ansible-Host, stockende SFTP-Übertragung, langsame ESXi-/vCenter-Abfrage, großer Objektbestand. Keine VM-Erstellung, Festplattengröße oder Adoption in dieser Hilfe.

### 8.3 Link zur Reparaturstelle

Zeigt die ESXi-Karte einen `ansible_*`-Code, rendert sie zusätzlich den über `system_status_url(VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_ANSIBLE)` gebauten und mit dem neuen Schlüssel `system_status.inv_open_ansible_status` übersetzten Link „Ansible-Status öffnen“. Das Ziel ist ein Abschnitt derselben bereits autorisierten Seite und braucht deshalb keine erfundene Zusatzberechtigung. Dort bleiben der Volltest und die Zugangsdaten-Aktion wie heute an `credentials.manage` gebunden. Der erklärende Satz bleibt für jeden Betrachter sichtbar.

Der bestehende Joblog-Link bleibt separat an `deploy.run` gebunden. `SystemStatusPanelBranchTest` beweist Ansible-Link, Joblog-Berechtigungszweige und dass kein ESXi-Host in einem Ansible-Fehler erscheint. Der neue Link wird nicht als handgeschriebener Fragment-String gebaut.

### 8.4 Zugangstest

`credential_test_ssh_failure()` liefert dieselben `ansible_*`-Codes. Der Flash zeigt sein redigiertes Detail weiterhin direkt an und verweist nicht auf ein nicht existentes Jobprotokoll.

Der Ownership-Kommentar in `credentials_test_message.php` wird dabei berichtigt: Ein Testergebnis ist die Vereinigung aus den wenigen test-only Codes `VIRTUSPHERE_CREDENTIAL_TEST_*` und den gemeinsamen Inventarfehlercodes, nicht ausschließlich die erste Gruppe. Die Darstellung bleibt bei `connection_error_message()` als SSoT und erhält keine zweite Mapping-Tabelle.

Der Etappenabschluss prüft alle sichtbaren Kategorien mit und ohne `deploy.run` beziehungsweise `credentials.manage`, die Zielanker der Links, DE/EN-Hilfe und die Herkunft des technischen Details. Audit- und Jobloglinks müssen auf den bestehenden autorisierten Kategorien landen; es entsteht kein paralleles Protokoll. Changelog, QA und Betriebsdoku werden für die tatsächlich sichtbare Bedienung in derselben Etappe vervollständigt.

---

## 9. Etappe 10: Betriebsabnahme und Deploy-QoL

Die Betriebsdoku und der Changelog werden nicht erst hier begonnen: Jede vorherige Etappe hat ihre fachlichen Aussagen bereits zusammen mit Code, Hilfe und Protokollen aktualisiert und abgenommen. Etappe 10 konsolidiert ausschließlich die vollständige Bedien- und Rollout-Sicht, prüft die etappenweise fortgeschriebenen Texte gegeneinander und setzt den getrennten Deploy-QoL-Hunk um. Entdeckt diese Konsolidierung eine alte Lücke, wird die verursachende Etappe wieder geöffnet; die Lücke gilt nicht als reguläre Arbeit von Etappe 10.

### 9.1 Gesamtabgleich von `docs/operations/esxi-inventory.md`

Die folgenden Punkte wurden jeweils bereits in der Etappe umgesetzt, die das zugehörige Verhalten änderte. Hier werden sie als geschlossene Gesamtheit erneut gegen Code, Hilfe und Logs geprüft, nicht erstmals nachgetragen:

- Überschrift „Fehlerbilder (nie blockierend)“ ersetzen, zum Beispiel durch „Fehlerbilder: Cache bleibt erhalten; nur ESXi-Auth pausiert die Automatik“.
- Tabelle exakt um alle neuen Codes erweitern.
- `timeout` aus der alten `unreachable`-Bedeutung entfernen.
- `ssh` und `http` als Legacy kennzeichnen.
- Richtigstellen: Das technische Original steht im aufbewahrten DB-Jobprotokoll. Es gibt an der ESXi-Karte keine persistierte Detailspalte und keinen zusätzlichen Originalfehler in `logs/error.log`.
- Berechtigungs- und Retentionsgrenze des Links nennen.
- Altbestand erklären: kein sicherer Backfill; gezielter erfolgreicher Einzelabruf oder erneutes Speichern löst eine historische Fehlpause.
- Die bewusst nicht umgesetzte Detailpersistenz knapp dokumentieren: Kategorie bleibt dauerhaft, Detail folgt der Jobaufbewahrung. Eine spätere Spiegelung erfordert gemeinsame Redigierung beider Secrets, Löschen bei Erfolg und eine bewusste längere Aufbewahrung. Dafür wird kein eigener ADR nur auf Vorrat angelegt.

### 9.2 Changelog-Gesamtabgleich

Der seit Etappe 1 fortgeschriebene Eintrag nennt abschließend:

- getrennte Ansible-/ESXi-Fehlerherkunft;
- beseitigte falsche ESXi-Pause bei Ansible-Auth;
- typisierte SSH-/SFTP-Budgets;
- unveränderte alte Zeilen bis zum nächsten erfolgreichen Einzelabruf;
- manuelle Alternative über erneutes Speichern des ESXi-Zugangs.

### 9.3 Deploy-Hilfe

Die Klarstellung in `help_deploy.deploy_identity_p2` bleibt Teil dieses Plans, aber als eigener Hunk:

- Nur ein Abbruch/Timeout während oder nach dem Create-Schritt kann bereits erzeugte VMs hinterlassen.
- VMs mit bereits bestätigtem MAC-Import behalten ihren bereitgestellten Zustand.
- Vor einem Wiederholungslauf Bestand und Identität prüfen.

Diese Aussage steht ausschließlich in der Deploy-Hilfe, nicht in der read-only Inventarhilfe.

Der Etappenabschluss vergleicht Runbook, Deployment-/QA-Anleitung, Changelog, Portalhilfe und tatsächliche Log-/Protokollpfade als vollständige Operatorreise. Er prüft insbesondere, wo das technische Original liegt, wer den Link sehen darf, wie lange er gültig bleibt, welches Audit ausgelöst wird und welche Maschinenverträge bewusst unverändert bleiben. Etappe 10 ist erst abgeschlossen, wenn dieser Soll/Ist-Abgleich und der eigenständige Deploy-QoL-Hunk jeweils grün nachgewiesen sind.

---

## B. UX-Etappen 11–17

Für alle folgenden Etappen bleiben Machine-API-Wire-Verträge, die fünf Legacy-Statusstrings, technische DB-/Workerwerte, persistente Joblogs, RBAC, CSRF, CSP und Runtime-Air-Gap unverändert, soweit eine Etappe nicht ausdrücklich einen additiven Portal-JSON-Vertrag nennt. Technische MECM-/Ansible-Begriffe werden nicht übersetzt, wenn sie echte Schnittstellenwerte sind. Sichtbare geschlossene Zustandsmengen werden dagegen auf der Portalebene lokalisiert.

### Etappe 11: Kollisionssichere UX-Basis und deterministischer Visual-Harness

1. Vor Beginn müssen Etappen 1–10 einschließlich ihrer Abnahmezeilen grün sein. Unter `qa-artifacts/` entsteht ein Manifest mit HEAD, Branch, `git status --short`, Diffstat, `git diff --check`, Dirty-/Clean-Status aller UX-Zieldateien und SHA-256 jedes dirty oder untracked Zielinhalts. Untracked Inhalte werden nicht nur über Patchhashes geprüft. Vor jedem Schreibvorgang wird der Dateihash erneut verglichen; eine Kollision führt zum erneuten Lesen des betroffenen Diffs, nicht zum Zurücksetzen fremder Arbeit.
2. Die Fast-Basis läuft über `scripts/check.ps1 -Lane Fast`. Der benutzer- und revisionsgebundene Chromium-Fallback in `check.ps1` entfällt. `check.ps1` und Playwright konsumieren einen gemeinsamen, getesteten Browser-Resolver beziehungsweise denselben Auflösungsvertrag; zwei eigenständige „höchste Revision“-Algorithmen sind unzulässig.
3. Das Visual-Projekt wird in die bestehende Playwright-Konfiguration integriert und teilt Base-URL, Auth und Reporter. Es verwendet ausschließlich einen synthetischen Wegwerf-QA-Stack mit idempotentem Seed. Worker werden nur dort nach Ausschluss aktiver Jobs pausiert und in `finally` in den Ausgangszustand versetzt; ein Shared-, Dev- oder Produktionsstack darf nicht für Baselines angehalten werden.
4. Pixelbaselines laufen in genau einem deklarierten Chromium-Projekt mit festem OS-, Browser-, Playwright- und Fontvertrag. Firefox, WebKit und Edge bleiben funktionale Releaseprojekte, teilen aber keine Pixelbaseline. Metadaten nennen OS, Browserrevision, Playwright-Version, Fonts/-hashes, Locale, Portalzeitzone, Viewports, `deviceScaleFactor` und Screenshotscale. Ein Mismatch ist `infrastructure_error`, kein Grund für automatische Neubaselines.
5. Uhr, Zufallsquelle, Hell-/Dunkelzustand, Locale, Zeitzone, Viewports und `prefers-reduced-motion` werden fixiert; Screenshots verwenden deaktivierte Animationen und versteckten Caret. Deterministische Seedwerte haben Vorrang vor Masken. Jede unvermeidbare Maske ist eng, benannt und reviewed; Statusbadges werden nie maskiert. Vergleich erfolgt pixelbasiert mit dokumentierter enger Toleranz, nicht über PNG-Bytes.
6. Browser/Fonts werden über den vorhandenen offline-fähigen QA-/Dev-Pfad bereitgestellt; es entsteht keine Runtime-Downloadabhängigkeit.

Etappenabschluss:

- Fast-Basis grün; zwei identische Visual-Läufe beider Themes bleiben innerhalb der Toleranz; Vorherbilder und Metadaten sind reviewbar.
- Positive/negative Tests beweisen Browserresolver, falsche Runner-Metadaten, Worker-Restore und Seed-Isolation.
- `docs/QA.md`, ADR-0028 und Changelog beschreiben Resolver, Visualprojekt, Updatepolitik und Restore. Portalhilfe, Audit-, Job-, Container- und Wire-Verträge werden geprüft und begründet als nicht betroffen protokolliert.

### Etappe 12: Hochwirksame UX-Korrekturen und vollständige Deploy-Blocker

Passwort und kleine Darstellung:

1. `users.php` und `account.php` verwenden die Backend-SSoT `password_policy_min_length()` als `minlength` und lokalisierten sichtbaren Hinweis. Neue Passwörter erhalten `autocomplete="new-password"`, das aktuelle Kontopasswort bleibt `current-password`. Resetfelder erhalten eindeutige IDs und ein zugeordnetes sichtbares oder `sr-only`-Label; Placeholder sind keine Bezeichnung.
2. Deployhinweise unterscheiden textlich zwischen echtem Blocker und nicht blockierender Warnung; Farbe ist nie der einzige Träger. `.alert-info` ergänzt nur die fehlende Infosemantik, ohne den Grundrahmen zu duplizieren.
3. `portal_format_duration()` erhält Singularformen für Sekunde, Minute und Stunde einschließlich der Grenzen 0/1/59/60/3599/3600 und des Millisekundenpfads.
4. Das MECM-Feld `updated` erscheint in VM-Liste und Editordiagnose bei `1` als „Für MECM vorgemerkt“, sonst als Gedankenstrich. Flag und Maschinenverhalten bleiben unverändert.

Vollständiger Blockervertrag:

5. `deploy_queue_blockers()` vereinigt Basisvoraussetzungen, ausgewählte leere Mission, VM-Identitätskonflikte und jeden späteren echten Queue-Blocker für denselben normalisierten Formularzustand. Das Ergebnis ist eine diskriminierte Union mit gemeinsamen Feldern `kind`, `code`, `message`, optionaler strukturierter `action` samt Zielberechtigung sowie kind-spezifischen Daten. `target_id` ist nur dort Pflicht, wo es fachlich existiert. Der Renderer behandelt alle bekannten `kind` exhaustiv; Unknown scheitert im Test.
6. `$canQueue`, Anzahl, Singular/Plural, Sprungziel und sichtbare Blocker werden ausschließlich aus dieser Liste abgeleitet. Das Repository-Gate normalisiert und prüft unmittelbar vor dem Queue-Schreibvorgang erneut.
7. Weil Credential-, ESXi- und VM-Auswahl live wechseln, berechnet ein read-only, Session-/RBAC-geschützter JSON-Endpunkt denselben Aggregator aus allen aktuellen Controls und demselben Form-State-Vertrag. Disabled-but-filled Werte gehen nicht verloren. Der Client arbeitet debounced und Single-Flight, verwirft veraltete Antworten, prüft Content-Type und stoppt bei `401`/`403` mit lokalisierter Handlungsmeldung. DOM-Text/Links werden ohne HTML-Injektion gebaut; erfolgreiche Abfragen erzeugen keine Audit-/Errorlogs. Der Endpunkt ist QoL, nie Sicherheitsgrenze.
8. Identitätskonflikte behalten Refresh-/Adoption-Aktionen und deren bestehende `vms.write`-/Bestätigungsregeln. Linklabels benennen das Portalziel; erklärender Text bleibt auch ohne Zielberechtigung sichtbar.

Navigation und Help-SSoT:

9. Missionsdetails und VM-Liste erhalten bei echten Missionen und `deploy.run` einen über Helper gebauten Bereitstellungslink. Missionsnamen in Jobs verlinken auf Missionsdetails; System-/Inventarjobs erhalten keine erfundene Mission.
10. `lib/help_page.php` (Name nach lokaler Konvention) definiert alle gerenderten Panel- und Abschnitts-IDs einschließlich `help-backup`; `help_url($panel, $section)` validiert gegen gerenderte Partials. Zielberechtigung entscheidet beim Rendern über den Link, nicht über die Gültigkeit des Vokabulars. Handgeschriebene `help.php#...`-Links entfallen. Tests prüfen Headerstellen, begründete Ausnahmen, Partials in beide Richtungen sowie das Öffnen/Fokussieren verschachtelter Abschnitte.

Etappenabschluss:

- Unit/Static/E2E decken Passwortattribute, Blocker-Union, alle Live-Änderungen, stale Responses, `401`/`403`, Repo-Recheck, Links, Dauergrenzen, `updated` und Helpanker ab; Lang-Audit und Fast-Lane sind grün.
- `AGENTS.md`, `.claude/rules/portal.md`, `GROK.md`, Portalhilfe, `docs/QA.md` und Changelog sind synchron.
- Backend-Gate, Bestätigungen, RBAC, Audit-/Joblogs, Session-/Fehlerprotokolle und Machine-Wire-Verträge werden im Etappenprotokoll explizit nachgewiesen.

### Etappe 13: Verständlicher Portal-Zustandswortschatz und robuster Jobpoller

1. Technische Werte bleiben roh in Konstanten, DB, Worker, persistenten Joblogs, Maschinenfeldern und bestehenden JSON-Feldern wie `status`. Portal-only-Labels liegen zentral in `lib/portal_status_display.php` für Lifecycle/MECM und `lib/deploy_display.php` für Jobstatus, Modi und Payloadanzeige; DE/EN verwenden einen gemeinsamen Statuskatalog.
2. `lifecycle_badge()` und `mecm_sync_badge()` beziehen die Variante aus bestehender Meta-SSoT, sichtbaren Text aus dem neuen Labelhelper und zeigen für Unknown einen neutralen lokalisierten Fallback ohne Rohwert. Konstanten-Walk-Tests prüfen alle Lifecycle-/MECM-Werte und beide Sprachen; ein Render erzeugt kein Drift-`error_log`.
3. Alle aus aktiven und terminalen SSoT-Mengen abgeleiteten Jobzustände (`queued`, `running`, `cancelling`, `succeeded`, `failed`, `cancelled`, `partial`) werden in Tabelle, initialem Joblogbadge und betroffenen Systemstatusanzeigen lokalisiert. Polling-JSON ergänzt `label`; `status` und `badge` bleiben abwärtskompatibel. `deploy.js` zeigt nur `label`.
4. Der JSON-Poller liefert immer `application/json`: `401` bei fehlender/abgelaufener Session ohne Login-Redirect/-HTML, `403` bei fehlender Berechtigung, lokalisierte `404` bei unbekanntem Job. Auth, RBAC und Locale werden ermittelt, dann wird vor der DB-Pollabfrage `session_write_close()` ausgeführt. Der Client stoppt bei `401`/`403`, prüft Content-Type, arbeitet ohne parallele Requests und loggt erfolgreiche Polls weder in Audit noch Fehlerlog.
5. `virtusphere_deploy_mode_labels()` bleibt technische Validierungs-SSoT. Portal-only-Helper benennen die sechs postbaren Modi sowie den nicht postbaren Systemmodus `inventory`; `deploy_job_payload_summary()` bleibt für technische persistente Logs unverändert.
6. Dashboard-Missionsstatus zeigt `active` lokalisiert als „Aktiv“, leer als Gedankenstrich und andere freie Legacywerte neutral mit ihrem Text; das freie VARCHAR wird nicht fälschlich als Enum behandelt.

Etappenabschluss:

- Konstanten-Walk-, Unknown-, Additiv-JSON-, `cancelling`-, Auth-/Content-Type- und Poll-Single-Flight-Tests sowie Visuals beider Themes sind grün; Fast und Integration laufen.
- DE/EN-Hilfe, Statusglossar, `docs/QA.md`, Changelog und Portalregeln sind aktualisiert.
- Persistente Joblogs, Rohwerte, bestehende JSON-Felder, Auditverhalten und Machine-Wire-Verträge sind unverändert oder additiv belegt.

### Etappe 14: Selbstbeschreibende und barrierearme Formulare

1. Vor Änderung entsteht eine Migrationsmatrix aller Feld-/Gruppen-/JS-Hints und Fehler. Sie nennt Seite, Feld, ID/Label, vorhandene Hint-/Error-ID, Gruppenziel, dynamische Erzeugung und geplante Migration. Allgemeine Prosa wird ausdrücklich ausgeschlossen; eine Anzahl allein genügt nicht.
2. `lib/forms.php` vereinigt `form_hint_id()`, `form_error_id()`, `form_control_attrs()` und `form_error_html()`. `form_control_attrs()` integriert die bisherige `form_input_class()`-Funktionalität, setzt `aria-invalid` am Control und verbindet Hint plus Fehler in `aria-describedby`. Fehlerausgabe erhält eine stabile ID. IDs werden aus Form, Feld und optionaler Zeilen-/Gruppekennung sicher normalisiert; es entsteht keine zweite konkurrierende API. `vm_field_error()` wird Wrapper dieser Kernlogik.
3. Feldhints sind dem Control, Gruppenhinweise einem `fieldset` oder `role="group"` zugeordnet. Wiederholte Zeilen sind eindeutig; JS-Templates ersetzen Indizes vollständig. Dynamische Deployhints halten `aria-describedby` aktuell.
4. Static-/DOM-Negativtests verbieten tote `aria-describedby`-Referenzen, doppelte IDs und Hint-/Fehler-IDs ohne eindeutiges Control oder begründete Gruppe. Erfolgs- und Fehlerzustände, dynamische Zeilen, Tastatur, axe und eine Screenreader-Stichprobe gehören zur Abnahme.

Etappenabschluss:

- Die Migrationsmatrix ist vollständig abgearbeitet; Fast und Integration, DOM-/axe-/E2E-Tests und Visuals sind grün.
- `docs/QA.md`, Formular-/Portalhilfe, DE/EN-Texte, Changelog und dauerhafte Formularregeln sind synchron.
- Audit-, Job-, Container- und Wire-Verträge werden geprüft und, sofern wirklich unberührt, begründet als nicht betroffen protokolliert.

### Etappe 15: Navigation, Tabellen, Statusübersicht und Operatorfilter

Portalnavigation und Tabellen:

1. Nur die VM-Liste erhält opt-in `.table-sticky-actions` und `.table-action-cell` auf `th`/`td`, mit deckendem Hintergrund, Z-Index zum Sticky Header, Hoverzustand und mobiler Deaktivierung. CSS-Vertrag, Desktopgeometrie, erzwungener Wrap, horizontaler Scroll und Mobile werden geprüft; `users.php` bleibt ausgenommen.
2. Missionen/Vorlagen sowie Missionsdetails/VMs werden als normale Seitennavigation mit genau einem `aria-current="page"` dargestellt. Es ist kein ARIA-Tabwidget: kein `role="tab"`, `aria-selected` oder erfundene Pfeiltastensteuerung. IDs/Kontext bleiben erhalten; der VM-Editor bleibt Unterseite.
3. Systemstatus erhält eine fünfte Abweichungskarte: `null` „Nicht geprüft“, `0` „Keine Abweichungen“, `1`/mehr lokalisierte Warnanzahl. Übersicht und Detail teilen einen einmal berechneten Count; der Link läuft über `system_status_url()`. Geometrie deckt fünf Karten und Wrapgrenze ab.
4. OS/Pakete/VLAN unterscheiden echte leere Datenbank von leerem Filterergebnis; „Alle Einträge anzeigen“ setzt `status=all` und erhält `sort`/`dir`. Gesamtzahl wird ohne N+1 ermittelt. VLAN übernimmt `portal_catalog_status_filter()`; Contract-Test verbietet Reimplementierungen.
5. Missionsdetails und VM-Editor teilen je Seite eine Titel-SSoT für sichtbaren und Browser-Titel. Begriffe/Rücksprünge werden vereinheitlicht; Logs und Systemstatus nennen die konfigurierte Portalzeitzone.

Logfilter und Korrelation:

6. `lib/log_filter.php` normalisiert Freitext, IP, Tab/Kategorie, lokale Von-/Bis-Daten, UTC-Untergrenze, exklusive UTC-Obergrenze und Korrelations-ID. Der lokale Zeitraum ist `[von 00:00, Tag nach bis 00:00)` in Portalzeitzone, niemals `+86400`; DST, ungültige Tage, leere Grenzen und `von > bis` werden getestet. Das Repository erhält einen dokumentierten Filter-Struct statt langer Positionsparameter.
7. Ein URL-Builder trägt den validierten Zustand durch Pagination, Tab, Kategorie, Reset, CSV und Korrelationslinks. Ungültige Daten/Korrelation bleiben sichtbar, erzeugen Feldfehler und keine Repository-Abfrage. Tab/Kategorie werden über die Logtaxonomie validiert.
8. Audit-Tabelle und -CSV sowie Deploy-Log-Kopf zeigen die Korrelations-ID. Bei Exaktfilter zeigt `logs.php` passende Jobs mit ID, Mission/Systemjob, lokalisiertem Status und `deploy_job_log_url()`. Auditseite bleibt `users.manage`, Joblogziel zusätzlich `deploy.run`.
9. Passende Jobs sind deterministisch sortiert und begrenzt oder paginiert; Kappung wird sichtbar benannt. Hilfe dokumentiert Retention-Asymmetrie zwischen Audit, Missionsjobs und missionslosen Systemjobs. Korrelation bleibt Diagnostik, keine Autorisierungsgrenze.
10. Indizes auf `deploy_logs.correlation_id` und `deploy_jobs.correlation_id` werden mit repräsentativem `EXPLAIN` vor/nach Änderung belegt; weitere Datums-/Kategorieindizes nur bei nachgewiesenem Nutzen. Migration und Fresh-Schema bleiben synchron; Schema-Konvergenz ist Pflicht.

Etappenabschluss:

- Filter-/DST-/Invalid-Input-Units, kombinierte Repo-Integration, Korrelation Audit→Job→Joblog, CSV-State, Katalogleerzustände, Navigation/Sticky/Wrap und RBAC sind grün; Fast und Integration laufen.
- Portalhilfe, ADR-0032, `docs/QA.md`, betroffene Runbooks, Changelog und Agentregeln sind aktualisiert.
- Audit-CSV/-Tabelle, Joblogdeep-links, Retention, Berechtigungen, Logs/Protokolle und unveränderte Machine-Wire-Verträge sind im selben Etappenabschluss geprüft.

### Etappe 16: Slate-/Indigo-Refresh, Farbtoken und belastbarer Kontrast

1. Beide Themes erhalten eine gemessene Slate-/Indigo-Identität. Info-Blau bleibt vom Akzent unterscheidbar; Sidebar nutzt eigene Tokens und ist von der Inhaltsfläche getrennt. Datenflächen bleiben opak, solide Buttons verwenden `--btn-bg`/`--btn-fg`, Danger bleibt solide und semantisch unverändert.
2. Glows, Schatten und Glas werden dezenter; maximal zwei Blur-Ebenen. Der vorhandene opake `@supports not (backdrop-filter)`-Fallback wird angepasst und getestet, kein zweiter Fallback aufgebaut. Keine externen Assets.
3. Ein tokenisierender/parsenden Static-Guard verbietet außerhalb `base.css` Hex, RGB/HSL/Lab/LCH/OKLCH/`color()`, gewöhnliche Farbnamen und rohe Farben in Gradients, Shadows, `var()`-Fallbacks, verschachtelten Funktionen und dekodierten Data-URLs. Kommentare/normale Strings bleiben Nichttreffer. Erlaubt sind Tokens, `transparent`, `currentColor`, CSS-weite Keywords und ausschließlich tokenbasiertes `color-mix()`.
4. Systemfarben sind innerhalb einer ausdrücklich getesteten `@media (forced-colors: active)`-Policy in passenden Paaren erlaubt; ein pauschales Verbot benannter Farben wäre normwidrig. Der Guard erhält positive, negative, Mutation- und Zero-Match-Fixtures sowie stabile Diagnose-IDs und läuft genau einmal über die bestehende PHPUnit-Suite.
5. Browsernachweise prüfen beide Themes, Inhalts-/Panel-/Glastext, Badges, Fokus, primäre/Danger-Buttons und den opaken Fallback. Normaltext erreicht 4,5:1; große Schrift 3:1 nur ab mindestens 24 CSS-px regulär oder ungefähr 18,66 CSS-px fett; erkennbare UI-/Grafikteile erreichen 3:1 gegen angrenzende Farben.
6. Fokus erfüllt WCAG 2.2 AA einschließlich Nicht-Verdeckung und Non-text Contrast; zusätzlich gilt Focus Appearance als Projektziel: kontrastierende Fläche mindestens entsprechend einer 2-CSS-px-Umrandung und 3:1 Zustandsänderung. Transparente Flächen werden gegen den tatsächlichen Worst-Case-Backdrop alpha-komponiert oder deterministisch pixelgemessen; isolierte Computed-Style-Farben genügen nicht. Forced Colors erhält eine eigene Gegenprobe.

Etappenabschluss:

- Farbguard samt Mutation, Browserkontrast, Farb-nicht-allein-Gegenprobe, axe, Forced Colors, Fast/Integration und Visualvergleich gegen Etappe 11 sind grün.
- ADR-0013, `docs/QA.md`, Portalhilfe, Changelog und UI-Agentregeln sind synchron.
- Danger-/Bestätigungssemantik, Audit-, Job-, Containerlogs und Wire-Verträge werden ausdrücklich geprüft und bei Unberührtheit begründet.

### Etappe 17: Reviewte Sollbaselines und Release-Gate

1. Erst nach fachlichem Review von Etappe 16 werden Sollbaselines für beide Themes sowie Desktop, Wrapgrenze und Mobile erzeugt. `--update-snapshots` ist nur über einen getrennten, ausdrücklich aufgerufenen Updatebefehl erlaubt und läuft nie in Fast-, Integration- oder Release-Gates.
2. Jede Änderung wird mit Diffbild, Runner-Metadaten und fachlichem Grund reviewed und bewusst committed. Runner-/Font-Mismatch darf keine Sollbilder überschreiben. Vorherbilder bleiben Audit-Artefakte, nicht aktuelle Sollbaseline.
3. Die Release-Lane führt das Visualprojekt aus. Ein bewusst mutierter Designtoken muss den Test rot machen. Danach folgt PRE-SHIP mit Tastatur, Fokus, Screenreader-Stichprobe, Hell/Dunkel, Wrap/Mobile und dem Nachweis, dass Screenshots keine echten Daten enthalten.

Etappenabschluss:

- Fast, Integration und Release einschließlich Visualprojekt sind grün; Baseline-Mutation und Metadatenfehler sind negativ bewiesen.
- `docs/QA.md`, ADR-0028, Changelog und Baseline-/Releaseworkflow sind synchron.
- Portalhilfe sowie Laufzeit-Logs/Protokolle werden geprüft und begründet als nicht betroffen protokolliert.

Bewusst nachgelagert und nicht durch diesen Masterplan freigegeben bleiben: ein generischer Dashboard-„Nächster Schritt“, Help-Inhaltsverzeichnisse, Cadence-Zeilen, Sticky-Speichern/Dirty-Warnung und ein generischer Auto-Refresh-Controller. Der eng begrenzte Deploy-Blocker-Endpunkt aus Etappe 12 ist erforderlich und fällt nicht unter diese Vertagung. Jedes spätere Vorhaben benötigt eine eigene Etappe mit demselben vollständigen Abschlussvertrag.

---

## 10. Testmatrix

### Review-Korrekturen und Worker-Resilienz

| Test | Beweis |
|---|---|
| `DiskTypeLabelTest` + PHPStan | exhaustive Label-SSoT ohne stillen `default`; alle Tokens sichtbar benannt |
| `CliRequireClosureContractTest` | vollständige CLI-Entry-Points einschließlich `seed.php`; positive, negative und Zero-Match-Fixtures |
| neuer `DeployWorkerDbChannelTest` | tote Verbindung wird ersetzt; SSH-Stream bleibt offen; Backoff, bounded Spool, Reihenfolge, Überlauf und Redigierung |
| `DeployReapObserverGraceTest` | Grace bewertet nur Beobachterblindheit; `--once`-Vertrag; keine Behauptung über Jobfortsetzung |
| Reaper-Integrationstests | Observation pro Job, `locked_by`, Restart-/aktueller-Status-Zweige, kein Überschreiben verlorener Ownership |
| `AnsibleActivityTest` | `attempts > 0`, queued-cancelled verdeckt nichts, terminale Reihenfolge und credential-getrennte Auswahl |
| `AnsibleActivityContractTest` | Query-/Index-/Frischschema-Vertrag und keine zweite Statuskopie |
| `system-status.spec.js` | vorsichtige Aktivitätsbezeichnung, RBAC-Joblink und responsive Geometrie mit realistischem `attempts` |
| Lang-/Help-/Doc-Suchen | keine rohen Disk-Tokens, Performance-Absolutaussagen, falschen Reaperursachen oder Ausführungsbehauptungen |

### Unit/Static

| Test | Beweis |
|---|---|
| `InventoryErrorVocabularyContractTest` | Kategorien, Texte, Locales, Help, Doku und Spaltenbreite exakt synchron; positive und negative Fixtures |
| `SshTransportExceptionRequireContractTest` | Exception-Klasse in beiden Require-Closures wirklich geladen |
| `SshStreamHardeningTest` | Idle und Gesamtbudget werfen konkreten finalen Typ; Heartbeatsemantik unverändert |
| neuer `SshSftpBudgetTest` | Put/Mkdir/Delete-Timeout, normaler SFTP-Fehler, fremde Exception, DB-Callback bleibt unverpackt, Cleanup genau einmal, Restzeitlimit und Gesamtbudget vor/nach letzter Datei |
| `ConnectionErrorTest` | generischer Klassifikator und gemeinsame `ansible_*`-Abbildung getrennt; Auth/Authz nicht vermischt |
| `DeployWorkerFailureClassificationTest` | vollständige Phasen-/Throwable-Matrix, unbekannte Phase, DB-Callback, SFTP-Fallback |
| `AnsibleInventoryParseTest` | ESXi-Evidenz bleibt ESXi; fehlendes Playbook/Modul und Privilege-Escalation-Timeout werden `ansible_config` |
| `EsxiTrustModeTest` | Zertifikat in CONFIG bleibt certificate |
| `PhaseCContractTest` | Worker übergibt `$phase, $exception`; Preflight-Code ist verdrahtet |
| `SystemStatusPanelBranchTest` | kein ESXi-Host in Ansible-Text; Reparaturlink nach Zielberechtigung; Joblog weiter nach `deploy.run` |
| bestehende Lang-/Doc-/Bounds-Gates | DE/EN, Platzhalter, Doku und Grenzwerte |

Mindestens diese Klassifikationsfälle sind verpflichtend:

1. eigener Idle-Budgettyp in SSH → `ansible_timeout`;
2. eigener Gesamtbudgettyp in TRANSPORT → `ansible_timeout`;
3. eigener SFTP-Operations-/Gesamtbudgettyp in SFTP → `ansible_timeout`;
4. identischer Timeouttext in gewöhnlicher `RuntimeException` → `ansible_unreachable`;
5. Budgettyp in CONFIG → `config`;
6. lokale Transportkonfiguration in SSH/SFTP/TRANSPORT → `config`;
7. `mysqli_sql_exception` in CONFIG/SSH/SFTP/TRANSPORT → `worker`;
8. Ansible-DNS → `ansible_dns`;
9. Ansible-Auth → `ansible_auth`;
10. Ansible-Authz → `ansible_authz`, nie `ansible_auth`;
11. direktes `SSH2::login() === false` im Zugangstest → `ansible_auth`;
12. unbekannter SSH-Text → `ansible_transport`;
13. unbekannter SFTP-Text → `ansible_sftp`;
14. typisierter SFTP-Rechtefehler mit „permission denied“ → `ansible_sftp`, nie `ansible_auth`;
15. SFTP-Probe mit Budgettyp → `ansible_timeout`, sonstiger SFTP-Fehler → `VIRTUSPHERE_CREDENTIAL_TEST_SFTP`, lokale Konfiguration → `config`;
16. Preflight-Exitcode → `ansible_preflight`;
17. fehlendes Playbook/Modul/Collection bzw. eindeutig fehlende Controller-Abhängigkeit in Playbook-Ausgabe → `ansible_config`;
18. naher Negativfall „ESXi object could not be found“ → `parse`, nie `ansible_config`;
19. Privilege-Escalation-Timeout → `ansible_config`;
20. ESXi-Auth aus Playbook-Ausgabe → `auth`;
21. Markerfehler → `parse`;
22. unbekannte Phase → `worker`;
23. unbekannter gespeicherter Code → `conn_unknown`.

### UX Unit/Static/E2E

| Bereich | Verbindlicher Nachweis |
|---|---|
| Visual-Infrastruktur | gemeinsamer Browserresolver; falsche Metadaten rot; Seed-/Worker-Isolation und Restore; zwei stabile Läufe |
| Deploy-Blocker | exhaustive Union; alle Blockerarten; Live-Änderungen; stale Response; `401`/`403`; Content-Type; serverseitiger Recheck; Bestätigung/RBAC |
| Help-SSoT | Panel-/Abschnitts-IDs in beide Richtungen; glob-erfasste Partials; keine handgeschriebenen Links; verschachteltes Ziel öffnet korrekt |
| Passwort/Dauer/`updated` | Autocomplete/Label/Minlength-SSoT; Singulargrenzen; kontextbezogene Flagdarstellung ohne Wireänderung |
| Statusanzeige | Konstanten-Walk DE/EN und Unknown; sieben Jobzustände; additives `label`; Rohfelder unverändert; Poll-Single-Flight und Sessionende |
| Formulare | vollständige Migrationsmatrix; keine toten `aria-describedby`; keine doppelten IDs; Fehler-/Erfolgszustand; dynamische Zeilen; axe/Keyboard |
| Navigation/Tabellen | Seitennavigation mit `aria-current`, kein falsches Tabwidget; Sticky-/Wrap-/Scroll-/Mobile-Geometrie; fünf Statuskarten |
| Katalog/Logs | echte vs. gefilterte Leere; Sortierzustand; DST/Invalid-Input; keine Query bei Fehler; CSV/Pagination; bounded Korrelation und RBAC |
| Schema/Performance | Migration/Fresh-Schema synchron; repräsentatives `EXPLAIN` vor/nach Korrelationsindizes; keine unbegrenzte Jobliste |
| Design/Contrast | parserbasierter Farbguard mit Mutationen; Forced-Colors-Ausnahmen; Text/UI/Fokus/Backdrop-Kontrast; axe; Tokenmutation macht Visual rot |
| Baselines | separater Updatebefehl; Release kann nicht aktualisieren; Metadatenmismatch rot; Diffbild und Grund reviewbar; keine echten Daten |

### Integration

Vor der neuen Inventartaxonomie beweisen Etappen 2 und 3 auf der echten DB-Schicht:

- transienter Callback-DB-Ausfall, Reconnect, Ownership-Recheck und genau eine Finalisierung;
- verlorene Ownership wird weder als Erfolg noch als Fehler überschrieben;
- Reaperbegründung und SYSTEM-Joblog enthalten dieselbe belegbare Beobachtung;
- `attempts=0/cancelled` verdeckt keinen bearbeiteten Missionsjob;
- Migration 0039 ist in Frischschema und inkrementeller Migration synchron und der Query-Plan wurde protokolliert.

Der vorhandene ESXi-Inventar-Integrationstest wird anschließend für Etappen 5 bis 10 erweitert:

- `auth` pausiert;
- `ansible_auth`, `ansible_authz`, `ansible_timeout`, `ansible_unreachable` pausieren nicht;
- Erfolg hebt die Pause auf;
- Speichern hebt eine Legacy-Pause auf;
- Failure-Streak und `last_error_category` bleiben konsistent.

Für Etappen 11 bis 17 beweist die echte QA-Schicht zusätzlich:

- Deploy-Blocker-Endpunkt und Queue-POST verwenden denselben normalisierten Zustand und dasselbe Repo-Gate;
- Polling liefert auch bei Sessionablauf/Berechtigungsfehler ausschließlich den vereinbarten JSON-Vertrag;
- kombinierte Logfilter, lokale DST-Grenzen, CSV-State und Korrelationsnavigation arbeiten gegen echte Repositoryabfragen;
- Korrelationsmigration konvergiert aus Alt- und Frischschema und die Ergebnisliste bleibt begrenzt;
- Visualprojekt läuft ausschließlich gegen den synthetischen QA-Stack und stellt Workerzustand zuverlässig wieder her.

Da die wichtigsten Nebenwirkungen nur gegen die Datenbank beweisbar sind, sind grüne Integrationstests verpflichtend. Ist der QA-Stack nicht verfügbar, ist die Umsetzung nicht vollständig abgenommen; die Sitzung meldet den Infrastrukturblocker statt die Tests optional zu nennen. Ein kontrollierter realer MySQL-Neustart mit laufendem Test-SSH-Stream gehört in die Release-/Staging-Abnahme, nicht in einen Unit-Test und nicht gegen produktive Jobs.

---

## 11. QA und Abnahme

### Entwicklungszyklen

Jede Etappe ist ein eigener Entwicklungs- und Abnahmezyklus: Implementierung, gezielte positive/negative/Zero-Match-Tests, Help/Doku, Logs/Protokolle, vollständiger Diff-Abgleich und Eintrag im Abnahmeprotokoll. Die nächste Etappe beginnt erst danach. Der gezielte Integrationstest folgt bereits in der Etappe, die die Pause-Nebenwirkung ändert; er wird nicht bis zur finalen Integration-Lane aufgeschoben. Keine reale Wartezeit für Budgets, kein echter SSH-/SFTP-Server für die reinen Klassifikationsfälle.

Für jeden bekannten mehrteiligen Prüfumfang gelten die Fortschrittszeilen aus `AGENTS.md`. Bei einem gepufferten Transport wird der Lauf in beobachtbare Blöcke geteilt oder sein letzter `[n/total]`-Stand mindestens einmal pro Minute gemeldet. Das Abnahmeprotokoll nennt den letzten vollständigen Stand und jede fehlgeschlagene Einheit; eine bloße Aussage „Tests laufen“ oder „grün“ genügt nicht.

### Finale Gates

```powershell
powershell -NoProfile -File scripts\check.ps1 -Lane Fast -Json qa-artifacts/qa-deploy-reliability-master-fast.json
powershell -NoProfile -File scripts\check.ps1 -Lane Integration -Json qa-artifacts/qa-deploy-reliability-master-integration.json
powershell -NoProfile -File scripts\check.ps1 -Lane Release -Json qa-artifacts/qa-deploy-reliability-master-release.json
```

Maßstab:

- Fast vollständig grün, ohne Skips im Unit/Static-Gate;
- Integration vollständig grün, insbesondere Pause-Test und `migrate-check`;
- Release vollständig grün; der kontrollierte Staging-Drill dokumentiert DB-Unterbrechung, Worker-Reconnect und den finalen Jobzustand;
- Release führt das Visualprojekt im deklarierten Baseline-Runner aus, ohne Snapshot-Update; Runner-/Fontmetadaten stimmen exakt;
- keine feste Testanzahl;
- keine geduldeten roten Tests;
- keine neue CSP-/Zeilenbudgetwarnung;
- `git diff --check` grün;
- DE/EN-Hilfe im Portal stichprobenartig sichtbar, ohne hartkodierte Dev-Zugangsdaten in dieser Planung.
- manueller PRE-SHIP-Nachweis für Tastatur, Fokus, Screenreader-Stichprobe, Hell/Dunkel, Wrap/Mobile und screenshots ohne reale Daten.

Die Fast-Lane enthält bereits PHPStan, Unit/Static mit vollem Repo-Mount, Sprach-, Bounds-, Doku- und CSP-Gates. Diese Prüfungen werden nicht als scheinbar zusätzliche Einzelbeweise dupliziert.

---

## 12. Reihenfolge

1. Arbeitsbaum und Zielfunktionen erneut lesen; Basis im Protokoll festhalten.
2. Etappe 1: Fast-Blocker, vollständiger CLI-Require-Vertrag, QA-Text und temporäres E2E-Artefakt; danach gezielte Gates, Soll/Ist-Abgleich und Abnahmezeile.
3. Etappe 2: aktiver DB-Kanal, Reconnect, bounded Logspool, Ownership und ausschließlich beobachtende Reapermeldung; danach Unit/Integration, Help/Doku/Logs/Protokolle, Soll/Ist-Abgleich und Abnahmezeile.
4. Etappe 3: Ansible-Aktivitätsquery mit `attempts > 0`, vorsichtige Anzeige, Migration/Index und Query-Plan; danach Unit/Integration/E2E, Help/Doku/Logs/Protokolle, Soll/Ist-Abgleich und Abnahmezeile.
5. Etappe 4: Disk-Label-SSoT und faktisch belastbare DE/EN-Hilfe; danach Unit/Static/Lang/Golden, Doku-/Audit-/Wire-Abgleich und Abnahmezeile.
6. Etappe 5: Inventar-Vokabularvertrag mit negativen Fixtures, betroffenen Texten/Hilfe/Doku, Changelog-Anteil und Logs-/Protokollprüfung; danach Soll/Ist-Abgleich und Abnahmezeile.
7. Etappe 6: Exception-Datei, Require-Contract und alle SSH-/SFTP-Producer samt Hilfe-/Doku-/Logwirkung; danach Soll/Ist-Abgleich und Abnahmezeile.
8. Etappe 7: gemeinsame dependency-arme Ansible-Abbildung samt Verbrauchertexten, Audit-/Pausewirkung und Betriebsdoku; danach Soll/Ist-Abgleich und Abnahmezeile.
9. Etappe 8: Phasen, Throwable-Wiring, Preflight, Playbook-Klassifikation, Pause und Logging auf Basis des DB-Kanals samt zugehöriger Hilfe/Doku/Protokolle; gezielte Integration inklusive, danach Soll/Ist-Abgleich und Abnahmezeile.
10. Etappe 9: sichtbare Portalzweige, handlungsfähige Links und Zugangstest samt Help/Doku/Log-/RBAC-Abgleich; danach Soll/Ist-Abgleich und Abnahmezeile.
11. Etappe 10: Betriebsabnahme und getrennter Deploy-QoL-Hunk samt aktualisierter Hilfe/Doku/Logs/Protokolle; danach Soll/Ist-Abgleich und Abnahmezeile.
12. Etappe 11: kollisionssichere UX-Basis, gemeinsamer Browserresolver und isolierter Visual-Harness; danach Fast-/Determinismusnachweise, Help/Doku/Logs/Protokolle und Abnahmezeile.
13. Etappe 12: Passwort/Dauer/`updated`, vollständige Live-Deploy-Blocker, Missionslinks und Help-URL-SSoT; danach gezielte Unit/Static/E2E, Help/Doku/Logs/Protokolle und Abnahmezeile.
14. Etappe 13: lokalisierter Portal-Zustandswortschatz und robuster JSON-Poller; danach Unit/Integration/Visual, unveränderte Roh-/Wire-Verträge und Abnahmezeile.
15. Etappe 14: Formular-Migrationsmatrix und gemeinsame Accessibility-API; danach DOM/axe/Keyboard/Integration, Help/Doku/Logs/Protokolle und Abnahmezeile.
16. Etappe 15: Navigation, Sticky-Tabelle, Statusübersicht, Kataloge sowie Log-/Korrelationsfilter; danach Schema/EXPLAIN, Unit/Integration/E2E, Help/Doku/Logs/Protokolle und Abnahmezeile.
17. Etappe 16: Slate-/Indigo-Tokenumbau, Glas, parserbasierter Farbguard und zusammengesetzter Kontrast; danach Mutation/Forced-Colors/Visual, Help/Doku/Logs/Protokolle und Abnahmezeile.
18. Etappe 17: reviewte Visual-Sollbaselines und Release-Gate; danach PRE-SHIP, Help/Doku/Logs/Protokolle und Abnahmezeile.
19. Fast-Lane vollständig und mit `[n/total]`-Fortschritt grün.
20. Integration-Lane vollständig und mit `[n/total]`-Fortschritt grün.
21. Release-Lane, Visualprojekt und kontrollierten Staging-DB-Unterbrechungsdrill vollständig und mit `[n/total]`-Fortschritt grün.
22. Unabhängiger Gesamtabgleich über Befunde, Etappenprotokolle und lebende Dateiliste. Gefundene Lücken öffnen die verursachende Etappe erneut; anschließend deren gezielte Tests und betroffene Lanes wiederholen.
23. Gestageten Diff nur lesen/stagen, wenn ein Commit ausdrücklich beauftragt ist.

---

## 13. Vollständige Dateiliste

Voraussichtlich betroffen:

| Datei | Änderung |
|---|---|
| `Docker/WebAPI/lib/defaults.php` | typisierte exhaustive Disk-Label-SSoT; EZT-Default bleibt |
| `Docker/WebAPI/lib/deploy_worker_db_channel.php` | neu: veränderbare aktive DB-Verbindung, Backoff und bounded Logspool |
| `Docker/WebAPI/lib/deploy_constants.php` | neun neue Kategorien, Herkunfts-/Pause-Prädikate und Legacy-Kommentare |
| `Docker/WebAPI/lib/ssh_transport_exceptions.php` | neu: Budget-, SFTP- und lokale Transportkonfigurationstypen |
| `Docker/WebAPI/lib/connection_errors.php` | gemeinsame exhaustive Ansible-Abbildung |
| `Docker/WebAPI/lib/ssh_sftp.php` | neu aus `ssh.php`: SFTP-Producer, Operations-Guard, Gesamtbudget und Probe |
| `Docker/WebAPI/lib/ssh.php` | SSH-Producer, Modul-Require und Zugangstest-Mapping |
| `Docker/WebAPI/lib/deploy_worker_outcome.php` | Observer-/Reapervertrag, DB-Kanal-Hooks, SFTP-Phase und Throwable-/Phasenklassifikation |
| `Docker/WebAPI/lib/deploy_worker.php` | aktiver Reconnect, Streamspool, Ownership, Phasen-Wiring, `$exception`, Preflight-Code |
| `Docker/WebAPI/lib/maintenance_worker.php` | identischer Observer-/Reapervertrag |
| `Docker/WebAPI/lib/integration_health.php` | aktueller Dienststatus bleibt Beobachtung, keine Besitzerursache |
| `Docker/WebAPI/lib/repo/ansible_activity.php` | letzter vom Worker bearbeiteter Missionsjob, `attempts > 0` |
| `Docker/WebAPI/lib/repo/deploy_jobs.php` | Claim-/Attempts- und Reaperbeobachtung bleiben SSoT |
| `Docker/WebAPI/lib/ansible_inventory.php` | `ansible_config`, Privilege-Escalation-Timeout |
| `Docker/WebAPI/lib/repo/esxi_inventory.php` | Pause über SSoT-Helper bzw. exakten Code |
| `Docker/WebAPI/lib/system_status_esxi_panels.php` | Ansible-Statuslink über URL-SSoT; Joblog-RBAC bleibt getrennt |
| `Docker/WebAPI/lib/system_status_panels.php` | vorsichtige Ansible-Aktivitätsanzeige und bestehender Log-Deep-Link |
| `Docker/WebAPI/lib/help/deploy.php` | sichtbarer Disktyp über `disk_type_label()` |
| `Docker/WebAPI/lib/help/missions.php` | Defaultlabel und belastbare Disk-Erklärung |
| `Docker/WebAPI/portal/credentials.php` | Ansible-Aktivitätsnachweis ohne Statuskopie |
| `Docker/WebAPI/lib/credentials_test_message.php` | Vokabular-Ownership im Docblock richtigstellen; Mapping bleibt zentral |
| `Docker/WebAPI/lang/{de,en}/common.php` | neun neue Basissätze, Legacy-Text |
| `Docker/WebAPI/lang/{de,en}/help_system_status.php` | neun Fixtexte, Pause-/Link-/Timeout-Korrekturen |
| `Docker/WebAPI/lang/{de,en}/system_status.php` | neues Linklabel `inv_open_ansible_status` |
| `Docker/WebAPI/lang/{de,en}/help_deploy.php` | präziser Identity-/Timeout-Hinweis |
| `Docker/WebAPI/lang/{de,en}/help_credentials.php` | manueller Volltest vs. bearbeiteter Missionsjob |
| `Docker/WebAPI/lang/{de,en}/help_missions.php` | storageabhängige Disktypen ohne Performance-Absolutaussage |
| `Docker/WebAPI/lang/{de,en}/vm_edit.php` | erster Schreibzugriff, Default und nur Create-Wirkung |
| `Docker/WebAPI/tests/Static/CliRequireClosureContractTest.php` | vollständiger CLI-Entry-Point-Vertrag inklusive `seed.php` |
| `Docker/WebAPI/tests/Static/AnsibleActivityContractTest.php` | Query-/Index-/Frischschema-Vertrag |
| `Docker/WebAPI/tests/Static/InventoryErrorVocabularyContractTest.php` | neu |
| `Docker/WebAPI/tests/Static/SshTransportExceptionRequireContractTest.php` | neu |
| `Docker/WebAPI/tests/Static/PhaseCContractTest.php` | Wiring-Pin |
| `Docker/WebAPI/tests/Unit/ConnectionErrorTest.php` | generisch vs. Ansible, Auth/Authz |
| `Docker/WebAPI/tests/Unit/SshStreamHardeningTest.php` | konkreter Exceptiontyp |
| `Docker/WebAPI/tests/Unit/SshSftpBudgetTest.php` | neu: Operationstypen, Timeoutzustand, Cleanup und Gesamtbudget |
| `Docker/WebAPI/tests/Unit/DeployWorkerDbChannelTest.php` | neu: Reconnect, Spool, Ownership, Redigierung und Exitcode |
| `Docker/WebAPI/tests/Unit/DeployReapObserverGraceTest.php` | reine Observergrenze und `--once`, keine Recovery-Behauptung |
| `Docker/WebAPI/tests/Unit/DiskTypeLabelTest.php` | exhaustive Disklabels und unbekannter Wert |
| `Docker/WebAPI/tests/Unit/DeployWorkerFailureClassificationTest.php` | Phasen-/Throwable-Matrix |
| `Docker/WebAPI/tests/Unit/AnsibleInventoryParseTest.php` | Playbook-/Modul-/Privilege-Escalation-Fälle |
| `Docker/WebAPI/tests/Unit/EsxiTrustModeTest.php` | Throwable-Signatur |
| `Docker/WebAPI/tests/Unit/SystemStatusPanelBranchTest.php` | Text-/RBAC-Linkzweige |
| `Docker/WebAPI/tests/Integration/EsxiInventoryCacheTest.php` | Pause-/Erfolgsmatrix |
| `Docker/WebAPI/tests/Integration/AnsibleActivityTest.php` | `attempts > 0`, Terminal-/Credential-Auswahl |
| Reaper-/Outcome-Integrationstests | DB-Reconnect, Ownership, belegbarer Reapertext und Finalisierung |
| `tests/e2e/specs/system-status.spec.js` | Aktivitätsanzeige, Loglink und Geometrie |
| `tests/e2e/shot.tmp.js` | temporäres Artefakt entfernen, nicht committen |
| `scripts/check.ps1` | benutzergebundenen Chromium-Fallback entfernen; gemeinsamer Resolver; Release-Visualprojekt ohne Updatepfad |
| `tests/e2e/playwright.config.js` | isoliertes Visualprojekt, deklarierter Runner, Metadaten, Viewports/Themes und bestehende funktionale Projekte |
| `tests/e2e/package.json` und Lockdatei | nur falls für pixelbasierten Vergleich/Resolver tatsächlich nötig; keine Runtime-Abhängigkeit |
| `tests/e2e/lib/{auth,visual-seed,visual-runner}.js` bzw. lokale Konvention | bestehende Auth wiederverwenden; synthetischer Seed, Uhr/Locale/Fonts/Worker-Restore zentralisieren |
| `tests/e2e/visual/**` und Baselineverzeichnis | Visualspecs, deterministische Fixtures, reviewte Sollbilder und Metadaten; keine echten Daten |
| `Docker/WebAPI/lib/deploy_page.php` | diskriminierte `deploy_queue_blockers()`-SSoT einschließlich Aktionen und Konfliktdaten |
| `Docker/WebAPI/lib/deploy_form_state.php` | exakt ein normalisierter Formularzustand für Render, Blocker-JSON und Queue-POST |
| `Docker/WebAPI/portal/deploy.php` und neuer read-only Blocker-JSON-Pfad | Anzeige/Queue aus derselben Blockerliste; JSON-Auth/RBAC/Content-Type; Repo-Recheck |
| `Docker/WebAPI/portal/assets/deploy.js` | Live-Blocker debounced/Single-Flight, stale-Abwehr, DOM-Renderer und lokalisierte Authfehler |
| `Docker/WebAPI/lib/help_page.php` | neu: Panel-/Abschnitts-SSoT und validierter `help_url()` |
| `Docker/WebAPI/lib/layout.php`, `Docker/WebAPI/lib/help/*.php`, `Docker/WebAPI/portal/help.php` | Headeranker, Abschnittsvertrag, Dauerpluralisierung und korrektes Öffnen verschachtelter Hilfeziele |
| `Docker/WebAPI/portal/{users,account,mission_details,vms,vm_edit}.php` | Passwortattribute/-labels, Bereitstellungslinks, `updated`, Titel, Sticky-Aktionen und Formularzuordnung |
| `Docker/WebAPI/lib/portal_status_display.php` | neu: lokalisierte Lifecycle-/MECM-Anzeige geschlossener Zustände |
| `Docker/WebAPI/lib/deploy_display.php` | neu: lokalisierte Jobstatus-/Deploymodus-/Payloadanzeige ohne technische Logmutation |
| `Docker/WebAPI/portal/deploy_log.php` | additives Statuslabel, lokalisierte JSON-Fehler und früher Session-Lock-Abschluss |
| `Docker/WebAPI/lib/forms.php` | gemeinsame IDs, Controlattribute und Fehlerausgabe für Hint-/Error-Zuordnung |
| betroffene Portalformulare und JS-Zeilentemplates | vollständige Formular-Migrationsmatrix, eindeutige IDs und dynamisches `aria-describedby` |
| `Docker/WebAPI/portal/{missions,os,packages,vlans,logs}.php` | Seitennavigation, Katalogleerzustände/-filter, Zeitzone und normalisierte Logfilter/Korrelation |
| `Docker/WebAPI/lib/system_status_panels.php` | fünfte Übersichtskarte mit gemeinsamem Abweichungs-Count und SSoT-Link |
| `Docker/WebAPI/lib/log_filter.php` | neu: validierter Filter-Struct, lokale Tagesgrenzen und URL-State |
| `Docker/WebAPI/lib/repo/log.php` und `Docker/WebAPI/lib/repo/deploy_jobs.php` | strukturierter Logfilter und begrenzte/paginierte Korrelationssuche |
| `Docker/WebAPI/portal/assets/css/{base,components}.css` und weitere Portal-CSS-Dateien | Slate-/Indigo-Tokens, vorhandener Glass-Fallback, Sticky-Geometrie, Forced Colors und keine rohen Verbraucherfarben |
| `Docker/WebAPI/lang/{de,en}/**` | Passwort-/Blocker-/Status-/Form-/Navigation-/Filter-/Kontrasttexte und Help-Parität pro verursachender Etappe |
| UX-Unit-/Static-Tests unter `Docker/WebAPI/tests/` | Blocker-, Help-, Status-, Form-, Filter-, CSS-Farb- und Kontrastverträge mit positiven/negativen/Zero-Match-Fällen |
| UX-E2E-Specs unter `tests/e2e/specs/` | Live-Blocker, Poller, Navigation, Form-DOM, Sticky/Wrap, Logfilter/RBAC, axe/Forced Colors und Visuals |
| `Docker/mysql/mysql-init/struktur.sql` | Aktivitätsindex aus Etappe 3, Fehlerkategoriebreite aus Etappe 5 und belegte Korrelationsindizes aus Etappe 15 als Frischschema-SSoT |
| `Docker/WebAPI/lib/migrate.php` | Migration 0039 gegen Query-Plan prüfen/anpassen; Fehlerkategoriebreite aus Etappe 5 sowie begründete Korrelationsindizes aus Etappe 15 spiegeln |
| `docs/operations/esxi-inventory.md` | Tabelle, Heading, Logging, RBAC, Altbestand, Detailgrenze |
| `docs/operations/deploy-chain.md` | aktiver DB-Kanal, Observergrenze und belegbare Reaperdiagnose |
| `docs/operations/troubleshooting.md` | Containerlogpfad, Reaperbeobachtung und Wiederanlauf |
| `docs/DEPLOYMENT.md` | Worker-, Fehlerherkunfts-, Joblog- und unveränderten Wire-Vertrag abgleichen |
| `docs/QA.md` | neue Contract-/Integrationsnachweise und etappenweise Abnahme |
| `docs/adr/ADR-0013-frontend-design-baseline.md` | Slate/Indigo, Token-/Glas-/Forced-Colors-/Kontrastvertrag und unveränderte Danger-Semantik |
| `docs/adr/ADR-0028-playwright-dev-e2e-layer.md` | Visualrunner, Browserresolver, Baseline-Metadaten und bewusster Updateworkflow |
| `docs/adr/ADR-0032-correlation-id.md` | Portal-Korrelationssuche, RBAC/Retention, Begrenzung und belegte Indexannahmen |
| `docs/adr/ADR-0033-cancellation-state-machine.md` | Observer/Reaper/Ownership ohne falsche Ursachenbehauptung |
| `.claude/rules/webapi.md` | vollständiger CLI-Vertrag und ehrliche Reaperregel |
| `.claude/rules/portal.md`, `AGENTS.md`, `GROK.md` | Blocker-/Help-/Status-/Form-/Farbverträge unmittelbar mit ihren Etappen |
| `docs/CHANGELOG.md` | sichtbare Verhaltensänderung und Altbestand |
| diese Datei | Abnahmeprotokoll |

Die Tabelle ist eine lebende Vollständigkeitsliste, keine Schranke. Weitere Dateien werden ergänzt, sobald der Etappenabschluss einen betroffenen SSoT-Verbraucher, Portaltext, Hilfeartikel, ADR, Runbook, QA-Hinweis, Agentvertrag, Audit-, Job-/Containerlog oder technischen Protokollvertrag findet. Die Ergänzung und ihr Grund werden **vor** der Änderung im Abnahmeprotokoll festgehalten; ein fehlender vorhandener Contract-Test ist kein Grund, eine notwendige Anpassung auszulassen.

---

## 14. Nicht Teil dieser Umsetzung

- Änderung der numerischen SSH-/SFTP-Budgets selbst.
- Persistenz von `last_error_detail` am Statusdatensatz.
- Änderung von `VIRTUSPHERE_RUN_ERROR_*` oder MECM-Laufberichten.
- Änderung der Machine-API-Wire-Contracts.
- Rückwirkender, spekulativer Backfill alter Fehlercodes.
- Behauptung, ein Ansible-Prozess laufe nach SSH-Disconnect sicher weiter. Gesichert ist nur, dass vor dem Timeout bereits externe Änderungen erfolgt sein können.
- Historischer Snapshot des Missionsnamens; die Anzeige benennt bewusst den aktuellen Missionsdatensatz.
- Behauptung eines tatsächlich realisierten Festplattentyps ohne Rücklese-Evidenz von ESXi.
- Generischer Dashboard-„Nächster Schritt“, Help-Inhaltsverzeichnisse und noch nicht gegen den finalen Blockervertrag spezifizierte Cadence-Zeilen.
- Sticky-Speichern/Dirty-Warnung im VM-Editor und ein seitenübergreifender Auto-Refresh-Controller; der eng begrenzte Live-Blocker-Endpunkt aus Etappe 12 bleibt Bestandteil.
- Generische persistente `failure_code`-/`failure_phase`-Spalten für alle Jobs, globale Volltextsuche, Digest/Benachrichtigungen und Ersteinrichtungsassistent.
- Rückwirkende Übersetzung persistenter technischer Logs oder Änderung bestehender technischer JSON-/Machine-API-Felder.

---

## 15. Quellenlage

- Ansible trennt einen nicht erreichbaren Host von einem ausgeführten, fehlgeschlagenen Task: <https://docs.ansible.com/projects/ansible-core/2.19/playbook_guide/playbooks_error_handling.html>
- gRPC dokumentiert als Analogie, dass ein Deadline-Fehler den tatsächlichen externen Ausgang nicht sicher beweist: <https://grpc.io/docs/guides/status-codes/> und <https://grpc.io/docs/guides/deadlines/>
- Microsoft fordert getrennte Meldungen je bekannter Ursache und konkrete Reparaturhinweise: <https://learn.microsoft.com/en-us/windows/win32/debug/error-message-guidelines>
- Ansible dokumentiert die Wire-Tokens `thin`, `thick` und `eagerzeroedthick`: <https://docs.ansible.com/projects/ansible/latest/collections/community/vmware/vmware_guest_module.html>
- Broadcom beschreibt Lazy-/Eager-Zeroing und weist auf storage-/workloadabhängige Performance sowie mögliche EZT-Automatisierungstimeouts hin: <https://knowledge.broadcom.com/external/article/308992>, <https://knowledge.broadcom.com/external/article/343258> und <https://knowledge.broadcom.com/external/article/431859>
- WHATWG definiert Passwort-Autocomplete, `minlength` und Constraint Validation: <https://html.spec.whatwg.org/multipage/form-control-infrastructure.html> und <https://html.spec.whatwg.org/multipage/input.html>
- W3C WAI fordert explizite Formularlabels, verständliche Hinweise und zugeordnete Fehlermeldungen: <https://www.w3.org/WAI/tutorials/forms/labels/> und <https://www.w3.org/WAI/tutorials/forms/notifications/>
- WAI-ARIA 1.2 trennt `aria-current="page"` in Navigation von `aria-selected` in echten Tabwidgets: <https://www.w3.org/TR/wai-aria/#aria-current> und <https://www.w3.org/WAI/ARIA/apg/patterns/tabs/>
- WCAG 2.2 definiert Text-, Non-text-, Farb- und Fokusanforderungen: <https://www.w3.org/TR/WCAG22/>, <https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast>, <https://www.w3.org/WAI/WCAG22/Understanding/use-of-color> und <https://www.w3.org/WAI/WCAG22/Understanding/focus-appearance.html>
- CSS Color 4 und Color Adjustment 1 definieren Alpha-Komposition, Systemfarben und Forced-Colors-Verhalten: <https://www.w3.org/TR/css-color-4/> und <https://www.w3.org/TR/css-color-adjust-1/>
- Playwright weist auf renderumgebungsabhängige Screenshots hin und dokumentiert Pixelvergleich, Baseline-Updates, feste Uhr sowie Locale/Zeitzone/Viewport: <https://playwright.dev/docs/test-snapshots>, <https://playwright.dev/docs/clock> und <https://playwright.dev/docs/emulation>
- PHP dokumentiert Sessionlocking und den frühen Abschluss per `session_write_close()`: <https://www.php.net/manual/en/function.session-write-close.php> und <https://www.php.net/manual/en/session.examples.basic.php>
- MySQL verlangt für konkrete Indexentscheidungen eine Prüfung des Ausführungsplans; zusammengesetzte Indizes wirken abhängig von Spaltenreihenfolge und Query: <https://dev.mysql.com/doc/refman/8.4/en/using-explain.html> und <https://dev.mysql.com/doc/refman/8.0/en/multiple-column-indexes.html>

Die externen Primärquellen und offiziellen Dokumentationen stützen Standards, Werkzeugverhalten, Taxonomie und Textgestaltung. Die konkrete VirtuSphere-Wirkung wird ausschließlich durch Repository-Code, Schema, Tests und Etappenprotokolle bewiesen.

---

## 16. Abnahmeprotokoll

Die ausführende Sitzung ergänzt je Etappe eine Zeile, bevor die nächste Etappe beginnt. Jedes Nachweisfeld enthält entweder einen konkreten Diff-/Test-/Pfadnachweis oder `nicht betroffen: <Begründung>`. Ein leeres Feld bedeutet nicht abgeschlossen. `Ergebnis = grün` ist nur zulässig, wenn der Soll/Ist-Abgleich keine offene Anforderung und keine auf später verschobene Help-/Doku-/Log-/Protokollarbeit enthält.

| Etappe | Datum | Ergebnis | Soll/Ist und Diff | Tests/Gates | Help/Doku | Logs/Protokolle | Abweichung/Begründung |
|---|---|---|---|---|---|---|---|
| Basis/Arbeitsbaum | | | | | | | |
| 1 Fast/CLI/Hygiene | | | | | | | |
| 2 DB-Ausfall/Reaper | | | | | | | |
| 3 Ansible-Aktivität | | | | | | | |
| 4 Disk-SSoT/Hilfe | | | | | | | |
| 5 Inventar-Vokabularvertrag | | | | | | | |
| 6 Budgettyp und Producer | | | | | | | |
| 7 gemeinsame Ansible-Abbildung | | | | | | | |
| 8 Worker/Pause/Logging | | | | | | | |
| 9 Anzeige/Links/Zugangstest | | | | | | | |
| 10 Betriebsabnahme/Deploy-QoL | | | | | | | |
| 11 UX-Basis/Visual-Harness | | | | | | | |
| 12 UX-Quick-Wins/Deploy-Blocker/Help | | | | | | | |
| 13 Portal-Zustände/Jobpoller | | | | | | | |
| 14 Formular-Accessibility | | | | | | | |
| 15 Navigation/Tabellen/Logfilter/Korrelation | | | | | | | |
| 16 Design/Farbguard/Kontrast | | | | | | | |
| 17 Visual-Baselines/Release-Gate | | | | | | | |
| Fast-Lane | | | | | | | |
| Integration-Lane | | | | | | | |
| Release-Lane/Staging-Drill | | | | | | | |
| Gesamtabgleich | | | | | | | |

### Befundabgleich

| Befund | Erledigt durch | Nachweis |
|---|---|---|
| Fast-Gate scheitert an `disk_type_label()` | 1 | |
| CLI-Guard behauptet mehr Entry-Points als er prüft | 1 | |
| temporäres E2E-Screenshot-Skript liegt im Arbeitsbaum | 1 | |
| Grace stellt aktiven Jobheartbeat nach DB-Ausfall nicht wieder her | 2 | |
| Reaper leitet Besitzerursache aus aktuellem Singletonstatus ab | 2 | |
| `--once`-Reaping ist implizit dauerhaft blockiert | 2 | |
| queued-cancelled gilt als tatsächlich ausgeführte Ansible-Aktivität | 3 | |
| Aktivitätsquery skaliert ungeprüft über unbegrenzte Missionshistorie | 3 | |
| Disk-Hilfe umgeht Label-SSoT oder verspricht pauschale Performance | 4 | |
| Ansible-Code nennt ESXi-Host | 6–9 | |
| Ansible-Auth pausiert ESXi | 7–8, Integration | |
| Authz wird zu Passwortfehler | 6–9 | |
| eigene Budgets werden aus Text geraten | 6–8 | |
| SFTP-Operations-/Gesamtbudget unvollständig | 6 | |
| Preflight heißt Verbindungsfehler | 8–9 | |
| Ansible-Konfigurationsfehler aus Playbook heißen ESXi | 7–8 | |
| nicht wiederherstellbarer DB-Callback heißt Netzwerkfehler | 2, 8 | |
| Vokabular/Text/Hilfe/Doku können driften | 5 | |
| Doku verspricht Alert-Detail und `logs/error.log` | 10 | |
| Joblog-Link verschweigt RBAC/Retention | 9–10 | |
| Altbestand/Fehlpause ohne Abhilfe | 10 | |
| `check.ps1` besitzt persönlichen revisionsgebundenen Chromium-Fallback und driftet von Playwright | 11 | |
| Visualplan kann Worker eines falschen Stacks pausieren oder Baselines auf anderem Runner überschreiben | 11, 17 | |
| Deploy-Blockeraggregation wäre nach Live-Auswahl veraltet oder fachlich unvollständig | 12 | |
| Help-Plan vertagt Abschnitts-Deep-Links trotz vorhandenem `help-backup` und baut URLs von Hand | 12 | |
| Polling kann Login-HTML als JSON behandeln, Session locken oder bei `401`/`403` endlos weiterlaufen | 13 | |
| sichtbare geschlossene Statuswerte sind roh, persistente Logs dürfen aber nicht übersetzt werden | 13 | |
| Hint-Anzahl beweist keine eindeutige Label-/`aria-describedby`-Zuordnung | 14 | |
| Seitennavigation könnte fälschlich als ARIA-Tabwidget umgesetzt werden | 15 | |
| Datumsfilter könnte DST mit `+86400` verfehlen oder ungültige Werte abfragen | 15 | |
| Korrelationssuche wäre unbeschränkt, ohne RBAC-/Retentionerklärung oder ungeprüft indexiert | 15 | |
| pauschales Named-Color-Verbot würde erforderliche Systemfarben in Forced Colors verhindern | 16 | |
| Computed Style allein beweist Kontrast auf transparentem Glas nicht; Fokusziel war unvollständig | 16 | |
| Snapshot-Update könnte im Gate laufen oder Umgebungsdrift als Sollzustand committen | 17 | |
