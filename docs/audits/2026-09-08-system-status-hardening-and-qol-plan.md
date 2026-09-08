# Systemstatus: vertiefter Audit und Umsetzungsplan

Stand: 08.09.2026. Planstatus: zur anschließenden Umsetzung vorbereitet; Produktänderungen noch nicht begonnen.

## 1. Auftrag, Grenzen und Nachweisstand

Die Systemstatus-Seite soll zuverlässig beantworten: Was wurde beobachtet, wie aktuell ist dieser Nachweis, was bedeutet er für den Betrieb, und was kann die betrachtende Person als Nächstes tun? Hilfe, Dashboard, Bereitstellungsseite und anonymer Health-Endpunkt müssen dieselben fachlichen Tatsachen richtig einordnen, ohne überall dieselbe Informationstiefe auszugeben.

Untersucht wurden `portal/system_status.php`, ihre Panel-/POST-Owner, Integrations- und Deploy-Snapshots, Inventar-/Netzwerk-/Recovery-Repositories, AD-Anzeige, Ansible-Testpersistenz, `portal/health.php`, `core.js`, Hilfepartials, DE/EN-Katalogstruktur und einschlägige Tests/ADRs. Der frühere Audit liegt lokal unter `qa-artifacts/system-status-audit-2026-09-08.md`; dieser Plan übernimmt seine wesentlichen Befunde und ist ohne dieses ignorierte Artefakt umsetzbar.

Ausgangs-HEAD war `2635b271c4fab3d46bbac633ac72af3c76d0e643`. Im Checkout arbeiten andere Sessions; insbesondere Ansible-Laufzeit, Zugangsdaten-/Systemstatus-Hilfe, Tests und Betriebsdokumentation werden parallel geändert. Vor jeder Etappe den aktuellen Stand und das Eigentum betroffener Änderungen erneut feststellen. Fremde Änderungen weder zurücksetzen noch stagen oder committen.

Hier gibt es laut Betreiber keinen echten MECM-Server und keinen echten ESXi. Angezeigte Inventarwerte sind Test-/Altdaten. Es wurden keine Inventar-, Recovery-, Pause- oder Korrekturaktionen im Entwicklungsportal ausgeführt. Neue Integrationsfixtures dürfen nur den isolierten QA-Kontext verwenden, nie den Entwicklungsbestand auf Port 8021. Keine deaktivierten Remote-Modi, keine AD-Anmeldung und kein `supervisor_v1` zum Zweck dieses Audits einschalten.

Nachweisarten:

| Kürzel | Bedeutung |
|---|---|
| L | Im laufenden Browser beobachtet/reproduziert. |
| S | Gegen reine PHP-Funktionen mit synthetischen Eingaben ausgeführt. |
| C | Direkt am aktuellen Codepfad nachgewiesen; zusätzliche Laufzeitregression ist noch zu schreiben. |
| H | Belastbarer Verdacht oder Robustheitslücke; Reachability/Produktwirkung vor einem Fix gezielt prüfen. |

Historische lokale Nachweise dieses Audits: 92 ausgewählte Systemstatus-/Hilfe-Tests mit 877 Assertions bestanden; Require-Closure-Stichprobe: 24 Tests mit 36 Assertions bestanden. Die ausgewählten Gates `lang-parity`, `doc-hygiene`, `doc-semantics` bestanden. Das war keine vollständige Fast-, Integration- oder Release-Lane. Der Require-Closure-Guard ist in `1035596` umgesetzt; die Dashboard-Mutation, dynamische Aufrufe, Namespace-Auflösung, Registry-Drift und Zero-Match sind bereits abgesichert. Ihn nicht neu entwickeln.

Während des vertieften Audits wurde Docker unerreichbar (Pipe `dockerDesktopLinuxEngine` nicht vorhanden). Eine zusätzlich vorbereitete Gegenprobe mit rein sitzungsgebundenen temporären QA-Tabellen konnte deshalb nicht starten. `qa-artifacts/system-status-deep-edge-probes.log` enthält den Infrastrukturfehler, keinen erfolgreichen Test. Die neuen Datenbank-/Concurrency-Befunde sind entsprechend C/H, nicht als Laufzeitbeweis ausgegeben. Ein neuer grüner Lauf ist Voraussetzung für die spätere Abnahme.

## 2. Befundregister und gewünschtes Verhalten

Die Priorität bezeichnet die Umsetzungsreihenfolge, nicht eine bereits nachgewiesene Ausnutzung. P1 betrifft die Identität einer Schreibaktion bzw. eines als gültig gespeicherten Nachweises; P2 die fachliche Richtigkeit und Bedienbarkeit; P3 Reduktion und langfristige Wartbarkeit.

| ID | Prio / Evidenz | Was ist falsch oder fehlt? | Wieso ist das relevant? / Soll |
|---|---|---|---|
| SS-01 | P1 C/S | GET-Vorbelegung und POST der VLAN-Massenkorrektur verwenden `request_trimmed()`. | ` VLAN 700 ` wird zu `VLAN 700`; eine andere exakte Namensgruppe kann geändert werden. Rohwerte unverändert erhalten, Leerprüfung davon trennen. |
| SS-02 | P2 C | Fehlerhafte Einzelziel-IDs fallen über `request_int()` auf 0 bzw. einen anderen Integer zurück; `<=0` wählt den Bulk-Zweig. | Ein ungültiger Einzelabruf darf nicht alle Zugänge betreffen. Einzel-/Gesamtscope explizit unterscheiden; ungültige Scalars, Arrays, negative und übergroße IDs vor jedem Write zurückweisen. |
| SS-03 | P2 L/C | Hilfe-Querverweis ändert den Hash, aktiviert aber das verborgene Zielpanel nicht. | Interner Link zu `#help-deploy-service` ist nutzlos, frischer Direktaufruf funktioniert. Gemeinsame Hash-Auflösung bei Initialisierung und Navigation. |
| SS-04 | P2 C/S | Bereits ein ESXi-Zugang setzt `hasInventory=true`; leere Kategorien werden im Scan übersprungen und ergeben 0. | Nicht geprüft und erfolgreich verglichen werden verwechselt. Auswertbarkeit je Art ist Teil des Ergebnisses. |
| SS-05 | P2 L/C | Der Abweichungsbericht nutzt alte/unqualifizierte Namensunion ohne eigenen Beweisstand. | Live standen acht Abweichungen einem alten Inventar mit unbestätigter Namenssemantik gegenüber. Historische Diagnose als solche kennzeichnen; kein aktueller Negativbeweis. |
| SS-06 | P2 L/S/C | Das Detailbadge „Bereit“ verwendet die Gesamtfarbe aus Verfügbarkeit und Klärung; außerdem doppelte Beschriftung. | Rotes „Bereit“ widerspricht der zugesagten Achsentrennung. Gesamtbadge und Achsenbadge nicht vermischen. |
| SS-07 | P2 L/C | „Manuelle Klärung nötig“ nennt weder Anzahl noch Jobs/VMs oder konkrete Maßnahmen. | Der Benutzer kann den Zustand nicht abarbeiten. Vollständige Zähler plus begrenzte, berechtigt verlinkte Fallliste anbieten. |
| SS-08 | P2 L/C | „Letztes Lebenszeichen“ liest nur `$active['heartbeat_at']`. | Im Leerlauf Strich trotz lebendem Dienst. Auftrag und Dienst ausdrücklich unterscheiden. |
| SS-09 | P2 C | `repo_deploy_active_job_summary()` filtert `locked_at IS NOT NULL` und liest nur den ersten aktiven Job. | Aktive unbesessene Jobs verschwinden, weitere aktive Jobs bleiben unsichtbar. Legitime Recovery ohne Lock existiert ausdrücklich; diese von tatsächlich inkonsistenten Jobs unterscheiden. |
| SS-10 | P2 C/H | Recovery-Zähler verbinden Remote-Ausführungen nur über `job_id`, ohne aktuelle Attempt-/Generationseingrenzung; `COUNT(*)` zählt Handles. | Historische Versuche können die Anzeige beeinflussen; mehrere Handles vervielfachen einen Job. Aktuelle Zuordnung/erlaubte historische Ungewissheit fachlich definieren und Jobs eindeutig zählen. Reachability alter unaufgelöster Handles vor Fix reproduzieren. |
| SS-11 | P2 C | Pause-Repository ist idempotent, HTTP-Handler auditiert jedoch jeden Pause-POST. Vorab gelesener aktiver Job gehört nicht zur atomaren Entscheidung. | Audit behauptet Änderungen/Jobbezug, die dieser Klick nicht bewirkt hat. Übergangsresultat inklusive `changed` und tatsächlichem Kontext aus dem Transaktionsowner liefern. |
| SS-12 | P2 C | Inventar-Taktbeschreibung kennt Intervall, Ansible-Auswahl, Worker-Lebenszeichen, Auth-Pause; Claim-Pause und Ausfall des planenden Wartungsdiensts fehlen. | Lebender Worker genügt nicht für wiederkehrende Inventaraktualisierung. Planung, Warteschlange und Ausführung getrennt erklären. |
| SS-13 | P2 C | MECM-Setupzustand ist `syncState===unknown && siteState===unknown`. | Eine erste laufende Meldung oder ein Providerfehler ist ebenfalls unknown, aber kein Beweis fehlender Einrichtung. Vorhandensein von Meldungen unabhängig von Ergebnisfarbe bestimmen. |
| SS-14 | P2 C | Site-Status verwendet die allgemeine Completed-Alterung; ein alter Providerfehler wird dadurch danger. | Verstößt gegen ADR-0018: Rot nur bei MECM-bestätigtem Site-Status 2. Site-Verfügbarkeit des Nachweises und Site-Ergebnis separat behandeln. |
| SS-15 | P2 C | Neue `started`-Meldung bewahrt vorherige Summary/Fehler/Dauer; der Renderer zeigt diese neben dem neuen Start ohne eindeutige Laufzuordnung. | Das Beibehalten ist vertraglich richtig, kann aber als Fehler/Zähler des gerade laufenden Versuchs gelesen werden. Aktuellen Start und letzten abgeschlossenen Lauf beschriften; keine Wire-Änderung. |
| SS-16 | P1 C/H | Ansible-Test liest Zugang/Secret, arbeitet extern und schreibt anschließend ungeprüft per ID; eine parallele Änderung löscht nur zwischenzeitlich den alten Teststand. | Ein Ergebnis für den alten Host kann nach einer Änderung wieder als gültiger Test des neuen Zugangs erscheinen. Persistenz an getestete Konfiguration und Testgeneration binden; Laufzeitrace mit kontrolliertem Testdouble nachweisen. |
| SS-17 | P2 C | AD-Zähler prüft nur enabled + validated_revision, beschriftet das als „einsatzbereit“. | Dieselben Controller können im gemeinsamen Snapshot gestört/veraltet sein. Zulassung und letzter Betriebsnachweis getrennt zählen/beschriften. |
| SS-18 | P2 C | VLAN-Korrekturlink kann existieren, obwohl `#reassign` wegen leerem aktivem Katalog nicht gerendert wird. Resolver-Flash verlinkt Einstellungen ohne Zielrecht. | Toter Anker bzw. vermeidbarer 403. Link und Ziel aus identischer vollständiger Bedingung ableiten; Erklärung ungated lassen. |
| SS-19 | P2 C/S | Inventarlegende behauptet generelle Pause nach Fehlerstreak; Hilfetext behauptet Erhalt jedes leeren Teilergebnisses. | Auth-Pause ist nicht Retry-Streak; autoritativ leer ist nicht abgelehnt/übersprungen. DE/EN und Betriebshilfe fachlich angleichen. |
| SS-20 | P2 H/C | Fehlende/unlesbare Beweiszeit kann bei Ansible/Completed-Ergebnis grün bleiben; Zukunftszeit wird als frisch behandelt. | Beschädigter oder importierter Zustand darf keinen aktuellen positiven Nachweis vortäuschen. Korruptionsfälle und Uhrsprünge klassifizieren, Grenzen am bestehenden Owner festlegen. |
| SS-21 | P2 C/H | Integrations- und Service-Snapshot lesen dieselben Heartbeats erneut mit eigenem `time()`; Queue-SQL nutzt `NOW()` trotz injiziertem `$now`. | Bei Grenzzeiten oder Änderungen während des Requests können Details auseinanderlaufen. Eine Auswertungszeit verwenden und bewusst festlegen, welche Lesungen kohärent sein müssen. Kein Laufzeitnachweis eines solchen Flackerns vorhanden. |
| SS-22 | P2 C | Ein Fehler beim Service-Snapshot kann die ganze Statusseite verhindern; Health behandelt Snapshotfehler bereits separat. | Diagnoseoberfläche fällt im Diagnosefall weg. Erwartete Teilausfälle sichtbar, neutral und lokalisiert darstellen; keine fehlenden Daten zu OK/0 umdeuten. |
| SS-23 | P3 C | Ankerhelper prüft nur Syntax, nicht Existenz; einzelne Missionslinks umgehen `mission_details_url()`. | Syntaktisch gültige tote Ziele und Navigation-Drift bleiben möglich. Bestehende Owner verwenden, dynamische/bedingte Anker gezielt testen. |
| SS-24 | P3 L/C | Leeres MECM nimmt viel Platz ein; Überblick enthält den Deploy-Dienst und bedingtes AD nicht; globale Abweichungen werden vollständig gerendert. | Relevante Arbeit geht zwischen Platzhaltern und großen Listen verloren. Leerzustand verdichten, Navigation vervollständigen, große Ergebnisse begrenzen/filtern. |
| SS-25 | P2 C/H | Abweisungsübersicht liest höchstens fünf IPs ohne Gesamtzahl; zeigt historische Ablehnungen unabhängig von späterer Erholung. | Liste wirkt vollständig bzw. wie eine aktuelle Blockade. Historie, Zeitfenster und Kürzung explizit machen; keine Korrelation zu MECM erfinden, da auch Ansible dieselbe API nutzt. |

## 3. Architekturentscheidungen für die Umsetzung

1. Kein universeller neuer Health-Status. Bestehende Achsen und fachliche Bedeutung je Quelle bleiben erhalten. Insbesondere Prozessliveness, Arbeitsfähigkeit, Claim-Pause, historische Ergebnisse und aktueller Beweis sind unterschiedliche Tatsachen.
2. `deploy_service_health_snapshot()` bleibt der einzige Deploy-Service-Owner. Die Detail-UI bekommt vollständige fachliche Fakten von dort, keine zusätzlichen direkten Jobqueries in Renderern.
3. `integration_health_snapshot()` liefert Meldungsvorhandensein, Bewertung und denselben Auswertungszeitpunkt. Die UI leitet „eingerichtet“ nicht aus Farbe ab. Bestehende konstante Quellenregistries bleiben maßgeblich.
4. `esxi_inventory_kind_evidence()`, `esxi_datacenter_resolution()` und `esxi_object_names.php` bleiben Eigentümer von Inventarevidenz und exakter Identität. Ein Abweichungs-Snapshot aggregiert diese Informationen, kopiert ihre Regeln aber nicht. Autoritativ leere Mengen können auswertbar sein; positive Namensanzahl allein ist ebenfalls kein ausreichender Auswertbarkeitsvertrag.
5. Der Bericht bleibt vor Zielhostwahl ein Vergleich gegen die Inventarunion. Er darf daraus keine Eignung eines konkreten Hosts behaupten. Zielgebundene Queue-/Worker-Prüfungen bleiben unverändert zuständig.
6. `repo_reassign_vlan()` und `lib/repo/vm_network.php` bleiben die einzigen Writer der Massenkorrektur. Keine zweite Update-Schleife im Portal. MAC-Erhalt, aktive Jobs und Mission-WDS sind Bestandteil jeder Abnahme.
7. `directory_health_snapshot()` bleibt der Owner der AD-Bewertung. Renderer dürfen lediglich benennen/ausgeben, nicht parallel gesund zählen.
8. Die bestehende Tabimplementierung in `core.js` und `help_url()` bleiben die einzigen Navigationsowner. Kein zweites Hilfeskript, keine parallele Ankerliste, keine Timeouts zur Reparatur des Hash-Wechsels.
9. Der anonyme Health-Endpunkt bleibt ein grober Adress-/Bereitschaftscheck: 200 für bedienbare degradierte Anwendung, 503 nur bei tatsächlicher Nichtbedienbarkeit nach bestehendem Vertrag. Keine Jobdetails, Namen, IPs, Queuezahlen oder neuen Diagnosefelder anonym ausgeben. Eine manuelle Klärung allein muss ihn nicht rot machen.
10. Keine automatische Reparatur beim Anzeigen/Refresh. Keine neuen Telemetrie-Dienste, CDN-Abhängigkeiten, Hintergrundprobes auf MECM oder automatischen Inventarstarts. Live-Anzeigeaktualisierung ist eine spätere optionale Etappe.

## 4. Etappen mit Was, Wieso, Ownern und Abnahme

### E0: Reproduzierbare Ausgangsbasis und gezielte rote Tests

**Was:** Aktuellen HEAD, Dirty-Dateien und fremde Zuständigkeiten erfassen. QA-Stack anhand Compose-Labels identifizieren; Docker-Verfügbarkeit wiederherstellen lassen, bevor Laufzeitabnahme behauptet wird. Bestehende Tests gezielt ergänzen, je Befund zunächst den tatsächlich falschen Fall reproduzieren. Neue Fixtures eindeutig markieren und in `finally` vollständig entfernen. Für reine SQL-Leser sind verbindungslokale temporäre Tabellen geeignet; deren Schema muss die getesteten Semantiken abbilden, echte Writer brauchen echte isolierte Integrationstests.

**Wieso:** Die bisherigen grünen Tests liefern häufig bereits vorgefertigte Zustände. Beispielsweise prüft `DeployServiceHealthTest` die Regel für ein inkonsistentes aktives Job-Fact, nicht ob der SQL-Reader diesen Fact überhaupt liefern kann. Der Provider-Test übergibt bereits `state=unknown` und deckt die Alterung nicht ab.

**Abnahme:** Pro C/H-Befund Fixture, erwartetes Verhalten, tatsächliches Ergebnis und Owner notieren. H-Befunde bei Widerlegung schließen statt unnötige Umbauten auszuführen. Keine erneute breite Release-Lane auf parallel veränderlichem Checkout. Vorhandenen Require-Closure-Guard nur weiterverwenden.

### E1: Schreibscope und exakte VLAN-Korrektur absichern

**Befunde:** SS-01, SS-02, Teile SS-18/24.

**Was:** Rohwerte auf allen Transportstufen erhalten. Bulk-/Einzelabruf explizit normalisieren; vorhandene Bulkform darf weiterhin bewusst alle geeigneten Zugänge wählen, malformed Einzelinput darf nie dorthin fallen. Eine read-only Vorschau der VLAN-Korrektur zeigt exakte Quelle/Ziel, betroffene Missionen/Vorlagen/VMs/Interfaces und aktive Konfliktjobs. Vorschau und Write verwenden dieselbe Scope-Ermittlung. Ein versionierter Fingerprint bindet die Bestätigung an den angezeigten vollständigen Scope, nicht an dessen gekürzte Ansicht. Der Writer liest diesen Scope im vorhandenen Lockpfad neu; jede relevante Änderung verlangt neue Vorschau. Aktive Ziel-Katalogeigenschaft unmittelbar vor dem Write prüfen, nicht nur außerhalb der Transaktion.

**Wieso:** Die heutige Bestätigung „alle Zuweisungen ändern?“ bindet weder die Identität noch den konkreten Umfang der angekündigten Änderung. Zwischen Vorschau und Submit können neue Interfaces, Missionen oder Jobs entstehen. Die Reparatur darf eine bestätigte Auswahl nicht still erweitern.

**Owner:** `portal/system_status.php`, `lib/system_status_page.php`, `lib/esxi_inventory_deviations.php`, `lib/repo/vm_network.php`, `lib/forms.php`; bestehende Fingerprint-/Bounds-Owner wiederverwenden, genaue Struktur vor Implementierung dort registrieren. Große Scope-Ermittlung nicht mit einer neuen globalen Lockstrategie überziehen; bestehende Mission -> Job -> VM -> Interface-Reihenfolge bewahren.

**Abnahme:** Exakte Rand-Leerzeichen-/Case-/Unicodevarianten bleiben verschieden; nur ausgewählte Variante geändert. `credential_id[]=1`, `-1`, `0`, `12abc`, Overflow und gelöschtes/fremdtypiges Einzelziel erzeugen keinen Bulkjob. Cancel/no-JS/CSRF/RBAC geprüft. Parallel neuer Scope, geändertes Ziel, laufender/cancelling Job oder MAC-Änderung: kein unerwarteter Write. Fehler erhalten Formzustand. Leerer aktiver Katalog erzeugt keinen toten Reparaturlink. Audit nennt echten Umfang ohne Secrets.

### E2: Deploy-Service-Fakten, Recovery und Audit korrekt machen

**Befunde:** SS-06 bis SS-11, SS-21/22.

**Was:** Aktive Jobs vollständig erfassen, normalen Besitzer, legitime Recovery ohne Besitzer und unzulässige Inkonsistenz unterscheiden. Bei mehreren aktiven Jobs deren Zustand sichtbar/diagnostizierbar machen; `LIMIT 1` darf weitere Inkonsistenzen nicht verschlucken. Recovery-Fallauswahl und Zähler teilen denselben Owner und dieselbe Definition von aktueller Zuordnung. Pro Job deduplizieren; aktuelle Attempt-/Generation-/Create-Unit-Bindung beachten. Terminale `uncertain`-Create-Einheiten bleiben ausdrücklich sichtbar. Legitimen historischen Nachweis nicht durch einen pauschalen Current-Attempt-Filter verlieren.

Den Pause-Writer ein strukturiertes Ergebnis zurückgeben lassen: Zustand, ob geändert, tatsächlicher Jobkontext und Änderungszeit. Nur erfolgreiche echte Transitionen auditieren, nach Möglichkeit im gleichen vorhandenen Transaktionsrahmen. Resume-/Worker-CAS erhalten. Die Karte zeigt beschriftete Achsen, ihre eigenen Farben, Fallzahlen/Links sowie getrennte Auftrags-/Dienstzeitpunkte. Ein lesender Snapshotfehler wird als fehlende Diagnose dargestellt und protokolliert; Aktionen dürfen daraus keine Berechtigung/Freigabe ableiten.

**Wieso:** Ein genauer Policy-Algorithmus hilft nicht, wenn seine Eingabefakten unvollständig sind. Doppelzählungen und blinde rote Hinweise erschweren die Klärung. Wiederholte POSTs dürfen keine erfundenen Übergänge erzeugen.

**Owner:** `lib/repo/deploy_job_service_state.php`, bestehende Remote-/Create-Repositories, `lib/deploy_service_health.php`, `lib/system_status_service_actions.php`, `lib/system_status_service_panel.php`. Alle vier konsumierenden Oberflächen mitprüfen. Fachliche Recovery-Policy nicht im Renderer duplizieren.

**Abnahme:** Gesund/leerlaufend; aktiv konsistent; aktiv ohne Besitzer mit/ohne Recovery; mehrere aktive Jobs; alle Claimzustände; terminal uncertain; gelöster historischer Handle; mehrere Handles/ein Job; alte Versuche/neuer Versuch; Recovery/Cancel-Race. Doppel-Pause und zwei gleichzeitig pausierende Sitzungen erzeugen genau einen Zustandswechsel samt Audit. Resume während Workerbestätigung bleibt wirksam. Nicht vorhandener Benutzer im Audit erhält verständlichen Fallback. Anonymer Health-Body bleibt minimal.

### E3: Meldungsevidenz und MECM-Site-Semantik trennen

**Befunde:** SS-13 bis SS-15, SS-20/21/25.

**Was:** Vorhandene Meldung, laufender Versuch, letzter abgeschlossener Lauf und dessen Frische im Snapshot ausdrücken. Setup nur dann anzeigen, wenn tatsächlich kein entsprechender Bericht existiert. Site-Bewertung im vorhandenen Statusowner von Sync-Alterung unterscheiden: fehlende aktuelle Beobachtung darf keine MECM-Kritikalität erfinden. Bei einem veralteten ehemals kritischen Bericht den historischen kritischen Befund und seinen Zeitpunkt behalten, aber nicht als aktuell bestätigt ausgeben. Schwellen und Zeitbasis aus bestehenden Konstanten/Requestclock ableiten.

Laufende Syncs beschriften mit „Aktueller Lauf gestartet …“ und „Letztes abgeschlossenes Ergebnis …“; dazugehörige Zähler, Dauer und Fehler klar zuordnen. Legacy-Wechsel darf alte V2-Felder höchstens ausdrücklich historisch zeigen. Fehlende, unlesbare oder auffällig zukünftige Zeitwerte sind kein frischer Erfolgsbeweis. Abweisungen als beobachtete historische Ereignisse im Zeitfenster anzeigen, Kürzung und vollständige Zahl angeben. Ohne positive Quellenzuordnung nicht behaupten, eine bestimmte IP sei der MECM-Server oder müsse freigegeben werden.

**Wieso:** Unbekannt bedeutet nicht unkonfiguriert; ein fehlender Reporter bedeutet nicht kritisches MECM. Aufbewahrte Ergebnisse sind nützlich, solange erkennbar bleibt, zu welchem Versuch sie gehören.

**Owner:** `lib/status.php`, `lib/integration_health.php`, `lib/repo/heartbeats.php` (Lesefakten; Wire-/Writersemantik bewahren), `lib/system_status_mecm_panels.php`, `lib/repo/log.php`. Keine Änderung an reportRun-Envelope, Arrival-order oder Replay-Regeln.

**Abnahme:** Keine Quelle; erster Start ohne Abschluss; Siteproviderfehler ohne Sync; Sync unknown ohne Site; teilweise eingerichtete Quellengruppe; Site 0/1/2/unlesbar/provider-error jeweils frisch und veraltet; Grenzen exakt, -1/+1 Sekunde; Legacy nach V2; Start nach fehlgeschlagenem Abschluss; fehlerhafte Summary nur neutral/diagnostisch, kein Fatal. Mehr als fünf abgewiesene IPs, gleiche Zeitstempel und später erfolgreicher Bericht. Gültige Serverzeiten bleiben unverändert bewertet.

### E4: Inventarvergleich und tatsächlichen Aktualisierungspfad erklären

**Befunde:** SS-04/05/12, Teile SS-18/19/21/24.

**Was:** Einen Ergebnisvertrag für den Abweichungsbericht definieren: Auswertbarkeit je Art, letzte Beobachtung, qualifizierter Namensstand, vollständige Anzahl Befunde, dargestellte Teilmenge und ausgelassene Treffer. Namensevidenz am bestehenden Kind-Owner abfragen. Autoritativ leer, abgelehnt, übersprungen und fehlender Nachweis getrennt behandeln. Historische Vergleiche weiterhin als Diagnose erlauben, jedoch ohne aktuelle Erfolgs-/Abwesenheitsbehauptung.

Inventar-Takttext berücksichtigt getrennt: automatische Planung durch Maintenance, bereits vorhandene Queuejobs, Claimannahme, ausführender Worker, Ansible-Zuordnung und individuelle Auth-Pause. Alle konkreten Blocker nennen, nicht einen Grund als alleinige Ursache behaupten. Inventar darf trotz pausierter Claims geplant/eingereiht sein; die UI muss dann „wartet auf Fortsetzung“ statt „kein Auftrag möglich“ sagen. Ein manueller Abruf bleibt ein bewusster Versuch; keine stillen Re-Tests beim Seitenladen.

**Wieso:** Ein erfolgreich gestarteter Abruf, ein vorhandener Cache und eine heute bewiesene Objektmenge sind unterschiedliche Aussagen. Die heutige boolesche Aussage `deploy_worker_alive` kann keine gesamte Aktualisierungskette erklären.

**Owner:** `lib/esxi_inventory_evidence.php`, `lib/esxi_inventory_deviations.php`, `lib/repo/esxi_inventory_queries.php`, `lib/esxi_automation.php`, `lib/integration_health.php`, `lib/system_status_esxi_panels.php`, `lib/credentials_status.php`. Änderungen an Schedulerentscheidungen nur mit eigenem nachgewiesenem Bedarf; zunächst Darstellung aus realen Regeln ableiten.

**Abnahme:** Zugang ohne Cache; nur unsupported Namen; teilweise Artbelege; bestätigte leere Menge; fehlgeschlagene Folgeabfrage erhält alte Evidenz; mehrere Hosts mit nur teilweise gleichem Namen; Intervall 0; Auth-Pause; fehlender/mehrdeutiger Ansible-Zugang; Wartungsdienst aus; Deploydienst aus; Claims pausiert; bereits queued/running; gleichzeitige Cacheänderung. Overviewzahl und Liste stammen aus derselben Ergebnismenge. Ein fehlender Datacenter-Negativbeweis darf keine Deployfreigabe erzeugen.

### E5: Ansible-Testergebnisse und AD-Anzeige gegen Drift absichern

**Befunde:** SS-16/17/20.

**Was:** Ansible-Testanfang an einen unveränderlichen Konfigurationsstand binden. Zugang und Secret aus demselben serverseitigen Stand lesen. Ergebnis nur speichern, wenn relevante Zugangsdaten nach Abschluss noch diesem Stand entsprechen. Gegen zwei gleichzeitig laufende Tests eine eindeutige Testgeneration verwenden, damit ein älterer Test nicht einen neueren überschreibt. Das vorhandene sekundengenaue `updated_at` allein reicht für Änderungen in derselben Sekunde nicht als Beweis; geeignete monotone Revision oder serverseitig überprüfte opaque Version wählen. Keine Secretwerte oder vergleichbaren Fingerprints in Browserlogs/Audit ausgeben. Während SSH/SFTP keine Datenbanksperre halten.

AD-Zahlen und Zustandstext ausschließlich aus `directory_health_snapshot()` ableiten. „Zugelassen“, „zuletzt erfolgreich“, „gestört“ und „Nachweis veraltet“ fachlich benennen. Zertifikat bereits abgelaufen vs. bald ablaufend untersuchen; aus alten Tests keine aktuelle Zertifikatsprüfung behaupten. Letzten Erfolg entweder als historischen Gesamterfolg beschriften oder auf den ausdrücklich genannten gültigen Pool begrenzen.

**Wieso:** Ein grüner Test darf nicht nachträglich einer ungetesteten Konfiguration zugeordnet werden. Eine validierte Konfiguration ist kein Beweis eines heute funktionierenden Controllers.

**Owner:** `lib/credentials_actions.php`, `lib/repo/credentials.php`, `lib/repo/ansible_preflight.php`, `lib/directory_status.php`, `lib/system_status_directory_panels.php`. Überschneidung mit laufender Ansible-Arbeit vor Beginn abgleichen. Falls eine Migration nötig ist, additive Einführung und Legacyzustand unbekannt vorsehen, keinen erfundenen Backfill.

**Abnahme:** Langsamer Test A, Update auf B, Abschluss A darf B nicht grün setzen; zwei Tests mit umgekehrter Abschlussreihenfolge; Löschung/Typwechsel während Test; Secretrotation in gleicher Sekunde; fehlende API-URL als explizit ungeprüfter Teil des Tests. AD: kein Configdatensatz, deaktiviert, keine Controller, nur ungetestete, gemischt gesund/gestört/veraltet, alle gestört, Bind-Circuit-Breaker, alte Revision, abgelaufenes Zertifikat. Alles lokal mit Testdoubles/Fixtures, kein echtes AD aktivieren.

### E6: Navigation, Berechtigungen und verständliche Fehlerpfade

**Befunde:** SS-03/18/22/23.

**Was:** Gemeinsame Hash-Auflösung in `core.js`; direkte Links, interne Querverweise und Browsernavigation aktivieren zuerst das Zielpanel. Fokusziel muss tatsächlich fokussierbar sein; sichtbare Überschrift nicht hinter Sticky-Header scrollen. Ungültige/verdeckte/nichtberechtigte Fragmente fallen verständlich auf sichtbaren Inhalt zurück. Linkerzeugung für Missionen auf vorhandenen Helper umstellen. Bedingte Anchors in Statusseite und Hilfe über reale DOMzustände prüfen, keine zweite handgepflegte Seitenliste.

Resolver-/Katalog-/Recovery-Fehler verlinken ausschließlich erreichbare berechtigte Ziele. Änderungen durch andere Benutzer zwischen Ansicht und POST führen zu verständlichem Ergebnis mit erhaltenem Eingabestand. Wenn ein Fehler den Korrekturabschnitt zwischenzeitlich entfallen lässt, bleibt der Fehler an einer sichtbaren Stelle und der Redirect zeigt kein nicht existentes Ziel.

**Wieso:** Ein korrekter Hinweis ohne erreichbare Abhilfe löst den Arbeitsfall nicht. Statische Ankerexistenz deckt weder versteckte Tabs noch RBAC-abhängige DOMzustände ab.

**Abnahme:** Hilfe-Systemstatus -> Deployhilfe -> zurück; Direktaufruf, Reload, Hashwechsel, Zurück/Vorwärts, Leer-/Fremdanker, percent-encoded Fragment; ohne JavaScript Inhalt erreichbar. Admin und normale Rolle; keine Rechteausweitung. Arrayparameter verursachen keinen Fatal. Fehler/Sessionablauf erhalten sinnvolle Navigation. Bestehende Settings-Tabs mitprüfen, da derselbe JS-Owner betroffen ist.

### E7: Informationsdichte und große Bestände verbessern

**Befunde:** SS-07/24; baut auf E2 bis E6 auf.

**Was:** Unkonfiguriertes MECM als kompakte Einrichtungskarte; Quellenliste und leere Detailfelder aufklappbar. Echte Fehler/erforderliche Arbeit standardmäßig sichtbar. Überblick um Deploy-Service und sichtbares AD ergänzen, keine versteckten Ziele anbieten. Technischen Prozessvertrag hinter Details verschieben, sofern keine aktuelle Abweichung seine Erklärung benötigt. Abweichungen nach Mission/Vorlage/VM/Art filtern und paginieren; vollständige Zahl und Kürzung erkennbar. Stabile Sortierung bis zu exakten Bytes und IDs, URL-Zustand erhält Filter und Inventarauswahl. Neue Tabellen nutzen `portal_sort.php`.

**Wieso:** Weniger Platzhalter und konkrete Handlungslisten verkürzen den Weg zur Ursache. Bloßes Verkleinern der Schrift oder zusätzliche globale Ampeln würde die Semantik nicht verbessern.

**Abnahme:** Keine Einrichtung, Teilintegration, gesunder Betrieb und viele Fehler jeweils verständlich. Schmale Ansicht, Wrapgrenze und Desktop ohne horizontalen Seitenüberlauf; lange Unicode-/Hostnamen, große Zähler, beide Sprachen/Themes, Tastatur. Query-/Renderkosten bei vergrößertem synthetischem Bestand messen und ein aus der Messung begründetes Budget festlegen. Keine N+1-Queries je gerenderter Zeile; wiederholtes Refresh darf keine Aufträge erzeugen. Screenshotabnahme ausschließlich über den bestehenden QA-Visualvertrag, keine automatischen Baselineupdates.

### E8: Hilfe, Betriebsdokumentation und dauerhafte Abschlussprüfung

**Was:** Alle geänderten Bedienwege und Statusaussagen in DE/EN angleichen. Gemeinsame Legenden behalten, aber gegen echte Zustandseingaben prüfen. Hilfetexte zu Retry-Streak/Auth-Pause, autoritativ leerem Inventar, Clientphasen ohne Abschlussmeldung, Testevidenz, AD-Zulassung und offenen Klärungen korrigieren. Systemstatus-Reiter mit kurzen Sprungzielen gliedern; ausführliche MECM-Objektstruktur und Logaufbewahrung als vertiefende Themen darstellen. Keine zweite Beschreibung der Recovery-Policy pflegen.

**Wieso:** Sprachparität und die bisherigen Doc-Gates finden einen auf beiden Sprachen und beiden Seiten identisch falschen Satz nicht. Entscheidend ist der Nachweis Aussage -> Owner -> Zustandstest.

**Dokumente:** `lang/{de,en}/system_status.php`, `help_system_status.php`, betroffene `help_deploy.php`/`help_credentials.php`, `lib/help/*`, `docs/operations/esxi-inventory.md`, `docs/operations/mecm-integration.md`, `docs/operations/deploy-chain.md`, relevante ADR-Ergänzungen, QA-/Testplan und Changelog nur für tatsächlich geändertes Verhalten. Veraltete Kommentare wie die angeblich rein manuelle Frischebewertung in `repo/ansible_preflight.php` korrigieren.

**Abnahme:** Jede neue Warnung erklärt Bedeutung, Datenalter und nächsten Schritt; jeder Portalverweis hat einen erreichbaren berechtigten Link. Zahlen/Fristen kommen aus Konstanten. Alte Messwerte und externe Abnahmen werden nicht umetikettiert. Offene H-Fälle bleiben mit Ergebnis dokumentiert, nicht pauschal abgehakt.

## 5. Optionale spätere Etappe: Anzeigeaktualisierung

Nach E1 bis E8 kann eine opt-in Live-Anzeige folgen. Sie ist kein Bestandteil der notwendigen Fehlerkorrekturen und wird nicht vorab als neuer Monitoringdienst gebaut.

Nur read-only Daten aktualisieren, keine Inventar-/Recovery-Aktion starten. Sessionlock vor DB-Poll freigeben, Hintergrundtabs drosseln, bei Netzfehlern Backoff, nach Abmeldung/403 stoppen. Letzten erfolgreichen Aktualisierungszeitpunkt zeigen; alte Daten bei Fehler erhalten, aber als alt kennzeichnen. Bearbeitete Formulare, Fokus, Scrollposition und offene Details erhalten. Keine ganze Statusseite als Live-Region vorlesen. Ein getrennt beschrifteter manueller Reload bleibt verfügbar. Falls diese Bedingungen nur durch eine große neue Infrastruktur erfüllt würden, bleibt die bewusste Momentaufnahme die bessere erste Lösung.

## 6. Prüfstrategie und Etappenabschluss

Die gesamte Zustandsmenge wird nicht als blindes kartesisches Produkt getestet. Jede fachliche Grenze erhält einen benannten Positiv-/Negativfall, Wechselwirkungen bekommen gezielte Integrationstests. Die wichtigsten Testfamilien:

| Familie | Vorhandene Basis | Notwendige Ergänzung |
|---|---|---|
| Servicepolicy/Fakten | DeployServiceHealthTest, RemoteRecoveryFoundationTest, HealthEndpointStatusTest | Reale Reader -> Policy -> Renderer, Recovery ohne Lock, Mehrfachhandles/Attempts, Pause-Audit-Race. |
| Integrationszustände | IntegrationHealthGroupTest, SystemStatusPanelBranchTest | Sitealterung aus Rohdaten statt fertig übergebenem state; unknown mit vorhandener Meldung; altes Ergebnis/neuer Start. |
| Inventar | EsxiInventoryAmpelTest, EsxiInventoryDeviationScopeTest, EsxiInventoryCacheTest | Auswertbarkeit je Art, bestätigte Leermenge, unqualifizierter Cache, identische/ähnliche Rohwerte, Queue-/Claim-Pause. |
| Reparatur | system-status-actions.spec.js, Netzwerkvertragstests | Exakte Scopebindung, Vorschau-Race, fehlerhafte IDs ohne Bulkfallback, leerer Katalog, MAC-Erhalt. |
| Ansible/AD | bestehende Preflight- und DirectoryHealthSnapshotTests | Konfigurationsänderung während Test, Testgeneration; Zulassung vs. Beobachtung. |
| Navigation | HelpAnchorContractTest, SystemStatusDeepLinkContractTest, Browser-Specs | Same-document Hashwechsel, versteckte Panels, Fokus, bedingte Ziele/RBAC. |
| Umfang/Darstellung | system-status.spec.js, StatusSpacingContractTest, vorhandenes Visualprojekt | kompakte Leerzustände, viele Befunde, Wrapgrenze, DE/EN, beide Themes. |

Pro Etappe zuerst gezielte Regressionen, dann passende Gates über `scripts/check.ps1`: PHP-/JS-Syntax, PHPUnit, PHPStan, Sprachparität, Anker-/Form-/CSS-/Bestätigungsverträge, Require-Closure, Audit-/Bounds-/Enum-/Dateigrößen-/Dokumentations-/CSP-Gates soweit betroffen. Keine zweite Gate-Registry oder separaten öffentlichen Runner einführen. Neue canonical Multi-Unit-Pfade erfordern ProgressReporting-Vertragstests. Lange Läufe immer mit pollbarem Log, echten `[n/total]`-Zeilen und mindestens minütlicher Beobachtung.

Nach fachlich vollständigen Etappen zusammenhängende eigene Commits; keine pauschale Freigabe zum Stagen fremder Dateien. E1 und E5 können wegen unabhängiger Identitätsrisiken vor Komfortänderungen abgeschlossen werden; E2/E3/E4 bilden die Grundlage für E7. Vor produktiven API-/Deploy-/Migrationsänderungen die vertragliche Reviewrolle nach geltenden Repositoryvorgaben einplanen. Ein abschließender gemeinsamer Fast-/Integration-/Release-Nachweis erfolgt erst auf einem stabilen, eindeutig zugeordneten Stand mit verfügbarem QA-Stack. Eine wegen Infrastruktur fehlende Prüfung ist kein Pass.

## 7. Definition of Done

1. Jeder bestätigte Befund SS-01 bis SS-25 besitzt Fixnachweis oder eine explizit begründete, überprüfte Entscheidung; H-Befunde werden durch Reproduktion bestätigt oder mit Evidenz widerlegt.
2. Kein Schreibpfad verändert den Scope durch Normalisierung, malformed Input oder zwischenzeitliche Änderungen. Transaktion, Rechte, CSRF, MAC-Erhalt und Audit sind zusammen geprüft.
3. Nicht vorhanden, nicht auswertbar, historisch, veraltet, laufend, gestört und aktuell bestätigt sind auf der Oberfläche unterscheidbar. Unbekannt/Fehler darf nicht in eine grüne Null fallen.
4. Zähler, angezeigte Fälle und Gesamtbadge basieren auf derselben fachlichen Ergebnismenge; Kürzung und verbleibende Treffer sind sichtbar.
5. Alle Hilfe-/Status-/Job-/Missionslinks funktionieren im realen sichtbaren DOM für die jeweilige Rolle, einschließlich interner Tabwechsel.
6. Tests decken Reader und Renderer gemeinsam ab, nicht nur bereits passend vorbereitete Policy-Inputs. Alte Gegenbeispiele werden dauerhaft rot, wenn der Fix entfernt wird.
7. Dokumentation beschreibt das tatsächlich implementierte Verhalten, keine erhoffte Wirkung eines Buttons. Maschinenverträge, Sicherheitsgrenzen und anonyme Health-Daten bleiben erhalten.
8. Abschluss enthält pro Etappe Dateien/Owner, Ursache, Tests, Gateergebnisse, Commitstand und verbleibende Grenzen. Kein Zielsystemnachweis aus lokalen Fixtures ableiten.

Externe Abnahmen für echtes MECM, Ansible/Standalone-ESXi, Ziel-AD, Windows-SYSTEM, Supervisor/8R-S und menschlichen Screenreader bleiben separat. Für die Planung und die genannten lokalen Korrekturen sind derzeit keine weiteren Produktfragen an den Betreiber nötig. Die erste praktische Voraussetzung für neue Laufzeitnachweise ist ein wieder verfügbarer Docker-/QA-Kontext.

## 8. Prüfung dieses Planartefakts

Am 08.09.2026 wurden die ausgewählten kanonischen Gates `doc-hygiene` und `doc-semantics` nach Erstellung des Plans erfolgreich ausgeführt: 2 pass, keine Fehler oder übersprungenen Gates. Nachweis: `qa-artifacts/system-status-plan-docs-retry.json` und das zugehörige Fortschrittslog. Der erste sandboxierte Versuch scheiterte vor der eigentlichen Prüfung am Start von `sh.exe`; der Runner klassifizierte diesen Lauf als 2 fail. Er bleibt unter `qa-artifacts/system-status-plan-docs.json` erhalten. Der erneute Lauf außerhalb der Sandbox bestand. Diese Gates prüfen die aktive Repository-Dokumentation; datierte Auditpläne sind von Teilen des Semantikscans ausgenommen. Der Plan wurde zusätzlich auf Befund-/Etappenvollständigkeit, UTF-8-Ersatzzeichen und Whitespacefehler geprüft. Daraus folgt keine nachträgliche Laufzeitbestätigung der neuen C/H-Befunde.
