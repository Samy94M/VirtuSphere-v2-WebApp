# PowerShell-Prüfung 2026-09-28

Prüfung aller PowerShell-Skripte des Produkts (MECM-Server, Installer, Clients,
Paketwrapper) sowie der Ablaufdiagramme in
[mecm-scheduled-tasks.md](../operations/mecm-scheduled-tasks.md). QA-Werkzeuge
unter `scripts/` nur als leichter Durchgang. Nur Befunde, keine Fixes.

Bereits registrierte Befunde (AV-F01 bis AV-F22 im
[Autoimporter-Plan](2026-09-28-autoimporter-version-model-plan.md), M03-F/Q im
[Backlog](2026-09-12-consolidated-session-backlog.md)) werden hier nicht
wiederholt, sondern nur verlinkt, wo ein neuer Befund an sie grenzt.

**Evidenzklassen:** B = im Code belegt; V = Verdacht, braucht eine Probe auf
einem MECM-Server (Prüfbefehl steht dabei). **Priorität:** P1 = Kernfunktion
blockiert oder Datenrisiko; P2 = Zuverlässigkeit/Diagnose; P3 = Doku, Hygiene.

## Ergebnis in Kürze

Die Skripte sind insgesamt in gutem Zustand: Identität, Mitgliedschafts-Journal, Content-Tracking, Netz- und Plattenphase sind fail-closed gebaut, und die Pester-Suite fängt Eingriffe in die Kernentscheidungen (Mutationsstichprobe unten). Die neuen Befunde liegen an den Rändern: an Voraussetzungen der Laufzeitumgebung, an Fehlerpfaden ohne sichtbare Ursache und an zwei Stellen, wo Server und Client dieselbe Frage verschieden beantworten.

Zuerst angehen:

1. **PS-01** (V, P1 falls bestätigt): CMSite-Laufwerk für SYSTEM. Ein Einzeiler auf dem MECM-Server klärt es.
2. **PS-10** (B, P2): `ErrorAction` vom Server akzeptiert, vom Client abgelehnt; Paket scheitert auf jedem Client.
3. **PS-08** (B, P2): doppelte Name-Version erzeugt endlose Contentupdates.
4. **PS-07** (B, P2): Nachbarprodukt sperrt `removeOldVersion` dauerhaft und erzwingt Vollscans.
5. **PS-12** (B/V, P2): Reporter-Host ohne TLS 1.2 bei HTTPS-Portal; auf Windows 11 widerlegt (`SystemDefault`), offen für ältere Server-Vorlagen.
6. **PS-13** (B, P2): Wrapper- und Reporting-Logs sind für SYSTEM-Pakete auf jedem Standard-Client abgeschaltet (geerbte ProgramData-ACL).
7. **PS-15** (V, P2): Disk-Phase hängt an der Windows-SAN-Richtlinie; online-RAW- und schreibgeschützte Platten lassen sie scheitern.
8. **PS-14** (B, P2): ein vorübergehender Membership-Fehler wird zu dauerhafter Handarbeit.
9. **PS-04, PS-11** (B, P2): Journal- und Bootstrap-Fehler ohne sichtbare Ursache.
10. **PS-02, PS-03** (B/V, P2): entfernter Provider nur teilweise unterstützt (bis hin zum fehlenden Site-Code); Providerfehler als „Ordner fehlt“.
11. **D-01 bis D-09**: Doku- und Hilfeabweichungen, D-01 (Pflichtfelder) zusammen mit PS-10.

**Prüftiefe:** Selbst hergeleitet wurden alle vier Serverschleifen samt Common, Logging und Journal, der Serverinstaller, alle vier Clientphasen, der Paketwrapper mit Logger und Reporter-IPC sowie der HTTP-Teil des Reporter-Hosts. Auf Tests und frühere Audits (A01 bis A18, M03) gestützt bleiben: der Reporter-Adapter (reine Validierungsfunktionen), die Wire-Felder gegen die PHP-Endpunkte (`MachineApiWireTest`, `RunReport`-Tests) und die gesperrten MC-Module ClientPackaging, ClientPreflight, ClientBundles, ClientDelivery (nur Musterscans; ihr Audit gehört zum MC-Plan). MECM- und Windows-Laufzeitverhalten ist, wo es entscheidet, als V mit Probe markiert.

## Aufnahme in die Pläne

Umgesetzt wird in den Fachplänen; Zuordnung, neues Paket PSA und Reihenfolge stehen im [gemeinsamen Register](2026-09-12-consolidated-session-backlog.md), Abschnitt „PowerShell-Prüfung 28.09.2026: Aufnahme und Zuordnung“.

- [Autoimporter-Plan](2026-09-28-autoimporter-version-model-plan.md): PS-03 bis PS-10, PS-14, D-01 bis D-03, D-06, D-07, D-09, T-05 als AV-F23 bis AV-F31 und in den Paketen AV-P0 Teil 2, AV-A, AV-B, AV-D, AV-E sowie der Doku-Matrix.
- [Reporterplan](2026-09-13-package-wrapper-logging-plan.md), Abschnitt 30: PS-12, PS-13 und die P3-Punkte des Paketwrappers.
- [MECM-Plan](2026-09-15-mecm-client-delivery-simplification-plan.md): PS-11, D-04, D-05, D-08 in MC05; die Proben zu PS-01, PS-02, PS-12, PS-15 und zur DHCP-Restadresse in MC07.
- Register: PS-01 und PS-02 als Paket PSA, T-01 und T-02 zu R01.

## Stand und Ausgangsmessung

- Quellstand: `main` bei `a364047`, sauberer Baum, `origin/main` gleich.
- Umfang Produkt: 24 Dateien, 13.214 Zeilen unter `Powershell-MECM/`.
- Kodierung: der Produktcode ist reines ASCII; kein Risiko durch fehlende BOM
  unter 5.1. Sechs Testdateien enthalten Nicht-ASCII ohne BOM (siehe T-Befunde).
- `check.ps1 -Gate powershell-syntax,powershell-tests` (Windows PowerShell 5.1):
  Syntax 83 Dateien ok; PSScriptAnalyzer ohne Befund; Pester 906 grün, 1 rot,
  40 NotRun, Coverage 81,8 % (Floor 55 %); Härtungsregister 35 grün, 5
  Handproben offen. Der rote Test ist kein PowerShell-Produkttest:
  `CheckRunner.Tests.ps1` / JavaScript-Visualvertrag, Fall „the committed
  baseline set matches its manifest and this contract“. Das ist das bekannte
  UX02-Rot (12 statt 18 Referenzbilder) aus dem Register.

## Befunde

| ID | Klasse / Prio | Befund | Beleg | Richtung |
|---|---|---|---|---|
| PS-01 | V / P1 falls bestätigt | Kein Skript legt das CMSite-Laufwerk an. `Initialize-VsCmSite` importiert das Modul und wechselt per `Set-Location "<Site>:"`. Das Modul legt Laufwerke aus der MRU des *aktuellen Benutzers* an; SYSTEM hat keine. Fehlt das Laufwerk, scheitern Device-Sync, Packages-Sync und Autoimporter bei jedem Lauf mit `mecm_unavailable`. | `mecm/VirtuSphere-Common.ps1` (`Initialize-VsCmSite`); `git log -S New-PSDrive` leer | Probe: `psexec -s powershell -NoProfile -Command "Import-Module (Join-Path (Split-Path $env:SMS_ADMIN_UI_PATH) 'ConfigurationManager.psd1'); Get-PSDrive -PSProvider CMSite"`. Leer: `New-PSDrive -Name <Site> -PSProvider CMSite -Root <Provider>` vor `Set-Location`. |
| PS-02 | B / P2 | Ein entfernter SMS-Provider ist nur teilweise unterstützt. `MECM_ProviderMachine` wirkt in Site Health und in der Verteilkopie-Abfrage, aber nicht in `Get-VsSiteCode` (nur lokales WMI), nicht beim CMSite-Laufwerk (siehe PS-01) und nicht in der Katalogabfrage des Packages-Sync (lokaler Namespace). Die Betriebsdoku verspricht den entfernten Provider allgemein. Dazu kommt: Der Installer schreibt `MECM_SiteCode` nur, wenn die *lokale* WMI-Erkennung einen Site-Namespace findet, und hat keinen Parameter dafür. Mit entferntem Provider fehlt der Site-Code deshalb ganz: Site Health meldet dauerhaft `unknown`/`query_failed`, und `Get-VsSiteCode` findet ihn weder per WMI noch per (noch nicht vorhandenem) CMSite-Laufwerk noch in der Registry, womit `Initialize-VsCmSite` und alle drei Sync-Aufgaben scheitern, solange niemand den Wert von Hand setzt. | Lokaler Namespace ohne `-ComputerName` in `mecm_Packages-TaskSeq-sync.ps1` (Katalog), `retire-VirtuSphere-LegacyGetInfo.ps1` (`SMS_ApplicationAssignment`) und `mecm/VirtuSphere-ClientPreflight.ps1` (`SMS_TaskSequenceAppReferencesInfo`); `docs/operations/mecm-integration.md` Abschnitt Installation („Liegt der SMS Provider auf einem anderen Rechner…“) gegen Registry-Tabelle („für Site Health“) | Entweder überall denselben Provider verwenden oder die Doku auf „nur Site Health“ zurücknehmen. |
| PS-03 | B (Code) / V (Wirkung) / P2 | Packages-Sync: `Get-CMFolder … -ErrorAction SilentlyContinue` macht aus einem nicht beendenden Providerfehler „Ordner fehlt“. Der Lauf meldet dann `warning`/`source_missing` statt `fail`/`mecm_unavailable`, setzt `$consecutiveErrors` auf 0, und die Site-Drive-Neuinitialisierung nach drei Fehlern greift nie. Ein nach Providerneustart kaputtes Laufwerk bliebe so dauerhaft eine gelbe Karte mit falscher Ursache. Wirft das Cmdlet stattdessen eine beendende Ausnahme, greift der `catch` richtig; welches Verhalten MECM 2509 zeigt, ist cmdletabhängig. Der Device-Sync-Kommentar zu den Vollabfragen und A03 gehen vom Maskieren aus. | `mecm/mecm_Packages-TaskSeq-sync.ps1` (Ordnerabfrage, `$consecutiveErrors = 0` im Warnzweig) | Abfrage mit `-ErrorAction Stop`; „leer“ und „Fehler“ trennen wie beim Device-Sync (A03-Muster). |
| PS-04 | B / P2 | Device-Sync: Ist das Journal beim Start gesperrt, unlesbar oder quarantiniert, wirft das Skript vor dem ersten Run-Report. Das Portal sieht nur, dass Meldungen ausbleiben, ohne Ursache; Neustarts helfen bei Quarantäne nie. Scheitert das Journal mitten im Lauf, meldet der Lauf `fail`/`mecm_unavailable` und baut ab dem dritten Mal die MECM-Verbindung neu auf, obwohl die Ursache lokal ist. Das Wire-Vokabular hat keine passende Kategorie. | `mecm/mecm_new-device-sync.ps1` (Start-`try`/`throw`, Phase `mecm` im Schleifen-`catch`) | Eigene Kategorie (z. B. `journal_blocked`) im Wire-Vertrag, Startfehler als `completed`/`fail` melden, dann gebremst weiterlaufen statt zu enden. Grenzt an AV-F19. |
| PS-05 | B / P3 | Device-Sync: Der Freigabezweig (`Approve-CMDevice`) ist praktisch unerreichbar. Er läuft nur bei leerer ResourceID, und nach „use“ oder bestätigtem Import ist sie nie leer. Das Flowchart zeigt die Freigabe als regulären Schritt. | `mecm/mecm_new-device-sync.ps1` (`if (-not $resourceId)`), Flowchart Devices Sync Knoten `RID` | Entscheiden, ob Freigabe gebraucht wird: dann erreichbar machen, sonst Code und Diagramm bereinigen. |
| PS-06 | B / P3 | `Get-VsFilesManifestStamp` listet den `files`-Baum ohne `-Force`, versteckte Dateien fehlen also im Stamp. MECM verteilt sie trotzdem; eine Änderung nur an einer versteckten Datei löst keinen Abgleich aus. Der Vorlagenbaum wird dagegen mit `-Force` gelesen. | `mecm/VirtuSphere-Common.ps1` (`Get-VsFilesManifestStamp`) | `-Force` auch für `files`, oder versteckte Dateien bewusst ausschließen und dokumentieren. |
| PS-07 | B / P2 | `removeOldVersion` eines Produkts ist dauerhaft gesperrt, sobald ein Produkt mit Bindestrich-Erweiterung existiert (`Firefox` neben `Firefox-ESR`). Der Plan liest `Get-CMApplication -Name "Firefox-*"` und wertet `Firefox-ESR-115` als Kandidat mit ungültiger Version. Weil jeder Lauf damit einen offenen Punkt hat, merkt sich der Autoimporter den Dateistand nie und scannt jede Minute voll. Der Katalog trennt am letzten Bindestrich, `Firefox-ESR-115` gehört also gar nicht zu `Firefox`. Kein Test deckt ein Nachbarprodukt in der Bereinigung ab (nur das Löschmuster). | Reproduziert unter PS 5.1 mit `Get-VsPackageRetirementPlan`: mit Nachbar `blocked` / `candidate_version_unsupported:Firefox-ESR-115`, ohne `ready`. `mecm/mecm_autoimporter.ps1` (`$readRetirementPlan`), `VirtuSphere-Common.ps1` (Schleifen über `Applications`/`Collections`) | Kandidaten nach der Katalogregel filtern: Basisname vor dem letzten Bindestrich muss exakt `ProductName` sein; ein Rest mit Bindestrich ist ein anderes Produkt, kein Blocker. Regressionstest mit Nachbarprodukt. |
| PS-08 | B / P2 | Zwei Paketordner mit identischem `ProjectName`/`version`, aber verschiedenem Inhalt (Ordner für die nächste Version kopiert, Versionsnummer nicht angehoben) erzeugen eine endlose Folge von Contentupdates. Beide Ordner laufen durch dieselbe Application; jeder hält den gespeicherten Manifest-Stand des anderen für veraltet und fordert per `Update-CMDistributionPoint` neuen Content an, etwa alle zwei Läufe. Verteilt wird dabei immer der Ordner, auf den `ContentLocation` seit der Anlage zeigt, die Änderungen des zweiten erreichen nie einen Client. `Get-VsPackageSourceSelections` erkennt das Duplikat, wirkt aber nur auf die Bereinigung. | Ablauf in `mecm/mecm_autoimporter.ps1` (Schleife über `$packageEntries` ohne Duplikatprüfung, `$needsContentRequest`) | Doppelte Name-Version vor jedem MECM-Write als offener Punkt sperren. Entfällt mit AV-B („kein Contentupdate derselben Version“); bis zum Cutover aktiv. AV-E02 behandelt nur verschiedene Versionen. |
| PS-13 | B / P2 | Die Wrapper- und Reporting-Logs (`PackageWrapper\<hash>\wrapper_*.log`, `reporting_*.log`) sind für jedes SYSTEM-Paket auf einem Standard-Client abgeschaltet. Der Wrapper legt `C:\ProgramData\VirtuSphere\Logs` per `New-Item` an; der Ordner erbt von `C:\ProgramData` `BUILTIN\Users: Write (ContainerInherit)`, und `Test-VsPackageLogAccess` lehnt jede Logwurzel mit Schreibrecht für `Users` ab. Ergebnis: Warnung „Logs sind für diesen Lauf deaktiviert“, Installation läuft ohne die neuen Logs weiter. Nur `InstallForUser` (Profilordner) bekommt Logs. Die Tests leiten `$env:ProgramData` auf ein Temp-Verzeichnis mit Benutzer-ACL um und sehen den Standardfall nie; der Logging-Plan nennt echte ACLs ausdrücklich als offene Beweislast. | Auf diesem Windows-11-Host geprüft: `C:\ProgramData` vererbt `VORDEFINIERT\Benutzer Write, ContainerInherit`; die unveränderte Funktion `Test-VsPackageLogAccess` aus `a364047` liefert für drei Unterordner mit rein geerbter ACL `False`. `Package_Vorlage/install.ps1` (Logwurzel, `Test-VsPackageLogAccess`); `tests/powershell/VirtuSphere.PackageRepair.Tests.ps1` (`$env:ProgramData` umgebogen); `docs/audits/2026-09-13-package-wrapper-logging-plan.md` (Beweislast ACLs) | Den verwalteten Unterbaum `VirtuSphere\Logs\PackageWrapper` beim Anlegen mit geschützter ACL (SYSTEM, Administratoren) erzeugen, statt die geerbte Wurzel zu prüfen; einen Test mit einer echten, geerbten ProgramData-ACL ergänzen. |
| PS-14 | B / P2 | Ein vorübergehender Fehler bei `Add-`/`Remove-CMDeviceCollectionDirectMembershipRule` (etwa ein Provider-Timeout) setzt den Journaleintrag auf `uncertain`, und jeder folgende Lauf blockiert die VM dauerhaft mit `membership_operation_uncertain`, bis jemand das Journal von Hand klärt; ein Werkzeug dafür fehlt (AV-F19). Für einen offenen Add ist das strenger als nötig: Ist die Regel im nächsten Lauf nachweislich *abwesend*, hat der Add nicht gegriffen, und der Intent ließe sich sicher verwerfen und wiederholen. Nur *vorhanden* ist mehrdeutig (eigene oder Handregel). Für Removes prüft der Replay schon genau so auf Abwesenheit. | `mecm/mecm_new-device-sync.ps1` (Add-/Remove-`catch` → `uncertain`; Replay: jeder nicht `remote_confirmed` Eintrag blockiert) | Offene Add-Intents bei sicher gelesener Abwesenheit automatisch auflösen (analog zum Remove-Replay); nur Anwesenheit oder unbekannten Live-Stand manuell klären lassen. Grenzt an AV-F19 und ADR-0034 Amendment 3. |
| PS-15 | V / P2 | Die Disk-Phase funktioniert nur, wenn neue Datenplatten **offline und beschreibbar** erscheinen. Das hängt an der Windows-SAN-Richtlinie, die weder Code noch Doku betrachten: Unter `OnlineAll` (Standard auf Windows-Clients) und bei `OfflineShared` an einem nicht geteilten Bus erscheint eine neue VMDK *online-RAW*; das Skript wertet sie als unbekannte Platte und lässt die Phase scheitern, statt sie einzurichten. Unter `OfflineAll` ist sie offline und schreibgeschützt; `Assert-DiskIsSafeTarget` blockiert sie wegen `IsReadOnly`. Die Erstfassung hob den Schreibschutz ebenfalls nie auf; kein Test deckt `IsReadOnly` ab. | `clients/client_VMDisksOnline.ps1` (nur `OperationalStatus -eq 'Offline'` wird neu eingerichtet; online-RAW ohne Journal und `IsReadOnly` sind Blocker); A09 im Härtungsplan („unbekannte online-RAW-Platten nicht pauschal formatieren“) | Probe je OS-Vorlage: `Get-StorageSetting \| Select NewDiskPolicy` und nach Hinzufügen einer VMDK `Get-Disk \| Select Number, OperationalStatus, IsReadOnly, PartitionStyle`. Danach entscheiden: SAN-Richtlinie als dokumentierte Voraussetzung setzen (Task Sequence) oder eine sichere Eigentumsregel für online-RAW-Platten einführen; Schreibschutz einer eigenen Operation bewusst aufheben. |
| PS-09 | B / P3 | Autoimporter: Das Verschieben einer neuen Application in den Ordner läuft unter `SilentlyContinue` ohne Zähler (R3). Kosmetisch, weil der Packages-Sync nur den Collection-Ordner liest; bei Collections zählt derselbe Fehlschlag bereits. | `mecm/mecm_autoimporter.ps1` (`Move-CMObject -FolderPath $appOrgFolder`) | Wie bei Collections: wiederholen und als `collection_folder_failed` bzw. eigene Ursache zählen, oder bewusst als kosmetisch dokumentieren. |
| PS-10 | B / P2 | Server und Paketwrapper prüfen verschiedene Pflichtfelder. Der Wrapper bricht mit Exit 1 ab, wenn `ErrorAction` nicht `Stop`/`Continue` ist; `Read-VsPackageConfig` prüft `ErrorAction` gar nicht. Ein Paket ohne dieses Feld oder mit Tippfehler wird also importiert, verteilt und deployt und scheitert dann auf jedem Client, sichtbar nur im lokalen Wrapper-Log. Der Zwillingstest „prüft dieselben Pflichtfelder wie Read-VsPackageConfig“ vergleicht eine fest eingetragene Liste (`ProjectName`, `version`) und sieht die Abweichung deshalb nicht. | `Package_Vorlage/install.ps1` (`$ErrorAction`-Guard); `mecm/VirtuSphere-Common.ps1` (`Read-VsPackageConfig`); `tests/powershell/VirtuSphere.Autoimporter.Tests.ps1` (Pflichtfeld-Zwilling) | `ErrorAction` serverseitig mit derselben Regel prüfen (fehlend: klarer offener Punkt statt Import). Den Zwillingstest die Pflichtfelder aus beiden ASTs ableiten lassen statt eine Liste zu führen. |
| PS-11 | B / P2 | `client_getInfos.ps1` ruft `Initialize-VsClientBootstrap` ohne `try` auf. Blockiert der Bootstrap (`configuration_drift`, `configuration_invalid`, fehlendes Manifest), endet der Prozess mit einer unbehandelten Ausnahme: im Client-Log steht nur „Starte getinfo“, ans Portal geht keine Phase, MECM sieht Exitcode 1. `configuration_drift` entsteht realistisch, wenn sich die Portaladresse ändert und eine VM noch den alten Satz trägt. | `clients/client_getInfos.ps1` Zeile 24; kein `trap`/Transcript in den Clientskripten | Aufruf in `try` fassen und die Ursache per `Write-VsClientLog -Level ERROR` schreiben, bevor mit 1 beendet wird. |
| PS-12 | B (Code) / V (Wirkung) / P2 | Der Reporter-Host setzt in seinem eigenen Prozess kein TLS 1.2. Die Phasenskripte tun es über `Resolve-VsApi` → `Initialize-VsTls`, der Host sourct `Client-Common`, ruft `Initialize-VsTls` aber nie auf. Windows PowerShell 5.1 startet je nach .NET-Registry (`SchUseStrongCrypto`/`SystemDefaultTlsVersions`) mit SSL3/TLS 1.0. Dieselbe Lücke hatte die Serverseite und hat sie geschlossen (Kommentar an `Initialize-VsTls` in `mecm/VirtuSphere-Common.ps1`). Bei `Scheme=https` endet dann jeder Paketbericht als `transport_error`, während die Phasen funktionieren. Probe am 28.09.2026 auf Windows 11 Pro: `SecurityProtocol` steht ohne Registry-Schalter auf `SystemDefault`, dort also unkritisch; offen bleiben ältere OS-Vorlagen (Windows Server). | `clients/VirtuSphere-Package-ReporterHost.ps1` (`Start-VsPackageReportPipeWorker`, `Invoke-VsPackageReportHttp`); kein Test und keine Referenzzeile zu TLS im Reporter | Probe auf einer ausgerollten VM: `powershell -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol"`. Zeigt sie `Ssl3, Tls`, im Host-Start einmalig TLS 1.2 setzen (nur bei `https`), ohne die read-only-Grenze von `Get-VsPackageReportApiConfiguration` zu verletzen. |

### Kleinere Punkte (P3)

- `mecm/VirtuSphere-Common.ps1`, `Read-VsPackageConfig`: der Kommentar sagt, ein fehlender `InstallationBehaviorType` werde „kanonisch gesetzt und zurückgeschrieben“. Geschrieben wird nichts, nur im Speicher ergänzt. Das Verhalten ist trotzdem konsistent, weil der Wrapper ohne das Feld ebenfalls HKLM wählt. Bereits in der Doku-Matrix des Autoimporter-Plans.
- `clients/client_hostname.ps1`, Kopfkommentar: nennt als Erkennungsregel nur `Status = Erfolgreich`; die Paketdefinition akzeptiert richtig auch `Uebersprungen`.
- `install-VirtuSphere-MECM.ps1`: ein für normale Benutzer beschreibbarer `files`-Ordner ergibt nur einen Hinweis, keinen Blocker, obwohl Paketskripte als SYSTEM auf allen Clients laufen. Die Standard-ACL eines Datenlaufwerks erlaubt `Users` das Anlegen von Dateien und Ordnern.
- `Package_Vorlage/install.ps1`: `Add-Type` kompiliert die Job-Hilfsklasse beim ersten Reporterstart auf dem Installationsthread. Die Zeit zählt zum Reporterbudget, ist aber nicht unterbrechbar; auf einer frischen VM mit Virenscan kann sie die Installation um Sekunden verzögern. Scheitert sie, wird nur das Reporting abgeschaltet.
- `Package_Vorlage/install.ps1`: Teilskripte starten über `& PowerShell.exe` aus dem `PATH`, der Reporter dagegen über den festen System32-Pfad. Einheitlich den festen Pfad nutzen.
- `Package_Vorlage/install.ps1`: Die Einzel-Logs der Teilskripte (`*> $logPath`, je Lauf und Skript eine Datei) liegen unredigiert in `C:\ProgramData\VirtuSphere\Logs`, sind über die geerbte ACL für alle lokalen Benutzer lesbar und werden nie bereinigt (die Wrapper-Retention betrifft nur `PackageWrapper\<hash>`). Gibt ein Paketskript Zugangsdaten aus, liegen sie dort offen. Der Logging-Plan hat diese Dateien bewusst ausgeklammert; die Lesbarkeit sollte zumindest in der Paketautoren-Doku stehen.
- `mecm/VirtuSphere-Common.ps1`, `Sync-VsPackageTemplateSet`: `install.ps1` wird vor dem Deskriptor umgeschaltet und bei einem Fehler des Deskriptorwechsels nicht zurückgerollt. Folge ist nur ein abgeschaltetes Reporting in diesem Zustand.

- `mecm/mecm_autoimporter.ps1`: Hat die DP-Gruppe keine Mitglieder, gelingt `Start-CMContentDistribution`, aber es erscheint nie ein Ziel. Der Intent bleibt dann dauerhaft `package_content_in_progress` (statt einer Ursache wie „DP-Gruppe leer“), und der Autoimporter scannt jede Minute voll. Der Installer weist nur als Hinweis auf eine leere Gruppe hin.
- `clients/client_staticip.ps1` (V): Beim Wechsel DHCP → statisch wird nur eine früher *selbst* gesetzte Adresse entfernt. Ob Windows eine vorhandene DHCP- oder APIPA-Adresse beim `Set-NetIPInterface -Dhcp Disabled` selbst freigibt, ist ungeprüft; bleibt sie stehen, ist der Adapter mehrfach adressiert. Probe: auf einer Test-VM vorher/nachher `Get-NetIPAddress -InterfaceIndex <n> -AddressFamily IPv4`.

## Doku und Hilfe

Abgeglichen wurden die prüfbaren Aussagen (Namen, Reihenfolgen, Takte, Pflichtfelder, Befehlszeilen, Registry-Werte, Logpfade, Exitcodes, Verhaltenszusagen) in der Portal-Hilfe (`lang/de/help_*.php`, EN-Gegenstücke stichprobenartig), `Powershell-MECM/README.md`, `Powershell-MECM/clients/README.md`, der Fehlertabelle und den Installations-/Provider-/TLS-Abschnitten von `docs/operations/mecm-integration.md` sowie `mecm-scheduled-tasks.md`. Nicht Satz für Satz gelesen wurden die übrigen Abschnitte von `mecm-integration.md` und `go-live.md`.

| ID | Prio | Abweichung | Stellen |
|---|---|---|---|
| D-01 | P2 | Pflichtfelder der `config.json` werden überall nur als `ProjectName`/`version` genannt; der Paketwrapper verlangt zusätzlich `ErrorAction` (PS-10). | `help_packages.packages_p1`, `Powershell-MECM/README.md` („Pflichtfelder“) |
| D-02 | P3 | „genehmigt sie“ bzw. „notfalls per `Approve-CMDevice`“: der Zweig ist unerreichbar (PS-05). | `help_stack.stack_a6_p2`, `README.md` Devices-Sync-Schritt 4, `mecm-integration.md` Fehlertabelle („Auto-Approve scheitert“), Flowchart `RID` |
| D-03 | P3 | Device-Sync ordne VMs „der Collection ihrer Mission“ zu; tatsächlich OS-, Paket- und Missions-Collection samt Entfernen eigener Regeln. Autoimporter/Pakete: „samt (Device-)Collection“ gilt nur mit `generateOwnDeviceColletion`. | `help_system_status.system_status_source_1`, `_source_3`, `help_packages.packages_p1`, Flowchart `CO` |
| D-04 | P3 | Hilfe rät, `install-VirtuSphere-MECM.ps1 -Upgrade` bzw. den Installer erneut auszuführen; der Installer wirft bis zum MC-Cutover bedingungslos. Die Betriebsdoku sagt das im Kopf, die Hilfe nicht. | `help_system_status.system_status_status_package_p5`, `_fix_1` |
| D-05 | P3 | Hilfe (DE und EN) nennt die Client-Phasen „immer in dieser Reihenfolge“ getinfo → hostname → staticip → disks; die SSoT `Get-VsClientAppSpecs` verkettet getInfos → hostname → VMDisksOnline → staticip. Die Portalanzeige (`VIRTUSPHERE_CLIENT_PHASES`) ist nur eine Anzeigereihenfolge. | `help_system_status.clientphases_p1`, `help_stack.stack_a7_p3` (DE/EN) |
| D-06 | P3 | Hilfe zu „hängt bei 3/5“: der Gast-IP-Modus bestimme die PXE-Identität nicht. Der heutige Device-Sync wählt die MAC aber über das erste Interface mit `mode = DHCP` (AV-F20); die Hilfe beschreibt das geplante Zielbild. | `help_missions.status_stuck_2` |
| D-07 | P3 | Aufräumregel für zurückgezogene Pakete: „nie einer VM zugeordnet“ bzw. „ohne VM-Verknüpfung“. Tatsächlich: aktuell keiner VM zugeordnet **und** nie umgehängt (`repo_purge_retired_packages`). Eine früher zugeordnete, von Hand gelöste Zeile wird also gelöscht. | `help_packages.packages_p2b`, `help_system_status.logs_p3`, `mecm-scheduled-tasks.md` (Packages-Sync-Einleitung) |
| D-08 | P3 | Befehlszeile der Clientphasen ohne `-NoProfile -NonInteractive`; im Text noch die alten Namen `client_getinfo` / `Set-VMDisksOnline`. | `Powershell-MECM/clients/README.md` („Programm-Befehlszeile“, „Wichtige Verhaltensdetails“) |
| D-09 | P3 | Fehlertabelle „Alle drei Skripte: Backoff 30 s, ab 3 Fehlern 60 s + Neuinitialisierung“ gilt nicht für den Autoimporter (immer 60 s, sofort neu). „Collection angelegt, Ordner scheitert: funktional ok, WARN“ verschweigt, dass der Lauf dann `warning`/`partial_failure` meldet. | `mecm-integration.md` Fehlertabelle |

Stimmig befunden: Logformat, Logpfade (Server `%ProgramFiles%`, Clients `C:\Program Files\VirtuSphere\Logs`), Takte und Spannen, Erkennungsregeln und Exitcodes der Clientphasen, Token-Ablage und -Geltungsbereich, Ordnerstruktur in MECM, Ursachencodes der Identität, Content-/Bereinigungsregeln, Rückzugslogik je Katalogtyp, Journal- und Quarantäneverhalten.

## Testqualität

| ID | Klasse / Prio | Befund | Beleg | Richtung |
|---|---|---|---|---|
| T-01 | B / P3 | Sechs Testdateien enthalten Nicht-ASCII-Testdaten ohne BOM (`'München 東京'`, `'Grüße 世界'`, `'ü' * 5000`). Windows PowerShell 5.1, der Host dieses Gates und der Produktionslaufzeit, liest sie als ANSI: die Tests prüfen dort Mojibake aus 1-/2-Byte-Zeichen statt echter 3-Byte-Sequenzen und bleiben grün, auch wenn die UTF-8-Kanten nicht mehr stimmen. Unter pwsh (Linux-CI) prüfen sie das Gemeinte. | `ErrorPaths`, `JsonTransport`, `Logging`, `MembershipJournal`, `RunReport` Tests; `scripts/test-guards.ps1` (nur Kommentar) | BOM ergänzen oder Testdaten als `[char]`-Codes bilden. |
| T-02 | B / P3 | PSScriptAnalyzer prüft nur `Powershell-MECM/`. Tests und `scripts/` (Check-Runner, läuft unter 5.1 und pwsh 7) erhalten weder die Kompatibilitätsregeln noch `PSUseBOMForUnicodeEncodedFile`; genau diese Regel hätte T-01 gemeldet. | `scripts/run-pester.ps1` (`Invoke-ScriptAnalyzer -Path $scriptRoot`) | Analyzer-Umfang auf `scripts/` und `tests/powershell/` ausdehnen, notfalls mit eigener Ausnahmeliste. |
| T-03 | B / P2 | Zwei der Produktbefunde oben zeigen Testlücken derselben Art: der Pflichtfeld-Zwilling führt eine feste Liste (PS-10), und die Bereinigung hat keinen Fall mit Nachbarprodukt (PS-07). | siehe PS-07, PS-10 | Mit den jeweiligen Fixes. |
| T-05 | B / P3 | Der Sende-Schutz des Packages-Sync für einen leeren Katalog ist ungetestet (Mutant M8 überlebt alle vier Testdateien, die das Skript ansprechen). Die Serverseite lehnt einen leeren Payload zwar mit 400 ab, ohne Schutz hieße der Lauf dann aber `fail`/`portal_unreachable` statt `warning`/`source_missing`. | Mutationsstichprobe unten | Verhaltenstest für den leeren Katalog. |
| T-04 | Info | Die 40 `NotRun` sind genau die 40 Fälle des Härtungsregisters (Tag `Haertung`), die `run-pester.ps1` aus dem Hauptlauf ausschließt und separat fährt (35 grün, 5 Handproben). Keine versteckten Tests. | `scripts/run-pester.ps1` (`ExcludeTag = 'Haertung'`) | – |

### Mutationsstichprobe

Acht gezielte Eingriffe in eine `git archive`-Kopie von `a364047` (Arbeitsbaum unberührt), je Mutant nur die Testdateien, die die betroffene Datei ansprechen, Windows PowerShell 5.1 mit Pester 5.7.1. Skript: `run-mutants.ps1` im Session-Scratchpad.

| Mutant | Eingriff | Ergebnis |
|---|---|---|
| M1 | Mitgliedschaftsplan entfernt auch fremde Regeln | getötet (gemeinsame PHP/PS-Planvektoren) |
| M2 | gebundene ResourceID ohne MAC-Prüfung | getötet (Identitätsentscheidung) |
| M3 | DP-Fehler im Verteilstatus ignoriert | getötet (B7-Verteilstatus) |
| M4 | Exitcode 1 eines Teilskripts gilt als Erfolg | getötet (Verhaltenstests `PackageRepair`) |
| M5 | `client_staticip` meldet Erfolg ohne vollständige Adapterabdeckung | getötet |
| M6 | Bindestrich in `version` erlaubt | getötet |
| M7 | ResourceID trotz unvollständiger Zuweisung melden | getötet |
| M8 | leerer Katalog wird gesendet | **überlebt** (T-05) |

## QA-Werkzeuge (leichter Durchgang)

Keine Befunde über P3 hinaus. Die drei Kopien von `Invoke-Tool` (`lib/check/runtime.ps1`, `check-compose-hardening.ps1`, `test-guards.ps1`) setzen `$ErrorActionPreference` korrekt lokal auf `Continue`, damit stderr unter 5.1 nicht als `NativeCommandError` terminiert; sie sind aber dreifach dupliziert. Kein `Invoke-Expression`, rekursives Löschen nur auf eigene Arbeitsverzeichnisse.

## Kontext: gesperrte Installer

`install-VirtuSphere-MECM.ps1` (Zeile 121) und `install-VirtuSphere-Clients.ps1` (Zeile 110) werfen bis zum MC-Cutover bedingungslos. Ihr Code wurde trotzdem geprüft, weil er beim Cutover läuft. Der Serverinstaller erhält `membership-journal.json` und Quarantänedateien beim Upgrade (nur benannte `.ps1` werden getauscht), legt vor dem Dateitausch alle Aufgaben still und rollt Registry, Dateien und Aufgaben zurück. `VirtuSphere-ClientBundles.ps1` und `VirtuSphere-ClientDelivery.ps1` sind MC03-Mechanik ohne Aufrufer außerhalb der Tests; sie wurden nur mit den Musterscans geprüft, ihr Audit gehört zum MC-Plan.

## Flowchart-Abgleich

| Diagramm | Ergebnis |
|---|---|
| Gemeinsamer Rahmen | Stimmt mit dem Code (Backoff, Neuaufbau ab drittem Fehler, 5-s-Report). |
| Devices Sync | Reihenfolge stimmt. Abweichungen: Knoten `RID` (PS-05); Knoten `LX` verschweigt, dass bei Quarantäne kein Neustart hilft und das Portal keine Ursache erhält (PS-04); vor dem Lesen der Mitgliedschaften prüft der Code zusätzlich Soll-Eindeutigkeit (`membership_identity_ambiguous`) und Provenienz (`membership_provenance_invalid`), beides fehlt im Diagramm. |
| Packages Sync | Knoten `T` nennt feste 30 %. Tatsächlich: Einstellung 5 bis 90 %, Standard 30 %, und die Bremse greift erst ab 5 aktiven Einträgen (`packages_retire_guard`). Knoten `F` deckt auch den Providerfehler ab (PS-03). Die Portal-Knoten `RT`/`RL` stimmen mit `mecm_packages.php` (Umhängen nur auf eine in diesem Payload neu angelegte, höhere Version). Die Einleitung sagt, der Wartungsdienst lösche zurückgezogene Pakete nach 30 Tagen, „wenn keine VM sie mehr nutzt“; zusätzlich bleibt jede Zeile, deren Zuweisungen je umgehängt wurden, dauerhaft (`assignments_relinked_at`, `repo_purge_retired_packages`). |
| Package Import | Stimmt weitgehend. Abweichung: Knoten `CO` legt Collection und „Erforderlich“-Deployment nur an, wenn `generateOwnDeviceColletion` gesetzt ist; ohne das Feld entsteht keine Collection, und das Paket fehlt deshalb im Portalkatalog (der Packages-Sync liest nur Collections). Das Diagramm nennt die Bedingung nicht. Knoten `A` lässt den Zweig „Applicationname mehrdeutig: übersprungen“ aus. |
| Site Health | Stimmt mit dem Code. Der entfernte Provider läuft über WinRM/WSMan; das ist in der MECM-README dokumentiert. |

## Fortschritt

- [x] Ausgangsmessung, maschinelle Scans (Kodierung, Muster)
- [x] `mecm/VirtuSphere-Common.ps1`, `VirtuSphere-Logging.ps1`, `VirtuSphere-MembershipJournal.ps1`
- [x] `mecm_new-device-sync.ps1`, `mecm_Packages-TaskSeq-sync.ps1` samt Flowcharts
- [x] `mecm_autoimporter.ps1`, `mecm_site-health.ps1` samt Flowcharts
- [x] `install-VirtuSphere-MECM.ps1` (Journal bleibt beim Upgrade erhalten)
- [x] LegacyRetirement und `retire-…` (gezielt); ClientPackaging, ClientPreflight, `install-VirtuSphere-Clients.ps1` nur Musterscan (gesperrt, MC-Plan)
- [x] Clients (`clients/*`)
- [x] Paketwrapper `Package_Vorlage/install.ps1` (Kern und Reporter-IPC), ReporterHost (HTTP/TLS); Reporter-Adapter nicht vertieft (reine Funktionen, eigene Tests)
- [x] Testqualität (BOM, NotRun, Mutationsstichprobe)
- [x] QA-Werkzeuge leichter Durchgang
- [x] Logging: Server-/Client-Modul, Wrapper-Logger samt ACL-Prüfung (PS-13), Secret-Scan über alle Log- und Reportaufrufe
- [x] Doku und Hilfe: prüfbare Aussagen (D-01 bis D-09); `go-live.md` und die übrigen Abschnitte von `mecm-integration.md` nicht Satz für Satz
- [x] First-Principles-Nachprüfung der zuvor nur test- oder auditgestützten Stellen: Journal-Blockade (PS-14), SAN-Richtlinie (PS-15), leere DP-Gruppe, DHCP-Restadresse
