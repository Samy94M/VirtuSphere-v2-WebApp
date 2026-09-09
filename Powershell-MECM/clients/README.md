# VirtuSphere Client-Skripte

Diese Skripte laufen auf den per PXE frisch installierten Windows-Clients und
werden über das MECM-Software-Center als Anwendungen verteilt. Sie teilen sich
`VirtuSphere-Client-Common.ps1` (Adressfindung und Rückkanal) sowie das lokal
mitgelieferte, versionierte `VirtuSphere-Client-Logging.ps1`.

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
2. **mitgelieferter Notfall-DNS-Name** aus `VirtuSphere-Client-Common.ps1`
3. **mitgelieferte Notfall-IP** als letzte Rückfallebene

Vor der ersten Auflösung übernimmt `client_getinfo` die mit dem Client-Installer
erzeugte `bootstrap.json` einmalig in die Registry, sofern dort noch kein
`WebAPI`-Wert existiert. Danach ist ausschließlich die Registry wirksam;
vorhandene Werte werden bei einem Paketupgrade nicht überschrieben.

Der Bootstrap ist der reguläre Konfigurationsweg. Für einen Standortwechsel
wird der Clientinstaller mit `-WebApi`, `-Scheme` und optional
`-CertThumbprint` erneut ausgeführt; ausgelieferter Quelltext wird nicht
bearbeitet. Die Adressprobe akzeptiert nur das VirtuSphere-Health-Schema, nicht
irgendeine HTTP-Antwort. JSON-POSTs sind explizite UTF-8-Bytes. Ein leerer
Fingerabdruck nutzt normale PKI, ein gesetzter ist nur die enge Ausnahme für
genau dieses Zertifikat; es gibt keinen Accept-all-Schalter.

Wenn der DNS-Administrator beim ersten Rollout noch nicht verfügbar ist, wird
die feste, aus allen Deploy-VLANs erreichbare WebApp-IP beim Packaging gesetzt:

```powershell
.\install-VirtuSphere-Clients.ps1 -ContentShare '\\MECM-01\VirtuSphere\Base\Packages' -WebApi '192.0.2.10:8021' -Scheme http
```

Der DNS-Kandidat bleibt für die spätere Umstellung erhalten. Bei HTTPS ist eine
IP nur geeignet, wenn das Zertifikat diese IP als Subject Alternative Name
enthält. Das vollständige Vorgehen einschließlich Server-Installer und späterem
DNS-Wechsel steht im **Admin-Runbook** in
[`docs/operations/mecm-integration.md`](../../docs/operations/mecm-integration.md).

## Rückkanal

Jede Phase meldet `started` vor und `finished`/`failed` nach der Aktion an die
WebApp (`mecm_report.php`), sodass der Deploy-Fortschritt im Portal an der VM
sichtbar wird. `staticip` meldet `started` **vor** der IP-Umstellung (der
Client kann danach durch einen VLAN-Wechsel offline sein — das Portal zeigt
dann „ausgeführt, Bestätigung ausstehend"), `hostname` meldet `finished`
**vor** dem Reboot. Diese Phasenmeldungen sind best effort und blockieren nie.

Davon getrennt ist der verbindliche Client-Ready-ACK von `client_getinfo`:
Basisfelder und Interfaces werden zunächst unter einem neuen, versionierten
`Snapshots`-Schlüssel vorbereitet und vollständig nachgelesen. Erst der finale
`ActiveSnapshot`-Zeiger veröffentlicht diesen Satz für die Folgephasen. Danach sendet V23 die MAC
per POST an `mecm_client_ack.php`. Erst das setzt die VM auf 5/5; erst die
bestätigte Antwort setzt lokal `SetupState=complete`. Damit hinterlässt auch ein
harter Abbruch während des POSTs keinen falschen MECM-Erkennungsstatus; der
Server dedupliziert einen bereits angekommenen POST.

Seit Etappe 14D (ADR-0043, ADR-0019-Amendment 3) trägt der ACK zusätzlich die
`rollout_revision` aus derselben `getDeviceInfos`-Antwort, die der Lauf gerade
gelesen und geschrieben hat. Ohne sie könnte der Client eines Rollouts, der
zwischenzeitlich zurückgesetzt wurde, den Lebenszyklus des neuen abschließen;
der Server prüft sie unter demselben VM-Lock wie seinen Write und antwortet
sonst mit 409 ohne Seiteneffekt. Der Wert ist reine Korrelation: `client_getinfo`
whitelistet und speichert ihn, `Confirm-VsClientReady` schickt ihn zurück, und
**kein** Skript der Kette entscheidet anhand seines Werts. Ein Clientpaket von
vor dem Cutover sendet das Feld gar nicht, statt eine `0` zu erfinden; der Server
nimmt das ausschließlich für Revision 1 ohne Tombstone an.

Ebenfalls seit 14D ist `vm_hostname` in der Antwort der **eingefrorene
Rolloutname**, nicht der aktuelle Portal-Sollwert. `client_hostname.ps1` bleibt
unverändert: Es benennt Windows nach dem, was in der Registry steht, und das ist
genau der Name, unter dem MECM das Gerät importiert hat. Haben Import und Task
Sequence ihn bereits angewandt, endet die Phase ohne Rename-Reboot.

Die Client-Phasen authentifizieren sich über ihre bereits bekannte MAC; sie senden
keinen Rückkanal-Token (der Token gilt nur für die Server-Heartbeats).

Alle URLs — einschließlich des ACK — laufen durch `Get-VsApiUrl`. Standard ist
`http`; dafür werden weder CA noch Zertifikat noch Thumbprint benötigt. `https`
bleibt eine optionale Registry-/Paketkonfiguration und ändert keine Endpunkte.

## MECM-Anwendungsdefinitionen

| Skript | Erkennungsregel (Registry) | Wert | Exit-Codes |
|---|---|---|---|
| client_getinfo | `HKLM:\SOFTWARE\VirtuSphere\SetupState` | `complete` | 0 = ok, 1 = Fehler |
| client_hostname | `HKLM:\SOFTWARE\VirtuSphere\HostnameUpdate\Status` | `Erfolgreich`/`Uebersprungen` | 0, 1641 (Neustart), 1 |
| client_staticip | `HKLM:\SOFTWARE\VirtuSphere\staticip\installed` | `1` | 0 = ok, 1 = Fehler |
| Set-VMDisksOnline | `HKLM:\SOFTWARE\VirtuSphere\VMDiskManagement\VMDisksOnlineStatus` | `Success` | 0 = ok, 1 = Fehler |

Programm-Befehlszeile jeweils:
`powershell.exe -ExecutionPolicy Bypass -File "<skript>.ps1"`,
als System ausführen, Administratorrechte erforderlich. Für `client_hostname`
den Rückgabecode **1641** als „Erfolg mit Neustart" konfigurieren.

### Anlegen per Skript

`install-VirtuSphere-Clients.ps1` (im `Powershell-MECM`-Wurzelordner) legt diese
vier Applikationen im Konsolen-Ordner `VirtuSphere_Core` an, **falls sie fehlen**
(Self-Healing), stellt je Ordner das
Client-Skript **plus** `VirtuSphere-Client-Common.ps1` **plus**
`VirtuSphere-Client-Logging.ps1` sowie das nicht geheime Bootstrapmanifest bereit, ersetzt diesen Satz beim Upgrade
als einen SHA-256-geprüften Paketsatz per atomarem Verzeichnis-Swap und rollt bei
einem Aktivierungsfehler den vollständigen Altstand zurück. Das vollständige
Pfad-/Längen-/SHA-256-Manifest jedes lokalen Ordners muss am tatsächlichen
`ContentShare` identisch lesbar sein; eine alte gleichnamige Datei reicht nicht.
Der Installer prüft alle veröffentlichten Clientordner und bricht bei einem fehlenden, nicht
vollständig lesbaren oder abweichenden Manifest vor Site-Initialisierung und vor
jedem Configuration-Manager-Cmdlet ab. In diesem Lauf werden keine Applications,
Deployment Types, Dependencies oder Contentverteilungen geändert. Freigabepfad,
Leserechte beziehungsweise Inhalt korrigieren und den Installer danach erneut
starten.
Bei jedem Re-Run werden Eigentumsmarker bzw. der enge Legacy-Ordnernachweis,
genau ein verwalteter Deployment Type, Detection, Systemkontext, Rebootverhalten,
die Standard-Returncodes und jede Dependency bis zum wirklichen Ziel-DT geprüft.
Fehlende eigene Teile werden ergänzt. Gleichnamige fremde, zusätzliche,
mehrdeutige oder manuell abweichende Definitionen bleiben unverändert und sind
Blocker; der Name allein gilt nie als Eigentumsnachweis. Danach verteilt der
Installer den Content an die angegebene DP-Gruppe.
Es legt **kein** Deployment an eine Collection an — das entscheidet der Admin.

```powershell
.\install-VirtuSphere-Clients.ps1 -ContentShare \\MECM-SERVER\VirtuSphere\Base\Packages -WebApi 'virtusphere.lan:8021' -Scheme http
```

Die Erkennungswerte oben sind die SSoT: sie stehen als Datentabelle in
`mecm\VirtuSphere-ClientPackaging.ps1` und werden von einem Pester-Contract-Test
gegen den Quelltext der Client-Skripte geprüft (weicht die Tabelle von dem ab, was
ein Skript in die Registry schreibt, gilt die App nie als installiert). Der
WebAPI-Name wird **nicht** in die Common-ps1 gestempelt. Der Installer erzeugt
`bootstrap.json`; es füllt nur bei der Erstinstallation fehlende Registrywerte
und bleibt danach kein konkurrierender Laufzeitleser.

**Logs:** `C:\Program Files\VirtuSphere\Logs\yyyy-MM-dd_<phase>.log` mit
`ISO-8601 | LEVEL | Komponente | Kontext | Nachricht | Korrelations-ID`. Dieser
Vertrag, seine UTF-8-sicheren Grenzen, Secret-Redigierung, Level und 30 Tage
Aufbewahrung sind gespiegelt zum Servermodul und durch Pester gepinnt. Eine
Terminalsequenz wird vor der Redigierung entfernt, damit sie einen bekannten
Secret-Schluessel nicht aufteilen kann. Erkannt werden dokumentierte benannte
Header-, Parameter- und Schluesselformen; unbeschrifteter Freitext kann nicht
pauschal als Secret erkannt werden. Der Logger schreibt auf den Host-/
Informationspfad und laesst Stream 1 fuer strukturierte Rueckgaben frei. Eine
Korrelations-ID gilt pro Prozess und reist bei Phasenmeldung und Client-Ready-ACK
nur als additiver Diagnoseheader; der JSON-Body bleibt unverändert. Ein defekter
Dateisink stoppt die Phase nicht und meldet lokal höchstens einmal die Störung
sowie einmal die Erholung. Daraus entstehen keine weiteren Portalaufrufe.

## Wichtige Verhaltensdetails

- **client_getinfo** schreibt jeden Lauf in einen neuen Snapshot; leere oder
  entfernte optionale Werte können deshalb nicht aus einem Vorlauf überleben.
  `client_hostname`, `client_staticip` und der Phasen-MAC-Leser akzeptieren nur
  den vollständig publizierten aktiven Snapshot zusammen mit
  `SetupState=complete`. Das Skript bestätigt Client-Ready explizit. Den
  Erfolgs-Marker `SetupState=complete` setzt es erst nach dem ACK. So kann eine
  neu ausgerollte VM mit weniger NICs
  keine veraltete Netzconfig erben, und Folgephasen starten nie mit halben oder
  serverseitig unbestätigten Daten.
- **client_staticip** validiert vor dem ersten Netzwerk-Write die vollständige
  Sollmenge: jede normalisierte MAC muss genau einen aktiven kabelgebundenen
  Adapter treffen, Namen dürfen nicht kollidieren und höchstens ein statisches
  Interface darf ein Default-Gateway vorgeben. Bereits passende Werte bleiben
  unverändert; entfernt werden ausschließlich IPv4-Adresse und Default-Route,
  die ein früherer erfolgreicher VirtuSphere-Lauf mit MAC und Präfix
  dokumentiert hat. IPv6 und fremde manuelle Werte bleiben erhalten. Leeres DNS
  bei `static` bedeutet „bestehenden DNS-Stand erhalten“, bei `dhcp` werden die
  DNS-Server auf automatische Ermittlung zurückgesetzt. `Tentative` wird
  höchstens 15 Sekunden abgewartet, `Duplicate`/`Invalid` sind Fehler. Ein
  Rückfall greift nur auf Änderungen dieses Laufs und überschreibt keine
  zwischenzeitliche Fremdänderung. Portal-Erreichbarkeit und lokal bestätigte
  IP-Konfiguration bleiben getrennte Aussagen.
- Nur **Workgroup**-Computer werden umbenannt; Domain-Computer überspringt
  `client_hostname`.
- **Set-VMDisksOnline** schaltet vorhandene GPT-/MBR-Datenplatten nur online und
  formatiert sie nie. Vor der ersten Änderung einer neuen Offline-RAW-Platte
  schreibt es unter
  `HKLM:\SOFTWARE\VirtuSphere\VMDiskManagement\Operations` ein versioniertes
  Eigentumsjournal aus stabiler `UniqueId`, ersatzweise nur aus der vollständigen
  Kombination Seriennummer, LocationPath und Größe. Nach Abbruch oder Neustart
  wird die Operation auch bei geänderter Disknummer und bereits online
  befindlicher Platte fortgesetzt. Das Skript liest nach Online, GPT,
  Partition, Laufwerksbuchstabe und NTFS-Volume jeweils den tatsächlichen Stand
  nach. Unbekannte online-RAW-Platten, mehrdeutige Identitäten, abweichende
  Größen, fremde Partitionen sowie Boot-/System-, schreibgeschützte, Cluster-
  und größenlose Platten blockieren die Phase ohne Formatierung. Nur wenn weder
  eine offene eigene Operation noch eine optionale Zusatzplatte existiert, ist
  `optional: no disk work required` ein erfolgreicher Leerlauf. Ein exklusiver
  Prozesslock verhindert zwei parallele Storage-Läufe.
