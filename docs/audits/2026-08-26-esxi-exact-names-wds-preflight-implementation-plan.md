# Umsetzungsplan: exakte ESXi-Namen, eindeutige WDS/PXE-Schnittstelle und ehrliche Deploy-Vorabprüfung

Stand: 27.08.2026

Status am 08.09.2026: 14A lokal implementiert; Standort-/Canary-Abnahme offen. Auch 14B ist inzwischen lokal implementiert, siehe Create-Abschluss und Masterplan. Die frühere Aussage „14B nicht begonnen“ ist überholt.

Korrekturstand: 27.08.2026. Eingearbeitet sind die expliziten Amendments zum
allgemeinen Netzwerkvertrag, kindweise Inventar-Namenssemantik, Datacenter-
Ableitung, atomare Staffelung, Callback-Modus-/Lock-/Idempotenzvertrag,
Writer-Matrix, Ergebnisinvarianten, Bounds, der irreversible Writer-Cutover sowie
die 48-Stunden-Namensevidenz mit Systemstatus als einziger operativer Detail-SSoT.

## 0. Geltung, Einordnung und Vorrang

Dieser Plan korrigiert und ergänzt den Netzwerk-/MAC-Teil der Etappe 14A aus
`docs/audits/2026-08-13-mac-import-vlan-ambiguity-qol-implementation-plan.md`.
Er erfindet keine zweite globale Ausführungsreihenfolge.

Bei Widersprüchen gilt ohne Auslegungsspielraum:

1. `docs/audits/2026-08-11-deploy-reliability-master-plan.md` bleibt die SSoT für
   Etappenreihenfolge, Etappenabschluss, QA-Lanes und Rolloutreihenfolge.
2. Der Plan vom 13.08.2026 bleibt Fach-SSoT für Remote-Recovery, den übrigen
   allgemeinen VM-Netzwerkvertrag, MAC-Retry, die übrigen per-VM-Ergebnisse und
   die Etappe 14A insgesamt. Die in den folgenden Punkten ausdrücklich
   amendierten Namens-, Staffel-, WDS-, Callback- und Resultatinvarianten sind
   davon ausgenommen.
3. Diese Datei ist die korrigierende Fach-SSoT für genau diese Teilbereiche:
   - Gleichheit und Ähnlichkeit von ESXi-Objektnamen;
   - case-sensitive Inventarhaltung;
   - Datacenter-, Datastore- und Portgruppenabweichungen;
   - Speicherbewertung bei abweichender Schreibweise;
   - die Missions-WDS/PXE-Portgruppe;
   - die Genau-eine-WDS-Schnittstelle-Regel;
   - deren Modus-, Auswahl-, Queue-, Worker- und Callbackvertrag;
   - Callback-Fingerprint, WDS-spezifische Resultatinvarianten und Bounds;
   - die zugehörige Portal-, Help-, Doku- und Fehlertaxonomie.
4. Sie ersetzt ausdrücklich die Abschnitte 3.1, 4.2 und 4.3 des Plans vom
   13.08.2026, soweit diese `esxi_inventory_name_key()`, Unicode-Kleinschreibung,
   den VLAN-Key oder `ambiguous_vlan` als Identität case-verschiedener
   Portgruppen verwenden. `Prod` und `prod` sind zwei verschiedene VMware-Ziele.
   `ambiguous_vlan` entsteht künftig nur durch mehrere exakt identische
   Portal-/ESXi-Zuordnungen, nie allein durch Case-Ähnlichkeit. Historische
   Resultate behalten ihre damalige Bedeutung und werden nicht umgeschrieben.
5. Sie ersetzt außerdem Abschnitt 3.5 Punkt 5 des Plans vom 13.08.2026 für die
   Erstellung einer Staffel: Vor dem Anlegen irgendeines Staffelslots wird der
   vollständige angeforderte Gesamtscope geprüft. Ein Blocker verhindert die
   gesamte Staffel. Nach erfolgreicher Gesamtprüfung wird jeder Einzelscope in
   derselben Transaktion erneut geprüft.
6. Die bisherige Aussage, `esxi_inventory_name_key()` definiere die operative
   Namensgleichheit durch `trim` plus Kleinschreibung, ist in diesen Bereichen
   verworfen. Case-Folding darf nach Umsetzung nur noch ähnliche Schreibweisen
   für eine Diagnose finden. Es darf nie Erfolg, Vorhandensein, Speicherplatz,
   MAC-Zuordnung oder Deployfähigkeit beweisen.
7. Die allgemeine Regel aus dem Plan vom 13.08.2026, dass leere oder innerhalb
   einer VM doppelte Portal-Portgruppenzuordnungen für Create-/Exportpfade nicht
   geraten werden, bleibt bestehen. Die neue WDS-Regel ist zusätzlich und besitzt
   eigene Codes, Meldungen und eine eigene Modusableitung. Nach diesem Amendment
   sind nur exakt gleiche Portal-Portgruppennamen Duplikate; Case-Varianten sind
   getrennte Ziele mit Diagnosewarnung.

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
3. Eine VMware-Portgruppe wird durch ihren exakten Namen adressiert. Ein manuell
   eingegebener Portalwert wird weiterhin zentral getrimmt; danach müssen
   Groß-/Kleinschreibung und alle verbleibenden Zeichen mit dem unveränderten
   ESXi-Rohnamen übereinstimmen.
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
13. `Prod` und `prod` dürfen als zwei getrennte Portgruppen derselben VM
    gespeichert und adressiert werden. Zwei exakt identische Portalwerte bleiben
    ein allgemeiner Mehrdeutigkeitsfehler.
14. Staffelung ist all-or-nothing. Ein Queueklick erzeugt nie still eine
    unvollständige Staffel.
15. Ein identischer zweiter MAC-Callback desselben Jobs ist nur in
    `running|cancelling`, im selben Attempt und in derselben Runtime-Generation
    `200`/no-op. Ein inhaltlich abweichender zweiter Callback sowie jeder
    Callback nach einem Terminalzustand ist `409`, schreibt keine Domainwerte
    und erzeugt höchstens das vertraglich erlaubte gedrosselte Audit.
16. Nach dem exakten Inventar-Cutover darf ein alter case-insensitiver
    Inventarwriter nicht mehr aktiviert werden. Ein Rückbau ist nur mit einem
    Compatibility-Backport zulässig, der jedes von ihm beantwortete Kind atomar
    wieder als Namenssemantik 1 kennzeichnet.
17. VMware-Namen mit führendem oder folgendem Whitespace werden im Inventar
    nicht still getrimmt und dadurch mit einem anderen ESXi-Objekt vereinigt.
    Der Snapshot bewahrt den Rohwert; Picker-, Sync- und Importpfade, die einen
    Inventar-Rohnamen als Portalziel materialisieren würden, weisen ihn als
    derzeit nicht unterstützte Objektbezeichnung verständlich ab. Manuelle
    Portaleingaben behalten ihre bestehende Eingabetrimmung.
18. Etappe 14A bereitet den Callbackvertrag für `full` vor und testet ihn
    offline, aktiviert aber weder Create noch Full remote. Callbackfähigkeit und
    Remote-Aktivierung erhalten getrennte Registries; die Aktivierung von
    Create/Full bleibt ausschließlich Etappe 14B.
19. Ein fehlender Missions-Datacenterwert darf höchstens 48 Stunden nach einer
    vollständig beantworteten, exakten Semantik-2-Datacenter-Abfrage automatisch
    aus dem gewählten ESXi-Zugang abgeleitet werden. Diese Frist bestätigt nur
    den Namen zum Abrufzeitpunkt, niemals aktuelle Erreichbarkeit oder
    Verfügbarkeit. Ein ausdrücklich gespeicherter Missionswert bleibt davon
    unberührt.
20. Der Systemstatus ist die einzige operative Detail-SSoT für ESXi-Abrufzustand,
    Datacenter-Namensevidenz, Fehlerursache, Refresh und Jobprotokoll. Mission,
    Deploy, Queue und Zugangsdaten wiederholen keine Ursachentabelle; sie zeigen
    nur den zentral präsentierten Kurzbefund und verlinken mit `system_status_url()`
    auf die konkrete ESXi-Karte.

## 2. Verifizierter Ist-Zustand

### 2.1 Produktionsbeleg

Deploy-Auftrag 311 scheiterte am 26.08.2026 mit
`Invalid datastore format 'Daten'`. Der Zielhost meldete den Datastore als
`DATEN`, die Mission speicherte `Daten`.

Das Portal bewertete die Namen über `esxi_inventory_name_key()` als gleich und
verwendete dadurch den freien Speicher von `DATEN` für den geplanten Datastore
`Daten`. Die Anzeige gab somit eine sachlich falsche Zusage, bevor
`community.vmware.vmware_guest` mit dem abweichenden Namen scheiterte.

Vor Umsetzung wird dieser Laufzeitbefund als unveränderliches redigiertes
Audit-Artefakt im Repository referenziert: Job-ID und bounded Logauszug,
ausgerollte App-/Worker-/Ansible-Revision oder Digest, verwendete
`community.vmware`-Version sowie normalisierter `SHOW CREATE TABLE`-/Collation-
Snapshot. Zugangsdaten, MACs und nicht benötigte Produktionsobjekte werden nicht
übernommen. Ohne diesen Nachweis bleibt der Befund plausibel, aber nicht
reproduzierbar und gilt nicht als Rolloutbeleg.

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
7. Cacheabgeleitete Hostabweichungen bleiben warn-only. Unbekannt bedeutet
   unbekannt und wird weder als vorhanden noch als nicht vorhanden behauptet.
   Die bestehende Ableitung eines fehlenden Datacenter-Pflichtwerts ist die
   ausdrücklich getrennte Ausnahme aus Abschnitt 13.4.
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
5. Die offizielle
   [`vmware_guest_info`-Dokumentation](https://docs.ansible.com/projects/ansible/latest/collections/community/vmware/vmware_guest_info_module.html)
   zeigt im Rückgabevertrag den von VirtuSphere ausgewerteten Netzwerknamen als
   `instance.hw_eth*.summary` zusammen mit `macaddress`. Das belegt das
   verfügbare Wire-Feld, aber nicht dessen eindeutige Zuordnung zu einer
   Portalzeile; diese Eindeutigkeit muss der Domainvertrag herstellen.
6. Microsoft beschreibt, dass Configuration Manager bei PXE-Geräten die
   anfragende MAC beziehungsweise SMBIOS-Identität gegen seine Gerätedatenbank
   abgleicht:
   [Use PXE for OSD](https://learn.microsoft.com/en-ie/mem/configmgr/osd/deploy-use/use-pxe-to-deploy-windows-over-the-network).
7. Microsoft beschreibt außerdem, dass DHCP und der PXE-fähige Distribution
   Point im selben VLAN erreichbar sein oder über IP Helper verbunden werden
   müssen:
   [Troubleshoot PXE boot issues](https://learn.microsoft.com/en-us/troubleshoot/mem/configmgr/os-deployment/troubleshoot-pxe-boot-issues).
   Daraus folgt keine Portaldetektion eines laufenden Dienstes. Es bestätigt nur,
   dass die richtige Netzwerkanbindung für PXE fachlich relevant ist.
8. MySQL 8.4 dokumentiert, dass Vergleiche nichtbinärer Strings ihrer Collation
   folgen und eine binary beziehungsweise case-sensitive Collation für
   case-sensitive Vergleiche erforderlich ist:
   [MySQL 8.4 Case Sensitivity](https://dev.mysql.com/doc/refman/8.4/en/case-sensitivity.html).
   Für `utf8mb4_0900_bin` dokumentiert MySQL zusätzlich Binärgewichte und
   `NO PAD`; damit bleiben auch nachgestellte Leerzeichen im DB-Vergleich
   unterscheidbar:
   [MySQL 8.4 Unicode Character Sets](https://dev.mysql.com/doc/refman/8.4/en/charset-unicode-sets.html).
9. W3C empfiehlt verständliche Gesamt- und Inlinefehler, Links zum betroffenen
   Control, `aria-describedby` und Fokus auf das erste fehlerhafte Feld:
   [WAI User Notification](https://www.w3.org/WAI/tutorials/forms/notifications/).

Herstellerquellen bestimmen externe Mechanik und Grenzen. Die konkrete
VirtuSphere-Modus-, Status- und Retrysemantik stammt ausschließlich aus den
Repositoryverträgen und den oben belegten Laufzeitbefunden.

Die Online-Links zeigen den aktuellen Herstellerstand, sind aber nicht die
ausführbare Dependency-SSoT. Vor Codeänderung und Staging werden dieselben
Feldpfade und Verhaltensaussagen zusätzlich mit dem installierten
`ansible-doc`/Collection-Artefakt der in `Ansible/requirements.yml` gepinnten
`community.vmware`-Version 6.2.0 belegt. Ein späteres `latest` darf die geprüfte
Pin-Wahrheit nicht still ersetzen.

## 4. Verbindliche Begriffe

| Begriff | Exakte Bedeutung |
|---|---|
| ESXi-Rohname | Vom Inventar gelieferter, bytegetreu erhaltener Name eines Datacenters, Datastores oder einer Portgruppe |
| Portal-Objektname | Durch den vorhandenen Writer validierter und getrimmter Name; Randwhitespace ist im Portalmodell nicht adressierbar |
| exakter Treffer | PHP-Stringgleichheit `===` zwischen gespeichertem Portalwert und unverändertem ESXi-Rohnamen; keine Case-, Whitespace- oder Unicode-Normalisierung |
| ähnliche Schreibweise | Kein exakter Treffer, aber gleiche Diagnoseform nach `mb_strtolower` bei zwei ansonsten unterstützten Namen; nur Reparaturhinweis |
| nicht unterstützter ESXi-Name | Druckbarer gültiger UTF-8-Rohname innerhalb der 255-Zeichen-Grenze mit führendem/folgendem Whitespace; wird erhalten und angezeigt, aber nie still als Portalziel materialisiert |
| nicht persistierbare Inventarzeile | Name ist leer/Nur-Whitespace, enthält NUL oder andere Steuer-/Formatzeichen oder überschreitet 255 Unicode-Zeichen; nur geschlossener Grund und Count werden protokolliert, nie der ungeeignete Rohwert |
| nicht dekodierbarer Inventarmarker | Base64-/JSON-Gesamtergebnis ist einschließlich UTF-8 ungültig; gesamter Pull ist Parsefehler, keine Zeile und kein Kind werden geraten |
| Datacenter-Namensevidenz | Letzte vollständig beantwortete Datacenter-Abfrage, deren Cachezeilen und Frische in derselben Transaktion mit Namenssemantik 2 festgeschrieben wurden |
| Ableitungsfenster | Feste 172800 Sekunden ab der Datacenter-Namensevidenz; exakt an der Grenze noch verwendbar, danach abgelaufen |
| letzter Inventarversuch | Neuester kindspezifisch persistierter Versuch mit Zeitpunkt, Ergebniscode und Job-ID; getrennt von der letzten erfolgreichen Namensevidenz |
| aktuelle ESXi-Verfügbarkeit | Zustand beim realen ESXi-/vSphere-Aufruf; weder Cache, Login, Ampel noch ein früherer erfolgreicher Abruf können ihn garantieren |
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

1. die unveränderte Übernahme und Validierung eines ESXi-Rohnamens;
2. die bestehende Portal-Eingabetrimmung als ausdrücklich getrennte Operation;
3. exakte Gleichheit ohne weitere Normalisierung;
4. einen Diagnosekey für Case-Ähnlichkeit unterstützter Namen;
5. die Klassifikation eines konfigurierten Werts gegen eine Menge von
   Inventarnamen.

Die Klassifikation ist geschlossen:

```text
exact
case_mismatch
missing
inventory_unknown
unsupported_name
```

`case_mismatch` trägt alle tatsächlich gemeldeten ähnlichen Kandidaten. Gibt es
mehr als einen, wird keiner als „der richtige“ vorgeschlagen. `missing` wird nur
verwendet, wenn eine bewertbare Inventarmenge existiert und weder exakter noch
ähnlicher Kandidat vorkommt. Ein nicht bewertbarer Cache liefert
`inventory_unknown`. `unsupported_name` benennt den unveränderten, druckbaren,
höchstens 255 Zeichen langen gültig kodierten Rohwert und den Grund
`boundary_whitespace`; dieser Zustand darf nicht zu `missing` oder
`case_mismatch` gerundet werden. Leere Namen, Nur-Whitespace, NUL, sonstige
Unicode-Steuerzeichen und Namen über 255 Unicode-Zeichen werden vor Persistenz
als nicht persistierbare Inventarzeilen gezählt. Sie erhalten keinen erfundenen
Ersatznamen und keinen Cacheeintrag. Ungültiges UTF-8 kann im vorhandenen
base64-verpackten Gesamt-JSON nicht zuverlässig einer einzelnen Zeile
zugeordnet werden: Es ist ein Marker-/Parsefehler des gesamten Pulls und niemals
ein erfundener per-row Count.

Für den allgemeinen Netzwerkvertrag gilt nach der vorhandenen Portalvalidierung:
Zwei Zeilen sind nur dann doppelt, wenn ihre gespeicherten Portgruppennamen mit
`===` gleich sind. `Prod` und `prod` sind keine Duplikate. Damit sind die
normalisierten VLAN-Keys aus den Abschnitten 3.1, 4.2 und 4.3 des Altplans für
neue Entscheidungen ausdrücklich außer Kraft.

Die Zeichenklassifikation ist ebenfalls SSoT und wird nicht per Seite mit
`trim()` angenähert:

- Unicode-Kategorien `Cc`, `Cf` und `Cs` sind an jeder Position nicht
  persistierbar; NUL behält seinen eigenen Grund, Tabs/Zeilenumbrüche,
  Zero-Width- und Bidi-Formatzeichen fallen unter den geschlossenen
  Control-/Formatgrund;
- ein Unicode-Separator aus `Zs|Zl|Zp` am Anfang oder Ende ist
  `unsupported_name/boundary_whitespace`; dazu gehören ASCII Space, NBSP und
  Em-Space;
- Separatoren innerhalb eines ansonsten gültigen Namens bleiben bytegetreu und
  sind Bestandteil der exakten Identität;
- NFC, NFD, Case, Breite und Kompatibilitätszeichen werden niemals normalisiert.

Tests decken mindestens ASCII Space, Tab, CR/LF, NBSP, Em-Space, U+200B,
U+202E, BOM sowie sichtbar gleiche NFC-/NFD-Varianten ab. Die Help weist knapp
darauf hin, dass optisch ähnliche Unicode-Namen technisch verschieden sein
können; sie listet keine zweite Zeichentabelle.

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
- Erfolg eines Deploy- oder Callbackresultats;
- Duplikaterkennung des allgemeinen Portal-Netzwerkvertrags.

Ein Static-Guard scannt diese Owner und schlägt auf
`esxi_inventory_name_key()` beziehungsweise auf inline
`strtolower(trim(...))` in operativen Vergleichen an. Nach vollständiger
Migration wird der mehrdeutig benannte alte Helper entfernt, nicht als Alias
weitergeführt.

### 5.3 SSoT-Matrix

| Wahrheit | Einzige SSoT |
|---|---|
| Portal-Eingabetrimmung | `Validator::optionalString()`; keine Verwendung für Inventar-Rohnamen |
| ESXi-Rohname | Objektname-Helper; unverändert erhalten plus Supportklassifikation |
| operative ESXi-Namensgleichheit | exakter Objektname-Helper |
| ähnliche Schreibweise | Diagnosekey desselben Helpers |
| Deploymodus und Playbookfolge | `ansible_playbooks_for_mode()` |
| Modus importiert MACs | aus dem Vorhandensein des Export-Playbooks abgeleiteter Helper |
| Job-VM-Scope | vorhandene Payload-Normalisierung und `vm_ids`-Regel |
| WDS-Ziel | `deploy_missions.wds_vlan` |
| VM-WDS-Befund | `vm_network_contract.php` aus Etappe 14A |
| Queueblocker | `deploy_queue_blockers()` |
| Warnungen | getrennter Warning-Aggregator über denselben Domainbefund |
| Hostinventarbeweis | Cachezeilen plus kindweise Frische und kindweise Namenssemantik |
| Datacenter-Ableitungsentscheidung | `esxi_datacenter_resolution()` über Cachezeilen, `kind_freshness_json`, `kind_name_semantics_json`, `kind_observation_json`, feste 48-Stunden-Grenze und ein requestweit gemeinsames `now` |
| Datacenter-Kurz-/Detailtext | ein zentraler Presenter über demselben Resolution- und Observation-Verdict; Seiten besitzen keine eigenen Alters-, Status- oder Ursachenbedingungen |
| ESXi-Detail und Fehlerbehebung | konkrete ESXi-Karte im Systemstatus über `system_status_url('credential-<id>', ['inventory' => <id>])` |
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
die heutige interne 191/255-Abweichung. Die Ingestion misst diese Grenze als
Unicode-Zeichen, schneidet niemals ab und behandelt einen längeren Namen gemäß
Abschnitt 7.1 als nicht verlustfrei persistierbar.

### 6.2 Kindweise Inventar-Semantikversion

Ein einzelner Versionswert je Credential ist verboten: Ein Pull kann Datastores
erfolgreich beantworten, während die Netzwerkabfrage fehlschlägt und alte
Netzwerkzeilen eingefroren bleiben. Die additive SSoT in
`deploy_esxi_inventory_state` heißt deshalb `kind_name_semantics_json`.

Der JSON-Wert ist eine geschlossene Map der Namen aus
`VIRTUSPHERE_INVENTORY_KINDS` auf die Integerwerte `1` oder `2`:

- fehlender Key beziehungsweise `NULL` bedeutet Semantik 1;
- bestehende Zustandszeilen werden nicht inhaltlich backgefüllt und gelten damit
  für jedes Kind als 1;
- Fresh-Schema startet mit `NULL`, nicht pauschal mit 2;
- nur ein Kind, dessen zugehörige Abfragen nach dem bestehenden
  Per-Query-Vertrag vollständig beantwortet wurden, wird in derselben
  Transaktion wie Cachezeilen und `kind_freshness_json` auf 2 gesetzt;
- answered-empty setzt das Kind ebenfalls auf 2 und erneuert dessen Frische:
  „Version 2, bekannt leer“ ist ein ausdrücklicher beweisbarer Zustand;
- failed oder skipped behält Cachezeilen, Frische und bisherigen Semantikwert
  dieses Kinds unverändert;
- ein Kind ist nur exakt bewertbar, wenn sein Semantikwert 2 und sein Eintrag in
  `kind_freshness_json` derselben erfolgreichen Antwort entstammt;
- unbekannte Keys oder Werte machen nur den betroffenen Beweis unbrauchbar und
  werden diagnostiziert; sie werden nie als 2 interpretiert.

Schreibhelper für `kind_freshness_json` und `kind_name_semantics_json` teilen
dieselbe validierte Kindliste und dieselbe Transaktion. Ein Guard verbietet einen
credentialweiten `name_semantics_version`-Ersatz.

Damit der Systemstatus einen späteren Fehlschlag nicht mit der älteren positiven
Namensevidenz vermischt, kommt additiv `kind_observation_json` hinzu. Die
geschlossene Map verwendet dieselben Keys aus `VIRTUSPHERE_INVENTORY_KINDS`; ihr
Wert besitzt ausschließlich:

```text
attempted_at
outcome       answered | failed | skipped
reason_code   nullable, geschlossene Query-/Inventar-Fehlerregistry
job_id        positive Integer-ID oder null für historischen Bestand
raw_item_count          nonnegative Integer oder null
persisted_item_count    nonnegative Integer oder null
supported_name_count    nonnegative Integer oder null
```

Die Counts werden nur bei `answered` gesetzt und erlauben die Unterscheidung
answered-empty, ausschließlich unsupported und mehrdeutig, ohne alte Frozen-
Cachezeilen als Teil der neuen Antwort zu lesen. `answered` umfasst auch ein
gültig beantwortetes leeres Ergebnis. Ein Transport-
oder Authentifizierungsfehler vor den Einzelabfragen schreibt für alle in diesem
Job vorgesehenen Kinds dasselbe `failed`-Observation-Objekt. Bei einer
Teilantwort wird jedes Kind getrennt geschrieben. Freitext des Upstream-Moduls
landet nur bounded im Joblog, nie in dieser Map. Ein späteres `failed|skipped`
ändert die letzte positive Frische und Namenssemantik nicht, aktualisiert aber
die Observation. So können gleichzeitig wahr sein: „Name vor vier Stunden
bestätigt" und „letzter Versuch vor fünf Minuten fehlgeschlagen".

### 6.3 Migration ohne Datenraten

Die Migration:

1. ändert keine gespeicherten Namen;
2. lowercaset, merged oder korrigiert keine Zeile;
3. löscht keinen Portalbestand;
4. baut Unique-Keys unter der neuen Collation reproduzierbar neu auf;
5. fügt `kind_name_semantics_json JSON NULL` und
   `kind_observation_json JSON NULL` hinzu; fehlende Semantikkeys sind Semantik 1,
   fehlende Observations sind unbekannt, daher gibt es kein ratendes
   Datenbackfill;
6. ist idempotent und besitzt Fresh-Schema-Konvergenztests;
7. protokolliert ausschließlich Counts, niemals Objektlisten mit Secrets;
8. enthält keinen Down-Pfad. Vorher gilt der Backupvertrag aus ADR-0017.

Case-Varianten, die der alte Cache bereits zusammengelegt hat, werden je Kind
erst mit dessen nächstem vollständig beantworteten Inventarabruf wieder sichtbar.
Bis dahin lautet das Verdict für dieses Kind „Inventar nach Update noch nicht
exakt verifiziert“. Ein erfolgreicher Datastore-Abruf zertifiziert niemals
Netzwerk-, Datacenter-, Host- oder VM-Zeilen.

### 6.4 Verbindlicher Migrationsbetriebsnachweis

Die Collationänderungen und Unique-Key-Neubauten dürfen erst in das
Produktionswartungsfenster, nachdem die exakt geplante Migration auf einem
zeitnahen, produktionsgroßen Restore-Klon gemessen wurde. Das Protokoll enthält
je betroffener Tabelle Zeilenzahl, `data_length`, `index_length`, Laufzeit,
Spitzenwert des zusätzlich belegten Datenträgerplatzes und beobachtete
Schreibblockade.

Verbindliche Freigabekriterien:

1. Der freie Platz im Produktions-DB-Dateisystem ist mindestens der auf dem Klon
   gemessene zusätzliche Spitzenbedarf plus 100 Prozent Reserve.
2. Das reservierte Wartungsfenster umfasst mindestens die doppelte gemessene
   Gesamtlaufzeit plus 30 Minuten für Vor-/Nachprüfung.
3. Nach Jobdrain werden sämtliche Portal-POSTs, Machine-API-Writer einschließlich
   MECM-ACK/ID/Packages/Report und `db_importMAC.php`, externe MECM-Scheduled-
   Tasks sowie Deploy- und Maintenance-Worker für die Schemaänderung gestoppt
   oder durch den gemeinsamen Maintenance-Gate abgewiesen. Erst dann ist die
   DDL-Phase write-free; „Portalwriter gestoppt" allein genügt nicht.
4. Für jedes `MODIFY` wird die vollständige bestehende Spaltendefinition
   einschließlich Typ, Länge, `NULL`, Default, Kommentar und Position aus
   Fresh-Schema/aktueller Migration übernommen. Ein verkürztes `MODIFY name
   VARCHAR(...)` ist verboten.
5. Normalisierte `SHOW CREATE TABLE`-Snapshots vor/nach dem Klontest beweisen,
   dass außer den ausdrücklich geplanten Collations, der Inventarnamenlänge, der
   Semantik-/Observationsspalten und deren betroffenen Indizes keine Defaults,
   FKs oder Indizes abweichen.
6. Migration, `migrate --check`, Fresh-Schema-Konvergenz und ein Rollback der
   Applikationsfreigabe werden auf dem Klon geprobt. Es gibt weiterhin keinen
   destruktiven Schema-Down-Pfad.

Erfüllt der Klon einen Punkt nicht, findet kein Produktionsrollout statt. Die
Messwerte werden nicht aus Entwicklungsdaten hochgerechnet.

## 7. Inventar, Katalog und Abweichungslogik

### 7.1 Cache-Ingestion

`repo_esxi_inventory_dedupe()` dedupliziert künftig nur noch exakt identische
ESXi-Rohnamen. Case-Varianten und Whitespace-Varianten bleiben getrennte Zeilen.
Der Ingest trimmt Inventarnamen nicht. Leerstring, Nur-Whitespace, NUL, andere
Unicode-Steuerzeichen oder mehr als 255 Unicode-Zeichen werden mit einem der
geschlossenen Gründe `empty`, `whitespace_only`, `nul`, `control_character`
oder `exceeds_internal_name_limit` gezählt;
der Rohinhalt landet weder in Cache, Log noch Audit. Ein ansonsten druckbarer,
gültiger Name mit mindestens einem Nicht-Whitespace-Zeichen und Randwhitespace
wird bytegetreu gespeichert und mit
`unsupported_name/boundary_whitespace` gekennzeichnet, damit kein
Informationsverlust als vermeintliche Bereinigung erscheint.

Bereits eine nicht persistierbare Zeile macht nur das betroffene Inventarkind für
diesen Pull zu `failed/unsupported_inventory_name`. Für dieses Kind werden weder
Cachezeilen ersetzt noch Katalogzeilen retired noch Frische oder Namenssemantik
fortgeschrieben. Andere vollständig beantwortete Kinds dürfen in derselben
Gesamtausführung unabhängig committen. Ein teilweise gespeichertes Kind darf nie
als Semantik 2 erscheinen.

Scheitert `json_decode()` des base64-verpackten Gesamtmarkers an ungültigem
UTF-8, ist das Ergebnis `parse/invalid_utf8_marker`: Kein Kind, keine Zeile,
keine Frische und keine Semantik werden fortgeschrieben. Eine per-row
UTF-8-Diagnose wäre erst mit einem anderen, feldweise verlustfreien Wireformat
möglich und wird in diesem Vorhaben bewusst nicht erfunden.

Meldet derselbe Zugang denselben exakten Namen mehrfach aus unterschiedlichen
Abfragequellen, wird der erste Datensatz nicht still zur eindeutigen Wahrheit.
Der Cache hält im `meta_json` einen bounded Herkunfts-/Treffercount. Die Anzeige
kennzeichnet den Namen als mehrfach gemeldet. Wegen der Cache-Regel bleibt dies
eine Warnung; es wird kein Zielobjekt geraten. Ein Datastore mit `source_count >
1` liefert keine belastbare Kapazität, und die Datacenter-Ableitung verlangt
einen gesamten Sourcecount von genau 1, nicht nur eine eindeutige Namenszeile.

### 7.2 VLAN-/Portgruppenkatalog

Der Katalog ist weiterhin ESXi-owned und read-only im Portal. Neu gilt:

- Case-Varianten sind getrennte Katalogzeilen;
- ausschließlich unterstützte Netzwerkzeilen eines Kinds mit Namenssemantik 2
  dürfen einen exakten Namen upserten, reaktivieren oder als Abwesenheitsbeweis
  verwenden; Semantik 1 und unsupported Namen bleiben Diagnosebestand;
- während des Cutovers ist Retirement vollständig eingefroren, bis jeder aktive,
  für Inventar vorgesehene ESXi-Zugang eine Semantik-2-Netzwerkbaseline besitzt
  oder bewusst deaktiviert/entfernt wurde; ein einzelner neuer Zugang darf keine
  Namen eines noch nicht neu inventarisierten Zugangs retiren;
- nach dieser Baseline bleibt der letzte erfolgreiche Semantik-2-Netzwerkstand
  eines später fehlgeschlagenen Zugangs positive Frozen-Evidenz. Failed/skipped
  ist niemals Abwesenheitsbeweis und löst kein Retirement aus;
- answered-empty darf erst nach derselben Baseline und nur als vollständig
  beantwortetes Semantik-2-Kind in die Retiremententscheidung eingehen;
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
match_state           exact | case_mismatch | missing | inventory_unknown |
                      unsupported_name
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
kind_name_semantics
candidate_total
candidate_omitted_count
unsupported_reason     nullable
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

`ansible_storage_by_datastore()` gruppiert gespeicherte Portalwerte künftig
exakt. Die Kapazitätskarte verwendet unveränderte unterstützte ESXi-Rohnamen.
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
portal_wds_interface_missing
portal_wds_interface_case_mismatch
portal_wds_interface_ambiguous
```

Regeln:

1. `mission_wds_missing`: Missionswert nach Trim leer.
2. `ready`: genau ein VM-Interface trifft den Missionswert exakt.
3. `portal_wds_interface_case_mismatch`: kein exakter Treffer, aber mindestens ein
   nur case-abweichender Kandidat. Alle Kandidaten werden genannt.
4. `portal_wds_interface_missing`: weder exakter noch ähnlicher Kandidat.
5. `portal_wds_interface_ambiguous`: mehr als ein exakter Treffer. Interface-IDs und
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
5. Mehrere exakte WDS-Schnittstellen: beide Controls erhalten eine WDS-Warnung
   über `aria-describedby`, Zusammenfassung und Links. `aria-invalid=true` wird
   nur gesetzt, wenn der getrennte allgemeine Netzwerkvertrag den konkreten
   Speichervorgang tatsächlich ablehnt.
6. Keine passende Schnittstelle: Zusammenfassung über dem Netzwerkbereich und
   Hinweis, welche Portgruppe ergänzt werden muss.
7. Das bestehende Badge/Feedback für leere oder allgemein doppelte Portgruppen
   bleibt getrennt.
8. Ohne JavaScript bleiben serverseitige Fehler, Eingaben und Links vollständig.
9. Der Editor darf niemals automatisch die erste NIC zur WDS-NIC erklären.
10. Neue VMs dürfen die Missions-WDS-Portgruppe weiterhin als erste
    Vorbelegung erhalten. Das ist eine editierbare Vorgabe, keine vererbte
    Bindung.

### 11.1 Verbindliche Writer-Matrix

WDS-spezifische Zustände entscheiden keinen Portalwriter. Sie werden erst in
exporthaltigen Deploypfaden hart. Davon getrennt bleibt der allgemeine
Netzwerkvertrag nach seinem hier amendierten exakten Namensbegriff gültig.

| Writer | WDS missing/case/ambiguous | Allgemeiner leerer/exakt doppelter Portgruppenwert | Weitere Sperre |
|---|---|---|---|
| Mission bearbeiten | Speichern erlaubt, Auswirkungswarnung und VM-Counts | nicht anwendbar auf Interfacebundle | aktive Job-Sperre |
| VM bearbeiten, Netzwerkbundle unverändert | Speichern unabhängiger Felder erlaubt, Warnung | vorhandenes Grandfathering des Altplans | aktive Scope-Sperre |
| VM bearbeiten, Netzwerkbundle geändert | Speichern wegen WDS allein erlaubt, Warnung | vollständiger geänderter Bundle muss gültig sein | aktive Scope-Sperre |
| Mission importieren | Preview und Commit erlaubt, positionierte Warnung | allgemeiner Importvertrag entscheidet | aktive Konflikte/Schemafehler |
| Mission/Template klonen | Klon erlaubt, Ergebnis nennt betroffene VMs | allgemeiner Klonvertrag entscheidet | Namens-/Aktivjobkonflikte |
| VM in Mission verschieben | Transfer erlaubt, Ziel-WDS wird neu bewertet | allgemeiner Transfervertrag entscheidet | aktive Scope-Sperre |
| Portgruppe massenweise neu zuweisen | Simulation zeigt WDS-Auswirkung; WDS allein blockiert nicht | exakt doppelter Zielzustand blockiert gesamte Aktion | atomar, kein Teilwrite |

Jeder erfolgreiche Writer zeigt danach den neuen WDS-Zustand, behauptet aber
keine Deploybereitschaft. Warnungen verwenden `aria-describedby`; nur eine
tatsächliche Writerablehnung verwendet `aria-invalid`, Fehlerzusammenfassung
und Fokus. Kein Writer korrigiert eine andere NIC oder die Missions-WDS-
Portgruppe automatisch.

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
4. bestehende Missions-/Credential-/Identity-/Datacenter-Gates ausführen;
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
Staffelung ist atomar: Zuerst wird der vollständige angeforderte Gesamtscope
geprüft. Entsteht für irgendeine VM ein Blocker, wird keine Gruppen- oder
Staffelzeile angelegt. Erst nach grüner Gesamtprüfung werden alle Einzelscopes in
derselben Transaktion erneut geprüft und vollständig geschrieben. Damit ist
Abschnitt 3.5 Punkt 5 des Altplans ausdrücklich ersetzt.

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
[2/5] FAIL WDS/PXE preflight APP02: portal_wds_interface_case_mismatch expected=WDS-VLan actual=WDS-VLAN
```

Die technische Zeile bleibt redigiert und bounded. Portaltexte werden über den
zentralen Presenter lokalisiert.

### 13.4 Cacheabweichung bleibt warn-only; fehlender Datacenter-Pflichtwert nicht

Ein gespeicherter Missions-/VM-Wert wird nie allein deshalb abgelehnt, weil der
letzte Inventarsnapshot die Portgruppe, den Datastore oder das Datacenter nicht
enthält oder anders schreibt. Das reale Ansible-/vSphere-Ergebnis bleibt die
Autorität. Ein Cachebefund darf im Joblog als Vorabhinweis erscheinen, aber nicht
als bewiesener Livefehler.

Die bestehende Datacenter-Ableitung aus ADR-0023 ist keine Cacheabweichung,
sondern das Füllen eines für `serverlist.yml` erforderlichen Werts. Fehlt der
gespeicherte Missionswert, darf der zentrale Resolver nur dann einen Wert
liefern, wenn gleichzeitig:

1. das Datacenter-Kind vollständig beantwortet wurde;
2. `kind_freshness_json.datacenter` den erfolgreichen Abruf belegt;
3. `kind_name_semantics_json.datacenter === 2` gilt;
4. genau ein unterstützter, exakter ESXi-Rohname gemeldet wurde;
5. der Erfolgszeitpunkt höchstens 172800 Sekunden zurückliegt.

Die feste Produktgrenze heißt
`VIRTUSPHERE_ESXI_DATACENTER_DERIVATION_MAX_AGE_SECONDS = 172800` und liegt in
der zentralen Deploy-Constants-/Bounds-SSoT. Sie ist keine Einstellung. Exakt
172800 Sekunden sind noch verwendbar, ab 172801 Sekunden ist die Evidenz
abgelaufen. Alle Verbraucher desselben Requests verwenden denselben injizierten
UTC-`now`-Wert. Ein fehlender, unlesbarer oder in der Zukunft liegender
Frischezeitpunkt ist kein positiver Beweis und ergibt einen eigenen geschlossenen
Grund; er wird nicht auf Alter 0 geklemmt.

Der Resolver liefert keinen nackten String, sondern ein geschlossenes Verdict:

```text
resolution       resolved | expired | never_confirmed | semantics_unverified |
                 answered_empty | ambiguous | unsupported | timestamp_invalid
name             nullable, nur bei resolved
confirmed_at     nullable
age_seconds      nullable
remaining_seconds nullable
semantics        1 | 2 | unknown
supported_name_count
observation      attempted_at | outcome | reason_code | job_id, jeweils nullable
```

`repo_esxi_sole_datacenter()` wird entfernt oder bleibt ausschließlich ein dünner
Kompatibilitätswrapper über diesem Verdict. Es darf keine zweite Alters- oder
Namensentscheidung besitzen. Vorschau, finaler Queue-POST,
`repo_deploy_assert_mission_ready()`, Worker und `ansible_serverlist_yml()` lesen
denselben Resolver. Ein vorhandener Missionswert überspringt die automatische
Ableitung wie heute vollständig und wird durch alten oder fehlgeschlagenen Cache
weder verworfen noch überschrieben.

Ein späterer fehlgeschlagener oder übersprungener Inventarversuch invalidiert
eine höchstens 48 Stunden alte positive Namensevidenz nicht: Der Fehlschlag
beweist weder Umbenennung noch Abwesenheit. Er wird als zweite, unabhängige Achse
angezeigt. Eine spätere vollständig beantwortete Datacenter-Abfrage mit null,
mehreren oder nicht unterstützten Namen ersetzt dagegen die frühere positive
Namensevidenz für die Ableitungsentscheidung sofort. Der reale
Ansible-/vSphere-Aufruf bleibt in allen Fällen die Autorität für die aktuelle
Verfügbarkeit.

Ist die Mission leer und das Verdict nicht `resolved`, blockiert
`datacenter_required_unresolved` vor jedem Upload und Playbook. Der Presenter
unterscheidet den Grund, behauptet aber nie, der Host besitze kein Datacenter.
Ein Job, der innerhalb des Fensters eingereiht wurde, aber erst nach Ablauf
startet, wird beim Worker-Recheck mit `configuration_blocked` beendet. Nach dem
ersten Remote-Upload wird die Frist nicht mitten im laufenden Job erneut
bewertet. Das Preflight-Verdict unmittelbar vor der Remote-Grenze ist der
maßgebliche Snapshot.

### 13.5 Gewählte Anzeige- und Ownerlösung

Geprüfte Varianten:

1. Vollständige Zeit-, Ursachen- und Hilfetexte auf Mission, Deploy,
   Zugangsdaten und Systemstatus zu wiederholen wird verworfen: vier Presenter
   und vier Erklärungen würden unvermeidlich driften.
2. Eine neue Integrations-Detailseite wird verworfen: Der Systemstatus besitzt
   bereits ESXi-Karten, Einzelrefresh, `last_attempt_at`, `last_success_at`,
   Fehlerkategorie, Jobloglink und Inventardetails.
3. Einen synchronen ESXi-Livecheck bei jeder Vorschau auszuführen wird verworfen:
   Er erhöht Latenz und Lockout-/Last-Risiko und könnte die Verfügbarkeit bis zum
   späteren Deploy trotzdem nicht garantieren.
4. Gewählt ist ein zentraler Domainresolver plus ein zentraler Presenter;
   Systemstatus ist Detail-Owner, alle anderen Seiten sind kompakte Verbraucher
   mit Deep Link.

Die konkrete ESXi-Karte im Systemstatus zeigt, soweit Daten vorhanden sind:

```text
Datacenter-Name `ha-datacenter` zuletzt vor 12 Stunden bestätigt.
Automatische Namensableitung noch 36 Stunden zulässig.
Letzter Inventarversuch vor 5 Minuten fehlgeschlagen: Host nicht erreichbar.
Die aktuelle ESXi-Verfügbarkeit ist unbekannt.
```

„Bestätigt" und „zulässig" beziehen sich ausschließlich auf die automatische
Namensableitung. Wörter wie „verfügbar", „erreichbar", „gesund" oder „gültiger
Host" sind dafür verboten. Relative Dauern verwenden
`portal_format_duration()`, absolute Zeitstempel
`portal_format_timestamp()`. Das bereits vorhandene Datacenter-Kinddetail bleibt
die absolute Zeitquelle; die zusammengeklappte Karte zeigt die relative
Kurzfassung, nicht denselben vollständigen Absatz ein zweites Mal.

Deploy-Vorschau und Queue zeigen bei ausgewähltem Zugang höchstens die kompakte
Fassung aus demselben Presenter:

```text
Datacenter-Name `ha-datacenter` vor 12 Stunden bestätigt; automatische
Namensableitung noch 36 Stunden zulässig. Letzter Inventarversuch fehlgeschlagen.
Aktuelle ESXi-Verfügbarkeit unbekannt. [ESXi-Status öffnen]
```

Fehlerkategorie, exakter Versuchszeitpunkt, Refresh, Auth-Pause und Joblog stehen
nur auf der verlinkten Systemstatuskarte. Bei abgelaufener Evidenz lautet der
kompakte Blocker sinngemäß:

```text
Datacenter kann nicht automatisch bestimmt werden. Die letzte bestätigte
Datacenter-Abfrage ist älter als 48 Stunden. [ESXi-Status öffnen]
[Datacenter in der Mission festlegen]
```

Der Systemstatuslink wird mit `system_status_url()` auf Karte und geöffnetes
Inventardetail gebaut. Der Link zur Mission erscheint nur mit der
Zielberechtigung; die Ursache bleibt für jeden berechtigten Portalbetrachter
sichtbar. Die Zugangsdatenliste behält ausschließlich ihr bereits vorhandenes,
verlinktes Statusbadge. Dashboard, Missionsliste und VM-Liste erhalten keine
weitere Datacenter-Zeile. Die Missionsmaske erklärt bei leerem Wert nur die
48-Stunden-Voraussetzung und verlinkt auf den Systemstatus, weil dort noch kein
Zielzugang gewählt ist.

Ein konfiguriertes Inventarintervall von `0` oder mindestens 48 Stunden bleibt für
Installationen mit expliziten Missionswerten zulässig. Die Einstellungsseite
zeigt jedoch aus demselben Konstanten-/Presentervertrag eine Warnung, dass die
automatische Datacenter-Ableitung regelmäßig oder dauerhaft ablaufen kann. Das
Defaultintervall von sechs Stunden bleibt unverändert. Die Einstellung löst
keine credential- oder missionsabhängige Sonderplanung aus. Bereits exakt 48
Stunden erhalten die Warnung, weil Queuewartezeit und Abrufdauer sonst in jedem
gesunden Zyklus ein unvermeidbares Ablaufloch erzeugen würden.

Fehlertexte eines Upstream-Moduls werden nicht per Stringmuster in eine andere
Ursache umgedeutet. Eigene präzise Ursachen entstehen nur aus eigenen
deterministischen Prüfungen.

## 14. MAC-Callback und VM-Statuswahrheit

### 14.1 Autoritatives Job-/Step-Gate und Lockreihenfolge

Der Read vor `begin_transaction()` bleibt höchstens ein schneller
Ablehnungshinweis. Die einzige schreibberechtigende Entscheidung fällt unter
Locks mit raw prepared statements in dieser festen Reihenfolge:

1. Mission einschließlich `wds_vlan` `FOR UPDATE`;
2. Job derselben Mission einschließlich `status`, `attempts`, `payload_json`,
   vorhandenem `result_json`, `execution_contract` und
   `LOWER(HEX(execution_generation_id))` `FOR UPDATE`;
3. `deploy_runtime_identity.id = 1` einschließlich
   `LOWER(HEX(current_generation_id))` `FOR UPDATE`;
4. bei `execution_contract=remote_v1` genau die
   `deploy_remote_executions`-Zeile für `job_id`, den aktuellen
   `job_attempt=deploy_jobs.attempts` und `step_key='export'` `FOR UPDATE`;
5. erwartete Scope-VMs nach ID `FOR UPDATE`;
6. deren Interfaces nach VM- und Interface-ID `FOR UPDATE`;
7. sonstige Identitäts-/MAC-Konfliktzeilen in der bereits zentral definierten
   Reihenfolge.

Die fachliche Grundreihenfolge bleibt damit
`Mission -> Job -> VM -> Interfaces`. Die dazwischen liegenden Fencingzeilen
werden immer in derselben Reihenfolge `Runtime-Identität -> Remote-Export-Handle`
gesperrt. Repository- und Integrationtests müssen beweisen, dass kein beteiligter
Callback-, Worker- oder Recoverypfad diese Reihenfolge invertiert.

Nach dem Joblock gilt fail-closed:

- Status ist `running` oder gemäß ADR-0033 `cancelling`;
- Payloadmodus ist bekannt und normalisierbar;
- `ansible_mode_expects_mac_result()`, ausschließlich aus
  `ansible_playbooks_for_mode()` abgeleitet, liefert true;
- die getrennte `callback_step_expectation_registry()` enthält für den
  normalisierten Modus den Step-Key `export` mit exakt
  `callback_expectation=db_import_mac`; Modushelper, Playbookfolge und diese
  Registry müssen in einem Contracttest übereinstimmen. Sie erteilt keine
  Remote-Aktivierung und ergänzt `full` nicht zu
  `VIRTUSPHERE_REMOTE_POLICY_MODES`;
- `execution_contract` ist exakt `legacy_v1` oder `remote_v1`, und die nicht
  leere Jobgeneration stimmt per `hash_equals()` mit
  `deploy_runtime_identity.current_generation_id` überein;
- bei `remote_v1` existiert genau das unter Punkt 4 gesperrte Export-Handle,
  dessen `job_attempt` dem gesperrten `deploy_jobs.attempts`, dessen `step_key`
  `export` und dessen `generation_id` derselben aktuellen Generation entspricht;
- bei `legacy_v1` wird gemäß dem bereits geltenden Remote-Recovery-Vertrag kein
  Remote-Handle erfunden; die Freigabe beruht dann auf aktivem Job,
  unverändertem Attempt, aktueller Generation und der übereinstimmenden
  Modus-/Playbook-/Step-Policy;
- Mission, Job, Scope und aktuelle Ausführung gehören zusammen.

`create`, `start`, `autostart`, `inventory`, unbekannte Modi, fehlender
Export-Step, NULL-/unbekannter Ausführungsvertrag, leere oder fremde Generation,
fehlendes beziehungsweise falsches `remote_v1`-Handle oder abweichender Attempt
ergeben `409` ohne Writes. Ein Remote-Handle eines früheren Attempts darf niemals
als Ersatz dienen. Der Machine-Payload bleibt unverändert; die Zuordnung erfolgt
serverseitig über die vorhandene `job_id` und den persistierten
Ausführungsvertrag. Das funktioniert nur, weil Remote-Recovery keinen zweiten
konkurrierenden Export desselben Jobs starten darf; dieser Vertrag aus dem
Masterplan bleibt Voraussetzung.

### 14.2 Doppelte Callbacks

Der erste akzeptierte Callback persistiert additiv einen
`callback_fingerprint`: SHA-256 über kanonisches, versioniertes JSON der
erwarteten VM-IDs und der gesamten normalisierten semantischen Anfrage. Dazu
gehören alle für Outcome oder Fehlervertrag relevanten gültigen, fehlenden,
malformed, fremden, unscoped und doppelten Zeilen einschließlich ihrer
Multiplizität. Keyreihenfolge und reine Listensortierung werden kanonisiert;
Duplikate dürfen dabei nicht zu einer Menge zusammenfallen. Ignoriert werden nur
eine geschlossene Allowlist rein diagnostischer Modulmetadaten und
Fehlerfreitext, die weder Code noch Outcome verändern dürfen. Eine zusätzliche,
entfernte oder veränderte semantische Zeile muss einen anderen Fingerprint
erzeugen.

Unter Mission- und Joblock gilt vor jedem VM-/Interfacewrite:

- existiert noch kein finales `result_json`, wird normal geplant;
- existiert bei weiterhin `running|cancelling`, gleichem Attempt und gleicher
  Generation ein valides `mac_import`-Resultat mit demselben Fingerprint,
  antwortet der Endpoint mit dem gespeicherten Vertrag als `200`/no-op; kein
  Interface-, VM-, Statusevent-, Resultat- oder Auditwrite wird wiederholt;
- existiert ein anderer Fingerprint, eine andere Resultatart oder ein
  unlesbares Resultat, folgt `409 callback_result_conflict` ohne Writes;
- ist der Job inzwischen terminal, folgt unabhängig vom Fingerprint `409`; ein
  historisches Resultat wird nicht als erneute aktive Verarbeitung quittiert;
- der Konfliktaudit nutzt
  `VIRTUSPHERE_AUDIT_EVENT_MACHINE_API_CALLBACK_REJECTED`, Reason-Code und die
  vorhandene Machine-API-Drosselung von 3600 Sekunden mit Scope `job:<id>`;
  es entsteht kein zweiter Logger.

Ein last-writer-wins-Update von `result_json` ist verboten.

### 14.3 WDS- und Identitätsvoraussetzungen

`db_importMAC.php` verwendet die unter Abschnitt 14.1 gesperrte Mission. Für
jede erwartete VM gilt vor einem Write:

1. Die Portalmission besitzt eine nicht leere WDS-Portgruppe.
2. Die Portal-VM besitzt genau ein Interface mit exakt diesem Wert.
3. Das ESXi-Ergebnis enthält genau eine NIC mit exakt diesem Network-Summary.
4. Diese NIC besitzt eine gültige MAC.
5. Die MAC ist nicht widersprüchlich oder einer anderen VM zugeordnet.
6. Der vom ESXi-Ergebnis gemeldete VM-Name trifft den gespeicherten Portalnamen
   mit `===`. Der heutige case-insensitive SQL-Fallback wird entfernt; die
   Mission-VMs werden einmal gesperrt geladen und in PHP exakt indiziert.
7. MOID-/Instance-UUID-Prüfung und alle übrigen
   Portal-/ESXi-VM-Identitätsregeln sind erfolgreich.
8. Alle übrigen heutigen per-VM-Atomaritätsguards bleiben erfüllt.

Erst dann darf die VM in `successful_vm_ids` erscheinen.

### 14.4 Exakte MAC-Zuordnung

Die heutige SQL-Zuordnung unter `utf8mb4_unicode_ci` wird ersetzt. Der
Callbackplan lädt alle Interfaces einer erwarteten VM sperrend und baut in PHP
eine exakte Map. DB-Collation und zufällige Querytreffer entscheiden nicht mehr.

Case-only Kandidaten liefern einen eigenen Fehler mit Soll und gemeldetem Wert.
Mehrere exakte Portal- oder ESXi-Treffer werden nie auf den ersten reduziert.

### 14.5 Geschlossene neue Callbackcodes

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

Diese Tokens sind zugleich die einzigen WDS-Codes des Domainhelpers, des
Queue-/Workerresultats, des Callbackresultats und des Presenters. Es gibt keine
zweite `vm_wds_interface_*`-Familie. Eine zentrale exhaustive Registry ordnet
jeden Code Quelle, Schwere, Retryklasse und DE/EN-Key zu. Ein Code in nur einem
Producer oder Presenter bricht den Registrytest. Das historische
`ambiguous_vlan` bleibt für allgemeine exakt doppelte Zuordnungen lesbar;
Case-Ähnlichkeit erzeugt es nach dem Cutover nicht mehr.

### 14.6 Erfolg und Statusübergang

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

### 14.7 Resultatvertrag und Invarianten

ADR-0030 Version 1 bleibt ausschließlich als historischer Lesevertrag erhalten.
Neue Writes verwenden Resultatversion 2. Dadurch kann der Decoder ein altes V1
ohne neue Felder von einem beschädigten neuen Resultat unterscheiden. V2
persistiert verpflichtend die bereits geplanten `vm_results`, den
`callback_fingerprint` aus Abschnitt 14.2 und bounded WDS-Kontext ohne
Secretwerte:

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

Für jedes neu geschriebene `mac_import`-Resultat gelten gleichzeitig:

1. Für jede erwartete VM existiert genau ein `vm_results`-Eintrag; unbekannte
   oder unscoped Inputzeilen bleiben ausschließlich in der bounded
   Top-Level-Fehlerliste.
2. `successful_vm_ids` und `failed_vm_ids` sind sortiert, duplikatfrei,
   disjunkt und ihre Vereinigung ist exakt der erwartete Jobscope.
3. `counts` wird aus den validierten Mengen und `vm_results` berechnet. Werte
   aus dem Callback werden niemals übernommen.
4. Top-Level-`outcome` wird aus der VM-Bilanz abgeleitet: alle erfolgreich =
   `success`, gemischt = `partial`, kein Erfolg = `failed`.
5. `vm_results[*].outcome=success` gilt genau dann, wenn die VM in
   `successful_vm_ids` liegt. `wds.verified=true` ist nur bei diesem Erfolg
   zulässig und verlangt den atomar committeten MAC-Write der eindeutigen
   WDS-Schnittstelle.
6. Ein fehlender, doppelter oder fremder VM-Eintrag, widersprüchliche Counts,
   überlappende IDs, `wds.verified=true` bei Fehler oder ein unbekannter Code
   macht den neuen Vertrag unverwertbar. Der Decoder vertraut niemals nur dem
   Top-Level-`outcome`.
7. Ein unverwertbarer Vertrag verhindert automatischen Retry und wird als
   `protocol_error/manual_required` behandelt; sein Scope wird nie auf die
   ganze Mission erweitert.
8. Reihenfolgen sind kanonisch: VM-IDs numerisch, Codes lexikalisch binär,
   Fehlerobjekte nach VM-ID, Code und exaktem Objektwert.

Historische Version-1-Ergebnisse ohne `vm_results` bleiben eingeschränkt gültig,
werden aber
in der Portalansicht ausdrücklich als historisch ohne per-VM-WDS-Nachweis
bezeichnet. Sie werden niemals rückwirkend umgeschrieben.

Der Decoder akzeptiert nur die zwei ausdrücklich registrierten Formen: V1 mit
dem historischen Pflichtfeldsatz und ohne erfundene V2-Invarianten, oder V2 mit
vollständigem Fingerprint, `vm_results`, WDS-Beweis und sämtlichen Invarianten.
Ein V2 ohne irgendein Pflichtfeld ist korrupt und fail-closed. Datum, Job-ID oder
Feldanwesenheit dürfen nicht als stiller Versionsersatz dienen.

`mac_import_decode_result()` liefert die Fehler-/VM-Details an Presenter und
Retry-Gate weiter. Unbekannte Codes bleiben fail-closed und erscheinen escaped
als technischer Token.

### 14.8 Callback- und Resultatbounds

Folgende Grenzen liegen in der zentralen Bounds-SSoT und werden zwischen PHP,
Uploader, Tests und Hilfe synchronisiert:

| Konstante | Wert | Vertrag |
|---|---:|---|
| `VIRTUSPHERE_MAC_IMPORT_REQUEST_MAX_BYTES` | 16777216 | größere Requests erhalten vor JSON-Decoding `413`, ohne DB-Write |
| `VIRTUSPHERE_MAC_IMPORT_RESULT_MAX_BYTES` | 1048576 | vollständiges kanonisches persistiertes V2-Resultat |
| `VIRTUSPHERE_MAC_IMPORT_RESPONSE_MAX_BYTES` | 1048576 | vollständige serialisierte HTTP-Maschinenantwort einschließlich Legacyfeldern und Envelope |

Die Responsegrenze entspricht dem vorhandenen
`Ansible/upload_mac_list.py::MAX_RESPONSE_BYTES`. Resultat und Antwort werden
getrennt vollständig mit `JSON_THROW_ON_ERROR` serialisiert und vor irgendeinem
Interface-/VM-/Resultatwrite geprüft; ein passendes Resultat beweist nicht, dass
die zusätzliche Maschinenantwort ebenfalls passt. Nichts wird durch Abschneiden
von `vm_results`, Fehlern oder Legacydiagnostik passend gemacht. Überschreitet
das Resultat die Grenze, folgt `409 result_contract_too_large`; überschreitet
die Antwort die Grenze, `409 response_contract_too_large`, jeweils vor jedem
Domainwrite.

Der Endpoint prüft `Content-Length`, liest `php://input` aber auch bei fehlender
oder falscher Längenangabe nur bis `REQUEST_MAX_BYTES + 1` und antwortet vor
`json_decode()` mit 413. Die Infrastruktur wird bewusst oberhalb des
Applikationsbounds synchronisiert: `post_max_size` muss mindestens 17 MiB
zulassen und nginx den Endpoint ebenfalls passieren lassen; die Anwendung bleibt
die Stelle, die den exakten 16-MiB-Vertrag und dessen JSON-Fehlercode durchsetzt.
Static-/Exposuretests prüfen PHP-ini, nginx, PHP-Konstanten und Uploader gemeinsam.
Der heutige Wert `post_max_size = 6M` ist vor Aktivierung zwingend anzupassen.

Vor Festlegung der 1-MiB-Grenze belegt ein Worst-Case-Test mit der maximal
zulässigen VM-/Interfacezahl eines Jobs, dass ein vollständiges V2-Resultat und
die Antwort hineinpassen. Existiert keine belastbare Scopeobergrenze, wird sie in
der Bounds-SSoT ergänzt; ein erst nach dem realen Export unvermeidbarer
Größenfehler ist kein akzeptabler Normalpfad.

Alle Identifier werden mit einem gemeinsamen UTF-8-validierenden Bytehelper
begrenzt. Byteweises `substr()` auf frei belegbaren UTF-8-Namen ist verboten;
die Kappung endet vor einem vollständigen Codepoint und
`json_encode(JSON_THROW_ON_ERROR)` wird vor dem ersten DB-Write ausgeführt.
Request- und Resultatgrenze erhalten positive, negative, exakte Grenz- und
Umlauttests.

### 14.8b Der reguläre Jobscope und was er kostet

`VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS` = 40 und
`VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_INTERFACES_PER_VM` = 10 sind aus dem
gemessenen Worst Case abgeleitet, nicht gewählt: jede VM scheitert, jeder
Bezeichner liegt auf seiner maximalen gespeicherten Bytelänge, jede Karte
erzeugt zwei Fehler. Das ergibt ein vollständiges V2-Resultat von 0,50 MiB und
eine vollständige Antwort von 0,83 MiB, beide innerhalb der 1-MiB-Grenze mit
Rand. `MacImportBoundsTest` misst beides nach und belegt mit dem doppelten
Scope, dass die Zahl tragend ist.

Der Preis ist benannt statt versteckt: eine Mission darf rund 500 VMs enthalten
(`VIRTUSPHERE_MISSION_IMPORT_MAX_BYTES`), ein einzelner Auftrag deckt davon
höchstens 40 ab. Für `full`, `powercycle` und `start` bleibt die Staffelung der
vorgesehene Weg, weil sie ohnehin einen Auftrag je VM erzeugt und deshalb
ausgenommen ist. `create`, `export` und `autostart` sind nicht staffelbar und
damit real auf 40 VMs je Auftrag begrenzt; eine größere Mission wird in
mehreren Aufträgen bereitgestellt.

Die Grenze ist eine Folge der Antwortgröße, nicht des Netzwerkvertrags. Der
eigentliche Platzverbrauch liegt darin, dass jede Fehlerzeile `vm_name` und
`vlan` erneut vollständig trägt: bei zehn Karten sind das rund 8,9 KiB
wiederholter Namen je VM. Wer den Scope anheben will, verkleinert zuerst diese
Wiederholung, zum Beispiel durch eine Referenz auf den `vm_results`-Eintrag.
Das ist eine Änderung am V2-Wire-Vertrag und gehört in eine eigene Etappe mit
eigener Kompatibilitätsentscheidung, nicht in 14A.

Nicht akzeptabel wäre die Alternative, die es vorher gab: der Auftrag läuft,
der Export legt die VMs an, und erst die Antwort reißt die Grenze und wird mit
409 abgewiesen. Danach existieren die VMs, das Ergebnis ist verloren, und keine
Aufteilung der Auswahl macht das rückgängig.

### 14.9 Rejection-, Log- und Auditmatrix

„Ohne Writes" bedeutet in diesem Abschnitt stets: keine Interface-, VM-,
Status-, Resultat-, Jobstatus- oder sonstigen Domainwrites. Erst nach Rollback
darf der ausdrücklich definierte bounded Observabilitypfad schreiben. Producer,
Maschinenantwort, Joblog, Audit und ADR verwenden dieselbe geschlossene
Reason-Registry.

| Klasse | HTTP/Code | Joblog | Audit/Serverlog |
|---|---|---|---|
| Request über Bound oder vor JSON nicht lesbar | `413 request_too_large` | keines, da Job-ID nicht vertrauenswürdig geparst ist | nur Infrastruktur-/gedrosseltes Machine-Log, kein DB-Audit |
| Modus ohne Callback-Expectation | `409 callback_mode_rejected` | bei existierendem Job höchstens einmal je Reason/Throttlefenster | gedrosseltes Callback-Rejected-Audit |
| Job nicht aktiv/terminal | `409 callback_job_not_active` | ebenso | ebenso |
| Attempt/Generation/Step/Handle fremd | eigener geschlossener `409`-Reason | ebenso, ohne fremdes Handle/Generation im Klartext | ebenso |
| anderer/unlesbarer Fingerprint/Resultattyp | `409 callback_result_conflict` | ebenso | ebenso |
| Resultat oder Response zu groß | `409 result_contract_too_large` beziehungsweise `response_contract_too_large` | ebenso, nur gemessene/erlaubte Bytes | ebenso |
| identischer aktiver Zweitcallback | `200`, gespeicherter Vertrag | kein neuer Eintrag | kein Audit, kein Warnlog |
| unerwarteter Serverfehler | bestehende generische 500-Hülle | nur wenn transaktionssicher und Job bekannt | bestehendes Failure-Audit plus serverseitige Exceptionklasse, keine Secrets |

Joblog und Audit nutzen denselben 3600-Sekunden-Scope `job:<id>:<reason>`; eine
Callbackflut darf nicht pro Request eine dauerhafte Zeile erzeugen. Die
Maschinenantwort enthält den stabilen Code und `job_id`, aber keine internen
Vergleichswerte. Static-/Integrationtests beweisen für jeden Registrycode
HTTP-Code, erlaubte Observabilitywrites, Null-Domainwrites und Drosselung.

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
   aktualisiert. Eine verwendbare Datacenter-Namensevidenz ist neutral und nie
   grün, weil sie keine aktuelle Hostverfügbarkeit beweist.
4. Grün: nur ein positiv belegter Zustand, nie das Fehlen einer Warnung.

Ein verwendbarer Datacenter-Name mit später fehlgeschlagenem Abruf trägt den
neutralen Namenshinweis und zusätzlich den vorhandenen Abruf-Fehlerzustand. Die
beiden Wahrheiten werden nicht zu einer neuen Mischfarbe verrechnet. Ist der
Name abgelaufen und fehlt zugleich ein Missionswert, ist der daraus entstehende
Pflichtwertblocker rot.

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
- Rohnamen mit Randseparatoren verwenden in der Inventardetailansicht eine
  kopierbare `<code>`-Darstellung mit `white-space: break-spaces`, sichtbaren
  Anfang-/Endemarkern und Zeichenzahl. HTML-Whitespace-Collapse darf einen
  unsupported Namen nicht wie seinen getrimmten Nachbarn aussehen lassen.
- Aktionszeile und folgende Blockerliste besitzen den gemeinsamen vertikalen
  Abstand, auch wenn die Buttonzeile wrappt.
- Neue Klassen erhalten CSS-Regeln und bestehen den CssClassContractTest.
- Dynamische Aktualisierungen verwenden `role=status` für Hinweise und
  `role=alert` für neu entstandene Blocker.
- Fokus wird bei Queueversuch auf den ersten Blocker beziehungsweise bei
  VM-Speicherfehler auf das erste betroffene Control gesetzt.
- Dark/Light Theme, 200 Prozent Zoom, Tastatur und Screenreader-Stichprobe sind
  Teil der Abnahme.

### 16.4 Anzeige-, Kandidaten- und JSON-Bounds

Die im Altplan vorgesehenen netzwerkspezifischen Bounds werden durch allgemeine
Deploy-Preflight-Bounds ersetzt:

| Konstante | Wert |
|---|---:|
| `VIRTUSPHERE_DEPLOY_PREFLIGHT_INITIAL_LIMIT` | 10 |
| `VIRTUSPHERE_DEPLOY_PREFLIGHT_DETAIL_LIMIT` | 50 |
| `VIRTUSPHERE_ESXI_SIMILAR_NAME_CANDIDATE_LIMIT` | 5 |
| `VIRTUSPHERE_DEPLOY_PREFLIGHT_JSON_MAX_BYTES` | 65536 |

Sie gelten gemeinsam für allgemeine Netzwerkblocker, WDS-Befunde,
Objektnamensabweichungen und Storagehinweise. Die alten geplanten Namen
`VIRTUSPHERE_VM_NETWORK_BLOCKER_INITIAL_LIMIT` und
`VIRTUSPHERE_VM_NETWORK_BLOCKER_DETAIL_LIMIT` werden nicht zusätzlich
implementiert.

Jede Liste liefert `total`, die deterministisch gewählten Einträge und
`omitted_count`. Kandidatengruppen liefern zusätzlich `candidate_total` und
`candidate_omitted_count`. Sortiert wird nach Schwere-Registry, Mission-ID,
natürlichem VM-Anzeigenamen, danach binär exaktem VM-Namen, VM-ID,
Source-Kind-Registry, Interface-ID, Code, konfiguriertem Wert und binär exaktem
Kandidatennamen. Der letzte Binärvergleich löst Case-Gleichstände reproduzierbar.

Die JSON-Insel nimmt höchstens die ersten 50 vollständigen Befunde und je fünf
Kandidaten auf. Überschreitet deren kodiertes JSON 65536 Bytes, entfernt der
Serializer deterministisch Einträge vom Listenende, bis die Grenze eingehalten
ist, und setzt `truncated_by_bytes=true`; `total` und `omitted_count` bleiben
die Wahrheit. Der Backendguard bewertet immer den vollständigen Scope. Kein
Kappen kann einen Blocker in eine Freigabe verwandeln.

### 16.5 Datacenter-Logs, Protokollcodes und Troubleshooting

Die Datacenter-Ableitung besitzt eine geschlossene Reason-Registry synchron zum
Resolution-Verdict. Portaltexte werden daraus präsentiert; Joblogs und Tests
verwenden die stabilen Codes. Ein Queue- oder Workerpfad erfindet keine
Freitextursache.

| Code | Bedeutung | Wirkung ohne gespeicherten Missionswert |
|---|---|---|
| `datacenter_name_confirmed` | genau ein unterstützter Semantik-2-Name innerhalb 48 Stunden | Ableitung zulässig |
| `datacenter_name_expired` | positive Evidenz älter als 172800 Sekunden | Pflichtwertblocker |
| `datacenter_name_never_confirmed` | keine positive Datacenter-Evidenz | Pflichtwertblocker |
| `datacenter_name_semantics_unverified` | Kind besitzt nicht Semantik 2 | Pflichtwertblocker |
| `datacenter_name_answered_empty` | aktuelle vollständige Abfrage meldet null unterstützte Namen | Pflichtwertblocker, keine Host-Abwesenheitsbehauptung |
| `datacenter_name_ambiguous` | mehrere unterstützte exakte Namen | Pflichtwertblocker, Mission muss auswählen |
| `datacenter_name_unsupported` | einziger/alle Kandidaten nicht materialisierbar | Pflichtwertblocker mit Supportgrund |
| `datacenter_name_timestamp_invalid` | fehlender, unlesbarer oder zukünftiger Frischezeitpunkt | Pflichtwertblocker plus Zeitdiagnose |

Ein abgewiesener Queue-POST legt keinen Job und kein Audit an. Er antwortet mit
dem strukturierten Blocker und den Presenterlinks; wiederholte Vorschauen bleiben
schreibfrei. Blockiert erst der Worker einen bereits eingereihten Job, schreibt
er genau eine bounded Joblogzeile vor der Remote-Grenze, zum Beispiel:

```text
configuration_blocked code=datacenter_name_expired credential_id=4
confirmed_at=2026-08-24T05:12:00Z age_seconds=183245
max_age_seconds=172800 semantics=2 supported_name_count=1
```

Die Zeile enthält keine Secrets, keine Credential-URL und bei unsupported Namen
keinen ungeeigneten Rohwert. Ein separat fehlgeschlagener Inventarversuch bleibt
im Inventarjob und in `kind_observation_json`; er wird nicht als erfundene
Datacenter-Ursache in den Deployjob kopiert. Der Deployjob verlinkt mit
`deploy_job_origin_url()` zurück zu seinem Ursprung und sein Presenter zusätzlich
mit `system_status_url()` auf die konkrete ESXi-Karte. Reine Zeitabläufe erzeugen
kein Security-Audit. Das bewusste Speichern eines Missions-Datacenters bleibt
über den vorhandenen Missionsaudit nachvollziehbar.

Der einzige ausführliche Troubleshooting-Ablauf lebt in der Systemstatus-Hilfe:

1. konkrete ESXi-Karte öffnen und Datacenter-Bestätigung gegen letzten Versuch
   unterscheiden;
2. laufenden/offenen Inventarjob beziehungsweise letztes Jobprotokoll öffnen;
3. je nach registrierter Fehlerkategorie Worker, Queue, Ansible-Auswahl, DNS,
   Routing, TLS, SSH, ESXi-Zugang, Rechte oder Auth-Pause korrigieren;
4. Inventarabruf auf derselben Karte erneut starten;
5. Semantik 2, vollständige Datacenter-Antwort und genau einen unterstützten
   Namen prüfen;
6. alternativ einen fachlich bestätigten exakten Datacenter-Namen bewusst in der
   Mission speichern.

Mission- und Deploy-Hilfe verweisen nur auf diesen Ablauf und wiederholen weder
Fehlerkategorietabelle noch Ursachenliste. Die vorhandene
`VIRTUSPHERE_INVENTORY_ERROR_CATEGORIES`-/`connection_error_message()`-Tabelle in
`help_system_status.php` bleibt alleinige Ursachen- und Reparatur-SSoT.

## 17. Help- und Dokumentationsmatrix

### 17.1 Portaltexte

Alle DE/EN-Kataloge werden gemeinsam geändert:

| Katalog | Inhalt |
|---|---|
| `mission_details.php` | Feldname, Keine-Hinweis, Nicht-Vererbung, Auswirkungszusammenfassung; bei leerem Datacenter nur 48-Stunden-Voraussetzung plus Systemstatuslink |
| `vm_edit.php` | Portgruppe, WDS-Badges, Inlinefehler, Reparaturhinweise |
| `deploy.php` | strukturierte Blocker/Warnungen, PXE-MAC-Badges, Scopezusammenfassung, Storage-Case-Verdict und kompakter zentraler Datacenter-Evidenzbefund |
| `validate.php` | Feldlabels und geschlossene Domainmeldungen |
| `help_deploy.php` | Modusmatrix, harte WDS-Modi, Hostcachegrenze, Case-Sensitivität; Datacenter nur als Kurzverweis auf Systemstatus-Hilfe |
| `help_missions.php` | Bedeutung und Änderungsfolgen der WDS/PXE-Portgruppe; leeres Datacenter nur als Kurzverweis auf Systemstatus-Hilfe |
| `help_stack.php` | PXE-MAC-Ablauf ohne Behauptung einer Portal-Dienstprüfung |
| `help_system_status.php` | einzige ausführliche Datacenter-Evidenz-/Abruf-/Verfügbarkeits- und Troubleshooting-Erklärung; exakte Namen, Case-Gruppen, kindweise Observation/Frische/Namenssemantik und unsupported Namen |
| `system_status.php` | Detailtext, letzter Versuch, Fehlerursache, Refresh und Jobloglink aus zentralem Verdict/Presenter |
| `settings.php` / `help_settings.php` | Warnung bei Intervall 0 oder mindestens 48 Stunden, ohne zweite Ursachen- oder Troubleshooting-Erklärung |
| `vlans.php` | Portgruppenbegriff, case-verschiedene Katalogzeilen, Reassign |

Der heutige Missionshilfe-Hinweis, bei 3/5 nur nach einer Netzwerkkarte im
DHCP-Modus zu suchen, wird korrigiert. Maßgeblich ist die WDS/PXE-Portgruppe; der
Gast-IP-Modus ist keine NIC-Identität.

Für die Datacenter-Frist gilt ein Anti-Duplikationsvertrag: Nur
`help_system_status.php` erklärt die drei Achsen Namensevidenz, letzter Versuch
und Live-Verfügbarkeit sowie Ursachen und Reparaturschritte. Andere Help-Panels
nennen höchstens die konkrete Auswirkung ihrer Seite und verlinken über einen
zentralen Topic-Key/`help_topic_url()` auf den Abschnitt
`help-esxi-datacenter-evidence`. Ein Static-Contracttest verbietet die vollständige
48-Stunden-/Ursachenpassage außerhalb ihres Help-Owners und stellt sicher, dass
alle referenzierten Fehlercodes aus derselben Registry gerendert werden.

### 17.2 Aktive technische Dokumentation

| Datei | Verbindliche Ergänzung |
|---|---|
| `docs/DEPLOYMENT.md` | exakte VMware-Namen, ein Objektname-Owner mit getrennten Operationen, WDS-Modusmatrix, Worker- und Callbackgate |
| `docs/INSTALLATION-ANLEITUNG.md` | sichtbaren Begriff WDS-Portgruppe (PXE), 48-Stunden-Voraussetzung bei leerem Missions-Datacenter und Systemstatus als Betriebsziel |
| `docs/operations/esxi-inventory.md` | neue Collation, kindweise Observation/Semantik, feste 48-Stunden-Namensevidenz, klare Nicht-Verfügbarkeitsgarantie, Systemstatus-Owner, Case-Gruppen, warn-only Grenze, Storage-Verdicts |
| `docs/operations/deploy-chain.md` | Queue- und Worker-Recheck vor Remote-Arbeit, Ablauf zwischen Queue und Worker sowie keine Neubewertung nach Remote-Grenze |
| `docs/operations/mecm-integration.md` | WDS-MAC, Modus-/Step-Gate, Callback-Fingerprint, Idempotenz, Lockreihenfolge und per-VM-Atomarität |
| `docs/operations/troubleshooting.md` | kurzer Suchpfad Mission -> Systemstatus-ESXi-Karte -> Inventarjob -> Deployjob -> Callback; Ursachen bleiben per Link/Verweis in der ESXi-Inventardoku statt als zweite Tabelle |
| `docs/QA.md` | alle neuen Unit-, Integration-, Static-, E2E- und Stagingnachweise |
| `docs/CHANGELOG.md` | Operatornutzen und bewusst unveränderte Grenzen |
| `docs/adr/ADR-0023-esxi-inventory-and-vlan-ownership.md` | Amendment: Rohname exakt, kindweise Observation/Semantik, feste Datacenter-Ableitungsfrist, drei getrennte Wahrheitsachsen, Systemstatus-Owner und irreversible Writergrenze |
| `docs/adr/ADR-0030-partial-deploy-results-and-result-json.md` | Amendment: Modus-/Step-Gate, Lockreihenfolge, Callback-Fingerprint, WDS-MAC, Resultatinvarianten und `vm_results` |

### 17.3 Dauerhafte Agent-/Guardregeln

`AGENTS.md`, `GROK.md` oder `.claude/rules/` werden nur ergänzt, wenn nach der
Umsetzung eine dauerhafte konstruktive Regel beziehungsweise ein verbotener
Driftpfad entstanden ist. Plantext allein wird nicht als Regel dupliziert.

## 18. Datei- und Owner-Matrix

| Owner | Geplante Verantwortung |
|---|---|
| `lib/esxi_object_names.php` neu | Rohname, Portalkanonisierung, Supportgrund, exakter Name, Diagnosekey, Matchklassifikation |
| `lib/ansible_inventory_parse.php` / `lib/ansible_inventory_capability.php` | ESXi-Rohnamen vor Cache und Capability-Auswertung nicht trimmen/casefolden; per-kind Query-/Observation-Ergebnis liefern |
| `lib/repo/esxi_inventory_cache.php` | rohe exakte Deduplizierung und kindweise Semantik/Frische |
| `lib/repo/esxi_inventory_vlan.php` | exact-case Katalogsync und Präsenz |
| `lib/esxi_inventory_deviations.php` | strukturierte Abweichungen mit Herkunft/Scope |
| `lib/esxi_inventory_options.php` | exact-case Optionen und ähnliche Hinweise |
| `lib/inventory_field.php` | konsistente Selectdarstellung |
| `lib/ansible_yaml.php` | exakte Storagegruppierung; keine erfundene NIC |
| `lib/deploy_storage.php` | exact/case/missing/unknown Verdict |
| `lib/vm_network_contract.php` neu gemäß Altplan | allgemeiner und WDS-Domainbefund |
| `lib/repo/vm_network.php` neu gemäß Altplan | gebündelte Scope-Leser und aktive Schreibsperre |
| `lib/vm_network_display.php` neu gemäß Altplan | lokalisierter Presenter |
| `lib/repo/missions.php` / `lib/repo/vms.php` | bestehende Mission-/VM-/Interfacewriter auf exakten Domainvertrag umstellen |
| `lib/mission_transfer_import.php` | Importpreview und Commit verwenden denselben exakten Netzwerk-/Datacentervertrag |
| `lib/esxi_inventory_deviations.php` | Massen-Reassign simuliert alle aktuellen Writerziele exakt und atomar |
| `lib/esxi_datacenter_resolution.php` neu | reines geschlossenes 48-Stunden-Verdict aus Name, Frische, Semantik, Observation und requestweitem `now` |
| `lib/esxi_datacenter_presenter.php` neu | einzige kompakte/detailierte Meldungs-, Reason- und Linkableitung; keine DB-Lese- oder Gateentscheidung |
| `lib/repo/deploy_job_guards.php` | serverseitige Missionsbereitschaft über dasselbe Datacenter-Verdict |
| `lib/repo/deploy_job_queue.php` | Queue-/Staffeltransaktion nutzt Aggregator |
| `lib/deploy_worker_mission.php` | Worker-Recheck vor Remote-Arbeit |
| `lib/mac_import.php` | exakte VM-Namen sowie Portal-/ESXi-NIC-Zuordnung und WDS-Erfolg |
| `lib/mac_import_result.php` | Fingerprint, Resultatinvarianten, UTF-8-Bounds, Decoder und Codes |
| `lib/machine_api.php` | vorhandener 3600-Sekunden-Throttle für Callback-Konfliktaudit |
| `lib/remote_step_policy.php` | Remote-Aktivierungspolicy bleibt ohne Create/Full bis 14B; getrennte Callback-Expectation-Registry leitet Exportfähigkeit aus der Playbookfolge ab |
| `lib/repo/deploy_remote_execution.php` | aktuelles Runtime-Generation-/Attempt-/Export-Handle-Fencing gemäß bestehendem Remote-Vertrag |
| `db_importMAC.php` | Mission->Job->Runtime->Remote-Handle->VM->Interface-Locks, Modus-/Step-Gate, idempotenter atomarer Commit |
| `Ansible/upload_mac_list.py` | Request-/Response-Bounds und unveränderter Machine-Payload |
| `lib/deploy_constants.php` / Bounds-SSoT | gemeinsame Preflight-, Kandidaten-, JSON- und Callbackgrenzen |
| `portal/mission_details.php` | Feldhilfe und WDS-Änderungsauswirkung |
| `lib/vm_edit_form.php` / geplante Splits | WDS-Markierung und Inlinefeedback |
| `portal/deploy.php` / Masterplan-Splits | Blocker-/Warnungsrenderer und JSON-Insel |
| `portal/assets/deploy.js` / Masterplan-Splits | progressive Modus-/Scope-/Hostaktualisierung |
| `lib/integration_health.php` | requestweiter ESXi-Snapshot enthält das einmal berechnete Datacenter-Verdict und die Observation, ohne Presentertext |
| `lib/system_status_esxi_panels.php` | alleinige Detaildarstellung, Refresh, Fehlerursache und Jobloglink für die konkrete ESXi-Karte |
| `portal/system_status.php` / `lib/system_status.php` | Deep-Link-/Anchor-SSoT und Berechtigung; keine eigene Evidenzlogik |
| `lib/settings_page.php` / Einstellungen-Owner | Intervallwarnung aus der festen 48-Stunden-Konstante, keine zweite Gateentscheidung |
| `lib/help_page.php` neu | validierte Help-Topic-/Fragment-SSoT für den Datacenter-Evidenzabschnitt und weitere echte Querverweise |

Keine dieser Verantwortungen wird in einer zweiten Helperkopie nachgebaut.
Dateisplits richten sich nach dem dann erreichten Masterplanstand; die fachlichen
Owner bleiben gleich, auch wenn ein Pfad inzwischen in ein fokussiertes Modul
verschoben wurde.

Vor Paket A erzeugt ein read-only Callsite-Audit aus den tatsächlichen Writes auf
`wds_vlan`, `interface.vlan`, Datacenter-/Datastorefelder sowie aus allen
`esxi_inventory_name_key()`-/`trim()`-Nutzungen die konkrete Migrationsliste.
Diese Liste wird nicht von Hand als zweite SSoT gepflegt: Static-Tests leiten die
Writer-/Readerfundstellen aus Code und Spaltennamen ab und verlangen für jede
Fundstelle einen klassifizierten Owner oder eine begründete Ausnahme.

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

1. Migration/Fresh-Schema für Collations, Länge,
   `kind_name_semantics_json` und `kind_observation_json`.
2. Objektname-Helper mit unverändertem ESXi-Rohnamen, Portalnormalisierung und
   geschlossenem `unsupported_name`.
3. Static-Guard gegen operative Case-Folding-Verwendung.
4. Schema-Konvergenz und positive/negative/zero-match Guardtests.

### Paket C: Cache, Katalog und Inventaroptionen

1. Parser und Capability-Pfade bewahren Namen bereits vor dem Repository ohne
   Trim-/Case-Collapse;
2. rohe exakte Cache-Ingestion sowie case- und whitespace-verschiedene Zeilen;
3. Semantikversion nur je vollständig beantwortetem Kind setzen;
4. answered-empty als Version 2/known-empty und failed/skipped als eingefrorene
   positive Evidenz plus aktualisierte `kind_observation_json` beweisen;
5. genaue Hostpräsenz, unsupported Namen und ähnliche Schreibweisengruppe;
6. Katalogsync darf nur Semantik-2-Netzwerk-Kinds als positive oder negative
   Evidenz verwenden und retired während gemischter 1/2-Cutoverlage nichts;
7. Katalog und Massen-Reassign transaktional anpassen;
8. Altcache je Kind als unbekannt statt grün behandeln.

### Paket D: Abweichung und Speicherbewertung

1. strukturierte Herkunftsobjekte;
2. Modus-/VM-Scope;
3. exakte Storagegruppierung und Kapazitätsmap;
4. vier Verdictzustände;
5. zentraler Datacenter-Resolutionhelper mit fester 172800-Sekunden-Grenze,
   getrenntem Observation-Verdict und requestweitem `now`;
6. ein Presenter für Detail-, Kurz- und Blockerform statt Seitentexte;
7. serverseitige Vorschau und bounded Live-JSON aus derselben Datenstruktur.

### Paket E: WDS-Domainvertrag und alle Writer

1. pure WDS-Prüfung;
2. allgemeine und WDS-Issues getrennt halten;
3. Mission edit, VM edit, Klon, Missionstransfer und Massen-Reassign anbinden;
4. Writer-Matrix mit warn-only WDS und getrenntem allgemeinem Blocker umsetzen;
5. WDS-Änderung ohne Auto-Propagation;
6. aktive Job-Schreibsperren bewahren.

### Paket F: Deploy-Queue und Worker

1. Modusableitung aus Playbookfolge;
2. Scope-Aggregator;
3. serverseitiger Queueblock und all-or-nothing Staffeltransaktion;
4. Initialrenderer und progressive Browseraktualisierung;
5. Worker-Recheck einschließlich Ablauf zwischen Queue und Start mit
   Fortschrittsvertrag;
6. strukturiertes `configuration_blocked`-Ergebnis und genau eine bounded
   Datacenter-Reason-Logzeile vor Remote;
7. nach der Remote-Grenze keine zeitgesteuerte Neubewertung mitten im Job.

### Paket G: MAC-Callback, Resultat und Retry

1. Raw Lockreihenfolge Mission -> Job -> Runtime-Identität -> gegebenenfalls
   Remote-Export-Handle -> VM -> Interfaces;
2. Modus-/Export-Step-/Attempt-/Generationgate unter diesen Locks; bei
   `remote_v1` ist das exakte Handle Pflicht, bei `legacy_v1` wird keines
   erfunden;
3. Callback-Expectation-Registry gegen `ansible_playbooks_for_mode()` und
   `ansible_mode_expects_mac_result()` vervollständigen, aber von der
   Remote-Aktivierungsregistry trennen; Create/Full bleiben bis 14B disabled;
4. identischen Zweitcallback nur während `running|cancelling` im selben
   Attempt/Generation als 200/no-op beweisen; abweichend oder terminal ist 409;
5. exakte VM-Namen und Portal-/ESXi-NIC-Map;
6. WDS-MAC als Erfolgsbedingung;
7. eine exhaustive WDS-Code-Registry;
8. `vm_results`, Fingerprint und vollständige Invarianten persistieren/lesen;
9. Request-/Resultat-/Response-/UTF-8-Bounds einschließlich PHP/nginx;
10. Retry aktuellen Scope revalidieren;
11. Machine-Wire und Legacy-Statusstrings unverändert beweisen.

### Paket H: Portal-QoL, Help und Dokumentation

1. Systemstatus als alleinigen ESXi-/Datacenter-Detail-Owner, zentralen Presenter,
   kompakte Verbrauchertexte, Deep Links, Labels und Badges;
2. DE/EN-Parität;
3. Help- und aktive Doku-Matrix vollständig abarbeiten und doppelte
   Ursachen-/48-Stunden-Erklärungen per Contracttest verhindern;
4. ADR-Amendments und Changelog;
5. Warnung der Inventarintervalleinstellung bei 0 oder mindestens 48 Stunden aus
   derselben Konstante;
6. zentrale Anzeige-/Kandidaten-/JSON-Bounds;
7. Responsive-, Theme- und Accessibilityabnahme.

### Paket I: Rollout und reale Abnahme

1. produktionsgroßen Restore-Klon und exakte Migrationsmessung belegen;
2. Backup und Revision/Digest belegen;
3. read-only Bestandsaudit;
4. write-free Migration und gemeinsamer App-/Worker-/Ansible-Rollout;
5. alle ESXi-Zugänge kindweise neu inventarisieren;
6. Case-, Partial-Kind-, Datacenter- und WDS-Canaries;
7. bestehenden Altwriter nach Cutover gesperrt beweisen;
8. bestehende Abweichungen fachlich korrigieren;
9. keine automatische Datenreparatur.

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
| Hostcache-Kind leer/unfrisch oder Namenssemantik 1 | betroffenes Kind unbekannt | Cacheabweichung blockiert nicht | reales Modulresultat entscheidet |
| Datastore beantwortet, Netzwerkquery fehlgeschlagen | Datastore darf Semantik 2 sein; Netzwerk bleibt alter Stand | kindweise Bewertung | kein credentialweiter Grünbeweis |
| mehrere ähnliche Hostnamen | erste fünf deterministisch plus Gesamt-/Restzahl, nichts vorschlagen | Cache blockiert nicht | exakter Resultatname entscheidet |
| Mission ohne Datacenter, genau ein Semantik-2-Rohname, Alter exakt 172800 Sekunden | neutral als automatisch abgeleitet plus Restzeit 0 sichtbar | letzter zulässiger Resolverzeitpunkt | nicht Callback-relevant |
| Mission ohne Datacenter, Name 172801 Sekunden alt | Pflichtwertblocker mit Systemstatus- und berechtigtem Missionslink | `configuration_blocked` vor Remote | nicht Callback-relevant |
| Name vor 4 Stunden bestätigt, letzter Versuch vor 5 Minuten fehlgeschlagen | Name weiterhin ableitbar; Kurztext nennt Fehlschlag, Detailursache nur Systemstatus | reales ESXi-Ergebnis entscheidet, Observation überschreibt positive Evidenz nicht | nicht Callback-relevant |
| später vollständig answered-empty/mehrdeutig/unsupported | alte positive Ableitung sofort unbrauchbar, präziser Pflichtwertgrund | blockiert vor Remote | nicht Callback-relevant |
| Mission mit explizitem Datacenter, Evidenz alt/fehlgeschlagen | gespeicherter Wert bleibt, höchstens Cachewarnung | kein Ableitungsblocker | reales ESXi-Ergebnis entscheidet |
| Mission ohne Datacenter, Kind nie bestätigt/Semantik 1/unsupported/null/mehrdeutig | Pflichtwertblocker ohne Host-Abwesenheitsbehauptung | blockiert vor Remote | nicht Callback-relevant |
| Queue bei 47 Stunden, Worker startet nach 49 Stunden | Queue war zulässig; historischer Hinweis bleibt | Worker-Recheck blockiert vor Upload | kein Callback |
| Frist läuft nach erstem Remote-Upload ab | keine laufende UI-Neuentscheidung | Job verwendet seinen Preflight-Snapshot; kein Mid-Run-Abbruch | Callbackvertrag unverändert |
| `kind_freshness_json.datacenter` liegt in der Zukunft/ist unlesbar | Zeitdiagnose, keine Ableitung, Systemstatuslink | blockiert vor Remote | nicht Callback-relevant |
| Inventarintervall 0 oder mindestens 48 Stunden | Settingswarnung; explizite Missionswerte bleiben möglich | keine Sonderschedulerlogik | nicht Callback-relevant |
| explizit eine gute VM gewählt, andere Mission-VM fehlerhaft | Auftrag zulässig | nur gewählte VM prüfen | nur Scope-VM erwartet |
| keine VM gewählt | Hinweis „alle X“; alle prüfen | alle prüfen | alle erwartet |
| Staffel mit einer fehlerhaften von vielen VMs | gesamte Staffel abgelehnt, null Gruppen-/Jobzeilen | nichts gestartet | kein Callback |
| `remote_v1`, Export-Handle fehlt oder gehört zu altem Attempt/Generation | nicht Queue-relevant | Recovery-/Reconciliationvertrag entscheidet | `409`, null Writes, kein Handle-Fallback |
| `legacy_v1`, aktiver Exportmodus und aktuelle Generation | nicht Queue-relevant | bestehender Legacyvertrag | Modus-/Step-Policy darf freigeben; kein Remote-Handle wird erfunden |
| gepostete IDs gehören nicht mehr zur Mission | bestehender Repo-Fehler; keine Scopeausweitung | nicht gestartet | kein Callback |
| Moduswechsel `create` -> `export` | Warnung wird Blocker, Button aktualisiert | POST revalidiert | Export nur bei bereit |
| Mission-WDS nach Queue geändert | Anzeige historisch; aktuelle Config darf noch ändern, solange queued | Recheck blockiert neuen Ist-Zustand | kein alter Wert geraten |
| Mission-WDS während running/cancelling geändert | serverseitig gesperrt | Job behält konsistenten Scope | Callback sieht unveränderten Vertrag |
| VM-Interface außerhalb Portal auf ESXi geändert | Cache kann abweichen | kein Cacheblock | Callback meldet exakte ESXi-Ursache |
| ESXi liefert zusätzliche unbekannte NIC | kein Vorabbeweis | Export läuft | bestehende per-VM-Atomarität und `interface_not_found` bleiben |
| ungültige oder doppelte MAC | bestehende Warn-/Blockregeln | Export läuft | alte Codes bleiben, kein WDS-Erfolg |
| identischer Callback doppelt während aktivem Job | keine UI-Aktion | gleicher Attempt/Generation | zweiter Aufruf 200/no-op, gespeichertes Resultat unverändert |
| abweichender zweiter Callback desselben Jobs | keine UI-Aktion | gleicher Attempt/Generation | 409 `callback_result_conflict`, keine Writes, gedrosseltes Audit |
| Callback für `create`/`start`/`autostart` | keine UI-Aktion | Modus erwartet keinen Export | 409 ohne Writes |
| Callback für alten/fremden Export-Step oder Generation | keine UI-Aktion | aktuelle Step-Policy stimmt nicht | 409 ohne Writes |
| ESXi-VM-Name unterscheidet sich nur im Case | kein Cachebeweis | Exportresultat eingetroffen | VM nicht exakt zugeordnet, eigener Identitätsfehler, kein Write |
| Callback nach Terminalzustand | keine UI-Aktion | Job terminal | 409 ohne Writes |
| Teiljob: eine WDS-VM gut, eine schlecht | vorab nur bei später externer Abweichung möglich | Export läuft | guter VM-Commit, schlechter kein Write, Job `partial` |
| Retry nach Portalreparatur | aktueller Scope grün, alter Job bleibt historisch fehlerhaft | neuer Exportjob | neues Resultat nur am neuen Job |
| Benutzer ohne `vms.write` | Ursache sichtbar, kein Reparaturlink | Servergate unverändert | unverändert |
| JavaScript aus | servergerenderter Stand; POST blockiert verbindlich | unverändert | unverändert |
| zwei Browser-Tabs, alte Formularseite | alter Buttonzustand unbeachtlich | Repo liest aktuellen Stand | kein stale Write |
| Portgruppenname `0` | gültiger nichtleerer Name | exakt vergleichen | exakt zuordnen |
| versehentliche Randspaces in manueller Portaleingabe | vorhandener Writer trimmt zum Portalwert | gespeicherter Portalwert | exakter Vergleich dieses Werts |
| gültiger ESXi-Rohname mit Randwhitespace | bytegetreu sichtbar als unsupported, nie still auswählbar | keine Ableitung daraus | kein automatisches Mapping |
| ESXi-Name leer/Nur-Whitespace/NUL/Steuerzeichen | nur per-row Grund und Count, kein Rohwert persistiert | Kindabruf sichtbar degradiert | kein Mapping oder Statuswrite |
| Gesamtmarker enthält ungültiges UTF-8 | kein per-row Raten, Pull zeigt `parse/invalid_utf8_marker` | kein Kindcommit | kein Mapping oder Statuswrite |
| innere Leerzeichen, Umlaute oder Unicode | unverändert erhalten, kein Normalisierungsraten | exakt | exakt |
| sehr langer Umlautname an Bytegrenze | vollständig gültiges UTF-8 oder verständliche Längenabweichung | redigiert/UTF-8-sicher | `json_encode` bleibt gültig, kein halber Codepoint |
| `Prod` und `prod` an derselben VM | zwei getrennte Ziele, Case-Warnung möglich | exakt getrennt | nur exakte Summary mappt |
| case-only Massen-Reassign | echte bestätigte Änderung | atomar | nachher exakter Vertrag |
| Massen-Reassign erzeugt doppelte VM-Portgruppe | vollständiger Block, kein Teilupdate | nicht betroffen | nicht betroffen |
| Template ohne WDS | zulässig mit Hinweis | nicht direkt deploybar | kein Callback |
| Klon aus Template mit abweichenden Interfaces | Preview/Commit gemäß neuem Vertrag | späterer Deploy prüft | kein automatisches Umschreiben |
| Callbackrequest über 16777216 Bytes | keine UI-Aktion | Uploadfehler sichtbar | 413 vor JSON/DB |
| vollständiges Resultat über 1048576 Bytes | keine UI-Aktion | fachlicher Protokollfehler | 409, kein Teilresultat und kein VM-Write |

## 21. Testplan

### 21.1 Unit

- exakte Namen: `Daten`/`DATEN`, `Prod`/`prod`, `WDS-VLan`/`WDS-VLAN`,
  Portaltrim, unveränderter ESXi-Rohname, Randwhitespace, `0`, Umlaute und fünf
  von mehreren ähnlichen Kandidaten;
- Matchzustände exact/case/missing/unknown/unsupported exhaustiv;
- Storagegruppierung und vier Verdicts;
- WDS-Zustände null/zero/one/multiple/case-only;
- Modusableitung über alle `virtusphere_deploy_modes()`;
- Scope leer/explizit/Retry;
- Datacenter-Resolutionzustände mit 0/172799/172800/172801 Sekunden, fehlendem
  oder künftigem Zeitstempel, Semantik 1/2, 0/1/n Namen und injiziertem `now`;
- getrennte Achsen „Name bestätigt" und „späterer Versuch fehlgeschlagen";
- zentraler Detail-/Kurz-/Blockerpresenter, Restdauerformat und
  Berechtigungslinks;
- Resultdecoder historisch Version 1 und strikt Version 2 mit `vm_results`;
- Resultatinvarianten für disjunkte/vollständige IDs, Counts, Outcome und
  `wds.verified`;
- kanonischer Callback-Fingerprint ist reihenfolgeunabhängig nur für erlaubte
  Sortierung/Metadaten, nicht für relevante VM/NIC/MAC-Werte, fremde/malformed
  Zeilen oder deren Multiplizität;
- UTF-8-sichere Bytekappung exakt unter/an/über der Grenze;
- unbekannter Fehlercode bleibt fail-closed.

### 21.2 Integration mit MySQL

- Fresh-Schema und Migration besitzen `utf8mb4_0900_bin` an allen benannten
  Spalten;
- Cache speichert case- und whitespace-verschiedene Rohnamen desselben
  Kinds/Zugangs getrennt;
- fehlender kindweiser Semantikkey wird nicht als exakter Beweis verwendet;
- Datastore-Erfolg plus Netzwerkfehler setzt nur Datastore auf 2;
- answered-empty setzt nur das beantwortete Kind atomar auf Semantik 2 plus
  Frische; failed/skipped verändert beides nicht;
- `kind_observation_json` hält den neuesten kindspezifischen Versuch, während
  failed/skipped die ältere positive Frische/Semantik nicht überschreibt;
- Datacenter-Ableitung akzeptiert nur genau einen unterstützten Semantik-2-
  Rohnamen bis einschließlich 172800 Sekunden;
- expliziter Missionswert überspringt die Ableitung auch bei altem/fehlgeschlagenem
  Inventar; leerer Wert blockiert ab 172801 Sekunden;
- Queue vor und Worker nach Fristablauf blockiert erst beim Worker ohne Upload;
- Katalogsync erhält Varianten und retired nicht gegen einen Fehlabruf;
- Massen-Reassign case-only, Konflikt und Rollback;
- Queue-POST blockiert die drei Exportmodi und erlaubt Warnmodi;
- Staffelung ist all-or-nothing und hinterlässt bei einem Blocker null
  Gruppen-/Jobzeilen;
- Worker-Recheck startet keinen Remote-Schritt;
- MAC-Callback mit nur Nicht-WDS-MAC schreibt weder Status noch Identität;
- Callback für jeden nicht exporthaltigen Modus, unbekannten Modus und
  fremden/alten Export-Step ergibt 409 ohne Writes;
- `remote_v1` ohne das aktuelle Export-Handle sowie mit falschem Attempt oder
  falscher Generation ergibt 409; `legacy_v1` erfindet kein Handle;
- Lockrace beweist Mission -> Job -> Runtime-Identität -> gegebenenfalls
  Remote-Export-Handle -> VM -> Interfaces ohne Deadlock oder
  Reihenfolgeinversion;
- identischer Zweitcallback ergibt nur bei aktivem Job im selben
  Attempt/Generation 200/no-op; abweichend oder terminal ist 409 und
  `result_json` ist niemals last-writer-wins;
- case-abweichender VM-Name nutzt keinen SQL-Fallback;
- erfolgreicher WDS-Import schreibt alle gekoppelten Felder atomar;
- Request-/Resultat-/Responsegrößen exakt unter/an/über dem Bound sowie
  Content-Length fehlend/falsch/chunked;
- Partial, Duplicate, Cancel, Terminal-409 und Retry bleiben korrekt.

### 21.3 Static/Contract

- keine operative Verwendung des alten case-insensitiven Helpers;
- keine inline Case-Folding-Kopie in den Ownern;
- kein credentialweiter `name_semantics_version`; Frische- und Semantikmaps
  sowie Observation verwenden dieselbe Kindregistry;
- keine Datacenter-Altersberechnung außerhalb des Resolutionhelpers und keine
  Presenterbedingung in Portal-, Queue-, Worker- oder Systemstatusseiten;
- 172800-Sekunden-Konstante ist in Resolver, Settingswarnung, Help und Tests die
  einzige Quelle; keine zweite 48-Stunden-Literalentscheidung;
- Systemstatus-Deep-Link wird nur mit `system_status_url()` gebaut;
- ausführliche Datacenter-Ursachen-/Troubleshootingpassage existiert nur in
  `help_system_status.php`; andere Help-Panels enthalten ausschließlich einen
  Kurzverweis;
- Modusklassifikation exhaustiv;
- Queuebutton stammt aus derselben Blockerliste wie die Anzeige;
- Worker lädt Domainhelper und prüft vor Upload;
- `db_importMAC.php` behält raw äußere Transaktion und die statisch geprüfte
  Lockreihenfolge, keine Repo-Transaktion darin;
- Resultcode-Registry und ADR-Liste synchron;
- Callback-Request-/Resultat-/Responsebounds stimmen mit PHP-ini, nginx,
  Uploader und Bounds-SSoT überein;
- kein byteweises `substr()` auf frei belegbaren UTF-8-Identifiern;
- Altplan und Masterplan verweisen auf diese Korrektur und nennen dieselbe
  Paketmenge;
- Fresh-Schema/Migration/Bounds synchron;
- DE/EN- und Placeholderparität;
- CSP, Confirm, CSS-Klassen, Deep Links, RBAC und File-Size-Gate.

### 21.4 Browser/E2E

- Mission-WDS-Feldlabel, permanenter Nicht-Vererbungs-Hinweis und Änderungsvorschau;
- VM-Editor mit ready, missing, case und multiple;
- Deployform reagiert auf Modus, VM-Auswahl, Alle, Host und Sticky State;
- gewählter ESXi-Zugang zeigt den kompakten Datacenter-Befund und führt auf die
  exakt geöffnete Systemstatuskarte; Ursache/Refresh/Joblog werden dort, nicht
  doppelt im Deployformular gezeigt;
- Systemstatus zeigt bestätigten Namen, Restfenster und späteren Fehlversuch als
  getrennte Aussagen; Credentials/Dashboard/Missionsliste erhalten keine Kopie;
- Intervall 0 und mindestens 48 Stunden zeigt eine Settingswarnung, sechs Stunden nicht;
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
8. Datacenter-Abruf bestätigen, einen kontrollierten späteren Fehlschlag erzeugen
   und belegen, dass Name/Restfenster sowie fehlgeschlagener Versuch gleichzeitig
   wahr und im Systemstatus verständlich bleiben.

## 22. Rollout, Bestand und Rückbau

### 22.1 Vor Rollout

1. Den vollständigen Betriebsnachweis aus Abschnitt 6.4 mit einem zeitnahen
   produktionsgroßen Restore-Klon abschließen; Laufzeit, Peak-Platz,
   Schreibblockade, Tabellen-DDL und reserviertes Wartungsfenster dokumentieren.
2. App-, Worker- und Ansible-Revision/Digests dokumentieren.
3. Backup plus Restore-Nachweis gemäß ADR-0017.
4. Laufende/cancelling Jobs regulär drainieren.
5. Read-only Audit exportieren:
   - case-verschiedene Mission-/VM-/Cachewerte;
   - ESXi-Rohnamen mit Randwhitespace sowie Counts verworfener
     Leer-/NUL-/Steuerzeichenzeilen und Marker-UTF-8-Fehler, ohne deren Rohinhalt;
   - Missionen ohne WDS;
   - VMs mit null/eins/mehreren exakten WDS-Interfaces;
   - Nicht-WDS-MACs ohne WDS-MAC;
   - betroffene Jobs nur als IDs/Counts, keine Secrets.
6. Findings fachlich bestätigen; nichts automatisch korrigieren.
7. Die Rollbackrevision entweder mit dem beschriebenen Compatibility-Backport
   bauen und testen oder ausdrücklich als nach Cutover nicht aktivierbar sperren.

### 22.2 Deployment

1. Gemeinsamen Wartungsmodus und Claim-Pause aktivieren; alle mutierenden Portal-
   und Machine-API-Endpunkte liefern den dokumentierten 503-Retryvertrag,
   externe MECM-Tasks sind pausiert und Deploy-/Maintenance-Worker nach dem
   Drain gestoppt. Read-only Seiten sowie Health-/Migrationschecks bleiben
   erreichbar.
2. Migration ausführen; DDL-/Countnachweis und `migrate --check` müssen grün sein.
3. Portal, Worker und Ansible-Artefakte aus derselben Revision bereitstellen.
   Die erste Aktivierung des neuen Inventarwriters ist der irreversible Cutover.
4. Portal und Worker im Validierungsmodus starten; Machine-API-Writer und reale
   Missionsclaims bleiben gesperrt. Zulässig sind nur explizit freigegebene
   System-Inventarjobs und die benannten Canaries. PHP-/Deploy-/Maintenance-
   Gesundheit prüfen.
5. Alle ESXi-Zugänge neu inventarisieren. Für jedes benötigte Kind wird getrennt
   Semantik 2/Frische, answered-empty oder ein sichtbarer Abruffehler belegt.
6. Read-only Audit erneut ausführen.
7. Gültigen/ungültigen WDS-Canary, partiellen Inventarpull, Datacenter-Ableitung
   bei 48-Stunden-Grenze und späterem Fehlversuch, identischen aktiven,
   terminalen und abweichenden Zweitcallback sowie Nicht-Export-Modus-Callback
   durch die realen Grenzen führen.
8. Erst danach Schreibwartung beenden und reale betroffene Missionen freigeben.

### 22.3 Abbruchkriterien

Rollout sofort stoppen, wenn:

- ein case-abweichender Datastore wieder fremde Kapazität erhält;
- ein WDS-Blocker irgendeinen Remote-Upload oder ESXi-Schritt zulässt;
- `create`, `start` oder `autostart` allein wegen der speziellen WDS-Regel
  blockiert;
- ein Callback ohne verifizierte WDS-MAC eine VM auf `deployed/pending` setzt;
- ein Callback eines Nicht-Export-Modus oder fremden Attempts/Steps akzeptiert wird;
- ein `remote_v1`-Callback ohne exakt passendes aktuelles Export-Handle akzeptiert
  wird oder ein `legacy_v1`-Callback ein erfundenes Handle voraussetzt;
- ein abweichender Zweitcallback `result_json` oder VM-Daten überschreibt;
- ein Kind-Erfolg ein fehlgeschlagenes anderes Inventarkind als Semantik 2 ausgibt;
- ein Datacenter aus unbekannter, mehr als 172800 Sekunden alter, zeitlich
  ungültiger, nicht Semantik-2- oder mehrdeutiger Cachelage abgeleitet wird;
- ein Retry seinen Scope erweitert;
- Cachefehlen als Live-Abwesenheitsbeweis dargestellt wird;
- Migration und Fresh-Schema divergieren;
- Machine-API-Payload, Legacy-Statusstrings oder ADR-0030-Atomarität brechen;
- Help, DE/EN, CSP, RBAC, Schema-, SSoT- oder Guard-Gate rot ist.

### 22.4 Rückbau

- Nach Aktivierung des exakten Inventarwriters ist ein Rückbau auf eine Revision
  mit unverändertem case-insensitivem Writer verboten.
- App/Worker/Ansible dürfen nur gemeinsam auf eine vorherige Revision, wenn diese
  den getesteten Compatibility-Backport für Writer und Reader enthält. Vor
  Aktivierung des Altreaders stuft eine kontrollierte Transaktion sämtliche
  vorhandenen Kinds auf Namenssemantik 1 herab oder leert den vollständig
  rekonstruierbaren Inventarcache; ein Default oder nur zukünftige Writes
  genügen nicht. Der Altreader behandelt Semantik 1 in Optionen, Katalog,
  Storage, Datacenter-Ableitung und Abweichungen als unbekannt und darf
  bestehende Semantik-2-Zeilen niemals case-insensitiv als Wahrheit lesen.
- Nach der Herabstufung setzt der Backport jedes von ihm vollständig beantwortete
  und case-insensitiv geschriebene Kind in derselben Cachetransaktion auf
  Namenssemantik 1; failed/skipped verändert weder Zeilen noch Semantik. Der
  Katalogretirementpfad bleibt bis zum erneuten exakten Vorwärtsupgrade
  deaktiviert, damit der Altreader Case-Varianten nicht gegeneinander retired.
- Existiert dieser Backport nicht, bleibt nur Vorwärtsreparatur mit derselben
  exakten Writergeneration. Eine UI-/Worker-Aktivierung des Altstands bleibt
  technisch und betrieblich gesperrt.
- Die neuen case-sensitive Collations und additiven Semantik-/Observation-
  Spalten bleiben stehen; es gibt keinen destruktiven Down-Pfad.
- Inventarcache ist rekonstruierbar. Er darf bei einer kontrollierten
  Rückwärtskompatibilitätsmaßnahme neu abgerufen, aber nicht als Produktdatenbackup
  behandelt werden.
- Manuell bestätigte Portalnamen werden nie automatisch zurückgeschrieben.
- Der Backport wird mit bereits vorhandenen Semantik-2-Zeilen,
  case-verschiedenen Katalogzeilen, answered-empty, partial-kind failure,
  Altreaderzugriff und erneutem exakten Vorwärtsupgrade geprüft. Ein Default 1
  im Schema ersetzt das atomare Herabstufen geschriebener Kinds nicht.
- Historische `result_json`-Ergebnisse und Joblogs bleiben unverändert.

## 23. Definition of Done

Das Vorhaben ist erst abgeschlossen, wenn alle Punkte belegt sind:

- [x] ADR-0023 und ADR-0030 besitzen die beschriebenen Amendments.
- [x] Bestehender Netzwerk-/MAC-Plan verweist auf diese korrigierende SSoT.
- [x] Masterplan-Etappe 14A verweist auf diesen Plan und Pakete A bis I.
- [x] Operative VMware-Namensgleichheit ist überall case-sensitive.
- [x] `Prod` und `prod` sind getrennte Ziele; nur exakte Duplikate erzeugen
  den allgemeinen Mehrdeutigkeitsbefund.
- [x] Der Diagnosekey kann niemals Erfolg oder Kapazität beweisen.
- [x] Cache und VLAN-Katalog können Case- und Whitespace-Varianten ohne
  Trim-Collapse getrennt halten; unsupported Namen sind sichtbar.
- [x] Namenssemantik und Frische werden pro Inventarkind geführt.
- [x] Altcache wird je Kind bis zu dessen beantwortetem Neuabruf nicht als
  exakter Beweis ausgegeben.
- [x] Datacenter-Ableitung akzeptiert ausschließlich genau einen unterstützten
  Semantik-2-Rohnamen bis einschließlich 172800 Sekunden und bleibt vom
  Warn-only-Abweichungsvertrag getrennt.
- [x] `kind_observation_json` trennt den letzten Versuch von der letzten
  positiven Namensevidenz; ein späterer Fehlschlag kann beide Wahrheiten
  gleichzeitig sichtbar lassen.
- [x] Systemstatus ist einziger Detail-Owner für Ursache, Refresh und Joblog;
  andere Oberflächen nutzen den zentralen Kurzpresenter und den vorhandenen
  Deep-Link-Helper ohne Textduplikat.
- [x] Kein Text bezeichnet die 48-Stunden-Namensevidenz als aktuelle
  ESXi-Verfügbarkeits- oder Erreichbarkeitsgarantie.
- [x] `Daten` gegen `DATEN` ergibt Warnung plus offene Storagebewertung.
- [x] Jede Warnung nennt Mission/VM/Interface und den exakten Wert.
- [x] WDS-Portgruppe ist in Mission, VM, Deploy und Help eindeutig bezeichnet.
- [x] Full, Powercycle und Export blockieren null/mehrfach/case-falsch.
- [x] Create, Start und Autostart warnen, blockieren deswegen aber nicht.
- [x] Explizite Auswahl und „leer = alle“ stimmen in UI, Queue, Worker und Callback.
- [x] Staffelung ist all-or-nothing und erzeugt bei einem Blocker keine Zeile.
- [x] Queue- und Workerprüfung verwenden denselben Domainaggregator.
- [x] Worker blockiert vor Upload und erster Remoteoperation.
- [x] Gespeicherte Werte werden durch Cacheabweichungen nie blockiert.
- [x] MAC-Callback verlangt die exakte WDS-NIC und deren gültige MAC.
- [x] Callback prüft unter Locks Modus, Policy-Export-Step, Ausführungsvertrag,
  Attempt sowie Job-/Runtime-/Handle-Generation.
- [x] Callback-Expectation und Remote-Aktivierung sind getrennt; Create/Full
  bleiben bis Etappe 14B remote deaktiviert.
- [x] Callback sperrt in der Reihenfolge Mission -> Job -> Runtime-Identität ->
  gegebenenfalls Remote-Export-Handle -> VM -> Interfaces.
- [x] VM-Namen werden im Callback exakt ohne CI-SQL-Fallback aufgelöst.
- [x] Identischer Zweitcallback ist nur im aktiven Job, selben Attempt und
  derselben Generation 200/no-op; abweichend oder terminal ist 409 ohne
  Domainwrites.
- [x] Eine beliebige andere NIC kann keinen VM-Erfolg mehr erzeugen.
- [x] Per-VM-Atomarität, vollständige Resultatinvarianten, Partialstatus und
  Retryscope bleiben korrekt.
- [x] `vm_results` und Callback-Fingerprint sind im strikten V2 persistiert;
  historischer V1-Bestand bleibt eingeschränkt lesbar und wird nie mit einem
  beschädigten V2 verwechselt.
- [x] Request-, Resultat-, Response-, Anzeige-, Kandidaten- und JSON-Bounds sind
  mit PHP-ini, nginx und Uploader synchron;
  UTF-8-Identifier werden nie mitten im Codepoint abgeschnitten.
- [x] Anzeige-, Kandidaten- und JSON-Bounds haben einen einzigen Owner
  (`lib/deploy_preflight_bounds.php`); jede Liste liefert vollständiges `total`
  und Auslassungszahl, Kandidatengruppen zusätzlich `candidate_total` und
  `candidate_omitted_count`, und kein zweiter Pfad schneidet eine Befundliste.
- [x] Die Auswahl folgt einer kanonischen Gesamtordnung, die auf binären
  Vergleichen der exakt gespeicherten Bytes endet; Bytekappung entfernt
  deterministisch vom Listenende und setzt `truncated_by_bytes`, während
  abgeleitete Werte innerhalb der Kappungsschleife berechnet werden.
- [x] Der Backendguard bewertet weiterhin den vollständigen Scope; keine Kappung
  kann einen Blocker in eine Freigabe verwandeln.
- [x] Das Worker-`result_json` wird begrenzt statt abgelehnt; ein blockierter
  Auftrag endet nie fälschlich als `execution_failed`, und Decoder und
  Presenter lesen das gekürzte Dokument mit vollständigen Zählern.
- [x] `VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS` und
  `VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_INTERFACES_PER_VM` liegen in der Bounds-SSoT
  und werden vor jeder Remote-Arbeit in Queue, Staffelungsslot, Retry und Worker
  durchgesetzt; die Gruppenunion ist begründet ausgenommen.
- [x] Ein Worst-Case-Test belegt, dass der maximal zulässige reguläre Jobscope
  ein vollständiges V2-Resultat und eine vollständige Antwort jeweils unter
  1 MiB erzeugt, und ein Gegentest belegt, dass der doppelte Scope eine Grenze
  reißt. Ein regulär großer Job scheitert damit nicht erst nach realem Export.
- [x] Missions-WDS-Änderung verändert keine VM automatisch.
- [x] Alle Writer, Import-/Klon-/Reassignpfade verwenden den Domainvertrag.
- [x] Meldungen sind lokalisiert, handlungsfähig, responsive und barrierearm.
- [x] Help und aktive Dokumentation beantworten alle Punkte aus Abschnitt 17;
  ausführliche Datacenter-Ursachen und Reparaturschritte existieren nur einmal
  unter Systemstatus.
- [ ] Unit-, Integration-, Static-, Guard-, E2E-, Visual- und Stagingmatrix ist grün.
  Stand 01.09.2026: Unit, Static, Integration und Guard sind grün, `phpunit-full`
  ohne Skips. Offen bleibt der Visual-Determinismusvergleich: `missions-desktop`
  light weicht zwischen den beiden Aufnahmen einer Sitzung um 38 von 1 440 000
  Pixeln ab, ±1 je Farbkanal auf antialiasten Kanten. Die Seite und das
  zuständige `components.css` liegen außerhalb dieser Etappe, die Laufmetadaten
  eines grünen und eines roten Laufs sind identisch, und der Vergleichsvertrag
  hat Null-Toleranz. Belege in `docs/QA.md`. Nicht durch Wiederholen grün
  gemacht und nicht durch Toleranzanhebung erledigt: Sollbaselines gehören zu
  Etappe 17.
  Ebenso offen bleibt `e2e-browser-matrix`: drei von 482 Tests fallen nur auf
  Firefox und WebKit. Auch das ist kein Etappenbefund. `deploy-log.spec.js:120`
  und `deploy-recovery.spec.js:38` bestehen bei isoliertem Nachlauf auf WebKit
  gegen denselben Commit (14 von 14 grün), `directory-ad.spec.js:436` liegt in
  einer Domäne, deren Dateien der Commit überhaupt nicht anfasst, und dieselbe
  Suite ist auf Chromium und auf Windows-Edge vollständig grün.
- [ ] Produktionsgroßer Restore-Klon belegt Laufzeit, Peak-Platz,
  Schreibunterbrechung und unveränderte DDL-Eigenschaften.
- [ ] Die DDL-Phase weist auch Machine-API-Writer und externe MECM-Tasks nach;
  der Validierungsmodus erlaubt nur Inventar-/Canarywrites und keine realen
  Missionsclaims.
- [ ] Nach Writer-Cutover ist Altcode ohne getesteten Semantik-1-Backport
  technisch und betrieblich gesperrt.
- [ ] Der Rollbackbackport stuft vorhandene Semantik-2-Kinds vor Altreaderstart
  herab oder leert den Cache und behandelt Semantik 1 in jedem Reader
  fail-closed; nur zukünftige Writer zu markieren genügt nicht.
- [ ] Fast-, Integration- und Release-Lane zeigen den vorgeschriebenen
  `[n/total]`-Fortschritt und sind grün.
  Stand 01.09.2026: Fortschrittsvertrag in allen drei Lanes eingehalten. Fast
  `29 pass / 0 fail / 0 skip`. Integration `35 pass / 1 fail / 0 skip`. Release
  auf dem Commit `42 pass / 2 fail / 0 infrastructure_error / 0 skip`, jedes
  Nicht-Browser-Gate grün einschließlich Restore-Drill, Secret-Scan, SBOM,
  Image-CVE, Offline-Bundle und npm-audit. Die zwei Fehler sind die oben
  beschriebenen Browserbefunde außerhalb dieser Etappe. Damit ist dieser Punkt
  bewusst NICHT abgehakt.
- [ ] Backup, Bestandsaudit, kindweises Semantik-2-Inventar, Canary und
  Rollbackentscheidung sind dokumentiert.

Die offenen Punkte 2159 bis 2168 und 2171 bis 2172 sind bewusst die physische
Standortgrenze: produktionsgroßer Restore-Klon, reale Writer-Unterbrechung,
Writer-Cutover/Altreader-Backport, Bestandsinventar, Canary und
Rollbackentscheidung. Sie sind keine vertagte lokale Codearbeit. Der lokale
Stand sperrt Create/Full bis Etappe 14B und bis zur standortbezogenen Freigabe;
synthetische QA darf diese Nachweise nicht ersetzen.

Es bleiben danach nur die bewusst benannten physikalischen Grenzen: Ein
Inventar-Snapshot beweist keine aktuelle Hostkonfiguration, ein Portgruppenname
beweist keinen laufenden PXE-Dienst, und das Portal kann externe Änderungen
zwischen Prüfung und realem vSphere-Aufruf nicht verhindern. Es darf diese
Grenzen aber niemals als Erfolg darstellen oder durch Case-Folding verdecken.
