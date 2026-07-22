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
                           Heartbeats + Client-Phasen     --->  mecm_report.php  (ab Etappe 1)
```

- Reihenfolge der Client-Phasen ist über MECM-Anwendungsabhängigkeiten fixiert:
  `getinfo → hostname → staticip → disks` (disks optional).
- Zeitstempel der WebApp sind maßgeblich; Client-Uhren werden nicht vertraut.

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
> der Migration liefern `null`, ebenso Missionen, die über die Legacy-Token-API
> angelegt wurden (dort gibt es keinen Benutzerkontext, nur eine Token-Rolle).

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
Header `X-VirtuSphere-Token` gilt nur für `action=heartbeat` (Server-Sync-Aufgaben)
und wird nur geprüft, wenn im Portal unter Einstellungen ein Token generiert wurde.
`action=reportPhase` (Clients) authentifiziert ausschließlich über IP-Allowlist bzw.
bekannte MAC und braucht nie einen Token.

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

- **Integrationen** (alle angemeldeten Nutzer): Ampel je Quelle mit letzter
  Meldung, letzter Prüfung und Klartext-Handlungsanweisung bei Rot.
  Dashboard zeigt den schlechtesten Status als Kachel.
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
| `DeviceSyncIntervalSeconds` | Intervall Device-Sync (DWord, Standard 10) |
| `PackagesSyncIntervalSeconds` | Intervall Packages-Sync (DWord, Standard 60) |
| `ImporterIntervalSeconds` | Intervall Autoimporter (DWord, Standard 60) |
| `MECM_SiteCode` | erkannter Site-Code (nur wenn automatisch ermittelbar) |
| `SetupCompleted` | Zeitstempel der erfolgreichen Erstinstallation |

Hinweis HTTP/HTTPS: Die API-Aufrufe der Skripte laufen bewusst ueber HTTP; das
Portal-HTTPS aus WP7 leitet sie nie um (ADR-0027). Kommt bei E3 die
HTTPS-Umstellung der Maschinen-API (ADR-0019, Kandidat 5), aendern sich nur
die Registry-URL auf `https://` und eine einmalige
`[Net.ServicePointManager]::SecurityProtocol = Tls12`-Zeile in den Skripten;
ein Zertifikat pro Client ist nicht noetig, weil domänengebundene Maschinen
der Domaenen-CA automatisch vertrauen.

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

## Aktive Überwachung & Wartungs-Worker (Etappe 1b)

Der Compose-Service `maintenance-worker` (Container
`virtusphere-v2-webapp-maintenance-worker-1`) läuft dauerhaft neben dem
Deploy-Worker und erledigt:

1. **Eigen-Heartbeat** (alle 60 s): erscheint auf der Statusseite als
   „Wartungsdienst (WebApp)". Fehlt er, läuft der Container nicht.
2. **MECM-Erreichbarkeits-Probe** (alle 5 min): TCP-Verbindung zum
   MECM-Server. Ziel automatisch = Absende-IP des letzten
   Device-Sync-Heartbeats; übersteuerbar im Portal unter Einstellungen →
   „MECM-Erreichbarkeits-Prüfung" (Host + Port, Standardport 445). Eine
   fehlgeschlagene Prüfung schaltet die Zeile sofort auf Rot.
3. **Aufräumjobs** (stündlich): Client-Events älter als 30 Tage,
   Sicherheits-Protokolle (Anmeldung, Benutzer, Zugangsdaten) älter als 365 Tage,
   übrige Portal-Logs älter als 90 Tage, Anmeldeversuchs-Zähler älter als 7 Tage
   (gestaffelt nach Kategorie, siehe ADR-0026). (Früher lief die Log-Bereinigung
   huckepack bei API-Requests; jetzt läuft sie auch ohne Traffic.)
4. **Zustandswechsel-Audits**: Nur Übergänge von/nach Rot landen im Log
   (Kategorie „MECM-Integration"), niemals einzelne Heartbeats.

**Diagnose-Kombination auf der Statusseite:**

| Beobachtung | Bedeutung | Maßnahme |
|---|---|---|
| Sync-Quellen rot, Probe grün | Server läuft, Tasks melden sich nicht | Aufgabenplanung auf dem MECM-Server prüfen |
| Probe rot | Server/Netz nicht erreichbar | MECM-Server bzw. Netzwerk/Firewall prüfen |
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

Die drei Server-Skripte liegen versioniert unter `Powershell-MECM/mecm/` und
teilen sich `VirtuSphere-Common.ps1` (Konfiguration, Logging, Heartbeat,
MAC-Normalisierung, Site-Code-Erkennung). Installation und Registrierung der
geplanten Aufgaben erledigt `Powershell-MECM/install-VirtuSphere-MECM.ps1`
(idempotent, siehe `Powershell-MECM/README.md`).

Wichtige Härtungen gegenüber den Altskripten:

- **Konfiguration nur aus der Registry** `HKLM:\SOFTWARE\VirtuSphere\MECM` –
  keine IPs, DNS-Namen, UNC-Pfade oder Site-Codes im Code.
- **Kein Laufzeitlimit** für die Aufgaben (`ExecutionTimeLimit=PT0S`) plus
  Auto-Neustart – das Standard-72h-Limit hätte die Endlosschleifen sonst
  regelmäßig beendet.
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
- Alle drei senden je Durchlauf einen **Heartbeat**; ein toter Task wird im
  Portal unter *Integrationen* rot.

**Task neu starten** (MECM-Server): Aufgabenplanung öffnen → Task unter
`\` auswählen → *Ausführen*. Oder per PowerShell:
`Start-ScheduledTask -TaskName 'VirtuSphere MECM Devices Sync'`.

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
- Einheitliches Datei-Logging unter `C:\Program Files\aplw\Logs` (30 Tage).

## Edge Cases der Server-Skripte (Referenz)

Alle Fälle schreiben ins Tageslog (`%ProgramFiles%\VirtuSphere\Logs\<datum>_<komponente>.log`),
sofern nicht anders vermerkt. „Still" heißt: bewusst ohne Log-Eintrag, um Spam
im 10s/60s-Takt zu vermeiden; Sichtbarkeit entsteht anderweitig (Heartbeat/Portal).

**Alle drei Skripte gemeinsam**

| Fall | Verhalten | Log |
|---|---|---|
| Registry-Konfiguration fehlt komplett | wartet in 60-s-Schleife auf den Installer (Selbstheilung, kein Exit) | ERROR einmalig (Default-LogRoot) |
| WebApp/MECM-Fehler im Durchlauf | Backoff 30 s; ab 3 Fehlern in Folge 60 s + Site-Drive-Neuinitialisierung | ERROR je Versuch |
| Heartbeat-Zustellung scheitert | still verworfen (fire-and-forget, ADR-0018) | still; Portal-Ampel wird rot |
| Registry-Änderung zur Laufzeit | greift erst nach Task-Neustart (Konfig wird beim Start gelesen; Installer-Re-Run startet die Tasks neu) | — |
| Dateilog selbst nicht schreibbar | Sync läuft weiter (Logging stoppt nie den Prozess) | Konsole only |

**Device-Sync**

| Fall | Verhalten | Log |
|---|---|---|
| 0 Devices von der WebApp | Leerlauf-Abkürzung, keine MECM-Abfragen | still |
| VM ohne Mission / ohne DHCP-MAC | übersprungen, nächste VM | WARN |
| MAC-Konflikt MECM ≠ ESXi | nur melden, nie automatisch ändern | WARN „manuelle Prüfung" |
| Import-Race (paralleler Scan) | toleriert; Existenz-Nachprüfung statt Fehlertext-Parsing (sprach-/versionsneutral) | still |
| Mehrere DHCP-Interfaces an einer VM | erste MAC wird genutzt | WARN |
| Auto-Approve scheitert / ResourceID fehlt noch | Retry im nächsten Scan | DEBUG + WARN |
| Ziel-Collection existiert nicht | Zuweisung übersprungen | WARN |
| Collection angelegt, Ordner-Verschub/Ordner-Anlage scheitert | Collection bleibt im Wurzelordner, funktional ok | WARN |
| Collection-Update nicht anstoßbar | Mitgliedschaft greift erst beim nächsten MECM-Zyklus | WARN |
| ResourceID-Rückmeldung an WebApp scheitert | Sync läuft weiter | WARN |

**Packages-Sync**

| Fall | Verhalten | Log |
|---|---|---|
| Applications-Ordner fehlt / Katalog leer | Sende-Guard: nichts senden | WARN |
| Katalog unverändert (Hash) | kein Sync; Voll-Sync spätestens stündlich | still (Konsole) |
| WebApp lehnt mit 409 ab (Schutzschwelle) | Hash nicht gemerkt → nächster Durchlauf versucht erneut | WARN |

**Autoimporter**

| Fall | Verhalten | Log |
|---|---|---|
| `config.json` fehlt im Ordner | Ordner ignoriert | still |
| `config.json` ungültig / ohne ProjectName+version | übersprungen | WARN |
| `PackagesShare` fehlt in Registry | wartet in 60-s-Schleife auf den Installer | ERROR einmalig |
| files-Baum unverändert (mtime-Stamp) | kein Scan | still |
| files-Pfad fehlt | Scan übersprungen | WARN |
| Alt-Version nicht vollständig entfernbar | Retry im nächsten Durchlauf (Stamp wird nicht gemerkt) | WARN |
| Alt-Version ohne eigene Collection | wird über die Application gefunden und bereinigt | Log je Entfernung |
| Vorlagen-install.ps1 nicht kopierbar | Paket nutzt eigene Datei | WARN |
| Deployment/Collection fehlt (auch nach früherem Teilfehler) | wird idempotent nachgezogen; bei Fehlschlag Retry im nächsten Durchlauf | WARN |
| Content-Verteilung scheitert | kein Auto-Retry (Doppel-Verteilung wirft selbst Fehler); manuell anstoßen | WARN mit DP-Gruppe |
| `DeployTo`-Ziel-Collection fehlt | Konfigurationsfehler; kein Dauer-Retry | WARN |
| Application existiert bereits | Anlage übersprungen, Collection/Deployment werden trotzdem geprüft | still (Konsole) |

## Troubleshooting

Erste Anlaufstelle ist immer die Portal-Seite **Integrationen** (Klartext-Ampel
je Quelle mit Handlungsanweisung). Häufige Fälle:

**VM taucht nicht in MECM auf**
1. *Integrationen* prüfen: läuft „MECM Device-Sync"? Wenn rot → Aufgabenplanung
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
   `C:\Program Files\aplw\Logs`.

**Pakete verschwinden / Sync abgelehnt (409)**
1. Im Log (Kategorie „MECM-Integration") nach „Katalog-Sync abgelehnt" suchen:
   Der Paket-Sync hätte mehr als die Schutzschwelle zurückgezogen, meist ein
   WMI-Aussetzer oder falscher Collections-Ordner auf dem MECM-Server.
2. Katalogquelle prüfen; notfalls Schwelle temporär anheben (Portal →
   Einstellungen → Paket-Sync-Schutzschwelle).

**MECM-Server offline**
- *Integrationen* zeigt „MECM-Server erreichbar" rot → Server/Netz/Firewall
  zwischen WebApp und MECM-Server prüfen. Probe-Ziel/-Port sind im Portal unter
  Einstellungen konfigurierbar (Standard: IP des letzten Device-Sync, Port 445).

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
