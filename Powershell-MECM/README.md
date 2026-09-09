# VirtuSphere MECM-Integration – PowerShell

Diese Skripte verbinden den MECM-Server mit der VirtuSphere-WebApp. Sie
laufen als geplante Aufgaben auf dem MECM-Server (`mecm/`) bzw. werden über das
MECM-Software-Center auf die PXE-installierten Clients verteilt (`clients/`).

Der normale Paketimport löscht keine Altversion. Eine Bereinigung braucht einen
separat geprüften Plan mit eindeutigem numerischem Zielstand, stabilen IDs,
VirtuSphere-Ownership, fehlenden Referenzen und vollständig bereitem Ersatz.
Freie oder doppelte Versionsstrings, parallele Quellversionen und Altobjekte
ohne Marker bleiben erhalten. Ein Portal-Retirement ist kein MECM-Löschauftrag.

JSON wird unter Windows PowerShell 5.1 explizit als UTF-8 gesendet. Bei HTTPS
bedeutet ein leerer Fingerabdruck normale PKI-Prüfung; ein gesetzter Abdruck ist
nur die enge Vertrauensausnahme für genau dieses selbstsignierte Zertifikat.
Ein allgemeiner Accept-all-Pfad existiert nicht. Die Paketpfadprüfung arbeitet
mit SIDs und erkennt deshalb dieselben breiten Schreibrechte auf deutschem und
englischem Windows.

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
   Prozessliste sichtbar bleibt. Ein Re-Run **ohne** `-ReportToken` behält den
   eingestellten Token; **entfernt** wird er durch `-ReportToken ''`, also den
   ausdrücklich leer übergebenen Parameter. Alle drei Ausgänge (behalten,
   ersetzt, gelöscht) stehen im Tageslog, die Löschung als WARN-Zeile, nie der
   Wert selbst.
3. Im Portal unter *Einstellungen → Machine-API IP-Freigaben* die IP des
   MECM-Servers freischalten, dann den *Systemstatus* beobachten – die
   drei Sync-Quellen und der MECM-Site-Status sollten auf „OK“ springen.

Ist noch kein DNS-Eintrag verfügbar, darf der Server-Installer vorläufig eine
feste IP als `-WebApi '<WEBAPP-IP>:8021'` erhalten. Vor dem Client-Installer muss
diese Adresse mit dessen `-WebApi`-Parameter in das ausgelieferte
`bootstrap.json` geschrieben werden. `client_getinfo` übernimmt sie vor der
ersten Auflösung einmalig in die Registry; Quelltext wird nicht angepasst. Die vollständige
Übergangs- und spätere DNS-Wechselprozedur steht im Admin-Runbook.

Der Installer ist idempotent: erneutes Ausführen aktualisiert Konfiguration und
Skripte. Die vier Intervalle und `MECM_ProviderMachine` behalten dabei ihren
eingestellten Wert, wenn der jeweilige Parameter nicht angegeben wird; ein
Skript-Update setzt einen getunten Takt also nicht auf den Standard zurück.

**Ergebnis beider Installer.** Sie unterscheiden zwei Klassen von Meldung. Ein
**Blocker** (`!!`) heißt, die Installation hat ihre Arbeit nicht geleistet: eine
Aufgabe läuft nicht, das Portal antwortet nicht oder mit 403, ein Tageslog bleibt
leer, die Freigabe zeigt nicht auf den Paketpfad, oder eine Application, ihr
Content oder die Vorlage fehlt. Die Schlusszeile nennt dann die Zahl der offenen
Punkte, und der Prozess endet mit Exit-Code 1. Beim Client-Installer werden immer
alle veröffentlichten Client-Contentmanifeste geprüft. Fehlt eines, ist es nicht
vollständig lesbar oder weicht es ab, endet der Lauf nach dieser vollständigen
Prüfung vor Site-Initialisierung und vor jedem Configuration-Manager-Cmdlet. Erst
nach Korrektur von Freigabepfad, Leserechten oder Inhalt darf der Installer erneut
gestartet werden. Ein **Hinweis** (`~~`) berührt das Ergebnis nicht: die
DP-Gruppe darf legitim erst nach der Installation entstehen,
DNS löst auf dem MECM-Server anders auf als im Deploy-VLAN, der Paketordner ist
für Benutzer beschreibbar (eine ACL-Entscheidung), oder der Site-Health-Provider
ist nicht abfragbar (das heißt „nicht abfragbar“, nicht „Site krank“). Die
Abhängigkeitskette der Client-Apps wird bei jedem Re-Run geprüft; ein
fehlender verwalteter Deployment Type oder eine nicht eindeutig nachweisbare
notwendige Abhängigkeit ist ein Blocker. Ohne
offene Punkte endet der Installer mit 0. Beide Klassen stehen zusätzlich im
Tageslog unter `Logs`, weil das Konsolenfenster den Feierabend nicht überlebt.

## Die vier geplanten Aufgaben (MECM-Server)

| Aufgabe | Skript | Zweck | Intervall |
|---|---|---|---|
| VirtuSphere MECM Devices Sync | `mecm_new-device-sync.ps1` | VMs aus VirtuSphere nach MECM importieren, Collections zuweisen, ResourceID zurückmelden | 10 s |
| VirtuSphere MECM Packages Sync | `mecm_Packages-TaskSeq-sync.ps1` | Paket-/Task-Sequence-Katalog an die WebApp melden | 60 s |
| VirtuSphere MECM Package Import | `mecm_autoimporter.ps1` | aus `config.json`-Ordnern MECM-Applications erzeugen | 60 s |
| VirtuSphere MECM Site Health | `mecm_site-health.ps1` | offiziellen MECM-Site-Zustand aus `SMS_SummarizerSiteStatus` melden | 300 s |

Alle vier laufen als `NT AUTHORITY\SYSTEM`, höchste Rechte, ohne Profil
(`-NoProfile`), Doppelstart-Schutz `MultipleInstances IgnoreNew`, **ohne
Laufzeitlimit** (`PT0S`, Endlosschleifen) und mit Auto-Neustart bei Absturz.

Jede Aufgabe hat **zwei** Trigger: `AtStartup` und eine stündliche
Wiederholung. Mit dem Systemstart allein war eine Aufgabe nach ihren drei
Neustartversuchen bis zum nächsten Reboot tot, und ein MECM-Server bootet
selten: der Ausfall sah aus wie eine stille Integration. `IgnoreNew` sorgt
dafür, dass der stündliche Trigger nichts tut, solange die Aufgabe läuft. Der
Installer deaktiviert ihre Trigger, beendet laufende Aufgaben, bevor er die
Skripte ersetzt, und registriert und startet sie danach neu. Ein Fehler beim
Deaktivieren oder Stoppen bricht den Austausch sichtbar und fail-closed ab.

Upgrade und Re-Run halten dafür einen gemeinsamen Rollbackkontext. Ein globaler
Mutex verhindert konkurrierende Installer; der Installationspfad muss exakt das
lokale, reparse-freie `%ProgramFiles%\VirtuSphere\mecm` sein. Vor dem ersten
Write sichert der Installer alle Registrywerte samt Typ und ACL sowie XML und
Laufzustand der vier eigenen Aufgaben. Der alte vollständige Dateisatz bleibt
bis nach Registrierung, Start, Verifikation und Abschlussmarker erhalten. Bei
einem Fehler werden zunächst alle eventuell neuen Aufgabenprozesse beendet,
dann Dateien und Registry und zuletzt die vorherigen Taskdefinitionen
wiederhergestellt. Zuvor laufende alte Aufgaben werden nur neu gestartet, wenn
Prozessende, Datei- und Registry-Rollback vollständig belegt sind; andernfalls
bleiben sie deaktiviert und die Fehlermeldung nennt den unvollständigen Teil.

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

**Logs:** `%ProgramFiles%\VirtuSphere\Logs\yyyy-MM-dd_<komponente>.log`.
Server und Clients verwenden denselben versionierten Sechs-Feld-Vertrag:
`ISO-8601 | LEVEL | Komponente | Kontext | Nachricht | Korrelations-ID`, die
Level `DEBUG`/`INFO`/`WARN`/`ERROR`, UTF-8-sichere Grenzen, Redigierung benannter
Secrets und 30 Tage Aufbewahrung. Eine Korrelations-ID gilt für genau einen
PowerShell-Prozess und wird als reiner Diagnoseheader an die WebApp gegeben. Ein
nicht vertrauenswuerdiger Logtext wird zuerst von ANSI-/OSC-Terminalsequenzen
bereinigt und erst danach redigiert; dadurch kann eine Escape-Sequenz einen
bekannten Secret-Schluessel nicht in zwei scheinbar harmlose Teile zerlegen.
Die Zusage gilt fuer die dokumentierten benannten Header-, Parameter- und
Schluesselformen, nicht fuer unbeschriftete Geheimnisse in beliebigem Freitext.
Logger schreiben Diagnosen nicht auf den Success-Stream; datengebende Helfer
liefern dort deshalb nur ihren Fachwert. Ein
nicht schreibbarer Log-Sink stoppt die Aufgabe nicht: lokal erscheint höchstens
eine Warnung pro Störung und eine Meldung bei Erholung. Das erzeugt weder einen
zusätzlichen Heartbeat noch einen Auditeintrag. Site Health schreibt
`yyyy-MM-dd_site-health.log`.

## Architektur und Funktionsweise

### Common-Fassade und versioniertes Loggingmodul

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

Die Loggingdomäne liegt getrennt in `mecm\VirtuSphere-Logging.ps1`; Common lädt
dieses lokale Modul und verlangt exakt dessen Vertragsversion. Fehlt die Datei
oder passt ihre Version nicht, bricht das Serverpaket vor dem ersten Sync hart
und verständlich ab. Der Installer staged und prüft alle Serverdateien per
SHA-256 und liest die deklarierten Vertragsversionen per AST, ohne die
zustandsbehafteten Stagingdateien auszuführen. Erst danach deaktiviert und
beendet er die laufenden Aufgaben und tauscht Loggingmodul, Laufzeitskripte und
Common-Fassade als einen Paketsatz aus.

Dieser Satz bildet zusammen mit dem Registry- und Taskzustand eine
Installertransaktion: Bis zum Abschlussmarker bleibt der vollständige Altstand
verfügbar. Ein Rollback startet alte Aufgaben erst wieder, nachdem neue Prozesse
beendet und Dateien sowie Registry vollständig wiederhergestellt wurden.

Common stellt bereit:

| Funktion | Zweck |
|---|---|
| `Get-VsConfig` | liest die komplette Konfiguration aus `HKLM:\SOFTWARE\VirtuSphere\MECM`; liefert `$null`, wenn sie fehlt |
| `Initialize-VsLog` / `Write-VsLog` | von `VirtuSphere-Logging.ps1` bereitgestellter Tageslogvertrag; tägliche, pro gemeinsamem Marker gesperrte Aufräumprüfung nach 30 Tagen; Log-Fehler stoppen nie den Hauptprozess |
| `Invoke-VsApi` / `Get-VsApiBaseUrl` | HTTP-Aufrufe an die WebApp; `Get-VsApiBaseUrl` ist die **einzige** Schema-Stelle der Server-Skripte und liest `Scheme` aus der Registry (Default `http`, LAN-Projektziel). Der Body geht über `ConvertTo-Json -InputObject`, damit eine Liste **immer** ein JSON-Array bleibt: über die Pipeline packt PS 5.1 eine einelementige Liste aus, und `mecm_packages.php` beantwortet das dauerhaft mit 400 |
| `Get-VsErrorDetail` / `Get-VsErrorStatusCode` | lesen den **Antwort-Body** einer fehlgeschlagenen Anfrage. `Invoke-RestMethod` wirft in PS 5.1 bei 4xx/5xx und verwirft den Body dabei — genau dort steht aber die JSON-Envelope der WebApp (`{"error":"..."}`). Ohne diese Helfer sagt das Log nur `(400) Bad Request`, nie den Grund |
| `Resolve-VsInterval` | löst das konfigurierte Intervall **einmal** auf, für den Sleep und den Report: Untergrenze je Aufgabe aus `$script:VsIntervalBounds`, Obergrenze aus dem Wire-Contract, und eine WARN-Zeile, wenn geklemmt wurde. Die Statusseite färbt die Zeile nach dem *gemeldeten* Takt, also darf keine Aufgabe in einem anderen laufen |
| `New-VsRunId` / `Send-VsRunReport` | Ergebnisbericht an `mecm_report.php?action=reportRun`: eigene `run_id` je Lauf, `started`/`completed`, Ergebnis, Fehlerkategorie, quellenspezifisches Summary; zentrale Detail-Redaction und Byte-Kürzung vor dem Versand. Ein fehlgeschlagener Bericht bricht den eigentlichen Lauf nie ab; Zustellfehler werden lokal gedrosselt protokolliert |
| `Get-VsProviderMachine` / `Get-VsMecmSiteHealth` | SMS-Provider ermitteln (Installerparameter → Registry `MECM_ProviderMachine` → lokale WMI-Erkennung → CMSite-PSDrive → Computername) und `SMS_SummarizerSiteStatus` per CIM für exakt den konfigurierten Site-Code abfragen; fehlende, mehrdeutige oder unlesbare Zeilen sind `unknown`, die reine Statusabbildung bleibt `0→ok`, `1→warning`, `2→fail`, sonst `unknown` (ohne MECM per Pester testbar) |
| `Send-VsHeartbeat` | Fire-and-forget-POST an `mecm_report.php?action=heartbeat` (5 s Timeout, Fehler bewusst still); nur noch für Rückwärtskompatibilität, die aktuellen Skripte senden `reportRun` (ADR-0018) |
| `ConvertTo-VsNormalizedMac` | MAC kanonisch: Großbuchstaben, Doppelpunkte; verhindert falsche Konfliktmeldungen zwischen ESXi- und MECM-Schreibweisen. **Existiert dreimal** (hier, im Client-Common, als `virtusphere_normalize_mac()` in PHP) — die drei laufen auf drei Maschinen und teilen sich keine Datei, aber `Docker/WebAPI/tests/fixtures/mac-vectors.json` als gemeinsame Wahrheit: wer eine ändert, ohne die anderen nachzuziehen, bricht den Build (ADR-0029) |
| `Read-VsPackageConfig` / `Get-VsSupersededNamePattern` | `config.json` eines Paketordners lesen und validieren; Muster zum Erkennen namensähnlicher Alt-Versionen (`^Name-<version>$`, exakt). Das Muster ist kein Eigentumsnachweis und autorisiert keine Löschung. Liegen hier und nicht im Autoimporter, weil der eine Endlosschleife ist und dort kein Test hinkommt |
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
2. MECM-Daten **einmal pro Scan** cachen: `New-VsMecmDeviceIndex` baut drei
   Multimaps nach ResourceID, normalisiertem Namen und **jeder** normalisierten
   MAC. Eine Hashtabelle mit dem Anzeigenamen als Einzelschlüssel ist verboten
   (Etappe 14D): sie löste Duplikate still nach „last wins" auf, und welcher von
   zwei gleichnamigen Datensätzen gewann, entschied die Reihenfolge der
   Providerantwort. Eine Mehrdeutigkeit muss ein Fehler bleiben und darf keine
   Auswahlheuristik werden.
3. Ordner `VirtuSphere_OS` und `VirtuSphere_Missions` sicherstellen; für jede
   Task Sequence eine gleichnamige Collection im OS-Ordner anlegen.
4. Je Device:
   - PXE-MAC aus dem ersten DHCP-Interface ermitteln (Warnung bei mehreren);
     ohne Mission oder MAC wird das Device übersprungen.
   - Mission-Collection bei Bedarf anlegen.
   - **Identität auflösen** (`Resolve-VsDeviceIdentity`, rein und ohne
     Providerzugriff, damit Pester jeden Zweig fahren kann). Die Reihenfolge ist
     die Aussage: Eine gebundene ResourceID gilt allein, und ein davon
     abweichender **Anzeigename ist ausdrücklich kein Fehler** (Windows und
     Discovery dürfen umbenennen; kein Reimport, kein Rename, keine Warnung alle
     zehn Sekunden). Ohne Bindung blockiert ein noch vorhandenes
     Vorgängergerät; danach wird genau ein Objekt übernommen, wenn Name **und**
     MAC gemeinsam eindeutig darauf zeigen. Jeder andere Ausgang ist ein
     geschlossener Fehlercode, zählt einen Fehlschlag und lässt die VM in der
     Warteschlange. Das passiert **vor** jeder Mitgliedschaftsmutation.
   - Neues Device per `Import-CMComputerInformation -ComputerName <Rolloutname>`
     in "All Systems" importieren; importiert wird der Windows-Hostname aus dem
     Wire-Feld `vm_hostname`, nicht der ESXi-Name. Danach wird **nicht** nach
     Fehlertext geraten, sondern Name und MAC werden erneut eindeutig gelesen
     (kein `-MergeIfExist`, kein Name-only-Fallback).
   - ResourceID beschaffen, notfalls per `Approve-CMDevice` nachhelfen;
     ohne ResourceID: nächster Scan.
   - Direct-Membership-Regeln für OS-, Paket- und Mission-Collections setzen.
     Vor jedem Remote-Write wird ein lokaler Journal-Intent dauerhaft
     gespeichert. Nur ein bestätigtes Remote-Ergebnis wird idempotent ans
     Portal nachgemeldet; ein beim Absturz offener Intent blockiert als
     ungeklärter Ownership-Fall statt eine vorhandene Regel zu adoptieren.
   - Provenienz und ResourceID per `POST /mecm_updateid.php` zurückmelden,
     beides mit `rollout_revision`. Antwortet das Portal mit 409, arbeitet
     dieser Scan mit einem veralteten Rollout: eigener Code
     `stale_rollout_revision`, kein Transportfehler, kein Handgriff nötig.
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
- **Change-Detection:** Fingerabdruck über alle `config.json` (Pfad + mtime)
  **und** über `Package_Vorlage\install.ps1`; nur bei Änderung wird voll
  gescannt. Der Fingerabdruck erfasst damit ausdrücklich **nicht** den übrigen
  Paketinhalt: wer Installationsdateien innerhalb derselben Versionsnummer
  austauscht, löst keinen Abgleich aus. Das ist Absicht, weil sonst jede Datei
  im Baum (auch Logs und temporäre Dateien) den Vollscan auslösen würde; der
  vorgesehene Weg ist eine neue `version`.
- **Validierung:** kaputtes JSON, fehlende Pflichtfelder (`ProjectName`,
  `version`) oder ein unbekanntes `InstallationBehaviorType` → Ordner wird
  übersprungen und protokolliert.
- Je Paket (`Name-Version`):
  - **Alt-Versionen** (bei `removeOldVersion: "true"`): werden mit dem exakten
    Muster `^Name-<Version>$` erkannt, aber vom normalen Importlauf nicht
    gelöscht. Name und Ordner beweisen weder Eigentum noch Ersatzbereitschaft.
    Der Lauf meldet deshalb `package_cleanup_failed` und hält den Stamp zurück,
    bis ein gesonderter, sicherer Bereinigungsplan verfügbar ist.
  - **Application anlegen** (falls neu) mit Script-Deployment-Type:
    Install-Kommando
    `powershell.exe -NoProfile -ExecutionPolicy Bypass -NonInteractive -File "install.ps1"`
    (Schalter aus `$script:VsPowerShellArgs`),
    Registry-Detection unter `SOFTWARE\VirtuSphere\Packages\Name-Version`
    (HKCU bei `InstallForUser`, sonst HKLM; dieselbe Richtung wie
    `Package_Vorlage\install.ps1`). **Kein `UninstallCommand`:** ein Paket ist
    definiert als „führe diese Skripte aus“, und dazu gibt es keine allgemeine
    Umkehrung; der frühere Wert `cmd.exe /s` entfernte nichts und meldete
    trotzdem Erfolg.
  - **Collection + Deployment idempotent nachziehen** (bei
    `generateOwnDeviceColletion: "true"`): läuft auch für bestehende Apps und
    heilt frühere Teilfehler (App vorhanden, Collection/Deployment fehlt).
    Lässt sich die Collection nicht anlegen, ist das ein offener Punkt
    (`collection_missing`) und der Deployment-Versuch entfällt, damit die
    Ursache nicht als `package_deploy_failed` erscheint.
    Content-Verteilung an die DP-Gruppe, solange
    `Get-VsContentDistributionState` den Content dort nicht als `succeeded`
    meldet, auch für bestehende Apps; jeder andere Zustand
    (`not_started`/`in_progress`/`failed`/`unknown`) ist ein offener Punkt mit
    eigenem Ursachen-Code und wird im nächsten Durchlauf erneut geprüft.
  - Optionales Available-Deployment an die Collection aus `DeployTo`
    (fehlende Ziel-Collection ist ein Konfigurationsfehler; Warnung ohne
    Dauer-Retry).
- Traten **gezählte** Warnungen auf, wird der Datei-Stamp **nicht** gemerkt; der
  nächste Durchlauf wiederholt die offenen Punkte. Die `DeployTo`-Warnung zählt
  bewusst nicht: sie ist ein Konfigurationsfehler ohne Dauer-Retry, der Stamp
  wird trotz ihr gemerkt und erst eine geänderte `config.json` löst den
  nächsten Versuch aus.

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

`InstallationBehaviorType` kennt genau zwei Werte, `InstallForSystem` und
`InstallForUser`; Groß-/Kleinschreibung spielt keine Rolle. Fehlt das Feld oder
ist es leer, gilt `InstallForSystem`. Ein anderer Wert ist ein Tippfehler und
kein Wunsch: der Ordner wird übersprungen (`package_config_invalid`, die
Meldung nennt Ordner und Wert), das Paket verschwindet nicht, bekommt aber
keine Aktualisierung mehr, bis die Datei stimmt. Der Wert entscheidet auf
beiden Seiten dieselbe Frage, die Detection-Klausel in MECM und den
Registry-Zweig in `Package_Vorlage\install.ps1`; SSoT ist
`$script:VsInstallationBehaviorTypes` in `VirtuSphere-Common.ps1`, die Vorlage
führt das Literal gespiegelt, weil sie allein in den Paketordner kopiert wird.

Die Vorlage prüft `config.json`, **bevor** das erste Teilskript läuft: fehlende
Datei, kaputtes JSON oder fehlendes `ProjectName`/`version` beenden sie mit
Exit-Code 1 und einer Meldung, die Pfad und Grund nennt. Vorher lief sie mit
einer leeren Konfiguration weiter, schrieb nach `…\Packages\-` und startete
trotzdem alle Teilskripte als SYSTEM; MECM fing das erst über die nicht
erfüllte Detection ab, also nach der Ausführung.

Ihr Exit-Code folgt einer Rangfolge: **Fehler (1) → 1641 (Neustart eingeleitet)
→ 3010 (Neustart nötig) → 0**. Ein Fehlschlag gewinnt, sonst meldete ein Paket
„bitte neu starten“ statt „hat nicht funktioniert“; 1641 gewinnt gegen 3010,
weil der Neustart dort schon läuft und MECM sonst einen zweiten plant. Als
Erfolg gelten `0`, `1707`, `3010` und `1641` (`$successExitCodes`, genau einmal
im Quelltext). Ein übersprungenes Teilskript liefert keinen Code und fordert
damit auch keinen Neustart mehr an. Der Deployment-Type steht auf
`RebootBehavior = BasedOnExitCode`, 3010 und 1641 stehen in der
MECM-Standardtabelle als Neustart-Erfolg: **Clients starten dadurch tatsächlich
neu, wo sie es vorher nicht taten.**

Teilskripte startet die Vorlage mit
`-NoProfile -ExecutionPolicy Bypass -NonInteractive` (dieselben Schalter wie
`$script:VsPowerShellArgs`, hier als Literal, weil die Vorlage einzeln in den
Paketordner kopiert wird). Ein Teilskript, das interaktiv liest (`Read-Host`,
eine Bestätigung), bekommt dadurch einen Fehler statt eines Hängers, der die
Bereitstellung blockiert, bis MECM sie abbricht. Bestehende Deployment-Types
behalten ihre alte Kommandozeile: Autoimporter und Clients-Installer setzen
`InstallCommand` nur beim Anlegen. Wer die neue Zeile rückwirkend will, löscht
die App einmal in der Konsole.

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
V23 trennt das read-only `getDeviceInfos` vom verbindlichen, idempotenten
`mecm_client_ack.php`-POST. Beide verwenden denselben Schema-Schalter; mit dem
Default `http` funktionieren sie ohne CA, Zertifikat oder Thumbprint.

`client_staticip` setzt beide Modi, die das Portal kennt: `static` konfiguriert
Adresse, Präfix, optional genau ein Gateway und bei nichtleerem Soll die DNS-
Server; leeres statisches DNS erhält den vorhandenen Stand. `dhcp` stellt eine
zuvor von VirtuSphere statisch verwaltete Karte wieder auf DHCP und automatische
DNS-Ermittlung zurück. Vor dem ersten Write müssen alle Soll-MACs eindeutig auf
aktive Adapter zeigen; doppelte MACs/Namen, fehlende oder Down-Adapter,
ungültige IPv4-Werte und mehrere Gateways blockieren den ganzen Plan. IPv4-
Adresse und Default-Route werden nur entfernt, wenn der vorherige erfolgreiche
Lauf ihre Eigentümerschaft dokumentiert; IPv6 und fremde Werte bleiben erhalten.
Beide Zweige lesen DHCP, Adresse, Route und DNS nach; `Tentative` hat ein
15-Sekunden-Limit, `Duplicate`/`Invalid` scheitern. Ein Modus, den das Skript
nicht kennt, ist ein benannter Fehlschlag. Das Detail des Laufs nennt die Verteilung
(`applied=3 (static=2 dhcp=1)`), damit die Portalkarte mehr zeigt als eine Zahl.
Die Modusnamen gehören `VIRTUSPHERE_INTERFACE_MODES` in
`Docker/WebAPI/lib/defaults.php`; ein Pester-Test hält die beiden
PowerShell-Literale dagegen.

`Set-VMDisksOnline` trennt die aktuelle Offline-Auswahl von offenen eigenen
Operationen. Für eine neue Offline-RAW-Platte wird vor `Set-Disk` ein
versioniertes Registry-Journal mit stabiler Storage-Identität und erwarteter
Größe geschrieben. Ein Retry findet diesen Eintrag unabhängig von Disknummer
und Onlinezustand wieder, gleicht Disk, GPT-Basic-Data-Partition,
Laufwerksbuchstabe, NTFS und das erwartete Label mit der tatsächlichen
Storageansicht ab und setzt nur eindeutige eigene Arbeit fort. Unbekannte
online-RAW-Platten werden nicht adoptiert oder formatiert. Vorhandene GPT-/MBR-
Datenplatten werden ausschließlich online geschaltet. Boot-/System-, Read-only,
Cluster-, größenlose oder mehrdeutige Datenträger sind benannte Fehler; eine VM
ohne Zusatzplatte bleibt dagegen ein ausdrücklich optionaler Erfolgsfall.

`client_hostname` kürzt und bereinigt den Namen nach den NetBIOS-Regeln und
**meldet die Abweichung, bricht aber nicht ab**: die verbindliche Prüfung sitzt
im Portal, das die Regeln bei jeder neuen VM und jeder Hostnamensänderung
erzwingt. Erreichbar ist der Bereinigungszweig nur noch für Altzeilen, die
seitdem niemand angefasst hat, und ihre lockere Regel lässt ihnen das Portal
bewusst. Ein Lauf meldet dadurch genau ein terminales Ereignis; vorher meldete
derselbe Durchgang `failed` und danach `finished`, und was das Portal anzeigte,
hing davon ab, welche Meldung zuletzt ankam. Welcher Name gewünscht war und
welcher gesetzt wurde, steht im Detail dieser einen Meldung und als WARN-Zeile
im Log. Ergibt die Bereinigung einen leeren Namen, ist das ein benannter
Fehlschlag mit `failed` und Exit-Code 1, weil es dann tatsächlich keinen
setzbaren Namen gibt.

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
