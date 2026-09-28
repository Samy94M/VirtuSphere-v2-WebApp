# Plan: Autoimporter-Versionsmodell und Paketwechsel je VM

**Stand:** 2026-09-28

**Status:** Nutzerentscheidungen vom 28.09.2026 festgehalten; Code am Stand `9139971` geprüft (Codelesung, keine Laufzeitprobe). Nichts davon ist umgesetzt.

Einstieg und Reihenfolge stehen im [Register](2026-09-12-consolidated-session-backlog.md), Abschnitt „Entscheidungen 28.09.2026“. Dieser Plan ersetzt die dortige offene Frage M03-Q03 und besitzt Entscheidungen, Befunde, Randfälle und Arbeitspakete dieses Strangs.

## 1. Ziel

Eine Versionsnummer in `config.json` bezeichnet genau einen Paketinhalt. Nur eine echt höhere Nummer erzeugt in MECM eine neue Application. Welche VM welche Version bekommt, entscheidet allein der Admin am Paket-Haken der VM; das Portal hängt nie selbst um. Paketänderungen an einer bereits in MECM registrierten VM gehen beim Speichern automatisch an MECM, Betriebssystemänderungen weiterhin nur per bewusstem Klick.

## 2. Entscheidungen des Nutzers (28.09.2026)

| ID | Entscheidung | Heute |
|---|---|---|
| AV-R01 | Maßgeblich ist nur `version` in `config.json`. Jede andere Änderung (Paketdateien, `DeployTo`, `removeOldVersion`, `InstallationBehaviorType`, `generateOwnDeviceColletion`) wirkt erst mit einer höheren Version. Ohne Anhebung ändert sich in MECM nichts; der Systemstatus warnt „Dateien geändert, Version nicht angehoben“. | Geändertes Dateimanifest löst `Update-CMDistributionPoint` für dieselbe Application aus. |
| AV-R02 | Nicht höher als die höchste vorhandene Version (niedriger oder nur anders geschrieben wie `1.0` → `1.0.0`): nichts anlegen, Warnung „Version nicht höher“. Ein Rückweg geht nur über eine neue höhere Nummer mit altem Inhalt. | Jede noch nicht vorhandene Nummer wird eine eigene Application. |
| AV-R03 | Versionen bestehen nur aus Zahlen und Punkten, dieselbe Regel wie `ConvertTo-VsPackageVersionParts`. Andere Pakete werden nicht verarbeitet und im Systemstatus gemeldet; vorhandene MECM-Objekte bleiben unangetastet. | Werden importiert, sperren aber die Bereinigung des Produkts. |
| AV-R04 | Die Paketvorlage (`install.ps1`, Reporter-Bündel) kommt nur mit einer Versionsanhebung ins Paket. Nutzen Pakete eine ältere Vorlage, zeigt der Systemstatus eine Hinweiszeile, keine Warnung. | Vorlage wird bei jedem Lauf in alle Paketordner kopiert. |
| AV-R05 | Ersetzen wie bisher nur mit `removeOldVersion: "true"`; Löschzeitpunkt unverändert (Register M02). Eine nicht übernehmbare fremde Altversion sperrt die Bereinigung des ganzen Produkts weiter. | Unverändert. |
| AV-R06 | Altbestand ohne Ownership-Marker (importiert vor `cf75676`, 07.09.2026) wird einmalig mit einem eigenen Werkzeug übernommen: lesender Bericht vorab, markiert nur eindeutig passende `Produkt-Version`-Objekte, löscht nichts. | Unmarkierte Altversionen sperren die automatische Bereinigung dauerhaft. |
| AV-R07 | Kein Abgleich ohne Dateiänderung, kein täglicher Scan. | Unverändert. |
| AV-R08 | Das Portal hängt VM-Zuweisungen nie automatisch auf eine neue Version um; der Admin setzt den Haken je VM. | `packages_relink_upgrades` hängt um, wenn die Nachfolgerin im selben Package-Sync neu erscheint, also zufallsabhängig. |
| AV-R09 | „Update verfügbar“ im VM-Editor, sobald eine höhere Version desselben Pakets existiert. | Nur wenn die verknüpfte alte Version zurückgezogen ist. |
| AV-R10 | Zwei Versionen desselben Pakets auf einer VM sind erlaubt, das Portal warnt nur. Einen zurückgezogenen alten Haken entfernt der Admin selbst, auch wenn die neue Version schon angehakt ist. | Kein Hinweis. |
| AV-R11 | Keine Rolloutsperre für eine noch nicht ausgerollte VM mit zurückgezogener Paketversion; es bleibt bei `collection_missing` im Device-Sync. | Unverändert. |
| AV-R12 | Registrierte VM: Speichern mit geänderter Paketauswahl stellt die VM automatisch zur MECM-Übertragung ein, aber nur wenn das gewünschte Betriebssystem dem in MECM gesetzten entspricht (`deploy_vm_mecm_rules`, Typ `os`). Sonst keine automatische Übertragung; die Meldung erklärt das Neuinstallationsrisiko, der Admin klickt „Zuweisungen an MECM übertragen“. | Speichern ändert nur das Portal (ADR-0020-Nachtrag, WP-06); Übertragung immer per Klick. |
| AV-R13 | Doku und DE/EN-Hilfe erklären die MECM-Übertragung: was sie ist, warum es sie gibt, wie sie funktioniert (automatisch bei Paketen, per Klick beim Betriebssystem, keine Deinstallation). | Nur eine Speichermeldung. |
| AV-R14 | Die Retire-Schutzschwelle (Standard 30 %) zählt Versionswechsel nicht mit: Verschwindet eine Version, deren höhere Nachfolgerin desselben Basisnamens im Payload steht, gilt das nicht als Katalogausfall. | Alles zählt; viele gleichzeitige Versionswechsel sperren den Sync mit HTTP 409. |

## 3. Zielablauf

Beispiel: Firefox 128.0 wird 128.1, mit `removeOldVersion: "true"` und eigener Collection. Autoimporter und Package-Sync laufen standardmäßig alle 60 Sekunden, der Device-Sync alle 10 Sekunden.

1. Admin hebt in `config.json` die Version an und speichert.
2. Autoimporter erkennt geänderte Dateien und scannt. Version ungültig (AV-R03) oder nicht höher (AV-R02): Warnung, MECM unverändert, Ende.
3. Autoimporter kopiert die aktuelle Vorlage ins Paket (AV-R04), legt die Application `Firefox-128.1` an (Erkennung über `SOFTWARE\VirtuSphere\Packages\Firefox-128.1`), fordert die Verteilung an die DP-Gruppe an, legt die Collection `Firefox-128.1` samt Deployment „Erforderlich“ an, bei `DeployTo` zusätzlich „Verfügbar“.
4. Package-Sync meldet die neue Collection; das Portal führt Firefox 128.1 als aktiv, noch keiner VM zugewiesen.
5. Ohne `removeOldVersion` bleibt 128.0 parallel bestehen. Mit dem Schalter löscht der Autoimporter Deployment, Application und Collection von 128.0, sobald der Verteilauftrag der neuen Version gebunden ist (meist im nächsten Lauf) und nichts dagegenspricht. Clients deinstallieren nichts.
6. Package-Sync: 128.0 fehlt, das Portal markiert sie „zurückgezogen“, ohne VMs umzuhängen (AV-R08) und ohne sie zur Schutzschwelle zu zählen (AV-R14).
7. VM-Editor zeigt „Update verfügbar“ (AV-R09). Der Admin setzt den Haken bei 128.1, entfernt 128.0 und speichert.
8. Nicht registrierte VM: Die Auswahl geht mit der ersten Übertragung beim Rollout mit. Registrierte VM mit passendem Betriebssystem: automatisch zur Übertragung eingestellt (AV-R12).
9. Device-Sync nimmt die VM in die Collection 128.1 auf und entfernt ihre eigene Mitgliedschaft in 128.0. Zeigt ein Haken auf eine gelöschte Version, meldet er `collection_missing`, und die VM bleibt in der Warteschlange.
10. MECM installiert 128.1 beim nächsten Richtlinienabruf, weil der Registry-Schlüssel fehlt; `install.ps1` von 128.1 setzt ihn. 128.0 bleibt installiert.

## 4. Befunde im heutigen Code

Codelesung am Stand `9139971`; keiner der Befunde ist per Laufzeitprobe bestätigt.

| ID | Befund | Ort | Folge |
|---|---|---|---|
| AV-F01 | `updateDevice` mit derselben ResourceID endet als Fence-NOOP ohne Write; `updated` bleibt 1. Eine per MECM-Transfer neu eingestellte registrierte VM verlässt `getDeviceList` nie. Kein Test deckt das ab. | `Docker/WebAPI/mecm_updateid.php` (NOOP-Zweig), `lib/mecm_rollout_fence.php` | Vor AV-R12 beheben, sonst bleibt jede automatisch übertragene VM dauerhaft in der Warteschlange. |
| AV-F02 | `repo_set_vm_state_forward(..., 0, ...)` setzt `updated` ohne Vergleich zurück. Speichert jemand während eines Device-Sync-Durchlaufs, kann die neue Markierung verloren gehen. | `Docker/WebAPI/mecm_updateid.php`, `lib/repo/status_events.php` | Übertragungszähler nötig: `getDeviceList` exportiert ihn, `updateDevice` meldet ihn zurück, das Portal löscht die Markierung nur bei gleichem Stand. Änderung am Maschinenvertrag. |
| AV-F03 | Der Device-Sync fragt die Mitgliedschaft eigener Regeln per Provider auch für inzwischen gelöschte Collections ab, obwohl der Collection-Cache alle vorhandenen IDs kennt. Wirft der Provider statt „nicht vorhanden“ zu melden, bleibt die VM mit `membership_query_failed` hängen. | `Powershell-MECM/mecm/mecm_new-device-sync.ps1` (Collection-Cache, Owned-Probe) | Nicht vorhandene IDs als nicht vorhanden behandeln; Providerverhalten im Labor bestätigen. |
| AV-F04 | Die Retire-Schutzschwelle sperrt den Package-Sync, wenn viele Versionen gleichzeitig wechseln. | `Docker/WebAPI/mecm_packages.php` (`packages_retire_guard`) | AV-R14. |
| AV-F05 | Jeder Befund erzwingt heute einen Vollscan pro Lauf (Stamp wird nicht gemerkt), und ein ruhiger Lauf meldet keine Befunde mehr. Dauerhafte Hinweise würden so Last erzeugen und trotzdem nach einer Minute aus dem Systemstatus verschwinden. | `Powershell-MECM/mecm/mecm_autoimporter.ps1` (Stamp-Logik, Laufbericht) | Zustandsbefunde merken und in jedem Lauf erneut melden, ohne den Stamp zu blockieren; Hinweiszeile als neuer Summary-Key. |
| AV-F06 | Applications ohne Content-Tracking (Altbestand) bekämen beim ersten Lauf eine Contentanforderung. | `mecm_autoimporter.ps1` (`$needsContentRequest`) | Aktuellen Manifeststand als Baseline übernehmen, ohne neu zu verteilen (zusammen mit AV-C). |
| AV-F07 | Das heutige Umhängen hängt davon ab, ob neue und alte Version im selben Package-Sync wechseln. | `mecm_packages.php` (`packages_relink_upgrades`, `packages_pick_successor`) | Entfällt mit AV-R08. |
| AV-F08 | „Update verfügbar“ nur für zurückgezogene Pakete. | `Docker/WebAPI/lib/repo/catalog.php` (`repo_vm_package_upgrade_hints`) | AV-R09. |
| AV-F09 | Der VM-Editor verhindert keine zwei Versionen desselben Pakets. | VM-Editor, `lib/repo/vms_persistence.php` (`repo_replace_packages`) | AV-R10. |
| AV-F10 | Doku und Kommentare widersprechen dem Code (siehe Abschnitt 8). | mehrere | Mit der Lieferung korrigieren. |
| AV-F11 | `vmListToCreate` und `vmListToUpdate` haben keine Aufrufer mehr und schreiben Paketzuweisungen am Save-Service vorbei. | `Docker/WebAPI/lib/repo/vms_legacy.php` | Entfernen oder bewusst begründet belassen. |

## 5. Randfälle

| ID | Fall | Erwartetes Ergebnis |
|---|---|---|
| AV-E01 | Version im selben Ordner angehoben | Neue Application, Contentquelle ist derselbe Ordner. |
| AV-E02 | Ordner kopiert, alte und neue Version liegen gleichzeitig unter `files` | Beide Quellversionen bleiben; die alte wird nie gelöscht. Systemstatus meldet zwei Quellordner für dasselbe Produkt. |
| AV-E03 | Version gesenkt | AV-R02. |
| AV-E04 | `1.0` → `1.0.0` | AV-R02 (numerisch gleich). |
| AV-E05 | `2.0b`, führende Null `01.2`, Bindestrich | AV-R03; Bindestrich wird schon heute abgelehnt. |
| AV-E06 | Nur `DeployTo` oder `removeOldVersion` geändert | AV-R01: Warnung, wirkt mit der nächsten Version. Ein nachträglich gesetztes `removeOldVersion` löscht erst bei der nächsten Anhebung. |
| AV-E07 | `ProjectName` geändert | Neues Produkt; Objekte des alten Namens bleiben. In der Doku erklären. |
| AV-E08 | Ordner umbenannt, Version gleich | Kein MECM-Write; Hinweis, dass die Contentquelle der Application auf den alten Pfad zeigt. |
| AV-E09 | `removeOldVersion` ohne Bereitstellungsziel (keine eigene Collection, kein `DeployTo`) | Ersatz kann nie bereit sein; als ungültige Konfiguration melden. |
| AV-E10 | Alte Version in Tasksequenz oder Abhängigkeit | Bereinigung gesperrt, Warnung (wie heute). |
| AV-E11 | Nicht übernommene fremde Altversion | Sperrt das Produkt (AV-R05). |
| AV-E12 | Viele Pakete gleichzeitig angehoben | AV-R14. |
| AV-E13 | Vorlage aktualisiert | Hinweiszeile „N Pakete nutzen eine ältere Vorlage“; kein MECM-Write, kein Dauer-Vollscan. |
| AV-E14 | Nicht registrierte VM mit Haken auf gelöschter Version | `collection_missing`, bleibt in der Warteschlange (AV-R11); „Update verfügbar“ im Editor. |
| AV-E15 | Registrierte VM mit Haken auf gelöschter Version, dazu eine andere Paketänderung | Automatische Übertragung ergibt `collection_missing`; die Speichermeldung warnt vorab. |
| AV-E16 | Registrierte VM mit früher gespeicherter, nicht übertragener OS-Änderung und neuer Paketänderung | Keine automatische Übertragung, weil der Vergleich gegen den MECM-Stand läuft, nicht gegen das aktuelle Speichern. |
| AV-E17 | Beide Versionen angehakt | Warnung; beide Deployments treffen die VM, bis der Admin aufräumt. |
| AV-E18 | Haken entfernt | Device-Sync entfernt die eigene Regel; keine Deinstallation. |
| AV-E19 | Speichern während eines Device-Sync-Durchlaufs | Änderung darf nicht verloren gehen (AV-F02). |
| AV-E20 | Vorlagen-Missionen („_“) | Werden nie an MECM übertragen; keine automatische Übertragung. |
| AV-E21 | Erster Lauf des neuen Autoimporters auf einer Site mit Altbestand | Baseline statt Neuverteilung (AV-F06). |
| AV-E22 | Neue Version, Verteilungspunkte offline | Die alte Version wird trotzdem nach gebundenem Verteilauftrag gelöscht (Register M02). |

## 6. SSoT und Verträge

- **Versionsregel:** eine Regel für PowerShell (`ConvertTo-VsPackageVersionParts`, `Compare-VsPackageVersion`) und PHP (`catalog_pick_highest_version` nutzt heute `version_compare`). Mit AV-R03 sind nur kanonische Versionen zulässig; eine gemeinsame Vektor-Fixture prüft beide Seiten gegeneinander.
- **Laufursachen:** `$script:VsRunCauseVocabulary` und das `ValidateSet` von `Add-VsRunCause` in `Powershell-MECM/mecm/VirtuSphere-Common.ps1` sowie die PHP-Liste in `Docker/WebAPI/lib/system_status_mecm_panels.php`. Neue Codes etwa für ungültige, nicht angehobene oder nicht höhere Version, doppelten Quellordner und `removeOldVersion` ohne Ziel.
- **Summary-Keys des Autoimporters:** geschlossene Liste in `Docker/WebAPI/lib/constants.php`; ein neuer Zähler für ältere Vorlagen ist eine Änderung am Maschinenvertrag.
- **Übertragungszähler (AV-F02):** `getDeviceList`-Projektion (`VIRTUSPHERE_MECM_DEVICE_LIST_COLUMNS`), `updateDevice`, `MachineApiWireTest`, `docs/ai/contracts/machine.md`, `docs/ai/reference/machine-api.md`.
- **Automatische Übertragung:** gehört in denselben Transaktionsowner wie Speichern und Audit (`vm_save_with_audit()` in `Docker/WebAPI/lib/vm_save_service.php`), nicht in die Seite. Nachtrag zu [ADR-0020](../adr/ADR-0020-deploy-catalog-mecm-owned-read-only.md).

## 7. Arbeitspakete

Reihenfolge laut Register: nach MC03 zuerst AV-P0 und AV-A, danach AV-B und AV-C.

### AV-P0: Warteschlange und Mitgliedschaften absichern

- AV-F01 beheben: Eine registrierte VM verlässt die Warteschlange nach erfolgreicher Übertragung.
- AV-F02: Übertragungszähler über `getDeviceList` und `updateDevice`.
- AV-F03: Gelöschte eigene Collections ohne Providerabfrage als nicht vorhanden behandeln.
- Tests: Integration (Transfer einer registrierten VM endet; Speichern während eines Durchlaufs geht nicht verloren), Maschinen-Wire-Test, Pester für den Device-Sync.

### AV-A: Portal (WebApp, ohne MECM-Installer auslieferbar)

- AV-R08: automatisches Umhängen im Package-Sync entfernen; Purge-Schutz (`assignments_relinked_at`) für Altzeilen prüfen.
- AV-R14: Schutzschwelle zählt Versionswechsel nicht.
- AV-R09 und AV-R10: Update-Hinweis für jede höhere Version, Warnung bei zwei Versionen desselben Pakets.
- AV-R12: automatische Übertragung im Save-Service, nur für `mecm_sync_state = registered`, Betriebssystem gegen `deploy_vm_mecm_rules`; Speichermeldung nennt Zu- und Abgänge („keine Deinstallation“), OS-Hinweis mit Neuinstallationsrisiko, Warnung bei Haken auf gelöschter Version.
- AV-R13 und AV-F10: Doku und Hilfe.
- Tests: Save-Service, Package-Sync-Endpoint, VM-Editor (Unit/Integration), E2E für die Editor-Meldungen, DE/EN-Parität.

### AV-B: Autoimporter (MECM-Server, erst mit MC03 und Cutover auslieferbar, MC-R4)

- AV-R01 bis AV-R04 und AV-R07, AV-F05, AV-F06, AV-E02, AV-E08, AV-E09.
- Kein Contentupdate derselben Version; Vorlage nur bei Neuanlage; gemerkte Zustandsbefunde und Hinweiszeile; neue Laufursachen und Summary-Key.
- Pester: Versionsregeln (Format, höher, gleich, niedriger, `1.0`/`1.0.0`), gleiche Version mit geänderten Dateien, ältere Vorlage, doppelter Quellordner, `removeOldVersion` ohne Ziel, Baseline ohne Tracking, ruhige Läufe melden Hinweise weiter; bei neuem Fortschritt `VirtuSphere.ProgressReporting.Tests.ps1`.

### AV-C: Übernahme-Werkzeug für Altbestand (MECM-Server)

- Lesender Bericht mit Plan-ID. Übernahmefähig nur: Name exakt `Produkt-Version` eines aktuellen Quellprodukts mit kanonischer Version, genau ein Deployment Type mit Contentquelle unter dem Paketshare und VirtuSphere-Registry-Erkennung, gleichnamige Collection im Ordner `VirtuSphere_Applications`. Alles andere erscheint mit Grund als nicht übernehmbar.
- `-Apply` setzt nur Marker (Application-Beschreibung, Collection-Kommentar) und die Tracking-Baseline; `ShouldProcess` mit ConfirmImpact High, Journal, Rückleseprobe, Sperre. Muster: `Powershell-MECM/retire-VirtuSphere-LegacyGetInfo.ps1`.
- Vor dem Bau prüfen (Microsoft-Doku, Labor), ob die Beschreibungsänderung eine neue Application-Revision erzeugt und was das für Clients bedeutet.

## 8. Doku- und Hilfematrix

| Ort | Änderung |
|---|---|
| `docs/operations/mecm-integration.md` | Katalog-Lebenszyklus ohne automatisches Umhängen (heute steht dort „automatisch umgehängt“), Versionsregeln, Übernahme-Werkzeug, MECM-Übertragung. |
| `Powershell-MECM/README.md` | `config.json`-Regeln (Version, `removeOldVersion` wirkt mit der Anhebung), Marker und Übernahme; heute steht dort, Altobjekte ohne Marker „bleiben erhalten“, tatsächlich sperren sie das Produkt. |
| `Docker/WebAPI/lib/repo/vms_operations.php` | Kommentar „device-sync never Remove“ korrigieren; der Device-Sync entfernt eigene, nicht mehr gewünschte Regeln. |
| `Powershell-MECM/mecm/VirtuSphere-Common.ps1` | Kommentar in `Read-VsPackageConfig` („zurückgeschrieben“) an den Code angleichen. |
| [ADR-0020](../adr/ADR-0020-deploy-catalog-mecm-owned-read-only.md) | Nachtrag: automatische Übertragung von Paketänderungen, Betriebssystem weiter per Klick. |
| `docs/ai/reference/webapi.md`, `portal.md`, `powershell.md`, `machine-api.md`, `docs/ai/contracts/machine.md` | Betroffene Owner und Verträge. |
| DE/EN-Hilfe `help_packages`, `help_missions`, `help_system_status`, VM-Editor-Texte in `vm_edit.php` | Versionsanhebung, „zurückgezogen“, kein Umhängen, „Update verfügbar“, zwei Versionen, MECM-Übertragung (was, warum, wie), neue Systemstatus-Befunde und Hinweiszeile; `flash_mecm_transfer_pending` gilt künftig nur noch für das Betriebssystem. |
| `docs/CHANGELOG.md` | Bei Lieferung. |

## 9. Offen außerhalb des Codes

- AV-F03: Verhalten des Providers bei einer gelöschten Collection-ID (Labor).
- AV-C: Revisionswirkung der Markierung (Microsoft-Doku, Labor).
- Site-Abnahme von AV-B und AV-C nach dem Cutover.
