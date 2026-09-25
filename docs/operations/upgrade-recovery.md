# Upgrade- und Recovery-Kompatibilität

Dieses Runbook ist die ausführbare X01-Matrix für Portalquellen, Schema,
Worker, PowerShell-Paket, Images, Konfiguration und Browserzustand. Es ergänzt
`offline-install.md`, `go-live.md` und `backup.md`. Ein Image-Digest allein ist
keine Releaseidentität, weil Anwendungscode und Konfiguration in Bind-Mounts
liegen.

## Releaseidentität

Vor einem Wartungsfenster werden gemeinsam festgehalten:

1. Git-Commit und SHA-256 des verifizierten `source.tar.gz`;
2. angewandte und ausstehende Einträge aus `deploy_migrations` beziehungsweise
   `migrate.php --check`;
3. aufgelöste Compose-Dateien einschließlich Host-Override, Image-IDs und die
   Digests/Pins aus dem Liefermanifest;
4. Hashmanifest und Provenienz des Kernbundles sowie, falls installiert, des
   getrennten Tools-Bundles;
5. Version/Manifest des MECM-PowerShell-Pakets und des Durable Runners;
6. Sicherungszeitpunkt, Hashmanifest und bestandener Restore-Drill des zur
   Rückkehr vorgesehenen Daten-/Konfigurationsstands.

Fehlt eines dieser Teile, ist der Stand nicht als wiederherstellbares Release
identifiziert. Eine unveränderte Image-ID gleicht keinen geänderten Bind-Mount
aus.

## Zulässige Kombinationen

| Portal/Quellen | Schema | Worker/Prozessform | Integration/Assets | Entscheidung |
|---|---|---|---|---|
| dieselbe Releaseidentität | zugehöriger, vollständig migrierter Stand | zugehöriger Worker und gespeicherter Prozessvertrag | zugehörige PowerShell-/Runnergeneration und Assets | unterstützt |
| neue Quellen | altes Schema mit ausstehenden Migrationen | beliebig | beliebig | nur `migrate.php --check`; Portal und Worker noch nicht freigeben |
| neue Quellen | neues Schema | alter, bereits laufender Workerprozess | alt oder neu | Übergangszustand, nicht freigeben; Claims vorher pausieren, laufende Einheit geordnet beenden, Prozess neu starten |
| alte Quellen | neueres Schema | alter Worker | neue Vertragsdaten möglich | nicht als Rollback verwenden; konsistenten alten Daten-/Konfigurationsstand restoren |
| neues Portal | neues Schema | neuer Worker | altes PowerShell-Paket | nur für weiterhin explizit kompatible alte Meldungen lesbar; neue Integrationsfunktion gesperrt, bis das Paket kontrolliert aktualisiert ist |
| altes Portal | beliebiges Schema | beliebig | neues PowerShell-Paket oder neuer Callback | unzulässig; neue Felder/Aktionen dürfen nicht gegen einen alten Endpoint gesendet werden |
| neue Quellen | neues Schema | passend | alte Browserseite | nur lesend; vor jedem Schreiben neu laden. Writer mit `edit_version` lehnen veraltete Formulare ab, diese Sicherung darf nicht für ungeschützte Altformulare behauptet werden |
| beliebige Quellen | beliebiges Schema | nicht zum gespeicherten `supervisor_contract` passend | beliebig | absichtlich degraded; Prozessform und gespeicherten Vertrag im Wartungsfenster wieder zusammenführen |

Die Machine-API bleibt additiv kompatibel, soweit ihr jeweiliger Wirevertrag das
ausdrücklich festlegt. Alte Callbacks werden trotzdem an Job, Attempt,
Runtimegeneration, Handle, VM und Revision geprüft; ein Restore oder Update
macht daraus keinen erlaubten Replay. `uncertain` bleibt ungelöst und löst nie
automatisch eine zweite Create-Ausführung aus.

## Migrationsklassen des geprüften Stands

Die Klasse beschreibt die Rückkehrgrenze, nicht nur die SQL-Syntax. Ein exakter
Datenbank-Downgrade wird von keiner Migration angeboten; dafür wird der zuvor
bewiesene konsistente Restorestand verwendet.

| Klasse | Migrationen | Rückkehrvertrag |
|---|---|---|
| A — additive Form | 0001, 0002, 0004, 0005, 0006, 0007, 0010, 0011, 0012, 0013, 0015, 0016, 0019, 0021, 0022, 0023, 0024, 0025, 0026, 0027, 0029, 0030, 0031, 0032, 0033, 0035, 0036, 0037, 0038, 0039, 0051, 0052 | Ältere Leser können zusätzliche Form häufig ignorieren. Das erlaubt keinen pauschalen App-Rollback: erst aktive Jobs, neue Statuswerte und Writer prüfen; für eine garantierte Rückkehr weiterhin Restore. |
| N — normalisierende Datenänderung | 0003, 0008, 0009, 0014, 0017, 0018, 0020 | Vorwärts reparierbar und idempotent geprüft, aber ursprüngliche Bytes/Bedeutung werden nicht rekonstruiert. Exakte Rückkehr nur über Restore. |
| C — Vertrags-/Rückkehrgrenze | 0028, 0034, 0040, 0041, 0042, 0043, 0044, 0045, 0046, 0047, 0048, 0049, 0050, 0053 | Entfernte Form, neue Identitäts-/Ownership-/Prozess-/Callbackverträge oder neue Schreibfences machen alten Code unsicher. Kein gemischter Betrieb; Rückkehr nur als zusammengehöriger Source-, Schema-, Konfigurations- und Schlüsselrestore. |

Migration 0034 entfernt das Legacy-Tokenschema ausdrücklich. Die Migrationen
0042, 0043, 0044, 0045, 0046, 0047, 0048, 0049 und 0050 führen
Runtimegeneration, Remoteausführung, Claim-/Supervisorzustand,
Create-Einheiten, Callbackfences und Rolloutnamen zusammen. 0053 macht
`edit_version` zum Schreibzaun. Diese Grenzen dürfen bei einem Rückbau weder
gelöscht noch durch Defaultwerte simuliert werden.

## Upgradefolge

1. Zielrelease und Bundle offline verifizieren; obige Releaseidentität sichern.
2. Aktuellen Health-/Systemstatus sowie offene, laufende, cancelling und
   `uncertain` Jobs erfassen. Neue Claims im vorhandenen Adminpfad pausieren.
3. Laufende externe Einheiten geordnet bis zu ihrer beobachtbaren Grenze
   auslaufen lassen. Ein unbekannter Create-Ausgang wird nicht durch Abbruch und
   Neueinreihung „bereinigt“.
4. `scripts/backup.sh` ausführen und genau dieses Tripel mit
   `scripts/restore_test.sh` beweisen. Ohne grünen Drill kein Schemaübergang.
5. Worker stoppen. Erst jetzt verifizierte Quellen/Assets und erforderliche
   Images bereitstellen. `.env`, Host-Override, Schlüssel und Laufzeitdaten
   nicht aus einem Quellarchiv überschreiben.
6. `migrate.php --check` ausführen. Bei roten Daten-Preflights abbrechen. Danach
   Migration genau einmal starten und anschließend `pending=0` sowie
   Schemakonvergenz prüfen.
7. PHP, beide Worker und Webserver mit der zusammengehörigen Compose-/Imageform
   neu erstellen beziehungsweise starten; Health und gespeicherten
   `supervisor_contract` prüfen. Claims erst danach wieder öffnen.
8. Portal-Login, Health, betroffene Machine-API-Negativprobe und einen
   ungefährlichen lesenden Integrationsweg prüfen. Alte Browserseiten neu laden.
9. PowerShell-/Runnerpaket erst nach Portal/Schema aktualisieren und seine
   eigene Version/Generation prüfen. Standortfunktionen bleiben bis zu ihren
   Laborproben gesperrt.

## Unterbrechung und Rückkehr

- Vor der Migration kann der verifizierte alte Quell-/Imagestand wieder
  gestartet werden, sofern kein neuer externer Vorgang begonnen hat.
- Bei unklar unterbrochener Migration nicht blind erneut ausführen. Den Eintrag
  in `deploy_migrations`, die konkrete Tabellenform und das Migrationsprotokoll
  prüfen. Ist atomarer Abschluss nicht belegbar, den bewiesenen Vorabstand
  restoren.
- Ein Abbruch beim Quellen-/Assetwechsel bleibt gesperrt, bis ein vollständiges
  Manifest genau eines Releases vorliegt. Keine Mischung aus zwei Archiven
  vervollständigen.
- Ein Worker-/Runtimewechsel erhält Jobbesitz, Attempt, Generation und Remote-
  Handle. Erst der bestehende Recoveryvertrag darf eine Einheit freigeben.
- Ein Restore verwendet Datenbank, `.env`, Host-Override, APP_KEY und
  Quellrelease desselben Sicherungsstands. Anschließend AD-Konvergenz ausführen;
  neuere offene Browserseiten und Callbacks müssen an ihren Revisions-/
  Generationfences scheitern.
- Nach Restore oder Rückbau bleiben `uncertain`-Einheiten, fremde
  Runtimegenerationen und verlorene externe Antworten sichtbar. Neustart ist
  keine Erfolgsevidenz.

## Erneuerung der Nachweise

Schema-, Runtime-, Compose-, Image-, EnvBoot-, Asset- oder Lieferänderungen
erneuern ihre gezielten Fresh-/Upgrade-/Restore- und Betriebsproben. Änderungen
an Query-/Sessionownern erneuern die betroffenen P01-Reihen; reine
Dokumentänderungen nicht. Ein finaler Release benötigt weiterhin Q02 und die
erreichbaren Standortlabore. Diese Matrix ist keine Commit-, Push- oder
Produktivfreigabe.
