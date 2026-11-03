# LDAPS/AD-Integration: Restarbeit nach Etappe 6

Status: Etappe 7 (Coding-Anteil) abgeschlossen am 2026-08-17. Gate 0B bleibt
offen, unverändert außerhalb des Repos.

Ausgangslage: Commit `e7480e8` (2026-08-13) hat Etappe 0A sowie 1-6 aus
`docs/audits/2026-08-11-ldaps-active-directory-integration-plan.md` in einem
Zug umgesetzt (ADR-0039, Migration 0040, Repos, Login/Failover, Systemstatus,
Logkategorie `directory`). Dieser Bestand wurde am 2026-08-17 gegen den Plan
nachgeprüft; die dabei gefundenen Lücken (hermetische Testinfrastruktur,
fehlende AD-Zeile in `help_system_status.php`, ein zu einfaches
Status-Badge ohne echte vierstufige Ampel) sind in derselben Session
geschlossen worden.

## 1. Gate 0B: reale Ziel-AD-Freigabe (weiterhin kein Coding-Task)

`docs/audits/2026-08-13-ldaps-target-ad-validation-protocol.md` steht
unverändert vollständig auf "offen". Das ist beabsichtigt: der Nachweis
braucht Zugriff auf die echte Ziel-AD-Umgebung (DC-Matrix, Policy-/CBT-Test,
CA-Rotation im produktiven PHP-FPM). AD-Aktivierung, Pilotlogin und Release
bleiben bis zu diesem Nachweis gesperrt, unabhängig vom Codestand. Diese
Session hat das Protokoll nicht angefasst und keinen seiner Punkte ersetzt
oder umgangen.

## 2. Etappe 7: erledigt

### 2.1 Hermetische LDAP-TLS-Fixture (Plan-Abschnitt 18.3)

`Docker/ldap-fixture` (Dockerfile + `entrypoint.sh` mit drei Rollen: `dc`,
`tls-stub`, `blackhole`) plus sechs Dienste in `Docker/qa/docker-compose.qa.yml`
(`ldap-dc1`, `ldap-dc2`, `ldap-badcert-unknown-ca`, `ldap-badcert-expired`,
`ldap-badcert-wrongname`, `ldap-dc-rotated`, `ldap-blackhole`), dauerhaft Teil
der Integrationslane (`scripts/check.ps1`, `$qaServices`). Zertifikate als
statische Fixtures unter `Docker/WebAPI/tests/fixtures/ldap/` (README dort
erklärt jede Datei). `Docker/WebAPI/tests/Integration/DirectoryLdapFixtureTest.php`
und `DirectoryLdapFixtureFailoverTest.php` beweisen den nativen
PHP/ext-ldap-Pfad: vertrauenswürdiges/unbekanntes/abgelaufenes/namensfalsches
Zertifikat, Dienstkonto-Bind, Suche, GUID-Rohwert, Benutzer-Bind, zwei
Controller mit Ausfall/Recovery, dass ein falsches Benutzerpasswort (bewiesen
über den LDAP-Monitor-Bindzähler) nie einen zweiten Controller erreicht, dass
ein Nicht-gefunden vor dem Passwort-Bind weiterspringen darf,
Timeout-/Budgetverhalten, Referral-Off und CA-Rotation im selben langlebigen
PHP-Prozess.

Dabei aufgedeckter und behobener Fund: `directory_ldap_failure()`
(`lib/directory_ldap.php`) prüfte nur die LDAP-Protokollcodes 81/85/91 als
Transportfehler; der tatsächlich verlinkte OpenLDAP-Client liefert für jeden
Verbindungsfehler (verweigerter Port, DNS-Fehler, jede TLS-Ablehnung) `errno
-1`. Ohne die Ergänzung wäre ein Controller mit ungültigem Zertifikat oder
schlicht unerreichbar als Datenfehler statt als Transportfehler eingestuft
worden und `directory_read_with_failover()` hätte nie auf den nächsten
Controller ausgewichen: der zentrale Failover-Vertrag der gesamten
AD-Integration war für den häufigsten realen Ausfall (DC nicht erreichbar)
nicht wirksam. Jetzt durch `DirectoryLdapFixtureTest`/
`DirectoryLdapFixtureFailoverTest` gegen echte Fehlerszenarien gepinnt.

### 2.2 Vierstufige AD-Ampel im Systemstatus (Plan-Abschnitt 15.2)

Beim Bau der Hilfe-Legende zeigte sich, dass `system_status_render_directory()`
nur ein Drei-Zustands-Badge (neutral/rot/grün, keine Konfigurationsprüfung,
keine Berechtigungsgrenze, keine Controllertabelle) berechnete statt der im
Plan spezifizierten vier Zustände. Nach Rückfrage beim Auftraggeber wurde die
echte Ampel nachgebaut statt die Hilfe an das unvollständige Verhalten
anzupassen:

- `lib/directory_status.php` (`directory_health_snapshot()`,
  `directory_controller_ampel()`) berechnet Controller- und Gesamtzustand
  einmal mit demselben Zeitpunkt: neutral ohne Konfiguration/deaktiviert, rot
  ohne einsatzbereiten Controller oder bei abgewiesenem Suchkonto, gelb sobald
  mindestens ein Controller funktioniert und ein anderer ausgefallen, veraltet
  oder mit bald ablaufendem Zertifikat ist, sonst grün.
- Neue Konstanten `VIRTUSPHERE_DIRECTORY_CERTIFICATE_EXPIRY_WARNING_DAYS` (30)
  und `VIRTUSPHERE_DIRECTORY_OBSERVATION_STALE_AFTER_DAYS` (7) in
  `lib/directory_constants.php`.
- `system_status_render_directory()` (`lib/system_status_directory_panels.php`)
  ist jetzt auf `users.manage` gegated, rendert ohne gespeicherte Konfiguration
  gar keine Karte mehr (vorher: dauerhaft graue Karte für jeden
  Systemstatus-Betrachter) und zeigt zusätzlich eine Controllertabelle
  (Priorität, Host/Port, letzter Test, letzter Erfolg, Zertifikatsablauf,
  typisierter Zustand).
- `system_status_legend_items('directory')` (`lib/system_status.php`) nutzt
  denselben SSoT-Renderer wie die ESXi-/Ansible-Legenden.
- `Docker/WebAPI/tests/Unit/DirectoryHealthSnapshotTest.php` (21 Fälle,
  deckt u.a. auf: die ursprüngliche Aggregatlogik hätte einen einzelnen
  veralteten, aber nicht bekanntermaßen gestörten Controller fälschlich als
  „rot" statt „gelb" gemeldet) und
  `Docker/WebAPI/tests/Unit/DirectoryStatusLegendCoverageTest.php` (Legenden-
  Abdeckung DE/EN, analog `DiskTypeLabelTest`).

### 2.3 Portalhilfe (Plan-Abschnitt 15.2/16.1)

`lib/help/system_status.php` erklärt die AD-Zeile jetzt mit
`system_status_legend_items('directory')` (dieselbe Legende wie die Karte) plus
zwei Absätzen (`help_system_status.directory_p1/_p2`, DE/EN). Analog zum Muster
in `help_users.php`.

### 2.4 Playwright-E2E für den AD-Pfad (Plan-Abschnitt 18.4)

`tests/e2e/specs/directory-ad.spec.js` (16 Tests) deckt Setupblocker,
Controlleranlage/-priorisierung/-löschung mit Cancel-Beweis, den ehrlich
scheiternden Verbindungstest (die Fixture liefert bewusst kein AD-RootDSE,
das bleibt Gate-0B-Sache; die Suche/den Import/den Sync und die
De-/Reaktivierung fährt der Test gegen einen direkt geseedeten, bereits
validierten Zustand, genau wie `DirectoryLdapFixtureFailoverTest.php` es auf
PHPUnit-Ebene tut), Aktivierung/Deaktivierung mit Cancel-Beweis, Löschen der
Konfiguration mit Cancel-Beweis, eine echte AD-Anmeldung über HTTPS gegen die
Fixture (verweigertes Passwort ohne Fallback, manipulierter `local`-Quelle-POST
für ein reines AD-Konto), Status-/Log-Deep-Links, DE/EN-Rendering ohne
auslaufende Katalogschlüssel und Wrap-Geometrie bei 360px ab.
`E2eActionCoverageContractTest::PENDING_ACTIONS` ist jetzt leer; alle elf
`users.php:directory_*`-Aktionen tragen `e2e-covers`-Marker.

## 3. Nachweise

- Volle PHPUnit-Suite (1185 Tests) grün, `--fail-on-skipped`, gegen den
  QA-Stack inklusive aller sieben LDAP-Fixture-Dienste.
- `php scripts/lang-audit.php --ci`, `check-enum-sync.sh`,
  `check-bounds-sync.php`, `check-doc-hygiene.sh`, `check-doc-semantics.sh`,
  `composer stan` grün.
- `migrate.php --check` grün auf dem QA-Stack.
- Playwright-Chromium: `directory-ad.spec.js` (16/16) sowie die verwandten
  Bestandsspecs (`system-status*.spec.js`, `https-flow.spec.js`,
  `crud-users.spec.js`, 27/27) grün, keine Regression.

## 4. Reihenfolge-Empfehlung für Gate 0B (unverändert außerhalb des Repos)

1. Betriebssystem-Build/Patchstand, effektive Signing-/Channel-Binding-Policy
   und `AvoidPdcOnWan` der Zielumgebung erfassen.
2. Echten AD-Policy-/CBT-Test gegen jeden vorgesehenen schreibbaren DC und
   PHP-FPM-CA-Rotation im Zielbetrieb beweisen
   (`docs/audits/2026-08-13-ldaps-target-ad-validation-protocol.md`).
3. Erst danach: AD-Aktivierung, Pilotlogin, Release.
