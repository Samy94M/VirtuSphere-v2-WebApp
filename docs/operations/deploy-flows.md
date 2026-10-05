# Bereitstellung: Abläufe

Die Bereitstellungsmodi und alle Ansible-Playbooks unter `Ansible/` als Ablaufdiagramm: vom Formular über den Deploy-Worker bis zum einzelnen Playbook-Schritt. Übergaben und Rückkanäle der ganzen Kette stehen in der [Bereitstellungskette](deploy-chain.md), Voraussetzungen und Supportgrenzen in [DEPLOYMENT.md](../DEPLOYMENT.md).

Die Diagramme beschreiben den ausgelieferten Code. Wer Reihenfolge, Verzweigung oder Meldung eines Modus, des Workers oder eines Playbooks ändert, zieht das zugehörige Diagramm im selben Commit nach. Beschlossene, noch nicht gelieferte Änderungen stehen in den Plänen unter `docs/audits/`, nicht hier.

## Modi auf einen Blick

| Modus | Playbooks in dieser Reihenfolge | Staffelung | Liest | MAC-Ergebnis nötig | Braucht Datacenter/Datastore |
|---|---|---|---|---|---|
| `full` (Vollständige Kette) | Create je VM, dann `powercycleVMs`, `exportVMs`, `startVMs`; `autostartVMs` nur bei Mission mit Autostart | ja | Power-Cycle- und Start-Wartezeit | ja | ja |
| `create` (VMs anlegen) | Create je VM | nein | nichts | nein | ja |
| `powercycle` (Ein- und ausschalten mit MAC-Export) | `powercycleVMs`, dann `exportVMs` | ja | Power-Cycle-Wartezeit | ja | ja |
| `export` (MAC-Adressen exportieren) | `exportVMs` | nein | nichts | ja | ja |
| `start` (VMs starten) | `startVMs` | ja | Start-Wartezeit | nein | ja |
| `autostart` (ESXi-Autostart anwenden) | `autostartVMs` | nein | nichts | nein | nein |
| `inventory` (Inventar abrufen) | `inventoryESXi` | nein | nichts | nein | nein |

Die Namen in Klammern sind die deutschen Portalbezeichnungen; `DeployFlowsDocContractTest` leitet Modi, Bezeichnungen und Playbook-Reihenfolge aus dem Code ab. `inventory` ist der Systemmodus des Inventarabrufs: ohne Mission, nie über das Formular. Er entsteht durch den Zeitplan, durch **Alle aktualisieren** und den Einzelabruf im Systemstatus, beim Speichern und Testen eines ESXi-Zugangs und nach einem `create`- oder `full`-Auftrag, der `succeeded` oder `partial` endet. Quelle der Reihenfolge ist `ansible_playbooks_for_mode()`; Staffelung, Wartezeitsperren im Formular, MAC-Erwartung und Create-Zeilen werden daraus abgeleitet. `create_identity_check_tasks.yml` und `powercycle_vm_tasks.yml` sind eingebundene Task-Dateien. `requirements.yml` ist die Versionssperre der Collections und hat keinen Ablauf.

## Einreihen

Das Formular sammelt Mission, ESXi- und Ansible-Zugang, Modus, VM-Auswahl, Wartezeiten, Startzeitpunkt, Staffelung und die ausführliche Ausgabe. Die Sperren darunter prüft die Seite vor dem Absenden und das Repository im Einreihen erneut.

```mermaid
flowchart TD
  F["Formular: Mission, Zugänge, Modus, VM-Auswahl, Wartezeiten, Start sofort oder geplant, Staffelung, ausführliche Ausgabe"]
  F --> B{"Einreihsperren frei?"}
  B -->|nein| BX["Sperre mit Verweis anzeigen, nichts eingereiht"]
  B -->|ja| T{"Zeitplan gültig? (nicht in der Vergangenheit, im Planungshorizont; Staffelung nur bei full, powercycle, start und im erlaubten Bereich)"}
  T -->|nein| TX["Feldfehler am Formular, nichts eingereiht"]
  T -->|ja, mit Zeitplan oder Staffelung| PV["Vorschau: Startzeit je VM, Speicherbedarf je Datastore, Kapazität; erst die Bestätigung reiht ein"]
  T -->|ja, sofort| S
  PV --> S{"Staffelung gesetzt?"}
  S -->|ja| G["Je VM ein eigener Auftrag mit gemeinsamer Gruppen-ID, Startzeit je VM um N Minuten versetzt; der letzte Start muss im Planungshorizont liegen"]
  S -->|nein| J["Ein Auftrag für die Auswahl; leere Auswahl heißt ganze Mission"]
  G --> C{"Modus legt VMs an?"}
  J --> C
  C -->|ja| CR["Je VM eine Create-Ergebniszeile in fester Reihenfolge (Name, ID); die Auswahl ist damit eingefroren"]
  C -->|nein| Q
  CR --> Q["Auftrag steht als queued bereit, mit geplantem Zeitpunkt"]
```

Einreihsperren, jeweils mit Verweis auf die Stelle, die sie behebt:

- Voraussetzungen: Mission vorhanden, ESXi- und Ansible-Zugang vorhanden und gewählt, API-Basis-URL auflösbar.
- Mission: gewählt, keine Vorlage, mindestens eine VM, kein anderer aktiver Auftrag.
- Ort: Datastore gesetzt und Datacenter auflösbar, außer für `autostart`.
- Identität: Das Inventar des gewählten Hosts kennt eine namensgleiche VM, die nicht an die Portal-VM gebunden ist.
- Auswahl: nicht leer, gehört zur Mission, bleibt unter der Obergrenze je Auftrag.
- Netz: allgemeine Zuordnung und PXE-Karte laut Netzvertrag; welche Modi sperren und welche nur warnen, steht in [DEPLOYMENT.md](../DEPLOYMENT.md#deploy-modes-and-the-mac-generation-power-cycle).
- Create-Historie: Eine frühere Create-Einheit derselben VM ist noch ungeklärt (`uncertain`).

## Worker: ein Auftrag

Der Deploy-Worker bearbeitet immer genau einen Auftrag. Ein Abbruch greift an jeder Schrittgrenze: Der laufende Schritt endet auf ESXi vollständig, danach startet kein weiterer.

Vor jeder SSH-/SFTP-Anmeldung prüft der gemeinsame [Hostidentitäts-Guard](trust-flows.md#ssh-und-sftp-zum-ubuntu-host) den Pin gegen den aktuellen Zugang. Fehlende Bestätigung bei neuen Zugängen, Schlüsselabweichung, nicht dauerhaft speicherbare Prüfung oder interne Wiederanmeldung auf demselben Objekt brechen vor Passwort-/Dateiübertragung ab. Die Fehlerwege dieses Workers bleiben maßgeblich für VM- und Auftragszustände; der Host-Preflight läuft vor der Markierung `deploying`: Scheitert der Auftrag davor, bleiben alle VM-Zustände unverändert.

```mermaid
flowchart TD
  L["Worker-Schleife: veraltete Aufträge ernten, nächsten fälligen Auftrag beanspruchen, solange die Annahme nicht pausiert ist"]
  L --> I{"Systemmodus inventory?"}
  I -->|ja| INV["Inventarpfad: Playbook inventoryESXi (Abschnitt Playbooks)"]
  I -->|nein| K["Collection-Sperre (requirements.yml) lokal lesen"]
  K -->|fehlt oder ungültig| KX["Auftrag failed, noch ohne SSH"]
  K --> P{"Netzvertrag nach dem Beanspruchen erneut erfüllt?"}
  P -->|nein| PX["Auftrag failed (configuration_blocked); nichts hochgeladen, nichts auf ESXi geändert"]
  P -->|ja| D["Zugangsdaten entschlüsseln, jede Logzeile gegen beide Geheimnisse schwärzen"]
  D --> H["Ansible-Host-Preflight per SSH: ansible-playbook, python3, pyvmomi, requests, community.vmware (Modul vmware_guest), Laufzeitversionen, Async-Arbeitsbereich; bei Modi mit MAC-Export zusätzlich Portal-Erreichbarkeit und IP-Freigabe des Hosts"]
  H -->|Komponente fehlt| HX["Auftrag failed mit Name der Komponente; VM-Zustände unverändert"]
  H -->|MAC-Modus, IP nicht freigegeben| HAX["Auftrag failed (configuration_blocked), Link auf die Freigabeliste; kein Upload, VM-Zustände unverändert"]
  H -->|Hostidentität nicht freigegeben| HKX["Auftrag failed mit geschlossener Hostidentitätsursache, kein Login und kein Upload"]
  H --> M["VMs des Auftrags auf deploying setzen"]
  M --> A["Artefakte bauen: accounts.yml, serverlist.yml, Vertrauensdatei, Upload-Skript mit Mission und Auftrag"]
  A --> AS{"Schreibt der Auftrag Autostart?"}
  AS -->|ja| AP{"Aktueller Befund des Hosts?"}
  AP -->|freie Lizenz| APX["Auftrag failed, noch vor dem Upload"]
  AP -->|HA-Cluster| APH["Modus autostart: failed; Modus full: Autostart-Schritt entfällt"]
  AP -->|unbekannt oder veraltet| APW["Hinweis im Protokoll, weiter"]
  AP -->|keine Einschränkung| U
  AS -->|nein| U["Arbeitsverzeichnis per SFTP auf den Ansible-Host laden"]
  APH --> U
  APW --> U
  U --> C{"Modus legt VMs an?"}
  C -->|ja| CS["Create je VM (Abschnitt unten)"]
  CS --> CA{"Alle Create-Einheiten erfolgreich?"}
  CA -->|nein| CAX["Kein weiteres Playbook; create: VM-Zustand zurücksetzen, full: VMs failed; Auftrag partial oder failed"]
  CA -->|ja| R{"Weitere Playbooks im Modus?"}
  C -->|nein| R
  R -->|ja, nach Create| RS["serverlist.yml mit den soeben gebundenen UUIDs neu schreiben und hochladen"]
  RS --> ST
  R -->|ja| ST["Nächstes Playbook: Schrittgrenze prüfen (eigener Auftrag, kein Abbruch), dann ansible-playbook ausführen"]
  ST -->|Exitcode ungleich 0| STX["Auftrag failed mit Name des Schritts"]
  ST -->|Abbruch angefordert| CX["Auftrag cancelled; VMs in deploying werden failed"]
  ST -->|nächster Schritt| ST
  ST -->|letzter Schritt fertig| E
  R -->|nein| E{"Modus erwartet MAC-Ergebnis?"}
  E -->|nein| EO["VM-Zustand zurücksetzen, Auftrag succeeded"]
  E -->|ja| EM{"MAC-Ergebnis des Rückrufs?"}
  EM -->|fehlt oder alle VMs gescheitert| EMX["Auftrag failed"]
  EM -->|teilweise| EMP["gescheiterte VMs failed, Auftrag partial"]
  EM -->|vollständig| EMS["Auftrag succeeded"]
  EO --> Z
  EMP --> Z
  EMS --> Z
  CAX --> Z
  Z["Nach create oder full mit succeeded oder partial: Inventarabruf für den Host einreihen; zuletzt die Arbeitsverzeichnisse auf dem Ansible-Host und lokal löschen, außer ein Schritt läuft dort noch"]
```

## Create je VM

`create` und `full` legen VMs einzeln an: je VM ein Prepare, ein Launch als Async-Job und eine Statusabfrage alle `VIRTUSPHERE_CREATE_POLL_INTERVAL_SECONDS` Sekunden. Jede Einheit hat ein dauerhaftes Ergebnis, bevor die nächste beginnt.

```mermaid
flowchart TD
  N["Nächste Einheit: erst eine laufende, sonst die nächste offene nach Position"]
  N -->|keine mehr| DONE["Zusammenfassung ins Protokoll, zurück zum Worker"]
  N --> UN{"Einheit uncertain?"}
  UN -->|ja| HOLD["HOLD: gesamte Create-Phase stoppt"]
  UN -->|nein| AC{"Aktion der Einheit?"}
  AC -->|verify_skip, nach Wiederholung| V["Prepare-Playbook als Prüfung der früher bestätigten VM"]
  V -->|gebundene UUID live nicht mehr vorhanden: Ersatz anlegen| BG
  V -->|dieselbe UUID live| SK["skipped (unverändert)"]
  V -->|andere UUID, oder kein früherer Erfolg auffindbar| VF["failed (identity_conflict)"]
  V -->|Transport abgerissen| VT["failed (transport_lost)"]
  V -->|Hostidentität vor Anmeldung abgelehnt| HF["failed (host_identity_rejected), nichts gestartet"]
  V -->|keine lesbare Antwort| PP["failed (protocol_error)"]
  AC -->|create| B0{"Budget der Create-Phase noch übrig?"}
  B0 -->|nein| BGX["failed (job_timeout), keine VM gestartet"]
  B0 -->|ja| PR["createVMPrepare: Identität lesend prüfen"]
  PR -->|rejected| PF["failed (Identitätsbefund)"]
  PR -->|Transport abgerissen| VT
  PR -->|Hostidentität vor Anmeldung abgelehnt| HF
  PR -->|keine lesbare Antwort oder unerwartetes Ereignis| PP
  PR -->|prepared| BG{"Budget der Create-Phase noch übrig?"}
  BG -->|nein| BGX
  BGX --> HOLD
  PP --> HOLD
  BG -->|ja| LA["createVMLaunch: Identität erneut prüfen, mit Prepare vergleichen, vmware_guest als Async-Job starten"]
  LA -->|rejected| LF["failed"]
  LA -->|Hostidentität vor Anmeldung abgelehnt| HF
  LA -->|keine lesbare Antwort, unerwartetes Ereignis oder ungültige Job-ID| LU
  LA -->|Transport abgerissen| JD{"Job-ID im Async-Verzeichnis gefunden?"}
  JD -->|nein| LU["uncertain"]
  JD -->|Hostidentität der Suchverbindung abgelehnt| HU["uncertain (host_identity_rejected), Ausgang bleibt unbekannt"]
  JD -->|ja| PO
  LA -->|launched| PO["createVMStatus im Takt VIRTUSPHERE_CREATE_POLL_INTERVAL_SECONDS"]
  PO -->|running| PO
  PO -->|Transport abgerissen| PO
  PO -->|Hostidentität vor Anmeldung abgelehnt| HU
  PO -->|Budget der Create-Phase erschöpft| TU["uncertain (job_timeout)"]
  PO -->|Abbruch angefordert| PC["Diese VM bis zum Ende beobachten, danach keine weitere"]
  PC --> PO
  PO -->|failed| MF["failed (module_failed)"]
  PO -->|rejected oder unlesbar| SU["uncertain"]
  PO -->|succeeded| CM{"Identität binden: UUID und MOID in einer Transaktion; ersetzte Instanz übernehmen"}
  CM -->|gebunden| CL["createVMCleanup: Statusdatei genau dieser Job-ID löschen"]
  CM -->|abgelehnt, etwa andere UUID schon gebunden| CMF["failed mit Code der Ablehnung"]
  CMF --> CL
  MF --> CL
  CL --> N
  SK --> N
  VF --> N
  VT --> N
  PF --> N
  LF --> N
  LU --> HOLD
  TU --> HOLD
  SU --> HOLD
  HF --> HOLD
  HU --> HOLD
```

Die Prüfung vor einer übersprungenen VM (`verify_skip`) nutzt dasselbe Prepare-Playbook, schreibt aber in eine eigene Ergebnisdatei. Ein gewöhnlicher Fehlschlag betrifft nur seine VM; die nächste Einheit beginnt trotzdem. `uncertain`, `protocol_error`, `ownership_lost`, `job_timeout` und `host_identity_rejected` stoppen die ganze Create-Phase. Eine Hostablehnung vor Prepare, Launch oder `verify_skip` ergibt `failed`: Die gesperrte Verbindung hat nichts ausgeführt. Bei einer bereits gestarteten Einheit oder der Job-ID-Suche nach einem tatsächlich verlorenen Launch bleibt der Ausgang auf ESXi unbekannt; die Hostablehnung ergibt sofort `uncertain`, ohne weitere Statusversuche. Eine `uncertain`-Einheit wird nie aufgeräumt: Ihre Statusdatei ist der einzige Nachweis. Den Vertrauenscheck vor Anmeldung beschreibt [Verbindungen und Vertrauensanker](trust-flows.md#ssh-und-sftp-zum-ubuntu-host).

## Playbooks

Jedes Playbook läuft auf dem Ansible-Host gegen genau einen ESXi-Host, liest `accounts.yml` und meist `serverlist.yml` und setzt den Vertrauensanker über `SSL_CERT_FILE`.

### createVMPrepare

Liest nur. Beantwortet für genau eine Portal-VM, ob auf dem Host schon eine VM mit ihrem Namen existiert und ob sie der Portal-VM gehört.

```mermaid
flowchart TD
  A["Ziel-VM über portal_vm_id aus serverlist.yml auflösen"] --> B{"Genau ein Treffer?"}
  B -->|nein| BX["Abbruch: Protokollfehler"]
  B -->|ja| C["Identitätsprüfung (create_identity_check_tasks.yml)"]
  C --> D{"Identitätsbefund?"}
  D -->|ja| DR["Ergebnis rejected mit Code, keine Änderung"]
  D -->|nein| DP["Ergebnis prepared: existierte vorher, MOID, Instance-UUID, ersetzte UUID"]
```

### create_identity_check_tasks

Die eine Identitätsmatrix für Prepare, Launch und Status. Ein Befund bricht nie ab, sondern setzt einen geschlossenen Code. Scheitert dagegen das Lesen des Live-Inventars selbst (Anmeldung, Transport, Modul), endet der Lauf ohne Ergebniszeile; der Worker wertet das als unlesbare Antwort.

```mermaid
flowchart TD
  A["Live-Inventar lesen (vmware_vm_info)"] --> B["Namensgleiche VMs und VMs mit der gespeicherten Instance-UUID sammeln"]
  B --> C{"Mehr als eine namensgleiche VM?"}
  C -->|ja| C1["identity_conflict"]
  C -->|nein| D{"Eine namensgleiche VM ohne MOID oder UUID?"}
  D -->|ja| D1["identity_result_invalid"]
  D -->|nein| E{"Gespeicherte UUID lebt unter anderem Namen?"}
  E -->|ja| E1["identity_bound_vm_renamed"]
  E -->|nein| F{"Namensgleiche VM trägt nicht die gespeicherte UUID?"}
  F -->|ja| F1["identity_conflict"]
  F -->|nein| G{"Gespeicherte UUID und Name live beide nicht vorhanden?"}
  G -->|ja| G1["Kein Befund, ersetzte UUID merken: die gebundene VM ist nachweislich weg"]
  G -->|nein| G2["Kein Befund"]
```

Nach einem erfolgreichen Async-Job ruft das Status-Playbook die Matrix ohne Abgleich gegen die gespeicherte UUID auf (`vs_identity_require_stored_match` aus), weil die VM gerade erst entstanden ist. Dann entfallen die Fragen E und F.

### createVMLaunch

Mutiert genau eine VM. Alles, was sich seit Prepare geändert hat, führt vor dem Modulaufruf zur Ablehnung.

```mermaid
flowchart TD
  A["Ziel-VM auflösen, genau ein Treffer"] --> B["Identitätsprüfung wiederholen"]
  B --> C{"Identitätsbefund?"}
  C -->|ja| CX["rejected, keine Änderung"]
  C -->|nein| D{"Live-Identität gleich dem Prepare-Ergebnis?"}
  D -->|nein| DX["rejected: live identity differs, keine Änderung"]
  D -->|ja| E{"VM existierte vorher?"}
  E -->|nein| E1["vmware_guest per Name: neue VM anlegen"]
  E -->|ja| E2["vmware_guest per gebundener Instance-UUID: Hardware angleichen"]
  E1 --> F["Async-Job mit poll 0 starten: Hardwareversion 21, EFI, Secure Boot, CPU, RAM, Disks, Netze"]
  E2 --> F
  F --> G["Ergebnis launched mit Job-ID und Async-Verzeichnis"]
```

### createVMStatus

Fragt eine gespeicherte Job-ID genau einmal ab; der Worker wiederholt die Abfrage.

```mermaid
flowchart TD
  A["Ziel-VM auflösen"] --> B["Statusdatei der Job-ID gebunden lesen (inspect_create_async_state.py)"]
  B -->|Leser scheitert| BX["rejected: protocol_error"]
  B -->|fehlt, unlesbar, ungültig| BM["rejected: async_state_missing oder protocol_error"]
  B --> C["async_status einmal abfragen (Fehler toleriert), Statusdatei erneut prüfen"]
  C --> D{"Datei noch da?"}
  D -->|nein| DM["rejected: async_state_missing"]
  D -->|ja| D2{"Schnappschuss und Abfrage widerspruchsfrei?"}
  D2 -->|nein| DX["rejected: protocol_error"]
  D2 -->|ja| E{"Zustand laut Statusdatei?"}
  E -->|läuft| E1["running"]
  E -->|fehlgeschlagen| E2["failed mit einzeiliger Ursache"]
  E -->|erfolgreich| F["Identität live prüfen, ohne Abgleich mit der gespeicherten UUID"]
  F --> G{"Genau eine VM mit MOID und UUID?"}
  G -->|nein| GX["rejected: identity_result_invalid"]
  G -->|ja| H{"Energiezustand per MOID lesbar?"}
  H -->|ja| H1["Ergebnis succeeded mit MOID, UUID, changed, Energiezustand"]
  H -->|nein| HX["Lauf endet ohne Ergebniszeile; der Worker setzt die Einheit uncertain"]
```

Den Zustand bestimmt die Statusdatei, die `inspect_create_async_state.py` gebunden liest; `async_status` muss ihr nur widerspruchsfrei zustimmen.

### createVMCleanup

```mermaid
flowchart TD
  A{"Job-ID gesetzt und Async-Verzeichnis absolut?"} -->|nein| AX["Abbruch: Protokollfehler"]
  A -->|ja| B["async_status mode cleanup: nur die Statusdatei dieser Job-ID löschen"]
```

Der Worker ruft es erst, wenn das Ergebnis der Einheit gespeichert ist, und nie für eine `uncertain`-Einheit.

### powercycleVMs und powercycle_vm_tasks

Erzeugt MAC-Adressen: ESXi vergibt einer Netzwerkkarte ihre MAC erst beim ersten Einschalten.

```mermaid
flowchart TD
  A["Ziele: nur VMs mit needs_mac true (PXE-Karte im Portal ohne MAC)"] --> B["Je Ziel Zustand per Name lesen (vmware_guest_info)"]
  B --> C{"Name gleich und Instance-UUID gleich der gebundenen?"}
  C -->|nein| CX["Abbruch: Identitätsabweichung"]
  C -->|ja| D{"Jede Antwort trägt einen bekannten Energiezustand?"}
  D -->|nein| DX["Abbruch: Modulantwort passt nicht"]
  D -->|ja| E["Nur anfangs ausgeschaltete VMs schalten; laufende und angehaltene bleiben, wie sie sind"]
  subgraph JE["Je VM nacheinander"]
    F["Einschalten per UUID"] --> G{"Eigene Einschaltung bestätigt (changed, nicht failed)?"}
    G -->|ja| H["PowerCycleWaitSeconds warten"]
    H --> I["Immer: bestätigte eigene Einschaltung hart ausschalten"]
    G -->|nein| I
    I -->|Ausschalten scheitert, VM kann eingeschaltet bleiben| KX
    I -->|ausgeschaltet oder nichts auszuschalten| K{"Einschaltung fehlgeschlagen oder unklar?"}
    K -->|ja| KX["Abbruch vor der nächsten VM: Zustand der UUID im ESXi Host Client prüfen"]
    K -->|nein| KP["VM fertig, nächste VM"]
  end
  E --> F
```

Je VM meldet das Playbook `[n/total] RUN`, `pass` oder `fail Powercycle <VM>`. Ein harter Prozessabbruch (SSH-Abriss, Kill) führt den Ausschaltblock nicht mehr aus und kann eine eingeschaltete VM zurücklassen.

### exportVMs-Informations

Liest die MACs und schickt sie an das Portal. Ein Fehler betrifft nur die einzelne VM.

```mermaid
flowchart TD
  A["Je VM Informationen per Name lesen; ein Fehler bleibt als fehlgeschlagenes Einzelergebnis stehen"] --> B{"Name gleich und Instance-UUID gleich der gebundenen?"}
  B -->|nein| BX["Einzelergebnis als fehlgeschlagen markieren (identity mismatch)"]
  B -->|ja| BO["Einzelergebnis übernehmen"]
  BX --> C
  BO --> C["Alle Ergebnisse als vm_infos.json schreiben (Modus 0600)"]
  C --> D["upload_mac_list.py: Rückruf an db_importMAC.php mit Mission und Auftrag"]
  D --> E{"Exitcode?"}
  E -->|0 vollständig| E0["Schritt erfolgreich"]
  E -->|20 teilweise| E20["Schritt erfolgreich, Teilergebnis im Auftrag"]
  E -->|sonst| EX["Schritt fehlgeschlagen"]
```

### startVMs

```mermaid
flowchart TD
  A["Je VM Zustand per Name lesen"] --> B{"Name gleich und Instance-UUID gleich der gebundenen?"}
  B -->|nein, bei einer VM| BX["Abbruch: keine VM wird gestartet"]
  B -->|ja, bei allen| C["StartWaitSeconds warten, damit MECM die Geräte übernimmt"]
  C --> D["Alle VMs per UUID einschalten; laufende bleiben unverändert"]
```

### autostartVMs

Schreibt die Autostart-Richtlinie des Hosts für die VMs einer Mission.

```mermaid
flowchart TD
  A["Je VM Zustand per Name lesen"] --> B{"Name gleich und Instance-UUID gleich der gebundenen?"}
  B -->|nein| BX["Abbruch vor jeder Änderung am Host"]
  B -->|ja| C{"Autostart der Mission aktiv?"}
  C -->|ja| D["Host-Standardwerte setzen: Autostart ein, Verzögerungen, Stoppaktion, Heartbeat"]
  C -->|nein| E
  D --> E["Je VM per UUID schreiben: powerOn oder none, Reihenfolge -1, Start- und Stoppverzögerung; Stoppaktion und Heartbeat bleiben beim Host-Standard (systemDefault)"]
```

Der Host-Autostart wird nie ausgeschaltet, weil ein Host VMs mehrerer Missionen tragen kann; eine Mission zieht ihre Richtlinie zurück, indem jede ihrer VMs `none` erhält.

### inventoryESXi

Liest nur und läuft ohne Mission als Systemauftrag je ESXi-Zugang.

```mermaid
flowchart TD
  A["Datacenter lesen (Verbindungstest, ohne Fehlertoleranz)"] -->|Fehler| AX["Abruf failed; der Worker ordnet die Ursache ein"]
  A --> B["Datastores, Standard-Portgruppen, verteilte Portgruppen des ersten Datacenters, Hostdaten, VMs, Produkt und Lizenz, Wartungsmodus und HA-Zustand lesen; jede Abfrage darf einzeln scheitern"]
  B --> C["Ergebnis und Zustand jeder Einzelabfrage als eine Base64-Markerzeile ausgeben"]
```

Im Worker folgt darauf: Ausgabe begrenzt einlesen, Markerzeile auswerten, Inventar-Cache und Host-Eigenschaften speichern, VLAN-Katalog abgleichen. Ein Fehlschlag erhöht die Fehlerserie des Zugangs; der vorige Cache bleibt erhalten.
