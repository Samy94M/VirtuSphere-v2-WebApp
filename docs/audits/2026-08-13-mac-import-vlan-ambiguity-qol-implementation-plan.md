# Konsolidierter Umsetzungsplan: selbstheilende Deploy-Ausführung, eindeutige VM-Netzwerke und sicherer MAC-Retry

Stand: 13.08.2026

Status: entscheidungsreifer Gesamtplan; seit 2026-08-20 in Offline-Implementierung (8R-O) und Standortabnahme (8R-S) getrennt

Zusammengeführt aus: VLAN-/MAC-Review und Codex-Session `019ffafd-2893-7ed1-94f1-d069b6e88174`

Änderungsumfang dieses Arbeitslaufs: ausschließlich Plan-, SSoT- und Querverweisdokumentation; kein Produktivcode, kein Schema und kein Laufzeitverhalten

## 0. Verbindlicher Einordnungs- und Ausführungsvertrag

Dieser Plan ist die zusammengeführte fachliche Spezifikation für zwei voneinander abhängige Querschnitte:

1. dauerhafte Kontrolle entfernter Ansible-Ausführungen, Worker-Fencing, Recovery, Supervisor und verständlicher Deploy-Dienstzustand;
2. eindeutige VM-Netzwerke, handlungsfähiger MAC-Import und ein Retry, der weder einen ungeklärten Remote-Lauf noch einen unveränderten VLAN-Konflikt wiederholt.

Der globale Ausführungsplan `docs/audits/2026-08-11-deploy-reliability-master-plan.md` bleibt die einzige SSoT für die Reihenfolge. Diese Datei ist die einzige zusammengeführte Fach-SSoT für die neuen Etappen **8R, 13R, 14A, 14B und 14C**. Der frühere Einzelplan `docs/audits/2026-08-13-deploy-self-healing-implementation-plan.md` bleibt ausschließlich Reviewspur und darf nicht zusätzlich abgearbeitet werden. Der Create-Plan bleibt nur für seine ausdrücklich benannten per-VM-Verträge Owner.

Bei Widersprüchen gilt ohne Interpretationsspielraum:

1. Der Masterplan bestimmt Reihenfolge, Etappenabschluss, Commit/Push und globale Qualitätsgates.
2. Diese Datei bestimmt Remote-Handle, Lease/Fencing, Reaper/Recovery, Deploy-Dienstzustand, Netzwerkvertrag, MAC-Retry und die Wechselwirkung dieser Bereiche.
3. `docs/audits/2026-08-13-create-flow-reliability-implementation-plan.md` bestimmt per-VM-Create-Zustände, Ansible-Async-JID, Create-Identität und Create-Zeitbudgets, soweit diese Datei nicht ausdrücklich den Remote-/Recovery-Vertrag korrigiert.
4. Der frühere Self-Healing-Einzelplan und ältere, abweichende Passagen bleiben historische Befunde, nicht ausführbare Anforderungen.

Die verbindliche Reihenfolge lautet:

1. Masterplan-Etappen 6 und 7 werden abgeschlossen. Sie liefern Transportfehler, Ansible-Abbildung und die aus `ansible_playbooks_for_mode()` abgeleitete Step-SSoT.
2. **Etappe 8R** ersetzt für neu aktivierte Modi den langlebigen direkten SSH-Step durch Prepare/Launch/Poll/Reattach. Sie führt Remote-Handle, globale Worker-Epoch, Job-Fencing, Runner/Launcher, Hostpreflight und zunächst den read-only Inventarpiloten ein. Danach werden Export, Start, Autostart und Powercycle einzeln freigegeben. Der Reaper darf sein Recoveryverhalten immer erst für einen Modus aktivieren, nachdem dessen Recoverypolicy grün ist.
3. Die übrige Masterplan-Etappe 8 nutzt für freigegebene Modi den 8R-Vertrag; ihre Cancel-, Fehlerherkunfts-, Ausgabe-, Secret- und Cleanupanforderungen bleiben erhalten. Ein normales `finally` darf jedoch keinen aktiven oder ungeklärten Remote-Beweis löschen.
4. Masterplan-Etappen 9, 10 und 10A bis 10D folgen. 10A liefert vollständigen Joblog-Tail/Drain/Rohdownload, 10B Terminalergebnis und 10C strukturierte Audits. Remote-Events werden erst mit 10C in `deploy_logs` strukturiert; davor bleiben sie im Joblog und im begrenzten Supervisor-Snapshot.
5. Masterplan-Etappen 11 und 12 folgen. Etappe 12 liefert `deploy_queue_blockers()` samt identischem Repository-Recheck.
6. **Etappe 13R** wird gemeinsam mit Masterplan-Etappe 13 umgesetzt: ein gemeinsamer Deploy-Dienstsnapshot, Recovery-/Pause-/Queueanzeige, vollständiger Logpoller und Portalaktionen, aber noch kein lokaler Supervisor-Neustart.
7. Masterplan-Etappe 14 liefert die gemeinsame Formular-/Fehler-API.
8. **Etappe 14A** setzt die Arbeitspakete A bis H aus Abschnitt 10 für Netzwerk und MAC um. Create und Full erhalten dabei den Netzwerkblocker, laufen aber produktiv erst nach 14B über die neue Create-Ausführung.
9. **Etappe 14B** setzt den Create-Plan um, bindet jede per-VM-JID in das Remote-Handle ein und aktiviert Create sowie Full erst nach den kombinierten Fault-Gates. Die dortigen alten Reaper-/Retry-/Cleanup-Passagen werden durch Abschnitt 16 bis 24 dieser Datei ersetzt.
10. **Etappe 14C** aktiviert den lokalen PID-1-Supervisor erst, wenn alle produktiv zugelassenen Modi durable und reattach-fähig sind. Vorher wäre ein automatischer Kindneustart ein Doppelstart-Risiko.
11. Erst danach folgen Masterplan-Etappen 15 bis 17.

Jede Teilfreigabe wird pro Ansible-Zugang und Modus persistiert. Ein globaler Schalter, der alle Modi gleichzeitig aktiviert, ist verboten. Fehlt eine Voraussetzung, bleibt der betreffende Modus sichtbar blockiert; es gibt keinen stillen Legacy-Fallback.

Wenn eine der Voraussetzungen noch nicht umgesetzt ist, wird nicht improvisiert. Die betreffende Voraussetzung wird zuerst gemäß ihrem Owner-Plan abgeschlossen.

### 0.1 Verbindliche Trennung 8R-O/8R-S bei nicht erreichbarem Air-Gap-Ziel

Das Ausführungsteam besitzt dauerhaft keinen direkten Zugang zum produktionsgleichen Air-Gap-/Ansible-/ESXi-Ziel. Diese Infrastrukturgrenze ändert nicht die Sicherheitsanforderung, sondern trennt Implementierung von Aktivierung:

1. **8R-O (Offline-Implementierung)** darf ADR, geschlossene Protokolle, Runner/Launcher, Offline-Bundle, additive Migration/Fresh-Schema, Runtime-Identität, Lease/Epoch, Job-Fencing, Claim-Pause, Repositories, deaktivierte Consumer sowie lokale positive/negative/Zero-Match- und Faulttests implementieren. Alle neuen Moduszeilen starten `disabled`; ein lokaler Testfingerprint ist niemals ein Standortfingerprint.
2. **8R-S (Standortabnahme)** wird ausschließlich von einem autorisierten Betreiber am Ziel mit dem versionierten, checksum-geprüften Bundle ausgeführt. Es erhebt systemd-/Ansible-/User-Bus-/Linger-/cgroup-/Freiplatz-/Ressourcenwerte, reale Faultmatrizen, mindestens die Messreihen aus 24.2, Beobachtungsfenster und Rückbau. Das Bundle exportiert nur redigierte Evidenz und niemals Credentials.
3. Der Import einer 8R-S-Evidenz ist fail-closed: Repositoryrevision, Bundlehash, Protokollversion, Credential-/Hostidentität, Modus, Messmatrix und Ablaufzeit müssen passen. Fehlend, unbekannt, abgelaufen oder widersprüchlich bedeutet `disabled`, niemals Legacy oder Pilot.
4. `pilot_remote` und `remote_enabled` sind ohne grünen 8R-S-Nachweis technisch unerreichbar. 8R-O darf deshalb als Codepaket grün sein, während 8R-S und sämtliche Modusfreigaben offen bleiben. Dokumentation nennt diese zwei Ergebnisse getrennt.
5. Gibt es dauerhaft keinen autorisierten Standortlauf, bleibt die Remote-Architektur installiert aber unaktiviert. Der bestehende explizite `legacy_v1`-Vertrag darf bis zu einer eigenen Betriebsentscheidung weiterlaufen; kein Fehlerpfad wählt ihn als Fallback. Create/Full bleiben zusätzlich bis 14B gesperrt.

Die früher als P1 bis P8 bezeichneten Empfehlungen sind hiermit übernommen: Queue bleibt speicherbar, kein Blindretry, systemd-User-Services als Remote-Owner, kein unsicherer Fallback, bestehende Jobstatuswerte bleiben kompatibel, Abbruch wirkt an sicherer Grenze, Portalpause ersetzt Containerstop und automatische Heilung behauptet nur beweisbare Fälle. Es gibt dafür keine offene Produktfrage mehr. Messwerte für Ressourcen und Retention sind keine freie Entscheidung: Abschnitt 24 definiert, wie sie reproduzierbar erhoben und freigegeben werden.

## 1. Ergebnis des geprüften Ist-Zustands

### 1.1 Nachgewiesene Ursache des konkreten Produktionsfehlers

Bei den drei betroffenen VMs waren jeweils zwei Portal-Netzwerkkarten demselben VLAN `vm_srv_depl` zugeordnet:

| VM | Portal-Interfaces | gemeinsames VLAN | Ergebnis |
|---|---:|---|---|
| `VM-04342` | 47 und 48 | `vm_srv_depl` | `ambiguous_vlan` |
| `VM-04451` | 51 und 52 | `vm_srv_depl` | `ambiguous_vlan` |
| `VM-04692` | 61 und 62 | `vm_srv_depl` | `ambiguous_vlan` |

Der MAC-Import ordnet heute über `(vm_id, vlan)` zu. Zwei Portal-Interfaces mit demselben VLAN erzeugen deshalb zwei Treffer. Das Portal verweigert zu Recht einen willkürlichen MAC-Schreibvorgang, zeigt die Ursache aber nicht an der Stelle und in der Form, in der ein Operator sie zuverlässig reparieren kann.

Die Power-Cycles und die ESXi-Abfragen waren erfolgreich. Wiederholungen konnten die unveränderte Portal-Konfiguration nicht reparieren. Eager Zeroed Thick, VMFS6, der Cisco-UCSC-RAID-Controller und VAAI sind für diesen Fehler nicht ursächlich.

### 1.2 Aktuelle technische Lücken

1. `repo_validate_interfaces()` validiert jede Zeile einzeln, prüft aber keine Beziehung zwischen mehreren Interfaces derselben VM.
2. Der VM-Editor bietet für jede Zeile dasselbe VLAN erneut an und kennzeichnet weder die erste noch die zweite kollidierende Auswahl.
3. Leere VLAN-Werte sind speicherbar. `ansible_vm_interfaces()` überspringt solche Zeilen still. Sind alle VLANs leer, erfindet die Ansible-Ausgabe eine WDS-Fallback-NIC. Portalansicht und tatsächlich konfigurierte ESXi-NICs können dadurch auseinanderlaufen.
4. `repo_reassign_vlan()` aktualisiert Interface-VLANs direkt und kann durch eine Massenersetzung eine Kollision erst erzeugen.
5. Speichern, Missionstransfer und Klonen verwenden überwiegend `repo_replace_interfaces()`. Der Massen-Reassign ist der relevante direkte Bypass und muss denselben Vertrag erhalten.
6. Queue, Worker und Retry prüfen die Eindeutigkeit nicht vor einer ESXi-Mutation. Der Fehler wird erst nach Power-Cycle und Export sichtbar.
7. `db_importMAC.php` liefert den technischen Code `ambiguous_vlan`, aber es existiert kein zentraler lokalisierter Portal-Presenter für diesen und die übrigen MAC-Import-Codes.
8. `mac_import_finalize_plan()` erzeugt `vm_results` für die HTTP-Antwort. `mac_import_result_contract()` persistiert sie derzeit nicht in `deploy_jobs.result_json`. Die Jobansicht kann deshalb keine vollständige historische per-VM-Tabelle aus einer einzigen strukturierten Quelle rendern.
9. Der aktuelle Retry kann einen partiellen Auftrag automatisch als Export für die fehlgeschlagenen VM-IDs neu anlegen. Er prüft nicht, ob die aktuelle Konfiguration den deterministischen Fehler weiterhin enthält.
10. Der Joblog-Erstabruf zeigt im heutigen Stand höchstens den ältesten Block. Das wird ausschließlich durch Masterplan-Etappe 10A repariert und in diesem Plan nicht dupliziert.
11. Die Hilfe behauptet bereits, mehrdeutige Netzwerkkarten würden „mit klarer Fehlermeldung“ abgelehnt. Die Ablehnung existiert, die versprochene handlungsfähige Oberfläche jedoch noch nicht.
12. Die untersuchten Produktionsausgaben deuten zusätzlich auf einen älteren Produkt-/Schema-Stand als im aktuellen Arbeitsbaum hin. Vor einem Rollout muss deshalb die tatsächlich laufende Revision und Migration belegt werden. Eine Annahme „Produktiv entspricht dem Arbeitsbaum“ ist unzulässig.

## 2. Ziel und ausdrücklich ausgeschlossene Ziele

### 2.1 Zielzustand

Nach Etappe 14A gilt:

- Eine VM kann im heutigen MAC-verwalteten Datenmodell nicht neu mit leerem VLAN oder zwei logisch gleichen VLANs gespeichert, importiert, geklont oder per Massenaktion verändert werden.
- Bereits vorhandene fehlerhafte Daten werden nicht automatisch verändert oder gelöscht. Sie werden sichtbar, verständlich und direkt reparierbar.
- Create, Full, Power-Cycle und Export werden für genau die betroffenen ausgewählten VMs vor der ersten ESXi-Mutation blockiert.
- Start und Autostart bleiben ausführbar, weil sie keine MAC-Zuordnung benötigen. Sie zeigen die Konfigurationsabweichung als Warnung, nicht als falschen Blocker.
- Queueprüfung, Repository-Gate, Worker-Recheck, VM-Speichern, Missionstransfer, Klonen, Massen-Reassign und MAC-Import verwenden denselben fachlichen Netzwerkvertrag.
- Ein partieller Job zeigt pro VM Ursache, betroffenen VLAN-Namen, Ergebnis und passende Aktion. Technische Codes bleiben unverändert gespeichert und werden nur für die Portalansicht lokalisiert.
- Ein Retry wird nicht angeboten oder serverseitig angenommen, solange die aktuelle Portal-Konfiguration für den Retry-Scope weiterhin mehrdeutig ist.
- Vollständiger Rohlog, Tail/Drain, Filter und Polling bleiben im Owner aus Masterplan 10A/13. Dieser Plan ergänzt nur strukturierte Netzwerkdiagnosen und lesbare Ansible-Loop-Labels.

### 2.2 Nicht Teil dieser Etappe

Folgendes wird nicht nebenbei implementiert:

- paralleles Erstellen von zwei oder drei VMs;
- Änderung der Create-Timeouts oder der Async-/Polling-Architektur;
- Änderung von Eager Zeroed Thick, VMFS, RAID, VAAI oder Datastore-Auswahl;
- automatische Löschung einer „zweiten“ Netzwerkkarte;
- automatische Vermutung, welches andere VLAN ein Interface erhalten sollte;
- automatische Zuordnung nach Listenposition, `hw_eth0`, Anzeigename oder „erstem Treffer“;
- generelle Unterstützung mehrerer NICs derselben VM im selben Portgroup/VLAN;
- Änderung der fünf Legacy-MECM-Statusstrings;
- Lokalisierung oder Umbenennung technischer Machine-API-Felder und Fehlercodes;
- ein zweiter Joblog-Speicher, ein zweiter Terminalstatus oder ein zweiter Retry-Mechanismus;
- eine DB-Migration, die Produktionsdaten selbstständig repariert;
- eine Unique-Constraint auf `(vm_id, vlan)`. Sie würde die fachliche Normalisierung nicht exakt ausdrücken, bei Altbestand den Rollout blockieren und unverständliche DB-Fehler statt handlungsfähiger Portalfehler erzeugen.

## 3. Fest entschiedener Fachvertrag

### 3.1 Identität einer Netzwerkkarte im aktuellen Produkt

Der aktuelle MAC-Workflow kennt keine stabile ESXi-Geräte-ID je Portal-Interface. Seine einzige fachliche Zuordnung ist:

```text
Portal-VM + normalisierter VLAN-/Portgroup-Name -> genau ein Portal-Interface
```

Deshalb gilt für jede VM, die durch Create oder MAC-Export berührt werden kann:

1. Jede persistierte Interface-Zeile benötigt einen nicht leeren VLAN-/Portgroup-Namen.
2. Nach `trim` und Unicode-Kleinschreibung muss jeder VLAN-Name innerhalb derselben VM eindeutig sein.
3. Dasselbe VLAN darf selbstverständlich bei beliebig vielen unterschiedlichen VMs vorkommen.
4. Groß-/Kleinschreibung und führende oder folgende Leerzeichen erzeugen keine künstliche Eindeutigkeit. `vm_srv_depl`, ` VM_SRV_DEPL ` und `Vm_Srv_Depl` sind für diesen Vertrag derselbe Name.
5. VLAN `0` beziehungsweise der Text `"0"` ist nicht leer und darf nicht durch einen Truthiness-Test verworfen werden.
6. Ein retired oder im Cache aktuell nicht sichtbares VLAN ist eine getrennte Inventarabweichung. Es wird durch diesen Vertrag nicht automatisch einem leeren VLAN gleichgesetzt und nicht allein deshalb als MAC-Mehrdeutigkeit behandelt.

Die bestehende Funktion `esxi_inventory_name_key()` bleibt die einzige SSoT für diese Namensgleichheit. Es wird kein zweiter `strtolower(trim(...))`-Helper angelegt. Ihr PHP-Ergebnis, nicht die zufällige MySQL-Collation einer einzelnen Query, entscheidet die Produktsemantik.

### 3.2 Warum nicht nach NIC-Reihenfolge zugeordnet wird

Die offizielle `community.vmware.vmware_guest`-Dokumentation beschreibt `networks` als Liste „in the order of the NICs“. Das reicht für das Erstellen einer neuen VM, ist aber kein belastbarer historischer Identitätsvertrag zwischen Portal-Zeile und späterem `vmware_guest_info`-Resultat:

- vorhandene VMs können außerhalb des Portals verändert worden sein;
- ein Adapter kann entfernt, neu angelegt oder umsortiert worden sein;
- `vmware_guest_info` liefert technische Schlüssel wie `hw_eth0`, aber keine Portal-Interface-ID;
- die Datenbank besitzt derzeit keine persistierte ESXi-Device-Key-/Slot-Zuordnung;
- „erstes Element gewinnt“ würde genau den stillen Falschschreibfehler einführen, den der heutige `ambiguous_vlan`-Guard verhindert.

Mehrere NICs im selben VLAN werden erst in einem späteren, eigenen ADR-fähigen Feature unterstützt. Dieses Feature muss vor jeder Freigabe eine stabile NIC-Identität definieren, zum Beispiel explizite, vorab vergebene MAC-Adresse oder einen nachweislich stabilen ESXi-Device-Key/Slot. Bis dahin wird nicht geraten.

### 3.3 Behandlung bestehenden fehlerhaften Bestands

Bestehende fehlerhafte Zeilen werden weder in einer Migration geändert noch automatisch zusammengeführt.

- Die VM-Liste und der VM-Editor zeigen den Befund.
- Ein Create-/Export-relevanter Deploy wird im betroffenen Scope blockiert.
- Start und Autostart bleiben möglich und zeigen eine Warnung.
- Eine Änderung ausschließlich außerhalb des Interface-Bundles, zum Beispiel CPU oder Beschreibung, bleibt für einen fehlerhaften Altbestand möglich, sofern der kanonische Interface-Bundle-Fingerprint gegenüber dem geladenen DB-Stand unverändert ist.
- Sobald irgendein Interface-Feld, eine Reihenfolge, eine Zeile oder ein Interface-ID-Bezug verändert wird, muss der gesamte Interface-Bundle nach dem neuen Vertrag gültig sein.
- Eine neue VM und jede neu erzeugte VM aus Import oder Klon müssen sofort gültig sein. Für neu erzeugte Daten gibt es kein Grandfathering.

Damit sperrt eine Altlast keine dringende, unabhängige VM-Korrektur, kann aber nicht durch einen Schreibpfad weiter vervielfältigt werden.

### 3.4 Verbindliche Modusmatrix

Die Matrix wird nicht als zweite handgepflegte Modusliste implementiert. Der Helper `deploy_mode_requires_unique_network_mapping($mode)` leitet seine Antwort aus `ansible_playbooks_for_mode()` ab: Enthält die Sequenz Create oder Export, ist die Eindeutigkeit erforderlich.

| Modus | Create-Schritt | Export-Schritt | Verhalten bei leerem/doppeltem VLAN |
|---|---:|---:|---|
| `create` | ja | nein | harter Blocker vor Queue und erneut im Worker |
| `full` | ja | ja | harter Blocker vor Queue und erneut im Worker |
| `powercycle` | nein | ja | harter Blocker vor dem ersten Remote-Schritt und damit vor Power-Off/Power-On |
| `export` | nein | ja | harter Blocker vor ESXi-Abfrage/Callback |
| `start` | nein | nein | sichtbare Warnung, Auftrag bleibt zulässig |
| `autostart` | nein | nein | sichtbare Warnung, Auftrag bleibt zulässig |
| `inventory` | nein | nein | nicht betroffen, weil missionslos |

Wenn sich die Playbook-Sequenz später ändert, ändert sich die Antwort automatisch über `ansible_playbooks_for_mode()`. Ein Test läuft über `virtusphere_deploy_modes()` und bricht bei einem unbekannten oder nicht klassifizierbaren Modus.

### 3.5 Scope-Regeln

1. Enthält der normalisierte Jobpayload `vm_ids`, werden ausschließlich diese VMs geprüft.
2. Ein leeres `vm_ids` bedeutet wie heute die vollständige Mission. Es bedeutet niemals „keine Prüfung“.
3. Doppelte oder ungültige IDs werden zuerst durch den bestehenden Payload-Normalizer behandelt. Der Netzwerkprüfer erhält nur den bereits validierten Scope.
4. Ein partieller Retry prüft ausschließlich `failed_vm_ids` aus dem vertrauenswürdigen Resultat. Fällt der bestehende Retry-Vertrag auf `original_selection` zurück, wird exakt diese Auswahl geprüft.
5. Staffel-/Gruppenjobs werden je tatsächlich entstehendem Job und dessen VM-Scope geprüft. Eine fehlerhafte VM blockiert nicht einen getrennten Staffelslot, der sie nicht enthält.
6. Eine Konfigurationsänderung zwischen Planung und Ausführung ist zulässig, solange der Job noch `queued` ist. Der Worker prüft beim Übergang zu `running` den dann aktuellen Stand.
7. Während `running` oder `cancelling` sind Interface-Bundle-Schreibvorgänge für VMs im Scope des aktiven Missionsjobs serverseitig gesperrt. Leere Job-`vm_ids` sperren alle VMs der Mission; eine explizite Liste sperrt nur deren IDs. Eine nicht ausgewählte VM bleibt editierbar. Dadurch kann das Portal nicht nach Artefakterzeugung die Zuordnungsgrundlage einer tatsächlich laufenden VM austauschen.
8. Für denselben aktiven Scope sind zusätzlich VM-Umbenennung, Löschen und Missionswechsel gesperrt. Das ist zwingend, weil der unveränderte Machine-API-Vertrag MAC-Ergebnisse über `(mission_id, vm_name)` zuordnet. Eine Änderung vor dem Claim ist zulässig und wird vom Worker-Recheck/Manifest übernommen; nach dem Claim bleiben der materialisierte Name und die Mission bis zum fachlichen Jobterminal unveränderlich. Andere unabhängige VM-Felder folgen weiterhin der Fingerprint-/Snapshotregel und werden nicht pauschal gesperrt.

## 4. SSoT, Datenstrukturen und Verantwortungsgrenzen

### 4.1 Neue fokussierte Owner

Die Umsetzung legt folgende Owner an. Keine Datei überschreitet 400 physische Zeilen.

| Datei | alleinige Verantwortung |
|---|---|
| `Docker/WebAPI/lib/vm_network_contract.php` | technische Issue-Codes, kanonische Bundle-Prüfung, Modusableitung, Fingerprint; keine DB, keine Übersetzung |
| `Docker/WebAPI/lib/repo/vm_network.php` | gebündelte DB-Leseabfragen für VM-/Missions-/Job-Scope, aktive-Job-Schreibsperre, kein HTML |
| `Docker/WebAPI/lib/vm_network_display.php` | lokalisierte Meldung, Badge, Handlung und Resultatklassifikation; keine SQL- oder Wire-Werteänderung |
| `Docker/WebAPI/lib/vm_edit_network.php` | zugängliches Markup der wiederholten Interface-Gruppen; delegiert Validierung und URLs |
| `Docker/WebAPI/lib/vm_urls.php` | validierter `vm_edit_url()` einschließlich erlaubtem `interfaces`-Anker |
| `Docker/WebAPI/portal/assets/vm-network.js` | progressive Live-Prüfung, Zeilennummerierung, Fokus und Undo; keine Sicherheitsentscheidung |

Bestehende Owner bleiben bestehen:

- `repo/vms.php` beziehungsweise die nach Masterplan 14 extrahierte VM-Persistenz bleibt Owner des Speicherns.
- `mac_import.php` und `mac_import_result.php` bleiben Owner des Machine-API-Ergebnisvertrags.
- `deploy_queue_blockers()` bleibt einzige Queue-Blocker-SSoT.
- der zentrale Presenter aus Masterplan 10B/13 bleibt Owner der Job-Ergebnisanzeige.
- Masterplan 10A/13 bleibt alleiniger Owner des Joblog-Cursors, Pollers, Filters und Rohdownloads.
- `esxi_inventory_name_key()` bleibt einzige Namens-Key-SSoT.
- `ansible_playbooks_for_mode()` bleibt einzige Sequenz-SSoT.
- Die Remote-Execution-Registry aus Abschnitt 18 bleibt alleiniger Owner von Remotezustand, Reconciliation und Cleanup. Der Netzwerkvertrag schreibt dort keinen zweiten Ausführungszustand.
- Der Deploy-Dienstsnapshot aus Abschnitt 21 bleibt alleiniger Owner der Verfügbarkeitsachse `ready`, `busy`, `degraded`, `cooldown`, `offline`, der Claim-Achse `accepting`, `pause_after_current`, `paused` und der Recoveryachse `none`, `recovering`, `manual_review`.

### 4.2 Technisches Issue-Objekt

Jede Prüfung liefert eine sortierte Liste technischer Objekte mit exakt diesen Feldern:

```php
[
    'code' => 'interface_vlan_empty' | 'interface_vlan_ambiguous',
    'mission_id' => int,
    'vm_id' => int,
    'vm_name' => string,
    'vlan' => string,
    'interface_ids' => list<int>,
    'row_indexes' => list<int>,
    'occurrences' => int,
]
```

Regeln:

- `interface_vlan_empty`: `vlan` ist `''`, genau die leeren Zeilen stehen in `interface_ids`/`row_indexes`.
- `interface_vlan_ambiguous`: `vlan` ist der erste getrimmte sichtbare Originalwert der Gruppe; alle logisch gleichen Zeilen stehen in den ID-/Indexlisten.
- Nicht persistierte Zeilen haben keine positive ID und erscheinen nur in `row_indexes`.
- `occurrences` entspricht der Listenlänge der betroffenen Zeilen und wird nicht separat geraten.
- Sortierung: `vm_name` natürlich/case-insensitive, danach `vm_id`, dann Issue-Code, dann normalisierter VLAN-Key, dann kleinste Interface-ID beziehungsweise Zeilenindex.
- Das Domainobjekt enthält weder übersetzten Text noch HTML noch Berechtigungsentscheidungen.

Die pure Signatur lautet `vm_network_issues_for_interfaces(array $interfaces, int $missionId, int $vmId, string $vmName): array`. Für eine noch nicht persistierte VM ist `$vmId = 0` zulässig. `row_indexes` sind intern nullbasiert; der Presenter zeigt sie immer als „Netzwerkkarte N“ mit `N = index + 1`. `interface_ids` enthält ausschließlich positive persistierte IDs. `repo_vm_network_issues_for_scope()` liest alle benötigten Interfaces in einer gebündelten Query, ruft ausschließlich diese pure Funktion auf und erzeugt kein N+1.

### 4.3 Fingerprint für Grandfathering und Rennen

`vm_network_bundle_fingerprint()` erzeugt SHA-256 über versioniertes kanonisches JSON. Version 1 verwendet die effektive Interface-Reihenfolge: bei DB-Daten `ORDER BY id`, bei Formdaten die eingereichte Reihenfolge. Jede Zeile enthält ihre nullbasierte Ordinalzahl, damit auch eine Umordnung erkannt wird. Eine Sortierung nach Interface-ID innerhalb des Fingerprinthelpers ist verboten. Je Zeile werden aufgenommen:

- Ordinalzahl;
- Interface-ID oder `0` für neu;
- getrimmten VLAN-Originalwert;
- VLAN-Key aus `esxi_inventory_name_key()`;
- Modus;
- Typ;
- IP, Subnet, Gateway, DNS1, DNS2;
- normalisierte vorhandene MAC, soweit sie serverseitig erhalten wird.

Der Fingerprint wird nicht persistiert. Beim Edit-POST liest und sperrt die VM-Persistenz den aktuellen Bundle-Stand, berechnet Alt- und Neu-Fingerprint in derselben Transaktion und entscheidet:

- identisch: unabhängige VM-Felder dürfen trotz einer Altlast gespeichert werden;
- verschieden: der neue Bundle muss vollständig gültig sein;
- DB-Stand seit Render geändert: der vorhandene `updated_at`-Konfliktpfad gewinnt; keine automatische Zusammenführung.

Während eines laufenden oder abbrechenden Jobs prüft derselbe Repo-Pfad vor jedem Interface-Write die aktive Missionsjob-Sperre. Worker-Claim/Artefakterzeugung und VM-Speichern verwenden dieselbe Lockreihenfolge `Mission -> Job, falls vorhanden -> VM -> Interfaces nach ID`. Der Integrationstest erzwingt, dass genau eines von zwei Rennen gewinnt: entweder die Änderung committed vor dem Worker-Recheck und wird von ihm verwendet, oder der Job ist bereits laufend und die Änderung wird verständlich abgewiesen. Ein Zwischenzustand ist unzulässig.

### 4.4 Persistierter MAC-Resultatvertrag

`mac_import_result_contract()` erhält additiv `vm_results`. Die vorhandene Version, der Kind-Name und alle bisherigen Felder bleiben erhalten. Der bestehende Workerdecoder darf additive Felder weiterhin ignorieren.

Jeder persistierte Eintrag hat:

```php
[
    'vm_id' => int|null,
    'vm_name' => string,
    'outcome' => 'success'|'failed',
    'updated_interfaces' => int,
    'error_codes' => list<string>,
]
```

Vorgaben:

- Für jeden erwarteten VM-Scope existiert genau ein Eintrag.
- Unbekannte/unscoped Inputzeilen bleiben in `errors`, erzeugen aber keine erfundene Portal-VM.
- `error_codes` ist dedupliziert, sortiert und enthält technische Tokens.
- `vm_name` ist ein Snapshot für die historische Anzeige. Eine spätere Umbenennung verändert alte Resultate nicht.
- `vm_id` ist additiv auch in der HTTP-Antwort. Bestehende Verbraucher müssen unbekannte Felder weiter tolerieren.
- Altresultate ohne `vm_results` bleiben lesbar. Der Presenter synthetisiert eine eingeschränkte Ansicht aus `successful_vm_ids`, `failed_vm_ids` und `errors` und kennzeichnet sie als historischen Detailstand. Er erfindet keine Ursache.
- Die bestehende Ergebnis- und Fehlerbegrenzung bleibt erhalten. Ein Resultat darf den definierten JSON-/Log-Bound nicht umgehen.

Für `ambiguous_vlan` wird additiv das optionale Fehlerfeld `ambiguity_source` mit `portal`, `esxi` oder `both` erzeugt:

- `portal`: mehrere Portal-Interfaces besitzen denselben VLAN-Key;
- `esxi`: mehrere ausgelesene ESXi-NICs besitzen denselben VLAN-Key, während das Portal genau eine passende Zeile besitzt;
- `both`: beide Seiten sind mehrfach;
- Feld fehlt: historisches Ergebnis, Quelle unbekannt.

Der technische Code bleibt `ambiguous_vlan`. Dadurch bleibt der Machine-API-Vertrag kompatibel, während das Portal die richtige Reparaturstelle nennen kann.

### 4.5 Strukturiertes Ergebnis eines Worker-Preflight-Blocks

Ein Queueblocker erzeugt keinen Job. Der Worker-Recheck kann nach einer Änderung an einem geplanten Job oder nach einem externen DB-Eingriff trotzdem fehlschlagen. Damit dieser seltene Fall nicht nur als Freitext im Log existiert, schreibt er in `deploy_jobs.result_json` genau einen getrennten versionierten Vertrag:

```json
{
  "version": 1,
  "kind": "network_preflight",
  "outcome": "failed",
  "mode": "create",
  "counts": {
    "expected_vms": 15,
    "blocked_vms": 3,
    "issues": 3
  },
  "vm_results": [
    {
      "vm_id": 25,
      "vm_name": "VM-04342",
      "outcome": "failed",
      "issues": [
        {
          "code": "interface_vlan_ambiguous",
          "vlan": "vm_srv_depl",
          "interface_ids": [47, 48]
        }
      ]
    }
  ]
}
```

Regeln:

- `kind=network_preflight` ist ausschließlich für einen terminalen Worker-Recheck vor Remote-Arbeit zulässig.
- Der Vertrag wird auf denselben Bounds wie andere Deployresultate begrenzt und enthält keine IPs, MACs, Ansible-Payloads oder Secrets.
- Für einen Job existiert zu einem Zeitpunkt genau ein finales `result_json`. Ein Preflight-Block erreicht keinen MAC-Import und kann daher keinen `mac_import`-Vertrag überschreiben.
- Ein erfolgreicher Preflight persistiert kein eigenes Resultat. Bei einem späteren Export ist ausschließlich `kind=mac_import` das Resultat.
- Create ohne Export kann bei Erfolg weiterhin ohne `result_json` enden. Create-Zwischenstände bleiben beim separaten Create-Plan.
- Der zentrale Presenter dispatcht exhaustiv über bekannte `kind`-Werte. Ein unbekannter Kind-Wert ist ein neutraler technischer Fehler, niemals Erfolg.
- Altjobs ohne diesen Vertrag bleiben unverändert.

## 5. Schreibpfade und ihre exakte Behandlung

| Schreibpfad | erforderliches Verhalten |
|---|---|
| VM neu im Portal | leeres oder doppeltes VLAN ablehnen; Eingaben erhalten; Fokus auf erstes fehlerhaftes VLAN |
| bestehende VM, Interface-Bundle geändert | gesamtes neues Bundle validieren; atomar ablehnen |
| bestehende VM, Interface-Bundle unverändert | unabhängige Felder trotz sichtbarer Altlast speichern; Altlast bleibt Deploy-Blocker |
| VM-Name/Mission/Löschen | bei `running`/`cancelling` eines Jobs im betroffenen Scope serverseitig mit Link auf den aktiven Job ablehnen; vor Claim zulassen und beim Worker-Recheck neu materialisieren |
| Legacy-VM-Create/Update | gleiche Domainprüfung; bestehender Bool-/Wire-Fallback bleibt unverändert, serverseitiges Audit enthält nur technischen Grund |
| Mission aus Vorlage klonen | vor erstem Insert alle neuen Bundles prüfen; bei einem Fehler kompletter Klon-Rollback |
| Missionstransfer Vorschau | Fehler mit VM, VLAN und Zeilenanzahl in Preview; Commit-Button blockiert |
| Missionstransfer Commit | dieselbe Prüfung innerhalb der Transaktion erneut; Preview ist keine Sicherheitsgrenze |
| VLAN-Massen-Reassign | betroffene `from`- und bereits vorhandene `to`-Interfaces per ID lesen, Zielzustand in Memory simulieren, alle Konflikte nennen, gesamte Aktion ohne Teilupdate abbrechen |
| direkte MAC-Schreibvorgänge | unverändert ausschließlich durch `db_importMAC.php`; Eindeutigkeitsguard bleibt letzte Verteidigung |

Für den Massen-Reassign wird nicht mehr `UPDATE ... WHERE vlan = ?` als alleinige Auswahl verwendet. Wegen Collation- und Whitespace-Unterschieden werden Kandidaten gelesen, über `esxi_inventory_name_key()` ausgewählt und anschließend nur die explizit geprüften Interface-IDs aktualisiert. Missions-WDS-VLANs werden im selben Transaktionsvertrag behandelt. Bei irgendeinem Konflikt werden weder Missionen noch Interfaces geändert.

Sind Quell- und Zielname nach `esxi_inventory_name_key()` gleich, wird die Aktion vor der Transaktion als wirkungsloser Selbst-Reassign abgelehnt. Unterschiedliche Schreibweise oder Leerzeichen dürfen keine scheinbare Änderung und kein Collation-Breitupdate auslösen.

Die Fehleransicht des Massen-Reassign nennt höchstens die ersten 20 VMs und zusätzlich „N weitere“. Ein Download oder eine technische Auditkontextliste bleibt bounded. Es gibt niemals „14 geändert, 1 übersprungen“: Erfolg ist vollständig, Konflikt ist vollständig ohne Write.

## 6. Queue-, Worker- und Retry-Vertrag

### 6.1 Queue

`deploy_queue_blockers()` ergänzt den `kind`-Zweig `vm_network_mapping`. Er enthält technisch:

```php
[
    'kind' => 'vm_network_mapping',
    'code' => 'interface_vlan_empty'|'interface_vlan_ambiguous',
    'message' => string,
    'vm_id' => int,
    'vm_name' => string,
    'vlan' => string,
    'interface_ids' => list<int>,
    'action' => [
        'kind' => 'edit_vm_interfaces',
        'url' => string,
        'label' => string,
        'permission' => 'vms.write',
    ]|null,
]
```

Der technische Domainbefund wird vom Presenter in diesen Blocker übersetzt. `$canQueue` wird wie im Masterplan ausschließlich aus derselben Blockerliste abgeleitet. Der Live-Endpunkt und das Repository-Gate rufen denselben Aggregator mit demselben normalisierten Formularzustand auf.

Für Start und Autostart erzeugt der Domainbefund keinen Blocker, sondern eine getrennte Warning-Liste. Die Warnung darf den Submit nicht deaktivieren und verwendet nicht die Blockerfarbe als einzigen Bedeutungsunterschied.

### 6.2 Worker-Recheck

Unmittelbar nach dem erfolgreichen Claim und vor Artefakt-Upload oder erstem ESXi-Schreib-/Power-Schritt:

1. Scope und Modus erneut aus dem gespeicherten Payload normalisieren.
2. Falls der Modus Eindeutigkeit benötigt, alle Scope-VMs und Interfaces gebündelt lesen.
3. Pro VM die bekannten Progresszeilen schreiben:

   ```text
   [n/total] RUN network preflight <VM-Name>
   [n/total] OK network preflight <VM-Name>
   ```

   oder

   ```text
   [n/total] FAIL network preflight <VM-Name>: configuration_blocked, duplicate VLAN <VLAN> on interface ids <IDs>
   ```

4. Sobald irgendeine VM blockiert ist, wird kein Remote-Artefakt hochgeladen und kein Playbook gestartet. Der Job endet über den zentralen Terminalgrund `configuration_blocked`; die strukturierte Ergebnisanzeige enthält alle betroffenen Scope-VMs, nicht nur die erste.
5. Bekanntes `total` ist die Anzahl der Scope-VMs. Vor jeder VM steht `RUN`, unmittelbar danach genau ein `OK` oder `FAIL`. Die Progress-Regel aus `AGENTS.md` wird nicht durch eine Sammelzeile ersetzt.
6. Start und Autostart schreiben höchstens eine zusammenfassende SYSTEM-Warnung und laufen weiter. Sie erzeugen keine per-VM-Warnflut.

### 6.3 Retry

Der Retry bleibt ein neuer Job und verwendet weiter `deploy_job_retry_plan()`. Hinzu kommt genau ein `deploy_retry_blockers()`-Aggregator für Ausführungs-, Identitäts- und Konfigurationssicherheit:

1. Zuerst den vorhandenen Retryplan bestimmen. Existiert kein Plan, gibt es keinen Retry.
2. Danach prüfen, ob der alte Job oder einer seiner Remote-Schritte noch aktiv ist, `recovery_requested_at` gesetzt ist, `reconciliation_state` den Wert `pending`, `running` oder `manual_required` trägt oder eine fremde Generation ungeklärt ist. Jeder dieser Befunde blockiert einen neuen Job unabhängig vom Netzwerkzustand. Zusätzliche Aliaszustände sind verboten.
3. Danach die vorhandene VM-Identity-Recovery prüfen. Ein ungeklärtes UUID-/MOID-/JID-Ergebnis blockiert vor der VLAN-Prüfung.
4. Erst danach den aus dem Retryplan resultierenden aktuellen VM-Scope mit demselben Netzwerkaggregator prüfen.
5. Der Presenter zeigt alle Blocker in der festen Reihenfolge Remote-Ausführung, Identität, Portal-Netzwerk, externe ESXi-Prüfung. Der Buttonzustand wird ausschließlich aus dieser Liste abgeleitet.
6. Bei aktuellem Portal-Konflikt den Retry-Button durch „Konfiguration korrigieren“ ersetzen. Der Retry-POST führt den gesamten Aggregator in einer Transaktion erneut aus und lehnt einen manipulierten oder veralteten Browserzustand ab.
7. Nach einer erfolgreichen Reparatur wird das historische Resultat nicht umgeschrieben. Die alte Jobseite zeigt weiterhin „damals fehlgeschlagen“ und zusätzlich „Aktuelle Portal-Konfiguration ist jetzt eindeutig“.
8. Ein neues Retry-Jobresultat steht ausschließlich am neuen Job. Es gibt keine rückwirkende Grünfärbung und eine Recovery erhöht nicht `attempts`.
9. Bei `ambiguity_source=esxi` kann das Portal die externe Reparatur nicht vorab beweisen. Ein Export-Retry ist nur zulässig, wenn kein Remote-/Identity-Blocker besteht. Der Confirmtext sagt ausdrücklich, dass das Portal die ESXi-Seite erst beim erneuten Auslesen prüfen kann.
10. Bei historischem `ambiguous_vlan` ohne `ambiguity_source` werden sowohl Portal als auch ESXi als mögliche Quelle genannt. Ist die Portalprüfung grün und sind Remote-/Identity-Prüfung ebenfalls grün, bleibt ein bestätigter Export-Retry möglich.

### 6.4 Klassifikation aller heutigen MAC-Import-Codes

| Code | Portalbedeutung | primäre Aktion | Retryregel |
|---|---|---|---|
| `ambiguous_vlan` | Zuordnung ist mehrfach; Quelle über `ambiguity_source` | Portal-VM oder ESXi-NICs prüfen | Portal-Konflikt blockiert; ESXi/alt nach Bestätigung retrybar |
| `interface_not_found` | ESXi meldet ein VLAN, für das die Portal-VM keine Zeile besitzt | VM-Netzwerke und ESXi-Portgroup vergleichen | bestätigter Export-Retry nach Prüfung |
| `duplicate_mac` | MAC gehört bereits zu einer anderen Portal-NIC | Konfliktinhaber anzeigen, Daten prüfen | kein Blind-Retry |
| `invalid_mac` | ESXi-/Payloadwert ist keine gültige MAC | Joblog und ESXi-NIC prüfen | kein Blind-Retry |
| `missing_nic_data` | Resultat oder NIC-Daten fehlen | Joblog/ESXi-Abfrage prüfen | Export-Retry zulässig |
| `esxi_query_failed` | VM-Info-Abfrage auf ESXi schlug fehl | Joblog und Zugang/VM prüfen | Export-Retry zulässig |
| `identity_mismatch` | Name zeigt auf eine andere Instance-UUID | vorhandene Identity-Recovery-Aktion | kein Retry vor Identity-Klärung |
| `duplicate_result` | Callback enthielt dieselbe VM mehrfach | Joblog/Ansible-Payload prüfen | kein Bedien-Retry; interner Fehler |
| `vm_not_in_mission` | gemeldete VM gehört nicht zur Mission | Scope/Name/Job prüfen | kein Bedien-Retry |
| `vm_not_in_job_scope` | Callback meldete eine nicht ausgewählte VM | Jobpayload/Ansible-Artefakt prüfen | kein Bedien-Retry |
| `missing_name` | Callbackresultat besitzt keinen VM-Namen | Joblog/Ansible-Artefakt prüfen | kein Bedien-Retry |

Unbekannte zukünftige Codes rendern neutral „Unbekannter technischer Fehler“ plus den escaped technischen Token. Sie werden nie als Erfolg und nie automatisch als retrybar klassifiziert. Ein Exhaustivitätstest läuft über die Fehlercode-Registry.

## 7. Portal-UX im Detail

### 7.1 VM-Editor

Der Netzwerkbereich erhält den stabilen Anker `id="interfaces"`. Jede Zeile wird als `fieldset` beziehungsweise gleichwertige eindeutig beschriftete Gruppe gerendert:

```text
Netzwerkkarte 1                         [Entfernen]
VLAN: vm_srv_depl   Modus: DHCP   Typ: VMXNET3
...

Netzwerkkarte 2                         [Entfernen]
VLAN: vm_srv_depl
! Dieses VLAN ist bereits bei Netzwerkkarte 1 gewählt.
  Für den automatischen MAC-Import benötigt jede Netzwerkkarte dieser VM ein eigenes VLAN.
```

Verbindliches Verhalten:

1. Labels und Controls besitzen eindeutige IDs aus Form, Feld und Zeilenkennung gemäß Masterplan 14.
2. Beide kollidierenden VLAN-Selects erhalten `aria-invalid="true"` und jeweils eine eigene Fehler-ID in `aria-describedby`.
3. Die Fehlerzusammenfassung oberhalb des Formulars enthält Links zu allen betroffenen VLAN-Controls und fokussiert nach Serverfehler das erste Control.
4. Die Live-Prüfung läuft beim Laden, bei VLAN-Änderung, Hinzufügen, Entfernen und Undo. Sie verwendet im Browser `trim()` plus `toLowerCase()` als schnelle Annäherung an den PHP-Key. Unicode-Versionen von Browser und PHP können bei exotischen Zeichen abweichen; deshalb darf der Clienthinweis nur früher warnen, niemals einen Serverfehler löschen oder eine Freigabe entscheiden. Der Serverguard über `esxi_inventory_name_key()` bleibt allein maßgeblich.
5. Ohne JavaScript bleibt der Serverpfad vollständig verständlich: Eingaben bleiben erhalten, Fehler stehen zusammengefasst und inline.
6. „Keine“ wird aus dem VLAN-Select persistierbarer Interface-Zeilen entfernt. Ein vorhandener leerer Altwert wird als eigene ungültige Legacy-Option „Kein VLAN zugeordnet, muss korrigiert werden“ angezeigt, damit der Browser nicht still das erste gültige VLAN auswählt.
7. Die Hilfe direkt an der Gruppe sagt: „Jede gespeicherte Netzwerkkarte wird auf ESXi angelegt und benötigt ein VLAN. Wenn keine weitere Karte benötigt wird, entfernen Sie die Zeile.“
8. Entfernen wirkt bis zum Speichern nur lokal und bietet „Rückgängig“. Die letzte verbleibende Zeile kann nicht entfernt werden; die vorhandene Regel „mindestens eine Netzwerkkarte“ bleibt bestehen.
9. Nach Hinzufügen wird die neue Legende angekündigt und der Fokus auf deren VLAN gelegt. Nach Entfernen/Undo wird die Nummerierung vollständig neu berechnet, ohne Inputnamen oder persistierte IDs zu verwechseln.
10. Bei fehlendem `vms.write` ist alles read-only. Die Diagnose bleibt sichtbar, eine Reparaturaktion wird nicht vorgetäuscht.
11. Speichern eines unveränderten Legacy-Bundles zeigt nach Erfolg weiterhin die Warnung. Der Flash behauptet nicht, die Netzwerkkonfiguration sei korrigiert.
12. `vm_edit_url($missionId, $vmId, 'interfaces')` baut alle Deep Links. Handgeschriebene `vm_edit.php?...#interfaces`-Links sind per Static-Test verboten.

### 7.2 VM-Liste

Wenn mindestens eine VM der Mission einen Netzwerkbefund hat, erscheint eine Spalte „Konfiguration“. Sie wird in einer gebündelten Query gespeist.

- gültig: Gedankenstrich, kein grünes „alles sicher“, weil die ESXi-Seite nicht live geprüft wurde;
- ungültig: Warning-Badge mit Text „Netzwerk prüfen“ und zugänglichem Zusatz „Doppeltes VLAN vm_srv_depl“ oder „VLAN fehlt“;
- bei `vms.write`: Badge/Link führt direkt zum Netzwerkanker;
- ohne `vms.write`: reiner Text, keine tote Aktion;
- Farbe ist nie der einzige Träger;
- CSV-Export erhält zwei additive Spalten `network_config_status` und `network_config_detail`, technische stabile Werte, keine HTML- oder lokalisierte Badgeklasse.

Desktop, horizontales Scrollen, 320-Pixel-Mobile und eine Breite, bei der Titel/Aktionen umbrechen, erhalten Geometrie- und Screenshotabnahme. Die spätere Sticky-Aktionsspalte aus Masterplan 15 darf die neue Spalte nicht überdecken.

### 7.3 Deploy-Formular

Bei Create-/Export-relevanten Modi erscheint vor dem Submit ein Blockerblock:

```text
3 ausgewählte VMs besitzen keine eindeutige Netzwerkkonfiguration.

VM-04342: VLAN vm_srv_depl ist Netzwerkkarte 1 und 2 zugeordnet. [VM bearbeiten]
VM-04451: VLAN vm_srv_depl ist Netzwerkkarte 1 und 2 zugeordnet. [VM bearbeiten]
VM-04692: VLAN vm_srv_depl ist Netzwerkkarte 1 und 2 zugeordnet. [VM bearbeiten]

Der Auftrag wurde noch nicht gestartet. Ordnen Sie jeder Netzwerkkarte ein eigenes VLAN zu.
```

Vorgaben:

- Anzahl, Liste, Disabled-Zustand und Aktionen stammen aus derselben Blocker-Union.
- Eine VM mit zwei verschiedenen Konfliktgruppen erscheint einmal als VM-Gruppe und darunter mit beiden VLANs.
- Die Anzeigegrenzen liegen als `VIRTUSPHERE_VM_NETWORK_BLOCKER_INITIAL_LIMIT = 10` und `VIRTUSPHERE_VM_NETWORK_BLOCKER_DETAIL_LIMIT = 50` in der zentralen Bounds-SSoT. Bis 50 betroffene VMs sind zunächst zehn sichtbar und „Alle N anzeigen“ erweitert clientseitig die vollständig gelieferte Liste. Oberhalb von 50 liefert der Endpunkt `total`, die ersten 50 nach der festgelegten Sortierung und `omitted_count`; die UI sagt „Weitere N in der VM-Liste“ und verlinkt dorthin. Der Backendguard prüft unabhängig davon immer den vollständigen Scope. Der Submit bleibt unabhängig vom Einklappen blockiert.
- Der Live-Endpunkt verwirft stale Responses. Ein schneller Wechsel von `start` zu `create` darf niemals den Start-Warnzustand als Create-Freigabe stehen lassen.
- Sessionende, 403 oder Netzfehler führen zu „Prüfung nicht möglich“ und einem nicht freigegebenen Submit für harte Modi. Für Start/Autostart bleiben die bestehenden serverseitigen Regeln maßgeblich; der Client erfindet keine globale Sperre.
- Bei Start/Autostart lautet der Block „Hinweis“ und erklärt, dass der aktuelle Modus keine MAC-Zuordnung ausführt. Der Button bleibt aktiv.

### 7.4 Jobliste und Jobdetail

Die Jobliste zeigt für ein strukturiertes MAC-Ergebnis zusätzlich zum Status:

```text
12/15 VMs erfolgreich · 3 Konfigurationsfehler
```

„Konfigurationsfehler“ wird nur gezählt, wenn die Codes der zentralen Klassifikation entsprechen. Transport-, Identitäts- und interne Fehler erhalten ihre eigene Sammelbezeichnung. Gemischte Gruppen werden als „3 fehlgeschlagen“ angezeigt und im Detail aufgeschlüsselt.

Der Ergebnisblock im Jobdetail enthält:

1. Summenkarten für erwartet, erfolgreich, fehlgeschlagen und aktualisierte Interfaces.
2. Eine Tabelle `VM | Phase | Ursache | VLAN | Ergebnis | Aktion`.
3. Pro fehlgeschlagener VM genau eine Hauptzeile; mehrere Codes erscheinen als Liste innerhalb derselben Ursachezelle.
4. Technische Codes in einem sekundären aufklappbaren Bereich „Technische Details“, kopierbar und escaped.
5. Für den konkreten Fall die Aktion `VM bearbeiten`, sofern erlaubt. Bei ESXi-Quelle die Aktion/Anweisung `ESXi-Netzwerkkarten prüfen` ohne erfundenen Portal-Link.
6. Einen historischen Hinweis bei alten Resultaten ohne `vm_results`; keine erfundenen erfolgreichen VM-Namen.
7. Einen aktuellen Reparaturstatus, der per read-only Recheck ermittelt wird und den historischen Ausgang nicht überschreibt.

Bei gelöschter VM zeigt die historische Tabelle den gespeicherten Namen und `VM #<id> nicht mehr vorhanden`. Es gibt keinen Link auf eine fremde oder inzwischen wiederverwendete ID.

### 7.5 Logs und Protokolle

1. Der strukturierte Ergebnisblock ist die primäre Diagnose. Freitext-Ansible-Ausgabe wird nicht geparst, um Portalentscheidungen zu treffen.
2. Jede Ansible-Schleife über VMs erhält `loop_control.label` mit dem VM-Namen. Komplexe komplette `item`-Objekte werden nicht in Standardausgaben vervielfältigt.
3. `loop_control.label` ist Lesbarkeit, kein Secret-Schutz. Tasks mit sensiblen Werten behalten `no_log`; `display_args_to_stdout` wird nicht aktiviert.
4. Preflight `ansible-doc` behält erfolgreichen stdout unterdrückt und verständlichen stderr bei Fehler. Er wird nicht als VM-Fortschritt ausgegeben.
5. Vollständiger Tail, ältere Seiten, Rohdownload, Wrap, Suche und Quellenfilter kommen ausschließlich aus Masterplan 10A/13.
6. Portal-Validierungsfehler erzeugen keinen Errorlog-Stacktrace und kein Audit pro Live-Prüfung.
7. Erfolgreiche Reparatur eines zuvor erkannten Befunds erzeugt nach Masterplan 10C genau ein strukturiertes Audit `vm.network_mapping_repaired` mit mission_id, vm_id und Anzahl behobener Issues, aber ohne vollständige Formpayload.
8. Ein blockierter VLAN-Massen-Reassign erzeugt ein strukturiertes Audit mit `result=blocked`, Quell-/Zielname, Anzahl betroffener VMs und bounded VM-IDs. Reine Deploy-Preview-Blocker erzeugen kein Auditspam.

## 8. Barrierefreiheit und visuelle Anforderungen

Die Umsetzung folgt den W3C-Vorgaben für Fehlerbenachrichtigung:

- Gesamtfehlerliste vor dem Formular plus Inline-Fehler am jeweiligen Feld;
- verständliche Korrekturanweisung statt nur Fehlercode;
- Link aus der Fehlerliste zum zugehörigen Control;
- `aria-describedby` und `aria-invalid` am fehlerhaften VLAN-Control;
- Fokus auf das erste fehlerhafte Feld nach Servervalidierung;
- sichtbarer Text und Symbol/Badge zusätzlich zur Farbe;
- dynamische Statuszusammenfassungen mit zurückhaltender Live-Region, nicht jede einzelne Logzeile assertiv vorlesen.

Die neue Darstellung verwendet bestehende Theme-Tokens, `.button`-Verträge, `:focus-visible`, `min-width:0` und gemeinsame Stack-/Gap-Regeln. Jede im Markup verwendete Klasse erhält eine CSS-Regel oder eine begründete Hook-Ausnahme. Kein neues Modal wird gebaut; falls eine Bestätigung nötig ist, verwendet sie das bestehende gemeinsame Dialogsystem.

## 9. Edge-Case-Matrix mit festem Sollverhalten

| Edge Case | verbindliches Ergebnis |
|---|---|
| genau ein Interface, gültiges VLAN | kein Befund |
| zwei Interfaces, verschiedene VLANs | kein Befund |
| dasselbe VLAN auf verschiedenen VMs | zulässig |
| `vm_srv_depl` und `VM_SRV_DEPL` in einer VM | Ambiguous-Befund |
| führende/folgende Leerzeichen | werden für Gleichheit ignoriert; sichtbarer Wert getrimmt gespeichert |
| VLAN-Wert `"0"` | gültiger nicht leerer Name |
| leerer String, Whitespace oder NULL | `interface_vlan_empty` |
| zwei leere Zeilen | ein Empty-Befund mit beiden Zeilen, nicht Ambiguous plus Empty doppelt |
| dreimal dasselbe VLAN | ein Ambiguous-Befund mit drei IDs/Indizes |
| zwei unterschiedliche doppelte VLAN-Gruppen | zwei Befunde, eine gruppierte VM-Anzeige |
| Unicode-Groß-/Kleinschreibung | Gleichheit ausschließlich über `esxi_inventory_name_key()` |
| MySQL-Collation hält zwei andere Namen für gleich | Produktlogik bleibt beim PHP-Key; Reassign aktualisiert geprüfte IDs, nicht Collation-Breitmatch |
| retired VLAN | getrennte Inventarwarnung; nicht automatisch Empty/Ambiguous |
| VLAN fehlt im aktuellen ESXi-Cache | bestehende Inventarabweichung; dieser Vertrag erfindet keinen harten Mappingfehler |
| ESXi hat zwei NICs im selben VLAN, Portal nur eine | Callback `ambiguous_vlan`, `ambiguity_source=esxi`; ESXi-Prüfanweisung |
| Portal hat zwei, ESXi eine | `ambiguity_source=portal`; Portal-Reparatur |
| beide Seiten haben zwei | `ambiguity_source=both`; beide Reparaturstellen nennen |
| alte Ergebniszeile ohne Quelle | generische Meldung nennt beide Möglichkeiten |
| ESXi-NIC ohne Summary oder MAC | `missing_nic_data`, kein Teilwrite für diese VM |
| ESXi meldet unbekanntes VLAN | `interface_not_found`, Portal und ESXi vergleichen |
| dieselbe MAC schon an anderer VM | `duplicate_mac`, keine Überschreibung |
| eine VM erfolgreich, eine mehrdeutig | erfolgreicher VM-Write bleibt atomar committed; Job `partial`; Retry nur fehlgeschlagener Scope |
| mehrere Fehler innerhalb einer VM | kein MAC-Teilwrite für diese VM; alle bounded Codes sichtbar |
| doppelte Callbackzeile derselben VM | `duplicate_result`; kein „letzter gewinnt“ |
| Callback nach Jobterminal | bestehendes HTTP 409, keine Änderung |
| Callback während `cancelling` | bestehender ADR-0033-Vertrag bleibt gültig |
| VM-Konfiguration wird vor geplantem Start repariert | Worker sieht aktuellen gültigen Stand und darf laufen |
| Interface-Edit während `running` | serverseitig abgelehnt, Eingaben bleiben sichtbar, Link zum Job |
| VM-Umbenennung/Missionswechsel/Löschen während `running` oder `cancelling` | serverseitig abgelehnt; `(mission_id, vm_name)` und Scope bleiben bis Jobterminal stabil |
| Massen-Reassign trifft laufende Mission | gesamte Aktion blockiert, kein Teilupdate |
| zwei Browser-Tabs editieren dieselbe VM | vorhandener Optimistic-Lock-Konflikt; keine Zusammenführung |
| JS deaktiviert | Serverfehler vollständig nutzbar |
| Live-Blockerresponse kommt veraltet an | Requestgeneration verwirft sie |
| Live-Endpunkt liefert HTML/Login statt JSON | als Session-/Protokollfehler behandeln, nicht rendern |
| Benutzer darf deployen, aber nicht VMs schreiben | Grund sichtbar, Reparaturlink fehlt, Submit blockiert |
| Benutzer darf VMs schreiben, aber nicht deployen | Editordiagnose nutzbar; keine Deployaktion |
| VM nach altem Job gelöscht | historischer Name, keine fremde ID-Verlinkung |
| Result JSON fehlt/ist kaputt | bestehender sicherer Failed-Fallback; keine Erfolgssynthese |
| unbekannter neuer Fehlercode | neutral sichtbar, nicht retrybar, Test weist Registry-Drift nach |
| mehr als 10 UI-Befunde | UI einklappbar, Backend prüft trotzdem alle |
| mehr als 200 Bulk-VMs | bestehender `VIRTUSPHERE_VM_BULK_CAP` bleibt Grenze; keine neue Zahl |
| sehr langer VLAN-/VM-Name | vorhandene Bounds, escaped, Layout wrappt ohne horizontalen Seitenausbruch |
| Umlaute/Emoji im Namen | gültiges UTF-8, keine Byte-/Zeichenzählungsdrift |
| direkte SQL-Manipulation außerhalb der App | nicht unterstützt; MAC-Endpoint bleibt letzte sichere Ablehnung, nie willkürliches Write |
| Ansible-Output ist größer als DOM-Limit | Masterplan 10A Tail/Rohdownload; Ergebnisblock bleibt separat vollständig |
| Retention hat Detailzeilen gelöscht | strukturierter Jobresultat-/Terminalfallback bleibt; keine rekonstruierte Freitextbehauptung |

## 10. Verbindliche Arbeitspakete

### Paket A: Charakterisierung, Registry und rote Tests

Vor Produktcode:

1. Exakte Aufruferliste von `repo_validate_interfaces()`, `repo_replace_interfaces()`, direktem `deploy_interfaces`-Write, `ansible_vm_interfaces()`, `mac_import_build_plan()`, `deploy_queue_blockers()` und Retry-POST erzeugen und als Test-/Umsetzungsmatrix in dieser Etappe ablegen.
2. Fixtures für die drei Produktions-VMs mit anonymisierungsfreiem technischen Namen, Interface-IDs und VLAN anlegen. Keine MAC oder Zugangsdaten in Fixtures übernehmen.
3. Rote Unit-Tests für Empty, Duplicate, Case, Whitespace, Unicode, VLAN `0`, mehrere Gruppen und deterministische Sortierung.
4. Rote Integrationstests für Portal-Save, unverändertes Grandfathering, Legacy-Write, Klon, Transfer und Massen-Reassign.
5. Rote Queue-/Worker-/Retry-Tests für alle sechs Missionmodi plus Inventory.
6. Rote Machine-API-Tests für `ambiguity_source` und persistierte `vm_results` anlegen, ohne bestehende Felder zu ändern.

Abnahme A: Tests schlagen ausschließlich wegen der noch fehlenden neuen Verträge fehl. Bestehende Tests bleiben grün; kein Snapshot wird vorschnell aktualisiert.

### Paket B: pure Netzwerk-SSoT und Repositoryleser

1. `vm_network_contract.php` mit Issuecodes, purem Gruppierer, Modusableitung und Fingerprint implementieren.
2. Bestehenden `esxi_inventory_name_key()` verwenden und Include-/Bootstrapreihenfolge statisch absichern.
3. `repo/vm_network.php` mit gebündelten Scope-Abfragen implementieren.
4. Missionweite VM-Liste, ausgewählter Scope, Retry-Scope und einzelne VM ohne N+1 abdecken.
5. Unknown Mode und malformed rows fail closed behandeln.

Abnahme B: Unit-/Integrationstests für alle Normalisierungsfälle grün; Query-Count-Test belegt konstante Queryzahl je Scope.

### Paket C: alle Schreibpfade schließen

1. VM-Neuanlage und Interface-Änderung an die pure SSoT anbinden.
2. Grandfathering ausschließlich über den Fingerprint umsetzen.
3. Aktive-Job-Schreibsperre mit definierter Lockreihenfolge für Interface-Bundle sowie VM-Name, Missionswechsel und Löschen implementieren.
4. Legacy-Writes ohne Wireänderung anbinden.
5. Klon und Transfer-Preview/Commit anbinden.
6. `repo_reassign_vlan()` auf read-simulate-update-by-ID umbauen und vollständig atomar halten.
7. Leere VLAN-Auswahl für neue/geänderte Interfaces entfernen, WDS-VLAN-Semantik der Mission unberührt lassen.

Abnahme C: Jeder Matrixpfad hat Positive-, Negative- und Rollbacktests. Kein Writer kann durch eine neue Datei am Guard vorbeigehen; ein Static-Owner-Glob und ein Negativfixture beweisen das.

### Paket D: Queue, Worker und Rennen

1. Domainbefunde in den bestehenden Blocker-Aggregator integrieren.
2. Modusabhängige Blocker/Warnings ausschließlich aus der Sequenz ableiten.
3. Live-Endpunkt und Repo-Gate mit identischem normalisiertem State prüfen.
4. Worker-Recheck und Progresszeilen vor Remote-Arbeit einbauen.
5. Edit-vs-Claim-, Reassign-vs-Running- und Scheduled-Job-Rennen integrieren.
6. Terminalgrund `configuration_blocked` und den versionierten `network_preflight`-Resultatvertrag an den zentralen Presenter aus 10B übergeben.

Abnahme D: In jedem Hard-Block-Fall existieren null Uploads, null SSH-Kommandos und null ESXi-Mutationen. Ein Mock/Spy beweist diese Nullseite.

### Paket E: MAC-Resultat, Ursache und Retry

1. MAC-Lookup pro VM gebündelt lesen und über `esxi_inventory_name_key()` gruppieren, statt je NIC eine Collation-abhängige Query auszuführen.
2. Portal- und ESXi-Mehrfachheit getrennt zählen und `ambiguity_source` additiv setzen.
3. `vm_results` mit `vm_id` additiv persistieren.
4. Altresultate fail-soft lesen.
5. Fehlercode-Registry und Retryklassifikation zentral implementieren.
6. Retry-Scope aktuell revalidieren und POST serverseitig sperren.

Abnahme E: Bestehende Machine-Wire-Tests bleiben grün; neue Felder sind additiv; per-VM-Atomarität, Idempotenz und Jobstatus bleiben gemäß ADR-0030/0033 erhalten.

### Paket F: VM-, Deploy- und Joboberfläche

1. URL-Helper und Netzwerkanker implementieren.
2. Zugängliche Interface-Gruppen, Inlinefehler, Fehlerliste, Liveprüfung und Undo implementieren.
3. VM-Listenindikator ohne N+1 implementieren.
4. Deploy-Blocker/Warnings und direkte Aktionen implementieren.
5. Joblisten-Summary und zentrale Jobdetailtabelle implementieren.
6. RBAC-Varianten, JS-less Pfad, Unknown/Legacy und gelöschte VM rendern.

Abnahme F: DOM-, axe-, Keyboard-, Screenreader-Stichprobe, beide Themes, Desktop/Mobile/Wrap-Screenshots und Poller-Interaktion grün.

### Paket G: Ansible-Lesbarkeit und Logintegration

1. Alle VM-Loops in den betroffenen Create-, Power-Cycle-, Export-, Start- und Autostart-Playbooks inventarisieren.
2. Wo komplexe VM-Objekte geloopt werden, `loop_control.label` mit VM-Namen ergänzen.
3. `no_log`- und Secret-Sentinel-Tests unverändert beziehungsweise stärker halten.
4. Joblog-Tail, Rohdownload und Filter nur gegen 10A/13 integrieren, nicht neu bauen.
5. Einen Testlauf mit mehr als 1.500 Logzeilen und den drei Ambiguous-VMs ausführen: Ende, Ergebnisblock und Rohdownload müssen vollständig sein.

Abnahme G: lesbare per-VM-Ausgaben, keine vollständigen Item-Dumps, keine Secrets, keine Doppelzeilen und vollständiger Terminaltail.

### Paket H: Doku, Produktionsrollout und Abschluss

1. Alle in Abschnitt 12 genannten Texte und Dokumente im selben Commitstand aktualisieren.
2. Read-only Bestandsaudit im Produktivsystem ausführen.
3. Revision, Containerimage und Migration belegen.
4. Canary-, Negativ- und Retry-Szenario abnehmen.
5. Fast-, Integration- und Release-Lane gemäß `scripts/check.ps1` ausführen.
6. Vollständigen Diff, untracked Dateien, Datei-Budgets, SSoT-Drift, CSP, i18n und Doku-Semantik prüfen.

Abnahme H: Abschnitt 13 ist vollständig belegt; keine offene Frage und kein „später prüfen“ bleibt.

## 11. Testplan

### 11.1 Unit

- `VmNetworkContractTest`: sämtliche Key-, Empty-, Duplicate-, Sortier- und Fingerprintfälle.
- `DeployNetworkModeContractTest`: alle Modi aus der Sequenz-SSoT, Unknown fail closed.
- `MacImportErrorDisplayTest`: jeder bekannte Code DE/EN, Unknown, Quelle portal/esxi/both/legacy.
- `DeployRetryBlockerTest`: historischer Fehler versus aktuelle Konfiguration, Scopefallbacks.
- `VmNetworkDisplayTest`: Singular/Plural, 1/2/3/mehr Gruppen, escaping.
- `VmUrlTest`: Mission-/VM-ID, erlaubter Anchor, ungültiger Anchor.

### 11.2 Integration

- `VmNetworkPersistenceTest`: Neu, Update, unchanged Grandfather, changed Legacy, Rollback, MAC-Erhalt.
- `VmNetworkWriterCoverageTest`: Portal, Legacy, Klon, Transfer, Reassign.
- `VmNetworkJobLockTest`: queued editierbar, running/cancelling gesperrt, terminal wieder editierbar, Race.
- `DeployNetworkBlockerTest`: Auswahl/gesamte Mission/Staffel und alle Moduszweige.
- `DeployWorkerNetworkPreflightTest`: null Remote-Aktion bei Block, Progressvertrag, Resultat.
- `MacImportCallbackTest`: Portal-/ESXi-/both-Ambiguity, per-VM-Atomarität, additive Felder, alte Payload.
- `DeployJobRetryFlowTest`: blockiert, repariert, ESXi-bestätigt, doppelte POSTs, ein aktiver Job.
- `EsxiVlanReassignTest`: Kollisionssimulation, exakte IDs, Collationfall, kompletter Rollback.
- `MissionTransferTest` und `MissionsRepoTest`: Preview/Commit/Klon mit mehreren VM-Fehlern.

### 11.3 Static/Contract

- genau eine VLAN-Key-SSoT;
- kein direktes `deploy_interfaces.vlan`-Update außerhalb erlaubter Owner;
- kein handgeschriebener VM-Netzwerk-Deep-Link;
- alle MAC-Fehlercodes in Registry und Presenter;
- alle postbaren Modi durch Modusableitung abgedeckt;
- `vm_results` additiv, Altdecoder akzeptiert Fehlen;
- DE/EN-Schlüssel und Platzhalter parity;
- jede neue CSS-Klasse definiert;
- Confirm-/POST-/RBAC-Owner-Globs vollständig;
- alle neuen PHP-Dateien unter 400 Zeilen;
- Ansible-Loops mit komplexem `item` besitzen Label oder begründete Ausnahme;
- ProgressReporting-Test deckt den neuen Worker-Preflight ab.

### 11.4 Browser/E2E

1. Neue VM: zweites identisches VLAN live markieren; Submit serverseitig abweisen.
2. JavaScript aus: identischer POST zeigt Zusammenfassung und Inlinefehler.
3. Bestehende Produktionsfixture: drei VMs erscheinen in Deploy-Blockern mit exakten Links.
4. Benutzer ohne `vms.write`: Ursache sichtbar, keine Reparaturaktion.
5. Wechsel Create -> Start -> Create mit absichtlich vertauschter Response-Reihenfolge.
6. Zeile hinzufügen, Duplikat erzeugen, entfernen, Undo, Nummerierung/Fokus prüfen.
7. Letzte Zeile kann nicht entfernt werden.
8. Serverkonflikt aus zweitem Tab erhält Eingaben und verständlichen Updated-at-Pfad.
9. Partial Job zeigt 12/15 und drei per-VM-Ursachen; reparierte Portal-Konfiguration schaltet Retry frei.
10. ESXi-Quellenfall zeigt keinen falschen Portal-Reparaturbeweis.
11. Mehr als 1.500 Joblogzeilen: Terminaltail, ältere Seiten und Rohdownload.
12. 320 px, 768 px, 1280 px, beide Themes, erzwungener Heading-/Action-Wrap.
13. axe, reine Tastatur, Fokusreihenfolge und kurze Screenreader-Stichprobe.

### 11.5 Maschinen- und Abwärtskompatibilität

- HTTP-Methode, Allowlist, `mission_id`, `job_id`, Statuscodes und bestehende JSON-Felder unverändert.
- Additive `vm_id`/`ambiguity_source` werden von altem Uploadclient ignoriert beziehungsweise stammen serverseitig.
- Legacy-Callback ohne neue Felder bleibt verarbeitbar, soweit der bestehende ADR-0035-Vertrag es erlaubt.
- Technische Codes und Resultatwerte werden nicht lokalisiert.
- Fünf MECM-Statusstrings, `updated` und `mecm_id` bleiben unverändert.
- Cancel-Fenster `running|cancelling` bleibt gemäß ADR-0033.
- `result_json` bleibt MAC-/Pipeline-Resultat; Create-Zwischenstände bleiben in der Owner-Struktur des Create-Plans.

## 12. Vollständige Text-, Hilfe- und Dokumentationsmatrix

### 12.1 Sprachkataloge

Mindestens diese DE/EN-Module werden gemeinsam geändert:

| Modul | Inhalt |
|---|---|
| `lang/*/vm_edit.php` | Zeilenlegenden, Empty/Duplicate-Fehler, Gruppenhilfe, Undo, laufender Job |
| `lang/*/vms.php` | Konfigurationsspalte, Badge, CSV-Detailbezeichnungen |
| `lang/*/deploy.php` | Blocker/Warning, Summen, Retryzustände, aktuelle Reparaturprüfung |
| `lang/*/validate.php` | serverseitige fachliche Validierungstexte |
| `lang/*/help_missions.php` | eindeutiger VLAN-Vertrag, keine automatische NIC-Wahl |
| `lang/*/help_stack.php` | MAC-Ablauf, Portal-/ESXi-Mehrdeutigkeit und Reparatur |

Bestehende Rohcodes bleiben außerhalb der Übersetzung. Es werden echte deutsche Umlaute und keine Em-Dashes in Portalprosa verwendet.

### 12.2 Portalhilfe

Die Hilfe muss nach Umsetzung exakt beantworten:

1. Warum jede gespeicherte NIC ein VLAN braucht.
2. Warum zwei NICs derselben VM im heutigen Workflow nicht dasselbe VLAN verwenden dürfen.
3. Dass zwei unterschiedliche VMs selbstverständlich dasselbe VLAN nutzen dürfen.
4. Wie ein Operator den Blocker vor dem Deploy repariert.
5. Wie Portal- und ESXi-seitige Mehrdeutigkeit unterschieden werden.
6. Warum das Portal keine Karte löscht und kein Ziel-VLAN errät.
7. Was Start/Autostart trotz Warnung tun und was sie nicht tun.
8. Was ein Retry wiederholt und warum ein alter Job historisch fehlgeschlagen bleibt.
9. Dass eine spätere Unterstützung doppelter VLANs stabile NIC-Identität benötigt.

Die heute bereits vorhandene Aussage „klare Fehlermeldung“ bleibt nur stehen, wenn die E2E-Abnahme die direkte Meldung und Aktion tatsächlich beweist.

### 12.3 Technische und betriebliche Dokumentation

| Datei | verbindliche Ergänzung |
|---|---|
| `docs/DEPLOYMENT.md` | Netzwerk-Invariante, Modusmatrix, Worker-Recheck, additive Resultfelder, Ansible-Label |
| `docs/operations/deploy-chain.md` | Preflight vor ESXi, strukturierte Ursachen, Retry-Gate |
| `docs/operations/mecm-integration.md` | MAC-Mapping, `ambiguity_source`, `vm_results`, per-VM-Atomarität |
| `docs/operations/esxi-inventory.md` | atomarer VLAN-Reassign mit Kollisionspreflight |
| `docs/operations/troubleshooting.md` | Suchweg von Job -> VM -> VLAN -> ESXi, konkrete Schritte |
| `docs/operations/go-live.md` | read-only Bestandsaudit, Canary und Abbruchkriterien |
| `docs/QA.md` | neue Unit/Integration/E2E/Visual-Szenarien und Produktionsfixture |
| `docs/adr/ADR-0030-partial-deploy-results-and-result-json.md` | additive persistierte `vm_results`, Quellenkontext, Retryklassifikation |
| `docs/adr/ADR-0023-esxi-inventory-and-vlan-ownership.md` | Reassign-Kollisionsvertrag, Cacheabweichung bleibt getrennt |
| `docs/CHANGELOG.md` | Operatornutzen, kein Hardware-/Storage-Fix behaupten |
| `AGENTS.md`, `.claude/rules/portal.md`, `GROK.md` | dauerhafte Owner-/SSoT-Regel nur soweit durch die Umsetzung tatsächlich geändert |

Kein neues ADR ist für die hier beschriebene Eindeutigkeitsabsicherung nötig, weil ADR-0030 und ADR-0023 die betroffenen Verträge bereits besitzen. Eine spätere Unterstützung mehrerer NICs im selben VLAN benötigt dagegen eine neue Entscheidung mit Identitätsmodell und Migration.

## 13. Produktionsrollout ohne Datenraten

### 13.1 Vor dem Deployment

1. Laufende Revision belegen: Git-Commit beziehungsweise Image-Digest des PHP-, Worker- und Ansible-Deployments dokumentieren.
2. `docker compose config` und laufende Images/Container erfassen, ohne Secrets auszugeben.
3. Zuerst alle `running`/`cancelling`-Jobs regulär bis zum Terminalzustand drainieren. Danach den `deploy-worker` stoppen, bevor eine Komponente aktualisiert wird. Ein laufender Remote-Schritt wird niemals durch Containerneustart abgeschnitten. `queued`-Jobs dürfen während des Wartungsfensters bestehen oder neu hinzukommen, weil ihr Payloadvertrag unverändert bleibt; der gestoppte Worker kann sie nicht mit einer gemischten Revision übernehmen. Erst nachdem Portal, Worker-Code und Ansible-Artefakte vollständig aus derselben Revision laufen, wird der Worker wieder gestartet und prüft jeden wartenden Job gegen den aktuellen Netzwerkvertrag.
4. Migrationsstand mit dem vorhandenen `migrate --check`-Pfad prüfen. Der früher beobachtete fehlende `vm_moid`-Stand muss vor dieser Featureabnahme erklärt oder behoben sein.
5. Read-only Bestandsaudit über denselben Domainhelper ausführen. Ausgabe: Mission-ID/-Name, VM-ID/-Name, Code, VLAN, Interface-IDs. Keine MACs, IPs oder Secrets.
6. Report als freigabebezogenes QA-Artefakt sichern. Keine dauerhafte Kopie produktiver Daten in Git.
7. Betroffene VMs mit Fachverantwortlichen einzeln klären. Keine Karte wird gelöscht und kein VLAN geändert, bevor das Soll-VLAN bestätigt ist.

### 13.2 Deployment-Reihenfolge

1. Backup und dokumentierter Restore-Check gemäß ADR-0017.
2. Anwendung, Worker und Ansible-Artefakte aus derselben Revision deployen. Gemischte Revisionen sind nicht zulässig.
3. Migrationen ausführen. Diese Etappe enthält keine automatische Datenkorrekturmigration.
4. PHP-/Maintenance-/Deploy-Worker gesund prüfen.
5. Read-only Audit erneut ausführen. Anzahl und IDs müssen gegenüber dem Vorbericht erklärbar sein.
6. Einen synthetischen gültigen Zwei-NIC-Canary durch Save -> Create/Export-Preflight -> Resultat führen.
7. Einen synthetischen Duplicate-Canary erzeugen, soweit ausschließlich im isolierten QA-Stack: Save und Queue müssen vor ESXi blockieren.
8. Eine der realen betroffenen VMs nach bestätigter Korrektur als Produktionscanary ausschließlich mit dem erforderlichen Export-Retry ausführen. Kein Create/Power-Cycle der ganzen Mission, wenn der Retryplan Export-only vorsieht.
9. Erst danach die übrigen korrigierten VMs einzeln beziehungsweise im vorgesehenen fehlgeschlagenen Scope retryn.

### 13.3 Abbruchkriterien

Rollout sofort stoppen, wenn:

- Portal, Worker und Ansible nicht dieselbe Revision tragen;
- Migrationsstand unbekannt ist;
- ein Hard-Block-Fall irgendeinen Remote-Upload, SSH- oder ESXi-Schritt startet;
- ein gültiger Altbestand fälschlich blockiert wird;
- Start oder Autostart wegen eines reinen Mappingbefunds blockiert wird;
- ein Retry mehr VMs als seinen berechneten Scope enthält;
- eine erfolgreiche VM eines Partial-Jobs erneut verändert wird;
- Machine-API-Felder, HTTP-Status oder MECM-Statusstrings abweichen;
- ein Log Secret-/vollständige Item-Payload enthält;
- DE/EN-, CSP-, Schema-, Enum- oder Dateigrößen-Gate rot ist.

### 13.4 Rollback

- Code/Container werden auf die vorherige vollständig zusammengehörige Revision zurückgerollt.
- Da keine automatische Datenreparatur und keine Unique-Constraint eingeführt wird, muss kein produktiver Interface-Bestand zurückgeschrieben werden.
- Manuell und fachlich bestätigte VLAN-Korrekturen werden nicht automatisch rückgängig gemacht. Ein Rollback darf keine korrekten Bedienänderungen überschreiben.
- Additive Resultfelder in bereits geschriebenem JSON bleiben für alte Leser unschädlich.
- Ein während des Rollouts gestarteter Job wird nach dem bestehenden Cancel-/Workervertrag behandelt, nicht durch DB-Manipulation beendet.

## 14. Quellen und daraus abgeleitete Entscheidungen

1. Die offizielle Ansible-Dokumentation beschreibt `vmware_guest.networks` als Liste in NIC-Reihenfolge und erlaubt eine optionale MAC pro Eintrag. Daraus folgt: Reihenfolge hilft beim Provisionieren, ersetzt aber ohne persistierte Portal-zu-ESXi-Geräteidentität keinen sicheren späteren Join.
   Quelle: [community.vmware.vmware_guest](https://docs.ansible.com/projects/ansible/latest/collections/community/vmware/vmware_guest_module.html)
2. `vmware_guest_info` liefert in seinen Resultaten technische NIC-Blöcke mit Label, MAC und Network-Summary, aber keine Portal-Interface-ID. Daraus folgt: `summary`/VLAN bleibt im aktuellen Modell der einzige vorhandene Join und muss eindeutig sein.
   Quelle: [community.vmware.vmware_guest_info](https://docs.ansible.com/projects/ansible/latest/collections/community/vmware/vmware_guest_info_module.html)
3. Ansible empfiehlt `loop_control.label`, um bei komplexen Loop-Items die Ausgabe auf einen verständlichen Namen zu begrenzen. Die Dokumentation warnt zugleich, dass dies kein Secret-Schutz ist und `no_log` dafür getrennt nötig bleibt.
   Quelle: [Ansible Loops, Limiting loop output with label](https://docs.ansible.com/projects/ansible-core/devel/playbook_guide/playbooks_loops.html)
4. Die Ansible-Logging-Dokumentation weist auf `no_log` für sensible Daten hin. Daraus folgt: Lesbarkeitslabels dürfen die vorhandene Redigierung nicht ersetzen, und `display_args_to_stdout` wird nicht als QoL-Abkürzung aktiviert.
   Quelle: [Logging Ansible output](https://docs.ansible.com/projects/ansible/latest/reference_appendices/logging.html)
5. W3C empfiehlt eine Gesamtfehlerliste vor dem Formular, Inlinefeedback, verständliche Korrekturhinweise, Links zu den Controls, `aria-describedby` und Fokus auf das erste fehlerhafte Feld.
   Quellen: [WAI User Notification](https://www.w3.org/WAI/tutorials/forms/notifications/), [WAI Validating Input](https://www.w3.org/WAI/tutorials/forms/validation/), [ARIA21: aria-invalid](https://www.w3.org/WAI/WCAG22/Techniques/aria/ARIA21.html)
6. WCAG verlangt, dass Farbe nicht der einzige Informationsträger ist. Daraus folgt: Badgefarbe wird stets durch Text und bei Bedarf Symbol/Status ergänzt.
   Quelle: [Understanding Use of Color](https://www.w3.org/WAI/WCAG22/Understanding/use-of-color)

Alle Quellen dienen der Absicherung des Designs. Die konkrete Produktsemantik stammt aus den vorhandenen VirtuSphere-Verträgen und den nachgewiesenen Logs, nicht aus einer allgemeinen VMware-Vermutung.

## 15. Definition of Done des Netzwerk-/MAC-Teilvorhabens

Etappe 14A ist nur abgeschlossen, wenn jeder Punkt belegt ist:

- [ ] Die globale Masterplanreihenfolge enthält Etappe 14A und die Create-Plan-Abhängigkeit ist abgeglichen.
- [ ] Genau eine Namens-Key-SSoT und genau eine Netzwerk-Issue-SSoT existieren.
- [ ] Jeder Schreibpfad aus Abschnitt 5 ist positiv, negativ und transaktional getestet.
- [ ] Altbestand wird sichtbar, aber nicht automatisch verändert.
- [ ] Unabhängige VM-Felder bleiben bei unverändertem Legacy-Bundle editierbar.
- [ ] Create, Full, Power-Cycle und Export blockieren betroffene Scope-VMs vor Remote-Arbeit.
- [ ] Start und Autostart warnen, blockieren aber nicht.
- [ ] Queue, Live-Endpunkt, Repo und Worker verwenden denselben Aggregator.
- [ ] Retry verwendet den tatsächlichen Retry-Scope und revalidiert serverseitig.
- [ ] Portal-, ESXi- und unbekannte Ambiguity-Quelle werden korrekt dargestellt.
- [ ] `vm_results` ist additiv persistiert und alte Resultate bleiben lesbar.
- [ ] Ein Worker-Preflight-Block ist als `kind=network_preflight` strukturiert gespeichert; erfolgreicher Create bleibt davon unberührt.
- [ ] Kein Machine-API-, MECM-, Cancel-, Lifecycle- oder Create-Ergebnisvertrag ist gebrochen.
- [ ] VM-Editor, VM-Liste, Deploy-Seite, Jobliste und Jobdetail besitzen die beschriebenen verständlichen Zustände und Aktionen.
- [ ] JS-less, RBAC, Sessionende, stale Response, zwei Tabs, Mobile, Wrap, beide Themes, Keyboard, axe und Screenreader-Stichprobe sind grün.
- [ ] Ansible-Logs sind per VM lesbar, ohne vollständige komplexe Items oder Secrets auszugeben.
- [ ] Joblog-Ende, ältere Seiten und Rohdownload funktionieren über die bestehenden Owner auch bei mehr als 1.500 Zeilen.
- [ ] Alle DE/EN-Kataloge, Hilfen, ADRs, Betriebsdokus, QA und Changelog sind synchron.
- [ ] `scripts/check.ps1 -Lane Fast`, Integration und Release sind grün; Fortschritt wird gemäß Repositoryvertrag sichtbar ausgegeben.
- [ ] Produktionsrevision, Migration, Bestandsaudit, Canary, Retry-Scope und Rollbacknachweis sind dokumentiert.
- [ ] Die drei konkreten VMs können nach fachlich bestätigter VLAN-Korrektur im Export-Retry erfolgreich verarbeitet werden, ohne bereits erfolgreiche VMs neu zu erstellen oder zu powercyclen.

Es gibt in diesem Plan keine offene Produktentscheidung. Wenn bei der Umsetzung ein Fall auftaucht, der eine zweite NIC derselben VM im selben VLAN wirklich voraussetzt, wird Etappe 14A für diesen Fall nicht aufgeweicht. Dafür wird ein getrenntes Identitätsdesign mit ADR, Migration, Rücklesebeweis und eigener Testmatrix erstellt.

---

## 16. Integrierter Befund der Session `019ffafd-2893-7ed1-94f1-d069b6e88174`

### 16.1 Nachgewiesener zweiteiliger Produktionsvorfall

Die geprüfte Session gehört zum selben Repository und enthält den früheren Self-Healing-Plan. Ihr nachgewiesener Laufzeitbefund ist nicht derselbe Fehler wie `ambiguous_vlan`, beide Fehler trafen jedoch dieselbe Deploy-Kette:

1. Der Container `deploy-worker` lief, war aber `unhealthy`. Docker beendete oder startete ihn deshalb nicht neu.
2. Der Wartungs-Reaper setzte Aufträge nach zehn Minuten ohne Jobheartbeat in einen terminalen Datenbankstatus.
3. Für die bereits terminalen beziehungsweise abgebrochenen Jobs 105, 112 und 114 liefen auf dem Ubuntu-Ansible-Host weiterhin `bash`-, `python3`- und `ansible-playbook`-Prozessgruppen mit PPID 1.
4. Erst das gezielte Beenden der drei zuvor identifizierten Prozessgruppen entfernte sie.
5. Ein bei gestopptem Worker eingereihter neuer Auftrag blieb korrekt `queued` und wurde nach dem manuellen Workerstart übernommen. Das beweist Queuepersistenz, aber keine automatische Wiederaufnahme.
6. Job 115 endete anschließend unabhängig davon korrekt als `partial`: 12 MAC-Zuordnungen gelangen, drei schlugen wegen der in Abschnitt 1 belegten doppelten VLAN-Zuordnungen fehl.

Damit bestehen drei getrennte Wahrheiten: Remote-Prozesszustand, fachlicher Jobzustand und MAC-/VM-Ergebnis. Keine davon darf aus einer anderen geraten werden.

### 16.2 Durch die Zusammenführung verbindlich korrigierte Planfehler

Die Herz-und-Nieren-Prüfung hat folgende ältere Annahmen verworfen:

| Frühere Annahme | Fehler | Verbindliche Korrektur |
|---|---|---|
| Reaper terminalisiert; ein späterer Retry pollt dieselbe Create-JID | ein terminaler alter Job und ein neuer Retry konkurrieren um dieselbe aktive Mutation | derselbe aktive Job bleibt `running`/`cancelling`, verliert nur seine Lease und wird wiederangebunden; ein Retry übernimmt nie eine aktive alte JID |
| ein SSH-Befehl pro Playbook sei bereits eine sichere Grenze | SSH-Kanal und Linux-/vSphere-Laufzeit sind getrennt | jeder Schritt erhält vor Mutation ein persistentes DB-Handle, eine systemd-Cgroup und dauerhafte Marker |
| `finally` oder altersbasierter Sweep dürfe Remote-Artefakte entfernen | bei Kanalverlust können diese Dateien der einzige Ergebnisbeweis sein | Cleanup benötigt Terminal-/Reconciliation-Nachweis, Cleanup-Lease und Diagnosefrist |
| `remote exit != 0` bedeute automatisch „keine Mutation“ | Ansible kann vor dem Fehler bereits VMware-Änderungen ausgeführt haben | Controllerresultat und externer Effekt werden getrennt; mutierende Nonzero-/Signal-/Timeoutfälle durchlaufen die Modus-Reconciliation |
| eine monotone Generation in der restaurierten DB verhindere Restore-Kollisionen | ein altes Backup kennt spätere Generationen nicht und kann denselben Zahlenwert erneut erzeugen | jede Restore-/Clone-Aktivierung mintet eine neue kryptografisch zufällige Generation-ID |
| eine beendete transiente Unit bleibe als dauerhafte Wahrheit verfügbar | erfolgreiche Units werden typischerweise rasch entladen; mit `--collect` auch fehlgeschlagene | `started.json`, `result.json` und DB-Handle sind dauerhafte Wahrheit; die Unit ist nur Laufzeitowner |
| `MemoryMax`/`TasksMax` wirken bei jedem User-Manager sicher | Wirkung hängt insbesondere von cgroup-v2/Delegation und Hostkonfiguration ab | diese Limits bleiben aus, bis der Zielhost ihre tatsächliche Enforcement im Harness beweist; Runtime, Cgroup-Ownership und UMask bleiben Pflicht |
| `manual_review` als einzelner Dienststatus reiche | Verfügbarkeit, Pausenwunsch und ungeklärter fachlicher Ausgang können gleichzeitig bestehen | Snapshot führt getrennte Achsen `availability`, `claim_state` und `recovery_attention` |
| Credential-Edit müsse während Recovery vollständig gesperrt sein | dann könnte ein abgelaufenes Secret nicht im Portal repariert werden | Identitätsfelder/Delete bleiben gesperrt; Secretrotation derselben Credential-ID bleibt CAS-/Audit-geschützt möglich |
| Diagnose-Retention dürfe das gesamte Arbeitsverzeichnis halten | `accounts.yml` und andere Eingaben würden Secrets unnötig lange aufbewahren | geheime Eingaben werden nach Controllerende sofort getrennt gelöscht; nur redigierte Evidenz bleibt bis zur Diagnosefrist |

Diese Korrekturen sind Teil des Zielvertrags und keine optionalen Verbesserungsvorschläge.

## 17. Verbindliche Begriffe und getrennte SSoT

| Wahrheit | SSoT | Bedeutung |
|---|---|---|
| fachlicher Auftrag | `deploy_jobs.status`, Ergebnis- und Terminalgrundvertrag aus Masterplan 10B | queued/running/cancelling/succeeded/failed/cancelled/partial |
| Workerberechtigung | globale Worker-Lease plus `worker_epoch`, Job-`lock_token` und CAS | welcher Prozess noch schreiben oder einen neuen Schritt beginnen darf |
| Linux-Controller | `deploy_remote_executions.controller_state` plus aktuelle systemd-Unit | ob Runner/Ansible nie startete, aktiv ist oder wie der Controller endete |
| externer Effekt | `deploy_remote_executions.effect_state` | ob VMware-/Callbackwirkung ausgeschlossen, möglich, live bestätigt oder abweichend ist |
| Reconciliation | `deploy_remote_executions.reconciliation_state` plus append-only Resolution | ob derselbe Lauf beobachtet, automatisch versöhnt oder manuell geklärt werden muss |
| Create-Einheit | `deploy_create_vm_results` aus dem Create-Plan | per-VM-JID, Ziel-ID, UUID/MOID und Create-Ergebnis |
| Netzwerkvertrag | `vm_network_contract.php` | leere beziehungsweise doppelte normalisierte VLAN-Zuordnung |
| MAC-Ergebnis | versioniertes `result_json.vm_results` | Callbackresultat pro VM, einschließlich `ambiguous_vlan` |
| Dienstverfügbarkeit | `deploy_service_health_snapshot().availability` | ready/busy/degraded/cooldown/offline |
| Claim-Freigabe | `deploy_service_health_snapshot().claim_state` | accepting/pause_after_current/paused |
| Recovery-Aufmerksamkeit | `deploy_service_health_snapshot().recovery_attention` | none/recovering/manual_review; `manual_review` wird ausschließlich aus mindestens einer aktiven Remote-Ausführung mit `reconciliation_state=manual_required` abgeleitet und ist kein Persistenzzustand |
| Joberzählung | `deploy_job_logs` | vollständiges redigiertes, sequenzielles Jobprotokoll |
| Betriebsereignis | strukturiertes `deploy_logs` ab Masterplan 10C | historische, filterbare Zustandswechsel; keine Heartbeat-/Pollzeile |
| tatsächlicher VM-Zustand | UUID-/MOID-gebundenes ESXi-/vCenter-Liveinventar | Existenz, Powerstate, Task-/Autostart- und NIC-Beobachtung |

`Recovery` bedeutet Wiederanbindung oder Versöhnung desselben fachlichen Attempts. `Retry` bedeutet einen neuen fachlichen Attempt nach bewiesenem Ausgang. Eine Recovery erhöht `recovery_count`, niemals `attempts`. Ein Remote-Controller mit Exit 0 ist noch kein MAC-Erfolg; `ambiguous_vlan` kann danach korrekt zu `partial` führen.

## 18. Zielarchitektur für dauerhafte Remote-Ausführung

### 18.1 Verantwortungsgrenzen

- MySQL besitzt Jobs, Auswahlpayload, globale Lease/Epoch, Job-Fencing, Remote-Handle, Reconciliationentscheidung, Claim-Pause und persistierte Logsequenz.
- Der Deploy-Supervisor besitzt nur den lokalen Worker-Kindprozess, Restartgrenze, Cooldown und Supervisorheartbeat.
- Der Deploy-Worker besitzt Claimreihenfolge, kurze SSH-Probes, Launch/Poll/Reattach, Logimport, Modus-Reconciliation und genau einmalige Finalisierung.
- Der systemd-User-Manager des dedizierten Ansible-Benutzers besitzt die Linux-Cgroup eines gestarteten Playbookschritts. Transiente Units müssen keinen Hostreboot überleben.
- Der versionierte Remote-Runner besitzt `started`, Heartbeat, bounded Output, Child-Wait und atomisches Controllerresultat.
- Ansible-Async besitzt beim Create die einzelne VM-Operation und ihre JID. Diese JID ist in das generische Remote-Handle eingebettet, kein Ersatz dafür.
- Das Portal schreibt ausschließlich deklarative, CSRF-/RBAC-geprüfte DB-Aktionen. Es erhält weder Docker-Socket, freie SSH-Shell, systemd-DBus noch Worker-Key.

### 18.2 Exakter Ablauf eines Schritts

1. Worker erneuert globale Lease und Joblease und prüft Claim-Pause, Cancel, Scope, Netzwerkvertrag sowie Live-Identität.
2. In einer DB-Transaktion entsteht genau eine `deploy_remote_executions`-Zeile im Zustand `prepared` mit zufälligem 128-Bit-Run-Token, der unverändert aus `deploy_jobs.execution_generation_id` kopierten aktuellen Runtime-Generation, Step-Key, Attempt und deterministischem Unit-/Pfadnamen. Jobgeneration und Singleton müssen beim CAS identisch sein; die Generation wird nicht pro Run neu erzeugt.
3. Erst nach Commit lädt der Worker Manifest, Playbookartefakte und getrennte geheime Eingaben in das exakte Remote-Verzeichnis. Jeder Upload hat maximale Größe und erwartete SHA-256.
4. Der installierte Launcher erhält ausschließlich Manifestpfad und Run-Token als feste Argumente, niemals einen freien Shellstring. Er validiert realen Pfad, Owner, Mode, keine Symlinks, Protokollversion, Checksummen, Instance-/Generation-ID und Step-Policy.
5. Der Launcher sperrt `launch.lock`, prüft vorhandene Unit/Marker/Resultat und startet nur bei bewiesenem Nichtstart die deterministische Unit. Ein verlorener SSH-Response erzeugt deshalb keinen zweiten Lauf.
6. Der Start verwendet ohne PTY, `--pipe`, `--wait` oder freie Umgebungsübernahme sinngemäß `systemd-run --user --unit=<validiert> --property=Type=exec --property=KillMode=control-group --property=UMask=0077 --property=RuntimeMaxSec=<SSoT> --collect --expand-environment=no -- <versionierter-runner> <manifest>`. Für normale Runner, die ihren Child bis zum Ende warten, gilt `ExitType=main`; der spezielle Create-Launch verwendet zusätzlich `ExitType=cgroup`.
7. `XDG_RUNTIME_DIR=/run/user/<uid>` und der User-Bus werden aus der lokal aufgelösten UID gesetzt und auf Owner/Mode geprüft. `loginctl show-user` und `systemctl --user show` werden maschinenlesbar verwendet; formatierte Statusausgabe wird nicht geparst.
8. Der Runner schreibt und `fsync`-t `started.json`, bevor er `ansible-playbook` als Child derselben Cgroup erzeugt. Ein Started-Marker ohne Childstart trägt die Phase `before_child` und beweist weiterhin, dass keine Ansible-Mutation begann.
9. Der Worker speichert `active`, pollt in kurzen begrenzten SSH-Aufrufen Unit, Marker, Resultat und Logoffset. Joblease hängt nicht von Ansible-Ausgabe ab.
10. Der Runner drainiert stdout/stderr fortlaufend, aktualisiert seinen Remoteheartbeat über einen zeitgesteuerten Eventloop auch bei stiller Ausgabe und wartet alle direkten Children. Normale Steps schreiben danach `result.json` über Tempdatei, Datei-/Verzeichnis-`fsync` und atomisches Rename. Ein `per_vm_async`-Create-Launch schreibt dagegen nur `launch_result.json`; dieses bestätigt Launch-Playbook/JID-Erfassung, ist aber ausdrücklich kein terminales Create-Ergebnis.
11. Der Worker validiert Resultat, Token, Manifesthash, Step, Attempt und Generation, importiert den vollständigen Restlog und bestimmt Controller-, Effekt- und Reconciliationzustand getrennt. Bei Create bleibt `controller_state=active`, solange Unit/Cgroup oder JID aktiv ist; erst der validierte terminale `async_status` plus Live-Identität finalisiert die Remote-Ausführung.
12. Erst nach fachlichem Beweis wird der Schritt und danach gegebenenfalls der Job finalisiert. Geheimdateien werden sofort nach bewiesenem Controllerende entfernt; redigierte Evidenz erst nach Abschnitt 22.

### 18.3 Remote-Pfad und Dateivertrag

Der feste benutzereigene Root ist:

```text
~/.local/state/virtusphere/<instance-id>/<current-generation-id>/jobs/<job-id>/<attempt>/<step-key>/<run-token>/
```

Pfadsegmente stammen ausschließlich aus validierten internen IDs. Mission-, VM-, Benutzer- und Portgroupnamen kommen nicht in den Pfad. Root und Verzeichnisse sind `0700`, Dateien `0600`.

| Datei | Dauer | Zweck |
|---|---|---|
| `manifest.json` | Diagnosefrist | unveränderliche Identität, Version, Checksummen, Step, Token, Korrelation; keine Secrets |
| `launch.json` | Diagnosefrist | akzeptierter Controllerstart, Unit und systemd-Startresultat |
| `started.json` | Diagnosefrist | genaue Runnerphase vor/nach Childspawn |
| `heartbeat.json` | bis Cleanup | lokale monotone Aktivität, keine Geschäftsentscheidung |
| `output.log` | Diagnosefrist, bounded/redigiert beim DB-Import | vollständige drainbare Controllerausgabe bis zum SSoT-Limit |
| `result.json` | Diagnosefrist | terminales Controllerresultat normaler Steps: Exitcode, Signal, Laufzeit, Truncation und Manifestbindung |
| `launch_result.json` | Diagnosefrist, nur Create | nichtterminales Ergebnis des Create-Launch-Playbooks einschließlich Markerfingerprint; fachlicher Ausgang kommt ausschließlich aus JID plus Live-Identität |
| `async/` | bis Create-Reconciliation/Cleanup | dediziertes `ansible_async_dir` genau dieser Create-Einheit; enthält höchstens die validierte JID und deren Statusbeweis |
| `secrets/` | nur bis bewiesenem Controllerende | `accounts.yml`/geheime Inputs; getrennt, niemals Diagnose-Retention |
| `cleanup.json` | bis lokaler Cleanupnachweis importiert ist | exakter, idempotenter Bereinigungsnachweis |

Jede JSON-Datei besitzt geschlossenes Schema, maximale Bytezahl und `schema`, `protocol`, `instance_id`, `generation_id`, `run_token`. Unbekannte Version, Zusatzfelder in sicherheitskritischen Objekten, falsche Typen, Token-/Hashabweichung oder Übergröße führen zu `protocol_error`, Sicherheitsereignis und `reconciliation_state=manual_required`; sie werden nicht automatisch gelöscht. Der Dienstsnapshot zeigt daraus `recovery_attention=manual_review`.

### 18.4 systemd- und Launch-Grenzen

- `Type=exec` bestätigt nur den erfolgreichen Exec-Start, nicht fachlichen Erfolg.
- `KillMode=control-group` hält Shell, Ansible und Children in einer adressierbaren Cgroup. Ein Stop des Linux-Controllers beweist trotzdem nicht das Ende einer bereits an VMware übergebenen Aufgabe.
- `ExitType=cgroup` ist für `create.vm.<position>` Pflicht: Die Unit bleibt aktiv, solange der mit `poll: 0` gestartete Ansible-Async-Prozess in ihrer Cgroup lebt, auch wenn der Launch-Playbook-/Runner-Hauptprozess bereits beendet ist. Create/Full bleiben gesperrt, bis ein reales Ansible-2.19-Harness Prozessabstammung, Cgroupmitgliedschaft, Unit-Lebensdauer und JID-Status auf dem Ziel-Ubuntu beweist. Verlässt der Async-Prozess wider Erwarten die Cgroup, wird nicht auf `KillMode=none` ausgewichen; das Protokolldesign muss vor Freigabe korrigiert werden.
- Der Runner schreibt für Create ausschließlich die aus dem validierten Manifest abgeleitete Ansible-Variable `ansible_async_dir=<remote-dir>/async` in die bereits allowlist-validierte, mit `0600` geschützte Extra-Var-Datei. Weder freie CLI-Interpolation noch ein Task-`environment`-Eintrag ist zulässig. Das globale Standardverzeichnis `~/.ansible_async` wird für neue Create-Läufe nicht durchsucht oder gesweept.
- Für normale Steps ist `heartbeat.json` die lokale Runner-Liveness. Beim Create endet diese Erwartung mit validiertem `launch_result.json` und committed JID: Danach sind frische erfolgreiche `async_status`-Beobachtung und Unit/Cgroup-Status die Livenessquellen; ein alter Runnerheartbeat ist allein kein Hang. Ist die Unit aktiv, aber JID/Status nicht beweiskräftig lesbar, bleibt der Effekt `active_or_possible` und Recovery/Reconciliation beginnt, niemals ein zweiter Launch.
- `--collect` verhindert, dass fehlgeschlagene transiente Units unbegrenzt geladen bleiben. Dauerhafte Wahrheit sind deshalb Marker und DB, nicht die spätere Existenz der Unit.
- Findet der Launcher dieselbe aktive Unit oder passende Marker, liefert er deren Identität und startet nichts.
- Ist die Unit entladen, aber `result.json` vorhanden, wird nur das Resultat übernommen.
- Ist `started.json` vorhanden, aber Unit und Resultat fehlen, ist der Ausgang abhängig von `started.phase` mindestens `uncertain`; ein Relaunch ist verboten.
- Fehlen Unit und Started nach einem nachweislich fehlgeschlagenen systemd-Start, darf derselbe deterministische Start erst nach `--collect`/vollständigem Unload und leerer Cgroup erneut versucht werden. Der Launcher dokumentiert genau diesen Pre-Start-Retry; ein neuer Run-Token ist dafür verboten.
- `MemoryMax` und `TasksMax` werden erst als Unit-Properties aktiviert, wenn Ubuntu-Zielhost, cgroup v2, Delegation und tatsächliche Enforcement im Harness grün sind. Nicht unterstützte Limits werden nicht still ignoriert.

## 19. Persistentes Daten-, Fencing- und Zustandsmodell

### 19.1 Additive Datenstruktur

`deploy_jobs` erhält bei der nächsten dann freien Migration mindestens:

- `lock_token CHAR(32) COLLATE ascii_bin NULL`;
- `worker_epoch BIGINT UNSIGNED NULL`;
- `execution_contract VARCHAR(16) NULL` aus der Registry `legacy_v1|remote_v1`;
- `execution_generation_id BINARY(16) NULL`;
- `recovery_count INT UNSIGNED NOT NULL DEFAULT 0`;
- `recovery_reason VARCHAR(32) NULL` aus `remote_observation|legacy_uncertain|foreign_generation`;
- `recovery_requested_at TIMESTAMP NULL`.

Beim ersten Claim eines nach der Migration erzeugten oder noch sicher ungestarteten Queuejobs setzt derselbe CAS `execution_contract` aus dem persistierten Credential-/Modus-Aktivierungsflag und kopiert `current_generation_id` nach `execution_generation_id`; beide Werte sind danach unveränderlich. `legacy_v1` ist für noch nicht umgestellte Modi eine explizite Übergangspolicy, kein Fallback. Ist ein Modus als Remote aktiviert, darf kein neuer Claim dieses Modus `legacy_v1` erhalten. Historische terminale NULL-Werte bleiben lesbar; ein nichtterminaler NULL-Wert wird `legacy_uncertain` und nicht geraten.

`deploy_remote_mode_activations` besitzt den Primärschlüssel `(credential_ansible_id, mode)`, einen FK auf `deploy_credentials(id) ON DELETE CASCADE` und mindestens `state`, `contract_version`, Hostpreflight-/Faultmatrix-Fingerprint, `changed_at`, `changed_by` und Optimistic-Lock-Zeit. `mode` kommt ausschließlich aus `virtusphere_deploy_modes()` einschließlich `inventory`; unbekannte Werte blockieren. `legacy_explicit` verlangt `contract_version=legacy_v1`, `pilot_remote|remote_enabled|rollback_pending` verlangen `remote_v1`, `disabled` hat keinen ausführbaren Vertrag; ein Constraint-/Contracttest koppelt das. Die geschlossene Zustandsmaschine lautet:

```text
legacy_explicit -> pilot_remote -> remote_enabled
legacy_explicit -> disabled
disabled -> pilot_remote
pilot_remote | remote_enabled -> rollback_pending -> legacy_explicit | disabled
```

Migration materialisiert ausschließlich für Credentials vom Typ Ansible jede bekannte Moduskombination als `legacy_explicit`; Repository und Migration prüfen den Typ, es gibt keinen impliziten NULL-Default. Neue Ansible-Credentials verwenden bei `supervisor_contract=worker_v1` ebenfalls `legacy_explicit`, bei `supervisor_v1` dagegen `disabled`. `pilot_remote` und `remote_enabled` ergeben beim Claim `execution_contract=remote_v1`; `legacy_explicit` ergibt `legacy_v1`; `disabled`, `rollback_pending`, fehlende Zeile, unbekannte Revision oder roter Fingerprint blockiert normale Claims sichtbar. Der Übergang nach `pilot_remote` verlangt grünen Hostpreflight und die modusspezifische Faultmatrix; `remote_enabled` zusätzlich das festgelegte Beobachtungsfenster. Rollback setzt zuerst `rollback_pending`, lässt ausschließlich Recovery bestehender `remote_v1`-Jobs zu und darf erst nach vollständigem Drain/Reconciliation wechseln: unter `worker_v1` explizit nach `legacy_explicit` oder `disabled`, unter `supervisor_v1` ausschließlich nach `disabled`. Kein laufender Job ändert seinen Vertrag, und kein Fehlerpfad wählt selbstständig Legacy.

Für zusammengesetzte Modi prüft die Aktivierungs-SSoT zusätzlich jeden von `ansible_playbooks_for_mode()` gelieferten Step-Descriptor. `full` darf erst `pilot_remote` werden, wenn Create sowie alle in seinem tatsächlich konfigurierten Pfad möglichen Folge-Owner für denselben Credential `remote_enabled` sind; ein optional deaktivierter Autostart-Step wird nicht erfunden. Vor Rollback eines abhängigen Owners wechseln `full` und jeder weitere abhängige Modus zuerst nach `rollback_pending` und drainieren. Ein Dependency-Guard läuft über alle Modi/Descriptoren und besitzt Zero-Match-Schutz.

`deploy_remote_executions` enthält mindestens:

- Job-ID, Job-Attempt, Step-Key und Protocol-Version;
- `run_token`, `unit_name`, `remote_dir`, `instance_id`, unveränderliche Kopie der aktuellen `generation_id`;
- `controller_state`, `effect_state`, `reconciliation_state`, `cleanup_state` als `VARCHAR` mit PHP-Registry;
- `launch_intent_at`, `started_at`, `last_observed_at`, `finished_at`;
- Exitcode, Signal, Resultat-SHA, Logoffset, Truncation;
- Recoveryzähler, letzte Probe-Kategorie/-Detail;
- Cleanup-Fälligkeit, Lease, kumulative `cleanup_attempts`, seit der letzten manuellen Freigabe laufende `cleanup_auto_attempts`, letzter Fehler und Abschlusszeitpunkte;
- `created_at`, `updated_at`.

Eindeutig sind `(job_id, job_attempt, step_key)`, `run_token`, `unit_name` und der kanonische `remote_dir`. Für normale Playbooks ist `step_key` der kanonische Step-Key. Der Create-Descriptor besitzt das Ausführungsmodell `per_vm_async` und expandiert ausschließlich über die materialisierte Auswahl in `create.vm.<position>`; Position und VM-ID werden gegen `deploy_create_vm_results` geprüft. DB-Spaltenlängen, PHP-Registry, Runner-Schema und alle Producer/Consumer werden durch Golden-/Bounds-Guards gekoppelt. Die nächste Migrationsnummer wird zu Beginn gegen den realen Arbeitsbaum ermittelt; Stand dieser Prüfung wäre 0041, sie wird aber nicht reserviert.

`deploy_runtime_identity` besitzt genau die feste Zeile `id=1` mit `current_generation_id BINARY(16)`, `supervisor_contract` aus `worker_v1|supervisor_v1`, `created_at`, `rotation_reason` aus der geschlossenen Registry `install|restore|clone` und `rotated_by`. Die API-/Dateidarstellung der Generation ist ausschließlich 32-stelliges kleingeschriebenes Hex. Migration/Fresh-Schema erzeugen sie kryptografisch zufällig und setzen `supervisor_contract=worker_v1`; Laufzeitcode darf beide Werte nie still ersetzen. Restore und Clone rotieren die Generation über den eigenen Offline-Ablauf unter Claim-Pause, ändern aber den gesicherten Supervisorvertrag nicht automatisch. Jede neue Remote-Ausführung kopiert den aktuellen Generationswert unveränderlich in ihre eigene `generation_id`; dadurch ist `remote.generation_id != runtime.current_generation_id` der einzige abgeleitete Fremdgenerationsblocker.

Eine Singleton-Lease `deploy-worker` trägt monotone `epoch`, zufälliges `owner_token`, MySQL-UTC-`lease_until` und Renew-Zeit. Ein Restore verwendet keine fortgezählte DB-Generation, sondern erzeugt vor Claimfreigabe eine neue kryptografisch zufällige `current_generation_id`.

`deploy_recovery_resolutions` ist append-only und speichert bei manueller Klärung Job-ID, nullable Remote-Execution-ID, `resolution_scope=remote_execution|legacy_job`, gewählten Resolution-Code, begrenzte redigierte Begründung/Referenz, Evidence-Fingerprint, Akteur, Zeit und vorherigen Zustand. `remote_execution` verlangt eine zu demselben Job gehörende Remote-ID; `legacy_job` verlangt `remote_execution_id IS NULL` und `deploy_jobs.recovery_reason=legacy_uncertain`. Andere NULL-Fälle sind verboten. Updates oder Deletes sind im Produktpfad verboten.

### 19.2 Vier orthogonale Zustände

Controllerzustände:

```text
prepared -> active -> exited_0 | exited_nonzero | exited_signal | lost_after_start | protocol_error
prepared -> never_started | protocol_error
lost_after_start -> exited_0 | exited_nonzero | exited_signal   (nur durch später validiertes Resultat)
```

Effektzustände:

```text
not_started -> active_or_possible
not_started -> goal_verified | divergence_verified                 (nur unmittelbarer Live-Beweis ohne Mutation)
active_or_possible -> not_started | goal_verified | divergence_verified | unknown
unknown -> goal_verified | divergence_verified
```

`goal_verified` und `divergence_verified` sind innerhalb dieses Runs terminal und speichern den belegten Beobachtungszeitpunkt/Fingerprint. Spätere externe Änderungen schreiben die Historie dieses Runs nicht um. `active_or_possible -> not_started` ist ausschließlich mit einem strukturierten Vor-Mutations-Abbruch plus passender Live-Reconciliation erlaubt; bloßes Nichtfinden eines Tasks genügt nicht.

Reconciliationzustände:

```text
not_required -> pending
pending -> running
pending -> manual_required                                     (Integritäts-/Generationskonflikt vor Probe)
running -> pending                                             (begrenzter Backoff, `next_retry_at` gesetzt)
running -> resolved_success | resolved_failure | manual_required
manual_required -> pending | resolved_success | resolved_failure
```

`resolved_success` und `resolved_failure` sind für denselben Run terminal. Ein neuer externer Driftfall ist ein neues Inventar-/Betriebsereignis und keine rückwirkende Änderung dieser Resolution.

Cleanupzustände:

```text
pending -> eligible -> running -> cleaned | failed
eligible -> pending                                             (Eligibility per CAS verloren)
failed -> eligible                                              (Backoff fällig, Evidenz unverändert)
```

`cleaned` ist terminal. Jeder Pfeil ist eine Repository-CAS; ein Cleanup-Retry darf nur bei unverändertem Evidence-Fingerprint und weiterhin erfüllten Bedingungen aus Abschnitt 22.3 von `failed` nach `eligible` wechseln.

Controllerzustände werden nicht nachträglich in fachlichen Erfolg umbenannt. Die einzige nachträgliche Präzisierung ist `lost_after_start -> exited_*`, wenn später ein vollständig validiertes Resultat desselben Tokens/Manifests erscheint. Ein `exited_nonzero` kann zusammen mit `goal_verified` vorkommen; das Protokoll sagt dann ehrlich, dass der Controller fehlschlug, der gewünschte externe Zustand aber live belegt wurde. Ein `exited_0` kann wegen fehlendem/partiellem Callback trotzdem ein fachlich partielles oder ungeklärtes Ergebnis haben.

### 19.3 CAS- und Fencingvertrag

Jeder mutierende Workerwrite enthält:

```text
job_id + aktiver Jobstatus + locked_by + lock_token + worker_epoch + execution_generation_id = current_generation_id
```

Das gilt für Heartbeat, Logappend, Stepstart, Reconciliationwrite und Finish. Der Worker liest Generation und Epoch beim Leaseerwerb; jede Repository-CAS vergleicht beide mit den aktuellen Singletonwerten. Ein alter Worker darf nach Epoch- oder Generationswechsel weder einen neuen Schritt starten noch Job-/Remotezustand schreiben.

Deploy-Callbacks, die Job-/VM-Ergebnisse mutieren, behalten ihren bestehenden Job-/Mission-/Scope-/Idempotenzvertrag und benötigen kein Worker-Token oder neues Wire-Feld. Für `db_importMAC.php` verlangt das Repository vor dem Write zunächst `job.execution_generation_id = runtime.current_generation_id`. Bei `execution_contract=remote_v1` löst es zusätzlich den erwarteten Export-Callback-Step über den bereits verpflichtenden `job_id` auf und verlangt dieselbe Generation am Remote-Handle; bei `legacy_v1` ist kein Handle erfunden. NULL-Vertrag/-Generation, fremde Generation oder fehlendes Remote-v1-Handle antwortet mit dem bestehenden JSON-Fehlerrahmen und HTTP 409, schreibt keine Lifecycle-/MAC-Daten und auditiert `callback_generation_mismatch` gedrosselt. Eine Recovery derselben aktuellen Generation bleibt zulässig, solange der fachliche Job aktiv ist. Nach Terminalisierung bleiben späte Callbacks ebenfalls abgelehnt und gedrosselt auditiert. Ein nach Restore abgewiesener fremder Callback wird ausschließlich durch Live-Reconciliation ersetzt, nie durch Payloadraten. MECM-Report-/Client-ACK-Endpunkte sind von diesem Deploy-Generationgate ausdrücklich unberührt.

Bei DB-Ausfall gilt fail closed: Ein Worker darf eine bereits gestartete Remote-Unit weiter beobachten beziehungsweise den Kanal verlieren lassen, aber ohne erfolgreiche Lease-/Epochprüfung keinen weiteren mutierenden Schritt starten. Remoteoutput bleibt bounded auf dem Ansible-Host und wird nach DB-Rückkehr ab dem persistierten Offset importiert.

### 19.4 Reaper- und Jobentscheidung

Der Maintenance-Worker erhält keine SSH-/ESXi-Credentials und führt keine Remoteprobe aus. Er entscheidet ausschließlich atomar anhand persistierter Evidenz:

| Staler Job | Persistierte Evidenz | Entscheidung |
|---|---|---|
| `execution_contract=remote_v1`, kein Handle und kein Launch-Intent | der neue Vertrag erlaubt ohne committed Handle keinen Remote-Launch | als bewiesenen Nichtstart terminalisieren |
| `prepared`, kein Launch-/Started-Beweis | Start nicht bewiesen | Lease lösen, Recovery desselben Handles anfordern |
| Controller aktiv/möglich | Mutation oder Read kann laufen | Lease lösen, `recovery_reason=remote_observation` und `recovery_requested_at` setzen, Job aktiv lassen |
| gültiges Resultat, noch nicht importiert | Controller terminal | Recovery anfordern; neuer Worker importiert und finalisiert genau einmal |
| Started, keine Unit, kein Resultat | möglicher Effekt | Reconciliation anfordern, niemals Blindretry |
| Protokoll-/Token-/Generationkonflikt | Integrität ungeklärt | `manual_required`, Sicherheitsereignis, kein Cleanup |
| `execution_contract=legacy_v1` oder historisch NULL, kein Handle | Ausgang eines alten direkten SSH-Laufs nicht beweisbar | Job aktiv lassen, `recovery_reason=legacy_uncertain`, `recovery_requested_at` setzen, Retry sperren, geführte Job-Level-Klärung |

Ein aktiver Recoveryjob bleibt `running`, ein abgebrochener Recoveryjob `cancelling`; beide können vorübergehend keine `locked_by`-Lease besitzen. `repo_sweep_orphaned_deploying_vms()` darf für aktive Jobs, Jobs mit `recovery_requested_at` oder Reconciliation in `pending|running|manual_required` keinen VM-Lifecycle erfinden.

### 19.5 Manuelle Klärung ohne Sackgasse

`reconciliation_state=manual_required` beziehungsweise Job-Level-`recovery_reason=legacy_uncertain` ist kein Endzustand ohne Ausweg. Die Jobdetailseite zeigt dafür `recovery_attention=manual_review` und bietet für `system.config`:

1. `Erneut automatisch prüfen`: idempotente DB-Anforderung, keine SSH-Arbeit im HTTP-Request.
2. `Externe Prüfung dokumentieren`: eigene serverseitig gerenderte Seite mit aktuellem Remote-/VM-Befund, erlaubten modusabhängigen Resolution-Codes, Pflichtbegründung, CSRF, Optimistic Lock und gemeinsamer Bestätigungsmodal.

Eine manuelle Resolution ist nur annehmbar, wenn keine passende Unit/Cgroup als aktiv beobachtet wurde oder der Betreiber die Infrastrukturprüfung ausdrücklich mit Referenz dokumentiert. Sie startet niemals automatisch einen Retry. Für Create ist `resolved_success` nur mit eindeutiger UUID/MOID-/Ziel-ID je betroffener VM zulässig. Für Powercycle müssen Phase, Ausgangspowerstate, aktueller Powerstate und keine bekannte aktive Task belegt sein. `unknown` bleibt blockiert und kann nicht als „failed“ weggeklickt werden. Jede Resolution schreibt den append-only Datensatz und ein strukturiertes Audit.

## 20. Modusspezifische Recovery und Wechselwirkung mit Netzwerk/MAC

Die Step-Policy-Registry wird vollständig aus `ansible_playbooks_for_mode()` geprüft und enthält je Step Mutationstyp, Runtimebudget, Reconciliationowner, Callbackerwartung und Aktivierungsflag. Ein unbekannter Step oder Modus ist ein harter Preflightblocker.

| Schritt | Verbindliche Recovery |
|---|---|
| Inventar | gleiche aktive Unit reattachen; bei bewiesenem Controllerende darf der read-only Abruf neu laufen |
| Export MACs | Resultat und Callback jobgebunden/idempotent übernehmen; bei fehlendem Controllerresultat live erneut read-only inventarisieren; niemals Callback eines anderen Jobs akzeptieren |
| Create | pro materialisierter VM genau ein Remote-Handle `create.vm.<position>` und eine per FK gebundene Ergebniszeile/JID/Ziel-ID/UUID/MOID; Create-Launch-Unit mit `ExitType=cgroup`; `launch_result.json` ist nichtterminal, JID plus Live-Identität sind terminal; nach Kanalverlust dieselbe JID im selben Quelljob pollen oder live versöhnen; niemals namenbasiert neu erstellen |
| Start | UUID-/MOID-gebundenen Powerstate und aktive Task prüfen; nur nach bewiesenem Controllerende fehlende Sollzustände erneut konvergieren |
| Autostart | materialisierte Sollpolicy und Livepolicy key-/UUID-gebunden vergleichen; HA-/Lizenzgate bei jeder erneuten Anwendung prüfen |
| Powercycle | Ausgangspowerstate und per-VM-Phasen `not_started`, `stop_requested`, `stopped_verified`, `start_requested`, `started_verified` persistieren; niemals gesamte Gruppe von vorn wiederholen |
| Full | an letzter bewiesener Step-/VM-Grenze fortsetzen; kein Neustart der gesamten Pipeline |

Zusätzliche Invarianten:

- `Another task is already in progress` ist aktive/unklare Arbeit, kein Retrybeweis.
- Netzwerk-Preflight läuft vor Remote-Prepare/Upload. Ein Netzwerkblocker erzeugt null Unit, null JID und null ESXi-Mutation.
- Eine nachträgliche `ambiguous_vlan`-Antwort nach erfolgreichem Export ist kein Remote-Recoveryfall: Controller kann `exited_0` sein, während das fachliche Ergebnis `partial` ist.
- Ein aktiver Remote-Lauf, gesetztes `recovery_requested_at` oder `reconciliation_state` in `pending|running|manual_required` blockiert Retry stets vor dem Netzwerkblocker. Nach Remote-/Identity-Klärung entscheidet der aktuelle VLAN-Scope.
- Start/Autostart warnen bei Portal-VLAN-Konflikt, bleiben aber nur dann ausführbar, wenn ihre eigene Remote-Recoverypolicy grün und kein anderer Missionsjob aktiv ist.
- Interfaceänderungen bleiben während `running`, `cancelling`, Recovery und manueller Create-Klärung für den betroffenen Scope gesperrt.
- Erfolgreiche VMs eines Partial-Jobs werden weder durch Retry noch Recovery erneut Create-/Powercycle-mutiert.

## 21. Supervisor, Dienstsnapshot und Portal-QoL

### 21.1 PID-1-Supervisor erst in Etappe 14C

Der repository-eigene Supervisor startet genau einen Worker als Kind, reapt ihn, leitet TERM/INT weiter und schreibt unabhängig einen lokalen Supervisorheartbeat. Er startet nie einen zweiten Kindprozess, bevor `waitpid`/Prozessstatus das Ende des alten Kindes bestätigt. Ein unkillbarer Prozesszustand führt zu `degraded/manual infrastructure`, nicht zu parallelem Ersatz.

`supervisor_contract` wechselt nur in einem auditierten 14C-Wartungsfenster per CAS von `worker_v1` nach `supervisor_v1`. Voraussetzungen sind: jede Activation-Zeile jedes aktiven Ansible-Zugangs steht auf `remote_enabled` oder `disabled`; kein aktiver, ungeklärter oder fremdgenerierter Job besitzt `legacy_v1`; Create/Full-Faultgate und Supervisor-Hangtest sind grün; Claim-Pause und Drain sind aktiv. Danach wird PID 1 aus derselben Revision gestartet und erst nach frischem Supervisor-/Kindheartbeat wird die Pause aufgehoben. Der Übergang erfolgt nie automatisch. Ein Rückbau nach `worker_v1` ist nur im umgekehrten kontrollierten Fenster bei vollständig pausierten/drainierten Claims und beendetem Supervisor zulässig; er reaktiviert keinen `disabled`-Modus und ändert keinen Jobvertrag.

Nach mehreren anhand der SSoT-Grenzen bestätigten Kindheartbeatfehlern sendet er TERM, wartet die Frist, sendet gegebenenfalls KILL und startet erst nach Cooldown neu. Der neue Worker reattacht zuerst. Überschreitet die Restartzahl das Fenster, wartet der Supervisor bis `next_retry_at`; keine heiße Schleife. Scheitert der Supervisor selbst fatal, endet PID 1 ungleich null. Docker kann dann gemäß `unless-stopped` neu starten, sofern der Container zuvor mindestens zehn Sekunden erfolgreich lief und nicht manuell gestoppt wurde.

Der Container-Healthcheck prüft Supervisor-Liveness, nicht Queueerfolg. Ein pausierter, aber reaktionsfähiger Supervisor ist `healthy`. Docker-`unhealthy` allein wird nirgends als Neustartbehauptung verwendet.

### 21.2 Gemeinsamer Snapshot mit drei Achsen

`deploy_service_health_snapshot()` ist einzige Quelle für Dashboard, Deployseite, Systemstatus und anonymen Healthendpoint:

- `availability`: `ready`, `busy`, `degraded`, `cooldown`, `offline`;
- `claim_state`: `accepting`, `pause_after_current`, `paused`;
- `recovery_attention`: `none`, `recovering`, `manual_review`;
- `queue`: fällig, geplant, ältester fälliger Job, Momentaufnahme der Position;
- `active`: Job, Mission, Step, Einheit, letzte bestätigte Beobachtung;
- `automation`: nächster Versuch, Recoveryzähler, Pausezustand;
- `maintenance`: fällige/fehlgeschlagene Cleanupanzahl, ältester Fall und nächster Versuch; keine Remote-Pfade im anonymen Snapshot;
- `blockers`: geschlossene Codes, keine bereits gerenderten freien Texte.

Die `availability`-Ableitung besitzt feste Präzedenz. Bei `supervisor_contract=worker_v1` ist der bestehende Workerheartbeat die Prozessquelle: fehlt er über die gemessene SSoT-Stale-Grenze, ist der Dienst `offline`; bei frischem Heartbeat und inkonsistentem/stalem aktivem Job oder bei `claim_state=accepting` mit einem ohne aktiven Job über die gemessene Claim-Grace hinaus fälligen Queuejob `degraded`; bei konsistentem aktivem Job `busy`; sonst `ready`. Bei `supervisor_contract=supervisor_v1` gilt: fehlender/staler Supervisorheartbeat ist `offline`; frischer Supervisor im gespeicherten Restartfenster ohne Kind ist `cooldown`; frischer Supervisor mit fehlendem/stalem Kind, inkonsistentem aktivem Job oder derselben überfälligen Accepting-Queue ist `degraded`; gesundes Kind mit aktivem Job/Recovery ist `busy`; sonst `ready`. Die erste passende Regel gewinnt. Claim-Pause, bloß zukünftige Jobs und ein ausschließlich historischer Fehler verändern `availability` nicht. Der Snapshot trägt denselben Wert als `source_contract`; stimmt die beobachtete Prozessform nicht damit überein, ist der Zustand fail closed `degraded`, niemals erraten.

`recovery_attention` ist ebenfalls geschlossen und priorisiert `manual_review` vor `recovering` vor `none`: Mindestens eine nichtterminal gebundene Remote-Ausführung mit `reconciliation_state=manual_required` oder ein aktiver Job mit `recovery_reason=legacy_uncertain` ergibt `manual_review`; andernfalls ergeben gesetztes `recovery_requested_at`, `reconciliation_state=pending|running` oder ein aktiver Recoveryclaim `recovering`; andernfalls gilt `none`. Terminale Historienzeilen und reine Cleanup-Retries verändern diese Achse nicht.

Ein kompakter Badge wird deterministisch aus allen drei Achsen abgeleitet, aber Detailansichten zeigen alle drei. Dadurch bleiben `busy + pause_after_current` und `offline + manual_review` gleichzeitig wahr und sichtbar. Geplante Jobs vor Fälligkeit machen den Dienst nicht degraded. Ein alter terminaler Historienfall zählt nicht als aktuelle manuelle Aufmerksamkeit; nur ungeklärte aktive Resolutionen tun es.

Der Zustandsübergang der Claim-Achse ist geschlossen: `Nach aktuellem Auftrag pausieren` setzt bei vorhandenem aktivem Job atomar `pause_after_current`, sonst sofort `paused`. Normaler Queue-Claim ist in beiden Zuständen verboten; Recovery-/Reconciliationclaims desselben aktiven Jobs bleiben erlaubt. Nach dessen fachlichem Terminal wechselt der Worker per CAS von `pause_after_current` zu `paused`. `Fortsetzen` setzt per CAS `accepting`; ein gleichzeitig neu entstandener aktiver oder manueller Fall verändert nur die anderen beiden Achsen und geht dadurch nicht verloren. Wiederholte identische Aktionen sind idempotent und erzeugen höchstens einen gedrosselten Audit-/Statuswechsel, keinen Fehlerdialog.

### 21.3 Portalverhalten

- Vor Queue: Der Dienstzustand erklärt `startet sofort`, `wird gespeichert und wartet`, `pausiert`, `Recovery läuft` oder `Dienst offline`. Ein reiner Dienstausfall verhindert das Speichern nicht; fachliche Blocker tun es.
- Nach Queue: Meldung nennt Job-ID, geplant/fällig, momentane Position und Wartegrund, ohne eine Startzeit zu versprechen.
- Jobliste: `Wartet seit/Geplant für`, Grund, Schritt, `n/total`, Recovery und nächster Versuch.
- Jobdetail: fachlicher Status, Controller-/Effekt-/Reconciliationzusammenfassung, `Was passiert als Nächstes?`, sichere Aktion und berechtigter Diagnoseblock. Run-Token werden auch dort nur gekürzt.
- Systemstatus: Karte `Bereitstellungsdienst` mit Supervisor, Kind, Queue, aktivem Job, Remoteausführung, Automatik, Claim-Pause und Remote-Cleanup-Rückständen.
- Dashboard: nur handlungsrelevante Hinweise; keine Warnung für ausschließlich zukünftige Jobs.
- Portalaktionen: `Nach aktuellem Auftrag pausieren`, `Fortsetzen`, `Recovery jetzt prüfen`, `Cleanup erneut versuchen`, `Externe Prüfung dokumentieren`; alle als DB-Aktion, `system.config`, CSRF, Confirmklassifikation und Audit. `Cleanup erneut versuchen` setzt nur bei unveränderter Evidenz den Zustand per CAS wieder auf `eligible` und `cleanup_auto_attempts=0`; der kumulative Zähler bleibt erhalten und der HTTP-Request löscht nichts remote. Kein Force-kill/-release in der ersten Umsetzung.
- Modusfreigaben erscheinen je Ansible-Zugang/Modus mit aktuellem State, geprüfter Runner-/Hostrevision, letzter Abnahme und fehlender Voraussetzung. `Pilot aktivieren`, `Produktiv freigeben` und `Rollback einleiten` benötigen `system.config`, CSRF, Optimistic Lock, einen Confirmtext mit Zugang und Modus sowie Audit; sie schreiben nur die Aktivierungszeile. Der Worker entscheidet die nächste zulässige Transition serverseitig neu. `Rollback einleiten` verspricht keinen Sofortwechsel, sondern zeigt bis zum Drain `rollback_pending`.
- Joblog: Masterplan 10A/13 bleibt Owner für Cursor, `has_more`, terminalen Drain, Older-Pagination, bounded DOM, Rohdownload und Page-Visibility-Catch-up. `role=status aria-atomic=true` meldet Zustandswechsel, nicht jede Zeile.
- Technische Links verwenden ausschließlich `deploy_job_log_url()`, `deploy_job_origin_url()` und `log_category_url()`.

Der anonyme `health.php` bleibt knapp: DB-Ausfall `503`; ein isolierter Hintergrunddienstfehler `200` mit `status=degraded`. Keine Job-, Mission-, Host-, Unit-, Pfad- oder Fehlerdetails werden anonym ausgegeben.

## 22. Security, Credentials, Cleanup, Backup und Restore

### 22.1 Sicherheitsvertrag

- Kein freier Shellstring, keine aus Benutzertext erzeugten Unit-/Pfadnamen und keine ungeprüfte Environmentweitergabe.
- Manifest-/Resultatfelder sind allowlist-, typen- und größenbegrenzt. CR/LF, ANSI, C0/DEL und ungültiges UTF-8 werden vor Persistenz normalisiert.
- `no_log`, zentrale Redaction und der Secret-Sentinel decken generierte Variablendatei, Runneroutput, DB, JSON, HTML, Rohdownload, Audit, PHP-/Containerlog und Fehlerpfade ab.
- Der Launcher prüft `lstat`, realen Root, Owner, Mode und verweigert Symlink/Traversal. Fremde oder manipulierte Verzeichnisse werden weder ausgeführt noch automatisch gelöscht.
- Das Portal erhält weder Docker-Socket noch sudo, systemd-DBus, freie SSH-Konsole oder Deploy-Key.

### 22.2 Credential-Lebenszyklus

Während aktiver Ausführung, bei gesetztem `recovery_requested_at` oder `reconciliation_state` in `pending|running|manual_required` bleiben Delete sowie Änderung von Host, Port, Benutzer, Authentifizierungstyp und Credential-Zuordnung gesperrt. Eine Secretrotation derselben Credential-ID ist erlaubt, wenn diese Identitätsfelder per Optimistic Lock unverändert bleiben; sie wird auditiert und der nächste kurze Probezugriff lädt das neue Secret. Der bereits laufende Remoteprozess benötigt das Portal-Secret nicht erneut. Scheitert Rotation, bleibt der alte Wert atomar erhalten.

Wird Host, Port, Benutzer oder Authentifizierungstyp eines inaktiven Ansible-Credentials später zulässig geändert, setzt dieselbe Transaktion alle zugehörigen Modusaktivierungen auf `disabled`, löscht deren Preflight-/Faultfingerprint und protokolliert die Ursache; kein Modus fällt auf Legacy. Eine reine Anzeigenamen- oder Secretänderung invalidiert die Aktivierung nicht. Ein anschließender Pilot verlangt den vollständigen Hostpreflight gegen die neue Identität.

### 22.3 Cleanup und Retention

Cleanup darf erst laufen, wenn:

1. Controller nicht aktiv ist;
2. Reconciliation nicht pending/running/manual_required ist;
3. Callback-/Create-JID-Vertrag abgeschlossen ist;
4. DB-Resultat, Restlog und Evidence-Fingerprint committed sind;
5. Diagnosefrist abgelaufen ist;
6. exakte Cleanup-Lease per CAS erworben wurde.

Geheime Inputs sind von Punkt 5 ausgenommen und werden nach Punkt 1 sofort entfernt. Jobretention darf die DB-Zeile nicht vor erfolgreichem Remote-Cleanup beziehungsweise bewusst archiviertem Cleanupfehler löschen. Ein Sweep arbeitet ausschließlich auf vom Repository gelieferten exakten Pfaden, begrenzt Einträge/Zeit pro Lauf und berichtet `[n/total] RUN`/Ergebnis, wenn die Menge vorab bekannt ist.

Die generische Registry in `lib/deploy_remote_execution_constants.php` besitzt exakt diese Startwerte; Create-spezifische Aliasnamen sind verboten:

| Konstante | Wert | Bedeutung |
|---|---:|---|
| `VIRTUSPHERE_REMOTE_CLEANUP_BATCH_SIZE` | `25` | maximale vom Worker pro Runde materialisierte Cleanup-Einheiten |
| `VIRTUSPHERE_REMOTE_CLEANUP_MAX_AUTO_ATTEMPTS` | `8` | automatische Versuche seit letzter manueller Freigabe |
| `VIRTUSPHERE_REMOTE_CLEANUP_BACKOFF_MIN_SECONDS` | `300` | erster automatischer Wiederholabstand |
| `VIRTUSPHERE_REMOTE_CLEANUP_BACKOFF_MAX_SECONDS` | `86400` | Deckel des verdoppelten Wiederholabstands |

Jeder tatsächliche Versuch erhöht `cleanup_attempts` und `cleanup_auto_attempts`. Nach acht automatischen Fehlschlägen bleibt `cleanup_state=failed`, `cleanup_retry_at=NULL`, Evidenz und exakter interner Pfad bleiben erhalten, und Systemstatus/Jobdetail zeigen die sichere Aktion `Cleanup erneut versuchen`. Diese Aktion setzt ausschließlich den automatischen Zykluszähler zurück und erteilt per CAS eine neue Retryberechtigung; sie bestätigt weder Erfolg noch umgeht sie Controller-/Reconciliation-/Generation-/Fingerprintprüfungen. Bounds-/Enum-Guards koppeln Registry, Worker, Hilfe und Tests.

### 22.4 Backup/Restore ohne Generationkollision

- Ein Rollback-/Wartungsbackup verlangt Claim-Pause und Drain, bis kein aktiver Lauf, kein gesetztes `recovery_requested_at` und keine Reconciliation in `pending|running|manual_required` besteht.
- Ein automatisches Sicherungsbackup darf bei aktiver Remoteausführung nicht als direkt rollbackfähig bezeichnet werden; es speichert einen Marker `requires_remote_reconciliation` samt nicht geheimem Handleinventar.
- Restore läuft bei gestoppten Workern. Noch bevor irgendein Claim möglich ist, setzt das Restoretool Claim-Pause und mintet eine neue zufällige 128-Bit-Generation-ID. Eine aus der alten DB hochgezählte Zahl ist verboten.
- Alle aus dem Backup restaurierten nichtterminalen Jobs/Remote-Ausführungen mit vorhandener alter Generation behalten sie, erhalten noch unter Claim-Pause `recovery_reason=foreign_generation` und `recovery_requested_at` und bleiben wegen `job/remote.generation_id != runtime.current_generation_id` für normale Claims gesperrt. Ein historischer nichtterminaler NULL-Vertrag/-Generation-Fall erhält stattdessen `recovery_reason=legacy_uncertain`. `foreign_generation` ist ein Job-Level-Recoverygrund und abgeleiteter Blockercode, kein zusätzlicher Remote-Zustand. Keine restaurierte Zeile darf allein deshalb zur neuen Generation umgeschrieben werden.
- Danach inventarisiert ein read-only Drill Units/Verzeichnisse aller alten Generationen und korreliert sie über Instance-ID, Generation, Run-Token, Manifestfingerprint und Job/Attempt. Treffer und Nichttreffer werden angezeigt und einzeln über Reconciliation beziehungsweise manuelle Resolution geklärt, nie pauschal gelöscht, übernommen oder mit der neuen Generation reattacht. Erst wenn jede restaurierte nichtterminale Zeile einen belegten Ausgang besitzt, darf Claim-Pause aufgehoben werden.
- Ein geklontes zweites System benötigt zusätzlich eine neue `VIRTUSPHERE_INSTANCE_ID`; Setup/EnvBoot verweigern gleichzeitigen Betrieb mit ungeklärter Klonidentität. Der Clone-Ablauf rotiert die Generation, setzt Claim-Pause und alle Credential-/Modus-Aktivierungen auf `disabled`; erst neuer Hostpreflight, Faultfingerprint und expliziter Pilot dürfen sie öffnen. Ein aus der Quelle kopiertes `remote_enabled` wird nie weiterverwendet.
- Rückbau beginnt ebenfalls mit Claim-Pause und ist bei aktiver Unit, Create-JID, Reconciliation oder einzigem noch nicht importiertem Remote-Beweis verboten.

## 23. Kombinierte Edge-Case- und Fault-Matrix

| Fall | Verbindliches Sollverhalten |
|---|---|
| zwei Worker/Compose-Replikate | globale Epoch plus Jobtoken verhindert parallelen Claim/Write/Stepstart |
| neuer Ansible-Zugang nach 14C | alle Moduszeilen starten `disabled`; kein Legacy-Claim, bis Pilot/Freigabe grün ist |
| Remote-Modusrollback unter Supervisor | `rollback_pending`, Drain/Reconciliation, danach `disabled`; niemals Legacy-Fallback |
| alter Worker kehrt nach DB-Ausfall zurück | jeder CAS und jeder nächste Step scheitert an Epoch/Token |
| DB fällt während Remote-Run aus | Unit läuft; kein nächster mutierender Step ohne DB-Lease; bounded Remoteoutput |
| SSH-Response beim Launch geht verloren | Launcherlock, Unit/Started/Result verhindern Doppelstart |
| Unit endet und wird gesammelt | persistierte Marker/Resultat übernehmen; keine Abhängigkeit von Unit-Retention |
| Host rebootet vor Childspawn | Started-Phase beweist Nichtmutation; Policy darf sicheren Pre-Start-Fehler behandeln |
| Host rebootet nach Childspawn | möglicher VMware-Effekt, Reconciliation, kein Retry |
| Ansible Exit 2 nach erster Änderung | Controller nonzero, Effekt möglich; Modus-Reconciliation vor Jobterminal/Retry |
| Resultat geschrieben, Worker vor DB-Finish tot | Restlog importieren und genau einmal finalisieren |
| Callback während Recovery | aktiver Job-/Mission-/Scopevertrag nimmt ihn idempotent an |
| Callback nach Terminalisierung | ablehnen und gedrosselt auditieren |
| `Another task is already in progress` | beobachten/reconcile, nicht automatisch scheitern und retryn |
| Cancel während aktivem Step | `cancelling`, keinen neuen Step; laufenden Effekt bis sicherer Grenze beobachten |
| Pause während aktivem Job | verhindert neuen normalen Claim, nicht Recovery; laufender Job bleibt |
| ausschließlich geplante Queue | keine Degraded-Warnung, Fälligkeit getrennt zählen |
| Supervisor-Kind hängt | erst TERM/KILL/waitpid, dann Cooldown/reattach; nie zwei Kinder |
| Kind ist unkillbar/D-State | kein Ersatzkind; Infrastrukturfehler sichtbar, manuelles Runbook |
| Supervisor crasht vor 10 Sekunden | Docker-Restartschutz berücksichtigen; Harness und Runbook zeigen Startfehler, keine falsche Heilungszusage |
| Container manuell gestoppt | `unless-stopped` respektiert Stop; Portalpause ist Normalweg |
| Docker-Daemon/Host down | ehrliche Infrastrukturgrenze; keine Anwendungsselbstheilung behaupten |
| User-Bus/Linger fehlt | harter Hostpreflight, kein Legacy-Fallback |
| cgroup-Controller nicht delegiert | Memory-/Tasks-Limit bleibt deaktiviert; Runtime-/Cgroupvertrag weiter prüfbar |
| Remoteplatte voll | Preflight blockiert Start; Schreibfehler nach Started führt zu Reconciliation |
| Output über Limit | Runner drainiert weiter, ein Truncationmarker, kein Pipe-Deadlock |
| Geheimnis in Ansiblefehler | Sentinel macht Gate rot; kein Release |
| manipuliertes Manifest/Symlink | fail closed, Sicherheitsereignis, manual review, kein Cleanup |
| Cleanup und Recovery gleichzeitig | Cleanup-Lease/State-CAS verhindert Beweisverlust |
| DB-Retention vor Cleanup | FK/Guard blockiert Purge bis Cleanup/Archivnachweis |
| Restore eines alten Backups | neue Zufallsgeneration vor Claim; fremde Generation read-only inventarisieren |
| Callback eines Vor-Restore-Laufs | internes Generationgate antwortet 409 und schreibt nichts; Live-Reconciliation klärt den externen Effekt |
| Credential läuft während Recovery ab | Identitätsfelder gesperrt, Secretrotation derselben ID erlaubt, danach reattach |
| VM/Mission umbenannt | vor Claim zulässig und vom Recheck neu materialisiert; im aktiven Scope bis Terminal gesperrt; Handles korrelieren über IDs und historische Anzeigen verwenden den gespeicherten Namenssnapshot |
| VM gelöscht während aktiv | FK/aktive Guards verweigern Löschung |
| zwei NICs gleiches VLAN im Portal | Save/Queue/Worker/Retry gemäß Abschnitt 3 bis 6 blockiert, keine geratenen MACs |
| gleiche VLAN-Schreibweise mit Case/Whitespace | `esxi_inventory_name_key()` entscheidet; kein Collation-Breitupdate |
| VLAN `"0"` | gültiger nicht leerer Wert; kein Truthinessfehler |
| alle VLANs leer | keine WDS-Fallback-Abweichung für neue/geänderte VM; verständlicher Blocker |
| Reassign kollidiert nur bei einer von vielen VMs | gesamte Aktion ohne Teilwrite abweisen, bounded Fehlerliste |
| Interfaceedit gegen Claimrace | definierte Lockreihenfolge; entweder Edit vor Claim plus Worker-Recheck oder Claim zuerst plus Schreibsperre |
| Remote-Recovery und VLAN-Konflikt gleichzeitig | Retryblocker zeigt Remote zuerst, VLAN zusätzlich; kein Button bis beide geklärt |
| Export Controller 0, drei MACs ambiguous | Remote succeeded, Job partial, genau drei VM-Resultate; kein Remote-Retry nötig |
| erfolgreicher Scope neben drei Fehler-VMs | Retry enthält nur vertraglich fehlgeschlagenen Scope |
| historisches Resultat ohne neue Felder | fail-soft Legacyanzeige, niemals automatische Retrybarkeit raten |
| unbekannter Modus/Step/MAC-Code | fail closed beziehungsweise neutraler technischer Fehler; kein Erfolg/Auto-Retry |
| versteckter Browser-Tab | Polling pausiert, Cursor holt lückenlos nach |
| terminaler Log > 1.500 Zeilen | bis `caught_up`, Older-Pagination und Rohdownload vollständig |
| Sessionablauf/zwei Tabs/stale Pollresponse | keine HTML-Injektion, kein Zurückspringen, serverseitiger Zustand gewinnt |
| Status nur farblich/Heading wrappt | Text/Icon, `role=status`, Fokusvertrag und Geometrietest |

Die Fault-Injection-Suite führt mindestens die 17 Remote-Crashpunkte des früheren Plans plus jeden Netzwerk-/Retry-Race aus. Jeder mehrteilige Lauf besitzt die Repository-Fortschrittszeilen. Mutierende Fälle laufen ausschließlich gegen isolierten Stagingbestand mit UUID/MOID-Nachweis. Jeder Fall prüft zusätzlich: keine zweite Unit, keine zweite JID, keine zweite VM, keine verwaiste Prozessgruppe, kein unzulässiger Terminalstatus und kein Secret.

## 24. Verbindliche Umsetzungspakete und Messregeln

### 24.1 Globale Paketreihenfolge

1. **8R-O-0 Planharmonisierung und Offline-Charakterisierung:** Master-/Create-Widersprüche markieren, vorhandene lokale Revision, Migration, Jobs, Worker, Fixtures und Offline-Artefakte erheben. Nicht erreichbare Host-/Laufzeitwerte werden als 8R-S-Nachweis registriert, nicht geraten. Keine Verhaltensänderung.
2. **8R-O-1 ADR, Protokoll, Runner und Offline-Bundle:** Remote-Protokoll, Launcher/Runner, Golden Vectors, Checksummen, Statepfad und read-only Hostpreflight implementieren. Der Preflight ist am Ziel ausführbar, aber ohne importierten Standortnachweis nutzt ihn kein Produktivjob.

   **Umgesetzt 2026-08-20 (offline):** Der geschlossene v1-Vertrag liegt unter
   `Ansible/runner/` und erlaubt nur die einzeln zugeordneten Schritte
   Inventory, Export, Start, Autostart und Powercycle. Launcher und Runner
   prüfen Identität, abgeleiteten Pfad/Unitnamen, Eigentum, Modi, Symlinks,
   Artefaktgrößen und SHA-256; freie Shell-/Argumentlisten gibt es nicht.
   Marker werden atomar geschrieben, Ausgabe ist vereinigt, begrenzt und gegen
   das zwingende Redaction-Artefakt gefiltert. Installer und Preflight sind im
   Offline-Bundle; der Preflight ist read-only, braucht einen expliziten
   standortfreigegebenen Mindestfreiplatz und kann keinen Modus aktivieren.
   Lokale Golden-/Negativtests sind grün. Nicht umgesetzt und nicht ersetzt:
   Installation, Linger/User-Bus/cgroup-, Ressourcen-, Fault-, Mess-,
   Beobachtungs- und Rückbaunachweise am Ziel; sie bleiben vollständig 8R-S.
3. **8R-O-2 Additives Schema und Fencinggrundlage:** Migration/Fresh-Schema, Remote-Repositories, Runtime-Identität, globale Lease/Epoch, Jobtoken, Claim-Pause, explizite deaktivierte Moduszeilen und report-only Snapshot lokal beweisen. Keine Aktivierung und kein Rollout auf das unbekannte Ziel.

   **Umgesetzt 2026-08-20 (offline):** Migration 0042 und Frischschema
   enthalten nullable Job-Fencingfelder, die einmalige zufällige
   Runtime-Generation, Supervisorvertrag, globale Lease/Epoch mit persistierter
   Claim-Pause, deaktivierte Credential-/Moduszeilen, Remote-Handles und
   append-only Recovery-Resolutionen. Bestehende Ansible-Zugänge werden durch
   die Migration, neue Zugänge atomar bei Erstellung ausschließlich mit
   `disabled`/`NULL` materialisiert. PHP-Registries, Datenbank-Checks und der
   Quellenvertrag halten Zustände, Modusmenge, Fresh-/Migrationsschema,
   Einmal-Generation und report-only Grenze zusammen. Claim, Reaper und
   SSH-Ausführung bleiben absichtlich unverdrahtet: Lease-/Heartbeat-/Grace-
   Werte und die atomare Aktivierung gehören weiterhin 8R-S.
4. **8R-O-3 Deaktivierter Inventarconsumer:** Prepare/Launch/Poll/Reattach/Result/Logoffset/Cleanup und Recoverypfade implementieren; lokale Protokoll-/DB-/Prozess-Faulttests beweisen fail-closed Verhalten. Der Consumer bleibt bis 8R-S unerreichbar.
5. **8R-O-4 Reaper/Recoverygrundlage:** vollständige persistierte Reaper-/Recoverymatrix, VM-Sweep, Legacyfälle, Callbackraces und manuelle Resolution lokal beweisen. Altes Reaperverhalten ändert sich erst atomar mit einer standortfreigegebenen Modusaktivierung.
6. **8R-O-5 Mutierende Policies ohne Aktivierung:** Export, Start, Autostart und Powercycle erhalten getrennte Policy-/Aktivierungs- und Reconciliationowner sowie lokale Faulttests. Alle Zustände bleiben `disabled`; Create/Full bleiben gesperrt.
7. **8R-S Standortabnahme und Aktivierung:** 8R-S-0 übernimmt die echten Host-/Messwerte. Danach werden Inventory, Export, Start, Autostart und Powercycle streng einzeln mit realer Faultmatrix, Beobachtungsfenster und Rückbau freigegeben. Jede Freigabe erhält einen eigenen Commit beziehungsweise ein revisionsgebundenes Standortprotokoll; ohne Repositoryzugriff am Standort wird der importierte Nachweis im nächsten Repositorycommit festgehalten.
8. **13R Portalintegration:** Drei-Achsen-Snapshot, Queue-/Recovery-/Pauseanzeige, Systemstatus, Dashboard, Actions, vollständiger Tail und Barrierefreiheit auf Masterplan 10A bis 13.
9. **14A Netzwerk/MAC:** Pakete A bis H aus Abschnitt 10 unverändert in Reihenfolge, ergänzt um die Remote-/Retry-Präzedenz aus Abschnitt 20.
10. **14B Create:** Create-Plan per VM, JID und Identität umsetzen; Remotehandle um Create-Einheit erweitern; Create/Full-Faultmatrix einschließlich 90-Minuten-EZT-Staginglauf.
11. **14C Supervisor:** erst jetzt PID-1-Supervisor, getrennte Heartbeats, Cooldown und Compose-Health aktivieren.
12. **15 bis 17:** Logfilter/Korrelation, Design/Visuals und Release gemäß Masterplan; Remote-Eventcodes und Resolutionen sind vollständig integriert.

Jedes Paket endet mit Code, Migration/Fresh-Schema soweit betroffen, gezielten positiven/negativen/Zero-Match-Tests, Help/Doku/Logs/Protokolle, vollständigem Diffreview, kanonischen Gates und dem Masterplan-Commit-/Push-Abschluss. Ein Paket wird nicht teilweise als grün markiert.

### 24.2 Werte werden reproduzierbar bestimmt, nicht geraten

- Pro produktivem Step werden mindestens 30 repräsentative Stagingläufe über kleine, mittlere und maximal unterstützte Mission sowie Thin/Lazy/EZT erfasst; Create enthält mindestens einen Lauf in der beobachteten Größenordnung von 90 Minuten.
- `RuntimeMaxSec` darf nie unter dem bereits freigegebenen fachlichen Gesamtbudget liegen und wird auf `max(bisheriges Budget, ceil(maximal beobachtete Laufzeit * 1,5))` gesetzt. Ein Überschreiten bleibt `uncertain`, nicht Blindretry.
- Childheartbeat-Timeout ist mindestens `max(3 * Heartbeatintervall, 2 * maximales Budget eines einzelnen lokalen Probeaufrufs + eine Heartbeatperiode)`. Probeaufrufe bleiben ihrerseits durch bestehende Transportbudgets begrenzt.
- Claim-Grace ist `max(3 * Worker-Loop-Intervall, ceil(p99 der gemessenen Claim-Transaktion * 3) + ein Worker-Loop-Intervall)` und wird mit einem fälligen Einzeljob ohne aktiven Job positiv sowie mit geplantem/pausiertem Job negativ getestet. Erst nach dieser Frist darf die Accepting-Queue `degraded` auslösen.
- Supervisor-Cooldown und Restartfenster werden mit einem Faultlauf aus mindestens drei aufeinanderfolgenden Kind-Hangs validiert; kein Wert wird nur aus Hilfeprosa übernommen.
- Remote-Freiplatzgate deckt mindestens maximales Eingabebundle plus konfiguriertes Outputlimit plus atomische Tempkopie plus 100 Prozent Headroom je zulässigem gleichzeitigen Run. Die globale Lease begrenzt die Gleichzeitigkeit aktuell auf eins.
- `MemoryMax`/`TasksMax` bleiben bis zu einem positiven Enforcementtest aus. Ein bloß akzeptierter systemd-Property-Aufruf genügt nicht; der Harness muss den Grenzfall tatsächlich begrenzen.
- Retention für redigierte Remote-Evidenz wird erst nach gemessenem Maximalvolumen festgelegt, muss aber länger als das längste Job-/Recoverybudget plus Betriebsdiagnosefenster sein. Secretinputs folgen dieser Retention ausdrücklich nicht.

Messprotokoll, Formel, Rohwerte, gewählter Wert, Headroom und Gegenprobe werden als QA-Artefakt dokumentiert. Hilfe liest jeden sichtbaren Grenzwert aus Constants/Defaults; keine Zahl wird dupliziert.

8R-O setzt für standortabhängige Werte keinen Ersatzwert. Bis 8R-S fehlen bleiben produktive Runtime-/Heartbeat-/Claim-Grace-/Freiplatz-/Retentionfreigaben und `MemoryMax`/`TasksMax` deaktiviert. Lokale Grenztests dürfen Implementierungsfehler finden, gelten aber nicht als Messreihe aus diesem Abschnitt.

### 24.3 Ergänzte Datei-/Modulowner

Neue Zielowner zusätzlich zu Abschnitt 4:

- `lib/deploy_remote_execution_constants.php`: Controller-/Effekt-/Reconciliation-/Cleanup-Registry und Step-Policy;
- `lib/deploy_remote_protocol.php`: geschlossene Manifest-/Resultschemas und Validatoren;
- `lib/deploy_remote_execution.php`: Orchestrierung ohne SQL/HTML;
- `lib/deploy_remote_recovery.php`: Modusentscheidung und manuelle Resolutionpolicy;
- `lib/deploy_worker_lease.php`: globale Epoch/Claim-Pause;
- `lib/deploy_runtime_identity.php`: aktuelle Runtime-Generation, Rotation- und Fremdgenerationsblocker ohne SQL/HTML;
- `lib/deploy_worker_supervisor.php`: ausschließlich lokaler Kindprozess;
- `lib/deploy_service_health.php`: drei-achsiger Snapshot aus Verfügbarkeit, Claim-Zustand und Recovery-Aufmerksamkeit;
- `lib/repo/deploy_remote_execution.php`, `deploy_worker_lease.php`, `deploy_runtime_identity.php`, `deploy_recovery_resolution.php`: jeweilige Persistenz;
- `Ansible/runner/virtusphere_remote_runner.py` und `virtusphere_remote_launcher.py`: offline versioniert/checksum-geprüft.

Bestehende öffentliche Require-Pfade bleiben Fassaden. Kein neues PHP-Modul überschreitet 400 physische Zeilen; Owner-Registries/Static-Globs verhindern, dass Splits aus Prüfflächen fallen.

### 24.4 Vollständige Doku-/Hilfematrix

Zusätzlich zu Abschnitt 12 werden im jeweils verursachenden Paket geprüft/angepasst:

- neue ADR: Durable Remote Execution, systemd-User/Linger, Worker-Fencing, Reaper-Recovery, Modus-Reconciliation, Portalpause, manuelle Resolution und Grenzen;
- ADR-0002, -0007, -0017, -0018, -0022, -0030, -0032, -0033, -0036 und -0038 als datierte Ergänzung, historische Aussagen nicht umschreiben;
- `docs/DEPLOYMENT.md`, `INSTALLATION-ANLEITUNG.md`, `operations/offline-install.md`, `deploy-chain.md`, `esxi-inventory.md`, `troubleshooting.md`, `go-live.md`, `backup.md`, `QA.md`, `TESTPLAN.md`, `QUALITY-GATES.md`, `GLOSSARY.md`;
- README nur als kompakter Linkowner; Changelog erst mit tatsächlicher Implementierung;
- Help DE/EN: Deploy, Stack, Systemstatus, Credentials, Missionen, VM-Editor, Logs und gegebenenfalls Settings/Overview;
- Sprachkataloge DE/EN: Deploy, Systemstatus, Dashboard, Logs, Credentials, Missionen, VM-Edit, Common.

Falsche Sätze werden explizit gesucht und entfernt: `unhealthy startet neu`, `Timeout beendet Ansible/ESXi`, `Reaper hat Remoteprozess beendet`, `einfach neu einreihen`, `Worker steht, Jobs laufen einfach weiter`. Troubleshooting beginnt Portal-first und nennt Ubuntu-/PGID-Kills nur als gekennzeichneten Legacy-/Notfallanhang nach exakter Identitätsprüfung.

### 24.5 Gesamtrollout

1. 8R-O führt das read-only lokale Bestands-/Migrationsaudit aus und baut das checksum-geprüfte Standortbundle; fehlende Zielwerte bleiben offen.
2. 8R-O liefert Schema, lesende Anzeige, Hostpreflight, Runner und sämtliche Moduszeilen `disabled`, ohne Zielinstallation oder Aktivierung zu behaupten.
3. 8R-S führt Bestands-/Versionsaudit, produktionsgleiches Air-Gap-Staging, Runnerinstallation und Inventarpilot pro Credential aus; danach Reaper-Recovery nur für Inventar.
4. 8R-S aktiviert Export, Start, Autostart und Powercycle einzeln mit Beobachtungsfenster und Rückbauprobe.
5. Portal 13R und Claim-Pause freigeben.
6. Netzwerk/MAC-14A mit Bestandsaudit und Duplicate-Negativcanary.
7. Create/Full-14B erst nach per-VM-, Remote-, Netzwerk- und 90-Minuten-EZT-Faultgate.
8. Supervisor 14C zuletzt. Ein stiller Kind-Hang muss ohne Duplikat heilen.
9. Masterplan 15 bis 17, vollständige Fast-/Integration-/Release-Lane, Restore-/Rollbackdrill.

Der 8R-2-Schema-/Fencingwechsel ist ein kontrolliertes Ein-Revisions-Fenster: Zuerst keine neuen Claims mehr zulassen und den aktiven Altjob bis Terminal drainieren; Queuezeilen bleiben gespeichert. Danach Deploy- und Maintenance-Worker stoppen, Backup plus `migrate --check` erstellen, additive Migration/Fresh-Schema prüfen und WebAPI, beide Worker sowie Remote-Runner aus exakt derselben Revision ausrollen. `deploy_runtime_identity` wird bei Erstinstallation genau einmal erzeugt, nicht bei jedem Containerstart. Vor dem Neustart darf kein alter Workerprozess mehr leben. Findet der Startcheck dennoch einen nichtterminalen Legacyjob ohne Handle, bleibt Claim-Pause aktiv und der Fall geht in `legacy_uncertain`; er wird nicht automatisch vom neuen Fencingvertrag übernommen. Erst danach startet der Inventarpilot. Ein Mischbetrieb alter/neuer Worker oder Maintenance-Reaper ist verboten.

Sofortiger Rolloutstopp gilt bei gemischter Revision, unbekannter Migration, Legacy-Fallback, Remote-/JID-/VM-Duplikat, aktivem Remotejob mit terminalem DB-Status, Cleanup eines ungeklärten Beweises, falschem Retryscope, Netzwerkblock trotz Remoteaktivität übergangen, Secretfund, rotem Wire-/Schema-/i18n-/CSP-/SSoT-Gate oder einem nicht belegten Hostlimit.

### 24.6 Zusätzliche Primär- und Herstellerquellen der Remote-Architektur

1. Docker dokumentiert, dass Restart Policies auf Containerende reagieren, `unless-stopped` einen manuellen Stop respektiert und die Policy erst nach mindestens zehn Sekunden erfolgreichem Start greift. Daraus folgt: `unhealthy` ist Diagnose, kein Restarttrigger; Supervisor und Infrastrukturgrenze müssen getrennt bleiben.
   Quelle: [Docker: Start containers automatically](https://docs.docker.com/engine/containers/start-containers-automatically/)
2. Docker-Healthchecks liefern einen Gesundheitszustand und begrenzte Diagnoseausgabe, aber keinen fachlichen Jobstatus.
   Quelle: [Docker: Healthchecks](https://docs.docker.com/engine/containers/run/#healthcheck)
3. `systemd-run` erstellt transiente Services; `Type=exec` bestätigt erfolgreichen Exec-Start, `--collect` entlädt auch fehlgeschlagene Units und Umgebungs-/Dollar-Expansion ist ohne Gegenmaßnahme aktiv. Daraus folgen `Type=exec`, `--collect`, `--expand-environment=no` und die Marker- statt Unit-Retention-SSoT.
   Quelle: [systemd-run](https://github.com/systemd/systemd/blob/main/man/systemd-run.xml)
4. `ExitType=cgroup` hält eine Service-Unit aktiv, solange irgendein Prozess ihrer Cgroup lebt. Daraus folgt der zwingende Create-Harness für Ansible `poll: 0`.
   Quelle: [systemd.service: ExitType](https://github.com/systemd/systemd/blob/main/man/systemd.service.xml)
5. `KillMode=control-group` beendet bei Unitstop alle verbleibenden Cgroup-Prozesse; `process` und `none` lassen Prozesse dem Lifecycle entkommen. Daraus folgt: kein PID-Datei-/`KillMode=none`-Fallback.
   Quelle: [systemd.kill: KillMode](https://github.com/systemd/systemd/blob/main/man/systemd.kill.xml)
6. `enable-linger` startet den User-Manager beim Boot und hält ihn nach Logouts. Daraus folgt der Offline-Installer-/Preflightvertrag; ein Hostreboot beendet dennoch transiente Units und verlangt Marker-/Live-Reconciliation.
   Quelle: [loginctl](https://www.freedesktop.org/software/systemd/man/252/loginctl.html)
7. systemd delegiert Resourcecontroller an User-Manager abhängig von der Cgrouphierarchie/-konfiguration. Daraus folgt: Memory-/Taskgrenzen werden auf tatsächliche Enforcement und nicht nur akzeptierte Properties geprüft.
   Quelle: [systemd.resource-control](https://github.com/systemd/systemd/blob/main/man/systemd.resource-control.xml)
8. Ansible hält synchron die Verbindung offen; `poll: 0` liefert eine JID, räumt den Cache nicht automatisch und kann bei zu knappem Async-Budget den Statusbeweis verlieren. Daraus folgen persistierte JID/Deadline, explizites Cleanup und kein Parallelstart.
   Quellen: [Ansible asynchronous actions and polling](https://docs.ansible.com/projects/ansible-core/devel/playbook_guide/playbooks_async.html), [ansible.builtin.async_status](https://docs.ansible.com/projects/ansible/latest/collections/ansible/builtin/async_status_module.html)
   Der POSIX-Shell-Pluginvertrag führt `async_dir` als Konfigurationswert mit `ANSIBLE_ASYNC_DIR` beziehungsweise `ansible_async_dir`. VirtuSphere verwendet pro Handle ausschließlich `<remote-dir>/async`; ein Task-`environment`-Eintrag ist nicht der Konfigurationsvertrag.
   Quelle: [ansible.builtin.sh: async_dir](https://docs.ansible.com/projects/ansible/latest/collections/ansible/builtin/sh_shell.html)
9. SSH-Channelclose, Signal und Exitstatus sind getrennte Protokollereignisse. Daraus folgt: Transportverlust ist kein Prozess- oder Geschäftsbeweis.
   Quelle: [RFC 4254](https://www.rfc-editor.org/rfc/rfc4254.html)
10. Broadcom dokumentiert Fälle, in denen ein vCenter-Timeout gemeldet wird, während die Aufgabe auf ESXi weiterläuft, und ein Folgeversuch an einer noch aktiven Aufgabe scheitert. Daraus folgt: VMware-Timeout/`Another task is already in progress` verbieten Blindretry.
    Quelle: [Broadcom KB 310967](https://knowledge.broadcom.com/external/article?legacyId=1004790)
11. OWASP verlangt Kontext, Sanitizing, Zugriffsschutz und Geheimnisausschluss für Logs. Daraus folgen geschlossene Eventcodes, bounded Context, Redaction, CR/LF-Normalisierung und RBAC.
    Quelle: [OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html)
12. W3C verlangt programmatisch erkennbare Statusmeldungen; die Page-Visibility-Spezifikation liefert den Zustand für pausiertes Hintergrundpolling.
    Quellen: [WCAG 2.2 Status Messages](https://www.w3.org/TR/WCAG22/#status-messages), [WHATWG Page Visibility](https://html.spec.whatwg.org/multipage/interaction.html#page-visibility)

Die Herstellerquellen stützen Mechanik und Grenzen. Die konkrete VirtuSphere-Zustandsmaschine bleibt aus Repositoryverträgen, Produktionsbeweisen und den in diesem Plan festgelegten SSoT abgeleitet.

## 25. Kombinierte Definition of Done

Der Gesamtplan ist erst abgeschlossen, wenn zusätzlich zu Abschnitt 15 alle Punkte belegt sind:

- [ ] Masterplan enthält 8R, 13R, 14A, 14B und 14C in der Reihenfolge aus Abschnitt 24.
- [ ] Der frühere Self-Healing-Plan ist als Reviewspur markiert und der Create-Plan enthält keine widersprechende Reaper-/Retry-/Cleanupregel mehr.
- [ ] Jeder Remote-Schritt besitzt vor Mutation DB-Handle, Run-Token, Unit/Cgroup und dauerhafte Marker.
- [ ] Controller-, externer Effekt-, Reconciliation- und Cleanupzustand sind getrennt und exhaustiv getestet.
- [ ] Worker-Epoch und Jobtoken fencen jeden mutierenden Write und nächsten Step.
- [ ] Jeder Job besitzt vor Ausführung unveränderlichen `execution_contract` und `execution_generation_id`; jede Credential-/Moduszeile ist explizit Legacy, Pilot, Remote, Rollback-pending oder disabled.
- [ ] Reaper terminalisiert keinen möglicherweise aktiven oder ungeklärten Remotejob.
- [ ] Worker-/SSH-/DB-/Containerverlust reattacht denselben Run oder eskaliert ehrlich; kein Blindretry.
- [ ] Nonzero-/Signal-/Timeout eines mutierenden Steps wird nicht als Nichtmutation interpretiert.
- [ ] Restore mintet vor Claim eine zufällige Generation und inventarisiert fremde Generationen read-only.
- [ ] Secretinputs werden früher als Diagnoseevidenz bereinigt; Cleanup/Retention verlieren keinen Beweis.
- [ ] Manuelle Klärung ist sicher erreichbar, append-only auditiert und kann `unknown` nicht wegklicken.
- [ ] Verfügbarkeit, Claim-Freigabe und Recovery-Aufmerksamkeit werden als drei Achsen aus genau einem Snapshot dargestellt.
- [ ] Supervisor startet erst nach Durable-Freigabe aller produktiven Modi und nie vor bestätigtem Ende des alten Kindes neu.
- [ ] Unter `supervisor_v1` sind Legacy-Claims und neu angelegte implizit aktive Modi technisch ausgeschlossen.
- [ ] Inventar, Export, Start, Autostart, Powercycle, Create und Full besitzen je eine grüne Recovery-/Faultpolicy.
- [ ] Create-JID, UUID/MOID und VM-Ergebnis sind an dasselbe Job-/Attempt-/Remotehandle gebunden.
- [ ] Netzwerk-/MAC-Blocker, Remote-/Identity-Blocker und Retry-Presenter besitzen die feste Präzedenz aus Abschnitt 6.3.
- [ ] Job 115 als Fixture ergibt Remote-Erfolg plus fachliches `partial` mit genau drei `ambiguous_vlan`, ohne Zustandsvermischung.
- [ ] Kein terminaler Job hinterlässt eine von VirtuSphere kontrollierte Linux-Prozessgruppe; ein noch laufender vSphere-Task wird als ungeklärt sichtbar gehalten.
- [ ] Queue, Pause, Recovery, Cooldown, Offline und Manual Review sind ohne Ubuntu-Zugriff verständlich und handlungsfähig.
- [ ] Alle Quellen-, SSoT-, Bounds-, Migration/Fresh-Schema-, Wire-, RBAC-, CSRF-, Confirm-, Deep-Link-, CSS-, i18n-, Doku- und Air-Gap-Gates sind grün.
- [ ] Alle Fault-, Browser-, Accessibility-, Restore-, Rollback- und produktionsgleichen Stagingdrills sind grün und enthalten die vorgeschriebenen Fortschrittszeilen.

Es bleiben bewusst nur physikalische Infrastrukturgrenzen: Ein gestoppter Docker-Daemon, ein ausgefallener Host, zerstörtes MySQL oder eine bereits an VMware übergebene nicht beobachtbare Aufgabe kann nicht durch Code im gestoppten Container magisch repariert werden. Das Portal benennt diese Grenze; es leitet daraus weder Erfolg noch sicheren Retry ab.
