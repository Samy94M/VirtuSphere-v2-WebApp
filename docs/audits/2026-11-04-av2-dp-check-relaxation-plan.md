# Plan AV2: DP-Prüfung des Autoimporters entschärfen

**Stand:** 04.11.2026. Vom Nutzer freigegeben („Ja, umsetzen“, 04.11.2026) und umgesetzt (Common-Vertrag 3, `autoimporter/2.3`, ADR-0034 Amendment 7). Offen: Laborabnahme nach Abschnitt 8 und Rollout per `-Upgrade -ServerOnly` mit dem nächsten Release. Gemessen am Stand `608eb014` (Release 2026.11.3).

**Nutzerentscheidung 04.11.2026:** „unabhängig von dem prod stand und historie soll es ja immer einwandfrei funktionieren, virtusphere und die skripte sollen die dps nicht so streng prüfen, sie sollen ja nur die pakete auslesen auf mecm und in virtusphere anzeigen sodass man sie in virtusphere den geräten/vms zuweisen kann“.

## 1. Messung

### 1.1 Lage auf Prod (`autoimporter/2.2+2026.11.3`)

23 dauerhaft offene Punkte, Lauf bleibt `warning`, der Dateistand wird nie gemerkt (Vollscan jede Minute, AV-F15):

| Lage | Anzahl | Sperrstelle (`mecm_autoimporter.ps1`) | Folge |
|---|---|---|---|
| L1 DP ersetzt, Baseline nennt alten DP, Manifest unverändert | 16 | Z. 586 bis 593, „Ein bisheriges DP-Ziel fehlt“ | `continue`: Collection, Deployments und Bereinigung übersprungen |
| L1b DP ersetzt, neues Manifest | 1 | Z. 504 bis 512, „DP-Zielmenge weicht ab“ | neuer Inhalt wird nie angefordert, Collection/Deployment übersprungen |
| L2 Application neu angelegt (neue ModelName/PackageID) | 4 | Z. 434 bis 440, „Application-Contentidentität weicht ab“ | `continue` wie oben |
| L3 DPs offline, Aggregat `failed` | 2 | Z. 609 und 617 | offener Punkt `package_content_failed` für immer |

Gemessen vom Koordinator (04.11.): Die Site listet 8 DPs. Der DP aus der gespeicherten Baseline existiert in MECM nicht mehr, an seiner Stelle steht ein neuer DP. L1 und L1b sind also kein vorübergehender Lesefehler: Die alte Prüfung konnte nie wieder grün werden. Die Migration (Abschnitt 4) übernimmt aus dem alten Tracking deshalb nur Manifest und Identität, nie die DP-Baseline.

### 1.2 Alle Stellen, die wegen DP- oder Identitätsevidenz sperren

| Nr. | Stelle | Prüfung heute | Neu |
|---|---|---|---|
| S1 | Z. 234 | Applicationname mehrdeutig | **behalten** (Mehrdeutigkeit, `package_definition_drift`) |
| S2 | Z. 326 | Deployment Type fehlt, mehrdeutig oder fremder DT | **behalten** (Definition, kein DP-Thema) |
| S3 | Z. 335 | vollständiges Nachlesen der Application nicht eindeutig | **behalten** (Mehrdeutigkeit) |
| S4 | Z. 378 | Application-/DT-Identitätsfelder unlesbar | **herabstufen**: kein Contentauftrag, Collection/Deployment laufen weiter; offener Punkt `package_content_unknown` bleibt (eigenes Objekt unlesbar, kein DP-Zustand) |
| S5 | Z. 384 | DP-Kopierprojektion (`SMS_PackageStatusDistPointsSummarizer`) unlesbar | **entfernen**, die Abfrage entfällt ganz |
| S6 | Z. 392 | Aggregat (`Get-CMDistributionStatus`) unlesbar | **herabstufen**: nur wenn gerade ein Auftrag nötig ist und die Art (Erst/Update) nicht bestimmbar ist, offener Punkt und Wiederholung; sonst nur Logzeile. Collection/Deployment laufen immer weiter |
| S7 | Z. 398 | Zielzahl Aggregat ≠ Kopierprojektion | **entfernen** |
| S8 | Z. 404, 535 | Löschzustand auf einem DP, Baseline „bereit“ | **entfernen** |
| S9 | Z. 416 | Tracking unlesbar | **entfernen**, wird wie „kein Tracking“ behandelt (höchstens ein Auftrag) |
| S10 | Z. 423 | Altes Tracking-Schema | **entfernen**, Migration (Abschnitt 4) |
| S11 | Z. 434 | Application-Identität weicht ab (L2) | **ersetzen**: Neu binden, wenn die Application VirtuSphere gehört (Marker in der Beschreibung oder Lage im Ordner `VirtuSphere_Applications`). Sonst fremd: kein Contentauftrag und kein Deployment, offener Punkt `package_definition_drift` |
| S12 | Z. 441, 496 | DT-Modell, DT-ID oder ContentId weicht ab (DT neu, Contentupdate von Hand) | **entfernen**; ein von Hand angestoßenes Update ist Sache von MECM |
| S13 | Z. 457 bis 493 | unbestätigter Intent, Bindung an neue ContentId | **vereinfachen** (Abschnitt 3) |
| S14 | Z. 504 bis 512 | neues Manifest, DP-Zielmenge weicht ab (L1b) | **entfernen** |
| S15 | Z. 518 | DP-Gruppe nicht konfiguriert | **behalten als offenen Punkt**, aber Collection/Deployment laufen weiter |
| S16 | Z. 528 | DP-Gruppe ohne Mitglieder | **behalten als offenen Punkt** (`package_content_group_empty`), Collection/Deployment laufen weiter |
| S17 | Z. 586 bis 593 | bisheriges DP-Ziel fehlt (L1) | **entfernen** |
| S18 | Z. 595 bis 621 | Abschluss nur mit neuerem `LastCopied` aller Ziele; `failed`/`in_progress` als offener Punkt (L3) | **zu Hinweis herabstufen**: kein offener Punkt, Lauf bleibt `ok` |
| S19 | Common Z. 2784 | Bereinigung (`removeOldVersion`) verlangt `pending`/`complete`, sichere Zielevidenz | **vereinfachen**: verlangt bestätigten Auftrag für die aktuelle Identität und geprüftes Deployment, keine Zielevidenz |

In `VirtuSphere-Common.ps1` entfallen ersatzlos `Get-VsDistributionCopySnapshot`, `ConvertTo-VsDistributionBaselineJson`, `Test-VsDistributionCopyBaselineReady`, `Test-VsDistributionCopyAdvanced`, `Test-VsDistributionCopyEvidence` und `ConvertTo-VsProviderUtcTicks` (Z. 974 bis 1153). Die Tracking-Funktionen (Z. 1502 bis 1613) werden auf das neue Schema umgestellt.

### 1.3 Portal, Packages-Sync, Device-Sync

Kein Portalpfad und kein anderes Serverskript liest DP-Zustand oder Content-Tracking. Paketanzeige und Zuweisung hängen nur an der Collection im Ordner `VirtuSphere_Applications` (Packages-Sync). Die Sperren wirken indirekt: wo der Autoimporter vor der Collection abbricht, fehlt das Paket im Katalog oder bekommt kein Deployment. Die Ampel der Quelle folgt nur `outcome`; der Verteilabschnitt der Karte liest die Ursachencodes aus `detail` auch bei `ok`. Der Wire-Vertrag (`reportRun`) erlaubt `detail` unabhängig vom Ergebnis, Summary-Keys bleiben unverändert.

Der Client-Installer (`VirtuSphere-ClientBundles.ps1`, Abschlussnachweis je DP) ist ein von Hand gestarteter Installationsschritt mit eigenem Vertrag und nicht Teil von AV2.

## 2. Vereinfachter Vertrag

1. **DP-Verteilung gehört MECM.** Der Autoimporter fordert höchstens an (Erstverteilung an die DP-Gruppe oder `Update-CMDistributionPoint`), liest den Stand und meldet ihn als Hinweis. Kein DP-Zustand, kein ersetzter oder entfernter DP, kein fehlender Kopiernachweis und kein Löschzustand verhindert Collection, Deployment, Paketanzeige, Zuweisung oder Bereinigung, und keiner hält die Quelle auf `warning`/`danger`.
2. **Höchstens ein angenommener Auftrag je Manifest und Application.** Ein Auftrag ist an Manifest und Application-Identität (ModelName, PackageID) gebunden. Gleiches Manifest: kein weiterer Auftrag, auch nicht bei `failed`. Neues Manifest: genau ein Auftrag, unabhängig vom DP-Stand.
3. **Sicherheitsgrenzen, die bleiben:**
   - *Keine Doppelanlage:* Application und Collection werden nur nach exakter Namenssuche angelegt, und ein unvollständiges Objekt wird zurückgebaut (unverändert).
   - *Keine Änderung außerhalb des VirtuSphere-Bestands:* Eine namensgleich neu angelegte Application wird nur übernommen, wenn sie den Marker trägt oder im Ordner `VirtuSphere_Applications` liegt. Begründung: Der Name allein beweist kein Eigentum (ADR-0034 Amendment 1 bleibt gültig).
   - *Mehrdeutigkeit bleibt Fehler:* zwei Applications oder Deployment Types gleichen Namens, oder ein fremder Deployment Type, sperren das Paket wie heute (S1 bis S3).
   - *Keine Auftragsschleife:* siehe Abschnitt 3; ein abgebrochener oder abgelehnter Aufruf wird nur wiederholt, wenn MECM ihn nachweislich nicht angenommen hat.
4. **Offene Punkte (Lauf `warning`) nur noch für echte Fehler:** Mehrdeutigkeit, fremde Application, eigene Identität unlesbar, DP-Gruppe fehlt oder leer, Auftrag von MECM abgelehnt, Vorlage, Collection, Deployment, Ordner, Bereinigung, `config.json`.
5. **Hinweise (Lauf bleibt `ok`):** `package_content_in_progress` (MECM verteilt noch) und `package_content_failed` (mindestens ein DP meldet einen Fehler, etwa weil er offline ist). `package_content_failed` bedeutet künftig nur noch das; die fehlende DP-Gruppe und ein abgelehnter Aufruf bekommen den neuen Code `package_content_request_failed`.
6. **Hinweise bleiben sichtbar, ohne Vollscan:** Ruhige Läufe ohne Dateiänderung melden die Verteilhinweise des letzten Scans weiter (aus dem Prozessspeicher, AV-B „gemerkte Befunde“). Höchstens alle 30 Minuten liest der Autoimporter für die Pakete mit Hinweis nur `Get-CMDistributionStatus` neu und streicht erledigte Hinweise. Das ist kein Abgleich und ändert nichts in MECM (AV-R07 bleibt gewahrt). Nach einem Neustart baut der erste Vollscan sie neu auf.

## 3. Auftragsablauf je Paket (neu)

```
Application eindeutig, genau ein DT (sonst Sperre wie heute)
Identität lesbar? nein -> offener Punkt, kein Auftrag, weiter zu Collection/Deployment
Tracking lesen (neu, sonst migriert aus altem Schlüssel)
Identität im Tracking ≠ aktuelle?
   Application VirtuSphere-eigen? nein -> offener Punkt package_definition_drift, Paket übersprungen
                                  ja   -> Tracking gilt als Stand dieser Application (Manifest bleibt Vergleichswert)
Zustand intent (Aufruf nicht bestätigt)?
   MECM hat angenommen (Update: DT-ContentId ≠ Baseline; Erst: Aggregat zeigt Ziele) -> requested, kein Aufruf
   sonst -> Aufruf wiederholen
Manifest ≠ Tracking oder kein Tracking oder Aggregat not_started?
   DP-Gruppe fehlt/leer bei Erstverteilung -> offener Punkt, kein Aufruf
   intent schreiben -> Aufruf -> requested schreiben; Fehler -> offener Punkt package_content_request_failed
Aggregat failed/in_progress -> Hinweis; unknown -> Logzeile
Collection, Deployments, Bereinigung wie heute
```

Neuer Trackingschlüssel `HKLM:\SOFTWARE\VirtuSphere\MECM\ContentRequests\<SHA-256 des Namens>`, Felder: `Schema=3`, `ApplicationName`, `State` (`intent`|`requested`), `Manifest`, `RequestKind`, `ApplicationModelName`, `ApplicationPackageId`, `BaselineContentId`, `RequestedAtUtc`. `State` bleibt letzter Commit-Marker.

## 4. Vorhandene Trackingstände auf Prod (Migration ohne Handarbeit)

Der alte Schlüssel `ContentTracking` wird nur gelesen, nie geändert oder gelöscht. Das hält einen Rückweg auf 2026.11.3 offen: Die alte Version findet ihre alten Daten und zeigt die heutigen 23 Punkte, nichts Schlimmeres. Liegt für ein Paket noch kein neuer Schlüssel vor, wird der alte einmal übernommen:

| Alter Stand | Übernahme | Auftrag nach dem Update |
|---|---|---|
| Schema 2 `pending`/`complete`, oder `intent` bestätigt | `requested` mit altem Manifest und alter Identität | nur wenn das Manifest heute anders ist (L1b: genau einer) |
| Schema 2 `intent` unbestätigt | `intent` mit alter Baseline-ContentId | nur wenn MECM ihn nachweislich nicht angenommen hat |
| Schema 1 (ohne Identität) | `requested` mit altem Manifest, leere Identität -> Eigentumsprüfung wie bei L2 | nur bei anderem Manifest |
| unlesbar oder fehlend | kein Tracking | ein Auftrag (wie heute bei einem neuen Server) |

Wirkung auf die gemessenen Lagen im ersten Scan nach `-Upgrade -ServerOnly`:

- **L1 (16):** gleiches Manifest, gleiche Identität: kein Auftrag, Collection/Deployment werden geprüft, Lauf ohne offenen Punkt; ein offline DP erscheint als Hinweis.
- **L1b (1):** genau ein Updateauftrag für den neuen Inhalt, danach Hinweis „verteilt noch“. Das ist keine blinde Zweitverteilung, der Inhalt ist neu.
- **L2 (4):** Application im VirtuSphere-Ordner oder mit Marker: neu gebunden, bei gleichem Manifest ohne Auftrag; war die neue Application noch nie verteilt, genau eine Erstverteilung an die DP-Gruppe.
- **L3 (2):** Hinweis `package_content_failed`, Lauf `ok`.

Damit verschwinden alle 23 offenen Punkte im ersten Lauf, sofern die vier Applications aus L2 im Ordner liegen oder den Marker tragen (Laborfrage unten).

## 5. ADR-0034 Amendment 7 (übernommen)

> **Amendment 7 (04.11.2026): DP-Verteilung nur anfordern und anzeigen (AV2)**
>
> Ersetzt die DP-Grenzen aus Amendment 5 und 6. Der Autoimporter bindet einen Contentauftrag nur noch an Manifest und Application-Identität. Er fordert je neuem Manifest höchstens einen von MECM angenommenen Auftrag an (Erstverteilung an die DP-Gruppe oder neue Contentversion) und liest danach nur das Aggregat von `Get-CMDistributionStatus`. Kopiernachweise je DP, DP-Zielmengen, Löschzustände und ContentId-Wechsel sind keine Sperre mehr; `failed` und `in_progress` sind Hinweise, keine offenen Punkte, und halten weder den Dateistand noch die Ampel. Collection, Deployments und die Bereinigung über `removeOldVersion` setzen nur einen bestätigten Auftrag für die aktuelle Identität voraus. Bestehen bleiben: keine Doppelanlage, Mehrdeutigkeit als Fehler, keine Übernahme einer namensgleichen Application ohne Marker außerhalb von `VirtuSphere_Applications`, Wiederholung eines Aufrufs nur ohne Annahmenachweis. Das Trackingschema 3 liegt unter einem neuen Schlüssel; der Schema-2-Bestand wird gelesen und übernommen, nicht verändert.
>
> Grund: Nutzerentscheidung 04.11.2026. Ein ersetzter DP und neu angelegte Applications hielten auf Prod 21 funktionierende Pakete dauerhaft gesperrt.

## 6. Verhältnis zu früheren Entscheidungen

- **AV-R01/AV-R04 (Plan 28.09., Paket AV-B offen):** Dort soll eine Dateiänderung ohne Versionsanhebung gar keinen Contentauftrag auslösen. Heute löst sie einen aus, und die Vorlagenpflege braucht das (eine neue Vorlage ändert jedes Paket). AV2 behält „ein Auftrag je neuem Manifest“ und widerspricht AV-B nicht: Mit AV-B wird die Regel „neues Manifest“ zu „neue Application“ enger, der Rest von AV2 bleibt.
- **AV-R07 (kein Abgleich ohne Dateiänderung):** gewahrt; die Auffrischung der Hinweise liest nur Status.
- **AV-F15 (Vollscan jede Minute bei offline DP):** für DP-Zustände erledigt; echte offene Punkte scannen weiter jeden Takt.
- **AV-E22:** unverändert gültig.
- **Memory „DP-Verteilung nur anzeigen“:** ist die Grundlage dieses Plans.

## 7. Umsetzung (nach Freigabe)

- `mecm_autoimporter.ps1`: Content-Abschnitt Z. 360 bis 630 neu, Hinweisgedächtnis und Statusauffrischung, Version `autoimporter/2.3`.
- `VirtuSphere-Common.ps1`: Kopiernachweis-Funktionen entfernen, Tracking Schema 3 samt Übernahme, Eigentumsprüfung `Test-VsManagedApplication` (Marker oder `ObjectPath`), neuer Ursachencode, Bereinigungsplan ohne Zielevidenz, Common-Vertrag 3 (Installer prüft ihn schon bei Staging und Livebestand). Hunks liegen getrennt von MAC1 (Device-Index, MAC-Zerlegung).
- Pester: Controller-Szenarien über den bestehenden Scan-Harness, darunter die drei Prod-Lagen L1/L1b, L2 (eigen und fremd), L3 mit synthetischen Daten; Migration je Altzustand; keine Schleife bei Annahme ohne Rückmeldung; Vokabular in beide Richtungen.
- Portal: Texte `finding_package_content_*` und Hilfe-Codes DE/EN, neuer Code in `system_status_mecm_panels.php` und `lib/help/system_status.php`, `AutoimporterStatusPresentationTest`. Keine Statuslogik (AI1/DS1 ändern dort andere Schlüssel).
- Doku: ADR-0034 Amendment 7, `mecm-integration.md` (Autoimporter-Abschnitt, Fallzeilen, Laborabnahme), `troubleshooting.md`, `mecm-scheduled-tasks.md` (Diagramm Package Import), `system-status-checks.md` soweit betroffen, `Powershell-MECM/README.md`, `docs/ai/reference/powershell.md`, `docs/ai/contracts/machine.md` (Codeliste), Hinweis im AV-Plan, CHANGELOG unter „Noch in keinem Release“.
- Gates: powershell-syntax, powershell-tests, PHP-Gates, lang-parity, doc-hygiene, doc-semantics.

## 8. Laborabnahme (MECM, nach Rollout)

1. Nach `-Upgrade -ServerOnly`: Portalzeile zeigt `autoimporter/2.3`, erster Lauf ohne die 23 Punkte, `ContentTracking` unverändert, `ContentRequests` neu befüllt.
2. Tageslog: genau ein Updateauftrag für L1b, keiner für L1, L2 und L3.
3. Unverändert erneut scannen: kein Auftrag. Eine Datei ändern: genau ein Auftrag.
4. Offline-DP bleibt Hinweis, Lauf `ok`.

## 9. Offene Punkte nach der Umsetzung

- **Namensgleiche Application ohne gespeicherten Auftrag (vorbestehend, nicht AV2):** Die Fremdprüfung greift nur, wenn ein gespeicherter Auftrag eine andere Identität nennt. Ohne gespeicherten Auftrag verwendet der Autoimporter eine vorhandene namensgleiche Application wie schon am Stand `608eb014` ohne Eigentumsprüfung weiter, fordert Content an und zieht Deployments nach. Eine Sperre dort träfe auch unmarkierten Altbestand (AV-R06). Entscheidung offen; die Doku beschreibt den Ist-Stand.
- **Labels auf der Autoimporter-Karte (vorbestehend):** `package_source_missing`, `package_deploy_failed` und `package_template_failed` haben weder Portal-Label noch Hilfeeintrag, `package_config_invalid` fehlt in der Hilfeliste. Kleiner QoL-Kandidat für ein eigenes Paket.
