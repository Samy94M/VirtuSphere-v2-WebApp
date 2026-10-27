# Clientabläufe

Auf einer frisch installierten Windows-VM laufen zwei Arten VirtuSphere-Code, beide als MECM-Anwendungen: die vier Clientanwendungen, die die VM ans Portal anbinden, umbenennen, ihre Datenträger bereitstellen und ihr Netz setzen, und der Paketwrapper `install.ps1`, der jedes Softwarepaket ausführt und seinen Lauf über den Paketreporter meldet. Die Skripte liegen unter `Powershell-MECM/clients/` und `Powershell-MECM/Package_Vorlage/`. Was das Portal mit den Meldungen tut, zeigen die [Abläufe der Maschinen-API](machine-api-flows.md); wie eine VM dadurch ihre Phase wechselt, steht im [Lebenszyklus einer VM](vm-lifecycle.md). Einstieg: [Abläufe](flows.md).

Die Diagramme beschreiben den Code im Repository. Welcher Stand an einem Standort ausgerollt ist, entscheidet der Core-/Reporter-Cutover in der [MECM-Integration](mecm-integration.md); bis dahin kann dort noch der alte Graph laufen. Wer Reihenfolge, Erkennung oder Meldungen eines Skripts ändert, zieht dieses Dokument im selben Commit nach; `VirtuSphere.ClientFlowsDoc.Tests.ps1` leitet Anwendungen, Abhängigkeiten, Erkennungswerte und Phasen aus dem Code ab.

## Anwendungen und Reihenfolge

Die vier Clientanwendungen stehen als Datentabelle in `Get-VsClientAppSpecs` (`Powershell-MECM/mecm/VirtuSphere-ClientPackaging.ps1`). MECM führt sie über Anwendungsabhängigkeiten in dieser Reihenfolge aus; nur `client_staticip` wird verteilt, die drei anderen sind seine Abhängigkeiten. Eine Anwendung gilt als installiert, wenn ihr Erkennungswert in `HKLM` steht; das Skript schreibt ihn selbst und nur bei Erfolg.

```mermaid
flowchart TD
    client_getInfos["client_getInfos"] --> client_hostname["client_hostname"]
    client_hostname --> client_VMDisksOnline["client_VMDisksOnline"]
    client_VMDisksOnline --> client_staticip["client_staticip"]
    classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;
    class client_getInfos,client_hostname,client_VMDisksOnline,client_staticip step;
```

| Anwendung | Rolle | Hängt ab von | Erkennung (Schlüssel, Wert, Inhalt) |
|---|---|---|---|
| `client_getInfos` | `internal_dependency` | – | `SOFTWARE\VirtuSphere`, `SetupState` = `complete` |
| `client_hostname` | `internal_dependency` | `client_getInfos` | `SOFTWARE\VirtuSphere\HostnameUpdate`, `Status` = `Erfolgreich` oder `Uebersprungen` |
| `client_VMDisksOnline` | `internal_dependency` | `client_hostname` | `SOFTWARE\VirtuSphere\VMDiskManagement`, `VMDisksOnlineStatus` = `Success` |
| `client_staticip` | `deployable_entry` | `client_VMDisksOnline` | `SOFTWARE\VirtuSphere\staticip`, `installed` = `1` |

Alle vier laufen höchstens 15 Minuten und melden MECM 0 als Erfolg, 1641 und 3010 als Erfolg mit Neustart; jeder andere Code ist ein Fehlschlag, den MECM wiederholt. Von den Skripten liefert nur `client_hostname` 1641, die übrigen enden mit 0 oder 1.

## client_getInfos

Bindet die VM ans Portal: liest die Adresse des Portals aus `bootstrap.json`, holt die Daten der VM über ihre MAC und veröffentlicht sie als Registrysatz, den die Folgephasen und der Paketreporter lesen. Erst der bestätigte Client-Ready-ACK macht die Anwendung „installiert“.

```mermaid
flowchart TD
    boot{"bootstrap.json vollständig und gleich einem schon gespeicherten Satz?"} -->|nein| silent["Exit 1 ohne Phasenmeldung, Grund im Clientlog"]
    boot -->|ja| clear["SetupState entfernen"]
    clear --> api{"Portal-Health in bis zu drei Versuchen erreicht?"}
    api -->|nein| silent
    api -->|ja| mac{"getDeviceInfos kennt eine MAC dieser VM?"}
    mac -->|nein| silent
    mac -->|ja| check{"Antwort vollständig: Namen, Revision, Generationen, 1 bis 16 eindeutige Netzwerkkarten?"}
    check -->|nein| failed_schema["getinfo failed, Exit 1"]
    check -->|ja| publish["Registrysatz vorbereiten, nachlesen, als ActiveSnapshot veröffentlichen"]
    publish --> ack{"mecm_client_ack.php bestätigt?"}
    ack -->|nein oder Fehler| failed_ack["SetupState entfernen, getinfo failed, Exit 1; MECM wiederholt"]
    ack -->|ja| done["SetupState = complete, alte Sätze löschen, getinfo finished, Exit 0"]
    classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;
    classDef decision fill:#fff7ed,stroke:#9a3412,color:#0f172a;
    classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;
    class clear,publish,done step;
    class boot,api,mac,check,ack decision;
    class silent,failed_schema,failed_ack blocked;
```

`Initialize-VsClientBootstrap` schreibt die Portaladresse nur, wenn der Registrysatz noch leer ist, und unter einer maschinenweiten Sperre; ein gleicher Satz bleibt, ein abweichender, halber oder ungültiger blockiert ohne Mischung und ohne DNS- oder IP-Ersatz. `Resolve-VsApi` prüft genau eine Adresse und nimmt nur eine Antwort im Health-Schema des Portals an. Jede MAC wird bis zu dreimal abgefragt, mit zehn Sekunden Pause nach einem Fehler. Bevor die VM bekannt ist, gibt es keine MAC, unter der eine Phasenmeldung ankäme; die ersten drei Abbrüche stehen deshalb nur im Clientlog. Der ACK (`Confirm-VsClientReady`) ist die einzige verbindliche Meldung der Kette; er kann beim Portal angekommen sein, obwohl seine Antwort verloren ging, und der Wiederholungslauf trifft dann auf den Duplikatzweig des Portals.

## client_hostname

Benennt Windows nach dem eingefrorenen Rollout-Hostnamen um.

```mermaid
flowchart TD
    domain{"Computer in einer Domäne?"} -->|ja| skipped["Erkennung Uebersprungen, Exit 0, keine Meldung"]
    domain -->|nein| wait{"vm_hostname binnen 60 s im veröffentlichten Satz?"}
    wait -->|nein| stop_wait["Exit 1, keine Erkennung, keine Meldung"]
    wait -->|ja| clean{"Nach Bereinigung auf a-z, 0-9 und Bindestrich, höchstens 15 Zeichen, noch ein Name übrig?"}
    clean -->|nein| failed_empty["Erkennung Fehler, hostname failed, Exit 1"]
    clean -->|ja| same{"Heißt Windows schon so?"}
    same -->|ja| already["Erkennung Erfolgreich, hostname finished, Exit 0"]
    same -->|nein| rename["hostname started, umbenennen, Erkennung Erfolgreich, hostname finished"]
    rename --> reboot["Neustart in 60 s einplanen, Exit 1641"]
    rename -.->|Fehler beim Umbenennen| failed_rename["hostname failed, Exit 1; die alte Erkennung bleibt"]
    classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;
    classDef decision fill:#fff7ed,stroke:#9a3412,color:#0f172a;
    classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;
    class skipped,already,rename,reboot step;
    class domain,wait,clean,same decision;
    class stop_wait,failed_empty,failed_rename blocked;
```

`Set-MecmDetection` schreibt den Erkennungswert. `Uebersprungen` ist ein zweiter Erfolgswert, damit ein Domänencomputer nicht endlos wiederholt wird. Ein Name, den die Bereinigung verändert hat, wird mit Hinweis im Detail verwendet und ist kein Fehler. Die Meldung `finished` geht vor dem Neustart ab; ob sie ankommt, entscheidet das Netz.

## client_VMDisksOnline

Bringt zusätzliche Datenträger online und richtet leere ein. Ohne zusätzliche Datenträger ist nichts zu tun, und auch das ist ein Erfolg.

```mermaid
flowchart TD
    lock{"Sperre des Datenträgerlaufs frei?"} -->|nein| failed_lock["disks failed, Exit 1"]
    lock -->|ja| running["disks started, Status Running"]
    running --> blockers{"Ein Datenträger unsicher: Boot oder System, schreibgeschützt, Cluster, ohne stabile Identität, mehrdeutig, oder ein offenes Journal passt nicht?"}
    blockers -->|ja| failed_block["Status Error, disks failed, Exit 1; nichts formatiert"]
    blockers -->|nein| resume["Offene eigene Vorgänge fortsetzen"]
    resume --> offline["Offline und leer: Vorgang anlegen, online, GPT, Partition, NTFS. Offline mit Daten: nur online schalten"]
    offline --> success["Status Success mit Zählern, disks finished, Exit 0"]
    offline -.->|Fehler| failed_run["Status Error, disks failed, Exit 1; ein abgebrochener Lauf hinterlässt Error statt Running"]
    classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;
    classDef decision fill:#fff7ed,stroke:#9a3412,color:#0f172a;
    classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;
    class running,resume,offline,success step;
    class lock,blockers decision;
    class failed_lock,failed_block,failed_run blocked;
```

Jeder Vorgang an einem leeren Datenträger steht vorher in einem Journal unter `SOFTWARE\VirtuSphere\VMDiskManagement\Operations` und durchläuft die Schritte `intent`, `online`, `initialized`, `partitioned`, `formatted`, `complete`; nach jedem Schritt wird der Ist-Zustand zurückgelesen (`Invoke-OwnedRawDiskOperation`). Ein abgebrochener Lauf setzt beim nächsten Versuch an derselben Stelle fort. Ein Datenträger mit Daten wird nie formatiert, ein schon online stehender leerer ohne eigenes Journal ebenfalls nicht.

## client_staticip

Setzt die Netzwerkkarten nach dem veröffentlichten Satz: statische Adresse oder DHCP, Name der Karte nach dem VLAN.

```mermaid
flowchart TD
    snapshot{"Veröffentlichter Satz mit Netzwerkkarten vorhanden?"} -->|nein| stop_snapshot["installed = 0, Exit 1, keine Meldung"]
    snapshot -->|ja| plan["Plan aus Soll und vorhandenen Karten, ohne Änderung"]
    plan --> started["staticip started, vor jeder Netzänderung"]
    started --> valid{"Plan gültig: jede Soll-MAC genau einmal, Adressen, Maske, Gateway, DNS, Karte aktiv, höchstens ein Gateway?"}
    valid -->|nein| failed_plan["installed = 0, staticip failed, Exit 1; nichts geändert"]
    valid -->|ja| apply["Je Karte anwenden: umbenennen, statisch oder DHCP, nachprüfen; bei Fehler nur diese Karte zurückrollen und weiter"]
    apply --> all{"Alle Karten angewendet, keine gescheitert?"}
    all -->|ja| ok["installed = 1, staticip finished, Exit 0"]
    all -->|nein| failed_partial["installed = 0, staticip failed, Exit 1"]
    classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;
    classDef decision fill:#fff7ed,stroke:#9a3412,color:#0f172a;
    classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;
    class plan,started,apply,ok step;
    class snapshot,valid,all decision;
    class stop_snapshot,failed_plan,failed_partial blocked;
```

`New-VsClientNetworkPlan` prüft alles, bevor eine Karte angefasst wird. Was das Skript selbst gesetzt hat, merkt es je MAC unter `SOFTWARE\VirtuSphere\staticip\Managed` und entfernt beim nächsten Lauf nur diese eigenen Adressen und Routen. Ein leeres DNS-Soll lässt die vorhandenen Server stehen. Die neue Adresse kann die Verbindung zum Portal trennen; `started` geht deshalb vor der Umstellung ab, `finished` ist ein Versuch. Die vNIC wechselt keine ESXi-Portgruppe.

## Phasenmeldungen

Die Clientanwendungen melden ihren Stand mit `Send-VsPhase` an `mecm_report.php?action=reportPhase`: eine Phase, ein Ereignis `started`, `finished` oder `failed`, ein kurzes Detail. Jede Meldung ist ein einzelner Versuch mit fünf Sekunden Frist; scheitert sie, steht das im Clientlog und das Skript läuft weiter. Ohne MAC aus dem veröffentlichten Satz oder einer aktiven Karte (`Get-VsReportMac`) wird nichts gemeldet. Die Meldungen sind Anzeige, kein Beleg; verbindlich ist allein der Client-Ready-ACK.

| Phase | Skript | `started` | `finished` | `failed` |
|---|---|---|---|---|
| `getinfo` | `client_getInfos` | nach dem MAC-Treffer | nach dem ACK | ungültige Antwort, Schreib- oder ACK-Fehler |
| `hostname` | `client_hostname` | nur vor einem Umbenennen | Name schon richtig oder umbenannt, vor dem Neustart | leerer Name nach Bereinigung, Fehler |
| `disks` | `client_VMDisksOnline` | nach der Sperre | Erfolg, auch ohne Arbeit | Sperre belegt, unsicherer Datenträger, Fehler |
| `staticip` | `client_staticip` | vor der Netzänderung | alle Karten angewendet | ungültiger Plan, eine Karte gescheitert |

## Paketwrapper

`install.ps1` ist der Einstieg jedes Softwarepakets. Er führt die Teilskripte unter `powershell\` in Namensreihenfolge aus, merkt sich je Skript den Hash seines letzten Erfolgs und setzt den Erkennungswert des Pakets nur, wenn alle Teilskripte durchliefen. Gesteuert wird er von `config.json`: `ProjectName` und `version` bilden den Registrypfad `Software\VirtuSphere\Packages\<ProjectName>-<version>`, `ErrorAction` (`Stop` oder `Continue`) entscheidet über den Abbruch nach einem Fehler, `InstallationBehaviorType` `InstallForUser` legt Registry und Logs beim Benutzer statt beim System ab. Ein leerer `InstallationBehaviorType` heißt `InstallForSystem`; ein anderer Wert bricht wie im Autoimporter ab, bevor Registry, Log oder ein Teilskript etwas tun.

```mermaid
flowchart TD
    config{"config.json lesbar, ProjectName, version, ErrorAction und InstallationBehaviorType gültig?"} -->|nein| stop_config["Exit 1 vor jedem Teilskript, ohne Bericht"]
    config -->|ja| scripts{"Teilskripte vorhanden?"}
    scripts -->|nein| failed_empty["Bericht failed, Exit 1"]
    scripts -->|ja| hash{"Hash des nächsten Skripts gleich seinem letzten Erfolg?"}
    hash -->|ja, überspringen| more{"Weitere Skripte?"}
    hash -->|nein| run["Erkennungswert Version entfernen, Skript als eigenen Prozess starten, Ergebnis mit Hash speichern"]
    run --> rc{"Exitcode?"}
    rc -->|0, 1707 oder 3010| more
    rc -->|anderer, ErrorAction Continue| more
    rc -->|anderer, ErrorAction Stop| failed_stop["Bericht failed, Exit 1"]
    rc -->|1641| restart["Schleife endet ohne Erkennungswert: Exit 1641, nach einem früheren Fehler Exit 1; die restlichen Skripte folgen im nächsten Lauf"]
    more -->|ja| hash
    more -->|nein| final["Ohne Fehler Version schreiben; Exit 1 nach einem Fehler, sonst 3010 bei Neustartwunsch, sonst 0"]
    classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;
    classDef decision fill:#fff7ed,stroke:#9a3412,color:#0f172a;
    classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;
    class run,restart,final step;
    class config,scripts,hash,more,rc decision;
    class stop_config,failed_empty,failed_stop blocked;
```

Der Wrapper entfernt den Erkennungswert vor jedem Teilskript, das wirklich läuft; scheitert das Entfernen oder das Speichern des Ergebnisses, endet er mit Exit 1. Ein übersprungenes Skript fordert einen früheren Neustart nicht erneut an. Bei mehreren Ergebnissen gilt die Rangfolge Fehler vor 1641 vor 3010 vor 0, damit ein gescheitertes Paket nicht als „bitte neu starten“ erscheint. Die Logs liegen je Lauf unter `%ProgramData%\VirtuSphere\Logs`, bei `InstallForUser` unter `%LOCALAPPDATA%\VirtuSphere\Logs`; eine unsichere Logwurzel schaltet nur die Logs ab. `DeployTo`, `generateOwnDeviceColletion` und `removeOldVersion` liest der Autoimporter, nicht der Wrapper.

## Paketreporter

Der Paketreporter meldet einen Paketlauf als Diagnose an `mecm_report.php?action=reportPackageRun`: ein Ereignis `started`, eines je Teilskript und eines zum Abschluss. Er ändert nie das Ergebnis der Installation; sein aktives Zeitbudget beträgt zehn Sekunden je Lauf, und eine Überschreitung steht im Reporting-Log.

```mermaid
flowchart TD
    start["Wrapper startet den Reporter (Start-VsPackageReporterForRun)"] --> bundle{"Reporterdateien gegen Manifest und Hash geprüft?"}
    bundle -->|nein| off["Bericht für diesen Lauf aus, Grund im Reporting-Log; Installation läuft weiter"]
    bundle -->|ja| host{"Reporterprozess binnen Frist bereit?"}
    host -->|nein| off
    host -->|ja| identity{"Veröffentlichter Clientsatz mit Identität und Portaladresse vorhanden?"}
    identity -->|nein| off
    identity -->|ja| send["Ereignisse started, je Teilskript, completed über eine benannte Pipe an den Reporterprozess"]
    send --> http{"Antwort 200 mit genau der erwarteten Bestätigung?"}
    http -->|ja| confirmed["Bestätigt oder als Duplikat bestätigt"]
    http -->|nein, 429 oder 503 mit Retry-After| back["Bis zur genannten Zeit nichts senden"]
    http -->|nein, sonst| lost["Nicht bestätigt; Zähler payload_omitted_count im Abschluss"]
    classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;
    classDef decision fill:#fff7ed,stroke:#9a3412,color:#0f172a;
    classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;
    class start,send,confirmed step;
    class bundle,host,identity,http decision;
    class off,back,lost blocked;
```

Der Reporter besteht aus einer reinen Bibliothek ohne Ein- und Ausgabe (`VirtuSphere-Package-Reporter.ps1`), die die Ereignisse baut und Antworten prüft (`Resolve-VsPackageReportAcknowledgement`), und einem eigenen Prozess (`VirtuSphere-Package-ReporterHost.ps1`, `Start-VsPackageReportPipeWorker`), der sendet (`Invoke-VsPackageReportHttp`). Jedes Ereignis hat genau einen Versuch mit höchstens zwei Sekunden Frist; es gibt keine Wiederholung und keine Pause. Gesendet wird ohne Weiterleitung, Proxy und Anmeldedaten; bei HTTPS mit hinterlegtem Fingerabdruck gilt nur dieses Zertifikat. Den Lauf ordnet das Portal über die MACs und die Generationen des veröffentlichten Satzes genau einer VM zu; was es prüft und speichert, steht im [Berichtskanal](machine-api-flows.md#berichtskanal).
