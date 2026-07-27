# ESXi-Inventar (read-only)

Das Portal liest in regelmäßigen Abständen read-only aus den registrierten ESXi-Zugangsdaten: Datacenter, Datastores (mit Kapazität/frei), Portgruppen (Standard und Distributed) sowie Host-Kapazität (RAM, CPU-Kerne, Modell). Die Werte sind Snapshots vom letzten Abruf, kein Live-Monitoring (ADR-0023). ESXi ist die Quelle, der Cache im Portal nur ein Spiegel: er speist Anzeige und Warnungen, blockiert aber nie einen Deploy.

## Wie es läuft

- Ein Systemjob im Modus `inventory` (missionslos) fährt das read-only Playbook `Ansible/inventoryESXi_playbook.yml` über den vorhandenen Deploy-Worker; nur `community.vmware`-`*_info`/`*_facts`-Module, keine Änderungen am Host.
- Der Wartungsdienst reiht je ESXi-Zugangsdatum einen Abruf ein, wenn der letzte Erfolg älter als das Intervall ist (Einstellung `esxi_inventory_interval_hours`, Standard 6, 0 = aus).
- Nach dem Anlegen/Ändern eines ESXi-Zugangsdatums wird sofort abgerufen; im Systemstatus gibt es einen manuellen Refresh (Recht `deploy.run`). „Inventarabruf starten" auf der Seite Zugangsdaten stößt denselben Abruf an (siehe unten).
- „Alle aktualisieren" überspringt auth-pausierte Zugangsdaten (Kontosperren-Schutz) und weist die Anzahl im Hinweis aus. Ein pausiertes Zugangsdatum gezielt neu versuchen: sein eigener Aktualisieren-Button (bewusster Einzel-Retry) oder das Zugangsdatum speichern (hebt die Pause auf). Der Hinweis schlüsselt auch „bereits in der Warteschlange" auf und nennt bei fehlendem/mehrdeutigem Ansible-Zugangsdatum den Grund samt Einstellungs-Verweis.
- Nach einem erfolgreichen create- oder full-Deploy (neue VMs, veränderte Datastore-Belegung) wird automatisch ein Abruf für das genutzte ESXi-Zugangsdatum eingereiht. Power-Cycle, Start und Export lösen keinen Abruf aus (sie erzeugen keine Ressourcen). Der Doppel-Einreihungs-Guard verhindert Dopplungen mit der Intervall-Automatik.
- Ein Zugangsdatum, das noch nie erfolgreich war (falscher Host, fehlende Rechte), wird nicht bei jedem Prüf-Zyklus neu versucht, sondern erst nach Ablauf des Intervalls (Sperre auf den letzten Versuch, nicht nur den letzten Erfolg). Auth-Fehler pausieren den Auto-Abruf ganz.
- Anzeige im Systemstatus, Abschnitt „ESXi-Inventar": je Zugangsdatum zunächst eine kompakte Karte mit Zustand, Objektzahlen, den Zeitpunkten von Versuch und Erfolg, der Taktzeile darunter (welche Gründe sie nennt, steht in der Blocker-Tabelle weiter unten) und einem offenen Auftrag. Fähigkeits-Marken erscheinen nur, wenn der Abruf welche gemeldet hat. Tabellen und Kapazitäten werden erst über „Inventardetails öffnen" für genau diese Karte geladen.
- Jeder Abruf ist ein Systemauftrag. Beendete Systemaufträge werden nach 30 Tagen samt Ausgabe entfernt, denn sie tauchen in keiner Liste auf und ihr bleibendes Ergebnis ist die Ampel. Laufende und eingereihte Aufträge werden nie angefasst.

## Setup-Reihenfolge

1. Unter Zugangsdaten ein ESXi-Konto anlegen (Read-only-Rechte genügen für das Inventar, siehe unten). Bei genau einem Ansible-Zugang wird er automatisch verwendet; bei mehreren unter Einstellungen → Kataloge und Inventar einen auswählen.
2. Ersten Abruf abwarten (Sofort-Pull nach dem Anlegen oder manueller Abruf). Der Systemstatus zeigt Versuch und Ergebnis; offene Aufträge sowie der letzte beendete Abruf führen direkt in ihr Jobprotokoll.
3. Erst danach greift der ESXi-owned VLAN-Katalog (Rollout-Vorbedingung): Portgruppen erscheinen als Auswahl, `vlans.php` wird read-only.

## Benötigte ESXi-Rechte

Zwei Profile. Für das reine Inventar genügt Profil 1; das Deployen von VMs braucht Profil 2.

| Profil | Wofür | Rechte (Kurzform) |
|---|---|---|
| Inventar (read-only) | Datacenter, Datastores, Portgruppen, Host-Facts, Produkt/Lizenz lesen | Read-only-Rolle auf den betroffenen Objekten (Datacenter, Datastore, Netzwerk/Portgruppe, Host) genügt |
| Deploy (Playbooks) | VMs anlegen, Storage belegen, Netzwerk zuweisen, Power-Ops | VM anlegen/registrieren, Datastore-Platz belegen, Netzwerk/Portgruppe zuweisen, Power On/Off (Powercycle), Gast-/Konfigurationsänderungen für Disks/NICs |
| Autostart (Modus `autostart`) | Autostart-Liste des Hosts schreiben | Host-Konfiguration ändern (Autostart-Manager). Zusätzlich zum Deploy-Profil. |

Die exakten Privilegien-Bezeichner werden bei der Umsetzung gegen die community.vmware-Moduldoku und den produktiven Host verifiziert. Voraussetzung für Deploy und Autostart: keine Free-ESXi-Lizenz (community.vmware braucht die API-Schreibzugriff-Lizenzstufe; durch die funktionierenden create-Playbooks faktisch belegt). Reicht ein Recht nicht, erscheint im Portal die Fehlerkategorie „unzureichende Berechtigungen" (`authz`).

## Fehlerbilder (nie blockierend, Cache bleibt immer)

| Kategorie | Bedeutung | Maßnahme |
|---|---|---|
| `dns` | Hostname nicht auflösbar | Hostnamen im Zugangsdatum prüfen; DNS/Suchdomäne des Portal-Containers prüfen |
| `unreachable` | Host aus/nicht erreichbar, Timeout | Netzwerk/Host prüfen; Auto-Retry beim nächsten Intervall; alter Cache bleibt |
| `tls` | Zertifikat wurde abgelehnt | Betrifft nur den SSH-Weg zum Ansible-Host; der ESXi-Abruf prüft keine Zertifikate |
| `auth` | Falscher Benutzer/Passwort | Zugangsdatum korrigieren; **Auto-Abruf pausiert** bis zur Änderung (Kontosperren-Schutz), kein Auto-Retry |
| `authz` | Rechte reichen nicht | ESXi-Rolle des Kontos erweitern (Profil oben) |
| `http` | Host antwortet mit unerwartetem Status | Statuscode ansehen; erscheint bei ESXi-Abrufen nicht mehr |
| `ssh` | Ansible-Host nicht erreichbar/Preflight | Ansible-Zugangsdatum und Host prüfen (wie bei Deploy-Jobs) |
| `worker` | Deploy-Worker verlor während des Abrufs sein Lebenszeichen | Deploy-Worker im Systemstatus prüfen und neu starten; das Jobprotokoll nennt die Reaper-Ursache |
| `parse` | Ausgabe unerwartet/Marker fehlt | Job-Log ansehen; Playbook/Modulversion gegen den Host prüfen |
| `config` | Zugangsdatum unvollständig oder Typ nicht unterstützt | Host, Benutzername und Typ im Zugangsdatum prüfen |

Die Kategorien sind die SSoT-Liste `VIRTUSPHERE_INVENTORY_ERROR_*` in `lib/deploy_constants.php`. Der technische Originaltext steht im Portal hinter „Technische Details" am Alert und zusätzlich in `logs/error.log`.

Ampel je Zugangsdatum: Sie zeigt **nur die Gesundheit des Abrufs**. `warning` bei fehlgeschlagenem letzten Abruf, noch nie erfolgreichem Zugangsdatum oder veraltetem Erfolg (älter als `VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR` x Intervall, aktuell 2x); `danger` bei Fehlerserie ab `VIRTUSPHERE_ESXI_INVENTORY_FAILURE_STREAK_DANGER` (aktuell 3) oder Auth-Pause. Bei Intervall 0 (Automatik aus) entfällt die Veraltet-Warnung; das Alter beweist dann nichts. Die Hilfe interpoliert dieselben Konstanten in ihrer Erklärung, Text und Verhalten können nicht auseinanderlaufen.

Dieselbe Ampel erscheint an drei Stellen und wird aus demselben Health-Snapshot berechnet: auf der ESXi-Karte im Systemstatus, als Zeiger-Badge auf der Seite Zugangsdaten und als Dashboard-Kachel „Hypervisor". Host-Eigenschaften (freie Lizenz, HA-Cluster, Wartungsmodus) färben diese Ampel nicht; sie sind eigene Badges und werden beim Deploy über den Preflight (ADR-0025) durchgesetzt, nicht über die Ampelfarbe.

Was die Ampel nicht beantwortet, steht als Taktzeile darunter: auf der Seite Zugangsdaten und auf der Inventarkarte im Systemstatus nennt jede Zeile unter Badge und Zeitpunkt, ob sich der Wert von allein erneuert. Badge über Zeitstempel heißt im Portal sonst überall „letzte Abfrage", und gepollt wird nur ESXi. Drei Dinge halten diesen Abruf an, und jedes muss die Zeile beim Namen nennen, sonst ist sie dieselbe stille Behauptung an neuer Stelle:

| Blocker | Reichweite | Zeile sagt |
|---|---|---|
| Intervall 0 | alle Zugangsdaten | kein automatischer Abruf (Intervall 0) |
| Kein auflösbarer Ansible-Zugang (keiner, mehrere ohne Auswahl, Auswahl gelöscht) | alle Zugangsdaten | kein automatischer Abruf, kein Ansible-Zugang gewählt |
| Auth-Pause | dieses Zugangsdatum | pausiert, kein automatischer Abruf |

Die Reihenfolge ist die, in der ein Betreiber sie beheben muss, nicht die der Prüfung: „pausiert" zu melden, während das Intervall auf 0 steht, verspräche, dass das Aufheben der Pause den Zyklus startet. Der zweite Fall ist der stillste: ohne Ansible-Host wird kein Abruf eingereiht und nicht einmal ein Versuch gestempelt, der Zeitstempel friert also ein, während die Einstellung weiter sechs Stunden liest.

Wer prüft und wer anzeigt, entscheidet dabei nicht getrennt: `esxi_inventory_automation_blocker()` (`lib/esxi_automation.php`, Werteliste `VIRTUSPHERE_ESXI_AUTOMATION_BLOCKERS`) ist dieselbe Bedingung, auf die der Wartungsdienst überspringt und aus der die Zeile ihren Satz macht. Ein vierter Grund, der nur im Dienst ergänzt würde, hätte die Zeile sonst wieder einen Zyklus versprechen lassen, den es nicht gibt; die Zuordnung Grund → Satz ist ein `match` ohne Default und wird gegen die Werteliste getestet, bricht also im Build und nicht auf der Seite.

## Grüner Abruf, trotzdem eine 0

Ein Abruf ist kein einzelner Vorgang, sondern mehrere getrennte Abfragen: Datacenter, Datastores, Standard-Portgruppen, Distributed-Portgruppen, Host-Daten. Nur die erste ist der Verbindungstest; bleibt eine der übrigen ohne Ergebnis, läuft der Abruf trotzdem sauber zu Ende. Die Karte zeigt für diese Art dann eine 0 und die Ampel bleibt grün, denn die Ampel beantwortet ausschließlich, ob der Abruf gelaufen ist. Eine 0 neben gefüllten Zahlen ist also kein Widerspruch, sondern der Hinweis, genau eine Abfrage nachzusehen.

Woher die einzelnen Zahlen kommen und was eine 0 dort bedeutet:

| Zahl auf der Karte | Wird gelesen von | 0 heißt üblicherweise |
|---|---|---|
| Datacenter, Datastores | Host oder vCenter, je nach Zugangsdatum | Selten. Meist darf das ESXi-Konto die Objekte nicht sehen; scheitert die Datacenter-Abfrage ganz, ist der Abruf rot statt grün |
| Portgruppen (Standard) | dem ESXi-Host selbst | Zugangsdatum zeigt auf ein vCenter statt auf den Host, oder der Portalstand ist älter als der Portgruppen-Fix (siehe Changelog) |
| Portgruppen (Distributed) | nur vom vCenter | Normalfall an einem einzelnen ESXi-Host: dort gibt es keine Distributed-Portgruppen |
| Hosts | dem ESXi-Host selbst | Zugangsdatum zeigt auf ein vCenter statt auf den Host |

Nachsehen muss man dafür nichts raten: **jeder Abruf protokolliert selbst, welche seiner Abfragen geantwortet hat.** Im Job-Log (Systemstatus, Auftrag der jeweiligen Karte) steht direkt unter den Zahlen eine Zeile `Inventory queries:`. Sie hat genau zwei Formen:

```
Inventory queries: all 7 answered.
Inventory queries: 6 of 7 answered, 1 without an answer - networks_standard rejected (one of the following is required: cluster_name, esxi_hostname)
```

Die erste Form heißt: alle Zahlen sind belastbar, eine 0 ist dann eine echte 0. Die zweite nennt jede stumme Abfrage beim Namen und in zwei Worten den Grund. Scheitern mehrere Abfragen an derselben Ursache, was bei einem falschen Zugangsdatum oder einer unpassenden Modulversion der Normalfall ist, stehen sie gemeinsam vor einer Meldung (`datastores, hosts rejected (...)`), statt dieselbe Meldung mehrfach zu wiederholen:

| Wort | Bedeutung | Was zu tun ist |
|---|---|---|
| `answered` | Die Abfrage lief und hat geantwortet, auch wenn die Antwort leer war | Nichts. Eine 0 bedeutet hier wirklich „gibt es nicht"; der Cache übernimmt sie dann auch (siehe unten) |
| `rejected` | Die Abfrage wurde abgelehnt, oft bevor sie ESXi überhaupt erreicht hat | Die Meldung in Klammern lesen; sie stammt wörtlich vom Ansible-Modul und steht ausführlich weiter oben im selben Job-Log |
| `skipped` | Das Playbook hatte nichts zu fragen, etwa Distributed-Portgruppen ohne bekanntes Datacenter | In der Regel nichts. Erwartet man hier Daten, zuerst die Abfrage darüber prüfen, von der sie abhängt |

Die Namen in der Zeile sind die Abfragen, nicht die Kartenzahlen. Welche es gibt und was sie speisen (die Zeile selbst nennt ihre Gesamtzahl, hier steht bewusst keine zweite):

| Abfrage | Speist |
|---|---|
| `datacenters` | die Zahl „Datacenter"; zugleich der Verbindungstest des Abrufs |
| `datastores` | die Zahl „Datastores" samt Kapazität und freiem Speicher |
| `networks_standard`, `networks_dvs` | gemeinsam die Zahl „Portgruppen" und den VLAN-Katalog |
| `hosts` | die Zahl „Hosts" samt RAM, Kernen und Uhrabweichung |
| `about`, `host_runtime` | die Hinweise zum Host (Produkt, Lizenz, HA-Cluster, Wartungsmodus), keine Kartenzahl |

Eine Zeile ohne `Inventory queries:` stammt aus einem Abruf, der älter ist als dieser Bericht; sie behauptet bewusst nichts über Vollständigkeit.

Für das Inventar ist ein Zugangsdatum **auf den ESXi-Host selbst** der geprüfte und empfohlene Weg; ein vCenter-Zugangsdatum liefert Datacenter und Datastores, aber weder Standard-Portgruppen noch Host-Daten.

Ein leeres Teilergebnis ist seit dem per-Query-Bericht zwei verschiedene Dinge, und der Cache behandelt sie verschieden:

- Haben **alle** Abfragen einer Art geantwortet (bei Portgruppen: beide) und die Antwort ist leer, dann ist die 0 belastbar: der Cache **löscht** die Art für dieses Zugangsdatum. Das Job-Log sagt es wörtlich (`network: cleared (host reports none, 2 removed)`); alte Zeilen stehen zu lassen hieße, Portgruppen anzuzeigen, die der Host verloren hat.
- Wurde eine Abfrage abgelehnt oder übersprungen, beweist die leere Liste nichts: die vorhandenen Zeilen bleiben eingefroren stehen (`kept (empty result, not authoritative)`), ihre Frische wird **nicht** erneuert. In den Inventardetails trägt jede Art dafür ihren eigenen Stand („Stand: …"); eine eingefrorene Art zeigt dort ein älteres Datum als der letzte Erfolg der Karte, und genau das ist die Wahrheit.
- Ein Abruf ohne den per-Query-Bericht (älteres Playbook) entscheidet nie autoritativ: leer heißt dort weiterhin „Bestand behalten".

Zusätzlich bilanziert jede erfolgreiche Abholung ihre Normalisierung (`Inventory normalization: …`): wie viele rohe Modul-Einträge ankamen und wie viele davon verwertbar waren. Ein Eintrag, dessen Form nicht mehr passt, sah vorher exakt aus wie ein Host, der weniger hat. Der VLAN-Katalog zieht Portgruppen unverändert erst zurück, wenn ein Abruf sie nachweislich nicht mehr meldet (Retire, nie Delete).

## „Inventarabruf starten" bei ESXi ist ein echter Auftrag

Das Portal spricht ESXi **nie** direkt an. „Inventarabruf starten" auf der Seite Zugangsdaten hebt bei einem ESXi-Zugangsdatum die Auth-Pause auf und reiht denselben read-only Abruf ein, den auch das Speichern auslöst: über den Ansible-Host, per pyVmomi/SOAP gegen `/sdk`, mit `validate_certs: false`. Damit läuft genau der Weg, den ein Deploy nimmt.

Konsequenzen für den Betrieb:

- **Die Zertifikatsvariante ist egal.** Selbstsigniert ab Werk, von der VMCA des vCenters oder von der Domänen-CA: die Prüfung ist im Playbook bewusst aus. Eine strikte TLS-Prüfung ist eine spätere Härtungsentscheidung (WP7) und gehört in eine ADR, nicht still in einen Stream-Kontext.
- **Die Meldung und der Befund haben getrennte Aufgaben.** Der Flash unterscheidet eingereiht, bereits offen oder fehlende/uneindeutige Ansible-Auswahl. Der dauerhafte Befund steht im Systemstatus; eingereihte und laufende Aufträge sowie der letzte beendete Abruf sind dort direkt verlinkt. Auch ein erfolgreicher Abruf behält den Link, damit Teilabfragen und Nullwerte prüfbar bleiben.
- **Das Jobprotokoll bleibt die technische Wahrheit.** `deploy_esxi_inventory_state.last_job_id` merkt sich nur, welcher Auftrag den dauerhaften Befund erzeugt hat; Status und Ausgabe bleiben in `deploy_jobs` und `deploy_job_logs`. Der Fremdschlüssel verwendet `ON DELETE SET NULL`: löscht die Aufbewahrung den 30 Tage alten Systemauftrag, zeigt ein alter Fehler einen verständlichen Hinweis statt eines toten Links. Ein neuer Abruf erzeugt wieder einen exakten Verweis.
- **Ein Ansible-Zugangsdatum wird weiterhin sofort geprüft** (SSH-Anmeldung plus Preflight), denn dieser Weg läuft aus dem Portal heraus. Einen Scheduler hat er nicht, deshalb altert sein Ergebnis: Ein bestandener Preflight wechselt nach `VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS` (aktuell 7) Tagen auf „Unbestätigt", denn ein Grün von vor Wochen beweist nichts über heute. Ein Fehlschlag altert nie; ein bekannter Defekt darf nicht ins Graue verschwinden. Indirekt wird der Host trotzdem laufend benutzt, weil jeder ESXi-Abruf über ihn läuft, aber dieses Ergebnis landet auf der ESXi-Zeile, nicht auf dem Ansible-Badge.

Historisch prüfte der Test `{host}:{port}/rest/appliance/system/version` mit PHPs strikter Zertifikatsprüfung. Beides war falsch: der Pfad gehört zur Management-API der vCenter Server Appliance und fehlt auf einem einzelnen ESXi (HTTP 404 trotz korrekter Zugangsdaten), und die Prüfung lehnte das ab Werk selbstsignierte Host-Zertifikat ab, das der Betrieb akzeptiert. Der Test meldete also rot bei völlig gesunden Zugangsdaten. Dieser Weg ist entfernt (ADR-0023, drittes Amendment).

## Was der Host kann: Capability-Hinweise

Nach jedem **erfolgreichen** Abruf merkt sich das Portal je Zugangsdatum, was der Host ist und was er gerade tut: `api_type` (direkt am Host oder über vCenter), Produkt und Version, Lizenzname, ob es eine freie Lizenz ist, ob der Host in einem vSphere-HA-Cluster liegt und ob er im Wartungsmodus ist.

Das sind **keine Fehlerkategorien**. Die Tabelle oben beschreibt einen Abruf, der gescheitert ist; diese Angaben beschreiben einen Host, dessen Abruf geklappt hat. Ein Zugangsdatum kann grün leuchten und trotzdem einen Hinweis tragen.

| Hinweis | Badge | Bedeutung | Auswirkung |
|---|---|---|---|
| Freie Lizenz | gelb | Die freie ESXi-Lizenz (auch 8.0 U3e) hat nur eine lesende API. | Inventar läuft. Bereitstellen und Autostart sind gesperrt; ein Autostart-Auftrag bricht mit klarer Meldung ab. |
| HA-Cluster | gelb | Der Host gehört zu einem vSphere-HA-Cluster. | ESXi schaltet dort den Autostart ab. Der Autostart-Schritt wird übersprungen (im Modus `full`) beziehungsweise der Auftrag abgelehnt (im Modus `autostart`). |
| Wartungsmodus | grau/blau | Vorübergehender Zustand des Hosts. | Nur Information, keine Fehlkonfiguration. |

**Unbekannt heißt unbekannt.** Ist eine Angabe nicht bekannt (nie abgerufen, alter Cache, Modul liefert das Feld nicht), erscheint gar kein Hinweis. Sie wird nie als „ist in Ordnung" gelesen: eine Ablehnung aus einer **Fähigkeit dieses Hosts** (freie Lizenz, HA-Cluster) setzt immer eine Angabe aus einem **frischen** erfolgreichen Abruf voraus. Veraltete oder fehlende Angaben warnen dort nur und lassen den Auftrag laufen, denn ESXi bleibt die Autorität (Cache-blockiert-nie-Regel).

Genau **eine** Ablehnung gehorcht dieser Frische-Regel nicht, und das ist beabsichtigt: das **Datacenter**. Das ist keine Aussage über den Host, sondern ein Pflichtfeld der erzeugten `serverlist.yml`, für das der Cache nur die Standardquelle ist. Lässt die Mission es leer und meldet der gewählte Zugang nicht genau eines (noch kein Abruf, oder mehrere), wird der Auftrag abgelehnt, statt mit leerem Wert zu laufen. Die Unterscheidung steht in ADR-0023, Nachtrag 4. Ein Datacenter, das die Mission **trägt**, fragt den Cache gar nicht; ein Wert, den der Cache nicht kennt, ist immer nur eine Warnung.

**Verifikation der Capability-Feldpfade am produktiven Host (einmalig nach Rollout):** Inventarabruf starten, im Job-Log die Zeile `ESXi capabilities: product=... api=... license=...` lesen und mit der Karte im Systemstatus vergleichen. Stimmt ein Feldpfad nicht, bleibt der Wert leer und es erscheint kein Hinweis; kaputtgehen kann nichts.

Dabei gleich drei Anzeigen mitprüfen, die dieselben Facts lesen und bisher nur gegen eingespielte Testdaten belegt sind:

- **Endpunktzeile auf der ESXi-Karte** („über vCenter · VMware ESXi 8.0.2"). Sie erwartet `about.apiType` mit genau `VirtualCenter` oder `HostAgent`; jeder andere Wert wird bewusst nicht geraten, dann nennt die Zeile nur die Version. `SystemStatusHostFactsTest` pinnt diese Zuordnung.
- **Kernanzahl je Host-Zeile** unter „Inventardetails öffnen" (`ansible_processor_cores`, ersatzweise `hardware_num_cpu_cores`).
- **Uhrabweichung** ebendort. Sie erscheint nur, wenn die Facts eine Host-Zeit liefern (`ansible_host_date_time_epoch`) **und** die Abweichung `VIRTUSPHERE_ESXI_CLOCK_SKEW_WARN_SECONDS` erreicht. Bleibt sie auf einem Host mit bekannt verstellter Uhr aus, fehlt der Fact, nicht die Abweichung.

Auch hier gilt durchgehend: fehlender Fact heißt keine Anzeige, nie eine erfundene Zahl.

**Bewusste Grenze: `connectionState` wird nicht abgefragt.** Diese vCenter-Eigenschaft beschreibt, ob ein Host aus Sicht seines vCenters verbunden ist (`connected`/`disconnected`/`notResponding`). Auf dem unterstützten Deploy-Weg, direkt gegen den Standalone-Host, ist sie ohne Aussage: erreicht der Abruf den Host, ist er verbunden, erreicht er ihn nicht, meldet die Fehlerkategorie das präziser. Auf dem read-only vCenter-Inventarweg hieße das: ein im vCenter als getrennt geführter Host fällt hier nur dadurch auf, dass seine Abfragen leer oder abgelehnt ausfallen, nicht durch einen eigenen Hinweis. Wer diesen Zustand überwachen will, tut das im vCenter selbst.

## Autostart auf dem ESXi-Host

Ausführlich in ADR-0025. Kurz für den Betrieb:

- Die Mission trägt die **Standardwerte des Hosts** (Start-/Stoppverzögerung, Aktion beim Herunterfahren, Warten auf den VMware-Tools-Heartbeat), jede VM optional einen **eigenen Wert**. Ein leeres Verzögerungsfeld an der VM bedeutet „erbt von der Mission". `0` bedeutet „ohne Wartezeit" und ist etwas anderes.
- Die **Reihenfolge** ergibt sich aus den Startverzögerungen, nicht aus einer Nummer. Grund: ein offener Fehler in `community.vmware` lehnt jede Startreihenfolge über 1 ab.
- Der Modus `autostart` schreibt die Richtlinie. Ein Häkchen zurückzunehmen und den Modus erneut zu fahren entfernt den Eintrag der VM wieder. Nur VMs, die im Auftrag ausgewählt sind, werden geschrieben.
- Der Autostart-Schalter des **Hosts** wird nie ausgeschaltet, nur eingeschaltet: auf einem Host können VMs mehrerer Missionen liegen. Teilen sich mehrere Missionen einen Host, gewinnt die zuletzt geschriebene Mission dessen Standardwerte. Deshalb die Verzögerungen je VM überschreibbar.
- `guestShutdown` und „auf Heartbeat warten" brauchen die **VMware Tools** im Gast. Fehlen sie, verstreicht die Stoppverzögerung und die VM wird hart ausgeschaltet.

## Offline-/Datenschutz-Garantie

- Fehlgeschlagene Abrufe (unreachable/auth/authz) sind nie ein Abwesenheitsbeweis; ohne frischen Erfolgslauf wird nichts retired. Der Katalog friert auf dem letzten guten Stand ein.
- „Frischer Erfolgslauf" heißt: eine Zustandszeile mit `last_status = 'ok'` **und** einem `last_success_at`-Zeitstempel (`repo_esxi_inventory_has_fresh_success()`). Das Statuswort allein genügt nicht, denn eine Zeile kann `ok` tragen, ohne dass je ein Abruf durchgelaufen ist (Backfill, von Hand gesetzt). Eine solche Zeile darf das Stilllegen nicht scharfschalten.
- Ein Erfolgslauf mit 0 Ergebnissen für eine Kategorie behält den Bestand (Leer-Ergebnis-Guard).
- Das Ersetzen der Cache-Zeilen eines Zugangsdatums ist DELETE plus INSERT und läuft in einer Transaktion (`repo_transaction()`). Ein Abbruch mitten drin darf den leeren Zwischenstand nie committen: der Sync liest genau diese Tabelle als Anwesenheitsbeweis und würde die Portgruppen des Hosts stilllegen. Der Sync selbst liest Beweis und Katalog in derselben Transaktion, entscheidet also nicht gegen einen Stand, den ein paralleler Abruf schon ersetzt hat.
- Missions- und VM-Felder sind Name-Strings und werden vom Sync nie angefasst; ein Zugangsdaten-Löschen entfernt nur Cache-Zeilen.

## ESXi-Uhrabweichung (Diagnose)

Sofern die Host-Facts eine Host-Zeit liefern, zeigt die Host-Zeile unter „Inventardetails öffnen" (Systemstatus) eine Uhrabweichung zum Pull-Zeitpunkt an, sobald sie `VIRTUSPHERE_ESXI_CLOCK_SKEW_WARN_SECONDS` erreicht; darunter wird nichts gezeigt, weil ein paar Sekunden normal sind. Dieselbe Konstante interpoliert die Hilfe in ihre Erklärung, Text und Verhalten können nicht auseinanderlaufen. Das ist eine Diagnose, keine Live-Uhr: eine stark verstellte ESXi-Uhr kann den Domänenbeitritt frisch erzeugter VMs (Kerberos, ±5 min) brechen. Behebung über NTP auf dem ESXi-Host, nicht im Portal.

## Datacenter und Datastore: Mission als Standard, VM als Ausnahme

Beide Felder sind harte Auswahlfelder aus dem Inventar. Beim Datastore trägt jede Option den freien Speicher des letzten Abrufs; der gespeicherte Wert bleibt der reine Name. Ein gespeicherter Wert, den das Inventar nicht kennt, bleibt als eigene Option mit dem Zusatz „nicht im aktuellen Inventar" wählbar und geht nie verloren; die Auswahl vergleicht dabei exakt, alle Warnlogik case-insensitiv.

Freitext erscheint nur noch, solange das Inventar einer Art leer ist: dann gibt es nichts anzubieten, und ein reines Dropdown würde handlungsunfähig machen. Sobald Namen im Cache liegen, kommen neue Werte über einen Inventar-Abruf hinzu. Ein Wert, der nur auf einem noch nie abgerufenen Zugang existiert, ist bis zu dessen erstem Abruf nicht wählbar; das ist ein bewusster Kompromiss. Die Maschinen-API und der Missions-Import akzeptieren weiterhin beliebige Zeichenketten. Ein Wert außerhalb des Inventars wird nachträglich als Abweichung gemeldet, nicht vorab verhindert.

Auflösung je VM beim Deploy:

| Feld | 1. Wahl | 2. Wahl | 3. Wahl |
|---|---|---|---|
| Datacenter | VM-Wert | Missionswert | das einzige Datacenter des gewählten ESXi-Zugangs |
| Datastore | VM-Wert | Missionswert | keine, der Missionswert ist Pflicht |

**Der Datastore der Mission bleibt Pflicht.** Ein Host hat fast immer mehrere, und das Ansible-Modul
hat dafür keinen Standardwert. Das **Datacenter der Mission darf dagegen leer bleiben**: ein
einzelner ESXi-Host kennt genau ein (implizites) `ha-datacenter`, das erst beim Bereitstellen
feststeht, weil dort der Zielhost gewählt wird. Beim Einreihen eines Auftrags wird geprüft, ob sich
der Wert auflösen lässt; ist er es nicht, wird der Auftrag abgelehnt:

| Lage beim Einreihen | Ergebnis |
|---|---|
| Mission hat ein Datacenter | wird verwendet, das Inventar wird nicht befragt |
| Mission leer, Zugang meldet genau eines | wird übernommen |
| Mission leer, Zugang hat noch kein Inventar | Ablehnung: Inventar aktualisieren oder Wert setzen |
| Mission leer, Zugang meldet mehrere (vCenter) | Ablehnung: die Kandidaten werden genannt |

Ändert sich der Cache zwischen Einreihen und Lauf, prüft der Worker erneut und lässt den Job
sichtbar scheitern, statt in ein geratenes Datacenter zu deployen. Bestandsmissionen behalten
ihren gespeicherten Wert; er wird nicht automatisch geleert.

Zwei weitere Eigenheiten:

- **Der Datastore-Override wirkt nur beim Erstellen der VM.** Nur `createVMs-ESXi_playbook.yml` liest `datastore_name`; Power-Cycle, Start und Export lesen ausschließlich `datacenter_name`. Eine bereits erstellte VM zieht durch eine Änderung nicht um.
- **Pro Disk ist ein eigener Datastore derzeit nicht möglich.** Der Top-Level-Parameter `datastore` von `community.vmware.vmware_guest` überstimmt laut Moduldoku `disk[].datastore` und lässt ihn ignorieren; genau diesen Parameter setzt das create-Playbook. Ein Feld dafür wäre wirkungslos. Voraussetzung für eine Umsetzung wäre ein Playbook-Umbau plus Verifikation gegen den produktiven Host.

Die Mission speichert kein Zugangsdatum; der Zielhost wird erst beim Deploy gewählt. Die Auswahlliste ist deshalb eine Vereinigung über alle Zugangsdaten. Gruppiert wird nach dem **Risiko**, nicht nach der Herkunft: Die Frage am Feld lautet nicht „wer hat den Wert gemeldet", sondern „übersteht dieser Wert die Host-Wahl, die ich später treffe". Jeder Name steht in genau einer Gruppe:

- **Auf allen ESXi-Zugängen vorhanden**: der Wert übersteht jede Host-Wahl.
- **Nur auf einigen Zugängen** (mehr als einer, aber nicht alle): die Gruppe nennt die betroffenen Zugänge.
- **Nur auf `<Zugang>`**: die Gruppe nennt Name und Adresse des einen Zugangs. Wer hier wählt, legt die spätere Host-Wahl fest.

Gezählt werden nur Zugangsdaten mit mindestens einem erfolgreichen Abruf (`last_success_at`), dieselbe Beweisregel wie im VLAN-Präsenzbericht. Ein Zugang, der erfolgreich abgerufen hat und tatsächlich keinen Datastore meldet, bleibt im Nenner; sonst würden die Datastores der übrigen Hosts als „überall vorhanden" gelten, während ein ganzer Host fehlt. Gibt es noch keinen einzigen erfolgreichen Abruf, wird nichts als „auf allen" ausgewiesen. Melden alle dasselbe, entsteht genau eine Gruppe und die Liste bleibt flach.

Vorbelegung und das Ausblenden des Feldes „Datacenter" (in Mission und VM-Editor) hängen weiterhin allein an **exakt**: alle Zugangsdaten melden dieselben Namen **und** jedes eingerichtete Zugangsdatum hat mindestens einmal erfolgreich abgerufen. Die Gruppierung entscheidet nur die Darstellung; beide Fragen sind verschieden und dürfen nicht zusammenfallen. Ein nie abgerufenes Zugangsdatum kann nicht belegen, was es hat; dann bleibt das Feld sichtbar, es wird nichts vorbelegt, und der Hinweis am Feld verlinkt auf die Systemstatus-Karte des betroffenen Zugangs. Ein ausgeblendetes Feld sendet seinen Wert weiter über ein verstecktes Eingabefeld, sonst würde Speichern eine bestehende Abweichung löschen.

Der freie Speicher in den Datastore-Optionen folgt dem **schlechtesten meldenden Host**: der kleinste gemeldete Wert, weil der Zielhost noch nicht feststeht. Meldet auch nur einer der Hosts den Datastore als in Wartung oder nicht erreichbar, steht statt einer Zahl der Zusatz „Wartung", und die Bewertung bleibt offen.

Beide Editoren zeigen dieselben Hinweise unter den Feldern, abgeleitet aus dem, was tatsächlich gerendert wird: Ist die Liste flach, wird keine Gruppierung behauptet; ist kein Abruf offen, erscheint gar kein Hinweis.

## Speicherbedarf beim Bereitstellen

Die Deploy-Seite zeigt nach der Missionsauswahl, wie viel Plattenplatz der Auftrag je Ziel-Datastore belegt: die Summe der **provisionierten** Festplattengrößen der angehakten VMs, gruppiert über dieselbe Auflösung, die auch das Playbook bekommt (VM-Override, sonst Missionswert). Eine VM ohne Festplatten-Zeilen zählt mit der Standardplatte, die auch tatsächlich angelegt würde.

Nach Wahl des ESXi-Zugangs wird der Bedarf dem gecachten `free_bytes` dieses Zugangs gegenübergestellt und je Zeile als ausreichend oder voraussichtlich zu klein bewertet. Ist beim Laden der Seite bereits ein Zugang gewählt (etwa nach einem Missionswechsel oder einem Validierungsfehler), rendert der Server diese Zellen sofort mit; JavaScript aktualisiert sie danach. Dieselbe Tabelle steht serverseitig auch in der Zeitplan-Vorschau. Beide Wege sind damit ohne JavaScript verbindlich.

Die Anzeige ist **warnend, nie blockierend** (Cache-blockiert-nie-Regel): fehlt der Zugang, der Datastore oder der Wert, lautet die Bewertung „keine Angabe". Die Zahlen sind so alt wie der letzte Abruf, und Thin-Festplatten belegen anfangs weniger als ihre provisionierte Größe.

**Fehlender Wert heißt überall „unbekannt", nie 0.** Ein `free_bytes` von SQL NULL ist eine Lücke im Cache und keine Zahl. Alle drei Anzeigen (Inventardetail im Systemstatus, Speicherbedarf beim Bereitstellen, Beschriftung der Auswahlliste) lesen denselben Helfer `esxi_datastore_usable_free_bytes()`; vorher zeigte das Inventardetail dieselbe NULL als „0 B frei" neben einer vollen Kapazität, während die Deploy-Tabelle sie korrekt als „keine Angabe" führte.

**Wartungsmodus und Erreichbarkeit.** Das Playbook liefert je Datastore auch `accessible` und `maintenanceMode`; beide landen als Zusatzdaten in `deploy_esxi_inventory.meta_json`, ohne Schemaänderung (dieselbe Art Zusatzdaten wie bei Netzwerken und Hosts). Beide sind dreiwertig: Ein Feld, das der Abruf nicht liefert, gilt als **unbekannt** und nie als „gesund"; umgekehrt zieht ein unbekannter Zustand keine vorhandene Zahl zurück. Ein Datastore in Wartung wird in den Auswahllisten gekennzeichnet und im Systemstatus mit einer Marke versehen; sein freier Speicher gilt als unbekannt statt als verfügbar, sodass die Bewertung offen bleibt statt Platz zu versprechen.

**Jeder Abruf protokolliert seine Health-Felder.** Im Job-Log steht direkt unter der Zeile `Inventory queries:` die Zeile `Datastore health:`. Sie nennt, für wie viele Datastores Erreichbarkeit und Wartungsmodus überhaupt ankamen, und danach namentlich die in Wartung oder nicht erreichbar. Auch der gute Fall wird geschrieben, aus demselben Grund wie bei den Abfragen: Eine Flotte ohne Wartung und ein Feldpfad, den der Abruf nicht mehr trifft, sähen sonst identisch aus.

## Abweichungen und VLAN-Neuzuweisung

Eine Abweichung liegt vor, wenn Datacenter, Datastore oder VLAN nicht im aktuellen Inventar der jeweiligen Kategorie vorkommt. Geprüft werden Missionen (Datacenter, Datastore, WDS-VLAN), VM-Overrides (Datacenter, Datastore) und VM-Netzwerkkarten (VLAN). Eine Kategorie wird nur bewertet, wenn das Inventar mindestens einen Eintrag davon hat; ein leeres oder nie abgerufenes Inventar erzeugt also keine falschen Abweichungen. Ohne ein einziges ESXi-Zugangsdatum läuft der Scan gar nicht: der Systemstatus zeigt dann „Nicht geprüft" statt einer grünen Null, denn ein nicht durchgeführter Vergleich ist kein bestandener. Eine Abweichung an einer Vorlage wird als solche markiert; sie wird erst wirksam, wenn daraus eine Mission entsteht. Sichtbar an drei Stellen, alle rein hinweisend (kein Deploy-Block): Bereich „Abweichungen" im Systemstatus, Badge „Inventar-Abweichung" in der Missionsliste, Hinweis beim Einreihen eines Bereitstellungsauftrags. Der Badge leuchtet auch, wenn nur eine VM der Mission abweicht.

| Symptom | Wahrscheinliche Ursache | Maßnahme |
|---|---|---|
| Wert als „nicht im aktuellen Inventar" markiert | ESXi-Objekt umbenannt/entfernt, oder Cache veraltet | Frische prüfen (Stand-Angabe); Wert auf ESXi verifizieren, dann „Aktualisieren" |
| VLAN fehlt im Auswahlfeld | Portgruppe existiert nicht (mehr) auf ESXi oder wurde retired | Auf ESXi prüfen; nach Anlage „Aktualisieren"; gespeicherter Wert bleibt bis dahin wählbar |
| VLAN auf ESXi umbenannt | Erscheint als „alt retired, neu aktiv" (Rename nicht von Löschen unterscheidbar) | Geführte Massen-Neuzuweisung nutzen (siehe unten) |

**Geführte VLAN-Massen-Neuzuweisung** (Systemstatus, Rechte `missions.write` + `vms.write`): ändert alle Zuweisungen eines VLAN-Namens (in Missionen und VM-Netzwerkkarten) in einem Schritt auf einen aktiven Katalog-Eintrag. Feld „Von" ist Freitext (auch der alte/retired Name), „Nach" ein aktives VLAN. Die Umstellung läuft in einer Transaktion, betrifft nur Portal-Datensätze (nie ESXi) und schreibt einen aggregierten Audit-Eintrag. Das Formular erscheint nur bei einer echten VLAN-Abweichung und ist als „Korrekturaktionen" eingeklappt. Leere, identische, zu lange oder inaktive Ziele werden feldbezogen abgewiesen; ohne Treffer bleibt der eingegebene Wert erhalten. Cancel ändert nichts, Confirm schreibt Missionen und Interfaces gemeinsam.

## Teilpräsenz und VLAN-IDs im Katalog (Mehr-Host-Betrieb)

Der VLAN-Katalog beantwortet je Portgruppe zwei Fragen in getrennten Spalten:

- **„Auf ESXi" (Präsenz):** „auf allen Y Hosts" (grau, kein Handlungsbedarf) oder „auf X von Y Hosts" (gelb) mit den **fehlenden** Hosts. Y zählt nur Zugangsdaten mit mindestens einem erfolgreichen Abruf; ein nie abgerufenes Zugangsdatum kann Ab- oder Anwesenheit nicht beweisen. Ein Deploy auf einen fehlenden Host würde die Portgruppe nicht finden; die Deploy-Seite warnt dafür live bei der Host-Auswahl (nicht blockierend).
- **„VLAN-ID":** vergleicht die IDs gleichnamiger Portgruppen über die Hosts. Einheitlich = graue Info „ID n"; unterschiedlich = rotes Badge mit Aufschlüsselung je ID und Host (gleicher Name, anderes Netz: wahrscheinliche Fehlkonfiguration auf einem der Hosts). Trunk-/Bereichs-Portgruppen werden nicht verglichen und neutral ausgewiesen; Zeilen ohne ID-Daten (Cache von vor dieser Funktion) zeigen einen Platzhalter bis zum nächsten Abruf.

**Verifikation der VLAN-ID-Felder am produktiven Host (einmalig nach Rollout):** manuellen Inventory-Refresh auslösen, im Job-Log „Inventory updated ... network: N items" prüfen und auf der VLAN-Seite kontrollieren, ob IDs erscheinen. Das Playbook liefert die rohen Portgruppen-Objekte der `*_info`-Module; die Feldauswertung liegt im PHP-Parser. Die beiden Modulfamilien legen die ID unterschiedlich ab: Standard-Portgruppen führen `portgroup` und `vlan_id` direkt, Distributed-Portgruppen `portgroup_name` und einen Block `vlan_info` mit `vlan_id` und Trunk-Kennzeichen. Stimmen Feldpfade nicht, bleiben die Listen leer, der Empty-Guard hält den Bestand und nur die ID-Spalte bleibt stumm; kaputtgehen kann nichts.

Distributed-Portgruppen werden für das erste gemeldete Datacenter abgerufen und sind mangels DVS in der Testumgebung nicht gegen echte Daten verifiziert.
