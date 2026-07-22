# Quality Gates: Bedeutung, Voraussetzungen, Interpretation

Rollentrennung (AP9): `scripts/check.ps1` ist die **ausführbare** SSoT der Gates; diese Seite erklärt, was jedes Gate beweist, was es voraussetzt und wie ein rotes Ergebnis zu lesen ist. Bedienung und lokale Fehlersuche stehen in `docs/QA.md`; die Entscheidungen hinter Runner, Lanes und Skip-Politik in ADR-0031, ADR-0015 (Amendment) und ADR-0028 (Revision). Diese Seite nennt bewusst keine Testzahlen, Laufzeiten oder Baselines: solche Werte gehören in die erzeugten QA-Artefakte (`-Json`), nicht in Doku, die still veraltet.

## Ergebnisklassen und Exitcodes

Jedes Gate endet als `pass`, `fail`, `skip`, `not_applicable` oder `infrastructure_error`. Ein fehlendes Tool ist **immer** `infrastructure_error`, nie ein Skip; `not_applicable` ist nur für bewusste Plattform-/Flag-Entscheidungen zulässig (windows-only-Gate auf Linux, Netz-Gate unter `-NoNetwork`) und trägt seinen Grund im Artefakt. Runner-Exitcodes: `0` alle verpflichtenden Gates grün, `1` mindestens ein Gate rot (dominiert), `2` Prüfumgebung unvollständig, `3` ungültiger Aufruf.

Ausführungsformen: **nativ** (Host-Tools), **containerisiert** (läuft über Docker; auf Windows-Hosts setzt die Fast-Lane Docker deshalb ausdrücklich voraus), **windows-only** (eigener Windows-Runner in CI). Netz-Gates beziehen Advisories/Toolimages nur in verbundener CI; das Runtime-System bleibt offline (ADR-0007).

## Fast-Lane (jeder PR, lokaler Vorabcheck)

| Gate | Beweist | Form | Rot heißt meist |
|---|---|---|---|
| `compose-config` | Compose-Dateien valide und interpolierbar | containerisiert | Syntax-/Env-Fehler in `docker-compose.yml`/`.env` |
| `compose-hardening` | Bestehende Container-Härtung als Vertrag: `read_only`+tmpfs, `cap_drop: ALL` + dokumentierte `cap_add`, `no-new-privileges`, Limits, Healthchecks, `service_healthy`-Ordnung, phpMyAdmin nur im `tools`-Profil auf Loopback, Tag+Digest-Pins, kein Docker-Socket | containerisiert | Eine Lockerung der Härtung; Fix am Compose, nie am Check |
| `php-lint` | Jede First-Party-PHP-Datei parst | containerisiert | Syntaxfehler; Datei in der Fehlzeile |
| `phpunit-unit` | Unit- + Static-Suiten, `--fail-on-skipped` | containerisiert | Regression oder verletzter Contract-Test; ein Skip ist hier ein Defekt (ADR-0015-Amendment) |
| `phpstan` | Statische Analyse auf dem Level aus `phpstan.neon.dist`, Baseline nur als Ratchet | containerisiert | Neuer Befund: an der Quelle fixen, nie re-baselinen |
| `composer-validate` | `composer.json`/Lock konsistent, Plattformanforderungen erfüllbar | containerisiert | Lock-Drift nach Handedit |
| `composer-audit` | Bekannte Advisories der PHP-Abhängigkeiten | containerisiert, Netz | Neues Advisory: bewerten, updaten oder begründet ausnehmen |
| `lang-parity` | DE/EN-Schlüssel- und Platzhalter-Parität, echte Umlaute, kein Gedankenstrich in Portal-Prosa (ADR-0014) | nativ | Key nur in einer Sprache oder Platzhalter-Drift |
| `enum-sync` | PHP-Konstanten == ENUM-Spiegel in `struktur.sql`/`migrate.php`, ordnungs-exakt (ADR-0016) | nativ | Spiegel vergessen; am SSoT (Konstante) beginnen |
| `php-version-sync` | Dockerfile-`FROM` == composer.json == constants == Doku | nativ | Version an einer Stelle angehoben |
| `bounds-sync` | Kein Portal-Text schreibt eine Zahl aus, die eine Konstante besitzt; `BOUNDS_EXEMPT` nicht veraltet | nativ | Zahl statt Platzhalter im Text, oder verwaiste Ausnahme |
| `doc-hygiene` | Keine Changelog-Marker und Zeilenbudgets in den Session-Dokus (AGENTS/GROK/CLAUDE/README) | nativ | Historie in Regel-Doku; nach `docs/CHANGELOG.md` verschieben |
| `doc-semantics` | Betriebsdoku behauptet keine veraltbaren Stände: keine `[x]`/Datums-Nachweise in `PRE-SHIP-CHECKLIST.md`, keine hartcodierten Test-/Migrationszahlen oder Messwerte, PHPStan-Level-, MySQL- und Node-Nennungen gegen ihre SSoT, stillgelegte Backup-Pfade nur mit Stilllegungs-Marker | nativ | Doku nennt einen Ist-Stand als Zahl; interpolieren, streichen oder ins Artefakt/Archiv verschieben |
| `csp-patterns` | Keine `BLOCK:`-Muster (interpolierte SQL, Secret-Fallbacks, Inline-Handler, externe Assets) im Worktree | nativ | Hard-Finding; Fundstelle in der Ausgabe |
| `js-syntax` | `node --check` der Portal-Skripte | nativ | Syntaxfehler in `core.js`/`forms.js`/`deploy.js` |
| `powershell-syntax` | Alle `.ps1`/`.psm1`/`.psd1` parsen (5.1-kompatibel) | nativ | Parser-Fehler; Datei in der Ausgabe |
| `powershell-tests` | PSScriptAnalyzer (inkl. 5.1-Kompatibilitätsregeln) + Pester über die MECM-Clients (ADR-0029) | nativ | Regelverstoß oder gebrochener Vektor-/Zwillings-Contract |
| `yaml-lint` / `actionlint` | YAML-Stil und Workflow-Semantik | containerisiert | Echte YAML-/Actions-Fehler; Linter-Zeile lesen |
| `ansible-syntax` / `ansible-lint` | Playbooks parsen und bestehen `--strict` | containerisiert | Playbook-Änderung verletzt Lint-Profil |
| `yaml-roundtrip` | Golden-Mission rendert durch die Produktions-Generatoren und PyYAML liest sie semantisch identisch (Anhang-C-Nachfolger) | containerisiert | Generator-Änderung ohne nachgezogenen `expected`-Block; genau dieses Gespräch ist gewollt |
| `shellcheck` / `hadolint` | Shell-Skripte und Dockerfiles | containerisiert | Echte Findings; Ausnahmen nur inline mit Grund |
| `python-client-tests` | `upload_mac_list.py` (stdlib-only) inkl. Exitcode-Vertrag 0/20-24 | containerisiert | Rückkanal-Contract verletzt (ADR-0030) |

## Integration-Lane (Merge, Nightly, Release-Kandidaten)

Zusätzlich zur Fast-Lane; erstes Gate ist der Wegwerf-Stack.

| Gate | Beweist | Form | Rot heißt meist |
|---|---|---|---|
| `qa-stack` | Eigener Compose-Stack (`virtusphere-qa`) aus `struktur.sql`, Migrationen, Seed, Health; berührt nie den Dev-Stack | containerisiert | Fresh-Install-Pfad gebrochen; vor allen DB-Gates fixen |
| `migrate-check` | Migrations-Preflight `pending=0` gegen den QA-Stack | containerisiert | Migration fehlt oder ist nicht idempotent |
| `phpunit-full` | Volle Suite inkl. Integrationstests, `--fail-on-skipped`; Allowlist-/Credential-Zustände arrangieren die Tests selbst | containerisiert | Ein dynamischer Skip oder eine echte Integrations-Regression |
| `schema-convergence` | `struktur.sql` allein == `struktur.sql` + alle Migrationen, inkl. der 0019/0020-Edge-Cases | containerisiert | Schema-Änderung nur auf einer Seite |
| `health-contract` | `health.php` 200/`ok` mit vergröberter PHP-Version, `/tests/` 403 | containerisiert | Exposure-Regression |
| `e2e-portal` | Playwright Chromium gegen den QA-Stack: der E6-Abdeckungsvertrag (unten) | nativ, Netz für Toolbezug | Browser-beweisbare Regression; Report im Artefakt |
| `guard-harness` | Jeder Guard positiv, negativ (Mutation wird erkannt) und im Zero-Match-Fall bewiesen (`scripts/test-guards.ps1`) | nativ | Ein Guard schützt nicht mehr, was er behauptet |
| `legacy-csharp-build` | Desktop-Client baut reproduzierbar (bis zur E3-Entscheidung) | windows-only | Build-Bruch durch Repo-Änderung; off-Windows `not_applicable` |

## Release-Lane (vor jeder Auslieferung)

| Gate | Beweist | Form | Rot heißt meist |
|---|---|---|---|
| `e2e-browser-matrix` | Dieselbe Playwright-Suite wie `e2e-portal` auf Firefox und WebKit (ADR-0028-Revision: die volle Browser-Matrix gehört zur Release-Lane) | nativ, Netz für Toolbezug | Engine-spezifische Regression; Report im Artefakt |
| `e2e-msedge` | Dieselbe Suite auf Windows-Edge über den `msedge`-Channel | windows-only, Netz für Toolbezug | Edge-spezifische Regression; off-Windows `not_applicable` |
| `restore-drill` | Backup + Restore in isolierten Containern: Hash/Manifest, Rowcounts, Schemafingerprint, Invarianten, App-Smoke, Credential-Entschlüsselung mit richtigem und Scheitern mit falschem `APP_KEY` (ADR-0017) | containerisiert | Restore-Pfad oder Schlüsselmaterial-Handling gebrochen |
| `secret-scan` | Kein Secret in der vollständigen Git-Historie | containerisiert, Netz | Neues Secret committet: rotieren, nicht nur löschen |
| `sbom` | SPDX-SBOM je Runtime-Image | containerisiert, Netz | Toolfehler; SBOM gehört ins Artefakt |
| `image-cve` | Trivy-Scan, blockend nur bei fixbaren Critical/High; Ausnahmen in `.trivyignore.yaml` mit ID, Grund, Owner, Ablauf, und eine abgelaufene Ausnahme bricht wie eine fehlende | containerisiert, Netz | Neues fixbares CVE oder abgelaufene Ausnahme |
| `offline-bundle` | Bundle (Images, vendor, Collections, SBOM, CVE-Bericht, Quelle, `SHA256SUMS`) verifiziert sich selbst offline | containerisiert, Netz beim Bau | Bundle unvollständig; `verify.sh`-Ausgabe lesen |
| `npm-audit` | Advisories des E2E-Dev-Tooling (`tests/e2e`), blockend ab high | nativ, Netz | Dev-Tooling-Advisory; Update oder begründete Ausnahme |

## E2E-Abdeckungsvertrag (E6, ADR-0028-Revision)

Playwright beweist jede **Aktion** genau einmal im echten Browser; **Felder** werden erschöpfend in PHPUnit geprüft. Das Inventar ist maschinell geschlossen, nicht diese Seite:

- Das Inventar ist der geschlossene Action-Scan `tests/Support/PortalActionInventory.php` (derselbe, den `PortalConfirmContractTest` klassifiziert); `tests/Static/E2eActionCoverageContractTest.php` erzwingt, dass jede Action einen `// e2e-covers:`-Marker in einer Spec trägt (Confirm-Aktionen zusätzlich `e2e-covers-cancel:`). Eine neue Action bricht den Build, bis ihre Spec existiert oder die Schuld offen in `PENDING_ACTIONS` gebucht ist.
- Confirm-Aktionen beweisen beide Zweige (Abbrechen ändert nichts, per DB-Beweis; Bestätigen führt aus); Verifikation läuft immer über Zustand (DB, frisches GET), nie über die POST-Antwort.
- Bewusst **nicht** im Browser: die erschöpfende Feld-Wertematrix. Sie lebt in `ValidatorRulesTest` (jede Regel, jede Grenze); der Browser fährt nur die feindliche Wertematrix je Render-Kontextklasse (`field-roundtrip.spec.js`). Alles andere wäre eine langsame, flake-anfällige Duplikation der PHPUnit-Schicht.

### Seiteninventar mit Prüffokus (überführt aus TESTPLAN Anhang A)

`health-matrix.spec.js` prüft **jede** Portalseite basal (Ladezustand je Rolle, kein PHP-Fehlertext, keine Konsolen-/CSP-Fehler, kein Off-Host-Request, kein roher i18n-Key, beide Themes); `accessibility.spec.js` scannt jede Seite mit Axe in beiden Themes; `rbac-csrf.spec.js` beweist die Ablehnungs-Diagonale (fehlendes/fremdes Token, unberechtigte Rolle, anonym) quer über die POST-Actions. Die Spalte nennt die darüber hinaus vertiefende Spec.

| Seite | Besonderer Prüffokus | Vertiefende Spec |
|---|---|---|
| login/logout/session_ping | Fixation/Rotation, `ip_locked`, serverseitige Invalidierung, CSRF-Exemption | `session-security.spec.js` |
| index/dashboard | Redirect je Auth-Zustand, Aggregate, Live-Deploy-Badge | `health-matrix.spec.js`, `session-security.spec.js` |
| missions | CRUD, Template-Erkennung, Confirm-Zweige | `crud-mission.spec.js` |
| mission_details | Bulk-Aktionen, Transfer-Export/-Import-Round-Trip | `mission-transfer.spec.js` |
| vms / vm_edit | Bulk mit Confirm; dynamische Disk/NIC-Zeilen, vNIC-Enum, Overrides, Hotplug, Bearbeitungskonflikt | `crud-vm.spec.js` |
| deploy / deploy_log | Enqueue, Scheduling, Cancel (`data-confirm-action`), Retry inkl. partial-Zweig, Live-Status bis Terminal inkl. `partial` | `deploy-actions.spec.js` |
| os / packages / vlans | Geteilter Statusfilter, Status-Normalisierung sichtbar, MECM-owned read-only (UI und POST) | `crud-catalog.spec.js` |
| credentials | Nie Klartext-Rendering, Verbindungstest-Kategorien, Referenzschutz lokalisiert | `crud-credential.spec.js`, `crud-negative.spec.js` |
| integrations | Ampel, Inventory-Enqueue, Intervall, Fehlerdetail hinter `<details>` | `integrations-flow.spec.js` |
| users / account | Rollen, Policy in UI und Backend identisch, Selbstpflege | `crud-users.spec.js` |
| settings | Tab-Fragment-Redirects, One-Time-Report-Token, HTTPS-Karte (Upload, Ablehnfälle, Enable/Disable) | `settings-flow.spec.js`, `https-flow.spec.js` |
| logs | Tab+Kategorie-Deep-Links, CSV-Export | `csv-injection.spec.js` |
| CSV-Exporte | Header, BOM, Formel-Injection-Werte erneut im heruntergeladenen Artefakt | `csv-injection.spec.js` |
| help | Vollständigkeit gegen UI-Delta, Anker | `health-matrix.spec.js` |
| health.php | 200/`ok`, generisch bei Störung, kein Detail-Leak | `health-contract`-Gate |

Die Maschinen-API ist kein Portal: ihre Endpunkte sind über `MachineApiWireTest`/`MachineApiErrorShapeContractTest`/`MecmReportWireTest` gepinnt (Wire-Felder eingefroren, keine Lokalisierung; `.claude/rules/machine-api.md`).

### Feld-Wertematrix (überführt aus TESTPLAN Anhang B)

Die Wertklassen und was sie fangen; erschöpfend je Validator-Regel in `ValidatorRulesTest`, je Render-Kontext im Browser in `field-roundtrip.spec.js` (DB byte-exakt, kein Entity in der Speicherung, jeder Kontext escaped, Dialog-Handler beweist Nicht-Ausführung):

| Wertklasse | Fängt |
|---|---|
| Umlaute/ß | Encoding (utf8mb4), Rendering |
| HTML-Metazeichen, `<script>`, Attribut-Breakout | fehlendes Escaping, Doppel-Escaping, XSS |
| SQL-Sonderzeichen | Round-Trip durch Prepared Statements |
| Führendes/trailing Whitespace | Trim-Konsistenz DB vs. Anzeige |
| Exakt Maximallänge / +1 | Grenzlängen (`mb_strlen` == VARCHAR-Semantik), Truncation vs. Validierung |
| 4-Byte-Emoji | utf8mb4-Vollständigkeit |
| Locale-Zahlen/-Datum | Formatierungs-Idempotenz beim Re-Save (ADR-0022) |
| Leerstring nach gefülltem Wert | NULL-vs.-`''`-Konsistenz |
| YAML-Zeichen und C0-Steuerbytes | Weiterverwendung im Deploy-YAML; Chokepoint ist der Escaper (`yaml-roundtrip`-Gate) |

## Bewusst manuelle Release-Gates

Nicht automatisierbar bzw. bewusst menschlich; sie gehören zur Release-Abnahme (`PRE-SHIP-CHECKLIST.md`) und werden als Blocker dokumentiert, nie als Skip umgedeutet:

- **Tastatur-, Fokus- und Screenreader-Durchgang** der Kernflüsse in beiden Themes: Axe findet Attribut-, nicht Bedienbarkeitsfehler.
- **SYSTEM-Smoke** der PowerShell-Clients in einer Wegwerf-Windows-VM und **MECM-Staging-Abnahme**: braucht echte MECM-Infrastruktur.
- **Reales Ansible-/ESXi-Staging** mit zweitem Idempotenzlauf (`changed=0`-Erwartung): der Sandbox-Stub beweist die Choreografie, nicht das Zielsystem.
- **Clean-Checkout-Releaseprobe** auf frischem Host (Rollout-Schritt 9/10).
