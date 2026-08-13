# UX-Umsetzungsplan v4.1: verständliches Portal für wechselndes Adminpersonal

Stand: 2026-08-11, kritisch gegen Repository, bestehende ADRs und offizielle Webstandards geprüft. Diese Fassung ist in den Deploy-/Betriebs-Masterplan integriert; bei abweichender Nummerierung und Reihenfolge ist der Masterplan ausführende SSoT. Die dort etappengenau ergänzten ADR-0006-Refactorings für Runner, Deploy/Settings, Layout/Status/Credentials, VM-Code und CSS sind verbindlicher Bestandteil der UX-Etappen und werden hier nicht als zweite, driftfähige Liste dupliziert.

## 1. Ziel und Scope

Ziel ist ein Portal, das auch wechselndes Adminpersonal ohne tiefes Vorwissen sicher bedienen und diagnostizieren kann. Technische Zustände sollen verständlich, Voraussetzungen handlungsorientiert und Formulare barrierearm dargestellt werden.

Nicht verändert werden:

- Machine-API-Wire-Verträge und die fünf Legacy-Statusstrings.
- Technische Statuswerte in Datenbank, Worker, persistenten Joblogs und JSON-Wire-Feldern.
- RBAC-, CSRF- und Sicherheitsgrenzen.
- MECM-/Ansible-Fachbegriffe, wenn sie tatsächlich technische Schnittstellenwerte bezeichnen.
- Runtime-Air-Gap und CSP-Verträge.

Die Etappen 1–10 des zusammengeführten Deploy-/Betriebs-Masterplans werden zuerst vollständig abgeschlossen. Die dortige Fehlerklassifikation und ihre Wire-/Logverträge werden durch diesen UX-Teil nur angezeigt, nicht neu definiert.

## 2. Arbeits- und QA-Vertrag

`scripts/check.ps1` bleibt die ausführbare QA-SSoT.

Für jede Etappe gilt:

1. Gezielte Unit-, Static- oder E2E-Tests während der Entwicklung.
2. `check.ps1 -Lane Fast -Json qa-artifacts/<etappe>-fast.json`.
3. Integration-Lane nach Etappen 2, 3 und 4.
4. Release-Lane nach Etappe 4c.
5. Neue Runner halten den Progress-Reporting-Vertrag ein.
6. Neue Guards erhalten stabile Diagnose-IDs sowie positive, negative und Zero-Match-Nachweise.
7. Commits erfolgen nur auf ausdrücklichen Auftrag.
8. Nach der Etappe wird jede Anforderung erneut gegen Diff, Aufrufer, SSoT-Verbraucher und Negativabgrenzungen geprüft. Eine Lücke öffnet dieselbe Etappe erneut; sie wird nicht als Restarbeit verschoben.
9. Hilfe, DE/EN-Texte, `docs/`, ADR/Runbook, QA-Anleitung, Changelog, dauerhafte Agentregeln sowie Audit-, Job-, Container- und Fehlerlogs werden **in derselben Etappe** geprüft und nötigenfalls geändert. `nicht betroffen` ist mit Begründung im Abnahmeprotokoll festzuhalten.
10. Das Etappenprotokoll nennt Soll/Ist, gezielte Tests, Help/Doku und Logs/Protokolle. Erst ein vollständiger grüner Eintrag gibt die nächste Etappe frei.

## Etappe 0 – Kollisionsschutz, aktuelle Basis und Visual-Harness

### 0.1 Vorgelagerte Masterplan-Arbeit und Kollisionsschutz

Vor Beginn müssen die Masterplan-Etappen 1–10 grün protokolliert sein. Parallel geänderte Dateien werden nicht pauschal eingefroren. Stattdessen wird vor jeder UX-Etappe der aktuelle Diff der konkreten Zieldateien gelesen und unmittelbar vor jedem Schreibvorgang ihr Hash mit dem Etappenmanifest verglichen. Bei einer Kollision wird nur die betroffene Datei neu eingeordnet; fremde Änderungen werden nicht zurückgesetzt.

Danach wird unter `qa-artifacts/` ein Manifest erzeugt:

- HEAD und Branch
- `git status --short`, einschließlich untracked Dateien
- `git diff --stat`
- `git diff --check` für getrackte Änderungen sowie ein eigener Whitespace-/Strukturnachweis für untracked Zieldateien
- SHA-256 der vollständigen Inhalte aller dirty oder untracked Plan-Zieldateien; ein Patchhash allein erfasst untracked Inhalte nicht
- Liste aller Plan-Zieldateien mit Dirty-/Clean-Status

Ändert sich HEAD oder der Inhalt einer Zieldatei außerhalb des aktuellen Hunks, wird das Manifest erneuert und der Diff erneut gelesen. Das Manifest ist QA-Artefakt, kein Ersatz für Git und keine globale Schreibsperre.

### 0.2 Aktuelle QA-Basis

Die historischen Infrastrukturfehler gelten nicht mehr als offener Befund. Trotzdem muss der dann aktuelle Arbeitsbaum neu geprüft werden:

```powershell
powershell -NoProfile -File scripts\check.ps1 -Lane Fast -Json qa-artifacts/ux-v4-baseline-fast.json
```

Der hart codierte, revisions- und benutzergebundene Chromium-Fallback in `check.ps1` wird entfernt. `check.ps1` und Playwright verwenden einen gemeinsamen Browser-Resolver oder einen von beiden konsumierten, getesteten Auflösungsvertrag; es bleiben nicht zwei Implementierungen mit unterschiedlicher Revisionsauswahl.

### 0.3 Visual-Harness

Der Visual-Harness wird an die bestehende Playwright-Konfiguration angebunden. Gemeinsame Einstellungen für Base-URL, Auth, Browserauflösung und Reporter werden nicht dupliziert.

Eigenschaften:

- eigener Visual-Projektname und eigenes Spec-Verzeichnis
- ausschließlich eigener synthetischer Wegwerf-QA-Stack und idempotenter Seed
- keine realen Benutzer-, Missions- oder Hostnamen
- Worker nur in diesem Wegwerf-Stack pausieren; vorher aktive Jobs ausschließen und den ursprünglichen Zustand in `finally` wiederherstellen
- feste Locale, Portal-Zeitzone, Viewports und `deviceScaleFactor`
- feste Hell-/Dunkel-Theme-Zustände
- feste Uhr, Zufallsquelle, `prefers-reduced-motion`, Screenshotoptionen `animations: disabled` und `caret: hide`
- genau ein deklarierter Chromium-Visual-Runner mit festem OS-/Browser-/Playwright-/Fontvertrag; Firefox/WebKit/Edge bleiben funktionale Releaseprojekte, aber erzeugen keine gemeinsamen Pixelbaselines
- Baseline-Metadaten enthalten OS, Browserrevision, Playwright-Version, Fontliste/-hash, Locale, Zeitzone und Scale; Abweichung ist `infrastructure_error`, keine stille Neubaseline
- deterministische Werte bevorzugen; nur unvermeidbare Zeit-, Session- und Zufallsbereiche eng maskieren und jede Maske reviewen
- Statusbadges stabil seed-en, nicht maskieren
- Pixelvergleich mit eng definierter Toleranz statt PNG-Bytevergleich
- keine Runtime-Downloads; Browser und Fonts werden über den vorhandenen offline-fähigen QA-/Dev-Pfad bereitgestellt

Der Visual-Diff wird ein Gate der bestehenden Release-Lane, kein neuer Lane-Typ.

### Abnahme Etappe 0

- Kollisionsmanifest vorhanden.
- Aktuelle Fast-Lane ohne Fehler oder Infrastrukturfehler.
- Chromium-Erkennung besitzt keine revisionsgebundene Benutzerpfad-Fallbackregel.
- Zwei Visual-Läufe desselben Stands bleiben innerhalb der festgelegten Pixeltoleranz.
- Vorher-Baselines für beide Themes sind reviewbar gespeichert.
- `docs/QA.md` und ADR-0028 beschreiben Resolver, Visual-Runner, Metadaten, Seed, Baseline-Update und Restore-Verhalten; Portalhilfe, Audit-, Job-, Container- und Wire-Verträge sind geprüft und mit Begründung als nicht betroffen protokolliert.

---

## Etappe 1 – kleine, hochwirksame UX-Korrekturen

### 1.1 Passwortfelder und Mindestlänge gemeinsam korrigieren

In `users.php` und `account.php`:

- `autocomplete="new-password"` für neue Passwörter
- `minlength` aus `password_policy_min_length()`
- sichtbarer Hinweis „Mindestens :min Zeichen.“
- DE/EN-Parität

Das Passwortfeld der Benutzerneuanlage behält sein sichtbares Label. Das zeilenweise Reset-Feld erhält pro Benutzer eine eindeutige ID und ein zugeordnetes `sr-only`-Label „Neues Passwort für :name“. Ein Placeholder bleibt höchstens Ergänzung, nie zugängliche Bezeichnung.

Backendvalidierung bleibt maßgeblich.

### 1.2 Deploy-Hinweise verständlicher unterscheiden

- Basisvoraussetzungen erhalten eine klare textliche Kennzeichnung wie „Voraussetzung fehlt“.
- Blockierende Hinweise dürfen `alert-warning` verwenden.
- Nicht blockierende Host-, Capability- und Terminwarnungen bleiben ebenfalls Warnungen, benennen aber ausdrücklich, dass der Auftrag trotzdem gestartet werden kann.
- `.alert-info` erhält einen infofarbenen Rahmen; der bereits vorhandene Grundrahmen wird nicht dupliziert.
- Farbe ist nie die einzige Unterscheidung.

### 1.3 Vollständiges Deploy-Blockermodell

Es entsteht ein Aggregator `deploy_queue_blockers()`. Dieser ersetzt keine vorhandene Fachprüfung, sondern vereinigt für **denselben normalisierten Formularzustand**:

- `deploy_prerequisite_notices()`
- ausgewählte Mission ohne VMs
- VM-Identitätskonflikte
- später hinzukommende echte Queue-Blocker

Das Modell ist eine diskriminierte Union. Gemeinsame Pflichtfelder sind:

- `kind`
- `code`
- `message`
- optional eine strukturierte `action` mit URL, Label und Zielberechtigung

Nur der jeweilige `kind` verlangt seine Zusatzfelder: beispielsweise `target_id` für ein Sprungziel oder die vorhandenen Konfliktdaten für Refresh-/Adoption-Aktionen. Der Renderer ist für alle bekannten Varianten exhaustiv; ein unbekannter `kind` scheitert im Test statt still zu verschwinden.

`$canQueue`, Blockeranzahl, Singular-/Pluraltext und Sprungziel werden ausschließlich aus dieser Aggregatliste abgeleitet.

Die Renderer unterscheiden per `kind`:

- normale Voraussetzung mit optionalem Link
- leere Mission mit VM-Link
- Identitätskonflikt mit vorhandenen, bestätigten Formularaktionen

Das Repo-Gate bleibt die verbindliche serverseitige Sicherung.

Da Credential-, ESXi- und VM-Auswahl ohne vollständigen Reload geändert werden, darf die Anzeige nicht auf einem beim Seitenaufbau eingefrorenen Aggregat beruhen. Ein read-only, session-/RBAC-geschützter JSON-Endpunkt berechnet dieselbe Funktion aus dem aktuellen, vollständig übertragenen und serverseitig normalisierten Formularzustand. Der Client:

- sendet alle aktuellen Controls über denselben Form-State-Vertrag wie die Queue, einschließlich disabled-but-filled Feldern;
- arbeitet debounced und Single-Flight, verwirft veraltete Antworten und baut Text/Links über DOM-APIs statt HTML-Injektion;
- leitet Buttonzustand, Anzahl, Singular/Plural und Sprungziel ausschließlich aus der jüngsten gültigen Antwort ab;
- beendet bei `401`/`403`, prüft `Content-Type` und zeigt eine lokalisierte Wiederanmelde-/Berechtigungsmeldung;
- erzeugt keinen Audit- oder Error-Logeintrag pro erfolgreicher Abfrage.

Das eigentliche Queue-POST normalisiert erneut und prüft `deploy_queue_blockers()` unmittelbar vor der Repository-Operation; der JSON-Endpunkt ist Komfort, nie Sicherheitsgrenze.

`AGENTS.md` und `.claude/rules/portal.md` werden im selben Patch auf den Aggregator umgestellt.

### 1.4 Mission und Bereitstellung verbinden

- „Bereitstellen“-Button auf Missionsdetails und VM-Liste
- nur für echte Missionen
- nur bei `can('deploy.run')`
- Ziel `deploy.php?mission_id=<id>`
- Missionsname in der Auftragstabelle verlinkt auf Missionsdetails
- System-/Inventarjobs bleiben ohne erfundene Mission

Für den Deploy-Missionslink wird ein kleiner URL-Helper verwendet, damit die Route nicht an mehreren Stellen von Hand entsteht.

### 1.5 Hilfe-URL als SSoT

Neues fokussiertes Modul, beispielsweise `lib/help_page.php`:

- zentrale Konstanten für alle gerenderten Help-Panel- und Abschnitts-IDs, einschließlich `help-backup`
- `help_url($panel, $section = null)`
- Validierung gegen die tatsächlich gerenderten IDs der Help-Partials
- keine URL als vermeintlicher Ankerparameter
- bedingte Panels bleiben Teil des validierten Vokabulars; die Zielberechtigung entscheidet erst beim Rendern, ob der Link erscheint

Hilfe-Abzeichen erhalten:

- Dashboard → Übersicht
- Betriebssysteme → Pakete/Kataloge
- VM-Liste → Missionen
- Audit-Logs → Systemstatus/Logs
- Deploy-Log → Bereitstellung
- Konto → Benutzer/Konto

`HelpAnchorContractTest` prüft:

- jede relevante `layout_header()`-Stelle
- begründete Ausnahmen wie die Hilfeseite selbst
- Konstanten ↔ gerenderte Panel- und Abschnitts-IDs in beide Richtungen
- keine handgeschriebenen `help.php#...`-Links außerhalb des Builders
- Partials werden per Glob erfasst
- Panel- und Abschnittsziel werden auch dann korrekt geöffnet und fokussiert, wenn der Abschnitt innerhalb eines zunächst geschlossenen Panels liegt

### 1.6 Dauerpluralisierung

`portal_format_duration()` erhält Singularformen für Sekunde, Minute und Stunde.

Tests decken mindestens ab:

- 0 und 1 Sekunde
- 59 und 60 Sekunden
- 1 und 2 Minuten
- 3599 und 3600 Sekunden
- 1 und 2 Stunden
- Millisekundenpfad über `portal_format_duration_ms()`

### 1.7 `updated` verständlich anzeigen

- nackte `0`/`1` hinter MECM-Badges entfernen
- bei `updated=1`: „Für MECM vorgemerkt“
- sonst „—“
- dieselbe fachliche Darstellung in VM-Liste und Editor-Diagnose
- kein „Ja/Nein“ ohne benannten Kontext
- keinerlei Änderung am Flag oder Machine-API-Verhalten

### Abnahme Etappe 1

- gezielte Unit-/Static-/E2E-Tests
- DE/EN-Audit
- Screenshots der betroffenen Seiten
- Fast-Lane grün
- `AGENTS.md`, `.claude/rules/portal.md`, `GROK.md`, Portalhilfe, `docs/QA.md` und Changelog sind im selben Patch aktualisiert; Backend-Gate, Audit-/Joblogs und Machine-Wire-Verträge sind auf Unverändertheit geprüft und protokolliert.

---

## Etappe 2 – verständlicher Zustandswortschatz

### 2.1 Getrennte Anzeigeebenen

Technische Werte bleiben roh in:

- Konstanten und DB
- Workerlogik
- persistenten Joblogs
- Machine-API-Feldern
- bestehenden JSON-Feldern wie `status`

Lokalisierung erfolgt ausschließlich auf der Portal-Anzeigeebene.

Neue Aufteilung:

- `lib/portal_status_display.php` für Lifecycle- und MECM-Anzeigen
- `lib/deploy_display.php` für Jobstatus, Deploy-Modi und Payload-Anzeigen
- gemeinsamer DE/EN-Statussprachkatalog statt Ablage ausschließlich im VM-Editor-Modul

### 2.2 Lifecycle- und MECM-Badges

`lifecycle_badge()` und `mecm_sync_badge()` verwenden:

- bestehende technische Meta-Funktionen für die Badge-Variante
- neue lokalisierte Label-Helper für den sichtbaren Text
- neutralen lokalisierten Unknown-Fallback
- niemals den unbekannten Rohwert im HTML

Es wird nicht bei jedem Rendern in `error_log` geschrieben. Drift wird über Konstanten-Walk-Tests und vorhandene ENUM-/Schema-Gates erkannt.

Tests laufen über:

- `VIRTUSPHERE_LIFECYCLE_STATES`
- `VIRTUSPHERE_MECM_SYNC_STATES`
- DE/EN-Schlüssel
- Unknown-Fallback

### 2.3 Alle sieben Jobzustände

Lokalisierte Darstellung für:

- `queued`
- `running`
- `cancelling`
- `succeeded`
- `failed`
- `cancelled`
- `partial`

Die vollständige Testmenge wird aus aktiven und terminalen SSoT-Statusmengen abgeleitet, nicht erneut ausgeschrieben.

Verwendung:

- Auftragstabelle
- initiales Joblog-Badge
- Systemstatus-Ansible-Aktivität, soweit betroffen
- Polling-JSON als additives Feld `label`

`deploy.js` rendert ausschließlich `label`. `status` und `badge` bleiben unverändert erhalten.

Der JSON-Pfad erhält zusätzlich:

- einen expliziten JSON-Vertrag: `application/json`, `401` bei abgelaufener/fehlender Session, `403` bei fehlender Berechtigung und lokalisierte `404` bei unbekanntem Job; kein Login-HTML und kein Redirect im Fetch-Pfad
- `session_write_close()` erst nachdem Auth, RBAC und die benötigte Locale aus der Session gelesen wurden, aber vor der Polling-DB-Abfrage
- Content-Type-Prüfung im Client
- Behandlung von `401`/`403` mit Poll-Stopp und lokalisierter, handlungsfähiger Meldung statt Endlos-Retry
- keine parallelen Poll-Requests
- kein Audit- oder Error-Log pro erfolgreichem Poll

### 2.4 Deploy-Modi

`virtusphere_deploy_mode_labels()` bleibt technische Validierungs-SSoT.

Portal-only:

- `deploy_mode_label()`
- `deploy_job_payload_display_summary()`

Anzeigenamen:

- Komplette Pipeline
- VMs anlegen
- Power-Cycle und MACs exportieren
- MACs exportieren
- VMs starten
- ESXi-Autostart-Richtlinie anwenden
- ESXi-Inventarabruf für den unpostbaren Systemmodus `inventory`

`deploy_job_payload_summary()` bleibt unverändert für persistente technische Logs.

Die tatsächlichen Hilfeschlüssel in `help_deploy.php` werden aktualisiert; keine feste Satzanzahl wird vorausgesetzt.

### 2.5 Dashboard-Missionsstatus

- `active` → lokalisiertes Erfolgsbadge „Aktiv“
- leer → „—“
- anderer freier Legacy-Wert → neutral mit unverändertem Text

Der freie VARCHAR-Status ist ausdrücklich keine geschlossene ENUM-Anzeige.

### Abnahme Etappe 2

- Konstanten-Walk-Tests
- JSON-Vertragstest für additives `label`
- Polling-Test für `cancelling`
- Unknown-Tests
- Rohwert-Assertions im Portal aktualisiert
- Fast- und Integration-Lane grün
- Visual-Screenshots beider Themes
- DE/EN-Hilfe, Statusglossar, `docs/QA.md`, Changelog und dauerhafte Portalregeln sind aktualisiert; persistente Joblogs, technische Statuswerte, bestehende JSON-Felder und Machine-Wire-Verträge sind unverändert und als solche abgenommen.

---

## Etappe 3 – Formulare erklären sich selbst

### 3.1 Aktuelles Hint-Inventar

Die Zahl der Hints wird aus dem zu Etappenbeginn protokollierten Arbeitsbaum neu ermittelt. Jede Stelle wird klassifiziert als:

- Feld-Hint
- Gruppen-Hint
- allgemeine Prosa
- dynamischer JS-Hint
- Validierungsfehler

Das Inventar nennt pro Control Seite, Feldname, ID/Label, vorhandene Hint-/Error-ID, Gruppenbezug, dynamische Erzeugung und geplante Migration. Allgemeine Prosa wird ausdrücklich ausgeschlossen. Die Liste ist Migrationsmatrix und Abnahmenachweis, keine bloße Anzahl.

### 3.2 Gemeinsame Formular-API

`lib/forms.php` erhält beziehungsweise vereinigt:

- `form_hint_id()`
- `form_error_id()`
- `form_control_attrs()`
- `form_error_html()`

Vertrag:

- `form_control_attrs()` integriert die heutige `form_input_class()`-Funktionalität.
- `aria-invalid` sitzt am Control.
- `aria-describedby` nennt Hint und Fehler gemeinsam.
- Fehlerausgabe erhält eine stabile ID.
- IDs werden aus Form, Feld und optionaler Zeilen-/Gruppenkennung sicher normalisiert.
- keine konkurrierende zweite Attribute-API

Der VM-Editor-Fehlerpfad `vm_field_error()` wird als Wrapper auf dieselbe Kernlogik gehoben.

### 3.3 Gruppen und dynamische Controls

- Feld-Hints stehen als Geschwister beim Control.
- Gruppen-Hints referenzieren `fieldset` oder `role="group"`.
- wiederholte Zeilen erhalten eindeutige IDs
- JS-erzeugte Zeilen ersetzen Template-Indizes vollständig
- dynamisch wechselnde Deploy-Hints aktualisieren `aria-describedby`
- keine toten Referenzen oder doppelten IDs
- keine `aria-describedby`-Referenz auf ein fehlendes Element und keine Hint-/Fehler-ID ohne eindeutig zugeordnetes Control beziehungsweise eine begründete Gruppe

### Abnahme Etappe 3

- DOM-Tests für Fehler- und Erfolgszustände
- axe
- Tastatur- und Screenreader-Stichprobe
- Tests für dynamisch erzeugte Zeilen
- Fast- und Integration-Lane grün
- Visual-Screenshots
- `docs/QA.md`, Formular-/Portalhilfe, DE/EN-Texte, Changelog und dauerhafte Formularregeln sind innerhalb der Etappe synchronisiert; Audit-, Job-, Containerlogs und Wire-Verträge sind geprüft und begründet nicht betroffen, sofern die Inventur keinen tatsächlich sichtbaren Fehlertext darin findet.

---

## Etappe 4 – Navigation, Tabellen und Operatorfilter

### 4.1 Sticky Aktionsspalte

Opt-in ausschließlich auf der VM-Liste:

- `.table-sticky-actions`
- `.table-action-cell` auf `th` und `td`
- deckende Hintergründe
- definierter Z-Index mit Sticky-Header
- Hover-Hintergrund
- Deaktivierung unter festgelegtem Breakpoint
- kein Einsatz auf `users.php`

Tests:

- CSS-Klassenvertrag
- Desktop-Geometrie
- erzwungener Wrap-Zustand
- horizontaler Tabellenscroll
- mobile Deaktivierung

### 4.2 Reiter statt Aktionsbuttons

Bestehendes Tab-Muster aus `logs.php` verwenden:

- Missionen / Vorlagen auf `missions.php`
- Missionsdetails / VMs auf der Detailstufe
- übersetztes `aria-label`
- `aria-current="page"`
- Mission-ID und Templatekontext bleiben erhalten
- VM-Editor bleibt Unterseite mit Rücklink/Breadcrumb, kein falscher Peer-Tab

Es handelt sich um Seitennavigation mit normalen Links und genau einem `aria-current="page"`, nicht um ein ARIA-`tablist`-Widget. Daher werden weder `role="tab"` noch Pfeiltastenlogik oder `aria-selected` ergänzt.

### 4.3 Abweichungen in die Statusübersicht

Der Detailrenderer besitzt bereits die Dreiteilung. Ergänzt wird nur:

- `system_status_render_overview(..., ?int $deviationCount)`
- `null` → neutral „Nicht geprüft“
- `0` → success „Keine Abweichungen“
- `1` → warning „1 Abweichung“
- größer → warning mit Anzahl
- Link über `system_status_url()` auf den vorhandenen Abweichungsabschnitt

Die Anzahl wird einmal über einen gemeinsamen Helper berechnet und an Übersicht und Detailpanel übergeben.

Das vorhandene 4→2→1-Raster bleibt erhalten; die breite Darstellung wird für fünf Karten angepasst, beispielsweise mit `auto-fit`.

### 4.4 Katalog-Leerzustände

Für OS, Pakete und VLANs:

- ungefilterte Gesamtzahl getrennt von gefilterten Zeilen bestimmen
- Datenbank wirklich leer → bisheriger Leertext
- Gesamtmenge vorhanden, Filterergebnis leer → „Kein Eintrag mit diesem Status“
- Link „Alle Einträge anzeigen“
- Ziel `status=all`
- bestehende Sortierung und Richtung erhalten

### 4.5 Objektname im Titel

- Missionsdetails: Typ und Missions-/Vorlagenname
- VM-Editor: VM-Name bei Bearbeitung, verständlicher Neuanlagetitel sonst
- Titel und Browser-Titel verwenden dieselbe SSoT
- Namen werden weiterhin escaped

### 4.6 Gemeinsamer Katalogfilter

`vlans.php` verwendet `portal_catalog_status_filter()`.

`CatalogFilterContractTest` verlangt anschließend auf allen drei Seiten:

- gemeinsame Tokenkonstante
- gemeinsamen Renderer
- keine eigene Reimplementierung

### 4.7 Begriffe und Zeitzonenhinweise

- „Deploy-Einstellungen“ → „Bereitstellung“, sofern das Ziel die Portal-Bereitstellungseinstellungen sind
- „Passwortwechsel“ → „Passwortwechsel ausstehend“
- konsistente Rücksprunglabels
- Logs und Systemstatus nennen die aktuelle Portal-Zeitzone
- Standort-/Abweichungsspalten bleiben kompakt
- CSV darf detaillierter bleiben

### 4.8 Gemeinsames Logfilter-Modell

Neues `lib/log_filter.php` erzeugt einen normalisierten Filterzustand:

- Freitext
- IP
- Tab und Kategorie
- lokales Von-Datum
- lokales Bis-Datum
- UTC-Untergrenze
- exklusive UTC-Obergrenze
- Korrelations-ID

Datumsvertrag:

- Portal-Zeitzone → UTC
- `[von 00:00, Tag nach bis 00:00)`
- lokaler Kalendertag, nie `+86400`
- strikte Formatprüfung
- DST- und ungültige Datumsfälle
- `von > bis` als Feldfehler
- leere Grenzen erlaubt

Das Repo erhält nur normalisierte UTC-Grenzen und eine validierte Korrelations-ID. Die bisherigen langen Positionsparameter werden durch einen dokumentierten Filter-Struct ersetzt.

Ein gemeinsamer Query-Builder erhält Filter über:

- Pagination
- Tabwechsel
- Kategorie
- Reset
- CSV-Export
- Korrelationslinks

Ungültige Datums- oder Korrelationswerte bleiben sichtbar, erzeugen einen lokalisierten Feldfehler und dürfen keine Repository-Abfrage auslösen. Der URL-Builder validiert Tab und Kategorie über die bestehende Logtaxonomie; ein ungültiger Tab-/Kategorie-Zustand wird nicht in Pagination, CSV oder Deep-Links weitergetragen.

### 4.9 Nutzbare Korrelationsspur

Die Korrelations-ID wird angezeigt in:

- Audit-Tabelle
- Audit-CSV
- Deploy-Log-Kopf
- optional kompakt mit vollständigem `title`/`code`

Exaktfilter verwendet `virtusphere_correlation_id_is_valid()`.

Bei gesetzter Korrelations-ID zeigt `logs.php` zusätzlich passende `deploy_jobs`:

- Job-ID
- Mission beziehungsweise Systemjob
- Status
- Joblog-Link über `deploy_job_log_url()`
- Link nur mit Zielberechtigung

Die Ergebnisliste ist deterministisch sortiert und hart begrenzt oder paginiert. Bei Kappung nennt die Oberfläche die Begrenzung und bietet den nächsten Schritt; eine Korrelations-ID kann mehrere Gruppen-/Teiljobs besitzen und darf keine unbeschränkte Zusatzabfrage oder riesige Seite erzeugen. Die Auditseite bleibt durch `users.manage` geschützt, der Joblog-Link zusätzlich durch die Zielberechtigung `deploy.run`.

Die Hilfe erklärt die Retention-Asymmetrie: Eine Auditzeile kann bereits gelöscht sein, während ein Missionsjob noch existiert; missionslose Systemjobs können ihrerseits früher aus der Jobhistorie verschwinden. Korrelation ist Diagnosehilfe nach ADR-0032, keine Autorisierungsgrenze.

Damit kann der Operator von der Fehlerreferenz zu Auditzeilen und passenden Joblogs navigieren, ohne Datenbankzugriff.

Datenbank:

- `EXPLAIN` mit repräsentativen Daten
- Index auf `deploy_logs.correlation_id` vorsehen
- Index auf `deploy_jobs.correlation_id` vorsehen
- Datums-/Kategorieindex nur ergänzen, wenn `EXPLAIN` ihn begründet
- additive Migration und Fresh-Schema-Spiegel
- `migrate --check` und Schema-Konvergenz
- ADR-0032 und `docs/QA.md` aktualisieren

### Abnahme Etappe 4

- Filter-Unit-Tests einschließlich DST
- Repo-Integrationstests für kombinierte Filter
- Korrelation Audit → Job → Joblog
- CSV trägt alle aktiven Filter und die Korrelations-ID
- Katalogleerzustände
- Tab-/Geometrie-/Sticky-Tests
- Fast- und Integration-Lane grün
- Visual-Screenshots
- Portalhilfe, ADR-0032, `docs/QA.md`, betroffene Runbooks, Changelog und Agentregeln sind im selben Etappenpatch aktualisiert; Audit-CSV/-Tabelle, Joblog-Deep-Link, Retention, RBAC und unveränderte Machine-Wire-Verträge sind ausdrücklich gegengeprüft.

---

## Etappe 4b – Design-Refresh „Slate + Indigo“

### 4b.1 Tokenumbau

Beide Themes erhalten eine abgestimmte Slate-/Indigo-Identität.

- Info-Blau bleibt unterscheidbar vom Indigo-Akzent.
- Sidebar erhält eigene `--sidebar-*`-Tokens.
- Sidebar ist je Theme klar von der Inhaltsfläche unterscheidbar.
- Datenflächen bleiben opak.
- `--btn-bg`/`--btn-fg` bleiben die SSoT solider Buttons.
- Destruktive Aktionen bleiben semantisch `.button-danger` und vorerst solide; ihre bestehende Sicherheits- und Bestätigungssemantik wird nicht abgeschwächt.

### 4b.2 Dezenteres Glas

- Glows deutlich reduzieren
- `--glass-bg` deckender
- Blur auf etwa 8 px
- weichere Schatten
- maximal zwei gestapelte Blur-Ebenen
- den vorhandenen opaken `@supports not (backdrop-filter)`-Fallback anpassen und testen, keinen zweiten Fallbackpfad aufbauen
- keine externen Assets

### 4b.3 Farbtoken-Guard

Außerhalb `base.css` werden rohe Farben verboten:

- Hex
- RGB/RGBA
- HSL/HSLA
- LAB/LCH/OKLCH
- `color()`
- gewöhnliche benannte Farben außerhalb der Forced-Colors-Policy
- rohe Farben innerhalb von Gradients, Shadows und Data-URLs

Erlaubt:

- `var(...)`
- `transparent`
- `currentColor`
- CSS-weite Keywords wie `inherit`, `initial`, `unset` und `revert`
- `color-mix()`, wenn sämtliche Farbeingaben ausschließlich aus Tokens oder erlaubten Keywords bestehen
- CSS-Systemfarben nur innerhalb der ausdrücklich getesteten `@media (forced-colors: active)`-Policy und dort in passenden Vorder-/Hintergrundpaaren

Der Guard parst Deklarationswerte beziehungsweise tokenisiert CSS; ein naiver regulärer Ausdruck genügt nicht. Kommentare und normale Strings werden ignoriert, `var()`-Fallbacks und verschachtelte Funktionen aber geprüft. Data-URLs werden als eigener Fall dekodiert und auf eingebettete Farbwerte geprüft. Als PHPUnit-Static-Test läuft er über die normale Suite und wird nicht noch einmal als separates `check.ps1`-Gate registriert.

Negative Fixtures beweisen:

- jede verbotene Notation
- ein Literal innerhalb `color-mix()`
- ein Literal in einem `var()`-Fallback und in einer verschachtelten Funktion
- erlaubte Systemfarben innerhalb sowie verbotene gewöhnliche Farbnamen außerhalb der Forced-Colors-Policy
- Kommentar und String als Nichttreffer
- erlaubtes tokenbasiertes `color-mix()`
- Zero-Match

### 4b.4 Kontrastprüfung

Kontrast wird im Browser auf berechneten und tatsächlich zusammengesetzten Farben geprüft:

- beide Themes
- Text auf Inhaltsfläche
- Text auf Panel/Karte
- Text auf Glas einschließlich tatsächlicher Komposition
- Badges
- Fokusindikatoren
- solide Primär- und Danger-Buttons
- opaker Glass-Fallback

Grenzen und Messvertrag:

- 4,5:1 für normalen Text
- 3:1 für große Schrift; groß bedeutet mindestens 24 CSS-px regulär oder ungefähr 18,66 CSS-px fett, nicht lediglich eine Überschrift
- 3:1 für die zur Erkennung nötigen visuellen Teile aktiver UI-Komponenten und grafischer Objekte gegen unmittelbar angrenzende Farben
- Fokus erfüllt mindestens WCAG 2.2 AA einschließlich „Focus Not Obscured“ und Non-text Contrast; zusätzlich wird „Focus Appearance“ als bewusstes Projektziel geprüft: kontrastierende Fläche mindestens entsprechend einer 2-CSS-px-Umrandung und mindestens 3:1 Änderung zwischen fokussiertem und unfokussiertem Zustand
- halbtransparente Glasflächen werden gegen den tatsächlichen Worst-Case-Hintergrund alpha-komponiert oder auf deterministischen Pixeln gemessen; ein isolierter `getComputedStyle()`-Farbwert ohne Backdrop ist kein Nachweis
- Text-Antialiasing wird nicht als Ausrede oder zufällige Toleranz benutzt; maßgeblich sind spezifizierte Vorder-/Hintergrundfarben und die definierte Komposition

axe bleibt Ergänzung, nicht alleiniger Nachweis.

### 4b.5 Doku

ADR-0013 wird aktualisiert:

- Slate-/Indigo-Identität
- Sidebar-Tokens
- Glasparameter
- Farbtokenvertrag
- Kontrastprüfung
- unveränderte Danger-Semantik
- aktualisiertes `Updated`-Datum

### Abnahme Etappe 4b

- Farbguard grün und mutationsgeprüft
- berechnete Kontraste in beiden Themes grün
- WCAG-1.4.1-Gegenprobe
- axe
- Fast- und Integration-Lane
- Visual-Vergleich gegen die Etappe-0-Aufnahmen
- Forced-Colors-Gegenprobe mit Systemfarben und ohne durch Schatten/Background-Images verlorene Zustandsinformation
- ADR-0013, `docs/QA.md`, Portalhilfe, Changelog und Farb-/UI-Agentregeln sind aktualisiert; Audit-, Job-, Containerlogs und Wire-Verträge sind als nicht betroffen begründet.

---

## Etappe 4c – neue Visual-Baselines und Release-Gate

Nach Review des Design-Refresh:

- Soll-Baselines neu erzeugen
- beide Themes
- Desktop, erzwungener Wrap und Mobile
- Baselineänderungen nur bewusst per Commit
- `--update-snapshots` läuft ausschließlich über einen getrennten, ausdrücklich aufgerufenen Update-Befehl und nie in Fast-/Integration-/Release-Gates
- jede geänderte Baseline wird zusammen mit Diffbild, Runner-Metadaten und fachlichem Grund reviewed; eine Runner-/Fontabweichung darf keine Sollbilder überschreiben
- alte Vorher-Bilder als Audit-Artefakt, nicht als Sollbaseline
- Release-Lane führt das Visual-Projekt aus
- bewusst veränderter Token muss den Visual-Test rot machen

### Abnahme Etappe 4c

```powershell
powershell -NoProfile -File scripts\check.ps1 -Lane Release -Json qa-artifacts/ux-v4-release.json
```

Danach manueller PRE-SHIP-Lauf:

- Tastatur
- Fokus
- Screenreader-Stichprobe
- Hell/Dunkel
- Wrap- und Mobilviewport
- keine echten Daten in Screenshots
- `docs/QA.md`, ADR-0028, Changelog und der Release-/Baseline-Workflow sind in derselben Etappe aktualisiert; Portalhilfe und Laufzeit-Logs/Protokolle sind geprüft und mit Begründung als nicht betroffen protokolliert.

---

## Etappe 5 – bewusst nachgelagerte Einzelvorhaben

Diese Punkte werden nicht automatisch durch den UX-Plan freigegeben:

1. Dashboard „Nächster Schritt“ auf Basis von `deploy_queue_blockers()`.
2. Hilfe-Inhaltsverzeichnis je Panel auf Basis der Help-Konstanten.
3. Takt-/Cadence-Zeilen erst gegen den dann aktuellen Blockervertrag neu spezifizieren.
4. Sticky-Speichern und Dirty-Warnung im VM-Editor.
5. Gemeinsamer Auto-Refresh-Controller erst, wenn Zielseiten und zu ersetzende DOM-Bereiche einzeln benannt sind. Der eng begrenzte Deploy-Blocker-Endpunkt aus Etappe 1 ist davon nicht betroffen und darf nicht auf diese spätere Generalisierung warten.

Für jeden späteren Poller gelten:

- read-only JSON
- kein Seitenreload
- Queue-Formular bleibt unverändert
- Single-Flight
- exponentieller beziehungsweise begrenzter Backoff
- Pause bei `document.hidden`
- `aria-pressed` für manuelle Steuerung
- Auth- und Content-Type-Prüfung
- `session_write_close()`
- DOM-Erzeugung/Textausgabe statt HTML-Injektion
- kein Audit- oder Error-Log pro Poll

Jedes später einzeln freigegebene Vorhaben wird als eigene Etappe mit vollständigem Soll/Ist-, Help/Doku- und Logs/Protokolle-Nachweis geplant. Diese Liste ist keine Erlaubnis, Doku oder Hilfe gesammelt nachzuarbeiten.

---

## Nicht Bestandteil dieses Plans

- Generische persistente `failure_code`-/`failure_phase`-Spalten für alle Jobs.
- Änderung des Inventarfehler-Herkunftsplans.
- globale Volltextsuche über alle Protokolltabellen.
- Digest-/Benachrichtigungssystem.
- Ersteinrichtungsassistent.
- Änderungen an Machine-API- oder MECM-Wire-Verträgen.
- Rückwirkende Übersetzung persistenter technischer Logs.

Eine generische Jobfehlerklassifikation benötigt später einen separaten Plan beziehungsweise ADR.

## Etappenübergreifender Dokumentationsabgleich

Die folgende Liste ist eine unabhängige Vollständigkeitskontrolle. Sie ersetzt nicht die Aktualisierung in der verursachenden Etappe und darf keine offene Nacharbeit enthalten:

Mit dem jeweiligen Implementierungspatch:

- `AGENTS.md`
  - vollständiger Deploy-Blocker-Aggregator
  - `help_url()`-SSoT
  - Portal-Anzeige geschlossener Statusmengen
- `.claude/rules/portal.md`
  - dieselben operativen Regeln
- `GROK.md`
  - kein handgeschriebener Help-Deep-Link
  - keine rohen geschlossenen Statuswerte in der Portal-Anzeige
  - Ausnahmen für Wire, technische Logs und freie Legacy-Werte
  - Farbtokenregel
- ADR-0013
  - Design und Kontrast
- ADR-0028
  - Visual-Harness und Baselinepolitik
- ADR-0032
  - Portal-Korrelationssuche und Indexannahmen
- `docs/QA.md`
  - Visual-Gate, neue Guards, Hint-API, Korrelationsworkflow
- Portal-Hilfe
  - lokalisierte Modusnamen und neue Navigationswege
- `docs/CHANGELOG.md`
  - getrennte Einträge für Zustandswortschatz, Operatorfilter und Design-Refresh

## Online geprüfte Faktenbasis

- WHATWG HTML: `autocomplete="current-password"`/`new-password`, `minlength` und Constraint Validation: <https://html.spec.whatwg.org/multipage/form-control-infrastructure.html> und <https://html.spec.whatwg.org/multipage/input.html>
- W3C WAI: explizite Labels, verständliche Formhinweise und mit `aria-describedby` zugeordnete Fehler: <https://www.w3.org/WAI/tutorials/forms/labels/> und <https://www.w3.org/WAI/tutorials/forms/notifications/>
- WAI-ARIA 1.2: `aria-current="page"` für aktuelle Seitennavigation; echte Tab-Widgets verwenden dagegen `aria-selected`: <https://www.w3.org/TR/wai-aria/#aria-current> und <https://www.w3.org/WAI/ARIA/apg/patterns/tabs/>
- WCAG 2.2: Text-, Non-text-, Farb- und Fokusanforderungen: <https://www.w3.org/TR/WCAG22/>, <https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast>, <https://www.w3.org/WAI/WCAG22/Understanding/use-of-color> und <https://www.w3.org/WAI/WCAG22/Understanding/focus-appearance.html>
- CSS Color 4/Color Adjustment 1: Alpha-Komposition, Systemfarben und Forced-Colors-Verhalten: <https://www.w3.org/TR/css-color-4/> und <https://www.w3.org/TR/css-color-adjust-1/>
- Playwright: Pixelvergleich, umgebungsabhängiges Rendering, reviewbare Baselines, feste Uhr sowie Locale/Zeitzone/Viewport: <https://playwright.dev/docs/test-snapshots>, <https://playwright.dev/docs/clock> und <https://playwright.dev/docs/emulation>
- PHP: offene Sessions serialisieren gleichzeitige Requests; `session_write_close()` beendet den Lock nach benötigtem Sessionzugriff: <https://www.php.net/manual/en/function.session-write-close.php> und <https://www.php.net/manual/en/session.examples.basic.php>
- MySQL: Indexannahmen werden mit `EXPLAIN` und repräsentativen Daten belegt: <https://dev.mysql.com/doc/refman/8.4/en/using-explain.html> und <https://dev.mysql.com/doc/refman/8.0/en/multiple-column-indexes.html>

Die Quellen bestimmen Standards und Werkzeugverhalten. Ob VirtuSphere sie korrekt umsetzt, beweisen ausschließlich Repository-Code, Schema, Tests und die Etappenprotokolle.

## Verbindliche Reihenfolge

1. Masterplan-Etappen 1–10 vollständig abschließen und grün protokollieren.
2. Kollisionsmanifest und QA-Basis.
3. Visual-Harness und Vorheraufnahmen.
4. Etappe 1.
5. Etappe 2 plus Integration.
6. Etappe 3 plus Integration.
7. Etappe 4 plus Integration.
8. Etappe 4b.
9. Review der neuen Gestaltung.
10. Etappe 4c und Release-Lane.
11. Unabhängigen Gesamtabgleich durchführen; jede Lücke öffnet die verursachende UX-Etappe erneut.
12. Nachgelagerte Punkte nur einzeln als neue, vollständig abgenommene Etappe freigeben.
