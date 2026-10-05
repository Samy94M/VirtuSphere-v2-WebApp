# MECM-Serveraufgaben: Abläufe

Die vier geplanten Aufgaben, die `Powershell-MECM/install-VirtuSphere-MECM.ps1` auf dem MECM-Server einrichtet, als Ablaufdiagramm. Installation, Registry-Werte, Überwachung und Fehlersuche stehen in der [MECM-Integration](mecm-integration.md); dieses Dokument zeigt nur, was die Skripte in welcher Reihenfolge tun.

Die Diagramme beschreiben den ausgelieferten Code. Wer den Ablauf eines der vier Skripte ändert (Reihenfolge, Verzweigung, Meldung), zieht das zugehörige Diagramm im selben Commit nach. Beschlossene, noch nicht gelieferte Änderungen stehen in den Plänen unter `docs/audits/`, nicht hier.

| Aufgabe | Skript | Takt (Standard, Spanne) | Registry-Wert | Tageslog |
|---|---|---|---|---|
| VirtuSphere MECM Devices Sync | `mecm_new-device-sync.ps1` | 10 s, 5 bis 3600 | `DeviceSyncIntervalSeconds` | `JJJJ-MM-TT_device-sync.log` |
| VirtuSphere MECM Packages Sync | `mecm_Packages-TaskSeq-sync.ps1` | 60 s, 10 bis 3600 | `PackagesSyncIntervalSeconds` | `JJJJ-MM-TT_packages-sync.log` |
| VirtuSphere MECM Package Import | `mecm_autoimporter.ps1` | 60 s, 30 bis 3600 | `ImporterIntervalSeconds` | `JJJJ-MM-TT_autoimporter.log` |
| VirtuSphere MECM Site Health | `mecm_site-health.ps1` | 300 s, 60 bis 3600 | `SiteHealthIntervalSeconds` | `JJJJ-MM-TT_site-health.log` |

Die Tageslogs liegen unter dem Registry-Wert `LogRoot` (Standard `C:\Program Files\VirtuSphere\Logs`) und bleiben 30 Tage. Jede Aufgabe meldet ihren Lauf an `mecm_report.php?action=reportRun`; das Portal zeigt ihn unter Systemstatus.

## Gemeinsamer Rahmen

Alle vier Skripte sind Endlosschleifen mit demselben Rahmen. Die Abschnitte darunter zeigen jeweils nur die eigentliche Arbeit eines Laufs.

```mermaid
flowchart TD
  T["Windows-Aufgabenplanung startet powershell.exe -NoProfile -File als SYSTEM, beim Systemstart und stündlich; läuft das Skript noch, startet nichts Neues"]
  T -.->|Absturz| TR["Bis zu drei Neustarts im Minutentakt, danach holt der Stundentrigger das Skript zurück"]
  T --> C{"Registry-Konfiguration vorhanden?"}
  C -->|nein| CW["Fehler ins Log, Registry jede Minute neu lesen"] --> C
  C -->|ja| I["Tageslog öffnen, TLS 1.2 und Zertifikats-Pin setzen, Intervall aus der Registry lesen und auf die erlaubte Spanne begrenzen"]
  I --> S["Neuer Lauf: Lauf-ID, „started“ melden (Site Health meldet nur „completed“)"]
  S --> A["Eigentliche Arbeit (Abschnitte unten)"]
  A --> F{"Fehler im Lauf?"}
  F -->|ja| E["Ergebnis „fail“; Devices und Packages Sync pausieren 30 s, ab dem dritten Fehler in Folge 60 s und bauen die MECM-Verbindung neu auf; der Autoimporter pausiert immer 60 s, baut neu auf und scannt beim nächsten Lauf voll; Site Health pausiert immer sein Intervall"]
  F -->|nein| D
  E --> D["„completed“ melden: Ergebnis (ok, warning, fail, unknown), Dauer, Zähler, Ursachen; Timeout 5 s, ein Zustellfehler landet nur im Log"]
  D --> P["Pause bis zum nächsten Lauf"]
  P -.->|nächster Lauf| S
```

## Devices Sync

Trägt VMs aus der Warteschlange des Portals in MECM ein und setzt ihre Mitgliedschaften in OS-, Paket- und Missions-Collections. In der Warteschlange stehen neue VMs vor dem Rollout und VMs, deren Zuweisungen jemand an MECM übertragen hat. Jede Mitgliedschaftsänderung steht vorher im Journal, damit ein Absturz nichts Unklares hinterlässt.

```mermaid
flowchart TD
  L["Einmal beim Start: Mitgliedschafts-Journal für die ganze Laufzeit sperren"] --> LJ{"Sperre und Journal in Ordnung?"}
  LJ -->|nein| LX["Skript endet ohne MECM-Änderung und ohne Meldung ans Portal (Ursache nur im Serverlog); die Aufgabenplanung startet es neu. Liegt eine Journal-Quarantäne vor, endet jeder Neustart genauso, bis sie geklärt ist"]
  LJ -->|ja| S["Neuer Lauf, „started“ melden"]
  S --> Q["Warteschlange holen (getDeviceList): neue VMs und zur Übertragung markierte"]
  Q --> QE{"Warteschlange leer?"}
  QE -->|ja| R
  QE -->|nein| M["Mit MECM verbinden (einmal), alle Geräte, Task Sequences und Device Collections lesen; je Task Sequence eine Collection anlegen"]
  M --> V
  subgraph JE["Je VM in der Warteschlange"]
    V{"Mission und DHCP-MAC vorhanden?"}
    V -->|nein| V1["VM übersprungen, bleibt in der Warteschlange"]
    V -->|ja| MC["Mission-Collection anlegen, falls sie fehlt"]
    MC --> ID{"Identität eindeutig? (Rolloutname, MAC, ResourceID)"}
    ID -->|nein| ID1["Keine Änderung, VM bleibt mit Ursache in der Warteschlange"]
    ID -->|ja| G{"Gerät schon in MECM?"}
    G -->|nein| IMP["Rolloutname und MAC importieren und nachlesen; nicht sichtbar oder Fehler: VM wartet auf den nächsten Lauf"]
    IMP -->|importiert| RID
    G -->|ja| RID["ResourceID lesen; fehlt sie: nächster Lauf. Die vorgesehene Freigabe per Approve-CMDevice läuft nur ohne ResourceID und wird praktisch nie erreicht (PS-05)"]
    RID --> J{"Offene Einträge im Mitgliedschafts-Journal?"}
    J -->|ja| J1["Erst nachmelden; unklare Einträge bleiben zur Klärung stehen; VM wartet auf den nächsten Lauf"]
    J -->|nein| PL["Plan: Soll (OS-, Paket-, Missions-Collection) gegen eigene und vorhandene Regeln"]
    PL --> SE{"Soll eindeutig, eigene Regeln mit gültiger Provenienz?"}
    SE -->|nein| SE1["Keine Änderung, VM bleibt in der Warteschlange (membership_identity_ambiguous, membership_provenance_invalid)"]
    SE -->|ja| RD{"Mitgliedschaften lesbar?"}
    RD -->|nein| RD1["Keine Änderung, VM bleibt in der Warteschlange (membership_query_failed)"]
    RD -->|ja| AP["Fehlende Direktregeln hinzufügen, eigene, nicht mehr gewünschte entfernen; jede Änderung steht vorher im Journal; fehlt eine Collection: collection_missing"]
    AP --> RM["Änderungen melden (reportMembership); abgelehnt: VM bleibt in der Warteschlange"]
    RM --> OK{"Alle Zuweisungen gesetzt?"}
    OK -->|nein| OK1["ResourceID nicht melden, VM bleibt in der Warteschlange"]
    OK -->|ja| UD["ResourceID und Übertragungszähler melden (updateDevice): das Portal bindet die ResourceID, setzt „registriert“ und nimmt die VM aus der Warteschlange; wurde sie während des Laufs erneut übertragen, bleibt sie für den nächsten Lauf drin"]
    UD -.->|abgelehnt| UD1["Warnung resource_update_failed, bei überholter Rollout-Revision stale_rollout_revision; VM bleibt in der Warteschlange"]
  end
  UD --> CU["Aktualisierung geänderter Collections anstoßen"]
  CU --> R["„completed“ melden (empfangen, importiert, Fehler, Warnungen); Pause 10 s"]
  R -.->|nächster Lauf| S
```

## Packages Sync

Meldet dem Portal den MECM-Katalog: Jede Device Collection im Ordner `VirtuSphere_Applications` ist ein Paket, jede Task Sequence ein Betriebssystem. Gesendet wird nur bei Änderung oder spätestens stündlich. Das Portal zieht fehlende Einträge zurück, löscht sie aber nicht; der Wartungsdienst entfernt zurückgezogene Pakete erst nach `VIRTUSPHERE_PACKAGE_PURGE_AFTER_DAYS` Tagen und nur, wenn keine VM sie mehr nutzt und ihre Zuweisungen nie umgehängt wurden; ein Paket, dessen Zuweisungen einmal auf eine höhere Version umgehängt wurden, bleibt dauerhaft stehen.

```mermaid
flowchart TD
  S["Neuer Lauf, „started“ melden"] --> M["Mit MECM verbinden (einmal)"]
  M --> F{"Ordner VirtuSphere_Applications gefunden? Ein Providerfehler beim Suchen zählt als nicht gefunden"}
  F -->|nein| F1["Nichts senden (Sende-Schutz), Warnung source_missing"] --> R
  F -->|ja| K["Collections im Ordner (Pakete) und alle Task Sequences (Betriebssysteme) lesen"]
  K --> E{"Katalog leer?"}
  E -->|ja| E1["Nichts senden, Warnung source_missing"] --> R
  E -->|nein| U{"Unverändert und letzter Versand unter einer Stunde?"}
  U -->|ja| U1["Nichts senden, Ergebnis „ok“ (unverändert)"] --> R
  U -->|nein| P["Katalog an mecm_packages.php senden"]
  P --> T{"Portal: mindestens VIRTUSPHERE_PACKAGE_RETIRE_MIN_ACTIVE aktive Einträge, und fehlen mehr als die eingestellte Schwelle (5 bis 90 %, Standard 30 %)?"}
  T -->|ja| T1["Portal lehnt ab (409), Warnung catalog_conflict; der nächste Lauf sendet erneut"] --> R
  T -->|nein| RT["Portal: fehlende Einträge „zurückgezogen“, neue und wieder aufgetauchte aktiv"]
  RT --> RL["Portal: VM-Zuweisungen auf eine höhere Version umhängen, wenn diese in diesem Katalog neu ist; sonst „Update verfügbar“ im VM-Editor"]
  RL --> H["Gesendeten Katalogstand für den nächsten Vergleich merken"]
  H --> R["„completed“ melden (Pakete, Task Sequences, gesendet); Pause 60 s"]
  R -.->|nächster Lauf| S
```

## Package Import (Autoimporter)

Macht aus den Paketordnern unter `files` mit ihrer `config.json` MECM-Applications samt Deployment Type und Verteilung, mit `generateOwnDeviceColletion` auch Collection und Deployments. Gescannt wird nur, wenn sich Dateien unter `files` oder `Package_Vorlage` geändert haben oder der letzte Lauf offene Punkte hatte.

```mermaid
flowchart TD
  V{"Beim Start: Common-Vertrag passt?"} -->|nein| V1["Abbruch: Ordner Powershell-MECM vollständig bereitstellen, Installer mit -Upgrade ausführen"]
  V -->|ja| SH{"Paketshare (UNC) konfiguriert?"}
  SH -->|nein| SH1["Warten, Registry jede Minute neu lesen"] --> SH
  SH -->|ja| S["Neuer Lauf, „started“ melden"]
  S --> M["Mit MECM verbinden (einmal), Ordner VirtuSphere_Applications anlegen, falls sie fehlen"]
  M --> H["Prüfsumme über files und Package_Vorlage bilden"]
  H -.->|Datei ändert sich währenddessen| HX["Lauf „fail“ (mecm_unavailable), MECM-Verbindung und Dateistand verwerfen, Pause 60 s"]
  H --> C{"Dateien geändert oder offene Punkte im letzten Lauf?"}
  C -->|nein| C1["Ergebnis „ok“ ohne Scan (unverändert)"] --> R
  C -->|ja| B{"Ordner files vorhanden?"}
  B -->|nein| B1["Warnung source_missing"] --> R
  B -->|ja| RC["Alle config.json lesen (ungültige sind offene Punkte), je Produkt die höchste Version bestimmen"]
  RC --> T
  subgraph JP["Je Paketordner"]
    T["Vorlage (install.ps1, Reporter) ins Paket kopieren, wenn sie abweicht"]
    T -.->|nicht kopierbar| T1["Paket übersprungen, offener Punkt package_template_failed"]
    T --> AM{"Applicationname eindeutig?"}
    AM -->|nein, mehrere| AM1["Paket übersprungen, keine Definition und kein Content verändert, offener Punkt package_definition_drift"]
    AM -->|ja| A{"Application Name-Version vorhanden?"}
    A -->|nein| A1["Application (Marker) und Deployment Type anlegen: install.ps1, Erkennung per Registry-Schlüssel der Version"] --> DT
    A1 -.->|Deployment Type scheitert| A2["Application wieder entfernen; der ganze Lauf endet mit „fail“"]
    A -->|ja| DT{"Genau ein Deployment Type, Identität lesbar?"}
    DT -->|nein| DT1["Paket übersprungen, offener Punkt"]
    DT -->|ja| CR{"Dateien anders als beim letzten Auftrag, oder noch keiner?"}
    CR -->|ja| CR1["Erstverteilung an die DP-Gruppe oder neue Contentversion anfordern"] --> TR
    CR -->|nein| TR["Auftrag binden, Verteilung verfolgen; offen oder fehlerhaft ist ein offener Punkt"]
    TR --> CO["Nur mit generateOwnDeviceColletion: Collection Name-Version und Deployment „Erforderlich“ anlegen, bei DeployTo zusätzlich „Verfügbar“; Fehlendes nachziehen. Ohne das Feld entsteht keine Collection, und das Paket fehlt im Portalkatalog, weil der Packages Sync nur Collections liest"]
    CO --> RO{"removeOldVersion und höchste Version?"}
    RO -->|ja| RO1["Bereinigungsplan zweimal lesen; bereit: Deployments, Applications und Collections älterer Versionen löschen; gesperrt: offener Punkt"]
  end
  RO -->|nein| OP
  RO1 --> OP{"Lauf ohne offene Punkte?"}
  OP -->|ja| OP1["Dateistand merken: der nächste Lauf ohne Änderung bleibt ruhig"] --> R
  OP -->|nein| R["„completed“ melden (Ordner, angelegt, entfernt, offene Punkte; mit offenen Punkten „warning“); Pause 60 s"]
  R -.->|nächster Lauf| S
```

## Site Health

Fragt den Status der konfigurierten MECM-Site ab und meldet ihn als Ampel. Gemeldet werden nur Site-Code, Provider und der Rohstatus, nie der Text der MECM-Statusmeldung.

```mermaid
flowchart TD
  S["Neuer Lauf, ohne „started“"] --> P["SMS-Provider bestimmen: Registry, lokale WMI, CMSite-Laufwerk, sonst lokaler Rechner"]
  P --> K{"Site-Code in der Registry?"}
  K -->|nein| U1["„unknown“ (query_failed)"] --> R
  K -->|ja| Q["SMS_SummarizerSiteStatus per CIM lesen, ohne ConfigurationManager-Modul"]
  Q --> QA{"Abfrage erfolgreich?"}
  QA -->|nein| U2["„unknown“: Zugriff verweigert ergibt provider_access_denied; nicht erreichbar ergibt provider_unreachable erst ab dem zweiten Fehlzyklus in Folge, vorher query_failed"] --> R
  QA -->|ja| E{"Genau ein Eintrag mit Status?"}
  E -->|nein| U3["„unknown“ (query_failed)"] --> R
  E -->|ja| M["Status 0 „ok“, 1 „warning“ (site_warning), 2 „fail“ (site_critical), sonst „unknown“"]
  M --> R["„completed“ melden mit Site-Code, Provider und Rohstatus; Pause 300 s"]
  R -.->|nächster Lauf| S
```
