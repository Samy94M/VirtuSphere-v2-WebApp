# Bereitstellungskette

Dieses Runbook beschreibt jeden Sprung eines vollständigen Deploys: Wer löst ihn aus, welcher Nachweis bleibt zurück, wie kommt das Ergebnis zurück und welche Fehler sind zuerst zu erwarten. Eine gemeinsame Korrelations-ID verbindet Portal-Anfrage, Auftrag, Worker-, Maschinen- und Auditzeilen.

## Kette im Überblick

| Sprung | Auslöser | Belastbarer Nachweis | Rückkanal | Häufigste Fehler |
|---|---|---|---|---|
| Portal → Datenbank-Warteschlange | Administrator reiht einen Auftrag ein | Auftrag erscheint mit Modus, Mission, Zeitpunkt und Status `queued` | Portal liest denselben Datensatz | Fehlende Zugangsdaten/API-Basis-URL, unvollständige Mission, Identitätskonflikt, bereits aktiver Missionsauftrag |
| Warteschlange → Deploy-Worker | Worker beansprucht den nächsten fälligen Auftrag per Ownership-CAS | Status `running`, Worker-ID, Lock und Heartbeat; Auftragsprotokoll beginnt | Worker schreibt Heartbeat, Log und Endstatus in die Datenbank | Worker ungesund, geplanter Zeitpunkt nicht erreicht, verlorener Besitz, verwaister Auftrag |
| Worker → Ansible-Host | Worker erzeugt Inventar/Variablen, überträgt sie per SFTP und startet per SSH | Auftragsprotokoll nennt Preflight, Schrittmarker, typisierte Budget-/SFTP-Ursache und Remote-Ausgabe | SSH-Exitcode je Playbook-Schritt und gestreamte, vereinigte Ansible-Ausgabe | DNS/Port, SSH-Authentisierung, fehlende Toolchain, SFTP-Subsystem/Rechte, VirtuSphere-Zeitbudget, falsche Portal-Rückadresse |
| Ansible-Host → ESXi | Playbook ruft `community.vmware` mit Credential, Trust-Modus und VM-Identität auf | Schrittmarker sowie Ansible-Taskergebnis; vor Mutationen Name+Instance-UUID | Modulresultat zurück an Ansible/Worker | Zertifikat/CA, Berechtigung oder Lizenz, Datacenter/Datastore, fremde namensgleiche VM |
| ESXi/Ansible → MAC-Rückruf | Export-Schritt sammelt MOID, Instance-UUID und MACs | Auftragsprotokoll zeigt Uploadversuch und API-Antwort | `db_importMAC.php` schreibt nur zum passenden `mission_id`/`job_id` und laufenden beziehungsweise abbrechenden Auftrag | IP-Allowlist, HTTP/TLS-Pin, fehlende `job_id`, Identitäts- oder MAC-Konflikt |
| Portal → MECM Device-Sync | Geplante MECM-Aufgabe liest Geräte mit bekannter DHCP-MAC und importiert sie unter dem eingefrorenen **Rolloutnamen** (`vm_hostname` auf der Leitung, nicht `vm_name`) | Systemstatus zeigt Start/Abschluss und Ursachen; Kategorie `mecm` | `mecm_updateid.php` meldet ResourceID und Provenienz der Collection-Mitgliedschaften, beides mit der Rolloutrevision | MECM-Provider, fehlende Collection, doppelte MAC, ResourceID fehlt, Rückreport abgelehnt, Identität nicht auflösbar, veraltete Rolloutrevision (409) |
| MECM → PXE-VM | Collection/Task Sequence ist bereit und VM startet | MECM-Status plus Portalstufe 4/5 | Windows-Client meldet Phasen an `mecm_report.php` | Verteilung noch nicht erfolgreich, falsche Task Sequence, PXE-/Netzproblem |
| Windows-Client → Portal | Clientphasen versuchen `started`, `finished` oder `failed` anhand der MAC best effort zu melden | **VM bearbeiten → Client-Phasen** mit dem letzten eingetroffenen Event; fehlende Events sind kein Fachnachweis | Report-Endpoint speichert ausschließlich Telemetrie; keine Lifecycle-Schreibabkürzung und keine Outbox | API nicht erreichbar, Zertifikatswechsel/Pin, kein passender Adapter oder Datenträger |

## Aktiver Transport und vorbereiteter Durable Runner

Der aktive Produktpfad ist weiterhin die in der Tabelle beschriebene direkte
SSH-/SFTP-Kette. Etappe 8R-O liefert zusätzlich ein offline installierbares,
geschlossenes Protokoll unter `Ansible/runner/`: genau ein erlaubter
Playbook-Schritt, eine aus Job/Attempt/Step/Run-Token abgeleitete systemd-Unit,
gehashte Artefakte und atomare `started.json`-, `heartbeat.json`- und
`result.json`-Marker. Freie Shell-Kommandos und unbekannte Felder werden
abgewiesen; ein begonnener oder fertiger Handle wird nie erneut gestartet.

Diese Basis ist nicht mit einer Aktivierung gleichzusetzen. Ohne importierte,
passende 8R-S-Standortevidenz ist kein Produktivjob an den neuen Launcher
verdrahtet und es gibt keinen stillen Fallback von einem Remote-Modus auf die
Legacy-Kette. Linger, User-Bus, cgroup-Enforcement, Kapazitätsgrenzen, reale
Faults und Rückbau bleiben am echten Air-Gap-/Ansible-/ESXi-Ziel nachzuweisen.
Create und Full wurden in der späteren Etappe 14B für den aktiven direkten
`legacy_v1`-Pfad als eine persistente Einheit je VM umgesetzt. Der vorbereitete
Durable Runner bleibt davon unberührt und weiterhin nicht aktiviert.

Migration 0042 bereitet dafür nur persistente Identität und Fencing vor. Die
Runtime-Generation wird einmal zufällig erzeugt; Lease, Epoch, Jobtoken,
Ausführungsvertrag, Remotehandle und Recoveryzustände besitzen getrennte
Felder. Die globale Claim-Pause und jede Credential-/Moduszeile starten
fail-closed. In 8R-O-2 liest der aktive Worker diese Grundlage noch nicht und
läuft deshalb verhaltensgleich auf dem bisherigen Pfad; das Umschalten von
Claim, Reaper und Recovery ist ein gemeinsames späteres 8R-S-Fenster und darf
nicht stückweise erfolgen.

Der vorbereitete Inventarconsumer besitzt bereits die vollständige lokale
Zustandsgrenze: Prepare, persistente Identität, Launchbeobachtung, Reattach,
Resultat-SHA, transaktionaler Logoffset, Reconciliation und Cleanup. Jeder
Write prüft Worker-ID, Lock-Token, Lease-Epoch und Runtime-Generation. Ein
veralteter Fence, fremdes Protokolldokument oder übersprungener Logoffset wird
`manual_required`; daraus entsteht weder Erfolg noch ein zweiter Launch.
Cleanup bleibt bis zu terminalem Controller und belegter Reconciliation
gesperrt. Diese Bibliothek ist im aktiven Worker nicht required und daher kein
neuer Transportpfad.

8R-O-4 ergänzt daneben nur die spätere Recoveryentscheidung. Sie klassifiziert
stale Remoteevidenz, kann eine idempotente Recoveryanforderung speichern und
liefert eine remote-sichere Kandidatenabfrage für den späteren VM-Sweep. Aktive,
verlorene, fremde oder manuell zu prüfende Läufe bleiben aktiv und Cleanup ist
verboten. Diese Module sind weder im Deploy-Reaper noch im Maintenance-Worker
required. Reaper, Recoveryconsumer und VM-Sweep dürfen erst gemeinsam im
8R-S-Fenster wechseln; bis dahin gilt unverändert der bestehende Legacy-Reaper.

8R-O-5 beschreibt zusätzlich die späteren Stepgrenzen für Export, Start,
Autostart und Powercycle in `lib/remote_step_policy.php`. Die Registry übernimmt
die Reihenfolge aus `ansible_playbooks_for_mode()`: Powercycle bleibt deshalb
zwei getrennte Steps aus Powercycle und Export. Export reconciliert über den
jobgebundenen MAC-Import und notfalls eine read-only Live-Inventarisierung;
Start über UUID/MOID, Powerstate und aktive Task; Autostart über materialisierte
Soll- und Livepolicy samt HA-/Lizenzgate. Powercycle darf nur an einer belegten
per-VM-Phase fortsetzen, nie die gesamte Gruppe wiederholen. Runtimebudgets
bleiben mit `site_acceptance_required` markiert und werden nicht lokal geraten.
Die Datei besitzt keinen Aktivierungswriter und keinen Aufrufer im Worker. Auch
diese Grundlage ändert daher den unten beschriebenen Legacy-Produktpfad nicht.

## Netzwerk-/MAC-Preflight (Etappe 14A)

`lib/vm_network_contract.php` ist die pure SSoT für Interface-Issues,
Fingerprint, WDS/PXE-Verdict und die aus der Playbookfolge abgeleitete
Modusmatrix. `lib/repo/vm_network.php` materialisiert einen ausgewählten Scope
in konstanter Queryzahl. Eine leere VM-Auswahl bedeutet weiterhin alle VMs der
Mission; ein Retry prüft ausschließlich seinen neu berechneten tatsächlichen
Scope.

| Befund | Create / Full / Powercycle / Export | Start / Autostart |
|---|---|---|
| VLAN nach äußerer Trimmung leer oder innerhalb einer VM exakt doppelt | blockiert vor Queue beziehungsweise Remote | sichtbare Warnung, nicht blockierend |
| spezielle WDS-PXE-Karte fehlt, weicht nur in Case ab oder ist mehrfach vorhanden | Full, Powercycle und Export blockieren; Create warnt | sichtbare Warnung, nicht blockierend |

Die allgemeine Regel und die spezielle WDS-Regel sind bewusst getrennt: Eine
Create-VM mit leerem oder exakt doppeltem VLAN ist ungültig und blockiert, auch
wenn Create allein wegen einer fehlenden WDS-Karte nur warnen würde. `Daten`
und `DATEN` bleiben zwei operative ESXi-Namen. Ein Case-ähnlicher Wert ist
Diagnose, nie ein erfolgreicher Match.

Writer sperren die Mission und weisen eine Netzwerkänderung zurück, solange ein
Auftrag `running` oder `cancelling` ist. Ein unverändertes ungültiges
Legacy-Bundle darf bei einer unabhängigen Feldänderung erhalten bleiben; sobald
das Bundle verändert wird, muss es gültig sein. Speichern, Klonen, Import,
Missionstransfer und geführte VLAN-Neuzuweisung verwenden denselben Fingerprint
und dieselbe Prüfung.

Queue und Staffelung führen Missionslock, Scopematerialisierung, allgemeine
Netzwerkprüfung, WDS-Prüfung und Inserts in einer Transaktion aus. Ein Blocker
erzeugt auch bei einer Staffelung null Jobzeilen. Nach dem Claim prüft der
Worker denselben Scope erneut und schreibt bei einem Blocker ein strukturiertes
`kind=network_preflight`-Ergebnis mit Abschlussgrund
`configuration_blocked`, bevor SFTP, SSH oder ESXi erreicht werden. Das
Auftragsprotokoll zeigt die Prüfung je VM mit dem kanonischen
`[n/total]`-Fortschritt. Der getrennte Resultatvertrag trägt `version=1`,
`outcome=failed`, Modus, Counts und ausschließlich die betroffenen VMs mit
geschlossenen Issue-Codes. Nach einer Korrektur wird er beim Retry nicht als
beschädigtes MAC-Resultat behandelt; der aktuelle Scope wird vollständig neu
geprüft.

Kann eine explizit eingereihte VM-ID nach einer erlaubten Änderung vor dem
Claim nicht mehr materialisiert werden, bleibt sie als sortierte
`missing_vm_ids`-Evidenz im Sollscope und blockiert vor Zugangsdaten- oder
Remotezugriff. Sie erhält keinen erfundenen Netzwerk-Issue-Code. Resultat und
`failed/configuration_blocked` werden atomar veröffentlicht. Gewinnt ein
gleichzeitiger Abbruch, endet der Auftrag ohne Preflight-Resultat als
`cancelled`; der Abschlussdetailtext bestätigt, dass kein Remote-Schritt lief.

Der MAC-Rückruf akzeptiert nur den aktuellen exportfähigen Auftrag und dessen
Attempt/Generation. Unter der Lockreihenfolge Mission, Job, Runtimeidentität,
gegebenenfalls Remote-Exporthandle, VM und Interfaces muss exakt eine WDS-Karte
mit gültiger MAC passen. Nur diese VM wird `deployed/pending`. Version 2 des
Ergebnisses speichert per-VM-WDS-Evidenz und den semantischen
Callback-Fingerprint; V1 bleibt historisch lesbar. Ein identischer zweiter
Callback ist nur während desselben aktiven Laufs 200 ohne Domainwrite,
abweichende oder terminale Wiederholungen sind 409. Der Fingerprint enthält die
erwarteten VM-IDs und die normalisierten semantischen Zeilen mit Multiplizität;
reine Reihenfolge und diagnostischer Freitext sind bedeutungslos. Der Retry
zeigt sämtliche gleichzeitig bestehenden Befunde in der Reihenfolge
Remoteevidenz, Identität, Netzwerk, externe Voraussetzung und wiederholt ein
Teilergebnis nur im aktuellen fehlgeschlagenen Export-Scope.

Eine Änderung der Missions-WDS-Portgruppe passt keine VM-Karte automatisch an.
Sie bleibt bei nur eingereihten Jobs erlaubt, wird aber während
`running`/`cancelling` unter demselben Missions-/Joblock mit einem Link auf das
aktive Jobprotokoll abgewiesen.

Etappe 14A aktivierte noch keinen neuen Remote-Create-Pfad. Die danach
umgesetzte Etappe 14B führt Create und Full je VM im aktiven direkten
`legacy_v1`-Pfad aus; sie aktiviert den separaten Durable Runner nicht.

## Zwei Ansible-Nachweise, zwei Aussagen

Der Systemstatus hält den manuellen **Volltest** und den letzten **vom Worker bearbeiteten Missionsauftrag** absichtlich getrennt. Als bearbeitet gilt dabei nur ein Auftrag, den ein Worker mindestens einmal übernommen hat (`attempts > 0`); ein aus der Warteschlange abgebrochener Auftrag war nie in Ausführung und erscheint dort nicht. Der Volltest prüft aus dem Portal heraus SSH, die vollständige Toolchain, einen echten SFTP-Transfer sowie – bei konfigurierter Rückadresse – Portal-Erreichbarkeit und IP-Allowlist. Er läuft nicht automatisch. Nach `VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS` Tagen heißt sein Zustand deshalb „Test veraltet“: kein bekannter Fehler, aber auch kein aktueller Gesamtnachweis. Ein bekannter Fehlschlag altert nicht ins Neutrale.

Jeder Volltest gehört zu einer monotonen Konfigurationsrevision und
Testgeneration. Zugang und verschlüsseltes Secret werden vor dem externen Lauf
aus demselben Datenbankstand materialisiert; während SSH/SFTP bleibt keine
Datenbanksperre offen. Das Ergebnis wird anschließend nur gespeichert, wenn
Typ, Revision und Generation noch übereinstimmen. Eine Secretrotation, ein
Typwechsel, eine Löschung oder ein neuerer paralleler Test kann einen älteren
Abschluss daher nicht dem aktuellen Zugang zuordnen. Vor Migration 0052
gespeicherte Ergebnisse besitzen diesen Nachweis nicht und erscheinen bis zum
erneuten Test als unbekannt.

Ergebniszeile und typisierter Audit sind ein gemeinsamer Datenbankabschluss.
Der Auditcontext enthält bei jedem Volltest das boolesche Feld
`evidence_stored`. Ein überholter Abschluss erhält `false`, Ausgang
`discarded` und Auditresultat `warning`; er überschreibt keinen Status. Das
Portal zeigt dafür nur den lokalisierten Hinweis zum verworfenen Ergebnis und
keine technische Diagnose aus dem inzwischen überholten Lauf.

Der Missionsnachweis wird direkt aus `deploy_jobs` gelesen: neuester terminaler Auftrag je `credential_ansible_id`, nur mit Mission; `queued`, `running`, `cancelling` und missionslose Inventaraufträge zählen nicht. Sein Status, Zeitpunkt und Jobprotokoll belegen den tatsächlich gelaufenen Modus. Ein erfolgreicher Start- oder Shutdown-Auftrag beweist beispielsweise weder SFTP-Neuaufbau noch MAC-Rückkanal und färbt daher die Volltest-Ampel nicht grün. Bei Zeitgleichheit entscheidet die höhere Job-ID. Es gibt keine zweite Statuskopie und kein zusätzliches Laufzeitprotokoll.

„Volltest jetzt starten“ im Systemstatus verwendet denselben CSRF-/RBAC-geschützten Handler wie die Seite Zugangsdaten. Das Ergebnis aktualisiert `deploy_ansible_preflight_state` und schreibt wie bisher genau eine Auditzeile in **Protokolle → Sicherheit**, Kategorie `credentials`; **Prüfprotokolle öffnen** führt dorthin. Missionsausgabe bleibt ausschließlich in `deploy_job_logs`, erreichbar über den direkten Link am Missionsnachweis.

## SSH-/SFTP-Budgets und Fehlerherkunft

Ein technischer Text allein entscheidet nicht, ob ein VirtuSphere-Budget abgelaufen ist. Nur `SshTransportBudgetExceeded` belegt diese Ursache; eine gewöhnliche `RuntimeException` mit demselben Wortlaut bleibt ein gewöhnlicher Transportfehler. `SftpTransportFailed` trennt Subsystem-, Pfad-, Rechte- und Übertragungsfehler davon, `SshTransportConfigurationException` lokale Voraussetzungen. Missions- und Inventarpfad behalten diese Typen bis zu ihrer jeweiligen Fehlerbehandlung. `DeployWorkerCancelled` ist ein eigener Abbruchtyp und wird vor dem Transport-Catch weitergeworfen.

Was von diesen Typen nicht erkannt wird, geht durch die eine gemeinsame Funktion `ansible_connection_error_category()` (`lib/connection_errors.php`): sie qualifiziert das generische Ergebnis von `connection_error_category()` als Ansible-Host-Ursache, bevor der Inventarworker (SSH-/Transport-Phase) oder der synchrone SSH-Zugangstest die Kategorie speichert. Eine abgelehnte Anmeldung, ein DNS-Fehler oder eine verweigerte Verbindung auf dieser Strecke landen deshalb als `ansible_auth`/`ansible_dns`/`ansible_unreachable`, nie als das generische `auth`/`dns`/`unreachable`, das dieselbe Zeile wie einen ESXi-Fund aussehen ließe. Weder `ansible_auth` noch `ansible_authz` pausieren einen ESXi-Zugang: nur der exakte Code `auth` einer echten ESXi-/vCenter-Antwort tut das (`docs/operations/esxi-inventory.md`).

Seit Etappe 8 nimmt diese Qualifizierung keinen fremden Fall mehr mit: Ein Datenbankfehler, den der DB-Kanal nicht mehr auffangen kann, wird vor jeder Phasenauswertung als `worker` erkannt, weil seine Phase nichts über seine Herkunft aussagt. Ebenso bleibt eine lokale Transport-Fehlkonfiguration phasenunabhängig `config`. Der technische Text im Jobprotokoll unterscheidet die Fälle weiterhin, und das Containerlog trägt die Zustandszeile des Kanals.

Das SFTP-Gesamtbudget beginnt nach erfolgreicher Anmeldung und läuft auf einer monotonen Uhr. Jede entfernte Operation erhält höchstens ihr Einzelbudget, kurz vor dem Gesamtende aber nur die verbleibende Zeit. Geprüft wird unmittelbar vor und nach `is_dir`, `mkdir`, Upload, Probe-Schreiben und Probe-Löschen sowie vor dem erfolgreichen Rücksprung. `false` und Exceptions werden zuerst gegen den unveränderten phpseclib-Timeoutzustand geprüft; erst danach findet der äußere `finally`-Disconnect statt. Eine Datenbank- oder Logger-Exception läuft außerhalb dieses Guards weiter zum Worker und wird nicht als SFTP ausgegeben.

## Playbook-Schritte, Abbruchgrenze und gespeicherte Ausgabe

Seit Etappe 8 ist jeder Playbook-Schritt eines Modus ein eigener entfernter Befehl. Vorher lief die Reihe als eine `a && b`-Kette, sodass es zwischen den Playbooks keine Grenze gab, an der ein Abbruch hätte greifen können: Für eine „Full pipeline" mit fünf Playbooks bedeutete die Zusage der Oberfläche nichts. Vor jedem Schritt und nach jedem beendeten Schritt entscheidet derselbe Ownership-Helper in einer kurzen Transaktion anhand von `id`, `locked_by` und `status`, ob ein weiterer Schritt startet. Für den Operator gilt dadurch genau: Der gerade laufende Schritt kann seine Änderungen auf ESXi vollständig ausführen, danach startet kein weiterer.

Der Abschluss selbst ist ein Compare-and-swap aus `running` mit eigenem Besitz. Gewinnt eine Abbruchanforderung das Rennen mit dem letzten Schritt, trifft dieser Swap null Zeilen; der Worker lädt die Zeile neu, bestätigt `cancelled` und hält im begrenzten Abschlussdetail fest, dass die Arbeit des laufenden Schritts ausgeführt wurde. Die bereits angenommene Anforderung bleibt die einzige Abbruchzeile im technischen Protokoll. Gewinnt der Abschluss, kann ein späterer Abbruch-POST den fertigen Auftrag nicht mehr verändern.

Aufräumen auf dem Ansible-Host: Jeder Schritt trägt einen Trap auf HUP/INT/TERM, der das Arbeitsverzeichnis samt `accounts.yml` entfernt, wenn seine entfernte Shell beendet wird (Verbindungsabbruch, Kill). Ein `EXIT`-Trap ist nicht mehr möglich, weil er nach dem ersten Schritt zuschlagen würde. Nach einer beendeten Reihe löscht der Worker das Verzeichnis zusätzlich selbst; hat ein Schritt nicht zurückgemeldet (Abbruch mitten im Playbook, gestörter Transport), lässt er es bewusst stehen und schreibt das ins Protokoll, statt unter einem laufenden Playbook zu löschen.

Gespeicherte Ausgabe: Jede Zeile läuft vor der Persistenz durch genau eine Stelle. Sie erzwingt gültiges UTF-8, entfernt ANSI- und andere Steuersequenzen (Tabulator bleibt), redigiert die Zugangsdaten des Auftrags in Klartext- und URL-Form und begrenzt Zeilenlänge sowie Gesamtvolumen je Auftrag; jede Kappungsart wird genau einmal je Auftrag als eigene Zeile benannt. Eine erreichte Grenze beendet weder Playbook noch Herzschlag. Die gespeicherte Quelle heißt für neue Zeilen `ansible` (die vereinigte Remote-Ausgabe, denn der entfernte Befehl leitet mit `2>&1` um) oder `worker_error` (Befund des Workers); `system` bleibt die Schrittmeldung des Workers. Die alten Werte `stdout`/`stderr` bleiben lesbar und werden im Portal als dieselbe Ansible-Ausgabe dargestellt: Sie waren nie zwei Kanäle.

Der Abschlusszustand und seine terminale Evidenz werden in derselben Datenbanktransaktion geschrieben. Erfolg, Teilergebnis und Fehlschlag erhalten dabei ihre Abschlusszeile. Beim laufenden Abbruch wurde die eine unveränderliche Abbruchzeile bereits beim Wechsel auf `cancelling` geschrieben; Bestätigung durch Worker oder Reaper ergänzt nur `cancelled_at` und den strukturierten Abschlussgrund. Ein normaler Schreiber sperrt zuvor den Auftrag und lehnt jede weitere Zeile nach einem terminalen Zustand ab.

Der Leser öffnet am tatsächlichen Ende: eine innere absteigende Auswahl nimmt die neuesten Zeilen, die äußere Sortierung zeigt sie wieder aufsteigend. Vorwärts wird ausschließlich mit `after_seq`, rückwärts mit `before_seq` gelesen; `has_more` verwendet eine zusätzliche Beweiszeile. Der Browser stoppt erst bei terminalem Auftrag und zugleich vollständig drainiertem Snapshot. Seine Tabelle bleibt auf ein dokumentiertes Fenster begrenzt. „Ältere Zeilen laden“ wechselt sichtbar in ein historisches Fenster und erhält den Scrollanker; Neuladen kehrt zum Live-Ende zurück. „Vollständiges Rohprotokoll“ streamt alle noch aufbewahrten, bereits redigierten Zeilen als NDJSON in Reihenfolge und besitzt keine eigene Schattenaufbewahrung.

## Datenbankausfall, während ein Auftrag läuft

Das Playbook läuft auf dem Ansible-Host, nicht im Worker. Auftragsprotokoll und Herzschlag sind ein Nebenkanal dieses Laufs, und ein Nebenkanal darf den Lauf nicht beenden: der Remote-Exitcode ist das Einzige, was über die bereits erzeugten VMs noch zu erfahren ist. Ein Datenbankausfall schließt den SSH-Stream deshalb nicht.

Der Worker führt jeden Schreibzugriff eines laufenden Auftrags über einen Kanal, der die aktuell gültige Verbindung besitzt; die Callbacks fragen ihn bei jedem Zugriff nach dem Handle, statt eine Verbindung festzuhalten, die nach einem Reconnect tot wäre. Beobachtbar wird die Störung so:

- Genau eine redigierte Zustandszeile je Störung im Containerlog (`docker compose logs deploy-worker`). Sie sagt ausdrücklich, dass der entfernte Lauf weiterläuft.
- Fertige, bereits redigierte Protokollzeilen werden in einer größenbegrenzten FIFO gehalten (`VIRTUSPHERE_DEPLOY_DB_CHANNEL_SPOOL_MAX_LINES`). Nach der Rückkehr steht im Auftragsprotokoll zuerst eine SYSTEM-Zeile mit Ausfalldauer, Anzahl der gepufferten Zeilen und, falls die Grenze griff, der Anzahl der ältesten verworfenen Zeilen. Danach folgen die gepufferten Zeilen in ihrer ursprünglichen Reihenfolge.
- Ein Reconnect wird höchstens einmal je Tick und nur bei fälligem Backoff versucht, damit das Lesen des SSH-Streams nicht stehen bleibt. Der dateibasierte Container-Heartbeat bleibt währenddessen aktuell, denn ein Worker, der einen Ausfall aussitzt, ist gesund.
- Nach dem Reconnect ist die Reihenfolge fest: zuerst Ownership, dann Jobheartbeat, dann die Spool. Gehört der Auftrag inzwischen jemand anderem, wird der entfernte Lauf beendet, ohne ein Ergebnis zu schreiben; die gepufferten Zeilen werden verworfen, weil sie zu einem Lauf gehören, dessen Abschluss bereits ein anderer veröffentlicht hat.

Endet der Remote-Befehl während der Störung, existiert sein Exitcode zunächst nur im Workerprozess. Der Loop-Worker wartet begrenzt auf die Datenbank, prüft die Ownership und finalisiert genau einmal. `deploy_worker.php --once` bleibt begrenzt und meldet auf STDERR ausdrücklich, dass dieser Ausgang nicht persistiert werden konnte und der Auftrag beansprucht bleibt. Missions- und Inventaraufträge verwenden denselben Kanal; es gibt keinen zweiten Reconnectpfad mit abweichendem Verhalten.

## Wie der Bereitstellungsdienst beendet und neu gestartet wird

Der Dienst kann in zwei Prozessformen laufen, und welche gilt, steht auf der
Systemstatus-Karte unter „Prozessvertrag" (ADR-0042). Standard ist die einfache
Form: der Arbeitsprozess ist der Hauptprozess seines Containers. In der
beaufsichtigten Form hält eine Aufsicht genau einen Arbeitsprozess als Kind.

**Ein Stoppsignal wird jetzt beantwortet.** Bis Etappe 14C war das nicht so, und
der Grund ist eine Kombination aus zwei einzeln harmlosen Tatsachen. Erstens
ignoriert ein Prozess, der PID 1 ist, jedes Signal, für das er keinen eigenen
Handler installiert hat; der Kernel wendet dort keine Standardaktionen an.
Zweitens erbt dieser Container vom PHP-FPM-Basisimage `STOPSIGNAL SIGQUIT`, weil
php-fpm damit sauber herunterfährt. Es kam also SIGQUIT an, niemand hörte zu, und
`docker stop` endete nach der vollen Frist im SIGKILL: gemessen 30,4 Sekunden und
Exitcode 137 im untersuchten Deploy-Worker-Fall. Genau
dann konnte der Worker weder eine letzte Zeile schreiben noch seinen Besitz
abgeben, und ein laufendes Playbook lief auf dem Ansible-Host weiter. Im
historischen Auditvergleich reagierte der isolierte untätige Hilfsprozess in
326 Millisekunden und mit Exitcode 0. Das ist ein historischer
Messwert dieses Falles, keine neue Laufzeitmessung dieser Änderungen und keine
allgemeine Zusage für alle drei Prozesse. Insbesondere kann ein bereits laufender
mysqli-Aufruf die Reaktion begrenzen, bis der Aufruf zurückkehrt. Die getrennt
beobachteten Exit-137-Fälle eines PHP-Containers belegen für sich keine Ursache
im Bereitstellungsdienst.

Die Zusage dabei ist bewusst bescheiden: Sobald ein **untätiger** Prozess die
Stopanforderung beobachtet, beendet er sich sauber; seine Pausen zwischen zwei
Versuchen sind dafür unterbrechbar. Ein bereits laufender Systemaufruf kann den
Beobachtungszeitpunkt begrenzen. Ein Prozess **mitten in einem Auftrag** merkt
sich die Anforderung und arbeitet weiter, denn das Playbook verändert ESXi auf
einem anderen Host und kein Signal an diesen Prozess hält das auf. Er schreibt
dafür eine Zeile ins Containerlog, was vorher niemand hatte.

**Die Aufsicht startet nie einen zweiten Arbeitsprozess**, bevor das Ende des
alten bestätigt ist, und bestätigt heißt hier `waitpid`, nicht „wir haben ein
Signal geschickt". Ein einzelnes ausgebliebenes Lebenszeichen zählt nicht; erst
mehrere bestätigte gelten als Befund. Danach folgt eine feste Reihe: auffordern,
Frist abwarten, hart beenden, Frist abwarten, aufgeben. Aufgeben heißt
ausdrücklich aufgeben: ein Prozess, der ein hartes Beenden überlebt, ist ein
Kernelzustand, und Ersatz für ihn wäre ein zweiter Ausführer derselben Arbeit.

**Ein Datenbankausfall löst keinen Neustart aus.** Ein Arbeitsprozess, der einen
Ausfall aussitzt, ist gesund; das ist seit Etappe 2 ausdrücklich so entschieden
und der Grund, warum der Worker überhaupt einen Datenbankkanal hat. Die Aufsicht
entscheidet deshalb ausschließlich anhand der Lebenszeichendatei des Prozesses
und nie anhand der Datenbank. Sie veröffentlicht ihren Zustand best effort in die
Datenbank, weil das Portal in einem anderen Container läuft und diese Datei gar
nicht lesen kann. Verliert nur diese Veröffentlichung ihre mysqli-Verbindung,
verwirft sie den Handle, wartet gedrosselt und verbindet sich neu. Der laufende
Kindprozess und seine PID bleiben dabei dieselben; ein DB-Wert fließt weiterhin
nicht in die Lebensentscheidung ein.

Das Neustartfenster, der Zähler, die Abkühlfrist und der nächste Versuch liegen
als begrenztes JSON unter `Docker/WebAPI/var/deploy-supervisor`. Dieses
gitignorierte Verzeichnis liegt im bind-gemounteten WebAPI-Baum; `/tmp` ist
tmpfs und eignet sich dafür nicht. Verzeichnis und Dateien sind restriktiv. Ein
separates exklusives, nicht blockierendes Lock bleibt über die gesamte Laufzeit
auf demselben Inode, während JSON im selben Verzeichnis atomar ersetzt wird.
Vor jedem Kindstart ist der vollständige Zustand dauerhaft reserviert. Schlägt
das Schreiben fehl, bleibt ein Ersatzstart gesperrt; die Beobachtung und ein aus
lokaler Prozessevidenz nötiger Stop oder Reap laufen weiter.

Findet eine neu gestartete Aufsicht einen gespeicherten Zustand `running` oder
`stopping`, besitzt sie keinen lokalen Prozesshandle und damit keinen
`waitpid`-Beleg für das Ende des alten Kindes. Auch PID 1 beweist keinen neuen
PID-Namespace. Die reservierte Ausführung wird deshalb gegen das Restartbudget
gerechnet und der Zustand bleibt `manual`, bis ein Administrator das Ende des
alten Prozesses außerhalb dieser Automatik geklärt hat. Ein gespeicherter PID
wird nie als Besitznachweis übernommen. Auch ein geordneter Stop der neuen
Aufsicht löscht diese Sperre nicht.

Beim Start und beim untätigen Wiederverbinden prüfen Deploy- und
Wartungsworker den gemeinsamen Stopstatus vor jeder Verbindung und nach einem
zurückgekehrten Verbindungsaufruf. Die Pausen zwischen Fehlversuchen laufen über
`worker_idle_wait()`. Ein Stop liefert dort `null`, und beide Aufrufer beenden
sich vor jeder weiteren DB-Nutzung. `--once` bleibt auf drei Versuche begrenzt.
Ein bereits laufender Datenbank-Verbindungsaufruf kann nicht durch den Helfer
abgebrochen werden; die Reaktion folgt dann erst nach seiner Rückkehr. Ein
aktiver Deploy-Schritt behält unverändert die Regel, seine aktuelle Arbeit bis
zur sicheren Grenze zu Ende zu führen.

Der Wechsel zwischen den Formen ist ein auditiertes Wartungsfenster und passiert
nie von selbst. `lib/deploy_supervisor_switch.php --check` nennt alle offenen
Voraussetzungen auf einmal; `--to=supervisor_v1` schaltet um und schreibt genau
eine Auditzeile, die Ablehnung eingeschlossen. Danach wird der Container mit
`docker-compose.supervisor.yml` gestartet, das Kommando und Healthcheck gemeinsam
umstellt. Solange die laufende Prozessform nicht zum gespeicherten Vertrag passt,
meldet der Dienst sich absichtlich als beeinträchtigt statt einen Zustand zu
raten.

Die Systemstatus-Karte bewertet Verfügbarkeit, Auftragsannahme und
Recoverybedarf als getrennte Achsen. Sie liest alle aktiven Jobs: normaler
Workerbesitz, legitimes Warten auf Recovery und inkonsistenter Besitz bleiben
unterschieden. Recoveryzählungen deduplizieren auf Jobebene und binden
Remotehandles an aktuellen Attempt und aktuelle Generation; terminale
ungeklärte Create-Einheiten bleiben unabhängig davon sichtbar. Falllisten sind
begrenzt, ihre vollständigen Zähler nicht. Auftragsheartbeat und
Dienstheartbeat werden getrennt angezeigt. Eine wiederholte Pause ohne echten
Zustandswechsel erzeugt keine neue Auditbehauptung; der transaktionale Writer
liefert Zustand und tatsächlichen Jobkontext gemeinsam.

## Ein Auftrag, den die Aufsicht beendet hat

Steht als Abschlussgrund `stale_heartbeat`, kam über das Fenster `VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS` kein Herzschlag an. Nur beim dadurch fehlgeschlagenen laufenden Auftrag bleibt dieselbe redigierte Diagnose zusätzlich als letzter Fehler für die Zeit nach der Log-Retention erhalten. Ein abbrechender Auftrag konvergiert dagegen mit `cancel_converged` zu `cancelled` und schreibt niemals einen letzten Fehler. Die begrenzte Detailmeldung nennt ausschließlich Beobachtbares: Job-ID, Alter des letzten Herzschlags gegen dieses Limit, wer den Lock hielt und den daraus folgenden Übergang.

Eine Ursache steht dort bewusst nicht. Ein ausbleibender Herzschlag beweist, dass niemand geschrieben hat, nicht warum. Der Deploy-Worker hängt einen ausdrücklich getrennten zweiten Satz an: ob sich in diesem Moment ein Bereitstellungsdienst über seine Statuszeile meldet. Das ist eine Aussage über jetzt, nicht über den Prozess, der den Auftrag hielt, denn ein Neustart erzeugt eine frische Statuszeile. „Meldet sich" beweist deshalb nicht, dass der damalige Besitzer überlebt hat, und „meldet sich nicht" nicht, dass er gestorben ist.

Zuerst das Auftragsprotokoll und das Containerlog lesen, dann handeln: eine Zustandszeile des DB-Kanals im Containerlog erklärt die Stille ohne toten Worker, und der Lauf auf dem Ansible-Host kann noch aktiv sein. Ein Neustart des Dienstes ist keine Standardmaßnahme, sondern die Folge eines Befundes.

Eine Aufsicht, die selbst gerade erst verbunden ist, urteilt nicht: sie kann in dieser Zeit einen toten Dienst nicht von ihrer eigenen Blindheit unterscheiden. Nach einem Neustart eines Workers oder einem Datenbankausfall bleibt ein verwaister Auftrag deshalb bis zu `VIRTUSPHERE_DEPLOY_REAP_OBSERVER_GRACE_SECONDS` stehen, bevor er beendet wird; das Containerlog zeigt diesen Holdoff einmal je Verbindung. War die Aufsicht durchgehend verbunden, entsteht keine Verzögerung. `deploy_worker.php --once` verbindet und reapt sofort, liegt damit immer im eigenen Grace-Fenster und beendet deshalb bewusst nie einen fremden Auftrag; ein erzwungenes Reaping bräuchte einen eigenen benannten Operatorschalter.

## Vor jeder Mutation: VM-Identität

Der VM-Name ist nur die Suche, nicht der Identitätsbeweis. Portal und Playbooks verwenden die gespeicherte Instance-UUID; die MOID ist der aktuelle Hostgriff und darf sich nach erneuter Registrierung ändern. Eine unbekannte namensgleiche VM blockiert. Die ausdrücklich bestätigte Adoption ist nur erlaubt, nachdem ein Administrator die VM am Host geprüft hat; sie speichert die Identität und verändert weder Hardware noch Energiezustand.

## Ergebnis je VM eines Create-Auftrags

Ein Auftrag, dessen Modus das Create-Playbook ausführt (`create` und `full`), löst seine VM-Auswahl schon beim Einreihen auf und legt in derselben Transaktion je VM eine dauerhafte Zeile in `deploy_create_vm_results` an. Eine leere Auswahl bedeutet damit nicht mehr „alle, später entschieden": eine nach dem Einreihen angelegte VM kann einen wartenden Auftrag nicht mehr still erweitern, und bei einem geplanten Start liegen zwischen beiden Zeitpunkten Stunden.

Position und Gesamtzahl folgen ausschließlich `vm_name, id`. Position 7 bezeichnet dadurch in Portal, Protokoll und Wiederholung dieselbe VM. Jede Zeile trägt ihren eigenen Zustand (`pending`, `prepared`, `running`, `succeeded`, `failed`, `uncertain`, `skipped`); `uncertain` ist ausdrücklich kein weicheres `failed`, sondern der Zustand, in dem VirtuSphere den Ausgang nicht kennt, und er hält den Auftrag an, statt mit der nächsten VM weiterzumachen.

Was diese Zeile bewusst NICHT besitzt: das Async-Verzeichnis, die Cleanup-Zähler und den Cleanup-Backoff. Die gehören dem generischen Remote-Handle, an das die Zeile über `remote_execution_id` gebunden ist; eine zweite Kopie hätte die Frage „darf dieses Verzeichnis schon entfernt werden" zwei Eigentümern gegeben.

Aufträge, die vor dieser Etappe eingereiht wurden, besitzen keine solchen Zeilen und bleiben unverändert lesbar. Ein Auftrag ohne materialisierte Zeilen wird mit einer Anweisung abgelehnt, statt still über einen zweiten Pfad zu laufen: Das frühere Sammel-Playbook `createVMs-ESXi_playbook.yml` ist gelöscht, weil ein zweiter Create-Pfad ein zweiter Beweis wäre.

## Der Einzel-VM-Vertrag des Create

Jeder durch VirtuSphere gestartete `ansible-playbook` läuft ab sofort mit `PYTHONUNBUFFERED=1`. Python puffert seinen stdout blockweise, sobald er kein Terminal ist, und der Worker liest über eine SSH-Pipe: ohne das stehen die Ergebniszeilen einer langen Schleife bis zum Prozessende im Puffer. Genau das war der Vorfall vom 13.08.2026, „keine Ausgabe seit 1800 Sekunden" über einer Arbeit, die auf ESXi weiterlief.

Für den per-VM-Create liegen vier Steuerplaybooks bereit. `createVMPrepare` prüft eine einzelne VM read-only, `createVMLaunch` wiederholt diese Prüfung, vergleicht sie mit dem gespeicherten Ergebnis und startet dann genau einen `vmware_guest`-Aufruf mit `async` und `poll: 0`, `createVMStatus` fragt genau eine Job-ID einmal ab, `createVMCleanup` entfernt gezielt deren Statusdatei. Die Auswahl erfolgt über `portal_vm_id` aus der Serverlist; der Name bleibt Anzeige und ESXi-Suchadresse. Die gemeinsame Identitätsprüfung liegt genau einmal in `create_identity_check_tasks.yml`.

Der einzige maschinenlesbare Rückkanal ist eine Zeile je Steueraufruf:

```text
::virtusphere-create:: v1 <base64url ohne Padding>
```

`emit_create_result.py` erzeugt sie aus einer lokalen Resultatdatei, `lib/ansible_create_protocol.php` liest sie. Beide Seiten kennen dieselben sechs Ereignisse mit exakt denselben Feldern; ein fehlender, doppelter, zu großer oder widersprüchlicher Marker ist ein Protokollfehler und wird nie aus gewöhnlichen Ansible-Zeilen rekonstruiert.

Zwei gemessene Eigenschaften stehen hinter dem Entwurf. Erstens antwortet `async_status` auf eine verschwundene Job-ID mit `finished: true` und, wenn der Aufruf sein Scheitern unterdrückt, mit `failed: false`; ein verlorener Job sähe damit aus wie ein fertiger. Das Status-Playbook entscheidet deshalb an der Anwesenheit der Statusdatei und nicht an der Meldung des Moduls. Zweitens verlangt die gepinnte `community.vmware` einen Mindest-`ansible-core`; der Preflight vergleicht ab jetzt die installierte Collection mit dem Pin und den installierten Kern mit dem, was diese Collection selbst fordert. Ein Host mit der aus dem Vorfall gemeldeten Kernversion fällt dort mit genau diesem Satz auf, statt bei jedem ESXi-Modul unerklärt zu scheitern.

## Wie der Worker eine Create-VM treibt

Der Missionsworker arbeitet die materialisierten Zeilen selbst ab, eine nach der anderen. Vor dem ersten Start prüft er die Menge als Ganzes: fortlaufende Positionen, gleiches `total` in jeder Zeile, keine VM doppelt, keine Zeile ohne VM. Danach gilt für jede Einheit dieselbe Folge aus vier Steuerplaybooks: `createVMPrepare` liest den Ist-Zustand read-only, `createVMLaunch` wiederholt diese Prüfung, vergleicht sie mit dem gespeicherten Prepare-Ergebnis und startet dann genau einen `vmware_guest`-Aufruf asynchron, `createVMStatus` fragt dessen Job-ID ab, `createVMCleanup` entfernt deren Statusdatei.

Die nächste VM startet ausschließlich dann, wenn keine Zeile mehr `prepared`, `running` oder `uncertain` ist, und diese Antwort kommt aus den Zeilen, nicht aus dem Gedächtnis des Prozesses. Ein neu gestarteter Worker liest deshalb denselben Zustand. Eine unklare Einheit hält den Auftrag immer an; sie ist der einzige Zustand, in dem VirtuSphere den Ausgang auf ESXi nicht kennt, und die nächste VM zu starten hieße, diese Unkenntnis zu überschreiben.

Das Async-Verzeichnis wird nicht zusätzlich gespeichert, sondern aus dem ohnehin deterministischen Arbeitsverzeichnis des Auftrags abgeleitet: `<Arbeitsverzeichnis>/create.vm.<Position>/async`. Dadurch findet ein neu gestarteter Worker dieselbe Job-ID ohne zweite Kopie in der Datenbank. Das Gesamtbudget der Create-Strecke ist kein eigener Wert, sondern das SSH-Gesamtbudget; es läuft ab `deploy_jobs.create_started_at` und wird per `COALESCE` genau einmal gesetzt, also nicht durch einen Wiederaufnahmelauf zurückgedreht.

Die feste Wartezeit nach dem Erstellen (früher `CreateSettleSeconds`, 60 Sekunden blind) ist ersatzlos entfallen. Jede Einheit wird bis zum Ende gepollt und ihre Live-Identität zurückgelesen; das ist der Beweis, den die Wartezeit nur zu ersetzen versuchte.

Nach jedem Erfolg bindet **eine** Transaktion die Identität: Auftrag, Ergebniszeile und VM gesperrt, eine leere UUID wird gebunden, eine gleiche UUID frischt nur die MOID auf, eine abweichende UUID schreibt nichts. Eine vierte Beweiskombination, die keiner dieser drei entspricht, ist `identity_result_invalid` und damit ein Fehler, keine Auslegung.

Im Auftragsprotokoll erscheint je Einheit eine technische, nicht übersetzte SYSTEM-Zeile:

```text
[7/15] RUN create Backup-12345
[7/15] POLL create Backup-12345
[7/15] DONE create Backup-12345 created
Create summary: total=15 created=14 updated=0 unchanged=0 skipped=0 failed=1 uncertain=0 not_started=0
```

`RUN`, `POLL`, `DONE`, `FAIL` und `HOLD` sind die fünf Verben; `HOLD` bedeutet, dass diese Einheit den Auftrag angehalten hat. Die Schlüssel der Zusammenfassung bleiben technisch, weil ein Operator sie greppt; die sichtbaren Zähler im Portal kommen aus denselben Zeilen über die Sprachkataloge.

Der Abschluss folgt einer festen Matrix: alles erfolgreich oder übersprungen ergibt `succeeded`, mindestens ein Erfolg ergibt `partial`, kein Erfolg ergibt `failed`. `partial` ist hier eine echte Kategorie und keine gerundete Niederlage: Ein Auftrag, der vierzehn von fünfzehn VMs erstellt hat, hat den Zielhost verändert. Ein Create-only-Auftrag lässt den fachlichen Lebenszyklus der VMs netto unverändert, ein `full` bricht vor dem Powercycle ab und konvergiert wie bisher.

Der Reaper konvergiert in derselben Transaktion nur die noch fliegenden Einheiten nach `uncertain`; bestätigte Erfolge, Fehler und Übersprungene bleiben unangetastet. Ein stehengelassenes `running` wäre die Behauptung eines Polls, den niemand mehr ausführt.

Die Create-Karte zählt weiterhin ausschließlich diese Ergebniszeilen. Unter den
Zählern ordnet sie gespeicherte Fehler zusätzlich fachlich ein, ohne den
technischen Code zu verstecken: entfernter Modulfehler, ungeklärte Beobachtung,
ungültige Ergebnisbelege und Identitätskollision sind verschiedene Ursachen.
Ein lokaler Konfigurationsfehler entsteht bereits vor der entfernten Einheit und
steht deshalb im strukturierten Auftragsabschluss, nicht als erfundener
VM-Fehler in der Create-Karte. Eine übernommene Identität ändert nur die aktuelle
Bindung der Portal-VM; eine ungeklärte Einheit und ihre Retry-Sperre bleiben
unverändert.

## Historische Create-Ergebnisse lesend bewerten

Die Korrektur des Async-Statusvertrags wirkt nur für neue Abfragen. Bereits
gespeicherte Ergebnisse werden deshalb vor jeder Wiederholung zunächst lesend
inventarisiert. Der folgende Befehl verändert weder Auftrag noch VM und stößt
keinen entfernten Lauf an:

```powershell
docker compose exec php php /var/www/html/lib/create_history_review_cli.php --job-id=123
```

Ohne `--job-id` werden alle bekannten Verdachtsformen ausgegeben. Mit
`--before=2026-09-08T12:00:00Z` lässt sich die Liste zusätzlich auf Einheiten
vor einem bekannten UTC-Rolloutzeitpunkt begrenzen. Der JSON-Bericht enthält
Fehlercode und gespeichertes Ergebnis, Prepare-Evidenz, aktuelle gespeicherte
Identität, Ausführungszeiten sowie den noch vorhandenen Datensatz des gebundenen
Remotehandles. `possible_created_recorded_failed` bezeichnet eine Einheit, bei
der eine neue VM trotz `identity_result_invalid` vorhanden sein könnte.
`possible_module_failure_recorded_unchanged` bezeichnet einen ebenso
mehrdeutigen Erfolg: `unchanged` kann korrekt sein, konnte vor der Reparatur aber
auch einen terminalen Modulfehler verdecken.

Jeder Treffer bleibt ausdrücklich `suspect_only`. Zuerst Auftragsprotokoll und
Remoteevidenz sichern, dann einen erfolgreichen VM-Inventarabruf für genau die
Zugangsdaten des Auftrags ausführen und Name, MOID sowie Instance-UUID im ESXi
Host Client abgleichen. Dieses neue Inventar beweist nur aktuelle Existenz und
Identität. Es beweist weder das damalige Modulergebnis noch die vollständige
Konvergenz von CPU, RAM, Disks oder Netzwerken. Identitätsübernahme ändert diese
Beweisgrenze nicht und hebt eine ungeklärte Create-Einheit nicht auf.

Aus dem Bericht folgt daher keine SQL-Korrektur, kein automatischer Retry und
kein Löschen. Ein nach frischem, zeitlich passendem Inventar nachweislich nicht
vorhandenes Objekt kann ausschließlich über die bereits dokumentierte,
bestätigte Freigabe einer `uncertain`-Einheit behandelt werden. Eine positive
Auflösung einer vorhandenen ungeklärten VM benötigt dagegen den gesonderten,
noch nicht beschlossenen Recoveryvertrag.

## Abbruch und Teilfehler

Ein laufender Abbruch wechselt zuerst auf `cancelling`. Der Auftrag bleibt aktiv und blockiert Löschen oder einen zweiten Missionsauftrag, bis der Worker `cancelled` bestätigt oder der Reaper einen toten Worker sicher konvergiert. Ein MAC-Rückruf zum noch abbrechenden, korrekt zugeordneten Auftrag wird angenommen; nach `cancelled` wird er abgelehnt und hinterlässt eine sichtbare Spur.

`cancel_requested_at` und `cancel_requested_by` halten unveränderlich die erste normale Anforderung; `cancelled_at` ist der bestätigte Endzeitpunkt. Ein Gruppenabbruch füllt dieselben Felder nur für noch wartende Slots und lässt den laufenden Slot unangetastet. Das Portal joint den Benutzer nur für die Anzeige; nach Kontolöschung bleibt die historische ID sichtbar. `last_error` ist ausschließlich der redigierte Fallback eines echten `failed`-Auftrags. Ergebnis, Abschlussgrund und Abbruch werden zentral aus Status, versioniertem Resultat und diesen Metadaten gerendert.

Mehrere VM-Ergebnisse werden nicht zu einem falschen Gesamterfolg verdichtet. Das Auftragsprotokoll und die Ergebnisdaten unterscheiden erfolgreiche, fehlgeschlagene und nicht ausgeführte Teilziele. Ein Wiederholungsauftrag übernimmt nur den dafür vorgesehenen Umfang.

## Diagnose

Vom Symptom aus führt die [Störungsdiagnose](troubleshooting.md) zur richtigen Portal-Seite, Log-Kategorie und ersten Maßnahme. Die Supportgrenzen für Standalone ESXi und vCenter stehen in der [Deployment-Matrix](../DEPLOYMENT.md); die Begriffe der Kette stehen im [Glossar](../GLOSSARY.md).
