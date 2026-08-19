# Masterplan: Deploy-Zuverlässigkeit, Fehlerherkunft und Portal-UX

Stand: 2026-08-13, zusammengeführte ausführbare Fassung nach Repository-Review, Online-Faktenprüfung, Integration des Joblog-/Abbruch-/Protokollreviews und Übernahme der AD/LDAPS-Baseline zwischen Etappe 5 und 6.

Dieser Masterplan verbindet vier bisher getrennte Arbeitsstränge. Die ursprüngliche Fassung führte zunächst diese drei zusammen:

1. die Korrektur der im Review gefundenen Lücken an Reaper/DB-Ausfall, Ansible-Aktivitätsnachweis, CLI-SSoT, Festplattentexten, Doku und Fast-Gate;
2. die bereits detailliert geplante eindeutige Fehlerherkunft des ESXi-Inventar-Abrufs;
3. den kritisch geprüften UX-Plan für verständliche Zustände, handlungsfähige Deploy-Blocker, barrierearme Formulare, Operatorfilter und reproduzierbare visuelle QA.

Mit der Erweiterung vom 2026-08-12 kommt als vierter Arbeitsstrang hinzu:

4. Datenkorrektheit und Bedienbarkeit des Bereitstellungsprotokolls, tatsächlich wirksame Abbruchgrenzen, eine eindeutige Terminalergebnis-SSoT, durchgängige Secret-/Ausgabehärtung sowie konsistente Audit-, CSV- und PowerShell-Protokolle.

Die Reihenfolge ist verbindlich: Zuerst wird der bereits veränderte Bestand stabil und ehrlich beobachtbar gemacht, danach baut die Inventar-Fehlertaxonomie auf diesem Worker-/Logvertrag auf. Nach Etappe 10 schließen die Ergänzungsetappen 10A–10D die Terminal-, Joblog- und Protokollverträge, bevor die bestehenden UX-Etappen 11–17 darauf aufbauen. So kaschiert die UX weder einen unvollständig gelesenen Logtail noch einen Abbruch, den der Worker technisch noch nicht einhalten kann. Diese Datei ersetzt getrennte Restlisten und die vorherigen Fassungen als ausführende SSoT vollständig; die geprüften Einzelpläne und Befundtexte bleiben nur als ausführliche Reviewspur erhalten.

Die Erweiterung ändert den bereits ausgeführten Verlauf nicht: Etappe 1 bleibt mit ihrer vorhandenen grünen Abnahme abgeschlossen. Etappe 2 war am 2026-08-12 bereits begonnen und behält ihren DB-/Reaper-Umfang; die neuen Playbook-Abbruchgrenzen gehören in die noch ausstehende Etappe 8, die neuen Portal-/Protokollverträge in 10A–10D. Bereits grün nachgewiesene Hunks werden weder wiederholt noch umnummeriert.

---

## 0. Ausführungsvertrag

Diese Datei liegt bewusst im Repository. Eine ausführende Sitzung liest zuerst `CLAUDE.md`, `AGENTS.md`, `GROK.md` sowie die berührten Regeln unter `.claude/rules/` und führt das Abnahmeprotokoll am Ende dieser Datei fort.

Der Arbeitsbaum ist parallel verändert. Vor jeder Etappe gilt deshalb:

1. `git status --short` lesen.
2. Den ungestageten Diff jeder betroffenen Datei und besonders der betroffenen Funktion lesen.
3. Fremde Änderungen erhalten; keine Datei auf einen früheren Stand zurücksetzen.
4. Nur eigene, der aktuellen Etappe eindeutig zugeordnete Hunks stagen. Der Commit/Push-Abschluss nach grün abgenommener Etappe ist für die ausführende Sitzung verpflichtend.
5. `git add -A`, `git reset --hard` und ein pauschales Checkout veränderter Dateien sind ausgeschlossen.

Aktuell überlappen unter anderem `lib/deploy_constants.php`, `lib/deploy_worker.php`, `lib/deploy_worker_outcome.php`, die DE/EN-Dateien für `help_deploy`, `help_system_status` und `system_status`, `SshStreamHardeningTest.php`, `SystemStatusPanelBranchTest.php`, `docs/CHANGELOG.md` und `docs/operations/esxi-inventory.md`. Auch die nur gelesenen Breitenquellen `struktur.sql` und `lib/migrate.php` sind bereits verändert. Diese Liste ist nur ein Startpunkt; maßgeblich ist immer der dann aktuelle Diff.

Es gibt keine akzeptierten roten Tests und keine feste Testanzahl. Der kanonische Runner ist `scripts/check.ps1`. Die Fast-Lane führt Unit/Static bereits mit vollem Repo-Mount und `--fail-on-skipped` aus; ein zweiter identischer Repo-Root-Lauf ist kein zusätzlicher Nachweis.

Eine Etappe ist nur abgeschlossen, wenn Code/SSoT, passende Tests, sichtbare Texte und Hilfe, technische und betriebliche Dokumentation sowie betroffene Protokoll-/Logpfade innerhalb **derselben Etappe** zusammenpassen und der geprüfte Etappenstand anschließend sauber committed und erfolgreich zum vorgesehenen Upstream gepusht wurde. Hilfe, Doku, Changelog, ADR/Runbook, Audit, Joblog, Containerlog oder Wire-Vertrag sind keine nachgelagerte Sammelarbeit. Was eine Etappe fachlich verändert, wird in dieser Etappe vollständig nachgezogen und abgenommen. Ist ein Bereich nachweislich nicht betroffen, trägt das Abnahmeprotokoll dafür `nicht betroffen` samt kurzer Begründung; ein leeres Feld ist kein Nachweis.

### Verbindlicher Etappenabschluss

Nach **jeder** Etappe wird die Umsetzung angehalten und gegen den Plan geprüft. Erst ein grüner Soll/Ist-Abgleich zusammen mit dem bestätigten Commit-/Push-Abschluss gibt die nächste Etappe frei:

1. Die Anforderungen und Negativabgrenzungen der Etappe erneut lesen und jede einzelne als umgesetzt, getestet oder begründet nicht betroffen markieren.
2. `git status --short` und den vollständigen Diff der Etappe lesen; dabei auch neu entstandene Dateien, indirekte Aufrufer, Spiegel/SSoT-Verbraucher und versehentlich mitgeänderte Fremdhunks prüfen.
3. Mit Repository-Suche nach alten Kategorien, Texten, Links, Zahlen, duplizierten Mappings und alternativen Codepfaden suchen. Ein grüner Test ersetzt diesen Vollständigkeitsabgleich nicht.
4. Betroffene Portaltexte, DE/EN-Hilfe, `docs/`, Changelog, ADR/Runbook, QA-Anleitung und dauerhafte Agent-Regeln in derselben Etappe aktualisieren. Bereits richtige Stellen werden ausdrücklich als geprüft protokolliert.
5. Audit-, Job-, Container- und Fehlerlogs sowie technische Protokolle/Wire-Contracts prüfen: Quelle und Kategorie, Wortlaut und Ursache, Redigierung von Secrets, RBAC/Deep-Link, Retention, Maschinenvertrag und Rückwärtskompatibilität. Notwendige Änderungen gehören in dieselbe Etappe; bei unverändertem Vertrag wird auch das als geprüft festgehalten.
6. Die gezielten positiven, negativen und Zero-Match-Tests der Etappe ausführen. Mehrteilige Runner folgen der `[n/total] RUN`-/`[n/total] PASS|FAIL`-Vorgabe aus `AGENTS.md`; bei gepufferter Ausgabe wird blockweise ausgeführt oder der Fortschritt beobachtbar gepollt.
7. Das Abnahmeprotokoll mit Soll/Ist-Nachweis, Testnachweis, Help/Doku-Nachweis und Logs/Protokolle-Nachweis fortführen. Entdeckte Lücken werden noch in derselben Etappe geschlossen und erneut geprüft, nicht auf eine spätere Etappe oder den Gesamtabgleich verschoben. Ein Befund, der nachweislich einer späteren Etappe gehört, ist die einzige Ausnahme, und er gilt nur dann als verschoben, wenn er **vor** dem Etappenabschluss als eigene Zeile in der Befundabgleich-Tabelle in Abschnitt 16 steht: Befundtext, Ziel-Etappe, Nachweisspalte leer. Ein Befund, der nur im Fließtext einer Etappensektion, in einer Commit-Nachricht oder im Verlauf der ausführenden Sitzung steht, ist nicht verschoben, sondern verloren; die Sitzung endet, das Register bleibt. Das gilt auch für Befunde, die bewusst nicht behoben werden, weil die Begründung sonst mit dem Befund verschwindet.
8. Erst nach diesem vollständigen grünen Nachweis den Commit-/Push-Abschluss aus dem folgenden Abschnitt durchführen. Die nächste Etappe bleibt bis zur bestätigten Upstream-Synchronität gesperrt.

Der Gesamtabgleich am Ende ist damit eine unabhängige Gegenprüfung, keine vorgesehene Reparaturphase. Findet er eine Lücke, wird die verursachende Etappe wieder geöffnet, korrigiert und mit ihren gezielten Nachweisen erneut abgenommen.

### Verbindlicher Commit-/Push-Abschluss je Etappe

„Sauber committed und gepusht“ bedeutet einen reproduzierbaren Etappenstand, nicht lediglich einen erfolgreichen `git commit`-Aufruf:

1. Commit und Push beginnen ausschließlich nach dem vollständigen grünen Etappenabschluss. Rote, übersprungene oder noch ausstehende Anforderungen, Tests, Help-/Doku-/Log-/Protokollfelder verbieten den Commit ebenso wie ein nicht geklärter Soll/Ist-Befund.
2. Unmittelbar davor werden `git status --short`, `git diff --check`, der vollständige ungestagete Diff und alle neuen Dateien erneut gelesen. Eigene Etappen-Hunks werden über explizite Pfade beziehungsweise bei überlappenden Dateien hunkgenau gestaged; `git add -A`, pauschales Staging eines Verzeichnisses und die Mitnahme fremder Änderungen bleiben verboten.
3. Der Index wird mit `git diff --cached --check`, `git diff --cached --stat` und dem vollständigen `git diff --cached` geprüft. Er enthält genau Code, Migration/Fresh-Schema, Tests, Help, Doku, Changelog, Agentregeln sowie Log-/Protokollanpassungen dieser Etappe, aber keine Secrets, lokalen QA-Ausgaben, Screenshots mit realen Daten, temporären Dateien oder fachfremden Hunks.
4. Ein verbliebener eigener, für die Etappe notwendiger Hunk bedeutet, dass die Etappe nicht vollständig ist. Verbleibende fremde Änderungen dürfen den Commit nicht blockieren, werden aber mit Pfad/Hunk als bewusst nicht enthalten protokolliert. „Sauber“ bezieht sich in einem parallelen Arbeitsbaum auf die vollständige Eigentumsmenge der Etappe, nicht auf einen künstlich global leeren Worktree.
5. Im Normalfall entsteht genau ein Etappenabschluss-Commit mit einer konkreten deutschen Nachricht nach dem Muster `Etappe <Kennung>: <fachliche Wirkung>`. Braucht eine Etappe aus Gründen der sicheren Revertierbarkeit mehrere Commits, etwa getrennte Struktur-, Migrations- und Verhaltenscommits, werden alle Hashes derselben Etappe zugeordnet; kein Zwischencommit wird vor der vollständigen grünen Etappe gepusht.
6. Commit-Hooks oder Formatter, die Dateien verändern, öffnen die Indexprüfung erneut. Die geänderten Dateien werden gelesen, die betroffenen gezielten Tests erneut ausgeführt und erst danach committed. Bereits gepushte Historie wird nicht amendiert oder umgeschrieben; notwendige Korrekturen öffnen die verursachende Etappe und erhalten einen normalen Folgecommit.
7. Nach dem Commit werden Commit-Hash, Betreff, enthaltene Pfade/Hunks und der danach verbleibende `git status --short` im Abnahmeprotokoll festgehalten. Der Commit darf erst dann gepusht werden, wenn der protokollierte Hash exakt `HEAD` ist und keine erforderliche Etappenänderung uncommitted blieb.
8. Gepusht wird ausschließlich ohne Force auf den für den aktuellen Branch konfigurierten Upstream. Fehlt ein eindeutiger Upstream, darf `--set-upstream` nur für den bereits vorgesehenen Remote/Branch verwendet werden; ist das Ziel nicht eindeutig, bleibt die Etappe gesperrt und die Sitzung meldet den Infrastruktur-/Berechtigungsblocker statt einen Remote zu erraten.
9. Nach dem Push müssen lokales `HEAD` und Upstream-Ref denselben Hash besitzen; geprüft wird das explizit mit `git rev-parse HEAD` und `git rev-parse '@{upstream}'`. Remote, Branch, Hash und Pushzeit werden in der Etappenzeile protokolliert. Ein abgelehnter Push, divergierter Upstream oder fehlende Berechtigung ist kein grüner Abschluss: Remoteänderungen werden sicher geprüft und integriert, betroffene Tests/Diffs erneut abgenommen und niemals mit `--force` oder `--force-with-lease` überschrieben.
10. Erst die Kombination aus vollständigem Soll/Ist-Abgleich, grünen Nachweisen, geprüftem Commit und bestätigtem Push setzt `Ergebnis = grün` und gibt die nächste Etappe frei. Ein lokaler Commit ohne Push, ein Push ohne Hashgleichheit oder ein pauschaler Mischcommit erfüllt den Vertrag nicht.

Übergangsregel für den Stand vom 2026-08-12: Etappe 1 wurde vor Einführung dieses Vertrags fachlich grün abgeschlossen, während Etappe 2 bereits begonnen wurde und beide Stände im parallelen Arbeitsbaum liegen. Vor dem ersten neuen Etappenabschluss werden die bereits geprüften Etappe-1-Hunks anhand des Abnahmeprotokolls und des aktuellen Diffs von Etappe 2 und fremden Arbeiten getrennt, ihre betroffenen Nachweise bei zwischenzeitlicher Überlappung erneut ausgeführt und als eigener Etappe-1-Nachtragscommit gepusht. Etappe-2-Hunks bleiben dabei ungestaged. Ist eine sichere Hunkzuordnung nicht beweisbar, wird nicht gemischt committed; die Sitzung dokumentiert den Blocker und klärt die Eigentumsgrenze. Diese Übergangshygiene ändert den fachlichen Umfang von Etappe 2 nicht.

### Verbindliche AD/LDAPS-Baseline zwischen Etappe 5 und 6

Die separat entwickelte Active-Directory-Anmeldung über LDAPS wird nach der abgeschlossenen Etappe 5 und vor Beginn von Etappe 6 als neue Repository-Baseline integriert. Sie ist im Auslieferungszustand deaktiviert. Deshalb blockiert die noch offene Prüfung gegen das reale Ziel-AD weder Code-Review noch Merge oder die Fortsetzung dieses Masterplans. Sie blockiert jedoch jede Aktivierung und jede Aussage, die Integration sei für den Produktivbetrieb freigegeben.

Die vollständige fachliche Entscheidung und die Aktivierungsgrenze bleiben in den folgenden Quellen; dieser Masterplan dupliziert sie nicht:

- `docs/adr/ADR-0039-active-directory-authentication-over-ldaps.md`: Architektur-, Sicherheits- und Authentifizierungsvertrag;
- `docs/audits/2026-08-11-ldaps-active-directory-integration-plan.md`: geprüfter Implementierungsumfang und Edge-Case-Matrix;
- `docs/audits/2026-08-13-ldaps-target-ad-validation-protocol.md`: noch auszuführender Ziel-AD-Nachweis einschließlich Zertifikats-, Channel-Binding-, Gruppen- und Failoverfällen;
- `docs/operations/active-directory.md`: Einrichtung, Betrieb, Rollback und Fehlersuche.

Ab dieser Baseline gelten für jede folgende Etappe zusätzlich diese Driftregeln:

1. Migration `0040_active_directory_authentication` ist belegt. Neue Migrationen beginnen bei `0041`; Registry, Frischschema und Upgradepfad bleiben gemeinsam zu prüfen.
2. Vor Änderungen an `lib/auth.php`, `lib/bootstrap.php`, `lib/constants.php`, `lib/migrate.php`, `portal/settings.php`, `portal/system_status.php`, `portal/users.php`, `Docker/mysql/mysql-init/struktur.sql`, `scripts/check-file-size.php`, den Sprachkatalogen, Hilfen oder Logmodulen werden der aktuelle Code und ADR-0039 erneut gelesen. Etappenannahmen aus dem Stand vor dieser Baseline dürfen nicht ungeprüft angewandt werden.
3. Die Module `directory_*` und `repo/directory.php` bleiben Owner der AD-/LDAPS-Domäne. LDAP-Ergebniswerte, Konfigurationsschlüssel, Limits, Statusabbildungen und Gruppen-/Rollenregeln erhalten keine zweite SSoT in Masterplan-Modulen.
4. Jeder spätere Datei-Split erweitert Require-Closure-, POST-/Confirm-, RBAC-, Formular-, CSP-, i18n- und Owner-Scanner um alle neuen Zielmodule. Ein struktureller Split darf keine AD-Aktion oder keinen negativen Vertragszweig aus der Prüffläche entfernen.
5. Der Settings-Split aus Etappen 12/14 bewahrt die transaktionale Kopplung von HTTPS-Pflicht und AD-Aktivierung. Der Systemstatus-Split aus Etappe 13 bewahrt das Directory-Panel samt Berechtigungs- und Fehlerzweigen. Der Users-Split bewahrt lokale Konten, Directory-Zuordnungen, letzte-Administrator-/letzte-Controller-Sperren und deren Aktionsinventar.
6. Ein späterer Auth-Split bewahrt Benutzerreservierung, Session-Erzeugung erst nach erfolgreichem Commit, Passwort-Rehash, lokale Vor-Migrations-Kompatibilität, atomare Rate-Limits, Circuit Breaker und fail-closed LDAPS-Verhalten. Die neue Trennung über `auth_password_rehash.php` wird nicht wieder in `auth.php` zurückkopiert.
7. Etappen 12 bis 14 übernehmen den dann aktuellen Zeilen- und Modulbestand. Die hier vor AD/LDAPS erhobenen Größen und Verantwortlichkeiten sind Planungswerte, keine Erlaubnis, neue AD-Module zusammenzuführen oder bestehende Ausnahmen wachsen zu lassen.
8. Vor dem Abschluss jeder Etappe wird `rg` auf direkte LDAP-Aufrufe außerhalb der Owner-Module, duplizierte Directory-Konstanten, handgeschriebene Settings-Links und nicht registrierte AD-Aktionen ausgeführt. Die relevanten SSoT-, Sprach-, Doku-, CSP- und File-Size-Gates bleiben grün.

Der interne Code-Nachweis der Baseline ist grün. Als ausdrücklicher externer Restpunkt bleibt ausschließlich das Ziel-AD-Protokoll offen. Wenn eine spätere Etappe AD/LDAPS-Verhalten fachlich verändert, öffnet sie diesen Baseline-Abgleich erneut und aktualisiert ADR, Betriebsdoku, Hilfe, Testmatrix und Aktivierungsnachweis in demselben Etappenabschluss.

### Verbindlicher Refactoring-Vertrag

ADR-0006 setzt für neue PHP-Seiten und -Module eine Zielgrenze von ungefähr 400 physischen Zeilen und verlangt Splits nach Fachdomäne. Die Bestandsaufnahme vom 2026-08-11 zeigt mehrere Dateien, die nicht nur groß sind, sondern drei oder mehr unabhängige Verantwortlichkeiten bündeln und in diesem Masterplan ohnehin fachlich geändert werden. Diese Dateien werden deshalb vor ihrer ersten semantischen Änderung in derselben Etappe strukturell zerlegt.

Ein Split ist kein Freibrief für einen Big Bang:

1. Zuerst pinnen Charakterisierungs-, Require-Closure-, Static- und gegebenenfalls Integrationstests den aktuellen Vertrag. Danach folgt ein eigener rein struktureller Hunk; erst bei grüner Parität beginnt der fachliche Hunk der Etappe.
2. Der bisherige öffentliche Require-Pfad bleibt als kleine Kompatibilitätsfassade bestehen und lädt die Domänenmodule in deterministischer Reihenfolge. Öffentliche Funktionsnamen, Signaturen, Transaktionen, Lockreihenfolge, Queryergebnis, Exceptions, Audit-/Joblogtexte und Wire-Felder bleiben beim Split byte- beziehungsweise verhaltensgleich.
3. Kein neues oder extrahiertes PHP-Modul überschreitet 400 physische Zeilen. Gesplittet wird nach Datenquelle/Fachverantwortung, nicht willkürlich nach Zeilennummer. Zyklische Includes, doppelte Helper und ein zweites Mapping sind ausgeschlossen.
4. Static-Tests, die heute genau eine Quelldatei lesen, werden auf einen zentralen Modul-Glob beziehungsweise eine Owner-Registry umgestellt. Ein Split darf keinen POST, Confirm, RBAC-, Require-, SQL- oder SSoT-Guard leise aus dessen Prüffläche entfernen.
5. `scripts/check-file-size.php --ci` wird als dauerhafter Guard für first-party PHP-Seiten/-Module eingeführt. Er hat stabile Diagnose-IDs, positive, negative und Zero-Match-Fixtures in `scripts/test-guards.ps1`; explizite Legacy-Ausnahmen tragen Pfad, aktuellen Grund und eine benannte Abbauetappe. Die Ausnahmeliste darf nicht wachsen und wird nach jedem hier geplanten Split verkleinert.
6. Help, Doku, Agentregeln, Logs und Protokolle werden auch beim rein strukturellen Hunk geprüft. Normalfall ist `nicht betroffen: öffentliche Funktionen und Ausgaben unverändert`; geänderte Modulownership, Require-Pfade, QA-Befehle und Troubleshootingpfade werden dagegen sofort dokumentiert.

Verbindliche, etappengebundene Splits:

| Aktueller Hotspot (physische Zeilen vor Umsetzung) | Begründete Zielgrenze | Etappe und Zielstruktur |
|---|---|---|
| `lib/repo/deploy_jobs.php` (1220) | Payload/Scheduling, Leseabfragen/Logs, Queue/Cancel, Worker-Ownership und Maintenance sind getrennte Transaktionsdomänen | Etappe 1 als struktureller Vorlauf zu Etappe 2: Fassade plus `deploy_job_input.php`, `deploy_job_queries.php`, `deploy_job_queue.php`, `deploy_job_worker.php`, `deploy_job_maintenance.php` |
| `lib/deploy_worker_outcome.php` (693) und `lib/deploy_worker.php` (521) | CLI-Loop, Missions-/Inventarprozess, Stream, Reaper, Klassifikation, VM-Konvergenz und Audit ändern sich in Etappen 2/8 unabhängig | Etappe 2 vor DB-Verhalten: Entry-/Outcome-Fassaden plus Runtime/Stream, Reaper, VM-State und Audit; Etappe 8 ergänzt getrennte Mission-/Inventory-Prozessoren und Klassifikation ohne neue Monolithen |
| `lib/repo/esxi_inventory.php` (794) | Cache-Replace, Status/Pause, Abfragen und VLAN-Sync ändern sich in Etappen 5–9 unabhängig | Etappe 5 vor der Vokabularänderung: Fassade plus `esxi_inventory_cache.php`, `esxi_inventory_state.php`, `esxi_inventory_queries.php`, `esxi_inventory_vlan.php` |
| `lib/esxi_inventory.php` (606) | Credentialauflösung/Enqueue, Abweichungsanalyse, Ampel/Summary und Scheduler sind getrennte Servicedomänen | Etappe 5: Fassade plus `esxi_inventory_scheduler.php`, `esxi_inventory_deviations.php`, `esxi_inventory_display.php` |
| `lib/ansible_inventory.php` (714) und `lib/ansible_command.php` (523) | Artifact/Remotecommand, Outputparser, Datastore/Capability/Hostparser sowie Mode/Marker/Preflight werden in Etappen 7–8 getrennt geändert | Etappe 7 vor gemeinsamer Fehlerabbildung: Fassaden plus Inventory-Parserdomänen; Etappe 8: Modes/Marker und Preflight/Command getrennt, CLI-Require-Closure bleibt vollständig |
| `scripts/check.ps1` (1209) | CLI/Umgebung, Gate-Registry und drei Lane-Ausführungen werden in Etappe 11 ohnehin für Browser/Visual geändert | Etappe 11: öffentliches Entry-Point-/JSON-Schema bleibt; reine Module unter `scripts/lib/check/` für Runtime/Tools, Gate-Registry und lane-spezifische Ausführung |
| `portal/deploy.php` (667) und `assets/deploy.js` (440) | POST-Dispatch, Viewmodel, Queueformular, Jobliste, Poller, Formularlocks und Storage-Liveansicht wachsen mit dem Blockermodell | Etappe 12: dünne Seite plus `lib/deploy_actions.php`, `lib/deploy_page_model.php`, Queue-/Jobrenderer; JS getrennt in Poller, Formular/Blocker und Storage, mit zentraler Script-Ladereihenfolge |
| `portal/settings.php` (952) | Settings-Aktionen, Tabs, Viewmodel und große Renderer einschließlich transaktional gekoppelter AD-/HTTPS-Konfiguration; wird durch Help-SSoT und Formular-API berührt | Etappe 12 strukturell vorbereiten, Etappe 14 migrieren: `lib/settings_actions.php`, `lib/settings_view_model.php`, Partials unter `lib/settings/`; Seite bleibt Auth/RBAC/CSRF-Shell, AD-/HTTPS-Vertrag und Action-Scanner bleiben vollständig |
| `lib/layout.php` (666) | Chrome, Flash, Auth, Formatierung, Badges, Statuslabels und Katalogfilter sind nicht eine Darstellungsdomäne | Etappe 13: Chrome/Fassade plus `portal_flash.php`, `portal_format.php`, `portal_badges.php`, `portal_catalog_filter.php`; öffentliche Helpernamen und Bootstrapverfügbarkeit bleiben erhalten |
| `lib/system_status_panels.php` (506) und `portal/credentials.php` (451) | MECM, Site, Ansible und interne Panels beziehungsweise POST/Test/Listenrenderer sind bereits getrennte Quellen | Etappe 13: source-spezifische Systemstatusmodule und Credentials-Actions/-Renderer; jeder Guard globbt alle Owner-Module |
| `lib/repo/vms.php` (888) und `portal/vm_edit.php` (514) | Legacy-Fassade, Validierung, Bundlepersistenz, Identität, Bulk-/Recoveryaktionen und Formrenderer sind getrennt | Etappe 14: Repo-Fassade plus `vm_validation.php`, `vm_persistence.php`, `vm_operations.php`, bewusst isolierte Legacyfunktionen; VM-Seite als Shell über bestehende/ergänzte `vm_edit_*`-Module |
| `assets/css/components.css` (1715) | Controls, Feedback/Modal, Tabellen, Datenkarten und Seitenelemente werden in Etappe 16 ohnehin tokenisiert | Etappe 16: domänenspezifische Stylesheets mit einer zentralen, cachegebusteten Ladereihenfolge für Portal und Login; `base.css`, `layout.css`, `status.css` behalten ihre Ownership |

Nicht als Teil dieses Masterplans allein wegen der Größe zerlegt werden `lib/migrate.php` (geordnete Migrationsregistry), `lib/constants.php` (SSoT-Registry), `lib/ansible_yaml.php` sowie die großen Pester-Suiten. `Powershell-MECM/mecm/VirtuSphere-Common.ps1` wird in Etappe 10D wegen seines tatsächlich berührten Loggingblocks nicht länger pauschal ausgenommen: Vor der Änderung pinnen Dot-Source-, Funktionsinventar-, Installer-/Packaging- und Pester-Verträge das Verhalten; anschließend wird ausschließlich die kohärente Logging-/Retention-Domäne in ein mitinstalliertes Modul extrahiert, wenn der Vorher/Nachher-Nachweis die getrennten Server- und Clientpakete nach ADR-0029 vollständig bewahrt. Die Pester-Suiten selbst werden dabei nicht allein wegen ihrer Größe zerlegt. Die knapp über 400 Zeilen liegenden, derzeit kohärenten Bestandsmodule `repo/missions.php`, `esxi_inventory_options.php`, `status.php` und `errors.php` bleiben begründete Legacy-Ausnahmen; jede Etappe prüft, ob ihr eigener fachlicher Hunk eine echte neue Domäne erzeugt. `auth.php` ist durch die AD/LDAPS-Baseline auf 387 Zeilen und die separate Passwort-Rehash-Domäne reduziert und deshalb keine Ausnahme mehr. `ssh.php` verliert in Etappe 6 seine SFTP-Domäne und muss danach aus der Ausnahme entfallen.

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
6. `scripts/check-file-size.php --ci` setzt den Refactoring-Vertrag als Fast-Gate um. Der initiale Legacy-Ausnahmesatz wird aus dem hier protokollierten Bestand begründet, nicht automatisch aus jedem Lauf erneuert. Positive, negative und Zero-Match-Fixtures beweisen neue Oversize-Datei, erlaubte kleine Datei, fehlende/gewachsene Ausnahme und leeren Scope.
7. Danach wird `lib/repo/deploy_jobs.php` als rein struktureller Hunk in die im Refactoring-Vertrag benannten fünf Domänenmodule zerlegt. Die alte Datei bleibt Fassade und einziger öffentlicher Require-Pfad. Static-Tests wie `DeployConvergenceContractTest` und `PhaseCContractTest` prüfen über eine zentrale Owner-Registry alle Module statt nur die Fassade. Sämtliche bestehenden Deploy-Repo-Unit-/Integrationstests laufen vor und nach dem Split mit identischem Ergebnis, bevor Etappe 2 Verhalten ändert.

Help/Doku/Logs/Protokolle in derselben Etappe:

- `docs/QA.md`, `.claude/rules/webapi.md` und gegebenenfalls der Changelog beschreiben nur den wirklich bewiesenen CLI-Vertrag.
- Die reine PHPDoc-/Guardkorrektur erzeugt keine neue Portalhilfe, kein Audit, keinen Joblog und keinen Wire-Contract. Diese vier Punkte werden dennoch jeweils als `nicht betroffen` mit Begründung protokolliert.

Gezielte Abnahme:

- PHPStan für `lib/defaults.php` ist grün, ohne einen `default` im `match`.
- `DiskTypeLabelTest` beweist alle SSoT-Tokens und den unbekannten Wert.
- `CliRequireClosureContractTest` beweist positive, negative und Zero-Match-Fälle sowie `seed.php`.
- Doku-Suche findet keine veraltete feste Testanzahl und keine überbreite CLI-Behauptung.
- Der Arbeitsbaum enthält `shot.tmp.js` nicht mehr; fremde ungetrackte Dateien bleiben unangetastet.
- Der File-Size-Guard ist mutationsgeprüft; `deploy_jobs.php` ist eine kleine Fassade, jedes neue Repo-Modul bleibt bei höchstens 400 physischen Zeilen und direkte Requires sowie alle bisherigen öffentlichen Funktionen funktionieren unverändert.

### Etappe 2: Aktiver DB-Ausfall, Reaper und belegbare Ursachen

Die Observer-Grace bleibt eine Schutzregel für einen Reaper, der die vergangene Stille nicht selbst beobachten konnte. Sie ist **keine** Wiederaufnahme eines bereits laufenden Jobs und darf nicht mehr so dokumentiert oder getestet werden.

Vor der Verhaltensänderung werden `deploy_worker.php` und `deploy_worker_outcome.php` rein strukturell entlang CLI-Runtime/Stream, Reaper, VM-State/Konvergenz und Audit/Finalisierung zerlegt. Die bisherigen Dateien bleiben Entry-/Outcome-Fassaden; `deploy_worker.php --once/--loop`, alle öffentlichen Helper, Require-Reihenfolge, STDERR-/Joblogzeilen und Workerexitcodes bleiben identisch. `CliRequireClosureContractTest`, `PhaseCContractTest`, Worker-Outcome-/Ownership-/Reaper-/Convergence-Tests und ein subprocess-basierter CLI-Smoke laufen vor und nach dem Split. Erst bei grüner Parität wird der neue DB-Kanal implementiert. Neue Module bleiben unter 400 physischen Zeilen und werden durch eine gemeinsame Worker-Owner-Registry von allen Static-Scannern erfasst.

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
- Der Worker-Split erfüllt ADR-0006; Fassade, CLI-Require-Closure, Exitcode-/Logparität und der negative Owner-Registry-Fall sind grün.

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

Vor dem neuen Vokabular wird `lib/repo/esxi_inventory.php` rein strukturell in Cache-, State/Pause-, Query- und VLAN-Module zerlegt; die bisherige Datei bleibt Require-Fassade und alle Funktionsnamen bleiben erhalten. Gleichzeitig wird die Servicefassade `lib/esxi_inventory.php` in Credentialauflösung/Scheduler, Abweichungsanalyse und Ampel-/Summarydarstellung getrennt. `EsxiInventoryCacheTest`, VLAN-Sync/-Reassign, Deviation-, Options-, Enqueue-, Scheduler-, Summary- und Pause-Tests laufen vor und nach der Extraktion. Static-Scanner erhalten eine gemeinsame `esxi_inventory`-Owner-Registry. Erst nach nachgewiesener SQL-/Transaktions-/Log-/Renderparität beginnt die Fehlercodeänderung. Alle neuen Module bleiben unter 400 physischen Zeilen; beide Legacy-Ausnahmen werden aus dem File-Size-Guard entfernt.

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

Der Etappenabschluss enthält zusätzlich den Vorher-/Nachher-Nachweis des Repo-Splits, die vollständige Require-Closure und die Bestätigung, dass keine vorbereitete SQL-Anweisung, Lock- oder `repo_transaction()`-Grenze verschoben wurde.

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

Der Etappenabschluss entfernt `ssh.php` aus der File-Size-Legacy-Ausnahme und beweist, dass `ssh.php` und `ssh_sftp.php` jeweils unter 400 physischen Zeilen bleiben, ohne Login-, Cleanup-, Callback- oder Exceptionverhalten zu duplizieren.

---

## 6. Etappe 7: Gemeinsame Ansible-Abbildung

Vor der Mappingänderung wird `lib/ansible_inventory.php` als verhaltensgleiche Fassade entlang Artifact/Remotecommand, Kern-Outputnormalisierung, Datastore/Query sowie Capability/Host-Parsing zerlegt. Öffentliche Funktionsnamen und Marker-/Logzeilen bleiben erhalten; `AnsibleInventoryParseTest`, Datastore-/Capability-/Hosttests und ein isolierter Require-Closure-Load laufen vor/nach dem Split. Static-Scanner lesen die zentrale Ansible-Inventory-Owner-Registry. Erst nach Parser-/Logparität wird die gemeinsame Fehlerabbildung verschoben; alle neuen Module bleiben unter 400 physischen Zeilen.

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

Der Etappenabschluss entfernt `ansible_inventory.php` aus der File-Size-Ausnahme und protokolliert Fassade, Owner-Registry, CLI-Require-Closure sowie Parser-/Logparität.

### 6.1 Nachtrag aus dem Etappenreview (2026-08-18)

Die unabhängige Gegenprüfung nach dem Push von `8f80022` fand sechs Befunde. Zwei gehörten zur Eigentumsmenge von Etappe 7 und wurden nach der Wiedereröffnungsregel aus Abschnitt 0 sofort geschlossen; vier gehören Etappe 8 und stehen unten, an ihrer Fachstelle und als eigene Zeilen im Befundabgleich in Abschnitt 16.

**Befund 1 (behoben, Etappe 7):** `common.conn_ssh` blieb in beiden Locales bei „Noch nicht nach Herkunft getrennter SSH-Fehler" / „origin has not yet been distinguished", während Hilfe und Runbook derselben Etappe bereits sagten, dass nur noch der Preflight-Exitcode diesen Wert schreibt. Zwei von drei Stellen waren nachgezogen; derselbe gespeicherte Code sagte demselben Operator im selben Alert Gegenteiliges, weil `conn_*` den Satz und `esxi_cause_fix_*` die Maßnahme liefert. Kein Test griff, weil `InventoryErrorVocabularyContractTest` Schlüsselexistenz prüft, nicht Wortlaut. Beide Locales sagen jetzt „Übergangswert: neue Abrufe schreiben ihn nur noch für einen fehlgeschlagenen Ansible-Preflight."

**Befund 2 (offen, verbindlich für Etappe 8, siehe 7.6):** Der Klassifikator besitzt weiterhin **keinen** `mysqli_sql_exception`-Zweig, obwohl Abschnitt 3 Punkt 1 ihn als phasenunabhängig erste Regel fordert und Abschnitt 3 ausdrücklich mit „ein echter `mysqli_sql_exception` bleibt auch in einer Transportphase `worker`" schließt. Etappe 7 hat diese Lücke nicht verursacht, aber verschärft: ein nicht wiederherstellbarer DB-Fehler in Phase `TRANSPORT` fiel vorher über `parse` auf den ehrlichen Übergangswert `ssh` und trägt jetzt `ansible_transport`, beschuldigt also bei einem Datenbankausfall aktiv den Ansible-Host. Eine unscharfe Falschaussage wurde zu einer bestimmten. Der Zweig ist vor jeder weiteren Wiringänderung in Etappe 8 zu setzen.

**Befund 3 (behoben, Etappe 7):** Der `@return`-Union von `connection_error_category()` ist eine handgeschriebene Kopie seiner `$needles`-Schlüssel plus `parse` und zugleich das Einzige, womit PHPStan das `match` in `ansible_connection_error_category_for_text()` als vollständig beweist. Eine neue Nadelgruppe ohne Docblock-Pflege hätte den Build grün gelassen und stattdessen einen `\UnhandledMatchError` **innerhalb** des `catch (Throwable)` des Inventarworkers erzeugt; ein `\Error` wird von genau diesem Catch nicht gefangen, der Auftrag verlöre also seinen Terminalzustand vollständig, statt eine falsche Kategorie zu speichern. Neu `tests/Static/ConnectionErrorMappingContractTest.php`: es hält Nadelgruppen, Docblock-Union und Match-Subjekte in beide Richtungen zusammen, verlangt, dass jedes Match-Ziel von `inventory_error_is_ansible()` erkannt wird, und schützt jede Extraktion gegen Zero-Match. Negativrichtung per Datei-Mount bewiesen (zusätzliche Nadelgruppe ohne Docblock-/Match-Pflege lässt genau die beiden Spiegeltests fallen und benennt die neue Kategorie).

**Befund 4 (offen, verbindlich für Etappe 8):** `ansible_connection_error_category()` behandelt `SshTransportConfigurationException` nicht; beide heutigen Aufrufer fangen ihn vorher selbst ab. Ein dritter Aufrufer, der das vergisst, erhält `ansible_transport` für eine rein lokale Ursache wie fehlende phpseclib oder leere Pflichtfelder und beschuldigt damit den Ansible-Host für unsere eigene Fehlkonfiguration, also genau die Fehlerklasse, für die diese Etappe existiert. Etappe 8 verdrahtet den Worker weiter und schafft realistisch diesen dritten Aufrufer. Die Funktion bildet den Typ deshalb künftig selbst auf `config` ab und die vorgelagerten Prüfungen entfallen, damit sie tatsächlich die eine Stelle ist.

**Befund 5 (offen, Etappe 8):** `deploy_worker_classify_inventory_failure()` bildet `SshTransportBudgetExceeded` unbedingt vor dem Phasen-Switch auf `ansible_timeout` ab, während Abschnitt 3 Punkt 6 „**nur** in `SSH`, `SFTP` und `TRANSPORT`" verlangt und Abschnitt 3 mit „ein künstlich in `CONFIG` platzierter Budgettyp bleibt `config`" schließt. Heute ist der Widerspruch nicht erreichbar, weil ein Budgettyp ausschließlich im SSH-/SFTP-Code geworfen wird; er steht aber schriftlich zwischen Code und Vertrag und wird mit der neuen Phase `SFTP` aus Abschnitt 7.1 erreichbar. Etappe 8 löst ihn auf oder begründet die unbedingte Form ausdrücklich.

**Befund 6 (offen, Etappe 8):** `credential_test_sftp_failure()` (`ssh.php`) bildet `SshTransportBudgetExceeded` auf `ansible_timeout` und `SshTransportConfigurationException` auf `config` ein zweites Mal ab, parallel zu `ansible_connection_error_category()`. Vertretbar, weil der Fallback dieses Pfads bewusst `VIRTUSPHERE_CREDENTIAL_TEST_SFTP` ist und die Funktion aus Etappe 6 stammt; die Etappe-7-Suche nach „einer zweiten Mapping-Tabelle" trifft sie aber zur Hälfte. Etappe 8 entscheidet zusammen mit Befund 4, ob die gemeinsame Funktion beide Typen selbst trägt und dieser Pfad nur noch seinen eigenen Fallback ergänzt.

---

## 7. Etappe 8: Worker-Wiring, Playbookgrenzen, Abbruch, Pause und Logging

Vor der semantischen Wiringänderung wird `lib/ansible_command.php` in Mode-/Markerlogik sowie Preflight-/Commandbau getrennt; die alte Datei bleibt Fassade. Die in Etappe 2 vorbereitete Workerstruktur erhält getrennte Missions- und Inventarprozessoren, statt die neuen Phasen wieder in den Entry Point zu schieben. Modefolge, Shellquoting, Marker, Preflightoutput, CLI-Require-Closure und vorhandene Worker-Logs werden vor/nach dem Strukturhunk charakterisiert. Alle neuen Module bleiben unter 400 physischen Zeilen; Owner-Registries sind die einzige Prüfflächen-SSoT.

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

Dieser Zweig ist der **erste** Schritt der Etappe und geht jeder weiteren Wiringänderung voraus (Befund 2 aus Abschnitt 6.1): `deploy_worker_classify_inventory_failure()` besitzt bis heute keine `mysqli_sql_exception`-Prüfung, obwohl Abschnitt 3 sie als Punkt 1 der verbindlichen Reihenfolge führt. Seit Etappe 7 die Phasen `SSH`/`TRANSPORT` auf die gemeinsame Ansible-Qualifizierung umgestellt hat, trägt ein dort durchgereichter DB-Fehler `ansible_transport` statt des früheren `ssh` und benennt damit fälschlich den Ansible-Host als Ursache eines Datenbankausfalls. Der Zweig steht vor den drei Transporttypen, weil ein `mysqli_sql_exception` keiner von ihnen ist und seine Phase nichts über seine Herkunft aussagt; ein gezielter Test belegt ihn in `SSH`, `TRANSPORT` und `DB`.

Die technische Fehlermeldung im Joblog bleibt durch `deploy_worker_redact_secrets()` gegen ESXi- und Ansible-Secret redigiert. `logs/error.log` wird nicht als Speicherort des Originalfehlers dokumentiert.

### 7.7 Tatsächliche Playbook-Grenzen und atomarer Abbruch

`ansible_playbooks_for_mode()` bleibt die SSoT für Reihenfolge und Modusinhalt, liefert dem Worker aber geordnete Schrittdeskriptoren statt einer einzigen entfernten `command1 && command2`-Kette. Ein Missionsauftrag hält einen strikt jobgebundenen Remote-Arbeitsordner und startet jedes Playbook als eigenen SSH-Befehl. Preflight und Upload werden nicht pro Schritt dupliziert; Marker, Quoting, Umgebung, Exitcode und redigierte Ausgabe jedes Einzelschritts bleiben vollständig beobachtbar.

Vor dem ersten mutierenden Schritt und nach jedem beendeten Remote-Schritt entscheidet ein gemeinsamer Repo-Helper in einer kurzen Transaktion anhand von `id`, `locked_by` und `status`:

- `running` mit eigener Ownership erlaubt den nächsten Schritt;
- `cancelling` mit eigener Ownership bestätigt den Abbruch und startet keinen weiteren Schritt;
- verlorene Ownership oder ein terminaler Fremdzustand beendet den lokalen Ablauf, ohne ihn zu überschreiben;
- ein fehlgeschlagener Schritt wird nach demselben Ownership-Recheck genau einmal finalisiert.

Die letzte-Schritt-Race wird durch konkurrierende Compare-and-swap-Updates entschieden, nicht durch einen vorher gelesenen Status: Erfolg/Teil-Erfolg/Fehler darf nur aus `running` mit eigener Ownership finalisieren. Ist die Abbruchanforderung zuerst committet, trifft der Abschluss-CAS null Zeilen, lädt `cancelling` neu und bestätigt `cancelled`; gewinnt der Terminal-CAS zuerst, kann ein späterer Cancel-POST den bereits terminalen Job nicht mehr verändern. Damit gibt es weder „erfolgreich trotz angenommener Abbruchanforderung“ noch `cancelled` mit weiterlaufendem nächsten Playbook.

Ein normaler Abbruch beendet den aktuell laufenden Remote-Schritt nicht hart. Die UI und ADR-0033 sagen deshalb exakt: Der aktuelle Schritt kann externe Änderungen vollständig ausführen; anschließend startet der Worker keinen weiteren Schritt. Die bestehende Gruppenaktion bleibt gemäß ADR-0022 bewusst auf noch wartende Gruppenjobs beschränkt und behauptet nicht, den bereits laufenden Slot abzubrechen. Doppel-POSTs verändern weder den ersten Akteur/Zeitpunkt noch erzeugen sie zusätzliche Logzeilen. Abbruch zwischen jedem Modusschritt, während DB-Reconnect, nach Ownership-Verlust und im Rennen mit dem letzten Schritt wird deterministisch getestet.

### 7.8 Ausgabegrenzen, Phasen, Secret-Sentinel und Remote-Artefakte

Alle Worker-Ausgaben laufen vor Persistenz durch genau eine Normalisierungs-/Redigierungsfunktion. Sie normalisiert Zeilenenden und ungültiges UTF-8, entfernt ANSI-Escape- und nicht darstellbare C0-/DEL-Steuersequenzen mit bewusst erlaubtem Tab, begrenzt die UTF-8-Bytezahl einer Zeile und das Gesamtvolumen je Job und schreibt bei jeder Kappungsart genau eine redigierte SYSTEM-Zeile. Die Grenzwerte liegen als Konstanten-SSoT in `deploy_constants.php`, werden anhand repräsentativer `-vvv`-Fixtures begründet und durch den Bounds-Sync-Guard mit Hilfe, Doku und Tests gekoppelt. Ein erreichtes Ausgabelimit beendet weder Heartbeat noch Playbook; es markiert die Diagnose als gekappt und verhindert unbegrenztes DB-/DOM-Wachstum.

Da die aktuelle Shellumleitung `2>&1` Ansible-stdout und -stderr vereinigt, darf Portal, Rohdownload und Doku diese Zeilen nicht als echten Einzelstream bezeichnen. Der persistierte Quellcode wird für neue Zeilen ehrlich als `ansible` geführt; historische `stdout`-/`stderr`-Zeilen werden durch denselben Displayhelper als „Ansible-Ausgabe“ dargestellt. `system` und `worker_error` bleiben getrennte technische Quellen. Eine spätere echte Kanaltrennung wäre eine eigene Änderung an `ssh_execute_command()` und darf nicht durch UI-Text vorgetäuscht werden.

Die vorhandenen `::virtusphere-step:: begin/end`-Marker bleiben technische SSoT. Ein dependency-armer Parser erzeugt daraus Phasenüberschriften und die aktuelle Phase; Worker, JSON und Portal führen keine zweite handgepflegte Playbook-/Phasenreihenfolge. Unbekannte oder unvollständige Marker bleiben als neutrale technische Zeilen sichtbar und können den Poller nicht brechen.

Der Sicherheitsnachweis verwendet ein synthetisches eindeutiges Secret-Sentinel und verfolgt es automatisiert durch generierte `accounts.yml`, produktive Playbooks, Remote-Ausgabe, Worker/Redigierung, `deploy_job_logs`, Polling-JSON, serverseitiges HTML, Browser-DOM, Rohdownload sowie Audit-, PHP- und Containerfehlerlog. Der Test prüft auch `-vvv`, Fehler- und Timeoutpfade; er druckt den Sentinel selbst nie in QA-Artefakte. `no_log` bleibt Defense-in-depth und wird nicht als vollständiger Schutz gegen `ANSIBLE_DEBUG` dokumentiert. Die Hilfe beschreibt `-vvv` ausschließlich als erhöhte Diagnoseausgabe und verspricht weder Variablenanzeige noch Geheimnisfreiheit.

Lokales und entferntes Jobmaterial wird im normalen `finally` entfernt. Zusätzlich räumt ausschließlich der `deploy-worker` beim sicheren Kontakt mit einem Ansible-Zugang verwaiste Remote-Verzeichnisse auf: nur kanonisch validierte Pfade mit VirtuSphere-Präfix, Job-ID/Ownershipmarker und abgelaufenem Alter; niemals ein berechneter breiter Pfad oder fremdes Verzeichnis. Positive/negative Tests beweisen normalen Cleanup, Verbindungsabbruch, hart unterbrochenen Vorlauf, Symlink-/Traversalabwehr, falschen Owner und den gedrosselten Sweep-Logeintrag.

Etappe 8 ist erst abgeschlossen, wenn Workerzustand, Schrittgrenzen, Cancel-CAS, Pause, Audit, Joblog, Remote-Cleanup und Containerlog als zusammenhängender Beobachtbarkeitsvertrag geprüft sind. In derselben Etappe werden die dazugehörigen Hilfesätze, ADR-0033, das Inventar-/Deploy-Runbook, QA-/Deployment-Aussagen und der Changelog-Abschnitt aktualisiert. Der Soll/Ist-Abgleich beweist außerdem, dass kein Fehler als erfolgreich persistiert wird, kein angenommener Abbruch ein weiteres Playbook startet, kein Secret in einem dauerhaften oder flüchtigen Log landet und keine neue Kategorie den Machine-API-Wire-Contract erreicht.

Zusätzlich sind `ansible_command.php` und die Worker-Fassaden aus der File-Size-Ausnahme entfernt; Mode-/Preflight-/Command-, Missions-/Inventory-Prozessor- und Static-Owner-Nachweise sind grün.

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

## B. Ergänzungsetappen 10A–10D: Joblog, Terminalergebnis und Protokoll-SSoT

Diese Etappen wurden am 2026-08-12 ergänzt, nachdem Etappe 1 abgeschlossen und Etappe 2 begonnen war. Sie ändern deshalb weder deren Umfang noch deren Abnahme. Etappe 8 stellt zuvor die echten Worker-Schritt- und Abbruchgrenzen her; 10A–10D schließen anschließend Datenmodell, Lesevertrag und systemübergreifende Protokolle. Erst danach beginnen die bestehenden UX-Etappen 11–17.

### Etappe 10A: Vollständiger Joblog-Tail, Drain und Rohprotokoll

Der aktuelle Initialabruf `seq > 0 ORDER BY seq ASC LIMIT 1000` zeigt bei langen Jobs den Anfang statt das Ende; ein Poll mit höchstens 500 Zeilen darf außerdem nicht nur deshalb aufhören, weil derselbe Response bereits einen terminalen Status trägt. Die Reparatur ist ein Repository-/JSON-Vertrag, kein clientseitiger Scrolltrick.

1. Das Repo erhält getrennte, eindeutig benannte Cursorabfragen für initialen Tail, Vorwärts-Drain und ältere Seiten. Der initiale Tail liest die neuesten `limit` Zeilen über eine absteigende innere Auswahl und liefert sie für die Anzeige wieder streng aufsteigend. `after_seq` liest vorwärts; `before_seq` liest die unmittelbar älteren Zeilen. Alle Limits sind positiv, hart begrenzt und SSoT-Konstanten.
2. Jede JSON-Seite liefert additive Cursor-Metadaten: `oldest_seq`, `newest_seq`, `has_older`, `has_more` und `caught_up`. `has_more` wird mit `limit + 1` bewiesen; `caught_up` ist nur wahr, wenn im konsistenten Read-Snapshot keine höhere Sequenz existiert. Leere Jobs und durch Retention geleerte Logs haben unterscheidbare, lokalisierte Zustände.
3. Alle Terminalpfade schreiben ihre letzte SYSTEM-Zeile und den terminalen Jobzustand in derselben Transaktion. Danach darf kein normaler Writer weitere Joblogzeilen anhängen. Der Poller beendet sich erst bei `job.terminal === true && caught_up === true`; `terminal && has_more` drainiert ohne das normale Intervall weiter, aber weiterhin single-flight und mit begrenztem Backoff bei Fehlern.
4. Der HTML-Erstabruf verwendet denselben Tailhelper wie JSON. Mehr als 1.000 vorhandene Zeilen zeigen daher das tatsächliche Ende, nicht die ersten Zeilen. „Ältere Zeilen laden“ verwendet den stabilen `before_seq`-Cursor und erhält den sichtbaren Scrollanker; parallel eintreffende neue Zeilen erzeugen weder Duplikate noch Lücken.
5. Ein eigener `format=raw`-Pfad streamt alle noch aufbewahrten Zeilen in Sequenzreihenfolge mit Quelle, UTC-/Portalzeitvertrag und Zeilentext, ohne sie vollständig in PHP-Speicher oder DOM zu laden. Er verwendet dieselbe `deploy.run`-Autorisierung, `nosniff`, eine sichere feste Dateinamenform und keine Inline-Ausführung. Retention wird sichtbar im Portal und im Downloadheader benannt; ein gelöschtes Detail kann nicht durch eine zweite Schattenkopie rekonstruiert werden.
6. Der unendlich wachsende Browser-DOM wird durch ein dokumentiertes Fenster begrenzt. Entfernte ältere Zeilen bleiben über „Ältere Zeilen laden“ beziehungsweise den vollständigen Rohdownload erreichbar; die UI behauptet nie, das DOM-Fenster sei das vollständige Protokoll.

Gezielte Abnahme:

- Integrationstests verwenden einen Job mit mehr als 1.000 Startzeilen und mehr als 500 während des Pollings nachgelieferten Zeilen; jede Sequenz erscheint genau einmal und die letzte persistierte Zeile ist sichtbar.
- Terminalstatus mit verbleibendem Rückstand, leerer Log, Retention-Purge, paralleler Append, Cursorgrenzen, ungültige Cursor, Sessionablauf, `403`, Netzfehler und parallele Pollversuche sind abgedeckt.
- Rohdownload beweist RBAC, Content-Type/Disposition, konstante Speichernutzung, Retentionhinweis, Reihenfolge, Redigierung und vollständige Ausgabe auch jenseits des DOM-Limits.
- DE/EN-Hilfe, Deploy-Hilfe, ADR-0033, `docs/DEPLOYMENT.md`, Deploy-Chain, Troubleshooting, QA, Changelog sowie Job-/Fehler-/Auditlogwirkung werden in 10A aktualisiert und abgenommen.

### Etappe 10B: Terminalergebnis-SSoT und ehrliche Abbruchmetadaten

`status` bleibt die Workflow-SSoT. Die bereits vorhandenen `cancel_requested_at`, `cancel_requested_by` und `result_json` werden verwendet, statt parallele Felder mit gleicher Bedeutung einzuführen. Additiv kommen `terminal_reason_code` und ein größenbegrenztes `terminal_reason_detail` hinzu; Migration und Frischschema bleiben synchron. Codes und erlaubte Statuskombinationen liegen zentral bei den Deploy-Konstanten, nicht in Portalzweigen.

1. `cancel_requested_*` beschreibt den ersten normalen Operatorwunsch. `cancelled_at` beschreibt den bestätigten Endzeitpunkt. Der anfordernde Benutzer wird für Anzeigezwecke separat gejoint; nach Löschung bleibt die historische ID erhalten und wird lokalisiert als „Benutzer #N (gelöscht)“ dargestellt. Doppelabbruch überschreibt weder Akteur noch Zeitpunkt.
2. `result_json` trägt die versionierte strukturierte Erfolgs-/Teil-Erfolgszusammenfassung. `terminal_reason_code/detail` trägt begrenzte technische Abschlussgründe wie Reaper, Ownership-Verlust, Timeout oder explizite Operator-Cancellation. `last_error` bleibt ausschließlich der redigierte Fehler-Fallback eines tatsächlich fehlgeschlagenen Jobs nach Ablauf der ausführlichen Joblogretention; Erfolg, Teil-Erfolg, Abbruchwunsch und normaler Abbruch schreiben dort nichts.
3. Ein zentraler Presenter rendert aus Status, Cancelmetadaten, Resultat und Terminalgrund die DE/EN-Blöcke „Ergebnis“, „Abschlussgrund“ und „Abbruch“. Persistierte Ansible-Ausgabe, Codes und Machine-API-Felder werden weder übersetzt noch rückwirkend umgeschrieben. Ein cancelled Job zeigt nie „Letzter Fehler“.
4. Die Cancel-Transition schreibt genau eine unveränderliche SYSTEM-Zeile: bei `queued -> cancelled` die sofortige Stornierung, bei `running -> cancelling` die angenommene Anforderung. Die spätere Workerbestätigung erzeugt keine zweite Cancel-Zeile. Gruppenabbruch füllt dieselben Metadaten pro noch wartendem Job und lässt `last_error` leer; die bestehende Regel, einen laufenden Gruppenslot nicht abzubrechen, bleibt sichtbar.
5. Historische Zeilen werden nicht spekulativ migriert. Bei alten `cancelled`-Jobs unterdrückt der Presenter `last_error` unabhängig vom Text und zeigt vorhandene Cancelmetadaten oder einen neutralen Legacy-Fallback. Bei alten `failed`-Jobs bleibt der vorhandene redigierte Fehler sichtbar. Ein Stringvergleich allein entscheidet nie die Semantik.
6. Ein Cancel aus `deploy_log.php` kehrt über einen validierten Origin-Token zum selben Joblog zurück. Der Pollresponse enthält additiv die gerenderten Cancel-/Terminalblöcke und erlaubten Aktionen; Status, Metadaten und Buttonzustand aktualisieren sich deshalb auch nach einem Abbruch aus einem zweiten Tab. Alle mutierenden POSTs bleiben CSRF-/RBAC-/Confirm-geschützt.

Gezielte Abnahme:

- Migration/Fresh-Schema, erlaubte Code-/Statuskombinationen, Resultatschema, Detailbounds und Presenter-Exhaustivität sind statisch und integriert geprüft.
- queued/running, Doppel- und Gruppenabbruch, gelöschter Benutzer, DB-Ausfall, Reaper, Ownership-Verlust sowie die letzte-Schritt-Race erzeugen genau den definierten Zustand und höchstens eine Cancel-SYSTEM-Zeile.
- Normale Cancellation erscheint niemals als „Letzter Fehler“; echter `failed`-Fallback bleibt nach Log-Purge sichtbar, Teil-Erfolg steht ausschließlich im Ergebnisblock.
- ADR-0033, ADR-0022, Deploy-Hilfe, Deployment-/Troubleshootingdoku, Retentiontext, DE/EN-Katalog, Audit-, Job-/Containerlog und Machine-Wire-Nichtbetroffenheit werden in 10B abgeschlossen.

### Etappe 10C: Strukturierte Audits, sichere Altpfade und sichtbare CSV-Kappung

1. Vor der Änderung inventarisiert eine Migrationsmatrix jeden first-party `audit()`-/`machine_api_audit_warning()`-Producer mit Ereignis, Objekt, Ergebnis, Kontext, Kategorie und Aufrufer. Neue additive Felder `event_code`, `object_type`, `object_id`, `result` und größenbegrenztes `context_json` bilden für neue Zeilen die SSoT; die bestehende Beschreibung bleibt als kompatible, aus denselben Daten gerenderte Anzeige beziehungsweise als unveränderter Legacy-Fallback erhalten. Historische Freitexte werden nicht geraten oder umgeschrieben.
2. Eventcodes und zulässige Kontextfelder liegen in einer zentralen Registry. Kontext ist allowlist-basiert, typisiert, bytebegrenzt und vor Persistenz redigiert; Passwörter, Tokens, vollständige Payloads und freie Exceptiondumps sind verboten. Ein Guard findet neue freie Auditproducer, unbekannte Codes, übergangene Registryowner sowie positive, negative und Zero-Match-Fixtures.
3. Die ungenutzte Legacyfunktion `addLog()` in `lib/repo/log.php` wird nach statischem und dynamischem Zero-Caller-Nachweis entfernt. Ein Security-Guard verbietet Audit-/Logsignaturen, die Auth-Tokens als speicherbaren Parameter annehmen, sowie Tokenwerte in Repo-, PHP-, Container- und Testausgaben. `audit()`/`audit_event()` sind danach die einzigen Portal-Audit-Entry-Points.
4. Audit-CSV verwendet exakt denselben validierten Filterstruct und dieselbe Sortierung wie die Tabelle. Vor dem Streamen wird die Trefferzahl bestimmt; bei mehr als 10.000 Zeilen zeigen Portal und lokalisierter Hinweis „erste 10.000 von N“, die Responseheader tragen Gesamtzahl, Limit und `truncated=1`, und ein strukturiertes Export-Audit protokolliert Filterfingerprint, ausgegebene/gesamte Anzahl und Kappung, aber keine sensiblen Suchwerte. Die CSV-Struktur erhält keine überraschende Kommentarzeile.
5. Heartbeat und `reportRun` behalten ihren spamfreien Vertrag: kein Audit pro Heartbeat. Wiederholte Zustell-, Sink- oder Providerfehler werden gedrosselt und ein Recovery wird höchstens einmal pro Störungsfenster protokolliert. ADR-0018 und Machine-Wire-Felder bleiben unverändert.
6. Die in Troubleshooting derzeit zu früh empfohlene Korrelationssuche wird in 10C auf den tatsächlich verfügbaren Stand korrigiert. Erst Etappe 15 darf sie nach implementierter exakter Suche, Anzeige, Kopieraktion und Export wieder als Bedienweg dokumentieren.

Gezielte Abnahme:

- Registry-/Schema-/Fresh-Migration, vollständiges Producerinventar, Legacyanzeige, Kontextbounds/Redigierung und Guardmutationen sind grün.
- Zero-Caller-Nachweis plus Repositorysuche beweisen, dass `addLog()` entfernt ist und kein Token-Sink übrig blieb.
- CSV-Fälle 0, 1, 9.999, 10.000 und 10.001 prüfen Tabelle/CSV-Parität, Header, sichtbare Kappung, RBAC, Locale und genau ein redigiertes Export-Audit.
- ADR-0018, ADR-0026, ADR-0032, QA, Troubleshooting, Protokollhilfe, Changelog sowie Audit-/Fehler-/Containerlogvertrag werden in 10C synchron abgenommen.

### Etappe 10D: Einheitlicher PowerShell-Logvertrag ohne Heartbeat-Spam

MECM-Server- und Clientskripte bleiben nach ADR-0029 getrennte Laufzeitpakete und teilen keinen zur Laufzeit nachzuladenden Web-/Cloudcode. Ihre Loggingimplementierungen erhalten jedoch denselben statisch geprüften Schema- und Retentionvertrag:

1. Beide Zeilentypen verwenden `ISO-8601 | LEVEL | Komponente | Kontext | Nachricht | Korrelations-ID`, dieselbe Levelmenge, dieselbe Normalisierung/Redigierung und Tagesdateien `yyyy-MM-dd_<komponente>.log`. Jeder Prozesslauf mintet eine nicht geheime Korrelations-ID; Client-Phasen reichen sie durch ihre vorhandenen Reportaufrufe weiter, soweit deren additiver Headervertrag dies bereits erlaubt. Machine-API-Payloadfelder ändern sich nicht.
2. Die 30-Tage-Retention, tägliche Prüffälligkeit, Dateinamensform und Bounds sind gespiegelte Konstanten mit einem gemeinsamen Pester-Paritätsvertrag. Server- und Clientpaket besitzen weiter je ihre lokale Implementation; ein Unterschied benötigt einen expliziten ADR-Grund und einen Negativtest.
3. Schreibfehler stoppen den Hauptprozess nicht, verschwinden aber nicht mehr ausschließlich in `Write-Debug`: pro Prozess und Störungsfenster entsteht höchstens eine redigierte `Write-Warning`-/zulässige lokale Fallbackmeldung „Log-Sink gestört“, bei Erholung höchstens eine Recoverymeldung. Der Fallback schreibt nicht rekursiv in denselben defekten Sink und erzeugt weder Heartbeat- noch Auditspam.
4. Vor jeder Extraktion pinnen Pester-Tests Dot-Source-Exports, Funktionssignaturen, Skriptaufrufer, Installer-/Packagingkopien und Linux-CI-Load. Die Server-Loggingdomäne darf aus `mecm/VirtuSphere-Common.ps1`, die Clientdomäne aus `clients/VirtuSphere-Client-Common.ps1` in je ein lokales Modul ziehen; Installer und ClientPackaging kopieren diese atomar mit. Fehlt eine Datei oder weicht ihre Version ab, scheitert Setup/Packaging sichtbar statt erst der geplante Task.
5. `Powershell-MECM/README.md`, Client-README, Installation, MECM-Runbook, Troubleshooting, QA, Changelog und Help nennen Format, Pfad, Retention, Korrelationsgrenze und Sinkstörung exakt. Die vorhandene Regel „kein Audit je Heartbeat“ bleibt ausdrücklich bestehen.

Gezielte Abnahme:

- Pester prüft Server/Client-Zeilenparität, Dateinamen, Unicode, Zeilen-/Nachrichtenbounds, Redigierung, tägliche Retention, 29/30/31 Tage, parallele Prozesse, defekten Sink, Drosselung und Recovery.
- Dot-Source-/Funktionsinventar, Installer, Upgrade, Neuinstallation, ClientPackaging und gepackte Clientphase laufen mit vollständigem Modulsatz; ein fehlendes beziehungsweise versionsfalsches Loggingmodul ist ein harter, verständlicher Testfehler.
- Heartbeat-/ReportRun-Tests beweisen unveränderte Payload-/Statusverträge, keine Auditzeile pro Heartbeat und gedrosselte Fehlerpfade.
- Help/Doku, lokale Logs, Reportprotokolle, Retention und Machine-Wire-Nichtbetroffenheit werden in derselben Etappe abgenommen.

---

## C. UX-Etappen 11–17

Für alle folgenden Etappen bleiben Machine-API-Wire-Verträge, die fünf Legacy-Statusstrings, technische DB-/Workerwerte, persistente Joblogs, RBAC, CSRF, CSP und Runtime-Air-Gap unverändert, soweit eine Etappe nicht ausdrücklich einen additiven Portal-JSON-Vertrag nennt. Technische MECM-/Ansible-Begriffe werden nicht übersetzt, wenn sie echte Schnittstellenwerte sind. Sichtbare geschlossene Zustandsmengen werden dagegen auf der Portalebene lokalisiert.

### Etappe 11: Kollisionssichere UX-Basis und deterministischer Visual-Harness

1. Vor Beginn müssen Etappen 1–10D einschließlich ihrer Abnahmezeilen grün sein. Unter `qa-artifacts/` entsteht ein Manifest mit HEAD, Branch, `git status --short`, Diffstat, `git diff --check`, Dirty-/Clean-Status aller UX-Zieldateien und SHA-256 jedes dirty oder untracked Zielinhalts. Untracked Inhalte werden nicht nur über Patchhashes geprüft. Vor jedem Schreibvorgang wird der Dateihash erneut verglichen; eine Kollision führt zum erneuten Lesen des betroffenen Diffs, nicht zum Zurücksetzen fremder Arbeit.
2. Die Fast-Basis läuft über `scripts/check.ps1 -Lane Fast`. Vor der Browseränderung wird der Runner strukturell zerlegt: `check.ps1` bleibt einziger öffentlicher Entry Point und behält Parameter, Exitcodes, Gate-IDs/-Reihenfolge, Lane-Zuordnung, `[n/total]`-Ausgabe und JSON-Schema. Dot-sourcete Module unter `scripts/lib/check/` besitzen keine Import-Nebenwirkung und lösen Pfade ausschließlich über `$PSScriptRoot`/den expliziten Check-Root auf. Ein Golden-Vertrag vergleicht Gatekatalog, `-Help`, ungültige Argumente, Fast-Auswahl und JSON vor/nach Split; `VirtuSphere.ProgressReporting.Tests.ps1` deckt den neuen kanonischen Ausführungspfad ab.
3. Danach entfällt der benutzer- und revisionsgebundene Chromium-Fallback. Runner und Playwright konsumieren einen gemeinsamen, getesteten Browser-Resolver beziehungsweise denselben Auflösungsvertrag; zwei eigenständige „höchste Revision“-Algorithmen sind unzulässig.
4. Das Visual-Projekt wird in die bestehende Playwright-Konfiguration integriert und teilt Base-URL, Auth und Reporter. Es verwendet ausschließlich einen synthetischen Wegwerf-QA-Stack mit idempotentem Seed. Worker werden nur dort nach Ausschluss aktiver Jobs pausiert und in `finally` in den Ausgangszustand versetzt; ein Shared-, Dev- oder Produktionsstack darf nicht für Baselines angehalten werden.
5. Pixelbaselines laufen in genau einem deklarierten Chromium-Projekt mit festem OS-, Browser-, Playwright- und Fontvertrag. Firefox, WebKit und Edge bleiben funktionale Releaseprojekte, teilen aber keine Pixelbaseline. Metadaten nennen OS, Browserrevision, Playwright-Version, Fonts/-hashes, Locale, Portalzeitzone, Viewports, `deviceScaleFactor` und Screenshotscale. Ein Mismatch ist `infrastructure_error`, kein Grund für automatische Neubaselines.
6. Uhr, Zufallsquelle, Hell-/Dunkelzustand, Locale, Zeitzone, Viewports und `prefers-reduced-motion` werden fixiert; Screenshots verwenden deaktivierte Animationen und versteckten Caret. Deterministische Seedwerte haben Vorrang vor Masken. Jede unvermeidbare Maske ist eng, benannt und reviewed; Statusbadges werden nie maskiert. Vergleich erfolgt pixelbasiert mit dokumentierter enger Toleranz, nicht über PNG-Bytes.
7. Browser/Fonts werden über den vorhandenen offline-fähigen QA-/Dev-Pfad bereitgestellt; es entsteht keine Runtime-Downloadabhängigkeit.

Etappenabschluss:

- Fast-Basis grün; zwei identische Visual-Läufe beider Themes bleiben innerhalb der Toleranz; Vorherbilder und Metadaten sind reviewbar.
- Positive/negative Tests beweisen Runner-Parität, import-nebenwirkungsfreie Module, Browserresolver, falsche Runner-Metadaten, Worker-Restore und Seed-Isolation. `check.ps1` ist deutlich unter der früheren Monolithgröße; alle neuen Runner-Module bleiben fachlich fokussiert und im vereinbarten Budget.
- `docs/QA.md`, ADR-0028 und Changelog beschreiben Resolver, Visualprojekt, Updatepolitik und Restore. Portalhilfe, Audit-, Job-, Container- und Wire-Verträge werden geprüft und begründet als nicht betroffen protokolliert.

### Etappe 12: Hochwirksame UX-Korrekturen und vollständige Deploy-Blocker

Struktureller Vorlauf mit unverändertem Verhalten:

- `portal/deploy.php` wird Auth/RBAC-/Request-Shell. POST-Aktionen, Viewmodel, Queueformular/Blocker und Jobliste ziehen in fokussierte `lib/deploy_*`-Module; `PortalActionInventory`, Confirm-/Post-Guard- und Phase-C-Scanner erfassen diese über einen gemeinsamen Owner-Glob. Vor und nach dem Split sind POST-Redirects, Sticky-State, Bestätigungen, Repoaufrufe und HTML-Snapshots identisch.
- Der vorhandene `assets/deploy.js` wird vor dem neuen Blockerclient in getrennte IIFEs für Joblogpolling, Formularnavigation/-locks, Blocker/Warnungen und Storage/Capacity zerlegt. `layout_app_scripts()` hält die einzige Ladereihenfolge; kein Modul greift auf nicht exportierte Zustände eines anderen zu. JS-less Verhalten bleibt vollständig serverseitig.
- `portal/settings.php` wird vor der Help-/Formänderung in Action-Dispatcher, Viewmodel und tabbezogene Partials unter `lib/settings/` zerlegt. Die Seite selbst bleibt Auth/RBAC/CSRF-Shell. `$actionTabs`, `settings_url()` und die gerenderten Panel-/Section-IDs bleiben eine SSoT; Deep-Link-, Redirect-, Confirm- und Post-Guard-Tests globben Seite plus Partials.
- Jeder neue PHP-Owner bleibt unter 400 physischen Zeilen. Erst nach Fast-/gezielter E2E-Parität beginnen die folgenden fachlichen Änderungen.

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
- Deploy-/Settings-Shells und ihre neuen Module erfüllen ADR-0006; Script- und Renderer-Owner-Registries sind vollständig, und ein negativer Guard beweist, dass eine zusätzliche Partial-/Scriptdatei nicht unbemerkt aus Confirm-, POST-, CSP- oder Assetreihenfolge-Prüfungen fällt.
- `AGENTS.md`, `.claude/rules/portal.md`, `GROK.md`, Portalhilfe, `docs/QA.md` und Changelog sind synchron.
- Backend-Gate, Bestätigungen, RBAC, Audit-/Joblogs, Session-/Fehlerprotokolle und Machine-Wire-Verträge werden im Etappenprotokoll explizit nachgewiesen.

### Etappe 13: Verständlicher Portal-Zustandswortschatz und robuster Jobpoller

Struktureller Vorlauf mit unverändertem Render-/POST-Vertrag:

- `lib/layout.php` behält Chrome, Header/Footer und die bootstrapweit erwarteten öffentlichen Funktionsnamen, delegiert Flash, Portalformatierung, Badges/Statusdarstellung und Katalogfilter aber an fokussierte Module. Bootstrap- und CLI-Require-Closure werden explizit getestet; `portal_format_timestamp()`, `portal_badge()` und alle bisherigen Aufrufer bleiben ohne zusätzliche Seiten-Requires verfügbar. `AGENTS.md`, `GROK.md` und Portalregel nennen anschließend die tatsächliche Owner-Datei statt einer historischen.
- `lib/system_status_panels.php` wird entlang seiner Datenquellen in MECM-/Site-, Ansible- und interne Panelmodule zerlegt; die bestehende ESXi-Datei bleibt separat. Übersicht und gemeinsame kleine Presenter bleiben in der Fassade. Alle Panel-/Branch-/RBAC-/Geometrietests globben die vollständige Owner-Menge.
- `portal/credentials.php` wird in Request-Shell, Test-/Actionhelper und Renderer zerlegt. Auditkontext, Confirmklassifikation, Zielberechtigungen, Sticky-Fehler und genau eine bestehende Credentials-Auditzeile des Volltests bleiben unverändert.
- Erst nach HTML-/Branch-/Integration-Parität beginnen Lokalisierung und Polleränderung; jedes neue PHP-Modul bleibt unter 400 physischen Zeilen.

1. Technische Werte bleiben roh in Konstanten, DB, Worker, persistenten Joblogs, Maschinenfeldern und bestehenden JSON-Feldern wie `status`. Portal-only-Labels liegen zentral in `lib/portal_status_display.php` für Lifecycle/MECM und `lib/deploy_display.php` für Jobstatus, Modi und Payloadanzeige; DE/EN verwenden einen gemeinsamen Statuskatalog.
2. `lifecycle_badge()` und `mecm_sync_badge()` beziehen die Variante aus bestehender Meta-SSoT, sichtbaren Text aus dem neuen Labelhelper und zeigen für Unknown einen neutralen lokalisierten Fallback ohne Rohwert. Konstanten-Walk-Tests prüfen alle Lifecycle-/MECM-Werte und beide Sprachen; ein Render erzeugt kein Drift-`error_log`.
3. Alle aus aktiven und terminalen SSoT-Mengen abgeleiteten Jobzustände (`queued`, `running`, `cancelling`, `succeeded`, `failed`, `cancelled`, `partial`) werden in Tabelle, initialem Joblogbadge und betroffenen Systemstatusanzeigen lokalisiert. Polling-JSON ergänzt `label`; `status` und `badge` bleiben abwärtskompatibel. `deploy.js` zeigt nur `label`.
4. Der JSON-Poller liefert immer `application/json`: `401` bei fehlender/abgelaufener Session ohne Login-Redirect/-HTML, `403` bei fehlender Berechtigung, lokalisierte `404` bei unbekanntem Job. Auth, RBAC und Locale werden ermittelt, dann wird vor der DB-Pollabfrage `session_write_close()` ausgeführt. Der Client stoppt bei `401`/`403`, prüft Content-Type, arbeitet ohne parallele Requests und loggt erfolgreiche Polls weder in Audit noch Fehlerlog.
5. `virtusphere_deploy_mode_labels()` bleibt technische Validierungs-SSoT. Portal-only-Helper benennen die sechs postbaren Modi sowie den nicht postbaren Systemmodus `inventory`; `deploy_job_payload_summary()` bleibt für technische persistente Logs unverändert.
6. Dashboard-Missionsstatus zeigt `active` lokalisiert als „Aktiv“, leer als Gedankenstrich und andere freie Legacywerte neutral mit ihrem Text; das freie VARCHAR wird nicht fälschlich als Enum behandelt.
7. Das Joblog-Modul setzt den in 10A bewiesenen Cursorvertrag als pausierbaren Follow-Modus um. Solange der Benutzer am Ende steht, folgt die Ansicht neuen Batches ohne Smooth-Scroll. Die Bottom-Erkennung verwendet eine kleine zentral getestete Toleranz, weil `scrollTop` nicht ganzzahlig sein muss, `scrollHeight` und `clientHeight` aber gerundet sind. Scrollt der Benutzer hoch, pausiert Follow automatisch; ein nicht modaler Hinweis zeigt „N neue Zeilen · Zum Ende“ und setzt den Zähler erst nach bewusster Rückkehr zurück.
8. Ein expliziter DE/EN-Schalter „Live folgen“ speichert nur diese UI-Präferenz lokal. Der Verbindungszustand unterscheidet „Live“, „Verbindung unterbrochen“, „Letzte Aktualisierung …“, Sessionende und fehlende Berechtigung. Reine Netzfehler verwenden begrenzten Backoff und eine Wiederholen-Aktion; `401`/`403` stoppen wie in Punkt 4. Erfolgreiche Polls sowie normale Hintergrundpausen erzeugen kein Audit- oder Fehlerlog.
9. Bei `document.hidden` pausiert das normale Pollintervall. Beim `visibilitychange` zurück zu sichtbar startet genau ein sofortiger Catch-up-/Drain-Lauf; Single-flight und Cursor verhindern Parallelität und Doppelzeilen. Terminale Jobs werden nach vollständigem Drain offline lesbar und führen kein nutzloses Intervall fort.
10. Der Logcontainer erhält `role="log"` mit zurückhaltender höflicher Live-Semantik, niemals `assertive`. Ein Pollbatch wird atomar ergänzt und ein gedrosselter, lokalisierter Status fasst neue Zeilen zusammen; Screenreader werden nicht durch jede einzelne Ansiblezeile unterbrochen. Tastaturfokus, „Zum Ende“, „Ältere Zeilen“, Such-/Filtercontrols und Wrap-Schalter besitzen sichtbare Labels und Focus-Visible-Nachweise.
11. Step-Marker aus Etappe 8 rendern Phasenüberschriften und „Aktuelle Phase“ aus demselben Parser. Ein Wrap-Schalter, Freitextsuche sowie Quell-/Phasenfilter arbeiten serverseitig innerhalb genau dieses Jobs und aller noch aufbewahrten Zeilen; Filterzustand ist sichtbar, URL-/Cursor-validiert und sagt ausdrücklich, wenn nur Treffer statt des vollständigen Ablaufs gezeigt werden. Follow ist im gefilterten Zustand bewusst aus oder folgt nur dem dokumentierten Treffer-Cursor; ein Wechsel kann keine ungesehenen Vollprotokollzeilen als gelesen markieren. Der Rohdownload aus 10A bleibt die vollständige retained Ausgabe.
12. Status-, Cancel-/Terminalmetadaten und erlaubte Aktionen aus 10B werden bei jedem Poll gemeinsam aktualisiert. Ein zweiter Tab kann den Abbruchknopf daher durch „Abbruch angefordert durch … um …“ ersetzen; der Satz erklärt, dass der aktuelle Schritt noch abgeschlossen wird und keine weiteren Schritte starten. Ein terminaler Job zeigt den zentral gerenderten Ergebnis-/Abschlussblock, niemals eine clientseitig nachgebaute Interpretation.

Etappenabschluss:

- Konstanten-Walk-, Unknown-, Additiv-JSON-, `cancelling`-, Auth-/Content-Type-, Poll-Single-Flight-, Tail-/Drain-, Scrolltoleranz-, Follow-Pause-, Visibility-/Catch-up-, Filter-/Wrap-, ARIA- und Live-Aktions-Tests sowie Visuals beider Themes sind grün; Fast und Integration laufen.
- Layout-, Systemstatus- und Credentials-Splits erfüllen ADR-0006; Bootstrap-/Require-, Panel-Glob-, POST-/Confirm- und Audit-Paritätsnachweise sind grün.
- DE/EN-Hilfe, Statusglossar, Deploy-/Loghilfe, `docs/QA.md`, Troubleshooting, Changelog und Portalregeln sind aktualisiert.
- Persistente Joblogs, Rohwerte, bestehende JSON-Felder, Auditverhalten und Machine-Wire-Verträge sind unverändert oder additiv belegt; der Browser zeigt nachweislich jede retained Terminalzeile oder benennt Filter/Retention/Kappung sichtbar.

### Etappe 14: Selbstbeschreibende und barrierearme Formulare

Struktureller Vorlauf:

- `lib/repo/vms.php` wird Fassade für getrennte Validierungs-, Bundlepersistenz- und Operator-/Recoverymodule. Legacyfunktionen für Machine-/Übergangspfade bleiben bewusst isoliert, mit identischem Namen und Wireverhalten. Transaktionsgrenzen von Interface-/Disk-/Package-Replacement und `repo_save_vm()`, MAC-/MECM-Erhalt, Optimistic Locking, Provenienz und Identity-Checks werden vor/nach Split durch bestehende Unit-/Integrationstests charakterisiert. Static-Scanner verwenden eine VM-Repo-Owner-Registry.
- `portal/vm_edit.php` wird Request-/Layout-Shell; Diagnostik/Progress, dynamische Formgruppen und Aktionen liegen in bestehenden oder ergänzten `vm_edit_*`-Modulen. `portal/settings.php`-Partials aus Etappe 12 werden jetzt ebenfalls über die gemeinsame Formular-API migriert. Jede Seite/jedes neue PHP-Modul bleibt unter 400 physischen Zeilen.
- Erst nach Repo-/HTML-/POST-/Confirm-Parität beginnt die ARIA-/Hint-Migration.

1. Vor Änderung entsteht eine Migrationsmatrix aller Feld-/Gruppen-/JS-Hints und Fehler. Sie nennt Seite, Feld, ID/Label, vorhandene Hint-/Error-ID, Gruppenziel, dynamische Erzeugung und geplante Migration. Allgemeine Prosa wird ausdrücklich ausgeschlossen; eine Anzahl allein genügt nicht.
2. `lib/forms.php` vereinigt `form_hint_id()`, `form_error_id()`, `form_control_attrs()` und `form_error_html()`. `form_control_attrs()` integriert die bisherige `form_input_class()`-Funktionalität, setzt `aria-invalid` am Control und verbindet Hint plus Fehler in `aria-describedby`. Fehlerausgabe erhält eine stabile ID. IDs werden aus Form, Feld und optionaler Zeilen-/Gruppekennung sicher normalisiert; es entsteht keine zweite konkurrierende API. `vm_field_error()` wird Wrapper dieser Kernlogik.
3. Feldhints sind dem Control, Gruppenhinweise einem `fieldset` oder `role="group"` zugeordnet. Wiederholte Zeilen sind eindeutig; JS-Templates ersetzen Indizes vollständig. Dynamische Deployhints halten `aria-describedby` aktuell.
4. Static-/DOM-Negativtests verbieten tote `aria-describedby`-Referenzen, doppelte IDs und Hint-/Fehler-IDs ohne eindeutiges Control oder begründete Gruppe. Erfolgs- und Fehlerzustände, dynamische Zeilen, Tastatur, axe und eine Screenreader-Stichprobe gehören zur Abnahme.

Etappenabschluss:

- Die Migrationsmatrix ist vollständig abgearbeitet; Fast und Integration, DOM-/axe-/E2E-Tests und Visuals sind grün.
- VM-Repo, VM-Editor und Settings-Partials erfüllen ADR-0006; Legacy-Require-/Wire-, Transaktions-, POST-/Confirm- und Owner-Glob-Negativtests sind grün.
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

6. `lib/log_filter.php` normalisiert Freitext, IP, Tab/Kategorie, lokale Von-/Bis-Daten, UTC-Untergrenze, exklusive UTC-Obergrenze, Korrelations-ID sowie die in 10C eingeführten strukturierten Felder Eventcode, Objekttyp/-ID und Ergebnis. Der lokale Zeitraum ist `[von 00:00, Tag nach bis 00:00)` in Portalzeitzone, niemals `+86400`; DST, ungültige Tage, leere Grenzen und `von > bis` werden getestet. Event-/Objekt-/Ergebniswerte werden gegen ihre Registry validiert. Das Repository erhält einen dokumentierten Filter-Struct statt langer Positionsparameter.
7. Ein URL-Builder trägt den validierten Zustand durch Pagination, Tab, Kategorie, Reset, CSV und Korrelationslinks. Ungültige Daten/Korrelation bleiben sichtbar, erzeugen Feldfehler und keine Repository-Abfrage. Tab/Kategorie werden über die Logtaxonomie validiert.
8. Audit-Tabelle und -CSV sowie Deploy-Log-Kopf zeigen die Korrelations-ID mit einer tastaturbedienbaren Kopieraktion und sichtbarer Kopierbestätigung. Suche ist ausschließlich exakt und normalisiert; Teiltreffer werden abgelehnt, damit eine Diagnose-ID nicht wie Freitext wirkt. Bei Exaktfilter zeigt `logs.php` passende Jobs mit ID, Mission/Systemjob, lokalisiertem Status und `deploy_job_log_url()`. Auditseite bleibt `users.manage`, Joblogziel zusätzlich `deploy.run`.
9. Passende Jobs sind deterministisch sortiert und begrenzt oder paginiert; Kappung wird sichtbar benannt. Die CSV-Kappungs-/Header-/Auditsemantik aus 10C bleibt mit jeder Filterkombination erhalten. Hilfe dokumentiert Retention-Asymmetrie zwischen Audit, Missionsjobs und missionslosen Systemjobs. Korrelation bleibt Diagnostik, keine Autorisierungsgrenze. Erst der grüne E2E-Nachweis dieser Etappe gibt den in 10C vorläufig entfernten Troubleshooting-Suchweg wieder frei.
10. Indizes auf `deploy_logs.correlation_id` und `deploy_jobs.correlation_id` werden mit repräsentativem `EXPLAIN` vor/nach Änderung belegt; weitere Datums-/Kategorieindizes nur bei nachgewiesenem Nutzen. Migration und Fresh-Schema bleiben synchron; Schema-Konvergenz ist Pflicht.

Etappenabschluss:

- Filter-/DST-/Invalid-Input-Units, strukturierte Auditfilter, exakte Korrelation samt Kopieraktion Audit→Job→Joblog, CSV-State/-Kappung, Katalogleerzustände, Navigation/Sticky/Wrap und RBAC sind grün; Fast und Integration laufen.
- Portalhilfe, ADR-0032, `docs/QA.md`, betroffene Runbooks, Changelog und Agentregeln sind aktualisiert.
- Audit-CSV/-Tabelle, Joblogdeep-links, Retention, Berechtigungen, Logs/Protokolle und unveränderte Machine-Wire-Verträge sind im selben Etappenabschluss geprüft.

### Etappe 16: Slate-/Indigo-Refresh, Farbtoken und belastbarer Kontrast

Struktureller Vorlauf:

- `components.css` wird vor jeder Farbänderung anhand bestehender Komponentenownership in fokussierte Stylesheets für Controls/Forms, Feedback/Modals, Tabellen/Listen und Daten-/Seitenelemente zerlegt. Regeln werden nicht umbenannt oder semantisch verändert; Selektor, Deklarationsreihenfolge und Kaskadenergebnis werden per CSS-AST-/Browservergleich sowie den vorhandenen Modal-, Klassen- und Spacing-Contracts gepinnt.
- `layout_app_styles()` (Name nach lokaler Konvention) ist die einzige cachegebustete Ladereihenfolge und wird von normalem Portal sowie Login verwendet. `status.css` bleibt nach seinen gleich spezifischen Basissheets; keine `@import`-Kette umgeht Versionierung oder Air-Gap. CSS-Class- und Farbguards globben alle Portalstyles und besitzen einen Negativfall für eine neue, nicht registrierte Datei.
- Erst nach berechneter Style-/Geometrie-/Visualparität des reinen Splits beginnt der Tokenumbau.

1. Beide Themes erhalten eine gemessene Slate-/Indigo-Identität. Info-Blau bleibt vom Akzent unterscheidbar; Sidebar nutzt eigene Tokens und ist von der Inhaltsfläche getrennt. Datenflächen bleiben opak, solide Buttons verwenden `--btn-bg`/`--btn-fg`, Danger bleibt solide und semantisch unverändert.
2. Glows, Schatten und Glas werden dezenter; maximal zwei Blur-Ebenen. Der vorhandene opake `@supports not (backdrop-filter)`-Fallback wird angepasst und getestet, kein zweiter Fallback aufgebaut. Keine externen Assets.
3. Ein tokenisierender/parsenden Static-Guard verbietet außerhalb `base.css` Hex, RGB/HSL/Lab/LCH/OKLCH/`color()`, gewöhnliche Farbnamen und rohe Farben in Gradients, Shadows, `var()`-Fallbacks, verschachtelten Funktionen und dekodierten Data-URLs. Kommentare/normale Strings bleiben Nichttreffer. Erlaubt sind Tokens, `transparent`, `currentColor`, CSS-weite Keywords und ausschließlich tokenbasiertes `color-mix()`.
4. Systemfarben sind innerhalb einer ausdrücklich getesteten `@media (forced-colors: active)`-Policy in passenden Paaren erlaubt; ein pauschales Verbot benannter Farben wäre normwidrig. Der Guard erhält positive, negative, Mutation- und Zero-Match-Fixtures sowie stabile Diagnose-IDs und läuft genau einmal über die bestehende PHPUnit-Suite.
5. Browsernachweise prüfen beide Themes, Inhalts-/Panel-/Glastext, Badges, Fokus, primäre/Danger-Buttons und den opaken Fallback. Normaltext erreicht 4,5:1; große Schrift 3:1 nur ab mindestens 24 CSS-px regulär oder ungefähr 18,66 CSS-px fett; erkennbare UI-/Grafikteile erreichen 3:1 gegen angrenzende Farben.
6. Fokus erfüllt WCAG 2.2 AA einschließlich Nicht-Verdeckung und Non-text Contrast; zusätzlich gilt Focus Appearance als Projektziel: kontrastierende Fläche mindestens entsprechend einer 2-CSS-px-Umrandung und 3:1 Zustandsänderung. Transparente Flächen werden gegen den tatsächlichen Worst-Case-Backdrop alpha-komponiert oder deterministisch pixelgemessen; isolierte Computed-Style-Farben genügen nicht. Forced Colors erhält eine eigene Gegenprobe.

Etappenabschluss:

- Farbguard samt Mutation, Browserkontrast, Farb-nicht-allein-Gegenprobe, axe, Forced Colors, Fast/Integration und Visualvergleich gegen Etappe 11 sind grün.
- Der CSS-Split besitzt Kaskaden-/Computed-Style-/Geometrieparität vor dem Designhunk; alle Stylesheets sind zentral registriert und der frühere `components.css`-Monolith ist aufgelöst.
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

### Strukturelle Refactoring-Parität

| Nachweis | Beweis |
|---|---|
| `check-file-size.php` + Guard-Fixtures | neue/gewachsene Oversize-Datei rot; kleine Datei und Zero-Match grün; Legacy-Ausnahmen begründet und nur schrumpfend |
| Repo-Fassaden-Contract | alte Require-Pfade laden alle bisherigen öffentlichen Funktionen; neue Module bleiben unter 400 Zeilen; keine Zyklen/doppelten Definitionen |
| Deploy-Repo Vorher/Nachher | Payload/Schedule, Queries/Logs, Queue/Cancel, Claim/Heartbeat/Finish, Reaper/Purge/Sweep verhalten sich identisch vor Etappe 2 |
| Worker Vorher/Nachher | CLI-Entry, Mission/Inventory, Stream, Reaper, VM-State, Audit/Finalisierung, Exitcodes und Logs bleiben vor Etappe 2/8 identisch |
| ESXi Repo/Service Vorher/Nachher | Cache, State/Pause, Queries, VLAN, Scheduler, Deviations und Summary behalten Prepared SQL, Transaktionen, Ergebnisse, Renderwerte und Logs vor Etappe 5 |
| Ansible Vorher/Nachher | Artifact/Parser/Capability/Host sowie Mode/Marker/Preflight/Command behalten Parsing, Quoting, Marker, Logzeilen und Require-Closure vor Etappe 7/8 |
| PowerShell-Logging Vorher/Nachher | Server-/Client-Dot-Source, Funktionssignaturen, Installer-/Packagingdateimenge und Report-Wire bleiben vor Etappe 10D vollständig; extrahierte Loggingmodule werden atomar mitgeführt |
| Runner-Golden-Contract | `check.ps1` behält Parameter, Exitcodes, Gate-IDs/-Reihenfolge, Lane-Auswahl, Progress und JSON-Schema; Module sind import-nebenwirkungsfrei |
| Portal-Owner-Registry | POST-, Confirm-, RBAC-, CSP-, Deep-Link- und Action-Inventories globben Seite plus Module; neue unregistrierte Partialdatei macht den Test rot |
| VM-Repo Vorher/Nachher | Legacy-/Machinefunktionen, Validierung, Save/Replace, MAC/MECM, Provenienz, Identity und Recovery behalten Transaktions-/Wireverhalten |
| CSS-Asset-/Kaskadenvertrag | zentrale Ladereihenfolge für Portal/Login; keine unregistrierte CSS-Datei; gleiche Computed Styles/Geometrie vor dem Designhunk |
| Help/Doku/Logs/Protokolle je Split | Ownership/Require-/QA-Doku aktualisiert oder konkrete Nichtbetroffenheit; keine Text-/Log-/Wireänderung im Strukturhunk |

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

### Joblog, Abbruch, Terminalergebnis und Protokolle

| Test | Beweis |
|---|---|
| Playbook-Grenz-/Cancel-CAS | jeder Modusschritt einzeln; Cancel vor/nach jedem Schritt, Doppel-/Gruppenabbruch, DB-Reconnect, Ownership-Verlust und letzter-Schritt-Race; kein weiterer Schritt nach angenommener Anforderung |
| Ausgabenormalisierung | ANSI/C0/DEL/ungültiges UTF-8, Zeilen-/Gesamtbytegrenze, exakt eine Kappungszeile, Heartbeat läuft weiter; historische Quellen ehrlich benannt |
| Secret-Sentinel | kein Sentinel in Remoteausgabe, Worker, DB, JSON, HTML, Browser, Rohdownload, Audit-, PHP- oder Containerlog; `-vvv`, Fehler und Timeout enthalten |
| Remote-Artefakt-Sweep | normaler Cleanup, abgebrochener Lauf, Alters-/Owner-/Präfixprüfung, Symlink/Traversal/fremder Pfad und gedrosselte Meldung |
| Tail-/Drain-Cursor | mehr als 1.000 initial und mehr als 500 nachgeliefert; `has_older`, `has_more`, `caught_up`, Terminalrückstand, paralleler Append, keine Lücke/Duplikate |
| Rohdownload/Retention | vollständige retained Reihenfolge bei bounded Memory/DOM; RBAC, sichere Header/Dateinamen, Redigierung und ehrlicher Purgehinweis |
| Terminalergebnis | Statuskombinationen, `result_json`, Terminalcode/detail, Cancelakteur/-zeit, gelöschter Benutzer, echter Fehlerfallback; Cancel nie als „Letzter Fehler“ |
| Audit-Eventregistry | jeder first-party Producer inventarisiert; Codes/Objekte/Ergebnisse exhaustiv; Kontext allowlist-/bytebegrenzt und redigiert; Legacyzeilen bleiben lesbar |
| Legacy-Tokenpfad | `addLog()` Zero-Caller und entfernt; Guard findet Tokenparameter/-werte in Audit-/Log-Sinks mit positiven, negativen und Zero-Match-Fixtures |
| CSV-Kappung | 0/1/9.999/10.000/10.001; Tabelle/Filter/CSV gleich; sichtbarer Hinweis, Responseheader und genau ein strukturiertes Export-Audit |
| PowerShell-Logvertrag | Server-/Clientformat, Dateiname, Korrelation, Bounds, 29/30/31-Tage-Retention, parallele Prozesse, Sinkausfall-Drosselung/Recovery, Installer und Packaging |

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
| Statusanzeige/Joblog | Konstanten-Walk DE/EN und Unknown; sieben Jobzustände; additives `label`; Rohfelder unverändert; Poll-Single-Flight und Sessionende; Follow/Scrollpause/Neue-Zeilen-Zähler, Visibility-Catch-up, Live/Offline/Letzte Aktualisierung, Filter/Wrap, Phasen, Cancelaktionen und Terminalblöcke |
| Formulare | vollständige Migrationsmatrix; keine toten `aria-describedby`; keine doppelten IDs; Fehler-/Erfolgszustand; dynamische Zeilen; axe/Keyboard |
| Navigation/Tabellen | Seitennavigation mit `aria-current`, kein falsches Tabwidget; Sticky-/Wrap-/Scroll-/Mobile-Geometrie; fünf Statuskarten |
| Katalog/Logs | echte vs. gefilterte Leere; Sortierzustand; DST/Invalid-Input; keine Query bei Fehler; strukturierte Auditfilter; CSV/Pagination/Kappung; exakte kopierbare bounded Korrelation und RBAC |
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

Für Etappen 8 und 10A–10D beweist die echte QA-Schicht zusätzlich:

- einzelne Playbookschritte, Abbruch-CAS und alle Terminalwriter halten Ownership, Status, letzte SYSTEM-Zeile und „keine Logs nach terminal“ atomar zusammen;
- Tail/Older/Forward-Cursor, Terminaldrain, Rohdownload und Retention arbeiten gegen echte Repositoryabfragen auch oberhalb beider früherer Limits;
- Schema/Fresh-Migration für Terminalgrund und strukturierte Audits konvergiert; Cancel-/Resultat-/Fehleranzeige folgt ausschließlich dem zentralen Presenter;
- Audit-CSV-Kappung und Exportaudit stimmen bei echten Counts; Heartbeats bleiben auditfrei und Fehler gedrosselt;
- PowerShell-Server-/Clientlogging, Installer und Packaging erfüllen denselben statisch geprüften Vertrag ohne Änderung der MECM-Wire-Payloads.

Für Etappen 11 bis 17 beweist die echte QA-Schicht zusätzlich:

- Deploy-Blocker-Endpunkt und Queue-POST verwenden denselben normalisierten Zustand und dasselbe Repo-Gate;
- Polling liefert auch bei Sessionablauf/Berechtigungsfehler ausschließlich den vereinbarten JSON-Vertrag; Follow-/Visibility-Zustände erzeugen weder Parallelrequests noch Auditspam;
- kombinierte strukturierte Logfilter, lokale DST-Grenzen, CSV-State/-Kappung und exakte Korrelationsnavigation arbeiten gegen echte Repositoryabfragen;
- Korrelationsmigration konvergiert aus Alt- und Frischschema und die Ergebnisliste bleibt begrenzt;
- Visualprojekt läuft ausschließlich gegen den synthetischen QA-Stack und stellt Workerzustand zuverlässig wieder her.

Da die wichtigsten Nebenwirkungen nur gegen die Datenbank beweisbar sind, sind grüne Integrationstests verpflichtend. Ist der QA-Stack nicht verfügbar, ist die Umsetzung nicht vollständig abgenommen; die Sitzung meldet den Infrastrukturblocker statt die Tests optional zu nennen. Ein kontrollierter realer MySQL-Neustart mit laufendem Test-SSH-Stream gehört in die Release-/Staging-Abnahme, nicht in einen Unit-Test und nicht gegen produktive Jobs.

---

## 11. QA und Abnahme

### Entwicklungszyklen

Jede Etappe ist ein eigener Entwicklungs- und Abnahmezyklus: Implementierung, gezielte positive/negative/Zero-Match-Tests, Help/Doku, Logs/Protokolle, vollständiger Diff-Abgleich und Eintrag im Abnahmeprotokoll. Die nächste Etappe beginnt erst nach dem zusätzlich bestätigten Commit-/Push-Abschluss. Der gezielte Integrationstest folgt bereits in der Etappe, die die Pause-Nebenwirkung ändert; er wird nicht bis zur finalen Integration-Lane aufgeschoben. Keine reale Wartezeit für Budgets, kein echter SSH-/SFTP-Server für die reinen Klassifikationsfälle.

Für jeden bekannten mehrteiligen Prüfumfang gelten die Fortschrittszeilen aus `AGENTS.md`. Vor einem langen Lauf über einen gepufferten Transport wird ein live pollbares Log eingerichtet oder der Lauf in beobachtbare Blöcke geteilt. Mindestens einmal pro Minute wird die tatsächlich zuletzt beobachtete `[n/total]`-Runnerzeile gemeldet; vor der ersten Zeile gilt bei bekanntem Gesamtumfang `[0/total]`. Eine reine Zeitmeldung ist kein Fortschrittsstand. Das Abnahmeprotokoll nennt den letzten vollständigen Stand und jede fehlgeschlagene Einheit; eine bloße Aussage „Tests laufen“ oder „grün“ genügt nicht.

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
- Release enthält einen synthetischen langen Joblog-/Terminaldrain-Drill, die Cancel-Race an jeder Playbookgrenze, Rohdownload oberhalb des DOM-Limits und den vollständigen redigierten `-vvv`-Sentinelpfad; weder Sentinelwert noch reale Secrets erscheinen im QA-Artefakt;
- die PowerShell-Pester-Gates prüfen Server-/Client-Logparität, Sinkausfall-Drosselung sowie Installer-/Packagingvollständigkeit; Heartbeat-/ReportRun-Payloads bleiben unverändert und auditfrei pro Heartbeat;
- Release führt das Visualprojekt im deklarierten Baseline-Runner aus, ohne Snapshot-Update; Runner-/Fontmetadaten stimmen exakt;
- keine feste Testanzahl;
- keine geduldeten roten Tests;
- keine neue CSP-/Zeilenbudgetwarnung;
- alle im Refactoring-Vertrag benannten PHP-Hotspots sind aus der Legacy-Ausnahmeliste entfernt; keine neue/extrahierte Seite oder kein Modul überschreitet 400 physische Zeilen;
- `git diff --check` grün;
- DE/EN-Hilfe im Portal stichprobenartig sichtbar, ohne hartkodierte Dev-Zugangsdaten in dieser Planung;
- manueller PRE-SHIP-Nachweis für Tastatur, Fokus, Screenreader-Stichprobe einschließlich höflichem Joblog-Liveverhalten, Hell/Dunkel, Follow-Pause, Wrap/Mobile und Screenshots ohne reale Daten.

Die Fast-Lane enthält bereits PHPStan, Unit/Static mit vollem Repo-Mount, Sprach-, Bounds-, Doku- und CSP-Gates. Diese Prüfungen werden nicht als scheinbar zusätzliche Einzelbeweise dupliziert.

---

## 12. Reihenfolge

Jede nachfolgende Etappe endet zwingend mit dem vollständigen Commit-/Push-Abschluss aus Abschnitt 0. Die Formulierung wird in den einzelnen Zeilen nicht wiederholt, ist aber weder optional noch bis zum Ende mehrerer Etappen aufschiebbar.

1. Arbeitsbaum und Zielfunktionen erneut lesen; den fortgeschriebenen Basisstand im Protokoll prüfen. Die aktuelle Session übernimmt vorhandene Hunks und setzt nichts zurück.
2. Etappe 1 ist fachlich abgeschlossen: Die vorhandene grüne Abnahme für Fast-Blocker, File-Size-Guard, CLI-Require-Vertrag und `deploy_jobs`-Repo-Split bleibt unverändert; die Implementierung wird nicht erneut ausgeführt oder um neue Anforderungen erweitert. Vor Fortsetzung bis zum Abschluss von Etappe 2 wird ausschließlich der in Abschnitt 0 definierte Etappe-1-Nachtragscommit samt erforderlicher Überlappungsgegenprüfung und Push abgeschlossen.
3. Etappe 2 ist begonnen: vorhandenen Worker-/Outcome-Split und Diff vollständig lesen, dann den bereits geplanten aktiven DB-Kanal, Reconnect, bounded Logspool, Ownership und die ausschließlich beobachtende Reapermeldung fertigstellen; danach Unit/Integration, Help/Doku/Logs/Protokolle, Soll/Ist-Abgleich und Abnahmezeile. Keine Arbeit aus 8 oder 10A–10D wird rückwirkend in diese Etappe gezogen.
4. Etappe 3: Ansible-Aktivitätsquery mit `attempts > 0`, vorsichtige Anzeige, Migration/Index und Query-Plan; danach Unit/Integration/E2E, Help/Doku/Logs/Protokolle, Soll/Ist-Abgleich und Abnahmezeile.
5. Etappe 4: Disk-Label-SSoT und faktisch belastbare DE/EN-Hilfe; danach Unit/Static/Lang/Golden, Doku-/Audit-/Wire-Abgleich und Abnahmezeile.
6. Etappe 5: zuerst verhaltensgleicher ESXi-Inventory-Repo-Split, dann Vokabularvertrag mit negativen Fixtures, betroffenen Texten/Hilfe/Doku, Changelog-Anteil und Logs-/Protokollprüfung; danach Soll/Ist-Abgleich und Abnahmezeile.
7. Übergabe zwischen Etappe 5 und 6: den separaten AD/LDAPS-Feature-Branch reviewen und ohne Force integrieren; danach Migration `0040`, deaktivierten Default, grünen internen Code-Nachweis und den weiterhin offenen Ziel-AD-Aktivierungsgate gegen den Baseline-Vertrag aus Abschnitt 0 prüfen. Erst dann Etappe 6 auf der neuen Baseline beginnen.
8. Etappe 6: Exception-Datei, Require-Contract und alle SSH-/SFTP-Producer einschließlich abgeschlossenem `ssh.php`-Domänensplit samt Hilfe-/Doku-/Logwirkung; danach Soll/Ist-Abgleich und Abnahmezeile.
9. Etappe 7: zuerst Ansible-Inventory-Parser-/Artifact-Split, dann gemeinsame dependency-arme Ansible-Abbildung samt Verbrauchertexten, Audit-/Pausewirkung und Betriebsdoku; danach Soll/Ist-Abgleich und Abnahmezeile.
10. Etappe 8: zuerst Ansible-Command- sowie Missions-/Inventory-Prozessor-Split, dann Phasen, Throwable-Wiring, Preflight, Playbook-Klassifikation und Pause. Anschließend einzelne Remote-Playbookschritte, Ownership-/Cancel-CAS, Ausgabe-/Volumenhärtung, Markerphasen, Remote-Artefaktsweep und vollständiger Secret-Sentinel auf Basis des DB-Kanals; gezielte Unit/Integration sowie Help/Doku/Logs/Protokolle, Soll/Ist-Abgleich und Abnahmezeile.
11. Etappe 9: sichtbare Portalzweige, handlungsfähige Links und Zugangstest samt Help/Doku/Log-/RBAC-Abgleich; danach Soll/Ist-Abgleich und Abnahmezeile.
12. Etappe 10: Betriebsabnahme und getrennter Deploy-QoL-Hunk samt aktualisierter Hilfe/Doku/Logs/Protokolle; danach Soll/Ist-Abgleich und Abnahmezeile.
13. Etappe 10A: Repositorycursor, echter Initial-Tail, vollständiger Terminaldrain, Older-Pagination, bounded DOM-Grundvertrag und vollständiger autorisierter Rohdownload; danach Limit-/Race-/Retention-/RBAC-Integration, Help/Doku/Logs/Protokolle und Abnahmezeile.
14. Etappe 10B: additive Terminalgrundmigration, `result_json`-/Cancel-/Fehler-SSoT, zentraler Presenter, genau eine Cancel-SYSTEM-Zeile und Rückkehr zum selben Log; danach Schema/Unit/Integration/E2E, Help/Doku/Logs/Protokolle und Abnahmezeile.
15. Etappe 10C: Audit-Producerinventar, additive Event-/Objekt-/Ergebnis-/Kontext-SSoT, Entfernung von `addLog()`, Token-Sink-Guard und sichtbare/auditierbare CSV-Kappung; danach Migration/Guard/Integration, Help/Doku/Logs/Protokolle und Abnahmezeile.
16. Etappe 10D: gespiegelter Server-/Client-PowerShell-Logvertrag, lokale Loggingmodule, Retention, Sinkstörungsdrosselung sowie Installer-/Packagingparität ohne Wireänderung; danach Pester/Packaging/Upgrade, Help/Doku/Logs/Protokolle und Abnahmezeile.
17. Etappe 11: kollisionssichere UX-Basis, verhaltensgleicher `check.ps1`-Modulsplit, gemeinsamer Browserresolver und isolierter Visual-Harness; danach Runner-Golden-/Fast-/Determinismusnachweise, Help/Doku/Logs/Protokolle und Abnahmezeile.
18. Etappe 12: zuerst Deploy-/Settings-Seiten- und Deploy-JS-Splits unter Erhalt der AD-/HTTPS-Kopplung und sämtlicher Action-Scanner, dann Passwort/Dauer/`updated`, vollständige Live-Deploy-Blocker, Missionslinks und Help-URL-SSoT; danach gezielte Unit/Static/E2E, Help/Doku/Logs/Protokolle und Abnahmezeile.
19. Etappe 13: zuerst Layout-/Systemstatus-/Credentials-Splits unter Erhalt des Directory-Panels, dann lokalisierter Portal-Zustandswortschatz, robuster JSON-Poller und die auf 10A/10B aufbauende Joblog-UX mit Follow, Scrollpause, Visibility-Catch-up, Livezustand, Phasen, Suche/Filter/Wrap und live aktualisiertem Cancel-/Terminalblock; danach Unit/Integration/Visual, Help/Doku/Logs/Protokolle, unveränderte Roh-/Wire-Verträge und Abnahmezeile.
20. Etappe 14: zuerst VM-Repo-/VM-Editor-Splits, dann Formular-Migrationsmatrix und gemeinsame Accessibility-API einschließlich Settings-Partials; Directory-Konfiguration, Zuordnungsformulare und lokale Konten bleiben vollständig in der Matrix. Danach DOM/axe/Keyboard/Integration, Help/Doku/Logs/Protokolle und Abnahmezeile.
21. Etappe 15: Navigation, Sticky-Tabelle, Statusübersicht, Kataloge sowie strukturierte Audit-/exakte Korrelationsfilter mit Kopieraktion und CSV-Kappungsparität; Directory-Auditkategorien und RBAC-Zweige bleiben erhalten. Danach Schema/EXPLAIN, Unit/Integration/E2E, Help/Doku/Logs/Protokolle und Abnahmezeile.
22. Etappe 16: zuerst kaskadengleicher `components.css`-Split und zentrale Style-Registry, dann Slate-/Indigo-Tokenumbau, Glas, parserbasierter Farbguard und zusammengesetzter Kontrast; danach Mutation/Forced-Colors/Visual, Help/Doku/Logs/Protokolle und Abnahmezeile.
23. Etappe 17: reviewte Visual-Sollbaselines und Release-Gate; danach PRE-SHIP, Help/Doku/Logs/Protokolle und Abnahmezeile.
24. Fast-Lane vollständig und mit `[n/total]`-Fortschritt grün.
25. Integration-Lane vollständig und mit `[n/total]`-Fortschritt grün.
26. Release-Lane, Visualprojekt sowie kontrollierten Staging-DB-, langen Joblog-, Cancel-Race- und Secret-Sentinel-Drill vollständig und mit `[n/total]`-Fortschritt grün. Die AD/LDAPS-Produktivfreigabe bleibt davon getrennt und verlangt zusätzlich das vollständig grüne Ziel-AD-Protokoll.
27. Unabhängiger Gesamtabgleich über Befunde, Etappenprotokolle, AD/LDAPS-Baseline und lebende Dateiliste. Gefundene Lücken öffnen die verursachende Etappe erneut; anschließend deren gezielte Tests und betroffene Lanes wiederholen.
28. Der Gesamtabgleich erzeugt keinen Sammelcommit für vergessene Arbeiten. Findet er einen notwendigen Hunk, wird dessen verursachende Etappe wieder geöffnet, erneut vollständig geprüft, separat committed und gepusht; abschließend müssen alle eigenen Planänderungen in den protokollierten Upstream-Hashes enthalten sein.

---

## 13. Vollständige Dateiliste

Voraussichtlich betroffen:

| Datei | Änderung |
|---|---|
| `Docker/WebAPI/lib/defaults.php` | typisierte exhaustive Disk-Label-SSoT; EZT-Default bleibt |
| `Docker/WebAPI/lib/deploy_worker_db_channel.php` | neu: veränderbare aktive DB-Verbindung, Backoff und bounded Logspool |
| `Docker/WebAPI/lib/deploy_constants.php` | neue Herkunftskategorien, Herkunfts-/Pause-Prädikate, Terminalgrund-/Statuskombinationen sowie Joblog-Zeilen-/Volumen-/Cursorbounds |
| `Docker/WebAPI/lib/ssh_transport_exceptions.php` | neu: Budget-, SFTP- und lokale Transportkonfigurationstypen |
| `Docker/WebAPI/lib/connection_errors.php` | gemeinsame exhaustive Ansible-Abbildung |
| `Docker/WebAPI/lib/ssh_sftp.php` | neu aus `ssh.php`: SFTP-Producer, Operations-Guard, Gesamtbudget und Probe |
| `Docker/WebAPI/lib/ssh.php` | SSH-Producer, Modul-Require und Zugangstest-Mapping |
| `Docker/WebAPI/lib/deploy_worker_outcome.php`, `deploy_worker.php` und neue Worker-Domänenmodule | kleine Fassaden; Runtime/Stream, Reaper, VM-State, Audit, Mission/Inventory und Klassifikation unter Budget; DB-Kanal, einzelne Playbookschritte, Cancel-CAS, Ausgabenormalisierung, Terminalwriter und Remote-Artefaktsweep |
| `Docker/WebAPI/lib/maintenance_worker.php` | identischer Observer-/Reapervertrag |
| `Docker/WebAPI/lib/integration_health.php` | aktueller Dienststatus bleibt Beobachtung, keine Besitzerursache |
| `Docker/WebAPI/lib/repo/ansible_activity.php` | letzter vom Worker bearbeiteter Missionsjob, `attempts > 0` |
| `Docker/WebAPI/lib/repo/deploy_jobs.php` | kleine Kompatibilitätsfassade; bisheriger öffentlicher Require-Pfad bleibt |
| `Docker/WebAPI/lib/repo/deploy_job_{input,queries,guards,worker,queue,cancel,maintenance}.php` | Splitbestand aus Etappe 1; in 8/10A/10B erweitert um Schritt-/Cancel-CAS, Tail/Forward/Older-Cursor, atomare Terminalzeile, Cancelmetadaten und Terminalgrund |
| `Docker/WebAPI/lib/ansible_inventory.php` plus Parser-/Artifact-Domänenmodule | Fassade unter Budget; Parser-/Logparität; `ansible_config`, Privilege-Escalation-Timeout |
| `Docker/WebAPI/lib/ansible_command.php` plus Mode-/Marker-/Preflight-/Commandmodule | Fassade unter Budget; Modefolge, geordnete Einzelschrittdeskriptoren, Quoting, Marker und Preflightverhalten |
| `Docker/WebAPI/lib/repo/esxi_inventory.php` | kleine Kompatibilitätsfassade; bisheriger Require-Pfad bleibt |
| `Docker/WebAPI/lib/repo/esxi_inventory_{cache,state,queries,vlan}.php` | neu: Cache-/Pause-/Query-/VLAN-Domänen; Fehlerwirkung anschließend über SSoT-Helper |
| `Docker/WebAPI/lib/esxi_inventory.php` plus Scheduler-/Deviation-/Displaymodule | Servicefassade unter Budget; Credentialauflösung, Abweichungen, Summary/Ampel und Due-Enqueue getrennt |
| `Docker/WebAPI/lib/system_status_esxi_panels.php` | Ansible-Statuslink über URL-SSoT; Joblog-RBAC bleibt getrennt |
| `Docker/WebAPI/lib/system_status_panels.php` und neue source-spezifische Panelmodule | unter 400 Zeilen; vorsichtige Ansible-Aktivitätsanzeige und bestehender Log-Deep-Link |
| `Docker/WebAPI/lib/help/deploy.php` | sichtbarer Disktyp über `disk_type_label()` |
| `Docker/WebAPI/lib/help/missions.php` | Defaultlabel und belastbare Disk-Erklärung |
| `Docker/WebAPI/portal/credentials.php` plus Credentials-Actions/-Renderer | Seite unter 400 Zeilen; Ansible-Aktivitätsnachweis ohne Statuskopie; POST-/Auditvertrag unverändert |
| `Docker/WebAPI/lib/credentials_test_message.php` | Vokabular-Ownership im Docblock richtigstellen; Mapping bleibt zentral |
| `Docker/WebAPI/lang/{de,en}/common.php` | neun neue Basissätze, Legacy-Text |
| `Docker/WebAPI/lang/{de,en}/help_system_status.php` | neun Fixtexte, Pause-/Link-/Timeout-Korrekturen |
| `Docker/WebAPI/lang/{de,en}/system_status.php` | neues Linklabel `inv_open_ansible_status` |
| `Docker/WebAPI/lang/{de,en}/help_deploy.php` | präziser Identity-/Timeout-Hinweis |
| `Docker/WebAPI/lang/{de,en}/help_credentials.php` | manueller Volltest vs. bearbeiteter Missionsjob |
| `Docker/WebAPI/lang/{de,en}/help_missions.php` | storageabhängige Disktypen ohne Performance-Absolutaussage |
| `Docker/WebAPI/lang/{de,en}/vm_edit.php` | erster Schreibzugriff, Default und nur Create-Wirkung |
| `Docker/WebAPI/tests/Static/CliRequireClosureContractTest.php` | vollständiger CLI-Entry-Point-Vertrag inklusive `seed.php` |
| `Docker/WebAPI/tests/Static/FileSizeDisciplineContractTest.php` bzw. Guard-Contract | neue/berührte PHP-Dateien unter Budget; Legacy-Ausnahmen begründet und schrumpfend |
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
| `scripts/check.ps1` und `scripts/lib/check/*.ps1` | Entry-Point-Vertrag bewahren; Runtime/Tools, Gate-Registry und Lanes trennen; Chromiumresolver und Visual-Gate |
| `scripts/check-file-size.php` und `scripts/test-guards.ps1` | neuer ADR-0006-Guard samt positiver, negativer und Zero-Match-Fixtures |
| `tests/e2e/playwright.config.js` | isoliertes Visualprojekt, deklarierter Runner, Metadaten, Viewports/Themes und bestehende funktionale Projekte |
| `tests/e2e/package.json` und Lockdatei | nur falls für pixelbasierten Vergleich/Resolver tatsächlich nötig; keine Runtime-Abhängigkeit |
| `tests/e2e/lib/{auth,visual-seed,visual-runner}.js` bzw. lokale Konvention | bestehende Auth wiederverwenden; synthetischer Seed, Uhr/Locale/Fonts/Worker-Restore zentralisieren |
| `tests/e2e/visual/**` und Baselineverzeichnis | Visualspecs, deterministische Fixtures, reviewte Sollbilder und Metadaten; keine echten Daten |
| `Docker/WebAPI/lib/deploy_page.php` | diskriminierte `deploy_queue_blockers()`-SSoT einschließlich Aktionen und Konfliktdaten |
| `Docker/WebAPI/lib/deploy_form_state.php` | exakt ein normalisierter Formularzustand für Render, Blocker-JSON und Queue-POST |
| `Docker/WebAPI/portal/deploy.php`, `lib/deploy_{actions,page_model,...}.php` und neuer read-only Blocker-JSON-Pfad | Seite/Module unter Budget; Anzeige/Queue aus derselben Blockerliste; JSON-Auth/RBAC/Content-Type; Repo-Recheck; validierte Cancel-Rückkehr zum selben Joblog |
| Deploy-JS-Module unter `Docker/WebAPI/portal/assets/` | Poller, Joblog-Follow/Visibility/Filter/Wrap, Formular/Blocker und Storage getrennt; Single-Flight, Cursor-/stale-Abwehr, bounded DOM, lokalisierte Auth-/Netzfehler und Live-Aktionen |
| `Docker/WebAPI/portal/settings.php`, `lib/settings_{actions,view_model}.php`, `lib/settings/*.php` | Seite/Module unter Budget; Action-/Tab-/Deep-Link-/Formvertrag bleibt vollständig |
| `Docker/WebAPI/lib/help_page.php` | neu: Panel-/Abschnitts-SSoT und validierter `help_url()` |
| `Docker/WebAPI/lib/layout.php`, neue `portal_{flash,format,badges,catalog_filter}.php`, `lib/help/*.php`, `portal/help.php` | Layout unter Budget; öffentliche Helper kompatibel; Headeranker, Dauer und Hilfeziele |
| `Docker/WebAPI/portal/{users,account,mission_details,vms,vm_edit}.php` | Passwortattribute/-labels, Bereitstellungslinks, `updated`, Titel, Sticky-Aktionen und Formularzuordnung |
| `Docker/WebAPI/lib/portal_status_display.php` | neu: lokalisierte Lifecycle-/MECM-Anzeige geschlossener Zustände |
| `Docker/WebAPI/lib/deploy_display.php` | neu: lokalisierte Jobstatus-/Deploymodus-/Payloadanzeige ohne technische Logmutation |
| `Docker/WebAPI/portal/deploy_log.php` plus fokussierte Joblog-Cursor-/Presenter-/Downloadhelper | echter Initial-Tail, Forward-/Older-Cursor, `has_more`/`caught_up`, Rohdownload, Phasen/Quelle, lokalisierte JSON-Fehler, früher Session-Lock-Abschluss sowie Cancel-/Resultat-/Terminaldarstellung |
| `Docker/WebAPI/lib/audit_events.php`, `lib/repo/log.php` und neue Audit-Registry/Presenterhelper | strukturierte Event-/Objekt-/Ergebnis-/Kontext-SSoT, Legacyfallback, Kontextbounds/Redigierung; totes tokenfähiges `addLog()` entfernen |
| `Docker/WebAPI/lib/forms.php` | gemeinsame IDs, Controlattribute und Fehlerausgabe für Hint-/Error-Zuordnung |
| `Docker/WebAPI/lib/repo/vms.php` und neue `vm_{validation,persistence,operations}.php`/Legacy-Domäne | Repo-Fassade unter Budget; Validierung, Transaktionen, Recovery und Machine-Verträge getrennt |
| `Docker/WebAPI/portal/vm_edit.php` und `Docker/WebAPI/lib/vm_edit_*.php` | Seite/Module unter Budget; Diagnostik, Gruppenrenderer und Aktionen getrennt |
| betroffene Portalformulare und JS-Zeilentemplates | vollständige Formular-Migrationsmatrix, eindeutige IDs und dynamisches `aria-describedby` |
| `Docker/WebAPI/portal/{missions,os,packages,vlans,logs}.php` | Seitennavigation, Katalogleerzustände/-filter, Zeitzone, strukturierte Auditfilter, exakte kopierbare Korrelation und sichtbare CSV-Kappung |
| `Docker/WebAPI/lib/system_status_panels.php` | fünfte Übersichtskarte mit gemeinsamem Abweichungs-Count und SSoT-Link |
| `Docker/WebAPI/lib/log_filter.php` | neu: validierter Filter-Struct, lokale Tagesgrenzen, Event-/Objekt-/Ergebnisfelder, exakte Korrelation und URL-State |
| `Docker/WebAPI/lib/repo/log.php` und Deploy-Job-Querymodule | strukturierter Logfilter, gezählte/auditierte CSV-Kappung und begrenzte/paginierte Korrelationssuche |
| `Docker/WebAPI/portal/assets/css/*.css` und zentrale Style-Registry | `components.css` nach Controls/Feedback/Tabellen/Daten splitten; Kaskadenreihenfolge, Tokens, Glass-Fallback, Forced Colors und keine rohen Verbraucherfarben |
| `Docker/WebAPI/lang/{de,en}/**` | Passwort-/Blocker-/Status-/Form-/Navigation-/Filter-/Kontrasttexte und Help-Parität pro verursachender Etappe |
| UX-Unit-/Static-Tests unter `Docker/WebAPI/tests/` | Blocker-, Help-, Status-, Form-, Filter-, CSS-Farb- und Kontrastverträge mit positiven/negativen/Zero-Match-Fällen |
| UX-E2E-Specs unter `tests/e2e/specs/` | Live-Blocker, Poller, Navigation, Form-DOM, Sticky/Wrap, Logfilter/RBAC, axe/Forced Colors und Visuals |
| Joblog-/Audit-Unit-, Static-, Integration- und E2E-Tests unter `Docker/WebAPI/tests/`/`tests/e2e/specs/` | Playbook-/Cancel-Races, Outputbounds/Sentinel/Cleanup, Tail/Drain/Download, Terminalpresenter, Auditregistry/Token-Guard, CSV-Kappung sowie Follow/ARIA/Visibility/Filter |
| `Docker/mysql/mysql-init/struktur.sql` | Aktivitätsindex aus Etappe 3, Fehlerkategoriebreite aus Etappe 5, Terminalgrund/Auditfelder aus 10B/10C und belegte Korrelationsindizes aus Etappe 15 als Frischschema-SSoT |
| `Docker/WebAPI/lib/migrate.php` | Migration 0039 gegen Query-Plan prüfen/anpassen; Fehlerkategoriebreite, additive Terminalgrund-/Auditfelder sowie begründete Korrelationsindizes spiegeln |
| `Powershell-MECM/mecm/VirtuSphere-Common.ps1` und lokales Server-Loggingmodul | Loggingdomäne unter Paritätsnachweis extrahieren; einheitliches Schema, Retention, Korrelation und gedrosselter Sinkstatus |
| `Powershell-MECM/clients/VirtuSphere-Client-Common.ps1` und lokales Client-Loggingmodul | getrennte ADR-0029-Implementation mit statisch gleichem Schema/Bounds/Retention und gedrosseltem Sinkstatus |
| `Powershell-MECM/install-VirtuSphere-{MECM,Clients}.ps1`, ClientPackaging und Paketdateilisten | Loggingmodule bei Neuinstallation, Upgrade und Clientpaket atomar kopieren und Version/Vollständigkeit sichtbar prüfen |
| `tests/powershell/VirtuSphere.*.Tests.ps1` | Server-/Client-Logparität, Dot-Source-/Funktionsinventar, Installer/Packaging, Sinkausfall/Recovery und unveränderte Report-/Heartbeatverträge |
| `Powershell-MECM/{README.md,clients/README.md}` | Format, Pfad, Retention, Korrelation, Sinkstörung und getrennte Runtimepakete dokumentieren |
| `docs/operations/esxi-inventory.md` | Tabelle, Heading, Logging, RBAC, Altbestand, Detailgrenze |
| `docs/operations/deploy-chain.md` | aktiver DB-Kanal, Observergrenze, einzelne Playbook-/Abbruchgrenzen, Terminal-/Joblogvertrag und belegbare Reaperdiagnose |
| `docs/operations/troubleshooting.md` | Containerlogpfad, Reaperbeobachtung, Tail/Drain/Retention, Cancelgrenze, Korrelation erst nach Etappe 15 und Wiederanlauf |
| `docs/DEPLOYMENT.md` | Worker-, Fehlerherkunfts-, Schritt-/Cancel-, Joblog-/Download-/Terminal- und unveränderten Wire-Vertrag abgleichen |
| `docs/QA.md` | neue Contract-/Integrationsnachweise und etappenweise Abnahme |
| `docs/adr/ADR-0006-file-size-discipline.md` | Guard, Kompatibilitätsfassaden, Owner-Glob und begründete Ausnahme-/Abbaupolitik |
| `docs/adr/ADR-0013-frontend-design-baseline.md` | Slate/Indigo, Token-/Glas-/Forced-Colors-/Kontrastvertrag und unveränderte Danger-Semantik |
| `docs/adr/ADR-0028-playwright-dev-e2e-layer.md` | Visualrunner, Browserresolver, Baseline-Metadaten und bewusster Updateworkflow |
| `docs/adr/ADR-0018-machine-report-channel-and-maintenance-worker.md` | strukturierte/gedrosselte Reportprotokolle ohne Audit je Heartbeat und unveränderte Wirefelder |
| `docs/adr/ADR-0022-portal-timezone-and-deploy-scheduling.md` | Gruppenabbruch bleibt queued-only; Cancelmetadaten normaler Jobs |
| `docs/adr/ADR-0026-log-retention-windows.md` | Joblog-/Rohdownload-/Audit-/CSV-/PowerShell-Retention und Kappung |
| `docs/adr/ADR-0032-correlation-id.md` | Portal-Korrelationssuche/-kopie/-export, RBAC/Retention, Begrenzung und belegte Indexannahmen |
| `docs/adr/ADR-0033-cancellation-state-machine.md` | einzelne Playbookgrenzen, Cancel-/Terminal-CAS, Observer/Reaper/Ownership, Metadaten und keine harte Standardtötung |
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
- Generische persistente `failure_code`-/`failure_phase`-Spalten für alle Jobs, globale Volltextsuche über alle Joblogs, Digest/Benachrichtigungen und Ersteinrichtungsassistent. Die eng definierte `terminal_reason_code`-SSoT aus 10B und die jobgebundene Suche aus Etappe 13 sind davon ausdrücklich nicht ausgeschlossen.
- Rückwirkende Übersetzung persistenter technischer Logs oder Änderung bestehender technischer JSON-/Machine-API-Felder.
- Breiter fachfremder Split von `Powershell-MECM/mecm/VirtuSphere-Common.ps1` sowie Größenzerlegung von `VirtuSphere.ErrorPaths.Tests.ps1` und `VirtuSphere.RunReport.Tests.ps1`. Etappe 10D darf ausschließlich die tatsächlich geänderte Logging-/Retention-Domäne unter vollständigem Pester-/Dot-Source-/Installer-/Packaging-Paritätsnachweis extrahieren; übrige Wire-/MECM-/Installerdomänen bleiben unberührt.
- Hartes Beenden des laufenden Ansible-Prozesses als normaler Cancelpfad. Es bleibt bei „aktuellen Schritt auslaufen lassen, keinen nächsten starten“; ein späterer Notfall-Kill bräuchte eine eigene Remote-Prozessgruppen-, Cleanup- und Operatorrisiko-Spezifikation.
- Zerlegung linearer SSoT-Registries wie `lib/migrate.php` oder `lib/constants.php` allein aufgrund der Zeilenzahl. Neue fachlich eigenständige Helper dürfen weiterhin extrahiert werden, ohne die geordnete Registry zu verteilen.

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
- POSIX definiert AND-OR-Listen so, dass die Shell den nächsten `&&`-Befehl nach erfolgreichem Vorgänger selbst ausführt; ein entfernter Gesamtbefehl bietet dem lokalen Worker dazwischen keine Abbruchgrenze: <https://pubs.opengroup.org/onlinepubs/9799919799/utilities/V3_chap02.html>
- AWX dokumentiert selbst für einen aktiven Prozessabbruch, dass bereits an Remotehosts ausgesandte Modultasks häufig bis zum Ende laufen; deshalb verspricht dieser Plan keinen rückwirkungsfreien Hard-Cancel: <https://docs.ansible.com/projects/awx/en/24.6.1/administration/troubleshooting.html#cancel-an-awx-job>
- Ansible dokumentiert `block`/`always` als Cleanupstruktur innerhalb eines laufenden Playbooks; ein harter Prozessabbruch wird daraus nicht als garantierter Cleanup abgeleitet: <https://docs.ansible.com/projects/ansible/latest/playbook_guide/playbooks_blocks.html>
- Ansible warnt, dass gespeicherte Ausgabe Geheimnisse enthalten kann und `no_log` Debugausgabe nicht schützt; die CLI beschreibt `-vvv` als erhöhte Diagnoseausgabe und `-vvvv` als mögliche Verbindungsdiagnose, nicht als garantierte Variablenanzeige: <https://docs.ansible.com/projects/ansible/latest/reference_appendices/logging.html> und <https://docs.ansible.com/projects/ansible-core/devel/cli/ansible-playbook.html>
- MDN dokumentiert die Rundungsdifferenz von `scrollTop` gegenüber `scrollHeight`/`clientHeight` und empfiehlt eine Bottom-Toleranz; die Page Visibility API liefert `visibilitychange` für Pause und Catch-up eines Hintergrundtabs: <https://developer.mozilla.org/en-US/docs/Web/API/Element/scrollHeight#determine_if_an_element_has_been_totally_scrolled> und <https://developer.mozilla.org/en-US/docs/Web/API/Page_Visibility_API>
- WAI-ARIA 1.2 definiert `role="log"` als geordnete Live-Region mit implizitem `aria-live="polite"`; der Plan verwendet deshalb keine aggressive `assertive`-Ansage: <https://www.w3.org/TR/wai-aria-1.2/#log>

Die externen Primärquellen und offiziellen Dokumentationen stützen Standards, Werkzeugverhalten, Taxonomie und Textgestaltung. Die konkrete VirtuSphere-Wirkung wird ausschließlich durch Repository-Code, Schema, Tests und Etappenprotokolle bewiesen.

---

## 16. Abnahmeprotokoll

Die ausführende Sitzung ergänzt je Etappe eine Zeile, bevor die nächste Etappe beginnt. Jedes Nachweisfeld enthält entweder einen konkreten Diff-/Test-/Pfadnachweis oder `nicht betroffen: <Begründung>`. Ein leeres Feld bedeutet nicht abgeschlossen. `Ergebnis = grün` ist nur zulässig, wenn der Soll/Ist-Abgleich keine offene Anforderung und keine auf später verschobene Help-/Doku-/Log-/Protokollarbeit enthält und das Feld `Commit/Push` Commit-Hash, Betreff, Remote/Branch, bestätigte Upstream-Hashgleichheit und Pushzeit nachweist.

| Etappe | Datum | Ergebnis | Soll/Ist und Diff | Tests/Gates | Help/Doku | Logs/Protokolle | Commit/Push | Abweichung/Begründung |
|---|---|---|---|---|---|---|---|---|
| Basis/Arbeitsbaum | 2026-08-11 | grün | HEAD `fd132ff`, Branch `main`, `git status --short` nur `M docs/audits/2026-08-11-deploy-reliability-master-plan.md`, `M docs/audits/2026-08-11-ux-implementation-plan-v4.md` (fremde Planaktualisierung, unangetastet) und `?? tests/e2e/shot.tmp.js`; `git diff --check` grün; die in Abschnitt 0 genannten Überlappungen (`deploy_constants.php`, `deploy_worker*.php`, help/system_status-Kataloge, `struktur.sql`, `migrate.php`) sind in diesem Arbeitsbaum **nicht** verändert, der Bestand ist committet | `migrate.php --check`: `pending=1`, `pending 0039_ansible_activity_index`, sonst `ok`; Migration bewusst nicht angewandt (gehört zu Etappe 3) | nicht betroffen: Bestandsaufnahme ohne Code-/Textänderung | nicht betroffen: keine Ausführung, kein Audit-/Job-/Containerlogeintrag erzeugt | historischer Basiscommit `fd132ff`; vor Einführung des etappenweisen Pushvertrags | Fremde Änderungen an beiden Planfassungen bleiben erhalten; es wurde nichts gestaged und nichts zurückgesetzt |
| Refactoring-Inventar/File-Size-Guard | 2026-08-11 | grün | Bestand `find lib portal -name '*.php' \| xargs wc -l`: 23 Dateien über 400 Zeilen, größte `lib/repo/deploy_jobs.php` (1220). Neu `scripts/check-file-size.php` (Scope `lib/`+`portal/`, Budget 400, `FILE_SIZE_ALLOWANCES` mit exakter Größe/Grund/Abbauetappe je Eintrag), Gate `file-size` in allen drei Lanes von `scripts/check.ps1`, Zweig in `.claude/hooks/session-start.sh` | Guard-Harness `file-size.*`: 6/6 proven (green, small-file-allowed, oversize, grown, stale-allowance, zero-match); Fast-Gate `[12/28] pass file-size` | ADR-0006 Amendment 2026-08-11 (Guard, Ratchet in beide Richtungen, Fassadenregel, Owner-Glob-Folgerung); `docs/QA.md` Drift-Checks-Abschnitt; `AGENTS.md` Skriptliste; `docs/CHANGELOG.md` | nicht betroffen: reiner Build-Guard, schreibt kein Audit, keinen Joblog und keine Containerlogzeile im Betrieb; Diagnose-IDs `[file-size.*]` sind CI-Ausgabe | `2148249` „Ein Datenbankausfall beendet keinen laufenden Deploy mehr, und der Reaper sagt nur noch, was er gesehen hat", `origin/main` (`github.com/Samy94M/VirtuSphere-v2-WebApp`), `fd132ff..2148249`, Upstream-Hashgleichheit bestätigt (lokal = remote = `2148249eb41d910353b37f6898dcb577a99b1ec4`), Push 2026-08-12T09:59:26Z | Die Ausnahmeliste startet mit dem hier protokollierten Bestand (23), nicht automatisch pro Lauf; nach dem Etappe-1-Split sind es 22 |
| 1 Fast/CLI/Hygiene | 2026-08-11 | grün | (1) `disk_type_label()` mit `@param 'thin'\|'thick'\|'eagerzeroedthick'`, `match` weiterhin ohne `default`; (2) `CliRequireClosureContractTest` leitet die Entry-Point-Menge aus dem Top-Level-CLI-Wächter ab, `DUAL_SAPI` nur für `lib/migrate.php`, Gegenprüfung über alle `php .../lib/*.php`-Aufrufe in Compose/Healthcheck/Setup; gefundene echte Lücke behoben (`lib/repo/log.php` requirt jetzt `lib/lang.php`); (3) QA-/Regeltext auf den bewiesenen Vertrag korrigiert; (4) `tests/e2e/shot.tmp.js` entfernt, keine fremde ungetrackte Datei angefasst; (5) Basis protokolliert (Zeile oben); (6) File-Size-Guard (eigene Zeile); (7) `lib/repo/deploy_jobs.php` 1220 → 50 Zeilen Fassade plus `deploy_job_{input,queries,guards,worker,queue,cancel,maintenance}.php` (251/184/172/110/277/145/204 Zeilen) und Owner-Registry `deploy_job_modules.php` | Fast-Lane 28/28 pass, 0 fail/skip (`qa-artifacts/qa-e1-fast.json`); PHPUnit unit/static 808 Tests/19085 Assertions ohne Skip mit vollem Repo-Mount; Integration 246 Tests (1 Skip: `DeployJobReaperTest` überspringt bei fremden laufenden Jobs im Dev-Stack, Vertragsverhalten); PHPStan grün (Baseline-Einträge auf `deploy_job_input.php`/`deploy_job_queue.php` umgezogen, kein neuer Eintrag); Guard-Harness 88/88 proven; Zeilenparitätsbeweis des Splits: jede Nicht-Leerzeile überlebt mit gleicher Vielfachheit, nichts hinzugekommen | `docs/QA.md` (CLI-Vertrag abgeleitet statt behauptet, feste „drei Tests"-Zahl durch ableitbare Beschreibung ersetzt, File-Size-Abschnitt), `.claude/rules/webapi.md` (abgeleitete Entry-Point-Menge), `AGENTS.md`, ADR-0006, `docs/CHANGELOG.md` | nicht betroffen: PHPDoc-, Guard- und reiner Strukturhunk erzeugen keine neue Portalhilfe, keine Auditkategorie, keine Joblogzeile und keinen Wire-Contract; die Joblogtexte des Deploy-Repos (`Deploy job queued: …`, `Retry of deploy job …`, Reaper-/Cancel-Sätze) wurden beim Split byte-gleich mitgenommen und sind durch den Zeilenparitätsbeweis belegt | `2148249` (siehe Zeile darüber), `origin/main`, Upstream-Hashgleichheit bestätigt, Push 2026-08-12T09:59:26Z. Abweichung zur Übergangsregel: Etappe 1 hat **keinen** eigenen Commit bekommen, sondern teilt sich einen mit Etappe 2. Grund: sechs Dateien tragen Hunks beider Etappen (`scripts/check-file-size.php`, `docs/QA.md`, `docs/CHANGELOG.md`, `.claude/rules/webapi.md`, `DeployConvergenceContractTest.php`, `PhaseCContractTest.php`), und `DeployConvergenceContractTest` pinnt seit Etappe 2 `$channel->connection()`. Ein Etappe-1-Commit ohne die Etappe-2-Hunks wäre also selbst rot gewesen; ein grüner Verlauf war nur mit einem gemeinsamen Commit erreichbar | Abweichung zur Plan-Modulliste: sieben statt fünf Domänenmodule. Grund: mit den fünf benannten Modulen läge `deploy_job_queue.php` bei ~430 und mit den Vorbedingungs-Asserts bei ~510 Zeilen und damit über der im selben Vertrag verbindlichen 400-Zeilen-Grenze. Zusätzlich sind `deploy_job_guards.php` (sperrende Vorbedingungen, eigene Lockreihenfolge) und `deploy_job_cancel.php` (ADR-0033-Zustandsmaschine inkl. Worker-Bestätigung) entstanden. `FileSizeDisciplineContractTest` wurde bewusst nicht angelegt: der Plan nennt „bzw. Guard-Contract", und ein PHPUnit-Zwilling des Guards wäre eine zweite SSoT derselben Regel |
| 2 DB-Ausfall/Reaper | 2026-08-12 | grün, mit benanntem Fremdblocker | **Strukturhunk:** `deploy_worker.php` 521 → 53, `deploy_worker_outcome.php` 693 → 45 Zeilen Fassaden plus `deploy_worker_{loop,mission,inventory,stream,runtime,vm_state,reaper,finish}.php`, Owner-Registry `VIRTUSPHERE_DEPLOY_WORKER_MODULES`, `DeployWorkerModuleContractTest` (Registry↔Dateisystem beidseitig), subprocess-basierter `DeployWorkerCliSmokeTest`, Zeilenparität bewiesen. **Verhalten:** `DeployWorkerDbChannel` besitzt die Verbindung, Callbacks lesen sie über `$channel->connection()`; genau eine redigierte STDERR-Zeile je Störung, bounded FIFO (`VIRTUSPHERE_DEPLOY_DB_CHANNEL_SPOOL_MAX_LINES`) mit ausgewiesenem Überlauf, höchstens ein Reconnect je Tick mit Backoff, Resume-Reihenfolge Ownership→Heartbeat→Spool, Ownershipverlust beendet ohne Ergebnis, `deploy_worker_settle_db_channel()` trennt `--once` (begrenzt, meldet nicht persistierten Ausgang) von `--loop`; Mission und Inventar teilen den Kanal. **Reaper:** `deploy_job_reap_observation()` als reine Funktion schreibt nur Job-ID, Heartbeatalter gegen Limit, `locked_by` und Übergang; die drei Ursachensätze sind entfernt, der Singletonstatus steht als ausdrücklich getrennte Momentaufnahme daneben; `--once` reapt bauartbedingt nie. **Diff-Gegenprüfung:** `DeployConvergenceContractTest` pinnte noch `deploy_worker_conclude_sequence($db, …)` und wurde auf `$channel->connection()` umgestellt, weil genau das die neue Aussage ist; `DeployJobReaperTest` trug noch den alten Wortlaut `600 seconds` statt `limit 600 s` | Gezielt 28/28 (`DeployWorkerDbChannelRecovery\|DeployJobReaper\|MaintenanceReap\|DeployWorkerOutcome\|DeployCancellationStateMachine`). PHPUnit unit/static 831 Tests/20513 Assertions, 2 Failures, **beide fremd** (`LangCatalogTest`: DE-Schlüssel `users.*` aus `lib/users_{accounts_panels,admin,directory_admin}.php`; `LogRetentionTest`: Kategorie `directory`). Integration 248 Tests/1116 Assertions, **0 Failures**, 5 Errors **alle fremd** (`Unknown column 'auth_source'`, `lib/auth.php:198`, Migration 0040 bewusst nicht angewandt), 1 umgebungsbedingter Skip (`EsxiInventoryEnqueueTest`, verlangt genau einen ESXi-Zugang). Bedingung protokolliert: Integration nur mit pausierten Dev-Stack-Workern deterministisch, sonst beansprucht der laufende `deploy-worker` den `queued`-Job von `DeployCancellationStateMachineTest` (`cancelling` statt `cancelled`). PHPStan: meine zwei Befunde behoben (Dead-catch durch `@throws mysqli_sql_exception` an der Seam; redundantes `??` im Modulvertrag), verbleibende 3 fremd. Guard-Harness 86/88 proven; die 2 unproven sind `file-size.green`/`.small-file-allowed`, beide durch fremdes Wachstum (`auth.php` 469, `constants.php` 603, `migrate.php` 1188, `directory_ldap.php` 444). Fast-Lane 23/28; alle 5 roten Gates fremd zugeordnet | ADR-0033 (drei Amendments 2026-08-12: Beobachtung statt Ursache, `--once` reapt nie, DB-Ausfall ist kein Jobfehler); `docs/operations/deploy-chain.md` (neuer Abschnitt „Datenbankausfall, während ein Auftrag läuft"; die Ursachenbehauptungen „ist er nicht gestorben"/„meist eine kurz nicht erreichbare Datenbank"/„Neustart ist die falsche Maßnahme" ersetzt); `docs/operations/troubleshooting.md` (drei Zeilen: Protokolllücke mit Replay, korrigierte Reaperzeile, Holdoff nach Neustart, jeweils mit `docker compose logs deploy-worker maintenance-worker`); `docs/QA.md`; `docs/DEPLOYMENT.md`; `docs/CHANGELOG.md` (drei Einträge 2026-08-12); `.claude/rules/webapi.md` (ehrliche Reaperregel plus neue Seitenkanalregel). Nicht betroffen: Portalhilfe und DE/EN-Kataloge, weil diese Etappe keinen sichtbaren Portaltext ändert | Containerlog: genau eine redigierte Zustandszeile je Störung, im Integrationslauf beobachtet (`[deploy-worker] database unreachable while deploy job 11650 …`), Holdoff einmal je Verbindung (`[deploy-reap] holding off for up to 120 s …`), nicht persistierbarer Ausgang als eigene STDERR-Zeile. Joblog: neue SYSTEM-Replayzeile mit Ausfalldauer, Anzahl gepufferter und verworfener Zeilen, danach die Zeilen in Originalreihenfolge; Reapertext ohne Ursachenbehauptung, `DeployJobReaperTest` verbietet „did not die"/„database outage"/„the worker died" explizit. Nicht betroffen: keine neue Auditkategorie (Reap schreibt weiter nur SYSTEM-Joblog plus `last_error`), keine Änderung an Machine-API-Wire-Feldern, keine Retention berührt | `2148249` „Ein Datenbankausfall beendet keinen laufenden Deploy mehr, und der Reaper sagt nur noch, was er gesehen hat", `origin/main` (`github.com/Samy94M/VirtuSphere-v2-WebApp`), `fd132ff..2148249`, Upstream-Hashgleichheit bestätigt (lokal = remote = `2148249eb41d910353b37f6898dcb577a99b1ec4`), Push 2026-08-12T09:59:26Z. 53 Dateien gestaged, `git diff --cached --check` sauber; kein fremder Hunk enthalten, `lib/repo/log.php` hunk-genau über `git apply --cached` gestaged (nur der `lang.php`-Require, nicht die fremden `directory`-Zeilen) | **Abweichung 1:** `lib/deploy_worker_db_channel.php` lag nach dem Verhaltenshunk bei 453 Zeilen und riss das ADR-0006-Gate. Nach Domäne zerlegt in Kanal-Zustandsmaschine, Repository-Adapter (`deploy_worker_db_operations.php`) und Warte-Policy (`deploy_worker_db_recovery.php`); der bisherige Require-Pfad bleibt der einzige öffentliche und lädt beide. **Abweichung 2:** Nebenbefund an eigenem Werkzeug behoben: `check-file-size.php` entfernte den Repo-Root per `str_replace` und traf dadurch auch das `lib/repo/`-Verzeichnis, sodass ein unter `/repo` gemounteter Checkout `libx.php` meldete; jetzt Präfix-Strip. **Fremdblocker:** ein paralleler AD/LDAPS-Strang hält Fast-Lane und volle Suite rot (siehe Tests/Gates). Der einzige Eingriff dort war auf ausdrückliche Nutzerfreigabe ein Zeichenpaar: `lib/repo/directory.php:64` schloss den SQL-String vorzeitig (`COALESCE(s.last_outcome, '')`) und ließ jeden Test scheitern, der `lib/layout.php` lädt; escaped wie in Zeile 66. Sonst wurde die fremde Arbeit nicht angefasst und nicht gestaged |
| 3 Ansible-Aktivität | 2026-08-12 | grün, mit benanntem Fremdblocker | **(1)** `repo_latest_completed_ansible_mission_jobs()` verlangt `j.attempts > 0`, also genau den Zähler, den ausschließlich `repo_claim_deploy_job()` beim Übernehmen erhöht. **(2)** Anzeige DE „Letzter vom Worker bearbeiteter Missionsauftrag" / EN „Last mission job processed by the worker"; zusätzlich sichtbar ist der ausgeführte Modus über den vorhandenen `deploy_job_payload_summary()` (neuer Schlüssel `system_status.ansible_job_mode`), ohne zweite Modus-SSoT. **(3)** Aktive und missionslose Jobs bleiben ausgeschlossen, `updated_at DESC, id DESC` unverändert; dass der **aktuelle** Missionsname und nicht ein Snapshot erscheint, steht jetzt im Docblock und im Glossar. **(4)** Query anhand des Plans umgestellt (siehe Tests/Gates), Index unverändert, Frischschema und Migration weiterhin wortgleich. **(5)** Keine Statuskopie, keine neue Logkategorie, Verlinkung weiter ausschließlich `deploy_job_log_url()`, manueller Volltest unberührt. Diff-Gegenprüfung: die Signatur nimmt jetzt die Credential-IDs, einziger Aufrufer ist `integration_health_snapshot()`, das die Zugänge ohnehin lädt und sie nun vor dem Leser auflöst; Repository-Suche nach weiteren Aufrufern und nach der alten Bezeichnung in Code, Tests, Specs und Doku ist leer | Migration: **nur** `0039_ansible_activity_index` angewandt (HEAD-Kopie von `migrate.php` in einem Wegwerfcontainer, damit die fremde, noch uncommittete 0040 nicht in die Ledger wandert); danach `migrate.php --check`: `pending=1`, `pending 0040_active_directory_authentication`. **EXPLAIN** gegen repräsentative Historie (201.545 `deploy_jobs`, 3 Ansible-Zugänge, 3 Jahre, dazu 1.500 nie übernommene bzw. missionslose Zeilen am Kopf der jüngsten Partition): (A) bisheriges `ROW_NUMBER()` = `Table scan on j` über 201.545 Zeilen, `Sort` über 193.333, materialisierte CTE, `EXPLAIN ANALYZE` 6,9 s; (B) `LATERAL` = weiterhin `Sort` über 64.444 Zeilen je Zugang plus Materialisierung; (C) **gewählt**: `Index lookup on j using deploy_jobs_ansible_activity (reverse)` + `Limit: 1`, 1.501 gelesene Zeilen, kein Sort, keine temporäre Tabelle. Synthetischer Bestand vollständig zurückgebaut (`deploy_jobs` wieder 45 Zeilen). Gezielt: Integration `AnsibleActivity` 6/6 (queued-cancelled verdeckt nicht, nur-nie-übernommen ergibt „noch keiner", Preflight-Fehlschlag und Cancel nach Claim zählen, Zeitgleichheit über `id`, mehrere Credentials, leere/ungültige ID-Liste, Snapshot bis gerendertes HTML); Static/Unit 28/28; splitberührte Verträge `PhaseC\|AmpelLegend\|SystemStatus\|CssClass\|CliRequireClosure` 65/65. Negativ- und Zero-Match-Richtung ohne Eingriff in den Arbeitsbaum bewiesen (Mutant je Datei-Mount): ohne `attempts > 0` fallen 3 Integrationsfälle; ein Fixture ohne `attempts` und Fixtures ganz ohne `INSERT` machen den Static-Guard rot. Suiten: unit/static 834 Tests/20537 Assertions, 2 Failures **beide fremd** (`LangCatalogTest` `users.*`, `LogRetentionTest` `directory`); Integration 253 Tests/1136 Assertions, 0 Failures, 5 Errors **alle fremd** (`Unknown column 'auth_source'`), 1 umgebungsbedingter Skip (`EsxiInventoryEnqueueTest`), Worker dafür pausiert und danach wieder gestartet. PHPStan 3 Befunde, alle fremd. `lang-audit`, `check-bounds-sync`, `check-doc-hygiene`, `check-doc-semantics`, `check-enum-sync`, `check-php-version-sync` grün; `check-file-size` meldet keine eigene Datei; Guard-Harness `file-size` 4/6 wie in Etappe 2 (dieselben 2 durch fremdes Wachstum) | DE/EN: `system_status.ansible_th_last_mission_job` neu benannt, `ansible_job_mode` neu; `help_system_status.esxi_test_ansible_p3` und `help_credentials.credentials_tests_p1` in beiden Locales neu formuliert (kein Zugangstest, ein vor dem Start abgebrochener Auftrag erscheint nicht, Gedankenstrich entfernt). `docs/GLOSSARY.md` (Begriff, `attempts`, Modus, aktueller Missionsname), `docs/DEPLOYMENT.md`, `docs/INSTALLATION-ANLEITUNG.md`, `docs/operations/go-live.md`, `deploy-chain.md`, `esxi-inventory.md`, `troubleshooting.md`, `docs/QA.md` (Guardvertrag inklusive Queryform, ohne Lastmesswerte), `docs/CHANGELOG.md` (zwei Einträge: ehrlicher Nachweis, skalierende Abfrage). Frischschema- und Migrationskommentar beschreiben den Rückwärtslauf. Nicht betroffen: ADRs, weil weder Zustandsmaschine, Retention noch ein Maschinenvertrag berührt ist | Geprüft und unverändert: keine neue Auditkategorie (der manuelle Volltest schreibt weiterhin genau eine `credentials`-Zeile über denselben Handler), keine Joblogzeile, keine Containerlogzeile, keine Retention, kein Machine-API-Wire-Feld; der Nachweis liest ausschließlich `deploy_jobs` und schreibt nichts. Der Joblog-Deep-Link bleibt `deploy_job_log_url()` und an `deploy.run` gebunden, geprüft in `SystemStatusPanelBranchTest` (Rollenzweige) und im neuen Render-Test | `1a24da8` „Etappe 3: Der Ansible-Nachweis zeigt nur noch bearbeitete Missionsauftraege", `origin/main` (`github.com/Samy94M/VirtuSphere-v2-WebApp`), `8016dcf..1a24da8`, Upstream-Hashgleichheit bestätigt (lokal = remote = `1a24da8c1476db4032d8d1c577b5d59425a07163`), Push 2026-08-12T11:01:13Z. 27 Dateien gestaged, `git diff --cached --check` sauber; die beiden gemischten Dateien `lib/migrate.php` und `struktur.sql` hunkgenau über `git apply --cached` (nur der Indexkommentar, nicht die fremde Migration 0040 und nicht die fremden AD-Tabellen). Bewusst nicht enthalten: der gesamte fremde AD/LDAPS-Strang (`composer.json`, `lang/{de,en}/{account,login,logs}.php`, `lib/{auth,bootstrap,constants}.php`, `lib/repo/log.php`, `portal/{account,login}.php`, `Docker/php/Dockerfile`, `scripts/check-enum-sync.sh`, `docs/audits/…-ux-implementation-plan-v4.md`, die ungetrackten `directory_*`/`users_*`-Dateien und `.codex/`) sowie die fremden Hunks der beiden gemischten Dateien | **Abweichung 1 (Strukturhunk):** Etappe 3 hat laut Vertrag keinen Strukturvorlauf, aber die Modusanzeige hätte `system_status_panels.php` von 506 auf 517 Zeilen wachsen lassen, was der Etappe-1-Guard genau verbietet („An exception is a ceiling, not a budget"). Statt eigene Kommentare zu opfern ist der Missionsaktivitäts-Presenter nach `lib/system_status_ansible_activity.php` gezogen (66 Zeilen, eigene Datenquelle, dieselbe Naht, die Etappe 13 fortsetzt); die Ausnahme ist auf 464 verkleinert, und `PhaseCContractTest` globbt jetzt `lib/system_status_*.php` statt `*panels.php`, damit der neue Owner nicht aus der Prüffläche fällt. **Abweichung 2 (Modus):** neu sichtbar, weil der Plan „Status, Modus, Mission, Zeit und Joblog" verlangt und der Hilfesatz „ein Start-Auftrag prüft den MAC-Rückkanal nicht zwingend" ohne den Modus nicht anwendbar ist. **Fremdblocker E2E:** `portal/login.php` (fremd, uncommittet) ruft `directory_is_enabled()`, dessen Repo auf die Tabellen der nicht angewandten Migration 0040 greift; jede Portalseite antwortet 500 (`portal/login.php:14` → `lib/directory_config.php:88` → `lib/repo/directory.php:13` → `mysqli->prepare()`), und die Playwright-Anmeldung scheitert vor dem ersten Spec. 0040 wurde bewusst nicht angewandt: ein uncommitteter, noch in Arbeit befindlicher Migrationsrumpf liefe nach dem Ledger-Eintrag nie erneut. Der Spec ist auf die neue Semantik nachgezogen (realistische `attempts`, Modus, nie übernommener Auftrag darf nicht erscheinen) und läuft, sobald der Fremdstrang seine Migration anwendet; bis dahin deckt der neue Integrationstest dieselbe Aussage serverseitig ab (Snapshot bis HTML) |
| 4 Disk-SSoT/Hilfe | 2026-08-12 | grün, mit benanntem Fremdblocker | **(1)** Letzte sichtbare Rohstelle beseitigt: `lib/help/deploy.php` interpolierte `VIRTUSPHERE_VM_DEFAULTS['disk_type']` direkt in den Speicherbedarfssatz und schrieb dort `eagerzeroedthick`; jetzt über `disk_type_label()`. Gespeicherte Werte, Ansible-Payload und Create-/Update-Audits tragen unverändert die drei Wire-Tokens. **(2)** DE/EN sagen durchgehend „erster Schreibzugriff/first write"; Thin = bedarfsgerechte Allokation, Thick (Lazy Zeroed) = reservierter Platz mit Nullung beim ersten Schreibzugriff, Eager Zeroed Thick = vorab genullter reservierter Platz. **(3)** Entfernt: „Thin ist die langsamste der drei", „sobald jeder Block einmal beschrieben wurde, sind beide gleich schnell", „bei Thin bleibt der Unterschied dauerhaft", „mit VAAI dauert es nur Sekunden" und „dauert das Anlegen einer einzelnen VM länger als :minutes Minuten, bricht der Auftrag ab". Stattdessen: Auswirkung hängt von Storage, VAAI-/NFS-Unterstützung, Größe und Last ab, ohne feste Rangfolge. **(4)** `VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS` ist jetzt korrekt das Budget eines entfernten Schritts **ohne jede Ausgabe**; die davon unabhängige Gesamtlaufzeitgrenze wird als eigene Grenze genannt und bleibt unverändert (`deploy_start_wait_p2` beschrieb die Stille schon richtig und blieb unberührt). **(5)** Trade-off benannt: EZT kann große oder mehrere Platten deutlich verlängern und Automatisierungsbudgets erreichen, Thin/Lazy verschieben die Arbeit nur; eine Umstellung konvertiert weder bestehende VMs noch bereits angelegte Platten. **(6)** Keine zweite Typ-SSoT, kein neues Maschinenfeld; ohne Rücklesen behauptet das Portal ausdrücklich keinen realisierten Endzustand. Diff-Gegenprüfung: Repository-Suche nach `langsamste/slowest`, `gleich schnell/equally fast`, `VAAI` und rohem `eagerzeroedthick` findet nur noch bewusst technische Stellen (Code-Kommentar in `defaults.php`, Wire-Beschreibung in `docs/DEPLOYMENT.md`, historischer Changelog-Eintrag vom 2026-08-10) | Fast-Lane 23 pass/5 fail, `qa-artifacts/qa-e4-fast.json`; die 5 roten Gates sind exakt die aus Etappe 2 bekannten fremden (`phpunit-unit` = `LangCatalogTest` `users.*` + `LogRetentionTest` `directory`, `phpstan` 3 fremde Befunde, `composer-validate`, `file-size` fremde Oversize-Dateien, `csp-patterns` BLOCK in `users_page.php`). Ausdrücklich grün darin: `yaml-roundtrip` (Golden-Mission überlebt den PyYAML-Roundtrip semantisch), `lang-parity`, `bounds-sync`, `doc-hygiene`, `doc-semantics`, `php-lint`. Unit/Static 835 Tests/20542 Assertions, dieselben 2 fremden Failures. `DiskTypeLabelTest` um einen Rohstellen-Wächter erweitert: er liest per `token_get_all()` die Argumente jedes `__t()`-Aufrufs aller Renderer unter `lib/help/`, `lib/*_form.php` und `portal/` und verlangt dort `disk_type_label(`, mit Zero-Match-Schutz. Negativrichtung per Datei-Mount bewiesen: die zurückgedrehte Interpolation in `lib/help/deploy.php` macht ihn rot. Die Formularverwendung derselben Konstante als **Wert** (`vm_edit_form.php` Zeilen 42/106/195) bleibt bewusst erlaubt, weil dort kein Text entsteht | DE/EN `help_missions.disktype_{thin,thick,eager,p2,p3}` und `vm_edit.disk_type_hint` neu formuliert; `lib/help/deploy.php` auf das Label umgestellt. `docs/DEPLOYMENT.md` nennt Wire-/Auditvertrag, Bestandsschutz, Erstellungsrisiko und die fehlende Rücklese-Evidenz; `docs/CHANGELOG.md` erhält den Eintrag mit allen fünf entfernten Aussagen und der korrigierten Timeout-Semantik; `.claude/rules/portal.md` hält die Label-Regel dauerhaft fest. Nicht betroffen: ADRs (keine Entscheidung geändert), Runbooks (kein Betriebsablauf geändert), Glossar (kennt keine Festplattentypen) | Nicht betroffen und geprüft: keine Auditzeile, keine Joblogzeile, keine Containerlogzeile und kein Machine-API-Feld geändert; die Create-/Update-Audits tragen weiterhin den technischen Token, und der Ansible-Payload (`serverlist.yml` `disks[].disk_type`) ist byte-gleich, bewiesen durch den grünen `yaml-roundtrip`-Gate gegen die Golden-Mission | `4070fca` „Etappe 4: Die Festplattenhilfe sagt, wann die Arbeit anfaellt, statt wer schneller ist", `origin/main` (`github.com/Samy94M/VirtuSphere-v2-WebApp`), `84c851a..4070fca`, Upstream-Hashgleichheit bestätigt (lokal = remote = `4070fca72c99050a3923e67b6654d440cd7864f0`), Push 2026-08-12T11:22:33Z. 9 Dateien gestaged, `git diff --cached --check` sauber, keine gemischte Datei betroffen. Bewusst nicht enthalten: der unveränderte fremde AD/LDAPS-Strang und das gitignorierte `qa-artifacts/qa-e4-fast.json` | **Fremdblocker (unverändert):** der AD/LDAPS-Strang hält dieselben fünf Fast-Gates rot; keine der Meldungen nennt eine Datei dieser Etappe. Die Portalhilfe konnte erneut nicht im Browser abgenommen werden, weil `portal/login.php` (fremd, uncommittet) weiter 500 antwortet; die sichtbaren Texte sind stattdessen über Katalogparität, Platzhalterparität und den Rohstellen-Wächter geprüft |
| 5 Inventar-Vokabularvertrag | 2026-08-13 | grün, mit benanntem Fremdblocker | **Strukturhunk:** `lib/repo/esxi_inventory.php` 794 → 19 Zeilen Fassade plus `esxi_inventory_{cache,state,queries,vlan}.php` (296/158/163/179); `lib/esxi_inventory.php` 606 → 22 Zeilen Fassade plus `esxi_inventory_{scheduler,deviations,display}.php` (186/326/79). Beide bisherigen Require-Pfade, alle 48 öffentlichen Funktionen, Signaturen, vorbereiteten SQL-Anweisungen, `repo_transaction()`-Grenzen, Ergebnisse und Logtexte bleiben erhalten; die gemeinsame Registry `VIRTUSPHERE_ESXI_INVENTORY_{REPO,SERVICE}_MODULES` wird in beide Richtungen gegen das Dateisystem und auf eindeutige Owner geprüft. Beide Etappe-5-Ausnahmen sind aus `FILE_SIZE_ALLOWANCES` entfernt. **Vokabular:** eine SSoT in `inventory_error_constants.php`, vom bisherigen `deploy_constants.php` geladen; neun Codes `ansible_{dns,unreachable,auth,authz,preflight,config,sftp,timeout,transport}` ergänzen die elf Bestandswerte. `inventory_error_is_ansible()` erkennt exakt diese neun; `inventory_error_pauses_credential()` liefert ausschließlich für `auth` wahr und ist die Entscheidung in `repo_esxi_inventory_record_failure()`. `InventoryErrorVocabularyContractTest` gleicht Kategorien, qualifizierte Message-Keys, alle DE/EN-`esxi_cause_fix_<code>`-Suffixe, erste Runbook-Tabellenspalte und `VARCHAR(32)` aus Frischschema/Migration exakt ab; Zero-Match und Mutanten für leer/fehlend/zusätzlich/ungültig/zu lang sind enthalten. Keine Schemaänderung, keine Migration | Vor dem Split `EsxiInventoryModuleContractTest` 4/4, 12 Assertions; danach vollständiger Repo-Mount: 7/7, 161 Assertions. Gezielt nach Abschluss: 73/73 Unit/Static, 299 Assertions; Cache/Pause 10/10, 52 Assertions; Split-Integration Cache/VLAN-Sync/-Reassign/Deviation/Options/Enqueue 37/37, 148 Assertions, 1 umgebungsbedingter Skip (Enqueue verlangt genau einen ESXi-Zugang). Alle geänderten PHP-Dateien lintfrei; `lang-audit`, Doku-/Bounds-Guards und gezieltes PHPStan grün. Fast-Lane `qa-artifacts/qa-e5-fast.json`: 23/28; die fünf roten Gates bleiben fremd (`phpunit-unit`: `users.*`/`directory`; `composer-validate`: fremde Composeränderung; `file-size`: `auth.php`, `constants.php`, `migrate.php`, `directory_ldap.php`; `csp-patterns`: `users_page.php`; PHPStan wurde nach Beseitigung der drei aus dem alten Pfad herausgefallenen Baselinebefunde kanonisch wiederholt: nur noch 3 fremde Befunde in `directory_ldap.php`/`portal/login.php`, `qa-artifacts/qa-e5-phpstan.json`) | DE/EN `common.conn_*` und `help_system_status.esxi_cause_fix_*` vollständig ergänzt und bestehende Aussagen für `config`, `worker`, `parse`, `http`, `ssh` korrigiert; `docs/operations/esxi-inventory.md` führt exakt die SSoT-Codes mit Bedeutung/Maßnahme, Pause- und Übergangsvertrag; `docs/QA.md` dokumentiert Quellen-/Negativvertrag und Vollrepo-Mount; `docs/CHANGELOG.md` dokumentiert Modulsplit und Herkunftsvokabular. Keine ADR-Änderung: das entschiedene Herkunftsmodell des Masterplans wird nur ausführbar gemacht | Geprüft und nicht betroffen: kein Producer wird in Etappe 5 umgestellt, keine Auditkategorie, Joblog- oder Containerlogzeile, Retention oder Machine-API-Wire-Feld geändert. Deshalb bleibt `ssh` bis Etappen 6–8 ein ehrlich benannter Übergangswert ohne sichere Herkunft; `http` ist bereits nur Altbestand. Die neuen Message-/Help-Zuordnungen sind Darstellung, nicht zweite Fehlercode- oder Log-SSoT | `ff95362` „Etappe 5: Inventarfehler nennen ihre Herkunft", `origin/main` (`github.com/Samy94M/VirtuSphere-v2-WebApp`), `e718744..ff95362`, Upstream-Hashgleichheit bestätigt (lokal = remote = `ff95362c8292233f7aef418512740c624f86cb7f`), Push 2026-08-13T09:00:48Z. 24 Dateien, `git diff --cached --check` sauber; kein fremder Hunk enthalten. Bewusst nicht enthalten: gesamter uncommitteter AD/LDAPS-Strang einschließlich gemischter `migrate.php`/`struktur.sql` | **Abweichung 1:** Die zusätzlichen Konstanten hätten `deploy_constants.php` auf 417 Zeilen gebracht; die kohärente Fehlercode-SSoT liegt deshalb in `inventory_error_constants.php` (61 Zeilen), während der bisherige Require-Pfad sie lädt. **Abweichung 2:** Drei redundante `isset(...) && ... !== null`-Vergleiche waren im Monolithen baseliniert und wurden durch den Split zu neuen PHPStan-Befunden; logisch gleichwertig auf `isset(...)` verkürzt und den alten Baselineblock entfernt. **Fremdblocker:** Portal-E2E bleibt wegen der fremden, nicht angewandten Migration 0040 vor dem ersten Spec auf HTTP 500 blockiert; 0040 wurde weiterhin nicht in die Ledger geschrieben. Die Hilfe ist über vollständigen Quellenvertrag, Sprachparität und Doku-Guards abgenommen |
| AD/LDAPS-Baseline zwischen 5/6 | 2026-08-13 | grün integriert; Ziel-AD-Aktivierung offen | Striktes LDAPS mit deaktiviertem Default; Directory-Konfiguration, Login, lokale Fallbacks, Gruppen-/Rollenabbildung, Just-in-time-Zuordnung, atomare Rate-Limits, Circuit Breaker, CAS-/Admin-/Controller-Sperren, Session erst nach Commit, HTTPS-Kopplung, CA-Konvergenz und Statusanzeige umgesetzt. Migration `0040_active_directory_authentication` und Frischschema konvergieren; nächste freie Migration ist `0041`. `directory_*`/`repo/directory.php` sind Domain-Owner, `auth_password_rehash.php` hält Rehash getrennt. Post-Merge-Prüfung bestätigt `enabled DEFAULT 0`; die lokale Entwicklungsmigration 0040 wurde angewandt | Vollständige isolierte QA des Featureabschlusses: 1122 Tests, 22507 Assertions, keine Skips. Übergabe erneut auf exakt `e7480e8` geprüft: kanonische Fast-Lane 28/28, darunter 424 PHP-Dateien lintfrei, Unit/Static ohne Skips, PHPStan, Composer, DE/EN, Enum-/Bounds-/PHP-Version-Sync, Doku-, File-Size-, CSP-, PowerShell- und Ansible-Gates grün. Danach `migrate.php --check`: `pending=0`. Browser-Aktionsinventar führt elf Ziel-AD-Fälle ausdrücklich als offenen Fixture-Slice | ADR-0039, Implementierungsplan, Ziel-AD-Validierungsprotokoll, `docs/operations/active-directory.md`, README, Deployment-/Installations-/Go-live-/Backup-/Restore-/Troubleshooting-/QA-Doku, Changelog sowie DE/EN-Hilfe und Kataloge aktualisiert | Directory-Audit- und Logkategorie registriert; Secrets und Bind-Daten werden nicht geloggt. Machine-API-Verträge bleiben unverändert. Ziel-AD-Protokoll muss Zertifikatskette, Hostname, Channel Binding, Gruppenauflösung, Failover, Sperren und CA-Rotation real nachweisen | Kein GitHub-PR-Ref und keine angemeldete GitHub-CLI-/Browser-Sitzung verfügbar; vollständiger Remote-Diff `a6dfd82..e7480e8` lokal geprüft. `main` ohne Force per Fast-Forward auf Featurecommit `e7480e8cc4ec7e94f85a7f0822b9a67761dac337` integriert und zu `origin/main` gepusht; lokal, Upstream und Feature-Remote waren danach hashgleich | Kein Code-Merge-Blocker: Feature ist standardmäßig aus. Produktionsaktivierung und Aussage „betriebsbereit" bleiben bis zum vollständig grünen Ziel-AD-Protokoll gesperrt |
| 6 Budgettyp und Producer | 2026-08-17 | grün | Drei kleine, direkt geladene Typen trennen VirtuSphere-Budget, entfernten SFTP-Fehler und lokale Transportkonfiguration. `ssh.php` ist 341 Zeilen lang und lädt die neue SFTP-Domäne (314 Zeilen) über die bidirektional geprüfte Registry; die bisherige File-Size-Ausnahme ist entfernt. SSH-Idle und -Gesamtbudget werfen exakt `SshTransportBudgetExceeded`. SFTP misst nach erfolgreichem Login monoton, setzt je Remoteoperation `min(Operationsbudget, Restgesamtbudget)`, prüft vor/nach jeder Operation und vor Erfolg, erhält positive Subsekundenreste und unterscheidet `false`/Throwable über `isTimeout()` vor Cleanup. Upload/Probe besitzen je genau einen äußeren Disconnect; Logger/Heartbeat bleiben außerhalb des Guards. Direkt abgelehnter SSH-Login wird `ansible_auth`; Budget/SFTP/lokale Konfiguration werden typgenau konsumiert, normale gleichlautende `RuntimeException` und `DeployWorkerCancelled` nicht umklassifiziert. Missionspfad erhält den exakten Typ beim Anfügen des Schritts, Inventarpfad übergibt das Throwable. Keine AD-/LDAPS-, Schema-, Migrations-, Retention- oder Machine-API-Änderung | Gezielt: SFTP-Budget 11 Tests/40 Assertions; Etappe-6-Unit/Static-Satz einschließlich Require-/Owner-/Consumerverträgen grün; Worker-/DB-Integration 25 Tests/130 Assertions grün, ohne Skips. Reparierter Systemstatus-Seed danach 3/3 Playwright grün. Kanonisch final mit live gepollten Logs: Fast `[28/28]` pass (`qa-e6-fast-final2.json`, 430 PHP-Dateien lintfrei, Unit/Static ohne Skips, PHPStan/Composer/Sprachen/Sync/Doku/File-Size/CSP/PowerShell/Ansible grün); Integration `[35/35]` pass (`qa-e6-integration-final2.json`, `pending=0`, volle PHPUnit-Suite ohne Skips, Schema-Konvergenz, Health/Exposure, Chromium und Guard-Harness positiv/negativ/zero-match), 0 fail/skip/infrastructure_error. Unmittelbar vor dem Commit am 2026-08-17 erneut kanonisch bestätigt, weil zwischen Nachweis und Commit vier Tage lagen: Fast `[28/28]` pass, 0 fail/skip/infrastructure_error (`qa-e6-fast-precommit.json`, 430 PHP-Dateien lintfrei, Unit/Static ohne Skips). Der Baum war dabei unverändert: HEAD `b65c562` von 14:16, die Etappe-6-Dateien zuletzt 14:22 bis 14:34 geschrieben, die Läufe vom 13.08. um 16:05 und 16:34 | DE/EN-Deploy-Hilfe erklärt getrennte SSH-/SFTP-Budgets und Ursachen. `docs/DEPLOYMENT.md`, `docs/operations/deploy-chain.md`, `esxi-inventory.md`, `troubleshooting.md`, `docs/QA.md` und `docs/CHANGELOG.md` sind nachgezogen. `AGENTS.md`, `CLAUDE.md`, QA-Doku und Pester-Vertrag verlangen für gepufferte lange Läufe ein pollbares Live-Log, den echten letzten `[n/total]`-Stand mindestens minütlich und `[0/total]` vor der ersten Zeile; reine Zeitmeldungen zählen nicht. Keine ADR-Änderung, weil die im Masterplan entschiedenen Grenzen nur ausführbar gemacht werden | Technische Budget- und Operationsmeldungen bleiben redigierbar und ursachengerecht; keine neue Auditkategorie, Joblogkategorie, Containerlogquelle oder Retention. Der bestehende Missions-Joblog erhält weiter nur den vorhandenen technischen Fehlertext samt Ansible-Schritt, jetzt ohne Typverlust. `mysqli_sql_exception` aus Logger/Heartbeat bleibt außerhalb des SFTP-Guards und erreicht den DB-Kanal unverändert. Machine-API-/MECM-Wire-Verträge und AD/LDAPS-Logs sind unverändert; Repository-Suche und vollständiger Diff geprüft | `4e31259` „Etappe 6: SSH- und SFTP-Fehler tragen ihren Typ, statt aus dem Text geraten zu werden", `origin/main` (`github.com/Samy94M/VirtuSphere-v2-WebApp`), `b65c562..4e31259`, Upstream-Hashgleichheit bestätigt (lokal = remote = `4e3125972815d4ecefe1fd6322f01375870a5800`), Push 2026-08-17T11:39:52Z. 31 Dateien gestaged, `git diff --check` und `git diff --cached --check` sauber. Die gemischte Planfassung wurde hunkgenau über `git apply --cached` gestaged: von ihren zwölf Hunks gehören nur die Fortschrittsregel in Abschnitt 11 und diese Abnahmezeile zu Etappe 6. Bewusst nicht enthalten und unangetastet: die zehn fremden Hunks derselben Datei (fünfter Arbeitsstrang 8R/13R/14A–14C mit Reihenfolge-Neunummerierung und Cleanup-Passage in Etappe 8), `.codex/` sowie die drei ungetrackten Auditpläne vom 2026-08-13 | Abweichung 1: Die vollständige Integration-Lane fand einen älteren Parsefehler im bereits vorhandenen Systemstatus-E2E-Seed: JavaScript entfernte den PHP-Stringescape vor eingebettetem JSON. Der Seed bindet `payload_json` jetzt als Prepared-Statement-Parameter; Produktivcode und Testaussage bleiben unverändert, der zuvor reproduzierbare Einzeltest ist 3/3 und die vollständige Chromium-Suite grün. Abweichung 2: Die vom Nutzer verlangte Fortschrittsverschärfung wurde in derselben Etappe dauerhaft dokumentiert und durch `VirtuSphere.ProgressReporting.Tests.ps1` gepinnt. Bewusst nicht enthalten und unangetastet: `.codex/`, die drei fremden ungetrackten Auditpläne sowie alle parallelen fremden Hunks dieser Masterplandatei. Etappe 7 wurde nicht begonnen |
| 7 gemeinsame Ansible-Abbildung | 2026-08-18 | grün, mit benanntem Fremdblocker | **Strukturhunk:** `lib/ansible_inventory.php` 714 → 38 Zeilen Fassade plus `ansible_inventory_{artifacts,parse,datastore,capability}.php` (58/275/208/195 Zeilen). Alle 17 öffentlichen Funktionen automatisiert byte-gleich verglichen (Docblock+Body je Funktion extrahiert und gegen den alten HEAD-Stand diffed, 0 Abweichungen); Marker-/Logwortlaut, Require-Reihenfolge und der bisherige Require-Pfad unverändert. Neue Owner-Registry `VIRTUSPHERE_ANSIBLE_INVENTORY_MODULES` (`lib/ansible_inventory_modules.php`), `AnsibleInventoryModuleContractTest` hält sie beidseitig gegen das Dateisystem, beweist die vollständige Fassadenoberfläche nach isoliertem Require in eigenem Prozess und genau einen Owner je Funktion. `AnsiblePlaybookHygieneContractTest::testRemoteCommandBuildersChmodAccountsBeforeAnyPlaybook` liest jetzt die Registry statt eines hartcodierten Zweierpfads, weil der geprüfte `chmod 600 accounts.yml`-Satz mit dem Split in `ansible_inventory_artifacts.php` gewandert wäre. **Fehlerabbildung:** `ansible_connection_error_category(Throwable $exception)` und ihr Text-Halbteil `ansible_connection_error_category_for_text(string $text)` in `lib/connection_errors.php`; Typprüfungen (`SshTransportBudgetExceeded`→`ansible_timeout`, `SftpTransportFailed`→`ansible_sftp`) vor Textklassifikation, danach ein `match` ohne `default` über die exakt sieben möglichen Rückgaben von `connection_error_category()` (`dns`/`unreachable`/`auth`/`authz`→`ansible_*`, `certificate`/`tls`/`parse`→`ansible_transport`). `credential_test_ssh_failure()` (`ssh.php`) verwendet ausschließlich diese eine Funktion (der bisherige doppelte Budget-Check und der PARSE→`ssh`-Legacy-Fallback sind entfernt); `deploy_worker_classify_inventory_failure()` (`deploy_worker_runtime.php`, Fassade `deploy_worker_outcome.php`) ruft in den Phasen SSH/TRANSPORT jetzt `ansible_connection_error_category_for_text()` statt der rohen generischen Kategorie mit `ssh`-Fallback auf. Damit schreibt keiner der beiden Producer mehr den unqualifizierten `dns`/`unreachable`/`auth`/`authz` oder den Legacy-Übergangswert `ssh`: eine abgelehnte SSH-Anmeldung am Ansible-Host, die vorher als generisches `auth` denselben Code wie eine echte ESXi-Ablehnung erzeugte und darüber fälschlich `repo_esxi_inventory_record_failure()`s Auth-Pause auslösen konnte, landet jetzt als `ansible_auth` und pausiert nichts mehr. **Prädikate:** `inventory_error_is_ansible()`/`inventory_error_pauses_credential()` existierten bereits seit Etappe 5 in `inventory_error_constants.php` (von `deploy_constants.php` geladen); geprüft und unverändert korrekt, `repo_esxi_inventory_record_failure()` und der exakte `=== VIRTUSPHERE_INVENTORY_ERROR_AUTH`-Vergleich in `deploy_worker_inventory.php:192` verwenden sie bereits. Repository-Suche nach einer zweiten Mapping-Tabelle oder verbliebenen `auth`-Sonderfällen: leer; `ansible_categorize_inventory_error()` (Playbook-stdout-Klassifikator) bleibt bewusst der zweite, unabhängige Klassifikator für ESXi-seitige Antworten. Einziger verbleibender Schreiber von `ssh` ist der noch nicht umgestellte Ansible-Preflight-Exitcode in `deploy_worker_inventory.php:87` (Etappe 8, Abschnitt 7.3). Der Etappenabschluss entfernt `ansible_inventory.php` aus `FILE_SIZE_ALLOWANCES` | Strukturparität vor der Mappingänderung: `AnsibleInventoryParseTest`, `EsxiCapabilitiesTest`, `EsxiDatastoreHealthTest`, `EsxiTrustModeTest`, `AnsiblePlaybookHygieneContractTest`, `InventoryQueryVocabularyContractTest`, `AnsibleInventoryModuleContractTest` gezielt 81/81, 395 Assertions, 2 umgebungsbedingte Skips (Doku-Pfad nicht sichtbar ohne vollen Repo-Mount; im kanonischen Lauf mit vollem Mount 0 Skips). Nach der Mappingänderung gezielt: `ConnectionErrorTest`, `DeployWorkerFailureClassificationTest`, `AnsibleInventoryModuleContractTest`, `AnsiblePlaybookHygieneContractTest`, `AnsiblePauseBudgetContractTest` 46/46, 302 Assertions. PHPStan fand einen echten neuen eigenen Befund (`match.unhandled` in `connection_error_category()`, weil PHPStan den Rückgabewert nur als `string` kennt) und wurde wie `disk_type_label()` durch einen `@return`-Docblock mit dem literalen Sieben-Werte-Union behoben; danach `[OK] No errors` sowohl gezielt als auch im vollen Lauf. Kanonisch mit live gepolltem Log: Fast-Lane 27/28 pass (`qa-e7-fast-run2.json`, 442 PHP-Dateien lintfrei, Unit/Static ohne Skips, PHPStan/Composer/Sprachen/Sync/Doku/CSP/PowerShell/Ansible grün); Integration-Lane 33/35 pass (`qa-e7-integration-run1.json`, `pending=0`, volle PHPUnit-Suite inkl. LDAPS-Fixture-Integrationstests ohne Skips, Schema-Konvergenz, Health/Exposure, Playwright-Chromium-Suite grün); je Lane genau ein roter Gate `file-size` (fremd), Integration zusätzlich `guard-harness` (86/88 proven, dieselben 2 unproven `file-size.green`/`.small-file-allowed` wie in Etappe 2/5/6, direkt gegen `scripts/test-guards.ps1` nachgewiesen) | DE/EN `help_system_status.esxi_cause_fix_ssh` korrigiert: behauptete bisher „bis zur Producer-Umstellung"/„until producer migration" und wäre nach dieser Etappe falsch geworden, weil genau diese Umstellung hier stattfindet; sagt jetzt, dass nur noch der Preflight-Exitcode diesen Übergangswert schreibt. `docs/operations/esxi-inventory.md` (Fehlerbildtabelle `ssh`-Zeile plus SSoT-Absatz auf die neue Ownership umgestellt), `docs/operations/deploy-chain.md` (neuer Absatz in „SSH-/SFTP-Budgets und Fehlerherkunft"), `docs/CHANGELOG.md` (zwei Einträge: Fehlerherkunft, Modulsplit). Keine ADR-Änderung: das im Masterplan entschiedene Herkunftsmodell wird nur ausführbar gemacht (wie Etappe 5) | Geprüft und unverändert: keine neue Auditkategorie, kein neuer Joblog- oder Containerlogtyp, kein Machine-API-Wire-Feld, keine Schema-/Migrationsänderung. Der bestehende Auth-Pause-Audit-Satz „ESXi inventory auto-pull paused …" bleibt an den exakten Code `auth` gebunden und feuert durch diese Etappe nachweislich seltener (nicht mehr für eine Ansible-Host-SSH-Ablehnung), nie häufiger. `VIRTUSPHERE_CONNECTION_MESSAGE_KEYS`/DE/EN-`esxi_cause_fix_ansible_*` waren bereits seit Etappe 5 vollständig, tragen jetzt aber für neue Zeilen tatsächlich zutreffende Fehlerkategorien statt der bisherigen generischen | `8f80022` „Etappe 7: SSH-Fehler beim Inventarabruf nennen den Ansible-Host, nicht mehr ESXi", `origin/main` (`github.com/Samy94M/VirtuSphere-v2-WebApp`), `0caccd9..8f80022`, Upstream-Hashgleichheit bestätigt (lokal = remote = `8f800228503f9126d0747f02fb0a5181e33f6c87`), Push 2026-08-18T08:16:52Z. 20 Dateien gestaged, `git diff --cached --check` sauber; kein fremder Hunk enthalten. Die vier gemischten Dateien (`lang/{de,en}/help_system_status.php`, `docs/CHANGELOG.md`, diese Masterplandatei) wurden hunkgenau über `git apply --cached` gestaged: nur der `esxi_cause_fix_ssh`-Text je Locale, der 2026-08-18-Changelog-Abschnitt und diese Abnahmezeile gehören zu Etappe 7. Bewusst nicht enthalten und unangetastet: der gesamte fremde AD/LDAPS-Strang (`directory_*`, `Docker/ldap-fixture/`, `Docker/qa/docker-compose.qa.yml`, die drei fremden Auditpläne vom 2026-08-13/17, `.codex/`, `tests/e2e/specs/directory-ad.spec.js`) sowie die fremden Hunks der vier gemischten Dateien (`directory_p1`/`directory_p2`-Hilfe, der 2026-08-17-Changelog-Abschnitt, die zehn fremden Masterplan-Hunks aus 8R/13R/14A–14C) | **Fremdblocker (unverändert seit Etappe 2/5/6):** derselbe parallele AD/LDAPS-Strang lässt `lib/layout.php` (666 → 702 Zeilen) sein `FILE_SIZE_ALLOWANCES`-Kontingent überschreiten und reißt damit sowohl den `file-size`-Gate als auch die zwei `file-size.green`/`.small-file-allowed`-Guardfixturen in beiden Lanes; keine Meldung nennt eine Datei dieser Etappe, direkt gegenverifiziert durch einen isolierten `scripts/test-guards.ps1`-Lauf. **Session-Selbstkorrektur:** Beim Warten auf den Integration-Lane-Hintergrundlauf wurden wiederholt wirkungslose Platzhalter-Tool-Aufrufe statt eines einzelnen Wartens auf die Hintergrund-Benachrichtigung abgesetzt; auf Nutzerhinweis gestoppt, Ursache benannt, Ergebnis danach reproduzierbar mit `TaskOutput`/`Monitor` blockierend abgerufen. Kein Einfluss auf Code, Tests oder Ergebnis. **Nachtrag 2026-08-18 (Review, Abschnitt 6.1):** Die Gegenprüfung nach dem Push fand vier Befunde. Nach der Wiedereröffnungsregel aus Abschnitt 0 wurden die beiden Etappe-7-eigenen sofort geschlossen: `common.conn_ssh` widersprach in beiden Locales der Hilfe und dem Runbook derselben Etappe (zwei von drei Stellen nachgezogen) und ist jetzt gleichlautend; der handgeschriebene `@return`-Union von `connection_error_category()` besaß keinen Wächter, obwohl er das Einzige ist, womit PHPStan das neue `match` als vollständig beweist, und eine Drift dort hätte einen `\UnhandledMatchError` innerhalb des `catch (Throwable)` des Inventarworkers erzeugt, der den Terminalzustand des Auftrags ganz verhindert statt eine falsche Kategorie zu speichern. Neu dafür `tests/Static/ConnectionErrorMappingContractTest.php` (3 Tests/60 Assertions, Nadelgruppen ↔ Docblock ↔ Match-Subjekte beidseitig, jedes Match-Ziel muss `inventory_error_is_ansible()` erfüllen, Zero-Match-Schutz je Extraktion, Negativrichtung per Datei-Mount bewiesen). Gezielte Nachabnahme `ConnectionErrorMapping\|ConnectionError\|InventoryErrorVocabulary\|DeployWorkerFailureClassification\|LangCatalog` 38/38, 17609 Assertions, `lang-audit --ci` grün. Die Befunde 2 (fehlender `mysqli_sql_exception`-Zweig, durch diese Etappe von `ssh` auf das bestimmtere `ansible_transport` verschärft) und 4 (`ansible_connection_error_category()` behandelt `SshTransportConfigurationException` nicht und ist damit eine Falle für den dritten Aufrufer) sind bewusst **nicht** hier behoben, weil sie Abschnitt 7 gehören; sie stehen als verbindliche Etappe-8-Anforderungen in 6.1 und an ihrer Fachstelle in 7.6. Nachtragscommit `44afa90` „Etappe 7 (Nachtrag): Der Code ssh sagt in Text und Hilfe dasselbe, und das neue match bekommt seinen Wächter", `origin/main`, `3af7f6a..44afa90`, Upstream-Hashgleichheit bestätigt (lokal = remote = `44afa90fa8bbba247cf679ba216101ba550ab5cd`), Push 2026-08-18T11:36:47Z. 8 Dateien, kanonische Fast-Lane davor 27/28 pass (`qa-e7-nachtrag-fast.json`, 443 PHP-Dateien lintfrei, Unit/Static ohne Skips, PHPStan/Sprachen/Sync/Doku/CSP/PowerShell/Ansible grün), einziger roter Gate weiterhin der fremde `file-size`. `docs/CHANGELOG.md` und diese Planfassung erneut hunkgenau über `git apply --cached --recount` gestaged. Nebenbefund beim Stagen behoben: der erste Versuch, 7.6 zu ergänzen, hatte den Schlussatz des Originalabsatzes an das Ende des neuen Absatzes gehängt; der Absatz ist wiederhergestellt, bevor gestaged wurde |
| 8 Worker/Playbookgrenzen/Cancel/Pause/Logging | | | | | | | | |
| 9 Anzeige/Links/Zugangstest | | | | | | | | |
| 10 Betriebsabnahme/Deploy-QoL | | | | | | | | |
| 10A Joblog-Tail/Drain/Rohdownload | | | | | | | | |
| 10B Terminalergebnis/Cancelmetadaten | | | | | | | | |
| 10C Audit-SSoT/Tokenpfad/CSV | | | | | | | | |
| 10D PowerShell-Logvertrag | | | | | | | | |
| 11 UX-Basis/Visual-Harness | | | | | | | | |
| 12 UX-Quick-Wins/Deploy-Blocker/Help | | | | | | | | |
| 13 Portal-Zustände/Jobpoller/Log-Follow | | | | | | | | |
| 14 Formular-Accessibility | | | | | | | | |
| 15 Navigation/Tabellen/strukturierte Logfilter/Korrelation | | | | | | | | |
| 16 Design/Farbguard/Kontrast | | | | | | | | |
| 17 Visual-Baselines/Release-Gate | | | | | | | | |
| Fast-Lane | | | | | | | | |
| Integration-Lane | | | | | | | | |
| Release-Lane/Staging-Drill | | | | | | | | |
| Gesamtabgleich | | | | | | | | |

### Befundabgleich

| Befund | Erledigt durch | Nachweis |
|---|---|---|
| Fast-Gate scheitert an `disk_type_label()` | 1 | Vorher reproduziert (`match.unhandled`, `lib/defaults.php:150`); eingeengter `@param`, `match` ohne `default`; PHPStan grün, `DiskTypeLabelTest` 5 Tests inkl. Docblock-Union gegen `VIRTUSPHERE_DISK_TYPES` |
| CLI-Guard behauptet mehr Entry-Points als er prüft | 1 | Menge wird aus dem CLI-Wächter abgeleitet, `DUAL_SAPI` nur `lib/migrate.php`, Aufruf-Gegenscan über Compose/Healthcheck/Setup; `seed.php` erstmals erfasst, dadurch echte Lücke `lib/repo/log.php` → `lib/lang.php` behoben; Positiv-, Negativ- und Zero-Match-Fixtures |
| temporäres E2E-Screenshot-Skript liegt im Arbeitsbaum | 1 | `tests/e2e/shot.tmp.js` entfernt, nie committet; `git status --short` zeigt es nicht mehr, fremde ungetrackte Dateien unangetastet |
| Grace stellt aktiven Jobheartbeat nach DB-Ausfall nicht wieder her | 2 | Die Grace bleibt reine Beobachtergrenze; die Wiederherstellung leistet jetzt der Kanal: `DeployWorkerDbChannel` versucht je Tick höchstens einen Reconnect mit Backoff und schreibt nach Ownership sofort den Jobheartbeat, bevor er die Spool leert. `DeployWorkerDbChannelTest` beweist Reihenfolge und Backoff ohne DB, `DeployWorkerDbChannelRecoveryTest::testATransientOutageReplaysTheJobLogAndStillFinalizesExactlyOnce` den aufgefrischten Heartbeat und genau eine Finalisierung gegen den Server |
| Reaper leitet Besitzerursache aus aktuellem Singletonstatus ab | 2 | `deploy_job_reap_observation()` ist die eine, reine Formulierung und trägt nur Job-ID, Heartbeatalter gegen Limit, `locked_by` und Übergang. Der Singletonstatus wird als ausdrücklich getrennte Momentaufnahme angehängt („That is a statement about now, not about the process that held this job"). `DeployJobReapObservationTest` (5 Fälle) pinnt den Satz ohne DB, `DeployJobReaperTest::testTheMessageStatesOnlyWhatTheRowShows` verbietet „did not die", „stopped reporting as well", „database outage" und „the worker died" im echten `last_error` |
| `--once`-Reaping ist implizit dauerhaft blockiert | 2 | Aus einem impliziten Nebeneffekt wurde ein benannter Werkzeugvertrag: `deploy_worker_reap_stale_jobs()` dokumentiert, dass `--once` immer im eigenen Grace liegt und deshalb nie reapt, weil ein Prozess ohne Beobachtung keinen fremden Auftrag beenden darf; ein erzwungenes Reaping bräuchte einen eigenen Operatorschalter. Gepinnt von `DeployReapObserverGraceTest`, dokumentiert in ADR-0033, `deploy-chain.md`, `troubleshooting.md` und `.claude/rules/webapi.md` |
| queued-cancelled gilt als tatsächlich ausgeführte Ansible-Aktivität | 3 | `attempts > 0` ist die Bedingung, und `attempts` wird ausschließlich beim Claim erhöht (`AnsibleActivityContractTest::testOnlyJobsAWorkerClaimedCountAsActivity` hält beide Seiten zusammen). `AnsibleActivityTest` beweist gegen die echte Datenbank, dass ein jüngerer queued-cancelled Auftrag den älteren bearbeiteten nicht verdrängt, dass ein Zugang mit ausschließlich nie übernommenen Aufträgen „noch keiner" meldet und dass ein Fehlschlag im Preflight oder ein nach dem Claim bestätigter Abbruch weiterhin zählen; der Mutant ohne die Bedingung macht drei dieser Fälle rot. Anzeige, Hilfe und Glossar heißen jetzt „vom Worker bearbeitet" und nennen den ausgeführten Modus |
| Aktivitätsquery skaliert ungeprüft über unbegrenzte Missionshistorie | 3 | Migration 0039 angewandt und `EXPLAIN`/`EXPLAIN ANALYZE` gegen 201.545 Aufträge geführt: die `ROW_NUMBER()`-Fassung scannte die Tabelle und sortierte/materialisierte 193.333 Zeilen. Ersetzt durch einen rückwärts gelesenen Indexbereich je Zugang mit `LIMIT 1` (kein Sort, keine temporäre Tabelle, 1.501 gelesene Zeilen trotz 1.500 zu überspringender), gepinnt von `AnsibleActivityContractTest::testTheReaderStaysOnTheIndexInsteadOfRankingTheWholeHistory`. Die Anzahl der Abfragen ist die Anzahl der Ansible-Zugänge, die der Aufrufer ohnehin lädt |
| Disk-Hilfe umgeht Label-SSoT oder verspricht pauschale Performance | 4 | Die eine verbliebene Rohstelle (`lib/help/deploy.php`) läuft über `disk_type_label()`, und `DiskTypeLabelTest::testNoRendererInterpolatesTheRawDefaultToken` hält jeden `__t()`-Aufruf aller Help-, Form- und Portalrenderer dagegen (Zero-Match-Schutz, Negativfall per Mutant bewiesen). Die fünf Absolutaussagen sind ersetzt: die Hilfe sagt jetzt, **wann** die Arbeit anfällt, nennt Storage, VAAI-/NFS-Unterstützung, Größe und Last als die Faktoren, beschreibt `VIRTUSPHERE_SSH_IDLE_TIMEOUT_SECONDS` als Stille-Budget eines entfernten Schritts statt als Gesamtdauer einer VM, benennt den EZT-Trade-off samt Bestandsschutz und behauptet ohne Rücklesen keinen realisierten Typ |
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
| Portaltext `common.conn_ssh` widerspricht Hilfe und Runbook derselben Etappe | 7 (Nachtrag) | Aus dem Etappe-7-Review (Abschnitt 6.1, Befund 1). Beide Locales sagen jetzt „Übergangswert: neue Abrufe schreiben ihn nur noch für einen fehlgeschlagenen Ansible-Preflight."; `lang-parity` und `LangCatalogTest` grün. Ursache der Lücke: der Vokabularvertrag prüft Schlüsselexistenz, nicht Wortlaut, und zwei von drei Stellen waren nachgezogen |
| `@return`-Union von `connection_error_category()` ohne Drift-Wächter | 7 (Nachtrag) | Aus dem Etappe-7-Review (Abschnitt 6.1, Befund 3). Neu `tests/Static/ConnectionErrorMappingContractTest.php`: Nadelgruppen ↔ Docblock-Union ↔ Match-Subjekte beidseitig, jedes Match-Ziel muss `inventory_error_is_ansible()` erfüllen, Zero-Match-Schutz je Extraktion; 3 Tests/60 Assertions. Negativrichtung per Datei-Mount bewiesen (zusätzliche Nadelgruppe ohne Docblock-Pflege lässt genau die beiden Spiegeltests fallen und benennt die neue Kategorie). Ohne ihn wäre eine Drift ein `\UnhandledMatchError` im `catch (Throwable)` des Inventarworkers, der den Terminalzustand des Auftrags ganz verhindert |
| Klassifikator ohne phasenunabhängige `mysqli_sql_exception`-Regel | 8 | Offen. Abschnitt 3 Punkt 1 und Abschnitt 7.6; durch Etappe 7 verschärft, weil ein durchgereichter DB-Fehler in der Transportphase jetzt `ansible_transport` statt `ssh` trägt. Als bekannte Einschränkung in `esxi-inventory.md` und `deploy-chain.md` benannt |
| `ansible_connection_error_category()` behandelt `SshTransportConfigurationException` nicht selbst | 8 | Offen. Abschnitt 6.1 Befund 4; Falle für den dritten Aufrufer, den Etappe 8 realistisch schafft |
| Budgettyp wird phasenunabhängig `ansible_timeout` | 8 | Offen. Abschnitt 6.1 Befund 5; widerspricht Abschnitt 3 Punkt 6, heute unerreichbar, mit der neuen Phase `SFTP` aus 7.1 erreichbar |
| `credential_test_sftp_failure()` dupliziert die Typabbildung zur Hälfte | 8 | Offen. Abschnitt 6.1 Befund 6; gemeinsam mit Befund 4 zu entscheiden |
| Initiales Joblog zeigt bei mehr als 1.000 Zeilen den Anfang statt des tatsächlichen Endes | 10A | |
| Poller stoppt bei terminalem Status trotz mehr als 500 noch ungelesener Zeilen | 10A | |
| Joblog wächst im Browser unbegrenzt oder bietet keinen vollständigen retained Rohdownload | 10A, 13 | |
| Auto-Scroll würde hochgescrollte Operatoren gegen ihren Willen zum Ende zwingen | 13 | |
| Hintergrundtab pollt weiter oder erzeugt beim Zurückkehren Parallelrequests/Doppelzeilen | 13 | |
| Joblog besitzt keinen höflichen ARIA-Live-/Tastaturvertrag | 13 | |
| entfernte `&&`-Playbookkette bietet entgegen ADR-/Hilfetext keine lokale Abbruchgrenze | 8 | |
| letzter Schritt, Cancel und Erfolg können ohne gemeinsamen CAS widersprüchlich finalisieren | 8, 10B | |
| Cancel aus Joblog springt zur Liste; zweiter Tab aktualisiert Aktion/Akteur/Zeitpunkt nicht | 10B, 13 | |
| normaler Abbruch steht doppelt in SYSTEM-Log und `last_error` und erscheint als „Letzter Fehler“ | 10B | |
| `last_error` vermischt Fehler, Teil-Erfolg, Abbruchwunsch, Abbruch und Reapergrund | 10B | |
| gelöschter Cancel-Akteur oder historische Cancel-Zeile hat keinen stabilen Darstellungsfallback | 10B | |
| `2>&1` wird im Portal fälschlich als trennbarer stdout-/stderr-Stream dargestellt | 8, 13 | |
| Step-Marker werden nicht als aktuelle Phase/Phasenüberschrift genutzt | 8, 13 | |
| Jobausgabe hat keine zentrale ANSI-/Steuerzeichen-, Zeilen- und Gesamtvolumengrenze | 8 | |
| harter Abbruch/Hostverlust kann entferntes `accounts.yml`-/Jobmaterial trotz EXIT-Trap verwaisen lassen | 8 | |
| Hilfe behauptet für `-vvv` Variablenanzeige oder überdehnt `no_log` als Vollschutz | 8 | |
| Secret-Redigierung ist nicht von `accounts.yml` bis Browser/Rohdownload/Audit-/PHP-Log bewiesen | 8, 10A | |
| Troubleshooting empfiehlt eine noch nicht implementierte Korrelationssuche | 10C, 15 | |
| Audit-SSoT besteht überwiegend aus freien englischen Meldungen ohne Event/Objekt/Ergebnis/strukturierten Kontext | 10C, 15 | |
| Audit-CSV kappt bei 10.000 ohne sichtbare Gesamtzahl und auditierbaren Kappungsnachweis | 10C, 15 | |
| totes `addLog()` könnte einen übergebenen Auth-Token persistieren | 10C | |
| PowerShell-Server-/Clientlogs driften in Format/Dateiname und verschlucken Sinkfehler nur in `Write-Debug` | 10D | |
| Heartbeat-/Reportfehler dürfen bei Sichtbarmachung des Sinkzustands keinen Auditspam erzeugen | 10C, 10D | |
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
| `repo/deploy_jobs.php` bündelt fünf Transaktions-/Lebenszyklusdomänen auf 1220 Zeilen | 1 Strukturhunk | Fassade 50 Zeilen plus sieben Domänenmodule (größtes 277); Owner-Registry `VIRTUSPHERE_DEPLOY_JOB_REPO_MODULES`, von `DeployConvergenceContractTest` und `PhaseCContractTest` gelesen; `DeployJobRepoFacadeContractTest` prüft Registry↔Dateisystem beidseitig, 40 öffentliche Funktionen nach isoliertem Require nur der Fassade und Doppeldefinitionen; Zeilenparität bewiesen; alle Deploy-Repo-Unit-/Integrationstests grün |
| Worker-/Outcome-Dateien bündeln CLI, zwei Jobarten, Stream, Reaper, VM-State und Audit auf 521/693 Zeilen | 2/8 Strukturhunks | Etappe-2-Anteil erledigt: Fassaden 53/45 Zeilen plus elf Domänenmodule, jedes unter 400. Owner-Registry `VIRTUSPHERE_DEPLOY_WORKER_MODULES` ist die Prüffläche von `DeployConvergenceContractTest`/`PhaseCContractTest`; `DeployWorkerModuleContractTest` hält sie beidseitig gegen `lib/deploy_worker*.php`, sodass ein neues unregistriertes Modul rot wird. CLI-Parität durch `DeployWorkerCliSmokeTest` (Subprozess) und Zeilenparität belegt; beide Worker-Fassaden sind aus `FILE_SIZE_ALLOWANCES` entfallen. Offen bleibt der Etappe-8-Anteil (Klassifikation, Mode-/Marker-/Preflighttrennung) |
| `repo/esxi_inventory.php` mischt Cache, Pause/State, Queries und VLAN auf 794 Zeilen | 5 Strukturhunk | |
| ESXi-Service mischt Scheduler, Abweichungen und Darstellung auf 606 Zeilen | 5 Strukturhunk | |
| Ansible-Inventory/-Command mischen Parser-, Artifact-, Mode-, Marker- und Preflightdomänen auf 714/523 Zeilen | 7/8 Strukturhunks | |
| `VirtuSphere-Common.ps1` bündelt Logging mit MECM-/Wire-/Packaginglogik; 10D berührt aber nur die Loggingdomäne | 10D Strukturhunk | |
| `check.ps1` mischt Runtime, Registry und drei Lanes auf 1209 Zeilen | 11 Strukturhunk | |
| `deploy.php`/`deploy.js` würden durch Live-Blocker weiter über ihre Verantwortungsgrenze wachsen | 12 Strukturhunk | |
| `settings.php` mischt elf Aktionen, fünf Tabs, Viewmodel und Renderer auf 934 Zeilen | 12/14 Strukturhunk | |
| `layout.php`, Systemstatus und Credentials überschreiten trotz trennbarer Presenter-/Datenquellen das ADR-0006-Ziel | 13 Strukturhunk | |
| VM-Repo und VM-Editor mischen Legacy, Persistenz, Recovery und Formdarstellung | 14 Strukturhunk | |
| `components.css` bündelt unabhängige Komponentenfamilien auf 1715 Zeilen | 16 Strukturhunk | |
