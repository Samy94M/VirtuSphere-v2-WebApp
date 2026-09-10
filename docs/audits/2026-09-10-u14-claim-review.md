# U14 Claim-Review: SC-020 und SC-023

Stand: 10.09.2026. Gegenstand ist der aktuelle gemeinsame Arbeitsbaum.
Ausgangspunkt war die AP11-Behauptungsmatrix vom 08.09.2026 mit 29
Einzelclaims. Alte Lauf- und Labornachweise werden nur als historische
Evidenz **R** verwendet. Eine Aussage ist als **K** eingeordnet, wenn sie am
aktuellen Codepfad nachvollzogen wurde.

Diese Runde hat auf ausdrücklichen Auftrag keine Lint-, Test-, Build-, Guard-,
QA-, Performance-, Browser-, Container- oder Real-System-Abnahme ausgeführt.

## Ergebnis

SC-020 ist als Textkorrektur umgesetzt. Best-effort-Phasentelemetrie wird nicht
mehr als vollständige Historie beschrieben. ACK, Detection, MECM-Zustand und
lokales Clientlog bleiben getrennte Nachweise. Hostname und staticip sind an
ihren wirklichen Kontrollpfaden erklärt. Recoverytexte unterscheiden sicheren
Wiederanlauf von ungeklärter externer Wirkung.

SC-023 ist als Darstellungs- und Projektionskorrektur umgesetzt. Die Projektion
liefert die Anzahl konfigurierter Quellen. Die Ansicht unterscheidet jetzt:

1. keine konfigurierte Inventarquelle,
2. konfigurierte Quellen ohne qualifizierte vollständige Artauswertung,
3. teilweise auswertbare Evidenz ohne Befund,
4. vollständige, aber historische Evidenz ohne Befund,
5. vollständige aktuelle Evidenz ohne Befund.

Eine leere Befundliste erzeugt nur im letzten Fall die unqualifizierte aktuelle
Aussage "keine Abweichungen". Fehlende Auswertbarkeit erhält einen vorhandenen
Systemstatus-Link; keine konkrete Quelle wird geraten. Die bestehenden
VLAN-Reparaturbedingungen in POST-Handler und Repository sowie Fingerprint-,
Ziel-, Job- und Netzschutz wurden nicht verändert. Historische Evidenz wird als
Warnung dargestellt, nicht als neue Aktionssperre.

## Die 29 Claims

| Nr. | AP11-Claim | Entscheidung am aktuellen Stand | Evidenz und verbleibende Grenze |
|---:|---|---|---|
| 1 | Hilfe Missionen: 5/5 nach getinfo/ACK | **Text korrigiert.** 5/5 belegt den serverseitig akzeptierten, revisionsgebundenen Client-Ready-ACK. | **K:** Endpoint, Confirm-VsClientReady und client_getinfo gelesen. Eine verlorene Antwort kann serverseitig 5/5 hinterlassen, während lokale Detection erneut versucht. Realer Responseverlust offen. |
| 2 | Client-README: Snapshot, ACK, lokales SetupState | **Richtig, präzisiert.** | **K:** Snapshot wird vollständig publiziert; SetupState folgt erst auf positive ACK-Antwort. Reale Registry-/Netzfehler offen. |
| 3 | MECM-Runbook: alleiniger 5/5-Schreiber und ACK-Body | **Text korrigiert.** Aktuelles Beispiel nennt rollout_revision und die Antwortverlustgrenze. | **K:** ADR-0019 und aktueller Endpoint/Clienthelper. |
| 4 | Client-README: jede Phase meldet Start und Abschluss | **Falsche Garantie entfernt.** | **K:** Skip-, Vor-MAC- und Frühabbruchpfade, ein best-effort-Sendeversuch, keine Outbox. |
| 5 | MECM-Runbook: vollständige Phasensequenz | **Falsche Garantie entfernt.** | **K:** Wire-Ereignisse bleiben möglich, Vollständigkeit ist nicht zugesagt. |
| 6 | Systemstatus-Hilfe: nur sichtbares failed ist echter Fehler | **Text korrigiert.** Fehlende oder stehende Telemetrie ist unbekannt. | **K:** Detection, MECM-Zustand und lokales Log sind getrennte Diagnosewege. |
| 7 | Missionshilfe: keine Phase beweist fehlenden Boot/Task-Sequence-Start | **Beweisbehauptung entfernt.** | **K:** fehlendes Ereignis ist nur ein Diagnosehinweis. Standortdiagnose offen. |
| 8 | Systemstatus-Hilfe: Hostname benennt immer um und rebootet | **Text korrigiert.** Nur Workgroup plus abweichender Name führt zu Rename/Reboot. | **K:** Domain-Skip und passender Name rebooten nicht. |
| 9 | Systemstatus-Hilfe: staticip wechselt VLAN | **Falsche Zuordnung entfernt.** | **K:** Client setzt Adaptername, DHCP/statische IPv4, DNS und Route. ESXi-Portgruppe gehört zur Ansible-/ESXi-Strecke. |
| 10 | Erwarteter Reboot verhindert regulär Hostname-Abschluss | **Text korrigiert.** Bei Report-MAC wird finished vor dem geplanten Timer versucht; ohne Report-MAC gibt es keinen Versuch. | **K:** Sendeversuch bleibt best effort. Reale Zustellung und Rebootwirkung offen. |
| 11 | Stackhilfe: jede der vier Phasen meldet Fortschritt | **Falsche Garantie entfernt; ACK getrennt erklärt.** | **K:** letzter eingetroffener Phasenstand ist Diagnose, 5/5 gehört allein zum ACK. |
| 12 | Stackhilfe: Reports gehen nicht verloren und kommen später | **Falsche Recoveryzusage entfernt.** | **K:** Phase hat einen Versuch; nur der Client-Ready-ACK besitzt seinen engeren Detection-/Retrypfad. |
| 13 | Stackhilfe: MECM-Sync holt nach Neustart alles nach | **Text qualifiziert.** Normale Pending-Arbeit konvergiert; ungeklärte Journale, Contentidentität oder externe Wirkung bleiben manuell. | **K:** aktuelles U07-U10-Verhalten berücksichtigt. MECM-/DP-Laufzeitabnahme offen. |
| 14 | Deploy-Runbook: aktiver direkter SSH/SFTP-Pfad, Durable Runner dormant | **Richtig, beibehalten.** | **K:** Claim-/Requirepfad und Aktivierungsschalter gelesen. |
| 15 | Offline-Installation: Durable-Runnerbasis noch nicht produktiv aktiviert | **Richtig, beibehalten.** | **K:** aktueller Aktivierungsvertrag. |
| 16 | Deploy-Runbook: Create/Full "bis 14B" ausgeschlossen | **Historische Gegenwartsform korrigiert.** | **K:** Per-VM-Create ist im direkten legacy_v1-Pfad aktiv; separater Durable Runner bleibt dormant. |
| 17 | Deploy-Runbook: persistente Per-VM-Create-Einheiten | **Richtig, beibehalten.** | **K:** Worker/Create-Owner gelesen. Reale vCenter-Wirkung offen. |
| 18 | Clientinstaller: ContentShare vor MECM-Mutation manifestgleich | **Historischer AP11-Fehler ist durch U06 im Code korrigiert; Doku auf aktuellen Vertrag eingeordnet.** | **K:** Drift oder unentscheidbare Prüfung stoppt vor Siteinitialisierung/CM-Cmdlet. Share-/DP-/SYSTEM-Abnahme offen. |
| 19 | PowerShell-README: Blocker bedeutet keinerlei Arbeit geleistet | **Text korrigiert.** Blocker bedeutet fehlgeschlagene Gesamtabnahme und kann bereits erfolgte Nebenwirkungen einschließen. | **K:** Log und erreichte Stufe entscheiden über Recovery. |
| 20 | Serverinstaller: Serverdateien, Registry und Tasks rollbackfähig | **Richtig für den benannten Satz; beibehalten.** | **K:** aktueller Stage-/Backup-/Rollbackpfad. Echte ACL-/Diskfehler offen. |
| 21 | Paketvorlage ohne gleichwertigen Rollback | **Historischer AP11-Fehler ist durch U09 korrigiert und dokumentiert.** | **K:** vollständige Vorlage wird gestaged, gehasht, gesichert und gemeinsam zurückgerollt. Windows-Dateisystemfehler offen. |
| 22 | 1641/3010 beweist tatsächlichen Clientreboot | **Als offene Standortabnahme gekennzeichnet.** | **K:** Returncode-/MECM-Klassifikation ist implementiert. Tatsächlicher Reboot und Task-Sequence-Fortgang bleiben unbewiesen. |
| 23 | Paketparameter und InstallationBehaviorType | **Regulärer Pfad richtig; beibehalten.** | **K:** Autoimporter weist unbekannte Werte ab. Isolierte Vorlagenrandbedingung und SYSTEM-/Benutzerwirkung bleiben Laufzeitabnahme. |
| 24 | Alter positiver Marker kann Repairfehler verdecken | **Historischer AP11-Fehler ist durch U10 korrigiert und im Text eingeordnet.** | **K:** Invalidation nur bei belegtem Hash-Miss vor Kindstart; Lese-/Löschfehler sperren. Windows-/CM-Evaluation offen. |
| 25 | DP-Erfolg, Flagabhängigkeit und Retry | **Historische falsche Doku korrigiert.** | **K:** Distribution ist flagunabhängig; Abschluss bindet Application/DT-Content-ID, exaktes DP-Ziel, Aggregat und neuere Kopierbaseline. Provider-/DP-Wirkung offen. |
| 26 | Change Detection mache Leerlauf millisekundenschnell | **Nicht nachgewiesene Performancezusage entfernt.** | Ohne AP09-/Standortmessung wird keine Laufzeit versprochen. |
| 27 | Membershipjournal schützt dauerhaft | **Historische AP11-Grenzen sind durch U07/U08 im Code adressiert; Recoverytext qualifiziert.** | **K:** Quarantäne bleibt sperrend, UTF-8 ist explizit, Provenienz-/ACK-Reihenfolge ist gebunden. PS5.1/MECM-Laufzeit offen. |
| 28 | Getrennte Logger, gleiche Verträge, Sinkfehler nicht fatal | **Im Kontrollfluss richtig; beibehalten.** | **K:** Version-/Schema-/Boundsvertrag gelesen. Gesperrte Datei und Retention auf realem Host offen. |
| 29 | Installerparameter und Defaults | **Richtig, beibehalten; externe Voraussetzungen ausdrücklich offen.** | Share, Provider, ACL, DP-Gruppe, Zertifikat und Erreichbarkeit sind Standortfreigaben, keine Produkteigenschaft. |

## SC-023 Dateien und vorbereitete Regression

lib/esxi_inventory_deviation_report.php projiziert source_count.
lib/system_status_deviation_controls.php besitzt die Auswahl der Nullzustände
und vorhandenen Links. lib/system_status_esxi_panels.php rendert nur das
Ergebnis dieser Auswahl. Dadurch bleibt der bestehende große Renderer unter
seinem Dateibudget, ohne eine neue Ausnahme anzulegen.

tests/Unit/SystemStatusDeviationEvidenceTest.php bereitet die fokussierte Matrix
für keine Quelle, unqualifizierte Quellen, teilweise Evidenz, vollständige
historische Evidenz und vollständige aktuelle Evidenz vor.
SystemStatusPanelBranchTest.php reicht die Projektion nun in den bestehenden
Branchtest. Beide Tests wurden in dieser Runde bewusst nicht ausgeführt.

## Systematischer Hilfekorpus

Die neun gepaarten aktiven Hilfekataloge wurden gegen die U14-Themen erfasst.
"Keine Änderung" bedeutet, dass im vereinbarten Claimkorpus kein zusätzlicher
Widerspruch gefunden wurde; es ist keine semantische Vollabnahme jedes Satzes.

| Hilfe | U14-Ergebnis |
|---|---|
| help_overview.php | Missions-/Statuslinks und Rollenhinweise im Zielkorpus gelesen; keine U14-Änderung. |
| help_credentials.php | Evidenzalter, verworfene Tests und externe Zugangsvoraussetzungen gelesen; keine U14-Änderung. |
| help_deploy.php | Create-, Retry-, Uncertain- und Netzblockertexte thematisch gelesen; fremde laufende Änderungen erhalten, keine U14-Änderung. |
| help_missions.php | 5/5-/ACK- und "keine Phase"-Aussagen in DE/EN korrigiert. |
| help_packages.php | Paket-/Detection-/Templatehinweise gegen U09/U10 eingeordnet; keine weitere U14-Änderung. |
| help_settings.php | Reportvollständigkeit als best effort und Backup als Dump/Config/Manifest-Satz korrigiert. |
| help_stack.php | Phasen, Wiederanlauf, MECM-Reports, Jobausgänge, unless-stopped, Supervisor sowie Backup/Restore in DE/EN korrigiert. |
| help_system_status.php | Clientphasen, ACK, Hostname, IP statt VLAN, fehlende Meldung und Inventarevidenz korrigiert. |
| help_users.php | Rollen-/Zugangsbehauptungen im Zielkorpus gelesen; keine U14-Änderung. |

## Aktiver Installations- und Betriebskorpus

| Dokument | U14-Ergebnis |
|---|---|
| README.md | Einstieg und Dokumentverweise im Claimkorpus erfasst; keine neue Produktzusage gefunden. |
| docs/INSTALLATION-ANLEITUNG.md | unless-stopped und manuellen Supervisorzustand präzisiert; externe Hostvoraussetzungen bleiben Betreiberabnahme. |
| docs/DEPLOYMENT.md | Aktiven Per-VM-Create- und direkten Transportpfad gegen den aktuellen Worker eingeordnet; keine U14-Änderung. |
| docs/operations/go-live.md | Freigaben, Hostrechte, Proxy/Netz und Rauchtests als externe Abnahme eingeordnet; keine U14-Änderung. |
| docs/operations/offline-install.md | Dormanten Durable Runner und Offlinevoraussetzungen bestätigt; keine U14-Änderung. |
| docs/operations/deploy-chain.md | Phasentelemetrie und historischen 14B-Satz korrigiert. |
| docs/operations/mecm-integration.md | ACK-Body/Antwortverlust, best-effort-Phasen, Hostname und IP-/Subnetzgrenze korrigiert. |
| docs/operations/troubleshooting.md | Relevante ACK-, Phase-, Create-, Inventar- und Recoveryfälle gelesen; bestehende Diagnosewege decken den U14-Rest, keine Änderung. |
| docs/operations/https.md | Zertifikat-, Browser- und Erreichbarkeitsvoraussetzungen als externe Freigabe eingeordnet; keine U14-Änderung. |
| docs/operations/vm-progress-observation.md | Portalbeobachtung nicht als vollständige externe Wirkungsevidenz eingeordnet; keine U14-Änderung. |
| docs/operations/esxi-inventory.md | Aktualität, Artqualifikation, Name/Freshness und externe Inventarquelle gegen SC-023 gelesen; keine Schutzlockerung. |
| docs/operations/backup.md | Backupstand als Dump, Konfigurationsarchiv und SHA-256-Manifest korrigiert; Retention getrennt je Artefaktart und vorhandenen U17-Prüfwurzeltext erhalten. |
| docs/operations/active-directory.md | Domänen-/Restorefreigabe und externe Voraussetzungen eingeordnet; keine U14-Änderung. |
| Powershell-MECM/README.md | Blocker, Content-/DP-Nachweis, Template-Rollback, 1641/3010 und unbelegte Leerlaufperformance korrigiert. |
| Powershell-MECM/clients/README.md | best-effort-Phasen, ACK-Grenze, Hostname und IP statt VLAN korrigiert. |

Der getrennte Root-Abgleich von docs/QA.md, docs/QUALITY-GATES.md,
docs/TESTPLAN.md und docs/security/asvs-wstg-matrix.md gegen Runner- und
Security-Owner steht in
[2026-09-10-u13-u14-qa-plan.md](2026-09-10-u13-u14-qa-plan.md#root-beitrag-zum-ap11-restkorpus).
Dieser Bericht beansprucht für diesen separaten Restkorpus keine Doppelprüfung.

## Explizite Restlücken

- Die aktuelle Hilfe und aktive Betriebsdokumentation wurden systematisch für
  die 29 AP11-Claims und die daraus folgenden U14-Themen gelesen. Eine
  vollständige semantische Beweisführung für jeden anderen Satz in allen
  Katalogen und historischen ADR-/Auditdokumenten wird nicht behauptet.
- Im vorbestehend geänderten Client-Common-Kommentar steht weiterhin ein
  Hinweis auf einen "VLAN-Wechsel". Der ausgeführte Code ändert Windows-IP,
  Subnetz, DNS und Route, nicht die ESXi-Portgruppe. Die Kommentarreststelle hat
  keine Laufzeitwirkung, ist als redaktioneller P3-Rest eingeordnet und wurde
  wegen des anderen E1-Owners nicht angefasst.
- Reale Verluste von Phasen- oder ACK-Antworten, echte SYSTEM-Ausführung,
  Hostname-Reboot, Task-Sequence-Fortgang, MECM-Provider, Share-/ACL-/DP-Wirkung,
  gesperrte Logs, Backupfehler und Desaster-Restore bleiben Laufzeitabnahmen.
- Der aktuelle U07-U10/U12/U17-Code wurde als K-Evidenz für den Text benutzt;
  die historischen negativen AP11-Befunde beweisen die Korrekturen nicht.
- E1, E7 und E9 sind im aktuellen Arbeitsbaum implementiert und am Quellcode
  geprüft; offen ist ihre Engine-/Laufzeitabnahme. Der gesonderte
  E9-Registry-Randfall und der ungeklärte PHP-Container-Exit 137 bleiben
  separat und werden keiner U14-Ursache zugerechnet.

Damit lautet der belastbare U14-Stand: **implementiert, anhand des Codes
geprüft, Laufzeitabnahme offen**.
