# Maschinen-API: Abläufe

Die Maschinen-API ist der Rückkanal von Ansible-Host, MECM-Server und Windows-Clients ins Portal. Ihre Endpunkte liegen als einzelne Dateien in `Docker/WebAPI/`, antworten nur mit JSON und sind ein eingefrorener Leitungsvertrag (ADR-0019, ADR-0035, ADR-0043, ADR-0044). Dieses Dokument zeigt, was die Serverseite mit einem Aufruf tut. Was die MECM-Serveraufgaben senden, steht in den [MECM-Serveraufgaben](mecm-scheduled-tasks.md), was die Clients senden, in den [Clientabläufen](client-flows.md); die Freigabeliste und ihre Rollen beschreibt die [MECM-Integration](mecm-integration.md). Einstieg: [Abläufe](flows.md).

Die Diagramme beschreiben den ausgelieferten Code. Wer einen Endpunkt, eine Aktion, ein Gate oder die Reihenfolge der Prüfungen ändert, zieht dieses Dokument im selben Commit nach; `MachineApiFlowsDocContractTest` leitet Endpunkte, Aktionen, Gates, Rollen und Fence-Ergebnisse aus dem Code ab.

## Endpunkte und Rollen

Jeder Eintrag der Freigabeliste trägt eine Rolle; ein Gate lässt nur IPs seiner Rollen durch (`machine_api_ip_allowed()`, Zuordnung in `VIRTUSPHERE_MACHINE_API_GATE_ROLES`). Ein Eintrag ohne Rolle (Bestand vor Migration 0063) öffnet weiter jedes Gate, bis ein Administrator eine Rolle setzt. Drei Clientwege nehmen statt der IP auch eine MAC an, die das Portal schon kennt (`machine_api_mac_allowed()`). Eine Ablehnung antwortet immer mit demselben 403-Text und schreibt eine gedrosselte Auditzeile `machine_api.access_denied` mit der Ursache `ip_not_allowlisted` oder `ip_role_not_permitted` (`machine_api_forbidden()`).

| Endpunkt | Aktion | Gate | Rolle | Bekannte MAC genügt | Schreibt |
|---|---|---|---|---|---|
| `db_importMAC.php` | `updateInterface` | `mac_import` | `ansible_host` | nein | MACs, Bindung und Zustand der VMs, Ergebnis des Auftrags |
| `deploy_vm_touch.php` | – | `vm_touch` | `ansible_host` | nein | nur das Journal des Auftrags (`esxi_touched`) |
| `mecm-api.php` | `getDeviceList` | `device_list` | `mecm_server` | nein | nichts |
| `mecm-api.php` | `getDeviceInfos` | `device_infos` | `client` | ja | nichts |
| `mecm_client_ack.php` | – | `client_ack` | `client` | ja | Lebenszyklus `os_installed`, Stufenwert 5/5 |
| `mecm_packages.php` | – | `package_catalog` | `mecm_server` | nein | Paket- und Betriebssystemkatalog |
| `mecm_report.php` | `reportPhase` | `client_phase` | `client` | ja | Clientereignis zur Anzeige |
| `mecm_report.php` | `heartbeat` | `server_report` | `mecm_server` | nein | Statuszeile der Quelle |
| `mecm_report.php` | `reportRun` | `server_report` | `mecm_server` | nein | Laufbericht der Serveraufgabe |
| `mecm_report.php` | `reportPackageRun` | `package_report` | `client` | nein | Diagnose eines Paketlaufs |
| `mecm_updateid.php` | `updateDevice` | `device_update` | `mecm_server` | nein | ResourceID, Lebenszyklus `os_installing`, MECM `registered`, Stufenwert 4/5 |
| `mecm_updateid.php` | `reportMembership` | `device_update` | `mecm_server` | nein | Herkunft der Collection-Mitgliedschaften |

`heartbeat` und `reportRun` verlangen zusätzlich den Berichtstoken im Kopf `X-VirtuSphere-Token`, sobald einer eingestellt ist (`machine_api_report_token_ok()`). Die übrigen Dateien in `Docker/WebAPI/` (`function.php`, `index.php`, `intern.php`, `mysql.php`) sind Einbindungen oder Weiterleitungen, keine Endpunkte. Die Liste der Endpunkte führt `VIRTUSPHERE_MACHINE_API_ENDPOINTS`.

## Rollout-Fence

Jeder Rückruf, der einen MECM- oder Clientzustand schreibt, nennt die Rollout-Revision, die er gelesen hat; `mecm_rollout_fence_decide()` entscheidet in derselben Transaktion wie das Schreiben (ADR-0043). So kann ein Rückruf aus einem früheren Rollout, etwa nach „MECM-ID zurücksetzen“, den neuen Rollout nicht überschreiben.

```mermaid
flowchart TD
    call["Rückruf mit oder ohne rollout_revision, VM unter Sperre gelesen"] --> sent{"Revision mitgesendet?"}
    sent -->|nein| legacy{"Gespeichert Revision 1 und kein Tombstone?"}
    sent -->|ja| same_rev{"Gleich der gespeicherten Revision?"}
    legacy -->|nein| stale["stale: 409, Auditzeile mecm.rollout_revision_refused mit revision_mismatch oder binding_conflict, nichts geschrieben"]
    same_rev -->|nein| stale
    legacy -->|ja| bound{"Schon eine Bindung gespeichert?"}
    same_rev -->|ja| bound
    bound -->|nein| accept["accept: schreiben"]
    bound -->|ja, dieselbe| noop["noop: 200 ohne zweites Schreiben und ohne zweites Statusereignis"]
    bound -->|ja, eine andere| stale
    classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;
    classDef decision fill:#fff7ed,stroke:#9a3412,color:#0f172a;
    classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;
    class call,accept,noop step;
    class sent,legacy,same_rev,bound decision;
    class stale blocked;
```

Eine fehlende Revision gilt nur für den Bestand vor dem Wechsel der Skripte als aktuell; eine vorhandene, aber unbrauchbare Revision ist 400, nicht 409 (`mecm_rollout_fence_reported_revision()`). Eine Revision aus der Zukunft wird ebenso abgelehnt wie eine alte. Bindung heißt bei `updateDevice` die ResourceID; `reportMembership` und `mecm_client_ack.php` binden nichts und kennen deshalb nur `accept` und `stale`. Welche Prüfung abgelehnt hat, steht nur in der Auditzeile (`mecm_rollout_fence_refusal_cause()`); die Antwort ist für beide Ursachen dieselbe.

## Geräte-Sync

Der Devices Sync liest `getDeviceList`: alle VMs mit `updated = 1` oder MECM-Zustand `pending`, nur mit MAC und nie aus einer Vorlagenmission, mit dem eingefrorenen Rollout-Hostnamen unter `vm_hostname` und dem Stufenwert, den `virtusphere_legacy_status_from_states()` bei jeder Abfrage aus den Achsen berechnet. Die Abfrage schreibt nichts. Was der Sync danach in MECM tut, zeigt [Devices Sync](mecm-scheduled-tasks.md#devices-sync); sein Rückruf ist `updateDevice`:

```mermaid
flowchart TD
    post["POST mecm_updateid.php?action=updateDevice"] --> checks{"IP-Gate device_update, Methode, Aktion, Größe und Felder in Ordnung?"}
    checks -->|nein| refused["403, 405, 400 oder 413; nichts geschrieben"]
    checks -->|ja| lock["VM in einer Transaktion sperren"]
    lock --> known{"VM vorhanden?"}
    known -->|nein| unknown["404 und Auditzeile mecm.unknown_vm_reported; der Sync behält das Gerät in seiner Liste"]
    known -->|ja| fence{"Rollout-Fence"}
    fence -->|stale| stale["409, nichts geschrieben"]
    fence -->|noop| noop["Warteschlange leeren, wenn transfer_generation der gelesenen entspricht; 200"]
    fence -->|accept| bind["Vorwärts auf os_installing, MECM registered, 4/5; ResourceID binden, Tombstone löschen; 200"]
    classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;
    classDef decision fill:#fff7ed,stroke:#9a3412,color:#0f172a;
    classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;
    class post,lock,noop,bind step;
    class checks,known,fence decision;
    class refused,unknown,stale blocked;
```

Der Lebenszyklus geht nur vorwärts (`repo_set_vm_state_forward()`): Eine VM, die schon `os_installed` meldet, behält ihn, ResourceID und MECM-Zustand werden trotzdem geschrieben. `updated` wird nur gelöscht, wenn der Rückruf die Übertragung beantwortet, die gerade ansteht (`mecm_transfer_generation_applied()`); ein Rückruf ohne `transfer_generation` beantwortet jede. Eine Ablehnung beantwortet der Endpunkt erst nach dem Ende der Transaktion, damit ein Erfolg seine eigene Schreibung nicht zurückrollt. `reportMembership` prüft bis zu `VIRTUSPHERE_MECM_RULE_REPORT_MAX_ENTRIES` Einträge vollständig, bevor er schreibt, durchläuft denselben Fence ohne Bindung und speichert nur die Herkunft der Mitgliedschaften (`repo_mecm_rules_apply_report()`). Ein unerwarteter Fehler ergibt 500 mit der Auditzeile `machine_api.internal_failure`.

## Client-Ready-ACK

`client_getInfos` liest seine Daten mit `getDeviceInfos` (ohne Schreibwirkung) und bestätigt nach dem Veröffentlichen seines Registrysatzes mit `mecm_client_ack.php`. Das ist der einzige Schreiber des Stufenwerts 5/5 (A63).

```mermaid
flowchart TD
    post["POST mecm_client_ack.php mit MAC und rollout_revision"] --> shape{"POST, Body bis VIRTUSPHERE_CLIENT_EVENT_MAX_BODY_BYTES, gültige MAC und Revision?"}
    shape -->|nein| bad["405, 413 oder 400; geprüft vor dem Gate"]
    shape -->|ja| gate{"IP mit Rolle client oder bekannte MAC?"}
    gate -->|nein| forbidden["403 und Auditzeile machine_api.access_denied"]
    gate -->|ja| vm{"VM zu dieser MAC vorhanden?"}
    vm -->|nein| unknown["404, ohne Auditzeile"]
    vm -->|ja| fence{"Rollout-Fence aktuell?"}
    fence -->|nein| stale["409 und Auditzeile mecm.rollout_revision_refused"]
    fence -->|ja| done{"Schon os_installed und 5/5?"}
    done -->|ja| dedup["200 mit deduplicated, nichts geschrieben"]
    done -->|nein| write["Vorwärts auf os_installed und 5/5; MECM-Zustand bleibt; 200"]
    classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;
    classDef decision fill:#fff7ed,stroke:#9a3412,color:#0f172a;
    classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;
    class post,dedup,write step;
    class shape,gate,vm,fence,done decision;
    class bad,forbidden,unknown,stale blocked;
```

Der ACK lässt den MECM-Zustand und das Flag `updated` stehen; `registered` schreibt allein `updateDevice` (VT-E1 C). Kommt die Antwort beim Client nicht an, wiederholt MECM die Anwendung; der zweite ACK trifft dann auf den Zweig `deduplicated`.

## Berichtskanal

`mecm_report.php` nimmt Berichte entgegen, die nur angezeigt werden: Phasen der Clients, Lebenszeichen und Laufberichte der MECM-Serveraufgaben und Berichte des Paketwrappers. Er ändert nie den Lebenszyklus einer VM.

```mermaid
flowchart TD
    post["POST mecm_report.php?action=…"] --> token{"heartbeat oder reportRun: Berichtstoken gültig, falls eingestellt?"}
    token -->|nein| denied["401 und Auditzeile mecm.report_token_rejected"]
    token -->|ja oder andere Aktion| body{"Body innerhalb der Grenze und JSON-Objekt?"}
    body -->|nein| bad["413 oder 400"]
    body -->|ja| action{"Aktion?"}
    action -->|reportPhase| phase["Phase und Ereignis aus der geschlossenen Liste, IP-Rolle client oder bekannte MAC; Duplikat binnen VIRTUSPHERE_CLIENT_EVENT_DEDUPE_SECONDS bestätigt, ab VIRTUSPHERE_CLIENT_EVENT_MAX_PER_DAY Ereignissen je VM und Tag 429"]
    action -->|heartbeat| beat["IP-Rolle mecm_server, Quelle aus der Liste, Intervall im erlaubten Bereich; Statuszeile der Quelle berühren"]
    action -->|reportRun| run["IP-Rolle mecm_server, Bericht prüfen und speichern; der erste V2-Bericht einer Quelle schreibt mecm.reporter_upgraded"]
    action -->|reportPackageRun| pkg["IP-Rolle client, V1-Ereignis prüfen, genau eine VM aus den MACs, Zäune und Wiederholung in einer Transaktion"]
    action -->|andere| unknown["400 Invalid action specified"]
    phase --> ok["200, gegebenenfalls mit deduplicated; kein Lebenszyklus geändert"]
    beat --> ok
    run --> ok
    pkg --> ok
    classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;
    classDef decision fill:#fff7ed,stroke:#9a3412,color:#0f172a;
    classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;
    class post,phase,beat,run,pkg,ok step;
    class token,action,body decision;
    class denied,bad,unknown blocked;
```

Die Grenze ist `VIRTUSPHERE_CLIENT_EVENT_MAX_BODY_BYTES`, für `reportPackageRun` `VIRTUSPHERE_PACKAGE_REPORT_MAX_BODY_BYTES`. Ein abgelehntes Gate ergibt 403. `reportPhase` prüft MAC, Phase und Ereignis vor dem Gate; eine unbekannte MAC ist 404. Duplikat, Tagesgrenze und Einfügen teilen sich eine Transaktion (`repo_client_event_record_once()`); eine erreichte Tagesgrenze schreibt eine je VM gedrosselte Auditzeile `mecm.client_event_cap_reached`. `reportRun` prüft den Bericht gegen die geschlossene Liste der Ursachen (`run_report_validate()`) und speichert ihn (`repo_record_run_report()`); einzelne Läufe werden nicht auditiert. `reportPackageRun` prüft das Ereignis (`package_run_report_validate()`), löst die MACs zu genau einer VM auf (`repo_package_report_resolve_vm()`) und speichert es (`repo_package_report_record()`); eine identische Wiederholung wird bestätigt, ohne Zeitstempel oder Ablauf zu erneuern, eine widersprüchliche abgelehnt. Paketberichte ändern weder Lebenszyklus noch Statuszeilen noch Katalog und schreiben keine Auditzeilen.

## Paketkatalog

Der Packages Sync schickt den ganzen Katalog: Pakete (`Package`) und Task Sequences (`TaskSequence`). `mecm_packages.php` zieht fehlende Einträge zurück, statt sie zu löschen, und bremst, wenn eine Lieferung zu viel zurückziehen würde.

```mermaid
flowchart TD
    gate{"IP-Rolle mecm_server?"} -->|nein| forbidden["403; geprüft vor der Methode"]
    gate -->|ja| shape{"POST, Body bis VIRTUSPHERE_MECM_PACKAGES_MAX_BODY_BYTES, nicht leer, jeder Eintrag Package oder TaskSequence mit Namen?"}
    shape -->|nein| bad["405, 413 oder 400; Katalog unverändert"]
    shape -->|ja| brake{"Je gelieferter Art: ab VIRTUSPHERE_PACKAGE_RETIRE_MIN_ACTIVE aktiven Einträgen würden mehr als die eingestellte Schwelle zurückgezogen?"}
    brake -->|ja| rejected["409 und Auditzeile mecm.catalog_sync_rejected; nichts geschrieben"]
    brake -->|nein| write["Eine Transaktion: Fehlende zurückziehen, neue und wieder aufgetauchte aktiv; eine nicht gelieferte Art bleibt unberührt"]
    write --> templates["Vorlagen-VMs folgen einer in dieser Lieferung neu erschienenen höheren Version; echte VMs nie"]
    templates --> audits["Auditzeilen in derselben Transaktion: noch zugewiesene Rückzüge, Umhängungen, Zähler je Katalog"]
    audits --> ok["200 mit success und der Zahl der Pakete und Task Sequences; nur diese Antwort gilt dem Packages Sync als Quittung"]
    write -.->|Fehler, auch eine nicht speicherbare Auditzeile| failed["500 und Auditzeile machine_api.internal_failure; alles zurückgerollt"]
    classDef step fill:#eef2ff,stroke:#475569,color:#0f172a;
    classDef decision fill:#fff7ed,stroke:#9a3412,color:#0f172a;
    classDef blocked fill:#fef2f2,stroke:#b91c1c,color:#0f172a;
    class write,templates,audits,ok step;
    class gate,shape,brake decision;
    class forbidden,bad,rejected,failed blocked;
```

Die Bremse (`packages_retire_guard()`) läuft vor jedem Schreiben. Die Schwelle ist die Einstellung `VIRTUSPHERE_SETTING_PACKAGE_RETIRE_THRESHOLD` (5 bis 90 %, Standard `VIRTUSPHERE_PACKAGE_RETIRE_THRESHOLD_DEFAULT`). Ein Paketname, den dieselbe Lieferung durch eine höhere Version desselben Pakets ersetzt, zählt nicht als Verlust (AV-R14). Ein zurückgezogenes Paket wird nicht gelöscht; das tut erst der [Wartungsdienst](maintenance-flows.md#aufbewahrung) nach Frist. Die Vorlagenregel (`packages_follow_first_versions_in_templates()`) zählt nur Einträge, die diese Lieferung neu angelegt hat; ein vorübergehend fehlender und wieder aufgetauchter Name bewegt nichts. Der Rückzug einer Version, die VMs noch tragen, erscheint einmal als Auditzeile mit der höheren aktiven Version als Arbeitsliste (`packages_audit_retired_assignments()`); verschoben wird dabei nichts.

## Rückrufe des Ansible-Hosts

Der Ansible-Host ruft zwei Endpunkte. `deploy_vm_touch.php` lässt eine VM für den nächsten ESXi-Zugriff von Power-Cycle oder Export zu: Der geschlossene Request `{job_id, vm_id, attempt, generation, phase}` muss zum aktiven Auftrag, seinem Versuch, der Laufzeitgeneration, dem Umfang und einem Playbook des Modus passen (`repo_deploy_job_vm_touch_refusal()`). Dann setzt er nur die Markierung im Journal; eine Ablehnung ist 409 mit einer Ursache aus `VIRTUSPHERE_DEPLOY_TOUCH_REFUSAL_CAUSES` und einer gedrosselten Auditzeile `machine_api.touch_refused`, und das Playbook bricht vor der ESXi-Abfrage dieser VM ab. `db_importMAC.php` nimmt das MAC-Ergebnis des Exports an; unter Sperre von Mission, Auftrag und Laufzeit entscheidet er, ob der Auftrag (`running` oder `cancelling`) es noch annehmen darf, und schreibt MACs, VM-Zustand und Ergebnis in einer Transaktion. Eine identische Wiederholung bekommt die gespeicherte Antwort; eine Ablehnung ist 409 mit einem Code aus `VIRTUSPHERE_MAC_IMPORT_CALLBACK_REASON_META`. Was der Worker aus dem Ergebnis macht, steht im Diagramm [„Worker: ein Auftrag“](deploy-flows.md#worker-ein-auftrag), was die VM danach zeigt, im [Lebenszyklus einer VM](vm-lifecycle.md).
