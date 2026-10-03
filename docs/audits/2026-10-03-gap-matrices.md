# Lückensuche mit Matrizen (03.10.2026)

## Auftrag

Der Nutzer will weitere Lücken systematisch aufdecken, nicht zufällig. Jede Matrix beginnt mit einer vollständigen Liste aus dem Code, stellt jedem Eintrag dieselben Fragen und hält leere Antworten als Lücke fest. Die Instrumente und ihre Reihenfolge stehen im [Ablaufprüfplan](2026-09-28-deploy-flows-review-plan.md), Abschnitt „Abdeckung der Abläufe und Prüfinstrumente“ (PI-01 bis PI-10). Diese Datei hält die Matrizen und ihre Befunde.

**Nutzerentscheid 03.10.2026:** In dieser Sitzung keine Codeänderung; Ergebnisse nur als Doku.

**Beleg:** Codelesung am Stand `3b37025`; spätere Commits dieses Branches ändern nur Doku. Klassen wie in der [PowerShell-Prüfung](2026-09-28-powershell-audit.md): B = im Code belegt, V = Verdacht, braucht eine Probe. Prioritäten P1 bis P3. Kein Befund ist durch einen Testlauf bestätigt; jeder Fix beginnt mit einem Test, der den Befund zeigt.

## Schritt 1: Zustands- und Schreibermatrix (PI-02)

**Vorgehen:** Suche über `Docker/WebAPI/lib` und die Maschinen-API-Dateien im Wurzelverzeichnis nach jedem `UPDATE` und `INSERT` auf die Zustandsfelder und nach den Aufrufern der gemeinsamen Schreibhilfen; Migrationen nur zusammengefasst. Jede Stelle wurde gelesen. Fragen je Schreiber: Wer löst aus, in welchem Modus? Welche Bedingung gilt vorher? Was wird geschrieben? Unter welcher Sperre oder Revisionsprüfung? Was geschieht mit dem vorigen Zustand? Entsteht ein Eintrag im Statusverlauf? Gibt es Tests (Anzahl Testdateien, die die Funktion nennen)?

### VM-Lebenszyklus und MECM-Zustand

| Schreiber | Auslöser | Bedingung vorher | Ergebnis | Schutz | Verlauf | Tests |
|---|---|---|---|---|---|---|
| VM anlegen: `repo/vms_persistence.php`, Vorlage klonen in `repo/missions.php`, `mission_transfer_import.php`, `repo/vms_legacy.php` | Portal | neue Zeile | `ready` / `not_ready` | Transaktion | ja | ja |
| `deploy_worker_mark_vms_deploying()` | Worker, jeder Modus, nach dem Netz-Preflight | jeder Lifecycle außer VMs mit MAC-Import in diesem Auftrag | `deploying`; MECM unverändert; voriger Lifecycle nur im Speicher des Workers | Auftragsbesitz, Vergleich auf vorigen Lifecycle | ja | 6 |
| `deploy_worker_restore_deploying_vms()` | Worker: Erfolg ohne Export; Ende der Create-Phase im Modus `create` | `deploying` und voriger Lifecycle im Speicher bekannt | voriger Lifecycle | Auftragsbesitz, Vergleich | ja | 4 |
| `deploy_worker_fail_locked_job_vms()` über `deploy_worker_handle_failure()` und den Reaper (`repo/deploy_job_maintenance.php`) | Fehlschlag, Reaper | jeder Zustand außer VMs mit MAC-Import in diesem Auftrag | `failed` / `failed` | Auftragsbesitz, Vergleich auf beide Zustände | ja | 6 |
| dieselbe Funktion über `deploy_worker_confirm_owned_cancel()` | bestätigter Abbruch | nur noch `deploying` | `failed` / `failed` | Auftragsbesitz, Vergleich | ja | 1 |
| `repo_sweep_orphaned_deploying_vms()` | Wartungsdienst | `deploying` ohne aktiven Auftrag der Mission | `failed` / `failed` | Zeilensperre, Vergleich | ja | 4 |
| `db_importMAC.php` | MAC-Rückruf im Export-Schritt (`export`, `powercycle`, `full`) | **keine Bedingung an Lifecycle oder MECM-Zustand** | `deployed` / `pending`, `updated = 1`, MOID und UUID per `COALESCE` | Missionssperre, VM-Zeilen gesperrt, Ergebnis-Fingerprint | ja | 17 zum Import, keiner mit bereits registrierter VM |
| `repo_set_vm_state_forward()` über `mecm_updateid.php` | Devices Sync, `updateDevice` | Revisions-Fence `accept` (noch keine Bindung); bei `noop` (gleiche ResourceID) kein Zustandsschreiben | Lifecycle nur vorwärts auf `os_installing`, MECM `registered`, `mecm_id` | VM-Zeilensperre, Revisions-Fence | ja | 2 |
| `repo_set_vm_state_forward()` über `mecm_client_ack.php` | Client-ACK am Ende der Task Sequence | Revisions-Fence | `os_installed` / `registered` | Zeilensperre, Fence, idempotent | ja | 2 |
| `repo_reset_vm_mecm_id()`, `repo_bulk_reset_mecm_ids()` | Portal „MECM-ID zurücksetzen“ | geschlossene Ablehnungsgründe | `deployed` / `pending`, `mecm_id` leer, Tombstone, Revision und Transfer-Generation erhöht | Sperre | ja | 1 und 2 |
| `repo_mark_vm_for_mecm_resync()` | Portal „An MECM übertragen“ | nur `registered` | nur `updated = 1`, Transfer-Generation erhöht | Zeilensperre | ja | 3 |
| `lib/migrate.php` | Migration | einmalig | Rückzug von `submitted`, Nachtrag der Beobachtungszeit | — | — | — |

### Auftragsstatus

Einreihen (`queued`) an drei Stellen in `repo/deploy_job_queue.php` (Einzelauftrag, Staffelgruppe, Systemauftrag); Beanspruchen `queued` → `running` und Abschluss `running` oder `cancelling` → Endzustand in `repo/deploy_job_worker.php`, beide mit Vergleich auf Besitzer und Status; Abbruch `queued` → `cancelled` und `running` → `cancelling`, Gruppenabbruch und Bestätigung `cancelling` → `cancelled` in `repo/deploy_job_cancel.php` unter Zeilensperre; Reaper `running` oder `cancelling` → `failed` oder `cancelled` in `repo/deploy_job_maintenance.php`. Der MAC-Rückruf schreibt nur `result_json`. **Ergebnis:** Jeder Übergang ist durch Zeilensperre oder Vergleich geschützt; keine neue Lücke.

### Create-Einheiten

Anlegen als `pending` (`repo_deploy_create_materialize()`, bei Wiederholung `repo_deploy_create_materialize_retry()`), Übergänge mit Besitz-Fence (`repo_deploy_create_transition()`), Lebenszeichen (`repo_deploy_create_touch_running()`), Erfolg mit Identität (`repo_deploy_create_commit_success()`), Reaper `prepared` oder `running` → `uncertain` (`repo_deploy_create_converge_reaped()`), Freigabe `uncertain` → `failed` mit `operator_released` (`repo_deploy_create_release_unit()`). **Ergebnis:** geschützt; bekannt bleibt FC2-02 (Belege bei abgelehntem Commit).

### Identität und MECM-Bindung

| Schreiber | Schreibt | Bedingung und Folgen |
|---|---|---|
| `repo_deploy_create_commit_success()` | MOID, Instance-UUID | nur beim Create-Erfolg; ein Ersatz (IDR-P02) löscht importierte MACs und dreht die Generation der Paketberichte (ADR-0044); das Protokoll nennt den MECM-Schritt |
| `repo_vm_identity_adopt_locked()` über `repo_adopt_vm_identity()` | MOID, Instance-UUID aus dem Inventar-Cache | Missionssperre, keine aktiven Aufträge; **keine Bedingung an eine bestehende Bindung**, keine weiteren Folgen (WM-03) |
| `db_importMAC.php` | MOID, Instance-UUID per `COALESCE`, MACs | nur bei widerspruchsfreier Identität |
| `mecm_updateid.php` | `mecm_id`, löscht den Tombstone | Revisions-Fence |
| `repo_reset_vm_mecm_id()` | `mecm_id` leer, Tombstone, Revision, Rolloutname | Hostname-Reservierung nur über `repo_vm_hostname_claims_sync()` |

### Befunde

| ID | Klasse / Prio | Befund | Maßnahme |
|---|---|---|---|
| WM-01 | B, MECM-Seite V / P1-Kandidat | Der MAC-Rückruf setzt jede VM mit gültigem Export-Ergebnis auf `deployed` / `pending` und `updated = 1`, auch eine längst registrierte oder installierte VM mit unveränderter MAC. Der Devices Sync findet das vorhandene Gerät und meldet dieselbe ResourceID; `mecm_rollout_fence_decide()` antwortet `noop`, und `mecm_updateid.php` schreibt dann keinen Zustand. Die VM bleibt dauerhaft `pending`, steht deshalb in jedem Lauf in `getDeviceList` (Auswahl `updated = 1 OR mecm_sync_state = pending`) und wird in jedem Takt des Devices Sync (Standard 10 s) neu verarbeitet; nach der Warnfrist zählt die Fortschrittsbeobachtung sie als überfällig. „An MECM übertragen“ lehnt die VM ab, und die Umbenennungssperre der Mission fällt weg, sobald keine VM mehr `registered` ist. Auslöser ist jeder `export`, `powercycle` oder `full` auf eine Mission mit schon ausgerollten VMs; leere Auswahl heißt ganze Mission. Auch der naheliegende Rückweg aus FC2-01, ein erneuter Export, endet hier. Kein Test deckt einen Export auf eine registrierte VM ab. | Entscheid WM-E1. Test: Export auf eine registrierte VM mit unveränderter MAC. Probe lesend: `SELECT id, mission_id, vm_name, lifecycle_state, mecm_id, mecm_pending_since FROM deploy_vms WHERE mecm_sync_state = 'pending' AND mecm_id IS NOT NULL AND mecm_id <> '';` Im normalen Ablauf hat eine VM in `pending` keine gebundene ResourceID; Treffer sind Kandidaten für WM-01. |
| WM-02 | B / P2 | Der vorige Lifecycle einer Auftrags-VM liegt nur im Speicher des Workers (`$priorLifecycles` aus `deploy_worker_mark_vms_deploying()`); der vorige MECM-Zustand wird gar nicht gemerkt. Nach Absturz, Reaper oder Sweep lässt er sich nicht wiederherstellen. Das ist ein Teil der Ursache von FC2-01. | Den Vorzustand (Lifecycle und MECM) je Auftrag und VM dauerhaft speichern; Voraussetzung für jede Lösung von FC2-E1. |
| WM-03 | B / P2 | „Identität übernehmen“ überschreibt auch eine bestehende Bindung an eine andere Instance-UUID: `repo_vm_identity_conflicts()` meldet diesen Fall als Konflikt, die Sperre bietet die Übernahme an, sobald das Inventar MOID und UUID kennt. Anders als der Create-Ersatz (IDR-P02) bleiben die importierten MACs der alten Instanz stehen, die Generation der Paketberichte wird nicht gedreht, und es gibt keinen MECM-Hinweis. Das Audit nennt nur die neuen Werte, nicht die abgelöste Bindung; der Bestätigungstext (`identity_adopt_confirm`) erwähnt das Ersetzen nicht. Zwei Schreiber desselben Übergangs mit verschiedenen Garantien. Kein Test deckt eine Übernahme bei bestehender Bindung ab. | Entscheid WM-E2. |
| WM-04 | B / P3 | `StatusWriterContractTest` erklärt „One writer per stage“ und nennt `db_importMAC.php` als einzigen Schreiber der Stufe 3/5 (`deployed`). „MECM-ID zurücksetzen“ schreibt dieselbe Stufe; der Test prüft nur, dass der genannte Endpunkt schreibt, nicht, dass kein anderer es tut. | Reset als zweiten Schreiber der Stufe aufnehmen und die Aussage im Test angleichen; die Hilfe zu 3/5 um den Fall „nach Reset“ ergänzen. |
| WM-05 | B / P3 | Der MAC-Rückruf setzt `updated = 1`, ohne `mecm_transfer_generation` zu erhöhen; Reset und „An MECM übertragen“ erhöhen sie. Ein Devices-Sync-Lauf, der die Generation vorher gelesen hat, kann den Merker des Rückrufs löschen. Folgenarm, solange `pending` die VM ohnehin in `getDeviceList` hält, aber eine zweite Regel für denselben Merker. | Generation auch im MAC-Rückruf erhöhen oder begründen, warum nicht. |
| WM-06 | V / P3 | Die Eindeutigkeit einer MAC über alle VMs prüft nur der MAC-Import (`mac_import_validate_duplicate_macs()`); der Index auf `deploy_interfaces.mac` ist nicht eindeutig. `mecm_client_ack.php` und `getDeviceInfos` wählen bei Doppel die erste Zeile per `LIMIT 1` ohne Ordnung. Ob manuell im Editor eingetragene MACs gegen andere VMs geprüft werden, ist offen. | Prüfen, ob der Editor Doppel zulässt; wenn ja, Eindeutigkeit an einer Stelle erzwingen. |
| WM-07 | B / P3 | VirtuSphere schreibt beim Anlegen keine Herkunftsmarke an die ESXi-VM (`createVMLaunch-ESXi_playbook.yml` setzt keine Notiz und kein Attribut). Das Portal kann eine früher von VirtuSphere angelegte VM deshalb nicht von einer von Hand angelegten unterscheiden; der Grundsatz GR-01 (unten) hängt bei „Identität übernehmen“ allein am Urteil des Operators. | Mit WM-E2a: Marke in der Notiz der VM beim Anlegen (etwa „VirtuSphere, Portal-VM <id>“), Inventar liest sie, Übernahme nur für markierte VMs. Bestehende VMs haben keine Marke. |
| WM-08 | B, Häufigkeit V / P3 | Der Devices Sync übernimmt im ersten Rollout ein schon vorhandenes MECM-Gerät, wenn Rolloutname und MAC eindeutig auf denselben Datensatz zeigen (`Resolve-VsDeviceIdentity()` in `Powershell-MECM/mecm/VirtuSphere-Common.ps1`, Regel 3). Gedacht ist das für einen verlorenen Rückruf nach dem eigenen Import; die Regel kann ein eigenes Importgerät aber nicht von einem von Hand angelegten unterscheiden. Nach GR-01 dürfte ein von Hand angelegtes Gerät nicht gebunden werden. | Entscheid WM-E3. |

**Bestätigt, ohne neue ID:** FC2-01 gilt auch für Reaper und Sweep; WM-01 und WM-02 erweitern es. FC2-02 bleibt. Dass `initializing` keinen Schreiber hat, ist bekannt und von `StatusWriterContractTest` festgehalten.

**Ansatzpunkt für den Wächter:** `StatusWriterContractTest` hält heute drei Stufen-Schreiber fest. Erweitert auf alle Schreiber von `lifecycle_state` und `mecm_sync_state` (mit derselben Suche über Endpunkte und `lib`) wird diese Matrix zum Wächter aus PI-02. Nur Vorschlag.

### Entscheidungen (Nutzer, 03.10.2026)

**Grundsatz GR-01:** Was in ESXi oder MECM von Hand angelegt wurde, landet nicht im Portal. Das Portal verwaltet nur, was es selbst angelegt hat.

| ID | Frage | Entscheidung |
|---|---|---|
| WM-E1 | Was soll ein Export mit einer VM tun, die MECM schon kennt (gespeicherte ResourceID)? | Den Zustand nicht anfassen, nur die MAC vergleichen. Gleiche MAC: nichts ändern, die VM zählt im Auftrag als unverändert. Andere MAC: die VM als fehlgeschlagen melden mit dem Hinweis „MAC geändert, MECM kennt noch die alte; bitte MECM-ID zurücksetzen“. VMs ohne ResourceID: wie heute. |
| WM-E2 | Darf „Identität übernehmen“ eine bestehende Bindung ersetzen? | Nein, folgt aus GR-01: Eine von Hand neu gebaute ESXi-VM ersetzt nie die Bindung einer Portal-VM. Variante B (Ersetzen mit denselben Folgen wie beim Create) entfällt. |
| WM-E2a | Bleibt „Identität übernehmen“ für ungebundene VMs? | Nein: Die Funktion wird zurückgebaut. Alt-VMs ohne Bindung aus der früheren Desktop-App oder älteren Portalversionen gibt es noch; sie werden nicht übernommen, die Altlast wird ignoriert. Eine namensgleiche VM auf ESXi blockiert dann immer. Damit erledigen sich WM-03 und der Übernahme-Teil von WM-07. |

**Rückbau-Skizze zu WM-E2a, für die spätere Umsetzung:** Aktion `adopt_vm` in `lib/deploy_actions.php`, `repo_adopt_vm_identity()` und `repo_vm_identity_adopt_locked()` entfallen. Die Identitätssperre in `lib/deploy_blockers.php` bietet statt der Übernahme einen Hinweis an: VM auf ESXi umbenennen oder löschen, oder die Portal-VM löschen. Nachzuziehen sind die Hilfe DE/EN, der Abschnitt „VM identity, collision block and adoption“ in `docs/DEPLOYMENT.md` und die Übernahme-Tests in `VmIdentityCollisionTest`. Das Audit-Ereignis der Übernahme bleibt für alte Einträge lesbar.

| WM-E3 | Darf der Devices Sync im ersten Rollout ein vorhandenes MECM-Gerät mit gleichem Namen und gleicher MAC übernehmen (WM-08)? | Nur Geräte, die der Sync nachweislich selbst importiert hat: Der MECM-Server führt dafür eine eigene Importliste, ähnlich dem Mitgliedschafts-Journal. Ein fremdes Gerät blockiert mit der Meldung „Gerät existiert schon in MECM, nicht von VirtuSphere importiert“. Bereits gebundene VMs sind nicht betroffen. Umsetzung mit den Serverskripten erst zum Cutover (MC-R4). |

## Schritt 2: Verbindungen, Vertrauensanker und Geheimnisse (PI-03)

**Vorgehen:** Jede Verbindung zwischen Browser, Portal, Datenbank, Worker, Ubuntu-Host, ESXi, MECM-Server, Clients, Domänencontrollern und Backup-Ziel aus dem Code abgeleitet (Verbindungsaufbau, Zugriffsprüfungen der Endpunkte, Trust-Einstellungen), dazu jedes Geheimnis mit Speicherung, Transport, Dateien auf Hosts, Schwärzung, Backup und Wechsel. Fragen: Weist sich der Server aus? Weist sich der Client aus? Welches Geheimnis geht darüber? Woran hängt das Vertrauen? Stand `3b37025`, nur Codelesung.

### Verbindungen

| Verbindung | Protokoll | Server weist sich aus | Client weist sich aus | Geheimnisse darauf | Anker |
|---|---|---|---|---|---|
| Browser → Portal | HTTP oder HTTPS (wahlweise, mit Weiterleitung und HSTS) | Zertifikat, wenn HTTPS an | Anmeldung lokal oder per LDAPS, Sitzung, CSRF | Benutzerpasswort, Sitzungscookie | Zertifikat; ohne HTTPS keiner. LDAPS-Anmeldung ist ohne HTTPS gesperrt (`lib/directory_config.php`) |
| PHP und Worker → MySQL | MySQL im Docker-Netz | internes Netz | Benutzer und Passwort aus `.env` | DB-Passwort | Docker-Netz |
| Worker → Ubuntu-Host | SSH und SFTP (`lib/ssh.php`, `lib/ssh_sftp.php`) | **nicht geprüft** (AB-01, entschieden mit AB-E1) | Passwort | Ansible-Passwort; per SFTP `accounts.yml` mit ESXi-Zugangsdaten | keiner |
| Ubuntu-Host → ESXi | HTTPS über pyVmomi | `strict`: CA aus `esxi-trust.pem`; `legacy_insecure`: keine Prüfung | ESXi-Benutzer und -Passwort | ESXi-Passwort | Vertrauensmodus je Zugang, neu standardmäßig `strict` |
| Ubuntu-Host → Portal, MAC-Rückruf | HTTP oder HTTPS (`upload_mac_list.py`) | Zertifikats-Pin bei HTTPS | IP-Freigabe, Auftrag und Mission | keine | Pin |
| MECM-Server → Portal | HTTP oder HTTPS mit TLS 1.2 und Zertifikats-Pin | Pin | IP-Freigabe; Token, wenn eingerichtet, nur für `heartbeat` und `reportRun` | Token-Header | Pin |
| Clients → Portal (`getDeviceInfos`, Client-ACK, `reportPhase`, Paketberichte) | HTTP oder HTTPS | Systemvertrauen des Clients (ADR-0019) | IP-Freigabe **oder bekannte MAC** | keine | — |
| Portal → Domänencontroller | LDAPS | gespeichertes CA-Zertifikat | Dienstkonto | Bind-Passwort; Benutzerpasswort bei Anmeldung | CA |
| App-Host → Backup-Ziel | Dateien, Abholung vom Backup-Host | — | — | `.env` (APP_KEY, DB-Passwörter), HTTPS-Schlüssel, Dump mit verschlüsselten Zugangsdaten | Zugriffsschutz des Ziels (ADR-0017) |

### Geheimnisse

| Geheimnis | Ruhend | Unterwegs und auf Hosts | Schwärzung | Backup | Wechsel |
|---|---|---|---|---|---|
| ESXi-Passwort | libsodium mit APP_KEY (`lib/repo/credentials.php`) | SFTP in `accounts.yml` (0600) je Auftrag; bleibt in Sonderfällen liegen (VT-04) | Auftragsprotokoll gegen beide Zugangsgeheimnisse; `error_detail` nur allgemein (FC2-08) | Dump verschlüsselt, Schlüssel im Config-Archiv (VT-05) | Zugang neu speichern |
| Ansible-Passwort | libsodium | SSH-Anmeldung ohne Server-Prüfung (AB-01) | wie oben | wie oben | Zugang neu speichern |
| LDAP-Bind-Passwort | libsodium (`lib/directory_config.php`) | LDAPS | — | wie oben | Konfiguration neu speichern |
| APP_KEY | `.env` auf dem App-Host | — | — | Config-Archiv | **kein Verfahren** (VT-06) |
| DB-Passwörter | `.env` | Docker-Netz | — | Config-Archiv | im Restore-Runbook beschrieben |
| Rückkanal-Token | SHA-256-Hash in den Einstellungen; auf dem MECM-Server in der Registry, nur für Administratoren lesbar | Header bei `heartbeat` und `reportRun` | — | Hash im Dump | in den Einstellungen neu erzeugen oder löschen |
| HTTPS-Schlüssel | Datei unter `/etc/nginx/ssl` | — | — | Config-Archiv | neues Zertifikat hochladen |
| Benutzerpasswörter | Hash mit Neuberechnung bei Anmeldung | Anmeldung, ohne HTTPS im Klartext | — | Hash im Dump | ändern oder zurücksetzen |

### Befunde

| ID | Klasse / Prio | Befund | Maßnahme |
|---|---|---|---|
| VT-01 | B / P2 | Die IP-Freigabe der Maschinen-API gilt für alle Endpunkte gleich (`machine_api_ip_allowed()` kennt keinen Endpunkt, die Freigabeliste hat nur IP und Beschreibung). Der Ubuntu-Host steht für den MAC-Rückruf darin und darf damit auch `updateDevice`, `reportMembership` und den Paketkatalog aufrufen; der MECM-Server umgekehrt den MAC-Rückruf. Der Token schützt, wenn eingerichtet, nur `heartbeat` und `reportRun`, nicht `updateDevice`, `reportMembership`, den Paketkatalog oder den MAC-Rückruf. Zusammen mit AB-01 ergibt das eine Kette: Wer das Ansible-Passwort abgreift, kontrolliert einen freigegebenen Host und kann MECM-Bindungen und Katalog verändern. | Freigabe je Rolle: MECM-Server, Ansible-Host und Clients nur für ihre Endpunkte. |
| VT-02 | B / P2 | Der Client-Kanal hat die bekannte MAC als einzige Berechtigung, auch wo er schreibt: Der Client-ACK ist der einzige Schreiber von Stufe 5/5 und setzt zugleich MECM `registered`. Die nötige Rollout-Revision liefert `getDeviceInfos` an jeden, der die MAC kennt, zusammen mit Hostname, Domäne, Betriebssystem, Mission, Generationen der Paketberichte und Netzkonfiguration. ADR-0018 nimmt die MAC-Berechtigung für Anzeige-Verkehr in Kauf, ADR-0044 sagt ausdrücklich, dass die Generationen keine Authentisierung sind. Der Nachtrag vom 09.08.2026 in ADR-0018 trennt den ACK gerade deshalb vom anzeigenden Kanal, weil er den Lifecycle schreibt, lässt ihm aber dieselbe Berechtigung. Ein Gerät im LAN, das eine MAC kennt, kann eine VM als installiert und registriert markieren. | Nach VT-E1: jetzt C, später B (unten). |
| VT-03 | B / P3 | Ohne HTTPS gehen lokale Anmeldungen und Sitzungscookies im Klartext; HTTPS ist wahlweise. Die LDAPS-Anmeldung ist ohne HTTPS richtig gesperrt. | Prüfen, ob das Portal ohne HTTPS sichtbar warnt; sonst Hinweis im Systemstatus. |
| VT-04 | B, Teil V / P2 | Das ESXi-Passwort bleibt in `accounts.yml` auf dem Ubuntu-Host liegen, solange eine Create-Einheit ungeklärt ist oder der Host beim Aufräumen nicht erreichbar war (`deploy_worker_cleanup_remote_dir()`: „left in place“, „reported, not resolved“). Eine zeitliche Grenze gibt es nicht. Verdacht V: Ansible legt bei Async-Aufrufen die Modulargumente samt Passwort in eigenen Arbeitsdateien auf dem Host ab. | Zugangsdaten vom Beleg trennen: `accounts.yml` immer am Auftragsende löschen, nur den Async-Status als Beleg behalten und für eine spätere Abfrage neu hochladen. Probe auf dem Ubuntu-Host nach einem Create: nach dem Passwort in `~/.ansible` und im Arbeitsverzeichnis suchen. |
| VT-05 | B / P3 | Backups enthalten Schlüssel und Chiffrat zusammen und unverschlüsselt: Das Config-Archiv trägt die `.env` mit APP_KEY, der Dump die verschlüsselten Zugangsdaten. Wer das Backup-Verzeichnis liest, kann alle ESXi-, Ansible- und LDAP-Passwörter entschlüsseln. ADR-0017 nimmt das mit Zugriffsschutz und Abholung durch einen zweiten Host bewusst in Kauf. | Entscheid VT-E2. |
| VT-06 | B / P3 | Für den APP_KEY gibt es weder ein Wechselverfahren noch ein Werkzeug zum Neuverschlüsseln; nichts in Code oder Doku. Nach einem Verlust von `.env` oder Backup bleibt nur, alle Passwörter an der Quelle zu ändern und neu einzutragen. | Runbook „APP_KEY wechseln“; optional ein Werkzeug, das die gespeicherten Geheimnisse mit neuem Schlüssel neu verschlüsselt. |
| VT-07 | B / P3 | Drift: Der Nachtrag vom 08.07.2026 in ADR-0018 sagt, der Token werde nur noch für `heartbeat` verlangt; `mecm_report.php` verlangt ihn für `heartbeat` und `reportRun`. | Nachtrag im ADR an den Code angleichen. |

### Entscheidungen zu Schritt 2 (Nutzer, 03.10.2026)

| ID | Frage | Entscheidung |
|---|---|---|
| VT-E1 | Reicht die bekannte MAC als Berechtigung für den Client-ACK, der eine VM auf „installiert“ und „registriert“ setzt? | In zwei Stufen. **Jetzt (C):** Der ACK setzt nur noch die Stufe 5/5 (`os_installed`) und nicht mehr MECM `registered`; `registered` schreibt allein `updateDevice` aus dem Devices Sync. Das ist eine reine Portaländerung: Antwort des Endpunkts und Clientskripte bleiben gleich, kein Cutover. **Später (B), mit dem Cutover MC-R4:** ein Einmalwert je Rollout als echte Berechtigung des ACK, erst nach einer Laborprobe. Anzeige-Meldungen (`reportPhase`, Paketberichte) bleiben bei der MAC-Berechtigung. |

**Was C begrenzt:** Wer eine MAC kennt, kann den MECM-Zustand einer VM nicht mehr verändern. Bis B bleibt möglich, eine VM fälschlich auf 5/5 zu setzen; für diese VM verstummt dann auch die Warnung bei überlanger Installation.

**Paketskizze C, für die spätere Umsetzung:**

- `mecm_client_ack.php` übergibt den vorhandenen MECM-Zustand der VM statt `registered`. Die Prüfung auf Wiederholung fragt nur noch Lebenszyklus und Altstatus ab; sonst schreibt jede Wiederholung des ACK bei `pending` ein weiteres Statusereignis.
- Selbstheilung geprüft (Codelesung): Kommt der ACK vor der Bindung, bleibt die VM bei `os_installed` / `pending`. `getDeviceList` liefert sie im nächsten Sync-Lauf wieder (`mecm_sync_state = pending`), der Zaun antwortet `accept`, und `repo_set_vm_state_forward()` setzt `registered`, ohne den Lebenszyklus zurückzustufen. Heute steht die VM in diesem Fall bis zum nächsten Sync-Lauf auf `registered` ohne ResourceID.
- Überwachung erweitern: `virtusphere_vm_progress_watch_kind()` in `lib/vm_progress.php` und die beiden Zählungen in `lib/repo/vms_operations.php` beobachten nur `deployed` / `pending` und `os_installing` / `registered`. `os_installed` / `pending` bliebe unsichtbar, wenn der Sync nie bindet. Neu: `pending` unabhängig vom Lebenszyklus über `mecm_pending_since` beobachten; der Zeitstempel bleibt beim ACK erhalten.
- Rot vor Fix: `MachineApiWireTest::testClientReadyAcknowledgementIsPostOnlyAndIdempotent` erwartet heute `registered` nach dem ACK; künftig bleibt der MECM-Zustand unverändert. Dazu ein Fall „ACK vor Bindung, danach `updateDevice`“, der bei `os_installed` / `registered` endet.
- Doku: kurzer Nachtrag in ADR-0019 (der ACK schreibt nur den Lebenszyklus). Hilfe, Flowcharts und `docs/ai/contracts/machine.md` binden `registered` nicht an den ACK und bleiben, wie sie sind.

**Skizze B, mit dem Cutover MC-R4:**

- Das Portal erzeugt je Rollout einen Zufallswert, speichert nur dessen Hash und gibt den Wert dem Devices Sync über `getDeviceList` mit.
- Der Sync legt ihn als maskierte MECM-Gerätevariable an. Der Client schickt ihn mit dem ACK; das Portal vergleicht mit `hash_equals` und verwirft den Wert nach Gebrauch. Ein Reset des Rollouts erzeugt einen neuen Wert.
- Laborprobe vorher, nur lesend: Die Client-Apps sind MECM-Anwendungen. Gerätevariablen sind regulär nur innerhalb einer Tasksequenz als Variablen lesbar, also klären, ob `client_getInfos.ps1` dort läuft (Schritt „Anwendung installieren“) oder als normale Bereitstellung. Im zweiten Fall muss ein Schritt der Tasksequenz den Wert vorher geschützt ablegen. Außerdem prüfen, ob ein maskierter Wert in `smsts.log` erscheint.

### Offene Entscheidungen (Nutzer)

| ID | Frage | Vorschlag |
|---|---|---|
| VT-E2 | Sollen Backups verschlüsselt oder der APP_KEY getrennt von den Dumps gesichert werden? | Archive mit einem Schlüssel verschlüsseln, der nicht auf dem App-Host liegt; mindestens das Config-Archiv getrennt vom Dump ablegen. |

## Schritt 3: Fehlerfälle entlang der Gesamtkette (PI-05)

**Vorgehen:** Die Kette in vier Abschnitte geteilt und an jedem Übergang die zehn Fehlerarten der Edge-Case-Checkliste aus dem Register geprüft: fehlende Konfiguration, leere Eingabe, Abbruch, Absturz und Neustart, Zeitüberschreitung, Teilerfolg, unbekannter Ausgang, parallele Instanz, veraltete Daten, Wiederholung. Je Fall vier Fragen: Wer erkennt ihn? In welchem Zustand enden VM, Auftrag und MECM? Was sieht der Operator? Welcher Test deckt ihn ab? Ein „—“ ist eine leere Zelle und damit eine Lücke. Tests nur nach Namen und Inhalt gesucht, nicht ausgeführt. Bekannte Befunde stehen als Verweis. Kanten-IDs in den Diagrammen und der Wächter aus PI-05 brauchen Code und bleiben offen. Stand `3b37025`, nur Codelesung.

### Abschnitt 1: Portal und Worker bis zum Start von Ansible

| Fall | Erkennung | Zustand danach (VM, Auftrag, MECM) | Signal für den Operator | Test |
|---|---|---|---|---|
| Voraussetzung fehlt beim Einreihen: Zugang, API-Basis-URL, Datastore, Netz, namensgleiche fremde VM, ungeklärte Create-Einheit | Einreihsperren in Seite und Repository | nichts eingereiht | Sperre mit Link | `DeployQueueBlockersTest`, `DeployPrerequisiteNoticesTest`, `VmIdentityCollisionTest`, `DeployCreateHistoricalFenceTest` |
| Zweiter Auftrag für dieselbe Mission, auch gleichzeitig | Missionssperre in der Einreihtransaktion | ein Auftrag | Sperre ohne Auftragsnummer und Link (FC2-06) | `DeployEnqueueRaceTest` |
| Mission, VM oder Zugang gelöscht oder Zugangsziel geändert, solange ein Auftrag wartet oder läuft | Sperre im Schreibpfad | unverändert | Validierungsfehler | `DeleteWhileDeployingTest`, `DeployCreateHistoricalFenceTest` |
| Netz oder Auswahl der Mission nach dem Einreihen geändert | Netzprüfung nach dem Beanspruchen | Auftrag `failed` (`configuration_blocked`), nichts hochgeladen | Ergebnis je VM mit Fortschritt | `DeployWorkerNetworkPreflightIntegrationTest` |
| Create mit anderem ESXi-Zugang oder nach geändertem Zugangsziel für schon gebundene VMs | — | neue VMs auf dem anderen Host, als Ersatz übernommen, MACs verworfen; die alten VMs laufen weiter | „Fehlende VMs angelegt“, als wäre die VM gelöscht worden | — (**FM-01**) |
| Start, Export, Power-Cycle oder Autostart mit einer nie angelegten VM im Umfang | erst die Identitätsprüfung im Playbook | Schritt scheitert, bei Start, Power-Cycle und Autostart für alle VMs; danach alle VMs `failed` und MECM `failed` (FC2-01) | Ansible-Meldung im Protokoll, Endgrund nur mit Exitcode (FC2-05) | — (**FM-02**) |
| Abbruch eines wartenden, laufenden oder gestaffelten Auftrags | Statuswechsel, Schrittgrenze | `cancelled`; VMs in `deploying` werden `failed` | Abschlussgrund und Abbruchzeile | `DeployCancellationStateMachineTest`, `DeployWorkerOutcomeTest` |
| Worker stirbt mitten im Auftrag | Reaper nach fehlendem Herzschlag und Schonfrist | Auftrag `failed` (`stale_heartbeat`), übrige VMs `failed` (FC2-01), importierte bleiben, fliegende Create-Einheiten `uncertain` | Abschlussgrund mit Beobachtung, bewusst ohne Ursache | `DeployJobReaperTest`, `DeployReaperCreateBatchTest`, `DeployReapObserverGraceTest`, `DeployWorkerOutcomeTest` |
| Zweite Worker-Instanz oder `--once` neben der Schleife | Besitz-CAS beim Beanspruchen | genau ein Besitzer | — | `DeployWorkerJobOwnershipTest`, `DeployClaimPriorityTest` |
| Datenbankausfall während eines Auftrags | DB-Kanal des Workers | Lauf geht weiter, Protokoll gepuffert | Zustandszeile im Containerlog, danach SYSTEM-Zeile im Protokoll | `DeployWorkerDbChannelTest`, `DeployWorkerDbChannelRecoveryTest` |
| Geplanter Auftrag kommt spät dran: Worker-Ausfall oder langer Auftrag davor | — | läuft ohne Obergrenze später; gestaffelte Aufträge ohne Abstand hintereinander | Liste zeigt den geplanten Zeitpunkt, nicht den tatsächlichen Start | Nachholwelle: Staffelaudit P4; Verfall: — (**FM-04**) |

### Abschnitt 2: Ansible und ESXi bis zum MAC-Rückruf

| Fall | Erkennung | Zustand danach (VM, Auftrag, MECM) | Signal für den Operator | Test |
|---|---|---|---|---|
| SSH-Anmeldung, DNS, Port, SFTP oder Werkzeug auf dem Ubuntu-Host scheitert | Host-Preflight, typisierte Transportfehler | Auftrag `failed`, VMs `failed` (FC2-01) | Kategorie `ansible_*` oder Name der Komponente | `DeployWorkerFailureClassificationTest`, `DeployWorkerTransportTypeTest`, `AnsiblePreflightTest` |
| Fremder Rechner antwortet unter der Adresse des Ubuntu-Hosts | — | Worker gibt Ansible-Passwort und ESXi-Zugang preis | — | — (AB-01, entschieden mit AB-E1) |
| Playbook-Schritt scheitert: ESXi-Zertifikat, Rechte, Lizenz, Datastore, Identität | Exitcode des Schritts | Auftrag `failed`, VMs `failed` (FC2-01) | Endgrund mit Schritt, Ursache nur im Protokoll (FC2-05) | `AnsibleStepMarkerTest` |
| Verbindung reißt ab oder Budget läuft ab, während ein Schritt läuft | Transport- oder Budgetfehler im Worker | Auftrag `failed`; der Fernlauf läuft womöglich weiter | Endgrund Budget oder Transport | nur Textprüfung des Traps (**FM-03**) |
| Create: Transport reißt ab, Budget aus, Status unlesbar | Ergebniszeile der Einheit | Einheit `uncertain`, Create-Phase hält an | Create-Karte; Freigabe erst nach Inventarabruf, ohne Link (FC2-03) | `DeployCreateWorkerFlowTest`, `CreateFlowWorkerContractTest`, `DeployCreateReleaseTest` |
| Create meldet Erfolg, das Binden wird abgelehnt | Commit lehnt ab | Einheit `failed`, die VM auf ESXi ist nicht mehr zuzuordnen | allgemeiner Satz | — (FC2-02) |
| Power-Cycle: Einschalten unklar oder Abbruch mitten im Zyklus | Playbook bricht ab | Auftrag `failed`; eine VM kann eingeschaltet bleiben | Hinweis „Zustand dieser UUID im ESXi Host Client prüfen“ nur im Protokoll (FC2-05) | `powercycle-sequence-contract.py` (qa-ansible) |
| MAC-Rückruf liefert ein Teilergebnis, keines oder nur Fehler | Auswertung des Ergebnisses | `partial` oder `failed`; nur gescheiterte VMs `failed`, importierte bleiben | Endgrund und Ergebnis je VM | `DeployWorkerOutcomeTest`, `DeployWorkerResultEvaluationTest` |
| MAC-Rückruf für einen fremden oder beendeten Auftrag, Wiederholung mit anderem Inhalt | Prüfung von Auftrag, Versuch und Fingerabdruck | 409 ohne Schreiben | gedrosselte Zeile im Auftragsprotokoll und im Audit | `MacImportCallbackTest` |
| Folgefehler oder Ernten nach angenommenem Rückruf | Ergebnis im gesperrten Auftrag | importierte VMs bleiben `deployed` / `pending` | — | `DeployWorkerOutcomeTest`, `DeployWorkerVmOwnershipRaceTest` |
| Zugangsdaten bleiben auf dem Ubuntu-Host: ungeklärte Einheit, Host beim Aufräumen weg | Aufräumen meldet „left in place“ | `accounts.yml` unbefristet | Protokollzeile | — (VT-04) |

### Abschnitt 3: MECM-Sync bis zur Bindung

| Fall | Erkennung | Zustand danach (VM, Auftrag, MECM) | Signal für den Operator | Test |
|---|---|---|---|---|
| Portal oder MECM-Provider nicht erreichbar | Lauf scheitert, Pause, Neuaufbau | VMs bleiben in der Warteschlange | MECM-Karte im Systemstatus mit Ursache des Laufs | `MecmReportWireTest`, `VirtuSphere.RunReport.Tests.ps1` |
| Zweite Instanz des Devices Sync | Aufgabenplanung startet keine zweite; Sperre des Journals | zweite Instanz endet ohne Änderung | nur Tageslog | `VirtuSphere.MembershipJournal.Tests.ps1` |
| Identität mehrdeutig, MAC oder Mission fehlt, Import oder Mitgliedschaft scheitert | Ursache je Gerät im Sync | VM bleibt `deployed` / `pending` in der Warteschlange | an der VM erst nach zwei Stunden „prüfen“, ohne Ursache und Link; Ursache nur unter den ersten zehn des letzten Laufs und im Tageslog | `VirtuSphere.RolloutIdentity.Tests.ps1`, `VmProgressWatchTest` (**FM-05**) |
| Mitgliedschaftsänderung mit unklarem Ausgang, etwa nach Absturz | Journal | Eintrag bleibt zur Klärung, VM wartet | Ursache `membership_operation_uncertain` | `VirtuSphere.MembershipJournal.Tests.ps1`, `VirtuSphere.MembershipApply.Tests.ps1` |
| `updateDevice` mit veralteter Revision, anderer ResourceID oder verlorener Antwort | Rollout-Zaun: `stale` oder `noop` | `stale`: VM bleibt in der Warteschlange; `noop`: unverändert | Warnung `resource_update_failed` oder `stale_rollout_revision` | `MecmProvenanceWireTest`, `MecmTransferQueueTest` |
| Erneuter Export einer registrierten VM | — | VM bleibt dauerhaft `pending` | — | — (WM-01) |
| Gebundene VM in ESXi oder ihr Gerät in MECM von Hand gelöscht | — | Portal bleibt beim letzten Stand, etwa 5/5 und `registered` | — | — (**FM-06**) |
| Portal-Zertifikat erneuert, MECM-Server pinnt den alten Fingerabdruck | TLS-Fehler auf dem MECM-Server | keine Läufe mehr, VMs bleiben in der Warteschlange | MECM-Karte ohne neue Läufe, Ursache nur im Tageslog | — (**FM-07**) |

### Abschnitt 4: Installation, Client-ACK, Clientphasen und Paketberichte

| Fall | Erkennung | Zustand danach (VM, Auftrag, MECM) | Signal für den Operator | Test |
|---|---|---|---|---|
| Tasksequenz scheitert vor den Clientskripten | nur in der MECM-Konsole | VM bleibt 4/5 | Warnung „Installation überfällig“ nach sechs Stunden, ohne Ursache | `VmProgressWatchTest` |
| Client erreicht das Portal nicht oder findet keine passende MAC | Skript endet mit Fehler, MECM wiederholt | VM bleibt 4/5 | keine Phasenmeldung möglich; Warnung nach sechs Stunden | `VmProgressWatchTest` |
| Antwort von `getDeviceInfos` unvollständig | Schemaprüfung im Client | VM bleibt 4/5 | Phase `getinfo` fehlgeschlagen, nur auf der Seite der VM | nur Textprüfung in `VirtuSphere.ErrorPaths.Tests.ps1` (**FM-09**) |
| ACK mit veralteter Rollout-Revision | Zaun im Endpunkt | 409, VM unverändert | Phase fehlgeschlagen, Ablehnung protokolliert | Zaun-Tabelle in `MecmRolloutContractTest`, kein Wire-Test (**FM-09**) |
| Antwort auf den ACK geht verloren | Wiederholung wird dedupliziert | 5/5, ein Statusereignis | — | `MachineApiWireTest` |
| ACK kommt vor der Bindung | — | heute `registered` ohne ResourceID; nach VT-E1 C `os_installed` / `pending` | nach VT-E1 C: Überwachung von `pending` (Paketskizze oben) | — (VT-E1) |
| Clientphase (Hostname, IP, Datenträger) scheitert oder bleibt unbestätigt, nach 5/5 | gemeldetes Ereignis oder Zeitablauf | VM bleibt 5/5 | nur auf der Seite „VM bearbeiten“ | — (**FM-08**) |
| Paketinstallation scheitert | Paketbericht | Bericht mit Fehler | Liste „Paketberichte“ mit Filter | `PackageRunReportWireTest`, `PackageRunRepositoryTest` |
| Portal-Zertifikat erneuert, Client-Bundle pinnt den alten Fingerabdruck | TLS-Fehler auf dem Client | VM bleibt 4/5 | keine Meldung möglich | — (**FM-07**) |

### Befunde

| ID | Klasse / Prio | Befund | Maßnahme |
|---|---|---|---|
| FM-01 | B / P2 | **Zielwechsel beim Create.** Die Bindung einer VM (`vm_moid`, `vm_instance_uuid`) kennt ihren Host nicht. Ein Create mit einem anderen ESXi-Zugang oder nach geändertem Zugangsziel findet auf dem neuen Host weder UUID noch Namen; `create_identity_check_tasks.yml` meldet das als ersetzte Instanz, und `repo_deploy_create_commit_success()` übernimmt die neue VM, rotiert die Paketgeneration und verwirft die MACs. Die alten VMs laufen auf dem alten Host weiter, ihre MECM-Geräte bleiben. Die Einreihsperren prüfen nur namensgleiche fremde VMs und ungeklärte Einheiten, den Datastore nur als gesetzt; ESXi nennt den lokalen Datastore auf jedem Host standardmäßig `datastore1`. Ein Zielwechsel ist erlaubt, sobald kein Auftrag und keine ungeklärte Einheit mehr am Zugang hängt. Der Identitätsplan sah für einen unklaren Zielwechsel „nicht feststellbar“ vor (IDR-S09, IDR-E25); P02 hat das nicht umgesetzt. | Entscheid FM-E1. Vorschlag: Bindung um den Host ergänzen, also Zugang und eine Host-Kennung aus dem Inventar (welche `vmware_host_facts` liefert, ist zu prüfen); Ersatz nur auf demselben Host. Rot vor Fix: Create mit einem zweiten Zugang für eine gebundene VM. |
| FM-02 | B / P2 | **Nie angelegte VM im Umfang von Start, Export, Power-Cycle oder Autostart.** Seit IDR-R2 verlangen diese Playbooks eine gebundene Instance-UUID (`lib/ansible_yaml.php`); die Einreihsperren prüfen das nicht, `lib/deploy_blockers.php` kennt nur Namenskonflikte. Eine neue VM in einer ausgerollten Mission, bei leerer Auswahl also die ganze Mission, lässt `startVMs`, `powercycleVMs` und `autostartVMs` für alle VMs scheitern, `exportVMs` nur für diese VM. Danach setzt FC2-01 alle VMs des Umfangs auf `failed` und MECM `failed`, auch registrierte. Bei einem geplanten Auftrag zeigt sich das erst am Termin. | Entscheid FM-E2. Vorschlag: Sperre beim Einreihen und nach dem Beanspruchen „VM X ist noch nicht angelegt“, mit Link auf Create. |
| FM-03 | B, Wirkung V / P2 | **Fernlauf nach Verbindungsende.** Der Worker beendet einen laufenden Schritt bei Budgetüberschreitung, Transportfehler oder verlorenem Besitz mit `$ssh->disconnect()` (`lib/ssh.php`). `ansible_remote_steps()` verlässt sich laut Kommentar darauf, dass sshd dabei HUP sendet und der Trap das Arbeitsverzeichnis löscht. phpseclib fordert aber kein Terminal an, und ohne Terminal sendet sshd beim Schließen des Kanals in der Regel kein HUP. Dann bleibt `accounts.yml` liegen (verstärkt VT-04), und das Playbook läuft weiter, bis eine Ausgabe in die geschlossene Leitung scheitert. In stillen Phasen, etwa der Pause vor dem Start oder im Power-Cycle, kann es VMs noch schalten, wenn das Portal den Auftrag schon als gescheitert führt; ebenso nach dem Ernten eines toten Workers. Eine Sperre auf dem Ubuntu-Host gibt es nicht; ein sofort neu eingereihter Auftrag kann parallel auf dieselben VMs wirken. Den MAC-Rückruf des alten Laufs weist das Portal ab (409), Schaltvorgänge nicht. `AnsibleStepMarkerTest` prüft nur den Text des Traps; die Zusage in `deploy-chain.md` („wird der entfernte Lauf beendet“) hängt an derselben Annahme. | Laborprobe auf einer Testmission: Auftrag `start` mit zwei Minuten Startwartezeit, während der Pause den Worker-Container hart beenden, danach auf dem Ubuntu-Host `pgrep -af ansible-playbook` und das Arbeitsverzeichnis prüfen und beobachten, ob die VMs noch eingeschaltet werden. Je nach Ergebnis: Schritt mit Terminal starten oder als eigene Prozessgruppe mit PID-Datei, die der Worker beim Ende gezielt beendet; dazu eine Sperre je Mission auf dem Host (`flock`). Entscheid erst nach der Probe. |
| FM-04 | B / P3 | **Geplanter Auftrag ohne Verfallszeit.** `repo_claim_next_deploy_job()` nimmt jeden fälligen Auftrag ohne Obergrenze (`scheduled_at <= UTC_TIMESTAMP()`). Nach einem Worker-Ausfall oder hinter einem langen Auftrag einer anderen Mission läuft ein für 06:00 geplanter Start auch Stunden später. Die Auftragsliste zeigt den geplanten Zeitpunkt und die letzte Änderung, nicht den tatsächlichen Start. Die Nachholwelle gestaffelter Aufträge plant das Staffelaudit (P4) schon, eine Verfallszeit nicht. | Entscheid FM-E3. |
| FM-05 | B / P3 | **Warten auf MECM ohne Ursache.** Der Devices Sync kennt die Ursache je Gerät (`mission_missing`, `mac_missing`, Identitätscodes, `device_import_failed`, `collection_missing`, Mitgliedschaftscodes, `resource_update_failed`), schickt aber nur Zähler und als Freitext die ersten zehn Ursachen des letzten Laufs (`Format-VsRunDetail()`, `VIRTUSPHERE_RUN_SUMMARY_FIELDS`). Die VM-Seite meldet erst nach zwei Stunden (`VIRTUSPHERE_VM_MECM_PENDING_WARN_SECONDS`) „MECM-Sync, DHCP-PXE-Schnittstelle und Collections prüfen“, ohne Ursache und ohne Link auf die MECM-Karte; das verletzt R11. | Ursache je VM melden (VM-ID und Code, begrenzt, nur Anzeige) und an der VM zeigen, sobald sie vorliegt, mit Link auf die MECM-Karte. Ändert den Laufbericht, kommt also mit den Serverskripten zum Cutover MC-R4. Der Link allein ist ein Portal-Fix. |
| FM-06 | B / P3 | **Kein Rückabgleich gebundener VMs.** Das Portal prüft eine gebundene VM nur, wenn ein Auftrag oder eine Übertragung sie anfasst. Wird sie in ESXi von Hand gelöscht oder ihr Gerät in MECM, bleibt das Portal beim letzten Stand, etwa 5/5 und `registered`. `getDeviceList` liefert registrierte VMs nicht mehr; der Sync bemerkt ein gelöschtes Gerät erst bei der nächsten Übertragung (`resource_id_missing`). Die Inventar-Abweichungen im Systemstatus vergleichen Werte wie VLAN, Datacenter und Datastore, keine VM-Bindungen. Gegenstück zu GR-01. | Lesender Abgleich: gebundene UUIDs gegen das letzte erfolgreiche Inventar ihres Hosts (braucht FM-01); im Devices Sync gebundene ResourceIDs gelegentlich lesend prüfen und als Ursache melden (mit FM-05). |
| FM-07 | B / P3 | **Zertifikatswechsel ohne Hinweis auf gepinnte Verbraucher.** MECM-Server und Client-Bundles können den Fingerabdruck des Portal-Zertifikats pinnen (`CertThumbprint`). Besteht das neue Zertifikat die Windows-Kettenprüfung nicht, etwa weil es selbstsigniert ist, scheitern danach Devices Sync, Laufberichte und Clients. Das Portal fragt beim Hochladen nur „Vorhandenes Zertifikat überschreiben?“. Danach kommen keine MECM-Läufe mehr an, die Ursache steht nur im Tageslog des MECM-Servers, Clients melden nichts. `https.md` beschreibt die Folge, das Portal nicht. | Hinweis in der Bestätigung und auf der HTTPS-Karte: neuer Fingerabdruck, betroffene Verbraucher, Befehl für den Installer, mit Link auf die Anleitung. |
| FM-08 | B / P3 | **Clientphasen nur an der einzelnen VM.** Eine gemeldete fehlgeschlagene oder unbestätigte Phase (Hostname, IP, Datenträger) nach 5/5 erscheint nur auf der Seite „VM bearbeiten“ (`lib/vm_edit_status.php`); Missionsliste, Dashboard und Systemstatus zeigen sie nicht. Eine gescheiterte IP-Umstellung bei einer von fünfzehn VMs fällt nur auf, wer jede VM öffnet. Paketberichte haben dagegen eine eigene Liste mit Filter. Ausbleibende Meldungen sind nach ADR-0018 kein Nachweis, eine empfangene Fehlermeldung schon. | Zähler und Badge „Clientphase fehlgeschlagen“ in Missionsliste und Dashboard mit Link auf die VM, nur aus empfangenen Meldungen. |
| FM-09 | B / P3 | **Testlücken am Client-Ende der Kette.** Die Ablehnung eines ACK mit veralteter Rollout-Revision (409) prüft nur die Zaun-Tabelle in `MecmRolloutContractTest`; einen Wire-Test wie für `updateDevice` (`MecmProvenanceWireTest`) gibt es nicht. Die Abbruchpfade von `client_getInfos.ps1` sind nur per Textprüfung abgedeckt (`VirtuSphere.ErrorPaths.Tests.ps1`). | Mit dem Paket zu VT-E1 C: Wire-Test für den abgelehnten ACK. |

**Bekannte Befunde, die Schritt 3 wieder trifft:** FC2-01 (fast jeder Fehlerweg in Abschnitt 1 und 2 endet mit allen VMs `failed` und MECM `failed`), FC2-02, FC2-03, FC2-05, FC2-06, AB-01, VT-04, WM-01, die Nachholwelle aus dem Staffelaudit (P4) und FC2-E6 (Start vor `registered`). Gut abgesichert sind Einreihen, Abbruch, Besitz, Datenbankausfall, MAC-Rückruf und das Mitgliedschafts-Journal.

**Muster:** Die meisten neuen Lücken liegen nicht im Fehlerweg, sondern im Signal. Der Code erkennt den Fall, das Portal zeigt ihn aber zu spät, an der falschen Stelle oder ohne Ursache (FM-05, FM-07, FM-08, dazu FC2-05). Die zweite Gruppe sind Annahmen über die Welt außerhalb des Portals: welcher Host gemeint ist (FM-01), ob eine VM angelegt ist (FM-02), ob ein Fernlauf endet (FM-03) und ob ESXi und MECM noch den Stand des Portals haben (FM-06).

### Offene Entscheidungen zu Schritt 3 (Nutzer)

| ID | Frage | Vorschlag |
|---|---|---|
| FM-E1 | Was soll ein Create tun, wenn der gewählte ESXi-Host nicht der ist, auf dem die VM angelegt wurde (FM-01)? | Bindung an den Host knüpfen; Create für gebundene VMs auf einem anderen Host sperren, mit Meldung „VM ist an Host X gebunden“ und Link. Ein gewollter Umzug wäre ein eigener, späterer Ablauf. Frage dazu: Zieht ihr Missionen je auf einen anderen ESXi-Host um? |
| FM-E2 | Nie angelegte VMs im Umfang von Start, Export, Power-Cycle oder Autostart (FM-02): sperren oder ausnehmen? | Sperren, mit Meldung und Link auf Create. Still ausnehmen würde verdecken, dass die Mission unvollständig ist. |
| FM-E3 | Geplante Aufträge (FM-04): Verfallszeit oder nur ein Hinweis auf die Verspätung? | Hinweis „startete N Minuten nach Plan“ in Liste und Protokoll. Eine Verfallszeit nur, wenn Starts außerhalb eines Zeitfensters ausgeschlossen sein müssen. |

FM-03 braucht zuerst die Laborprobe; FM-05 bis FM-09 sind ohne Entscheid umsetzbar.

## Nächster Schritt

Schritt 4 sind die Zeitbudgets (PI-04), danach die Meldungsprüfung nach R11 und der Rest von FC2. Offen sind VT-E2 und FM-E1 bis FM-E3. Laborproben: WM-01 (Abfrage oben, nur lesend), VT-04 (Passwort auf dem Ubuntu-Host suchen) und FM-03 (Fernlauf nach Verbindungsende, auf einer Testmission).
