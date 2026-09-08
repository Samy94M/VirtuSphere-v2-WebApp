# Reststand: Pläne, Auditbefunde und externe Abnahmen

Stand: 07.09.2026, geprüft gegen `cf75676` (PowerShell- und MECM-Integration härten). Arbeitsbaum zu Beginn sauber. Diese Bestandsaufnahme ändert keinen Produktcode und erteilt keine Betriebsfreigabe.

## 1. Ergebnis und Aussagegrenze

Es ist nicht alles erledigt. Es gibt noch einen eigenständigen, nicht implementierten RAM-/Deep-Link-Plan, offene Worker-/Log-Auditkorrekturen, Dokumentationsdrift, externe Standortabnahmen und bewusst zurückgestellte Erweiterungen. Umgekehrt dürfen alte offene Checkboxen nicht dazu führen, bereits implementierte Etappen noch einmal zu bauen.

Wichtige Korrektur gegenüber der vorherigen Unterhaltung: Der PowerShell-Plan wurde inzwischen in `cf75676` umgesetzt. Seine 18 A-Pakete stehen im Abschlussprotokoll auf **lokal verifiziert / externe Abnahme offen**. Die sieben U-Pakete sind weiterhin Folgeumfang. Das ist eine wesentlich andere Lage als ein komplett unausgeführter PowerShell-Plan.

Prüfmethode: alle 16 Dokumente unter `docs/audits/` nach Status, Abschluss, Konsolidierung und Restumfang eingeordnet; jüngere Masterplan-Abnahmen und Git-Historie berücksichtigt; konkrete offene Codepfade und ausgewählte Tests/Owner gegengeprüft. Keine erneute vollständige Funktionsprüfung aller Implementierungen, kein neuer vollständiger Release-Lauf und keine echten MECM-/AD-/ESXi-/Storage-Operationen. Die Tests im PowerShell-Abschlussprotokoll sind übernommene Nachweise der Umsetzungssession, keine in dieser Bestandsaufnahme neu ausgeführten Tests. Persönliche Planverzeichnisse außerhalb des Repositories sind nicht Bestandteil dieser Inventur.

## 2. Status jedes Audit-/Plandokuments

Alle Dateinamen in dieser Tabelle beziehen sich auf `docs/audits/`.

| Dokument | Aktuelle Einordnung | Tatsächlich verbleibend / Beleg |
|---|---|---|
| `2026-09-07-powershell-mecm-hardening-plan.md` | A01–A18 lokal umgesetzt, nicht erneut pauschal ausführen | Abschlusszeilen je A-Paket und Commit `cf75676`; reale Abnahmen, U01–U07 und die unten belegten Help-Reste bleiben. Eine erneute unabhängige Qualitätsprüfung des gesamten neuen Diffs ist mit dieser Inventur nicht behauptet. |
| `2026-08-27-ram-unit-and-deep-link-target-plan.md` | **Umsetzung offen, beide Teile** | RAM-Parser/-Renderer fehlen; VM-Editor nimmt weiterhin MB plus Preset. Systemstatus-Ziele sind `article`, vorhandene Zielregeln stylen `tr`. Siehe Abschnitt 3. |
| `2026-08-11-deploy-reliability-master-plan.md` | Kernetappen umgesetzt; kein vollständig abgeschlossener Gesamtbetrieb | Abschlussprotokoll enthält Etappen 1–17 samt Zwischenetappen. Neuere Release-Zeile vom 07.09. ersetzt das ältere Ergebnis 44/47 durch 47/47 am damaligen Referenzstand. Externe Abnahmen, 8R-Anbindung, Auditkorrekturen und vertagte Themen bleiben. |
| `2026-08-13-mac-import-vlan-ambiguity-qol-implementation-plan.md` | Konsolidierter Fachplan teilweise umgesetzt, teilweise bewusst gesperrt | 14A/14B/14C und 13R durch Masterplan belegt; 8R-O als deaktivierte Grundlage vorhanden. Die offenen DoD-Kästchen unterscheiden beides bislang nicht zuverlässig. |
| `2026-08-26-esxi-exact-names-wds-preflight-implementation-plan.md` | Lokale 14A-Implementierung vorhanden | Standort-/Canary-Abnahme offen. Kopfbehauptung „Etappe 14B nicht begonnen“ ist durch Masterplan-Abnahme und Create-Code überholt. |
| `2026-08-13-create-flow-reliability-implementation-plan.md` | A–H lokal umgesetzt; reale ESXi-Abnahme offen | Eigenes Abschlussprotokoll A–H und Masterplan 14B belegen den Code. Kopf „noch nichts … implementiert“ ist falsch. `ExitType=cgroup` wurde bewusst dem 8R-Strang zugeordnet; `identity_unbound_allowed` für Full bleibt eine dokumentierte Abweichung. |
| `2026-08-13-deploy-self-healing-implementation-plan.md` | Historische Reviewspur, ausdrücklich konsolidiert | Nicht separat ausführen. Remote-Owner-Rest über den konsolidierten Fachplan und 8R führen. |
| `2026-08-11-ux-implementation-plan-v4.md` | In Masterplan integriert | Umgesetzte UX-Etappen nicht doppelt planen. Abschnitt „Etappe 5“ enthält fünf ausdrücklich nachgelagerte Einzelvorhaben. |
| `2026-08-11-ldaps-active-directory-integration-plan.md` | Lokaler Implementierungsstrang vorhanden | Reale Ziel-AD-Freigabe Gate 0B bleibt offen; die Restarbeitsdatei besitzt den jüngeren Coding-Abschluss. |
| `2026-08-17-ldaps-ad-remaining-work.md` | Coding-Anteil Etappe 7 abgeschlossen | Dokumentiert explizit die verbleibende externe Gate-0B-Grenze. Kein pauschaler weiterer LDAP-Codingauftrag. |
| `2026-08-13-ldaps-target-ad-validation-protocol.md` | **Externe Abnahme offen** | Ziel-DCs, Richtlinien/CBT, Zertifikats-/CA-Rotation und produktionsnahe FPM-Bedingungen brauchen echte Nachweise. |
| `2026-08-20-mission-import-correctness-followup-plan.md` | Wesentliche geplante Implementierung vorhanden; Abschlussstatus fehlt im Plan | `mission_transfer_document_analyze()`, Shape-Tests, Handoff-/Upload-Tests und Changelog belegen die Umsetzung. Kein Anlass, den ursprünglichen Fehlerkatalog unverändert neu auszuführen. Vollständige DoD-Zuordnung und ggf. historischer Runtime-Ini-Nachweis nachtragen. |
| `2026-08-31-form-accessibility-migration-matrix.md` | Abgeschlossene Migrationsmatrix | Masterplan 14 enthält die nachgereichte Abnahme vom 06.09.; menschliche Screenreaderprobe bleibt extern. |
| `2026-08-25-etappe-10c-audit-producer-matrix.md` | Abgeschlossene Producer-Migration | Eigener Abschlussnachweis vom 26.08. vorhanden. Spätere Log-Auditfehler sind eigene Reparaturen, keine erneute vollständige Producer-Migration. |
| `2026-07-07-ssot-security-audit.md` | Abgeschlossen | Kopf: Befunde umgesetzt oder als Entscheidung dokumentiert. |
| `2026-07-hardening.md` | Eingefrorenes Archiv | Offene historische Kästchen sind ausdrücklich nicht nachgeführt. Nicht als aktuelle offene Aufgaben zählen und nicht nachträglich pauschal abhaken. |

## 3. Echte noch nicht implementierte Planarbeit

### O01: RAM-Einheiten und Zielmarkierung

Owner: `2026-08-27-ram-unit-and-deep-link-target-plan.md`, in dessen Reihenfolge **Teil B vor Teil A** ausführen.

- **Teil B, bestehende Regression:** `lib/system_status_ansible_panels.php:48` und `lib/system_status_esxi_panels.php:232` rendern die Credential-Ziele als `article`. `portal/assets/css/tables.css:31` und `:35` enthalten weiterhin `tr:target`. Die geplanten semantischen Zielattribute und die passende komponentenbezogene Markierung fehlen. Öffnen eines Ankers und sichtbares Hervorheben sind unterschiedliche Anforderungen.
- **Teil A, Erweiterung:** `lib/vm_edit_panels.php:189` rendert weiterhin ein numerisches `vm_ram`-Feld mit Preset; `lib/vm_edit_page.php:46` übernimmt dieses Feld direkt. Die geplanten Owner `lib/vm_ram.php` und `lib/vm_edit_ram.php` existieren nicht, und `vm_ram_unit` ist im geprüften Portalpfad nicht implementiert. Umrechnung, Validierung, Anzeige und No-JavaScript-Vertrag des Plans bleiben offen. Der gespeicherte MB-/Wire-Vertrag bleibt erhalten.

### O02: Dauerhaften Remote-Owner tatsächlich anbinden und freigeben

8R-O ist eine implementierte Offline-Grundlage, kein aktiv genutzter Remote-Ausführungspfad. `lib/remote_inventory_consumer.php` und `lib/remote_recovery_policy.php` sind vorhanden; die geprüften Worker-/Maintenance-Produktpfade rufen diese Consumer/Policy nicht auf. `lib/repo/deploy_remote_mode_activation.php` besitzt Leser und Materialisierung deaktivierter Einträge, keinen fertig freigegebenen Aktivierungsweg. Der Claim schreibt weiterhin den Legacy-Vertrag (`lib/repo/deploy_job_worker.php`).

Damit bleibt **Integrations-/Aktivierungsarbeit plus Standortabnahme**, nicht lediglich ein fehlendes Häkchen. Sie muss der bestehenden 8R-Reihenfolge und Standortevidenz folgen. Den schon vorhandenen Supervisor aus 14C nicht neu bauen; dessen Ausführungsform ist bewusst nicht aktiviert. Keine Aktivierungsflags allein deshalb setzen, weil die Offline-Tests grün sind.

### O03: Portal-Require-Closure-Guard

Im Masterplan als eigenes, nicht zugeordnetes Folgevorhaben benannt. Der vorhandene `tests/Static/CliRequireClosureContractTest.php` deckt CLI-Einstiege ab; ein entsprechender Portal-Guard wurde im Prüfumfang nicht gefunden. Der konkrete Dashboard-Fehler ist behoben, der generische Nachweis für nur bedingt aufgerufene Portalhelfer fehlt. Eigene begrenzte Etappe spezifizieren, keine pauschale Bootstrap-Zusammenlegung.

## 4. Offene Auditkorrekturen im aktuellen Code

Diese Restliste stammt aus dem vorherigen Masterplan-Audit und wurde an den genannten aktuellen Kontrollflüssen abgeglichen. `cf75676` ändert die hier genannten Worker-/SSH-/Logpfade nicht. Synthetische Reproduktionen des früheren Audits wurden in dieser Inventur nicht erneut gefahren. Die Einstufung ist deshalb ein belegter Restbefund, keine neue vollständige Laufzeitabnahme.

| ID / Priorität | Noch bestehender Mechanismus und Auswirkung | Fundstelle / notwendige Reparatur |
|---|---|---|
| R01 / P1 | Allgemeines Remote-Cleanup berücksichtigt laufende/ungeklärte Create-Einheiten nicht. Der In-flight-Schalter wird erst in der späteren Playbookschleife gesetzt. Nach einem ungeklärten Create kann dessen Remote-Evidenz entfernt werden. | `lib/deploy_worker_mission.php:41`, Create-Zweig vor der Schleife, `:274`, Cleanup ab `:299`. Cleanupentscheidung an dauerhafte Create-/Handle-Evidenz binden. |
| R02 / P1 | DB-Reconnect benutzt einen Helper, der `cancelling` als sichere Schrittgrenze bestätigt. Ein Reconnect während eines laufenden Create-Polls ist keine solche Grenze. | `lib/deploy_worker_db_channel.php:337` → `lib/deploy_worker_runtime.php:250`. Reine Ownershipprüfung und sichere Cancel-Bestätigung trennen. |
| R03 / P1 | Claim liest den Pausenzustand ohne Sperre; erst später wird die Runtime-Zeile gesperrt, ohne den Zustand erneut auszuwerten. Ein konkurrierendes Pause-Commit kann damit überholt werden. | `lib/repo/deploy_job_worker.php:40`, `lib/repo/deploy_job_service_state.php:42`. Zustandsprüfung unter derselben Sperre wie die Claimentscheidung durchführen. |
| R04 / P1 | Fehlender SSH-Exitstatus wird zu Erfolgscode 0. Ein nicht nachgewiesenes Ergebnis kann als Erfolg weiterlaufen. | `lib/ssh.php:290`. Unknown/Transportfehler erhalten, nur ausdrücklich beobachtete 0 als Erfolg behandeln. |
| R05 / P1 | Zeilenloser Remote-Output wächst vor dem nachgelagerten Loglimit unbegrenzt im Stringpuffer. | `lib/deploy_worker_stream.php:26`. Begrenzten, UTF-8-/Marker-/Redigierungsverträglichen Streamvertrag ergänzen. |
| R06 / P1 | Spool wird vor erfolgreichem Schreiben geleert. Bricht der DB-Drain erneut ab, sind noch nicht geschriebene Zeilen verloren. | `lib/deploy_worker_db_channel.php:357`, `lib/deploy_job_log_spool.php::take()`. Bestätigten Fortschritt statt vorweggenommenem Komplettverbrauch speichern; Duplikat-/Commit-Ungewissheit mitprüfen. |
| R07 / P1–P2 | Worker prüft Netzwerk und Standortevidenz erneut, aber nicht die Scope-Bounds. Nach Queue-Erstellung gewachsene Interface-Sets können die Callbackgrenzen überschreiten. | `lib/deploy_worker_network_preflight.php`. Dieselbe zentrale Scopeprüfung vor jeder Remote-Arbeit wiederholen. |
| R08 / P2 | Portalblocker prüft die gesamte Auswahl wie einen einzelnen Job, auch bei gestaffelter Ein-VM-Queue. Zulässige große Gruppen werden dadurch blockiert. | `lib/deploy_blockers.php:250` gegenüber `lib/repo/deploy_job_queue.php::repo_enqueue_deploy_group()`. Pro tatsächlich entstehendem Job begrenzen, keine zweite Grenzkonstante. |
| R09 / P2 | `stopped` beendet nicht nur Livepolling, sondern sperrt auch das Laden älterer Zeilen eines terminalen Jobs. | `portal/assets/deploy_log.js:419`, `:438`. History-Laden vom Livepoller-Ende trennen. |
| R10 / P2 | Ältere Zeilen werden vorangestellt und am DOM-Limit hinten entfernt; Scrollausgleich nur über gesamte Höhenänderung erhält den sichtbaren Anker dann nicht zuverlässig. | `portal/assets/deploy_log.js:318–336`. Sichtbares Ankerelement/Offset über Prepend und Trim stabil halten. |
| R11 / P2 | CSV-Export liest absteigend mit OFFSET in mehreren Abfragen. Gleichzeitige neue Logzeilen verschieben die Folgeseiten, wodurch Wiederholungen oder Auslassungen möglich sind. | `lib/logs_export.php:117`, `lib/repo/log.php:357`. Exportgrenze und Keyset-Cursor oder äquivalenten konsistenten Lesestand verwenden. |
| R12 / P2 | Recovery-Gesamtanzeige zählt Remote-Reconciliation und aktive Legacy-Recovery, aber keine ungeklärten Create-Ergebnisse terminaler Jobs. | `lib/repo/deploy_job_service_state.php:268`, `lib/deploy_service_health.php`. Tatsächlichen Create-Klärungsbedarf in der gemeinsamen Snapshot-SSoT berücksichtigen, nicht nur auf einer Seite nachzählen. |
| R13 / P2 | Deploy-Hilfe verwendet weiterhin alte englische Modusnamen im deutschen Text statt des aktuellen Portalwortschatzes. | `lang/de/help_deploy.php:32` und zugehörige Hilfesätze. Gegen die zentralen Moduslabels und EN-Datei abgleichen. |

**Noch gesondert zu präzisieren:** Der frühere Auditpunkt zu dauerhaft liegenden Queue-/Cancel-Altfällen ist durch diese Inventur nicht als eigenständiger neuer Fehler reproduziert. Eine akzeptierte Claim-Pause darf Queues absichtlich stehen lassen; der aktuelle normale Cancelpfad terminalisiert queued Jobs direkt (`lib/repo/deploy_job_cancel.php`). Den damaligen Spezialfall mit genauer Altzeile/Übergangsfolge nachstellen, bevor daraus eine generelle Reaperänderung wird. Dies ist ein Verifikationsrest, kein Anlass, pausierte Arbeit automatisch auszuführen oder zu löschen.

**Aus dem alten Audit nicht mehr ungeprüft offen führen:** Die PowerShell-Logging-Reihenfolge wurde in `cf75676` geändert und mit neuen Loggingtests versehen. Der alte Loggingbefund gehört jetzt zur Prüfung der gelieferten Reparatur, nicht zur Liste unverändert vorhandener Fehler.

## 5. Doku und Help: konkret noch offen

### D01: Zwei benannte PowerShell-Hilfekorrekturen fehlen

Der abgeschlossene PowerShell-Diff ändert `help_system_status.php`, aber nicht `help_settings.php`. Dort stehen weiterhin:

- `lang/de/help_settings.php:17`, `settings_token_p1`: Token gelte „nur für die Server-Heartbeats“. Der Vertrag umfasst die vorgesehenen Servermeldungen einschließlich `reportRun`.
- `lang/de/help_settings.php:27`, `settings_reporting_p1`: Alle vier Aufgaben meldeten Beginn und Ergebnis. Site-Health ist completed-only.

Beide waren schon in Abschnitt 6 des PowerShell-Plans ausdrücklich als Korrektur benannt. DE/EN gemeinsam nachziehen und den Paketabschluss um diesen Rest ergänzen. Sprachparität allein erkennt die fachliche Falschaussage nicht. Auch `settings_token_p2` zur tatsächlichen SYSTEM-/Admin-ACL und Rotation mit dem neuen Installer abgleichen; daraus hier keine unbewiesene weitere Sicherheitslücke ableiten.

### D02: Aktuelle Statusübersichten widersprechen ihren Abschlussprotokollen

- Create-Plan: Kopf aktualisieren; A–H-Abnahme erhalten und echte Standortreste ausweisen.
- ESXi-/WDS-Plan: „14B nicht begonnen“ entfernen beziehungsweise datiert als historischen Stand kennzeichnen.
- PowerShell-Plan: Einleitungs-/Startpromptteile beschreiben noch die ursprüngliche Planung; der jüngere Abschluss steht erst hinten. Einen sichtbaren aktuellen Status voranstellen, damit eine neue Session nicht A01–A18 blind erneut startet.
- Mission-Import-Plan: vorhandene Umsetzung mit Abschluss-/Testzuordnung nachtragen; behauptete Runtimewerte nur mit passender damaliger oder neuer Evidenz als abgenommen führen.
- Konsolidierter Fachplan: DoD einzeln in umgesetzt, extern offen und bewusst nicht angebunden einordnen. Kein Massen-Abhaken.
- Masterplan: historische Releasefehler von späteren Behebungen unterscheiden. Das ältere 44/47 ist kein aktueller Dreierblocker mehr. Der Gesamtabgleich ist stellenweise mit sichtbaren Zeichenkodierungsfehlern gespeichert; diesen Doku-Drift bei der Statuspflege beheben.

Das Juli-Archiv ausdrücklich historisch lassen. Diese Restliste ist eine datierte Bestandsaufnahme; fachliche Änderungen und Abnahmen bleiben in den jeweiligen vorhandenen Owner-Plänen dokumentiert.

## 6. Externe Abnahmen, die Codeprüfung nicht ersetzen kann

| Bereich | Fehlender Nachweis | Autoritativer Plan / Aktivierungsgrenze |
|---|---|---|
| Remote-Owner 8R-S | Zielhost mit systemd-User-Bus/Linger, Faultmatrix, Wiederanbindung, kontrolliertes Migrations-/Aktivierungsfenster | Konsolidierter Fachplan und Masterplan 8R. Offline-Implementierung allein reicht nicht; siehe auch O02. |
| Create 14B | Standalone-ESXi: genau eine aktive Create-Einheit, langer EZT-Lauf, Transportverlust, Wiederanlauf, zweiter idempotenter Lauf | Create-Plan Teiletappe H / Masterplan 14B. |
| Netzwerk/WDS 14A | Reale Inventarnamen, Callback-/Canary-Pfade, NIC-/Portgruppenbeobachtung | ESXi-/WDS-Plan Paket I. |
| AD/LDAPS | Ziel-DC-/Policy-/CBT-Matrix, CA-Rotation und FPM-Verhalten | Gate 0B im Ziel-AD-Protokoll; keine Aktivierungsfreigabe aus lokalen Fixtures. |
| MECM-Rollout 14D | Reale getrennte ESXi-/Rolloutnamen, Revisions-Fence, koordinierter Cutover alter/neuer Skriptstände | Masterplan 14D und ADR-0043. |
| PowerShell A01–A18 | Schemaechte MECM-Ausgaben, Journal-Crash/ACK-Verlust, interaktives Upgrade/Rollback, SourceVersion und Cleanup; Client-Snapshot/Detection unter SYSTEM; Netzwerk-/Disk-Abbrüche; TLS/Byte-Empfänger | Exakte Fälle pro Paket im PowerShell-Abschlussprotokoll. Nicht als 18 fehlende Codepakete zählen. |
| Accessibility | Menschliche Screenreader-Stichprobe der Kernflüsse | PRE-SHIP und Masterplan 14/17. Axe-/Tastaturtests sind bereits vorhandene, andersartige Nachweise. |

## 7. Bewusst nachgelagerter Umfang

- PowerShell-Plan **U01–U07**: Handlungsbedarf, MECM-Warteliste, Clientverlauf je Rollout, Netzwerk Soll/Ist, Paketbereitschaft, Bereinigungs-/Setupvorschau, Versions-/Diagnoseansicht.
- UX-Plan Abschnitt „Etappe 5“: Dashboard-Nächster-Schritt, Help-Inhaltsverzeichnis, Cadence-Zeilen, Sticky-Speichern/Dirty-Warnung und generischer Auto-Refresh-Controller. Nicht als fünf zusätzliche Pflichtfehler behandeln; Überschneidung mit U01 vor späterer Planung konsolidieren.
- Full-Identitätsartefakt: `identity_unbound_allowed` ist laut Create-Abschluss noch fachlich erforderlich, weil `serverlist.yml` vor Create erzeugt wird. Kein Blindfix durch Entfernen des Flags. Falls das Plan-Ziel weiter gelten soll, eigenständige Änderung der Artefakt-/Identitätsaktualisierung samt Hostnachweis planen; andernfalls die begründete Abweichung endgültig entscheiden.

## 8. Nicht erneut als offen zählen

- Vorlagengrenzenlücke für `vm_name`: `7fe9b18` ergänzt die globale Prüfung beim Umbenennen samt Integrationstest und Masterplan-Nachtrag.
- Mission-Import-Shape-/Handoff-Grundreparatur: kanonischer Dokumentanalysator und eigene Unit-/Integration-/Uploadtests vorhanden; fehlender Planabschluss ist kein Beweis fehlenden Codes.
- Create-Implementierung 14B, Supervisor-Implementierung 14C, Formularmigration 14 und Auditmigration 10C: vorhandene Implementierungen und Abschlussnachweise erhalten.
- Frühere Release-Browser-/Imagebefunde nicht aus der alten Etappe-17-Zeile wieder eröffnen. Der dokumentierte spätere Lauf bestand mit 47/47. Dieser Lauf datiert seinen damaligen Code-/Toolstand; er ist keine neue Gesamtabnahme von `cf75676`.

## 9. Sinnvolle nächste Arbeit

1. Eigenen Korrekturplan für R01–R13 erstellen, zuerst Remote-Evidenz, Cancelgrenze, Claim-Sperre, SSH-Ergebnis und Logverlust. Nicht in das bereits umgesetzte PowerShell-Paket hineinmischen.
2. D01 nachziehen und D02 als begrenzte Planstatuspflege bearbeiten. So erhält die nächste Session einen richtigen Einstieg.
3. Den vorhandenen RAM-/Deep-Link-Plan ausführen, zuerst die kleine Zielmarkierungsregression, anschließend RAM-Einheiten.
4. O02 und die externen Abnahmen mit konkreter Testumgebung verbinden; Aktivierung und erfolgreiche Implementierung getrennt protokollieren.
5. O03 und die optionalen U-/UX-Pakete anschließend nach Nutzen priorisieren.

Keine Aussage dieser Inventur bedeutet, dass alle verbleibenden Implementierungen fehlerfrei seien. Sie beantwortet, welcher dokumentierte Arbeitsumfang noch offen, schon implementiert oder nur historisch offen markiert ist, und benennt die im aktuellen Code weiterhin nachvollziehbaren Auditkorrekturen.
