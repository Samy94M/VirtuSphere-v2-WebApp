# VirtuSphere Client-Skripte

Diese Skripte laufen auf den per PXE frisch installierten Windows-Clients und
werden über das MECM-Software-Center als Anwendungen verteilt. Sie teilen sich
`VirtuSphere-Client-Common.ps1` (Adressfindung, Logging, Rückkanal).

## Reihenfolge (über MECM-Anwendungsabhängigkeiten)

```
client_getinfo  →  client_hostname  →  client_staticip  →  Set-VMDisksOnline
```

- **client_getinfo** installiert die Basisdaten in die Registry
  (`HKLM:\SOFTWARE\VirtuSphere`). Die drei folgenden sind Anwendungen vom
  Beziehungstyp *„hängt ab von"*, die für die Deploy-Collections bereitgestellt
  werden (z. B. „Deploy Windows Server 2019/2022").

## Adressfindung (Fallback-Kette)

Jedes Skript ermittelt die WebAPI-Adresse in dieser Reihenfolge:

1. **Registry-Override** `HKLM:\SOFTWARE\VirtuSphere\WebAPI` (falls gesetzt)
2. **DNS-Name** aus `VirtuSphere-Client-Common.ps1` (`$VsDefaultDnsApi`,
   Standard `virtusphere.lan:8021` — im Deploy-Netz einen passenden
   DNS-Eintrag anlegen)
3. **hartkodierte IP** `$VsFallbackIpApi` (optional, letzte Rettung)

`client_getinfo` schreibt die funktionierende Adresse in die Registry; die
Folge-Skripte lesen sie von dort. Vor dem Ausrollen die beiden
Standardadressen oben in der Common-Datei an die Umgebung anpassen.

## Rückkanal

Jede Phase meldet `started` vor und `finished`/`failed` nach der Aktion an die
WebApp (`mecm_report.php`), sodass der Deploy-Fortschritt im Portal an der VM
sichtbar wird. `staticip` meldet `started` **vor** der IP-Umstellung (der
Client kann danach durch einen VLAN-Wechsel offline sein — das Portal zeigt
dann „ausgeführt, Bestätigung ausstehend"), `hostname` meldet `finished`
**vor** dem Reboot. Der Rückkanal ist best effort und blockiert nie.

Die Client-Phasen authentifizieren sich über ihre bereits bekannte MAC; sie senden
keinen Rückkanal-Token (der Token gilt nur für die Server-Heartbeats).

## MECM-Anwendungsdefinitionen

| Skript | Erkennungsregel (Registry) | Wert | Exit-Codes |
|---|---|---|---|
| client_getinfo | `HKLM:\SOFTWARE\VirtuSphere\SetupState` | `complete` | 0 = ok, 1 = Fehler |
| client_hostname | `HKLM:\SOFTWARE\aplw-cgn\HostnameUpdate\Status` | `Erfolgreich`/`Uebersprungen` | 0, 1641 (Neustart), 1 |
| client_staticip | `HKLM:\SOFTWARE\APLw-CGN\staticip\installed` | `1` | 0 = ok, 1 = Fehler |
| Set-VMDisksOnline | `HKLM:\SOFTWARE\VirtuSphere\VMDiskManagement\VMDisksOnlineStatus` | `Success` | 0 = ok, 1 = Fehler |

Programm-Befehlszeile jeweils:
`powershell.exe -ExecutionPolicy Bypass -File "<skript>.ps1"`,
als System ausführen, Administratorrechte erforderlich. Für `client_hostname`
den Rückgabecode **1641** als „Erfolg mit Neustart" konfigurieren.

### Anlegen per Skript

`install-VirtuSphere-Clients.ps1` (im `Powershell-MECM`-Wurzelordner) legt diese
vier Applikationen im Konsolen-Ordner `VirtuSphere_Core` an, **falls sie fehlen**
(Self-Healing; bestehende Apps bleiben unangetastet), stellt je Ordner das
Client-Skript **plus** `VirtuSphere-Client-Common.ps1` bereit (ersetzt bestehende
Dateien) und verteilt den Content an eine DP-Gruppe. Es legt **kein** Deployment
an eine Collection an — das entscheidet der Admin.

```powershell
.\install-VirtuSphere-Clients.ps1 -ContentShare \\MECM-SERVER\VirtuSphere\Base\Packages
```

Die Erkennungswerte oben sind die SSoT: sie stehen als Datentabelle in
`mecm\VirtuSphere-ClientPackaging.ps1` und werden von einem Pester-Contract-Test
gegen den Quelltext der Client-Skripte geprüft (weicht die Tabelle von dem ab, was
ein Skript in die Registry schreibt, gilt die App nie als installiert). Der
WebAPI-Name wird **nicht** in die Common-ps1 gestempelt; die Auflösung läuft über
DNS.

**Logs:** `C:\Program Files\aplw\Logs\<datum>_<phase>.log` (30 Tage
Aufbewahrung).

## Wichtige Verhaltensdetails

- **client_getinfo** löscht vor dem Schreiben den alten `Interfaces`-Zweig und
  setzt den Erfolgs-Marker `SetupState=complete` erst nach vollständigem
  Schreiben — so kann eine neu ausgerollte VM mit weniger NICs keine veraltete
  Netzconfig erben, und Folgephasen starten nie mit halben Daten.
- **client_staticip** ist idempotent (Re-Run überschreibt sauber) und meldet
  echten Erfolg/Fehlschlag statt pauschal „installed".
- Nur **Workgroup**-Computer werden umbenannt; Domain-Computer überspringt
  `client_hostname`. Bereits partitionierte Datenträger werden nie formatiert.
