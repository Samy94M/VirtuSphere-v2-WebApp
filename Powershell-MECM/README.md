# VirtuSphere MECM-Integration – PowerShell

Diese Skripte verbinden den MECM-Server mit der VirtuSphere-WebApp. Sie
laufen als geplante Aufgaben auf dem MECM-Server (`mecm/`) bzw. werden über das
MECM-Software-Center auf die PXE-installierten Clients verteilt (`clients/`).

Alle umgebungsspezifischen Werte der **Server-Skripte** (Adresse der WebApp,
Pfade, Site-Code) kommen aus der Registry
`HKLM:\SOFTWARE\VirtuSphere\MECM` und stehen **nicht** im Server-Code. Diese
Registry schreibt der Server-Installer. Die separat verteilten Client-Skripte
verwenden ihre dokumentierte Registry-/DNS-/IP-Fallback-Kette.

## Erstinstallation auf dem MECM-Server (3 Schritte)

Die vollständig chronologische Anleitung für wechselndes Adminpersonal steht im
Abschnitt **„Admin-Runbook: MECM erstmals anbinden“** in
[`docs/operations/mecm-integration.md`](../docs/operations/mecm-integration.md).
Sie enthält die Variante ohne DNS, die Ermittlung von DP-Gruppe und Freigaben,
beide Installer und die Abnahmecheckliste.

1. Dieses Verzeichnis auf den MECM-Server kopieren, PowerShell **als
   Administrator** öffnen.
2. Installer ausführen (Adresse und UNC-Freigabe anpassen):

   ```powershell
   .\install-VirtuSphere-MECM.ps1 -WebApi virtusphere.lan:8021 `
       -PackagesShare \\MECM-01\VirtuSphere\Packages\files
   ```

   Optionaler Rückkanal-Token (vorher im Portal unter *Einstellungen →
   Rückkanal-Token* generieren): den Installer **ohne** `-ReportToken` starten,
   dann fragt er den Token verdeckt ab (`Read-Host -AsSecureString`) und schreibt
   ihn in die nur für Administratoren lesbare Registry. Den Token nicht als
   Klartext-Argument übergeben, da er sonst in der PowerShell-History und
   Prozessliste sichtbar bleibt.
3. Im Portal unter *Einstellungen → Machine-API IP-Freigaben* die IP des
   MECM-Servers freischalten, dann den *Systemstatus* beobachten – die
   drei Sync-Quellen und der MECM-Site-Status sollten auf „OK“ springen.

Ist noch kein DNS-Eintrag verfügbar, darf der Server-Installer vorläufig eine
feste IP als `-WebApi '<WEBAPP-IP>:8021'` erhalten. Vor dem Client-Installer muss
dann zusätzlich `$VsFallbackIpApi` in
`clients/VirtuSphere-Client-Common.ps1` gesetzt werden. Die vollständige
Übergangs- und spätere DNS-Wechselprozedur steht im Admin-Runbook.

Der Installer ist idempotent: erneutes Ausführen aktualisiert Konfiguration und
Skripte. Die vier Intervalle und `MECM_ProviderMachine` behalten dabei ihren
eingestellten Wert, wenn der jeweilige Parameter nicht angegeben wird; ein
Skript-Update setzt einen getunten Takt also nicht auf den Standard zurück.

## Die vier geplanten Aufgaben (MECM-Server)

| Aufgabe | Skript | Zweck | Intervall |
|---|---|---|---|
| VirtuSphere MECM Devices Sync | `mecm_new-device-sync.ps1` | VMs aus VirtuSphere nach MECM importieren, Collections zuweisen, ResourceID zurückmelden | 10 s |
| VirtuSphere MECM Packages Sync | `mecm_Packages-TaskSeq-sync.ps1` | Paket-/Task-Sequence-Katalog an die WebApp melden | 60 s |
| VirtuSphere MECM Package Import | `mecm_autoimporter.ps1` | aus `config.json`-Ordnern MECM-Applications erzeugen | 60 s |
| VirtuSphere MECM Site Health | `mecm_site-health.ps1` | offiziellen MECM-Site-Zustand aus `SMS_SummarizerSiteStatus` melden | 300 s |

Alle vier laufen als `NT AUTHORITY\SYSTEM`, höchste Rechte, Start beim Systemstart
(`AtStartup`), Doppelstart-Schutz `MultipleInstances IgnoreNew`, **ohne
Laufzeitlimit** (`PT0S`, Endlosschleifen) und mit Auto-Neustart bei Absturz.

Die Intervalle sind Registry-owned (Installerparameter, Spalte oben = Standard)
und je Aufgabe auf 5/10/30/60 s bis 3600 s begrenzt; die Spanne steht in
`$script:VsIntervalBounds` (`mecm\VirtuSphere-Common.ps1`), der Installer
spiegelt sie in `ValidateRange`. Ein Wert außerhalb wird beim Start geklemmt
**und protokolliert**: die Aufgabe darf nie in einem anderen Takt laufen als in
dem, den sie meldet und den der Systemstatus als geltend anzeigt. Ein Re-Run des
Installers ohne den jeweiligen Parameter behält den eingestellten Wert.

Die drei Sync-Aufgaben melden je Lauf einen **Ergebnisbericht** an
`mecm_report.php?action=reportRun`: `started` vor der Arbeit, `completed` im
`finally` mit Ergebnis (`ok`/`warning`/`fail`/`unknown`) und quellenspezifischen
Zählern. Die Site-Health-Aufgabe sendet nur `completed`. Ein toter Task erscheint
im *Systemstatus* als „Ausgefallen"; ein Alt-Skript, das nur `Send-VsHeartbeat`
sendet, erscheint gelb als „Legacy: Ergebnis nicht bestätigt". `Send-VsHeartbeat`
bleibt für Rückwärtskompatibilität erhalten, wird von den aktuellen Skripten aber
nicht mehr genutzt.

**Logs:** `%ProgramFiles%\VirtuSphere\Logs\<datum>_<komponente>.log`
(einheitliches Format, 30 Tage Aufbewahrung; Site Health:
`<datum>_site-health.log`).

## Architektur und Funktionsweise

### VirtuSphere-Common.ps1 – die gemeinsame Bibliothek

`VirtuSphere-Common.ps1` läuft **nie selbst** und hat keine eigene geplante
Aufgabe. Es ist eine reine Funktionsbibliothek, die jedes Sync-Skript in der
ersten Zeile per Dot-Sourcing in den eigenen Scope lädt:

```powershell
. "$PSScriptRoot\VirtuSphere-Common.ps1"
```

Das wirkt, als stünde der Inhalt von Common direkt im aufrufenden Skript.
Danach sind dort alle Hilfsfunktionen verfügbar. Wichtig für das mentale
Modell: Jede der vier Aufgaben ist ein **eigener PowerShell-Prozess** mit
einer **eigenen Kopie** von Common; die Prozesse teilen zur Laufzeit keinen
Zustand (jeder hat z. B. seine eigene Log-Komponente und Korrelations-ID).

Common stellt bereit:

| Funktion | Zweck |
|---|---|
| `Get-VsConfig` | liest die komplette Konfiguration aus `HKLM:\SOFTWARE\VirtuSphere\MECM`; liefert `$null`, wenn sie fehlt |
| `Initialize-VsLog` / `Write-VsLog` | Tageslogdateien im Format `ISO-8601 \| LEVEL \| Komponente \| Kontext \| Nachricht \| Korrelations-ID`; Aufräumen nach 30 Tagen; Log-Fehler stoppen nie den Hauptprozess |
| `Invoke-VsApi` / `Get-VsApiBaseUrl` | HTTP-Aufrufe an die WebApp; `Get-VsApiBaseUrl` ist die **einzige** Schema-Stelle der Server-Skripte und liest `Scheme` aus der Registry (Default `http`, LAN-Projektziel) |
| `Get-VsErrorDetail` / `Get-VsErrorStatusCode` | lesen den **Antwort-Body** einer fehlgeschlagenen Anfrage. `Invoke-RestMethod` wirft in PS 5.1 bei 4xx/5xx und verwirft den Body dabei — genau dort steht aber die JSON-Envelope der WebApp (`{"error":"..."}`). Ohne diese Helfer sagt das Log nur `(400) Bad Request`, nie den Grund |
| `Resolve-VsInterval` | löst das konfigurierte Intervall **einmal** auf, für den Sleep und den Report: Untergrenze je Aufgabe aus `$script:VsIntervalBounds`, Obergrenze aus dem Wire-Contract, und eine WARN-Zeile, wenn geklemmt wurde. Die Statusseite färbt die Zeile nach dem *gemeldeten* Takt, also darf keine Aufgabe in einem anderen laufen |
| `New-VsRunId` / `Send-VsRunReport` | Ergebnisbericht an `mecm_report.php?action=reportRun`: eigene `run_id` je Lauf, `started`/`completed`, Ergebnis, Fehlerkategorie, quellenspezifisches Summary; zentrale Detail-Redaction und Byte-Kürzung vor dem Versand. Ein fehlgeschlagener Bericht bricht den eigentlichen Lauf nie ab; Zustellfehler werden lokal gedrosselt protokolliert |
| `Get-VsProviderMachine` / `Get-VsMecmSiteHealth` | SMS-Provider ermitteln (Installerparameter → Registry `MECM_ProviderMachine` → lokale WMI-Erkennung → CMSite-PSDrive → Computername) und `SMS_SummarizerSiteStatus` per CIM abfragen; reine Statusabbildung `0→ok`, `1→warning`, `2→fail`, sonst `unknown` (ohne MECM per Pester testbar) |
| `Send-VsHeartbeat` | Fire-and-forget-POST an `mecm_report.php?action=heartbeat` (5 s Timeout, Fehler bewusst still); nur noch für Rückwärtskompatibilität, die aktuellen Skripte senden `reportRun` (ADR-0018) |
| `ConvertTo-VsNormalizedMac` | MAC kanonisch: Großbuchstaben, Doppelpunkte; verhindert falsche Konfliktmeldungen zwischen ESXi- und MECM-Schreibweisen. **Existiert dreimal** (hier, im Client-Common, als `virtusphere_normalize_mac()` in PHP) — die drei laufen auf drei Maschinen und teilen sich keine Datei, aber `Docker/WebAPI/tests/fixtures/mac-vectors.json` als gemeinsame Wahrheit: wer eine ändert, ohne die anderen nachzuziehen, bricht den Build (ADR-0029) |
| `Read-VsPackageConfig` / `Get-VsSupersededNamePattern` | `config.json` eines Paketordners lesen und validieren; Muster für die Alt-Versions-Bereinigung (`^Name-<version>$`, exakt — der Wildcard `Name*` löschte früher auch `Firefox-ESR-*`). Liegen hier und nicht im Autoimporter, weil der eine Endlosschleife ist und dort kein Test hinkommt |
| `Get-VsSiteCode` / `Initialize-VsCmSite` | Site-Code dreistufig ermitteln (WMI-Namespace → CMSite-PSDrive → Registry-Fallback), ConfigurationManager-Modul laden, ins Site-Drive wechseln |

Außerdem ist Common die SSoT für den MECM-Ordnernamen
`VirtuSphere_Applications` (`$script:VsApplicationsFolderName`): der
Packages-Sync **liest** diesen Ordner, der Autoimporter **befüllt** ihn.
Weil beide dieselbe Variable nutzen, können die Namen nicht auseinanderlaufen.

### Struktur in der MECM-Konsole

Die Skripte legen ihre Objekte in festen Ordnern ab, damit VirtuSphere-Objekte
von manuell gepflegten MECM-Objekten getrennt bleiben:

```
Assets and Compliance
└── Device Collections
    ├── VirtuSphere_OS            <- Devices Sync: je Task Sequence eine
    │                                 gleichnamige Collection (Betriebssysteme)
    ├── VirtuSphere_Missions      <- Devices Sync: je Mission eine Collection
    └── VirtuSphere_Applications  <- Autoimporter: je Paket eine Collection
                                      "Name-Version"; der Packages Sync liest
                                      GENAU diesen Ordner als Katalogquelle

Software Library
└── Application Management
    └── Applications
        └── VirtuSphere_Applications  <- Autoimporter: die Applications
                                          "Name-Version" selbst
```

Voraussetzung für die Ordner-Automatik: Die Cmdlets `Get-CMFolder` und
`New-CMFolder` gibt es erst ab der MECM-Konsole **Version 2111**. Auf
älteren Konsolen müssen die Ordner manuell angelegt werden.

Wer legt was an:

- Die Ordner `VirtuSphere_OS` und `VirtuSphere_Missions` erstellt der
  **Devices Sync** bei Bedarf selbst.
- Die beiden `VirtuSphere_Applications`-Ordner (Collections und Applications)
  legt der **Autoimporter** bei Bedarf selbst an (Self-Healing, nach jeder
  Site-Initialisierung geprüft). Warnt der Packages Sync trotzdem dauerhaft
  mit dem Sende-Guard, läuft der Autoimporter-Task nicht oder die
  Ordner-Anlage schlägt fehl (siehe Autoimporter-Log).
- Die Devices selbst importiert der Devices Sync in **"All Systems"** und
  weist sie den Collections per Direct-Membership-Regel zu; einen eigenen
  Geräte-Ordner gibt es nicht.
- Deployments (Required auf die eigene Paket-Collection, optional Available
  auf `DeployTo`) erzeugt der Autoimporter; sie erscheinen wie üblich an der
  Application bzw. Collection, nicht in einem eigenen Ordner.

Wichtig: Paket-Collections, die **außerhalb** von `VirtuSphere_Applications`
liegen, tauchen nicht im Portal-Katalog auf; manuell in den Ordner verschobene
Collections dagegen schon.

### Devices Sync (`mecm_new-device-sync.ps1`, alle 10 s)

Synchronisiert VMs aus der VirtuSphere-Datenbank nach MECM. Ablauf je Scan:

1. Geräteliste per `GET /mecm-api.php?action=getDeviceList` laden. Bei
   0 Devices sofort schlafen; die teuren MECM-Vollabfragen entfallen, der
   10-Sekunden-Normalfall ist damit fast kostenlos.
2. MECM-Daten **einmal pro Scan** cachen (alle Devices, Task Sequences,
   Collections in Hashtables) statt Einzelabfragen je Device.
3. Ordner `VirtuSphere_OS` und `VirtuSphere_Missions` sicherstellen; für jede
   Task Sequence eine gleichnamige Collection im OS-Ordner anlegen.
4. Je Device:
   - PXE-MAC aus dem ersten DHCP-Interface ermitteln (Warnung bei mehreren);
     ohne Mission oder MAC wird das Device übersprungen.
   - Mission-Collection bei Bedarf anlegen.
   - MAC-Konflikte (MECM ≠ ESXi) werden **nur gemeldet**, nie automatisch
     korrigiert.
   - Neues Device per `Import-CMComputerInformation` in "All Systems"
     importieren. Schlägt der Import fehl, prüft das Skript per
     Existenzabfrage auf ein Import-Race (kein Fehlertext-Parsing, da die
     Texte je MECM-Version und -Sprache variieren).
   - ResourceID beschaffen, notfalls per `Approve-CMDevice` nachhelfen;
     ohne ResourceID: nächster Scan.
   - Direct-Membership-Regeln für OS-, Paket- und Mission-Collections setzen.
   - ResourceID per `POST /mecm_updateid.php?action=updateDevice` zurückmelden.
5. Collection-Updates gesammelt anstoßen (einmal je geänderter Collection).

### Packages Sync (`mecm_Packages-TaskSeq-sync.ps1`, alle 60 s)

Meldet den verfügbaren Software-Katalog an die WebApp:

- Quelle: Device Collections im Ordner `VirtuSphere_Applications` (= Pakete,
  per WMI-Query) plus alle Task Sequences (= Betriebssysteme).
- **Sende-Guard:** Liefert WMI den Ordner nicht oder ist der Payload leer,
  wird **nicht** gesendet. Ein leerer Payload würde den Katalog serverseitig
  zurückziehen.
- **Change-Detection:** SHA256-Hash über den sortierten JSON-Payload;
  gesendet wird nur bei Änderung, zusätzlich ein erzwungener Voll-Sync pro
  Stunde.
- Versand per `POST /mecm_packages.php`. Antwortet die WebApp mit **409**
  (Schutzschwelle: zu viele Einträge würden retired), wird laut gewarnt und
  der Hash nicht aktualisiert, damit der nächste Durchlauf erneut sendet.

### Autoimporter (`mecm_autoimporter.ps1`, alle 60 s)

Erzeugt aus Paketordnern automatisch MECM-Applications, -Collections und
-Deployments. Quelle: `<PackagesRoot>\files\<Paket>\config.json`,
ContentLocation: `<PackagesShare>\<Paket>` (UNC aus der Registry).

- **Ordner-Self-Healing:** Nach jeder Site-Initialisierung stellt der
  Autoimporter sicher, dass die beiden `VirtuSphere_Applications`-Ordner
  (Applications und Device Collections) existieren, und legt sie sonst an.
- **Change-Detection:** Fingerabdruck über alle `config.json` (Pfad + mtime);
  nur bei Änderung wird voll gescannt.
- **Validierung:** kaputtes JSON oder fehlende Pflichtfelder
  (`ProjectName`, `version`) → Ordner wird übersprungen und protokolliert.
- Je Paket (`Name-Version`):
  - **Alt-Versions-Bereinigung** (bei `removeOldVersion: "true"`): entfernt
    Deployment, Collection und Application alter Versionen, aber nur mit
    exaktem Muster `^Name-<Version>$`. Das behebt den früheren Wildcard-Bug,
    bei dem ein `Firefox`-Update auch `Firefox-ESR-*` löschte.
  - **Application anlegen** (falls neu) mit Script-Deployment-Type:
    Install-Kommando `powershell.exe -ExecutionPolicy Bypass -File install.ps1`,
    Registry-Detection unter `SOFTWARE\VirtuSphere\Packages\Name-Version` (HKLM bzw. HKCU je
    nach `InstallationBehaviorType`).
  - **Collection + Deployment idempotent nachziehen** (bei
    `generateOwnDeviceColletion: "true"`): läuft auch für bestehende Apps und
    heilt frühere Teilfehler (App vorhanden, Collection/Deployment fehlt).
    Content-Verteilung an die DP-Gruppe nur bei neuen Apps, da erneutes
    Verteilen selbst Fehler wirft.
  - Optionales Available-Deployment an die Collection aus `DeployTo`
    (fehlende Ziel-Collection ist ein Konfigurationsfehler; Warnung ohne
    Dauer-Retry).
- Traten Warnungen auf, wird der Datei-Stamp **nicht** gemerkt; der nächste
  Durchlauf wiederholt die offenen Punkte.

### Site Health (`mecm_site-health.ps1`, alle 300 s)

Meldet den offiziellen, zusammengefassten MECM-Site-Zustand an die WebApp, ohne
dass das Portal MECM selbst ansprechen muss (der frühere TCP-445-Check im Portal
ist entfernt, ADR-0018 Amendment 2026-07-23):

- Fragt über den SMS Provider `SMS_SummarizerSiteStatus` für den konfigurierten
  Site-Code ab (Site-Code über `Get-VsSiteCode`). Die Abfrage läuft per CIM ohne
  ConfigurationManager-Modul; das Modul wird nur für den PSDrive-Fallback der
  Provider-Ermittlung geladen.
- Reine Statusabbildung: `0` = OK (grün), `1` = Warnung (gelb), `2` = kritisch
  (rot), jeder andere Rohwert = unbekannt (grau). Sendet ausschließlich
  `completed`-Berichte, kein `started`.
- Providerfehler (nicht erreichbar, Zugriff verweigert, Abfrage fehlgeschlagen)
  werden als `unknown` (grau) gemeldet; Rot ist exklusiv dem von MECM bestätigten
  Status 2 vorbehalten. `provider_unreachable` erst nach zwei aufeinanderfolgenden
  Fehlversuchen, damit ein MECM-Reboot nicht sofort einen Fehler ins Portal
  schreibt.

**Provider und Intervall (Registry, `HKLM:\SOFTWARE\VirtuSphere\MECM`):**

- `MECM_ProviderMachine`: SMS-Provider-Rechner (Installerparameter
  `-ProviderMachine`). Leer lassen, wenn der Provider lokal auf dem Site-Server
  liegt; die Aufgabe ermittelt ihn dann selbst.
- `SiteHealthIntervalSeconds`: Berichtsintervall (Installerparameter
  `-SiteHealthIntervalSeconds`), Standard 300, erlaubt 60–3600.

**Berechtigungen (Remote-Provider):** Das Computerkonto des Site-Servers ist
standardmäßig Mitglied der SMS-Admins-Gruppe auf jedem Provider; liegt der Provider
lokal und läuft die Aufgabe als SYSTEM, ist keine Zusatzkonfiguration nötig. Liegt
der Provider entfernt, braucht das Computerkonto die Provider-Berechtigung, und der
Transport muss erreichbar sein: `Get-CimInstance -ComputerName` nutzt WinRM/WSMan,
klassisches WMI braucht DCOM (RPC 135 plus dynamische Ports, Remote Activation). Ein
fehlendes Recht wird als `provider_access_denied` (grau) gemeldet, nicht als „MECM
kritisch".

### Gemeinsame Robustheits-Muster

Alle vier Dienste teilen dieselbe Überlebensstrategie:

- **Warten statt sterben:** Fehlt beim Start die Registry-Konfiguration (oder
  beim Autoimporter der `PackagesShare`), wartet das Skript in einer
  60-Sekunden-Schleife statt mit `exit 1` abzubrechen. Sonst wären die
  Taskplaner-Neustarts nach Minuten aufgebraucht und der Task bis zum Reboot
  tot.
- **Ergebnisbericht je Durchlauf:** die Sync-Aufgaben senden `started`/`completed`,
  die Site-Health-Aufgabe nur `completed`; das macht tote Tasks und fachliche
  Fehlschläge auf der Portal-Seite *Systemstatus* sichtbar, ohne den Lauf je
  auszubremsen. Ein fehlgeschlagener Bericht bricht den Lauf nie ab.
- **Backoff und Site-Drive-Recovery:** durchgängiges try/catch; nach
  3 Fehlern in Folge wird das Site-Drive verworfen und im nächsten Durchlauf
  neu initialisiert (fängt WMI-/Drive-Hänger ab).
- **Change-Detection vor Arbeit:** Voll-Scans bzw. Sendungen nur bei
  tatsächlicher Änderung; der Leerlauf-Durchlauf kostet Millisekunden.
- Der Devices Sync löst zusätzlich alle 100 Durchläufe eine Garbage
  Collection aus, um Speicherwachstum im Dauerbetrieb zu begrenzen.

## Paket hinzufügen (Autoimporter)

1. Unter `<PackagesRoot>\files\<Paketname>\` einen Ordner mit `config.json` und
   den Installationsdateien ablegen.
2. Der Autoimporter erkennt die Änderung (mtime) und legt automatisch
   Application, Collection und Deployment an. Collection-Name = `Name-Version`.

**Konvention:** Die **Version darf keinen Bindestrich enthalten** (die WebApp
splittet `Name-Version` am letzten Bindestrich).

`config.json` (Pflichtfelder: `ProjectName`, `version`):

```json
{
  "ProjectName": "Firefox",
  "version": "115.0",
  "ErrorAction": "Stop",
  "removeOldVersion": "true",
  "generateOwnDeviceColletion": "true",
  "InstallationBehaviorType": "InstallForSystem",
  "DeployTo": ""
}
```

Ordner ohne `ProjectName`/`version` werden übersprungen und protokolliert. `version`
darf keinen Bindestrich enthalten (der Katalog trennt `Name-Version` am letzten
Bindestrich). `ErrorAction` (`Stop`/`Continue`) steuert die paketeigene
`install.ps1`; `DeployTo` leer lassen, sonst wird ein Available-Deployment an eine
Collection erzeugt, die es meist noch nicht gibt.

> **Hinweis Self-Healing:** Die Standard-`install.ps1` und eine
> `config.json`-Blaupause liefert der Installer aus `Package_Vorlage/` nach
> `<PackagesRoot>\Package_Vorlage`. Beim Anlegen eines Pakets überschreibt
> `Package_Vorlage\install.ps1` die paketeigene `install.ps1`. Das ist
> beabsichtigt (einheitliche Standard-Installation). SSoT ist der Repo-Ordner
> `Package_Vorlage/`; Anpassungen dort vornehmen und den Installer erneut
> ausführen, nicht die Kopie auf dem Server.

## Client-Skripte (`clients/`)

Werden als MECM-Anwendungen auf die Clients verteilt; Reihenfolge über
Anwendungsabhängigkeiten: `client_getinfo` → `client_hostname` →
`client_staticip` → `Set-VMDisksOnline`. Details in `clients/README.md`.

## Bekannte Punkte / Wartung

- **Boundary Group vs. DP Group:** `mecm_autoimporter.ps1` verteilt Content an
  die in der Registry hinterlegte **Distribution-Point-Gruppe** (`DpGroupName`).
  Diese Gruppe muss in MECM existieren. Die frühere Skriptversion pflegte
  fälschlich eine gleichnamige *Boundary Group* – dieser Automatismus wurde
  entfernt; die DP-Gruppe wird bewusst manuell in MECM verwaltet.
- **Gerät außer Betrieb nehmen:** MECM ist die maßgebliche Quelle. Nicht mehr
  genutzte Geräte werden **in der MECM-Konsole** gelöscht (siehe
  `docs/operations/mecm-integration.md`).
- **Sync hängt?** Im Portal den *Systemstatus* öffnen; dort steht je
  Quelle eine Klartext-Handlungsanweisung.
