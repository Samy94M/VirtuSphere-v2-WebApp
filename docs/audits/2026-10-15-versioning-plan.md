# Plan: Release-Nummer und Einstellungen-Tab „Version“

**Stand:** 16.10.2026, Session s15. Entwurf vom 15.10.2026 (`qa-artifacts/s15-2026-10-15/versioning-plan-draft.md`), offene Fragen O-1 bis O-3 am 15.10. und die Kennungsfrage am 16.10. vom Nutzer entschieden; Umsetzung als eigenes Paket nach den s15-Paketen.

## 1. Anlass und Ziel

Ob Prod, Gitea, die MECM-Serverskripte und der Ansible-Host auf demselben Stand sind, ließ sich bisher nur über Commit-Hashes in Checklisten und Registereinträgen nachhalten (am 15.10.2026: Prod `c89c01f`, Bundle `c5902bed`, MECM-Server mit einer Altvorlage ohne zuordenbaren Commit). Ziel: Jede Komponente sagt, auf welchem Release sie läuft, und die Einstellungsseite beantwortet die eine Frage „Läuft überall derselbe Stand?“.

## 2. Entscheidungen des Nutzers

| ID | Entscheidung |
|---|---|
| V-1 | Eine Release-Nummer für Portal, Ansible-Playbooks und PowerShell; sie kommen aus einem Commit. Jede Komponente zeigt, auf welchem Release sie läuft. CHANGELOG nach Releases gegliedert. |
| V-2 | Neuer Tab „Version“ unter Einstellungen mit Portal und Datenbank, Ansible und MECM-Serverskripten. Paketvorlage und Clients gehören nicht hinein. |
| V-3 | Erst der Plan zur Freigabe, dann ein eigenes Paket. |
| O-1 | Format `JJJJ.MM.N`. |
| O-2 | Auf Prod nur die Release-Nummer; der Commit ist dort nicht bekannt, kein zusätzlicher Runbook-Schritt. |
| O-3 | Das nächste Prod-Update nach `c5902bed` wird `2026.10.1`; `c5902bed` bleibt ohne Nummer. |
| V-4 (16.10.) | Kennung der MECM-Serverskripte ohne Commit: `packages-sync/2.0+2026.10.1`, `-abw` bleibt. Die 32-Zeichen-Grenze von `script_version` bleibt, der Maschinenvertrag bleibt unverändert. Anlass: `packages-sync/2.0+2026.10.1.<Commit>-abw` hätte 39 Zeichen, und die Kürzung auf 32 hätte genau `-abw` abgeschnitten. |

## 3. Bestand (Codelesung `b92d2eae`)

- **MECM-Serverskripte:** `Powershell-MECM/BUILD.json` mit `export-subst` (Commit und Datum, nur über `git archive`); der Installer schreibt `manifest.json` (Build, Modus, SHA-256 je Datei); jede Aufgabe prüft beim Start ihre Dateien und hängt den Build an ihre Kennung im Laufbericht (`device-sync/2.1+<Commit7>`, `+unbekannt`, `+ohne-manifest`, `-abw` bei Abweichung). Das Portal speichert sie in `deploy_integration_heartbeats.last_script_version` (`VARCHAR(32)`).
- **Ansible-Playbooks:** liegen nicht fest auf dem Ansible-Host; das Portal lädt sie je Auftrag aus `/var/www/ansible-src` (Bind-Mount aus demselben Checkout) hoch. Sie haben immer den Stand des Portals.
- **Ansible-Host:** „Verbindung und Umgebung prüfen“ prüft unter anderem, ob jede gepinnte Collection genau in ihrer Version installiert ist und ansible-core dazu passt (Probe `runtime-versions`). Gespeichert wird je Ansible-Zugang `deploy_ansible_preflight_state` (Status, Zeitpunkt, bei Fehlschlag die Komponente). Der Zustand des Aufräum-Timers wird nicht gespeichert.
- **Portal:** keine Kennung. Prod wird per `git pull` aus Gitea aktualisiert; `export-subst` greift dort nicht. Der Container sieht nur `./Docker/WebAPI`. nginx liefert jede statische Datei unter `/var/www/html` aus, außer unter `lib/`, `vendor/`, `var/`, `logs/`, `tests/`.
- **Datenbank:** Die Migrationsliste existiert nur als Array in `lib/migrate.php`; die Datei führt beim Einbinden die Migrationen aus. Der Session-Hook liest die Schlüssel deshalb als Text.

## 4. Umsetzung

**4.1 Release-Nummer.** Form `JJJJ.MM.N`, Monat zweistellig, N von 1 bis 99 (die Obergrenze hält jede MECM-Kennung in 32 Zeichen). Sie steht als einzige Zeile in `Docker/WebAPI/lib/VERSION` und als Kopie in `Powershell-MECM/VERSION` (geht mit `git archive` ins MECM-Updatepaket). Abweichung vom Entwurf: unter `lib/` statt im Wurzelordner von `Docker/WebAPI`, weil nginx den Wurzelordner statisch ausliefert und die Nummer sonst ohne Anmeldung lesbar wäre. Gate `version-sync` (Fast-Lane, wie `php-version-sync`) prüft Form und Gleichheit beider Kopien, mit Guard-Fällen grün, Drift, Form und Zero-Match.

**4.2 Wann sie steigt.** Im Commit, aus dem ein Prod-Release gebaut wird: beide Kopien anheben, im CHANGELOG die Überschrift „Noch in keinem Release“ durch `## JJJJ.MM.N (TT.MM.JJJJ)` ersetzen und eine neue leere darüber setzen, Register-Eintrag. Zwischen zwei Releases trägt `main` die Nummer des letzten Releases; nur Entwicklungs- und QA-Stände sind dadurch mehrdeutig, nie Prod. Einmalig: Bis zum ersten Release-Commit trägt `main` schon `2026.10.1` (O-3), weil es noch kein Release gibt, dessen Nummer es tragen könnte. `offline-bundle` schreibt die Nummer als `version` in `provenance.json`. MECM-Updatepakete werden wie das Prod-Bundle nur aus einem Release-Commit gebaut (Koordinator, 16.10.2026): Weil die Kennung keinen Commit mehr trägt, wäre ein Paket von einem Zwischenstand von `main` sonst nicht vom Release zu unterscheiden.

**4.3 Commit neben der Nummer.** `Docker/WebAPI/lib/BUILD.json` mit `export-subst` wie beim MECM-Paket; das Portal zeigt den Commit nur, wenn er eingesetzt ist (Archiv-Installation). Nach `git pull` (Prod) fehlt die Zeile ohne Hinweis (O-2).

**4.4 MECM-Serverskripte.** `Get-VsServerBuildInfo` liest zusätzlich `VERSION`, der Installer schreibt die Nummer als `release` ins Manifest und nennt sie in seiner Abschlusszeile. Die Kennung im Laufbericht wird `<Grundkennung>+<Release>` (V-4), mit `-abw` bei Abweichung; ohne gültige Nummer im Manifest bleiben die bisherigen Formen. Kein neues Wire-Feld; die Grammatik steht im Maschinenvertrag (`docs/ai/contracts/machine.md`). Registry und Aufgabenbeschreibung bleiben beim Commit.

**4.5 Tab „Version“.** Panel `lib/settings/version_panel.php`, nur lesend, eine Tabelle Komponente, Stand, Zustand (passt, weicht ab, unbekannt), bei Abweichung ein Satz, was zu tun ist. Logik in `lib/release_version.php` (Nummer, Commit, Kennung lesen, Migrationsliste) und `lib/version_overview.php` (Zustand je Zeile).

| Zeile | Stand | Zustand |
|---|---|---|
| Portal | Release, Commit nur falls bekannt | Maßstab der übrigen Zeilen |
| Datenbank | letzte angewandte Migration, Zahl der ausstehenden | ausstehend = 0; die Liste aus `lib/migrate.php` als Text gelesen (Einbinden würde migrieren) |
| Laufzeit | PHP- und MySQL-Version | nur Anzeige |
| Ansible-Playbooks | Release des Portals | immer passt (gleicher Checkout) |
| Ansible-Host je Zugang | letzter gültiger Volltest mit Zeitpunkt | grün oder Warnung = passt; gescheitert an `runtime-versions` = weicht ab; sonst oder veraltete Evidenz = unbekannt |
| Device-Sync, Paket-Sync, Autoimporter, Site-Health | gemeldete Release-Nummer oder alte Kennung, letzter Bericht | gleich Portal = passt; andere Nummer oder `-abw` = weicht ab; keine Nummer oder kein Bericht = unbekannt |

Abweichung vom Entwurf: kein Aufräum-Timer in der Ansible-Zeile; er wird nicht gespeichert und beantwortet keine Versionsfrage. Display-Restraint: keine Dateilisten, keine Hashes außer dem kurzen Commit.

**4.6 Tests.** Unit: Form der Nummer, Lesen von `VERSION` und `BUILD.json`, Kennungsparser (neu, alt, `-abw`, ohne Bericht), Migrationsliste aus `lib/migrate.php`, Zustand je Zeile. Integration: Übersicht mit gespeicherten Berichten und Volltest-Ergebnis. Pester: `VERSION` lesen, Manifest mit `release`, Kennung mit Nummer, 32-Zeichen-Grenze für jede Aufgabe mit `JJJJ.MM.99-abw`. Guard-Harness: `version-sync`. e2e: Tab per Deep-Link `#panel-version`.

## 5. Nicht im Umfang

Paketvorlage, Reporter-Bundle und Client-Skripte (V-2); Remote-Runner (gesperrt); automatische Updates oder Warnungen außerhalb des Tabs; Aufräum-Timer.

## 6. Stand

Umsetzung siehe Register, Abschnitt „Session s15“, „Versionierung“.
