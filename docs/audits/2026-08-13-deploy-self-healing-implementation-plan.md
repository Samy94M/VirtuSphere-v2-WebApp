# Implementierungsplan: selbstheilende Deploy-Ausführung und verständlicher Betriebsstatus

Status: **Reviewspur, in den konsolidierten Plan integriert, nicht separat ausführen**

Stand: 2026-08-13

Auslöser: Produktionsvorfall mit terminalen Datenbankaufträgen und weiterhin laufenden entfernten `ansible-playbook`-Prozessgruppen

Geltungsbereich: Deploy-Worker, Wartungs-Reaper, Ubuntu-Ansible-Host, Deploy- und Systemstatus-Portal, Protokolle, Hilfe, Betriebs- und Installationsdokumentation

Nicht Gegenstand dieses Dokuments: direkte Änderung der Machine-API-Verträge oder eine Behauptung, vSphere-Aufgaben beliebig abbrechen zu können

> Konsolidierungshinweis vom 2026-08-13: Der vollständige ausführbare Vertrag liegt jetzt in `docs/audits/2026-08-13-mac-import-vlan-ambiguity-qol-implementation-plan.md`, insbesondere Abschnitte 16 bis 25. Dort wurden zusätzlich Controllerwirkung/Reconciliation getrennt, Restore-Generationen zufällig gemacht, die manuelle Resolution geschlossen, Secret-Cleanup getrennt, Dienstverfügbarkeit, Claim-Freigabe und Recovery-Aufmerksamkeit auf drei Achsen verteilt sowie der Create-Async-Fall mit `ExitType=cgroup` und einer festen Etappenfolge 8R/13R/14A/14B/14C ergänzt. Dieser Einzelplan bleibt als Herkunfts- und Quellenreview erhalten; seine abweichenden Passagen haben keinen Ausführungsvorrang.

## 0. Einordnung und Vorrang

Dieser Plan ergänzt:

- `docs/audits/2026-08-11-deploy-reliability-master-plan.md` um die bisher fehlende dauerhafte Kontrolle entfernter Ausführungen, Worker-Fencing und automatische Wiederaufnahme;
- `docs/audits/2026-08-13-create-flow-reliability-implementation-plan.md` um die Container-, Reaper-, Systemstatus- und allgemeine Remote-Runner-Ebene.

Bei Überschneidungen gilt:

1. Der Create-Plan bleibt für die sequenzielle Einzel-VM-Erstellung, Ansible-Async-JIDs, Create-Ergebniszeilen und die VM-Identitätsauflösung maßgeblich.
2. Dieser Plan ist für Worker-Leases, entfernte Playbook-Handles, Wiederaufnahme, Reaper-Entscheidungen, Supervisor und Betriebsanzeige maßgeblich.
3. Der Masterplan bleibt für Fehlerherkunft, SSH-/SFTP-Budgets, strukturierte Audits, Joblog-Tail, Terminalergebnis und allgemeine UX-Etappen maßgeblich, soweit dieser Plan keinen spezielleren Vertrag festlegt.
4. Die nächste Migrationsnummer wird erst bei Beginn der Umsetzung gegen den dann aktuellen Arbeitsbaum ermittelt. Dieses Dokument reserviert bewusst keine Nummer.

Die Umsetzung darf nicht als ein großer Commit erfolgen. Jede Etappe muss unabhängig migrierbar, prüfbar und rückbaubar sein. Änderungen an Deploy-Status, Migrationen, PowerShell-/Machine-API-Nähe und Produktionsverträgen benötigen vor dem Commit den dafür vorgesehenen Contract-Review; Portaltexte zusätzlich i18n-Prüfung, Layoutänderungen zusätzlich die CSS-, Modal-, Bestätigungs- und Responsive-Verträge des Repositories.

### 0.1 Vor Umsetzung aufzulösende Widersprüche in den Bestandsplänen

Der neue Produktionsbefund macht drei ältere Annahmen unhaltbar. Nach Freigabe dieses Plans werden die betroffenen Bestandspläne in derselben reinen Dokuetappe angepasst, bevor Code geändert wird:

1. **Create-Plan Abschnitt 11.2:** Dort terminalisiert der Reaper den Job und ein späterer Retry soll dieselbe JID weiterpollen. Das wird ersetzt. Eine bekannte laufende/unklare JID bleibt an demselben aktiven Job; der Reaper löst nur die alte Lease und fordert Recovery an. Erst die Recovery/Reconciliation finalisiert. Ein fachlich neuer Retry übernimmt keine aktive alte JID.
2. **Masterplan Abschnitt 7.7:** Einzelne SSH-Befehle pro Playbook bilden zwar echte Schrittgrenzen, sind aber noch kein dauerhaftes Remotehandle. Für neu aktivierte Modi wird der dortige direkte Step-Exec durch das in diesem Plan definierte Prepare/Launch/Poll/Reattach-Protokoll ersetzt.
3. **Masterplan Abschnitt 7.8 und Create-Plan Cleanup:** Ein normales `finally` beziehungsweise ein altersbasierter Verzeichnissweep darf keinen gestarteten oder ungeklärten Remote-Beweis entfernen. Cleanup verwendet künftig Remote-Ausführungszeile, Run-Token, Terminalnachweis, Cleanup-Lease und Diagnosefrist; die Create-Async-Bereinigung bleibt zusätzlich JID-spezifisch.

Diese Änderungen sind keine stillen Interpretationen. Die beiden Bestandsdokumente erhalten einen datierten Verweis auf die neue ADR und den geänderten Vorrang. Bis zu dieser Planharmonisierung darf die semantische Implementierung nicht beginnen.

## 1. Kurzbefund

### 1.1 Was im Produktionsvorfall tatsächlich passiert ist

- Der Container `deploy-worker` lief, war aber `unhealthy`.
- Ein Wartungslauf setzte einen Auftrag nach zehn Minuten ohne Jobheartbeat auf `failed`.
- Andere Aufträge waren in der Datenbank bereits `failed` oder `cancelled`.
- Auf dem Ubuntu-Ansible-Host liefen deren `bash`-, `python3`- und `ansible-playbook`-Prozessgruppen trotzdem weiter.
- Erst das gezielte Beenden der drei bekannten Prozessgruppen entfernte die Prozesse.
- Ein neuer Auftrag blieb bei gestopptem Worker korrekt `queued` und wurde nach dem Start übernommen. Das ist Persistenz der Warteschlange, aber noch kein Self-Healing.
- Auftrag 115 endete unabhängig davon korrekt als `partial`, weil der MAC-Import für 3 von 15 VMs fehlschlug.

### 1.2 Warum der sichtbare Zustand widersprüchlich war

Die aktuelle Implementierung besitzt drei voneinander getrennte Lebenszyklen:

1. **Datenbankauftrag:** `queued`, `running`, `cancelling` und terminale Stati.
2. **Worker/Container:** Prozess, lokaler Heartbeat und Docker-Healthcheck.
3. **Entfernte Ausführung:** SSH-Kanal, Shell, `ansible-playbook`, Ansible-Child und nachgelagerte vSphere-Aufgabe.

Heute wird ein verlorener SSH-/Worker-Kanal so behandelt, als ließe sich daraus ein sicherer terminaler Auftragszustand ableiten. Das ist falsch. Ein Timeout, eine geschlossene SSH-Verbindung oder ein fehlender DB-Heartbeat sagt nur, dass VirtuSphere den Controller nicht mehr beobachtet. Er beweist weder, dass der Linux-Prozess beendet ist, noch, dass ESXi/vCenter die bereits angenommene Aufgabe beendet hat.

### 1.3 Warum `restart: unless-stopped` das nicht heilt

Docker-Restartregeln reagieren auf das Ende des Container-Hauptprozesses. Ein Healthcheck markiert einen weiterhin laufenden Container nur als `unhealthy`; er löst keinen Neustart aus. Ein absichtlich mit `docker compose stop` gestoppter Container bleibt bei `unless-stopped` außerdem absichtlich gestoppt. Daher war das beobachtete Verhalten regelkonform, aber für den Produktbetrieb unzureichend.

### 1.4 Kernursache

Es fehlt ein persistentes, exakt adressierbares Remote-Ausführungshandle. Die Anwendung kennt nach Verlust des SSH-Kanals weder eine stabile Unit/JID noch einen dauerhaften Ergebnisnachweis. Der Reaper kann deshalb nur den DB-Datensatz umschreiben. Er kann den entfernten Zustand weder wiederfinden noch sicher beenden noch fachlich versöhnen.

### 1.5 Nachgewiesene Code- und UX-Lücken

| Stelle | Heutiges Verhalten | Lücke |
|---|---|---|
| `lib/ssh.php` | streamt einen SSH-Exec-Kanal und trennt bei Exception/Idle-/Totalbudget die Verbindung | kein Remotehandle und kein Cleanup-/Reattach-Beweis |
| `lib/ansible_command.php` | baut die Playbooks eines Jobs als eine Shell-`&&`-Kette | Playbookgrenzen sind nicht einzeln persistiert; eine lokale Abbruchgrenze ist nicht beweisbar |
| `lib/deploy_worker_mission.php` | hält die ganze Playbookfolge an einem SSH-Aufruf; Cancel wird an lokalen Grenzen geprüft | Worker-/SSH-Verlust trennt Beobachtung von Remoteausführung |
| `lib/repo/deploy_job_maintenance.php` und `lib/deploy_worker_reaper.php` | stale Heartbeat führt zu terminalem DB-Zustand und VM-Konvergenz | Remoteprozess/-task wird weder geprüft noch beendet; `failed` kann parallel zu echter Mutation stehen |
| `lib/worker_heartbeat.php` und `lib/worker_healthcheck.php` | lokale Datei macht den Compose-Healthcheck rot/grün | ein roter Healthcheck beendet den laufenden Container nicht und startet ihn nicht neu |
| `lib/deploy_worker_runtime.php` | Worker schreibt einen aktuellen Integrationheartbeat | die Produktionszeile kann fehlen; Supervisor-, Kind-, Queue- und Remotezustand sind nicht getrennt |
| `portal/health.php` | kennt DB und stale `running`-Jobs | keine fällige Queue, keine Worker-/Recoverybereitschaft, keine Pausenlage |
| `lib/integration_health.php` und Systemstatus | zeigen eine Heartbeat-Ampel und verweisen bei Ausfall auf Ubuntu/Docker | normale Benutzer können Ursache/Ablauf nicht unterscheiden und nichts im Portal tun |
| `lib/deploy_page.php` | prüft Missions-, Credential- und API-Voraussetzungen | der Dienstzustand fehlt; Einreihen ist technisch möglich, aber Rückmeldung sagt nur `queued` |
| `portal/deploy_log.php` und Poller | initial begrenzter Tail; Polling nur bei nichtterminalen Jobs | terminale Logs oberhalb der Grenze können verborgen bleiben; versteckte Tabs pollen weiter |
| `deploy_integration_heartbeats` | eine aktuelle Zeile pro Quelle | geeignet für Snapshot, ungeeignet als Recoveryhistorie |
| `deploy_job_logs` | lineare Joberzählung | ohne strukturierte Eventcodes schwer nach Recoveryursachen filterbar |
| Deploy-/Stack-/Systemstatus-Hilfe | verspricht teilweise einfaches Fortsetzen/Requeue oder nennt Dockerbefehle | unterscheidet Queuepersistenz nicht von sicherer Remote-Wiederaufnahme und ist für normale Nutzer nicht handlungsfähig |

Die Beobachtung `Job bleibt bei gestopptem Worker queued` ist für sich kein Defekt: Eine dauerhafte Queue darf warten. Der Defekt ist, dass Portal und Betriebsstatus nicht verständlich sagen, **warum** sie wartet, ob automatische Wiederaufnahme stattfindet und ob ein älterer Remotevorgang noch aktiv sein könnte.

## 2. Ziele und Erfolgskriterien

Die Umsetzung ist fertig, wenn alle folgenden Aussagen nachweislich gelten:

1. Ein laufender Playbook-Schritt besitzt vor der ersten Mutation einen persistenten DB-Datensatz und eine eindeutige, dauerhafte Identität auf dem Ansible-Host.
2. Ein Worker-Neustart, Container-Neustart, DB-Neustart oder kurzzeitiger SSH-Ausfall führt zur Wiederaufnahme derselben Ausführung, nicht zu einer zweiten Ausführung.
3. Ein Reaper darf einen Auftrag nicht terminalisieren, solange eine entfernte Mutation aktiv oder ihr Ergebnis ungeklärt ist.
4. Ein terminaler DB-Auftrag kann keinen von VirtuSphere kontrollierten Linux-Prozess desselben Laufs mehr unbeobachtet weiterlaufen lassen.
5. Ein Container mit blockiertem Worker-Kindprozess heilt sich nach mehreren bestätigten lokalen Heartbeatfehlern selbst, ohne einen Docker-Socket oder Host-Shellzugriff im Portal.
6. Normale Benutzer erkennen im Portal, warum ein Auftrag wartet, ob er geplant, pausiert, in automatischer Wiederherstellung oder wirklich blockiert ist und was als Nächstes passiert.
7. Administratoren können den Deploy-Dienst im Portal nach dem aktuellen Auftrag pausieren und wieder freigeben. Dafür wird der Container nicht gestoppt.
8. Ein unsicherer Mutationsausgang wird nie blind erneut ausgeführt. Wo automatische Versöhnung keinen Beweis liefern kann, zeigt das Portal ehrlich `Ausgang wird geprüft` beziehungsweise `manuelle Klärung erforderlich`.
9. Auftragsprotokoll, strukturierte Betriebsereignisse und aktuelle Systemstatus-Sicht haben je eine benannte SSoT und widersprechen einander nicht.
10. Der gesamte Betrieb bleibt air-gap-fähig: keine Cloud, keine Telemetrie, kein CDN und kein Laufzeitdownload.

Messbare Abnahmekriterien:

- Die Fault-Injection-Matrix aus Abschnitt 17 läuft vollständig grün.
- Kein Testfall erzeugt bei Wiederaufnahme eine zweite Remote-Unit, eine zweite Ansible-Async-JID oder eine zweite VM.
- Nach Worker-Kill während eines laufenden Schritts erscheint binnen der festgelegten Erkennungszeit `Wiederherstellung läuft`; anschließend wird derselbe Lauf fortgesetzt oder fachlich versöhnt.
- Ein versteckter Browser-Tab erzeugt keine dauernde Logpolling-Last und holt beim Sichtbarwerden lückenlos nach.
- Alle DE-/EN-Texte, Dokuverträge, SSoT-Guards und Release-Gates sind grün.

## 3. Verbindliche Produktentscheidungen

Diese Entscheidungen sind die empfohlene Basis. Sie müssen vor Etappe 2 als Produktentscheidungen bestätigt und danach in einer neuen ADR festgeschrieben werden.

### P1: Warteschlange bleibt auch bei gestörtem Dienst verfügbar

Ein Auftrag darf gespeichert werden, wenn der Deploy-Dienst pausiert, wiederhergestellt oder vorübergehend nicht erreichbar ist. Das Portal zeigt vor dem Absenden und nach dem Speichern den tatsächlichen Wartegrund. Es behauptet nicht, der Auftrag starte sofort.

Ausnahmen bleiben fachliche Blocker wie unvollständige Mission, fehlende Zugänge, Identitätskonflikt oder bereits aktiver Missionsauftrag.

### P2: Kein blindes automatisches Requeue nach unklarem Ausgang

`failed`, `cancelled` oder ein Timeout ist kein Beweis, dass ESXi nichts mehr tut. Ein Retry wird nur freigegeben, wenn:

- die frühere entfernte Ausführung nachweislich nie begonnen hat;
- sie nachweislich terminal ist; oder
- eine modusspezifische Versöhnung den gewünschten Istzustand und die Identität zweifelsfrei festgestellt hat.

### P3: Durable Remote Execution über systemd-User-Services

Jeder Playbook-Schritt läuft in einer eindeutig benannten transienten `systemd --user`-Service-Unit des dedizierten Ansible-Benutzers. Der Benutzer erhält während der Installation `linger`, damit sein User-Manager unabhängig von der SSH-Sitzung besteht.

Der systemd-Dienst ist die generische Prozess- und Cgroup-Hülle. Der Create-Plan nutzt zusätzlich seine persistierten Ansible-Async-JIDs pro VM. Beides löst verschiedene Probleme und wird nicht gegeneinander ausgespielt.

### P4: Keine unsichere Kompatibilitätsfallback-Ausführung

Nach Aktivierung des neuen Protokolls werden neue Aufträge nicht mehr über die alte langlebige SSH-`&&`-Kette ausgeführt. Fehlt `systemd --user`, Linger, die unterstützte Ansible-Version oder der checksum-geprüfte/versionierte Runner, bleibt der Auftrag mit verständlichem Blocker in der Warteschlange. Ein stiller Legacy-Fallback würde den Produktionsfehler wieder einführen.

### P5: Geschäftsstatus bleibt kompatibel, Betriebszustand wird abgeleitet

Die bestehende Jobstatusmenge bleibt zunächst unverändert. `running` und `cancelling` bleiben aktiv. Zustände wie `recovering`, `paused`, `remote active` und `outcome uncertain` sind abgeleitete Ausführungs- beziehungsweise Dienstzustände, keine neuen Machine-API- oder Job-ENUM-Werte.

### P6: Sichere Abbruchgrenze statt erzwungener vSphere-Abbruch

Ein normaler Portalabbruch bedeutet weiterhin: keine weitere Playbook-/VM-Einheit beginnen, den laufenden mutierenden Schritt beobachten und an der nächsten bewiesenen Grenze abschließen. Ein automatisches `kill -9` oder `systemctl stop` eines mutierenden Schritts ist kein normaler Abbruch, weil die vSphere-Aufgabe danach fortlaufen kann.

### P7: Portal steuert die Wartungsfreigabe, nicht Docker

`Nach aktuellem Auftrag pausieren` setzt eine persistente Claim-Sperre. Der laufende Auftrag wird nicht unterbrochen; danach übernimmt der Worker keine neuen Jobs. `Fortsetzen` hebt die Sperre auf. Das Portal bekommt weder Docker-Socket noch `sudo` noch eine freie SSH-Konsole.

### P8: Automatische Heilung ist begrenzt und ehrlich

Vollständige automatische Heilung ist nur dort erlaubt, wo Identität und Sollzustand belegbar sind. Ein nicht beweisbarer vSphere-Ausgang wird sichtbar eskaliert, nicht schöngeredet. Das Ziel ist, Ubuntu-Zugriff im Normalfall unnötig zu machen, nicht physikalisch unmögliche Garantien zu behaupten.

## 4. Quellenprüfung und daraus abgeleitete Entscheidungen

Die Architektur stützt sich auf mehrere unabhängige Primär- beziehungsweise Herstellerquellen:

| Quelle | Relevanter Vertrag | Konsequenz für VirtuSphere |
|---|---|---|
| Docker, Restart Policies: <https://docs.docker.com/engine/containers/start-containers-automatically/> | Restartregeln reagieren auf Containerende; `unless-stopped` respektiert manuelles Stoppen. | `unhealthy` allein ist kein Self-Healing. Der Hauptprozess muss bei einem nicht behebbaren lokalen Fehler kontrolliert enden oder einen Kindprozess neu starten. |
| Docker, Healthchecks: <https://docs.docker.com/engine/containers/run/#healthchecks> | Healthchecks liefern Zustand und Diagnosetext. | Healthcheck wird Diagnose, nicht Geschäftsstatus-SSoT und nicht alleinige Recovery-Engine. |
| RFC 4254, SSH Connection Protocol: <https://www.rfc-editor.org/rfc/rfc4254.html> | Channel-Close, Signalübermittlung und optionaler Exitstatus sind getrennte Protokollereignisse. | Ein verlorener/geschlossener SSH-Kanal ist kein hinreichender Prozess- oder Geschäftsnachweis. |
| Ansible Async: <https://docs.ansible.com/projects/ansible/latest/playbook_guide/playbooks_async.html> | Synchron hält Ansible die Verbindung offen; `async`/`poll: 0` liefert eine spätere Job-ID, deren Cache bewusst bereinigt werden muss. | Create verwendet persistierte JIDs; Poll und Cleanup sind explizite Workeraufgaben. |
| Ansible `async_status`: <https://docs.ansible.com/projects/ansible/latest/collections/ansible/builtin/async_status_module.html> | Eine bekannte JID lässt sich später beobachten und bereinigen. | JID wird vor dem nächsten VM-Start persistiert und nie nur aus Logtext rekonstruiert. |
| systemd-run: <https://github.com/systemd/systemd/blob/main/man/systemd-run.xml> | Transiente Services werden vom Service-Manager verwaltet; `Type=exec` bestätigt den erfolgreichen Exec-Start. | Jeder Schritt erhält eine deterministische Service-Unit und lässt sich nach SSH-/Workerverlust wiederfinden. |
| systemd KillMode: <https://github.com/systemd/systemd/blob/main/man/systemd.kill.xml> | `KillMode=control-group` umfasst alle Prozesse der Unit; `process` und `none` werden nicht empfohlen. | Shell, Ansible und Child-Prozesse bleiben in einer adressierbaren Cgroup; ein zulässiger Stop kann keine bekannten Kinder verwaisen lassen. |
| systemd Service: <https://github.com/systemd/systemd/blob/main/man/systemd.service.xml> | Service-Runtime, Restart, Watchdog und Startbegrenzung sind getrennte Mechanismen. | Runner-Runtime und Container-Supervisor erhalten getrennte, begrenzte Budgets und eine Restart-Schleifensperre. |
| `loginctl enable-linger`: <https://www.freedesktop.org/software/systemd/man/252/loginctl.html> | Der User-Manager startet beim Boot und bleibt nach Logout bestehen. | Offline-Installer richtet Linger für den dedizierten Ansible-Benutzer ein und prüft es vor Aktivierung. |
| Broadcom KB 431859: <https://knowledge.broadcom.com/external/article/431859/virtual-machine-deployment-fails-with-co.html> | Eine Automationsschicht kann timeouten, während vCenter die Aufgabe weiter verarbeitet. | Timeout darf Create nicht automatisch wiederholen. |
| Broadcom KB 314602: <https://knowledge.broadcom.com/external/article?legacyId=1013003> | vCenter- und ESXi-Aufgabensicht können abweichen; nach einem Timeout kann `Another task is already in progress` folgen. | Recovery prüft Livezustand und behandelt Taskkonflikte als laufende/unklare Arbeit, nicht als Beweis für einen neuen Retry. |
| W3C WCAG 2.2, 4.1.3: <https://www.w3.org/TR/WCAG22/#status-messages> | Statusmeldungen müssen programmatisch ermittelbar sein, ohne Fokuswechsel. | Livezustände erhalten eine ruhige `role=status`-Region und werden nicht nur über Farbe vermittelt. |
| W3C ARIA22: <https://www.w3.org/WAI/WCAG21/Techniques/aria/ARIA22> | `role=status` ist polite; `aria-atomic=true` macht die ganze Meldung verständlich. | Poller ersetzt die vollständige Statusmeldung atomar und verschiebt keinen Fokus. |
| WHATWG Page Visibility: <https://html.spec.whatwg.org/multipage/interaction.html#page-visibility> | Dokumente besitzen `visible`/`hidden` und senden `visibilitychange`. | Versteckte Joblog-/Status-Tabs pausieren Polling und holen beim Sichtbarwerden lückenlos nach. |
| OWASP Logging Cheat Sheet: <https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html> | Anwendungsereignisse brauchen Kontext, Sanitizing, Zugriffsschutz und Ausschluss von Geheimnissen. | Strukturierte Eventcodes, Korrelation, CR/LF-Sanitizing, Redaction und RBAC werden verpflichtend. |

Nicht übernommen werden die naheliegenden, aber unzureichenden Lösungen `nohup`, `setsid` plus PID-Datei, `screen`/`tmux`, ein Docker-Socket im Webcontainer oder ein Autoheal-Sidecar mit Docker-Socket. Sie liefern entweder kein starkes Prozessgruppen-/Resultatmodell, führen neue Laufzeitabhängigkeiten ein oder erweitern die Portalrechte bis zur Hostkontrolle.

## 5. Begriffe und getrennte Wahrheiten

| Begriff | Bedeutung | Kein Synonym für |
|---|---|---|
| Jobstatus | Fachlicher Zustand in `deploy_jobs.status` | Containerzustand, Remote-Unit-Zustand |
| Jobheartbeat | Lease-/Arbeitsnachweis des aktuell berechtigten Workers | Ausgabeaktivität, vSphere-Fortschritt |
| Supervisorheartbeat | Nachweis, dass der lokale PID-1-Supervisor überwacht und reagieren kann | Bereitschaft des Ansible-Hosts |
| Remote-Ausführung | Ein konkreter Playbook-Schritt mit Run-Token, Unit, Manifest und Resultat | gesamter Auftrag |
| Remote gestartet | Der Runner hat seinen dauerhaften `started`-Marker geschrieben, bevor er Ansible startet | fachlicher Erfolg |
| Wiederherstellung | Ein neuer berechtigter Worker bindet sich an dieselbe Remote-Ausführung oder versöhnt deren Ergebnis | Retry |
| Retry | Neuer fachlicher Auftrag beziehungsweise neue erlaubte Ausführung nach bewiesenem Ausgang | Wiederaufnahme |
| Unklarer Ausgang | Mutation könnte begonnen haben, aber es gibt noch keinen beweiskräftigen Endzustand | Fehler, Erfolg oder beendet |
| Pausiert | Persistente Sperre gegen neue Claims; laufender Job darf bis zur sicheren Grenze weiterlaufen | gestoppter Container |
| Container gesund | Supervisor-Hauptprozess ist reaktionsfähig | Queue wird gerade abgearbeitet |

Diese Begriffe werden identisch in Glossar, Portalhilfe, Betriebshandbuch und Tests verwendet.

## 6. Zielarchitektur

### 6.1 Verantwortungsgrenzen

- **MySQL** besitzt Jobstatus, Worker-Lease/Fencing, Claim-Pause, Remote-Ausführungshandle, Recoveryentscheidung und persistierte Logsequenz.
- **Deploy-Supervisor** besitzt ausschließlich den lokalen Worker-Kindprozess, Restart/Cooldown und den aktuellen Supervisorstatus.
- **Deploy-Worker** besitzt Jobreihenfolge, Remote-Launch/Poll, Logimport, modusspezifische Versöhnung und genau einmalige Finalisierung.
- **systemd --user auf dem Ansible-Host** besitzt Linux-Prozessgruppe, Unitstatus und Laufzeitgrenzen eines Playbook-Schritts.
- **Remote-Runner** besitzt dauerhafte Marker, begrenzte Ausgabe, Child-Wait und atomisches Resultat.
- **Ansible Async** besitzt beim Create die Ausführung einer einzelnen VM-Einheit und deren JID.
- **ESXi/vCenter Live-Inventar** bleibt Autorität über tatsächliche VM-Identität, Vorhandensein, Powerstate und Autostartkonfiguration.
- **Portal** stellt diese Zustände dar und reiht nur deklarative, RBAC-geprüfte Aktionen in die DB ein. Es führt keine Hostbefehle aus.

### 6.2 Ablauf eines neuen Playbook-Schritts

1. Worker prüft globale Lease, Job-Lease, Claim-Pause, Abbruch und Live-Identität.
2. Worker legt in einer DB-Transaktion eine Remote-Ausführung im Zustand `prepared` mit zufälligem Run-Token und deterministischer Unit/Remote-Position an.
3. Erst nach erfolgreichem DB-Commit werden Artefakte in ein exaktes, persistentes Remote-Verzeichnis hochgeladen.
4. Ein versionierter Launcher validiert Manifest, Pfad, Token, Dateirechte, Runner-Checksumme und Unitname.
5. Der Launcher startet dieselbe systemd-User-Service-Unit mit `Type=exec`, `KillMode=control-group` und festgelegten Ressourcen-/Runtimegrenzen.
6. Der Runner schreibt atomisch `started.json`, bevor er `ansible-playbook` startet.
7. Der Worker speichert `active`, pollt Unit, Resultat und Logoffset über kurze SSH-Aufrufe und erneuert seine DB-Lease unabhängig von der Playbook-Ausgabe.
8. Der Runner wartet auf alle Children und schreibt atomisch `result.json` mit Exitcode, Signal, Laufzeit, Truncation und Manifestbindung.
9. Der Worker validiert Resultat und fachliche Callback-/Inventarnachweise, finalisiert den Schritt und erst danach den Job.
10. Remote-Artefakte werden erst nach persistiertem Terminalnachweis und einer Diagnosefrist exakt bereinigt.

### 6.3 Warum das Remote-Verzeichnis nicht mehr `/tmp` ist

Der neue Laufzustand liegt unter einem festen, benutzereigenen Pfad, zum Beispiel:

```text
~/.local/state/virtusphere/<instance-id>/<generation>/jobs/<job-id>/<attempt>/<step>/<run-token>/
```

Der Pfad:

- übersteht SSH-Abmeldung und Hostneustart;
- gehört ausschließlich dem dedizierten Ansible-Benutzer;
- ist in jedem Segment aus validierten internen IDs aufgebaut;
- enthält keine Mission-, VM- oder Benutzernamen;
- wird mit `0700`, einzelne Dateien mit `0600` angelegt;
- wird nie über ein breites `find ... -delete` aus einem Portalpfad entfernt.

### 6.4 Remote-Dateivertrag

| Datei | Schreiber | Zweck |
|---|---|---|
| `manifest.json` | Worker/Launcher | unveränderliche Identität, Protocol-Version, Checksummen, Job/Attempt/Step, Run-Token, Korrelations-ID |
| `launch.json` | Launcher | bestätigt akzeptierten Startauftrag und Unitname |
| `started.json` | Runner | beweist, dass der Runner vor Ansible angelaufen ist |
| `heartbeat.json` | Runner | lokale Fortschrittsbeobachtung ohne Ausgabeabhängigkeit |
| `output.log` | Runner | größenbegrenzte Roh-Ausgabe für reattach und DB-Import |
| `result.json` | Runner | atomisches Terminalresultat des Linux-Controllers |
| `cleanup.json` | Cleanup | optionaler Nachweis einer abgeschlossenen exakten Bereinigung |

Jede JSON-Datei hat ein geschlossenes Schema, maximale Größe, `schema`, `protocol`, `instance_id`, `generation` und `run_token`. Unbekannte Versionen, falsche Typen, zusätzliche sicherheitsrelevante Felder, Tokenabweichungen oder zu große Dateien werden als Protokollfehler behandelt. Remote-Zeitstempel dienen nur der Anzeige; DB-Entscheidungen verwenden UTC aus MySQL und lokale monotone Laufzeitmessung.

### 6.5 Runner-Vertrag

Der Runner ist ein kleines, repository-eigenes Python-3-Programm, kein dauerhafter Agent und kein Netzservice. Er wird im Offline-Bundle ausgeliefert und checksum-geprüft installiert. Er:

- nimmt nur validierte Dateipfade und keinen freien Shellstring an;
- setzt ungepufferte Ausgabe;
- startet `ansible-playbook` als Child in derselben systemd-Cgroup;
- schreibt den Started-Marker dauerhaft, bevor das Child erzeugt wird;
- drainiert stdout/stderr fortlaufend;
- begrenzt die gespeicherte Logmenge, verwirft nach dem Limit weitere Ausgabe kontrolliert und schreibt einmalig einen Truncation-Marker, ohne den Child wegen einer vollen Pipe zu blockieren;
- fängt TERM ab, reicht es an die Prozessgruppe weiter, wartet begrenzt und dokumentiert Signal/Exitcode;
- wartet auf alle direkten Children;
- schreibt das Resultat zunächst in eine neue Datei, `fsync`-t Datei und Verzeichnis und benennt atomisch um;
- schreibt niemals Zugangsdaten, `accounts.yml`-Inhalt oder freie Umgebungsvariablen in Manifest oder Resultat.

### 6.6 systemd-Unit-Vertrag

Ein Unitname folgt ausschließlich einem geschlossenen Format wie:

```text
virtusphere-j115-a1-s03-7bb15d9069a5cac1.service
```

Die genaue Länge, erlaubte Zeichen und Ableitung liegen in einer PHP-SSoT und werden im Launcher erneut validiert. Empfohlene Properties:

- `Type=exec`
- `KillMode=control-group`
- `TimeoutStopSec=<SSoT>`
- `RuntimeMaxSec=<schrittspezifische SSoT>`
- `TasksMax=<gemessener, geprüfter Grenzwert>`
- `MemoryMax=<gemessener, geprüfter Grenzwert>`
- restriktive `UMask=0077`
- keine Privilegienerhöhung

Konkrete Ressourcenwerte werden nicht geraten. Etappe 2 erhebt reale Prozess-/Speichermaxima in Staging und setzt Grenzwerte mit dokumentiertem Headroom. Zu enge Grenzen wären selbst eine neue Fehlerquelle.

Ein Erreichen von `RuntimeMaxSec`, `MemoryMax` oder ein erzwungener systemd-Stop beweist bei einem mutierenden Playbook nur das Ende des Linux-Controllers. Der Remote-Schritt wechselt danach in `uncertain` und durchläuft die modusspezifische Versöhnung; er wird nicht allein wegen des systemd-Resultats als fachlich fehlgeschlagen und retryfähig markiert.

## 7. Persistentes Daten- und Lease-Modell

### 7.1 Erweiterung `deploy_jobs`

Additiv werden mindestens benötigt:

| Feld | Zweck |
|---|---|
| `lock_token CHAR(32) NULL` | zufälliges Fencing-Token des aktuellen Jobbesitzers |
| `worker_epoch BIGINT UNSIGNED NULL` | globale Worker-Generation, die alte Worker nach Leasewechsel sperrt |
| `recovery_count INT UNSIGNED NOT NULL DEFAULT 0` | Wiederaufnahmen desselben Attempts, getrennt von `attempts` |
| `recovery_requested_at TIMESTAMP NULL` | Zeitpunkt, ab dem ein recoverable aktiver Job vorrangig geclaimt wird |

`attempts` bleibt die Zahl fachlicher Erstübernahmen/Retry-Versuche. Eine Wiederaufnahme derselben Remote-Ausführung erhöht nicht `attempts`, sondern `recovery_count`.

Alle mutierenden Worker-Repositories erhalten den CAS-Vertrag:

```text
job_id + active status + locked_by + lock_token + worker_epoch
```

Ein alter Worker, dessen Lease entzogen wurde, darf weder Heartbeat noch Log noch Status noch nächsten Remote-Schritt schreiben. Ein bereits laufender Remote-Schritt wird nicht dupliziert; der neue Worker bindet ihn über das Handle wieder an.

### 7.2 Neue Tabelle `deploy_remote_executions`

Empfohlene Felder:

- `id BIGINT UNSIGNED` Primärschlüssel;
- `job_id BIGINT UNSIGNED` mit Fremdschlüssel;
- `job_attempt INT UNSIGNED`;
- `step_key VARCHAR(64)`;
- `protocol_version TINYINT UNSIGNED`;
- `run_token CHAR(32) COLLATE ascii_bin`;
- `unit_name VARCHAR(128) COLLATE ascii_bin`;
- `remote_dir VARCHAR(512)`;
- `state VARCHAR(24)`;
- `launch_intent_at`, `started_at`, `last_observed_at`, `finished_at`;
- `remote_exit_code SMALLINT NULL`, `remote_signal VARCHAR(16) NULL`;
- `result_sha256 CHAR(64) NULL`;
- `log_offset BIGINT UNSIGNED NOT NULL DEFAULT 0`;
- `log_truncated TINYINT(1) NOT NULL DEFAULT 0`;
- `recovery_count INT UNSIGNED NOT NULL DEFAULT 0`;
- `last_probe_category VARCHAR(32) NULL`, `last_probe_detail VARCHAR(512) NULL`;
- `cleanup_state VARCHAR(16)`, `cleanup_after`, `cleaned_at`;
- `created_at`, `updated_at`.

Erforderliche Eindeutigkeiten:

- `UNIQUE(job_id, job_attempt, step_key)`;
- `UNIQUE(run_token)`;
- `UNIQUE(unit_name)`.

Die Spalte `state` ist bewusst `VARCHAR`, kein neuer MySQL-ENUM-Spiegel. Erlaubte Werte und Übergänge liegen in `lib/deploy_remote_execution_constants.php`; alle Writes gehen durch das Repository und Contracttests prüfen Schema-Länge, Producer, Consumer und vollständige Übergangsmatrix.

### 7.3 Globale Worker-Lease

Eine Singleton-Tabelle oder äquivalente persistente Lease speichert:

- festen Leasenamen `deploy-worker`;
- monoton steigende `epoch`;
- zufälliges `owner_token`;
- `lease_until` nach MySQL-UTC;
- letzten Renew-Zeitpunkt.

Der Supervisor/Worker erwirbt die Lease transaktional. Nach Ablauf plus Beobachter-Grace darf eine neue Instanz die Epoch erhöhen. Jeder mutierende Pfad prüft die Epoch erneut, bevor er einen neuen Remote-Schritt startet. Dadurch können versehentlich zwei Compose-Replikate oder ein wiederkehrender alter Worker keine parallelen Jobs beginnen.

Ein MySQL-Advisory-Lock allein wird verworfen: Er hängt nur an einer Connection, liefert kein dauerhaftes Fencing-Token und ist nach Reconnect nicht ausreichend für einen noch laufenden Remote-Schritt.

### 7.4 Claim-Pause

Die persistente Claim-Freigabe liegt in `deploy_settings`, nicht in Compose oder einer PHP-Session:

- Schlüsselkonstante `deploy_claim_paused` mit booleschem, streng validiertem Wert;
- optionaler technischer Zeitpunkt; Akteur und Änderungshistorie ausschließlich im Auditlog;
- Worker prüft die Pause vor jedem neuen Claim, nicht mitten im aktuellen Schritt;
- Recovery eines bereits aktiven Jobs hat Vorrang und wird auch während der Pause fortgesetzt, weil Pause keine Fernprozesse verwaisen lassen darf.

### 7.5 Instanz und Restore-Generation

Manifest und Remote-Pfad tragen:

- eine stabile Installations-ID;
- eine monoton erhöhte Remote-Ausführungsgeneration.

Die Installations-ID ist ein nicht geheimes, beim Setup einmal erzeugtes `VIRTUSPHERE_INSTANCE_ID` in `.env`; Setup, EnvBoot, Backup und Offline-Bundle prüfen Format und Eindeutigkeit. Eine aus einem Backup geklonte zweite aktive Installation muss vor dem Start eine neue ID erhalten. Die Generation ist ein systemverwalteter, nicht frei editierbarer `deploy_settings`-Wert mit Konstantenschlüssel.

Ein Restore-Runbook erhöht die Generation nach dem Wiederherstellen einer alten DB, bevor neue Claims erlaubt werden. Dadurch kollidieren zurückgedrehte Job-IDs/Attempts nicht mit Units aus der Zukunft des Backups. Units einer fremden Generation werden nur inventarisiert und als Klärfall gezeigt; sie werden nicht pauschal gelöscht.

## 8. Zustandsautomaten

### 8.1 Remote-Ausführung

Erlaubte Zustände:

- `prepared`: DB-Handle steht, Remote-Start ist noch nicht bewiesen;
- `active`: Started-Marker oder aktive passende Unit ist bewiesen;
- `recovery_pending`: bisheriger Worker ist nicht mehr berechtigt; dieselbe Ausführung muss beobachtet werden;
- `succeeded`: Linux-Controller lieferte gültiges Ergebnis 0;
- `failed`: Linux-Controller lieferte gültigen Fehler/Signal;
- `uncertain`: Mutation könnte stattgefunden haben, aber Controllerresultat und Livezustand reichen noch nicht für Erfolg/Fehler;

Cleanup ist orthogonal: `pending`, `eligible`, `running`, `cleaned`, `failed`.

Erlaubte Übergänge:

```text
prepared -> active | recovery_pending | uncertain
active -> succeeded | failed | recovery_pending | uncertain
recovery_pending -> active | succeeded | failed | uncertain
succeeded | failed | uncertain -> keine fachliche Rücktransition
```

Eine spätere fachliche Versöhnung ändert nicht rückwirkend das Remote-Controllerresultat. Sie finalisiert den Job und schreibt einen eigenen Reconciliation-Nachweis.

### 8.2 Job und Recovery

Der Job bleibt:

- `running`, wenn die ursprüngliche Ausführung oder Wiederherstellung aktiv ist;
- `cancelling`, wenn keine neue Einheit mehr begonnen werden darf, eine laufende Einheit aber noch beobachtet wird;
- aktiv ohne `locked_by`, wenn der Reaper die alte Lease gelöst und `recovery_requested_at` gesetzt hat.

Der Worker claimt in dieser Reihenfolge:

1. recoverable `running`/`cancelling` ohne gültige Lease;
2. fällige Inventar-Recovery mit bestehendem Handle;
3. normale fällige `queued`-Jobs nach bestehender Priorität.

### 8.3 Reaper-Entscheidungsmatrix

| Staler Job | Remote-Evidenz | Neue Entscheidung |
|---|---|---|
| noch kein Remote-Handle | keine Mutation möglich | wie bisher sicher `failed` beziehungsweise `cancelled` |
| `prepared`, Remote-Pfad/Started fehlen | Start hat beweisbar nicht begonnen | Recovery darf denselben vorbereiteten Start fortsetzen |
| `active`, Unit läuft | Mutation/Leselauf läuft | Lease lösen, `recovery_pending`, Job aktiv lassen |
| Unit weg, gültiges `result.json` vorhanden | Controller terminal | Recovery-Worker importiert Restlog und finalisiert fachlich |
| Started vorhanden, keine Unit, kein Resultat | Host-/Controllerverlust nach möglichem Start | `uncertain`, modusspezifische Versöhnung, kein blindes Retry |
| Manifest/Token widersprüchlich | Integritätsfehler | `uncertain`, Sicherheitsereignis, keine Ausführung/kein Cleanup |
| Legacy-Job ohne Handle aus Upgradezeit | Ausgang nicht beweisbar | als `legacy_uncertain` auditieren, Retry sperren, geführte Klärung |

Der Wartungs-Worker führt keine SSH-Verbindung aus und erhält keine Deploy-Zugangsdaten. Er trifft ausschließlich transaktionale DB-Entscheidungen. Die Remote-Beobachtung bleibt beim Deploy-Worker.

### 8.4 VM-Konvergenzsweep

`repo_sweep_orphaned_deploying_vms()` darf eine VM nicht auf `failed` setzen, solange ein Job aktiv, recovery-pending oder fachlich ungeklärt ist. Für Create sind die per-VM-Ergebnisse aus dem Create-Plan maßgeblich. Für andere Modi wird keine VM-Lifecycle-Wahrheit aus einem fehlenden Workerheartbeat erfunden.

## 9. Crash- und Netzwerkfenster

| Ausfallzeitpunkt | Beweis nach Neustart | Verhalten |
|---|---|---|
| vor DB-`prepared` | keine Zeile | normal neu vorbereiten |
| nach DB-`prepared`, vor Upload | Handle ohne Remote-Pfad | denselben Run vervollständigen |
| nach Manifest, vor `systemd-run` | Manifest passt, kein Started, keine Unit | denselben Launcher idempotent erneut aufrufen |
| SSH-Antwort während `systemd-run` verloren | passende Unit oder Started/Result | an dieselbe Ausführung binden |
| Runner startete, vor Ansible-Spawn abgestürzt | Started, Unit terminal, Result mit Runnerfehler | kein ESXi-Start; bestätigt fehlgeschlagen, erlaubte Wiederholung nach Policy |
| mitten in Ansible, Worker stirbt | Unit aktiv, Remoteheartbeat/Log wächst | neuer Worker reattach, kein Retry |
| Ansible fertig, vor DB-Finalisierung | Result und Log vorhanden | vollständig importieren, genau einmal finalisieren |
| Ansible-Host rebootet mitten in Mutation | Started, Unit weg, kein Result | `uncertain`, Live-Reconciliation |
| DB fällt aus, Remote läuft | Unit/Runner unabhängig | Worker spoolt begrenzt lokal/remote und reattacht nach DB-Rückkehr |
| SSH fällt aus, DB lebt | Remote läuft | kurze Probe mit Backoff; Jobheartbeat bleibt als Recoverybeobachtung ehrlich, nicht als behauptete Remote-Ausgabe |
| Container manuell gestoppt | Docker respektiert Stop | keine automatische Wiederbelebung; Portal zeigt Dienst offline, aktive Remote-Unit bleibt recoverable nach bewusstem Start |
| Docker-Daemon/Ubuntu-Portalhost fällt aus | Remote systemd-User-Unit läuft weiter | nach Host-/Containerwiederkehr Reattach über DB-Handle |

## 10. Modusspezifische Wiederherstellung

Eine generische Regel `noch einmal starten` ist verboten. Die Policy wird aus `ansible_playbooks_for_mode()` und einer dazu vollständigen Step-Policy-Registry abgeleitet; keine zweite lose Modusliste.

| Schritt/Modus | Mutation | Recoveryregel |
|---|---:|---|
| Inventar | nein | dieselbe Unit reattachen; bei nachweislich totem Controller darf der Read erneut laufen |
| Export MACs | überwiegend lesend, aber Callback schreibt DB | Result/Callback anhand Job-ID idempotent übernehmen; bei fehlendem Result Live-Inventar erneut lesen, niemals fremden Job akzeptieren |
| Create | ja, Identitätsbildung | Create-Plan: persistierte Einheit, JID, Marker, UUID/MOID und Live-Reconciliation; nie namenbasiert neu erstellen |
| Start | ja, idempotenter Sollzustand `poweredOn` | erst Unit/Taskende klären, dann UUID-gebundenen Live-Powerstate lesen; nur fehlende Sollzustände erneut konvergieren |
| Powercycle | ja, mehrphasig | Ausgangspowerstate und die vom Job gestarteten/gestoppten VM-IDs vor jeder Phase persistieren; Recovery setzt Phasenmarker fort und stellt abschließend den vertraglichen Zustand her |
| Autostart | ja, deklarative Hostkonfiguration | gewünschte Policy materialisieren, Livepolicy lesen und Abweichungen UUID-/Key-gebunden erneut anwenden; HA-/Lizenzgate erneut prüfen |
| Full | mehrere Schritte | letzte bewiesene Schrittgrenze und modusspezifische Policy verwenden; nie die ganze Pipeline von vorn starten |

Zusätzliche Regeln:

- `Another task is already in progress` bedeutet zunächst `weiter beobachten`, nicht `failed und retry`.
- Ein Callback zu einem recovery-pending `running`/`cancelling`-Job bleibt nach bestehender Job-/Mission-/VM-Bindung zulässig.
- Ein Callback nach fachlich terminalem Job bleibt abgelehnt.
- Ein Retry-Button wird ausgeblendet oder gesperrt, solange Remoteausführung oder Reconciliation nicht abgeschlossen ist.
- Zugangsdaten dürfen während aktiver/recovery-pending Ausführung weder gelöscht noch so editiert werden, dass die Wiederaufnahme ihre SSH-/ESXi-Identität verliert. Kurzfristig wird Edit/Delete gesperrt; eine spätere Credential-Versionierung ist eine mögliche eigene ADR, aber keine Voraussetzung.

## 11. Lokales Worker-Self-Healing

### 11.1 Minimaler PID-1-Supervisor

Der Container startet künftig einen kleinen `deploy_worker_supervisor.php` als Hauptprozess. Dieser:

- startet den bestehenden Worker als Kindprozess;
- führt selbst keine Ansible-/SSH-Arbeit aus;
- liest einen lokalen Kindheartbeat und Prozessstatus ohne blockierende Remote-Aufrufe;
- leitet SIGTERM/SIGINT sauber weiter;
- beendet einen mehrfach bestätigten hängenden Kindprozess zuerst mit TERM, nach einer Frist mit KILL;
- startet nach Cooldown ein neues Kind, das über DB-Handle reattacht;
- beendet sich bei eigenem fatalen Fehler ungleich null, damit Dockers vorhandene Restartregel greift;
- begrenzt Wiederstarts in einem Zeitfenster und geht bei Wiederholung in einen zeitlich begrenzten Cooldown statt in eine heiße Restartschleife.

Der Supervisor wird erst aktiviert, nachdem Durable Remote Execution für den betreffenden Modus bewiesen ist. Sonst würde ein lokaler Neustart die Gefahr doppelter entfernter Arbeit erhöhen.

### 11.2 Heartbeats werden getrennt

- Supervisor schreibt den Integrationseintrag `deploy-worker` und ein versioniertes, begrenztes `last_summary` mit `supervisor_state`, `child_state`, `next_retry_at`, `current_job_id` und Zählern.
- Worker-Kind schreibt ausschließlich Job-/Leaseheartbeat und seinen lokalen Kindheartbeat.
- Der Container-Healthcheck prüft die Reaktionsfähigkeit des Supervisors. Geschäftsbereitschaft wird im Portal abgeleitet.
- `healthy` bei bewusst pausierter Queue ist korrekt: Supervisor funktioniert, aber der Dienstzustand ist `paused`.
- Ein hängender Kindprozess wird normalerweise geheilt, bevor der Container dauerhaft `unhealthy` werden kann.

### 11.3 Bewusste Grenze

Ein ausgefallener Docker-Daemon, ein komplett abgestürzter Ubuntu-Portalhost, eine zerstörte MySQL-Instanz oder ein absichtlich gestoppter Container kann nicht durch Anwendungscode im gestoppten Container geheilt werden. Diese Infrastrukturgrenze wird im Systemstatus ehrlich benannt. Der Plan beseitigt den Bedarf an Ubuntu-Zugriff für normale Worker-/SSH-/DB-Kanalstörungen, nicht für den Verlust der gesamten Plattform.

## 12. Portal-QoL und intuitiver Arbeitsablauf

### 12.1 Ein gemeinsamer Deploy-Dienstzustand

Ein neuer reiner Presenter `deploy_service_health_snapshot()` berechnet aus denselben Quellen für Dashboard, Deployseite, Systemstatus und Healthendpoint genau einen Zustand:

- `ready`: Worker bereit, keine blockierte fällige Queue;
- `busy`: mindestens ein normal laufender Job;
- `recovering`: aktive Wiederaufnahme oder automatische Versöhnung;
- `paused`: Claim-Sperre aktiv;
- `degraded`: Worker/Ansible-Voraussetzung gestört, aber automatische Probe läuft;
- `cooldown`: Supervisor wartet nach wiederholtem Fehler bis zum nächsten Versuch;
- `offline`: Supervisorheartbeat fehlt;
- `manual_review`: mindestens ein nicht automatisch beweisbarer Mutationsausgang.

Diese Werte sind eine PHP-Konstanten-SSoT mit Zuordnung zu vorhandenen Badge-Schweregraden. Sie sind keine DB-Jobstati und keine Machine-API-Felder.

### 12.2 Deployseite vor dem Einreihen

Oberhalb des Formulars steht eine kompakte Dienstmeldung:

- bereit: keine zusätzliche Warnung;
- pausiert: `Der Auftrag wird gespeichert und startet nach der Freigabe.`;
- Recovery: `Der Dienst stellt Auftrag #… wieder her. Neue Aufträge warten sicher in der Warteschlange.`;
- offline/degraded: `Der Auftrag wird gespeichert. Der Bereitstellungsdienst ist derzeit nicht bereit und prüft sich automatisch erneut.`;
- manual review derselben Mission: konkreter Missionsblocker mit Link zum betroffenen Job.

Der Submitbutton wird nicht wegen eines bloß gestörten Workers deaktiviert. Wird er aus einem fachlichen Grund deaktiviert, existiert für jeden Blocker genau eine konkrete Meldung und, soweit berechtigt, der Link zur Reparaturstelle.

### 12.3 Rückmeldung nach dem Einreihen

`Bereitstellungsauftrag eingereiht` wird durch kontextabhängige, aus derselben Snapshot-SSoT abgeleitete Meldungen ersetzt:

- `Auftrag #115 ist gespeichert und wartet an Position 2.`
- `Auftrag #115 ist für 14.08.2026 08:00:00 geplant.`
- `Auftrag #115 ist gespeichert. Der Dienst ist pausiert und startet ihn nach der Freigabe.`
- `Auftrag #115 ist gespeichert. Automatische Wiederherstellung läuft; neue Aufträge bleiben erhalten.`

Queueposition ist eine Momentaufnahme und wird ausdrücklich so bezeichnet. Geplante Jobs vor ihrem Zeitpunkt zählen nicht als blockierte fällige Queue.

### 12.4 Jobliste

Zusätzliche, abgeleitete Spalten/Zeilen ohne Rohtechnik für normale Benutzer:

- `Wartet seit` oder `Geplant für`;
- `Grund`: geplant, Position, pausiert, vorheriger Missionsjob aktiv, Dienst nicht bereit;
- `Aktueller Schritt` aus der Playbook-SSoT;
- `Ausführung`: normal, wird wiederhergestellt, Ausgang wird geprüft;
- `Nächster automatischer Versuch` bei Cooldown/Probe;
- direkte Logverlinkung ausschließlich über `deploy_job_log_url()`.

Farben werden durch Text und Icon ergänzt. Zeitstempel laufen über `portal_format_timestamp()`.

### 12.5 Jobdetail und Log

Am Kopf des Joblogs steht eine Statuskarte mit:

- fachlichem Jobstatus;
- verständlichem Ausführungszustand;
- aktuellem Schritt und Einheit `n/total`, falls bekannt;
- letzter bestätigter Beobachtung;
- `Was passiert als Nächstes?`;
- bei Recovery: `Keine Aktion erforderlich`, solange die automatische Frist läuft;
- bei manual review: genaue, sichere Handlung, ohne pauschales `neu einreihen`.

Technische Felder wie Unitname, Remote-Pfad, Run-Token, Hostname oder Fehlerdetail werden nur für `system.config`/vergleichbar berechtigte Rollen in einem aufklappbaren Diagnoseblock gezeigt. Tokens werden auch dort gekürzt und niemals als ausführbarer Shellbefehl angeboten.

Der Master-/Create-Plan für den vollständigen Logtail wird verbindlich mit umgesetzt:

- API liefert `has_more` und nächsten Sequenzcursor;
- terminale Jobs werden bis `has_more=false` leergelesen;
- ältere Zeilen sind paginierbar, Rohdownload RBAC- und größenbegrenzt;
- DOM bleibt begrenzt;
- versteckte Tabs pausieren Polling, beim Sichtbarwerden erfolgt sofortiger Cursor-Nachlauf;
- eine `role=status aria-atomic=true`-Region meldet Zustandswechsel, nicht jede Logzeile.

### 12.6 Systemstatus: neue Karte `Bereitstellungsdienst`

Die bisherige einzelne Heartbeatzeile wird zu einer fachlichen Karte erweitert:

| Zeile | Aussage |
|---|---|
| Dienstzustand | ready/busy/recovering/paused/degraded/cooldown/offline/manual review in lokalisierter Sprache |
| Aufsicht zuletzt gesehen | Supervisorheartbeat |
| Worker | Kindzustand und letzter Jobheartbeat |
| Warteschlange | fällig, geplant, ältester fälliger Job |
| Aktiver Auftrag | Job, Mission, Schritt und berechtigter Deep-Link |
| Remote-Ausführung | aktiv/wiederangebunden/unklar, letzte Probe |
| Automatik | Recoveryzähler und nächster Versuch |
| Wartungsfreigabe | läuft oder nach aktuellem Auftrag pausiert |

Portalaktionen:

- `Nach aktuellem Auftrag pausieren`: `system.config`, bestätigte Formaktion, Audit; setzt nur Claim-Pause.
- `Bereitstellung fortsetzen`: `system.config`, klassifizierte sichere Aktion oder Bestätigung gemäß PortalConfirmContract.
- `Wiederherstellung jetzt prüfen`: legt idempotent eine Recoveryanforderung in der DB an; kein SSH im HTTP-Request.
- Kein allgemeiner `Force kill`- oder `Force release`-Button in der ersten Umsetzung.

Ordinary users sehen Ursache und Auswirkung, aber keine Hostadministrationsanweisung. Admins sehen zusätzlich verlinkte Prüfprotokolle und den Runbook-Fallback.

### 12.7 Dashboard

Nur handlungsrelevante Hinweise werden hochgezogen:

- `recovering`: Info-/Warnbanner mit Link zum Job, keine rote Alarmierung;
- `paused` plus fällige Queue: Info mit Wartemenge;
- `offline/degraded` plus fällige Queue: Warnung;
- `manual_review`: klarer Warn-/Fehlerhinweis für berechtigte Betreiber;
- keine Warnung für ausschließlich zukünftig geplante Jobs.

Das Dashboard dupliziert keine Abfrage. Es konsumiert denselben `deploy_service_health_snapshot()`.

### 12.8 Healthendpoint

`portal/health.php` bleibt ein knappes, nicht sensibles Maschinenformat:

- DB-Ausfall bleibt `503`;
- interner Deploy-Dienstfehler bleibt wie bisher ein `200` mit `status=degraded`, damit Loadbalancer das Portal nicht wegen eines Hintergrunddienstes aus dem Verkehr nehmen;
- degraded wird zusätzlich ausgelöst durch fehlenden Supervisorheartbeat, eine über Grace fällige unbearbeitete Queue oder `manual_review`;
- keine Job-ID, Mission, Host, Unit oder Fehlerdetails im anonymen Endpoint.

## 13. Protokoll- und Beobachtungsmodell

### 13.1 Eine Wahrheit pro Zweck

| Zweck | SSoT | Retention/Anzeige |
|---|---|---|
| fachlicher Jobstatus | `deploy_jobs` | Jobliste/Jobdetail |
| laufender Remote-Schritt | `deploy_remote_executions` | Jobdetail/Systemstatus |
| vollständige Joberzählung | `deploy_job_logs` | Joblog, bestehende Retention |
| aktueller Workerzustand | `deploy_integration_heartbeats` Quelle `deploy-worker` | Systemstatus-Snapshot |
| historische Betriebs-/Recoveryereignisse | strukturiertes `deploy_logs` gemäß Masterplan 10C | Protokolle, Kategorien `deploy`/`system` |
| Create-Ergebnis pro VM | `deploy_create_vm_results` aus Create-Plan | Fortschrittskarte/Reconciliation |
| tatsächlicher ESXi-Zustand | Liveinventar plus vorhandener Beobachtungscache | Systemstatus/Mission |

Container-stdout bleibt Tiefendiagnose für Infrastrukturadministratoren. Es wird nicht als zweite Portal-Logdatenbank gespiegelt.

### 13.2 Stabile Eventcodes

Mindestens:

- `deploy.remote.prepared`
- `deploy.remote.started`
- `deploy.remote.reattached`
- `deploy.remote.result`
- `deploy.remote.uncertain`
- `deploy.remote.cleanup`
- `deploy.worker.child_stale`
- `deploy.worker.child_restarted`
- `deploy.worker.cooldown`
- `deploy.recovery.requested`
- `deploy.recovery.resolved`
- `deploy.recovery.manual_review`
- `deploy.claims.paused`
- `deploy.claims.resumed`

Eventcodes bleiben technische Wirewerte und werden nicht lokalisiert. Das Portal mappt sie über DE-/EN-Kataloge. Übergangsereignisse werden nur bei Zustandswechsel geschrieben; Polls und Heartbeats erzeugen keinen Logspam.

### 13.3 Sicherheitsvertrag

- Geheimnisse, private Schlüssel, `accounts.yml`, Tokens, Cookies und freie Ansible-Variablen werden redigiert.
- CR/LF und Steuerzeichen in fremder Ausgabe werden vor strukturierten Logs neutralisiert.
- Rohlogs sind berechtigungs- und größenbegrenzt.
- Unitnamen, Pfade und Eventfelder werden aus internen IDs konstruiert, nicht aus Benutzertext.
- Resultate werden gegen Run-Token, Manifestchecksumme, erwarteten Step und Job geprüft.
- Ein fremdes oder manipuliertes Remote-Verzeichnis wird nicht ausgeführt und nicht automatisch gelöscht.
- Das Portal erhält weder Docker-Socket noch Systemd-DBus noch SSH-Key des Deploy-Workers.

### 13.4 QoL der Seite `Protokolle`

Die Protokollseite wird nicht zu einem Containerlog-Terminal. Sie macht strukturierte Anwendungsereignisse auffindbar:

- neuer Schnellfilter `Bereitstellung und Wiederherstellung` auf den bestehenden, kanonischen Kategorien;
- validierte Filter für Job-ID, Mission, Eventcode, Schweregrad, Ergebnis und Korrelations-ID;
- Umschalter `Nur Handlungsbedarf`, der `manual_review`, Integritätsfehler und ausgeschöpften Cooldown zeigt, nicht normale Poll-/Heartbeataktivität;
- zusammengehörige Ereignisse erscheinen als chronologische Gruppe mit verständlicher lokalisierter Zusammenfassung und aufklappbaren technischen Details;
- Job-ID verlinkt über `deploy_job_log_url()`, Kategorie über `log_category_url()`;
- falls Filter-Deep-Links benötigt werden, wird der bestehende URL-Builder um validierte optionale Filter erweitert, statt Querystrings in Seiten zu duplizieren;
- Systemstatus verlinkt direkt auf die zu seinem aktuellen Befund passende gefilterte Sicht;
- CSV übernimmt aktive Filter, kennzeichnet die bestehende Kappung sichtbar und exportiert keine nur im UI verborgenen Secrets;
- leere Treffer erklären, ob Filter oder Retention die Ursache sein können, und bieten `Filter zurücksetzen`;
- Retention bleibt aus ADR-0026/den vorhandenen Konstanten abgeleitet, nicht als zweite Zahl im Hilfetext.

Auf der Systemstatuskarte stehen nur der aktuelle Zustand und die letzten wenigen Zustandswechsel. Die vollständige Historie bleibt unter `Protokolle`; das verhindert eine zweite Loganzeige mit eigener Filter-/Retentionlogik.

## 14. SSoT- und Drift-Vertrag

| Domäne | Verbindliche SSoT | Verbotene Kopie |
|---|---|---|
| Jobstatusmengen | bestehende Deploy-Konstanten | JS-/Portal-Sonderliste |
| Modus zu Playbooks | `ansible_playbooks_for_mode()` | Recovery-Modusliste |
| Step-Key/Label/Policy | neue Registry, aus Playbook-SSoT vollständig geprüft | Dateiname in Portaltext |
| Remote-Zustände/Übergänge | `deploy_remote_execution_constants.php` | SQL-ENUM/lose Stringvergleiche |
| Worker-Servicezustand | `deploy_service_health_snapshot()` | eigene Dashboard-/Health-Abfragen |
| Workerheartbeatgrenzen | Konstanten/Defaults | Magic Seconds in SQL/Text |
| Claim-Pause | `deploy_settings` plus Schlüsselkonstante | Compose-Stop als Appzustand |
| Installationsidentität | `VIRTUSPHERE_INSTANCE_ID` aus `.env`, einmalig durch Setup | Hostname, Compose-Projektname oder DB-ID |
| Restore-/Remotegeneration | systemverwalteter `deploy_settings`-Wert | Job-ID oder Zeitstempel |
| Remote-Unitname/Pfad | ein Builder plus Launcher-Validator | Shellstring in Worker |
| Logkategorie/Deep-Link | bestehende Taxonomie und `log_category_url()` | handgeschriebene Query |
| Joblog-Link/Origin | `deploy_job_log_url()` / `deploy_job_origin_url()` | rohes `deploy_log.php?id=` |
| Anzeigezeit | `portal_format_timestamp()` | rohe MySQL-Zeit |
| Rechte | `can()` und Permissions-SSoT | Rollenvergleich |

Neue/erweiterte Guards:

- Remote-State-Registry gegen alle Producer/Consumer und DB-Spaltenlängen;
- Step-Policy-Registry vollständig gegen `ansible_playbooks_for_mode()`;
- Unit-/Remote-Pfad-Builder PHP und Python mit gemeinsamen Golden Vectors;
- jeder Jobwrite enthält `lock_token` und `worker_epoch`;
- Reaper darf Remote-active/recovery-pending nicht terminalisieren;
- Health, Dashboard, Deployseite und Systemstatus rufen denselben Snapshot auf;
- keine Portaldatei enthält `docker compose`, `systemctl`, `kill`, Docker-Socket oder freien SSH-Aufruf;
- Doku-Semantikguard gegen falsche Aussagen wie `unhealthy startet automatisch neu`, `Timeout beendet Ansible/ESXi`, `einfach neu einreihen` und `Reaper hat den Remoteprozess beendet`;
- Frischschema und Migration bleiben wortgleich;
- DE/EN- und Placeholderparität;
- CSS-Klassen-, Modalachsen-, Bestätigungs-, Deep-Link- und Dateigrößenverträge.

## 15. Lücken- und Edge-Case-Prüfung

| Edge Case | Risiko | Verbindliche Behandlung |
|---|---|---|
| zwei Workercontainer | parallele Claims/Doppelmutation | globale Epoch-Lease und Job-Fencing |
| PID-/Unitname-Wiederverwendung | falscher Prozess wird beobachtet/beendet | zufälliger Run-Token, Generation, Manifestbindung, eindeutiger Unitname |
| SSH-Antwort verloren | Start wird doppelt gesendet | idempotenter Launcher, Started/Unit/Result prüfen |
| Hostreboot vor Started-Marker | unklar, ob Mutation begann | Runner schreibt Started vor Child; fehlt er, kein Ansible-Spawn |
| Hostreboot nach Started | vSphere-Task kann weiterlaufen | `uncertain`, Live-Reconciliation, kein Blindretry |
| systemd-Unit durch GC verschwunden | Prozessende ohne Unitinfo | persistentes Resultat/Started ist maßgeblich |
| Linger deaktiviert | Unit stirbt mit Session/bootet nicht | harter Preflightblocker, Installer repariert |
| DB-Uhr und Remote-Uhr abweichend | falsche Stale-Entscheidung | MySQL-UTC für Leases, monotone Laufzeit lokal; Remotezeit nur Anzeige |
| DB-Ausfall länger als Lease | neuer Worker könnte übernehmen | Epoch-Fencing; alter Worker prüft vor jeder neuen Mutation |
| alter Worker kehrt zurück | schreibt nach neuer Lease | CAS lehnt jeden Write und nächsten Step ab |
| Callback während Recovery | Ergebnis geht verloren | aktive Statusbindung bleibt gültig, idempotente Job-/VM-Zuordnung |
| Callback nach Terminalstatus | fremde/späte Mutation | bestehend ablehnen und auditieren |
| Credential wird editiert/gelöscht | Recovery verliert Zugriff | aktive/recovery-pending Referenzen sperren Edit/Delete |
| Passwort läuft ab | Remote läuft, Poll scheitert | Unit weiterlaufen lassen, Portal zeigt Zugangsstörung; nach Credentialreparatur reattach |
| Ansible-Core zu alt | Async-/Collectionvertrag falsch | harter Versionspreflight, kein Legacy-Fallback |
| Runner-Version passt nicht | Protokolldrift | Schema/Checksumme blockiert Start, vorhandene alte Runs bleiben lesbar |
| Remoteplatte voll | Marker/Log/Result fehlen | Preflight Freiplatz, größenbegrenztes Log, Resultatfehler -> uncertain/reconcile |
| Log > Limit | Diskfüllung/Browserlast | Runner drainiert weiter, einmalige Truncation, Job läuft weiter |
| ungültiges UTF-8/ANSI | kaputte UI/Log injection | Chunkdecoder, Sanitizing, Rohdownload klar gekennzeichnet |
| Secret in Ansible-Ausgabe | Datenleck | `no_log`, Redaction, Sentineltests, kein `-vvv` im Normalbetrieb |
| Symlink im Remote-Pfad | Traversal/Löschen fremder Daten | `lstat`, Owner-/Mode-/Rootprüfung, fail closed |
| Cleanup und Recovery gleichzeitig | Beweis wird gelöscht | Cleanup-Lease, Terminal- plus Fristprüfung, CAS |
| Job gelöscht während aktiv | Handle verwaist | bestehende Guards plus FK; keine Löschung bei aktiv/recovery |
| Mission umbenannt | Logs/Handle nicht auffindbar | nur IDs im Handle; Anzeige liest aktuellen Namen getrennt |
| nur geplante Queue | falsche Degraded-Warnung | `due` getrennt von `scheduled` zählen |
| Queue pausiert bei laufendem Job | harter Abbruch | Pause wirkt erst vor nächstem Claim, Recovery immer erlaubt |
| Abbruch während Recovery | neuer Step startet | Job bleibt `cancelling`, Recovery beobachtet nur laufende Einheit |
| wiederholter Child-Hang | Restartloop | Cooldown mit nächstem Versuch und Eventrate-Limit |
| Supervisor selbst crasht | Container bleibt tot | PID 1 endet, Docker-Restartregel greift |
| Container manuell gestoppt | unerwünschter Autostart | `unless-stopped` bleibt; Portalpause ist Standardweg |
| Docker-Daemon down | App kann sich nicht starten | klare Infrastrukturgrenze/Runbook, kein falsches Self-Healing-Versprechen |
| DB-Restore auf älteren Stand | zukünftige Units kollidieren | Generation erhöhen, Claims pausieren, fremde Generation inventarisieren |
| Backup während Remote-Mutation | inkonsistenter Restore | Backupstatus warnt; Restore verlangt Pause/Drain/Reconciliation |
| Browser-Tab hidden | unnötige Pollinglast | Page Visibility Pause, Cursor-Catch-up |
| mehr als 1000 terminale Logzeilen | Tail fehlt | `has_more` bis leer, Pagination/Download |
| Status nur farblich | Barriere | Text/Icon plus `role=status aria-atomic=true` |
| normaler Nutzer ohne Ubuntu | keine Handlung möglich | Ursache, automatische Aktion, Portal-Link; Hostschritte nur Adminrunbook |

## 16. Datei- und Modulplan

Die Namen sind Zielverantwortungen; bei Umsetzung gilt der File-Size-Vertrag und vorhandene Modulregistry-Stil.

### 16.1 Neue Kernmodule

- `Docker/WebAPI/lib/deploy_remote_execution_constants.php`
- `Docker/WebAPI/lib/deploy_remote_execution.php`
- `Docker/WebAPI/lib/deploy_remote_protocol.php`
- `Docker/WebAPI/lib/deploy_remote_recovery.php`
- `Docker/WebAPI/lib/deploy_worker_lease.php`
- `Docker/WebAPI/lib/deploy_worker_supervisor.php`
- `Docker/WebAPI/lib/deploy_service_health.php`
- `Docker/WebAPI/lib/repo/deploy_remote_execution.php`
- `Docker/WebAPI/lib/repo/deploy_worker_lease.php`
- `Ansible/runner/virtusphere_remote_runner.py`
- `Ansible/runner/virtusphere_remote_launcher.py` oder ein festes, argumentbasiertes Launcher-Skript
- nächste freie Migration plus identische Frischschemaänderung

### 16.2 Wesentliche Änderungen

- `lib/deploy_worker_loop.php`: globale Lease, Recovery-vor-Queue, Pause.
- `lib/deploy_worker_runtime.php`: getrennte Heartbeats, Fencing.
- `lib/deploy_worker_mission.php` und `deploy_worker_inventory.php`: Remote-Protokoll statt langlebiger SSH-Kette.
- `lib/ansible_command.php`: einzelne Step-Specs, keine freie `&&`-Kette.
- `lib/ssh.php`: kurze Launch/Poll/Read-Aufrufe; Disconnect bleibt Transportereignis.
- `lib/repo/deploy_job_worker.php`: Locktoken/Epoch in allen CAS-Writes.
- `lib/repo/deploy_job_maintenance.php` und `deploy_worker_reaper.php`: neue Matrix.
- `lib/repo/deploy_job_cancel.php`: Recovery-kompatibler Abbruch.
- `lib/worker_heartbeat.php`, `worker_healthcheck.php`, `docker-compose.yml`: Supervisor-Liveness.
- `lib/integration_health.php`, `system_status_page.php`, `system_status_panels.php`: gemeinsame Dienstsicht.
- `portal/deploy.php`, `portal/deploy_log.php`, Deploy-JS/CSS: Wartegrund, Recovery, vollständiger Tail.
- `portal/health.php`: knappe Degraded-Ableitung.
- Credential-Guards/Portal: Edit/Delete-Sperre bei aktiver Recovery.
- Offline-Bundle-/Installerskripte: Runner, Checksumme, Linger und Preflight.

### 16.3 Machine-API-Verträge bleiben unverändert

- kein neues Feld und keine Statusumbenennung in `mecm-api.php`;
- `db_importMAC.php` behält Payload und aktive Jobbindung;
- `mecm_report.php` bleibt display-only;
- der Client-ready-ACK bleibt alleiniger 5/5-Schreiber;
- keine Wiederbelebung der entfernten Token-API.

## 17. Verbindliche Test- und Fault-Injection-Matrix

### 17.1 Unit

- vollständige Remote-State-Übergänge, jede verbotene Kante;
- Unitname/Pfad Golden Vectors PHP/Python;
- Manifest-/Resultparser: Typen, Größen, Versionen, Token, Checksummen;
- Dienstzustands-Priorität einschließlich geplant/fällig/pausiert;
- Backoff, Cooldown, Rate-Limit und Zeitgrenzen;
- modusspezifische Recoveryentscheidung jedes Playbooks;
- Redaction, CR/LF, ungültiges UTF-8, Logtruncation;
- Queuepositions- und `Was passiert als Nächstes?`-Presenter;
- Restore-Generation.

### 17.2 Datenbankintegration

- zwei Worker claimen nie denselben oder parallel einen unerlaubten globalen Job;
- Epochwechsel fenced den alten Worker bei Heartbeat, Log, Stepstart und Finish;
- Recovery erhöht nicht `attempts`;
- Reaper lässt Remote-active aktiv und setzt Recoveryanforderung atomar;
- Resultat zwischen Reaper und Worker wird genau einmal finalisiert;
- Cancel/Reaper/Callback-Races;
- Pause lässt aktiven Job laufen, verhindert neuen Claim, erlaubt Recovery;
- Credential Edit/Delete ist während aktiv/recovery gesperrt;
- Cleanup konkurriert nicht mit Recovery;
- Migration wiederholbar, Frischschema gleich, FK/Unique-Indizes wirksam;
- Healthsnapshot mit leerer, alter und beschädigter `last_summary`.

### 17.3 Remote-Runner-Harness

Mit einem kontrollierten Fake-Ansible-Child:

- normale Ausgabe/Exit 0/Exit ungleich 0/Signal;
- Child mit eigenem Childprozess, Cgroup-Cleanup;
- keine Ausgabe über Idlefrist bei weiterwachsendem Heartbeat;
- Ausgabe oberhalb Limit wird drainiert und markiert;
- TERM, TimeoutStopSec und RuntimeMaxSec;
- Crash vor/ nach Started, vor Result-Rename, nach Result;
- Host-/User-Manager-Neustart soweit im Staging möglich;
- falsche Rechte, Symlink, volle Platte, manipuliertes Manifest;
- gleicher Launch zweimal ergibt genau eine Unit;
- alter Runner wird für bestehenden Run beobachtet, aber nicht für neuen Run verwendet.

### 17.4 Ende-zu-Ende-Fault-Injection

Jeder bekannte Crashpunkt wird als benannter `[n/total]`-Lauf ausgeführt:

1. Worker-Kill vor Prepare.
2. Kill nach Prepare.
3. SSH-Trennung während Launcher.
4. Kill nach Remote-Started vor DB-Active.
5. Kill mitten in stiller Ansible-Ausführung.
6. Kill bei wachsendem Output.
7. DB-Neustart mitten im Step.
8. SSH-Netzunterbrechung, DB verfügbar.
9. Container-Neustart, Remote-Unit aktiv.
10. Ansible-Host-Reboot nach Started.
11. Result geschrieben, Worker vor Finish getötet.
12. Cancel während aktiver Unit.
13. Reaper gleichzeitig mit Callback.
14. zweiter Worker/Replica.
15. dreifacher Kind-Hang und Cooldown.
16. geplante Queue während Pause/Offline.
17. Restore einer älteren Test-DB bei vorhandener Unit fremder Generation.

Für mutierende Stagingtests werden Test-VMs mit UUID/MOID und ein isolierter ESXi-Bestand verwendet. Jeder Lauf prüft zusätzlich: keine zweite Unit, keine zweite JID, keine zweite VM, keine verwaiste Prozessgruppe, kein unzulässiger Terminalstatus.

### 17.5 Browser und Barrierefreiheit

- Dienstbanner für jede Zustandsvariante und Rolle;
- Queueposition/geplant/fällig/pausiert;
- Pause/Resume bestätigt, CSRF, RBAC, Audit;
- Recovery-Linkrechte und technische Details verborgen;
- Joblog terminal >1000 Zeilen vollständig;
- Hidden-Tab stoppt Polls und Cursor holt lückenlos nach;
- `role=status`, `aria-atomic`, kein Fokusraub, Tastaturbedienung;
- Text/Icon unabhängig von Farbe;
- Responsive Geometrie bei umgebrochener Heading-/Action-Zeile;
- neue Klassen vollständig im CSS-Vertrag.

### 17.6 Release-Gates

- kanonische Fast-, Integration- und Release-Lane aus `scripts/check.ps1`;
- PHPUnit im Container, PHP-Lint, Python-Compile/Lint gemäß bestehender Toolchain, JS `node --check`;
- Migration `--check` und frische Installation;
- Enum-, PHP-Version-, Bounds-, Doku-, Dateigrößen-, CSP- und Sprachdrift;
- Guard-Harness positive/negative/zero-match;
- Health-/Exposure-Vertrag;
- Contractreview und Driftcheck vor Commit;
- echter Ubuntu-24.04-/systemd-/Ansible-2.19-Stagingnachweis;
- Air-Gap-Bundle-Selbstprüfung ohne Netz.

## 18. Umsetzungsreihenfolge

### Etappe 0: Freigabe und Charakterisierung

- Produktentscheidungen P1 bis P8 bestätigen.
- Produktionsvorfall anonymisiert als Regression Fixture dokumentieren.
- reale Ansible-Core-/community.vmware-/Python-/systemd-Version prüfen.
- reale Prozess-, Speicher-, Log- und Laufzeitspitzen erheben.
- vorhandene Master-/Create-Plan-Abhängigkeiten markieren.
- keine Verhaltensänderung.

Abnahme: freigegebene ADR-Skizze, Messprotokoll, vollständige aktuelle Code-/Dokuinventur.

### Etappe 1: Wahrheits- und QoL-Sofortkorrekturen

- falsche Hilfeaussagen entfernen: Timeout/SSH-Abbruch beendet Remote-Arbeit nicht sicher; `unhealthy` startet keinen Container neu; Reaper konvergiert bisher nur DB.
- Worker-/Queue-/Heartbeatdiagnose im Systemstatus lesbar machen, noch ohne neue Recoverybehauptung.
- Ansible-Version im Preflight hart gegen die gepinnte Collection prüfen.
- ungepufferte Ansible-Ausgabe und vorhandenen vollständigen Logtailvertrag aus Master/Create-Plan umsetzen.
- Queuebanner unterscheidet geplant, Worker offline und aktiven Missionsblocker.

Abnahme: keine falsche Self-Healing-Aussage mehr; keine Architekturmutation.

### Etappe 2: ADR, Protokoll und Offline-Hostvorbereitung

- neue ADR für Durable Remote Execution, Fencing und Recovery.
- Runner/Launcher mit Golden Vectors und Harness implementieren.
- Offline-Bundle enthält beide Dateien und Prüfsummen.
- Installer richtet unter kontrollierter Root-Installation Linger, State-Pfad und Rechte ein.
- Portal-Preflight prüft read-only: systemd User Manager, Linger, Runnerchecksumme, Freiplatz, Python/Ansible/Collection.
- kein neuer Job nutzt den Runner noch.

Abnahme: Staging-Unit übersteht SSH-Logout, lässt sich exakt beobachten und hinterlässt atomisches Resultat.

### Etappe 3: Schema, Repositories und Fencing

- additive Migration und Frischschema.
- Remote-Execution-Repo, globale Lease, Job-Locktoken/Epoch.
- alle bisherigen Workerwrites auf CAS erweitern.
- Claim-Pause und Audit.
- Snapshot im Report-only-Modus.

Abnahme: Zwei-Worker- und alte-Lease-Integrationstests grün; bestehendes Laufverhalten noch unverändert.

### Etappe 4: Read-only Pilot

- Inventar zuerst über durable Unit ausführen.
- Launch/Poll/Reattach/Result/Logoffset/Cleanup.
- Worker-Neustart- und Hostlogout-Faulttests.
- Systemstatus zeigt Remotehandle und Recovery.

Abnahme: Inventar überlebt Worker-/SSH-Ausfall ohne Doppelabruf oder verwaisten Prozess.

### Etappe 5: Reaper und Wiederaufnahme

- neue Reaper-Matrix aktivieren.
- Recoveryjobs vor Queue claimen.
- VM-Sweep an aktive Recovery binden.
- Legacy-Upgradefälle kennzeichnen.
- Callback-Races abnehmen.

Abnahme: Kein Remote-active Job wird terminalisiert; derselbe Lauf finalisiert genau einmal.

### Etappe 6: Mutierende Schritte stufenweise

Empfohlene Reihenfolge:

1. Export mit idempotentem Callback;
2. Start mit UUID-/Powerstate-Konvergenz;
3. Autostart mit deklarativem Live-Abgleich;
4. Powercycle mit persistierten Phasen-/Ausgangszuständen;
5. Create und Full gemeinsam mit dem Create-Plan.

Jeder Schritt erhält eigene Recovery-Faulttests, bevor der nächste aktiviert wird. Kein globaler Feature-Schalter aktiviert alle Modi gleichzeitig.

### Etappe 7: Supervisor-Self-Healing

- PID-1-Supervisor, Kindheartbeat, TERM/KILL-Frist, Cooldown.
- Integrationheartbeat gehört dem Supervisor.
- Compose-Healthcheck auf Supervisor-Liveness.
- Recovery wird nach Kindneustart priorisiert.
- absichtlicher Compose-Stop bleibt respektiert.

Abnahme: reproduzierter stiller Kind-Hang heilt ohne Ubuntu-Eingriff; kein Remote-Duplikat.

### Etappe 8: Portalbedienung

- gemeinsame Dienstsnapshot-SSoT.
- Dashboard-, Deploy-, Joblog- und Systemstatusdarstellung.
- Pause/Resume/Recovery-now als DB-Aktionen.
- vollständiger Logtail, Visibility-Polling, Accessibility.
- Rollen- und Responsive-Tests.

Abnahme: Operator kann alle normalen Zustände aus dem Portal verstehen und zulässige Aktionen ausführen.

### Etappe 9: Doku, Restore und Produktionsrollout

- Dokumentationsmatrix aus Abschnitt 19 vollständig.
- Backup-/Restore-Generation und Drill.
- Go-Live-Faultdrill auf produktionsgleichem Air-Gap-Staging.
- kontrollierte Aktivierung je Modus/Zugang.
- Beobachtungsfenster und Rückbauprobe.

Abnahme: Release-Lane grün, Runbook von einer Person ohne Codekenntnis erfolgreich durchgeführt.

## 19. Dokumentations- und Hilfematrix

### 19.1 ADRs

Neue ADR mit nächster freier Nummer:

- Remote-Execution-Protokoll;
- systemd-User-Service/Linger;
- Worker-Epoch/Job-Fencing;
- Reaper-Recovery statt blindem Terminalisieren;
- modusspezifische Reconciliation;
- Portalpause statt Containerstop;
- Self-Healing-Grenzen und verworfene Alternativen.

Gezielte Ergänzungen bestehender ADRs:

- ADR-0002: SSH wird Start-/Beobachtungstransport, nicht Lebensdauer-Owner.
- ADR-0007: Runner ist offline mitgeliefert; systemd ist Zielplattformvoraussetzung, kein Download.
- ADR-0018: Deploy-Supervisor besitzt den internen Workerstatus; Wartungsworker führt weiterhin keinen Outbound-MECM-Probe aus.
- ADR-0030: Callback-/Partial-Ergebnis während Recovery.
- ADR-0032: Korrelations-ID reicht bis Remotehandle/Event, bleibt aber kein Fencing-Token.
- ADR-0033: Reaper schließt `cancelling` nur, wenn Remote-/Fachausgang beweisbar ist.
- ADR-0036: VM-Identität ist Basis der modusspezifischen Versöhnung.
- ADR-0038: Beobachtung ist kein Lifecycle-Schreiber und wird für Recovery nicht überdehnt.

Historische Entscheidungen werden nicht rückwirkend umgeschrieben; Ergänzungen kennzeichnen Datum und geänderten Vertrag.

### 19.2 Betriebs- und Installationsdokumente

| Datei | Ergänzung |
|---|---|
| `docs/DEPLOYMENT.md` | Zielarchitektur, systemd/Linger/Runner, Versionen, Ports, State-Pfad, Security, Recovery und Supportgrenze |
| `docs/INSTALLATION-ANLEITUNG.md` | verständlicher Installations-/Upgradeablauf, Root-Schritt für Linger, Portalpreflight, Validierung |
| `docs/operations/offline-install.md` | Runnerartefakte, Checksummen, Python/Ansible/systemd-Voraussetzung, kein Runtime-Download |
| `docs/operations/deploy-chain.md` | neue Sequenz, Zustandsautomaten, Lease/Fencing, Reaper, Callback, Cancel, Cleanup |
| `docs/operations/esxi-inventory.md` | durable Inventar-Unit, Wiederaufnahme, Fehlerherkunft |
| `docs/operations/troubleshooting.md` | symptomorientierte Portal-first-Matrix, `queued`-Gründe, recovering/manual review, Adminfallback |
| `docs/operations/go-live.md` | Linger-/Runnerprobe, Faultdrill, Aktivierungs- und Rollbackgate |
| `docs/operations/backup.md` | Pause/Drain, aktive Remoteunits, Restore-Generation, Restore-Faultdrill |
| `docs/QA.md` und `docs/TESTPLAN.md` | neue Contract-/Fault-/Staging-/Air-Gap-Gates |
| `docs/QUALITY-GATES.md` | neue Guards, stabile Diagnose-IDs, Progressvertrag |
| `docs/GLOSSARY.md` | recovering, paused, uncertain, Worker-/Remoteheartbeat, Retry vs Recovery |
| `docs/CHANGELOG.md` | erst bei tatsächlicher Implementierung, nicht beim Plan |
| `README.md` | nur kompakter Architektur-/Betriebshinweis und Links, keine zweite Anleitung |

Das Troubleshooting beginnt künftig mit dem Portal:

1. Systemstatus `Bereitstellungsdienst`.
2. betroffener Auftrag und `Was passiert als Nächstes?`.
3. strukturierte Deploy-/Systemprotokolle.
4. erst danach Infrastrukturadmin und Hostbefehle.

Manuelle `pgrep`-/negative-PGID-Kills bleiben ein gekennzeichneter Legacy-/Notfallanhang mit exakter Identitätsprüfung, nicht die normale Benutzeranleitung.

### 19.3 Portalhilfe und Sprachkataloge

Mindestens betroffen, jeweils DE/EN:

- `lib/help/deploy.php`, `lang/*/help_deploy.php`;
- `lib/help/stack.php`, `lang/*/help_stack.php`;
- `lib/help/system_status.php`, `lang/*/help_system_status.php`;
- `lib/help/credentials.php`, `lang/*/help_credentials.php`;
- bei neuen sichtbaren Bereichen `help_overview.php`, `help_settings.php` und ihre Renderer;
- `lang/*/deploy.php`, `system_status.php`, `dashboard.php`, `logs.php`, `credentials.php`, `common.php`.

Konkret zu ersetzen:

- `Der Worker steht, Jobs laufen nach Start einfach weiter` wird getrennt in queued, remote-active und uncertain.
- `Ansible nicht erreichbar, Job schlägt fehl und wird einfach neu eingereiht` wird entfernt.
- `Das System ist self-healing, nichts bricht dauerhaft` wird durch konkrete automatische Fälle und Grenzen ersetzt.
- `Die Aufsicht schließt cancelling selbst ab` gilt nur nach nachgewiesenem Remote-/Fachausgang.
- `unhealthy` wird nicht als automatischer Neustart beschrieben.
- die normale Handlung `docker compose ...` verschwindet aus der Nutzerhilfe und bleibt nur im Adminrunbook.

Neue Hilfefragen:

- Warum ist mein Auftrag `queued`?
- Was bedeutet `Wiederherstellung läuft`?
- Warum kann ich noch nicht `Erneut ausführen`?
- Was passiert bei einem Worker-, DB- oder SSH-Ausfall?
- Was bewirkt `Nach aktuellem Auftrag pausieren`?
- Wann ist wirklich ein Administrator nötig?
- Was ist der Unterschied zwischen Auftrag, Worker und entfernter Ausführung?

## 20. Rollout, Kompatibilität und Rückbau

### 20.1 Aktivierung

- Schema und lesende Anzeige zuerst.
- Hostpreflight muss pro Ansible-Zugang grün sein.
- aktive Legacyjobs werden vor Aktivierung beendet beziehungsweise manuell geklärt; sie werden nicht in neue Handles erfunden.
- Featureaktivierung pro Modus, beginnend mit Inventar.
- Reaper-Neuverhalten wird erst aktiviert, wenn Recovery-Worker den entsprechenden Modus versteht.
- Supervisor erst nach durable Ausführung aller produktiv freigegebenen Modi.
- mindestens ein vollständiges Beobachtungsfenster je Stufe.

### 20.2 Rückbau

Ein Rückbau beginnt mit Claim-Pause. Er ist verboten, solange:

- eine v2-Remote-Unit aktiv ist;
- ein Create-JID ungeklärt ist;
- ein Job `recovery_pending`/`uncertain` ist;
- Cleanup noch die einzigen Ergebnisnachweise hält.

Nach Drain/Reconciliation kann der Code zurückgerollt werden; additive Tabellen und Spalten bleiben bestehen. Der alte Reaper darf nicht wieder aktiviert werden, solange neue Handles existieren. Remote-Runner und State-Verzeichnisse werden erst nach Retention und Backup entfernt.

### 20.3 Produktionsbeobachtung

Während des Rollouts werden ohne externe Telemetrie lokal beobachtet:

- Worker-Neustarts und Cooldowns;
- Recoveryanzahl/-dauer;
- Alter fälliger Queue;
- Remote-Protokollfehler;
- unklare Ausgänge je Modus;
- Cleanupfehler und Remotediskverbrauch;
- Logtruncation;
- Retryblockaden.

Schwellwerte liegen in Constants/Defaults und werden aus Stagingmessungen abgeleitet. Sie werden nicht in Hilfe oder SQL dupliziert.

## 21. Nicht-Ziele und bewusst verbleibende Grenzen

- keine freie Remote-Shell im Portal;
- kein Docker-Socket im PHP-/Webservercontainer;
- kein `sudo systemctl` aus HTTP;
- kein Versprechen, eine bereits an ESXi/vCenter übergebene Aufgabe sicher hart abbrechen zu können;
- kein automatischer Retry einer ungeklärten Mutation;
- keine externe Monitoring-/Benachrichtigungsplattform;
- keine Änderung der fünf Legacy-Machine-API-Statusstrings;
- keine zweite VM-Lifecycle-Wahrheit aus Joblogs oder Heartbeats;
- keine allgemeine Unterstützung beliebiger Linux-Distributionen ohne systemd-User-Manager; Zielplattform bleibt der dokumentierte Ubuntu-24.04-Ansible-Host;
- kein Ersatz für Infrastrukturbackup, Hostüberwachung oder einen Administrator bei komplettem Plattformausfall.

## 22. Definition of Done

Der Gesamtplan ist erst abgeschlossen, wenn:

- [ ] P1 bis P8 und die neue ADR freigegeben sind;
- [ ] jeder neue Remote-Schritt vor Mutation ein persistentes Handle besitzt;
- [ ] systemd-Unit, Manifest, Started, Resultat und DB-Zeile gegenseitig gebunden sind;
- [ ] Worker-Epoch und Locktoken alle mutierenden Writes fencen;
- [ ] Reaper keinen möglicherweise aktiven Remoteprozess terminalisiert;
- [ ] Worker-/Containerneustart denselben Lauf reattacht;
- [ ] Supervisor einen reproduzierten Kind-Hang selbst heilt;
- [ ] alle Modi eine explizite Recoverypolicy und Faulttests besitzen;
- [ ] Create die per-VM-JID-/Identitätsverträge des Create-Plans erfüllt;
- [ ] keine bekannten `ansible-playbook`-Prozessgruppen nach terminalem Job verwaisen;
- [ ] Queue, geplant, pausiert, recovering, degraded und manual review intuitiv unterschieden sind;
- [ ] normale Nutzer keine Ubuntu-Anweisung als einzige Handlung erhalten;
- [ ] Systemstatus, Dashboard, Deployseite und Health dieselbe Snapshot-SSoT verwenden;
- [ ] Joblogtail terminal vollständig und hidden-tab-schonend ist;
- [ ] strukturierte Events sicher, rate-limited und RBAC-geschützt sind;
- [ ] alle Dokus/Hilfen aus Abschnitt 19 DE/EN aktualisiert sind;
- [ ] Air-Gap-Bundle Runner und Voraussetzungen vollständig enthält;
- [ ] Restore-Generation und Rückbau praktisch geprobt wurden;
- [ ] alle Unit-, Integration-, Browser-, Fault-, Staging- und Release-Gates grün sind;
- [ ] Contract-, Drift- und i18n-Review keine Abweichung melden;
- [ ] ein Operator ohne Ubuntu-Zugriff den Normalfall und eine Worker-Recovery vollständig im Portal nachvollziehen kann.

## 23. Freigabecheckliste vor der ersten Implementierung

1. systemd-User-Service als verbindlichen Remote-Owner bestätigen.
2. Ubuntu 24.04 als unterstützte Ansible-Hostbasis bestätigen.
3. Claim-Pause als Portalwartungsweg bestätigen.
4. `running`/`cancelling` während Recovery statt neuem Jobstatus bestätigen.
5. automatische Recoverygrenzen je Modus bestätigen.
6. Berechtigungsniveau für Pause/Resume/Recovery-now festlegen, bevorzugt bestehendes `system.config` statt neuer Rollenlogik.
7. Retention für Remote-Marker und Rohlog nach Diskmessung festlegen.
8. reale Ressourcenwerte und RuntimeMaxSec pro Step messen.
9. Rolloutreihenfolge und produktionsgleiches ESXi-Staging festlegen.
10. erst danach Etappe 1 bis 3 beginnen; Mutationsmodi bleiben bis zu ihrem Fault-Gate im Legacybetrieb beziehungsweise pausiert.
