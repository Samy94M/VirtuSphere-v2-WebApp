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
anlegen, Pakete            (importiert Devices unter dem
verknüpfen                  Rolloutnamen, weist
                            Collections zu)               --->  mecm_updateid.php (ResourceID)
                                                                                        getinfo (Registry füllen)
                                                          <---  mecm-api.php?action=  hostname (umbenennen+Reboot)
                                                                getDeviceInfos        staticip (Netz konfigurieren)
                                                                                        disks (Platten online)
                                                          <---  mecm_client_ack.php (Client bereit, 5/5)
                     Ergebnisberichte + Site-Health + Client-Phasen  --->  mecm_report.php
```

- Reihenfolge der Client-Phasen ist über MECM-Anwendungsabhängigkeiten fixiert:
  `getinfo → hostname → staticip → disks` (disks optional).
- Zeitstempel der WebApp sind maßgeblich; Client-Uhren werden nicht vertraut.
- **Was MECM als Gerätenamen bekommt, ist der Windows-Hostname, nicht der
  ESXi-Name** (Etappe 14D, ADR-0043). Genauer: der für diesen Rollout
  eingefrorene Snapshot `mecm_rollout_hostname`, den `getDeviceList` unter dem
  unveränderten Wire-Key `vm_hostname` liefert. `vm_name` bleibt die
  ESXi-Identität und wandert nicht nach MECM.

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
| Schema | `http` | `http` oder `https`; HTTP braucht weder CA noch Zertifikat noch Thumbprint, HTTPS braucht eingerichtetes Vertrauen |
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
`-WebApi '<UBUNTU-IP>:8021'` verwenden. Dem Client-Installer dieselbe Adresse
als Bootstrapwert übergeben:

```powershell
.\install-VirtuSphere-Clients.ps1 -ContentShare '\\MECM-01\VirtuSphere\Base\Packages' -WebApi '<UBUNTU-IP>:8021' -Scheme http
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

Der MAC-Rückkanal ist jobgebunden. Für einen neuen Resultat-V2-Callback müssen
Mission, aktiver `running`/`cancelling`-Job, exportfähiger Modus, Attempt,
Runtime-Generation und beim Remotevertrag das aktuelle Exporthandle
zusammenpassen. Unter der Lockreihenfolge Mission, Job, Runtime, Remotehandle,
VM, Interfaces wird der VM-Name exakt aufgelöst und ausschließlich die eine
Netzwerkkarte akzeptiert, deren Portgruppe exakt dem Missionswert
**WDS-Portgruppe (PXE)** entspricht. Eine beliebige DHCP-Karte ist kein
Identitätsbeweis.

Resultat V2 speichert für jede erwartete VM den WDS-Verdict und einen
semantischen Callback-Fingerprint aus den sortierten erwarteten VM-IDs und den
normalisierten semantischen Ergebniszeilen einschließlich ihrer Multiplizität.
Reine Zeilen-/Keyreihenfolge, Modulmetadaten und Fehlerfreitext ändern ihn
nicht. Derselbe Callback ist nur für dieselbe noch
aktive Ausführung idempotent 200 ohne Domainwrite; ein abweichender,
unlesbarer oder terminal wiederholter Callback ist 409. Nur eine erfolgreiche
WDS-MAC aktualisiert Interface, VM-Identität und `deployed/pending`; alle
anderen NICs und VMs bleiben atomar unverändert. Historische Resultate V1
bleiben eingeschränkt lesbar. Request, persistiertes Resultat und Antwort sind
begrenzt; Body, Token und Provider-Rohantworten werden nicht protokolliert.

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
Ein interaktiv neu eingegebener Token ersetzt den alten Wert; eine leere
interaktive Eingabe behaelt ihn. Nur ein ausdrueckliches `-ReportToken ''`
löscht ihn. Für den Provider gilt dieselbe Herkunftsregel: ohne Parameter
behalten, mit ausdruecklichem `-ProviderMachine ''` den Override entfernen und
wieder die Laufzeiterkennung verwenden. Der Installer loest diese Werte vor
seiner ersten Konfigurationsschreiboperation genau einmal auf.

### 4a. Künftige Updates mit vorhandener Konfiguration

Den neuen, zusammengehörigen `Powershell-MECM`-Baum in ein frisches
Stagingverzeichnis entpacken. Aus dessen Wurzel genügt in einer administrativen
PowerShell:

```powershell
.\install-VirtuSphere-MECM.ps1 -Upgrade
```

`-Upgrade` übernimmt WebAPI, Schema, Zertifikatfingerabdruck, Paketpfade,
DP-Gruppe, Provider, Rückkanal-Token und Intervalle vollständig aus
`HKLM:\SOFTWARE\VirtuSphere\MECM`. Es gibt keine Tokenabfrage und keine lange
Parameterkette. Der getrennte Parametersatz verhindert, dass ein vermeintlich
reines Update nebenbei Konfigurationswerte überschreibt. Ohne vorhandene
Registry-Konfiguration oder ohne `VirtuSphere_WebAPI` beziehungsweise
`PackagesShare` bricht der Lauf vor seiner Transaktion ab.

Die Quelle nicht über einen alten entpackten Baum kopieren. Commitgebundene ZIP
oder verifiziertes Offline-Bundle in ein neues Verzeichnis entpacken, danach den
Installer direkt aus diesem Verzeichnis starten. Der bestehende transaktionale
Pfad staged und hasht den vollständigen Serverdateisatz und die Paketvorlage,
stoppt die vier Aufgaben, aktiviert den neuen Stand und rollt bei einem Fehler
auf Registry, Dateien, Vorlage und Aufgaben zurück. Exit-Code 0 plus laufende
Aufgaben und frische Tageslogs bilden den Abschlussnachweis.

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

Jede Zeile der vier Dateien hat sechs Felder:
`ISO-8601 | LEVEL | Komponente | Kontext | Nachricht | Korrelations-ID`. Fehlt
`VirtuSphere-Logging.ps1` im Installationsverzeichnis oder passt seine
Vertragsversion nicht zu `VirtuSphere-Common.ps1`, ist das kein stiller
Loggingausfall: Installer beziehungsweise Aufgabe brechen mit einem konkreten
Paketfehler ab. Der fachliche Lauf bleibt dagegen aktiv, wenn ausschließlich das
Zielverzeichnis vorübergehend nicht schreibbar ist.

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

Adresse und Schema werden beim Client-Installer als Bootstrapmanifest erzeugt.
Der Quelltext bleibt umgebungsneutral. Dann den Client-Installer ausführen:

```powershell
.\install-VirtuSphere-Clients.ps1 `
    -PackagesBase 'D:\VirtuSphere\Base\Packages' `
    -ContentShare '\\MECM-01\VirtuSphere\Base\Packages' `
    -DpGroupName 'DP Group - VirtuSphere-Applications' `
    -WebApi 'virtusphere.lan:8021' -Scheme http
```

Seit V23 ist das ein koordinierter Wire-Wechsel: `client_getinfo` bestätigt das
vollständige lokale Schreiben mit einem POST an `mecm_client_ack.php`; der
vorherige GET ist read-only. Deshalb den Client-Installer beim WebApp-Update
erneut ausführen und danach die aktualisierte Content-Verteilung abwarten. Ein
V22-Client kann seine Konfiguration weiterhin lesen, bleibt ohne den neuen ACK
im Portal aber auf 4/5. Der Installer ersetzt beide Dateien und stößt bei einer
bestehenden Anwendung `Update-CMDistributionPoint` an.

Vor jeder MECM-Änderung vergleicht er für alle Clientordner das vollständige
lokale Pfad-/Längen-/SHA-256-Manifest mit dem tatsächlichen `ContentShare`.
Alle Manifestvergleiche werden abgeschlossen. Sobald ein veröffentlichter Ordner
fehlt, nicht vollständig lesbar ist oder abweicht, endet der Installer danach mit
`!!` und Exit 1, noch vor Site-Initialisierung und vor dem ersten
Configuration-Manager-Cmdlet. Der fehlerhafte Lauf ändert daher keine
Applications, Deployment Types, Dependencies oder Contentverteilungen. Den
gemeldeten Freigabepfad, dessen Leserechte und den Inhalt korrigieren und erst
dann den Installer erneut ausführen.
Anschließend verlangt er pro Application einen VirtuSphere-Eigentumsmarker oder
den engen Legacy-Nachweis im Ordner `VirtuSphere_Core`, genau einen erwarteten
Deployment Type, den Detection-/System-/Reboot-/Returncodevertrag und die
wirkliche Dependency auf den erwarteten Vorgänger-DT. Fehlende eigene Teile
werden ergänzt; fremde oder manuell abweichende Definitionen werden nicht
überschrieben und enden als `!!`/Exit 1.

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
2. `install-VirtuSphere-Clients.ps1` erneut mit `-WebApi '<DNS-NAME>:8021'`
   ausführen. Er ersetzt Content und Bootstrapmanifest
   und stößt für bestehende Anwendungen die DP-Aktualisierung an.
3. `install-VirtuSphere-MECM.ps1` erneut mit dem DNS-Namen ausführen. Der Lauf
   aktualisiert die Registry und behält einen bestehenden Report-Token.
4. Bereits installierte Clients können die funktionierende IP in
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

> **Wire-Hinweis (ADR-0019/E3):** Nur `getDeviceList` bettet für den MECM-Server
> die Missionszeile ein und führt dort auch `mission_creator`. Das clientseitige
> `getDeviceInfos` ist ein exakter Minimalvertrag: fünf Basisfelder plus neun
> Interface-Felder, ohne Notizen, Ersteller, Pakete oder Lifecycle-Zustand.
> `getMissionName` ist entfernt; das ausgelieferte Device-Sync-Skript verwendete
> schon vorher ausschließlich die eingebettete Mission.
>
> **Etappe 14D (ADR-0043, ADR-0019-Amendment 3):** `getDeviceList` hat sein
> `SELECT *` verloren; die gelieferten Spalten stehen als
> `VIRTUSPHERE_MECM_DEVICE_LIST_COLUMNS` fest und werden von
> `MachineApiWireTest` in beide Richtungen gegen die echte Antwort geprüft.
> Zwei bewusste Aliasse und ein additives Feld: `vm_hostname` trägt den
> eingefrorenen Rolloutnamen (nie den aktuellen Portal-Sollwert),
> `previous_resource_id` ist der Lösch-Tombstone unter einem Wire-Namen, und
> `rollout_revision` ist der Fence. `getDeviceInfos` bleibt minimal und bekommt
> genau ein Feld dazu, `rollout_revision`; sein `vm_hostname` ist ebenfalls der
> Snapshot, weil der Client Windows nach dem benennt, was er dort liest.

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
Erfolgs- oder Fehlernachweis). Schutzmechanismen: 8-KB-Body-Limit (413), Dedupe identischer
Meldungen < 60 s, 300 Events/Tag pro VM (429), Aufbewahrung 30 Tage.

### Client-Ready-ACK (Windows-Client, verbindlich und idempotent)

Nach dem vollständigen Schreiben der Registry-Nutzdaten sendet V23
`POST /mecm_client_ack.php` mit
`{"mac":"00:50:56:AB:CD:EF","rollout_revision":7}` aus derselben gelesenen
Rolloutantwort. Nur dieser Endpoint setzt 5/5; `getDeviceInfos` verändert keinen
Zustand mehr. Scheitert der POST oder seine Antwort, liefert `client_getinfo`
Exitcode 1, damit MECM den Lauf wiederholt. Erst nach der positiven ACK-Antwort
setzt der Client `SetupState=complete`. Der Server kann 5/5 bereits gespeichert
haben, obwohl nur seine HTTP-Antwort verloren ging; dann antwortet er beim Retry
mit `deduplicated:true` und erzeugt keine zweite Statuszeile. Dieser ACK ist
absichtlich nicht Teil von `mecm_report.php`, dessen Phasenmeldungen weiterhin
rein anzeigend und best effort bleiben.

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
  Eine alte oder zeitlich ungültige Meldung bestätigt keinen aktuellen Erfolg.
  Ein veralteter ehemals kritischer Sitewert bleibt als historischer Befund mit
  Zeitpunkt sichtbar, färbt die Gegenwart aber nicht rot; Rot bleibt einem
  frischen MECM-bestätigten Status 2 vorbehalten. Ein Providerfehler bleibt
  unabhängig vom Alter unbekannt. Ein gestarteter Sync zeigt den aktuellen
  Start getrennt vom letzten abgeschlossenen Ergebnis.
- Abgewiesene Machine-API-Zugriffe erscheinen als historische Ereignisse im
  benannten Zeitfenster. Die Anzeige nennt die vollständige Zahl verschiedener
  IPs, eine begrenzte Liste und ausgelassene Einträge. Sie ordnet eine IP ohne
  weitere positive Evidenz weder MECM noch Ansible zu und behauptet nach einer
  späteren Erholung keine aktuelle Blockade.
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
und das Portal-HTTPS leitet sie nie um (ADR-0027). Das gilt auch für den neuen
Client-Ready-ACK. Im HTTP-Modus sind **keine** CA, kein Zertifikat und kein
Thumbprint zu konfigurieren. HTTPS ist für die
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

## Rolloutname, Reset und Tombstone (Etappe 14D, ADR-0043)

`vm_hostname` ist der Sollwert und das einzige editierbare Feld. Was MECM
bekommt, ist `mecm_rollout_hostname`: der für den laufenden Rollout eingefrorene
Snapshot. Solange `mecm_id IS NULL` folgt er dem Sollwert; mit der ersten
Bindung friert er ein.

Die SSoT-Kette, je Station ein Besitzer:

```
Portal-Sollwert (vm_hostname)
  --> Rollout-Snapshot (mecm_rollout_hostname, eingefroren bei der Bindung)
  --> MECM-Import (Import-CMComputerInformation -ComputerName <Snapshot>)
  --> Windows (client_hostname.ps1, idempotent)
  --> DDR-Anzeigename in MECM (Windows/Discovery, ab hier fremdes Eigentum)
```

**Neuer Rollout, der einzige unterstützte Weg:**

1. `vm_hostname` im Portal korrigieren.
2. Altes Gerät in der MECM-Konsole löschen (VirtuSphere löscht dort nie).
3. Bei neu erzeugter VM zuerst die neue PXE-MAC jobgebunden importieren lassen.
4. „MECM-ID zurücksetzen" ausführen.

Der Reset ist der **einzige** Aktivierungspunkt. Kein Bereitstellungsmodus,
auch nicht „Full pipeline", setzt einen Hostnamen.

Was der Reset in einer Transaktion tut: Snapshot auf den Sollwert, alte
ResourceID wird `mecm_previous_id` (Tombstone), `mecm_rollout_revision + 1`,
Hostname-Claim vom alten auf den neuen Namen. Ein zweiter Klick mit demselben
Namen ist ein idempotenter No-op; ein zweiter Klick nach einer Namenskorrektur
zieht den Snapshot nach und **behält** den Tombstone, damit eine Tippfehler-
korrektur nach dem Reset nicht bis zum Abschluss des Rollouts gesperrt ist. Der
Tombstone verschwindet ausschließlich mit einer erfolgreichen neuen Bindung.

Der Tombstone blockiert nicht den Reset, sondern die **Übergabe**: Solange das
Vorgängergerät in MECM existiert, importiert der Device-Sync nicht und meldet
`previous_resource_present`. Der VM-Editor zeigt denselben Sachverhalt als
offenen Punkt mit der ResourceID.

Vorlagen haben weder Snapshot noch Revision noch Tombstone. Ein Klon aus einer
Vorlage initialisiert beides frisch aus seinem gewünschten `vm_hostname`;
JSON-Export und -Import übertragen ausschließlich den Sollwert.

Der wirksame Rolloutname ist portalweit eindeutig, erzwungen in
`deploy_vm_hostname_claims` (case-insensitiver Primärschlüssel, Cascade auf die
VM). Eine VM darf gleichzeitig ihren eingefrorenen und ihren neuen Namen halten;
eine andere VM bekommt den alten erst im Reset-Commit.

## Namensregeln für VMs und Hostnamen (Etappe 2)

Was im Portal angelegt wird, muss später in MECM/Windows 1:1 funktionieren:

- **Hostname (`vm_hostname`)**: Windows-Computername: höchstens 15 Zeichen,
  nur Buchstaben/Zahlen/Bindestriche im Inneren, keine Punkte. Grund: Der
  Client kürzt und bereinigt beim Deploy stillschweigend; alles Laxere würde
  vom eingegebenen Namen abweichen. Bestands-VMs mit Alt-Hostnamen bleiben
  editierbar (Warnhinweis am Feld zeigt den Namen, den der Client erzeugen
  würde); erst eine Änderung muss die Regel erfüllen.
- **VM-Name (`vm_name`)**: der VM-Name in ESXi, global eindeutig über alle
  normalen Missionen hinweg. Das ist eine Portal-/ESXi-Namenspolitik und
  **nicht** der MECM-Gerätename: MECM importiert seit Etappe 14D den
  Rollout-Snapshot des Windows-Hostnamens. Templates dürfen Namen doppeln;
  beim Klonen eines Templates werden Kollisionen vorab als Liste gemeldet.
- **Missionsname**: ist gleichzeitig der MECM-Collection-Name. Sobald VMs der
  Mission in MECM übermittelt/registriert sind, ist der Name gesperrt.
- **MAC-Adressen**: kanonisches Format `AA:BB:CC:DD:EE:FF` (Großbuchstaben,
  Doppelpunkte). Lookups akzeptieren alle üblichen Schreibweisen; der
  Ansible-Import lehnt Dubletten (gleiche MAC an anderer VM) und mehrdeutige
  Ziele (zwei NICs derselben VM mit demselben exakten Portgruppennamen) mit
  klarer Fehlermeldung ab. Portgruppennamen sind case-sensitive; die PXE-MAC
  stammt nur von der exakt passenden WDS-Portgruppe der Mission.
  Eine fehlerhafte NIC verwirft alle MAC-Schreibplaene dieser VM, nicht aber
  vollstaendig valide VMs desselben Callbacks. Die alten Diagnosefelder
  `duplicate_macs`/`unmatched_interfaces` bleiben erhalten; Resultat V2 nennt
  pro erwarteter VM Outcome, Updatezahl, WDS-Evidenz, feste Fehlercodes und den
  semantischen Callback-Fingerprint.

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

### Altversionen und sicherer Bereinigungsplan

Der Autoimporter löscht in seinem normalen Scan keine MECM-Objekte. Er liest
zuerst alle `config.json`-Quellen und bestimmt je ordinalem Produktnamen einen
Zielstand. Für eine automatische Ordnung sind nur kanonische, punktgetrennte
Dezimalversionen zulässig; `1.10` ist neuer als `1.9`, `10` neuer als `2`.
Freie oder doppelte Versionen blockieren, und eine weiterhin in der Quelle
liegende Version bleibt immer erhalten. Diese enge Löschsemantik ist bewusst
nicht die breitere Anzeigeordnung des Portal-Katalogs.

Ein freizugebender Plan muss stabile Application-/Collection-IDs, die exakten
versionierten Ownership-Marker, keine Referenzen und einen vollständig bereiten
Ersatz belegen. Bereit bedeutet: genau ein Deployment Type, bestätigtes
Contentmanifest, vollständig bestätigter aktueller Contentstand und geprüftes
Deployment. Unklare oder fremde Objekte bleiben erhalten. Der Plan wird direkt
vor einer Ausführung neu erhoben; nur ein identischer SHA-256-Fingerabdruck ist
gültig. Der normale Task ruft den Executor nicht auf. Eine echte Entfernung
wird ausschließlich in einer freigegebenen MECM-Testmenge abgenommen.

### Transport, TLS, ACL und Site-Health seit 07.09.2026

Server- und Client-POSTs senden JSON als explizites UTF-8-`byte[]` mit Charset.
Beide ausgelieferten `ConvertTo-VsUtf8JsonBytes`-Helper emittieren dieses Array
als ein Funktionsobjekt; andernfalls enumeriert PowerShell die Bytes zu
`object[]`, und der HTTP-Cmdlet-Binder behandelt den Body nicht mehr als
Binärdaten. Die Regression prüft Objekt, Ein-/Mehrarray und Unicode direkt am
Helper sowie den untypisierten HTTP-Parameter. Für die Laufzeitabnahme liest die
Loopback-Fixture in `VirtuSphere.JsonTransport.Tests.ps1` die tatsächlich
übertragenen Bytes unter Windows PowerShell 5.1 und PowerShell 7 direkt hinter
dem HTTP-Header. Fehlerdiagnosen verwenden zuerst den bereits in
`ErrorRecord.ErrorDetails.Message` vorhandenen Antworttext und lesen den Stream
nur als Rückfall; extrahierter Text ist begrenzt und wird anschließend vom
Logger redigiert.

Bei HTTPS bleibt ein leerer Fingerabdruck normale PKI-Prüfung. Ein gesetzter
Fingerabdruck erlaubt genau das passende Zertifikat trotz Kettenfehler, ist aber
kein erzwungenes Pinning eines ohnehin gültigen PKI-Zertifikats. Es gibt keinen
Accept-all-Callback. Die Client-Adresswahl verlangt zusätzlich die Felder des
VirtuSphere-Health-Dokuments. Paketpfade werden sprachunabhängig über die SIDs
für Everyone, Authenticated Users und Builtin Users auf breite Allow-
Schreibrechte geprüft. Fremde Shares werden nur diagnostiziert; der vollständig
eigene Secret-Registry-Key wird dagegen ausschließlich aus SYSTEM- und
Administratoren-Rechten neu aufgebaut.

Site-Health verwendet nur den in `MECM_SiteCode` gespeicherten Code. Fehlt er,
ist der exakte Treffer nicht eindeutig oder ist der Rohstatus unlesbar, bleibt
die Anzeige grau. Rot entsteht ausschließlich aus Rohstatus 2. Laufzeiten der
vier Aufgaben werden vor dem Integercast auf den Reportvertrag begrenzt; das
Intervall bezeichnet den Schlafabstand zusätzlich zur Laufzeit und keine exakte
Wanduhrkadenz.

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
- **Der Installer deaktiviert Trigger und beendet laufende Aufgaben, bevor er
  die Skripte ersetzt.**
  Sonst lief die alte Instanz mit dem beim Start dot-gesourcten Common weiter,
  während die neue Registry-Konfiguration schon da war. Das Disable schließt
  zusätzlich einen neuen Stundenlauf im Stop/Move-Fenster aus; ein Fehler
  bricht den Austausch fail-closed ab.
- **Serverpaket als geprüfter Satz.** Der Installer staged alle `.ps1`-Dateien,
  vergleicht ihre SHA-256-Hashes und liest die deklarierten Vertragsversionen
  von Common und `VirtuSphere-Logging.ps1` per AST, ohne die Stagingdateien
  auszuführen. Erst danach deaktiviert und stoppt er Aufgaben und ersetzt das
  Loggingmodul vor der Common-Fassade. Fehlende oder versionsfalsche Module
  werden dadurch vor dem nächsten Sync sichtbar.
- **Paketvorlage gehört zum selben Austausch.** `install.ps1`, `config.json`
  und der ausgelieferte Vorlagenbaum werden vor dem Taskstopp gestaged und
  gehasht. Das Vorlagen-Staging liegt unter `PackagesRoot`, damit die
  Verzeichniswechsel auch bei einem anderen Paketlaufwerk auf demselben
  Dateisystem bleiben. Der bisherige Vorlagenbaum wird gesichert, der neue
  live gegen das Manifest geprüft und bei einem späteren Installationsfehler
  zusammen mit Serverdateien und Registry zurückgerollt. Ein unvollständig
  bestätigter Rollback lässt die Aufgaben deaktiviert.
- **Upgrade und Re-Run als gemeinsame Transaktion.** Ein globaler Mutex schließt
  parallele Installer aus; nur das exakte, reparse-freie lokale Verzeichnis
  `%ProgramFiles%\VirtuSphere\mecm` darf ersetzt werden. Vor dem ersten
  Registry-Write sichert der Installer alle Werte samt Typ und ACL sowie XML
  und Laufzustand der vier eigenen Aufgaben. Der vollständige Altdateisatz
  bleibt bis nach Start, Verifikation und Abschlussmarker erhalten. Scheitert
  ein Schritt, werden zuerst alle neuen Aufgabenprozesse stillgelegt, danach
  Dateien und Registry und zuletzt die alten Taskdefinitionen restauriert.
  Zuvor laufende Aufgaben starten nur erneut, wenn dieser Rollback vollständig
  nachgewiesen ist; sonst bleiben sie deaktiviert.
- **Sende-Guard im Paket-Sync:** Fehlt der Applications-Ordner oder liefert
  WMI nichts, wird **nichts** gesendet (ein leerer Payload würde serverseitig
  den Katalog zurückziehen). Zusätzlich Change-Detection per Payload-Hash.
- **Alt-Versionen im Autoimporter:** Der normale Importlauf erkennt bei
  `removeOldVersion` nur exakte `^Name-<Version>$`-Kandidaten, behaelt sie aber.
  Name und Ordner sind kein Eigentums-, Referenz- oder Ersatznachweis. Eine
  spätere Löschung braucht einen separat geprüften Plan; bis dahin bleibt der
  Stamp offen und der Bereinigungsbedarf sichtbar. `config.json` ohne
  `ProjectName`/`version` wird übersprungen. `LogonRequirementType` verwendet
  den offiziellen Enum-Wert `WhetherOrNotUserLoggedOn`.
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

Vor einem manuellen Start zuerst das Setup-Tageslog unter
`%ProgramFiles%\VirtuSphere\Logs` prüfen. Meldet es einen unvollständigen
Installer-Rollback, keine Aufgabe manuell starten: zuerst die genannte Prozess-,
Datei-, Registry- oder Taskabweichung beheben und den Installer erneut ausführen.

### Site Health und SMS-Provider

Die vierte Aufgabe „VirtuSphere MECM Site Health" fragt über den SMS Provider
`SMS_SummarizerSiteStatus` für exakt den konfigurierten Site-Code ab. Fehlt
dieser Eintrag, kommt er mehrfach vor oder ist sein Rohstatus nicht lesbar,
bleibt das Ergebnis `unknown`; eine andere Site wird nie ersatzweise verwendet.
Bei eindeutigem Eintrag meldet sie den
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
`%ProgramFiles%\VirtuSphere\Logs\yyyy-MM-dd_<Komponente>.log` (Site Health:
`yyyy-MM-dd_site-health.log`). Server und Clients verwenden denselben
versionierten Vertrag mit den Leveln `DEBUG`, `INFO`, `WARN`, `ERROR`, sechs
durch ` | ` getrennten Feldern, UTF-8-sicherer Begrenzung, Redigierung benannter
Secrets und 30 Tagen Aufbewahrung. ANSI-/OSC-Terminalsequenzen werden vor der
Redigierung normalisiert, damit sie bekannte Secret-Schlüssel nicht aufteilen.
Die Redigierung deckt dokumentierte benannte Header-, Parameter- und
Schlüsselformen ab; unbeschriftete Geheimnisse in beliebigem Freitext bleiben
ausserhalb dieser Zusage. Logger halten den Success-Stream frei, damit ein
Resolver oder anderer datengebender Helfer genau seinen Fachwert liefert. Die
tägliche Bereinigung verwendet einen gemeinsamen Marker und einen exklusiven Lock, damit parallele Aufgaben nicht
gegeneinander löschen. Die Korrelations-ID entsteht einmal pro Prozess und wird
als Diagnoseheader mitgesendet; sie ist kein Authentisierungsmerkmal.

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
- **Rückkanal:** Phasenevents sind einzelne best-effort-Sendeversuche und keine
  garantierte `started`-zu-Terminal-Sequenz. `staticip` versucht `started` vor
  der Änderung der Windows-IP-/Subnetzkonfiguration; das Skript ändert keine
  ESXi-Portgruppe. Bei einer echten Umbenennung meldet `hostname` `finished`
  vor dem Reboot. Skip-, Frühabbruch- und Zustellpfade können Events auslassen;
  „Bestätigung ausstehend" belegt weder Erfolg noch Fehler.
- **Snapshot (getinfo):** Ein neuer versionierter Stand wird vorbereitet,
  nachgelesen und erst über `ActiveSnapshot` veröffentlicht. Danach läuft der verbindliche Client-Ready-ACK, und erst seine
  Bestätigung setzt `SetupState=complete`; der idempotente Server-POST darf
  wiederholt werden.
- **Netzvertrag (staticip):** Vor dem ersten Write müssen alle Snapshot-MACs
  genau einen aktiven Adapter treffen; ungültige Werte, Namenskonflikte,
  fehlende/mehrdeutige/Down-Adapter und mehrere Default-Gateways blockieren den
  Lauf vollständig. Das Skript erhält IPv6 und unbekannte manuelle IPv4-Werte.
  Es entfernt nur die IPv4-Adresse und Route, die ein vorheriger erfolgreicher
  Lauf für dieselbe MAC dokumentiert hat. Leeres DNS im statischen Soll erhält
  den aktuellen DNS-Stand; DHCP setzt DNS auf automatische Ermittlung zurück.
  `Tentative` wird begrenzt abgewartet, `Duplicate` und `Invalid` scheitern.
  Eine lokal bestätigte IP-Konfiguration behauptet nicht, dass das Portal aus
  dem neuen Netz erreichbar ist; die terminale Phasenmeldung bleibt best effort.
- **Diskvertrag (disks):** Neue Offline-RAW-Platten erhalten vor dem ersten
  Storage-Write ein versioniertes Eigentumsjournal unter
  `HKLM:\SOFTWARE\VirtuSphere\VMDiskManagement\Operations`. Die stabile
  Identität ist `UniqueId`, ersatzweise nur Seriennummer plus LocationPath plus
  Größe; die Disknummer ist kein Eigentumsbeweis. Ein Retry löst jede offene
  Operation vor allen Writes eindeutig gegen das vollständige Inventar auf und
  setzt sie auch nach Online/Initialize/Partition/Format oder einer geänderten
  Disknummer fort. Tatsächliche Disk-, Partition- und Volumezustände werden
  jeweils nachgelesen. Unbekannte online-RAW-Platten, fremde Partitionen,
  Identitäts-/Größenkonflikte, Boot/System, Read-only, Cluster und Größe 0 sind
  manuelle Blocker und werden nicht formatiert. Vorhandene GPT-/MBR-Datenplatten
  werden nur online geschaltet. `optional: no disk work required` bedeutet
  ausschließlich, dass keine Zusatzplatte und keine offene eigene Operation
  vorhanden ist.
- Einheitliches Datei-Logging unter `C:\Program Files\VirtuSphere\Logs` (30
  Tage). Jede Application enthält Phase, `VirtuSphere-Client-Common.ps1`,
  `VirtuSphere-Client-Logging.ps1` und `bootstrap.json`; Packaging staged und hashprüft die Skriptdateien als
  Geschwisterverzeichnis und aktiviert sie mit einem atomaren Verzeichnis-Swap.
  Scheitert die Aktivierung, wird der vollständige Altstand zurückgerollt. Fehlt
  das Loggingmodul oder ist seine Vertragsversion falsch, beginnt die
  Clientphase nicht mit einem halben Paket.

**Disk-Recovery:** Bei einer fehlgeschlagenen `disks`-Phase zuerst die
Tageslogzeile und anschließend die Unterschlüssel unter
`VMDiskManagement\Operations` lesen. `State` zeigt den zuletzt dauerhaft
bestätigten Schritt; maßgeblich bleibt die vom Skript nachgelesene reale
Disk-/Partitions-/Volumeansicht. Ist genau dieselbe stabile Identität mit
unveränderter Größe vorhanden, darf derselbe MECM-Anwendungslauf die Operation
wiederaufnehmen. Fehlt sie, ist sie mehrfach vorhanden, enthält die Platte
fremde Partitionen oder ist nur eine unbekannte online-RAW-Platte sichtbar,
bleibt der Lauf rot und erfordert eine menschliche Storageentscheidung. Journal-
Unterschlüssel nicht löschen, um eine Formatierung zu erzwingen.

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
| Dateilog selbst nicht schreibbar | Sync läuft weiter (Logging stoppt nie den Prozess); derselbe Fehler warnt pro Prozess und Störung höchstens einmal, eine Erholung genau einmal | lokale PowerShell-Warnung `Log-Sink gestört` / `Log-Sink wieder verfügbar`; kein zusätzlicher Heartbeat, `reportRun` oder Auditeintrag |

**Device-Sync**

| Fall | Verhalten | Log |
|---|---|---|
| 0 Devices von der WebApp | Leerlauf-Abkürzung, keine MECM-Abfragen | still |
| VM ohne Mission / ohne DHCP-MAC | übersprungen, nächste VM | WARN |
| Rolloutname ungültig (`device_name_invalid`) | kein Import; im VM-Editor korrigieren | ERROR |
| Name mit fremder MAC oder MAC mit fremdem Namen (`mac_conflict`) | nie automatisch ändern; VM **bleibt in der Warteschlange** (ResourceID wird nicht gemeldet) | ERROR „Identitaet nicht aufloesbar" |
| Gebundene ResourceID fehlt in MECM (`resource_id_missing`) | blockiert; kein Ersatzdatensatz wird adoptiert. „MECM-ID zurücksetzen" | ERROR |
| Gebundene ResourceID mit fremder MAC (`resource_mac_conflict`) | blockiert; Handentscheidung | ERROR |
| Mehrere Treffer nach Name, MAC oder ResourceID (`device_identity_ambiguous`) | blockiert; das Skript wählt bewusst keinen aus | ERROR |
| Vorgängergerät noch vorhanden (`previous_resource_present`) | blockiert bis zum Löschen in der MECM-Konsole | ERROR |
| Abweichender **Anzeigename** bei gültiger ResourceID und MAC | **kein Befund**: Windows/Discovery darf umbenennen; kein Reimport, kein Rename, keine Warnung | DEBUG |
| Rückmeldung mit veralteter Rolloutrevision (`stale_rollout_revision`, HTTP 409) | Portal weist ab; der nächste Scan läuft mit der aktuellen Revision durch | WARN |
| Import-Race (paralleler Scan) | toleriert; nach dem Import werden Name UND MAC erneut eindeutig gelesen, kein Fehlertext-Parsing und kein `-MergeIfExist` | still |
| Mehrere DHCP-Interfaces an einer VM | erste MAC wird genutzt | WARN |
| Auto-Approve scheitert / ResourceID fehlt noch | Retry im nächsten Scan | DEBUG + WARN |
| Ziel-Collection existiert nicht | Zuweisung übersprungen; VM **bleibt in der Warteschlange** | WARN + ERROR-Zusammenfassung |
| Zuweisung zu einer Collection scheitert | dito: VM bleibt in der Warteschlange | ERROR |
| Eigene Regel nicht mehr zugewiesen (Provenienz, ADR-0034) | wird nur bei erfolgreich gelesenem Live-Bestand entfernt und mit ID, autoritativem Namen und Typ an `reportMembership` gemeldet; Hand-Regeln in MECM sind ohne Provenienzzeile unantastbar | INFO |
| Entfernen der eigenen Regel scheitert | VM bleibt in der Warteschlange; nächster Lauf konvergiert | ERROR |
| Eigene Regel wurde in MECM von Hand entfernt | Bleibt sie im Portal ausdrücklich gewünscht, stellt der Sync sie unter aktueller Revision wieder her. Bestätigtes Add unter derselben exakten CollectionID erhält die Provenienz; eine ersetzte CollectionID zieht nur den alten Nachweis zurück. Ohne bestätigtes Add bleibt der alte Nachweis zur Klärung erhalten. Ein nicht mehr gewünschtes Ziel wird als `removed` gemeldet. Fremde/manuelle Regeln bleiben unberührt (ADR-0034 Amendment 4, D-01). | INFO/WARN |
| Membership-Abfrage fehlschlägt oder ein Collectionname ist mehrdeutig | VM bleibt in der Warteschlange; keine Membership-Mutation und kein Provenienzrückzug | ERROR |
| Provenienz-Meldung (`reportMembership`) scheitert | Ein `remote_confirmed`-Journaleintrag bleibt erhalten und wird mit derselben VM-, Revisions-, Resource- und Collectionidentität idempotent wiederholt; die VM bleibt bis zum erfolgreichen Replay in der Warteschlange. | WARN |
| Altes Journal enthält Add und Remove für dieselbe VM, Revision, ResourceID und CollectionID | Das bestätigte Add erhält die Provenienz; sein ACK entfernt das zusammengehörige Paar atomar aus dem Journal. Ein einzelner Remove braucht vor dem Replay eine erfolgreich gelesene aktuelle Abwesenheit. Bei vorhandener Regel oder unbekanntem Live-Bestand bleibt er `uncertain` zur manuellen Klärung, ohne Adoption oder Provenienzlöschung. | WARN/ERROR |
| Journal enthält nur `intent`, passt nicht mehr zu Revision/ResourceID oder erhält beim Replay 404/409 | Ownership ist ungeklärt: kein erneuter MECM-Write, keine Adoption und keine ResourceID-Meldung. `membership-journal.json` samt lokalem Log sichern und Bestand/Operation manuell belegen; niemals nur wegen des Alters löschen. | ERROR |
| Journal ist voll, nicht schreibbar oder beschädigt | Mutierender Device-Sync blockiert vor dem nächsten MECM-Write. Beschädigte Evidenz bleibt als `membership-journal.json.quarantine.*.json` erhalten und sperrt auch spätere Starts sowie ein ersetztes Hauptjournal. Speicher/ACL reparieren, Evidenz und Logs sichern; Operationen gegen aktuelle Rolloutrevision, ResourceID und exakte CollectionID in MECM und Portal klären. Quarantäne erst nach dokumentierter Ownership-Entscheidung entfernen, niemals lediglich als Neustartmaßnahme. | ERROR |
| Client-Snapshot nicht veröffentlicht oder ACK ausstehend | `client_getinfo` entfernt zuerst `SetupState`, schreibt einen neuen versionierten Snapshot, liest Identität und Interfaceanzahl nach und veröffentlicht ihn über `ActiveSnapshot`. Folgephasen lesen nur diesen vollständigen Stand. Erst ein bestätigter Client-Ready-ACK setzt `SetupState=complete`; ein Retry erzeugt einen neuen Snapshot und der ACK bleibt idempotent. | ERROR/Phase `failed` |
| Client-Application vorhanden, Deployment Type oder Abhängigkeit fehlt | `install-VirtuSphere-Clients.ps1` prüft die verwalteten Pflichtteile bei jedem Re-Run, ergänzt einen fehlenden eigenen Deployment Type und eine fehlende eindeutige Dependency-Gruppe. Mehrdeutige oder nicht sicher auflösbare Fremddefinitionen werden nicht überschrieben und lassen den Installer mit Blocker enden. | Installer `!!`, Exit 1 |
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

Ein offener Punkt hält den Manifest-Stamp zurück: der nächste Durchlauf scannt
denselben Baum erneut, und der Lauf meldet `warning` mit `partial_failure` samt
Ursachencodes im Detail (`package_content_failed target=…`), statt `ok`. Nur ein
Durchlauf ohne offene Punkte merkt den Stamp.

Für jeden Paketordner wird ein eigener SHA-256-Manifeststand unter
`HKLM:\SOFTWARE\VirtuSphere\MECM\ContentTracking` verfolgt. Vor
`Start-CMContentDistribution` oder `Update-CMDistributionPoint` schreibt der
Autoimporter `intent` samt Application-/Deployment-Type-Identität, bisheriger
Content-ID und Kopierstand der konkreten Verteilungspunkte. Nur ein erfolgreich
zurückgekehrter Aufruf wird als bestätigt gespeichert. Ein Crash oder Fehler
in diesem Fenster löst keine blinde zweite Verteilung aus. Der bestätigte
Auftrag wird an seine DT-Content-ID gebunden; bei einem Update muss sie sich
gegenüber der Baseline ändern. Die Application-Packageversion darf dabei
gleich bleiben. `complete` verlangt zusätzlich zum erfolgreichen Aggregat
einen neueren erfolgreichen `LastCopied`-Nachweis für die bisherigen DP-Ziele;
fehlende, ersetzte oder unbekannte Ziele erlauben keinen Abschluss.
Ein Erstauftrag benötigt mindestens einen bestätigten Kopierstand. Dies ist
kein separater Nachweis der konfigurierten DP-Gruppenmitgliedschaft.
Unbestätigte oder beschädigte Trackingdaten brauchen manuelle Klärung. Diese
Contentpflege läuft unabhängig von `generateOwnDeviceColletion`; eine eigene
Collection ist keine Voraussetzung für eine aktuelle Paketquelle.

Alte Trackingdatensätze ohne den neuen Identitäts- und Kopiervertrag bleiben
auch mit dem früheren Zustand `complete` zur manuellen Prüfung gesperrt. Sie
belegen keine historische Content-ID und werden nicht automatisch auf das
aktuell gleichnamige Objekt übertragen. Vor einer Freigabe den betreffenden
Registrydatensatz und die Tageslogs sichern, Application-/DT-Identität,
lokales Manifest und tatsächlichen DP-Inhalt abgleichen und ausschließen,
dass noch eine alte Verteilung läuft. Die Entscheidung über den Altstand ist
zu dokumentieren; keine pauschale Löschung des Trackingbaums als Reparatur.

| Fall | Verhalten | Log |
|---|---|---|
| `config.json` fehlt im Ordner | Ordner ignoriert | still |
| `config.json` ungültig / ohne ProjectName+version | übersprungen, **offener Punkt** (`package_config_invalid`) | WARN |
| `PackagesShare` fehlt in Registry | wartet in 60-s-Schleife auf den Installer | ERROR einmalig |
| files-Baum unverändert (SHA-256-Manifest) | kein Scan | still |
| files-Pfad fehlt | Scan übersprungen, Stamp wird nicht gemerkt (`package_source_missing`) | WARN |
| Alt-Version erkannt | bleibt unverändert; normaler Import darf ohne Eigentums-, Referenz- und Ersatznachweis nicht löschen (`package_cleanup_failed`) | WARN je Kandidat |
| Alt-Version ohne eigene Collection | wird über die Application als Kandidat erkannt und ebenfalls erhalten | WARN je Kandidat |
| Vorlagen-install.ps1 nicht kopierbar oder beide install.ps1-Dateien fehlen | Keine Application-/DT-/Contentmutation für dieses Paket; Kopie muss den SHA-256-Vergleich bestehen. Ein vorhandenes lesbares Paketskript bleibt auch ohne Vorlage zulässig. Retry im nächsten Durchlauf (`package_template_failed`). | WARN |
| Deployment/Collection fehlt (auch nach früherem Teilfehler) | wird idempotent nachgezogen; bei Fehlschlag Retry (`package_deploy_failed`, `collection_folder_failed`) | WARN |
| Content-Verteilung scheitert oder bleibt unbekannt | Application-/DT-Contentidentität, vollständig erfolgreiche Aggregatzähler und der neuere Kopiernachweis jedes bisherigen DP-Ziels müssen zusammenpassen. Die Package-`SourceVersion` dient als Diagnose, ihr Anstieg ist kein Application-Abschlusskriterium. Unbekannte Identität oder Providerevidenz blockiert; Fehler werden nicht blind neu verteilt. | WARN mit Paket/Ursachencode |
| Tracking enthält einen unbestätigten `intent`, ist unvollständig oder stammt aus dem alten Schema | Keine automatische zweite Contentmutation; Tageslog, Trackingidentitäten und MECM-Verteilung manuell klären. Alte Trackingstände ohne Content-ID werden auch bei früherem `complete` nicht durch Vermutung übernommen. | WARN `package_content_unknown` |
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
2. Phasenmeldungen sind best effort. „Ausgeführt, Bestätigung ausstehend"
   bedeutet nur, dass `started` ankam und kein terminales Event gespeichert ist;
   auch eine vollständig fehlende Phase beweist weder Erfolg noch PXE-Fehler.
   Bei einer echten Umbenennung sendet `hostname` den Abschluss vor dem Reboot.
   `staticip` kann durch die neue Windows-IP-/Subnetzkonfiguration die
   Portalverbindung verlieren, verschiebt aber keine ESXi-Portgruppe. Deshalb
   MECM-Application-Detection, Erreichbarkeit und Client-Log unter
   `C:\Program Files\VirtuSphere\Logs` gemeinsam prüfen.

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
