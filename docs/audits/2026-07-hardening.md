# Audit 2026-07: Pre-Release-Härtungskampagne (Archiv)

**Historisches Dokument.** Dies ist das eingefrorene Kampagnendokument des Prüflaufs vom 2026-07-11 bis 2026-07-13 (vormals `docs/TESTPLAN.md`; archiviert 2026-07-17 im Zuge von AP9). Alle Zahlen, Checkbox-Stände und der genannte Migrations-/Baseline-Stand beschreiben den damaligen Zeitpunkt und werden nicht nachgeführt. Querverweise der Form "TESTPLAN 2.2" in Tests, ADRs und Changelog zeigen auf die Phasen-/Befundnummern dieses Dokuments. Dauerhafte Regeln leben heute in `docs/QA.md` (Bedienung), `docs/QUALITY-GATES.md` (Gate-Bedeutung), `scripts/check.ps1` (ausführbare SSoT) und den ADRs; der Index `docs/TESTPLAN.md` verweist hierher.

Stand: 2026-07-11 (Migrationsstand 0017_normalize_catalog_status, PHPStan Level 4 mit Baseline aktiv, 67 PHPUnit-Testdateien).

Ziel: das Portal, die Worker und die Maschinen-API vor der Auslieferung systematisch auf Funktion, Logik, Sicherheit, i18n, Doku-Wahrheit und Bedienqualität prüfen. Der Plan ist ein lebendes Dokument: Checkboxen abhaken, Findings unten in "Befunde" eintragen, dauerhafte Regeln nach Abschluss in `docs/QA.md` bzw. ADRs überführen.

Nicht-Ziele: kein Umbau der Architektur, keine Retirement-Entscheidungen zur Legacy-API (E3), keine Performance-Optimierung ohne gemessenen Bedarf.

Referenzen: `docs/QA.md` (bestehende Batterie), `PRE-SHIP-CHECKLIST.md`, `GROK.md` (Forbidden Patterns), ADR-0010 (Security-Baseline), ADR-0014 (i18n), ADR-0015 (Test-Baseline), ADR-0016 (Drift-Checks), ADR-0022 (Zeitzone/Scheduling).

Prioritäten: **P0** = Release-Blocker, **P1** = vor Auslieferung dringend empfohlen, **P2** = wertvoll, verschiebbar.

---

## Phase 0: Baseline einfrieren

Zweck: ein reproduzierbarer Nullpunkt. Ohne grünen Ausgangszustand ist später nicht unterscheidbar, ob ein Finding neu oder alt ist.

- [ ] P0: Offene Arbeit (HTTPS-Config, aktuell ~12 uncommittete Dateien) fertigstellen oder bewusst zurückstellen und committen. Kein Testlauf über einem halb fertigen Arbeitsstand.
- [ ] P0: Komplette bestehende Batterie grün, in dieser Reihenfolge:
  ```powershell
  docker exec virtusphere-v2-webapp-php-1 composer --working-dir=/var/www/html test
  docker exec virtusphere-v2-webapp-php-1 composer --working-dir=/var/www/html stan
  docker run --rm -v C:\projekte\VirtuSphere-v2-WebApp:/repo -w /repo virtusphere-v2-webapp-php php scripts/lang-audit.php --ci
  docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --check
  sh scripts/check-enum-sync.sh
  sh scripts/check-php-version-sync.sh
  sh scripts/check-doc-hygiene.sh
  docker run --rm -v C:\projekte\VirtuSphere-v2-WebApp:/repo -w /repo virtusphere-v2-webapp-php sh scripts/lint-csp-patterns.sh --all-changed
  node --check Docker/WebAPI/portal/assets/core.js
  node --check Docker/WebAPI/portal/assets/forms.js
  node --check Docker/WebAPI/portal/assets/deploy.js
  docker exec virtusphere-v2-webapp-webserver-1 nginx -t
  curl.exe -s -i http://127.0.0.1:8021/portal/health.php   # erwartet 200
  curl.exe -s -i http://127.0.0.1:8021/tests/bootstrap.php  # erwartet 403
  ```
- [ ] P0: Deterministischer E2E-Seed. `lib/seed.php` prüfen und zu einem reproduzierbaren Datenbestand ausbauen: je Rolle ein Benutzer, 2 Missionen (davon 1 Template), 4 bis 6 VMs mit Disks/Interfaces/Overrides, Pakete, VLANs, OS-Einträge, 1 ESXi-/1 Ansible-/1 MECM-Credential (Dummy), abgeschlossene und fehlgeschlagene Deploy-Jobs, Log-Einträge je Kategorie. Ein Kommando setzt die Dev-DB auf diesen Stand zurück (Truncate + Reseed).
- [ ] P1: PHPStan-Baseline-Stand notieren (Anzahl Einträge) als Ratchet-Referenz für Phase 2.

**Exit-Kriterium:** alle Kommandos grün, Seed-Reset idempotent wiederholbar, Arbeitsverzeichnis sauber.

---

## Phase 1: SSoTs und Doku festzurren

Zweck: jede doppelt gepflegte Wahrheit beweisen, nicht behaupten; Doku als ausführbaren Vertrag behandeln.

### 1.1 Schema-Konvergenz (P0, größte bekannte Lücke)

- [x] Frische DB A aus `Docker/mysql/mysql-init/struktur.sql` aufbauen. (P0-Befund: laedt nur nach FK-Guard-Fix.)
- [x] ~~Leere DB B nur über `lib/migrate.php`~~ nicht moeglich: Migrationen sind Deltas auf struktur-Basis. Stattdessen B = struktur + alle Migrationen.
- [x] Beide per `mysqldump --no-data --skip-comments` dumpen, normalisieren (AUTO_INCREMENT-Werte strippen) und diffen. Erwartung: identisch. -> **identisch**.
- [x] Als Script `scripts/check-schema-convergence.sh` festhalten. -> erstellt, laeuft gruen.
- [x] Migrations-Idempotenz: `migrate.php` zweimal hintereinander, danach `--check` weiterhin `check: ok`. -> bestaetigt (pending=0).

### 1.2 SSoT-Mirrors und Forbidden Patterns (P0)

- [x] Drift-Checks (enum, php-version, doc-hygiene) grün, Abweichungen an der SSoT-Quelle fixen (ADR-0016). -> alle gruen.
- [ ] `GROK.md`-Forbidden-Patterns einmal gegen den Gesamtbestand greppen, nicht nur gegen Diffs (Auftrag an den `contract-reviewer`-Agenten mit Gesamtscope).
- [x] Statische Contract-Tests vollständig laufen lassen: PortalPostGuard, PortalConfirm, CatalogFilter, SettingsTabRedirect, LogDeepLink, ModalAxis, PermissionParity, DomainInputPattern, PhaseC. -> alle 9 gruen (Teil der 415er-Suite). `SAFE_ACTIONS`-Begruendungen noch nicht gelesen.

### 1.3 ADR-Realitätsabgleich (P1)

- [ ] Alle ADRs (Index `docs/adr/README.md`) gegen den Code lesen. Für jede: gilt die Entscheidung noch, ist der beschriebene Mechanismus noch der implementierte? Abweichung heißt: ADR ergänzen/supersehen oder Code als Drift-Finding notieren.
- [ ] Speziell prüfen (jüngste Änderungen): ADR-0012 (HTTPS-Watcher, jetzt mit Boot-Quarantäne), ADR-0020 (MECM-owned read-only Pakete), ADR-0022 (Scheduling), ADR-0023 (ESXi-Inventar), Log-Retention-ADR.

### 1.4 Hilfe-Doku gegen die echte UI (P1)

Die Hilfe wurde 2026-07 auditiert, seitdem kamen aber Integrations-Redesign, Deploy-Retry, CSV-Export, Settings-Karten (Session-Lifetime, Passwort-Policy, Retention) und der HTTPS-Flow dazu.

- [ ] Delta-Audit: für jede der 8 Hilfe-Sektionen (`lib/help/*.php`) gegen die heutige Seite prüfen: jeder sichtbare Button/Workflow erklärt, nichts Verwaistes beschrieben, Screenshots/Behauptungen faktisch richtig.
- [x] Fehlende Themen ergänzen: Retry fehlgeschlagener Jobs, neue Settings-Karten (Sitzungsdauer, Passwort-Richtlinie), HTTPS-Aktivierung. -> ergänzt (`11ebbf3`). Log-CSV-Export und Retention waren bereits dokumentiert; Passwort-Mindestlänge kommt jetzt aus der Policy statt aus dem Text.
- [ ] Seiten ohne Hilfe-Sektion bewusst entscheiden (z. B. vms/vm_edit/logs/credentials: unter welcher Sektion werden sie erklärt?).

### 1.5 Betriebs-Doku als Test ausführen (P1)

- [x] `docs/INSTALLATION-ANLEITUNG.md` und `docs/DEPLOYMENT.md` als Vertrag gegen die Realitaet geprueft (siehe Befund): jede referenzierte Datei (`setup.sh`/`backup.sh`/`restore.sh`), jeder `.env`-Schluessel, jeder als "entfernt" behauptete Alt-Endpunkt, jedes CLI-Flag (`--check`/`--status`/`seed`) und jede verlinkte Doku existiert und stimmt; `setup.sh` Schritt fuer Schritt gegen die Anleitung, `health.php`/`migrate --status`/Mount-Pfade live bestaetigt. Zwei echte Doku-Bugs gefixt (`d5a6c7f`). Die Frisch-Volume-DB-Initialisierung selbst ist der Phase-1.1-Wegwerf-Container-Beweis. Ein woertlicher `docker compose up` aus leerem Volume wurde nicht auf dem laufenden Dev-Stack wiederholt (haette die echten Dev-Daten zerstoert).
- [x] `docs/operations/backup.md`: `scripts/backup.sh` + `scripts/restore_test.sh` durchgespielt -> Backup OK (882 KB DB-Dump), Restore-Probe OK (23 App-Tabellen inkl. `deploy_vms` im Wegwerf-Container). Ops-Doku-Container-Namen korrekt.
- [ ] `docs/operations/go-live.md` und `PRE-SHIP-CHECKLIST.md` gegen diesen Plan abgleichen; Checklisten-Punkte, die dieser Plan abdeckt, dort referenzieren statt duplizieren.

### 1.6 i18n-Endabnahme (P0)

- [x] `lang-audit --ci` grün: DE/EN-Parität (21 Module), echte Umlaute, kein Gedankenstrich in Portal-Prosa.
- [x] Tote Keys finden: Scanner gebaut, 4 echte Karteileichen triagiert (siehe Befunde 1.6).
- [x] VMware-Begriffe bleiben englisch (Datacenter/Datastore): DE-Katalog sauber, keine Rechenzentrum/Datenspeicher-Treffer.

**Exit-Kriterium:** Schema-Konvergenz bewiesen und als Script versioniert, ADR-Abgleich ohne offene Widersprüche, Hilfe-Delta geschlossen, Installations-Doku einmal fehlerfrei nachgespielt, i18n-Checks grün.

---

## Phase 2: Lücken unterhalb des Browsers schließen (PHPUnit/PHPStan)

Zweck: alles, was ohne Browser prüfbar ist, läuft hier hundertmal schneller und gehört nicht in Playwright.

### 2.1 Coverage-Inventur (P1)

- [ ] Für jede Datei in `lib/` und `lib/repo/` notieren, welcher Test sie abdeckt. Bekannte Kandidaten ohne offensichtlichen Test: `portal_export.php` (CSV), `portal_sort.php`, `portal_time.php`, `inventory_field.php`, `integrations_page.php`, `vm_edit_form.php`, `ssh.php` (so weit mockbar), `layout.php`-Helfer.
- [ ] Ergebnis triagieren: reine Render-Helfer brauchen keinen Unit-Test, alles mit Logik/Parsing/Formatierung schon.

### 2.2 Validierungs-Matrix (P0)

`ValidatorRulesTest` erweitern; je Regel: Grenzlänge (genau am Limit, eins darüber), leer vs. fehlend, nur Whitespace, führende/trailing Spaces, Umlaute/ß, Emoji (4-Byte-UTF-8), gemischte Groß-/Kleinschreibung, Formatvarianten (MAC mit `:`/`-`/ohne Trenner, VLAN 0/1/4094/4095, Hostname mit Unterstrich/Punkt am Ende, IPv4-Grenzen).

- [ ] Matrix je Feldtyp implementiert.
- [ ] `PasswordPolicyTest`: Grenzfälle der konfigurierbaren Policy (Minimum genau erreicht, Unicode-Zeichen, sehr lange Passwörter).
- [ ] `catalog_normalize_status()`: Synonym-Tabelle vollständig getestet, unbekannter Text passiert unverändert (Pass-Through-Beweis).

### 2.3 YAML-Erzeugung: Golden Files + Injection + Playbook-Contract (P0)

Wichtigster Einzelblock des Plans: `lib/ansible_yaml.php` baut `serverlist.yml`/`accounts.yml` per String-Konkatenation aus Benutzereingaben; ein Fehler bricht den Deploy, nicht nur eine Seite. `AnsibleServerlistYamlSafetyTest`, `AnsibleHotplugYamlTest`, `AnsibleLocationOverrideTest` existieren; ausbauen:

- [ ] Golden-File-Tests: fester Seed (Mission mit Overrides, VMs mit mehreren Disks/Interfaces/Paketen, Autostart, Template-Fall) rendert byte-genau gegen eingecheckte Erwartungsdateien unter `tests/fixtures/` (ein Golden File je Deploy-Modus).
- [ ] Injection-Matrix direkt gegen `ansible_yaml_string()`/`ansible_yaml_bare()` (Anhang C): Doppel-/Einfach-Quote, Doppelpunkt+Space, `#`, Zeilenumbruch, CR, Tab, führendes `- `, `!!tag`, `&anchor`/`*alias`, `{`/`[`, `%`, Backslash, Null-Byte, Umlaute, Emoji. Erwartung: immer ein einzelner Skalar, nie Strukturänderung. Prüfung semantisch: Ergebnis durch Parser schicken und Wert vergleichen.
- [ ] Round-Trip-Validität: erzeugte Dateien im Ansible-Kontext parsen (`python3 -c "import yaml,sys; yaml.safe_load(sys.stdin)"` im Ansible-Container oder `ansible-playbook --syntax-check`), danach semantische Assertions: exakt die geseedeten VMs, effektive Datastore/Datacenter-Werte nach Override-Logik, `WaitingTime`/`apiUrl` gesetzt.
- [ ] Schlüssel-Contract-Test (statisch): die im YAML emittierten Keys gegen die in `Ansible/*.yml`-Playbooks referenzierten `item.*`-Variablen abgleichen (der Kommentar zu `item.datastore_name` in `ansible_yaml.php` wird damit erzwungen). Umbenennung auf einer Seite schlägt sofort fehl.
- [ ] `ansible_parse_inventory_output()`-Gegenrichtung: kaputte, gekürzte, teilweise valide Ansible-Ausgaben (Fuzzing light) dürfen nie Exceptions bis zum Worker durchreichen, sondern kategorisierte Fehler liefern.

### 2.4 Maschinen-API-Fehlerpfade (P0)

`MachineApiWireTest`/`MecmReportWireTest` decken Happy-Paths; ergänzen: falsche HTTP-Methode je Endpoint, fehlende Pflichtfelder, ungültiges/abgelaufenes Token, überlange Payloads, kaputtes JSON, doppelte MAC-Einlieferung. Erwartung: generische 4xx/5xx-Envelope ohne Detail-Leak (PhaseC-Contract), Wire-Felder unverändert.

- [ ] Fehlerpfad-Matrix je Endpoint implementiert.
- [ ] CSV-/Export-Pfad: `portal_export.php` Unit-Tests inkl. Formel-Injection-Schutzentscheidung (Werte, die mit `=`, `+`, `-`, `@` beginnen), Umlaute/Excel-Kompatibilität (BOM ja/nein dokumentieren), Content-Type/Disposition.

### 2.5 Statik nachziehen (P1)

- [ ] PHPStan-Baseline-Ratchet: Baseline-Einträge messbar reduzieren (Ziel je Slice definieren), Level 4 halten; Level 5 als Experiment auf `lib/repo/` prüfen.
- [ ] `composer audit` auf dem Dev-Host (braucht Netz; nicht im Air-Gap) für phpseclib/phpunit/phpstan.
- [ ] P2: Mutation-Testing (Infection) punktuell auf `ansible_yaml.php`, `validate.php`, `permissions.php`, Deploy-Statusübergänge. Achtung vendored-Dev-Deps-Policy: Infection nur auf Dev-Host, nicht einchecken, Entscheid dokumentieren.

**Exit-Kriterium:** Golden Files + Injection-Matrix + Playbook-Contract grün, Fehlerpfad-Matrix der Maschinen-API grün, Validierungs-Matrix implementiert, PHPStan-Baseline nicht gewachsen.

---

## Phase 3: Playwright-E2E über das gesamte Portal

Zweck: der Laufzeitbeweis im echten Browser: jede Seite, jeder Button, jedes Feld, beide Sprachen, beide Themes, alle Rollen.

### 3.0 Infrastruktur-Entscheidungen (P0, vorab)

- [x] ADR-0028 geschrieben (Playwright als Dev-only-E2E-Schicht, ADR-0015 erweitert): `tests/e2e/` am Repo-Root, nicht unter `Docker/WebAPI`, `node_modules` git-ignoriert und per npm auf dem Dev-Host installiert, nicht in CI.
- [x] Gerüst: `tests/e2e/package.json` (`@playwright/test`, `@axe-core/playwright`), `executablePath` aus `PLAYWRIGHT_CHROMIUM` mit Default auf das lokale Chromium, `--no-sandbox`, kein Browser-Download. `package-lock.json` committet.
- [x] Gotchas kodifiziert: Basis-URL mit Trailing-Slash, Theme via `localStorage['virtusphere.theme']`, single-worker/seriell (geteilte Dev-DB), Trace `on-first-retry`.
- [x] Auth als Setup-Projekt: seedet den `user`-Account über den PHP-Container, loggt je Rolle ein, `storageState` unter `.auth/` wiederverwendet.
- [x] DB-Assertions: `lib/db.js` über `docker exec` in den MySQL-Container (Port bewusst nicht published; mysql2 vom Host kann nicht verbinden, ADR-Anpassung).
- [x] Stabilität: nur Web-First-Assertions, keine `waitForTimeout`.

### 3.1 Basis-Gesundheit jeder Seite (P0)

Matrix: 22 Portalseiten x {DE, EN} x {light, dark} x {anonym, Operator-Rolle(n), Admin}.

Je Kombination automatisch (`health-matrix.spec.js`, 58 Tests grün: 14 Seiten x {light,dark} x {admin,user}; Sprachumschaltung DE/EN und anonymer Redirect noch offen):
- [x] Seite lädt mit erwartetem Status; ohne Berechtigung Verweigerung (403) statt Inhalt. Anonym-Redirect noch nicht in der Matrix.
- [x] Kein PHP-Fehlertext/Notice/Warning im HTML (Body-Scan gegen Marker-Liste).
- [x] Keine Browser-Konsolen-Fehler, keine CSP-Violations (`page.on('console')` + `pageerror`).
- [x] Air-Gap-Beweis: `page.route`-Interception; jeder Request an einen Host außer `127.0.0.1` bricht ab und schlägt den Test fehl.
- [x] Kein roher `module.key`: Leaf-Element-Scan (ein Element, dessen *ganzer* Text ein Key-Token ist), so dass legitime Audit-Literale wie `users.manage` keinen Fehlalarm auslösen.
- [ ] Screenshot je Seite/Theme als visuelle Baseline (`toHaveScreenshot`, dynamische Bereiche maskiert). Noch offen; kommt mit der Phase-5-UX-Runde.

### 3.2 RBAC- und CSRF-Laufzeitbeweis (P0)

`PermissionParityTest` prüft statisch; hier der Laufzeitbeweis:

- [ ] Je Seite und Rolle: exakt die Buttons/Links sichtbar, deren Aktion die Rolle ausführen darf (Sichtbarkeit == Handler-Berechtigung).
- [ ] Je POST-Action: direkter POST (ohne UI) mit unberechtigter Rolle wird abgelehnt, mit berechtigter Rolle akzeptiert.
- [ ] Je POST-Action: ohne CSRF-Token und mit fremdem Token abgelehnt; Token ist sessiongebunden (Token aus Session A funktioniert nicht in Session B).
- [ ] Login-Härte: falsches Passwort, `ip_locked`-Pfad inkl. Meldung, Session-ID rotiert beim Login (Fixation), Logout invalidiert serverseitig (Back-Button zeigt keine geschützten Inhalte aus dem Cache), `session_ping` verlängert korrekt, Ablauf der konfigurierten Session-Lifetime erzwingt Re-Login.

### 3.3 CRUD-Round-Trips je Entität (P0)

Für Mission, VM, Paket, VLAN, OS, Credential, Benutzer, Settings-Werte; Verifikation immer über Zustand, nie über die POST-Antwort. (`crud-mission.spec.js`: Mission komplett grün; die übrigen Entitäten folgen demselben Muster, noch offen.)

- [x] Anlegen: erscheint in Liste UND per frischem GET UND in der DB (Mission).
- [x] Bearbeiten: Änderung übersteht Speichern + Reload; Nachbarfeld (domain bleibt bei Rename erhalten) unverändert (Mission).
- [x] Löschen: `data-confirm`-Dialog erscheint, "Abbrechen" löscht nichts (DB-Beweis), Bestätigen entfernt überall (Mission). **Befund**: der Dialog nennt den Zielnamen NICHT (siehe Befundtabelle), obwohl `portal.md` `:name` verlangt.
- [ ] Confirm-Zweige: Toggles fragen nur beim destruktiven Zweig; Generatoren nur beim Überschreiben. (Noch offen.)
- [x] Negativfälle: referenziertes Objekt löschen ergibt lokalisierte Ablehnung, keinen 500er (Credential in aktivem Job, `crud-negative.spec.js`). **Dabei P2-Befund gefunden und gefixt** (siehe Befundtabelle). Weitere Referenzpfade (OS/VLAN in VM) noch offen: `deleteOS` hat bewusst keinen Guard, weil `vm_os` freier Text und kein FK ist.
- [ ] Doppelklick auf Submit; Löschen in Tab B während Tab A offen. (Noch offen.)

### 3.4 Feld-Round-Trip: Backend UND Frontend UND Optik (P0)

Ein parametrisierter Test über die Feld-Inventur (Anhang B liefert die Wertematrix). Wichtig: die POST-Antwort rendert Sticky-Werte aus `form_remember()` und beweist daher nichts über Persistenz. Ablauf je (Formular, Feld, Wert):

1. Über die UI eintragen und speichern, Erfolgsmeldung abwarten.
2. Backend: Wert direkt aus MySQL lesen; byte-genau gleich, utf8mb4 intakt, keine HTML-Entities in der DB, Trim-/NULL-Verhalten wie spezifiziert.
3. Frontend nach frischem GET: an jeder Render-Stelle prüfen (Formularfeld per `inputValue()`, Tabellenzelle/Detail per `textContent()`), damit jeder Escaping-Kontext abgedeckt ist.
4. Idempotenz: erneutes Speichern ohne Änderung verändert den Wert nicht (fängt Formatierungs-Drift bei Zahlen/Datum/Zeitzonen, ADR-0022).
5. Optik nur für Extremwerte: Screenshot bei Maximallänge/mehrzeilig (Layoutbruch, Truncation) in beiden Themes.

- [x] Round-Trip-Helper gebaut (`field-roundtrip.spec.js`): UI-Save -> DB byte-exact + keine Entities -> jeder Render-Kontext per `textContent`/`inputValue` -> Idempotenz. XSS-Nichtausführung per Dialog-Handler, der bei jedem Dialog fehlschlägt.
- [x] Anhang-B-Matrix auf `mission_name` (drei Render-Kontexte: Listenzelle, Detail-`<h2>`, Rename-Input): Umlaute/ß, HTML-Metazeichen ohne Doppel-Escape, `<script>`-Payload als Text, Attribut-Breakout, SQL-Zeichen, 4-Byte-Emoji, YAML-Zeichen. 8 Tests grün. **Erkenntnis:** `vm_name` war der naheliegende Kandidat, ist aber charset-beschraenkt auf einen NetBIOS-Namen und *lehnt* die Matrix ab (gueltige Verteidigung, falscher Testtraeger).
- [ ] Validierungsfehler-Pfad je Formular (feldbezogene lokalisierte Meldung, andere Werte sticky, kein generischer Flash): noch offen, kommt mit der CRUD-Runde 3.3.
- [ ] Optik-Screenshots bei Extremwerten: mit der Phase-5-UX-Runde.

### 3.5 Seiten-Spezifika (P0/P1, siehe Anhang A)

- [ ] Settings-Tabs: jeder POST-Redirect landet im Tab des Formulars (`#panel-<tab>`), Feldfehler nie in verstecktem Panel, One-Time-Report-Token sichtbar.
- [ ] Logs: Tab+Kategorie-Deep-Links nur über `log_category_url()`-Semantik, Filter je Tab, CSV-Export lädt herunter (Inhalt, Header, Umlaute, Formel-Injection-Werte aus 2.4 im Export erneut geprüft).
- [ ] Deploy: Modi/Playbook-Zuordnung, Scheduling (Zeitzone!), Staggering-Lock-Wechselwirkung, Storage-Verdikt, Retry nur bei failed/cancelled sichtbar und funktional, Cancel mit `data-confirm-action`-Label.
- [ ] Deploy-Log/Dashboard: Live-Statusanzeige aktualisiert (data-deploy-status), leere Zustände.
- [ ] vm_edit: dynamische Zeilen (Disks/Interfaces) hinzufügen/entfernen, vNIC-Enum begrenzt auf vmxnet3/e1000/e1000e, Location-Overrides inkl. Normalisierung, Hotplug-Flags, Paketauswahl.
- [ ] Kataloge (os/packages/vlans): geteilter Statusfilter, Status-Normalisierung sichtbar (Synonym wird als kanonischer Wert wieder angezeigt), packages im MECM-owned-Modus wirklich read-only (Buttons weg UND POST abgelehnt).
- [ ] Integrations: Ampel-Legende, Inventory-Refresh-Enqueue, Intervall-Änderung, Verbindungstest-Fehler zeigt Kategorie + Detail hinter `<details>`.
- [ ] Credentials: Secret wird nie im Klartext zurückgerendert (value-Attribut leer/maskiert), Verbindungstests je Typ.
- [ ] Users/Account: Passwort-Policy greift in UI und Backend identisch, eigene Locale-/Theme-Umschaltung persistiert.
- [ ] Help: jede Sektion erreichbar, interne Anker funktionieren.
- [ ] Tastatur/Modal-Contract: Confirm-Dialog per ESC schließbar, Fokus gefangen und danach zurückgesetzt, Tab-Reihenfolge in großen Formularen logisch, Combos per Tastatur bedienbar.

### 3.6 Accessibility-Scan (P1)

- [x] `@axe-core/playwright` je Seite/Theme (WCAG 2.1/2.2 A+AA), Findings triagiert (`accessibility.spec.js`, 30 Tests). Drei echte Verstöße gefunden und **alle gefixt** (`5e7051c`, siehe Befundtabelle); Scan läuft jetzt 30/30 grün und bleibt dauerhaft im Lauf.
- [ ] Manueller Tastatur-Durchgang der Kernflüsse (Login, Mission anlegen, VM bearbeiten, Deploy starten) und Fokus-Sichtbarkeit in beiden Themes. Automatik deckt nur einen Teil ab; dieser Teil ist offen und braucht einen Menschen.

**Exit-Kriterium:** Matrix 3.1 komplett grün, RBAC/CSRF-Beweis grün, CRUD-Round-Trips aller Entitäten grün, Feld-Round-Trip über die volle Inventur grün, Axe ohne unbetriebene P0-Findings, Screenshot-Baseline eingecheckt.

**Stand 2026-07-12:** 3.0, 3.1, 3.3 (Mission + Negativfälle), 3.4 und 3.6 (automatisiert) sind grün, 98 E2E-Tests. Offen und bewusst als Backlog dokumentiert: DE/EN-Umschaltung und Anonym-Redirect in der Matrix, Screenshot-Baseline, 3.2 (RBAC-Laufzeit; der Kern ist in 4.6 bereits per curl bewiesen, der Browser würde ihn wiederholen, nicht erweitern), CRUD für die übrigen Entitäten (Muster steht, Guards sind bewiesen), Confirm-Zweige, 3.5 Seiten-Spezifika und der manuelle Tastatur-Durchgang.

---

## Phase 4: Härte-, Logik- und Fehlertests

Zweck: die Fehlerklassen, die Klick-Tests nicht finden: Zeit, Parallelität, kaputte Nachbarsysteme, Grenzdaten, Sicherheit.

### 4.1 Deploy-Lebenszyklus end-to-end (P0)

Mit gemocktem Ansible-Runner (SSH-Schicht stubben):

- [ ] enqueue → claim (Prioritätsordnung, `DeployClaimPriorityTest` als Basis) → running (Heartbeat) → done; Artefakte des Arbeitsverzeichnisses eingesammelt: beide YAML-Dateien vorhanden, `accounts.yml` mit Mode 600, Inhalt == Golden File. (Braucht SSH-Stub; offen.)
- [x] failed-Pfad (Repo-Vertrag): Retry nur fuer failed/cancelled Mission-Jobs, neuer Job mit Provenance-Logzeile, zweiter Retry scheitert am Ein-aktiver-Job-Guard. Runner-Abbruch-Kategorisierung selbst braucht den SSH-Stub (offen).
- [x] Reaper: 700s-alter Heartbeat wird gereapt (failed, Lock geloescht, Fehlertext), frischer Heartbeat und terminale Jobs bleiben unangetastet. Zombie-Finish (Worker lebt, Job wurde gereapt oder gecancelt) wird abgewiesen und ueberschreibt nichts.
- [x] Scheduling (Claim-Grenze): `scheduled_at` in der Zukunft wird nicht geclaimt, in der Vergangenheit sofort; UTC-Vergleich gegen `UTC_TIMESTAMP()` bestaetigt. Parse-Grenzen (DST-Luecke, Vergangenheit, Horizont) deckt `DeployScheduleParseTest` ab. Worker-Neustart zwischen Schedule und Faelligkeit ist per Konstruktion abgedeckt (`scheduled_at` liegt in der DB, der Claim liest sie).
- [ ] Inventory-Refresh nach Deploy (`deploy_worker_refresh_inventory_after_deploy`) feuert genau dann, wenn er soll. (Braucht SSH-Stub; offen.)

### 4.2 Nebenläufigkeit (P0)

- [x] Zwei Worker-Claims auf denselben Job: live mit drei gleichzeitigen Claimern bewiesen, genau einer gewinnt (`attempts=1`), die anderen bekommen null. `FOR UPDATE` + Re-Evaluierung nach dem Lock-Wait traegt.
- [x] Gleichzeitiger Deploy-Start auf dieselbe Mission: **Race gefunden und gefixt** (`d5e8629`, siehe Befunde), deterministisch gepinnt durch `DeployEnqueueRaceTest`.
- [ ] Parallele Bearbeitung derselben VM aus zwei Sessions (Last-Write-Semantik bewusst dokumentieren oder Konflikt erkennen).
- [x] `repo_transaction()`-Re-Entranz: `RepoTransactionReentrancyTest` (Integrationstest, echte DB, Sichtbarkeit ueber zweite Verbindung beurteilt): verschachtelt genau ein Commit, innerer Fehler rollt alles zurueck, Tiefenzaehler erholt sich nach Exception.

### 4.3 Fehlinjektion (P0)

- [ ] MySQL-Container stoppen: jede Portalseite liefert lokalisierten, generischen Fehler ohne Stacktrace; health.php meldet Fehler generisch; Maschinen-API antwortet mit generischer Envelope.
- [ ] ESXi/Ansible/MECM nicht erreichbar bzw. mit Müll-Antworten: Kategorie + redigiertes Detail (`VIRTUSPHERE_INVENTORY_ERROR_*`-Contract), UI zeigt `connection_error_message()`-Mapping, nie Rohtext.
- [ ] Debug-Gating: mit deaktiviertem Debug leakt kein Pfad Details (PhaseC-Contract im Livelauf bestätigen).
- [ ] Platte voll im Worker-Arbeitsverzeichnis (simulierbar über Quota/kleines tmpfs): sauberer Job-Fehler statt halber Artefakte.

### 4.4 Grenzdaten und Volumen (P1)

- [ ] Leere Zustände: frisch geseedete Minimal-DB (0 Missionen, 0 VMs, 0 Jobs) auf jeder Seite: sinnvolle Empty-States, keine Fehler.
- [ ] Volumen: 1000+ VMs, 100+ Missionen, 10000+ Logzeilen einspielen: Renderzeit der Listen messbar unter Schmerzgrenze (Richtwert < 2 s), Sortierung/Filter funktionieren, CSV-Export bleibt vollständig, Speicher im PHP-Container unauffällig.
- [ ] Extremnamen überall dort, wo Anhang B nicht ohnehin durchläuft (Mission mit 255-Zeichen-Namen im Deploy-YAML, im Confirm-Dialog, im Log).

### 4.5 HTTPS-Aktivierungsflow (P0, sobald WP7 committet)

Auf einem Wegwerf-Stack (Snapshot vorher):

- [ ] Admin-Flow: Zertifikat hochladen, Aktivierung, Reload-Watcher greift, Portal über HTTPS erreichbar, HTTP-Verhalten wie spezifiziert.
- [ ] Kaputtes/abgelaufenes/nicht zusammenpassendes Zertifikat: Boot-Quarantäne verhindert Aussperren, Portal bleibt über HTTP bedienbar, Fehler klar gemeldet.
- [ ] Rollback: Deaktivieren stellt HTTP-Zustand wieder her; `nginx -t`-Contract nach jedem Schritt.
- [ ] Cookie-Flags nach Aktivierung: `Secure` gesetzt, Session übersteht den Wechsel definiert (Re-Login akzeptabel, aber dokumentiert).

### 4.6 Sicherheits-Endabnahme (P0)

- [ ] `/security-review` über den Gesamtbranch; Findings triagieren.
- [ ] Security-Header je Antworttyp (HTML, JSON, CSV-Download, 403/404) per curl-Assertions gegen die OWASP-Empfehlungsliste (CSP mit Nonce, X-Content-Type-Options, Frame-Ancestors, Referrer-Policy); zentrale Stelle bleibt `lib/headers.php`.
- [ ] Session-Cookies: HttpOnly, SameSite, Pfad; nach HTTPS zusätzlich Secure.
- [ ] OWASP-WSTG-Stichproben Session-Management: Fixation (3.2 deckt ab), Token-Entropie/Rotation, gleichzeitige Sessions, Logout-Wirkung serverseitig.
- [x] Berechtigungs-Diagonale live geprueft (siehe Befund unten): `user`-Rolle scheitert an allen privilegierten Schreib-POSTs (403, nichts geschrieben), Cross-Mission-VM-Hijack und in Bulk-Delete geschmuggelte Fremd-VM-IDs prallen an mission-skopierten Guards ab, CSRF ohne/fremdes Token -> 400.
- [ ] Log-Hygiene: Secrets (Passwörter, Tokens, ESXi-Secrets) tauchen in keinem Log, keiner Audit-Zeile, keinem Fehlertext auf (Grep über Logs nach Seed-Secrets nach komplettem Testdurchlauf).
- [ ] Upload-/Eingabe-Sonderfälle: Null-Bytes, sehr lange Header, `Content-Length`-Lügen gegen die Maschinen-API.

### 4.7 Last (P2, bewusst optional)

LAN-Betrieb mit wenigen Operatoren; k6 nur, wenn 4.4-Volumentests Auffälligkeiten zeigen. Falls ja: Session-basierte Szenarien (Login → Listen → Edit → Deploy-Status-Polling), Lese- und Schreibpfade getrennt, Schwellwerte je Endpoint taggen.

**Exit-Kriterium:** Deploy-Lifecycle inkl. Fehler- und Zeitpfade grün, Fehlinjektion ohne Leak/500er-Kaskade, HTTPS-Flow inkl. Quarantäne bewiesen, Security-Abnahme ohne offene P0-Findings.

---

## Phase 5: QoL-Durchgang und Abschluss

- [ ] P1: Geführter UX-Durchgang aller Seiten anhand der Screenshot-Baseline (beide Themes, beide Sprachen): Inkonsistenzen in Wording, Abständen, Badge-Farben, Button-Reihenfolge, Tabellen-Truncation notieren. -> **verschoben in die Playwright-Kampagne** (Phase 3), da Screenshot-Baseline dort entsteht.
- [x] P1: Findings triagieren: alle P0/P1 gefixt und committet (siehe Befundtabelle), Nicht-Blocker und bewusste Grenzen dort mit Begründung notiert. Kein separater Backlog nötig; die offenen Punkte stehen unter "Offene Entscheidungen" und in der Befundtabelle.
- [x] P0: Alle P0/P1-Fixes eingearbeitet, komplette Batterie erneut grün (2026-07-12: 458 PHPUnit-Tests, PHPStan L4 clean, alle Drift-Checks, CSP nur WARN, Schema-Konvergenz, Backup/Restore, health 200 / tests 403).
- [x] P0: `PRE-SHIP-CHECKLIST.md` abgehakt (automatische Checks + manuelle Nachweise), `docs/CHANGELOG.md`-Release-Note für die Härtungskampagne geschrieben.
- [x] P1: Dauerhaftes nach `docs/QA.md` überführt (Schema-Konvergenz-Nachweis, Nebenläufigkeits-/HTTPS-Integrationstests); dieser Plan bleibt als Kampagnen-Doku liegen. Golden-File-Pflegehinweis entfällt, da die Golden-File-Tests (2.3) zugunsten der semantischen Injection-Matrix nicht gebaut wurden.

**Exit-Kriterium:** Checkliste komplett, Batterie grün, Release-Note geschrieben, Backlog triagiert. -> **erfüllt**, bis auf die bewusst nach Phase 3 verschobene Screenshot-Baseline.

---

## Anhang A: Seiteninventar mit Prüffokus

| Seite | Kern | Besonderer Prüffokus |
|---|---|---|
| login.php | Auth | Fixation/Rotation, ip_locked, Policy-Fehlertexte, CSRF-exempt korrekt |
| logout.php | Auth | serverseitige Invalidierung, exempt |
| session_ping.php | JSON | verlängert Session, exempt, kein HTML |
| index.php | Redirect | Ziel je Auth-Zustand |
| dashboard.php | Übersicht | Aggregatzahlen == DB, leere Zustände, Live-Deploy-Badge |
| missions.php | CRUD | Template-Erkennung (`mission_name_is_template`), Creator-Spalte (0015), Filter |
| mission_details.php | Detail/Bulk | Bulk-VM-Aktionen, Transfer Export/Import-Round-Trip, Autostart-Propagation |
| vms.php | Liste/Bulk | Sortierung, Filter, Bulk-Aktionen mit Confirm |
| vm_edit.php | Größtes Formular | dynamische Disk/NIC-Zeilen, vNIC-Enum, Overrides+Normalisierung (0014/0017), Hotplug |
| deploy.php | Deploy | Modi, Scheduling/DST, Staggering-Lock, Storage-Verdikt, Retry/Cancel |
| deploy_log.php | Monitor | Live-Status, Log-Detail, leere Zustände |
| os.php | Katalog | Statusfilter, Status-Normalisierung, Delete-Referenzschutz |
| packages.php | Katalog | MECM-owned read-only (UI UND POST), Namens-Split am letzten Bindestrich |
| vlans.php | Katalog | ESXi-VLAN-Sync/Reassign, ID-Aggregate |
| credentials.php | Secrets | nie Klartext-Rendering, Verbindungstest-Fehlerkategorien |
| integrations.php | Status | Ampel, Inventory-Enqueue, Intervall, Fehlerdetails hinter `<details>` |
| users.php | Admin | Rollen, Policy, Selbst-Aussperr-Schutz (eigenen Admin löschen/degradieren?) |
| account.php | Selbstpflege | Passwortwechsel (alte Session-Wirkung), Locale/Theme-Persistenz |
| settings.php | Tabs | Fragment-Redirects je Aktion, One-Time-Token, Session-Lifetime-Wirkung, HTTPS-Karte |
| logs.php | Tabs/Query | Deep-Link-Contract, Kategorie je Tab, CSV-Export, Retention-Anzeige |
| help.php | Doku | Vollständigkeit gegen UI-Delta, Anker |
| health.php | Contract | 200 ok, generisch bei Störung, kein Detail-Leak |

Maschinen-API (kein Portal, eigener Block): `mecm-api.php`, `mecm_updateid.php`, `mecm_packages.php`, `db_importMAC.php`, `mecm_report.php`, `access.php` über die Wire-Tests aus 2.4; keine Lokalisierung, Wire-Felder eingefroren.

## Anhang B: Feld-Wertematrix (Round-Trip 3.4)

| Wert | Fängt |
|---|---|
| `Übermäßig-Straße 42 ß` | Encoding (utf8mb4), Umlaut-Rendering |
| `Tom & Jerry <b>fett</b>` | fehlendes Escaping und Doppel-Escaping (`&amp;amp;`) |
| `<script>alert(1)</script>` | XSS: wird wörtlich angezeigt, nie ausgeführt |
| `'; DROP TABLE vms; --` | SQL-Sonderzeichen im Round-Trip |
| `  gepolstert  ` | Trim-Konsistenz DB vs. Anzeige |
| exakt Maximallänge / +1 | Grenzlängen, DB-Truncation vs. Validierung |
| `🚀🖥️` | 4-Byte-UTF-8 |
| `1,5` / `1.5` / `01.07.2026 23:59` | Locale-/Zeitzonen-Formatierung, Idempotenz beim Re-Save |
| Leerstring nach vorher gefülltem Wert | leeren erlaubt? NULL vs. `''` konsistent |
| YAML-Zeichen `x: y # {a}` in Namen | Weiterverwendung im Deploy-YAML (mit 2.3 verzahnt) |

## Anhang C: YAML-Injection-Matrix (2.3)

`"` `'` `: ` ` #` Newline CR Tab `- ` (führend) `!!str`/`!!python` `&a` `*a` `{` `[` `%` `\` `|` `>` Null-Byte, Umlaute, Emoji, 1000-Zeichen-Wert, Wert nur aus Leerzeichen. Erwartung je Wert: nach `yaml.safe_load` ein einzelner String identisch zur Eingabe (bzw. dokumentierte Normalisierung), Dokumentstruktur unverändert (Schlüsselanzahl konstant).

## Anhang D: Werkzeuge und Zuordnung

| Werkzeug | Einsatz |
|---|---|
| PHPUnit (Container) | Phasen 1.1, 2, 4.1 bis 4.3 |
| PHPStan L4 + Baseline | Phase 2.5, Ratchet |
| Playwright + axe + mysql2 (Dev-Host) | Phase 3, Teile 4.3/4.4 |
| **PSScriptAnalyzer + Pester** (`scripts/run-pester.ps1`, CI) | **die PowerShell-Integrationsclients (ADR-0029)** |
| curl-Assertions | Header-Contracts, health/403 |
| `qa-runner`-Agent | wiederkehrender Batterie-Lauf |
| `drift-checker`-Agent | Phase 1.2, vor jedem Commit-Batch |
| `i18n-checker`-Agent | Phase 1.6, nach Textänderungen |
| `contract-reviewer`-Agent | Phase 1.2 (Gesamtscope), Maschinen-API-Änderungen |
| k6 (optional) | Phase 4.7, nur bei Bedarf |

Die PowerShell-Zeile war bis 2026-07-13 leer: `Powershell-MECM/` kam als Prüfobjekt in keiner Phase vor, obwohl der Code als SYSTEM in Endlosschleifen auf dem SCCM-Server des Kunden läuft. Was diese Lücke verdeckt hat, steht in der Befundtabelle unter "Nachlauf 2026-07-13".

## Befunde

Während der Kampagne hier sammeln (Datum, Phase, Schwere, Kurzbeschreibung, Fix-Commit), bei Abschluss nach `docs/CHANGELOG.md` bzw. Issues überführen.

| Datum | Phase | Schwere | Befund | Status |
|---|---|---|---|---|
| 2026-07-11 | 1.1 | **P0** | `struktur.sql` laedt nicht auf frischem Volume: `deploy_esxi_inventory`/`_state` referenzieren `deploy_credentials` per FK vor dessen `CREATE`, ohne `FOREIGN_KEY_CHECKS=0`. Frischer `mysql:8.4`-Init bricht mit Fehler 1824 ab (Exit 1), jede Neuinstallation scheitert an der DB-Initialisierung. Verifiziert mit Wegwerf-Container. | Gefixt (`125e51b`): FK-Checks-Guard um die Datei, wie mysqldump ihn setzt. Fresh-Init danach `running`/Exit 0, 23 Tabellen. |
| 2026-07-11 | 1.1 | Info | Konvergenz bewiesen: `struktur.sql` allein == `struktur.sql` + alle 17 Migrationen (byte-identischer `--no-data`-Dump). Migrationen sind Deltas auf der struktur-Basis, kein From-Empty-Rebuild (0001 ALTERt Kern-Tabellen). Plan-Methode 1.1 ("leere DB B nur ueber migrate.php") passt daher nicht zur Architektur; als `scripts/check-schema-convergence.sh` festgehalten (laeuft gruen). | Erledigt |
| 2026-07-11 | 0 | Tooling | `sh scripts/lint-csp-patterns.sh --all-changed` via Docker ist auf diesem Windows-Checkout unbrauchbar: `core.autocrlf=true` + nur `*.sh` in `.gitattributes` -> `git diff` im Linux-Container sieht fast den ganzen Baum als geaendert, scannt hunderte unveraenderte Dateien. Realer Arbeitsbaum ist sauber. | Gefixt (`695f4ca`): `* text=auto` in `.gitattributes`; Index bleibt LF unabhaengig von der lokalen Config. Simuliert verifiziert (`git -c core.autocrlf=false diff`): vorher ~82 Phantom-Dateien, nachher nur echte Aenderungen. |
| 2026-07-11 | 0 | Info (False Positive) | CSP-Lint `BLOCK: secret fallback` in `lib/seed.php` ist ein Fehlalarm: `getenv('SEED_ADMIN_PASSWORD') ?: ''` faellt auf Leerstring zurueck (kein hardcoded Secret), Script bricht direkt darunter bei leerem Passwort ab. Nur unter dem `--all-changed`-Rauschen ueberhaupt sichtbar. | Gefixt (`2196cb7`), siehe Befund 1.2 unten. |
| 2026-07-12 | 1.2 | Minor | `lib/seed.php:13-15` trifft GROK-Forbidden-Pattern #6 (`getenv(...) ?:` Secret-Fallback) woertlich. Dev-Seed, Fallback auf Leerstring + Hard-Fail bei leerem Passwort, daher kein echtes Secret-Leak. Rest des Gesamt-Greps (interpolierte SQL, Short-Tags, externe Assets, `window.confirm`, Hand-Badges, Hand-CSRF) sauber bzw. dokumentierte Ausnahmen. | Gefixt (`2196cb7`): getenv-false faellt jetzt durch den String-Cast auf Leerstring statt durch `?:`; Verhalten identisch, Hard-Fail bei leeren Credentials bleibt, CSP-Lint meldet keinen BLOCK mehr. |
| 2026-07-12 | 1.3 | Info | ADR-Realitaetsabgleich (fokussierter Teil): ADR-0027/0012 (HTTPS) vollstaendig verifiziert - 301 GET/HEAD + 308 sonst (`https_config.php:305`), HSTS max-age 15552000/180d secure-only, `openssl_x509_check_private_key` + Expired-Reject, Key 0600 vor Rename, `HttpsConfigTest::testHttpAndHttpsDenyRulesStayInSync`. ADR-0020 (MECM-owned read-only) bestaetigt: `packages.php` ist reine Anzeige (kein POST/Form/Button), read-only per Konstruktion. Restliche ~22 ADRs noch offen. | Teilweise erledigt |
| 2026-07-12 | 1.4 | **P1 (Doku-Drift)** | Hilfe hardcodet "Startpasswort (mindestens 12 Zeichen)" (`help.usersmgmt_create_p1`/`_action_reset`), obwohl die Passwort-Mindestlaenge seit Commit 8b3eac3 **konfigurierbar** ist. Kann Operator in die Irre fuehren, wenn die Policy != 12 gesetzt ist. | Gefixt (`11ebbf3`): beide Saetze interpolieren `:min`, `lib/help/users.php` liest es ueber `password_policy_min_length(db())`. Live bewiesen: Policy auf 16 -> Hilfe sagt "mindestens 16 Zeichen". |
| 2026-07-12 | 1.4 | P1 (Doku-Luecke) | Hilfe fehlt fuer neue Features: **Deploy-Job-Retry** (re-queue failed/cancelled) nicht erklaert; **Session-Lifetime-Karte** nicht erklaert; **Passwort-Policy-Karte** (Settings) nicht erklaert; **HTTPS-Aktivierung** hat keine Portal-Hilfe (nur Ops-Runbook `docs/operations/https.md`). Positiv: **Log-CSV-Export** (`help.transfer_p5`, `ops_restore_p3`) und **Log-Retention** (`logs_p3`/`p4`) sind bereits dokumentiert; Plan-Annahme dort veraltet. | Gefixt (`11ebbf3`): vier neue Sektionen (Deploy-Retry im Deploy-Tab; HTTPS, Sitzungsdauer, Passwort-Richtlinie im Settings-Tab), DE/EN. Grenzwerte (Policy-Min/Max, Session-Min/Max, Warnfrist, Zertifikats-Warntage, HTTPS-Port) interpolieren aus ihren Konstanten statt als Zahl im Text zu stehen. |
| 2026-07-12 | 2.3 | **P1 (Deploy-Bruch)** | `ansible_yaml_string()` escapte C0-Steuerzeichen (NUL, SOH, VT, FF, ESC, DEL, `\x00-\x1F` ausser `\t\r\n`) nicht. YAML 1.1 verbietet rohe Steuerzeichen auch im Double-Quote-Skalar; die Freitext-Validatoren (`optionalString`/`requireString`) trimmen + laengen nur, kein Control-Char-Reject. Ein einzelnes solches Byte in Mission-Name/Notes/VM-Name/Datastore (Copy-Paste, Import, Legacy-API) macht die komplette `serverlist.yml` unparsebar -> ganzer Missions-Deploy scheitert am YAML-Parse (fail-closed, keine Struktur-Injection). Semantisch mit PyYAML 6.0.3 bewiesen (Injection-Matrix Anhang C, 32 Werte). | Gefixt (`66e2162`): Escaper emittiert `\xNN`; Regressionstest `testControlCharactersAreHexEscaped`; PyYAML-Round-Trip 0 Mismatches; Suite 416 gruen, STAN clean. |
| 2026-07-12 | 2.3 | OK | Playbook-Schluessel-Contract geprueft: alle `item.*`-Variablen der Produktions-Playbooks (`createVMs`/`powercycle`/`start`/`autostart`/`export`) haben ein Gegenstueck in den von `ansible_yaml.php` emittierten serverlist-Keys (vm_name, memory, vcpus, guest_id, needs_mac, autostart, disks->size_gb/type, network->name/device_type, datacenter_name, datastore_name, hotadd_cpu/memory). Keine Waisen, kein Rename-Drift. `item.path`/`item.content` nur in `test-linux_playbook.yml` (eigener File-Loop, nicht Teil des Deploy-Contracts). | Erledigt |
| 2026-07-12 | 2.4 | OK | Maschinen-API-Fehlerpfad-Matrix (live, allowlisted 127.0.0.1) gruen: falsche Methode -> 405, kaputtes/leeres JSON -> 400 "Invalid JSON body"/"Invalid data format", leerer Katalog-Payload -> 400, mecm_report Oversize (256 KB) -> 413 "Payload too large". Alle Antworten generische `{"error":...}`-Envelopes, keine Stacktraces, kein Detail-Leak (PhaseC-Contract im Livelauf bestaetigt). Body-Groessen gedeckelt: nginx 100m, PHP post_max_size 8M (gilt auch fuer `php://input`), memory_limit 128M -> keine Erschoepfung. mecm_updateid/db_importMAC haben keinen eigenen Cap, aber PHP 8M greift. | Erledigt |
| 2026-07-12 | 2.4 | Go-Live-Hinweis | Live-Dev-DB: `deploy_accessToWebAPI` ist **leer** (struktur seedet 127.0.0.1, diese DB hat keine Zeile) -> Maschinen-API weist aktuell jede IP mit 403 ab. Kein Code-Defekt, aber Go-Live-Checklisten-Punkt: MECM-/PowerShell-Server-IPs muessen in die Allowlist, sonst 403. Nebenbeobachtung: POST-Endpoints mit falscher `action` liefern 405 (kombiniertes Method+Action-Gate) statt 400/404 - generisch, kein Leak, kosmetisch; inzwischen gefixt (`60e58e7`): Gate gesplittet, falsche Methode weiter 405, unbekannte `action` antwortet wie `mecm-api.php`/`mecm_report.php` mit 400 `{"message":"Invalid action specified"}`, Wire-Test pinnt beides (live mit temporaer allowlisteter IP bewiesen). | Gefixt (`11ebbf3`, Doku): IP-Freigaben sind jetzt eigener Go-live-Schritt. Dabei zweite Luecke gefunden: `docs/operations/go-live.md` nannte nur die MECM-Server-IP, aber auch der **Ansible-Host** ist IP-gated (`db_importMAC.php`, `upload_mac_list.py` meldet die MACs zurueck); ohne Eintrag laeuft ein Deploy durch, ohne dass je eine MAC ankommt. Client-VMs brauchen keinen Eintrag (MAC gilt als Ausweis). |
| 2026-07-12 | 2.3 | Info | `ansible_yaml_bare()` emittiert Werte `^[A-Za-z0-9_.-]+$` unquoted; YAML-1.1-Keywords (`yes/no/null`) und Zahlen werden dabei zu bool/null/int/float typgewandelt. Call-Sites sind ausschliesslich enum-validierte Felder (disk type, vNIC device_type, autostart stop_action) - kein valider Enum-Wert kollidiert, daher nicht erreichbar. Dokumentierte Grenze, kein Fix. Alle `ansible_yaml_string()`-Freitextwerte (28) ausser Steuerzeichen: Round-Trip byte-identisch. | Notiert |
| 2026-07-12 | 1.6 | Info | Dead-Key-Scan: von 1331 DE-Keys sind nach Abzug aller dynamisch gebauten Familien (`validate.field_*`, `settings.timezone_city_*`, `help.stack_*`, `help.perm_*`, `settings.retention_*` etc.) nur **4 echte Karteileichen** uebrig: `common.add`, `common.created`, `common.name_label`, `portal.not_found`. Harmlos (DE/EN-Paritaet bleibt gruen), Kandidaten fuers Aufraeumen. VMware-Begriffe bleiben englisch, kein Gedankenstrich in Prosa. | Gefixt (`11ebbf3`): alle vier in DE und EN entfernt, Paritaet weiter gruen. |
| 2026-07-12 | 1.4/1.6 | OK | Doku-Fixes aus `11ebbf3` live nachgeprueft: Hilfeseite als Admin gerendert (curl), kein unaufgeloester Platzhalter und kein roher `module.key` im HTML; die Zahlen kommen aus der echten Umgebung (`Port 8022` == gesetztes `WEB_HTTPS_PORT`, nicht der Default 8443), dazu `ab 30 Resttagen`, `15 bis 480 Minuten`, `12 bis 128 Zeichen`, `5 Minuten vor Ablauf`. Zusatzpruefung gebaut: Platzhalter-Paritaet DE/EN ueber alle 1343 gemeinsamen Keys, 0 Abweichungen - ein `:token`, der nur in einer Sprache steht, verschluckt sonst still eine Zahl, ohne dass `lang-audit` (prueft nur Key-Paritaet) anschlaegt. Vier Leerzeichen-Nits (`=>'`) nachgezogen. | Erledigt |
| 2026-07-12 | 2.2 | **P1 (MECM-Bruch)** | `Validator::mac()` speicherte den MAC in der Schreibweise, in der er getippt wurde. Alle MAC-Lookups (`mecm-api.php`, `mecm_report.php`, Duplikat-Guard in `db_importMAC.php`) normalisieren die *eingehende* Adresse per `virtusphere_normalize_mac()` und suchen dann `WHERE mac = ?`. Die Bindestrich-Form, die Windows in `ipconfig /all` druckt (`00-50-56-AA-BB-CC`), und die Cisco-Punkt-Form matchen diese Abfrage nie: eine im Portal so eingetippte VM ist fuer MECM **unauffindbar**, ohne Fehlermeldung. Die `utf8mb4_unicode_ci`-Kollation rettet nur den Gross-/Kleinschreibungsfall. Migration 0008 hatte die Spalte genau dafuer schon einmal kanonisiert ("so exact-match lookups work regardless of the writer's format"), aber der Portal-Schreibpfad saete die Drift danach neu aus. Live bewiesen: geseedete Bindestrich-Zeile -> MECM-Lookup `NO MATCH`; nach Fix + Backfill -> `vm_id=4543`. | Gefixt: `Validator::mac()` kanonisiert beim Annehmen (wie `enum()` seinen Wert), Migration `0018_renormalize_interface_macs` repariert verdriftete Bestandszeilen (idempotent, Re-Run = No-op, `pending=0`). Regressionstest in der Matrix. |
| 2026-07-12 | 2.2 | OK | Validierungs-Matrix implementiert (`ValidatorRulesTest`, 13 Tests / 197 Assertions): Grenzlaengen exakt/+1, leer vs. Whitespace-only, Umlaute und Emoji als *ein* Zeichen (`mb_strlen` == VARCHAR-Semantik unter utf8mb4), Integer-Grenzen inkl. Overflow / `12abc` / `0x1` / Dezimalzahl, Hostname-, NetBIOS- und FQDN-Labelgrenzen (15/63/64), IPv4-Grenzen inkl. fuehrender Null, Subnetzmaske `/0` bis `/30`, MAC-Notationen, Enum-Kanonisierung samt Default-Fallback. Freitext behaelt Steuerzeichen bewusst (die Regeln trimmen und messen, sie sanitisieren nicht) - deshalb ist der YAML-Escaper der Chokepoint, siehe Befund 2.3. | Erledigt |
| 2026-07-12 | 2.2 | Minor | `Validator::hostname()` ist deutlich lockerer als `fqdn()`: das Muster erlaubt leere Labels und Bindestriche an Label-Raendern, also `a..b`, `a.-b`, `a-.b`. Nur fuer `vm_hostname` genutzt und fail-closed (DNS und MECM weisen den Namen spaeter ab), daher kein Blocker. Zunaechst bewusst nicht verschaerft (Produktentscheidung); am 2026-07-12 entschieden und umgesetzt. | Gefixt (`6dbb8e9`): Muster nutzt jetzt `VIRTUSPHERE_DNS_LABEL_PATTERN` je Label (deckelt Labels auch auf 63 Zeichen), DE/EN-Meldung nachgezogen, 5 neue Matrix-Faelle. Betroffen ist nur der Grandfather-Zweig fuer *unveraenderte* Legacy-Hostnamen (`lib/repo/vms.php`); neue/geaenderte Hostnamen liefen schon durch `netbiosHostname()`. Eine Bestands-VM mit `a..b`-Namen muss beim naechsten Speichern korrigiert werden; die Hilfe (`help.naming_p3`) nennt diese Ausnahme seit `97e83be`. |

| 2026-07-12 | 2.4 | OK | CSV-Export live geprueft (`vms.php?export=csv`, Admin-Session): `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, UTF-8-BOM (`efbbbf`), Semikolon-Trenner (deutsches Excel). Formel-Injection ist bereits geschuetzt (`portal_csv_guard()`, Offene Entscheidung 2 war damit schon beantwortet): geseedeter DDE-Payload als VM-Name kommt als `"'=cmd\|'/c calc'!A1"` zurueck, Semikolon und Quote im Hostnamen sauber gequotet/verdoppelt. Unit-Tests ergaenzt (`PortalCsvExportTest`, 13 Tests): Lead-Zeichen-Menge `= + - @ \t \r`, Mitte-Vorkommen bleibt unangetastet, negative Zahl wird bewusst zu Text, Struktur-Round-Trip (4 Zeilen x 2 Spalten, nichts bricht aus seiner Zelle aus). | Erledigt |
| 2026-07-12 | 2.4 | Minor (gehaertet) | Der CSV-Dateiname traegt den Missionsnamen (Benutzereingabe) in einen *gequoteten* `Content-Disposition`-Wert. Kein Live-Leck: alle drei Aufrufer waren sicher (Literale in `missions.php`, feste Tab-Liste in `logs.php`, Slug in `vms.php:131`). Aber die Regel stand ungeschrieben im Aufrufer, waehrend den Header `portal_send_csv()` schreibt: ein vierter Aufrufer ohne Slug haette ein Quote in den Dateinamen gelassen. (Response-Splitting verhindert PHP selbst, es geht um den wohlgeformten Header.) | Gehaertet: Slug als `portal_csv_filename_slug()` an die Stelle gezogen, die den Header baut; `vms.php` uebergibt den Rohnamen. Verhalten identisch (gleicher Dateiname vor/nach dem Umbau), Test pinnt die Zeichenmenge inkl. Quote-, CRLF- und Leer-Slug-Fall. |

| 2026-07-12 | 4.6 | **P1 (Header-Luecke)** | Die von nginx/PHP-FPM **selbst** erzeugten Antworten (403 aus den Deny-Regeln, 404 fuer eine nicht existierende PHP-Datei) laufen an `lib/headers.php` vorbei und trugen **gar keine** Security-Header: kein CSP, kein `X-Content-Type-Options`, keine `Referrer-Policy`. In der gesamten nginx-Konfiguration stand kein einziges `add_header`. Per curl ueber alle Antworttypen gefunden (HTML, JSON, CSV, health, 403, 404, Maschinen-API). | Gefixt (`0944c0b`): `add_header ... always` in beiden Server-Bloecken. Falle dabei: ein pauschales CSP haette jede PHP-Antwort ein **zweites** CSP gegeben, und der Browser erzwingt die *Schnittmenge* zweier Policies, d.h. die nonce-lose Fallback-Policy haette die eigenen Styles/Skripte des Portals blockiert. Deshalb `map $upstream_http_*`: nginx ueberspringt ein `add_header` mit leerem Wert, der Header wird also nur gesetzt, wenn PHP keinen geschickt hat. Live verifiziert: Portalseite genau **ein** CSP (mit Nonce), 403/404 bekommen striktes `default-src 'none'`. Generierter HTTPS-Block gleich mitgezogen und per Test gepinnt, sonst geht der Header beim HTTPS-Einschalten still wieder verloren. |
| 2026-07-12 | 4.6 | OK | Header-Contract der PHP-Antworten (HTML, JSON, CSV, health) gegen die OWASP-Liste geprueft: CSP mit Nonce und `frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy`, `Cache-Control: no-store` auf Portalseiten. `X-Frame-Options` fehlt bewusst; `frame-ancestors` ersetzt es fuer aktuelle Browser. | Erledigt |

| 2026-07-12 | 4.3 | **P1 (Wire-Contract)** | Bei gestoppter MySQL antworteten **alle sechs Maschinen-Endpunkte plus `api/login.php`** mit der **HTML-Fehlerseite des Portals** (500, `text/html`). MECM, die PowerShell-Skripte, der Ansible-Rueckkanal und der Desktop-Client parsen den Body als JSON: sie sehen also genau an dem Tag, an dem die DB haengt, einen Parse-Fehler statt eines Serverfehlers, den sie protokollieren und wiederholen koennten. Ursache ist eine unsichtbare Reihenfolge: `mysql.php` verbindet **beim Laden** (`$connection = db()`), die Exception fliegt also aus dem `require` heraus, bevor irgendetwas darunter laeuft. | Gefixt (`199163e`): Antwortform als `virtusphere_error_response_mode('json')` pro Entry-Point, deklariert **vor** `mysql.php`. Contract-Test `MachineApiErrorShapeContractTest` pinnt beides: die Reihenfolge je Endpunkt und dass `lib/` den Modus nicht global umschaltet (sonst wuerde jeder Portalfehler als JSON-Blob im Browser landen). Live bewiesen mit gestoppter DB: 7/7 liefern `{"error":"Internal server error","reference":"..."}`, Portal bleibt HTML. |
| 2026-07-12 | 4.3 | OK | Fehlinjektion MySQL-Stopp, Portalseite: 500 mit generischer deutscher Fehlerseite plus Referenz-ID, **kein** Stacktrace, kein Pfad, kein `mysqli`/SQLSTATE-Text im Body (Debug ist aus, PhaseC-Contract im Livelauf bestaetigt). `health.php` meldet 503 mit generischem JSON und faellt nicht auf die HTML-Seite zurueck. Nach dem Wiederanlauf der DB ist `health.php` ohne Eingriff wieder 200. | Erledigt |

| 2026-07-12 | 4.6 | OK | Log-Hygiene nach dem kompletten Fehlerpfad-Durchlauf: die echten Secret-Werte aus der Container-Umgebung (`MYSQL_*`, `APP_KEY`) tauchen in **keiner** App-Logdatei auf. `/logs/*` ist per nginx-Deny nicht erreichbar (403, auch fuer `error.log`). `logs/initial-admin-password.txt` enthaelt erwartungsgemaess ein Klartext-Erstpasswort, ist aber ein dokumentiertes Setup-Artefakt: `go-live.md` verlangt das Loeschen nach dem Erstlogin, und die Datei ist untracked und nicht web-erreichbar. Nebenbefund: `logs/query.log` (SQL samt gebundener Parameter im Klartext) ist ein **totes Artefakt vom 2026-06-28**; kein Code im Repo schreibt sie noch, keine sensiblen Parameter darin. Nur auf dieser Dev-Box, kein Auslieferungsartefakt. | Erledigt |
| 2026-07-12 | 4.6 | OK | Session-Management (OWASP-WSTG-Stichproben) live geprueft: Cookie mit `HttpOnly` und `SameSite=Strict`, `Secure` erwartungsgemaess erst nach HTTPS (Phase 4.5). Session-ID **rotiert beim Login** (Fixation abgewehrt), die Pre-Login-ID ist danach nicht mehr verwendbar, und Logout invalidiert serverseitig (die alte Cookie-Datei bekommt 302 statt Inhalt). | Erledigt |
| 2026-07-12 | 4.5 | **P1 (Aussperrung)** | Nach der Boot-Quarantaene war das Portal **ueber beide Schemata unerreichbar**. Die Quarantaene in `init.sh` haelt *nginx* am Leben (kaputte generierte Config wird zu `*.bad` umbenannt, nginx startet mit HTTP), aber die Umleitung sitzt in PHP und haengt am DB-Schalter `https_redirect_enabled`, der von der Quarantaene nichts weiss: HTTP antwortete weiter mit 301 auf einen Port, auf dem niemand mehr lauscht. Der dokumentierte Rueckweg (HTTP) war also genau das, was den Admin wegschickte; einziger Ausweg blieb der manuelle DB-Eingriff. Hilfe und ADR behaupten "HTTP bleibt in jedem Fall erreichbar", was ausgerechnet im Quarantaenefall nicht stimmte. Live reproduziert: kaputte Config + Container-Neustart -> HTTPS `connection refused`, HTTP weiterhin 301 dorthin. | Gefixt (`161d5f6`): `https_listener_live()` - die generierte Config ist der einzige Beleg, den PHP hat, dass ueberhaupt ein Listener existiert; die Umleitung haengt jetzt daran. Die HTTPS-Karte warnt, statt "An" zu behaupten. Regressionstest bildet die Quarantaene (`rename` nach `.bad`) nach. Live nachgeprueft: HTTP wieder 200, Admin kommt rein und liest die Warnung; nach Wiederherstellen der Config springt die Umleitung von selbst wieder an. |
| 2026-07-12 | 4.5 | OK | Restlicher HTTPS-Flow live durchgespielt (Wegwerf-Zertifikate mit SAN, danach vollstaendig zurueckgerollt): **Negativfaelle** werden abgewiesen, ohne etwas zu schreiben (abgelaufenes Zertifikat, Schluessel passt nicht zum Zertifikat, kein gueltiges PEM; `ssl/` bleibt leer), und der Admin bekommt einen praezisen Feldfehler ("Das Zertifikat ist abgelaufen") statt nur des generischen Flashes. **Positivfall**: Upload -> Karte zeigt Antragsteller, SAN-Liste, Aussteller, Gueltigkeit, SHA-256-Fingerabdruck, Resttage; Schluessel liegt mit `0600`, Zertifikat mit `0644`. **Listener**: Watcher laedt in ~2s nach, HTTP bleibt bedienbar. **Umleitung**: GET -> 301, POST -> **308** (Body bleibt erhalten); Maschinen-API (`mecm-api`, `mecm_report`, `db_importMAC`) und `health.php` werden **nie** umgeleitet. **Cookie**: `secure` erscheint ueber TLS. **HSTS**: `max-age=15552000` nur ueber HTTPS, nicht ueber HTTP. **Watcher-Ablehnung**: kaputte Config im laufenden Betrieb wird abgelehnt, die letzte gute laeuft weiter (HTTPS 200). `nginx -t` nach jedem Schritt gruen. | Erledigt |
| 2026-07-12 | 4.5 | Restrisiko (Doku) | HSTS + Quarantaene bleiben eine unangenehme Kombination: ein Browser, der die HSTS-Vorgabe schon gesehen hat, verweigert HTTP fuer 180 Tage von sich aus, egal was der Server sendet. Der Fix oben stellt HTTP serverseitig wieder her, aber genau dieser Browser kommt trotzdem nicht durch (frischer Browser, Inkognito oder HSTS-Cache leeren hilft). Das ist der Preis von HSTS und in der Portal-Hilfe bereits als Warnung benannt; gehoert zusaetzlich in das Stoerungs-Runbook `docs/operations/https.md`. | Erledigt (`6398d4a`): steht dort unter "Quarantaene trifft auf HSTS" samt Ausweg (frischer Browser, Inkognito, `chrome://net-internals/#hsts`). |

| 2026-07-12 | 4.2 | **P1 (Doppel-Deploy)** | Zwei ueberlappende Deploy-Starts auf dieselbe Mission passierten **beide** den Ein-aktiver-Job-Guard. Unter REPEATABLE READ liest der einfache COUNT den Transaktions-Snapshot, und der wird vom ersten Read des Enqueues gepinnt, also **bevor** die Transaktion am Mission-Lock zu warten beginnt: der zweite Enqueue zaehlte gegen einen Stand, in dem der Job des ersten noch nicht existierte. Kein Timing-Glueck: das Race feuert **jedes Mal**, wenn der zweite Request das Mission-Lock erreicht, waehrend der erste es haelt. Folge: zwei aktive Jobs, die der einzelne Worker nacheinander ausfuehrt; der zweite Deploy laeuft gegen die frisch erzeugten VMs. Live deterministisch bewiesen (zwei Verbindungen, verschachtelte Interleaving-Nachbildung): "A: ALSO enqueued job 2221, active jobs: 2". | Gefixt (`d5e8629`): Guard ist jetzt ein sperrender Existenzcheck (`FOR UPDATE`), dasselbe Muster wie `repo_create_system_job`; hinter dem Mission-Lock liest er immer den letzten Commit. Beide Enqueue-Pfade (Einzeljob, Stagger-Gruppe) gepinnt durch `DeployEnqueueRaceTest` (deterministisch, kein Timing, kann nicht flaken). |
| 2026-07-12 | 4.2 | OK | Claim-Rennen live bewiesen: drei gleichzeitige Claimer (Epochen-Barriere) auf einen einzigen queued Job -> genau einer gewinnt, `attempts=1`, `locked_by` eindeutig, Verlierer bekommen null. Repo-Vertragsmatrix (14 Checks, alle PASS): Scheduling-UTC-Grenze, Reaper (stale -> failed + Lock weg; frisch + terminal unangetastet), Zombie-Finish nach Reap **und** nach Cancel abgewiesen, Cancel-Matrix (queued nicht mehr claimbar, running -> cancelled + Lock weg, Doppel-Cancel abgelehnt), Retry-Guards. `repo_transaction()`-Re-Entranz als Integrationstest (`RepoTransactionReentrancyTest`). | Erledigt |
| 2026-07-12 | 4.1 | P2 (Robustheit, dokumentierte Grenze) | Zwei verwandte Grenzen der Single-Worker-Topologie, kein Fix in diesem Lauf: (1) Der Playbook-Exec laeuft mit `setTimeout(0)` (unbegrenzt); stirbt der Ansible-Host hart (TCP half-open), haengt der Worker **unbegrenzt** im Read. Cancel setzt zwar den Job-Status, aber `assert_not_cancelled` laeuft nie mehr, und der Reaper ist derselbe (haengende) Prozess: die gesamte Deploy-Pipeline steht bis zum Container-Neustart. Moegliche Abhilfe: `SSH2::setKeepAlive()`; nicht blind eingebaut, weil ohne echten SSH-Host nicht verifizierbar. (2) Der Heartbeat tickt nur bei **Output** (Intervall 30s, stale nach 600s): ein >10-min schweigsamer Task (grosser Clone) wuerde bei **skalierten** Workern vom Nachbarn gereapt, der Deploy liefe aber weiter -> Status-Luege + Retry-Doppel-Deploy. In der ausgelieferten Compose (genau 1 Worker) nicht erreichbar, weil der einzige Reaper der beschaeftigte Worker selbst ist. Wer Worker skaliert, muss zuerst den Heartbeat zeitbasiert machen. | Notiert (Doku; Fix-Kandidaten fuer spaeter) |

| 2026-07-12 | 4.6 | OK | IDOR-/Berechtigungs-Diagonale live mit einer echten `user`-Rollen-Session durchgespielt. Modell zuerst geklaert: `missions.write`/`vms.write`/`deploy.run` sind **global**, es gibt keine Pro-Benutzer-Eigentuemerschaft (Kleinteam-LAN); `mission_creator`/`vm_creator` sind reine Herkunftsstempel, keine Autorisierung. Der eine echte Objektgrenzen-Guard (Cross-Mission) haelt: (a) privilegierte POSTs mit gueltigem eigenem CSRF-Token gegen `os.php`/`vlans.php`/`users.php`/`credentials.php`/`settings.php` -> alle **403**, DB-Gegenprobe zeigt null Schreibvorgaenge; (b) VM aus Mission A per POST mit fremder `mission_id` B -> `vm_edit.php:27`-Guard greift auch auf POST (steht vor dem Dispatch), VM bleibt unveraendert; (c) Fremd-VM-ID aus Mission A in `bulk_delete` fuer Mission B geschmuggelt -> ueberlebt, weil jede Repo-Operation `WHERE id = ? AND mission_id = ?` skopiert (Verteidigung in der Tiefe); (d) CSRF auf geschuetztem Endpunkt: eigenes Token 302 + Schreibvorgang, ohne/fremdes Token **400** + nichts geschrieben. `login`/`logout`/`session_ping` sind dokumentiert CSRF-exempt. Kein Info-Leak in den Ablehnungen. | Erledigt |

| 2026-07-12 | 1.5 | Doku-Bug (2x, gefixt) | Installations-Anleitung als Vertrag durchgespielt: (1) Zeile 96 behauptete, `setup.sh` erzeuge Secrets "wenn Standardwerte noch vorhanden sind" - tatsaechlich regeneriert es nur, wenn `.env` **fehlt**; eine bestehende schwache `.env` faengt EnvBoot beim Start ab, nicht das Skript. (2) `restore.sh <backup-archiv>` (Zeile 208) ist irrefuehrend: das Skript erwartet das Backup-**Verzeichnis** (`Docker/backups/<stamp>` mit `db.sql`, optional `.env`/`ssl.tgz`), keine Archivdatei; die eigene Usage-Meldung des Skripts sagt es richtig. Beide wuerden einen Kunden zum Improvisieren zwingen. | Gefixt (`d5a6c7f`). Alles Uebrige (Skripte, `.env`-Keys, entfernte Alt-Endpunkte, CLI-Flags, verlinkte Docs, Mount-Pfade, `health.php`, `migrate --status`) stimmt mit der Realitaet ueberein. |

| 2026-07-12 | 3.0/3.1 | Erledigt | Playwright-Dev-E2E-Schicht gebaut und lauffaehig (ADR-0028, `85a7b21`). `tests/e2e/` am Repo-Root, nie im Auslieferungsartefakt, `node_modules` git-ignoriert, nicht in CI. Auth-Setup seedet den `user`-Account und cached `storageState` je Rolle; DB-Assertions ueber `docker exec` (MySQL-Port bewusst nicht published). Gesundheitsmatrix 3.1 gruen: 58 Tests (14 Seiten x light/dark x admin/user) pruefen Ladezustand/Verweigerung, PHP-Fehlerfreiheit, Konsolen-/CSP-Fehler, Air-Gap (jeder Off-Host-Request bricht ab) und rohe i18n-Keys. Zwei Erkenntnisse beim Gruenmachen, beide im Test: PHP ueber stdin braucht das `<?php`-Tag; `integrations.php` ist korrekt eine benutzer-sichtbare Status-Seite (`portal_require_user` + per-Feature-`can()`, keine Secrets gerendert), kein Admin-Leak, Inventar korrigiert. | Erledigt (Fundament); DE/EN-Umschaltung, Anonym-Redirect, Screenshots, RBAC-Laufzeitmatrix (3.2), CRUD-Round-Trips (3.3), Feld-Round-Trip (3.4), Axe (3.6) noch offen. |

| 2026-07-12 | 3.4 | Erledigt | Feld-Round-Trip ueber die Escaping-Matrix gebaut und gruen (`field-roundtrip.spec.js`, `a0cc8af`, 8 Tests). Traeger `mission_name` (drei Render-Kontexte: Listenzelle, Detail-Ueberschrift, Rename-Input; akzeptiert Sonderzeichen, nur keine Leerzeichen). Sieben feindliche Werte (Umlaute/ß, HTML-Metazeichen, `<script>`, Attribut-Breakout, SQL, Emoji, YAML) je dreifach geprueft: DB byte-exakt und roh (keine Entities in der Speicherung), jeder Render-Kontext zeigt den Wert verbatim, und ein Dialog-Handler laesst den Test bei jeder Script-Ausfuehrung fehlschlagen. Kein XSS, kein Doppel-Escape, kein Byte-Drift. Nebenbefund (kein Defekt): `vm_name` ist charset-beschraenkt (NetBIOS) und weist die Matrix ab; `vm_hostname` faellt bei leer auf `vm_name` zurueck. Beide dokumentierte Verteidigungen. | Erledigt |
| 2026-07-12 | 3.3 | Erledigt | CRUD-Round-Trips fuer Mission gruen (`crud-mission.spec.js`, `3e9fb1f`, 3 Tests). Anlegen erscheint in Liste, frischem GET und DB; Bearbeiten uebersteht Reload und laesst das Nachbarfeld (domain bei Rename) unangetastet; Loeschdialog beweist beide Zweige: Abbrechen laesst die Zeile in der DB (DB-Beweis), Bestaetigen entfernt sie aus DB und Liste. Muster steht fuer die uebrigen Entitaeten (VM/Katalog/Credential/User noch offen). | Erledigt |
| 2026-07-12 | 3.3 | P2 (UX/Regel-Drift) | **Kein** Loeschdialog nennt sein Ziel: alle nutzen ein Demonstrativpronomen (`Diese Mission`, `Diese VM`, `Dieses Betriebssystem`, `Diese Zugangsdaten`), obwohl `.claude/rules/portal.md` fuer Zeilenaktionen ausdruecklich `:name` verlangt ("so a row action cannot be confirmed for the wrong row"). Systematisch ueber missions/vms/os/vlans/credentials, nicht ein Einzelfall. Kein Sicherheitsfehler: der Confirm submittet das Formular der geklickten Zeile, es wird also immer die richtige geloescht; das Risiko ist rein UX (bei mehreren aehnlichen Zeilen bestaetigt man blind). Entscheidung noetig: Regel lockern (Demonstrativ ist ok) ODER `:name` in die Confirm-Texte interpolieren. `os.php` nutzt bereits `:count`, also ist die Interpolation im Confirm technisch schon da. | **Gefixt** (`253ab05`): Entscheidung getroffen zugunsten von `:name` (die Regel steht begruendet, der Mechanismus war da, und `.modal-msg` hat `overflow-wrap: anywhere` genau *weil* `:name` Benutzereingabe ist: das CSS wartete schon auf einen Namen, den die Texte nie lieferten). Alle Zeilenaktionen interpolieren jetzt: missions (4 Wortlaute), VMs (Loeschen + MECM-Reset, beide Seiten), credentials, os, vlans, IP-Freigabe, Deploy-Abbruch (mit Systemjob-Fallback). DE+EN. Drei Prompts bleiben bewusst generisch, Grund dokumentiert: Bulk-Aktionen benennen eine Auswahl, HTTPS-/Token-Schalter sind global, und `integrations.reassign_confirm` liest sein Ziel aus einem **editierbaren** Feld, ein serverseitig gerenderter Name wuerde dort luegen. `PortalConfirmNamingContractTest` macht es zum Vertrag (jeder Confirm muss als ROW_ACTIONS oder NO_TARGET klassifiziert sein, sonst bricht der Build); durch Kaputtmachen in beide Richtungen verifiziert. |
| 2026-07-12 | 3.6 | **P2 (Accessibility, 3 Verstoesse)** | Axe-Scan (WCAG 2.1/2.2 A+AA) ueber alle Seiten in beiden Themes: 24 von 28 Kombinationen sauber, drei echte Defekte. (1) **critical**: der Rollen-Select in jeder `users.php`-Zeile war ein blankes `<select>` ohne zugaenglichen Namen; ein Screenreader meldete "Auswahlfeld", ohne zu sagen, *wessen* Rolle er aendert, auf einer Seite, wo alle Zeilen gleich aussehen. (2) **serious** (WCAG 1.4.1): Links in Warnhinweisen waren nur an der Farbe erkennbar; ein Alert faerbt seinen ganzen Text, der Link hat also dieselbe Farbe wie die Prosa daneben. Am schlimmsten genau dort, wo es kaputt war: der Link im Fehler-Alert ist der Ausweg aus dem Fehler. (3) **serious**: die scrollbaren Tabellen-Container (`.table-wrap`, `overflow: auto` + `max-height`) waren per Tastatur nicht erreichbar; wer keine Maus nutzt, kam an ueberstehenden Inhalt nicht heran. | Gefixt (`5e7051c`): `aria-label` mit Kontoname am Rollen-Select; `.alert a` unterstrichen; `tabindex="0"` auf allen 25 `.table-wrap`. Scan danach 30/30 gruen und als `accessibility.spec.js` dauerhaft im Lauf. |

| 2026-07-12 | 3.3 | **P2 (i18n-Leck)** | Loeschen eines Credentials, das ein aktiver Deploy-Job haelt, zeigte dem deutschen Operator die rohe englische Exception: **"Credential is used by an active deploy job."**. `portal_error_message()` mappt nur vier Deploy-Enqueue-Meldungen; diese fehlte und fiel auf `return $message` durch. Der Kommentar der Map nennt selbst das Kriterium ("conditions an operator can hit without a crafted POST" werden lokalisiert) - und dieser Fall ist genau das: `credentials.php` rendert Loeschen fuer *jedes* Credential, auch fuer eines in Benutzung, der Guard ist also einen Klick entfernt. Verstoesst gegen `portal.md` ("Avoid raw generic `$exception->getMessage()` in user-facing output"). Live reproduziert. Breit geprueft: von allen RuntimeException-Texten der Repos ist dies der **einzige** operator-erreichbare ungemappte; die uebrigen (`Mission not found.`, `Credential not found.`) sind crafted-only und fallen laut Design bewusst durch. | Gefixt (`d024a2e`): Mapping-Eintrag + DE/EN-Keys; Map von `$deployEnqueueErrors` in `$operatorReachableErrors` umbenannt, weil sie jetzt einen Eintrag ausserhalb des Deploy-Pfads traegt. Diagnose-Text im Log bleibt englisch. `PortalErrorMessageTest` pinnt jeden erreichbaren Guard als lokalisiert und den crafted-only als durchfallend; durch Kaputtmachen verifiziert (Eintrag entfernt -> Test rot). E2E-Negativfall `crud-negative.spec.js` beweist zusaetzlich: kein 500er, Zeile ueberlebt, Flash ist ein Satz. |

| 2026-07-12 | SSoT | **P2 (Doku-Drift, ganze Klasse)** | Systematischer Nachschlag zum bereits gefixten Passwort-Minimum (`11ebbf3`): dieselbe Fehlerklasse hatte **Geschwister**. Zwei Zahlen hatten gar keine Konstante: die ESXi-Intervall-Grenze **168** lag dreifach (POST-Validierung, HTML-`max`, Fehlertext), und die Login-Sperre stand als **rohe SQL-Zahl** (`INTERVAL 15 MINUTE`) *und* im Hilfetext. Dazu neun Texte, die eine existierende Konstante als Ziffer ausschrieben: HSTS-Fenster (am gefaehrlichsten, weil Browser es unwiderruflich pinnen), Retire-Schwelle samt Bereich, MECM-Probe-Intervall, Client-Phase-Timeout, ESXi-Default und -Max, beide Worker-Takte, Login-Sperre, Import-Vorschau-TTL. Der Fehler ist konstruktionsbedingt leise: der Code laeuft weiter, nur der Text luegt, und kein Test merkt es. Bewusst **nicht** angefasst: NetBIOS-15 (Microsoft-Invariante), 255 (VARCHAR-Breite), MECM-Sync-Takt (auf dem SCCM-Server gesetzt). | Gefixt (`44ab680`): fuenf neue Konstanten, alle Texte interpolieren (DE+EN). End-to-end bewiesen statt per Sichtpruefung: HSTS auf 90 Tage -> Hinweis sagt 90 Tage; Sperre auf 42 Minuten -> Hilfe sagt 42. Neuer Drift-Check `scripts/check-bounds-sync.php` (ADR-0016-Familie, in CI): matcht auf Wert **und** Einheit (600 s sind auch 10 min, aber "10 Prozent" im Backup-Hinweis ist etwas anderes; ein Check, der Fehlalarm schlaegt, wird ignoriert), fuehrt Ausnahmen mit Begruendung, und eine veraltete Ausnahme schlaegt ebenfalls fehl - was beim ersten Lauf prompt einen falschen Key-Namen in seiner eigenen Liste fand. Durch Kaputtmachen verifiziert. |

| 2026-07-12 | 4.2 | **P2 (i18n-Leck)** | Der VM-Editor **erkennt Bearbeitungskonflikte bereits** (optimistisches Sperren: verstecktes `updated_at`-Feld, `FOR UPDATE`-Lock, `updated_at`-Vergleich in `repo_save_vm`). Die Plan-Frage "Last-Write oder Konflikt erkennen" war also veraltet, die sichere Variante war gebaut. Entscheidung (Nutzer, 2026-07-12): **Konflikterkennung behalten** (kein stiller Datenverlust bei paralleler Bearbeitung). Zwei Befunde am bestehenden Pfad: die Ablehnung "VM was changed by another user" war eine **rohe englische Exception** (dieselbe Leck-Klasse wie beim Credential-in-use, `d024a2e`, genauso erreichbar), und der Pfad hatte **keinen Test**. | Gefixt (`1f6635b`): Meldung lokalisiert (`vm_edit.err_conflict`, DE+EN; Diagnose bleibt englisch); `VmEditConflictTest` pinnt den Pfad gegen die echte DB (stale abgewiesen ohne Clobber, aktuell geht durch, leer = bewusster Opt-out fuer Import/Legacy); `PortalErrorMessageTest` deckt die Meldung mit. Live bewiesen: deutscher Operator sieht den lokalisierten Satz. |
| 2026-07-12 | 2.5 | Erledigt | Infection (Mutation-Testing) aufgenommen (`b8a18ab`), Dev-Host-only wie Playwright (ADR-0028): nicht vendored, nicht in CI, braucht Coverage-Treiber, den das Air-Gap-Image bewusst nicht hat. `infection.json5.dist` zielt auf `lib` (ohne View-Glue), laeuft gegen unit+static; `docs/QA.md` nennt das Rezept und die Dateien mit hoechstem Nutzen (validate, ansible_yaml, permissions, password_policy, catalog) fuers gezielte `--filter`. Baseline-Lauf bewusst auf einen vernetzten Dev-Host verschoben (hier kein Netz, kein pcov/xdebug - genau das, was Infection braucht). | Erledigt (Faehigkeit eingerichtet) |
| 2026-07-12 | 4.7 | OK | k6-Lasttest gefahren (`tests/load/portal-read.js`, Dev-Host-only). Session-basierter Lesepfad (Login -> Dashboard/Listen/health-Poll), Rampe auf 30 gleichzeitige VUs = 3x realistischer LAN-Peak, 1 Minute: **0 % Fehler ueber 2469 Requests**, alle p95-Schwellen gehalten (dashboard 601 ms, health 121 ms, missions 773 ms, vms 463 ms). `missions.php` ist die langsamste Seite und liegt am naechsten an ihrer 800-ms-Schwelle: erster Anlaufpunkt, falls Listen-Latenz je regressiert. Kein Defekt, LAN-Kapazitaet mit Reserve bestaetigt. Schreibpfade bewusst ausserhalb (ein Lasttest darf keine echten Zeilen anfassen). | **Zurueckgezogen** (2026-07-13): die Zahlen waren ein Artefakt des Tests selbst, siehe Befund 4.7 vom 2026-07-13. |

### Nachlauf 2026-07-13: Best-Practice-Abgleich (OWASP, PHP-Handbuch, k6, Playwright) und die PowerShell-Codebase

Anlass: Recherche gegen mehrere externe Quellen (OWASP Session-, CSRF-, Password-Storage- und CSP-Cheat-Sheets, PHP-Handbuch zur Session-Sicherheit, k6- und Playwright-Doku) und die Beobachtung, dass `Powershell-MECM/` in Anhang D als Pruefobjekt gar nicht vorkommt.

| Datum | Phase | Schwere | Befund | Status |
|---|---|---|---|---|
| 2026-07-13 | PS | **P0 (Secret im Repo)** | `functions.psm1` im Repo-Wurzelverzeichnis, git-tracked, enthaelt ein **Klartext-MySQL-Passwort** (`testkonto`), einen festen Host und `SslMode=none`. Der Rest der Datei ist toter WinForms-Code: `Invoke-MySQL` nimmt rohe Query-Strings, `Connect-MySQL` vergleicht eine `Password`-Property, die es nicht gibt, und `Get-VMs` liest eine Tabelle `vms`, die im Schema `deploy_vms` heisst. Von nirgends referenziert (Grep ueber ps1/php/md/cs). Die Log-Hygiene-Pruefung (4.6) hatte nach Seed-Secrets in **Logs** gesucht, nie im eigenen Quellcode. | Gefixt: Datei geloescht. **Das Passwort bleibt kompromittiert**: die Datei stammt aus dem Initial-Commit (`4fd9379`), `git rm` raeumt die Historie nicht. Rotation des DB-Kontos ist als Go-live-Schritt notiert (`docs/operations/go-live.md`). |
| 2026-07-13 | PS | **P0 (SSoT ueber die Sprachgrenze)** | Die MAC-Kanonisierung existierte **dreimal**: `virtusphere_normalize_mac()` (PHP), `ConvertTo-VsNormalizedMac` (MECM) und `ConvertTo-VsNormalizedMacClient` (Client, byte-gleiche Kopie). Kein gemeinsamer Test, kein Drift-Check; der Kommentar behauptete lediglich "passend zum serverseitigen". Genau diese Naht war schon einmal der P1-Bruch aus Befund 2.2 (VM fuer MECM unauffindbar, ohne Fehlermeldung), und der Fix hatte nur die PHP-Seite angefasst. | Gefixt: `Docker/WebAPI/tests/fixtures/mac-vectors.json` als gemeinsame Wahrheit (20 Vektoren). PHPUnit prueft PHP dagegen, Pester beide PS-Zwillinge, ein weiterer Pester-Test pinnt, dass die Zwillinge textgleich bleiben. Zusammenfuehren geht nicht: die drei laufen auf drei Maschinen. Durch Kaputtmachen verifiziert (Client-Zwilling auf Bindestrich-Notation umgestellt -> 12 Tests rot, Wiederherstellung -> gruen). |
| 2026-07-13 | PS | **P1 (Diagnose-Luecke)** | Die PS-Skripte warfen genau die JSON-Envelope weg, fuer die `199163e` (Befund 4.3) gebaut wurde. `Invoke-RestMethod` wirft in Windows PowerShell 5.1 bei 4xx/5xx und **verwirft dabei den Body**; alle Skripte loggten `$_.Exception.Message`, also `(400) Bad Request`, nie den Grund (`{"error":"Invalid data format"}`). Die Server-Seite war fuer Diagnose gehaertet, die Client-Seite konnte sie nicht anzeigen. Beide Enden des Wire-Contracts wurden nie gegeneinander geprueft, weil `MachineApiWireTest` nur die PHP-Seite kennt. | Gefixt: `Get-VsErrorDetail`/`Get-VsErrorStatusCode` (in beiden Common-Modulen) lesen den Body ueber `GetResponseStream()`, haengen die Envelope an die Statuszeile und kuerzen eine Nicht-JSON-Antwort (nginx-Fehlerseite) lesbar an, statt sie zu verschlucken. Alle Fehlerpfade der Sync-Skripte, der Client-Kette und des Installers ziehen darueber. Pester deckt Envelope, Netzfehler ohne Response und die HTML-Antwort ab. |
| 2026-07-13 | PS | **P1 (HTTPS-Blocker)** | `http://` war an fuenf Stellen fest verdrahtet (`Invoke-VsApi`, `Resolve-VsApi`, `Send-VsPhase`, `client_getinfo`, Installer-Health-Probe). Der Kommentar behauptete "die einzige Schema-Stelle der Server-Skripte" und uebersah die komplette Client-Kette mit ihrer eigenen Kopie. Phase 4.5 fuehrt den HTTPS-Flow als bewiesen, ohne dass die PowerShell-Seite je Teil der Pruefung war: wer HTTP abschaltet, killt die MECM-Integration und die PXE-Client-Kette, und es faellt erst beim naechsten Deploy auf. | Gefixt: `Scheme` als Registry-Wert (Default `http`), eine Schema-Stelle je Seite (`Get-VsApiBaseUrl` / `Get-VsApiUrl`), `-Scheme`-Parameter im Installer. Selbstsignierte Zertifikate brauchen ein bewusstes Opt-in (`$VsAllowSelfSignedTls`), weil PS 5.1 kein `-SkipCertificateCheck` kennt; eine dauerhaft blinde TLS-Pruefung waere schlechter als ehrliches HTTP. ADR-0029. |
| 2026-07-13 | PS | P2 (Token-Lesefenster) | Der Installer schrieb den Klartext-ReportToken in die Registry und haertete die ACL **danach**. `HKLM:\SOFTWARE` vererbt standardmaessig `Users:Read`: dazwischen lag ein Fenster, in dem jeder angemeldete Benutzer den Token lesen konnte. | Gefixt: Reihenfolge umgedreht (Schluessel anlegen -> ACL setzen -> Werte schreiben). |
| 2026-07-13 | PS | P2 (stille Fehlkonfiguration) | `Convert-SubnetMaskToPrefix` zaehlte nur gesetzte Bits: `255.0.255.0` (nicht zusammenhaengend, 16 Bits) wurde stillschweigend zu `/16`, und `"999"` passierte als Praefixlaenge. Der Client haette das falsche Netz bekommen, ohne Fehler. Ausserdem liess `if (-not $prefix)` ein gueltiges `/0` faelschlich in den Fehlerzweig laufen. | Gefixt: `Convert-VsSubnetMaskToPrefix` validiert Zusammenhang und Oktett-/Praefixgrenzen, liegt im Client-Common (im Loop-Skript waere sie nicht testbar), Aufrufer prueft `$null -eq`. 20 Pester-Faelle. |
| 2026-07-13 | PS | OK | PowerShell-Pruefschicht aufgebaut (ADR-0029): PSScriptAnalyzer + Pester ueber `scripts/run-pester.ps1`, **in CI** (pwsh ist auf ubuntu-latest vorinstalliert, Module aus der PSGallery, nicht vendored). `Set-StrictMode -Version 1.0` in allen Skripten, bewusst nicht `Latest` (ab 2.0 wirft PS auch bei fehlender *Property*, und die Skripte lesen JSON mit legitim fehlenden Feldern). Reine Logik aus den Endlosschleifen in die Common-Module gezogen, damit sie ueberhaupt aufrufbar ist. 80 Pester-Tests gruen, Analyzer ohne Befund. `PSAvoidUsingEmptyCatchBlock` bleibt **aktiv** (stiller Fehler ist die Fehlerklasse dieser Skripte): die 20 leeren catch-Bloecke schreiben jetzt eine `Write-Debug`-Zeile. Nur drei Regeln ausgeschlossen, jede mit Begruendung. | Erledigt |
| 2026-07-13 | 4.7 | **P1 (Messfehler)** | Die k6-Baseline vom 2026-07-12 war **unbrauchbar** und ihre Schlussfolgerung falsch. Zwei Fehler, beide mit gruenem Lauf: (1) `setup()` loggte **einmal** ein und gab allen 30 VUs **dieselbe** `PHPSESSID`; PHPs File-Session-Handler haelt ein exklusives Lock auf die Session-Datei ueber den ganzen Request (kein First-Party-Code ruft `session_write_close()`), die 30 VUs standen also hintereinander an einem Lock - gemessen wurde ueberwiegend Wartezeit aufeinander, nicht Portal-Arbeit. (2) Der vms-Check war `status < 400`; das Portal beantwortet einen unangemeldeten Request mit einem Redirect auf das Login-Formular, was ebenfalls 200 ist und dem k6 folgt: der Test haette den Login-Screen messen und trotzdem "gruen" melden koennen. Die Aussage "missions.php ist die langsamste Seite, erster Anlaufpunkt bei Latenz-Regression" beschrieb den Test, nicht das Portal. | Gefixt: jede VU meldet sich **selbst** an (Modulscope ist in k6 pro VU), jeder Check beweist den **angemeldeten** Zustand (Logout-Formular im Layout), und die Session liegt pro Iteration im Cookie-Jar statt in einem Header - k6 leert den Jar zwischen Iterationen, und einen handgesetzten Header traegt es nicht ueber einen Redirect (genau `vms.php` antwortet mit 302, der Header-Weg lief dort still anonym). **Neue Baseline (2026-07-13): 0 % Fehler ueber 3530 Requests, alle Checks gruen, missions 54 ms statt 773 ms, dashboard 61 ms, vms 50 ms, health 27 ms.** Die alte Zahl bestand zu ~93 % aus Session-Lock-Contention. |
| 2026-07-13 | 4.6 | **P1 (Session-Ablauf)** | `session.gc_maxlifetime` stand auf dem PHP-Default **1440 s (24 Minuten)**, waehrend die Settings-Karte eine Sitzungsdauer bis **480 Minuten** verspricht. Der Garbage Collector durfte also die Session-Datei eines Operators wegraeumen, waehrend das Portal die Sitzung noch stundenlang fuer gueltig hielt - und weil der GC probabilistisch von **fremden** Requests ausgeloest wird, war der Rauswurf nicht reproduzierbar und sah wie ein sporadischer Fehler aus. Dieselbe leise Fehlerklasse, die `check-bounds-sync` fuer Texte bewacht: der Code laeuft, nur die Zusage stimmt nicht. Kein Test der Kampagne konnte das finden, weil keiner 25 Minuten idlet. | Gefixt: `gc_maxlifetime = 28800`. `tests/Static/SessionHardeningContractTest.php` pinnt die Beziehung zur Konstanten (`VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX`), weil eine `.ini` keine Konstante interpolieren kann; durch Kaputtmachen verifiziert. Live bestaetigt nach Image-Rebuild (die INI wird beim Build kopiert, ein Container-Restart genuegt nicht). |
| 2026-07-13 | 4.6 | **P1 (Session-Fixation)** | `session.use_strict_mode` war **aus** (PHP-Default). PHP akzeptierte damit eine Session-ID, die es nie ausgegeben hat: ein Angreifer kann dem Opfer eine ID unterschieben. Das PHP-Handbuch nennt die Einstellung "mandatory". Die vorhandene Verteidigung (`session_regenerate_id(true)` beim Login) ist die zweite Schicht, nicht die erste, und die erste fehlte. Ausserdem liefen `cookie_httponly`, `cookie_samesite` und `use_only_cookies` auf INI-Ebene ungesetzt (der Portal-Bootstrap setzt sie zwar per `session_set_cookie_params()`, aber ein Einstiegspunkt ohne diesen Bootstrap faellt auf die unsicheren Defaults zurueck). | Gefixt: `Docker/php/conf.d/zz-virtusphere.ini` haertet `use_strict_mode`, `cookie_httponly`, `cookie_samesite=Strict`, `use_only_cookies`, `use_trans_sid=0`, `cache_limiter=nocache`. Alle sechs live im Container verifiziert und per Contract-Test gepinnt. |
| 2026-07-13 | 4.6 | **P1 (spoofbarer TLS-Beweis)** | `virtusphere_is_request_secure()` glaubte dem **Client-Header** `X-Forwarded-Proto: https`. In dieser Topologie steht kein Reverse-Proxy vor nginx, niemand Legitimes setzt den Header - aber jeder darf ihn senden. Drei Sicherheitsentscheidungen haengen an der Funktion: das `Secure`-Flag des Session-Cookies, HSTS und der HTTPS-Redirect. Ein Client (oder MITM im LAN) mit diesem Header wurde **nie umgeleitet** und bekam ein Cookie ohne `Secure`, auf einer Verbindung, die die ganze Zeit reines HTTP war. | Gefixt: der Header-Zweig ist raus; einziger Beleg bleibt `fastcgi_param HTTPS on` aus dem generierten TLS-Block (plus `SERVER_PORT 443`, das nginx setzt, nicht der Client). Kommentar haelt fest, dass ein spaeterer Proxy den Header in nginx **ueberschreiben** muss, bevor er hier wieder geglaubt werden darf. Live bewiesen: mit gesetztem Spoof-Header kommt ueber HTTP kein `Secure`-Cookie mehr. |
| 2026-07-13 | 2.2 | **P1 (bcrypt-Trunkierung)** | `password_hash(..., PASSWORD_DEFAULT)` ist bcrypt, und bcrypt schneidet die Eingabe **nach 72 Bytes** stillschweigend ab: zwei verschiedene lange Passwoerter verifizieren dann gegen denselben Hash, ohne dass es jemand erfaehrt. Die Policy prueft nur ein Minimum, und zwar in `mb_strlen`-**Zeichen** - 40 Umlaute sind 80 **Bytes**. Erreichbar, ohne dass jemand etwas Absonderliches tun muss (OWASP Password-Storage-Cheat-Sheet: das Limit ist zu erzwingen, nicht zu entdecken). Ausserdem fehlte der `password_needs_rehash()`-Pfad beim Login (Work-Factor-Upgrades). | Gefixt: `VIRTUSPHERE_PASSWORD_MAX_BYTES = 72`, `password_policy_error()` prueft das Minimum in Zeichen und das Maximum in Bytes (die Meldung sagt das auch: "Umlaute und Emojis zaehlen mehrfach", DE+EN). `auth_rehash_password_if_needed()` zieht veraltete Hashes beim Login nach - der einzige Moment, in dem der Klartext existiert -, best effort, ein Fehlschlag darf einen gueltigen Login nie in eine Ablehnung verwandeln. |
| 2026-07-13 | SSoT | P2 (Drift-Klasse, Nachschlag) | Der Bounds-SSoT-Check deckt nur die **Sprachkataloge** ab, nicht englische Strings in `lib/`. `auth.php` sagte viermal "15": als rohes `INTERVAL 15 MINUTE` in zwei Queries, als `retry_after_seconds => 900` und als Text in zwei **operator-sichtbaren** Audit-Zeilen (`logs.php`). Zeile 207 war fertig verdriftet: der Lock selbst las bereits `VIRTUSPHERE_LOGIN_LOCKOUT_MINUTES` (aus dem `44ab680`-Fix), der Audit-Text daneben hardcodete "15 minutes" - eine Aenderung der Konstanten haette die DB 30 Minuten sperren lassen, waehrend das Log 15 behauptet. Dazu: Zaehlfenster und Sperrdauer sind zwei verschiedene Konzepte, die sich zufaellig eine Zahl teilten, und das Fenster hatte gar keine Konstante. | Gefixt: `VIRTUSPHERE_LOGIN_FAILURE_WINDOW_MINUTES` neu, alle vier Stellen interpolieren, die SQL-Fenster sind gebundene Parameter. |
| 2026-07-13 | 4.6 | P2 (Kleinhaertung) | Vier Punkte aus dem OWASP-Abgleich, jeder fuer sich klein: (1) das CSRF-Token ueberlebte den Login (Rotation beim Privilegienwechsel ist eine Zeile); (2) `logout()` sendete kein `Clear-Site-Data`, der Back-Button konnte die letzte geschuetzte Seite aus dem Cache malen; (3) `require_admin()` antwortete mit einem nackten `exit('Forbidden')` - unlokalisiert, ohne Layout **und ohne Audit-Zeile**, obwohl `portal_forbid()` genau dafuer existiert und jeder andere Guard es nutzt; (4) CSP hatte `base-uri 'self'`, obwohl das Portal nirgends ein `<base>` rendert. | Gefixt: Token rotiert mit der Session-ID, `Clear-Site-Data` beim Logout (live per POST bewiesen), `require_admin()` geht durch `portal_forbid()`, `base-uri 'none'`. |
| 2026-07-13 | 3.0 | P2 (tote Konfiguration) | `trace: 'on-first-retry'` bei `retries: 0`: der Trace konnte nie geschrieben werden. Das einzige Artefakt, das nach einem roten Lauf etwas wert ist, wurde still nie erzeugt - waehrend ADR-0028 die Einstellung als kodifizierte Entscheidung fuehrt. | Gefixt: `trace: 'retain-on-failure'`. |
| 2026-07-13 | 4.6 | **P1 (500 + Audit-Flood aus URL)** | Ein Array-Parameter (`?sort[]=x`) liess **jede** Listenseite und **jeden** Maschinen-Endpunkt in einen 500er kippen: `(string) ($_GET['x'] ?? '')` wirft "Array to string conversion", der globale Error-Handler macht daraus einen 500 **plus eine `deploy_logs`-Zeile** (Kategorie system). Bei `mecm-api.php` sitzt der Cast **vor** dem IP-Gate: jeder Host konnte damit **unauthentifiziert** eine Audit-Zeile pro Request schreiben - genau die Log-Flood-Klasse, die der Auth-Kanal sonst deckelt. Live bewiesen: 8/8 Listenseiten und 5 Maschinen-Endpunkte -> 500; 5 unauth Requests -> 5 system-Zeilen. Betrifft 121 Cast-Stellen in 23 Dateien. Auch der CSRF-Pfad (`hash_equals` mit Array) und POST-Felder waren betroffen. | Gefixt (`8ec972b`): Grenz-Accessor `lib/request.php` (`request_string`/`request_int`/`request_trimmed`) liest nur Skalare, sonst Default - Verhalten fuer echte Werte identisch, nur der Array-Fall geschlossen. 110 Stellen per reviewtem Skript migriert, Rest von Hand, `csrf_verify` nimmt `mixed`, Maschinen-API-Casts hinter das IP-Gate gezogen. `RequestInputContractTest` verbietet rohe Casts dauerhaft (durch Kaputtmachen verifiziert). Live-Sweep nach dem Fix: alle Seiten normaler Status, unauth Audit-Delta = 0. **Scharfer PowerShell-Client-Test gegen die laufende API**: `getDeviceInfos` 400-Envelope erscheint jetzt als "WebApp: Invalid MAC address" via `Get-VsErrorDetail` (vorher unsichtbar), `action[]=x` -> 403 statt 500. Damit sind beide Enden des Wire-Contracts erstmals gegeneinander bewiesen (Befund 4.3 hatte nur die PHP-Seite). |
| 2026-07-13 | PS | Entschieden (belassen) | Zweiter Nachzuegler: eine manuell in SCCM angelegte Collection mit Bindestrich in der "Version" (`Firefox-1.0-beta`) geht am Autoimporter-Guard (`Read-VsPackageConfig`) vorbei direkt ueber den Packages-Sync in den Katalog, wo die Trennung am letzten Bindestrich sie fehlgruppiert. **Entscheidung (Nutzer, 2026-07-13): so belassen.** Begruendung: kein Schaden, nur eine Gruppierungs-Kosmetik (kein Datenverlust, kein Absturz); winzige Wahrscheinlichkeit (Admin legt von Hand eine Collection mit Bindestrich-Version in genau den VirtuSphere-Ordner); und der Server besitzt die Split-Semantik (`lib/repo/catalog.php`), ein PS-seitiger Guard wuerde eine serverseitige Regel duplizieren. Die Regel selbst ist ausfuehrlich in der Hilfe erklaert (`help.packages_p1b`, DE+EN): version ohne Bindestrich, ProjectName darf einen haben. Erster ACL-Nachzuegler (SYSTEM-beschreibbarer `files\`-Ordner) erledigt (`fd26ef9`, Go-live-Abnahmepunkt). | Erledigt |
| 2026-07-13 | 1.4 | OK (Hilfe-Klarheit) | MAC-Notations-Thematik in der Hilfe konkretisiert (`b05b728`): `help.naming_p4` nannte nur abstrakt "jeder ueblichen Schreibweise"; jetzt die drei Notationen mit Beispiel (Doppelpunkt/ESXi, Bindestrich/Windows-ipconfig, Cisco-Punkt) plus die betriebliche Aussage, dass der Device-Sync die VM in MECM unabhaengig von der Schreibweise findet (genau der Befund-2.2-Fall). Der separate Paketnamen-Bindestrich-Split war bereits in `help.packages_p1b` gut erklaert und blieb. Live gerendert, DE/EN-Paritaet gruen. | Erledigt |
| 2026-07-13 | - | Bewusst nicht geaendert | (1) **Argon2id statt bcrypt**: libsodium ist ohnehin harte Abhaengigkeit, und OWASP bevorzugt Argon2id; das 72-Byte-Problem gaebe es dort nicht. Eine Algorithmus-Migration beruehrt jeden Bestands-Hash und gehoert in eine eigene, geplante Aenderung, nicht in einen Haertungs-Nachlauf. (2) **`session.name` von `PHPSESSID` weg**: OWASP empfiehlt einen generischen Namen; der Nutzen ist reine Verschleierung, die Kosten (k6-Skript, E2E-`storageState`, laufende Sessions) sind real. (3) **`__Host-`-Cookie-Prefix**: braucht `Secure` und gehoert damit auf die HTTPS-Checkliste (4.5), nicht ins HTTP-LAN von heute. (4) **Firefox in der E2E-Matrix**: die Suite ist Chromium-only; `<dialog>`, `requestSubmit` und `overflow-wrap: anywhere` sind genau die Dinge, die Firefox anders rendert. Billig nachzuruesten, aber eine bewusste Entscheidung - bis dahin ist es eine dokumentierte Grenze. | Notiert |

## Offene Entscheidungen

1. ~~ADR für Playwright-Dev-E2E (3.0)~~ erledigt: ADR-0028 geschrieben und angenommen.
2. ~~CSV-Formel-Injection (2.4)~~ erledigt: war bereits per `portal_csv_guard()` präfixend gelöst, jetzt getestet.
3. ~~Infection-Mutationstests (2.5)~~ entschieden (2026-07-12): aufgenommen als Dev-Host-Fähigkeit (`b8a18ab`), Baseline-Lauf auf vernetztem Host offen.
4. ~~k6-Lasttest (4.7)~~ erledigt (2026-07-12): gefahren, 0 % Fehler bei 30 VUs, alle Schwellen gehalten (`tests/load/portal-read.js`).
5. ~~`Validator::hostname()` (2.2)~~ entschieden (2026-07-12): verschärft (`6dbb8e9`); Bestands-VMs mit solchen Namen müssen beim nächsten Speichern korrigiert werden.
6. ~~Confirm-Dialoge nennen ihr Ziel (`:name`, 3.3)~~ entschieden (2026-07-12): interpoliert statt Regel gelockert (`253ab05`), per Contract-Test erzwungen.
7. ~~Parallele Bearbeitung derselben VM aus zwei Sessions (4.2)~~ entschieden (2026-07-12): Konflikterkennung behalten (war bereits gebaut), i18n-Leck gefixt und Pfad gepinnt (`1f6635b`).
