# Systemstatus: Prüfungen

Wie die Seite **Systemstatus** zu jeder Ampel kommt: Datenquelle, Reihenfolge der Regeln und Ergebnis. Was bei einer roten oder gelben Ampel zu tun ist, steht in der Portalhilfe des Systemstatus und in der [Störungsdiagnose](troubleshooting.md); die MECM-Seite der Daten beschreibt die [MECM-Integration](mecm-integration.md).

Die Diagramme beschreiben den ausgelieferten Code. Wer eine Ampelregel, eine Quelle oder einen Schwellwert ändert, zieht das zugehörige Diagramm im selben Commit nach. Beschlossene, noch nicht gelieferte Änderungen stehen in den Plänen unter `docs/audits/`, nicht hier. Schwellwerte werden hier nur mit ihrem Konstantennamen genannt; der Wert steht im Code.

| Bereich | Quelle | Ampel je Zeile | Kachel in der Übersicht |
|---|---|---|---|
| Deploy-Dienst | Laufzeitidentität, Supervisor, Worker-Status, Warteschlange, Prüffälle | Verfügbarkeit plus Aufmerksamkeit | Summe der Karte |
| MECM | Laufberichte der drei Sync-Aufgaben und des Site-Health-Reporters | je Aufgabe | schlechteste Zeile |
| Ansible | Volltest je Ansible-Zugang, manuell oder geplant | je Zugang | schlechteste Zeile |
| ESXi | Inventarabruf je ESXi-Zugang | je Zugang | schlechteste Zeile |
| Abweichungen | Missionen und VMs gegen den Inventar-Cache | Anzahl Befunde | Anzahl |
| Intern | Wartungsdienst und Deploy-Worker | je Dienst | schlechteste Zeile |
| Verzeichnis | LDAPS-Konfiguration und Domänencontroller | je Controller | Gesamtzustand |

Die Rangfolge für „schlechteste Zeile“ ist für alle Bereiche dieselbe (`virtusphere_heartbeat_state_rank()`): rot vor „fehlt“ vor gelb vor „alte Skriptversion“ vor grau vor grün.

## Deploy-Dienst

```mermaid
flowchart TD
  A{"Supervisor-Vertrag bekannt und Prozessform passend?"} -->|nein| DG["Eingeschränkt"]
  A -->|ja| B{"Dienstprozess lebt? (Supervisor-Heartbeat frisch, sonst Worker-Status ok)"}
  B -->|nein| OF["Offline"]
  B -->|ja| C{"Supervisor in der Neustart-Abkühlphase?"}
  C -->|ja| CO["Abkühlphase"]
  C -->|nein| D{"Unter Supervisor: Worker-Kindprozess lebt?"}
  D -->|nein| DG
  D -->|ja| E{"Aktiver Auftrag widersprüchlich?"}
  E -->|ja| DG
  E -->|nein| F{"Kein aktiver Auftrag, Annahme offen, fälliger Auftrag länger als die Schonfrist nicht beansprucht?"}
  F -->|ja| DG
  F -->|nein| G{"Aktiver Auftrag?"}
  G -->|ja| BU["Beschäftigt"]
  G -->|nein| RE["Bereit"]
  OF --> X
  CO --> X
  DG --> X
  BU --> X
  RE --> X
  X{"Prüffälle?"} -->|manuelle Prüfung nötig oder ungeklärter Altfall| XR["Badge rot"]
  X -->|Wiederherstellung läuft| XY["Badge gelb, sofern nicht schon rot"]
  X -->|keine| XN["Badge aus Verfügbarkeit: Offline rot, Eingeschränkt und Abkühlphase gelb, Beschäftigt blau, Bereit grün"]
```

Die Schonfrist für einen nicht beanspruchten Auftrag ist `VIRTUSPHERE_DEPLOY_CLAIM_GRACE_SECONDS`. Ist die Annahme pausiert, sagt die Karte das ausdrücklich, statt einen Rückstand zu melden.

## MECM

Die drei Sync-Aufgaben (Devices Sync, Packages Sync, Package Import) und der Site-Health-Reporter melden jeden Lauf an `mecm_report.php?action=reportRun`. Sync und Site werden getrennt bewertet: Ein kritischer Site-Zustand beweist keinen ausgefallenen Datenfluss, und ein ausgefallener Sync behauptet nicht, MECM selbst sei kritisch.

```mermaid
flowchart TD
  A{"Hat die Sync-Aufgabe je gemeldet?"} -->|nein| A1{"Meldet eine andere Sync-Aufgabe?"}
  A1 -->|ja| MI["Fehlt (gelb)"]
  A1 -->|nein| UK["Unbekannt (grau)"]
  A -->|ja| L{"Letztes Ereignis?"}
  L -->|completed| CP{"Ergebnis fail?"}
  CP -->|ja| RD["Ausgefallen (rot)"]
  CP -->|nein| CA{"Alter des letzten Ergebnisses?"}
  CA -->|über Gefahrschwelle| RD
  CA -->|über Warnschwelle| YL["Verzögert (gelb)"]
  CA -->|frisch| CO{"Ergebnis?"}
  CO -->|ok| GR["OK (grün)"]
  CO -->|warning| YL
  CO -->|sonst| UK
  L -->|started| ST{"Wie lange ist der Lauf offen?"}
  ST -->|über Gefahrschwelle| RD
  ST -->|über Warnschwelle, mindestens Laufschonfrist| YL
  ST -->|kürzer| SR["Badge „Lauf offen“ in der Farbe des vorigen Ergebnisses"]
  L -->|nur alter Heartbeat| LG{"Heartbeat frisch?"}
  LG -->|ja| LE["Alte Skriptversion (gelb)"]
  LG -->|nein| YL
```

Der Site-Health-Reporter wird eigens bewertet: Sein Alter färbt die Zeile nie gelb oder rot, weil ein fehlender Nachweis kein kritischer MECM-Zustand ist.

```mermaid
flowchart TD
  A{"Hat der Site-Health-Reporter je gemeldet?"} -->|nein| UK["Unbekannt (grau)"]
  A -->|ja| S{"Letztes Ergebnis älter als die Warnschwelle?"}
  S -->|ja| SS["Veraltet: grau, Beschriftung „Unbekannt“, Hinweis „Befund ist historisch“"]
  S -->|nein| SC{"MECM-Site-Status?"}
  SC -->|kritisch| RD["Ausgefallen (rot)"]
  SC -->|Warnung| YL["Verzögert (gelb)"]
  SC -->|ok| GR["OK (grün)"]
  SC -->|Provider nicht lesbar oder Abfrage gescheitert| UK
```

Schwellen: Die Warnschwelle liegt bei `VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER` (aktuell 3) Intervallen, mindestens `VIRTUSPHERE_HEARTBEAT_WARN_FLOOR_SECONDS`; die Gefahrschwelle bei `VIRTUSPHERE_HEARTBEAT_DANGER_MULTIPLIER` (aktuell 10) Intervallen, mindestens `VIRTUSPHERE_HEARTBEAT_DANGER_FLOOR_SECONDS`; ein offener Lauf gilt frühestens nach `VIRTUSPHERE_RUN_GRACE_SECONDS` als verzögert. Über den Zeilen stehen zwei Hinweise, die keine Ampel färben: abgewiesene Maschinenzugriffe des letzten Tages mit IP, und frische Meldungen von mehr als einer IP-Adresse.

## Ansible

Nachweis ist der Volltest je Ansible-Zugang. Er läuft über **Jetzt vollständig testen**, über **Zugangsdaten, Testen** oder geplant als Kindprozess des Deploy-Workers. Der letzte tatsächlich bearbeitete Missionsauftrag steht daneben, färbt die Ampel aber nicht.

```mermaid
flowchart TD
  T["Volltest"] --> T1{"requirements.yml lokal lesbar?"}
  T1 -->|nein| TF["fehlgeschlagen (config), ohne SSH"]
  T1 -->|ja| T2{"SSH-Anmeldung?"}
  T2 -->|nein| TF2["fehlgeschlagen (Anmeldung oder Zeitbudget)"]
  T2 -->|ja| T3["Werkzeugkette prüfen: ansible-playbook, python3, pyvmomi, requests, vmware_host_auto_start, Laufzeitversionen, Async-Arbeitsbereich; mit API-Basis-URL auch Portal-Erreichbarkeit"]
  T3 -->|Komponente fehlt| TF3["fehlgeschlagen mit Name der Komponente"]
  T3 --> T4{"SFTP-Schreibprobe in /tmp?"}
  T4 -->|nein| TF4["fehlgeschlagen (SFTP)"]
  T4 -->|ja| T5{"IP-Freigabe für den MAC-Rückruf?"}
  T5 -->|abgewiesen| TW["bestanden mit Einschränkung"]
  T5 -->|frei oder ohne URL| TO["bestanden"]
  TF --> R
  TF2 --> R
  TF3 --> R
  TF4 --> R
  TW --> R
  TO --> R
  R["Ergebnis mit Konfigurationsstand speichern"] --> A{"Ergebnis gehört zum aktuellen Stand des Zugangs?"}
  A -->|nein, Zugang seither geändert oder nie getestet| U["Nicht getestet (grau)"]
  A -->|ja| B{"Ergebnis?"}
  B -->|fehlgeschlagen| D["Fehlgeschlagen (rot), altert nie"]
  B -->|bestanden oder eingeschränkt| C{"Älter als VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS?"}
  C -->|ja| S["Test veraltet (grau)"]
  C -->|nein| O["bestanden: grün; eingeschränkt: gelb"]
```

## ESXi

Nachweis ist der Inventarabruf je ESXi-Zugang, ein Systemauftrag des Deploy-Workers. Er läuft im eingestellten Intervall, manuell über **Alle aktualisieren** und nach jedem `create`- oder `full`-Auftrag.

```mermaid
flowchart TD
  A{"Je ein Abruf versucht?"} -->|nein| U["Unbekannt (grau)"]
  A -->|ja| B{"Anmeldung pausiert oder Fehlerserie ab VIRTUSPHERE_ESXI_INVENTORY_FAILURE_STREAK_DANGER?"}
  B -->|ja| D["Rot"]
  B -->|nein| C{"Je erfolgreich?"}
  C -->|nein| W["Gelb"]
  C -->|ja| E{"Intervall größer 0 und letzter Erfolg älter als VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR Intervalle?"}
  E -->|ja| W
  E -->|nein| F{"Letzter Abruf fehlgeschlagen?"}
  F -->|ja| W
  F -->|nein| O["Grün"]
```

Host-Eigenschaften aus einem erfolgreichen Abruf färben die Ampel nicht, sondern erscheinen als eigene Badges: freie Lizenz, HA-Cluster, Wartungsmodus. Über den Karten nennt eine Zeile, warum kein automatischer Abruf läuft: Intervall 0, kein Ansible-Zugang für den Abruf, Deploy-Worker nicht aktiv oder Anmeldung pausiert.

## Abweichungen

```mermaid
flowchart TD
  A{"Inventar-Cache vorhanden?"} -->|nein| N["Keine Zahl: ohne Inventar ist nichts beweisbar"]
  A -->|ja| B["Für Missionen, Vorlagen und VMs die gespeicherten Namen sammeln: Datacenter, Datastore, WDS-Portgruppe, VLAN jeder Netzwerkkarte, VM-eigene Ortsangaben"]
  B --> C{"Enthält das Inventar überhaupt Einträge dieser Art?"}
  C -->|nein| C1["Art überspringen"]
  C -->|ja| D{"Name exakt in der Vereinigung aller Zugänge enthalten?"}
  D -->|ja| D1["kein Befund"]
  D -->|nein| D2["Befund mit Feld und Wert"]
  D2 --> E["Zählen, filtern, seitenweise anzeigen; VLAN-Befunde lassen sich gesammelt auf eine vorhandene Portgruppe umhängen"]
```

Verglichen wird exakt, auch in der Groß- und Kleinschreibung. Die Deploy-Seite zeigt dieselben Befunde je Zugang zusätzlich als Warnung.

## Intern

Wartungsdienst und Deploy-Worker schreiben ihren Status direkt in dieselbe Tabelle wie die MECM-Aufgaben, im Takt `VIRTUSPHERE_MAINTENANCE_HEARTBEAT_INTERVAL_SECONDS` beziehungsweise `VIRTUSPHERE_DEPLOY_WORKER_HEARTBEAT_INTERVAL_SECONDS`.

```mermaid
flowchart TD
  A{"Hat der Dienst je gemeldet?"} -->|nein| A1{"Meldet der andere interne Dienst?"}
  A1 -->|ja| MI["Fehlt (gelb)"]
  A1 -->|nein| UK["Unbekannt (grau)"]
  A -->|ja| B{"Letzte Meldung fail?"}
  B -->|ja| RD["Ausgefallen (rot)"]
  B -->|nein| C{"Alter der letzten Meldung?"}
  C -->|über Intervall mal Gefahrfaktor| RD
  C -->|über Intervall mal Warnfaktor| YL["Verzögert (gelb)"]
  C -->|frisch| GR["OK (grün)"]
```

## Verzeichnis

Erscheint nur, wenn die LDAPS-Anmeldung eingerichtet ist.

```mermaid
flowchart TD
  subgraph JE["Je Domänencontroller"]
    A{"Aktiv und für den aktuellen Konfigurationsstand getestet?"} -->|nein| U["Deaktiviert (grau)"]
    A -->|ja| B{"Letzter Test ok?"}
    B -->|nein| D["Rot"]
    B -->|ja| C{"Zertifikat abgelaufen?"}
    C -->|ja| D
    C -->|nein| E{"Zertifikat läuft innerhalb von VIRTUSPHERE_DIRECTORY_CERTIFICATE_EXPIRY_WARNING_DAYS ab?"}
    E -->|ja| W["Gelb"]
    E -->|nein| F{"Letzter Erfolg fehlt oder älter als VIRTUSPHERE_DIRECTORY_OBSERVATION_STALE_AFTER_DAYS?"}
    F -->|ja| S["Veraltet (grau)"]
    F -->|nein| O["Grün"]
  end
  G{"LDAPS eingeschaltet?"} -->|nein| G0["Gesamt: Deaktiviert"]
  G -->|ja| H{"Automatische Anmeldung für diesen Stand gesperrt?"}
  H -->|ja| G1["Gesamt: Rot"]
  H -->|nein| I{"Nutzbare Controller?"}
  I -->|keiner, oder alle rot| G1
  I -->|alle grün| G2["Gesamt: Grün"]
  I -->|gemischt| G3["Gesamt: Gelb"]
```
