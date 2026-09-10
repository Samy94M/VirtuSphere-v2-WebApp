# Befundregister: VirtuSphere und PowerShell-Integrationskette

## Gemeinsame lokale QA, 10.09.2026, abgeschlossen im freigegebenen Umfang
`final-drift-01`: neun gezielte Gates bestanden, Exit 0: Sprachparität,
ENUM-/PHP-Version-/Bounds-Synchronität, Dateigrößen, Audit-Contract,
Dokumentationshygiene/-semantik und CSP. Nur zwei nicht blockierende
CSP-Zeilenbudgethinweise für die DE/EN-Systemstatuskataloge (je 408 Zeilen).
Der letzte Quellabgleich bereinigt ausschließlich Whitespace in backup.sh;
`git diff --check` ist sauber. Admin-Workflow-Plan und beide U13-Vorherdateien
stimmen unverändert mit ihren bereits dokumentierten SHA-256-Werten überein.

`final-cleanup-01` stoppt alle zwölf laufenden QA-Dienste; der dreizehnte war
bereits gestoppt. Seine anschließende Zuordnungsprüfung der Browserdateien
brach wegen lokaler statt UTC-Zeitinterpretation sicher vor dem Löschen ab.
`final-cleanup-02` korrigiert ausschließlich diese Zeitinterpretation,
entfernt die vier nachgewiesen laufzugehörigen Browser-Anmeldedateien und
bestätigt den Endzustand, 2/2 pass, Exit 0. QA ist wie zu Beginn gestoppt,
Volumes bleiben erhalten, eigene Probecontainer sind entfernt. Alle fünf
Entwicklungsdienste haben unveränderte Containeridentitäten/Startzeiten und
sind gesund. Rohtraces mit Auth-Daten wurden durch redigierte Fehlerbelege
ersetzt; frühere rote Laufresultate bleiben erhalten. Die lokale QA ist damit
im zuletzt freigegebenen Umfang abgeschlossen.

Abschließender Restore-Nachweis: `restore-prepare-01` besteht 2/2 Schritte
einschließlich eines frischen Backups ausschließlich der synthetischen QA-DB
und QA-Konfiguration. `restore-drill-01` besteht das ausgewählte kanonische
Release-Gate, Exit 0; alle neun internen Schritte einschließlich Cleanup
bestanden. Wiederhergestellt wurden 35 Tabellen; Migrationen bis 0053 und
Schema-Konvergenz stimmen. APP_KEY-Roundtrip und Ablehnung eines falschen
Schlüssels, DB-Health, eingefrorene Machine-API-Ablehnung sowie Portal-Login
sind belegt. Keine vorhandenen echten Backups verwendet. Die synthetische DB
enthielt keine bestehenden Credentials und keine Missionen/VMs; die
Kryptoprüfung belegt deshalb den Roundtrip. Windows-Archivmodi konnten keine
restriktiven POSIX-Dateirechte beweisen; der Runner dokumentiert dies.
Dies ist ein gezielter Restore-Nachweis, keine vollständige Release-Lane.

Der erste vollständige Integrationslauf bleibt als 37 pass / 2 fail erhalten.
Die bekannten Fehler wurden anschließend gezielt korrigiert und nachgeprüft;
es wird kein weiterer vollständiger Integrationslauf behauptet. Performance-
Messungen und visuelle Bildvergleiche bleiben gemäß eingegrenztem Auftrag
offen, ebenso echte Standort-/MECM-/AD-/ESXi-Abnahmen. Commit und Push nach
main sind ausdrücklich freigegeben; eine Entwicklungs-Neuerstellung oder
Standortbereitstellung ist davon nicht umfasst. Der PHP-Capability-Fix wurde
am QA-Service geprüft und wirkt im Entwicklungsstack erst nach dessen
nächster regulärer Neuerstellung.

`php-stop-v2-01` / `ops-php-stop-v2-final01`: 4/4 Schritte bestanden, Exit 0.
Tatsächlicher korrigierter QA-PHP-Service: Idle-SIGQUIT-Stopp 523,978 ms,
Stopp bei belegter ESTABLISHED-Verbindung zur pausierten QA-DB 486,673 ms,
beide Exit 0 und OOM false. Ein eigener netzloser, nur auf den QA-PID-Namespace
begrenzter Beobachter mit SYS_PTRACE liest ausschließlich Socketmetadaten;
die Produktcontainer erhielten keine Diagnose-Capability. Derselbe QA-Stack
anschließend wiederhergestellt, DB-Health grün, Observer entfernt, Cleanup leer.

`capability-guards-02`: alle vier ausgewählten Fälle proven, Exit 0:
grüne Kontrolle, unerlaubte Zusatzcapability, fehlendes PHP-KILL und Zero-Match.
Der vorherige Lauf hatte drei proven und eine Infrastrukturlücke wegen fehlender
synthetischer .env in der Prüfkopie; diese wurde aus Docker/qa/qa.env ergänzt.
Runner-Adapter/Filter und Quellhashes sind explizit manifestiert. Der echte
Runner bleibt unverändert; keine erneute gesamte Integration behauptet.

Weitere ausdrückliche Auftragsergänzung: Migrationen auch im lokalen Docker
ausführen. Die lesende Prüfung des exakt identifizierten Entwicklungs-PHP-
Containers fand 0052 und 0053 offen. Beide geprüften Migrationen ergänzen nur
Spalten. `local-migrations-01` wendet sie über den Produktmigrator an und
bestätigt anschließend pending=0, 2/2 Schritte bestanden, Exit 0. Diese
autorisierte Entwicklungs-DB-Änderung ist die eng begrenzte Ausnahme zur
vorherigen Vorgabe, Entwicklungscontainer/-daten unverändert zu lassen.
Keine Entwicklungsdienste neu erstellt oder angehalten. Detailbelege:
`dev-migrate-check.json`, `dev-migrate-apply.log`, `dev-migrate-after.log` und
`dev-migrate-result.json` im gemeinsamen Artefaktroot.

`php-fix-qa-02`: 7/7 gezielte tatsächliche Gates bestanden, Exit 0:
Compose-Konfiguration/-Härtung, JS-/PowerShell-Syntax, QA-Neuaufbau,
Migrationen pending=0 sowie vollständiger Health-/Exposure-Vertrag.
QA-PHP verwendet jetzt die korrigierte Capability-Konfiguration.
Der vorherige Aufruf `php-fix-qa-01` wurde vor Ausführung wegen zweier
unbekannter Gate-Kurzbezeichnungen abgewiesen; er ist kein Testfehler.

Supervisorabnahme lokal abgeschlossen: `ops-supervisor-04` beweist sieben
Persistenz-/Prozessfälle; sein letzter Fall scheiterte nur am instabilen
Textpräfix erneut gelesener Docker-Logs. `supervisor-db-01` wiederholt allein
diesen achten Fall mit fester Logzeitgrenze nach DB-Stopp und besteht, Exit 0.
Echte mysqli-Unterbrechung, neue Fehlermeldung und Heartbeat strikt nach erneut
bereiter DB sind belegt; Supervisor-/Kind-PID und Startticks bleiben gleich,
Startzahl bleibt eins. Eigene Ressourcen bereinigt, Quellen unverändert.

PHP-137-Ursache für den gemessenen Idle-Fall bestätigt: `php-cap-01`, 2/2 pass,
Exit 0, identisches Image und identischer FPM-Konfigurationshash. Ohne KILL
verweigert der Root-Prozess das Signal an UID-33-Kinder mit EPERM und beendet
nach 10,3376 s mit 137; mit ausschließlich zusätzlichem KILL erfolgt Exit 0
nach 0,3412 s. Alle vier UID-Felder und Capability-Sätze sind erfasst; OOM false,
Cleanup fehlerfrei. Linux-Signalberechtigungen:
[kill(2)](https://man7.org/linux/man-pages/man2/kill.2.html).
Enger, unabhängig geprüfter Produktfix: nur PHP erhält KILL; der Guard pinnt
das vollständige Set KILL/SETGID/SETUID und eine neue Gegenprobe entfernt KILL.
Keine Worker-Capability oder Stopfrist geändert. Nachprüfung am QA-Service folgt;
keine Garantie für SIGQUIT bei blockierten Requests und keine rückwirkende
Ursachenbehauptung zum früheren unbeobachteten 137-Ereignis.

`supervisor-cleanup-03`: 24/24 Bereinigungsschritte erfolgreich; acht nie
gestartete eigene Container und 15 Volumes entfernt, bereits im ursprünglichen
finally entferntes Netzwerk erneut als abwesend bestätigt. Die zwei früheren
Cleanup-Anläufe brachen vor Mutation an Harnesskonvertierung beziehungsweise
der falschen Erwartung eines noch vorhandenen Netzwerks ab.
`ops-supervisor-03` beendet, Exit 1, Cleanup ohne Fehler: Alle acht Fälle
scheiterten an PowerShell-Harnessfehlern (reservierter Parametername PID oder
Überschreiben des typisierten State-Parameters mit einem Containerobjekt).
Beide Ursachen sind in den Enddetails belegt und ausschließlich im lokalen
Probe-Artefakt korrigiert; `ops-supervisor-04` prüft sie nach.

`browser-target-04`: 13/13 funktionale Browserfälle bestanden, keine Skips,
Exit 0 der gezielten Auswahl. Das kombinierte Gate ist ausdrücklich
not_applicable, weil der Visualteil nutzerseitig ausgenommen bleibt.
Die roten Zwischenläufe `browser-target-02/-03` bleiben erhalten: Die
PROCESSLIST-Diagnose beweist eine frühere getMissions-VM-Zählung unter der
VM-Tabellensperre, nicht die später erwartete getVMs-Abfrage. Fixture sperrt
jetzt deploy_interfaces und erkennt ausschließlich die vollständige Query
für ihre angelegte VM. Nach dem erfolgreichen CSRF-Ping wird die weiterhin
wartende Relation nochmals bestätigt. Astra prüfte diesen gezielten Fix;
Sessionidentität, Locale, Antworten und bestätigtes Helperende bleiben erhalten.

`ops-php-stop-01`: eigener PHP/FPM-Befund reproduziert, noch nicht behoben:
Idle-Stopp mit SIGQUIT und 10 s Grace endet nach 10469 ms mit Exit 137,
OOMKilled false. QA-Dienst und DB-Health anschließend erfolgreich wiederhergestellt.
Master UID 0, Kinder UID 33; effektive Master-Capabilities sind nur SETUID/SETGID.
Ein kontrollierter Vergleich mit/ohne CAP_KILL wird vorbereitet. Daraus folgt
noch keine Aussage zur unbekannten Ursache des historischen PHP-137-Ereignisses.

Supervisor-Vorläufe `ops-supervisor-01/-02` sind fehlgeschlagene Harnessaufbauten:
zuerst falsche APP_ENV-Annahme (QA verwendet local), danach strenger Mountvergleich
gegen Windows-Pfade trotz Docker-Desktop-Hostalias. Kein Fall erreichte seine
Produktassertionen. Eigene nie gestartete Ressourcen werden identitätsgeprüft
bereinigt; exakte Aliasnormalisierung betrifft nur die lokalen Probe-Artefakte.

`ops-stop-01`: sechs echte Containerproben bestanden, Exit 0. Deploy- und
Maintenance-Worker beenden beim Startup mit unerreichbarer DB auf TERM, INT
und QUIT jeweils mit Exit 0 in 423 bis 478 ms, OOM false. Gemeinsamer
Stophandler im Log bestätigt; alle sechs eigenen Probecontainer entfernt.
Dies beweist den gemessenen Startup-/Connection-refused-Fall, keine beliebige
blockierende Systemoperation oder externe Workerwirkung.

`final-target-01`: PHPStan und 28 PHP-Tests/74 Assertions bestanden, null
Fehler/Skips; QA-Aufbau bestanden. Browserauswahl: zwölf bestanden/einer rot.
Der Status-Test samt neuem Satz und Link ist grün. Der Sessiontest erreicht
nach Locatorfix erstmals die DB-Wartebedingung und scheitert dort; Ursache wird
eng untersucht. Kein vollständiges E2E-Grün behauptet. Kopie und Auswahl sind
manifestiert, Originalrunner unverändert; trace/screenshot in der Kopie aus.
Aus den ursprünglichen Integration-Browserbelegen wurden CSRF-Werte redigiert
und rohe Auth-Traces samt zugeordnetem HTML-Report entfernt. End-JSON/Resultate,
redigierte Logs und Fehler-PNGs bleiben erhalten; Redaktionsmanifest liegt bei.

Aktuelle Auftragsgrenze: Der Benutzer beschränkt den Abschluss ausdrücklich auf
den bereits laufenden Guard-Harness, drei eingegrenzte Testkorrekturen mit
Nachprüfung, Supervisor-/DB-/Signalproben, PHP-137-Untersuchung und synthetischen
Restore, anschließend Commit und Push nach main. Weitere Performance- und
visuelle Bildprüfungen werden nicht ausgeführt; die vorbereiteten Harnesses
bleiben erhalten, ihre Messnachweise offen. Keine vollständige Releasefreigabe.

`integration-01` vollständig beendet (15:06:50 bis 16:09:56 UTC), Exit 1:
37 Gates bestanden, zwei fehlgeschlagen, keine Infrastrukturfehler/Skips.
Guard-Harness: 106 proven, null unproven/infra, Exit 0. Unit/Static:
1721 Tests/40221 Assertions; vollständige PHPUnit-Suite gegen QA-DB:
2187 Tests/44828 Assertions, jeweils null Fehler/Skips. PowerShell:
668 reguläre Tests bestanden, separat 35 historische bestanden/fünf explizite
Hand-/Altfall-Skips. Migration, Schema-Konvergenz und Health/Exposure bestanden.
Funktionales Chromium: 265 bestanden/zwei fehlgeschlagen; visuelle Bildvergleiche
wurden wegen dieses Gatefehlers nicht gestartet, Visual-Vertragsprüfung grün.
PHPStan beanstandet ausschließlich zwei uninitialisierte lokale Variablen in
der neuen Signalfixture. Die Browserfehler betreffen den durch zwei Logoutformen
mehrdeutigen CSRF-Locator und die alte No-Sources-Formulierung im Status-Test.
Nach Laufende wurden die drei unabhängig geprüften Testkorrekturen angewandt:
Sentinelinitialisierung, Banner-Scope und aktueller DE/EN-Satz samt Ziellink.
Die gezielte Nachprüfung steht noch aus. Vollständige rote Endbelege bleiben
unter `qa-artifacts/implementation-u13-u14/20260910-joint/integration-01/` erhalten.

Die folgenden Einträge protokollieren frühere Zwischenstände dieses QA-Tages.

`restore-ps7-01`: alle 29 ausgewählten Tests bestanden, null Fehler/Skips,
Analyzer und ShellCheck (29 Skripte) grün. Beide Engines bestätigen nun die
Restore-/Backup-Fixtures; Teilgate-Exit 1 ausschließlich wegen unveränderter
globaler Coverage-Schwelle. Gemeinsame vollständige 39-Gate-Integration wird
jetzt auf dem korrigierten tatsächlichen Arbeitsbaum gestartet. Währenddessen
bleiben Produkt-, Test- und Registerquellen eingefroren.

Auftragsänderung durch den Benutzer: Das bisherige Commit-/Push-Verbot wurde
im laufenden Gespräch ausdrücklich aufgehoben. Nach abgeschlossener QA und
Diff-/Integrationsprüfung ist Commit und Push des geprüften Stands nach main
autorisiert; noch kein Git-Schreib-/Pushvorgang durchgeführt. Keine Deployment-
oder reale Standortfreigabe daraus abgeleitet.
`restore-ps51-05`: alle 29 ausgewählten RestoreRoot-/ProgressReporting-Tests
bestanden, null Fehler, null Skips; PSScriptAnalyzer grün. Gate-Exit 1 kommt
allein von der unangetasteten globalen Coverage-Schwelle des bewusst engen
Teilruns (0 Prozent statt Floor 55). Die neun neuen Safetyfälle erreichen
jetzt ihre eigentlichen Prüfziele einschließlich Erfolgs-Cleanup, Kollision,
Labelwechsel, fehlgeschlagenem Start/Remove und beiden exakten TERM-Exits 130.
Dies ersetzt keine vollständige Matrix; PS7-Auswahl und gemeinsame Integration
folgen. Exakte Quellkopie/Manifest, Logs und Endnachweise sind gesichert.

Unabhängige Astra-Vorprüfung der zusätzlichen Betriebs-/Messharnesses
abgeschlossen: Supervisor-Network-Lifecycle, private Runtime-Masken,
ID-basiertes Cleanup, frische Reconnect-Zeitgrenze, begrenzte Lockprobe und
reale Stop-Exits nachgebessert; PHP-Stopprobe sichert HTTP-Ziel/Socketmetadaten
und unabhängige Wiederherstellung; U13 sichert DB-Ziel vor Mutation,
Warm-up-Hälften, bedingten zweiten Warm-up, akzeptierte Samples/Identitäten,
beide k6-Summaryformen und fehlersichtbares Runtime-Cleanup. Freigabe betrifft
nur die kontrollierte Ausführung. Noch keine Betriebs- oder Performancewerte.
Weitere gezielte Restore-Fixtureläufe: `restore-ps51-03` 23/29 bestanden,
sechs rot beim Entpacken: native Windows-tar versteht den Shell-mktemp-Pfad
nicht. Nur innerhalb der Safety-Ausführung werden nun die POSIX-Werkzeuge
der expliziten Shell priorisiert; Archiverstellung behält ihr explizites
natives tar. `restore-ps51-04` 28/29 bestanden, ein Fakefehler verbleibt:
Create-Klassifizierung suchte im gesamten Argumentstring und hielt den Smoke
wegen DB_HOST für MySQL. Fake wertet jetzt ausschließlich --name aus.
Die echte Identitätsprüfung verweigerte den widersprüchlichen Cleanup korrekt.
Alle vier roten Teilruns, End-JSON, Exits und unveränderte Coverage-Grenze bleiben
im Artefaktbaum erhalten; keine produktive Schutzprüfung wurde abgeschwächt.
`restore-ps51-02` gezielt beendet, Exit 1: 23/29 Tests bestanden, sechs
Safetyfälle rot, keine Skips. Die neue Capture-Datei beseitigt Exit 127;
die Skripte erreichen jetzt die Manifestprüfung. Windows-CRLF in den beiden
synthetischen Manifest-Schreibern wurde als Dateinamensuffix interpretiert.
Fixture schreibt jetzt explizite ASCII-/LF-Zeilen wie das Shell-Produkt.
Keine Änderung oder Lockerung an sha256sum, Hashes oder Assertions.
Astra hat den neuen Shelltransport unabhängig freigegeben. Nachlauf läuft.
`restore-ps51-01` gezielt beendet, Exit 1: ShellCheck 29 Dateien bestanden;
Pester entdeckt 29 ausgewählte Tests, davon 20 bestanden und neun neue
Safetyfälle fehlgeschlagen, keine Skips. Alle neun liefern vor dem Prüfziel
Shell-Exit 127. Die verschachtelte Windows-Shellübergabe wird deshalb durch
eine private Capture-Datei mit explizitem Shell-/Skriptpfad ersetzt;
Docker-Auflösungsbeweise und Assertions bleiben erhalten. Zusätzlich bleibt
die globale Coverage-Schwelle des Teilruns unverändert rot (0/55 Prozent).
Das ist keine vollständige PowerShell-Abnahme.
Restore-/Backup-Sicherheitskorrektur nach unabhängiger Astra-Gegenprüfung
integriert: Der Backup-Producerexit wird vor Kompression geprüft; private
Rohdatei und Teilarchive werden auch bei Signalabbruch entfernt. Der Restore
merkt sich erzeugte IDs, prüft Namen und eigene QA-/Runlabels vor Bereinigung,
verweigert Kollisionen und meldet fehlgeschlagenes Cleanup als Fehler. Neun
synthetische Safetyfälle ergänzen die vorhandenen Rootfälle; erwartetes stderr
wird innerhalb der Shell erfasst, POSIX-Fixture-Env erhält 0600, TERM muss
Exit 130 liefern. Kanonische Fortschrittsabdeckung wurde erweitert. Noch kein
Laufzeitnachweis dieser Korrektur; gezielte PS5.1-/7-Nachprüfung folgt.

`php-targeted-01` bestanden, Exit 0: 28 Tests der drei betroffenen PHP-Klassen
ohne Skips sowie Dokumentationssemantik und PowerShell-Syntax grün. Der
kanonische Runner arbeitete in der vollständigen aktuellen Quellkopie mit
expliziter PHPUnit-Testauswahl und denselben lokalen Composer-Abhängigkeiten;
Quellmanifest, Abhängigkeitshashes, JUnit und Endnachweise liegen im Artefaktbaum.
Die zwei Signal-Errors und die Closure-Fehlzuordnung sind damit gezielt
nachgeprüft; eine vollständige gemeinsame Integration steht noch aus.

`qa-stack-01` bestanden, Exit 0, 49,8 s: vorher wurden ausschließlich die
gestoppten und nochmals per Projekt-/Volumelabel verifizierten QA-Ressourcen
zurückgesetzt (`qa-before-start.json`, `qa-volume-ownership.json`,
`qa-fresh-reset-exit.json`). Der kanonische Aufbau startet die frische
synthetische Datenbank und das Portal unter `http://127.0.0.1:8031`.
Die neuen Runtime-/Log-/Backupstatus-Grenzen werden im Stack verwendet;
Entwicklungscontainer und ihre Daten wurden nicht verändert. Die vollständige
39-Gate-Integration sowie Betriebs-/Restore-/Messabnahmen bleiben ausstehend.
Der tatsächliche Mount-/Health-Nachweis liegt in `qa-live-isolation.json`.
Die vier Kernservices sind healthy. Gemessene Rechte: Logs `33:0/0777`,
Runtime `0:0/0755`. `0770` ist nur der Initializerzustand; der bestehende
Produkthelper `virtusphere_assert_log_dir_writable()` setzt danach ausdrücklich
`0777`. Die Isolation wird durch eigene QA-Volumes bewiesen, nicht durch die
Behauptung dauerhaft restriktiver Logrechte.

`fast-01` vollständig beendet mit Exit 1: 28 Gates bestanden, drei
fehlgeschlagen, keine Infrastrukturfehler oder Skips. PHP-Lint 733 Dateien,
PHPStan, Composer/Audit, Sprache/SSoT/Budgets, PowerShell 658 plus 35/5
historisch, YAML/Ansible einschließlich neun Async-Fällen und Python bestanden.
Unit/Static führte 1720 Tests mit 40187 Assertions aus: zwei Errors aus
erwartetem Signal-stderr im PHPUnit-Isolationsprotokoll und eine fehlerhafte
Guard-Zuordnung von `parent::__construct` zur fremden callable-Signatur.
Die vollständige JUnit-Datei beweist auch die Entdeckung der neuen Tests.
Dokumentationssemantik findet zwei `ConfigMgr`-Kommentare; ShellCheck findet
dreimal die mehrdeutige Schreibweise `CDPATH= cd` im Restore-Rootpfad.

Eng begrenzte Nachbesserung nach Laufende: Signalproben bekommen einen eigenen
Subprozess, privaten Heartbeat und begrenzte Bereitschaftssynchronisation;
echtes SIGQUIT, Stopflag, Senderende, vollständiges erwartetes stderr und
Stopdauer unter 1000 ms je Retry-Wait werden ausdrücklich geprüft. Der
Closure-Guard nutzt die wirkliche Signatur eines bekannten internen Parents;
Benutzer-Parents und interne callable-Methoden behalten negative Regressionen.
Astra gab diese Korrekturen nach den genannten Nachbesserungen frei. Die
beiden Kommentare verwenden jetzt MECM; Shellkorrektur wird mit dem separaten
Restorepatch integriert. Noch kein erfolgreicher Nachlauf dieser Änderungen.

PowerShell-Matrix auf dem gemeinsamen korrigierten Stand vollständig bestanden:
`ps7-01` ergänzt `ps51-04` mit Exit 0 unter PowerShell 7.6.5, ebenfalls
658 regulären Tests ohne Fehler/Skips und separat 35 grünen historischen
Tests bei fünf offenen Handproben. PS7-Coverage 76,94 Prozent, Gate 194,3 s,
Ende `2026-09-10T14:06:28.3609477Z`. Beide Engines verwenden Pester 5.7.1
und PSScriptAnalyzer 1.25.0; ihre Quellhashdeltas und Endnachweise sind
je Lauf gesichert. Die geplanten Restore-Sicherheitsänderungen sind noch
nicht Bestandteil dieser grünen Matrix und benötigen eigene Nachprüfung.

Vollständige PS5.1-Abnahme `ps51-04` bestanden: Exit 0, Analyzer grün,
658 reguläre Tests bestanden, keine Fehler oder Skips, 40 bewusst
ausgeschlossene historische Fälle; Coverage 76,91 Prozent. Separates
Härtungsregister 35 bestanden und fünf offene Handproben. Laufzeit 287,8 s,
Ende `2026-09-10T14:02:35.7900343Z`. Quellhashdelta, äußeres Live-Log,
Gate-JSON, Detailausgaben und Coverage sind im Laufverzeichnis gesichert.
Dieser Nachlauf ersetzt die vorherigen roten Läufe nicht. E1-Bodytypen und
Loopbackbytes, Membership/Journal, U09-Content, U10-Repair und RestoreRoot
wurden tatsächlich ausgeführt; reale Standortwirkung bleibt separat.

Vor dem echten Restore identifizierte Astra zwei weitere konkrete Befunde:
namensbasiertes Cleanup darf keine nie selbst erzeugten Container löschen;
`mysqldump | gzip` muss den echten Dump-Exit berücksichtigen. Die Korrektur
wird außerhalb des laufenden Quellstands vorbereitet und vor der Betriebsprobe
nachgeprüft. Noch kein echter Backup-/Restorelauf dieser Runde erfolgt.

Nachlauf `ps51-03` endete mit Exit 1: 647 reguläre Tests bestanden,
11 fehlgeschlagen, keine Skips, Coverage 76,91 Prozent. Historisches Register:
35 bestanden, fünf Handproben offen. Die vollständigen Logs und Endnachweise
liegen unter `qa-artifacts/implementation-u13-u14/20260910-joint/ps51-03/`.
Zehn Fehler betreffen PackageRepair; der letzte RestoreRoot-Fall trifft die
erwartete Produktionsablehnung, aber PS5.1 behandelt natives stderr als
terminierenden Fehler. Die Fixture leitet dieses stderr jetzt bereits in der
Shell nach stdout um und prüft weiterhin Exit 2 sowie die genaue Diagnose.

`ps51-targeted-01` verwendet den unveränderten kanonischen Runner in einer
vollständigen aktuellen Quellkopie mit ausschließlich PackageRepair und
RestoreRoot als Testauswahl. Ergebnis: neun bestanden, zehn PackageRepair-
Fehler; alle sechs RestoreRoot-Fälle bestanden. Der Gate-Exit bleibt 1,
einschließlich des unveränderten Coveragefloors, den diese bewusste Auswahl
nicht erreichen kann. Kein vollständiger Suiteerfolg wird daraus abgeleitet.
Die zusätzlich gesicherte synthetische Wrapperdiagnose zeigt, dass der
Child-Mock noch den alten Detection-Marker sieht; dessen Löschsimulation
wird gezielt untersucht. Original- und Nachlaufartefakte bleiben erhalten.

`ps51-targeted-02`: alle 19 ausgewählten Tests bestanden, keine Skips.
Die Ursache lag im Registry-Mock: `Remove-ItemProperty -Name` bindet ein
String-Array; `Hashtable.Remove()` bekam vorher dieses Array statt des
einzelnen Schlüssels. Die Fixture prüft jetzt exakt einen Namen `Version`
und entfernt genau diesen String-Key. Produktinstaller und Assertions an der
Child-Grenze bleiben erhalten. Der weiterhin aktive globale Coveragefloor
macht die bewusste Teilselektion nicht zu einem grünen Gesamtgate.

Ausdrücklich autorisierte lokale Abnahme einschließlich eng begrenzter
Fehlerkorrekturen; keine Commits, Pushes, Deployments, Baselineupdates oder
Eingriffe in reale Standort- oder Entwicklungsdaten. Root ist alleiniger
QA-Owner und alleiniger Schreibowner dieses Registers.

Eingang: HEAD `aa3daacb82ab947c1d9c4c275b118f2afe73c73e`, Branch
`codex/audit-round2-u05-u06-u15-u16`, einschließlich aller bestehenden
uncommitteten Änderungen. Sicherung unter
`qa-artifacts/implementation-u13-u14/20260910-joint/entry/`: Gitstatus,
Arbeitsbaum-/Indexdiff, vollständige Kopie von 3352 versionierten und
ungetrackten Quelldateien mit SHA-256-Manifest sowie zusätzliche Kopie der
U13-Originale und ihrer bestehenden Evidenz. Manifesthash:
`e457ecbe52ba2ea50698de2747ea758ecacd2bdf99f64ab2663430226f6292f9`.
Ignorierte Secrets und Laufzeitdaten sind keine Snapshotquellen; insbesondere
Admin-Workflow-Plan, Registerdiff und vorbereitete Tests sind erhalten.

Bestandsblock bestanden: kanonische Registry meldet 31 Fast- und 39
Integration-Gates. Der vorhandene synthetische QA-Stack ist gestoppt; der
Entwicklungsstack läuft und bleibt unangetastet. Dockerzugriff benötigt den
autorisierten Prozess außerhalb der Sandbox. Historischer QA-PHP-Zustand:
Exit 137, `OOMKilled=false`, Ende `2026-09-09T20:44:08.804364323Z`;
dies ist noch keine Ursachenklärung.

Vor der ersten PowerShell-Abnahme identifizierte Astra High eine konkrete
Berichtslücke: das historische Härtungsregister unterdrückt Einzelfallergebnisse
und berücksichtigt in seiner positiven Zusammenfassung keine Discoveryfehler.
Eine eng begrenzte Runnerkorrektur mit Progressregression wird vorbereitet;
die historische Nichtblockierung bleibt bestehen. E9-Modusnormalisierung und
der redaktionelle VLAN-Kommentar werden gesondert eng korrigiert. Noch kein
Testlauf dieser Runde abgeschlossen.

Vor QA-Containerstart wird zusätzlich die Dateisystemisolation geprüft:
der gemeinsame WebAPI-Bind-Mount enthält auch persistente `var`-Daten.
Die isolierte Datenbank allein beweist keine Isolation dieses Dateizustands.
Fast/Integration, Betriebsabnahmen, Restore und Performance stehen noch aus;
historische Erfolge werden nicht auf diesen Arbeitsbaum übertragen.

PowerShell-Block `ps51-01` abgeschlossen, Runner-Exit 1, End-JSON und äußeres
Live-Log einschließlich Gate-Logs/Coverage unter demselben Laufverzeichnis
gesichert. Windows PowerShell `5.1.26100.9444`, Pester `5.7.1`, Analyzer
`1.25.0`: Analyzer bestanden; reguläre Suite 628 bestanden, 29 fehlgeschlagen,
0 Skips, 40 bewusst ausgeschlossene historische Fälle. Coverage 76,3 Prozent
bei Runnerfloor 55 Prozent. Die Fehler betreffen U09-Content-/DP-Evidenz,
MembershipApply/Journal und PackageRepair. RestoreRoot scheitert bereits im
Setup an fehlendem `sh` im Prozesspfad, kein ausgeführter Restoretest; dieser
Infrastrukturgrund wird vom roten Gate getrennt bewertet. Git-Bash ist
installiert und wird für den Nachlauf explizit im Prozesspfad bereitgestellt.

Das separat vollständig ausgeführte Härtungsregister besteht mit 35 Tests und
5 offenen Handproben. E1-JSON und die E7-/E9-Automatik sind einzeln grün;
die permanenten JSON-Transporttests liefen ebenfalls, einschließlich echter
Loopbackbytes. Keine PS7- oder reale Netz-/SYSTEM-Abnahme daraus ableiten.
Der neue Berichtspfad wird zusätzlich nachgebessert: normale rote Assertions
dürfen nicht über `Container.Result=Failed` als Discoveryfehler gelten;
die gepinnten Pester-Zähler für fehlerhafte Container/Setupblöcke entscheiden.
Die zuvor tatsächlich grüne historische Suite bleibt als solche erhalten.

Vor diesem Lauf wurden QA-Runtime-/App-Log-/Backupstatus-Volumes mit
`nocopy:true`, synthetische Env-Maskierungen für App-/Testcontainer und der
Wegfall des Dev-Composer-Fallbacks ergänzt. Composeauflösung bestätigt die
Mountziele, noch keine Container-Laufzeitabnahme. Ein ausschließlich temporärer
Compose-Initializer stellt Logrechte `33:0/0770` her; die Service-Capabilities
bleiben unverändert. Astra High bestätigte die nachgebesserten Grenzen sowie
E9s Nutzung des kanonischen Planmodus. Alle Korrekturen bleiben uncommitted.

Nachlauf `ps51-02`: vollständig beendet mit Exit 1, 639 Tests bestanden,
18 fehlgeschlagen, 0 Skips; historisches Register erneut 35 bestanden und
5 offene Handproben. U09- und Membership-Fälle bestehen jetzt. U09 korrigiert
einen PS5.1-Arrayrückgabefehler beim Lesen der DP-Baseline und meldet den
Contentrequest-Catch lokal; Membership materialisiert eine generische Liste
an der echten Apply-Grenze mit `ToArray()`. Die Membership-Fixtures geben
ebenfalls konkrete Arrays zurück und verwenden je Test eigene Journalpfade.
Keine Produktquarantäne wird gelöscht oder freigegeben.

Die verbliebenen 13 PackageRepair-Fehler sind ein Scopefehler der ersten
Fixturekorrektur (`script:TestPathCommand` im aufgerufenen Installer nicht
vorhanden); fünf RestoreRoot-Fehler entstehen durch das vorangestellte Git-Bash-
Verzeichnis, dessen GNU-tar `C:` als entfernten Archivhost interpretiert.
Der Nachlauf wird mit angehängtem Shellpfad und damit unverändert bevorzugtem
Windows-tar vorbereitet. Dies sind keine nachgewiesenen Produktreparatur- oder
Restoredefekte. Astra fand zusätzlich einen PS7-Leerlistenrandfall im U09-Fix;
vor der Enginefreigabe werden `[]`, `[null]` und verschachtelte Leerlisten
explizit unterschieden und regressiv geprüft.

Zugehöriger [Auditplan](2026-09-08-system-chain-audit-plan.md). Dieses Register ist die einzige fortgeschriebene Quelle für Auditfortschritt, Befunde, Entscheidungen und Nachweisverweise dieser Runde. Ausführbare Produktregeln und Gate-Definitionen bleiben an ihren bestehenden Stellen.

## Umsetzungsrunde U13 und U14, 10.09.2026

Ausgangsstand: HEAD `aa3daacb82ab947c1d9c4c275b118f2afe73c73e`, Branch
`codex/audit-round2-u05-u06-u15-u16`, mit den vorhandenen uncommitteten
Änderungen aus U07–U10, E1/E7/E9, U12/U17 und ihren neuen Testdateien.
Der Registerdiff und der ungetrackte Admin-Workflow-Plan bleiben erhalten.
Historische Erfolge sind keine Abnahme dieses Arbeitsbaums.

Diese Runde führt ausschließlich Datei-/Git-Leseoperationen, Änderungen und
manuelle Quellenprüfung aus. Keine Lint-, PHPStan-, Test-, Build-, Guard-, QA-,
Browser- oder Performanceläufe, keine Container-/Restore-/externen Aktionen,
kein Commit, Push, Merge oder Deployment. Auch vorbereitete Tests werden
nicht ausgeführt. Alle internen Agenten erhalten dieselben Grenzen.

Aufteilung: Sol High bearbeitet Session/Relationsabfragen, Sol High die
Lastprofile und Messvorbereitung, Sol High die AP11-Texte und Inventaranzeige.
Gemeinsame Dateien haben einen Schreibowner; nur Root pflegt dieses Register.
Die unabhängige Astra-High-Gegenprüfung erfolgt am zusammengeführten Stand;
Prüfumfang und Korrekturen stehen im [Reviewbericht](2026-09-10-u13-u14-astra-review.md).
Originaldateien vor den Änderungen dieser Runde werden unter
`qa-artifacts/system-chain-audit/20260910-u13-u14/before/` aufbewahrt; der
Eingangsdiff und Gitstatus liegen daneben. Die Kopien enthalten nur die
betroffenen Quellen und ersetzen keinen vollständigen späteren Prüfsnapshot.

| Paket | Stand | Nächster Schritt |
|---|---|---|
| U13 / SC-008, SC-021, SC-025 | implementiert, anhand des Codes geprüft, Laufzeitabnahme offen | Relations-/Sessionregressionen und korrigierte S/T/L-/Session-A/B-Profile sind vorbereitet; später exklusiv nach dem konsolidierten QA-Plan abnehmen |
| U14 / SC-020, SC-023 | implementiert, anhand des Codes geprüft, Laufzeitabnahme offen | 29 AP11-Claims und thematischer Restkorpus sind eingeordnet; Evidenzmatrix, Sprach-/Linkverträge und reale Client-/Recoverywirkung später abnehmen |

U13: Der bestehende `getVMs()`-Owner liest drei Relationen
in vorbereiteten ID-Chunks von höchstens 500 Werten, mit unverändertem
Missionsfilter und Ergebnisformat. Aus dem Code folgt eine Abfragezahl von
`1 + 3 * ceil(N / 500)` bei nicht leerer Mission; diese Zahl wurde nicht neu
gemessen. Die Sessionfreigabe liegt nach Locale, `current_user()` einschließlich
Expiry/AD-Writes und expliziter Rechteentscheidung. Blocker-/Warning-JSON nutzt
danach den lokalen Benutzer. Details und Originaldateihashes stehen im
[U13-Codebericht](2026-09-10-u13-code-review.md). Root hat unter anderem die
PHPUnit-Assertionssignatur und die Isolation der neuen Sessionfixture zur
Korrektur zurückgegeben; die Suite wird weiterhin nicht ausgeführt.

Die VM-Lastmessung verlangt einen gültigen Missionskontext und nimmt nur die
exakte authentifizierte VM-Seite ohne Redirect mit passendem Titel, Zeilenzahl
und VM-Marker als Sample an. Positive Samplezähler verhindern einen Leerfolg.
Dauerhafte S/T/L-Fixtures, getrennte Warm-/Erstrequestprofile und Session-A/B
mit boolesch nachgewiesener gleicher/getrennter PHP-Session sind vorbereitet.
Jedes mutierende Fixture-Statement ist gegen ungültige Profile gesperrt.
Der [Messplan](2026-09-10-u13-measurement-plan.md) verlangt denselben vollständigen
Arbeitsbaumsnapshot einschließlich fremder Änderungen und denselben korrigierten
Harness; ausschließlich zwei gesicherte Produktoriginale unterscheiden Vorher
von Nachher. Historische AP09-Zahlen sind kein neuer Vorherwert.

Astra fand und prüfte die Korrekturen für begrenzten DB-Lockerwerb und
Prozessende im Sessiontest, einen expliziten DE-zu-EN-Localegegenfall sowie die
vollständige SQL-Profilvalidierung nach. Die Pingassertion belegt als späteres
Testziel Sessionzugriff und CSRF-Annahme, nicht zusätzlich einen verglichenen
persistierten Ablaufwert. Produktcode und ausgewählte unveränderte Schutzpfade
wurden von Root und Astra anhand der Quellen gegengeprüft; keine Ausführung.

U14 / SC-020: **Textkorrektur am beabsichtigten Vertrag.** DE/EN-Hilfe und
Betriebsanleitungen unterscheiden best-effort-Phasentelemetrie von verbindlichem
Client-Ready-ACK, serverseitiges 5/5 von empfangener ACK-Antwort, bedingten
Hostname-Reboot von Skip und Windows-IP-Konfiguration von ESXi-Portgruppen.
Recovery verspricht kein blindes Nachholen ungeklärter externer Wirkung.
U07–U10, U12/U17 und E1 werden als implementierter Code mit offener
Laufzeitabnahme berücksichtigt. Weiter korrigiert wurden Reportvollständigkeit,
`unless-stopped`, Backupmanifest, getrennte Retention je Artefaktart und der
Umfang des Dumps auf die Anwendungsdatenbank. Keine neue Outbox, Backupfunktion
oder automatische Reparatur wurde zur Erfüllung alter Texte eingeführt.

U14 / SC-023: **Darstellungs- und Projektionskorrektur.** Der bestehende
Bericht liefert die Quellenanzahl zusätzlich. Die Anzeige unterscheidet keine
Quelle, fehlende qualifizierte Artauswertung, teilweise Evidenz, vollständige
historische und vollständige aktuelle Evidenz. Nur letztere trägt die
unqualifizierte Aussage „keine Abweichungen“. Vorhandene Linkhelper führen zum
passenden Bereich; keine konkrete Quelle wird aus einem unbekannten Aggregat
erraten. Exakte Namen, All-Source-Negativevidenz, Identität, Freigabe,
VLAN-Reparatur, Ownership, RBAC, CSRF und Machine-Wire-Verträge bleiben erhalten.
Eine fokussierte Unitmatrix und der angepasste vorhandene Branchtest sind
vorbereitet. Neue Übersetzungen und Tests wurden nicht ausgeführt.

Der [U14-Korpusbericht](2026-09-10-u14-claim-review.md) ordnet alle 29 AP11-Claims
ein und dokumentiert neun aktive DE/EN-Hilfepaare sowie 15 aktive Installations-/
Betriebsdokumente dateiweise im thematischen Prüfumfang. Der separate Rootbeitrag
deckt TESTPLAN/Security sowie fokussierte QA-/Gatepassagen ab. Das ist keine
Vollabnahme sämtlicher Sätze, Portaltexte, historischer ADRs oder Standortbefehle.
Ein verbliebener „VLAN-Wechsel“-Kommentar im bereits anderweitig geänderten
Client-Common ist als P3 redaktioneller Rest dokumentiert; kein neuer
Funktionsfehler wird daraus abgeleitet.

Root und Astra prüften Änderungen und ausgewählte unveränderte Session-, ACK-,
Create- und Netzwerk-Schutzpfade. Gefundene Test-/Fixturelücken und zu weitgehende
Hostname-/Backupformulierungen wurden nachgebessert und erneut gelesen; Details
stehen im [Root-Gegenbericht](2026-09-10-u13-u14-counterreview.md) und Astra-Bericht.
Der [konsolidierte QA-Plan](2026-09-10-u13-u14-qa-plan.md) umfasst sämtliche noch
ungeprüften Umsetzungsrunden: später ein exklusiver Owner des synthetischen
`virtusphere-qa`-Stacks, kanonischer Runner und live lesbare echte `[n/total]`-
Fortschritte; Performance erst getrennt von allen übrigen Prüfungen.

Gesondert offen bleiben der E9-Registry-Randfall und der ungeklärte PHP-Container-
Exit 137. Reale MECM-/DP-/Share-/Windows-SYSTEM-/Reboot-, ESXi-/Ansible-/Identitäts-,
AD-/HTTPS-/Air-Gap- und Restoreabnahmen werden durch diesen Quellenreview nicht
ersetzt. E1/E7/E9 sind implementiert und anhand des Codes geprüft; ihre Engine-/
Laufzeitabnahme ist offen. Nächster Schritt ist eine separat zur Ausführung
freigegebene QA-Session, beginnend mit gesichertem Gesamtstand, exklusivem Owner,
live lesbarem Log und der kanonischen PowerShell-5.1-/7-Matrix. Diese Runde
endet ohne Prüfstart und mit allen Änderungen uncommitted im Arbeitsbaum.

Abschlusskontrolle: HEAD und Branch entsprechen dem Eingang. Der abschließende
lesende Gitstatus liegt in `qa-artifacts/system-chain-audit/20260910-u13-u14/final-status.txt`;
vorhandene Änderungen und der ungetrackte Admin-Workflow-Plan sind bewahrt.
Alle internen Implementierungs- und Reviewagenten sind abgeschlossen. Astra
meldet keinen offenen P1/P2 im geprüften Schlussstand; die benannten Restlücken
und sämtliche Laufzeitabnahmen bleiben offen. Kein Commit, Push, Merge oder
Deployment und auch zum Abschluss kein QA-Lauf.

## Umsetzungsrunde E1/E7/E9, U12 und U17, 10.09.2026

Bestandsaufnahme: HEAD `aa3daac`; die vorhandenen Änderungen aus U07–U10,
deren neue MembershipApply-, PackageRepair- und ServerTemplate-Testdateien,
der Registerdiff und der ungetrackte Admin-Workflow-Plan bleiben erhalten.
Historische lokale Abnahmen gelten nur für die damaligen Quellstände.
Diese Runde erlaubt ausschließlich Datei-/Git-Leseoperationen, Codeänderungen,
Quellenrecherche und manuelle Gegenprüfung. Keine Lint-, PHPStan-, Test-, Build-,
Guard- oder QA-Läufe, keine Container, Restore-Proben oder externen Mutationen;
kein Commit, Push, Merge oder Deployment. Testcode wird vorbereitet, nicht
ausgeführt. U13 und das umfassende U14 bleiben eigene Folgepakete.

Die Bearbeitung beginnt mit E1/E7/E9; danach folgen U12 und U17 unabhängig
parallel. Interne Sol-High-Agenten implementieren mit abgegrenzten Dateiownern,
Astra High prüft unabhängig Originalcode, Änderungen und ausgewählte unveränderte
Schutzpfade. Ausschließlich der Hauptagent schreibt dieses Register.

| Paket | Aktueller Stand | Evidenzgrenze |
|---|---|---|
| E1 | implementiert, anhand des Codes geprüft, Laufzeitabnahme offen | Beide `ConvertTo-VsUtf8JsonBytes`-Owner erhalten `byte[]` als ein einzelnes Rückgabeobjekt. `-InputObject`, UTF-8, JSON-Envelope und HTTP-Parameter bleiben unverändert. Sol-Implementierung durch Root und Astra unabhängig gegengeprüft. Befund bleibt K, nicht neu reproduziert. |
| E7 | implementiert, anhand des Codes geprüft, Laufzeitabnahme offen | Echte native/Start-Process-/Task-/MECM-Commandline-Stellen; lesender CIM-Filter ausgeschlossen. Wirksame Argumente vor `-File`/`-Command`, echte SSoT-Variablen und die vorhandene Taskformatierung werden geprüft. Positive, vier negative und Nullfundkontrollen; abschließende Astra-/Root-Quellprüfung ohne weiteren konkreten Blocker. Keine Produktänderung. |
| E9 | implementiert, anhand des Codes geprüft, Laufzeitabnahme offen | Test des tatsächlichen `New-VsClientNetworkPlan`-Owners mit gültigem DHCP-Gegenfall und unbekanntem Modus; Invalid-Negation, unbedingtes `exit 1` und Reihenfolge vor Netzmutation, inklusive positiver/negativer/Nullfundkontrollen. Keine Produktänderung für die historische Textassertion. |
| U12 / SC-013, SC-014, SC-015 | implementiert, anhand des Codes geprüft, Laufzeitabnahme offen | Root und Astra haben Produkt- und Testcode sowie die DE/EN-Hilfe manuell gegengeprüft; Root hat den letzten redaktionellen Dokurest abgeschlossen. SC-013/014 K; SC-015 historische R-Evidenz. Keine neue Laufzeitabnahme. Der separat beobachtete PHP-Container-Exit 137 wird keiner dieser Ursachen zugerechnet. |
| U17 / SC-026 | implementiert, anhand des Codes geprüft, Laufzeitabnahme offen | Root und Astra haben Produkt, synthetische Runnerfixture und Dokumentation manuell geprüft. Historischer fehlgeleiteter Restore bleibt R-Evidenz des alten Stands, kein Nachweis der neuen Korrektur. Keine Archive real wiederhergestellt. |

E1: `tests/powershell/VirtuSphere.JsonTransport.Tests.ps1` ist als permanente
Suite ohne historischen Ausschlusstag vorbereitet: zwei Helper-Matrizen für
Objekt, Ein-/Mehr-Element-Array und aus ASCII-Codepoints gebildetes Unicode;
drei untypisierte HTTP-Bindungsfälle (Server, Client-ACK, Phasenmeldung) sowie
fünf echte Loopback-Bytefälle. Kein Stub castet den Body nachträglich zu
`byte[]`. Der TCP-Empfänger berücksichtigt `100 Continue`; Bereitschaft und
Abschluss haben begrenzte Wartepfade samt Cleanup. Die historischen E1-Assertions
prüfen ebenfalls erst den CLR-Typ und dekodieren danach. README und
MECM-Runbook beschreiben den Transportvertrag und die offene Engineabnahme.
Alle diese Tests sind ungestarteter Quellcode, kein Übertragungsnachweis.

Quellgrundlage bleibt der dokumentierte
[PowerShell-Rückgabevertrag](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.core/about/about_return?view=powershell-7.5)
zusammen mit dem `byte[]`-Zweig der
[offiziellen HTTP-Body-Implementierung](https://github.com/PowerShell/PowerShell/blob/master/src/Microsoft.PowerShell.Commands.Utility/commands/utility/WebCmdlet/Common/WebRequestPSCmdlet.Common.cs).
Der PS7-Quellbeleg ersetzt keine tatsächlich ausgeführte PS5.1-Abnahme.

Separater E9-Resthinweis aus der Gegenprüfung: Der Plan-Owner normalisiert
`Mode` durch Trim/ToLower, während der Anwenderpfad den rohen Registrywert
verzweigt. Ein manuell veränderter Wert wie ` DHCP ` kann deshalb im Plan
zulässig sein und im Aufrufer nach einer Adapterumbenennung scheitern.
Die regulär publizierten Werte sind kanonisch. Dieser vorbestehende Randfall
ist kein neuer Nachweis zum unbekannten Modus und bleibt ohne Scopeerweiterung
für eine gesonderte Entscheidung vermerkt.

U12: Der lokale Supervisorzustand liegt unter
`Docker/WebAPI/var/deploy-supervisor`, innerhalb des vorhandenen persistenten
WebAPI-Mounts. Ein separater Lifetime-Lock bleibt während atomarer
Zustandsersetzung erhalten und wird nicht ins Kind vererbt. Versioniertes,
begrenzt gelesenes JSON enthält Neustartzähler, Fenster, Cooldown und nächsten
Versuch. Die Startreservierung wird vor `proc_open` gespeichert. Ein
Schreibfehler sperrt neue Starts, während Beobachtung, Stoppsignale und Reap
weiter aus lokalen Prozessbefunden folgen. Unlesbare Zustandsbytes bleiben
erhalten und führen zur manuellen Sperre.

Ein neuer Supervisor kann ein zuvor gespeichertes `running`/`stopping` nicht
als Nachweis des Kindendes behandeln: Der reservierte Lauf wird konservativ
budgetiert, der Zustand bleibt `manual`, auch über einen geordneten Stopp
dieses neuen Supervisors hinweg. Eine gespeicherte PID oder die eigene PID 1
ersetzt keinen Prozessnachweis. Die manuelle Betriebsfreigabe benötigt den
gesonderten Nachweis, dass das alte Kind beendet ist; kein automatischer
Reset oder Ersatzstart beseitigt diese Unsicherheit.

`deploy_supervisor_publish.php` besitzt den DB-Verbindungslebenszyklus für
die Portalpublikation: nach Schreibfehler wird die Verbindung verworfen,
beim nächsten begrenzten Versuch frisch aufgebaut, die Warnung nach Erfolg
wieder freigegeben. Diese Daten gehen nicht in die Kindentscheidung ein.
`worker_database_connect.php` bündelt die bisherigen Startup-/Idle-Retries
beider Worker mit dem gemeinsamen Stop-Owner. Ein Stopp beendet den
Verbindungsweg ohne anschließende DB-Nutzung; die drei Versuche von `--once`
bleiben erhalten. Der Busy-DB-Channel mit seiner Ownership-/Spoolreihenfolge
bleibt unverändert. Ein bereits laufender DB-Systemaufruf kann die Reaktion
bis zu seiner Rückkehr verzögern; es gibt keine neue garantierte Stopplatenz.

U17: `scripts/restore_test.sh` löst die gesetzte Prüfwurzel vor der
Backupauswahl auf. Dump, Configarchiv, Manifest, Schema und alle drei
Anwendungsmounts folgen diesem Root; der kanonische Runner lädt weiterhin
seinen eigenen vertrauenswürdigen Checker. Fehlende oder unvollständige Roots
und Backuptripel brechen mit stabilen `[restore.*]`-Diagnosen ab. Die Ausgabe
ordnet Prüfroot und Backupstand gemeinsam zu. Ein direkt gesetzter leerer
Shellwert wird abgewiesen; `check.ps1` normalisiert einen leeren Eingangswert
wie bisher schon vor dem Export auf seinen Defaultroot.

Die vorbereitete `tests/powershell/VirtuSphere.RestoreRoot.Tests.ps1` erstellt
erst bei späterer Ausführung unterscheidbare synthetische Gzip-/Tar-Archive
unter A/B und ruft den kopierten kanonischen Checker aus A mit Datenroot B
auf. Fake-Docker beendet den Pfad bei `image inspect`. Damit sind Auswahl,
Default und fehlgeschlagene Root-/Tripelauswahl prüfbar, ohne Restorecontainer
zu starten. Quellassertions erfassen zusätzlich Schema und drei Repo-Mounts.
Die abschließende Gegenprüfung schloss einen Suchpfadfehler in der Fixture:
Ein Shellshim im selben Fake-Verzeichnis verhindert, dass das vom Runner
vorangestellte Shellverzeichnis unter Linux echtes Docker bevorzugt.
Shell- und Dockerauflösung werden vor dem Runnerstart abgesichert.
Das ist kein dynamischer Nachweis für Hashprüfung, Import, Mounts, Migration,
Cleanup oder Smoke. Diese Kette bleibt einer separat freigegebenen echten
Restore-Probe ausschließlich mit synthetischen Archiven vorbehalten.

### Vorbereitete Abnahme dieser Runde, nicht ausgeführt

Nach gesonderter Freigabe den tatsächlichen Gesamtstand festhalten und die
PowerShell-Matrix über den kanonischen Runner getrennt fahren:

```powershell
powershell -NoProfile -File scripts/check.ps1 -Lane Fast -Gate powershell-tests -Json qa-artifacts/implementation-round4/ps51.json -KeepArtifacts
pwsh -NoProfile -File scripts/check.ps1 -Lane Fast -Gate powershell-tests -Json qa-artifacts/implementation-round4/ps7.json -KeepArtifacts
```

Vor jedem langen Lauf ein live abfragbares Log für alle Ausgabeströme einrichten
und mindestens minütlich die letzte tatsächliche `[n/total]`-Zeile beobachten.
Das historische Härtungsregister läuft im vorhandenen Runner separat und
beeinflusst dessen Exitcode nicht: seine Ergebniszusammenfassung zusätzlich
auswerten. Ein grüner allgemeiner Gate-Exit ist allein keine E7/E9-Abnahme.

Für U12 sind `DeploySupervisorPersistenceTest`, `DeploySupervisorPublisherTest`,
`WorkerDatabaseStopTest`, `DeploySupervisorPersistenceContractTest` und der
zusätzliche manuelle Stopfall im `DeploySupervisorPolicyTest` vorbereitet.
Sie prüfen lokale Persistenz-/Lockfehler, das Wiederöffnen des Stores,
den konservativen Ownership-Latch, Verbindungswechsel über injizierte
Publisherabhängigkeiten und begrenzte Signal-/Retryfälle. Diese Tests ersetzen
keinen Neustart des echten Containers und keine verlorene echte mysqli-Verbindung.
Nach Freigabe über `phpunit-unit` und danach `qa-stack,phpunit-full` ausführen;
die vorhandenen echten Prozess-/Ownershipregressionen bleiben Bestandteil
dieser kanonischen Suiten.

Zusätzliche Laufzeitabnahme ausschließlich im isolierten synthetischen QA-Stack:
Cooldown und Retryfenster über wiederholte Supervisor-Neustarts beobachten;
reserviertes `running`/`stopping`, beschädigte oder nicht beschreibbare
Zustandsdatei und konkurrierenden Supervisor ohne Ersatzstart prüfen;
DB-Publikation nach echter Verbindungsunterbrechung mit derselben lebenden
Kindinstanz wiederherstellen; TERM/INT/QUIT während Startup-/Idle-DB-Ausfall
für beide Worker mit begrenzter Beobachtung und Busy-Gegenkontrolle prüfen.
Dabei Startzahl, Prozessidentität, gespeichertes Budget, Publikationsrückkehr
und tatsächliche Stopdauer erfassen. Ein blockierender DB-Aufruf bleibt eine
gesondert zu messende Grenze. Der historische PHP-Container-Exit 137 ist kein
U12-Abnahmefall und bleibt separat ungeklärt. Danach Fast und Integration
gegen denselben Gesamtstand.
U17 benötigt zunächst die synthetische Root-Regressionsfixture; ein echter
`restore-drill` ist eine eigene spätere Freigabe ausschließlich für ausdrücklich
ausgewählte synthetische Archive. Diese Runde führt auch keine Restore-Probe aus.

Abschluss dieser Umsetzungsrunde: E1, E7, E9, U12 und U17 sind implementiert
und anhand des Codes geprüft; ihre Laufzeitabnahme bleibt offen. Die
unabhängige Astra-Gegenprüfung hat die konkreten Produkt-/Testprobleme bis zum
fachlichen Abschluss zurückgemeldet. Anschließend endeten der U12-Owner und
Reviewer am Nutzungslimit; der Hauptagent hat den finalen Dokumentationsstand
selbst gelesen und den verbleibenden redaktionellen Abschluss übernommen.
Der E7/E9-/U17-Owner hat seinen Auftrag regulär abgeschlossen. Es läuft kein
weiterer Implementierungsauftrag.

HEAD bleibt `aa3daac`, Branch `codex/audit-round2-u05-u06-u15-u16`.
Alle Änderungen bleiben uncommitted und unstaged; keine getrackten Dateien
wurden gelöscht. U07–U10, deren neue Testdateien und der ungetrackte
Admin-Workflow-Plan bleiben erhalten. Es gab keine Test-, Lint-, Build-, Guard-
oder QA-Ausführung, keine Container-/Restoreaktion und keinen Commit, Push,
Merge oder Deployment. Nächster konkreter Schritt ist die separat
freizugebende PowerShell-5.1-/7-Matrix über den kanonischen Runner mit
beobachtbarem Fortschrittslog, gefolgt von den oben beschriebenen PHP- und
synthetischen Betriebsabnahmen. Reale MECM-/Client-/ESXi-/AD-Labore bleiben
für die jeweils betroffenen externen Abnahmen erforderlich. U13, das umfassende
U14 und der gesonderte E9-Registry-Randfall sind damit nicht erledigt.

## Umsetzungsrunde U07–U10, 09.09.2026

Ausgangs-HEAD `aa3daac`, bestehender Branch `codex/audit-round2-u05-u06-u15-u16`.
Der vorbestehende Registerdiff und der fremde ungetrackte Admin-Workflow-Plan
bleiben erhalten. U01–U06/U11/U15/U16 werden nicht neu begonnen. Die folgenden
historischen Abnahmen gelten ausschließlich für ihre damaligen Quellstände.
Diese Runde führt auf ausdrücklichen Auftrag keine Lint-, PHPStan-, Test-,
Build-, Guard- oder QA-Läufe aus und startet keine Container. Alle neuen
Regressionen sind ausschließlich Quellcode; kein Commit, Push oder Deployment.

| Paket | Stand dieser Runde | Gegenprüfung und offene Abnahme |
|---|---|---|
| U07 / SC-003, SC-004 | Implementiert, anhand des Codes geprüft, Laufzeitabnahme offen. | Quarantänedateien sperren auch beim fehlenden oder ersetzten Hauptjournal. Striktes explizites UTF-8 beim Lesen/Schreiben, auch mit optionaler UTF-8-BOM. Vorbereitete Regressionen: frischer zweiter Prozess, manuelle Freigabe, ungültige unverändert gesicherte UTF-8-Bytes, Mehrfachroundtrip mit ASCII-konstruierten Unicode-Codepoints für PS5.1/7. Astra-Abschlussprüfung ohne offene P1/P2. |
| U08 / SC-005 | Implementiert, anhand des Codes geprüft, Laufzeitabnahme offen. | D-01 am Apply-/Report-Owner: bestätigte Wiederherstellung derselben exakten ID erhält Provenienz; Ersatz-ID zieht nur die alte zurück, fehlgeschlagenes Add verschiebt den Rückzug. Der pure Plan bleibt unverändert. Historische Paare werden zusammen atomar quittiert; ein einzelner Remove benötigt bestätigte Live-Abwesenheit, sonst `uncertain`. Vorbereitete Tests führen echte extrahierte Apply-/Replay-Blöcke aus und prüfen Endpoint-/DB-Deltas mit gebundener Revision 2 sowie 409 bei gespeicherter Revision 3. Astra-Abschlussprüfung ohne offene P1/P2. |
| U09 / SC-009, SC-010, SC-017 | Implementiert, anhand des Codes geprüft, Laufzeitabnahme offen. | Application-/DT-Modell, konkrete Content-ID, bestätigter Aufruf und neuere Kopierbaseline je exaktem DP-Ziel ersetzen die verlangte Packageversionssteigerung. Fehlende/ungültige Skripte sperren; vollständige Vorlage wird auf dem Paketdateisystem gestaged, gehasht, gesichert und gemeinsam zurückgerollt. Vorbereitete echte Controllerregressionen: zwei Updates bei SourceVersion 1, alte Kopierzeit bleibt pending, fehlgeschlagene Requests werden nicht wiederholt. Neue ServerTemplate-Fixture: reale Stage-/Aktivierungs-/Restore-Blöcke mit Copy-/Hashfehlern, frischem Ziel und exakter Wiederherstellung vor Taskstart. Astra-Abschlussprüfung von Produkt- und Testcode ohne offene P1/P2. |
| U10 / SC-018 | Implementiert, anhand des Codes geprüft, Laufzeitabnahme offen. | Markerinvalidierung nur beim tatsächlichen Hash-Miss vor dem Kindprozess; Lesefehler/Entfernungsfehler sperren die Nutzlast. Quelltests in `VirtuSphere.PackageRepair.Tests.ps1`: Hash-Skip, Fehler/Continue, 0/1707/3010/1641, Folgelauf sowie Registry-/Startfehler. Astra-Gegenprüfung ohne P1/P2; Hinweis zur Wiederherstellung von `LASTEXITCODE` in der Fixture übernommen. |

Implementierung: zwei interne Sol-High-Agenten für U07/U08 beziehungsweise U09;
Hauptagent für U10, Dokumentation und ausschließlich dieses Register.
`VirtuSphere-Common.ps1` hat ausschließlich den U09-Schreibowner; nach fachlicher
Prüfung war kein U08-Eingriff in den gemeinsamen Membershipplan erforderlich.
Astra High prüft unabhängig den Originalcode, die Diffs und die drei offenen
historischen PowerShell-Härtungsfälle. Keine eigenständigen Benutzertasks.
Nach Abschluss von U07/U08 übernimmt derselbe Sol-Agent ausschließlich die
neue Installer-Verhaltensfixture; U09-Controller und dessen Tests bleiben beim
Contentowner. Produkt- und Testdateien behalten eindeutige Schreibowner.

Abschluss der Quellgegenprüfung: Beide Sol-High-Implementierungsaufträge und
der unabhängige Astra-High-Review sind abgeschlossen. Ausgewählte unveränderte
Schutzpfade wurden ebenfalls gelesen: POST/IP-Allowlist, Reportvalidierung vor
Schreiben, Revisionsprüfung und Provenienzschreiben in derselben gesperrten
Transaktion sowie Journal-OS-Lock vor MECM-Mutationen. Keine neue
Laufzeitbestätigung wird daraus abgeleitet. Branch und HEAD bleiben unverändert;
alle Änderungen liegen uncommitted im Arbeitsbaum, einschließlich der neuen
MembershipApply-, PackageRepair- und ServerTemplate-Testdateien. Der bestehende
Admin-Workflow-Plan bleibt ungetrackt erhalten. Nächster konkreter Schritt ist
die unten vorbereitete, separat freizugebende QA unter PowerShell 5.1 und 7.

U09-Upgradeentscheidung: Alte Content-Trackingstände ohne historische
Application-/DT-/Content-ID und Kopierbaseline bleiben auch bei altem
`complete` zur manuellen Prüfung gesperrt. Ein aktuelles gleichnamiges Objekt
mit grünem Aggregat kann den fehlenden historischen Identitätsnachweis nicht
ersetzen. Die Altbelege bleiben erhalten; keine automatische Übernahme oder
pauschale Löschung. Diese konservative Betriebsgrenze ist im MECM-Runbook
beschrieben und Bestandteil der späteren Upgradeabnahme.

U09-Quellenabgleich (09.09.2026):
[Microsoft Contentupdates](https://learn.microsoft.com/en-us/intune/configmgr/core/servers/deploy/configure/deploy-and-manage-content#update-content)
beschreibt neue DT-Content-IDs bei gleichbleibender Application-Packageversion;
[SMS_DeploymentType](https://learn.microsoft.com/en-us/intune/configmgr/develop/reference/apps/sms_deploymenttype-server-wmi-class)
definiert ContentId und Modellidentitäten.
[SMS_PackageStatusDistPointsSummarizer](https://learn.microsoft.com/en-us/intune/configmgr/develop/reference/core/servers/configure/sms_packagestatusdistpointssummarizer-server-wmi-class)
definiert den exakten DP-Schlüssel und `LastCopied` als erfolgreichen
Kopierzeitpunkt. Die daraus implementierte Abschlussentscheidung kombiniert
diese Evidenzen; Providerreplikationsverhalten und tatsächlicher DP-Inhalt
bleiben Gegenstand der externen Abnahme, kein bereits erbrachter Labornachweis.

### Vorbereitete spätere QA, in dieser Runde nicht ausgeführt

Die Einstiegspunkte wurden nur in `scripts/check.ps1` und
`scripts/lib/check/gates-fast.ps1` gelesen. In einer später dafür freigegebenen
Session zuerst die PowerShell-Matrix getrennt unter beiden Engines ausführen:

```powershell
powershell -NoProfile -File scripts/check.ps1 -Lane Fast -Gate powershell-tests -Json qa-artifacts/implementation-round3/ps51.json -KeepArtifacts
pwsh -NoProfile -File scripts/check.ps1 -Lane Fast -Gate powershell-tests -Json qa-artifacts/implementation-round3/ps7.json -KeepArtifacts
```

Vor jedem langen Lauf eine live abfragbare Logdatei für alle Ausgabeströme
einrichten und die tatsächlichen Fortschrittszeilen gemäß Runnervertrag
beobachten. Danach Fast und Integration über denselben kanonischen Runner
gegen den dann festgehaltenen Gesamtstand. Neue PHP-Regressionen benötigen
die synthetische DB der Integration; keine bestehende QA-Abnahme wird auf
diese Änderungen übertragen.

Externe Abnahmen bleiben getrennt: MECM-Testsite mit zwei DT-Contentupdates
bei gleicher Application-Packageversion, nachweisbarer DP-Verteilung und
Membership-Reconciliation; Windows-Installer mit Stage-/Copy-/Aktivierungs-
und Rollbackfehlern; Client im vorgesehenen SYSTEM-/Benutzerkontext mit
alter positiver Detection, geändertem Kind, Fehler/1641 und erfolgreichem
Folgelauf. Gemockte Regressionen ersetzen keine dieser Wirkungsabnahmen.

### Historische drei PowerShell-Härtungsfälle

Der gespeicherte Round-2-Log
`qa-artifacts/implementation-round2/20260909/final-integration-complete-gate-logs/powershell-tests.log`
nennt drei offene Fälle, aber keine Namen (historischer Registerlauf mit
`Output.Verbosity=None`). Die folgende Zuordnung wurde durch Astra und
Hauptagent aus `VirtuSphere.Haertung2026-08.Tests.ps1` und den tatsächlichen
Aufrufpfaden rekonstruiert, nicht durch einen neuen Lauf bestätigt.

| Historischer Fall | Einordnung anhand des Codes | Spätere Prüfung |
|---|---|---|
| E1, einelementiges JSON-Array | Eigener vorbestehender Transportrestpunkt (K, nicht neu reproduziert), zusätzlich veraltete Assertion: Server- und Client-`ConvertTo-VsUtf8JsonBytes` geben `UTF8.GetBytes()` ohne Auspackschutz zurück; Funktionsausgabe kann so als `object[]` statt `byte[]` beim HTTP-Body ankommen. Der historische Stringcast und neuere Stubcasts auf `[byte[]]` prüfen den echten Bindungstyp nicht. Die erste Einordnung als ausschließlich veraltete Fixture ist zurückgenommen. | Ohne Stubcast zunächst den tatsächlichen Bodytyp und danach Loopback-HTTP-Bytes unter PS5.1/7 für Ein-/Mehr-Element-Array und Objekt prüfen; eng begrenzten Transportfix separat beauftragen. |
| E7, `-NoProfile` an jeder Aufrufstelle | Veralteter Texttest: Er wertet den lesenden `Win32_Process`-Filter mit `Name='powershell.exe'` im Serverinstaller als Prozessstart. Echte Starts verwenden weiterhin `VsPowerShellArgs`. | Tatsächliche Aufruf-/Taskstellen statt beliebiger Zeichenketten prüfen, Nullfundschutz erhalten. |
| E9, unbekannter Interface-Modus | Veralteter Texttest: Er sucht eine bestimmte Fehlermeldung nur in `client_staticip.ps1`. `New-VsClientNetworkPlan` im Client-Common validiert die Modi; der Aufrufer beendet einen ungültigen Plan vor Netzmutationen. | Owner-Verhalten und Abbruch vor Mutation mit ungültigem Modus prüfen. |

E7/E9 sind nach Quellprüfung veraltete Probes. E1 ist anhand des
[PowerShell-Rückgabevertrags](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.core/about/about_return?view=powershell-7.5)
und der
[offiziellen HTTP-Body-Implementierung](https://github.com/PowerShell/PowerShell/blob/master/src/Microsoft.PowerShell.Commands.Utility/commands/utility/WebCmdlet/Common/WebRequestPSCmdlet.Common.cs)
als eigener Restpunkt eingeordnet: Collections werden aus Funktionsausgaben
entpackt; der HTTP-Body-Switch behandelt ausdrücklich `byte[]` als Bytes und
konvertiert andere Objekte zu String. Quellenabgleich durch Astra am
09.09.2026, keine reale PS5.1-/PS7-Übertragung gemessen. Dies betrifft auch
Membership-ACKs und bleibt daher eine Abhängigkeit ihrer Laufzeitabnahme,
ohne den Journal-/Wirevertrag in U07–U10 automatisch zu erweitern.
Das historische Testregister und die Transporthelper bleiben unverändert;
Transportkorrektur, Testkorrektur und Laufzeitbestätigung sind ein gesonderter
Folgeschritt.

## Umsetzungsrunde U05/U06/U15/U16, 09.09.2026

Ausdrücklich beauftragte zweite Runde auf `7c52869`, Branch `codex/audit-round2-u05-u06-u15-u16`. U01–U04/U11 werden nicht erneut begonnen; deren externe Abnahmen bleiben offen. Bestand, ursprünglicher Registerdiff und fremder Admin-Workflow-Plan sind unter `qa-artifacts/implementation-round2/20260909/` gesichert. Nur der Hauptagent pflegt dieses Register. Kein Push, Merge oder Deployment.

| Paket | Umsetzung und lokale Abnahme | Externe Abnahme |
|---|---|---|
| U05 / SC-019 | Missionsgebundene Teil-/Leerauswahl bleibt erhalten; gemeinsamer Empty-Blocker verhindert Scope-Erweiterung. Echter Missionswechsel verwirft alte IDs. PHP-, Browser-, Preview- und Payload-Prüfungen bestanden. | keine neue Standortfreigabe |
| U06 / SC-016 | Alle Manifestvergleiche laufen; Drift oder unentscheidbare Prüfung stoppt vor Site-Initialisierung und jedem CM-Cmdlet. Nachgewiesener PS5.1-Startfehler des Standardquellpfads korrigiert, explizite Werte bleiben erhalten. Zehn echte Installer-Fixturefälle unter PS5.1 bestanden, einschließlich acht Negativfällen mit null CM-Aufrufen. | Share/DP/SYSTEM offen |
| U15 / SC-022 | Pflichtbool im geschlossenen Auditvertrag; atomarer Evidenz-/Auditabschluss; überholter Abschluss als verworfen gekennzeichnet. DE/EN, Revision/Generation, Audit-Rollback und Browser-Rückmeldung bestanden. | keine externe Zugangsfreigabe |
| U16 / SC-024 und E2E | Takt-, VLAN- und Inventarbeleg-Fixtures korrigiert. Vollständige Guard-Fixtures mit positiver Kontrolle und konkreten Diagnose-IDs: 106/106 bewiesen. Der alte Live-Queue-Test prüft nach Abwahl jetzt die U05-Sperre und nach erneuter Auswahl den unveränderten positiven Pfad. | keine neue externe Abnahme |

Die vollständige Gate-Abdeckung ist durch den kanonischen Integrationslauf `final-integration-complete` und den vollständigen E2E-/Visual-Nachlauf `final-e2e-recheck` hergestellt. Der erste dieser Läufe bleibt tatsächlich rot: 38/39 pass, ein E2E-Fehler, null Infrastrukturfehler/Skips. Sein Browser-Test erwartete nach ausdrücklicher Abwahl der einzigen VM weiterhin einen aktiven Queue-Button. Nur diese Testassertion wurde an U05 angepasst und um den positiven Wiederwahlpfad erweitert; Produktbedingungen und die anderen 38 Gate-Grundlagen blieben unverändert. Der gezielte Nachlauf bestand 3/3 einschließlich Vorbereitung, der vollständige E2E-Nachlauf 1/1 Gates. Dies ist kein rückwirkend grün erklärter monolithischer 39/39-Lauf.

Abnahmezahlen: Fast 31/31; Unit/Static 1696 Tests / 39979 Assertions; vollständiges PHPUnit 2156 Tests / 44545 Assertions, jeweils ohne Skips. Reguläres Pester-Gate 587 bestanden, null Fehler/Skips, Coverage 80,5 %. Der bestehende Hauptfilter weist 38 Härtungsregisterfälle als NotRun aus; das separat ausgeführte historische Register meldet 3 offene Befunde, 30 behobene und 5 Handproben und bleibt nach unverändertem Runnervertrag nicht gate-blockierend. Guard-Harness 106 proven, null unproven/Infrastrukturfehler. Der vollständige Chromium-Nachlauf bestand 266/266. Alle zwölf geprüften Visual-Sollbilder beider Themes trafen bei Nulltoleranz; keine Baselines geändert. Der Runner dokumentiert seine beiden Versuche je Theme und Rasterrauschen bei `light/deploy-mobile.png`: Versuch 1 exakt, Versuch 2 um 13 Pixel abweichend, entsprechend dem bestehenden Vertrag bestanden.

Frühere Versuche bleiben erhalten: erster Fast-Lauf 29/31 mit redundantem PHPStan-Fallback und dem belegten PS5.1-Standardparameterfehler, anschließend behoben und vollständig nachgeprüft. Drei Integrationsversuche endeten ohne Abschluss-JSON; eine sichere Abbruchursache ist nicht belegt. Ein inneres PowerShell-Tee erfasste Write-Host nicht; der abschließende Lauf verwendete eine weiter abfragbare Sitzung mit äußerem Tee. Teilresultate werden nicht als vollständige Abnahme gezählt.

Umsetzung durch Sol High, kritische Gegenprüfung durch Astra High, einschließlich der späten PS5.1- und Browser-Testkorrekturen: CLEAN. Drift- und i18n-Review ohne offene Befunde. Auf Nutzerwunsch wurden Luna Low und Terra Low für Routine-QA erprobt; die vollständige Integration betreute Sol Medium, den abschließenden E2E-Nachlauf beobachtete Terra Low in einer vom Hauptagenten gehaltenen Sitzung und erstellte den QA-Bericht. Der synthetische `virtusphere-qa`-Stack hatte zu jedem Lauf genau einen QA-Owner. Der vorhandene Entwicklungsstack blieb unangetastet.

Nachweise: `qa-final.md`, `review-final.md`, vollständige Gate-Logs und End-JSONs im Artefaktverzeichnis. Das finale Quellmanifest umfasst 37 Paketdateien. Reale Share-/DP-/MECM-/SYSTEM-/ESXi-/AD-Abnahmen bleiben offen; keine Standort- oder Performancefreigabe. Vorbestehende Registerinhalte und der fremde Admin-Workflow-Plan bleiben erhalten.

Lokale Paketcommits: `cf875b5` (U05: missionsgebundene VM-Auswahl), `9589966` (U06: harte Manifestgrenze), `87f8347` (U15: atomarer typisierter Credential-Abschluss), `2c12902` (U16: Browser-Fixtures und Guard-Nachweise). Der gemeinsame geprüfte Endstand ist ihre Abnahmegrundlage. Prozessabschluss und Datenerhalt werden in `qa-final.md` festgehalten.

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

- Stand: **09.09.2026, Audit AP00–AP13 mit ausdrücklich dokumentierten Nachweislücken abgeschlossen.** 26 bestätigte R/K-Befunde, davon 7 P1; alle Produktkorrekturen offen. Quellen-/Gegenprüfungen, lokale Reproduktionen, Performance-/Queryreihen, korrigierter Sessionvergleich, Stop-Proben und synthetischer Restore gesichert. Release-Auswahl 2/2 und Schlussgates 6/6 pass. Keine Gesamtfreigabe für Integration, Release oder externe Standorte.
- Aktueller Prüfstand: `68c3ea08cbfa183797f6237be6d8c6a68709e6c2`, Branch `main`; initiales `git status --short` leer. Quellmanifest und ausführbare Toolverfügbarkeit werden in AP00 gesichert. Ältere Ergebnisse gelten nicht automatisch für diesen Stand.
- Nächster Schritt: priorisierte Umsetzung U01–U06/U11 und Testkorrekturen U16 im nachgelagerten Umsetzungsauftrag; danach vollständige QA und die unten benannten Laborabnahmen. Der Auditlauf selbst ist beendet, QA gestoppt, keine automatische Fortsetzung.
- Agentenstand: frühere Agenten `qa_resume`, `qol_perf_resume`, `e2e_triage` am Nutzungslimit beendet; nach Hostneustart keine alten Agenten/Runner aktiv. Neue begrenzte Gegenprüfungen: `final_counterreview` Astra high (Originalcode/SC-024/025/026/008), `final_coverage` Sol high (Abdeckung), `source_preservation` Sol medium (Quellmanifest). Nur Hauptagent schreibt dieses Register und benutzt QA. Keine eigenständigen Benutzertasks oder Nutzungsgutschrift.
- Für dieses Audit geänderte Produkt-/Testdateien: keine.
- Während der Ausführung zusätzlich im Worktree erschienen: ungetrackte `docs/audits/2026-09-08-admin-workflow-feature-plan.md`. Sie stammt nicht aus den zugewiesenen Audit-Artefaktbereichen, wird als fremde parallele Dokumentänderung bewahrt und gehört nicht zum ursprünglichen Quellmanifest. Kein daraus abgeleiteter Auftrag zur Produktänderung.

## Paketstatus

Statuswerte: offen, in Arbeit, geprüft, mit Nachweislücken geprüft. Fehlende Infrastruktur erhält im nächsten Schritt eine konkrete Voraussetzung. Ein Paket mit Nachweislücken ist keine bestandene Laufzeitabnahme. Die Modellempfehlung steht ausschließlich in Abschnitt 5 des Plans; bei der Ausführung hier je Paket das tatsächlich eingesetzte Modell, Reasoning und eine erfolgte Gegenprüfung festhalten.

| Paket | Gegenstand | Status | Nachweis / nächste Arbeit |
|---|---|---|---|
| AP00 | Prüfstand, Vorbefunde, Umgebung | mit Nachweislücken geprüft | Sol medium; `ap00/report.md`, Manifest; ursprünglicher Hostprozessnachweis nicht verfügbar, nach Unterbrechung sicherer CIM-Abgleich erfolgt |
| AP01 | Gates und Aussagekraft | mit Nachweislücken geprüft | Sol medium; Fast 31/31 pass. Integration ohne EndJSON; E2E 258 pass/6 fail. Guard: 103 proven/2 unproven, Exit 1; vier Diagnosen und Astra-Gegenprüfung bestätigen SC-024, vier weitere proven sind nicht semantisch belastbar. QA-Neustart 1/1 pass, 27,3 s |
| AP02 | Datenflüsse und SSoT | mit Nachweislücken geprüft | Hauptagent; zentrale Matrix und `ap02/flow-map.md`, `execution/source-matrix-ap02.md`, AP04/AP05-Sender; externe Flüsse benötigen Lab |
| AP03 | Queue, Worker, Ansible, Create | mit Nachweislücken geprüft | Astra xhigh; Originalcode-Gegenprüfung plus Root-Lauf der acht DB-Proben: 2 Kontrollen pass, 6 beabsichtigte Vertragsassertionen fail, 79 Assertions; fünf Befundursachen reproduziert |
| AP04 | MECM-Server-PowerShell | mit Nachweislücken geprüft | Astra high; `mecm-server/report.md`, `matrix.md`, `lab-protocol.md`; drei PS5.1-Repros; keine reale MECM-Abnahme |
| AP05 | Clients, Installer, Pakete | mit Nachweislücken geprüft | Sol high; `clients-docs/report-ap05.md`, Phasen-/Installermatrix, 15 Labfälle; kritische ACK-/Reboot-/Netz-/Diskpfade im Original gegengeprüft |
| AP06 | Machine API, DB, Portal-Schreibwege | mit Nachweislücken geprüft | Hauptagent plus Astra-xhigh-Gegenprüfung; VM-/Missionsverlust gleicher Sekunde reproduziert, nächste Sekunde korrekt abgewiesen; Auditcontext mit ungültigem und registriertem Gegenfall ausgeführt |
| AP07 | Status, Logs, Recovery | mit Nachweislücken geprüft | Hauptagent; `ap07/report.md`; aktuelle E2E-Triage und Credential-/Inventargegenprüfung ausgewertet, offene Korrekturen SC-022/023 und spätere Browserabnahme |
| AP08 | Portal-QoL und Skalierung | mit Nachweislücken geprüft | Sol high vorbereitet, Root ausgeführt; SC-019 im Browser reproduziert, gültiger Missionswechsel, originaler E2E-Umfang/Triage; weitere DE/EN-/No-JS-/Visualmatrix bleibt explizite Abnahme |
| AP09 | Performance und Ressourcen aller Schichten | mit Nachweislücken geprüft | Sol high vorbereitet, Root ausgeführt, Astra high gegengeprüft; S/T/L je drei Leseläufe, drei Schreiblasten und Querydiagnose, SC-025. Ursprünglicher Session-A/B verworfen; korrigierter Nachlauf 4/4 pass, Identitäten belegt, je 14 Paare. Cold-/externe Zeiten und Profilerrest offen |
| AP10 | Robustheit und Self-Healing | mit Nachweislücken geprüft | Astra xhigh; `execution/report-ap10.md`, Recoverymatrix; Quellengegenprüfung erfolgt; Windows-Anteile AP04/AP05 und Fault-Labs ergänzen |
| AP11 | Wahrheitsprüfung von Doku und Portalhilfe | mit Nachweislücken geprüft | Sol high; `clients-docs/report-ap11.md`, `claim-matrix.md`; kritische DE/EN-Behauptungen und saubere ACK-Zuordnung im Original gegengeprüft; expliziter Text-/Befehlsrest |
| AP12 | Betrieb, Migration, Distribution, Lab | mit Nachweislücken geprüft | Sol high vorbereitet, Root ausgeführt: drei Stopfälle gemessen, synthetischer Restore 1/1 pass, 51,9 s; SC-015 R/SC-026. Secret-Scan/npm-Audit 2/2 pass; SBOM/CVE/Offlinevollabnahme und externe Labs ausdrücklich offen |
| AP13 | Gegenprüfung und Umsetzungspakete | geprüft | Astra high Originalcode-/Rohbeleggegenprüfung, Sol high Abdeckungsreview, Sol medium finaler Manifestvergleich; U01–U17, konsolidierter Bericht und offene Abnahmen. Schlussgates 6/6 pass |

## Prüfstand und Prüfprotokoll

Lauf-ID `20260908-full`, Referenz HEAD wie oben. Detailnachweise unter `qa-artifacts/system-chain-audit/20260908-full/`. Geheimnisse, ungefilterte Providerantworten und produktive Nutzdaten gehören nicht in dieses Register.

AP00: 3.284 Pfade im Manifest (3.281 echte Baselinehashes und drei ursprüngliche MISSING-Sentinels für Git-gequotete Umlaut-Screenshotpfade, beim Schlussabgleich erkannt) `ap00/source-manifest.sha256.tsv`, SHA-256 `06956e6415d0d074d147be11d52f01ddf2203241affee0da99a67f48a844cd9a`; Ausschlüsse separat. PHP 8.4.25 im Projektimage, Docker Client/Server 29.6.1, PS5.1.26100.9168, PS7.6.5, Pester 5.7.1, PSScriptAnalyzer 1.25.0, Node 24.14.1 und Chromium 1228 vorhanden. Alle neun Toolimages lokal aufgelöst und ausgeführt, einschließlich Hadolint 2.14. Native PHP fehlt, vorgesehener Containerfallback verfügbar. Git-Bash-Start im Sandboxkontext fehlerhaft, sichere Runnereskalation für AP01 erforderlich. Windows-Prozessinventur nicht zugänglich; QA-Belegung anhand Docker und Agentenkoordination geprüft. Kein laufender QA-Container beim Start; getrennten Dev-Stack unangetastet lassen. Details und historische Zuordnung: `ap00/report.md`.

Vorprüfung der Planerstellung am 08.09.2026: Lokale Markdownziele, UTF-8-Ersatzzeichen, nachlaufende Leerzeichen und die Übereinstimmung der Pakete AP00 bis AP13 in Plan und Register wurden geprüft, Ergebnis sauber. Der kanonische Aufruf `powershell -NoProfile -File scripts/check.ps1 -Gate doc-hygiene,doc-semantics -Json qa-artifacts/system-chain-audit/plan-validation/docs.json -KeepArtifacts` meldete beide Gates als `fail`, Runner-Exitcode 1. Die Ausgabe zeigt jedoch einen Startabbruch von `sh.exe`/MSYS mit Exitcode `-1073741502`, keine fachliche Checkdiagnose. Dieser historische Vorprüfungslauf ist nicht erfolgreich ausgeführt; die Toolstartursache ist von einer fachlichen Diagnose getrennt. Die späteren Fast-/Schlussläufe führten dieselben Gates erfolgreich aus. Liveausgabe und JSON liegen unter `qa-artifacts/system-chain-audit/plan-validation/`. Daraus folgt keine bestandene oder fachlich fehlgeschlagene Dokumentationsabnahme.

## Vorbefunde und SSoT-Matrix

Vorbefunde behalten ihre ursprünglichen IDs. AP00 ordnet F01–F08/A01–A08 des YAML-/Runtimeberichts und SS-01–SS-25 der Systemstatuskampagne dem aktuellen Stand zu (`ap00/report.md`); historische Offlineerfolge bleiben historische Nachweise. Reale Linuxvisual-, MECM-/ESXi-, Air-Gap- und positive Recoveryabnahmen werden nicht rückwirkend grün gesetzt.

Die gemeinsame AP02-Arbeitskarte liegt unter `ap02/flow-map.md`, vollständige aufgabenspezifische Schreibermatrizen bei den Fachberichten. Zentraler konsolidierter Stand:

| Fachregel/Feld | Owner / Schreiber | Leser / Darstellung | Fence, Normalisierung, Nachweis / Lücke |
|---|---|---|---|
| ESXi VM-/Objektname | esxi_object_names, VMrepo, MACcallback | Ansible/Inventar/Portal, raw UTF-8 | exakte Bytes, folded nur Diagnose; AP03/AP06 |
| gewünschter Windowsname / Rolloutname | vm_rollout, VMsave, Reset, Claims | DeviceList/Infos vm_hostname aliasiert Snapshot | globale Claims, Revision, Tombstone; AP04/AP05/AP06, MECM-Lab offen |
| Lifecycle / MECM / Legacy / updated / mecm_id | status_events, MACcallback, Worker, Reset, updateDevice, ACK | fünf technische Wirestatus; portal_status_display | ACK allein 5/5, vorwärts bei ResourceID; SC-001/002/007, Worker-Cancelrace SC-011 reproduziert |
| Interface / VLAN / MAC | vm_network_contract, repo/vm_network, db_importMAC | Preflight, Ansible, getDeviceInfos | Mission/Job/VM/Interface-Locks, exakte VLANbytes, effektive MAC erhalten |
| Queueauswahl / Modus / Zeit | deploy_form_state, queue_blockers, deploy_job_queue | Preview/Queue/Stagger/Worker | ein Formzustand; explizite fehlende IDs nicht zu alle; Grenzen vor Remote |
| Jobbesitz / Versuch / Runtime / Handle | job_worker, runtime_identity, remote repos | Worker/Callback/Status | Claimstate/CAS/Generation; remote Grundlagen teilweise nicht aktiv, AP03/AP10 |
| Createeinheit / Position / async-ID | deploy_create_results, worker_create, vier Ansible-Createplaybooks | Jobdetail/Retry/Liveidentity | persisted Row und derived async directory; SC-001/002/007 |
| Callback V2 / Fingerprint | upload_mac_list.py -> db_importMAC | VM/Interface/Status/result_json | aktive Mission/Job/Attempt/Generation/Handle; semantische identische Replaygrenze, Größenowner mac_import_constants |
| MECM-Provenienz | DeviceSync/Journal -> updateid -> mecm_provenance | Membershipplan/Portaltransfer | Revision im Write, CollectionID+Namensvertrag; SC-003/004/005 |
| Katalog | Package-TaskSeq-Sync -> mecm_packages, Admin Delete | Portal read-only Auswahl | retire statt delete, höhere neue Version/Relink; confirmed empty vs omitted Type als Entscheidung |
| Applicationcontent | Autoimporter, ClientPackaging, Installer | MECM Application/DT/DP | Manifest/intent/pending/complete; SC-009/010, echte Provider-/DP-Abnahme offen |
| Serverruns/Site | vier Tasks -> reportRun/heartbeat -> heartbeat repo | integration_health/Systemstatus | Site2 kritisch, Providerfehler unknown, Run arrival/dedup; AP04 |
| Clientphase | getinfo/hostname/staticip/disks -> reportPhase -> client_events | VM-Clientphasen | display-only, serverseitige Zeit, laufübergreifende Arrival-Order; Rolloutdarstellung offen |
| Clientready | getinfo Registry -> Confirm-VsClientReady -> ACK | Lifecycle 5/5 | Revision vor Dedup; kein Nachweis späterer Hostname/Netz-/Diskphasen |
| Logs/Retention/Korrelation | Loggingmodule, repo/log, joblog, maintenance | Portal/Files | getrennte Sinks, Korrelation diagnostisch; Terminalappendguard, SC-001/Cleanup |
| Deployservicezustand | deploy_service_health_snapshot | vier Oberflächen inkl anonymous health | drei Achsen und ein now; Formabweichung degraded; AP07/AP10 |
| Auth/Rollen/CSRF | auth, permissions, portal_guard_post | Portalhandler | serverseitig can, letzte lokale Adminrolle unter Lock, Sessionablauf; AP06/AP01 |
| Konfiguration/Secrets | envboot, settings/credentials repos | PHP, Worker, Installerregistry | keine Secretfallbacks, serverseitige Permission-/CSRFgrenze; reale Trustabnahmen offen |

Aktive MECM-Tasks einzeln: `mecm_new-device-sync.ps1` (DeviceList/updateDevice/reportMembership), `mecm_Packages-TaskSeq-sync.ps1` (Katalog), `mecm_autoimporter.ps1` (Application/DT/Distribution/Collection), `mecm_site-health.ps1` (Providerstatus). AP04-Dateisuche fand keine direkten PowerShell-SQL-Schreiber; HTTP und ConfigurationManager/CIM sind die aktiven Wege. Clientphasen und Installer werden in AP05 separat abgeschlossen.

## Befunde

Fortlaufende Befunde des aktuellen Prüfstands, einschließlich AP13-Gegenprüfungen. Alle Einträge sind offene Produkt-, Text- oder Testwerkzeugbefunde. Produktkorrekturen wurden nicht vorgenommen.

### SC-001: Reaper rollt bei einer offenen Create-Einheit seinen Abschluss zurück

- AP03/AP10, Robustheit, **P1/R**, offen. Owner: `lib/repo/deploy_job_maintenance.php:166-184`, `lib/repo/deploy_job_worker.php:207-225`, `lib/repo/helpers.php:48-88` (jeweils unter `Docker/WebAPI`).
- Soll ADR-0041: verwaisten Job und laufende Create-Einheit atomar zu terminal beziehungsweise `uncertain` konvergieren. Trigger: stale `running`/`cancelling` mit `prepared`/`running` Create-Row.
- Ist: Reaper schreibt erst den terminalen Job, dann konvergiert er die Einheit und versucht einen normalen Joblog-Append. Dessen Terminalguard wirft; `repo_transaction()` rollt den gesamten Reaper-Batch zurück. Kern-Recovery und gegebenenfalls weitere Jobs desselben Batches bleiben blockiert.
- Astra xhigh gefunden, Hauptagent anhand Originalcode gegengeprüft. Gegenfall ohne offene Create-Row überspringt den fehlerhaften Append. Der vorhandene Create-Konvergenztest prüft den inneren Helper, nicht die komplette Reapertransaktion. Vollständige QA-Reaperfälle 2/3 reproduzieren den Rollback, Kontrolle 1 besteht; siehe ergänzende Laufzeitbelege. Keine reale ESXi-Wirkung behauptet.
- Umsetzung: Reaper-Owner ordnet Log und Status im bestehenden Transaktionsvertrag korrekt. Regression über vollständigen Reaper einschließlich zweitem Job und Null-Create-Kontrolle; Contract-/QA-Review erforderlich.

### SC-002: Teilweiser Create-Erfolg wird im Retry als ungültiges MAC-Ergebnis blockiert

- AP03, Logiklücke, **P1/R**, offen. `lib/repo/deploy_job_retry.php:88-89,128-138`; Createabschluss in `lib/deploy_worker_create.php`.
- Soll ADR-0041: entschiedene Create-Row-Ergebnisse erlauben einen erneuten Versuch mit Identitätsprüfung erfolgreicher Einheiten; nur unaufgelöste Einheiten müssen blockieren.
- Ist: `partial` ohne MAC-/Netzwerk-JSON setzt vor der Create-Row-Auswertung `retry_result_protocol_error`. Ein Createauftrag kann regulär `partial` mit eigenständigen Create-Ergebnissen und ohne MAC-Export enden. Damit bleibt der vorgesehene Retry trotz geklärter Einheiten gesperrt.
- Astra xhigh und Hauptagent Originalcode-Gegenprüfung; Pure-Create-Retry-Matrix und vorhandener Partial-MAC-Test decken diese Verbindung nicht. QA-Fall 4 reproduziert die Sperre nach echtem Partialabschluss; Gegenfall 5 bewahrt die Uncertain-Sperre. Siehe ergänzende Laufzeitbelege.
- Umsetzung: Ergebnisarten am Retry-Owner unterscheiden, ohne Remote-/Identitäts-/Uncertain-Sperren zu lockern; vollständiger Queue-/Retry-Verhaltenstest, Contract-/QA-Review.

Zusätzliche SC-002-Gegenprüfung: `deploy_job_retry_plan()` (`repo/deploy_job_input.php:167-172`) wählt bei `partial` ohne MAC-Ergebnis außerdem Export-only. Nur die Protocolsperre zu entfernen wäre deshalb falsch: auch Modus und Create-Recovery-Materialisierung müssen gemeinsam korrigiert werden. Regulär failed/cancelled ohne Ergebnis sind nicht betroffen, weil der Terminalencoder dort NULL liefert; succeeded ist nicht retryable.

### SC-003: Quarantäne eines MECM-Membership-Journals blockiert nur den ersten Start

- AP04/AP10, Robustheit, **P2/R**, offen. `Powershell-MECM/mecm/VirtuSphere-MembershipJournal.ps1:49-69` und Startguard in `mecm_new-device-sync.ps1:42-50`.
- Unlesbares Journal wird verschoben und der aktuelle Lauf abgebrochen. Der nächste Read findet keine Hauptdatei und liefert ein leeres Journal trotz vorhandener Quarantäne. Damit gilt ungeklärte Ownership-Evidenz nach Neustart nicht mehr als Sperre.
- Isolierte Originalfunktionen unter Windows PowerShell `5.1.26100.9168`: erster Read wirft, Quarantäne erhält Bytes, zweiter Read liefert null Einträge ohne Fehler. Evidenz: `qa-artifacts/system-chain-audit/20260908-full/mecm-server/pure-results.json` und `reproduce-pure.ps1`; Hauptagent hat Artefakt und Originalcode geprüft. Kein MECM-Providerlauf; konkrete externe Mutation bleibt Laborabnahme.
- Umsetzung: dauerhafte Quarantäne-/Klärungsgrenze beim bestehenden Journal-Owner, klare manuelle Freigabe; Zwei-Start-Test, intaktes/leeres Journal als Gegenfälle, Installererhalt und Logging prüfen.

### SC-004: Membership-Journal beschädigt Unicode unter Windows PowerShell 5.1

- AP04, Fehler, **P2/R**, offen. Derselbe Journal-Owner: BOM-loser UTF-8-Writer, `Get-Content -Raw` ohne `-Encoding` im Reader.
- Isolierter PS5.1-Roundtrip verändert `Paket Ä` (U+00C4 wird zu U+00C3/U+201E). Collectionnamen und spätere Read/Modify/Write-Zyklen verlieren exakte Zeichen. Artefakte wie SC-003, einschließlich Codepointvergleich; reale MECM-Folgewirkung offen.
- Herstellerabgleich 08.09.2026: [Microsoft Character Encoding](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.core/about/about_character_encoding?view=powershell-7.6) beschreibt ANSI bei BOM-losem `Get-Content` unter Windows PowerShell; zusätzlich [Microsoft Encoding-Hintergrund](https://github.com/MicrosoftDocs/PowerShell-Docs/blob/main/reference/docs-conceptual/dev-cross-plat/vscode/understanding-file-encoding.md). Das stimmt mit dem gemessenen Roundtrip überein.
- Umsetzung: Encoding am Journalreader explizit festlegen; Byte-/Namensroundtrip über mehrere Writes unter PS5.1 und PS7, unveränderte Hash-/Versions-/Packagingverträge prüfen.

### SC-005: Membership-Apply kann frisch geschriebene Provenienz wieder löschen

- AP04/AP06, Logiklücke, **P2/K** (Pure-Plan zusätzlich R), offen. `VirtuSphere-Common.ps1` Membershipplan, `mecm_new-device-sync.ps1` Add- und stale-owned-Apply, `lib/repo/mecm_provenance.php` Berichtsanwendung.
- Trigger: Target A weiterhin gewünscht und als owned gespeichert, aktuelle direkte MECM-Regel fehlt. Plan enthält sowohl `add(A)` als auch `stale_owned(A)`. Apply fügt A extern hinzu und berichtet anschließend `added(A)` sowie `removed(A)`; Empfänger verarbeitet die Einträge sequenziell. Remote-Regel bleibt bestehen, neue eigene Provenienz wird gelöscht.
- Gemeinsamer vorhandener Testvektor erwartet beide Plan-Buckets ausdrücklich. Der bestätigte Widerspruch liegt deshalb an der verbundenen Apply-/ACK-Wirkung, nicht allein an der Planform. Gegenfall ohne desired A enthält kein Add. Pure-Reproduktion wie SC-003; vollständiger Sender-/DB- und MECM-Nachweis folgt.
- Umsetzung: beim bestehenden Reconciliation-Owner klären, ob externe Entfernung gewollt bleibt oder erneut hinzugefügt wird; in beiden Fällen müssen Livewirkung und Provenienz übereinstimmen. Ende-zu-Ende-Mock plus DB-Deltatest und reales MECM-Lab; keine pauschale Plan-Zentralisierung.

### SC-006: Sekundenauflösung lässt konkurrierende Portaländerungen unbemerkt überschreiben

- AP06/AP08, Fehler, **P1/R**, offen. `lib/repo/vms_persistence.php:229-262`, `lib/repo/missions.php:276-321`; `Docker/mysql/mysql-init/struktur.sql` definiert `updated_at TIMESTAMP` ohne Nachkommastellen.
- Soll laut bestehender Konfliktentscheidung (historisch `2026-07-hardening.md`, 4.2): parallele Bearbeitung soll keinen stillen Datenverlust verursachen. Zwei Formulare lesen T; A speichert innerhalb derselben Sekunde T; B sendet ebenfalls T mit altem Feldbestand. Der Lock serialisiert die Writes, doch der Timestampvergleich erkennt die Änderung nicht. B überschreibt A. Eine fehlende POST-Erwartung kann den Guard zusätzlich umgehen; der Repo-Opt-out für Legacy ist dagegen ausdrücklich beabsichtigt.
- `VmEditConflictTest` verwendet für stale absichtlich einen Zeitstempel aus 2000 und benennt selbst die Sekundengrenze; er beweist diesen Fall nicht. Hauptagent hat Code, Schema, Test und historische Sollentscheidung geprüft. Zusätzliche unabhängige Astra-xhigh-Gegenprüfung bestätigt Verlust bereits committeter Nutzerdaten und deshalb P1 gemäß Plan. Entscheidend ist, dass A den von beiden gelesenen Token T nicht verändert; zwei Writes in derselben späteren Sekunde allein genügen nicht. Die QA-Reproduktion mit sessionlokal kontrollierter DB-Zeit bestätigt beide Datenverluste; die Folgesekunde besteht als Gegenfall.
- [MySQL 8.4 Präzision](https://dev.mysql.com/doc/refman/8.4/en/fractional-seconds.html) bestätigt Standardpräzision 0 und `NOW()` ohne Bruchteile; [Zeitfunktionen](https://dev.mysql.com/doc/refman/8.4/en/date-and-time-functions.html) wurden ergänzend geprüft (08.09.2026).
- Umsetzung: monotoner Änderungszähler oder vollständiger Versionsvertrag beim VM-/Missionsowner; Portal muss seinen Token verlangen, legitimen Repo-Opt-out getrennt erhalten. Zwei-Writer-Test mit gleicher DB-Sekunde und unveränderten Kindzeilen; Migration-/Contract-/i18n-/QA-Review.

### SC-007: Frischer Createauftrag kann die Uncertain-Sperre eines älteren Jobs umgehen

- AP03/AP10, Vertragslücke, **P1/R**, offen. `repo/deploy_job_queue.php:37-114` und Stagger, `repo/deploy_job_worker.php` Claimpfad; historische Einheiten in `deploy_create_vm_results`.
- Nach terminalem alten Job mit `uncertain` blockiert der Retry. Eine frische Createqueue prüft aktive Jobs, Identitätscache und Netzwerk, aber keine historischen offenen Create-Einheiten. Claim prüft Claimstate, nicht die Recovery-Warnachse. Im Fenster vor sichtbarer ESXi-VM kann eine zweite Createausführung vorbereitet werden, obwohl die erste externe Wirkung ungeklärt ist.
- Astra xhigh gefunden, Hauptagent Queue/Claim/Blocker im Original gegengeprüft. Cache-/Live-Namenskollision kann später blockieren, ist jedoch kein Beweis für das frühere Fenster. QA-Fall 7 nimmt die neue Queue an und claimt sie, während die alte Einheit uncertain bleibt. Keine doppelte reale ESXi-Erstellung behauptet.
- Umsetzung: bestehende Create-Ownership/Freigabegrenze auch in frischer Queue, Stagger und Worker unmittelbar vor Remote durchsetzen; Scope-/Hostidentität beachten. Vollständiger Queue-/Claim-Test plus ESXi-Lab mit verzögertem Sichtbarwerden; Contract-/QA-Review.

### SC-008: Liveblocker-Endpoint hält die Browsersession über Scopeabfragen gesperrt

Stand 10.09.2026: **implementiert, anhand des Codes geprüft, Laufzeitabnahme offen**.
Sessionfreigabe nach vollständiger Auth-/Locale-/Sessionarbeit, expliziter
Benutzer für Folgezugriffe und vorbereitete Konkurrenzregression siehe U13
am Registeranfang. Der folgende Originalbefund bleibt historische Evidenz:

- AP07/AP09, Performance/QoL, **P2/K**, offen. `portal/deploy_blockers.php:35-42` und `lib/bootstrap.php:45`; Kontrastpfad `portal/deploy_log.php:59-60` gibt rechtzeitig frei.
- Derselbe Benutzer löst eine Liveprüfung aus und öffnet eine zweite Seite. Session bleibt während kompletter Blocker-/Warnqueries offen; andere Requests derselben Session warten. GROK verbietet diese Queryfolge ausdrücklich. Keine Latenzwerte ohne Messung; kleine Scopes und getrennte Sessions sind Gegenfälle.
- Hauptagent Originalcode geprüft; [PHP Sessionende](https://www.php.net/manual/en/function.session-write-close.php) und [Sessionstart](https://www.php.net/manual/en/function.session-start.php) als Primärquellen am 08.09.2026 gegengeprüft. Details `ap07/report.md`.
- Umsetzung: Session nach abgeschlossener Auth-/Locale-/Sessionarbeit schließen; zweisitzungs- und gleichtabbezogene Vorher/Nachher-Messung, Fehler/Authverhalten unverändert. Keine neue Cache-/Sessionabstraktion erforderlich.

### SC-009: Autoimporter kann Application-Contentupdates dauerhaft als pending behandeln

- AP04, Zuverlässigkeit, **P2/K**, offen. `Powershell-MECM/mecm/mecm_autoimporter.ps1:355-395`, Common Distributionstatus-/Trackinghelper.
- Nach Start/Update speichert der Importer eine Baseline und verlangt zum Abschluss strikt `SourceVersion > Baseline`. Der konsumierte `SMS_ObjectContentExtraInfo.SourceVersion` bezeichnet laut [Microsoft WMI-Vertrag](https://learn.microsoft.com/en-us/intune/configmgr/develop/reference/core/servers/console/sms_objectcontentextrainfo-server-wmi-class) die Packagequellversion; [Microsoft Contentbetrieb](https://learn.microsoft.com/en-us/intune/configmgr/core/servers/deploy/configure/deploy-and-manage-content#update-content) erklärt die konstante Application-Packageversion und neue DT-Content-ID bei Updates. [Get-CMDistributionStatus](https://learn.microsoft.com/en-us/powershell/module/configurationmanager/get-cmdistributionstatus?view=sccm-ps) bestätigt den konsumierten Typ. Mehrquellenabgleich 08.09.2026; Hauptagent Code/Quellen gegengeprüft.
- Daraus folgt ein dokumentierter Vertragswiderspruch, kein real gemessener Providerlauf: bei unveränderter Quellversion bleibt erfolgreicher verteilter Inhalt pending und nachfolgende Collection-/Deploymentarbeit aus. Gegenfall Baseline -1 kann beim ersten Lauf funktionieren.
- Umsetzung: tatsächliche Application-/DT-Contentidentität statt unpassendem Versionsfortschritt verfolgen; zwei Contentupdates mit geändertem Inhalt und gleicher Packageversion im MECM-Lab, bounded Statusbeobachtung und unveränderte intent/uncertain-Sicherheit. Detail/Lab L8: `mecm-server/report.md`, `lab-protocol.md`.

### SC-010: Fehlende Vorlage und fehlendes install.ps1 gelten gemeinsam als aktuell

- AP04/AP05, Readinessfehler, **P2/K**, offen. `VirtuSphere-Common.ps1:663` gibt bei fehlender TemplateFile sofort true zurück; Autoimporter `:215-276` kann danach einen DT mit `install.ps1` anlegen, obwohl auch die Paketdatei fehlt.
- Vorhandene Paket-install.ps1 ohne Vorlage kann legitim sein; beide fehlend ist der bestätigte Kontrollflussfall. Normaler Installer liefert die Vorlage, aber späterer Verlust wird im Daemon nicht zur Sperre. Keine echte fehlerhafte MECM-Application erzeugt.
- Umsetzung am bestehenden Readinessowner; Missing/Missing, Missing/Present und Wiederherstellung testen, vollständiges ausgeliefertes Dateimanifest vor Import verifizieren. AP05 gegenprüfen lassen; Hauptagent Originalhelper gelesen.

### SC-011: Alter Worker kann nach Besitzverlust den VM-Zustand eines neuen Jobs ändern

- AP03/AP06/AP10, Nebenläufigkeit, **P1/R**, offen. `lib/deploy_worker_finish.php:94-102`, `lib/deploy_worker_vm_state.php:100-124`.
- Alter Job endet, neuer Job beansprucht dieselbe VM und setzt deploying. Alter Worker verarbeitet anschließend seinen Cancellation-/Ownershipverlustpfad. Der Cancelhandler führt den VM-Sweep auch bei terminalem alten Job aus; der VM-Writer prüft Lifecycle vorab und schreibt nur nach VM-ID, ohne aktuelle Job-/Attempt-/Ownershipbedingung. Dadurch kann die neue Ausführung auf failed umgefärbt werden; ein Callback zwischen Read und Write ist ebenfalls nicht per CAS geschützt.
- Astra xhigh und Hauptagent haben Handler/UPDATE gegengeprüft; sichere sequentielle Interleaving-Probe mit realen Ownern in `execution/ExecutionChainAuditTest.php` ausgeführt: Nachfolger running, dessen VM nach altem Abbruch failed/failed. Keine externe Mutation durch die Probe.
- Umsetzung: authoritative VM-Statewrite mit aktuellem Job-/Scopebesitz koppeln und im selben Lock/CAS durchführen; Fremd-/Terminal-/Callbackgegenfälle, Contract-/QA-Review. Ein Job-CAS allein schützt die separate VM-Tabelle nicht.

### SC-012: Cleanup-Diagnose kann nach terminalem Job eine Exception aus finally werfen

- AP03/AP07, Robustheit, **P2/R**, offen. `lib/deploy_worker_cleanup.php:49-55,70-79`, Joblog-Terminalguard wie SC-001; DB-Channel fängt nur DB-Exceptions.
- Bei ungeklärter Remoteausführung oder Cleanupfehler will finally eine Diagnose anhängen. Job ist bereits terminal; normaler Logappend wirft RuntimeException, die aus Cleanup entweicht. Korrekt entschiedener Ausgang wird von einem vermeidbaren Workerfehler begleitet. Remoteverzeichnis bleibt zum Schutz ungeklärter Ausführung bestehen.
- Hauptagent hat Originalcode samt ausdrücklichem Nichtwerfen-Kommentar gegengeprüft; QA-Fixture testet den non-SSH-Zweig. Fix: Diagnose an zulässigen bestehenden Kanal oder vor den Abschluss, Outcome nicht verändern; keine Lockerung des Terminalguards.

### SC-013: Supervisor-Neustart vergisst die persistierte Retrydeadline

Stand 10.09.2026: **implementiert, anhand des Codes geprüft, Laufzeitabnahme offen**.
Persistenter lokaler Zustand und konservative Ownership-Sperre sind in der
Umsetzungsrunde am Registeranfang beschrieben. Der Originalbefund bleibt:

- AP10, Robustheit, **P2/K**, offen; betrifft die separat aktivierbare Form `supervisor_v1`, keine Behauptung über aktuelle Produktionsaktivierung.
- ADR-0042 fordert, dass die gespeicherte `next_retry_at` einen Supervisorneustart überlebt. `lib/deploy_supervisor_loop.php:51-59` startet immer mit `deploy_supervisor_initial_state()`; Policy setzt Deadline und Neustartzähler auf null/0. Kein Restore der vorherigen Deadline vor dem ersten Tick. Dadurch kann Neustart die Eskalationspause umgehen.
- Astra xhigh, Hauptagent Code/ADR gegengeprüft. Runtime-Kill/Restart während Cooldown noch offen. Fix an bestehendem Supervisorstate-Owner; lokaler persistenter Restartvertrag darf die DB nicht zur Livenessentscheidung machen. Fehlerstimulation/Contract-/QA-Review.

### SC-014: Supervisor publiziert nach verlorener DB-Verbindung dauerhaft nicht mehr

Stand 10.09.2026: **implementiert, anhand des Codes geprüft, Laufzeitabnahme offen**.
Der eigene Publikationskanal mit frischem Reconnect ist in der Umsetzungsrunde
am Registeranfang beschrieben. Der Originalbefund bleibt:

- AP10/AP07, Zuverlässigkeit, **P2/K**, offen, ebenfalls `supervisor_v1`.
- `deploy_supervisor_publish()` (`lib/deploy_supervisor_loop.php:151-170`) ruft wiederholt `db()` auf. `lib/db.php:21-24` liefert dieselbe einmal aufgebaute mysqli; im Publishcatch wird kein Reconnect angefordert. Nach serverseitigem Verbindungsverlust bleiben spätere Publikationen auf der toten Verbindung, bis der Supervisor selbst neu startet. Kindüberwachung bleibt korrekt DB-unabhängig; Portalstatus wird aber dauerhaft stale/degraded.
- Hauptagent Code-Gegenprüfung; tatsächlicher DB-Restart unter Supervisor noch offen. Fix: begrenzter Reconnect nur im Publikationskanal, angemessen gedrosselte Erholung/Fehlermeldung; niemals das Kind wegen DB-Ausfall ersetzen. Fehlerstimulation mit wieder erreichbarer DB und unverändertem Kind-PID.

### SC-015: Worker-Reconnect ignoriert Stop während DB-Ausfall

Stand 10.09.2026: **implementiert, anhand des Codes geprüft, Laufzeitabnahme offen**.
Gemeinsamer unterbrechbarer Startup-/Idle-Retry und seine Systemaufrufgrenze
sind in der Umsetzungsrunde am Registeranfang beschrieben. Die folgenden
Messwerte gehören ausschließlich zum historischen Originalbefund:

- AP10/AP12, Robustheit, **P2/R**, offen. `lib/deploy_worker_loop.php:86-112`, `lib/maintenance_worker.php:86-112`, gemeinsamer Signalhandler `lib/worker_stop_signal.php`.
- Beide Reconnectschleifen warten im Loop unbegrenzt, berühren die Livenessdatei und rufen `sleep()` auf. Der TERM/INT/QUIT-Handler setzt lediglich das Stopflag; die Reconnectschleife prüft es nicht. Auch ein signalbedingt verkürzter einzelner Sleep beendet die Schleife nicht. Ein noch untätiger Worker bei unerreichbarer DB bleibt bis DB-Rückkehr oder SIGKILL bestehen.
- Astra xhigh und Hauptagent Originalcode-Gegenprüfung; Root misst drei isolierte PID-1-Container mit Netzwerk none und SIGQUIT: Idle-Helfer 326 ms/Exit 0, Deploy 3.333 ms/Exit 137, Maintenance 3.353 ms/Exit 137 bei 3-s-Grace, jeweils kein OOM. Logs zeigen empfangenes Stopflag und erneuten DB-Versuch. `stop-probes/results.json`, Einzel- und Live-Logs; Container entfernt. Erste Auditfixture scheiterte nur an gepuffertem READY-Output, vor dem Produktvergleich korrigiert und separat erhalten. Ein aktiver externer Arbeitsablauf wurde nicht stimuliert.
- Umsetzung am bestehenden Reconnect-/Stopowner: Stop als geordnetes Ende vor Arbeitsbeginn behandeln, aktive externe Arbeit weiterhin gemäß ADR-0042 beenden; Signaltest während Startup und Idle-Reconnect für beide Worker, Contract-/QA-Review.

### SC-016: Clientinstaller verteilt Content trotz fehlgeschlagener Manifestprüfung

- AP05/AP12, Vertragslücke, **P1/K**, offen. `Powershell-MECM/install-VirtuSphere-Clients.ps1:116-151,240-258`.
- Lokales Staging und tatsächlicher ContentShare unterscheiden sich oder lassen sich nicht vollständig vergleichen. Der Installer zählt dies als Blocker (`Write-Warn`), läuft danach aber bis Siteinitialisierung, Application-/DT-Arbeit und Distribution weiter. Erst der finale Exitcode meldet Fehler. Damit kann unbestätigter beziehungsweise alter Content verteilt werden.
- Sol high gefunden, Hauptagent hat Manifestloop, nichtwerfenden Warnpfad und anschließende MECM-Aufrufe im Original geprüft. Kein echter MECM-Lauf. Passender Share ist Gegenfall; falsche Definitionen haben eigene spätere Sperren, ersetzen diesen Contentguard jedoch nicht.
- Umsetzung: vollständige Contentabnahme vor erster MECM-Mutation erzwingen, bestehende Staging-/Ownershipverträge erhalten. In einem kontrollierten negativen Installerlauf null CM-Schreibaufrufe bei Manifestdrift fordern; realen DP-Contenthash prüfen. Contract-/QA-Review, SYSTEM-/MECM-Lab.

### SC-017: Serverinstaller nimmt die Paketvorlage nicht in den Rollback auf

- AP05/AP12, Robustheit, **P2/K**, offen. `Powershell-MECM/install-VirtuSphere-MECM.ps1:682-790` und Rollbackhelper.
- Servermodule werden vollständig gestaged, gehasht und rückrollbar aktiviert. `Package_Vorlage` wird anschließend direkt mit rekursivem `Copy-Item -Force` ins Liveziel kopiert; Templatefiles stehen weder im Stage-/Hashsatz noch in den Aktivierungs-/Backuplisten. Ein Copyabbruch oder späterer Installerfehler kann einen gemischten Vorlagenstand hinterlassen, obwohl Servermodule/Tasks zurückgerollt werden.
- Sol high und Hauptagent Originalgegenprüfung. Kein I/O-Fault injiziert; erfolgreicher vollständiger Copy ist Gegenfall. Fehlende Vorlage plus fehlende Paketdatei ist bereits SC-010 und wird nicht erneut gezählt.
- Umsetzung: Vorlage in bestehenden Installations- und Wiederherstellungsvertrag aufnehmen; gezielte Fehler zwischen zwei Templatefiles und nach Aktivierung, vollständige Alt-/Neuhashes prüfen. Contract-/QA-Review, Windows-Installerlab.

### SC-018: Expliziter Paket-Reparaturlauf kann einen alten Erfolgsmarker stehen lassen

- AP05, Zustandsanzeige/Recovery, **P2/K**, offen. `Powershell-MECM/Package_Vorlage/install.ps1:199-338`.
- Vorbedingung: Paketversion bereits erfolgreich erkannt, Teilskriptinhalt unter derselben Version geändert, `install.ps1` ausdrücklich erneut zur Reparatur gestartet. Hashvergleich führt das geänderte Kind erneut aus. Bei Fehler oder 1641 vor Abschluss weiterer Schritte bleibt der alte finale `Version`-Marker bestehen, obwohl der Kommentar/Abschlussguard Detection erst nach vollständigem Folgelauferfolg vorsehen.
- Der normale ConfigMgr-Erkennungszyklus startet bei schon positiver Detection gegebenenfalls überhaupt keine Reparatur; das ist keine Reproduktion dieses Ablaufs. Sol-Befund durch Hauptagent auf diesen konkreten Reparaturtrigger begrenzt und im Original gegengeprüft. Keine Registry-/ConfigMgr-Laufzeitevidenz. Neue Paketversion ohne alten Marker ist Gegenfall.
- Umsetzung: bestehenden finalen Marker vor tatsächlich nötiger Reparatur kontrolliert invalidieren, unveränderte erfolgreiche Hashskips erhalten; vorhandener Marker plus geändertes Kind mit Fehler/1641/0 und Restschritten im isolierten Windowslab prüfen. Contract-/QA-Review.

### SC-019: Jobfilter derselben Mission setzt eine VM-Teilauswahl auf alle zurück

- AP08/AP03, Bedienablauf/Scope, **P1/R**, offen. `portal/assets/deploy_form.js:14-31,63-77`, `lib/deploy_form_state.php:127-132` unter `Docker/WebAPI`.
- Operator wählt wenige VMs im Queueformular und wendet den Jobfilter für dieselbe Mission an. Gemeinsamer Navigationsträger verwirft stets `vm_ids[]`; jeder GET wird zusätzlich als Auswahl=null gelesen und rendert alle VMs angehakt. Ein folgender Submit kann somit die ursprünglich eingeschränkte operative Auswahl erweitern.
- Sol high und Hauptagent Originalcode-Gegenprüfung. Missionwechsel A nach B soll fremde IDs verwerfen und ist der vorhandene Browsergegenfall; er deckt den Filter A nach A nicht ab. Formular-/Liveblocker-Reproduktion bestätigt Verlust von Teilmenge und leerer Auswahl; Missionswechsel besteht. Null Deploy-POSTs.
- Umsetzung: gleicher Missionskontext bewahrt explizite Auswahl einschließlich bewusst leerer Auswahl; echter Missionwechsel erhält neue Vorgabe. Carrier und serverseitigen Zustandsdiskriminator gemeinsam korrigieren, keine per-field-Fallbacks. Browserprüfung Auswahl/Preview/Queuepayload sowie A-nach-B-Gegenfall; Contract-/QA-Review.

### SC-020: DE/EN-Hilfe verspricht lückenlose Clientmeldungen und zu weitgehende Erholung

Stand 10.09.2026: **implementiert, anhand des Codes geprüft, Laufzeitabnahme offen**.
Die Textkorrekturen und die 29 Einzelentscheidungen samt thematischem Restkorpus
sind in der U14-Umsetzungsrunde am Registeranfang verlinkt. Der folgende
Originalbefund bleibt erhalten:

- AP11/AP07/AP10, Doku/Hilfewahrheit, **P2/K**, offen. `Docker/WebAPI/lang/{de,en}/help_stack.php` Schlüssel `stack_a11_p1`, `stack_a11_li2`; `help_system_status.php` `clientphases_p2/p3`; korrespondierende Client-README-/Betriebsanleitung in `clients-docs/claim-matrix.md`.
- Hilfe verspricht, Meldungen gingen nicht verloren, würden später zugestellt und nur sichtbar „Fehlgeschlagen“ mit Detail sei ein echter Fehler. `Powershell-MECM/clients/VirtuSphere-Client-Common.ps1:344-372` sendet Phasenmeldungen best effort in einem Versuch, ohne Outbox/Retry. Frühe Fehler können ohne MAC-/Phasenevent enden; erfolgreiche Detection kann eine erneute Skriptausführung verhindern. Deshalb sind fehlende Meldungen weder Erfolg noch zugesicherte spätere Zustellung.
- Sol high und Hauptagent DE/EN sowie tatsächlichen Sender im Original gegengeprüft. Der verbindliche revisionsgebundene getinfo-ACK ist der engere retrybare Gegenfall und darf nicht auf Phasentelemetrie verallgemeinert werden. Pauschales „alles nachholen“ ignoriert zusätzlich die dokumentierten Uncertain-/manuellen Recoverygrenzen.
- Umsetzung: bestehenden Sollvertrag korrekt erklären, lokale Logs/MECM-Detection/Portaltelemetrie trennen, Bedingungen und konkrete nächste Prüfung nennen. Keine neue Outbox oder pauschale automatische Reparatur nur zur Rettung falscher Prosa einführen. Behauptungsmatrix, semantischer DE/EN-Abgleich, existierende Link-/Langguards; D/I/Q. Tatsächlicher Deliveryverlust bleibt eigener Labfall.

### SC-021: Vorhandenes Lastprofil misst unter dem VM-Label die Missionsliste

Stand 10.09.2026: **implementiert, anhand des Codes geprüft, Laufzeitabnahme offen**.
Das korrigierte Profil verlangt die exakte VM-Zielseite mit gültiger Mission
und Inhalts-/Mengennachweis ohne Redirect. Der Portalredirect bleibt erhalten.
Der folgende Originalbefund und seine Messungen gehören zum damaligen Stand:

- AP01/AP09, Test-/Messabdeckung, **P2/R**, offen. `tests/load/portal-read.js` GET `/vms.php` mit Tag `page:vms`; `Docker/WebAPI/portal/vms.php:23-28`.
- Der Request enthält keine `mission_id`. Der Portalhandler findet keine Mission und leitet korrekt zu `missions.php?type=missions` weiter. Der Lasttest folgt dem Redirect und akzeptiert jede angemeldete Seite anhand von `logout.php`. Somit kann ein grüner als VM-Latenz bezeichneter Messwert entstehen, ohne die VM-Liste oder ihre Relationsabfragen auszuführen.
- Hauptagent hat Lastprofil, Inhaltsassertion und Redirect im Original gegengeprüft. Die Inhaltschecks aller S/T/L-Warmups bestätigen den Redirect ohne Mission und die tatsächliche VM-Liste mit expliziter Mission. Tatsächliche VM-Liste mit expliziter existierender Mission ist der Gegenfall. Historische Werte werden dadurch nicht zu einer aktuellen VM-Baseline.
- Entscheidung: Portalcode beibehalten; Lastprofil und dessen fachliche Assertions korrigieren. Für das Audit eigene klar bezeichnete Variante mit synthetischer Mission, End-URL, VM-Inhalts-/Mengennachweis und unverändertem Last-/Schwellenschema vorbereiten. U13; Q-Review, identischer Vorher-/Nachher-Vergleich nur tatsächlich gleicher Seiten.

### SC-022: Ansible-Zugangstest verletzt seinen eigenen Audit-Kontextvertrag

- AP01/AP06/AP07, Zuverlässigkeit/Diagnose, **P2/R**, offen. `Docker/WebAPI/lib/credentials_actions.php:258-290`, `lib/audit_event_definitions.php:142`, `lib/audit_registry.php:213-243`.
- Der Test speichert seine Preflightevidenz und sendet danach `evidence_stored` an `credential.tested`. Das Event erlaubt nur outcome sowie component/ip; auch im globalen typisierten Feldregister fehlt evidence_stored. Der Normalizer wirft vor dem Auditinsert. Der äußere Throwable-Catch zeigt eine interne Auditfehlermeldung statt der vorgesehenen Ergebnisdarstellung, während die zuvor gespeicherte Evidenz bestehen bleibt.
- Zwei originale synthetische E2E-Fälle aus `crud-credential.spec.js:193` und `system-status-actions.spec.js:34` scheiterten; beide Traces zeigen im Fehlerflash `Audit context field is not allowed: evidence_stored`. Astra high hat die Ursache an tatsächlicher Trace und Originalcode geprüft, Hauptagent Context/Registry/Catch gegengeprüft. Keine sechs Fehler allein aufgrund sechs roter Tests gezählt; diese zwei Fälle teilen einen Ownerfehler. Der Cadence-Fall `crud-credential.spec.js:377` hat eine andere Fixture-/Locatorursache. Der zusätzliche Originalnormalizer-Aufruf reproduziert den Schlüsselverstoß; der registrierte Gegencontext besteht. Ein neuer Browser-POST nach Produktkorrektur bleibt offen.
- Entscheidung: **Code korrigieren**, den typisierten Auditvertrag und Caller gemeinsam abgleichen. Das diagnostisch sinnvolle Persistenzergebnis korrekt typisieren/erlauben oder bewusst am Caller weglassen; Normalizer nicht pauschal permissiv machen. Bestehender Auditpresenter bewahrt die erwartete kompatible Beschreibung, deshalb kein bloß veralteter Stringmatcher. Erfolgs-, Fehler- und inzwischen veraltete Testevidenz müssen Ergebnisflash plus genau passenden Audit tragen. C/D/Q, Regression mit tatsächlichem Portal-POST.

### SC-023: Fehlende Inventarauswertbarkeit wird als fehlendes Inventar erklärt

Stand 10.09.2026: **implementiert, anhand des Codes geprüft, Laufzeitabnahme offen**.
Darstellung und additive Quellenprojektion unterscheiden die Evidenzzustände;
der fachliche Evidenz-/Freigabeschutz bleibt unverändert. Vorbereitete Unitfälle
ersetzen keine neue Browser- oder Inventarabnahme. Der Originalbefund bleibt:

- AP07/AP08/AP11, Status-/Textwahrheit, **P2/K**, offen. Verbleibende Darstellungsabweichung im Bereich des früheren E4/SS-04/05; kein erneuter Befund gegen den inzwischen korrigierten Evidenzschutz. `Docker/WebAPI/lib/system_status_esxi_panels.php:325-348`, `lib/esxi_inventory_deviation_report.php:37-103`, `lang/de/system_status.php:209`, `lang/en/system_status.php:209`.
- Der Renderer setzt fehlenden auswertbaren Abweichungszähler mit nicht vorhandenem Inventar gleich. Tatsächlich kann schon eine konfigurierte Quelle ohne qualifizierten Artnachweis den Vergleich verhindern, obwohl Inventarkarten und gespeicherte Namen vorhanden sind. Dann sind sowohl „noch kein Inventar vorhanden“ als auch die Zusage, ein erster erfolgreicher Abruf genüge, zu weitgehend. Die Originaltraces der VLAN-/Ampelfehler dokumentieren die Vorbedingungen; der Hauptagent hat Bericht, Renderbedingung und beide Sprachtexte gegengeprüft.
- Der Negativnachweis über alle Quellen bleibt richtig. Benutzeraufgabe ist, die fehlende Voraussetzung für den Vergleich zu verstehen und am richtigen Inventar zu beheben. Unterscheidbare Gründe und ein passendes Ziel vermeiden erfolglose wiederholte Abrufe der bereits qualifizierten Quelle.
- Entscheidung: **Evidenzcode beibehalten; Renderer, DE/EN-Text und gegebenenfalls Hilfe angleichen**. Für einen konkreten Quell-/Artlink muss die Projektion den tatsächlichen Blocker liefern; keine Quelle aus einem aggregierten unbekannten Status erraten. U14; C/D/I/Q. Abnahme: kein konfiguriertes Inventar, vorhandenes unqualifiziertes Inventar, teilweise qualifizierte Mehrquellenlage, historisch vollständiger und aktuell vollständiger Nachweis. Noch kein ergänzender Browserlauf dieser Abnahmematrix.

### SC-024: Unvollständige Guard-Fixtures prüfen die falsche Fehlerursache

- AP01/AP13, Testzuverlässigkeit, **P2/R**, offen. `scripts/test-guards.ps1:781-850`, `Docker/qa-ansible/module-contract.sh:34-49`, `scripts/lib/check/runtime.ps1:299-302`.
- Die Fixturefamilie kopiert zwei inzwischen notwendige Python-Lockprüfer nicht. Bereits die unmutierte Fixture scheitert deshalb mit `no-ssot`; Tool-Exit 2 wird Gate-Exit 1. Zwei Fälle erkennen die fehlende gewünschte Diagnose und melden unproven. Vier weitere Fälle 69/70/71/74 melden mit bloßer Exitprüfung aus falschem Grund proven. Zero-Match benötigt zusätzlich die ausgelassene `Ansible/requirements.yml`.
- Vier isolierte Läufe unter `guard-diagnosis/`: Originalfixture bereits rot, vollständige unmutierte Fixture grün, zwei Mutationen erreichen die gewünschten probe-incomplete-/probe-stale-Diagnosen. Root und Astra prüfen Originalcode und Logs; `final-counterreview.md`. Keine Behauptung eines defekten fachlichen Modul-/Lockguards.
- U16: vollständige gemeinsame Fixtures, grüne unmutierte Kontrolle und spezifische Diagnose-IDs aller sechs Fälle. Anschließend vollständiger kanonischer Harness. Guards und Fehlerklassen beibehalten.

### SC-025: VM-Relationen erzeugen linear zusätzliche Datenbankabfragen

Stand 10.09.2026: **implementiert, anhand des Codes geprüft, Laufzeitabnahme offen**.
Requestlokales Batching beim bisherigen Owner und Gleichheits-/Queryregressionen
sind vorbereitet. Die nachfolgenden Zahlen sind historische Messwerte und
keine Vorherbaseline des korrigierten Messaufbaus:

- AP08/AP09, Performance, **P2/R**, offen. `Docker/WebAPI/lib/repo/vms_legacy.php:7-23`; Consumer `portal/vms.php:185`, `lib/deploy_view_model.php:34`, `lib/deploy_blockers.php:128`, `lib/deploy_network_blockers.php:24`.
- Ein originaler `getVMs()`-Aufruf erzeugt eine VM-Abfrage plus drei Relationsabfragen je VM. MySQL-Sessionzähler belegt bei 10/40/1.000 VMs genau 31/121/3.001 SELECTs; Einzellauf 18,9/79,5/1.785,6 ms. `qol-perf-ops/query-diagnosis/{S,T,L}.json`, separat von Lastläufen erhoben. Gültige Deploy-/Blockerpfade rufen den Loader zusätzlich mehrfach auf; frühe Abbrüche und leere Listen sind Gegenfälle.
- T/L-Leseläufe überschreiten die vorhandene 800-ms-VM-p95-Schwelle. Keine vollständige kausale Aufteilung ihrer HTTP-Latenz; T/L-EXPLAIN nutzt den vorhandenen Missionsindex ohne Filesort. Root und Astra bestätigen Queryzähler und Originalcode, keine Behauptung eines fehlenden Index oder einer Produktionskapazität.
- U13: drei Relationen requestlokal für die genaue VM-Menge bündeln, Ergebnisform/Multiplizität/Sortierung/Identität erhalten. Kein vervielfachender Mehrfachjoin, kein globaler Cache, keine Wiederverwendung über autoritative Prewrite-/Claimgrenzen. Fachliche Gleichheit und identische S/T/L-Vorher-/Nachhermessung abnehmen.

### SC-026: Restore-Gate ignoriert die gemeldete alternative Prüfwurzel

Stand 10.09.2026: **implementiert, anhand des Codes geprüft, Laufzeitabnahme offen**.
Umsetzung und vorbereitete Quellwahlregression sind in der Umsetzungsrunde
am Registeranfang beschrieben. Folgende Zeilen bewahren den Originalbefund
und seine historische Evidenz:

- AP12, Prüfisolierung, **P2/R**, offen. `scripts/check.ps1:84-87,197-198`, `scripts/lib/check/runtime.ps1:197-209`, `scripts/restore_test.sh:29`, `scripts/lib/check/gates-release.ps1:72-76`.
- Der Runner meldet/exportiert `VIRTUSPHERE_CHECK_ROOT`, startet den Prüfer jedoch bewusst aus seinem eigenen Skriptverzeichnis. Restore wechselt ohne Berücksichtigung des Overrides zum Verzeichnis seines Skripts und liest dort Backups. Erster Lauf meldet Auditkopie als Root, prüft tatsächlich Stand `20260820-132905` statt synthetischem `20260909-075024` und wird grün.
- Evidenz `qol-perf-ops/first-restore-drill-gate.log`, `first-restore-drill.json`; Originalcode durch Root/Astra gegengeprüft. Vorhandenes lokales Altbackup wurde eingelesen, seine Daten nur in temporärer Restoreumgebung verwendet; Herkunft nicht als Produktion oder QA behauptet. Originalarchive unverändert, kein Inhalt/Schlüssel im Register. Dieser Lauf zählt nicht als synthetischer Nachweis.
- U17: Restore-Datenroot nach bestehendem CHECK_ROOT-Muster auflösen, Manifest/Archive/Mounts kohärent dort halten. Zwei isolierte Wurzeln mit unterscheidbaren synthetischen Backups müssen die tatsächlich ausgewählte Quelle beweisen. Nicht pauschal alle Prüfer aus Fixtures laden. Auditworkaround ist der identische Runner direkt aus der Quellkopie; das behebt den Produktvertrag noch nicht.

### Aussagekraft der sechs ursprünglichen Browserfehler

Triage durch Astra high, Bericht `e2e-triage/report.md`, gezielte Originaltraceausschnitte und zwölf Rohbeleg-Hashes daneben. Ergebnisse am unveränderten HEAD; keine Produkt- oder Testkorrektur ausgeführt.

| Fehlgeschlagener Fall | Belegte Ursache / Entscheidung | Offene gezielte Abnahme |
|---|---|---|
| Zugangstest `crud-credential:193` | SC-022; Evidenz gespeichert, Auditcontext verworfen | Echter POST mit zulässigem Event und passender Ergebnisnachricht nach Fix |
| Aktualisierungstakt `crud-credential:377` | Seed ohne Revision/Generation erzeugt korrekt unbekannte Evidenz und zwei Treffer der Stilklasse | Gebundenen Begin-/Record-Owner verwenden, danach Alterung und eindeutige Taktzeile prüfen |
| Gemischte Create-Ergebnisse `deploy-create-progress:201` | Seed scheitert bereits an Identitätsconstraint; zusätzliche Outcome-/Fehlerdetailmängel | Vollständig legale fünf Ergebniszustände, anschließend Zähler/Diagnose/Uncertain-Sperre; kein Beleg für SC-002/012 aus diesem roten Test |
| Systemstatus-Zugangstest `system-status-actions:34` | Derselbe SC-022; vorangehende Status-/Link-/Mobilprüfungen wurden erreicht | Beide UI-Einstiege desselben Handlers nach Fix |
| VLAN Cancel/Confirm `system-status-actions:167` | Vorherige Fixture lässt zweite ungeprüfte Quelle zurück; Union korrekt nicht auswertbar | Isolierter Fall, mit ungeprüfter zweiter Quelle und mit vollständig qualifizierter Union; Guard bewahren |
| Abweichungsgrammatik `system-status-ampel:324` | Eigener Seed ohne Querynachweise erzeugt keine qualifizierte Art, allgemeiner Erfolg ersetzt sie nicht | Gültige Queryevidenz und explizit 0/1/n in DE/EN; „Not checked“ ist kein Grammatik-Pass |

Die vier Fixture-/Locatorfehler erfordern nachgelagerte Testkorrekturen, keine Lockerung der Produktverträge. Zwei weitere rote Tests teilen einen Produktfehler. Der Gesamtlauf bleibt 258 pass/6 fail; die Triage macht ihn nicht grün.

## Performance, Self-Healing und dokumentierte Behauptungen

### Ergänzende tatsächliche Laufzeitbelege vom 09.09.2026

Hauptagent führte nach dem Nutzungslimit der Subagenten die vorbereiteten, zusätzlich durch Astra gegengeprüften Proben selbst aus. Exklusiver synthetischer QA-Stack, Worker für DB-Proben pausiert und in `finally` entsprechend vorheriger Laufzustände wieder gestartet. Kein externer SSH-/MECM-/ESXi-Aufruf. Diese Ergebnisse ersetzen die früheren Vorbereitungsstände der jeweiligen Befunde, nicht deren weiterhin offene externe Abnahmen.

| Nachweis | Tatsächliches Ergebnis / Aussagegrenze |
|---|---|
| `execution/repro-live.log`, `repro-junit.xml`, Exit 1 | 8 Tests, 79 Assertions, 2 pass/6 fail, 1,676 s. Die Tests verlangen das Sollverhalten, daher bestätigen die gezielten roten Assertions Fehler; keine grüne Produktsuite behauptet. |
| SC-001, Fälle 2/3 | Vollständiger Reaper wirft Terminalappend-Exception; Job bleibt running beziehungsweise cancelling, Einheit running, async-ID erhalten. Fall 1 ohne Create besteht. |
| SC-002, Fall 4 | Echter Partialabschluss liefert `kind=deploy_job`, Retry blockiert mit `retry_result_protocol_error`. Fall 5 mit unaufgelöster Einheit bewahrt den spezifischen Uncertain-Blocker. |
| SC-012, Fall 6 | Terminaler Cleanup wirft tatsächlich `Cannot append to a terminal deploy job` aus dem geprüften finally-Pfad. |
| SC-007, Fall 7 | Neuer Queuewrite akzeptiert und neuer Job tatsächlich geclaimt, obwohl alte Einheit uncertain bleibt. Kein zweiter entfernter Create ausgeführt; externe Doppelwirkung weiterhin Labfrage. |
| SC-011, Fall 8 | Nachfolgerjob bleibt running, alter Abbruchhandler setzt dessen VM dennoch auf failed/failed. |
| SC-006, `ap06/repro-live.log`, Exit 0 | VM-CPU des ersten Writers verloren, Missionsnotiz des ersten Writers verloren, Ausgangs- und gespeicherter Token jeweils exakt gleiche Sekunde. Nächste Sekunde weist den alten Token korrekt ab. Nur sessionlokale MySQL-Zeit gesetzt und zurückgesetzt; eigene Rows bereinigt. |
| SC-019, `qol-perf-ops/sc019-browser-result.json` und Live-Log | Teilmenge [484] inklusive Liveblockerrequest wird nach A->A zu [484,485], ausdrücklich leere Auswahl ebenfalls zu beiden; A->B liefert ausschließlich [486]. Null Deploy-POSTs; synthetische Fixture bereinigt. |
| SC-022, `ap06/audit-context-live.log` | Originalnormalizer wirft mit echtem `evidence_stored`-Context die erwartete Ausnahme; registrierter Gegencontext passiert. Ergänzt die beiden originalen Browsertracebelege. |
| SC-021, AP09 `s-smoke-1` | 35/35 Inhaltsprüfungen, keine HTTP-Fehler. Request ohne Mission erreicht Missionsliste, explizite Mission erreicht tatsächliche zehn VM-Zeilen. Das Auditprofil schließt die Messlücke; dauerhafter Lasttest noch unverändert. |

Zwei nicht nachgewiesene Guardfälle bleiben getrennt: der erneute kanonische `guard-harness`-Lauf endet nach 766,2 s mit 103 proven/2 unproven/0 infra und Gate-Exit 1. Vollständiger vom Runner erzeugter Log nachträglich gesichert unter `ap01/guard-harness-gate-logs/guard-harness.log`; JSON `ap01/guard-harness.json` ist maßgeblich. Exit 0 des fehlerhaften äußeren Wrappers ist kein Testresultat. Der ursprüngliche Integration-Gesamtlauf bleibt ohne finales JSON und ohne Freigabe.

Tatsächliche AP09-Ausführung: `qol-perf-ops/execution-results-ap09.md` mit Roh-/Normalisierungsartefakten ersetzt den Vorbereitungsstand. 21 serielle Einheiten abgeschlossen, keine konkurrierenden Tests. S/T/L je drei 60-s-Leserampen nach Inhaltswarmup: VM-p95 340–387/973–1.046/8.339–12.293 ms. T/L überschreiten die bestehende 800-ms-Schwelle; alle abgeschlossenen Inhaltschecks und aufgezeichneten HTTP-Ergebnisse erfolgreich. L hat nur 12–33 Samples je Endpoint/Lauf und 29–30 unterbrochene Iterationen; p99 ist explorativ, keine Produktionskapazität. Drei Schreiblasten je 121 vollständige Iterationen, 484/484 Checks, keine HTTP-Fehler/Drops; markierte HTTP-p95 unter 1 s. Querydiagnose separat 31/121/3.001 SELECTs, SC-025. Verifizierte Kaltstarts, vollständige FPM-/Lockzerlegung, große Joblog-/Healthlast und externe Zeiten bleiben offen.

Ursprünglicher Session-A/B zurückgezogen: Astra und Root fanden auch im als unabhängig bezeichneten Lauf gleiche IDs. Zwei `summary.json` enthielten irrtümlich Authwerte in `setup_data`; Root entfernte ausschließlich diese Felder, Gleichheitsbefund und frühere Hashes stehen in `qol-perf-ops/session-identity-correction.json`. Die übrigen Last-/Queryergebnisse sind davon unabhängig.

Korrigierter Sessionnachlauf nach Nutzerfortsetzung: `session-recheck-live.log` 4/4 pass einschließlich Cleanup; `qol-perf-ops/session-recheck-summary.json` plus beide `*-verified`-Laufverzeichnisse. Same/equal=true und Independent/equal=false ausdrücklich geprüft, Auth bleibt im VU-Speicher, kein setup_data-Export. Je 14 Paare/29 erfolgreiche Checks, keine HTTP-Fehler oder abgebrochenen Iterationen. Dashboard-p50 4.000 ms bei gleicher gegenüber 271 ms bei getrennter Sitzung, p95 4.366/353 ms; Blocker-p50 jeweils rund 4,1 s. Diagnose stützt SC-008, trennt FPM-/Query-/Sessionanteile aber nicht kausal vollständig; SC-008 bleibt K mit zusätzlicher Laufzeitdiagnose. Keine Nachheroptimierung oder Produktionskapazität behauptet.

AP12 Restore: korrekter synthetischer Nachlauf `restore-series-isolated-live.log`, `qol-perf-ops/restore-drill.json`, `restore-drill-gate.log` besteht in 51,9 s, Gate 1/1 pass, Exit 0. Geprüfter Backupstand `20260909-075254`, 35 Tabellen, 52 Migrationen, Schema-/Appuserprüfung, neue und vorbestehende Test-Credential entschlüsselbar, falscher APP_KEY abgewiesen, Health mit DB ok/degraded, Portal-Login und Machine-API-Ablehnung. Directory-Konvergenz meldet ausdrücklich not configured; keine reale AD-Abnahme. Windows prüft POSIX-Dateimodi nicht verbindlich. Eigene QA-Credential entfernt, keine vs-restore-Container mehr vorhanden. Fehlgeleiteter erster Lauf separat SC-026, nicht als synthetische Abnahme gezählt.

Self-Healing-Matrix vollständig in `execution/report-ap10.md`, ergänzt durch AP04/AP05:

| Störung | Bestehender Owner / sichere Aktion | Grenzen und offene Abnahme |
|---|---|---|
| DB zeitweise weg | Worker-DB-Channel/Reconnect beobachtet und verbindet neu, Fileliveness bleibt unabhängig | Kein Kindersatz wegen DB; Stop im untätigen Reconnect SC-015, Publishreconnect SC-014; gemessene Recoverydauer offen |
| Createantwort fehlt | Create-Owner sucht/pollt dieselbe async-ID innerhalb bestehender Budgets | Keine neue Mutation aus fehlender Antwort ableiten; nach Budget/Identitätszweifel uncertain/manuell, SC-007 muss auch neue Queue sperren |
| Worker verwaist | Reaper und Create-Konvergenz im bestehenden Transaktionsowner | SC-001/011 vor weiterer Automatik beheben; kein blindes VM-Recreate, gemischte Charge testen |
| Supervisorkind ohne Fortschritt | Ein Prozessowner, abgestufter Stop/Kill, bestätigtes Reap vor Ersatz | Überlebendes Kind endet manual; Budget muss Neustart überleben SC-013, keine zusätzliche Restartinstanz |
| MECM/API vorübergehend fehlerhaft | Vorhandene bounded Aufrufretries/Task-Scheduler und Membershipjournal | Revision/Provenienz bewahren; quarantänisiertes Journal manuell klären SC-003/004/005, echte Providerkonvergenz offen |
| Clientnetzwerk/Storage unterbrochen | Vollständiger Plan, eigene Adapteränderungen zurücknehmen bzw. stabile Diskjournaloperation fortsetzen | Fremdzustand nicht entfernen, mehrdeutige Diskidentity vor Mutation stoppen; SYSTEM-/Crashlab offen |

Keine zusätzliche automatische Reparatur ist umgesetzt. Auth-/Zertifikats-/Konfigurationsfehler, fremde Identität, ungelöste Createwirkung und beschädigte Ownershipevidenz benötigen begrenzte Beobachtung oder manuelle Klärung gemäß zuständigem Vertrag.

Konsolidierte historische Behauptungsmatrix AP11; detaillierte Fundstellen/DE-/EN-Vergleiche in `clients-docs/claim-matrix.md`.
Die Tabelle und ihre damalige Prüftiefe bewahren den Originalbefund. Den
Quellenstand nach U13/U14, die Einzelentscheidungen und den tatsächlich
bearbeiteten Restkorpus beschreibt der [U14-Korpusbericht](2026-09-10-u14-claim-review.md):

| Aussage / Quelle | Soll-/Codeabgleich | Urteil / Korrekturquelle |
|---|---|---|
| 5/5 trennt akzeptierten getinfo-ACK von späteren Phasen, help_missions DE/EN | ACK ist alleiniger Writer nach vollständigem Snapshot | Kernzuordnung richtig; Zustellung der HTTP-Antwort an Client ist bei Responseverlust keine garantierte Folgerung |
| Alle Clientmeldungen kommen später nach, help_stack DE/EN | Send-VsPhase hat einen best-effort-Versuch ohne Outbox | falsch, SC-020; vorhandenen Vertrag ehrlich erklären |
| Nur sichtbares Failed ist echter Fehler / keine Phase bedeutet kein Boot, help_system_status und help_missions DE/EN | Früher Abbruch oder Reportverlust kann ohne Event enden | falsch, SC-020; lokale Logs/Detection/Telemetrie trennen |
| Hostname benennt stets um und rebootet, help_system_status DE/EN | Domain-Skip und bereits korrekter Name ohne Rename/Reboot | unvollständig/falsch als unbedingte Zusage; Bedingungen am Textowner |
| Client staticip wechselt VLAN, help_system_status DE/EN | Client setzt Adaptername/IPv4/DHCP/DNS/Route; ESXi-Portgruppe liegt bei Ansible | falsch zugeordnet; SC-020-Präzisierung ohne neue Clientfunktion |
| Direkter SSH-/SFTP-Transport aktiv, Durable Runner dormant, deploy-chain/offline-install | Require-/Claimpfad legacy_v1; per-VM-Create darin aktiv | richtig; alte „bis 14B“-Präsenssätze zeitlich präzisieren |
| Manifest vor CM-Mutation garantiert, MECM-/Clientanleitung | Installer läuft bei Abweichung weiter | falsch, SC-016; Produktfix und dokumentierte Garantie gemeinsam abnehmen |
| Serverfiles/Registry/Tasks rückrollbar, PS-README | Tatsächlicher Stage-/Backupsatz vorhanden | für genannten Satz richtig; Paketvorlage separat SC-017 |
| Dauerhafte Journalsperre und vollständiges Nachholen, PS-/Betriebsanleitung | Quarantäne beim zweiten Start ignoriert, Encoding/ACK-Reihenfolge inkonsistent | falsch/unvollständig, SC-003/004/005 |
| Millisekundenleerlauf, PS-README | Vollständige Inhaltshashes; kein realer Providerbenchmark | nicht nachgewiesen, AP09-Labmessung |

Prüftiefe: Clients/Installer, ACK/Phasen, Recovery-/Transportwahrheit und die genannten DE/EN-Hilfethemen vertieft. Übrige Hilfe-/Portaltexte, vollständige Installations-/Go-live-/HTTPS-/AD-Anleitungen und alle Standortbefehle sind ausdrücklich Stichprobenrest beziehungsweise Laborabnahme. Sprachparität und Linkguards beweisen deren fachliche Wahrheit nicht.

## Szenarioabdeckung und offene Abnahmen

Ausgewählte kritische Pfade ohne neuen Befund, vom Hauptagenten im Original gegengeprüft (K, aktueller HEAD):

| Szenario | Gegenprüfung / Ergebnis | Nachweisgrenze |
|---|---|---|
| ACK / Reset / erneuter ACK | Revision vor Dedup; gleicher aktiver ACK idempotent, alter Rollout abgewiesen; Statehelper öffnet keine zweite Transaktion innerhalb raw BEGIN | `ap06/report.md`; phpunit-full der Integration pass, kein GesamtendJSON; realer Client offen |
| updateDevice / reportMembership | Revisionslock und Domainwrite in derselben Repo-Transaktion, Tombstone im Commit; keine exit-Anweisung im Closure | Senderwirkung SC-005 bleibt unabhängig davon fehlerhaft |
| MAC V2 | Mission/Job/Runtime/Handle/VM/Interfaces unter gemeinsamen Sperren; Rejectobservability erst nach Rollback; aktiver semantischer Replay eng begrenzt | Reale verzögerte ESXi-Rückmeldung offen |
| Letzter lokaler Administrator | Autorisierung/CSRF und geordnete Adminrows unter FOR UPDATE schützen verbleibenden lokalen Administrator | Aktuelle Race-/Wiretests in AP01 auswerten |
| Create-Erfolgsidentität | `repo/deploy_create_identity.php:46-185`: Worker/Token/Epoch und aktive Jobstatus, Unit und VM unter Lock; fremde UUID abgewiesen; VMbindung und Unittransition gemeinsam | Keine Behauptung über reale ESXi-Identität; SC-011 betrifft anderen VM-Writer |
| Create-Statusdatei | `Ansible/createVMStatus-ESXi_playbook.yml:124-160`: eigene Dateievidenz vor/nach async_status; missing oder widersprüchlich ergibt keinen Erfolg | Aktuelle Async-Fixture in Fast pass; reales Lost-reply-Lab offen |
| Zweites Supervisorkind | `deploy_supervisor_process.php:48-95`: offenes Handle verhindert start; tatsächlicher Prozessstatus und Reap vor Freigabe | Innerhalb eines Supervisorprozesses; Restartbudget separat SC-013 |
| JSON-Joblog | Sessionfreigabe nach Auth/Locale vor DB-Arbeit; begrenzte Cursor-/Ringansicht und Singleflight im Browser | Gesonderter großer Joblog-Laufzeit-/Skalierungsvergleich offen; Liveblocker separat SC-008 |
| Client-getinfo / Hostname | Readback vor Snapshotpublikation, revisionsgebundener ACK vor SetupState; Rename/Detection/finished vor verstecktem Reboottimer und 1641 | Original `client_getinfo.ps1:173-198`, `client_hostname.ps1:106-125`; verlorene ACK-Antwort und echte SYSTEM-/Rebootwirkung offen |
| Clientnetzwerk / Datenträger | Vollständiger Netzwerkplan vor Apply, Adapterrollback prüft eigenen geschriebenen Zustand; Diskmutex, gesamte Blockerliste und persistierter Intent vor RAW-Mutation | Original `client_staticip.ps1:295-338`, `Set-VMDisksOnline.ps1:81-109,225-328`; Mehradapter-/Storage-Crashlab offen |

Diese Stichproben ersetzen keine Gesamtfreigabe. Detaillierte Szenariomatrizen: `ap06/report.md`, `ap07/report.md`, `execution/report-ap03.md`, `execution/report-ap10.md`, `mecm-server/matrix.md`. Laufzeitlücken werden in AP12 je Zielumgebung mit Aktion, Sollzustand, Artefakt und Cleanup konsolidiert.

## Entscheidungen und Umsetzungspakete

Zusätzlicher Nutzerauftrag während des Audits: je Widerspruch ausdrücklich entscheiden, ob der Code das dokumentierte Soll erfüllen muss, ob der vorhandene Code beibehalten und Doku/Hilfe berichtigt werden sollen, oder ob beides erforderlich ist. Maßstab sind sichere, funktionsfähige Abläufe, Daten- und Identitätserhalt, verständliche Zustände und begrenzter Wiederanlauf. Dokumentation ist weder automatisch die Wahrheit noch nachträglich eine Entschuldigung für einen Codefehler. Die folgenden Empfehlungen gehören zur nachgelagerten Umsetzung; sie ändern noch keinen Produktcode.

### Sollentscheidung: Code oder Doku/Hilfe

| Befund / Aussage | Entscheidung | Begründung und Abnahme |
|---|---|---|
| SC-001/012: Reaperabschluss und Cleanup | **Code korrigieren**, sichere Garantie beibehalten | Ein terminaler Logguard ist richtig; seine Aufrufer müssen ihn respektieren. Reaper darf keine ganze Charge zurückrollen, Cleanup keinen entschiedenen Ausgang beschädigen. Vollständige Übergangstests statt Textabschwächung. |
| SC-002/007/011: Retry, Uncertain, alter Besitzer | **Code korrigieren**, Hilfe nach verifiziertem Verhalten präzisieren | Ungeklärte externe Wirkung und verlorener Besitz dürfen nicht durch neue Queue/VMwrites umgangen werden. Belegbare Partialergebnisse sollen sinnvoll fortsetzbar bleiben. Diese Schutzanforderungen sind fachlich notwendig. |
| SC-019: Filter erweitert Auswahl | **Code korrigieren** | Operatorauswahl ist Arbeitsauftrag. Ein bloßer Filter darf ihn nicht erweitern; eine Warnung in der Hilfe würde den fehlerhaften Ablauf nicht beheben. Same-Mission- und Missionswechsel-Gegenprobe. |
| SC-003/004/005: Membershipjournal/Provenienz | **Code korrigieren und Grenzen dokumentieren** | Identitätsbytes und Ownership müssen erhalten bleiben. Beschädigte Evidenz braucht dauerhafte Klärung, keine leere Neustartannahme. Auch nach Fix keine pauschale Zusage „alles heilt automatisch“. |
| SC-006: konkurrierende Änderungen | **Code korrigieren** | Eine dokumentierte Konflikterkennung muss echte Änderungen erkennen. Gleiche Sekunde ist kein zulässiger Grund, fremde Arbeit unbemerkt zu überschreiben. Versionierungsvertrag statt Hinweis, langsamer zu speichern. |
| SC-008: Session während read-only Abfragen | **Code eng korrigieren**, Messwerte danach dokumentieren | Auth-/Locale-/Sessionarbeit abschließen, dann Session freigeben. Kein längerfristiger Cache und keine Schwächung von Rechten oder Scopeprüfung erforderlich. |
| SC-009: Applicationcontent als Packageversion | **Code an den tatsächlichen Providervertrag anpassen**, Doku danach angleichen | Mehrere Herstellerquellen widersprechen der vorausgesetzten Versionssteigerung. Warten auf unmöglichen Fortschritt wird nicht durch Beschreibung korrekt. Echte DT-Contentidentität/DP-Evidenz im MECM-Lab abnehmen. |
| SC-010/016/017: fehlender/unbestätigter Content und Template-Rollback | **Code korrigieren**, Liefer-/Abbruchgarantie erhalten | Vor der externen Mutation muss vollständiger richtiger Content feststehen. Die bereits gewollte Staging-/Hashgrenze muss auch Vorlage und fehlgeschlagene Manifestabnahme umfassen. Doku nennt bis zur Behebung die reale Teilmutationsgrenze. |
| SC-018: Marker im expliziten Reparaturlauf | **Code korrigieren und Reparaturtrigger präzisieren** | Ein alter Erfolgsmarker darf einen tatsächlich fehlgeschlagenen neuen Reparaturstand nicht als fertig darstellen. Daraus folgt keine automatische CM-Reparatur bei positiver Detection; Anleitung muss den konkreten Einstieg erklären. |
| SC-013/014/015: Supervisorbudget, Publishreconnect, Idle-Stop | **Code korrigieren** | Bounded Neustart, frische Statuspublikation nach DB-Rückkehr und geordneter Stop ohne aktive Arbeit sind sinnvolle vorhandene Betriebsziele. DB-Ausfall bleibt unabhängig von Kindliveness; aktive Remoteeinheit darf gemäß Vertrag fertiglaufen. |
| SC-020: verlustfreie Phasentelemetrie / nur Failed echter Fehler | **Codevertrag beibehalten, Doku/Hilfe korrigieren** | Best-effort-Diagnose darf Netzwerk-/Diskarbeit nicht blockieren. Keine neue Outbox nur zur Rettung einer falschen Garantie. Fehlende Meldung bleibt unbekannt; Anleitung führt zu Detection, MECM und lokalem Log. Ein echter Bedarf an garantierter Historie wäre eine gesonderte Featureentscheidung. |
| 5/5 bedeutet Client hat die ACK-Antwort erhalten | **Code beibehalten, Text präzisieren** | Der Server kann seinen Commit bestätigen, aber den Empfang seiner Antwort durch den Client nicht daraus beweisen. ACK-Responseverlust bleibt sicher retrybar. 5/5 bedeutet serverseitig akzeptierter ACK nach geschriebenem Snapshot. |
| Hostname immer Rename/Reboot; staticip wechselt VLAN | **Code beibehalten, Hilfe korrigieren** | Bereits korrekter Name/Domain-Skip sind sinnvolle Gegenfälle; Windows-Netzwerk und ESXi-Portgruppe haben unterschiedliche Owner. Kein zusätzlicher Reboot oder Hypervisorwrite, nur damit der Text stimmt. |
| Durable Runner dormant / alte „bis 14B“-Sätze | **Aktivierungscode beibehalten, Runbook zeitlich bereinigen** | Aktiver per-VM-Create unter direktem SSH ist von der gesonderten remote_v1-Aktivierung zu trennen. Ein Dokumentwiderspruch autorisiert keine Aktivierung ungeprüfter Remote-Recovery. |
| Mehradapteränderung bei späterem Fehler | **Vorhandenen per-Adapter-Vertrag zunächst beibehalten, Grenzen erklären** | Globales Zurückrollen kann bereits richtige Netzzustände erneut stören. Vollständiger Preflight, Schutz fremder Werte, fehlende Gesamt-Detection und konvergenter Retry sind die passende Ausgangslage; SYSTEM-Faultlab muss das belegen. |
| DP-gruppenspezifische Zusage bei globalem Aggregat | **Doku an tatsächlich beobachteten Geltungsbereich angleichen; gezielte Codeerweiterung nur bei fachlichem Bedarf** | Globaler Status darf nicht als Messung genau einer Gruppe bezeichnet werden. Eine echte gruppenspezifische Abnahme braucht belastbare Zielmitgliedschaft und Contentidentität, keine bloß umbenannte Anzeige. |
| Millisekunden-/Self-Healing-Pauschalversprechen | **Texte begrenzen, Code nur anhand belegter Engpässe/Fehler ändern** | Dauer, Frische und Erfolg hängen von Bestand und Fremdsystemen ab. AP09 liefert aktuelle lokale Messungen; externe Aussagen bleiben bis Labor offen. |
| SC-021: VM-Lastmessung folgt Redirect zur Missionsliste | **Lasttest korrigieren, Portalredirect beibehalten** | Eine fehlende Mission soll zurück zur Liste führen. Der Test muss mit gültiger Mission die beabsichtigte VM-Seite samt Inhalt nachweisen; Zahlen und Profilbeschreibung dürfen keinen anderen Request behaupten. |
| SC-022: Zugangstest scheitert nach Ergebniswrite am Auditformat | **Code korrigieren**, bestehende Testforderung erhalten | Ein normaler Test muss seinen definierten Audit und Ergebnistext erreichen. Caller und geschlossenes typisiertes Schema abgleichen; rohe Formatfehlermeldung und fehlender Audit sind kein sinnvoller Produktvertrag. |
| SC-023: keine qualifizierte Inventarauswertung heißt angeblich kein Inventar | **Evidenzschutz beibehalten, Darstellung und Text korrigieren** | Fehlender Nachweis ist ein anderer Zustand als fehlender Bestand. Nur tatsächlich belegte Gründe und passende Quell-/Artziele nennen; DE/EN und Hilfe gemeinsam prüfen. |
| Vier fehlgeschlagene Browser-Fixtures | **Tests korrigieren, Produktconstraints und Evidenzschutz beibehalten** | Ein Test muss gültige Fachzustände herstellen. Identität, Revision/Generation und Querynachweise nicht lockern; den beabsichtigten Zustand vor UI-Assertions beweisen. |
| SC-024: Guard-Fixtures scheitern vor beabsichtigter Probe | **Tests korrigieren, Modul-/Lockguards beibehalten** | Vollständige Ausgangsfixture und spezifische Fehlerdiagnosen beweisen die Schutzwirkung. Bloßer Fehlerexit darf keine falsche Ursache als proven ausgeben. |
| SC-025: linearer Relationsqueryaufwand | **Code gezielt optimieren, Zahlen danach neu belegen** | Requestlokales Batching erhält die fachliche Ergebnisform. Keine durch globale Caches veralteten Queueentscheidungen; vorhandene Indizes nicht als fehlend darstellen. |
| SC-026: Restore ignoriert alternative Prüfwurzel | **Restore-Datenroot korrigieren, QA-Doku präzisieren** | Das geprüfte Backup muss aus der beauftragten Quelle stammen. Ein grüner Lauf über einen anderen Stand ist keine gültige Abnahme. |

### Weitere fachliche Entscheidungen

Vorläufige Empfehlungen, keine implementierten Produktänderungen:

- D-01 Membership bei externer Entfernung: empfohlen ist, weiterhin ausdrücklich im Portal gewünschte eigene Membership unter aktueller Revision wiederherzustellen und die neue eigene Provenienz zu erhalten. Fremde/manuelle Regeln nicht übernehmen oder löschen. Soll eine externe Entfernung dauerhaft gelten, braucht sie eine ausdrückliche Deselektion/Freigabe statt zufälliger Wirkung der Applyreihenfolge (SC-005).
- D-02 Clientphasen nach Rolloutreset: kurzfristig laufübergreifende Arrival-Order klar darstellen und Lifecycle nicht ändern. Wenn aktuelle Rolloutdiagnose benötigt wird, additive Revisionszuordnung planen, historische/unzugeordnete Events erkennbar erhalten und alten Wirepfad akzeptieren. Kein stilles Uminterpretieren vorhandener Events und kein automatischer Defekt allein aus pauschalem Regelwortlaut.
- D-03 Supervisorstart ohne lesbares Budget: persistente lokale Wiederanlaufgrenze festlegen. Fehlende DB darf niemals einen lebenden Worker zum Ersatz freigeben; Cooldown auch bei Prozessneustart erhalten (SC-013).
- D-04 Provider-Katalog: ausgelassene Objektart versus erfolgreich bestätigte leere Objektart eindeutig dokumentieren. Keine stille Änderung des bestehenden Massretirevertrags.
- D-05 Clientnetzwerk: begrenzten Rollback pro Adapter mit anschließendem konvergentem Retry dokumentieren oder eine andere Mehradapter-Recoveryanforderung ausdrücklich entscheiden. Ein späterer Adapterfehler macht die bereits richtige Änderung eines früheren Adapters nicht automatisch zum Produktdefekt; globale Atomizität ist bisher nicht nachgewiesen.

Weiterhin unbestätigte Hypothesen (keine zusätzlichen Defekte): komplexe falsche JSON-Feldtypen könnten einzelne Machine-Endpoints als 500 statt 400 verlassen (AP06, isolierter Wirefall offen); HTTP-200 mit syntaktisch gültigem, fachlich falschem ACK könnte lokale MECM-Journalquittung zu früh löschen (AP04, aktueller PHP-Fehlerpfad verwendet 4xx); allein gestartete Paketvorlage mit ungültigem InstallationBehaviorType fällt auf HKLM zurück, der reguläre Autoimporter verwirft diese Eingabe vorher (AP05, direkte Eintrittsbedingung entscheiden). Keine davon ist ein gemessener Produktionsvorfall.

Konsolidierte Umsetzungspakete nach AP13-Gegenprüfung und Laufzeitnachweisen. Reihenfolge nach Schadensfolge und Abhängigkeit; Korrekturen sind ausdrücklich nachgelagert. Reviewkürzel: C Vertragsreview (GROK/Machine-/PowerShell-/Migration-/Deployvertrag), D Driftprüfung, I i18n-Review, Q kanonische passende QA. Keine bestehende Schutzregel für einen grünen Test aufweichen.

| Paket / Vorrang | Befunde / zuständige Owner | Konkretes Ziel und Abhängigkeit | Regression, Review und Abnahme |
|---|---|---|---|
| U01 / P1 | SC-007; Queue/Stagger, Createownership, Worker-Preflight | Offene historische Createwirkung auch für frische Aufträge sperren; dieselbe fachliche Freigabe wie Retry, überlappende VM-/Hostidentität exakt | Terminal-alt/uncertain + frische Queue/Claim, unabhängiger Scope, neuere Freigabe; C/Q und ESXi lost-reply/late-visibility |
| U02 / P1 | SC-011; worker_vm_state, finish, reaper | VM-Änderung mit aktuellem Job-/Attemptbesitz und Lifecycle-CAS atomar koppeln; Reaperpostcommit einbeziehen | Alter Cancel/Reaper -> neuer Claim -> alter Write; neuer Callback darf nicht überschrieben werden; C/Q |
| U03 / P1 | SC-001/012; job_maintenance, job_worker log owner, worker_cleanup | Zulässige letzte Diagnose und Terminalisierung korrekt ordnen; atomaren Reapercommit und nichtwerfendes Cleanup erhalten | Ganzer Reaper mit gemischter Charge/offenen Createunits, Nicht-Create-Gegenfall, terminaler Cleanup; C/Q |
| U04 / P1 | SC-002; job_retry, job_input, job_queue, create recovery | Create-Summary von MAC-Ergebnis unterscheiden; richtige Folgeaktion verify_skip/create statt Export; U01-Fence zuvor erhalten | Tatsächliches partial Create/Full -> Retry -> Folgezeilen, MACpartial nur failed IDs, unknown/uncertain weiterhin gesperrt; C/Q plus ESXi-Identityabnahme |
| U05 / P1 | SC-019; deploy_form.js, deploy_form_state.php | Same-Mission-Navigation bewahrt Teilauswahl/leer; echter Missionswechsel verwirft alte IDs | Browser Filter A->A/A->B, Preview, Queuepayload; C/Q, I falls Textänderung |
| U06 / P1 | SC-016; Clientinstaller, ClientPackaging | Contentmanifestdrift beendet Lauf vor erster MECM-Mutation; kein best-effort Weiterverteilen | Negatives vollständiges Installermock mit null CM-Writes, echter Share-/DP-Hash; C/Q, MECM/SYSTEM |
| U07 / P2 | SC-003/004; MembershipJournal | Quarantäne als dauerhafte Klärungsgrenze, UTF-8 explizit; vorhandenen Journalowner verwenden | Zwei Starts nach korruptem Journal; Mehrfachroundtrip Unicode unter PS5.1/7; C/D/Q, MECM-Wiederanlauf |
| U08 / P2 | SC-005; Membershipplan/apply und Provenienzrepo | Nach D-01 externe Regel und lokale Ownership konsistent; Revision/CollectionID-Fence erhalten | Senderplan -> Applyberichte -> echte DB-Deltas, stale und andere CollectionID als Gegenfälle; C/Q plus MECM |
| U09 / P2 | SC-009/010/017; Autoimporter/Common/Serverinstaller | Application-/DT-Contentidentität richtig verfolgen, Missing/Missing sperren, Template in Stage/Hash/Rollback aufnehmen | Zwei Contentupdates bei gleicher Packageversion, Templatekombinationen und Copyfaults; C/D/Q plus MECM/Windows |
| U10 / P2 | SC-018; Package_Vorlage/install.ps1 | Finalen Detectionmarker bei tatsächlich nötiger Reparatur invalidieren, korrekte Hashskips bewahren | Existierender Versionmarker + geändertes Kind/Fehler/1641/0, mehrstufiger Folgelauf; C/Q plus Windows/CM-Evaluation |
| U11 / P1, parallel zu U01-U03 | SC-006; VM-/Missionspersistenz und Portalformular | Echten Versionsvertrag einführen; Portal erwartet Token, bewusster Legacy-Opt-out getrennt | Zwei stale Writer mit unverändertem Ausgangstoken in derselben DB-Sekunde und normale Folgeänderung, Migration/Kindzeilen/Claims; C/D/I/Q |
| U12 / P2 | SC-013/014/015; Supervisorpublish/state, Workerreconnect | Persistentes begrenztes Restartbudget, eigener Publishreconnect, Stop während untätigem DB-Warten | Supervisorrestart im Cooldown, tote Testverbindung -> frischer Heartbeat bei gleichem Kind-PID, SIGQUIT im Startup/Idle-DBausfall; C/Q |
| U13 / P2 | SC-008/021/025 und AP09; Liveblocker/session, Listenrepo, Lastprofil | Valide VM-Lastmessung, frühe Sessionfreigabe, requestlokales Relationsbatching; Frische-/Rechte-/Identitätsverträge erhalten | Explizit bewiesene gleiche/getrennte IDs und identische S/T/L-Profile vorher/nachher, Ergebnisgleichheit und Queryzähler; C/Q und Performancegegenprüfung |
| U14 / P2 | SC-020/023, AP11-Behauptungsmatrix, aktive Doku/DE/EN-Hilfe und Statusrenderer | Falsche/unvollständige Behauptungen nach Sollentscheidung am zuständigen Textowner berichtigen; Inventargründe wahrheitsgemäß unterscheiden | Links/Werte via bestehende Guards, fachlicher Ablauf anhand Verhalten; D/I/Q, externe Versprechen erst nach Labor |
| U15 / P2 | SC-022; credentials_actions, audit_event_definitions, audit_registry | Zugangstestcontext mit typisiertem Eventvertrag konsistent machen; Persistenz-/Resultgrenzen erhalten | Portal-POST für Erfolg/Fehler und nicht mehr aktuelle Evidenz, Audit/Flash zusammen prüfen; C/D/Q |
| U16 / vor abschließender Integrationsabnahme | SC-024 und vier E2E-Fixture-/Locatorfehler; test-guards, crud-credential, deploy-create-progress, system-status-actions, system-status-ampel | Vollständige Guard-Fixtures mit grüner Kontrolle und konkreten Diagnose-IDs, gültige Ownerzustände/Quellenisolation; richtige Takt-/Grammatikzweige | Minimale serielle Nachweise, danach vollständige Integration/Harness auf korrigiertem Stand; C/Q, keine Constraint-/Evidenzlockerung |
| U17 / P2 | SC-026; restore_test.sh und Restore-Gatevertrag | Explizite Prüfwurzel für Backupauswahl, Konfiguration und Mounts berücksichtigen, kein Rückfall auf lokalen Altbestand | Zwei synthetische Wurzeln mit unterscheidbaren Archiven, End-to-End-Runner prüft exakt beauftragten Stand; C/D/Q |

U01/U02/U03 sind unabhängige Owneraufgaben mit gemeinsamer abschließender Nebenläufigkeitsprüfung; U04 darf die Grenzen von U01 nicht umgehen. Performance-, Self-Healing- und Dokumentationsmaßnahmen bekommen keine ungeprüfte allgemeine Refactoringfreigabe.

## Konsolidierter Auditbericht und offene Abnahmen

Auditumfang: AP00 bis AP13 am unveränderten Produktstand `68c3ea08cbfa183797f6237be6d8c6a68709e6c2`. Geprüft wurden Portal, DB, Machine APIs, Worker und Ansible sowie MECM-Serveraufgaben, Clientphasen, beide Installer und ausgelieferte Pakete. Hinzu kommen SSoT-/Datenflüsse, Nebenläufigkeit, Performance, Self-Healing und die fachliche Wahrheit ausgewählter DE/EN-Hilfe-/Dokumentationsbehauptungen. Die Abschlussaussage bezieht sich auf dieses Audit mit ausdrücklich begrenzten Nachweisen; sie ist keine Release- oder Standortfreigabe und keine vollständige Prüfung jedes aktiven Textes.

### Ergebnis und Priorität

26 bestätigte Befunde mit Evidenz R oder K, davon sieben P1 und 19 P2. Unbestätigte Hypothesen und fachliche Entscheidungen sind oben getrennt und erhöhen diese Zahl nicht. Alle Produktkorrekturen bleiben offen. U01–U17 beschreiben Owner, Änderung, Abhängigkeiten, notwendige Regressionen und Reviews.

Vorrang haben Schutz vor erneuter ungeklärter Createwirkung (SC-007/U01), fremden VM-Writes nach Besitzverlust (SC-011/U02), blockierter Reaper-/Cleanup-Konvergenz (SC-001/012/U03), falschem Partial-Retry (SC-002/U04), Auswahlverbreiterung (SC-019/U05), ungeprüfter Contentverteilung (SC-016/U06) und verlorenen gleichzeitigen Änderungen (SC-006/U11). U01/U02/U03 können an getrennten Ownern bearbeitet werden, benötigen aber gemeinsame Nebenläufigkeitsabnahme; U04 muss die Uncertain-Grenze bewahren.

Danach folgen Membershipjournal/Provenienz und Paketcontent (U07–U10), geordneter Wiederanlauf (U12), Session-/Querykosten und gültige Lasttests (U13), wahre Status-/Hilfetexte (U14), Auditcontext (U15), belastbare Testfixtures (U16) und korrekte Restore-Prüfwurzeln (U17). U16 ist Voraussetzung einer glaubwürdigen abschließenden Integration, keine Erlaubnis zum Lockern von Produktconstraints.

### Gesicherte Prüfergebnisse

| Bereich | Ergebnis | Aussagegrenze |
|---|---|---|
| Fast | 31/31 pass, Exit 0 | Aktueller Auditstand; ersetzt externe Wirkung nicht |
| Ursprüngliche Integration | Gates 1–36/38 pass; E2E 258 pass/6 fail | Kein GesamtendJSON, keine vollständige Lane-Freigabe; zwei Browserfehler SC-022, vier Fixture-/Locatorfehler |
| Guard-Harness | 103 proven/2 unproven, Exit 1; vier ergänzende Diagnosen | SC-024: vier weitere proven können auf falscher Frühfehlerursache beruhen; keine 103 semantisch gültigen Beweise behaupten |
| DB-/Browserproben | Acht DB-Tests/79 Assertions mit zwei Kontrollen und sechs Defektreproduktionen; drei Konflikt-, drei Auswahl-, zwei Auditcontextfälle | Rote Sollassertionen sind Defektnachweise, keine bestandene Produktsuite; keine externe Mutation |
| Performance | S/T/L je drei Leserampen, drei Writes, Query-/Ressourcenmessung | T/L über bestehender VM-Latenzschwelle; L mit wenigen Samples/unterbrochenen Iterationen; keine Produktionskapazität |
| Korrigierte Sessions | Vier Einheiten pass, je 14 Requestpaare, Identitäten explizit richtig, keine Authwerte im Summary | Gültige kleine Diagnose; frühere Sessionmessung bleibt verworfen, keine vollständige kausale Zerlegung |
| Worker-Stop | Idle-Helfer Exit 0/326 ms; beide Worker bei DB-Ausfall Exit 137 nach 3-s-Grace | Drei Fälle gemessen, zwei Defekte reproduziert; keine drei erfolgreichen Produktstopps |
| Synthetischer Restore | 1/1 pass, Exit 0, 51,9 s; aktuelles Backup, Schema, Appuser, Verschlüsselung, Login/API/Health | AD nicht konfiguriert, POSIX-Modi unter Windows nicht abschließend geprüft; erster Altbackup-Lauf ist SC-026-Repro |
| Release-Auswahl | Secret-Scan 29,9 s und npm-Audit 16,8 s, 2/2 pass, Exit 0 | `release-selected.json` und `release-selected-logs/`; historische Secret-Allowlist bleibt wirksam, npm-Bericht null Advisories; keine gesamte Release-Lane |
| Abschlussprüfung | Sieben Manifestblöcke vollständig; Doku-/ENUM-/PHP-/Bounds-/CSP-Gates 6/6 pass, Exit 0, 63,2 s | Drei ursprüngliche Screenshot-Baselinehashes fehlen; fachliche Textvollprüfung wird durch diese Guards nicht bewiesen |

### Performance, QoL und sichere Erholung

Die Querydiagnose misst 31/121/3.001 SELECTs für 10/40/1.000 VMs. Vorhandener Missionsindex wird genutzt; drei relationale Einzelabfragen je VM verursachen vermeidbaren Aufwand. Relationsweises Batching muss rohe Identitäten, Multiplizität und Ergebnisform erhalten. Autoritative Queue-/Claim-/Prewriteentscheidungen dürfen nicht aus älteren View- oder Liveergebnissen übernommen werden. Der korrigierte Sessionvergleich misst Dashboard-p50 von rund 4.000 gegenüber 271 ms bei gleicher/getrennter Sitzung und stützt die enge Sessionfreigabe nach Auth/Locale. Weitere FPM-/DB-/Renderanteile bleiben ungetrennt.

Die wichtigsten QoL-Korrekturen schützen konkrete Bedienaufgaben: Filter bewahren eine ausdrücklich gewählte VM-Teilmenge; Teilerfolge bieten eine fachlich zulässige Fortsetzung; Status-/Recoverytexte unterscheiden fehlenden Nachweis von bestätigter Leere oder Fehler. Reine Darstellung darf niemals eine Uncertain-, Identitäts-, Revisions- oder Rechtebarriere umgehen.

Self-Healing bleibt begrenzt und evidenzgebunden: DB-Kanäle erneut verbinden, eigene nachgewiesene Aufgaben unter aktuellem Besitz fortsetzen und nach Budgetüberschreitung klar eskalieren. Fehlende externe Antwort ist kein Auftrag zur erneuten VM-Erstellung. Beschädigte Ownershipjournale, unklare externe Createwirkung und Auth-/Zertifikats-/Konfigurationsfehler dürfen nicht durch Neustartschleifen oder grüne Statusetiketten verdeckt werden. Die Matrix oben und `execution/report-ap10.md` benennen erlaubte Aktionen und Abbruchgrenzen.

### Code oder Doku/Hilfe

29 konkrete Behauptungen wurden in `clients-docs/claim-matrix.md` eingeordnet: acht richtig, zehn falsch, neun unvollständig, zwei nicht nachgewiesen. Das sind überprüfte Aussagen, keine Vollzählung aller Texte und keine 29 zusätzlichen Befunde. Die zentrale Sollentscheidungstabelle legt für jeden Widerspruch die Korrekturrichtung fest.

Code korrigieren, wo sinnvolle Sicherheits-/Funktionsverträge verletzt werden: Ownership, Revisionen, Auswahl, Recovery, Manifestprüfung und Restorequelle. Bestehenden Codevertrag erhalten und Texte korrigieren, wo die Hilfe unbegründet mehr verspricht: lückenlose best-effort-Telemetrie, fehlende Phase als Bootbeweis, unbedingter Hostname-Reboot, VLANwechsel durch Windows-IP-Konfiguration oder empfangene ACK-Antwort als Bedeutung von 5/5. Gültige Produktconstraints bleiben auch bei fehlerhaften E2E-/Guard-Fixtures bestehen. Für garantierte Telemetriehistorie, globale Mehradapter-Atomizität oder gruppenspezifische DP-Evidenz ist bei Bedarf eine eigene fachliche Entscheidung erforderlich.

### Ausdrücklich offene Abnahmen

| Offene Abnahme | Voraussetzung und konkretes Ziel | Zuständiges Protokoll / Umsetzung |
|---|---|---|
| P1-Korrekturen und vollständige QA | U01–U06/U11 umsetzen, U16-Fixtures richtigstellen, dann ganze Integration/Harness und relevante Regressionen auf neuem Stand | U-Pakete, `e2e-triage/report.md`, Execution-Fixtures; kein grüner Ersatz durch Triage |
| MECM-Provider/Distribution | Wegwerf-Site/DP, echte Application-/DT-Contentidentität, Membership-Reconciliation, Rollout-/Callbackrevision, installierte Taskdefinitionen und Teilausfälle | `mecm-server/lab-protocol.md`, `qol-perf-ops/lab-protocol-ap12.md` LAB-MECM-01/-02 |
| Windows-Client/SYSTEM/Installer | Wegwerf-VM, echte Detection/Enforcement, ACK-Verlust, 1641/Reboot, mehrere Adapter, stabile Datenträgeridentität, Crash-/ACL-/Share-/Copyfaults | `clients-docs/report-ap05.md` (15 Labfälle), AP05-Phasen-/Installermatrix und `qol-perf-ops/lab-protocol-ap12.md` LAB-SYSTEM-01 |
| ESXi/Ansible-Wirkung | Isolierter Host, lost reply/late visibility, genaue Name-/UUID-Identität, laufende/verwaiste Async-ID, Konkurrenz/Cancel, negative Netz-/Zertifikatsfälle | `execution/report-ap03.md`, `execution/report-ap10.md` und `qol-perf-ops/lab-protocol-ap12.md` LAB-ESXI-01; kein doppelter Create zur Diagnose |
| Standort-AD/LDAPS | Testverzeichnis, Zertifikate/Failover, Revision, Sitzungsentzug und Wiederfreigabe nach Restore | LAB-AD-01; synthetische Directory-Prüfung ersetzt Standortabnahme nicht |
| Air-Gap und vollständige Supply Chain | Sauberer netzgesperrter Zielhost, vollständiges Bundle samt SBOM/CVE-/Digest-/Mountprovenienz; aktuelle ganze Release-Lane | LAB-AIRGAP-01; SBOM/image-cve/offline-bundle und zusätzliche Browserengines in dieser Audit-Auswahl nicht neu ausgeführt, keine Infrastrukturunverfügbarkeit erfunden |
| Erweiterte lokale Performance | Verifizierte Cold-/Warmtrennung, repräsentative Hardware und Zeitbudgets, FPM-/Lock-/Transaktionszerlegung, große Joblog-DOM-/Hidden-Tab- und 5/s-Healthmessung, externer Durchsatz | `qol-perf-ops/execution-results-ap09.md`, U13 mit identischen Vorher-/Nachherprofilen |
| Breitere UI-/Textabnahme | Vollständige DE/EN-/No-JS-/Rechte-/Fokus-/Visualmatrix und verbleibender aktiver Text-/Installations-/HTTPS-/AD-Befehlskorpus | U14/U16, Claimmatrix und Coveragebericht; keine neuen Zielbilder im Audit |
| Restore-Betriebsgrenzen | POSIX-Dateirechte, echte AD-Neufreigabe, Standortkonfiguration und migrationsfähiger älterer unterstützter synthetischer Stand | U17 und Backup-Runbook; Altbackup-Fehlleitung nicht nachträglich als beauftragter Altstandnachweis werten |

### Erhalt, Gegenprüfung und Abschlusszustand

Hauptagent pflegt allein dieses Register. Sol/Astra wurden intern mit abgegrenzten Quellen- und Artefaktaufträgen delegiert; QA und Performance lagen jeweils exklusiv bei einem Owner. `final-counterreview.md` prüft Originalcode und Rohbelege einschließlich Gegenfällen, `final-coverage.md` die Paketabdeckung. Fehler im eigenen Session-/Restoreaufbau wurden offen korrigiert und deren ungültige Schlussfolgerungen zurückgenommen.

Es wurden keine Produktdateien oder dauerhaften Tests geändert, keine Zielbilder aktualisiert und keine Produktfixes/Commits vorgenommen. Nur dieses zentrale Register ist eine getrackte Auditänderung; die fremde ungetrackte `docs/audits/2026-09-08-admin-workflow-feature-plan.md` bleibt erhalten. Detailartefakte und synthetische Backupkopien liegen lokal unter dem gitignorierten `qa-artifacts/system-chain-audit/20260908-full/`; sie werden durch einen Gitcommit des Registers nicht automatisch mitgesichert. Keine geheimen Werte im Register.

Finaler Dateiabgleich: sieben persistierte Blöcke/3.284 Manifestpfade, 3.280 bytegleich, eine beabsichtigte Änderung dieses Registers. Drei ursprüngliche MISSING-Sentinels sind keine fehlenden Produktdateien: Screenshots/Oberfläche1.jpg bis Oberfläche3.jpg existieren und aktuelle Hashes sind gesichert; ohne Baselinehash wird ihre Gleichheit zum Auditbeginn nicht behauptet. Keine weitere Quellabweichung. `source-preservation-blocks.jsonl` und `source-preservation-final.{json,md}` halten diese Trennung fest. Das weiterhin bearbeitete Register ist die ausdrückliche Ausnahme, sein Zwischenhash kein unveränderlicher Endhash.

Abschlussgates: `final-doc-drift.json`, `final-doc-drift-live.log` und `final-doc-drift-logs/`, alle sechs pass; `git diff --check` sauber, keine UTF-8-Ersatzzeichen im Register. Quellberichte bleiben datierte Zwischenaufnahmen; für später ausgeführte Reproduktionen, Evidenzpromotionen und abgeschlossene Läufe sind dieses Register und die jeweiligen Rohartefakte maßgeblich.

QA-Abschluss: zwölf zuvor laufende Container des exakten Projekts virtusphere-qa erfolgreich gestoppt; `qa-final-stop.json`. Keine laufenden QA-Container, keine verbliebenen vs-restore-/vs-audit-Container beim Endabgleich. Volumes/Daten und lokale Belege erhalten, andere Projekte unverändert. Kein Auditprozess zur Fortsetzung erforderlich; der nächste Arbeitsauftrag ist die priorisierte Umsetzung und die genannten Abnahmen.
