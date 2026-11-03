# Unabhängige Gegenprüfung U13/U14

10.09.2026, HEAD `aa3daacb82ab947c1d9c4c275b118f2afe73c73e` plus vorhandener
uncommitteter Arbeitsbaum. Astra High prüfte ausschließlich Dateien und Gitdiffs
manuell. Kein Lint, PHPStan, Test, Guard, Build, QA-, Browser-, Last- oder
Benchmarklauf; keine Container, Restores oder externen Systeme wurden gestartet
oder verändert. Kein Commit, Push, Merge oder Deploy. Dieser Reviewer schreibt
ausschließlich diesen Bericht; die Produkt- und Testkorrekturen gehören ihren
jeweiligen Ownern.

## Prüfumfang und Maßstab

Gelesen wurden der konkrete U13/U14-Auftrag, AGENTS/GROK, einschlägige Regeln
unter `.claude/rules`, Auditplan/Register und die ursprüngliche
AP11-Behauptungsmatrix samt `clients-docs/report-ap11.md`. Historische Zahlen
und Abnahmen gelten nur für ihren alten Stand.

U13: Produktdiff von `portal/deploy_blockers.php` und `lib/repo/vms_legacy.php`,
vorheriger Relationsalgorithmus, Auth/Bootstrap/Locale, Blockerentscheidung
und JSON-Presenter; die neuen PHP-Relations-/Sessionverträge und der neue
E2E-Sessiontest. Lastprofile `portal-read.js`, `session-pair.js`,
`portal-read-cold.js`, SQL-Profile/Cleanup und U13-Messplan wurden manuell
gelesen. Die früh gesicherten Originaldateien bleiben Grundlage eines späteren
Vorherstands; ein HEAD-Checkout allein enthält die fremden Änderungen nicht.

U14: Inventarbericht, Evidenz-/Leerrenderer und neue synthetische Unitfälle,
geänderte DE/EN-Passagen zu Clientphasen, ACK, Hostname, IP/VLAN und Recovery;
fokussierte Änderungen in Server-/Client-README sowie MECM-/Deploy-Runbook.
Der gesonderte Sol-Korpusbericht und Root-QA-Beitrag besitzen ihren eigenen
Umfang. Dieser Bericht behauptet keine zusätzliche Vollprüfung aller aktiven
Dokumente, aller Hilfeschlüssel oder aller Standortbefehle.

## Im Review gefundene Korrekturen

| Priorität | Datei / Befund | An Owner gemeldete Korrektur / Status |
|---|---|---|
| P2 | `tests/e2e/specs/deploy-blocker-session.spec.js`: stdin-Timeout begrenzte erst die Zeit nach erfolgreichem `LOCK TABLES`; ein bei der Lockannahme wartender PHP-Helfer konnte den Readinessfehler überleben. | Root ergänzte `SET SESSION lock_wait_timeout = 5` vor dem Lock sowie begrenztes Warten auf den Prozessausgang auch im Readinessfehlerpfad. Diese Änderung wurde manuell nachgelesen. Ein nicht bestätigter Ausgang bleibt ausdrücklich Infrastrukturfehler und verlangt Klärung vor erneuter Stacknutzung. |
| P2 | Derselbe E2E-Test: `?lang=en` und spätere englische Seite konnten auch ohne persistierten Localewechsel bestehen, weil der frische Context keine abweichende Ausgangssprache verlangte. | Root ergänzte explizites `de-DE`, eine anfängliche deutsche Seite und die anschließende englische Seite ohne Localequery. Im Quelltext nachgelesen. |
| P2 | `tests/load/fixtures/profile.sql`: ungültiges Profil wurde nicht an sämtlichen Kind-/Job-Inserts geprüft. Bereits vorhandene Zeilen mit dem daraus abgeleiteten Präfix konnten trotz ungültigem Profil neue Interfaces, Disks, Paketrelationen oder Joblogs erhalten. | Loadowner ergänzte `@vs_valid` an allen VM-/Kind-/Job-Inserts; Zielname und Ziel-ID bleiben bei ungültigem Profil NULL. Im Quelltext nachgelesen. |
| Nachweisgrenze | E2E-Pingantwort `200/ok` beweist Lockfreigabe und CSRF-Annahme, aber allein keinen gespeicherten verlängerten Ablaufzeitpunkt. | Root begrenzte den U13-Codebericht auf diese tatsächliche Assertion und führte den fehlenden Persistenzvergleich ausdrücklich auf. |
| Textpräzisierung | `help_system_status.php` DE/EN: ein geplanter Hostname-Reboot folgt im Code auch dann, wenn wegen fehlender Report-MAC kein Abschlussversuch möglich war. „Nach gesendeter Abschlussmeldung“ suggerierte einen zu starken Nachweis. | Textowner formulierte den bedingten Berichtsversuch vor dem geplanten Neustart ausdrücklich und nannte die fehlende Report-MAC. DE/EN nachgelesen; keine Änderung am Clientverhalten. |
| Textpräzisierung | Neue Backuptexte in `help_settings.php` und `help_stack.php` DE/EN versprachen Retention vollständiger Läufe/Tripel. Der gelesene Originalowner `scripts/backup.sh` sortiert und entfernt aber jede der drei Artefaktarten getrennt. | Schlussstand DE/EN nachgelesen: neueste Dateien je Artefaktart, zusammengehöriges vollständiges Tripel für Restore erforderlich. Keine neue Backupfunktion zur Rettung eines Textversprechens. |

## U13: Produktentscheidung

Codekorrektur ist hier sachgerecht. Die frühe Sessionfreigabe folgt dem
vollständigen `current_user()`-Pfad einschließlich Lazy-Expiry, Terminal-Logout,
Directory-Verifikation/Retry und dem expliziten Benutzer-RBAC. Bootstrap lädt
und persistiert die Locale davor. Die nachfolgenden Blocker/Warnungen erhalten
die lokale Benutzerprojektion; `deploy_blocker_json()` reicht sie weiter an
`can($permission, $user)`. Es folgt kein impliziter Auth-/Sessionaufruf und kein
CSRF-/Flashschreiber. Die bestehende 405-Behandlung bleibt davor. Diese Aussage
ist Kontrollflussprüfung, keine AD-, Ablauf- oder Konkurrenzmessung.

Das Batching bleibt im vorhandenen Repositoryowner. Es leitet seine IDs aus
der missionsgefilterten Elternabfrage ab, lädt drei Relationen getrennt in
500er-Parameterlisten und hängt sie an dieselben Eltern. Die zusätzliche
Package-Gruppierungsspalte wird entfernt. Datentypen, Schlüsselreihenfolge,
Null/Leer, leere Listen und vorhandene Sortierung bleiben im Quellfluss
erhalten; `package_name` besitzt im gelesenen Schema einen Unique-Key.
Kein globaler, statischer oder anfrageübergreifender Cache wurde eingeführt.
Die rechnerische SELECT-Anzahl lautet `1 + 3 * ceil(N/500)` beziehungsweise
eins für eine leere Mission; dies ist noch keine gemessene Queryzahl.

Die vorbereiteten Integrationstests vergleichen per `assertSame()` mit dem
alten Einzelrelationsalgorithmus, kontrollieren eine fremde Mission,
Null/Leer/Ordnung und Querydeltas sowie die Relation auf VM 501 und einen
anschließenden frischen Read. Die `SHOW SESSION STATUS`-Sonden verwenden
`Com_select`; ein `SHOW` ist nicht die gemessene SELECT-Abfrage. Die statischen
Verträge sind zusätzliche Quellformprüfungen und ersetzen die Verhaltenstests
nicht. Ein kompletter Satz negativer Auth-/AD-/Ablaufkonkurrenzfälle bleibt
Laufzeitabnahme und ist durch den einen neuen Sessiontest nicht erledigt.

Die Lastprofile akzeptieren eine VM-Dauer erst nach exakter URL ohne Redirect,
200/HTML/Authmarker, Zielmissionsname, exakter Tabellen-/Auswahlzahl und
VM-Marker. Positive Samplezähler verhindern den Leerfolg. A/B benutzt bei
gleicher Session dasselbe CookieJar und bei unabhängigen Sessions zwei
frische Logins; der Identitätsbeweis protokolliert nur die Relation.
Der Cold-Test misst ausdrücklich nur den ersten Zielrequest nach dokumentiertem
Reset; sein vorheriger Login wärmt bereits Auth/Bootstrap. Keine allgemeine
Cold-Host-, Kapazitäts- oder Latenzverbesserung ist damit belegt.

## U14: Darstellung und unveränderte Schutzpfade

Die neue `source_count` ist additiver Darstellungskontext. Die Qualifikation
bleibt je Objektart an den vollständigen Nachweis aus allen konfigurierten
Quellen gebunden. Fehlende Quelle, vorhandene Quelle ohne qualifizierte Art,
teilweise Auswertbarkeit, historischer Vollstand und aktueller Vollstand
werden im Renderer getrennt bezeichnet. Filterleere bleibt separat. Die
synthetischen Unitfälle prüfen Projektionen; sie beweisen keine tatsächliche
Inventarabfrage. Kein zusätzlicher Repairgate ist Bestandteil des geprüften
Produktdiffs und keine bestehende serverseitige Freigabe wurde gelockert.

Die Textkorrekturen zu best-effort-Telemetrie, fehlenden Phasen,
ACK-Antwortverlust, bedingtem Hostname-Reboot und Windows-IP gegenüber
ESXi-Portgruppe entsprechen den gelesenen Produzenten. `Send-VsPhase` besitzt
einen best-effort-Aufruf ohne Outbox. `client_getinfo` publiziert seinen
Snapshot, wartet auf die positive ACK-Antwort und setzt erst danach
`SetupState=complete`; sein Catch entfernt den Marker. Das Machine-ACK prüft
die Rolloutrevision unter Rowlock vor Dedup und committet 5/5 vor seiner
HTTP-Antwort. Deshalb darf eine verlorene Antwort bei bereits sichtbarem 5/5
weiterhin zu fehlender lokaler Detection führen. Das ist Textkorrektur am
beabsichtigten Vertrag, keine neue Zustellgarantie.

Ausgewählte unveränderte Schutzpfade wurden zusätzlich im Originalcode gelesen:

- Create-Historienfence: offene Einheiten bleiben über terminale Jobs hinaus
  relevant; aktueller Job wird ausgeschlossen, Portal-VM-ID und exakte
  Name-/UUID-Überschneidung schützen den Scope. Unbekannte Endpunkte werden
  nicht als Beweis verschiedener Hosts behandelt.
- Create-Success: Jobstatus, Worker-ID, Token und Epoch sowie VM-/Missionsscope
  werden unter Lock geprüft; widersprüchliche gespeicherte Liveidentität wird
  nicht überschrieben. Release prüft Unit/Terminalität/aktive Mission und
  Inventarevidenz, schreibt Resolution und Statuswechsel in einer Transaktion
  mit Statusfence; die menschliche Begründung bleibt ihre Aussage.
- VLAN: Scope materialisiert exakte Missions-/VM-IDs, WDS benutzt exakte
  Zeichenketten; gefaltete Namen bleiben Diagnose. Bundlefingerprint enthält
  Version, Reihenfolge und rohe VLANbytes. Aktive Jobs sperren Änderungen,
  unveränderte ungültige Altbundles sind der bereits vorgesehene Sonderfall.

Dies ist keine erneute Vollabnahme von U01-U12 und keine Prüfung jeder
Korruptions-, Callback-, Reaper- oder externen Ausführungsvariante. E9 und der
ungeklärte PHP-Container-Exit 137 bleiben gesonderte Restpunkte.

## Schlussstand und offene Abnahme

Die Fixture-/Locale-/Lockhelperkorrekturen wurden im Schlussstand manuell
nachgelesen, ebenso die sofort beobachtete Blocker-Promise-Rejection mit
weiterhin maßgeblichem Originalpromise und die Cold-Schwellenabgrenzung im
Lastprofil-README. Hostname, Reporting, `unless-stopped` und manuelle
Supervisorgrenze wurden im letzten DE/EN-Diff gegen ihre Originalowner
geprüft. Der finale U14-Korpusbericht mit 29 Claims, neun gepaarten
Hilfekatalogen und 15 aktiven Dokumenten wurde einschließlich seiner
thematischen Abdeckungsgrenzen gelesen. Der zuletzt extrahierte
`system_status_deviation_empty_message()` und der bestehende, um die
Evidenzprojektion ergänzte `SystemStatusPanelBranchTest` wurden nachgelesen;
kein neuer P1/P2-Befund. Die Backup-Retentiontexte wurden ebenfalls abschließend
mit ihrem Owner abgeglichen.

Zwei gemeldete redaktionelle Schlusspräzisierungen sind ebenfalls korrigiert
und nachgelesen: Das Backup-Runbook nennt die tatsächlich gesicherte
Anwendungsdatenbank. Der U14-Bericht bezeichnet E1/E7/E9 als implementiert
mit offener Engine-/Laufzeitabnahme und grenzt den gesonderten E9-Registry-
Randfall sowie den ungeklärten PHP-Container-Exit 137 ab. Der verbliebene
VLAN-Kommentar im Client-Common ist ausdrücklich ein Kommentarrest ohne
Laufzeitwirkung. Keine weitere Produktänderung oder neue Prüfserie folgt
daraus. Im eingegrenzten Schlussreview bleibt kein offener P1/P2-Befund.

Unabhängig vom Reviewresultat bleibt der Paketstatus:
**implementiert, anhand des Codes geprüft,
Laufzeitabnahme offen**. Die gesamte kanonische QA und Performancephase sind
nur vorbereitet. Der konsolidierte QA-Plan verlangt einen exklusiven Owner,
synthetisches `virtusphere-qa`, live lesbare echte `[n/total]`-Fortschritte und
eine von allen anderen Prüfungen getrennte Performancephase.
