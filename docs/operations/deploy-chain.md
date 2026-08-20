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
| Portal → MECM Device-Sync | Geplante MECM-Aufgabe liest Geräte mit bekannter DHCP-MAC | Systemstatus zeigt Start/Abschluss und Ursachen; Kategorie `mecm` | `mecm_updateid.php` meldet ResourceID und Provenienz der Collection-Mitgliedschaften | MECM-Provider, fehlende Collection, doppelte MAC, ResourceID fehlt, Rückreport abgelehnt |
| MECM → PXE-VM | Collection/Task Sequence ist bereit und VM startet | MECM-Status plus Portalstufe 4/5 | Windows-Client meldet Phasen an `mecm_report.php` | Verteilung noch nicht erfolgreich, falsche Task Sequence, PXE-/Netzproblem |
| Windows-Client → Portal | Clientphasen melden `started`, `finished` oder `failed` anhand der MAC | **VM bearbeiten → Client-Phasen** mit Zeit, Ergebnis und Detail | Report-Endpoint speichert ausschließlich Telemetrie; keine Lifecycle-Schreibabkürzung | API nicht erreichbar, Zertifikatswechsel/Pin, kein passender Adapter oder Datenträger |

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
`create` und `full` sind zusätzlich bis Etappe 14B ausgeschlossen.

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

## Zwei Ansible-Nachweise, zwei Aussagen

Der Systemstatus hält den manuellen **Volltest** und den letzten **vom Worker bearbeiteten Missionsauftrag** absichtlich getrennt. Als bearbeitet gilt dabei nur ein Auftrag, den ein Worker mindestens einmal übernommen hat (`attempts > 0`); ein aus der Warteschlange abgebrochener Auftrag war nie in Ausführung und erscheint dort nicht. Der Volltest prüft aus dem Portal heraus SSH, die vollständige Toolchain, einen echten SFTP-Transfer sowie – bei konfigurierter Rückadresse – Portal-Erreichbarkeit und IP-Allowlist. Er läuft nicht automatisch. Nach `VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS` Tagen heißt sein Zustand deshalb „Test veraltet“: kein bekannter Fehler, aber auch kein aktueller Gesamtnachweis. Ein bekannter Fehlschlag altert nicht ins Neutrale.

Der Missionsnachweis wird direkt aus `deploy_jobs` gelesen: neuester terminaler Auftrag je `credential_ansible_id`, nur mit Mission; `queued`, `running`, `cancelling` und missionslose Inventaraufträge zählen nicht. Sein Status, Zeitpunkt und Jobprotokoll belegen den tatsächlich gelaufenen Modus. Ein erfolgreicher Start- oder Shutdown-Auftrag beweist beispielsweise weder SFTP-Neuaufbau noch MAC-Rückkanal und färbt daher die Volltest-Ampel nicht grün. Bei Zeitgleichheit entscheidet die höhere Job-ID. Es gibt keine zweite Statuskopie und kein zusätzliches Laufzeitprotokoll.

„Volltest jetzt starten“ im Systemstatus verwendet denselben CSRF-/RBAC-geschützten Handler wie die Seite Zugangsdaten. Das Ergebnis aktualisiert `deploy_ansible_preflight_state` und schreibt wie bisher genau eine Auditzeile in **Protokolle → Sicherheit**, Kategorie `credentials`; **Prüfprotokolle öffnen** führt dorthin. Missionsausgabe bleibt ausschließlich in `deploy_job_logs`, erreichbar über den direkten Link am Missionsnachweis.

## SSH-/SFTP-Budgets und Fehlerherkunft

Ein technischer Text allein entscheidet nicht, ob ein VirtuSphere-Budget abgelaufen ist. Nur `SshTransportBudgetExceeded` belegt diese Ursache; eine gewöhnliche `RuntimeException` mit demselben Wortlaut bleibt ein gewöhnlicher Transportfehler. `SftpTransportFailed` trennt Subsystem-, Pfad-, Rechte- und Übertragungsfehler davon, `SshTransportConfigurationException` lokale Voraussetzungen. Missions- und Inventarpfad behalten diese Typen bis zu ihrer jeweiligen Fehlerbehandlung. `DeployWorkerCancelled` ist ein eigener Abbruchtyp und wird vor dem Transport-Catch weitergeworfen.

Was von diesen Typen nicht erkannt wird, geht durch die eine gemeinsame Funktion `ansible_connection_error_category()` (`lib/connection_errors.php`): sie qualifiziert das generische Ergebnis von `connection_error_category()` als Ansible-Host-Ursache, bevor der Inventarworker (SSH-/Transport-Phase) oder der synchrone SSH-Zugangstest die Kategorie speichert. Eine abgelehnte Anmeldung, ein DNS-Fehler oder eine verweigerte Verbindung auf dieser Strecke landen deshalb als `ansible_auth`/`ansible_dns`/`ansible_unreachable`, nie als das generische `auth`/`dns`/`unreachable`, das dieselbe Zeile wie einen ESXi-Fund aussehen ließe. Weder `ansible_auth` noch `ansible_authz` pausieren einen ESXi-Zugang: nur der exakte Code `auth` einer echten ESXi-/vCenter-Antwort tut das (`docs/operations/esxi-inventory.md`).

Seit Etappe 8 nimmt diese Qualifizierung keinen fremden Fall mehr mit: Ein Datenbankfehler, den der DB-Kanal nicht mehr auffangen kann, wird vor jeder Phasenauswertung als `worker` erkannt, weil seine Phase nichts über seine Herkunft aussagt. Ebenso bleibt eine lokale Transport-Fehlkonfiguration phasenunabhängig `config`. Der technische Text im Jobprotokoll unterscheidet die Fälle weiterhin, und das Containerlog trägt die Zustandszeile des Kanals.

Das SFTP-Gesamtbudget beginnt nach erfolgreicher Anmeldung und läuft auf einer monotonen Uhr. Jede entfernte Operation erhält höchstens ihr Einzelbudget, kurz vor dem Gesamtende aber nur die verbleibende Zeit. Geprüft wird unmittelbar vor und nach `is_dir`, `mkdir`, Upload, Probe-Schreiben und Probe-Löschen sowie vor dem erfolgreichen Rücksprung. `false` und Exceptions werden zuerst gegen den unveränderten phpseclib-Timeoutzustand geprüft; erst danach findet der äußere `finally`-Disconnect statt. Eine Datenbank- oder Logger-Exception läuft außerhalb dieses Guards weiter zum Worker und wird nicht als SFTP ausgegeben.

## Playbook-Schritte, Abbruchgrenze und gespeicherte Ausgabe

Seit Etappe 8 ist jeder Playbook-Schritt eines Modus ein eigener entfernter Befehl. Vorher lief die Reihe als eine `a && b`-Kette, sodass es zwischen den Playbooks keine Grenze gab, an der ein Abbruch hätte greifen können: Für eine „Full pipeline" mit fünf Playbooks bedeutete die Zusage der Oberfläche nichts. Vor jedem Schritt und nach jedem beendeten Schritt entscheidet derselbe Ownership-Helper in einer kurzen Transaktion anhand von `id`, `locked_by` und `status`, ob ein weiterer Schritt startet. Für den Operator gilt dadurch genau: Der gerade laufende Schritt kann seine Änderungen auf ESXi vollständig ausführen, danach startet kein weiterer.

Der Abschluss selbst ist ein Compare-and-swap aus `running` mit eigenem Besitz. Gewinnt eine Abbruchanforderung das Rennen mit dem letzten Schritt, trifft dieser Swap null Zeilen; der Worker lädt die Zeile neu, bestätigt `cancelled` und schreibt dazu, dass die Arbeit des laufenden Schritts ausgeführt wurde. Gewinnt der Abschluss, kann ein späterer Abbruch-POST den fertigen Auftrag nicht mehr verändern.

Aufräumen auf dem Ansible-Host: Jeder Schritt trägt einen Trap auf HUP/INT/TERM, der das Arbeitsverzeichnis samt `accounts.yml` entfernt, wenn seine entfernte Shell beendet wird (Verbindungsabbruch, Kill). Ein `EXIT`-Trap ist nicht mehr möglich, weil er nach dem ersten Schritt zuschlagen würde. Nach einer beendeten Reihe löscht der Worker das Verzeichnis zusätzlich selbst; hat ein Schritt nicht zurückgemeldet (Abbruch mitten im Playbook, gestörter Transport), lässt er es bewusst stehen und schreibt das ins Protokoll, statt unter einem laufenden Playbook zu löschen.

Gespeicherte Ausgabe: Jede Zeile läuft vor der Persistenz durch genau eine Stelle. Sie erzwingt gültiges UTF-8, entfernt ANSI- und andere Steuersequenzen (Tabulator bleibt), redigiert die Zugangsdaten des Auftrags in Klartext- und URL-Form und begrenzt Zeilenlänge sowie Gesamtvolumen je Auftrag; jede Kappungsart wird genau einmal je Auftrag als eigene Zeile benannt. Eine erreichte Grenze beendet weder Playbook noch Herzschlag. Die gespeicherte Quelle heißt für neue Zeilen `ansible` (die vereinigte Remote-Ausgabe, denn der entfernte Befehl leitet mit `2>&1` um) oder `worker_error` (Befund des Workers); `system` bleibt die Schrittmeldung des Workers. Die alten Werte `stdout`/`stderr` bleiben lesbar und werden im Portal als dieselbe Ansible-Ausgabe dargestellt: Sie waren nie zwei Kanäle.

## Datenbankausfall, während ein Auftrag läuft

Das Playbook läuft auf dem Ansible-Host, nicht im Worker. Auftragsprotokoll und Herzschlag sind ein Nebenkanal dieses Laufs, und ein Nebenkanal darf den Lauf nicht beenden: der Remote-Exitcode ist das Einzige, was über die bereits erzeugten VMs noch zu erfahren ist. Ein Datenbankausfall schließt den SSH-Stream deshalb nicht.

Der Worker führt jeden Schreibzugriff eines laufenden Auftrags über einen Kanal, der die aktuell gültige Verbindung besitzt; die Callbacks fragen ihn bei jedem Zugriff nach dem Handle, statt eine Verbindung festzuhalten, die nach einem Reconnect tot wäre. Beobachtbar wird die Störung so:

- Genau eine redigierte Zustandszeile je Störung im Containerlog (`docker compose logs deploy-worker`). Sie sagt ausdrücklich, dass der entfernte Lauf weiterläuft.
- Fertige, bereits redigierte Protokollzeilen werden in einer größenbegrenzten FIFO gehalten (`VIRTUSPHERE_DEPLOY_DB_CHANNEL_SPOOL_MAX_LINES`). Nach der Rückkehr steht im Auftragsprotokoll zuerst eine SYSTEM-Zeile mit Ausfalldauer, Anzahl der gepufferten Zeilen und, falls die Grenze griff, der Anzahl der ältesten verworfenen Zeilen. Danach folgen die gepufferten Zeilen in ihrer ursprünglichen Reihenfolge.
- Ein Reconnect wird höchstens einmal je Tick und nur bei fälligem Backoff versucht, damit das Lesen des SSH-Streams nicht stehen bleibt. Der dateibasierte Container-Heartbeat bleibt währenddessen aktuell, denn ein Worker, der einen Ausfall aussitzt, ist gesund.
- Nach dem Reconnect ist die Reihenfolge fest: zuerst Ownership, dann Jobheartbeat, dann die Spool. Gehört der Auftrag inzwischen jemand anderem, wird der entfernte Lauf beendet, ohne ein Ergebnis zu schreiben; die gepufferten Zeilen werden verworfen, weil sie zu einem Lauf gehören, dessen Abschluss bereits ein anderer veröffentlicht hat.

Endet der Remote-Befehl während der Störung, existiert sein Exitcode zunächst nur im Workerprozess. Der Loop-Worker wartet begrenzt auf die Datenbank, prüft die Ownership und finalisiert genau einmal. `deploy_worker.php --once` bleibt begrenzt und meldet auf STDERR ausdrücklich, dass dieser Ausgang nicht persistiert werden konnte und der Auftrag beansprucht bleibt. Missions- und Inventaraufträge verwenden denselben Kanal; es gibt keinen zweiten Reconnectpfad mit abweichendem Verhalten.

## Ein Auftrag, den die Aufsicht beendet hat

Steht als letzter Fehler „Reaped stale deploy job", hat nicht der Auftrag versagt, sondern es kam über das Fenster `VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS` kein Herzschlag an. Die Meldung nennt ausschließlich Beobachtbares: Job-ID, Alter des letzten Herzschlags gegen dieses Limit, wer den Lock hielt und den daraus folgenden Übergang. Ein abbrechender Auftrag konvergiert dabei zu `cancelled`, ein laufender zu `failed`.

Eine Ursache steht dort bewusst nicht. Ein ausbleibender Herzschlag beweist, dass niemand geschrieben hat, nicht warum. Der Deploy-Worker hängt einen ausdrücklich getrennten zweiten Satz an: ob sich in diesem Moment ein Bereitstellungsdienst über seine Statuszeile meldet. Das ist eine Aussage über jetzt, nicht über den Prozess, der den Auftrag hielt, denn ein Neustart erzeugt eine frische Statuszeile. „Meldet sich" beweist deshalb nicht, dass der damalige Besitzer überlebt hat, und „meldet sich nicht" nicht, dass er gestorben ist.

Zuerst das Auftragsprotokoll und das Containerlog lesen, dann handeln: eine Zustandszeile des DB-Kanals im Containerlog erklärt die Stille ohne toten Worker, und der Lauf auf dem Ansible-Host kann noch aktiv sein. Ein Neustart des Dienstes ist keine Standardmaßnahme, sondern die Folge eines Befundes.

Eine Aufsicht, die selbst gerade erst verbunden ist, urteilt nicht: sie kann in dieser Zeit einen toten Dienst nicht von ihrer eigenen Blindheit unterscheiden. Nach einem Neustart eines Workers oder einem Datenbankausfall bleibt ein verwaister Auftrag deshalb bis zu `VIRTUSPHERE_DEPLOY_REAP_OBSERVER_GRACE_SECONDS` stehen, bevor er beendet wird; das Containerlog zeigt diesen Holdoff einmal je Verbindung. War die Aufsicht durchgehend verbunden, entsteht keine Verzögerung. `deploy_worker.php --once` verbindet und reapt sofort, liegt damit immer im eigenen Grace-Fenster und beendet deshalb bewusst nie einen fremden Auftrag; ein erzwungenes Reaping bräuchte einen eigenen benannten Operatorschalter.

## Vor jeder Mutation: VM-Identität

Der VM-Name ist nur die Suche, nicht der Identitätsbeweis. Portal und Playbooks verwenden die gespeicherte Instance-UUID; die MOID ist der aktuelle Hostgriff und darf sich nach erneuter Registrierung ändern. Eine unbekannte namensgleiche VM blockiert. Die ausdrücklich bestätigte Adoption ist nur erlaubt, nachdem ein Administrator die VM am Host geprüft hat; sie speichert die Identität und verändert weder Hardware noch Energiezustand.

## Abbruch und Teilfehler

Ein laufender Abbruch wechselt zuerst auf `cancelling`. Der Auftrag bleibt aktiv und blockiert Löschen oder einen zweiten Missionsauftrag, bis der Worker `cancelled` bestätigt oder der Reaper einen toten Worker sicher konvergiert. Ein MAC-Rückruf zum noch abbrechenden, korrekt zugeordneten Auftrag wird angenommen; nach `cancelled` wird er abgelehnt und hinterlässt eine sichtbare Spur.

Mehrere VM-Ergebnisse werden nicht zu einem falschen Gesamterfolg verdichtet. Das Auftragsprotokoll und die Ergebnisdaten unterscheiden erfolgreiche, fehlgeschlagene und nicht ausgeführte Teilziele. Ein Wiederholungsauftrag übernimmt nur den dafür vorgesehenen Umfang.

## Diagnose

Vom Symptom aus führt die [Störungsdiagnose](troubleshooting.md) zur richtigen Portal-Seite, Log-Kategorie und ersten Maßnahme. Die Supportgrenzen für Standalone ESXi und vCenter stehen in der [Deployment-Matrix](../DEPLOYMENT.md); die Begriffe der Kette stehen im [Glossar](../GLOSSARY.md).
