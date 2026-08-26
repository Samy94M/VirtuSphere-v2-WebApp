# Umsetzungsplan: exakte ESXi-Namen, eindeutige WDS/PXE-Schnittstelle und ehrliche Deploy-Vorabprüfung

Stand: 26.08.2026

Status: entscheidungsreifer Plan; Fakten geprüft; keine Produktimplementierung in diesem Arbeitslauf

## 0. Geltung, Einordnung und Vorrang

Dieser Plan korrigiert und ergänzt den Netzwerk-/MAC-Teil der Etappe 14A aus
`docs/audits/2026-08-13-mac-import-vlan-ambiguity-qol-implementation-plan.md`.
Er erfindet keine zweite globale Ausführungsreihenfolge.

Bei Widersprüchen gilt ohne Auslegungsspielraum:

1. `docs/audits/2026-08-11-deploy-reliability-master-plan.md` bleibt die SSoT für
   Etappenreihenfolge, Etappenabschluss, QA-Lanes und Rolloutreihenfolge.
2. Der Plan vom 13.08.2026 bleibt Fach-SSoT für Remote-Recovery, den allgemeinen
   VM-Netzwerkvertrag, MAC-Retry, per-VM-Ergebnisse und die Etappe 14A insgesamt.
3. Diese Datei ist die korrigierende Fach-SSoT für genau diese Teilbereiche:
   - Gleichheit und Ähnlichkeit von ESXi-Objektnamen;
   - case-sensitive Inventarhaltung;
   - Datacenter-, Datastore- und Portgruppenabweichungen;
   - Speicherbewertung bei abweichender Schreibweise;
   - die Missions-WDS/PXE-Portgruppe;
   - die Genau-eine-WDS-Schnittstelle-Regel;
   - deren Modus-, Auswahl-, Queue-, Worker- und Callbackvertrag;
   - die zugehörige Portal-, Help-, Doku- und Fehlertaxonomie.
4. Die bisherige Aussage, `esxi_inventory_name_key()` definiere die operative
   Namensgleichheit durch `trim` plus Kleinschreibung, ist in diesen Bereichen
   verworfen. Case-Folding darf nach Umsetzung nur noch ähnliche Schreibweisen
   für eine Diagnose finden. Es darf nie Erfolg, Vorhandensein, Speicherplatz,
   MAC-Zuordnung oder Deployfähigkeit beweisen.
5. Die allgemeine Regel aus dem Plan vom 13.08.2026, dass leere oder innerhalb
   einer VM doppelte Portal-Portgruppenzuordnungen für Create-/Exportpfade nicht
   geraten werden, bleibt bestehen. Die neue WDS-Regel ist zusätzlich und besitzt
   eigene Codes, Meldungen und eine eigene Modusableitung.

Die Umsetzung beginnt erst an dem im Masterplan vorgesehenen Einhängepunkt nach
Etappe 14. Sie wird als ein zusammengehöriger Release-Hunk aktiviert. Es ist
verboten, nur die Portalwarnung auszuliefern, während Cache, Queue, Worker oder
MAC-Callback noch die alte Semantik verwenden.

## 1. Fest entschiedene Produktregeln

Folgende Punkte sind entschieden und keine offenen Fragen mehr:

1. `deploy_missions.wds_vlan` bezeichnet fachlich die für WDS/PXE vorgesehene
   Portgruppe der Mission.
2. Die sichtbare Portalbezeichnung lautet künftig **WDS-Portgruppe (PXE)**.
   Der interne Feldname `wds_vlan` bleibt aus Kompatibilitätsgründen unverändert.
3. Eine VMware-Portgruppe wird durch ihren exakten Namen adressiert. Nach der
   bereits zentralen Eingabetrimmung müssen Groß-/Kleinschreibung und alle
   verbleibenden Zeichen übereinstimmen.
4. `Daten` ist deshalb nicht derselbe Datastore wie `DATEN`. `WDS-VLan` ist
   nicht dieselbe Portgruppe wie `WDS-VLAN`.
5. Bei `full`, `powercycle` und `export` muss jede tatsächlich zum Auftrag
   gehörende VM genau eine Portal-Netzwerkkarte besitzen, deren Portgruppenname
   exakt der Missions-WDS/PXE-Portgruppe entspricht.
6. Bei `create`, `start` und `autostart` ist derselbe WDS-Befund sichtbar, aber
   allein wegen dieser speziellen WDS-Regel kein Blocker.
7. Eine fehlende, veraltete oder nur ähnlich geschriebene Portgruppe im
   gecachten Hostinventar bleibt eine Warnung. Der Inventar-Cache ist ein
   read-only Snapshot und darf einen Auftrag nicht als Live-Autorität blockieren.
8. Der deterministische Mission-/VM-Befund ist dagegen ein harter Blocker in den
   drei MAC-Importmodi. Er stammt aus der aktuellen Portaldatenbank und ist keine
   unsichere Inventarannahme.
9. Eine Änderung der Missions-WDS/PXE-Portgruppe schreibt vorhandene
   VM-Netzwerkkarten niemals automatisch um.
10. Das Portal darf aus dem Namen einer Portgruppe nicht behaupten, dass dort
    aktuell ein DHCP-, WDS- oder PXE-Dienst antwortet. Es kann nur die deklarierte
    Konfiguration und den gecachten ESXi-Bestand prüfen.
11. Ein MAC-Callback darf eine VM nur dann auf `deployed/pending` und den
    Legacy-Status `3/5 Deployed` setzen, wenn die WDS/PXE-Schnittstelle der VM
    eindeutig identifiziert wurde und deren gültige MAC Teil des atomar
    committeten VM-Ergebnisses ist.
12. Es gibt keine automatische Korrektur, kein stilles Case-Folding, keine Wahl
    der ersten NIC und keinen Fallback auf irgendeine NIC mit MAC-Adresse.

## 2. Verifizierter Ist-Zustand

### 2.1 Produktionsbeleg

Deploy-Auftrag 311 scheiterte am 26.08.2026 mit
`Invalid datastore format 'Daten'`. Der Zielhost meldete den Datastore als
`DATEN`, die Mission speicherte `Daten`.

Das Portal bewertete die Namen über `esxi_inventory_name_key()` als gleich und
verwendete dadurch den freien Speicher von `DATEN` für den geplanten Datastore
`Daten`. Die Anzeige gab somit eine sachlich falsche Zusage, bevor
`community.vmware.vmware_guest` mit dem abweichenden Namen scheiterte.

### 2.2 Nachgewiesene interne Ursachen

1. `repo/esxi_inventory_cache.php` definiert
   `esxi_inventory_name_key()` als `mb_strtolower(trim($name))`.
2. Inventardeduplizierung, Präsenz, Abweichungsanalyse und Speicherzuordnung
   verwenden denselben case-insensitiven Schlüssel.
3. `deploy_esxi_inventory.name` liegt unter `utf8mb4_unicode_ci` und besitzt den
   Unique-Key `(credential_id, kind, name)`. Case-Varianten können deshalb nicht
   getrennt gespeichert werden.
4. Auch `deploy_vlan.vlan_name` besitzt einen case-insensitiven Unique-Key.
5. `deploy_interfaces.vlan`, die Missionsfelder und die VM-Overrides liegen
   ebenfalls unter der tabellenweiten case-insensitiven Collation. Ein einfaches
   SQL-`=` beweist dort keine exakte VMware-Namensgleichheit.
6. `inventory_select_field()` und `vlan_select_field()` vergleichen bei der
   Darstellung bereits exakt. Deshalb kann der Editor „nicht im aktuellen
   Inventar“ zeigen, während die Warn- und Speicherlogik denselben Wert als
   vorhanden behandelt.
7. `esxi_inventory_mission_missing_by_credential()` prüft heute die gesamte
   Mission. Es berücksichtigt weder die ausgewählten VMs noch den Deploymodus.
8. Die Hostwarnung reduziert Mission, VM-Overrides und VM-Interfaces auf eine
   namenlose Werteliste. Ein Operator kann nicht erkennen, ob Mission,
   VM-Datastore, VM-Datacenter oder eine bestimmte Netzwerkkarte gemeint ist.
9. `ansible_vm_needs_mac()` sucht bereits exakt nach der Missions-WDS-Portgruppe.
   Findet es keine passende Schnittstelle, liefert es lediglich `true`. In der
   VM-Auswahl erscheint dadurch das mehrdeutige Badge „MAC fehlt“.
10. `ansible_vm_interfaces()` überspringt leere Interface-Portgruppen. Bei einer
    vollständig leeren Liste erfindet es als Legacy-Fallback eine NIC aus dem
    Missions-WDS-Feld. Der ältere Netzwerkplan sieht die Entfernung dieser
    Portal-/Artefaktabweichung bereits vor.
11. `mac_import_build_plan()` verbindet ESXi-NICs über
    `(vm_id, reported summary)` mit `deploy_interfaces.vlan`. Die SQL-Abfrage
    läuft unter case-insensitiver Collation.
12. `mac_import_finalize_plan()` betrachtet eine VM als erfolgreich, wenn sie
    keine Fehler und mindestens irgendein Interface-Update besitzt. Es verlangt
    nicht, dass die Missions-WDS-Schnittstelle Teil dieser Updates ist.
13. `db_importMAC.php` setzt jede so als erfolgreich klassifizierte VM atomar
    auf `lifecycle_state=deployed`, `mecm_sync_state=pending`,
    `vm_status=3/5 Deployed` und `updated=1`.
14. `mac_import_result_contract()` persistiert heute die detaillierten
    `vm_results` nicht, obwohl `mac_import_finalize_plan()` sie bereits erzeugt.
    Der gespeicherte Vertrag enthält Fehlerobjekte, der heutige Decoder gibt
    jedoch nur Outcome, IDs und Counts an seine Leser weiter.

### 2.3 Bereits korrekte oder zu bewahrende Grenzen

1. Formulare trimmen Objektname-Strings zentral über
   `Validator::optionalString()`. Diese Trimmung bleibt bestehen.
2. Die VM-Auswahl ist bereits fest definiert: Eine explizite `vm_ids`-Liste ist
   der Scope; eine leere Liste bedeutet die gesamte Mission.
3. `ansible_playbooks_for_mode()` ist die SSoT der realen Modussequenz.
4. `full`, `powercycle` und `export` enthalten den Export-Schritt. Nur diese
   Modi erwarten ein MAC-Resultat.
5. `db_importMAC.php` schreibt Interface-, Identitäts-, VM-Status- und
   `result_json`-Änderungen in einer gemeinsamen äußeren Transaktion. Diese
   Atomarität bleibt erhalten.
6. Der Maschinen-API-Payload `{mission_id, job_id, results}` und die fünf
   Legacy-Statusstrings bleiben unverändert.
7. Der ESXi-Inventar-Cache bleibt warn-only. Unbekannt bedeutet unbekannt und
   wird weder als vorhanden noch als nicht vorhanden behauptet.
8. Portaltexte bleiben DE/EN-paritätisch und verwenden echte deutsche Umlaute.

## 3. Online verifizierte externe Fakten

1. Die offizielle Dokumentation von
   [`community.vmware.vmware_guest`](https://docs.ansible.com/projects/ansible/latest/collections/community/vmware/vmware_guest_module.html)
   sagt ausdrücklich, dass VMware-Objektnamen case-sensitive sind. Sie bezeichnet
   `networks[].name` als Namen der Standard- oder Distributed-Portgruppe.
2. Dieselbe Dokumentation sagt, dass der Top-Level-Parameter `datastore` Vorrang
   vor `disk[].datastore` besitzt. Der Portalwert muss deshalb exakt den
   tatsächlich adressierten Top-Level-Datastore treffen.
3. Broadcom dokumentiert bei nicht erreichbaren Portgruppen nach Clone/Migration,
   dass Portgruppennamen case-sensitive und mit identischem Label vorhanden sein
   müssen:
   [Broadcom KB 308799](https://knowledge.broadcom.com/external/article?legacyId=2003726).
4. Die offizielle
   [`vmware_guest_network`-Dokumentation](https://docs.ansible.com/projects/ansible/latest/collections/community/vmware/vmware_guest_network_module.html)
   zeigt, dass VMware technische NIC-Merkmale wie Label, MAC, Network-Name,
   Switch und Unit-Nummer liefern kann. VirtuSphere persistiert gegenwärtig aber
   keinen stabilen ESXi-Geräteschlüssel pro Portal-Interface. Deshalb darf der
   aktuelle Workflow weiterhin nicht nach Reihenfolge oder „erstem Treffer“
   raten.
5. Microsoft beschreibt, dass Configuration Manager bei PXE-Geräten die
   anfragende MAC beziehungsweise SMBIOS-Identität gegen seine Gerätedatenbank
   abgleicht:
   [Use PXE for OSD](https://learn.microsoft.com/en-ie/mem/configmgr/osd/deploy-use/use-pxe-to-deploy-windows-over-the-network).
6. Microsoft beschreibt außerdem, dass DHCP und der PXE-fähige Distribution
   Point im selben VLAN erreichbar sein oder über IP Helper verbunden werden
   müssen:
   [Troubleshoot PXE boot issues](https://learn.microsoft.com/en-us/troubleshoot/mem/configmgr/os-deployment/troubleshoot-pxe-boot-issues).
   Daraus folgt keine Portaldetektion eines laufenden Dienstes. Es bestätigt nur,
   dass die richtige Netzwerkanbindung für PXE fachlich relevant ist.
7. MySQL 8.4 dokumentiert, dass Vergleiche nichtbinärer Strings ihrer Collation
   folgen und eine binary beziehungsweise case-sensitive Collation für
   case-sensitive Vergleiche erforderlich ist:
   [MySQL 8.4 Case Sensitivity](https://dev.mysql.com/doc/refman/8.4/en/case-sensitivity.html).
8. W3C empfiehlt verständliche Gesamt- und Inlinefehler, Links zum betroffenen
   Control, `aria-describedby` und Fokus auf das erste fehlerhafte Feld:
   [WAI User Notification](https://www.w3.org/WAI/tutorials/forms/notifications/).

Herstellerquellen bestimmen externe Mechanik und Grenzen. Die konkrete
VirtuSphere-Modus-, Status- und Retrysemantik stammt ausschließlich aus den
Repositoryverträgen und den oben belegten Laufzeitbefunden.

## 4. Verbindliche Begriffe

| Begriff | Exakte Bedeutung |
|---|---|
| ESXi-Objektname | Getrimmter, ansonsten unveränderter Name eines Datacenters, Datastores oder einer Portgruppe |
| exakter Treffer | PHP-Stringgleichheit `===` der getrimmten Werte und case-sensitive DB-Semantik |
| ähnliche Schreibweise | Kein exakter Treffer, aber gleiche Diagnoseform nach `mb_strtolower`; nur für einen Reparaturhinweis |
| WDS-Portgruppe (PXE) | Der in `deploy_missions.wds_vlan` deklarierte Portgruppenname |
| WDS-Schnittstelle | Portal-Interface einer VM, dessen Portgruppenname die Missions-WDS-Portgruppe exakt trifft |
| WDS-bereit | Mission besitzt eine WDS-Portgruppe und die VM besitzt genau eine exakte WDS-Schnittstelle |
| PXE-MAC | MAC der eindeutig ermittelten WDS-Schnittstelle |
| Hostinventar | Letzter erfolgreicher read-only Snapshot eines ESXi-Zugangs, keine Live-Autorität |
| Blocker | Deterministischer Befund, der Queue und serverseitigen POST ablehnt |
| Warnung | Sichtbarer Befund ohne Queue-Sperre |

Die sichtbaren Texte verwenden „Portgruppe“. Das interne Schemafeld `vlan` und
das Maschinen-/Ansible-Wire bleiben unverändert, soweit dieser Plan nicht die
Collation, sondern nur den Feldnamen betrifft.

## 5. Neue SSoT-Aufteilung für Namen

### 5.1 Exakte operative Gleichheit

Es entsteht genau ein fokussierter Helper-Owner für ESXi-Objektnamen, geplant als
`Docker/WebAPI/lib/esxi_object_names.php`.

Er besitzt ausschließlich:

1. Eingangsnormalisierung durch `trim`, identisch zur bestehenden Validierung;
2. exakte Gleichheit;
3. einen Diagnosekey für ähnliche Schreibweisen;
4. die Klassifikation eines konfigurierten Werts gegen eine Menge von
   Inventarnamen.

Die Klassifikation ist geschlossen:

```text
exact
case_mismatch
missing
inventory_unknown
```

`case_mismatch` trägt alle tatsächlich gemeldeten ähnlichen Kandidaten. Gibt es
mehr als einen, wird keiner als „der richtige“ vorgeschlagen. `missing` wird nur
verwendet, wenn eine bewertbare Inventarmenge existiert und weder exakter noch
ähnlicher Kandidat vorkommt. Ein nicht bewertbarer Cache liefert
`inventory_unknown`.

### 5.2 Verbotene Verwendungen des Diagnosekeys

Der case-insensitive Diagnosekey darf nicht verwendet werden für:

- Inventar-Unique-Keys;
- Präsenzzählung;
- Datastore-Kapazitätszuordnung;
- Portgruppenverfügbarkeit;
- WDS-Schnittstellenermittlung;
- MAC-Importzuordnung;
- Queue- oder Workerfreigabe;
- automatische Feldkorrektur;
- Massen-Reassign-Auswahl;
- Erfolg eines Deploy- oder Callbackresultats.

Ein Static-Guard scannt diese Owner und schlägt auf
`esxi_inventory_name_key()` beziehungsweise auf inline
`strtolower(trim(...))` in operativen Vergleichen an. Nach vollständiger
Migration wird der mehrdeutig benannte alte Helper entfernt, nicht als Alias
weitergeführt.

### 5.3 SSoT-Matrix

| Wahrheit | Einzige SSoT |
|---|---|
| Eingabetrimmung | `Validator::optionalString()` beziehungsweise derselbe Objektname-Helper für read-only Daten |
| operative ESXi-Namensgleichheit | exakter Objektname-Helper |
| ähnliche Schreibweise | Diagnosekey desselben Helpers |
| Deploymodus und Playbookfolge | `ansible_playbooks_for_mode()` |
| Modus importiert MACs | aus dem Vorhandensein des Export-Playbooks abgeleiteter Helper |
| Job-VM-Scope | vorhandene Payload-Normalisierung und `vm_ids`-Regel |
| WDS-Ziel | `deploy_missions.wds_vlan` |
| VM-WDS-Befund | `vm_network_contract.php` aus Etappe 14A |
| Queueblocker | `deploy_queue_blockers()` |
| Warnungen | getrennter Warning-Aggregator über denselben Domainbefund |
| Hostinventarbeweis | Cachezeilen plus erfolgreicher, neuer Name-Semantik-Stand |
| MAC-Ergebnis | `deploy_jobs.result_json`, ADR-0030 |
| VM-Lifecycleübergang | atomarer Commit in `db_importMAC.php` |

## 6. Schema- und Migrationsvertrag

### 6.1 Case-sensitive Spalten

MySQL ist im Repository auf 8.4 gepinnt. Folgende ESXi-Objektnamenspalten werden
in Migration und Fresh-Schema explizit auf `utf8mb4_0900_bin` gesetzt:

- `deploy_esxi_inventory.name`;
- `deploy_vlan.vlan_name`;
- `deploy_missions.hypervisor_datacenter`;
- `deploy_missions.hypervisor_datastorage`;
- `deploy_missions.wds_vlan`;
- `deploy_vms.vm_datacenter`;
- `deploy_vms.vm_datastore`;
- `deploy_interfaces.vlan`.

Andere fachliche Namen, insbesondere Benutzer-, Missions-, VM- und Paketnamen,
werden nicht nebenbei umgestellt.

`deploy_esxi_inventory.name` wird zugleich von 191 auf 255 Zeichen erweitert,
damit Cache, Missions-/VM-Felder und VLAN-Katalog dieselbe interne Obergrenze
besitzen. Diese Änderung behauptet keine VMware-Maximallänge; sie entfernt nur
die heutige interne 191/255-Abweichung.

### 6.2 Inventar-Semantikversion

Eine additive Spalte in `deploy_esxi_inventory_state`, geplant als
`name_semantics_version`, unterscheidet Alt- und Neuabruf:

- bestehende Zustandszeilen erhalten `1`;
- ein erfolgreicher Abruf durch die neue exakte Pipeline schreibt `2` in
  derselben Transaktion wie Cache und Status;
- Fresh-Schema startet mit Default `2`;
- ein Abruffehler erhöht die Version nicht;
- Cachezeilen eines Zugangs mit Version 1 dürfen angezeigt werden, aber weder
  exakte Präsenz noch case-sensitive Abwesenheit beweisen.

Damit wird nach der Migration nicht so getan, als hätte ein alter
case-insensitiv deduplizierter Snapshot alle Schreibweisen erhalten.

### 6.3 Migration ohne Datenraten

Die Migration:

1. ändert keine gespeicherten Namen;
2. lowercaset, merged oder korrigiert keine Zeile;
3. löscht keinen Portalbestand;
4. baut Unique-Keys unter der neuen Collation reproduzierbar neu auf;
5. setzt nur die Inventar-Semantikversion vorhandener Zustände auf 1;
6. ist idempotent und besitzt Fresh-Schema-Konvergenztests;
7. protokolliert ausschließlich Counts, niemals Objektlisten mit Secrets;
8. enthält keinen Down-Pfad. Vorher gilt der Backupvertrag aus ADR-0017.

Case-Varianten, die der alte Cache bereits zusammengelegt hat, werden erst mit
dem nächsten erfolgreichen Inventarabruf wieder vollständig sichtbar. Bis dahin
lautet das Verdict „Inventar nach Update noch nicht exakt verifiziert“.

## 7. Inventar, Katalog und Abweichungslogik

### 7.1 Cache-Ingestion

`repo_esxi_inventory_dedupe()` dedupliziert künftig nur noch exakt identische,
getrimmte Namen. Case-Varianten bleiben getrennte Zeilen.

Meldet derselbe Zugang denselben exakten Namen mehrfach aus unterschiedlichen
Abfragequellen, wird der erste Datensatz nicht still zur eindeutigen Wahrheit.
Der Cache hält im `meta_json` einen bounded Herkunfts-/Treffercount. Die Anzeige
kennzeichnet den Namen als mehrfach gemeldet. Wegen der Cache-Regel bleibt dies
eine Warnung; es wird kein Zielobjekt geraten.

### 7.2 VLAN-/Portgruppenkatalog

Der Katalog ist weiterhin ESXi-owned und read-only im Portal. Neu gilt:

- Case-Varianten sind getrennte Katalogzeilen;
- die Präsenz je Host wird exakt gezählt;
- ähnliche Schreibweisen werden zusätzlich als Abweichungsgruppe dargestellt;
- ein Rename wird weiterhin nicht automatisch von Löschen plus Neuanlage
  unterschieden;
- eine case-only Korrektur erfolgt nur über die bestätigte geführte
  Massen-Neuzuweisung;
- die Massenaktion liest Kandidaten, simuliert den Zielzustand und aktualisiert
  ausschließlich geprüfte IDs;
- Quell- und Zielname werden exakt verglichen. Eine case-only Änderung ist eine
  echte Änderung und nicht länger ein wirkungsloser Selbst-Reassign;
- bei irgendeinem VM- oder Missionskonflikt erfolgt kein Teilupdate.

### 7.3 Abweichungsobjekt mit Herkunft

Eine Abweichung ist kein String mehr, sondern ein strukturiertes Objekt:

```text
kind                  datacenter | datastore | network
match_state           exact | case_mismatch | missing | inventory_unknown
source_kind           mission | vm_override | vm_interface
source_field          wds_vlan | hypervisor_datacenter | hypervisor_datastorage |
                      vm_datacenter | vm_datastore | interface_vlan
mission_id/name
vm_id/name            nullable
interface_id/position nullable
configured_value
inventory_candidates  Liste exakter ähnlicher Namen
credential_id         nullable bei Unionbefund
inventory_fetched_at  nullable
name_semantics_version
```

Presenter und JSON-Island erhalten dieses Objekt. Sie bauen keine Herkunft aus
Text oder Feldnamen nach.

### 7.4 Scope und Relevanz

Hostwarnungen werden aus dem tatsächlich normalisierten Formularzustand gebildet:

1. Explizit ausgewählte VMs: nur diese VMs.
2. Leere Auswahl: alle VMs der Mission.
3. Missionswerte werden nur genannt, wenn der Modus sie tatsächlich verwendet.
4. VM-Overrides werden nur für Scope-VMs genannt.
5. VM-Portgruppen werden bei `create` und `full` als Create-Eingaben geprüft.
6. Die WDS-Warnung verwendet unabhängig davon den Modusvertrag aus Abschnitt 9.
7. Ein später hinzugefügter Modus muss durch einen Exhaustivitätstest
   klassifiziert werden. Ein Default „wie full“ oder „warnen“ ist unzulässig.

## 8. Speicherbedarf und Datastore-Verdict

`ansible_storage_by_datastore()` gruppiert künftig nach exaktem getrimmtem Namen.
`Daten` und `DATEN` sind zwei Zeilen und werden nie addiert oder gegeneinander
gerechnet.

Die Kapazitätskarte des gewählten ESXi-Zugangs ist ebenfalls exakt indiziert.
Für jede Bedarfszeile gilt:

| Lage | Verdict | Anzeige |
|---|---|---|
| exakter Datastore mit nutzbaren Zahlen | `ok` oder `insufficient` | bisherige Berechnung |
| kein exakter Treffer, genau eine ähnliche Schreibweise | `case_mismatch` | Bewertung offen; beide Schreibweisen nennen |
| kein exakter und kein ähnlicher Treffer | `missing` | Bewertung offen; Datastore auf Host nicht im Cache |
| Cache leer, altsemantisch, ohne Zahl oder Datastore unbenutzbar | `unknown` | keine belastbare Angabe |

`case_mismatch`, `missing` und `unknown` erhalten niemals die freien Bytes eines
anderen Namens. Sie blockieren den Auftrag nicht. Die Storage-Tabelle und die
Hostwarnung verwenden dasselbe Matchobjekt, damit sie nicht widersprechen.

Beispieltext:

> Mission, Datastore: Konfiguriert ist „Daten“. Der gewählte Host meldete beim
> letzten Inventarabruf nur „DATEN“. VMware-Objektnamen sind
> case-sensitive. Die Speicherbewertung bleibt deshalb offen. Der Auftrag wird
> durch den Cache nicht blockiert.

## 9. WDS/PXE-Domainvertrag

### 9.1 Pure VM-Prüfung

Der Netzwerk-Domainhelper aus Etappe 14A erhält eine getrennte WDS-Prüfung. Für
jede VM liefert sie genau einen dieser Zustände:

```text
ready
mission_wds_missing
vm_wds_interface_missing
vm_wds_interface_case_mismatch
vm_wds_interface_multiple
```

Regeln:

1. `mission_wds_missing`: Missionswert nach Trim leer.
2. `ready`: genau ein VM-Interface trifft den Missionswert exakt.
3. `vm_wds_interface_case_mismatch`: kein exakter Treffer, aber mindestens ein
   nur case-abweichender Kandidat. Alle Kandidaten werden genannt.
4. `vm_wds_interface_missing`: weder exakter noch ähnlicher Kandidat.
5. `vm_wds_interface_multiple`: mehr als ein exakter Treffer. Interface-IDs und
   sichtbare Zeilennummern werden mitgeführt.
6. Ein leerer Portgruppenname und allgemeine doppelte Portal-Portgruppen bleiben
   zusätzlich Codes des allgemeinen Netzwerkvertrags. Der WDS-Code ersetzt
   diese nicht.
7. DHCP/static aus dem VM-Editor entscheidet nicht, welche NIC PXE-relevant ist.
   Der Create-Generator übergibt diese Gast-IP-Einstellung derzeit nicht als
   NIC-Identität. Die WDS-Portgruppe ist die einzige deklarierte PXE-SSoT.
8. Ein bereits gesetzter MAC-Wert ändert den WDS-Bereitschaftszustand nicht. Er
   wird separat als `wds_mac_present` beziehungsweise `wds_mac_missing`
   dargestellt.

### 9.2 Modusmatrix

Die Härte wird nicht als zweite freie Liste gepflegt. Ein Helper leitet aus
`ansible_playbooks_for_mode()` ab, ob die Sequenz das Export-Playbook enthält.

| Modus | Exportiert/importiert MACs | WDS-Befund | Allgemeiner Netzwerkvertrag |
|---|---:|---|---|
| `full` | ja | harter Blocker | harter Blocker |
| `powercycle` | ja | harter Blocker | harter Blocker |
| `export` | ja | harter Blocker | harter Blocker |
| `create` | nein | sichtbare Warnung | harter Blocker |
| `start` | nein | sichtbare Warnung | sichtbare Warnung gemäß Altplan |
| `autostart` | nein | sichtbare Warnung | sichtbare Warnung gemäß Altplan |
| `inventory` | nein, missionslos | nicht anwendbar | nicht anwendbar |

Die WDS-Prüfung in `powercycle` und `export` schließt den alternativen Pfad, über
den heute eine beliebige andere NIC-MAC importiert und die VM fälschlich auf
`deployed/pending` gesetzt werden könnte.

### 9.3 Auswahlvertrag

1. Explizite `vm_ids` prüfen nur diese VMs.
2. Leere `vm_ids` prüfen die gesamte Mission.
3. Eine gepostete Auswahl, die nach Repositoryfilterung leer wird, darf nicht zur
   ganzen Mission erweitert werden. Der vorhandene Guard bleibt bestehen.
4. Staffelung prüft den Gesamtscope vor Gruppenanlage und jede entstehende
   Einzel-VM erneut innerhalb derselben Queue-Transaktion.
5. Retry prüft den vom bestehenden Retryplan berechneten aktuellen Scope.
6. Die VM-Reihenfolge beeinflusst Ergebnis und Identität nicht.

## 10. Mission bearbeiten

### 10.1 Beschriftung und Hilfe am Feld

Deutsch:

```text
WDS-Portgruppe (PXE)
Portgruppe, über die VMs dieser Mission per WDS/PXE starten.
Für Full Pipeline, Power-Cycle + Export MACs und Export MACs muss jede
betroffene VM genau eine Netzwerkkarte mit exakt diesem Portgruppennamen besitzen.
Ohne WDS-Portgruppe können diese Modi nicht eingereiht werden.
```

Englisch erhält dieselbe Semantik. Die Select-Option „Keine“ bleibt zulässig,
weil Vorlagen und nicht exportierende Betriebsabläufe existieren.

### 10.2 Änderung bei vorhandenem VM-Bestand

Das Missionsformular zeigt dauerhaft den Hinweis:

> Eine Änderung passt vorhandene VM-Netzwerkkarten nicht automatisch an.

Wenn der ausgewählte Wert vom geladenen Missionswert abweicht, berechnet die
Seite aus den vorhandenen VM-Interfaces eine Vorschau:

- Anzahl sofort passender VMs;
- Anzahl VMs ohne exakten Treffer;
- Anzahl VMs mit mehreren Treffern;
- Link zur VM-Liste der Mission.

Die Vorschau ist QoL, keine Sicherheitsgrenze. Ohne JavaScript bleibt der
dauerhafte Hinweis sichtbar. Speichern bleibt erlaubt. Nach dem Speichern nennt
die Flashmeldung die Anzahl der VMs, deren WDS-Konfiguration jetzt nicht bereit
ist. Es erfolgt kein Auto-Reassign.

Während eines aktiven Jobs gelten die bestehenden Schreibsperren aus dem
Netzwerk-/Remoteplan. Eine Missions-WDS-Änderung, die den Scope eines laufenden
MAC-Imports verändern würde, wird serverseitig abgelehnt und verlinkt den aktiven
Job.

## 11. VM-Editor

1. Das Feld heißt sichtbar **Portgruppe**, nicht nur „VLAN“.
2. Jede Interfacegruppe besitzt eine eindeutige Überschrift
   „Netzwerkkarte N“ und einen stabilen Anker.
3. Genau passende Schnittstelle: neutrales Informationsbadge
   „WDS/PXE dieser Mission“.
4. Nur case-abweichende Schnittstelle: Warnung mit Soll- und Ist-Schreibweise.
5. Mehrere exakte WDS-Schnittstellen: beide Controls `aria-invalid=true`, eigene
   Inlinefehler, Zusammenfassung mit Links und Fokus auf die erste Kollision.
6. Keine passende Schnittstelle: Zusammenfassung über dem Netzwerkbereich und
   Hinweis, welche Portgruppe ergänzt werden muss.
7. Das bestehende Badge/Feedback für leere oder allgemein doppelte Portgruppen
   bleibt getrennt.
8. Ohne JavaScript bleiben serverseitige Fehler, Eingaben und Links vollständig.
9. Der Editor darf niemals automatisch die erste NIC zur WDS-NIC erklären.
10. Neue VMs dürfen die Missions-WDS-Portgruppe weiterhin als erste
    Vorbelegung erhalten. Das ist eine editierbare Vorgabe, keine vererbte
    Bindung.

## 12. Deploy-Formular vor „Auftrag einreihen“

### 12.1 Zeitpunkt und Reaktion

Die Anzeige wird neu berechnet bei:

- Missionswechsel;
- Moduswechsel;
- Auswahl oder Abwahl einer VM;
- „Alle auswählen“;
- ESXi-Zugangswechsel;
- Wiederherstellung eines Sticky-Formularzustands;
- Rückkehr aus einer Validierungs- oder Zeitplanvorschau.

Serverrendering erzeugt den vollständigen Anfangszustand. JavaScript aktualisiert
ihn progressiv aus einer lokal gerenderten, CSP-konformen JSON-Insel. Kein
Browserrequest entscheidet allein über die Freigabe.

### 12.2 Blocker

`deploy_queue_blockers()` erzeugt pro betroffener VM ein strukturiertes Objekt.
Die Submitfreigabe wird ausschließlich aus derselben Liste wie die sichtbare
Blockeranzeige abgeleitet.

Beispiele:

> VM „APP01“: Keine Netzwerkkarte verwendet die WDS/PXE-Portgruppe
> „WDS-VLan“ der Mission. VM-Netzwerke öffnen.

> VM „APP02“: Die Mission erwartet „WDS-VLan“, die VM verwendet
> „WDS-VLAN“. VMware-Portgruppennamen sind case-sensitive. VM-Netzwerke öffnen.

> VM „APP03“: Zwei Netzwerkkarten verwenden die WDS/PXE-Portgruppe
> „WDS-VLan“. Für den MAC-Import muss genau eine Karte zugeordnet sein.
> VM-Netzwerke öffnen.

Der Reparaturlink wird nur gezeigt, wenn der Benutzer `vms.write` am Ziel darf.
Die erklärende Meldung bleibt für Benutzer ohne dieses Recht sichtbar.

### 12.3 Warnungen

Warnungen sind visuell und semantisch getrennt von Blockern. Sie deaktivieren
den Button nicht.

Jede Inventarwarnung nennt:

- Quelle „Mission“ oder „VM <Name>, Netzwerkkarte N“;
- Feld „Datacenter“, „Datastore“ oder „Portgruppe“;
- konfigurierten exakten Wert;
- gewählten ESXi-Zugang;
- ähnlichen Inventarwert, sofern vorhanden;
- Zeitpunkt des Inventars;
- den Satz, dass der Cache den Auftrag nicht blockiert;
- Link zum exakten Systemstatus-Abschnitt.

Die heutige Sammelmeldung „Datacenter, Datastore oder VLAN dieser Mission“ wird
entfernt. Sie ist sachlich falsch, sobald der Wert aus einem VM-Override oder
einer VM-Netzwerkkarte stammt.

### 12.4 VM-Badges

Die mehrdeutigen Badges „MAC vorhanden“ und „MAC fehlt“ werden ersetzt durch:

- `PXE-MAC vorhanden`;
- `PXE-MAC fehlt`;
- `WDS/PXE-Portgruppe fehlt`;
- `WDS/PXE-Portgruppe mehrfach`;
- `Schreibweise weicht ab`.

Ein Badge basiert auf demselben WDS-Domainobjekt wie Queue und Worker.

### 12.5 Leere Auswahl

Bleibt keine einzelne VM angehakt, zeigt das Formular unmittelbar vor der
Aktion:

> Keine einzelne VM ist ausgewählt. Der Auftrag umfasst alle :count VMs der
> Mission.

Die Zeitplanvorschau und die bestätigte POST-Anfrage zeigen beziehungsweise
prüfen denselben Scope.

## 13. Queue-, Zeitplan- und Workergrenzen

### 13.1 Queue-POST

Innerhalb der bestehenden Queue-Transaktion gilt diese Reihenfolge:

1. Modus normalisieren.
2. Mission sperrend lesen.
3. VM-Scope filtern und materialisieren.
4. bestehende Missions-/Credential-/Identity-Gates ausführen.
5. allgemeinen Netzwerkvertrag für den Modus ausführen.
6. WDS-Domainvertrag für den Scope ausführen.
7. bei exporthaltigem Modus mit irgendeinem WDS-Befund vollständig ablehnen;
8. bei nicht exporthaltigem Modus Warnungen nicht in die persistierte
   Sicherheitsentscheidung mischen;
9. erst danach Job oder Staffelzeilen anlegen.

Der Repositoryfehler trägt geschlossene Codes und strukturierte Parameter. Er
enthält keinen vorübersetzten HTML-Text.

### 13.2 Geplante und gestaffelte Aufträge

Eine Vorschau ist kein Gate. Beim finalen POST wird alles erneut gelesen.
Staffelung ist atomar: Entsteht für eine VM ein Blocker, wird keine Teilstaffel
angelegt.

Ändert sich die VM-Konfiguration nach Queue und vor Claim, darf der neue Stand
gelten. Der Worker prüft ihn erneut. Nach Claim greifen die vorhandenen
Scope-Schreibsperren.

### 13.3 Worker-Recheck

Nach Claim und vor Upload, SSH, Poweroperation oder ESXi-Mutation:

1. Payloadmodus und Scope erneut normalisieren.
2. Mission, Scope-VMs und Interfaces gebündelt lesen.
3. allgemeinen Netzwerk- und WDS-Vertrag ausführen.
4. Progress je VM gemäß Repositoryvertrag schreiben.
5. bei Blockern keinerlei Remote-Artefakt hochladen und kein Playbook starten.
6. Job mit `configuration_blocked` und strukturiertem per-VM-Ergebnis beenden.

Beispiel:

```text
[2/5] RUN WDS/PXE preflight APP02
[2/5] FAIL WDS/PXE preflight APP02: vm_wds_interface_case_mismatch expected=WDS-VLan actual=WDS-VLAN
```

Die technische Zeile bleibt redigiert und bounded. Portaltexte werden über den
zentralen Presenter lokalisiert.

### 13.4 Cache bleibt kein Workerblocker

Der Worker darf einen Job nicht allein deshalb ablehnen, weil der letzte
Inventarsnapshot eine Portgruppe oder einen Datastore nicht enthält. Das reale
Ansible-/vSphere-Ergebnis bleibt die Autorität. Ein bekannter Cachebefund darf im
Joblog als Vorabhinweis erscheinen, aber nicht als bewiesener Livefehler.

Fehlertexte eines Upstream-Moduls werden nicht per Stringmuster in eine andere
Ursache umgedeutet. Eigene präzise Ursachen entstehen nur aus eigenen
deterministischen Prüfungen.

## 14. MAC-Callback und VM-Statuswahrheit

### 14.1 Zusätzliche Callback-Voraussetzung

`db_importMAC.php` liest innerhalb seiner bestehenden äußeren Transaktion die
Mission einschließlich `wds_vlan`. Für jede erwartete VM gilt vor einem Write:

1. Die Portalmission besitzt eine nicht leere WDS-Portgruppe.
2. Die Portal-VM besitzt genau ein Interface mit exakt diesem Wert.
3. Das ESXi-Ergebnis enthält genau eine NIC mit exakt diesem Network-Summary.
4. Diese NIC besitzt eine gültige MAC.
5. Die MAC ist nicht widersprüchlich oder einer anderen VM zugeordnet.
6. Die Portal-/ESXi-VM-Identitätsprüfung ist erfolgreich.
7. Alle übrigen heutigen per-VM-Atomaritätsguards bleiben erfüllt.

Erst dann darf die VM in `successful_vm_ids` erscheinen.

### 14.2 Exakte MAC-Zuordnung

Die heutige SQL-Zuordnung unter `utf8mb4_unicode_ci` wird ersetzt. Der
Callbackplan lädt alle Interfaces einer erwarteten VM sperrend und baut in PHP
eine exakte Map. DB-Collation und zufällige Querytreffer entscheiden nicht mehr.

Case-only Kandidaten liefern einen eigenen Fehler mit Soll und gemeldetem Wert.
Mehrere exakte Portal- oder ESXi-Treffer werden nie auf den ersten reduziert.

### 14.3 Geschlossene neue Callbackcodes

Die bestehende Fehlerregistry wird additiv um diese Codes erweitert:

| Code | Quelle | Bedeutung |
|---|---|---|
| `mission_wds_missing` | Portalmission | kein WDS/PXE-Ziel definiert |
| `portal_wds_interface_missing` | Portal-VM | kein exaktes WDS-Interface |
| `portal_wds_interface_case_mismatch` | Portal-VM | nur ähnlich geschriebene Schnittstelle |
| `portal_wds_interface_ambiguous` | Portal-VM | mehrere exakte WDS-Interfaces |
| `esxi_wds_interface_missing` | ESXi-Resultat | keine exakt gemeldete WDS-NIC |
| `esxi_wds_interface_case_mismatch` | ESXi-Resultat | nur ähnlich geschriebene NIC-Summary |
| `esxi_wds_interface_ambiguous` | ESXi-Resultat | mehrere exakt gemeldete WDS-NICs |
| `wds_mac_missing` | ESXi-Resultat | WDS-NIC ohne MAC |

Alte Codes bleiben für ihre heutige Bedeutung erhalten. Ein neuer Code wird
nicht auf `interface_not_found` oder `ambiguous_vlan` gerundet, weil sonst erneut
unklar wäre, ob Mission, Portal-VM oder ESXi-Ergebnis gemeint ist.

### 14.4 Erfolg und Statusübergang

`mac_import_finalize_plan()` verlangt künftig einen expliziten erfolgreichen
WDS-Beweis pro VM. „Mindestens irgendein Interface-Update“ ist kein Erfolg mehr.

Nur `successful_vm_ids` erhalten weiterhin innerhalb derselben Transaktion:

- Interface-MAC-Writes;
- MOID-/Instance-UUID-Aktualisierung;
- `lifecycle_state=deployed`;
- `mecm_sync_state=pending`;
- `vm_status=3/5 Deployed`;
- `updated=1`;
- Statusevent;
- das gemeinsame `result_json`.

Schlägt die WDS-Prüfung einer VM fehl, erhält sie keinen dieser Writes. Andere
VMs desselben Jobs dürfen gemäß ADR-0030 erfolgreich committen; der Job wird
`partial`, wenn mindestens eine VM erfolgreich und mindestens eine fehlerhaft ist.

### 14.5 Resultatvertrag

ADR-0030 Version 1 bleibt lesbar. Die neuen Felder sind additiv, damit alte
gespeicherte Ergebnisse nicht unlesbar werden. Neu persistiert werden die bereits
geplanten `vm_results`, ergänzt um bounded WDS-Kontext ohne Secretwerte:

```json
{
  "vm_id": 17,
  "vm_name": "APP01",
  "outcome": "success",
  "updated_interfaces": 2,
  "error_codes": [],
  "wds": {
    "configured_portgroup": "WDS-VLan",
    "portal_interface_id": 41,
    "verified": true
  }
}
```

Historische Version-1-Ergebnisse ohne `vm_results` bleiben gültig, werden aber
in der Portalansicht ausdrücklich als historisch ohne per-VM-WDS-Nachweis
bezeichnet. Sie werden niemals rückwirkend umgeschrieben.

`mac_import_decode_result()` liefert die Fehler-/VM-Details an Presenter und
Retry-Gate weiter. Unbekannte Codes bleiben fail-closed und erscheinen escaped
als technischer Token.

## 15. Retry-Vertrag

1. Ein `partial`-Retry bleibt Export-only und umfasst exakt die vertrauenswürdig
   gespeicherten `failed_vm_ids`.
2. Vor dem Retry wird der aktuelle WDS-Vertrag für diesen Scope erneut geprüft.
3. Besteht der Portalfehler fort, wird kein Retry angeboten. Stattdessen erscheint
   „Konfiguration korrigieren“ mit VM-Link.
4. Ist die aktuelle Portalprüfung grün, bleibt das historische Ergebnis rot oder
   gelb. Es erhält nur den Zusatz „Aktuelle Konfiguration ist jetzt bereit“.
5. Ein ESXi-seitiger historischer Fehler kann nach Bestätigung export-only
   wiederholt werden, weil das Portal die externe Reparatur nicht live beweisen
   kann.
6. Ein unbekannter oder unlesbarer alter Resultatscope wird nicht auf alle VMs
   erweitert. Die vorhandene ADR-0030-Divergenzregel bleibt maßgeblich.
7. Remote-, Identity- und Recoveryblocker besitzen weiterhin Vorrang vor
   Netzwerk/WDS, wie im Plan vom 13.08.2026 festgelegt.

## 16. Fehler-, Feedback- und Look-and-Feel-Vertrag

### 16.1 Darstellungshierarchie

1. Rot: harter, deterministischer Blocker.
2. Gelb: Cache-/Case-/WDS-Warnung ohne Sperre.
3. Neutral: Information wie PXE-MAC vorhanden oder Inventar noch nicht exakt
   aktualisiert.
4. Grün: nur ein positiv belegter Zustand, nie das Fehlen einer Warnung.

Farbe ist nie der einzige Informationsträger. Jeder Zustand besitzt Text und
gegebenenfalls Symbol/Badge gemäß den vorhandenen Portalhelfern.

### 16.2 Handlungsfähigkeit

Jede Meldung beantwortet:

1. Welches Objekt ist betroffen?
2. Aus welcher Quelle stammt der konfigurierte Wert?
3. Welcher exakte Wert wurde erwartet?
4. Welcher Wert wurde tatsächlich gefunden oder warum ist nichts beweisbar?
5. Blockiert der Befund?
6. Wo kann der Benutzer ihn korrigieren oder verifizieren?

Eine Meldung nennt keine Reparaturseite ohne Link. Der Link folgt den bestehenden
URL-Helper- und Berechtigungsverträgen.

### 16.3 Responsive und barrierearme Darstellung

- Meldungscontainer wrappen bei langen VM- und Portgruppennamen ohne horizontale
  Seitenüberläufe.
- Aktionszeile und folgende Blockerliste besitzen den gemeinsamen vertikalen
  Abstand, auch wenn die Buttonzeile wrappt.
- Neue Klassen erhalten CSS-Regeln und bestehen den CssClassContractTest.
- Dynamische Aktualisierungen verwenden `role=status` für Hinweise und
  `role=alert` für neu entstandene Blocker.
- Fokus wird bei Queueversuch auf den ersten Blocker beziehungsweise bei
  VM-Speicherfehler auf das erste betroffene Control gesetzt.
- Dark/Light Theme, 200 Prozent Zoom, Tastatur und Screenreader-Stichprobe sind
  Teil der Abnahme.

## 17. Help- und Dokumentationsmatrix

### 17.1 Portaltexte

Alle DE/EN-Kataloge werden gemeinsam geändert:

| Katalog | Inhalt |
|---|---|
| `mission_details.php` | Feldname, Keine-Hinweis, Nicht-Vererbung, Auswirkungszusammenfassung |
| `vm_edit.php` | Portgruppe, WDS-Badges, Inlinefehler, Reparaturhinweise |
| `deploy.php` | strukturierte Blocker/Warnungen, PXE-MAC-Badges, Scopezusammenfassung, Storage-Case-Verdict |
| `validate.php` | Feldlabels und geschlossene Domainmeldungen |
| `help_deploy.php` | Modusmatrix, harte WDS-Modi, Hostcachegrenze, Case-Sensitivität |
| `help_missions.php` | Bedeutung und Änderungsfolgen der WDS/PXE-Portgruppe |
| `help_stack.php` | PXE-MAC-Ablauf ohne Behauptung einer Portal-Dienstprüfung |
| `help_system_status.php` | exakte Namen, Case-Gruppen, Inventar-Semantikversion |
| `vlans.php` | Portgruppenbegriff, case-verschiedene Katalogzeilen, Reassign |

Der heutige Missionshilfe-Hinweis, bei 3/5 nur nach einer Netzwerkkarte im
DHCP-Modus zu suchen, wird korrigiert. Maßgeblich ist die WDS/PXE-Portgruppe; der
Gast-IP-Modus ist keine NIC-Identität.

### 17.2 Aktive technische Dokumentation

| Datei | Verbindliche Ergänzung |
|---|---|
| `docs/DEPLOYMENT.md` | exakte VMware-Namen, zwei Namenshelper, WDS-Modusmatrix, Worker- und Callbackgate |
| `docs/operations/esxi-inventory.md` | neue Collation, Semantikversion, Case-Gruppen, warn-only Grenze, Storage-Verdicts |
| `docs/operations/deploy-chain.md` | Queue- und Worker-Recheck vor Remote-Arbeit |
| `docs/operations/mecm-integration.md` | WDS-MAC als Erfolgsbedingung, neue Codes, per-VM-Atomarität |
| `docs/operations/troubleshooting.md` | Suchpfad Mission -> VM -> Host -> Job -> Callback |
| `docs/QA.md` | alle neuen Unit-, Integration-, Static-, E2E- und Stagingnachweise |
| `docs/CHANGELOG.md` | Operatornutzen und bewusst unveränderte Grenzen |
| `docs/adr/ADR-0023-esxi-inventory-and-vlan-ownership.md` | Amendment: operative Namen exakt, Cache weiterhin warn-only |
| `docs/adr/ADR-0030-partial-deploy-results-and-result-json.md` | Amendment: WDS-MAC als VM-Erfolg, additive Codes und `vm_results` |

### 17.3 Dauerhafte Agent-/Guardregeln

`AGENTS.md`, `GROK.md` oder `.claude/rules/` werden nur ergänzt, wenn nach der
Umsetzung eine dauerhafte konstruktive Regel beziehungsweise ein verbotener
Driftpfad entstanden ist. Plantext allein wird nicht als Regel dupliziert.

## 18. Datei- und Owner-Matrix

| Owner | Geplante Verantwortung |
|---|---|
| `lib/esxi_object_names.php` neu | exakter Name, Diagnosekey, Matchklassifikation |
| `lib/repo/esxi_inventory_cache.php` | exakte Deduplizierung und Semantikversion |
| `lib/repo/esxi_inventory_vlan.php` | exact-case Katalogsync und Präsenz |
| `lib/esxi_inventory_deviations.php` | strukturierte Abweichungen mit Herkunft/Scope |
| `lib/esxi_inventory_options.php` | exact-case Optionen und ähnliche Hinweise |
| `lib/inventory_field.php` | konsistente Selectdarstellung |
| `lib/ansible_yaml.php` | exakte Storagegruppierung; keine erfundene NIC |
| `lib/deploy_storage.php` | exact/case/missing/unknown Verdict |
| `lib/vm_network_contract.php` neu gemäß Altplan | allgemeiner und WDS-Domainbefund |
| `lib/repo/vm_network.php` neu gemäß Altplan | gebündelte Scope-Leser und aktive Schreibsperre |
| `lib/vm_network_display.php` neu gemäß Altplan | lokalisierter Presenter |
| `lib/repo/deploy_job_guards.php` | serverseitige Missionsbereitschaft |
| `lib/repo/deploy_job_queue.php` | Queue-/Staffeltransaktion nutzt Aggregator |
| `lib/deploy_worker_mission.php` | Worker-Recheck vor Remote-Arbeit |
| `lib/mac_import.php` | exakte Portal-/ESXi-NIC-Zuordnung und WDS-Erfolg |
| `lib/mac_import_result.php` | additive Resultdetails, Decoder und Codes |
| `db_importMAC.php` | unverändert atomarer Machine-API-Commit |
| `portal/mission_details.php` | Feldhilfe und WDS-Änderungsauswirkung |
| `lib/vm_edit_form.php` / geplante Splits | WDS-Markierung und Inlinefeedback |
| `portal/deploy.php` / Masterplan-Splits | Blocker-/Warnungsrenderer und JSON-Insel |
| `portal/assets/deploy.js` / Masterplan-Splits | progressive Modus-/Scope-/Hostaktualisierung |

Keine dieser Verantwortungen wird in einer zweiten Helperkopie nachgebaut.
Dateisplits richten sich nach dem dann erreichten Masterplanstand; die fachlichen
Owner bleiben gleich, auch wenn ein Pfad inzwischen in ein fokussiertes Modul
verschoben wurde.

## 19. Verbindliche Arbeitspakete

### Paket A: Charakterisierung und rote Tests

1. Produktionsfall `Daten` gegen `DATEN` als Unit-/Integrationfixture aufnehmen.
2. Heutige falsche Storagezusage rot beweisen.
3. Heutiges Collapse case-verschiedener Cachezeilen rot beweisen.
4. Heutige missionsweite statt ausgewählte Hostwarnung rot beweisen.
5. Heutigen MAC-Erfolg über eine Nicht-WDS-NIC rot beweisen.
6. Modussequenzen und Auswahlvertrag charakterisieren.
7. Erst danach Produktlogik ändern.

### Paket B: Schema und Objektname-SSoT

1. Migration/Fresh-Schema für Collations, Länge und Semantikversion.
2. Objektname-Helper und geschlossene Matchzustände.
3. Static-Guard gegen operative Case-Folding-Verwendung.
4. Schema-Konvergenz und positive/negative/zero-match Guardtests.

### Paket C: Cache, Katalog und Inventaroptionen

1. exakte Cache-Ingestion;
2. case-verschiedene Zeilen erhalten;
3. Semantikversion nur bei erfolgreichem Neuabruf setzen;
4. genaue Hostpräsenz und ähnliche Schreibweisengruppe;
5. Katalog und Massen-Reassign transaktional anpassen;
6. Altcache als unbekannt statt grün behandeln.

### Paket D: Abweichung und Speicherbewertung

1. strukturierte Herkunftsobjekte;
2. Modus-/VM-Scope;
3. exakte Storagegruppierung und Kapazitätsmap;
4. vier Verdictzustände;
5. serverseitige Vorschau und Live-JSON aus derselben Datenstruktur.

### Paket E: WDS-Domainvertrag und alle Writer

1. pure WDS-Prüfung;
2. allgemeine und WDS-Issues getrennt halten;
3. Mission edit, VM edit, Klon, Missionstransfer und Massen-Reassign anbinden;
4. WDS-Änderung ohne Auto-Propagation;
5. aktive Job-Schreibsperren bewahren.

### Paket F: Deploy-Queue und Worker

1. Modusableitung aus Playbookfolge;
2. Scope-Aggregator;
3. serverseitiger Queue-/Staffelblock;
4. Initialrenderer und progressive Browseraktualisierung;
5. Worker-Recheck mit Fortschrittsvertrag;
6. strukturiertes `configuration_blocked`-Ergebnis.

### Paket G: MAC-Callback, Resultat und Retry

1. Mission-WDS innerhalb Callbacktransaktion lesen;
2. exakte Portal-/ESXi-NIC-Map;
3. WDS-MAC als Erfolgsbedingung;
4. neue geschlossene Codes;
5. `vm_results` persistieren und lesen;
6. Retry aktuellen Scope revalidieren;
7. Machine-Wire und Legacy-Statusstrings unverändert beweisen.

### Paket H: Portal-QoL, Help und Dokumentation

1. Labels, Meldungen, Links und Badges;
2. DE/EN-Parität;
3. Help- und aktive Doku-Matrix vollständig abarbeiten;
4. ADR-Amendments und Changelog;
5. Responsive-, Theme- und Accessibilityabnahme.

### Paket I: Rollout und reale Abnahme

1. Backup und Revision/Digest belegen;
2. read-only Bestandsaudit;
3. Migration und gemeinsamer App-/Worker-/Ansible-Rollout;
4. alle ESXi-Zugänge neu inventarisieren;
5. Case- und WDS-Canaries;
6. bestehende Abweichungen fachlich korrigieren;
7. keine automatische Datenreparatur.

## 20. Edge-Case-Matrix

| Fall | Queue/Portal | Worker | Callback/Status |
|---|---|---|---|
| Mission ohne WDS, Modus `full`/`powercycle`/`export` | Blocker | Blocker vor Remote | Callback dürfte nicht erreicht werden; falls doch VM fehlgeschlagen |
| Mission ohne WDS, `create`/`start`/`autostart` | Warnung | läuft | kein MAC-Resultat erwartet |
| VM ohne WDS-Interface, exporthaltiger Modus | VM-Blocker | Blocker | keine Statusfortschreibung |
| genau eine exakte WDS-NIC | bereit | bereit | nur deren MAC kann WDS-Erfolg belegen |
| zwei exakte WDS-NICs | Blocker mit beiden Zeilen | Blocker | `portal_wds_interface_ambiguous` |
| VM `WDS-VLAN`, Mission `WDS-VLan` | Case-Blocker | Blocker | eigener Case-Code, kein Write |
| VM hat nur Produktivnetz mit gültiger MAC | Blocker | Blocker | darf niemals `deployed/pending` bewirken |
| WDS-NIC hat bereits MAC | bereit, Badge vorhanden | kein unnötiger Powercycle gemäß bestehender Logik | Export muss exakte MAC bestätigen |
| WDS-NIC ohne MAC | bereit, Badge fehlt | Powercycle/Export läuft | ohne gemeldete WDS-MAC Fehler, kein VM-Erfolg |
| ESXi meldet WDS-NIC nur in anderer Schreibweise | Cachewarnung | Portalconfig kann bereit sein; Cache blockiert nicht | exakter Callbackcheck schlägt fehl |
| Hostcache meldet Datastore nur case-abweichend | Storage offen, konkrete Warnung | Cache blockiert nicht | reales Modulresultat entscheidet |
| Hostcache leer oder Name-Semantik v1 | unbekannt | Cache blockiert nicht | reales Modulresultat entscheidet |
| mehrere ähnliche Hostnamen | alle Kandidaten nennen, nichts vorschlagen | Cache blockiert nicht | exakter Resultatname entscheidet |
| explizit eine gute VM gewählt, andere Mission-VM fehlerhaft | Auftrag zulässig | nur gewählte VM prüfen | nur Scope-VM erwartet |
| keine VM gewählt | Hinweis „alle X“; alle prüfen | alle prüfen | alle erwartet |
| gepostete IDs gehören nicht mehr zur Mission | bestehender Repo-Fehler; keine Scopeausweitung | nicht gestartet | kein Callback |
| Moduswechsel `create` -> `export` | Warnung wird Blocker, Button aktualisiert | POST revalidiert | Export nur bei bereit |
| Mission-WDS nach Queue geändert | Anzeige historisch; aktuelle Config darf noch ändern, solange queued | Recheck blockiert neuen Ist-Zustand | kein alter Wert geraten |
| Mission-WDS während running/cancelling geändert | serverseitig gesperrt | Job behält konsistenten Scope | Callback sieht unveränderten Vertrag |
| VM-Interface außerhalb Portal auf ESXi geändert | Cache kann abweichen | kein Cacheblock | Callback meldet exakte ESXi-Ursache |
| ESXi liefert zusätzliche unbekannte NIC | kein Vorabbeweis | Export läuft | bestehende per-VM-Atomarität und `interface_not_found` bleiben |
| ungültige oder doppelte MAC | bestehende Warn-/Blockregeln | Export läuft | alte Codes bleiben, kein WDS-Erfolg |
| Callback doppelt während aktivem Job | keine UI-Aktion | gleicher Attempt | bestehende Idempotenz erhalten |
| Callback nach Terminalzustand | keine UI-Aktion | Job terminal | 409 ohne Writes |
| Teiljob: eine WDS-VM gut, eine schlecht | vorab nur bei später externer Abweichung möglich | Export läuft | guter VM-Commit, schlechter kein Write, Job `partial` |
| Retry nach Portalreparatur | aktueller Scope grün, alter Job bleibt historisch fehlerhaft | neuer Exportjob | neues Resultat nur am neuen Job |
| Benutzer ohne `vms.write` | Ursache sichtbar, kein Reparaturlink | Servergate unverändert | unverändert |
| JavaScript aus | servergerenderter Stand; POST blockiert verbindlich | unverändert | unverändert |
| zwei Browser-Tabs, alte Formularseite | alter Buttonzustand unbeachtlich | Repo liest aktuellen Stand | kein stale Write |
| Portgruppenname `0` | gültiger nichtleerer Name | exakt vergleichen | exakt zuordnen |
| führende/folgende Leerzeichen aus Formular | zentral getrimmt | getrimmter Wert | getrimmter Vergleich |
| innere Leerzeichen, Umlaute oder Unicode | unverändert erhalten, kein Normalisierungsraten | exakt | exakt |
| case-only Massen-Reassign | echte bestätigte Änderung | atomar | nachher exakter Vertrag |
| Massen-Reassign erzeugt doppelte VM-Portgruppe | vollständiger Block, kein Teilupdate | nicht betroffen | nicht betroffen |
| Template ohne WDS | zulässig mit Hinweis | nicht direkt deploybar | kein Callback |
| Klon aus Template mit abweichenden Interfaces | Preview/Commit gemäß neuem Vertrag | späterer Deploy prüft | kein automatisches Umschreiben |

## 21. Testplan

### 21.1 Unit

- exakte Namen: `Daten`/`DATEN`, `WDS-VLan`/`WDS-VLAN`, Trim, `0`, Umlaute,
  mehrere ähnliche Kandidaten;
- Matchzustände exact/case/missing/unknown exhaustiv;
- Storagegruppierung und vier Verdicts;
- WDS-Zustände null/zero/one/multiple/case-only;
- Modusableitung über alle `virtusphere_deploy_modes()`;
- Scope leer/explizit/Retry;
- Presentertexte und Berechtigungslink;
- Resultdecoder alt Version 1 ohne Details und neu mit `vm_results`;
- unbekannter Fehlercode bleibt fail-closed.

### 21.2 Integration mit MySQL

- Fresh-Schema und Migration besitzen `utf8mb4_0900_bin` an allen benannten
  Spalten;
- Cache speichert case-verschiedene Namen desselben Kinds/Zugangs getrennt;
- Semantikversion 1 wird nicht als exakter Beweis verwendet;
- erfolgreicher Neuabruf setzt Version 2 atomar;
- Katalogsync erhält Varianten und retired nicht gegen einen Fehlabruf;
- Massen-Reassign case-only, Konflikt und Rollback;
- Queue-POST blockiert die drei Exportmodi und erlaubt Warnmodi;
- Staffelung ist all-or-nothing;
- Worker-Recheck startet keinen Remote-Schritt;
- MAC-Callback mit nur Nicht-WDS-MAC schreibt weder Status noch Identität;
- erfolgreicher WDS-Import schreibt alle gekoppelten Felder atomar;
- Partial, Duplicate, Cancel, Terminal-409 und Retry bleiben korrekt.

### 21.3 Static/Contract

- keine operative Verwendung des alten case-insensitiven Helpers;
- keine inline Case-Folding-Kopie in den Ownern;
- Modusklassifikation exhaustiv;
- Queuebutton stammt aus derselben Blockerliste wie die Anzeige;
- Worker lädt Domainhelper und prüft vor Upload;
- `db_importMAC.php` behält raw äußere Transaktion, keine Repo-Transaktion darin;
- Resultcode-Registry und ADR-Liste synchron;
- Fresh-Schema/Migration/Bounds synchron;
- DE/EN- und Placeholderparität;
- CSP, Confirm, CSS-Klassen, Deep Links, RBAC und File-Size-Gate.

### 21.4 Browser/E2E

- Mission-WDS-Feldlabel, permanenter Nicht-Vererbungs-Hinweis und Änderungsvorschau;
- VM-Editor mit ready, missing, case und multiple;
- Deployform reagiert auf Modus, VM-Auswahl, Alle, Host und Sticky State;
- Full/Powercycle/Export deaktivieren; Create/Start/Autostart warnen;
- exakte VM- und Missionsherkunft in Hostwarnungen;
- Storage-Case-Mismatch verwendet keine fremden Bytes;
- leer ausgewählt zeigt „alle X“;
- Reparaturlink mit und ohne Berechtigung;
- No-JS-POST, zwei Tabs und stale response;
- mobile Wrap-Grenze, beide Themes, 200 Prozent Zoom, Tastatur, axe;
- Screenreader-Stichprobe für dynamischen Blocker und Fokus.

### 21.5 Realer ESXi-/MECM-Stagingnachweis

Offline-Tests ersetzen nicht:

1. Inventarabruf eines Hosts mit realen Datacenter-/Datastore-/Portgruppennamen;
2. read-only Case-Variante im Portal gegen den echten Host;
3. isolierte Testmission mit genau einer WDS-NIC;
4. kontrollierter Export, der die MAC genau dieser NIC importiert;
5. negativer Test mit case-abweichender Testkonfiguration ohne Produktiv-VM-
   Mutation;
6. Prüfung, dass MECM die exportierte PXE-MAC der erwarteten NIC verwendet;
7. keine Behauptung, dass der Portaltest DHCP/WDS-Dienstgesundheit beweist.

## 22. Rollout, Bestand und Rückbau

### 22.1 Vor Rollout

1. App-, Worker- und Ansible-Revision/Digests dokumentieren.
2. Backup plus Restore-Nachweis gemäß ADR-0017.
3. Laufende/cancelling Jobs regulär drainieren.
4. Read-only Audit exportieren:
   - case-verschiedene Mission-/VM-/Cachewerte;
   - Missionen ohne WDS;
   - VMs mit null/eins/mehreren exakten WDS-Interfaces;
   - Nicht-WDS-MACs ohne WDS-MAC;
   - betroffene Jobs nur als IDs/Counts, keine Secrets.
5. Findings fachlich bestätigen; nichts automatisch korrigieren.

### 22.2 Deployment

1. Portal, Worker und Ansible-Artefakte aus derselben Revision deployen.
2. Migration ausführen und `migrate --check` bestehen.
3. PHP-/Worker-/Maintenance-Gesundheit prüfen.
4. Alle ESXi-Zugänge neu inventarisieren, bis Semantikversion 2 oder ein
   sichtbarer Abruffehler vorliegt.
5. Read-only Audit erneut ausführen.
6. Gültigen und ungültigen Canary durch Queue-/Worker-/Callbackpfad führen.
7. Erst danach reale betroffene Missionen freigeben.

### 22.3 Abbruchkriterien

Rollout sofort stoppen, wenn:

- ein case-abweichender Datastore wieder fremde Kapazität erhält;
- ein WDS-Blocker irgendeinen Remote-Upload oder ESXi-Schritt zulässt;
- `create`, `start` oder `autostart` allein wegen der speziellen WDS-Regel
  blockiert;
- ein Callback ohne verifizierte WDS-MAC eine VM auf `deployed/pending` setzt;
- ein Retry seinen Scope erweitert;
- Cachefehlen als Live-Abwesenheitsbeweis dargestellt wird;
- Migration und Fresh-Schema divergieren;
- Machine-API-Payload, Legacy-Statusstrings oder ADR-0030-Atomarität brechen;
- Help, DE/EN, CSP, RBAC, Schema-, SSoT- oder Guard-Gate rot ist.

### 22.4 Rückbau

- App/Worker/Ansible werden nur gemeinsam auf eine vorherige Revision gerollt.
- Die neuen case-sensitive Collations und additive Semantikspalte bleiben stehen;
  es gibt keinen destruktiven Down-Pfad.
- Inventarcache ist rekonstruierbar. Er darf bei einer kontrollierten
  Rückwärtskompatibilitätsmaßnahme neu abgerufen, aber nicht als Produktdatenbackup
  behandelt werden.
- Manuell bestätigte Portalnamen werden nie automatisch zurückgeschrieben.
- Vor einer Altcodeaktivierung wird geprüft, ob case-verschiedene Katalogzeilen
  dessen UI oder Sync verletzen. Ist das nicht bewiesen, bleibt der Rollback
  gesperrt und es wird vorwärts repariert.
- Historische `result_json`-Ergebnisse und Joblogs bleiben unverändert.

## 23. Definition of Done

Das Vorhaben ist erst abgeschlossen, wenn alle Punkte belegt sind:

- [ ] ADR-0023 und ADR-0030 besitzen die beschriebenen Amendments.
- [ ] Bestehender Netzwerk-/MAC-Plan verweist auf diese korrigierende SSoT.
- [ ] Operative VMware-Namensgleichheit ist überall case-sensitive.
- [ ] Der Diagnosekey kann niemals Erfolg oder Kapazität beweisen.
- [ ] Cache und VLAN-Katalog können Case-Varianten getrennt halten.
- [ ] Altcache wird bis zum Neuabruf nicht als exakter Beweis ausgegeben.
- [ ] `Daten` gegen `DATEN` ergibt Warnung plus offene Storagebewertung.
- [ ] Jede Warnung nennt Mission/VM/Interface und den exakten Wert.
- [ ] WDS-Portgruppe ist in Mission, VM, Deploy und Help eindeutig bezeichnet.
- [ ] Full, Powercycle und Export blockieren null/mehrfach/case-falsch.
- [ ] Create, Start und Autostart warnen, blockieren deswegen aber nicht.
- [ ] Explizite Auswahl und „leer = alle“ stimmen in UI, Queue, Worker und Callback.
- [ ] Queue- und Workerprüfung verwenden denselben Domainaggregator.
- [ ] Worker blockiert vor Upload und erster Remoteoperation.
- [ ] Hostcache bleibt warn-only.
- [ ] MAC-Callback verlangt die exakte WDS-NIC und deren gültige MAC.
- [ ] Eine beliebige andere NIC kann keinen VM-Erfolg mehr erzeugen.
- [ ] Per-VM-Atomarität, Partialstatus und Retryscope bleiben korrekt.
- [ ] `vm_results` ist additiv persistiert und historischer Bestand bleibt lesbar.
- [ ] Missions-WDS-Änderung verändert keine VM automatisch.
- [ ] Alle Writer, Import-/Klon-/Reassignpfade verwenden den Domainvertrag.
- [ ] Meldungen sind lokalisiert, handlungsfähig, responsive und barrierearm.
- [ ] Help und aktive Dokumentation beantworten alle Punkte aus Abschnitt 17.
- [ ] Unit-, Integration-, Static-, Guard-, E2E-, Visual- und Stagingmatrix ist grün.
- [ ] Fast-, Integration- und Release-Lane zeigen den vorgeschriebenen
  `[n/total]`-Fortschritt und sind grün.
- [ ] Backup, Bestandsaudit, Semantikversion-2-Inventar, Canary und
  Rollbackentscheidung sind dokumentiert.

Es bleiben danach nur die bewusst benannten physikalischen Grenzen: Ein
Inventar-Snapshot beweist keine aktuelle Hostkonfiguration, ein Portgruppenname
beweist keinen laufenden PXE-Dienst, und das Portal kann externe Änderungen
zwischen Prüfung und realem vSphere-Aufruf nicht verhindern. Es darf diese
Grenzen aber niemals als Erfolg darstellen oder durch Case-Folding verdecken.
