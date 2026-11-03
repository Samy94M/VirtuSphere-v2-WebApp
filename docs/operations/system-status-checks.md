# Systemstatus: Prüfungen

Wie die Seite **Systemstatus** zu jeder Ampel kommt: Datenquelle, Reihenfolge der Regeln und Ergebnis. Was bei einer roten oder gelben Ampel zu tun ist, steht in der Portalhilfe des Systemstatus und in der [Störungsdiagnose](troubleshooting.md); die MECM-Seite der Daten beschreiben die [MECM-Integration](mecm-integration.md) und die [MECM-Serveraufgaben](mecm-scheduled-tasks.md).

Die Diagramme beschreiben den ausgelieferten Code. Wer eine Ampelregel, eine Quelle oder einen Schwellwert ändert, zieht das zugehörige Diagramm im selben Commit nach. Beschlossene, noch nicht gelieferte Änderungen stehen in den Plänen unter `docs/audits/`, nicht hier. Schwellwerte werden hier nur mit ihrem Konstantennamen genannt; der Wert steht im Code.

| Bereich | Quelle | Ampel je Zeile | Kachel in der Übersicht |
|---|---|---|---|
| Bereitstellungsdienst | Laufzeitidentität, Supervisor, Worker-Status, Warteschlange, Prüffälle | Verfügbarkeit plus Aufmerksamkeit | Summe der Karte |
| MECM | Laufberichte der drei Sync-Aufgaben und des Site-Health-Reporters | je Aufgabe | schlechteste Zeile |
| Ansible-Test | Volltest je Ansible-Zugang, manuell oder geplant | je Zugang | schlechteste Zeile |
| ESXi-Inventar | Inventarabruf je ESXi-Zugang | je Zugang | schlechteste Zeile |
| Abweichungen | Missionen und VMs gegen den Inventar-Cache | Anzahl Befunde | Anzahl |
| Interne Dienste | Wartungsdienst und Deploy-Worker | je Dienst | schlechteste Zeile |
| Active Directory | LDAPS-Konfiguration und Domänencontroller | je Controller | Gesamtzustand |

Über der Übersicht stehen vier Hinweise, die keine Ampel haben und nur erscheinen, wenn sie zutreffen: Der Aufruf läuft über HTTP; die Backups sind nicht verschlüsselt (`backup_encryption_finding()`, kein Zertifikat hinterlegt oder der letzte erfolgreiche Lauf nicht an das hinterlegte verschlüsselt); Einträge der Machine-API-Freigabeliste haben keine Rolle und gelten deshalb für alle Maschinen-Endpunkte (`repo_api_access_without_role_count()`); offene ESXi-Zugangsdateien auf einem Ansible-Host (`repo_remote_secret_notice_groups()`). Der letzte Hinweis hat eine Gruppe je Zugang und Ziel. Sie erscheint, wenn eine ihrer Zeilen älter als 24 Stunden ist, sofort bei einem gelöschten Zugang oder einem Ziel, das nicht mehr zum Zugang passt. Laufende Leases und aktive Aufträge zählen nicht. Die Gruppe nennt die vollständige Anzahl, die älteste Zeile, Host und Grund. Benutzer mit `credentials.manage` bestätigen dort eine Gruppe mit Begründung; das ist dieselbe Bestätigung wie unter dem Zugang auf der Seite Zugangsdaten. Für `system.config` verlinkt jeder der ersten drei Hinweise die Einstellung, die ihn behebt.

Die Bereiche heißen hier wie die Kacheln der Übersicht auf der Seite; `SystemStatusChecksDocContractTest` leitet die Namen aus den Kachelbeschriftungen ab.

Die Rangfolge für „schlechteste Zeile“ ist für alle Bereiche dieselbe (`virtusphere_heartbeat_state_rank()`): rot vor „Erwartet, nie gemeldet“ vor gelb vor „Legacy“ vor grau vor grün.

## Bereitstellungsdienst

```mermaid
flowchart TD
  A{"Supervisor-Vertrag bekannt und Prozessform passend?"} -->|nein| DG["Beeinträchtigt"]
  A -->|ja| B{"Dienstprozess lebt? (Supervisor-Heartbeat frisch, sonst Worker-Status ok)"}
  B -->|nein| OF["Nicht erreichbar"]
  B -->|ja| SV{"Läuft der Dienst unter Supervisor?"}
  SV -->|nein, Einzelprozess| E
  SV -->|ja| C{"Supervisor in der Neustart-Abkühlphase?"}
  C -->|ja| CO["Wartet vor Neustart"]
  C -->|nein| D{"Worker-Kindprozess lebt?"}
  D -->|nein| DG
  D -->|ja| E{"Aktiver Auftrag widersprüchlich?"}
  E -->|ja| DG
  E -->|nein| F{"Kein aktiver Auftrag, Annahme offen, fälliger Auftrag länger als die Schonfrist nicht beansprucht?"}
  F -->|ja| DG
  F -->|nein| G{"Aktiver Auftrag?"}
  G -->|ja| BU["Arbeitet"]
  G -->|nein| RE["Bereit"]
  OF --> X
  CO --> X
  DG --> X
  BU --> X
  RE --> X
  X{"Prüffälle?"} -->|manuelle Prüfung nötig oder ungeklärter Altfall| XR["Badge rot"]
  X -->|Wiederherstellung läuft| XY["Badge gelb, sofern nicht schon rot"]
  X -->|keine| XN["Badge aus Verfügbarkeit: Nicht erreichbar rot, Beeinträchtigt und Wartet vor Neustart gelb, Arbeitet blau, Bereit grün"]
```

Die Schonfrist für einen nicht beanspruchten Auftrag ist `VIRTUSPHERE_DEPLOY_CLAIM_GRACE_SECONDS`. Ist die Annahme pausiert, sagt die Karte das ausdrücklich, statt einen Rückstand zu melden.

## MECM

Die drei Sync-Aufgaben (Devices Sync, Packages Sync, Package Import) und der Site-Health-Reporter melden jeden Lauf an `mecm_report.php?action=reportRun`. Sync und Site werden getrennt bewertet: Ein kritischer Site-Zustand beweist keinen ausgefallenen Datenfluss, und ein ausgefallener Sync behauptet nicht, MECM selbst sei kritisch.

```mermaid
flowchart TD
  A{"Hat die Sync-Aufgabe je gemeldet?"} -->|nein| A1{"Meldet eine andere Sync-Aufgabe?"}
  A1 -->|ja| MI["Erwartet, nie gemeldet (missing, gelb)"]
  A1 -->|nein| UK["Noch keine Daten (unknown, grau)"]
  A -->|ja| L{"Letztes Ereignis?"}
  L -->|completed| CT{"Ergebniszeit vorhanden, lesbar und höchstens VIRTUSPHERE_STATUS_EVIDENCE_FUTURE_SKEW_SECONDS in der Zukunft?"}
  CT -->|nein| UK
  CT -->|ja| CP{"Ergebnis fail?"}
  CP -->|ja| RD["Ausgefallen (danger, rot)"]
  CP -->|nein| CA{"Alter des letzten Ergebnisses?"}
  CA -->|über Gefahrschwelle| RD
  CA -->|über Warnschwelle| YL["Verzögert (gelb)"]
  CA -->|frisch| CO{"Ergebnis?"}
  CO -->|ok| GR["OK (grün)"]
  CO -->|warning| YL
  CO -->|sonst| UK
  L -->|started| SZ{"Startzeit vorhanden, lesbar und höchstens VIRTUSPHERE_STATUS_EVIDENCE_FUTURE_SKEW_SECONDS in der Zukunft?"}
  SZ -->|nein| UK
  SZ -->|ja| ST{"Lauf länger offen als die Laufschonfrist?"}
  ST -->|nein| SR["Badge „Lauf offen“ in der Farbe des vorigen Ergebnisses; ohne lesbares Ergebnis Noch keine Daten"]
  ST -->|ja| SD{"Auch über der Gefahrschwelle?"}
  SD -->|ja| RD
  SD -->|nein| YL
  L -->|nur alter Heartbeat| LG{"Heartbeat frisch?"}
  LG -->|ja| LE["Legacy (legacy, gelb)"]
  LG -->|über Warnschwelle| YL
  LG -->|über Gefahrschwelle oder Status fail| RD
```

Der Site-Health-Reporter wird eigens bewertet: Ein überfälliger Bericht färbt den Reporter gelb. Der letzte Site-Befund bleibt getrennt sichtbar, grau als „Veraltet“ und historisch markiert. Sein Alter behauptet keinen aktuellen kritischen MECM-Zustand. Dashboard und Systemstatus lesen beide Achsen aus demselben Snapshot.

```mermaid
flowchart TD
  A{"Hat der Site-Health-Reporter je gemeldet, mit lesbarer und nicht künftiger Ergebniszeit?"} -->|nein| UK["Unbekannt (grau)"]
  A -->|ja| S{"Letztes Ergebnis älter als die Warnschwelle?"}
  S -->|ja| SS["Reporter überfällig (warning, gelb); letzter Site-Befund Veraltet (stale, grau), historisch"]
  S -->|nein| SC{"MECM-Site-Status?"}
  SC -->|kritisch| RD["Ausgefallen (rot)"]
  SC -->|Warnung| YL["Verzögert (gelb)"]
  SC -->|ok| GR["OK (grün)"]
  SC -->|Provider nicht lesbar oder Abfrage gescheitert| UK
```

Schwellen: Die Warnschwelle liegt bei `VIRTUSPHERE_HEARTBEAT_WARN_MULTIPLIER` Intervallen, mindestens `VIRTUSPHERE_HEARTBEAT_WARN_FLOOR_SECONDS`; die Gefahrschwelle bei `VIRTUSPHERE_HEARTBEAT_DANGER_MULTIPLIER` Intervallen, mindestens `VIRTUSPHERE_HEARTBEAT_DANGER_FLOOR_SECONDS`; die Laufschonfrist eines offenen Laufs ist der größte Wert aus Warnschwelle und `VIRTUSPHERE_RUN_GRACE_SECONDS`. Erst nach ihr wird ein offener Lauf bewertet, und die Gefahrschwelle entscheidet zwischen Verzögert und Ausgefallen. Liegt die Gefahrschwelle unter der Laufschonfrist, wie bei kurzen Takten, springt ein hängender Lauf nach der Schonfrist sofort auf Rot. Über den Zeilen stehen zwei Hinweise, die keine Ampel färben: abgewiesene Maschinenzugriffe des letzten Tages mit IP, und frische Meldungen von mehr als einer IP-Adresse.

## Ansible-Test

Nachweis ist der Volltest je Ansible-Zugang. Er läuft über **Volltest jetzt starten**, über **Verbindung und Umgebung prüfen** (Zugangsdaten) oder geplant als Kindprozess des Deploy-Workers. Der letzte tatsächlich bearbeitete Missionsauftrag steht daneben, färbt die Ampel aber nicht.

Ein bestandener Volltest, manuell oder geplant, räumt danach liegengebliebene ESXi-Zugangsdateien dieses Ziels auf, soweit der Zugang noch darauf zeigt. Das Zeitbudget beträgt 60 Sekunden; bricht das Aufräumen ab, bleibt der Rest für den nächsten Kontakt, und die Meldung sagt es. Das Ergebnis des Tests ändert der Aufräumlauf nicht. Fehlt der Aufräum-Timer auf dem Ansible-Host, nennt die Meldung ihn als Hinweis, ohne die Ampel zu verändern.

Die getrennte Hostidentitätsanzeige zeigt Pin, Bestätigung und zuletzt beobachtete Abweichung. Sie ist kein Volltest und färbt dessen vorhandenen Nachweis nicht um. Jede SSH-/SFTP-Anmeldung geht über den [gemeinsamen Vertrauensablauf](trust-flows.md#ssh-und-sftp-zum-ubuntu-host); das gilt auch für die SFTP-Probe nach bestandenem SSH-Preflight.

```mermaid
flowchart TD
  T["Volltest"] --> T1{"requirements.yml lokal lesbar?"}
  T1 -->|nein| TF["fehlgeschlagen (config), ohne SSH"]
  T1 -->|ja| TK{"Hostidentitäts-Guard gibt die Verbindung frei?"}
  TK -->|nein| TFK["fehlgeschlagen (ansible_host_identity), kein Login"]
  TK -->|ja| T2{"SSH-Anmeldung?"}
  T2 -->|nein| TF2["fehlgeschlagen (Anmeldung oder Zeitbudget)"]
  T2 -->|ja| T3["Werkzeugkette prüfen: ansible-playbook, python3, pyvmomi, requests, vmware_host_auto_start, Laufzeitversionen, Async-Arbeitsbereich; mit API-Basis-URL auch Portal-Erreichbarkeit. Zeitbudget 45 s ohne Ausgabe, dasselbe wie im Auftrag"]
  T3 -->|Komponente fehlt| TF3["fehlgeschlagen mit Name der Komponente"]
  T3 --> T4{"SFTP-Schreibprobe in /tmp?"}
  T4 -->|nein| TF4["fehlgeschlagen (SFTP)"]
  T4 -->|ja| T5{"IP-Freigabe für den MAC-Rückruf (Eintrag ohne Rolle oder mit Rolle Ansible-Host)?"}
  T5 -->|abgewiesen| TW["bestanden mit Einschränkung (warning)"]
  T5 -->|frei oder ohne URL| TO["bestanden"]
  TF --> R
  TFK --> R
  TF2 --> R
  TF3 --> R
  TF4 --> R
  TW --> SW
  TO --> SW
  SW["Nur nach bestandenem Test: liegengebliebene Zugangsdateien dieses Ziels entfernen (Budget 60 s, Abbruch lässt den Rest offen; das Ergebnis bleibt)"] --> R
  R{"Zugang während des Tests geändert?"} -->|ja| RX["Ergebnis verworfen (discarded), Audit warning; bisheriger Nachweis bleibt unverändert"]
  R -->|nein| RS["Ergebnis mit Konfigurationsstand speichern"] --> A{"Ergebnis gehört zum aktuellen Stand des Zugangs?"}
  A -->|nein, Zugang seither geändert oder nie getestet| U["Nicht getestet (unknown, grau)"]
  A -->|ja| B{"Ergebnis?"}
  B -->|fehlgeschlagen| D["Fehlgeschlagen (danger, rot), altert nie"]
  B -->|bestanden oder eingeschränkt| C0{"Prüfzeit vorhanden, lesbar und nicht künftig?"}
  C0 -->|nein| U
  C0 -->|ja| C{"Älter als max(VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS, doppeltes Volltestintervall)?"}
  C -->|ja| S["Test veraltet (stale, grau)"]
  C -->|nein| O["bestanden (ok, grün); eingeschränkt (warning, gelb)"]
```

Die Deploy-Seite übernimmt die Ansible-Volltest-Ampel und den Prüfzeitpunkt aus `integration_health_snapshot()`. Der gewählte Zugang zeigt dieselbe Evidenz wie seine Systemstatus-Karte; ohne JavaScript bleiben die beschrifteten Nachweise aller Zugänge sichtbar. Der Hinweis sperrt keinen Auftrag. Seine Karte bietet berechtigten Benutzern **Volltest jetzt starten**.

## ESXi-Inventar

Nachweis ist der Inventarabruf je ESXi-Zugang, ein Systemauftrag des Deploy-Workers. Er läuft im eingestellten Intervall, manuell über **Alle aktualisieren** oder den Einzelabruf, beim Speichern und Testen eines ESXi-Zugangs und nach jedem Endzustand eines `create`- oder `full`-Auftrags, sobald eine Create-Einheit ihre Ansible-Job-ID dauerhaft gespeichert hat. Der Abruf ist fail-soft und wird durch den vorhandenen Systemauftrag-Writer dedupliziert.

```mermaid
flowchart TD
  A{"Je ein Abruf versucht?"} -->|nein| U["Noch kein Abruf (unknown, grau)"]
  A -->|ja| B{"Anmeldung pausiert oder Fehlerserie ab VIRTUSPHERE_ESXI_INVENTORY_FAILURE_STREAK_DANGER?"}
  B -->|ja| D["Fehler (danger, rot)"]
  B -->|nein| C{"Je erfolgreich?"}
  C -->|nein| W["Prüfen (warning, gelb)"]
  C -->|ja| F{"Letzter Abruf fehlgeschlagen?"}
  F -->|ja| W
  F -->|nein| T{"Erfolgszeit gültig, nicht zu weit in der Zukunft?"}
  T -->|nein| U
  T -->|ja| E{"Älter als VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR Intervalle, bei Intervall 0 älter als VIRTUSPHERE_ESXI_INVENTORY_STALE_AFTER_DAYS?"}
  E -->|ja| S["Veraltet (stale, grau)"]
  E -->|nein| O["OK (ok, grün)"]
```

Host-Eigenschaften aus einem erfolgreichen Abruf färben die Ampel nicht, sondern erscheinen als eigene Badges: freie Lizenz, HA-Cluster, Wartungsmodus. Über den Karten nennt eine Zeile, warum kein automatischer Abruf läuft: Intervall 0, kein Ansible-Zugang für den Abruf, Deploy-Worker nicht aktiv oder Anmeldung pausiert.

## Abweichungen

```mermaid
flowchart TD
  A{"ESXi-Zugang konfiguriert?"} -->|nein| N["Keine Zahl: keine ESXi-Quelle, Link zu den Zugangsdaten"]
  A -->|ja| K{"Je Objektart (Datacenter, Datastore, Portgruppe): von jedem Zugang mit exakter Namenssemantik und lesbarem Zeitpunkt beantwortet, auch leer?"}
  K -->|nein| C1["Art nicht auswertbar: weder Befund noch Freigabe"]
  K -->|ja| K2{"Letzte Abfrage jedes Zugangs beantwortet und jünger als VIRTUSPHERE_ESXI_INVENTORY_STALE_FACTOR Intervalle?"}
  K2 -->|ja| KC["Aktueller Nachweis"]
  K2 -->|nein| KH["Historischer Nachweis: Treffer sind Diagnose, kein aktueller Negativnachweis"]
  KC --> B["Aktuelle Deploy-Warnungen und Missions-Badges nur mit diesem Nachweis berechnen"]
  KH --> H["Historische Treffer bleiben Diagnose im Systemstatus; keine aktuelle Deploy-Warnung und kein Missions-Badge"]
  B --> B2["Für Missionen, Vorlagen und VMs die gespeicherten Namen dieser Art sammeln: Datacenter, Datastore, WDS-Portgruppe, VLAN jeder Netzwerkkarte, VM-eigene Ortsangaben"]
  B2 --> D{"Name exakt unter den unterstützten Namen aller Zugänge?"}
  D -->|ja| D1["kein Befund"]
  D -->|nein| D2["Befund mit Feld und Wert"]
  D2 --> E["Zählen, filtern, seitenweise anzeigen; VLAN-Befunde lassen sich gesammelt auf eine vorhandene Portgruppe umhängen"]
```

Verglichen wird exakt, auch in der Groß- und Kleinschreibung. Ist keine Art auswertbar, zeigt der Bereich keine Zahl, sondern einen Hinweis mit Link zum ESXi-Inventar. Mit Inventarintervall 0 entfällt die Altersgrenze. Deploy-Missionswarnung und Missionslisten-Badge verwenden denselben Bericht und zeigen nur aktuelle Treffer für Arten mit Namenssemantik v2. Die Host-Warnung vergleicht dieselbe Art für den gewählten Host und mindestens einen anderen Host; beide Nachweise müssen aktuell sein. Historische Treffer bleiben Diagnose im Systemstatus und erscheinen nicht als aktuelle Warnung. Die Host-Warnung nennt nur Werte, die auf einem anderen aktuellen Host vorhanden sind; ein Wert, der auf keinem aktuellen Host vorkommt, bleibt ausschließlich in der Missionswarnung.

## Interne Dienste

Wartungsdienst und Deploy-Worker schreiben ihren Status direkt in dieselbe Tabelle wie die MECM-Aufgaben, im Takt `VIRTUSPHERE_MAINTENANCE_HEARTBEAT_INTERVAL_SECONDS` beziehungsweise `VIRTUSPHERE_DEPLOY_WORKER_HEARTBEAT_INTERVAL_SECONDS`.

```mermaid
flowchart TD
  A{"Hat der Dienst je gemeldet?"} -->|nein| A1{"Meldet der andere interne Dienst?"}
  A1 -->|ja| MI["Erwartet, nie gemeldet (gelb)"]
  A1 -->|nein| UK["Noch keine Daten (grau)"]
  A -->|ja| B{"Letzte Meldung fail?"}
  B -->|ja| RD["Ausgefallen (rot)"]
  B -->|nein| C{"Alter der letzten Meldung?"}
  C -->|über Intervall mal Gefahrfaktor| RD
  C -->|über Intervall mal Warnfaktor| YL["Verzögert (gelb)"]
  C -->|frisch| GR["OK (grün)"]
```

## Active Directory

Erscheint nur, wenn eine LDAPS-Konfiguration gespeichert ist, und nur für Benutzer mit dem Recht **Benutzerkonten verwalten** (`users.manage`): Die Controller-Namen sind interne Infrastruktur.

Nutzbar ist ein Controller, der aktiv ist und für den aktuellen Konfigurationsstand getestet wurde. Jede echte Anmeldung, jede Sitzungsprüfung und jeder Test schreibt eine Beobachtung; automatische Fehlschläge nehmen die Zulassung nicht zurück, nur ein fehlgeschlagener manueller Test tut das.

```mermaid
flowchart TD
  subgraph JE["Je Domänencontroller"]
    A{"Aktiv und für den aktuellen Konfigurationsstand getestet?"} -->|nein| U["Nicht getestet (grau)"]
    A -->|ja| B{"Letzte Beobachtung ok?"}
    B -->|nein| D["Gestört (rot)"]
    B -->|ja| C{"Zertifikat abgelaufen?"}
    C -->|ja| D
    C -->|nein| E{"Zertifikat läuft innerhalb von VIRTUSPHERE_DIRECTORY_CERTIFICATE_EXPIRY_WARNING_DAYS ab?"}
    E -->|ja| W["Zertifikat läuft bald ab (gelb)"]
    E -->|nein| F{"Letzter Erfolg fehlt oder älter als VIRTUSPHERE_DIRECTORY_OBSERVATION_STALE_AFTER_DAYS?"}
    F -->|ja| S["Veraltet (grau)"]
    F -->|nein| O["Aktiv (grün)"]
  end
  G{"LDAPS eingeschaltet?"} -->|nein| G0["Gesamt: Deaktiviert (grau)"]
  G -->|ja| H{"Automatische Anmeldung für diesen Stand gesperrt, weil das Suchkonto abgewiesen wurde?"}
  H -->|ja| G1["Gesamt: Gestört (rot)"]
  H -->|nein| I{"Nutzbare Controller?"}
  I -->|keiner| G1
  I -->|ja| J{"Zustand der nutzbaren Controller?"}
  J -->|alle Gestört| G1
  J -->|alle Aktiv und kein aktivierter Controller ungetestet| G2["Gesamt: Aktiv (grün)"]
  J -->|sonst, auch alle veraltet oder aktivierte Controller für aktuelle Revision ungetestet| G3["Gesamt: Eingeschränkt (gelb)"]
```

Das Zertifikat kennt die Seite nur aus dem letzten manuellen Test. Ein unlesbares Ablaufdatum überspringt die beiden Zertifikatsfragen. Aktivierte Controller ohne Test für die aktuelle Revision fehlen im zugelassenen Pool und verhindern eine grüne Gesamtampel. Die Speichermeldung nennt ihre Anzahl.

