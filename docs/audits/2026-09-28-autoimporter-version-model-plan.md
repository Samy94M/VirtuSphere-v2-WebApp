# Plan: Autoimporter-Versionsmodell und Paketwechsel je VM

**Stand:** 2026-09-28

**Status:** Nutzerentscheidungen vom 28.09.2026 festgehalten; Code am Stand `9139971` geprüft (Codelesung, keine Laufzeitprobe). Umgesetzt ist AV-P0 Teil 1 (AV-F01, AV-F02) in `185bf57`; alles Übrige ist offen.

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

Aus der Durchsicht der Ablaufdiagramme ([MECM-Serveraufgaben: Abläufe](../operations/mecm-scheduled-tasks.md)) am selben Tag:

| ID | Entscheidung | Heute |
|---|---|---|
| AV-R15 | Der Package-Sync meldet nur Paket-Collections mit dem Eigentumsmarker des Autoimporters. Von Hand angelegte Collections im Ordner `VirtuSphere_Applications` erscheinen nicht mehr als Paket (AV-F21). | Jede Collection im Ordner ist ein Paket. |
| AV-R16 | Nur kuratierte Task Sequences werden Betriebssysteme im Portal und bekommen eine OS-Collection: solche aus einem VirtuSphere-Ordner oder mit Marker. Welches der beiden Merkmale, entscheidet AV-D nach Laborprobe (AV-F22, AV-F17). | Jede Task Sequence der Site. |
| AV-R17 | OS- und Missions-Collections tragen denselben Eigentumsmarker wie die Paket-Collections. Ein lesender Bericht nennt leere oder nicht mehr gebrauchte Collections; gelöscht wird nichts automatisch (AV-F17, AV-F18). | Kommentar „Autogeneriert by VirtuSphere“, keine Übersicht, kein Aufräumen. |
| AV-R18 | Unklare Einträge im Mitgliedschafts-Journal werden mit einem Werkzeug aufgelöst: lesender Bericht, geführte Auflösung nach dem Muster des Retire-Werkzeugs, statt die Datei von Hand zu bearbeiten (AV-F19). | Doku sagt „manuell belegen“. |

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
| AV-F12 | Scheitert `Add-CMScriptDeploymentType`, entfernt der Autoimporter die Application wieder und wirft weiter: der ganze Lauf endet mit „fail“. Alle Pakete danach bleiben unbearbeitet, solange das eine scheitert. | `mecm_autoimporter.ps1` (Anlage der Application) | Offener Punkt für dieses Paket, weiter mit dem nächsten, wie für die Altobjektsuche (M03-F02). |
| AV-F13 | Die Änderungserkennung hasht jeden Lauf alle Dateien unter `files` und `Package_Vorlage`, im Vollscan jedes Paket ein zweites Mal. Bei großen Installern sind das Gigabytes pro Minute. | `mecm_autoimporter.ps1`, `Get-VsFilesManifestStamp` in `VirtuSphere-Common.ps1` | Vorprüfung über Pfad, Größe und Änderungszeit; vollen Hash nur bei Abweichung und nur einmal je Lauf. |
| AV-F14 | Ändert sich eine Datei während des Hashens (Paket wird gerade kopiert), endet der Lauf mit „fail“ und `mecm_unavailable`, baut die MECM-Verbindung neu auf und verwirft den Dateistand. | `Get-VsFilesManifestStamp`, äußerer `catch` des Autoimporters | Eigene Ursache (Quelle in Bearbeitung), kein Verbindungsabbau, nächster Lauf prüft erneut. |
| AV-F15 | Jeder offene Punkt erzwingt im nächsten Lauf einen Vollscan mit allen Provider-Abfragen und Hashes. Ein dauerhaft offline stehender Verteilungspunkt heißt Vollscan jede Minute. | `mecm_autoimporter.ps1` (Stamp-Logik) | Zusammen mit AV-F05 lösen: gemerkte Befunde ohne erzwungenen Vollscan, Verteilstatus gezielt nachfragen. |
| AV-F16 | Hängt eine VM in der Warteschlange fest (Collection fehlt, Identität gesperrt, AV-F01), liest jeder Device-Sync-Lauf alle 10 Sekunden alle Geräte, Task Sequences und Collections. | `mecm_new-device-sync.ps1` (MECM-Abfragen je Lauf) | Rückstufung blockierter VMs, damit der SMS-Provider nicht dauerhaft belastet wird. |
| AV-F17 | Der Device-Sync legt für jede Task Sequence der Site eine OS-Collection an, auch für fremde, und für jede Mission eine Missions-Collection. Gelöscht wird keine. | `mecm_new-device-sync.ps1` | AV-R16, AV-R17. |
| AV-F18 | Zwei Kennzeichnungen: OS- und Missions-Collections tragen den Kommentar „Autogeneriert by VirtuSphere“, Paket-Collections den Eigentumsmarker. Die Ordnernamen `VirtuSphere_OS` und `VirtuSphere_Missions` stehen fest im Skript statt in `VirtuSphere-Common.ps1`. | `mecm_new-device-sync.ps1` | AV-R17; Ordnernamen zentral. |
| AV-F19 | Für unklare Einträge im Mitgliedschafts-Journal gibt es weder Werkzeug noch Schrittfolge. | `mecm_new-device-sync.ps1` (Journal), `docs/operations/mecm-integration.md` | AV-R18. |
| AV-F20 | Der Import nimmt die MAC der ersten DHCP-Karte. Das Portal definiert die PXE-Karte als die eine Karte auf der WDS-Portgruppe der Mission; beides liefert `getDeviceList` schon mit. Liegt eine andere DHCP-Karte vorne, bekommt MECM die falsche MAC und PXE scheitert. | `mecm_new-device-sync.ps1` (MAC-Auswahl) | Dieselbe Auswahlregel wie das Portal; keine oder mehrere passende Karten: VM mit Ursache in der Warteschlange. |
| AV-F21 | Jede Collection im Ordner `VirtuSphere_Applications` gilt als Paket, auch eine von Hand angelegte oder eine, deren Application gelöscht wurde. VMs bekommen dann eine Mitgliedschaft, installiert wird nichts. | `mecm_Packages-TaskSeq-sync.ps1` | AV-R15. |
| AV-F22 | Jede Task Sequence der Site wird ein Betriebssystem im Portal, auch fremde; die Liste ist nicht kuratiert. | `mecm_Packages-TaskSeq-sync.ps1` | AV-R16. |

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

Reihenfolge laut Register: nach MC03 zuerst AV-P0 und AV-A, danach AV-B und AV-C, anschließend AV-D und AV-E.

### AV-P0: Warteschlange und Mitgliedschaften absichern

- Teil 1, geliefert in `185bf57`: AV-F01 (eine übertragene registrierte VM verlässt die Warteschlange) und AV-F02 (Übertragungszähler `transfer_generation` über `getDeviceList` und `updateDevice`, Migration 0058). Nachweise im Register, Abschnitt „Entscheidungen 28.09.2026“.
- Teil 2, offen:
- AV-F03: Gelöschte eigene Collections ohne Providerabfrage als nicht vorhanden behandeln.
- AV-F20: MAC-Auswahl nach der Regel des Portals (Karte auf der WDS-Portgruppe der Mission).
- AV-F16: Rückstufung blockierter VMs, damit eine hängende VM nicht jeden Lauf alle MECM-Abfragen auslöst.
- Tests: Integration (Transfer einer registrierten VM endet; Speichern während eines Durchlaufs geht nicht verloren), Maschinen-Wire-Test, Pester für den Device-Sync (MAC-Auswahl mit mehreren DHCP-Karten, keine passende Karte, Rückstufung und Rückkehr).

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
- AV-F12 bis AV-F15: gescheiterter Deployment Type ist ein offener Punkt statt Laufabbruch; Hash nur nach Vorprüfung und einmal je Lauf; Datei in Bearbeitung als eigene Ursache ohne Verbindungsabbau; offene Punkte ohne Vollscan jede Minute.
- Pester: Versionsregeln (Format, höher, gleich, niedriger, `1.0`/`1.0.0`), gleiche Version mit geänderten Dateien, ältere Vorlage, doppelter Quellordner, `removeOldVersion` ohne Ziel, Baseline ohne Tracking, ruhige Läufe melden Hinweise weiter, Deployment Type scheitert mitten im Scan, Datei ändert sich beim Hashen; bei neuem Fortschritt `VirtuSphere.ProgressReporting.Tests.ps1`.

### AV-C: Übernahme-Werkzeug für Altbestand (MECM-Server)

- Lesender Bericht mit Plan-ID. Übernahmefähig nur: Name exakt `Produkt-Version` eines aktuellen Quellprodukts mit kanonischer Version, genau ein Deployment Type mit Contentquelle unter dem Paketshare und VirtuSphere-Registry-Erkennung, gleichnamige Collection im Ordner `VirtuSphere_Applications`. Alles andere erscheint mit Grund als nicht übernehmbar.
- `-Apply` setzt nur Marker (Application-Beschreibung, Collection-Kommentar) und die Tracking-Baseline; `ShouldProcess` mit ConfirmImpact High, Journal, Rückleseprobe, Sperre. Muster: `Powershell-MECM/retire-VirtuSphere-LegacyGetInfo.ps1`.
- Vor dem Bau prüfen (Microsoft-Doku, Labor), ob die Beschreibungsänderung eine neue Application-Revision erzeugt und was das für Clients bedeutet.

### AV-D: Katalog kuratieren und Collections kennzeichnen (MECM-Server und Portal)

- AV-R15: Package-Sync meldet nur markierte Paket-Collections. Voraussetzung ist AV-C, sonst verschwindet unmarkierter Altbestand aus dem Portal; die Schutzschwelle (AV-R14) muss diesen Übergang tragen.
- AV-R16: kuratierte Task-Sequence-Liste für Betriebssystemkatalog und OS-Collections. Vorher entscheiden: Ordner oder Marker; bestehende Portalzuweisungen auf dann nicht mehr gemeldete Task Sequences bleiben als „zurückgezogen“ sichtbar.
- AV-R17: Marker auf OS- und Missions-Collections (auch nachträglich für vorhandene), Ordnernamen zentral in `VirtuSphere-Common.ps1`, lesender Bericht über leere oder nicht mehr gebrauchte Collections, kein Löschen.
- Tests: Pester für Katalogfilter und Bericht, Package-Sync-Endpoint mit schrumpfendem Katalog, DE/EN-Hilfe zu Paket- und Betriebssystemkatalog.

### AV-E: Werkzeug für unklare Journal-Einträge (MECM-Server)

- AV-R18: lesender Bericht über unklare Einträge mit Plan-ID; `-Apply` löst nur auf, was der Provider eindeutig belegt, `ShouldProcess` mit ConfirmImpact High, Sperre gegen den laufenden Device-Sync, Rückleseprobe. Muster: `Powershell-MECM/retire-VirtuSphere-LegacyGetInfo.ps1`.
- Doku: `mecm-integration.md` ersetzt „manuell belegen“ durch die Werkzeugschritte.
- Tests: Pester für Bericht, Auflösung und Sperrkonflikt.

## 8. Doku- und Hilfematrix

| Ort | Änderung |
|---|---|
| `docs/operations/mecm-integration.md` | Katalog-Lebenszyklus ohne automatisches Umhängen (heute steht dort „automatisch umgehängt“), Versionsregeln, Übernahme-Werkzeug, MECM-Übertragung. |
| `Powershell-MECM/README.md` | `config.json`-Regeln (Version, `removeOldVersion` wirkt mit der Anhebung), Marker und Übernahme; heute steht dort, Altobjekte ohne Marker „bleiben erhalten“, tatsächlich sperren sie das Produkt. |
| `docs/operations/mecm-scheduled-tasks.md` | Diagramme von Devices Sync (AV-P0, AV-D), Packages Sync (Portal-Schritte aus AV-A, Katalogfilter aus AV-D) und Package Import (AV-B) im selben Commit wie die Codeänderung nachziehen; der Wächter `MecmScheduledTasksDocContractTest` prüft nur, dass jede Aufgabe ein Diagramm hat, nicht dessen Inhalt. |
| `Docker/WebAPI/lib/repo/vms_operations.php` | Kommentar „device-sync never Remove“ korrigieren; der Device-Sync entfernt eigene, nicht mehr gewünschte Regeln. |
| `Powershell-MECM/mecm/VirtuSphere-Common.ps1` | Kommentar in `Read-VsPackageConfig` („zurückgeschrieben“) an den Code angleichen. |
| [ADR-0020](../adr/ADR-0020-deploy-catalog-mecm-owned-read-only.md) | Nachtrag: automatische Übertragung von Paketänderungen, Betriebssystem weiter per Klick. |
| `docs/ai/reference/webapi.md`, `portal.md`, `powershell.md`, `machine-api.md`, `docs/ai/contracts/machine.md` | Betroffene Owner und Verträge. |
| DE/EN-Hilfe `help_packages`, `help_missions`, `help_system_status`, VM-Editor-Texte in `vm_edit.php` | Versionsanhebung, „zurückgezogen“, kein Umhängen, „Update verfügbar“, zwei Versionen, MECM-Übertragung (was, warum, wie, mit Verweis auf die Ablaufdiagramme im Projektordner), neue Systemstatus-Befunde und Hinweiszeile; `flash_mecm_transfer_pending` gilt künftig nur noch für das Betriebssystem. Mit AV-D: nur markierte Pakete und kuratierte Betriebssysteme. |
| `docs/CHANGELOG.md` | Bei Lieferung. |

## 9. Offen außerhalb des Codes

- AV-F03: Verhalten des Providers bei einer gelöschten Collection-ID (Labor).
- AV-C: Revisionswirkung der Markierung (Microsoft-Doku, Labor).
- AV-D: Ordner oder Marker für kuratierte Task Sequences; Revisionswirkung eines Kommentars an bestehenden Collections (Labor).
- Site-Abnahme von AV-B und AV-C nach dem Cutover.
