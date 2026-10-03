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

## Nächster Schritt

Schritt 2 ist die Verbindungs-, Vertrauens- und Geheimnismatrix (PI-03), danach die Fehlerfälle entlang der Gesamtkette (PI-05), die Zeitbudgets (PI-04), die Meldungsprüfung nach R11 und der Rest von FC2. Alle Entscheidungen zu Schritt 1 sind gefallen; Laborprobe für WM-01 mit der Abfrage oben, nur lesend.
