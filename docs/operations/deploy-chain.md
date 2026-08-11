# Bereitstellungskette

Dieses Runbook beschreibt jeden Sprung eines vollständigen Deploys: Wer löst ihn aus, welcher Nachweis bleibt zurück, wie kommt das Ergebnis zurück und welche Fehler sind zuerst zu erwarten. Eine gemeinsame Korrelations-ID verbindet Portal-Anfrage, Auftrag, Worker-, Maschinen- und Auditzeilen.

## Kette im Überblick

| Sprung | Auslöser | Belastbarer Nachweis | Rückkanal | Häufigste Fehler |
|---|---|---|---|---|
| Portal → Datenbank-Warteschlange | Administrator reiht einen Auftrag ein | Auftrag erscheint mit Modus, Mission, Zeitpunkt und Status `queued` | Portal liest denselben Datensatz | Fehlende Zugangsdaten/API-Basis-URL, unvollständige Mission, Identitätskonflikt, bereits aktiver Missionsauftrag |
| Warteschlange → Deploy-Worker | Worker beansprucht den nächsten fälligen Auftrag per Ownership-CAS | Status `running`, Worker-ID, Lock und Heartbeat; Auftragsprotokoll beginnt | Worker schreibt Heartbeat, Log und Endstatus in die Datenbank | Worker ungesund, geplanter Zeitpunkt nicht erreicht, verlorener Besitz, verwaister Auftrag |
| Worker → Ansible-Host | Worker erzeugt Inventar/Variablen, überträgt sie per SFTP und startet per SSH | Auftragsprotokoll nennt Preflight, Schrittmarker und Remote-Ausgabe | SSH-Exitcode und gestreamte stdout/stderr-Zeilen | DNS/Port, SSH-Authentisierung, fehlende Toolchain, falsche Portal-Rückadresse |
| Ansible-Host → ESXi | Playbook ruft `community.vmware` mit Credential, Trust-Modus und VM-Identität auf | Schrittmarker sowie Ansible-Taskergebnis; vor Mutationen Name+Instance-UUID | Modulresultat zurück an Ansible/Worker | Zertifikat/CA, Berechtigung oder Lizenz, Datacenter/Datastore, fremde namensgleiche VM |
| ESXi/Ansible → MAC-Rückruf | Export-Schritt sammelt MOID, Instance-UUID und MACs | Auftragsprotokoll zeigt Uploadversuch und API-Antwort | `db_importMAC.php` schreibt nur zum passenden `mission_id`/`job_id` und laufenden beziehungsweise abbrechenden Auftrag | IP-Allowlist, HTTP/TLS-Pin, fehlende `job_id`, Identitäts- oder MAC-Konflikt |
| Portal → MECM Device-Sync | Geplante MECM-Aufgabe liest Geräte mit bekannter DHCP-MAC | Systemstatus zeigt Start/Abschluss und Ursachen; Kategorie `mecm` | `mecm_updateid.php` meldet ResourceID und Provenienz der Collection-Mitgliedschaften | MECM-Provider, fehlende Collection, doppelte MAC, ResourceID fehlt, Rückreport abgelehnt |
| MECM → PXE-VM | Collection/Task Sequence ist bereit und VM startet | MECM-Status plus Portalstufe 4/5 | Windows-Client meldet Phasen an `mecm_report.php` | Verteilung noch nicht erfolgreich, falsche Task Sequence, PXE-/Netzproblem |
| Windows-Client → Portal | Clientphasen melden `started`, `finished` oder `failed` anhand der MAC | **VM bearbeiten → Client-Phasen** mit Zeit, Ergebnis und Detail | Report-Endpoint speichert ausschließlich Telemetrie; keine Lifecycle-Schreibabkürzung | API nicht erreichbar, Zertifikatswechsel/Pin, kein passender Adapter oder Datenträger |

## Zwei Ansible-Nachweise, zwei Aussagen

Der Systemstatus hält den manuellen **Volltest** und den letzten **beendeten Missionsauftrag** absichtlich getrennt. Der Volltest prüft aus dem Portal heraus SSH, die vollständige Toolchain, einen echten SFTP-Transfer sowie – bei konfigurierter Rückadresse – Portal-Erreichbarkeit und IP-Allowlist. Er läuft nicht automatisch. Nach `VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS` Tagen heißt sein Zustand deshalb „Test veraltet“: kein bekannter Fehler, aber auch kein aktueller Gesamtnachweis. Ein bekannter Fehlschlag altert nicht ins Neutrale.

Der Missionsnachweis wird direkt aus `deploy_jobs` gelesen: neuester terminaler Auftrag je `credential_ansible_id`, nur mit Mission; `queued`, `running`, `cancelling` und missionslose Inventaraufträge zählen nicht. Sein Status, Zeitpunkt und Jobprotokoll belegen den tatsächlich gelaufenen Modus. Ein erfolgreicher Start- oder Shutdown-Auftrag beweist beispielsweise weder SFTP-Neuaufbau noch MAC-Rückkanal und färbt daher die Volltest-Ampel nicht grün. Bei Zeitgleichheit entscheidet die höhere Job-ID. Es gibt keine zweite Statuskopie und kein zusätzliches Laufzeitprotokoll.

„Volltest jetzt starten“ im Systemstatus verwendet denselben CSRF-/RBAC-geschützten Handler wie die Seite Zugangsdaten. Das Ergebnis aktualisiert `deploy_ansible_preflight_state` und schreibt wie bisher genau eine Auditzeile in **Protokolle → Sicherheit**, Kategorie `credentials`; **Prüfprotokolle öffnen** führt dorthin. Missionsausgabe bleibt ausschließlich in `deploy_job_logs`, erreichbar über den direkten Link am Missionsnachweis.

## Ein Auftrag, den die Aufsicht beendet hat

Steht als letzter Fehler „Reaped stale deploy job after missing heartbeat", hat nicht der Auftrag versagt, sondern es kam für die Dauer des Fensters kein Herzschlag an. Der Satz dahinter nennt, was die Aufsicht dazu feststellen konnte: meldet sich der Bereitstellungsdienst weiterhin, ist er nicht gestorben, der Herzschlag blieb aus einem anderen Grund aus (meist eine kurz nicht erreichbare Datenbank), und der Playbook-Lauf kann auf dem Ansible-Host noch weiterlaufen. Dann ist ein Neustart des Dienstes die falsche Maßnahme; der Auftrag wird erneut ausgeführt, nachdem der Lauf beendet ist. Meldet sich der Dienst ebenfalls nicht mehr, ist er der Befund, und der Systemstatus zeigt ihn.

Eine Aufsicht, die selbst gerade erst verbunden ist, urteilt nicht: sie kann in dieser Zeit einen toten Dienst nicht von ihrer eigenen Blindheit unterscheiden. Nach einem Neustart des Wartungsdienstes oder einem Datenbankausfall bleibt ein verwaister Auftrag deshalb kurz stehen, bevor er beendet wird. War die Aufsicht durchgehend verbunden, entsteht keine Verzögerung.

## Vor jeder Mutation: VM-Identität

Der VM-Name ist nur die Suche, nicht der Identitätsbeweis. Portal und Playbooks verwenden die gespeicherte Instance-UUID; die MOID ist der aktuelle Hostgriff und darf sich nach erneuter Registrierung ändern. Eine unbekannte namensgleiche VM blockiert. Die ausdrücklich bestätigte Adoption ist nur erlaubt, nachdem ein Administrator die VM am Host geprüft hat; sie speichert die Identität und verändert weder Hardware noch Energiezustand.

## Abbruch und Teilfehler

Ein laufender Abbruch wechselt zuerst auf `cancelling`. Der Auftrag bleibt aktiv und blockiert Löschen oder einen zweiten Missionsauftrag, bis der Worker `cancelled` bestätigt oder der Reaper einen toten Worker sicher konvergiert. Ein MAC-Rückruf zum noch abbrechenden, korrekt zugeordneten Auftrag wird angenommen; nach `cancelled` wird er abgelehnt und hinterlässt eine sichtbare Spur.

Mehrere VM-Ergebnisse werden nicht zu einem falschen Gesamterfolg verdichtet. Das Auftragsprotokoll und die Ergebnisdaten unterscheiden erfolgreiche, fehlgeschlagene und nicht ausgeführte Teilziele. Ein Wiederholungsauftrag übernimmt nur den dafür vorgesehenen Umfang.

## Diagnose

Vom Symptom aus führt die [Störungsdiagnose](troubleshooting.md) zur richtigen Portal-Seite, Log-Kategorie und ersten Maßnahme. Die Supportgrenzen für Standalone ESXi und vCenter stehen in der [Deployment-Matrix](../DEPLOYMENT.md); die Begriffe der Kette stehen im [Glossar](../GLOSSARY.md).
