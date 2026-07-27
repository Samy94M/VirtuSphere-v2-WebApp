# MECM-Integration: Betriebshandbuch

Dieses Dokument beschreibt die Integration zwischen der VirtuSphere-WebApp, dem
MECM-Server und den per PXE ausgerollten Windows-Clients. Zielgruppe sind
Administratoren ohne tiefes MECM- oder Docker-Vorwissen.

## Überblick: die Pipeline

```
Paket-Autor                MECM-Server                          VirtuSphere-WebApp            Windows-Client (PXE)
-----------                -----------                          ------------------            --------------------
config.json-Ordner  --->   mecm_autoimporter.ps1                deploy_packages/deploy_os
(D:\VirtuSphere\           (erstellt MECM-Application +   <---  (Katalog, read-only im
 Packages\files)            Collection "Name-Version")          Portal)
                           mecm_Packages-TaskSeq-sync.ps1 --->  mecm_packages.php
                           (meldet Collections/TaskSeqs)
Portal: Mission/VM  --->   mecm_new-device-sync.ps1       <---  mecm-api.php?action=getDeviceList
anlegen, Pakete            (importiert Devices, weist
verknüpfen                  Collections zu)               --->  mecm_updateid.php (ResourceID)
                                                                                        getinfo (Registry füllen)
                                                          <---  mecm-api.php?action=  hostname (umbenennen+Reboot)
                                                                getDeviceInfos        staticip (Netz konfigurieren)
                                                                                        disks (Platten online)
                     Ergebnisberichte + Site-Health + Client-Phasen  --->  mecm_report.php
```

- Reihenfolge der Client-Phasen ist über MECM-Anwendungsabhängigkeiten fixiert:
  `getinfo → hostname → staticip → disks` (disks optional).
- Zeitstempel der WebApp sind maßgeblich; Client-Uhren werden nicht vertraut.

## Admin-Runbook: MECM erstmals anbinden

Dieser Abschnitt ist die chronologische Arbeitsanleitung für neues oder
wechselndes Adminpersonal. Die späteren Abschnitte erklären Architektur,
Fehlerbilder und Sonderfälle im Detail. Zuerst werden die vier Server-Aufgaben
installiert, danach die vier Client-Anwendungen. Der Client-Installer legt
bewusst noch kein Deployment auf eine Collection an.

### 1. Werte vor Beginn festhalten

| Wert | Beispiel | Bedeutung |
|---|---|---|
| Ubuntu-Host | `192.0.2.10` | Host mit dem produktiven Repo und der WebApp |
| WebAPI | `virtusphere.lan:8021` oder `192.0.2.10:8021` | Ohne `http://` oder `https://` an den Installer übergeben |
| Schema | `http` | `http` oder `https`; HTTPS braucht ein zum Zielnamen passendes Zertifikat |
| MECM-Server | `MECM-01` | Server, auf dem die MECM-Konsole und die Installer laufen |
| `PackagesRoot` | `D:\VirtuSphere\Packages` | Lokale Paketablage für den Autoimporter |
| `PackagesShare` | `\\MECM-01\VirtuSphere\Packages\files` | UNC-Pfad, der exakt auf `PackagesRoot\files` zeigt |
| `PackagesBase` | `D:\VirtuSphere\Base\Packages` | Lokale Ablage der vier Client-Anwendungen |
| `ContentShare` | `\\MECM-01\VirtuSphere\Base\Packages` | UNC-Pfad, der exakt auf `PackagesBase` zeigt |
| DP-Gruppe | `DP Group - VirtuSphere-Applications` | Bestehende Distribution-Point-Gruppe für beide Installer |
| SMS Provider | leer oder `MECM-PROVIDER-01` | Leer lassen, wenn er lokal auf dem MECM-Server liegt |

`PackagesShare` und `ContentShare` sind zwei verschiedene Quellen. Eine Freigabe
kann beide Pfade unterhalb ihres Freigabestamms abbilden; entscheidend ist, dass
der jeweilige UNC-Pfad auf genau den angegebenen lokalen Ordner zeigt. Normale
Benutzer dürfen dort nicht schreiben, weil der Inhalt später als SYSTEM läuft.

### 2. WebAPI-Adresse festlegen: DNS oder vorläufige IP

**Empfohlen: DNS.** Im DNS des Deploy-Netzes einen A-Record wie
`virtusphere.lan` auf die feste LAN-IP des Ubuntu-Hosts anlegen. Der Name muss
sowohl vom MECM-Server als auch von einem PXE-Client über den per DHCP gelieferten
DNS-Server auflösbar sein.

**Ohne aktuellen DNS-Zugriff:** Die Inbetriebnahme kann mit einer festen
Ubuntu-IP fortgesetzt werden. Für den Server-Installer später
`-WebApi '<UBUNTU-IP>:8021'` verwenden. Vor dem Client-Installer in
`clients\VirtuSphere-Client-Common.ps1` den Fallback setzen:

```powershell
$script:VsDefaultDnsApi = 'virtusphere.lan:8021'
$script:VsFallbackIpApi = '<UBUNTU-IP>:8021'
```

Der DNS-Kandidat darf stehen bleiben. Solange er nicht auflösbar ist, probieren
die Clients danach die IP. Die IP muss fest oder reserviert und aus allen
Deploy-VLANs erreichbar sein. Bei HTTPS funktioniert die IP nur, wenn das
Zertifikat diese IP als Subject Alternative Name enthält; andernfalls zuerst
einen passenden DNS-Namen und ein entsprechendes Zertifikat bereitstellen.

Verbindung vom MECM-Server prüfen:

```powershell
Test-NetConnection <WEBAPI-HOST-ODER-IP> -Port 8021
Invoke-RestMethod -Uri 'http://<WEBAPI-HOST-ODER-IP>:8021/portal/health.php' -TimeoutSec 5
```

Bei HTTPS `https://` und den tatsächlich veröffentlichten Port verwenden.

### 3. Portal und MECM vorbereiten

Im Portal unter *Einstellungen → Machine-API IP-Freigaben* mindestens die IP des
MECM-Servers eintragen. Die IP des Ansible-Hosts ebenfalls freischalten, weil er
die ermittelten MAC-Adressen über `db_importMAC.php` meldet. Optional unter
*Einstellungen → Rückkanal-Token* einen Token erzeugen und sicher bereithalten.
Den Token nie in ein Ticket, diese Dokumentation oder einen Kommandozeilenparameter
kopieren; der Installer fragt ihn verdeckt ab.

Die Registry-ACL und der geplante SYSTEM-Task verwenden intern die
sprachunabhängigen Well-Known-SIDs `S-1-5-18` (SYSTEM) und `S-1-5-32-544`
(lokale Administratoren). Dadurch funktioniert derselbe Installer auf deutschen
und englischen Windows-Servern; lokalisierte Anzeigenamen gehören nicht in die
Konfiguration.

Auf dem MECM-Server die Voraussetzungen und vorhandenen Namen read-only prüfen:

```powershell
$env:SMS_ADMIN_UI_PATH
Get-CimInstance -Namespace 'root\SMS' -ClassName '__NAMESPACE' |
    Where-Object Name -Like 'site_*' |
    Select-Object Name

Import-Module $($env:SMS_ADMIN_UI_PATH)\..\ConfigurationManager.psd1
$VsCmDrive = Get-PSDrive -PSProvider CMSite | Select-Object -First 1
if (-not $VsCmDrive) { throw 'Kein MECM-CMSite-Laufwerk gefunden.' }
Push-Location ($VsCmDrive.Name + ':\')
try {
    Get-CMDistributionPointGroup | Select-Object Name
} finally {
    Pop-Location
}

Get-SmbShare | Where-Object { -not $_.Special } | Select-Object Name, Path
```

Fehlt `SMS_ADMIN_UI_PATH`, ist die MECM-Konsole nicht korrekt installiert oder
die Sitzung wurde vor deren Installation geöffnet. MECM-Cmdlets wie
`Get-CMDistributionPointGroup` funktionieren nur, während der aktuelle Pfad auf
dem `CMSite`-Laufwerk liegt; `Push-Location`/`Pop-Location` stellen den vorherigen
Dateisystempfad danach wieder her. Gibt es noch keine passende Freigabe oder
DP-Gruppe, diese nach den lokalen MECM-Betriebsstandards anlegen. Keine
pauschalen Schreibrechte für `Everyone`, `Users` oder `Authenticated Users`
vergeben. Vor der Installation muss eine Datei aus dem lokalen Quellordner über
den vorgesehenen UNC-Pfad sichtbar sein.

### 4. Vier MECM-Server-Aufgaben installieren

Beispiel mit DNS:

```powershell
.\install-VirtuSphere-MECM.ps1 `
    -WebApi 'virtusphere.lan:8021' `
    -Scheme 'http' `
    -PackagesRoot 'D:\VirtuSphere\Packages' `
    -PackagesShare '\\MECM-01\VirtuSphere\Packages\files' `
    -DpGroupName 'DP Group - VirtuSphere-Applications'
```

Beispiel ohne DNS:

```powershell
.\install-VirtuSphere-MECM.ps1 `
    -WebApi '<UBUNTU-IP>:8021' `
    -Scheme 'http' `
    -PackagesRoot 'D:\VirtuSphere\Packages' `
    -PackagesShare '\\MECM-01\VirtuSphere\Packages\files' `
    -DpGroupName 'DP Group - VirtuSphere-Applications'
```

Liegt der SMS Provider auf einem anderen Rechner, zusätzlich
`-ProviderMachine '<PROVIDER-HOST>'` angeben. Den Installer ohne
`-ReportToken` starten und den vorbereiteten Token ausschließlich in der
verdeckten Eingabe einfügen. Ein leerer Wert ist erlaubt. Der Installer ist
idempotent und darf nach Korrekturen erneut ausgeführt werden; ein vorhandener
Token und ein vorhandener Provider-Wert bleiben bei einem erneuten Lauf ohne
entsprechenden Parameter erhalten.

Anschließend prüfen:

```powershell
Get-ScheduledTask -TaskName 'VirtuSphere MECM *' |
    Select-Object TaskName, State

Get-ItemProperty 'HKLM:\SOFTWARE\VirtuSphere\MECM' |
    Select-Object VirtuSphere_WebAPI, Scheme, PackagesRoot, PackagesShare,
                  DpGroupName, MECM_SiteCode, MECM_ProviderMachine,
                  SetupCompleted

Get-ChildItem $env:ProgramFiles\VirtuSphere\Logs -Filter '*.log'
```

Alle vier Aufgaben sollen `Running` melden und alle vier Tageslogs sollen neue
Einträge erhalten. Im Portal auf *Systemstatus* müssen Devices Sync, Packages
Sync, Package Import und Site Health nach ihren jeweiligen Intervallen sichtbar
werden. Ein 403 bedeutet fast immer, dass die MECM-Server-IP noch nicht in der
Machine-API-Freigabe steht.

**Das muss man nicht mehr erraten.** Jede abgewiesene Maschinenanfrage schreibt
eine Zeile in die Logkategorie `machine_api` (Protokolle → Sicherheit), gedrosselt
pro IP, mit der abgewiesenen IP und dem Endpunkt. Steht dort etwas, während die
Systemstatus-Zeilen grau bleiben, ist die Antwort eindeutig: die Aufgaben laufen,
ihre IP fehlt in der Freigabe. Der Systemstatus sagt das dann auch selbst, statt
„vermutlich noch nicht eingerichtet" zu behaupten. Vorher war das die schlimmste
Stille im Produkt: `machine_api_forbidden()` schrieb weder Audit noch error_log
noch Zähler, sechs Endpunkte hingen daran, und der häufigste Einrichtungsfehler
überhaupt sah im Portal genau wie ein Server aus, auf dem MECM nie installiert
wurde. Der Fehlerbericht konnte es auch nicht melden, weil `reportRun` hinter
derselben Sperre sitzt.

### 5. Vier Client-Anwendungen erstellen

Vorher die tatsächlich ausgelieferte Adresse kontrollieren:

```powershell
Select-String -Path '.\clients\VirtuSphere-Client-Common.ps1' `
    -Pattern 'VsDefaultDnsApi|VsFallbackIpApi|VsDefaultScheme'
```

Bei HTTP bleibt `$script:VsDefaultScheme = 'http'`. Bei HTTPS muss dort
`'https'` stehen oder die Client-Registry vor dem ersten API-Aufruf passend
gesetzt werden. Dann den Client-Installer ausführen:

```powershell
.\install-VirtuSphere-Clients.ps1 `
    -PackagesBase 'D:\VirtuSphere\Base\Packages' `
    -ContentShare '\\MECM-01\VirtuSphere\Base\Packages' `
    -DpGroupName 'DP Group - VirtuSphere-Applications'
```

In der MECM-Konsole danach prüfen:

1. Unter *Softwarebibliothek → Anwendungsverwaltung → Anwendungen →
   VirtuSphere_Core* existieren vier Anwendungen.
2. Die Kette lautet `client_getinfo → client_hostname → client_staticip →
   Set-VMDisksOnline`.
3. `client_hostname` erkennt `Erfolgreich` **oder** `Uebersprungen`, und
   Rückgabecode 1641 gilt als Erfolg mit Neustart.
4. Der Contentstatus der vier Anwendungen ist auf der vorgesehenen DP-Gruppe
   erfolgreich.
5. Erst jetzt legt der MECM-Admin bewusst ein Required Deployment auf einer
   Test-Collection an. Der Installer selbst deployt nichts.

Auf einem Testclient müssen die Phasen in dieser Reihenfolge erfolgreich sein.
Lokale Client-Logs liegen unter `C:\Program Files\VirtuSphere\Logs`; die Phasen erscheinen
zusätzlich an der VM im Portal.

### 6. Später von der festen IP auf DNS wechseln

1. DNS-Record anlegen und Auflösung aus MECM- und Deploy-VLAN prüfen.
2. In `VirtuSphere-Client-Common.ps1` `$VsDefaultDnsApi` auf den neuen Namen
   setzen; den IP-Fallback erst nach erfolgreicher Abnahme leeren.
3. `install-VirtuSphere-Clients.ps1` erneut ausführen. Er ersetzt den Content
   und stößt für bestehende Anwendungen die DP-Aktualisierung an.
4. `install-VirtuSphere-MECM.ps1` erneut mit dem DNS-Namen ausführen. Der Lauf
   aktualisiert die Registry und behält einen bestehenden Report-Token.
5. Bereits installierte Clients können die funktionierende IP in
   `HKLM:\SOFTWARE\VirtuSphere\WebAPI` gespeichert haben. Diesen Registry-Override
   im Rahmen eines kontrollierten Client-Deployments auf den DNS-Namen ändern;
   neue Clients verwenden den aktualisierten Content automatisch.

### 7. Übergabe- und Abnahmecheckliste

- [ ] Bereitgestellte Skriptversion dokumentiert
- [ ] WebAPI vom MECM-Server und aus dem Deploy-VLAN erreichbar
- [ ] DNS-Name oder befristeter IP-Fallback dokumentiert
- [ ] MECM- und Ansible-IP im Portal freigeschaltet
- [ ] Lokale Paketpfade und ihre exakten UNC-Gegenstücke dokumentiert
- [ ] DP-Gruppe und optionaler Remote-SMS-Provider dokumentiert
- [ ] Vier Server-Aufgaben laufen und schreiben Tageslogs
- [ ] Vier Quellen erscheinen im Portal-Systemstatus
- [ ] Vier Client-Anwendungen, Detection Rules und Abhängigkeiten geprüft
- [ ] Content auf der DP-Gruppe erfolgreich
- [ ] Required Deployment nur auf einer Test-Collection abgenommen
- [ ] Verantwortlicher und Termin für einen späteren DNS-Wechsel festgehalten

## Single Points of Truth (SSoT)

| Domäne | SSoT |
|---|---|
| Gewünschter VM-/Missions-Zustand | WebApp-DB (`deploy_vms`, `deploy_missions`) |
| Geräte-Lebensende / Cleanup | **MECM** (händisch, siehe unten) |
| Paket-Definitionen | `config.json`-Ordner auf dem MECM-Server (`D:\VirtuSphere\Packages\files`) |
| Paket-Katalog (was existiert) | MECM → gespiegelt in `deploy_packages` (Portal read-only) |
| MECM-Server-Konfiguration | Registry `HKLM:\SOFTWARE\VirtuSphere\MECM` (vom Installer geschrieben) |
| Client-Laufzeitdaten | Registry `HKLM:\SOFTWARE\VirtuSphere` (von `client_getinfo` geschrieben) |
| Deploy-Fortschritt (Ist-Zustand) | WebApp: `deploy_client_events` + Heartbeats (ab Etappe 1) |
| Ersteller einer Mission / VM | WebApp-Session beim Anlegen; danach unveränderlich (`mission_creator`, `vm_creator`) |

> **Wire-Hinweis (Migration 0015):** `mecm-api.php` bettet die Missionszeile per
> `SELECT *` ein. `getDeviceInfos` und `getDeviceList` liefern im `mission`-Objekt
> daher zusätzlich `mission_creator`. Die Ergänzung ist additiv; PowerShell-Clients
> lesen benannte Eigenschaften und ignorieren unbekannte. Es ist ein reines
> Anzeigefeld: keine Skript-Logik darf darauf verzweigen. Zeilen aus der Zeit vor
> der Migration liefern `null`, ebenso Missionen, die seinerzeit über die
> inzwischen entfernte Token-API (ADR-0035) angelegt wurden: dort gab es keinen
> Benutzerkontext, nur eine Token-Rolle.

## VM außer Betrieb nehmen

MECM ist der Single Point of Truth für das Lebensende eines Geräts. Die WebApp
räumt in MECM **nicht** automatisch auf (bewusste Entscheidung, keine
Verwaltungssuite). Vorgehen:

1. VM im Portal löschen (entfernt DB-Eintrag, Interfaces, Paket-Verknüpfungen
   und Status-Historie automatisch).
2. In der MECM-Konsole das Device-Objekt löschen
   (`Assets and Compliance → Devices`).
3. Falls die Mission komplett aufgelöst wird: zugehörige Device Collection im
   Ordner `VirtuSphere_Missions` löschen.

Wird Schritt 2 vergessen, entsteht beim Neuanlegen einer VM mit gleichem Namen
und neuer MAC ein MAC-Konflikt, den der Device-Sync im Log meldet.

<!-- Die folgenden Abschnitte werden je Umsetzungs-Etappe gefüllt. -->

## Rückkanal & Heartbeats (Etappe 1)

Der Endpoint `mecm_report.php` nimmt POST-Meldungen entgegen (JSON). Der optionale
Header `X-VirtuSphere-Token` gilt für die Server-Aufgaben (`action=heartbeat` und
`action=reportRun`) und wird nur geprüft, wenn im Portal unter Einstellungen ein
Token generiert wurde. `action=reportPhase` (Clients) authentifiziert ausschließlich
über IP-Allowlist bzw. bekannte MAC und braucht nie einen Token.

### Heartbeat (MECM-Server, IP muss freigeschaltet sein)

```powershell
try {
  Invoke-RestMethod -Method Post `
    -Uri "http://<webapp>:8021/mecm_report.php?action=heartbeat" `
    -ContentType 'application/json' `
    -Body '{"source":"device-sync","interval_seconds":10,"detail":"synced 4 devices"}' `
    -TimeoutSec 5 | Out-Null
} catch {}
```

Gültige `source`-Werte: `device-sync`, `packages-sync`, `autoimporter`.
Antworten: `200 {"success":true,...}`, `400` bei ungültiger Quelle/Intervall,
`403` wenn die Absender-IP nicht freigeschaltet ist (Portal → Einstellungen →
IP-Freigaben), `401` bei falschem Token.

Die aktuellen Skripte senden statt eines Heartbeats einen **Ergebnisbericht**
(`action=reportRun`, additiv; siehe „MECM-Server: Installation & Aufgabenplanung").
`heartbeat` bleibt für Alt-Skripte kompatibel, erscheint im Portal dann aber gelb
als „Legacy: Ergebnis nicht bestätigt".

### Client-Phase (Windows-Client, authentifiziert per bekannter MAC)

```powershell
$body = '{"mac":"00:50:56:AB:CD:EF","phase":"hostname","event":"started","detail":"renaming to WS-042"}'
Invoke-RestMethod -Method Post -Uri "http://<webapp>:8021/mecm_report.php?action=reportPhase" `
  -ContentType 'application/json' -Body $body -TimeoutSec 5
```

Phasen: `getinfo`, `hostname`, `staticip`, `disks`; Events: `started`,
`finished`, `failed`. Empfehlung: `started` **vor** riskanten Aktionen senden
(z. B. vor der IP-Umstellung), `finished` danach best effort; bleibt es aus,
zeigt das Portal nach 15 Minuten „ausgeführt, Bestätigung ausstehend" (kein
Fehler). Schutzmechanismen: 8-KB-Body-Limit (413), Dedupe identischer
Meldungen < 60 s, 300 Events/Tag pro VM (429), Aufbewahrung 30 Tage.

### Anzeige im Portal

- **Systemstatus** (alle angemeldeten Nutzer, URL weiterhin
  `portal/system_status.php`): getrennte Gruppen für MECM, Ansible, ESXi und
  interne Dienste mit letzter Meldung, letztem Ergebnis und
  Klartext-Handlungsanweisung. Der MECM-Bereich ist in zwei sichtbare Untergruppen
  geteilt: „VirtuSphere-MECM-Integration" (die drei Ergebnisreporter der
  Sync-Aufgaben) und „MECM-Site-Status" (der offizielle Site-Zustand aus
  `SMS_SummarizerSiteStatus`). Es wird kein gemeinsamer Worst-Status gebildet: ein
  kritischer Site-Zustand stellt den erfolgreichen Datenfluss nicht als
  ausgefallen dar, ein ausgefallener Sync behauptet nicht, MECM selbst sei
  kritisch. Das Dashboard liest denselben Health-Snapshot und zeigt in der
  „System status"-Kachel zwei beschriftete Zeilen: „Integration" und „MECM-Site".
- **VM-Detail**: Abschnitt „Client-Phasen" mit den vier Phasen und den letzten
  20 Roh-Ereignissen.
- MECM-relevante Audit-Einträge stehen im Log unter der Kategorie
  „MECM-Integration" (nur Admins); Heartbeats und Phase-Events werden dort
  bewusst nicht dupliziert.

### Registry-Konvention (MECM-Server, ab Etappe 4 vom Installer geschrieben)

| Registry-Wert (`HKLM:\SOFTWARE\VirtuSphere\MECM`) | Bedeutung |
|---|---|
| `VirtuSphere_WebAPI` | Adresse der WebApp, z. B. `virtusphere.lan:8021`; bewusst HTTP, siehe Hinweis unter der Tabelle |
| `PackagesRoot` | Wurzel der Paketablage, Standard `D:\VirtuSphere\Packages` |
| `PackagesShare` | UNC auf den `files`-Ordner (ContentLocation der Applications) |
| `ReportToken` | optionaler Token für `X-VirtuSphere-Token` |
| `DpGroupName` | Distribution-Point-Gruppe für die Content-Verteilung |
| `LogRoot` | Log-Verzeichnis der Server-Skripte |
| `DeviceSyncIntervalSeconds` | Intervall Device-Sync (DWord, Standard 10, erlaubt 5–3600) |
| `PackagesSyncIntervalSeconds` | Intervall Packages-Sync (DWord, Standard 60, erlaubt 10–3600) |
| `ImporterIntervalSeconds` | Intervall Autoimporter (DWord, Standard 60, erlaubt 30–3600) |
| `MECM_ProviderMachine` | SMS-Provider-Rechner für Site Health (neu; leer = lokale Ermittlung) |
| `SiteHealthIntervalSeconds` | Intervall Site Health (DWord, neu, Standard 300, erlaubt 60–3600) |
| `MECM_SiteCode` | erkannter Site-Code (nur wenn automatisch ermittelbar) |
| `SetupCompleted` | Zeitstempel der erfolgreichen Erstinstallation |

Hinweis Intervalle: Der Installer lehnt einen Wert außerhalb der Spanne ab
(`ValidateRange`); ein von Hand in die Registry geschriebener Wert wird beim
Start der Aufgabe geklemmt und die Korrektur ins Tageslog geschrieben. Der Takt,
in dem die Aufgabe läuft, ist damit immer derselbe wie der, den sie meldet und
den der Systemstatus in der Zeile zeigt. Ein Re-Run des Installers ohne den
jeweiligen Parameter behält den eingestellten Wert; ein Skript-Update setzt
einen getunten Takt also nicht zurück. SSoT der Spannen ist
`$script:VsIntervalBounds` in `mecm\VirtuSphere-Common.ps1`.

Hinweis HTTP/HTTPS: Die API-Aufrufe der Skripte laufen standardmäßig über HTTP,
und das Portal-HTTPS leitet sie nie um (ADR-0027). HTTPS ist für die
Maschinenkette aber nicht mehr Ausblick, sondern eingebaut: `-Scheme https` am
Installer schreibt das Schema in die Registry, `Initialize-VsTls` setzt TLS 1.2 in
**jeder** der vier Aufgaben (nicht nur im Installerprozess, der in seinem eigenen
läuft), und einem selbstsignierten Zertifikat wird über den hinterlegten
Fingerabdruck `-CertThumbprint` vertraut statt über abgeschaltete Prüfung. Ein
Zertifikat pro Client ist nicht nötig: domänengebundene Maschinen vertrauen der
Domänen-CA automatisch. Details in `docs/operations/https.md`.

#### Absicherung des ReportToken

Der Installer legt den Schlüssel `HKLM:\SOFTWARE\VirtuSphere\MECM` gehärtet an:
Vererbung aus, Vollzugriff nur für `SYSTEM` und `Administratoren`. Der `ReportToken`
liegt dort im Klartext (die SYSTEM-Tasks brauchen ihn zur Laufzeit), ist durch die
ACL aber nicht mehr für normale Benutzer lesbar. Serverseitig speichert die WebApp
nur den SHA-256-Hash. Den Token nicht als `-ReportToken`-Kommandozeilenargument
übergeben (in History und Prozessliste sichtbar): den Installer ohne dieses Argument
starten, dann fragt er ihn verdeckt ab. Der Token betrifft nur die Server-Heartbeats;
die Client-Phasen-Skripte senden keinen Token (Auth per bekannter MAC), daher muss auf
den ausgerollten VMs kein `ReportToken` provisioniert werden.

#### Paket-Ordner (ContentLocation)

Die paketeigene `install.ps1` wird als `SYSTEM` mit `ExecutionPolicy Bypass` aus der
ContentLocation ausgeführt. Der `files`-Ordner (`<PackagesRoot>\files`) und die
zugehörige Freigabe müssen daher schreibgeschützt für normale Benutzer sein
(nur `SYSTEM`/`Administratoren` schreibend), sonst ist beliebige Codeausführung als
`SYSTEM` möglich. Der Installer warnt, falls der Ordner für Benutzer beschreibbar ist.

## Überwachung & Wartungs-Worker (Etappe 1b)

Der Datenfluss läuft ausschließlich in einer Richtung: **MECM → Portal**. Die vier
SYSTEM-Aufgaben auf dem MECM-Server melden ihren Zustand per HTTP-POST an
`mecm_report.php`; das Portal selbst baut **keine** ausgehende Verbindung zum
MECM-Server auf. Der frühere 5-Minuten-TCP-Check auf Port 445 im Wartungsdienst
ist entfernt (ADR-0018, Amendment 2026-07-23): Eine offene TCP-Verbindung bewies
keine MECM-Anmeldung, WMI, Aufgabenplanung oder Katalog-Synchronisation. An seine
Stelle tritt die vierte Aufgabe „VirtuSphere MECM Site Health", die den offiziellen
Site-Zustand aus `SMS_SummarizerSiteStatus` meldet.

Der Compose-Service `maintenance-worker` (Container
`virtusphere-v2-webapp-maintenance-worker-1`) läuft dauerhaft neben dem
Deploy-Worker und erledigt:

1. **Eigen-Heartbeat** (alle 60 s): erscheint im Systemstatus unter
   „Interne Dienste" als „Wartungsdienst (WebApp)". Fehlt er, läuft der
   Container nicht. Sein Zustand verändert den MECM-Gesamtzustand nicht.
2. **Aufräumjobs** (stündlich): Client-Events älter als 30 Tage,
   Sicherheits-Protokolle (Anmeldung, Benutzer, Zugangsdaten) älter als 365 Tage,
   übrige Portal-Logs älter als 90 Tage, Anmeldeversuchs-Zähler älter als 7 Tage
   (gestaffelt nach Kategorie, siehe ADR-0026). (Früher lief die Log-Bereinigung
   huckepack bei API-Requests; jetzt läuft sie auch ohne Traffic.)
3. **Zustandswechsel-Audits**: die MECM-Quellen schreiben nur Übergänge von/nach
   problematisch in „MECM-Integration" (OK → Warnung/Fehler/Fehlt und zurück,
   Site healthy → warning/critical/unknown und zurück, sowie einmalig der Wechsel
   eines Reporters von Legacy auf V2). Der Maintenance-Worker schreibt seine
   eigenen Übergänge unter „System". Einzelne Läufe, Heartbeats und vollständige
   SMS-Provider-Antworten werden bewusst nicht protokolliert.

Der Wartungsdienst führt keine MECM-Erreichbarkeitsprobe mehr aus und hat kein
MECM-Ziel und keinen Port mehr in den Einstellungen.

**Diagnose-Kombination auf der Statusseite:** die Integration (Ergebnisberichte
der Sync-Aufgaben) und der MECM-Site-Status sind getrennt zu lesen.

| Beobachtung | Bedeutung | Maßnahme |
|---|---|---|
| Integration rot/stale, Site-Status grün | MECM läuft, aber ein Sync-Reporter meldet nicht (Task tot oder Bericht abgelehnt) | Aufgabenplanung prüfen und die benannte Aufgabe starten; lokales Tageslog der Aufgabe lesen |
| Integration grün, Site-Status gelb | Datenfluss ok, MECM meldet eine Warnung (Status 1) | MECM-Konsole → Monitoring → System Status |
| Integration grün, Site-Status rot | Datenfluss ok, MECM meldet Status 2 (kritisch) | MECM-Konsole → Monitoring → System Status; das ist ein MECM-Problem, kein VirtuSphere-Problem |
| Site-Status grau (unbekannt) | Providerfehler (nicht erreichbar oder Zugriff verweigert), **nicht** „MECM kritisch" | Providername und SYSTEM-Berechtigung prüfen (Remote-Provider siehe unten); ein „nicht erreichbar" nach MECM-Reboot löst sich von selbst |
| Sync-Zeile gelb „Ergebnis: Warnung" | Lauf mit partiellem Fehler (ungültige MAC, fehlende Collection, 409-Schutzschwelle) | Detailtext der Zeile und MECM-Log lesen |
| Sync-Zeile gelb „Legacy: Ergebnis nicht bestätigt" | Alt-Skript sendet nur Heartbeats, kein Ergebnis | Installer/Skripte aktualisieren |
| Wartungsdienst rot | interner Dienst steht | `docker compose ps` auf dem Ubuntu-Host, dann `docker compose up -d maintenance-worker` |

**Dienst neustarten** (Ubuntu-Host, Repo-Verzeichnis):

```bash
docker compose up -d maintenance-worker    # startet/erneuert den Dienst
docker compose logs -f maintenance-worker  # Live-Log
```

## Namensregeln für VMs und Hostnamen (Etappe 2)

Was im Portal angelegt wird, muss später in MECM/Windows 1:1 funktionieren:

- **Hostname (`vm_hostname`)**: Windows-Computername: höchstens 15 Zeichen,
  nur Buchstaben/Zahlen/Bindestriche im Inneren, keine Punkte. Grund: Der
  Client kürzt und bereinigt beim Deploy stillschweigend; alles Laxere würde
  vom eingegebenen Namen abweichen. Bestands-VMs mit Alt-Hostnamen bleiben
  editierbar (Warnhinweis am Feld zeigt den Namen, den der Client erzeugen
  würde); erst eine Änderung muss die Regel erfüllen.
- **VM-Name (`vm_name`)**: global eindeutig über alle normalen Missionen
  hinweg (MECM-Gerätenamen sind global). Templates dürfen Namen doppeln;
  beim Klonen eines Templates werden Kollisionen vorab als Liste gemeldet.
- **Missionsname**: ist gleichzeitig der MECM-Collection-Name. Sobald VMs der
  Mission in MECM übermittelt/registriert sind, ist der Name gesperrt.
- **MAC-Adressen**: kanonisches Format `AA:BB:CC:DD:EE:FF` (Großbuchstaben,
  Doppelpunkte). Lookups akzeptieren alle üblichen Schreibweisen; der
  Ansible-Import lehnt Dubletten (gleiche MAC an anderer VM) und mehrdeutige
  Ziele (zwei NICs derselben VM im selben VLAN) mit klarer Fehlermeldung ab.
  Eine fehlerhafte NIC verwirft alle MAC-Schreibplaene dieser VM, nicht aber
  vollstaendig valide VMs desselben Callbacks. Die alten Diagnosefelder
  `duplicate_macs`/`unmatched_interfaces` bleiben erhalten; `vm_results`
  nennt pro Inputzeile Outcome, Updatezahl und feste Fehlercodes.

## Paket-Lebenszyklus (Etappe 3)

**Konvention:** Paket-Collections heißen `Name-Version`; die **Version darf
keinen Bindestrich enthalten** (der Katalog splittet am letzten Bindestrich).

Lebenszyklus eines Katalogeintrags:

1. **Aktiv**: vom Paket-Sync gemeldet. Name/Version werden getrennt
   gespeichert und im Portal angezeigt.
2. **Zurückgezogen (Retired)**: fehlt ein Eintrag im Sync-Payload (z. B.
   Versionswechsel mit `removeOldVersion`), wird er markiert statt gelöscht.
   VM-Zuweisungen bleiben erhalten; existiert eine aktive Nachfolge-Version
   (gleicher Namensstamm), werden die Zuweisungen **automatisch umgehängt**
   (protokolliert im Log, Kategorie „MECM-Integration"). Taucht der Name
   wieder auf, wird er automatisch reaktiviert.
3. **Endgültig gelöscht**: erst nach 30 Tagen im Zustand „Zurückgezogen"
   **und** ohne verbleibende VM-Verknüpfung (Wartungsdienst). Verknüpfte
   zurückgezogene Pakete bleiben dauerhaft erhalten.

**Schutzschwelle:** Würde ein einzelner Sync mehr als X % (Standard 30 %,
Portal → Einstellungen) der aktiven Pakete zurückziehen (typisch bei
WMI-Lesefehlern oder falschem Collections-Ordner), wird er mit HTTP 409
abgelehnt und im Log vermerkt. Der Sync wiederholt sich dann sichtbar, statt
still den Katalog zu leeren. Task Sequences (`deploy_os`) haben denselben
Schutz. Der Betriebssystem-Katalog wird wie die Paketliste ausschließlich aus
MECM gespeist; die Portalseite „Betriebssysteme" bietet nur Anzeige und ein
Admin-Löschen als self-healende Aufräum-Aktion (existiert die Task Sequence noch
in MECM, legt der nächste Sync den Eintrag wieder an), aber kein Anlegen oder
Bearbeiten mehr (ADR-0020).

Im Portal: Paketliste mit Status-Filter (Aktiv/Zurückgezogen/Alle); die
VM-Bearbeitung blendet zurückgezogene Pakete aus (außer bereits verknüpfte,
mit Kennzeichnung) und zeigt „Update verfügbar"-Hinweise.

## MECM-Server: Installation & Aufgabenplanung (Etappe 4)

Die vier Server-Skripte liegen versioniert unter `Powershell-MECM/mecm/` und
teilen sich `VirtuSphere-Common.ps1` (Konfiguration, Logging, Ergebnisberichte,
MAC-Normalisierung, Site-Code- und SMS-Provider-Ermittlung, Site-Health-Abbildung).
Installation und Registrierung der geplanten Aufgaben erledigt
`Powershell-MECM/install-VirtuSphere-MECM.ps1` (idempotent, siehe
`Powershell-MECM/README.md`). Die vier registrierten Aufgaben laufen alle als
`NT AUTHORITY\SYSTEM`, mit höchsten Rechten, ohne Profil (`-NoProfile`), mit
zwei Triggern (`AtStartup` **und** stündliche Wiederholung),
`MultipleInstances IgnoreNew` und ohne Laufzeitlimit (`ExecutionTimeLimit=PT0S`):

| Aufgabe | Skript | Meldet an `reportRun` |
|---|---|---|
| VirtuSphere MECM Devices Sync | `mecm_new-device-sync.ps1` | `started`/`completed` je Lauf, Ergebnis + Zähler |
| VirtuSphere MECM Packages Sync | `mecm_Packages-TaskSeq-sync.ps1` | `started`/`completed` je Lauf, Ergebnis + Zähler |
| VirtuSphere MECM Package Import | `mecm_autoimporter.ps1` | `started`/`completed` je Lauf, Ergebnis + Zähler |
| VirtuSphere MECM Site Health | `mecm_site-health.ps1` | nur `completed`: Site-Code, Provider, Rohstatus 0/1/2 |

Wichtige Härtungen gegenüber den Altskripten:

- **Konfiguration nur aus der Registry** `HKLM:\SOFTWARE\VirtuSphere\MECM` –
  keine IPs, DNS-Namen, UNC-Pfade oder Site-Codes im Code.
- **Kein Laufzeitlimit** für die Aufgaben (`ExecutionTimeLimit=PT0S`) plus
  Auto-Neustart – das Standard-72h-Limit hätte die Endlosschleifen sonst
  regelmäßig beendet.
- **Zwei Trigger statt einem.** Mit `AtStartup` allein war eine Aufgabe nach
  ihren drei Neustartversuchen bis zum nächsten Reboot tot, und ein MECM-Server
  bootet selten: der Ausfall sah aus wie eine stille Integration. Der stündliche
  Trigger holt sie zurück; `IgnoreNew` sorgt dafür, dass er nichts tut, solange
  die Aufgabe läuft.
- **`-NoProfile`.** Die Aufgaben laufen als SYSTEM, und ein Profilskript unter
  SYSTEM ist Fremdcode im Sync-Prozess (Kodierung, `PSModulePath`,
  `$ErrorActionPreference`).
- **Der Installer beendet laufende Aufgaben, bevor er die Skripte ersetzt.**
  Sonst lief die alte Instanz mit dem beim Start dot-gesourcten Common weiter,
  während die neue Registry-Konfiguration schon da war.
- **Sende-Guard im Paket-Sync:** Fehlt der Applications-Ordner oder liefert
  WMI nichts, wird **nichts** gesendet (ein leerer Payload würde serverseitig
  den Katalog zurückziehen). Zusätzlich Change-Detection per Payload-Hash.
- **Wildcard-Fix im Autoimporter:** Alt-Versions-Bereinigung matcht exakt
  `^Name-<Version>$` statt `Name*` (früher löschte ein `Firefox`-Update auch
  `Firefox-ESR-*`). `config.json` ohne `ProjectName`/`version` wird
  übersprungen. `LogonRequirementType` auf den offiziellen Enum-Wert
  `WhetherOrNotUserLoggedOn` korrigiert.
- **Device-Sync:** Leerlauf-Abkürzung bei 0 Devices, Collection-Cache je Scan,
  Task-Sequence-Collections einmal statt pro Device, normalisierter
  MAC-Vergleich (keine falschen Konfliktwarnungen), nutzt das eingebettete
  `mission`-Objekt aus `getDeviceList` statt N+1 `getMissionName`.
- Die drei Sync-Aufgaben senden je Lauf einen **Ergebnisbericht** (`started`
  vor der Arbeit, `completed` mit `ok`/`warning`/`fail`/`unknown` im `finally`);
  die Site-Health-Aufgabe meldet nur `completed`. Ein toter Task wird im Portal im
  *Systemstatus* stale/rot; ein Alt-Skript, das nur Heartbeats sendet, erscheint
  gelb als „Legacy: Ergebnis nicht bestätigt".

**Task neu starten** (MECM-Server): Aufgabenplanung öffnen → Task unter
`\` auswählen → *Ausführen*. Oder per PowerShell:
`Start-ScheduledTask -TaskName 'VirtuSphere MECM Devices Sync'`.

### Site Health und SMS-Provider

Die vierte Aufgabe „VirtuSphere MECM Site Health" fragt über den SMS Provider
`SMS_SummarizerSiteStatus` für den konfigurierten Site-Code ab und meldet den
offiziellen MECM-Site-Zustand: `0` = OK (grün), `1` = Warnung (gelb),
`2` = kritisch (rot), jeder andere Rohwert = unbekannt (grau). Providerfehler
(nicht erreichbar, Zugriff verweigert, Abfrage fehlgeschlagen) werden als
`unknown` (grau) gemeldet; Rot ist exklusiv dem MECM-bestätigten Status 2
vorbehalten. „Nicht erreichbar" wird erst nach zwei aufeinanderfolgenden
Fehlversuchen gemeldet, damit ein MECM-Reboot nicht sofort einen Fehler ins Portal
schreibt.

Provider und Intervall sind Registry-owned (`HKLM:\SOFTWARE\VirtuSphere\MECM`),
keine Portal-Settings:

- `MECM_ProviderMachine`: SMS-Provider-Rechner. Leer lassen, wenn der Provider
  lokal auf dem Site-Server liegt (der Normalfall); die Aufgabe ermittelt ihn dann
  selbst.
- `SiteHealthIntervalSeconds`: Berichtsintervall, Standard 300, erlaubt 60–3600.
  Wie die drei Sync-Intervalle: außerhalb der Spanne lehnt der Installer ab, ein
  Registry-Wert von Hand wird beim Start geklemmt und protokolliert.

**Remote-Provider.** Das Computerkonto des Site-Servers ist standardmäßig Mitglied
der SMS-Admins-Gruppe auf jedem Provider; liegt der Provider lokal und läuft die
Aufgabe als SYSTEM, ist keine Zusatzkonfiguration nötig. Liegt der Provider auf
einem anderen Rechner, braucht dieses Computerkonto die Provider-Berechtigung, und
der Transport muss erreichbar sein: `Get-CimInstance -ComputerName` nutzt
WinRM/WSMan; klassisches WMI braucht DCOM mit RPC 135 plus dynamischen Ports und
Remote Activation. Ein fehlendes Recht meldet das Portal als
`provider_access_denied` (grau), nicht als „MECM kritisch".

Lokale Tageslogs aller vier Aufgaben liegen unter
`%ProgramFiles%\VirtuSphere\Logs\<Datum>_<Komponente>.log` (Site Health:
`<Datum>_site-health.log`), einheitliches Format, 30 Tage Aufbewahrung.

## Client-Anwendungen (Etappe 5)

Die überarbeiteten Client-Skripte liegen unter `Powershell-MECM/clients/` und
teilen sich `VirtuSphere-Client-Common.ps1`. Reihenfolge über
MECM-Anwendungsabhängigkeiten: `client_getinfo` → `client_hostname` →
`client_staticip` → `Set-VMDisksOnline`. Vollständige App-Definitionen,
Erkennungsregeln und Exit-Codes stehen in `Powershell-MECM/clients/README.md`.

Kernpunkte:

- **Adress-Fallback-Kette:** Registry-Override → DNS-Name (`virtusphere.lan:8021`,
  DNS-Eintrag im Deploy-Netz nötig) → hartkodierte IP. `client_getinfo`
  schreibt die funktionierende Adresse in die Registry für die Folge-Skripte.
- **Rückkanal:** jede Phase meldet `started`/`finished`/`failed`;
  `staticip` meldet `started` vor der IP-Umstellung, `hostname` `finished` vor
  dem Reboot (VLAN-/Reboot-robust, „Bestätigung ausstehend" ist kein Fehler).
- **Stale-Fix (getinfo):** alter `Interfaces`-Zweig wird vor dem Schreiben
  gelöscht, Erfolgs-Marker `SetupState=complete` erst danach gesetzt.
- **Idempotenz (staticip):** Re-Run überschreibt sauber und meldet echten
  Erfolg/Fehlschlag statt pauschal „installed".
- Einheitliches Datei-Logging unter `C:\Program Files\VirtuSphere\Logs` (30 Tage).

## Edge Cases der Server-Skripte (Referenz)

Alle Fälle schreiben ins Tageslog (`%ProgramFiles%\VirtuSphere\Logs\<datum>_<komponente>.log`),
sofern nicht anders vermerkt. „Still" heißt: bewusst ohne Log-Eintrag, um Spam
im 10s/60s-Takt zu vermeiden; Sichtbarkeit entsteht anderweitig (Heartbeat/Portal).

**Alle drei Skripte gemeinsam**

| Fall | Verhalten | Log |
|---|---|---|
| Registry-Konfiguration fehlt komplett | wartet in 60-s-Schleife auf den Installer (Selbstheilung, kein Exit) | ERROR einmalig (Default-LogRoot) |
| WebApp/MECM-Fehler im Durchlauf | Backoff 30 s; ab 3 Fehlern in Folge 60 s + Site-Drive-Neuinitialisierung | ERROR je Versuch |
| Berichts-Zustellung scheitert | Lauf läuft weiter (der Bericht bricht ihn nie ab); lokal gedrosselt protokolliert | gedrosselt (WARN); Portal-Ampel wird stale/rot |
| Registry-Änderung zur Laufzeit | greift erst nach Task-Neustart (Konfig wird beim Start gelesen; Installer-Re-Run startet die Tasks neu) | — |
| Dateilog selbst nicht schreibbar | Sync läuft weiter (Logging stoppt nie den Prozess) | Konsole only |

**Device-Sync**

| Fall | Verhalten | Log |
|---|---|---|
| 0 Devices von der WebApp | Leerlauf-Abkürzung, keine MECM-Abfragen | still |
| VM ohne Mission / ohne DHCP-MAC | übersprungen, nächste VM | WARN |
| MAC-Konflikt MECM ≠ ESXi | nie automatisch ändern; VM **bleibt in der Warteschlange** (ResourceID wird nicht gemeldet) | ERROR „manuelle Prüfung" |
| Import-Race (paralleler Scan) | toleriert; Existenz-Nachprüfung statt Fehlertext-Parsing (sprach-/versionsneutral) | still |
| Mehrere DHCP-Interfaces an einer VM | erste MAC wird genutzt | WARN |
| Auto-Approve scheitert / ResourceID fehlt noch | Retry im nächsten Scan | DEBUG + WARN |
| Ziel-Collection existiert nicht | Zuweisung übersprungen; VM **bleibt in der Warteschlange** | WARN + ERROR-Zusammenfassung |
| Zuweisung zu einer Collection scheitert | dito: VM bleibt in der Warteschlange | ERROR |
| Eigene Regel nicht mehr zugewiesen (Provenienz, ADR-0034) | wird entfernt und an `reportMembership` gemeldet; Hand-Regeln in MECM sind ohne Provenienzzeile unantastbar | INFO |
| Entfernen der eigenen Regel scheitert | VM bleibt in der Warteschlange; nächster Lauf konvergiert | ERROR |
| Eigene Regel wurde in MECM von Hand entfernt | Provenienz wird zurückgezogen (`removed` gemeldet), nie zurückgekämpft | WARN |
| Provenienz-Meldung (`reportMembership`) scheitert | Warnung; Report ist idempotent, nächster Lauf konvergiert | WARN |
| Collection angelegt, Ordner-Verschub/Ordner-Anlage scheitert | Collection bleibt im Wurzelordner, funktional ok | WARN |
| Collection-Update nicht anstoßbar | Mitgliedschaft greift erst beim nächsten MECM-Zyklus | WARN |
| ResourceID-Rückmeldung an WebApp scheitert | Sync läuft weiter, VM bleibt in der Warteschlange | WARN |
| MECM-Vollabfrage (Devices/Task Sequences/Collections) scheitert | Lauf bricht ab und meldet `mecm_unavailable`; **kein** Weiterlaufen mit leeren Caches | ERROR |

**Die ResourceID ist die Tür aus der Warteschlange.** `mecm_updateid.php` setzt die VM auf `registered`, und `getDeviceList` liefert sie danach nicht mehr; nichts schiebt sie erneut ein. Deshalb meldet der Device-Sync die ResourceID nur, wenn **jede** Zuweisung dieser VM gesessen hat, also OS-, Paket- und Mission-Collection. Vorher lief die Meldung unbedingt: eine VM mit fehlender Paket-Collection fiel dauerhaft aus der Warteschlange, bootete per PXE ohne Task Sequence oder installierte ohne ihre Pakete, und im Portal stand sie als fertig registriert. Ein unvollständiger Lauf zählt jetzt `item_failures`, meldet `warning`/`partial_failure` und nennt im `detail` VM und Collection.

**Packages-Sync**

| Fall | Verhalten | Log |
|---|---|---|
| Applications-Ordner fehlt / Katalog leer | Sende-Guard: nichts senden | WARN |
| Katalog unverändert (Hash) | kein Sync; Voll-Sync spätestens stündlich | still (Konsole) |
| WebApp lehnt mit 409 ab (Schutzschwelle) | Hash nicht gemerkt → nächster Durchlauf versucht erneut | WARN |

**Autoimporter**

Ein offener Punkt hält den mtime-Stamp zurück: der nächste Durchlauf scannt
denselben Baum erneut, und der Lauf meldet `warning` mit `partial_failure` samt
Ursachencodes im Detail (`package_content_failed target=…`), statt `ok`. Nur ein
Durchlauf ohne offene Punkte merkt den Stamp.

| Fall | Verhalten | Log |
|---|---|---|
| `config.json` fehlt im Ordner | Ordner ignoriert | still |
| `config.json` ungültig / ohne ProjectName+version | übersprungen, **offener Punkt** (`package_config_invalid`) | WARN |
| `PackagesShare` fehlt in Registry | wartet in 60-s-Schleife auf den Installer | ERROR einmalig |
| files-Baum unverändert (mtime-Stamp) | kein Scan | still |
| files-Pfad fehlt | Scan übersprungen, Stamp wird nicht gemerkt (`package_source_missing`) | WARN |
| Alt-Version nicht vollständig entfernbar | Retry im nächsten Durchlauf (`package_cleanup_failed`) | WARN |
| Alt-Version ohne eigene Collection | wird über die Application gefunden und bereinigt | Log je Entfernung |
| Vorlagen-install.ps1 nicht kopierbar | Retry im nächsten Durchlauf (`package_template_failed`) | WARN |
| Deployment/Collection fehlt (auch nach früherem Teilfehler) | wird idempotent nachgezogen; bei Fehlschlag Retry (`package_deploy_failed`, `collection_folder_failed`) | WARN |
| Content-Verteilung scheitert | Retry im nächsten Durchlauf, solange nichts verteilt ist (`package_content_failed`) | WARN mit DP-Gruppe |
| `DeployTo`-Ziel-Collection fehlt | Konfigurationsfehler; kein Dauer-Retry, kein offener Punkt | WARN |
| Application existiert bereits | Anlage übersprungen, Vorlagenskript/Collection/Deployment werden trotzdem geprüft | still (Konsole) |

## Troubleshooting

Erste Anlaufstelle ist immer der **Systemstatus** (Klartext-Ampel
je Quelle mit Handlungsanweisung). Die Handlungsanweisung ist eine
Reparaturanweisung und steht deshalb nur an einer Zeile, die nicht `ok` ist;
verschwindet sie nach einem Eingriff, hat die Quelle wieder gemeldet. Hat sich
noch nie eine Sync-Quelle gemeldet (Gruppe steht komplett auf „Noch keine
Daten"), entfallen die Zeilenhinweise ganz: es gibt keine Aufgabe, die man neu
starten könnte. Der Abschnitt nennt dann einmal den Einrichtungsweg (Skripte auf
dem MECM-Server, IP-Freigabe im Portal, dieses Dokument). Der MECM-Site-Status
wird davon getrennt geführt: solange die Site-Health-Aufgabe nichts gemeldet hat,
bleibt er grau (unbekannt), statt „kritisch" zu behaupten. Der
Zustand „Erwartet, nie gemeldet" (gelb) unterscheidet sich von „Noch keine
Daten" (grau): gelb heißt, andere MECM-Quellen melden sich bereits, diese eine
also nie eingerichtet oder nie gestartet; grau heißt, die Integration ist
insgesamt noch nicht angebunden. Die Legende der Seite erklärt alle drei Ampeln
(Quellen, ESXi, Ansible) aus denselben Konstanten wie die Hilfe. Häufige Fälle:

**VM taucht nicht in MECM auf**
1. *Systemstatus* prüfen: läuft „MECM Device-Sync"? Wenn rot → Aufgabenplanung
   auf dem MECM-Server, Task „VirtuSphere MECM Devices Sync" starten.
2. Hat die VM eine MAC? Im Portal an der VM prüfen; ohne DHCP-MAC überspringt
   der Sync sie (der Ansible-MAC-Import muss vorher gelaufen sein).
3. Gehört die VM zu einer Mission (nicht Template)? Templates werden bewusst
   nicht synchronisiert.
4. Log auf dem MECM-Server: `%ProgramFiles%\VirtuSphere\Logs\<datum>_device-sync.log`.

**Deployment hängt auf dem Client**
1. VM-Detail im Portal → Abschnitt „Client-Phasen": Wo steht die Kette
   (getinfo/hostname/staticip/disks)?
2. „Ausgeführt, Bestätigung ausstehend" ist meist harmlos (VLAN-Wechsel nach
   staticip). „Fehlgeschlagen" mit Detailtext → Client-Log unter
   `C:\Program Files\VirtuSphere\Logs`.

**Pakete verschwinden / Sync abgelehnt (409)**
1. Im Log (Kategorie „MECM-Integration") nach „Katalog-Sync abgelehnt" suchen:
   Der Paket-Sync hätte mehr als die Schutzschwelle zurückgezogen, meist ein
   WMI-Aussetzer oder falscher Collections-Ordner auf dem MECM-Server.
2. Katalogquelle prüfen; notfalls Schwelle temporär anheben (Portal →
   Einstellungen → Paket-Sync-Schutzschwelle).

**MECM-Server offline oder Site kritisch**
- Es gibt keine Portal-Probe mehr; das Portal spricht MECM nicht aktiv an. Fällt
  der MECM-Server aus, bleiben zuerst die Ergebnisberichte der Sync-Aufgaben aus
  (Integration wird stale/rot), und die Site-Health-Aufgabe kann den Provider nicht
  mehr abfragen (MECM-Site wird grau/unbekannt, nicht rot). Ein rotes MECM-Site
  bedeutet ausschließlich den von MECM selbst bestätigten kritischen Status 2:
  dann MECM-Konsole → Monitoring → System Status. Ein falsch konfigurierter
  Provider oder eine fehlende Berechtigung zeigt sich als grau (Providerfehler),
  nicht als „kritisch".

**Wartungsdienst rot**
- Auf dem Ubuntu-Host: `docker compose ps`, dann
  `docker compose up -d maintenance-worker`.

## Zurückgestellte Prüfpunkte (sobald Systemzugriff besteht)

1. **Ubuntu-Produktionsparität** (bei Abnahme Etappe 1): auf dem Ubuntu-Host
   prüfen: `docker compose ps` (Container-Namen `virtusphere-v2-webapp-*-1`),
   `.env` (`WEB_HTTP_PORT=8021`), Migrationsstand
   `docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --status`.
2. **DNS-Name für die API** (vor Etappe 5): Eintrag wie `virtusphere.lan`
   → IP des Ubuntu-Hosts im Deploy-Netz anlegen.
3. **Schwellwert der Paket-Bremse** (bei Abnahme Etappe 3): Default 30 % gegen
   die reale Kataloggröße prüfen (Portal → Einstellungen).
