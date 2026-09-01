# ADR-0030: Partial-Ergebnismodell und result_json-Vertrag

Date: 2026-07-16
Status: Accepted

## Context

Die Testplan-Kampagne 2026-07 hat den MAC-Rückkanal als blinden Fleck belegt: der
Upload-Client exitete immer mit 0, `db_importMAC.php` verwarf Inputzeilen still und
markierte VMs mit Mischfehlern trotzdem `deployed/pending`. Ein Deploy-Job konnte
grün enden, ohne dass je eine MAC ankam. Zwei strukturelle Ursachen:

1. Es gab keinen Zustand zwischen "alles gut" und "alles kaputt". Ein Teilerfolg
   (3 von 4 VMs importiert) musste auf `succeeded` oder `failed` gerundet werden,
   und beides ist eine Lüge: `succeeded` verschweigt die kaputte VM, `failed`
   verwirft drei gültige Ergebnisse und provoziert einen vollen Re-Deploy.
2. Der Deploy-Worker sah nur den Gesamt-Exit der per SSH ausgeführten `&&`-Kette.
   Ein differenzierter Exitcode (etwa rc=20 für Teilerfolg) wird durch diese Kette
   nie getunnelt; stdout ist als Ergebniskanal ebenso unzuverlässig wie ungeeignet
   für strukturierte Per-VM-Resultate.

Die fünf eingefrorenen Legacy-`vm_status`-Strings und die bestehenden
Machine-API-Wire-Felder dürfen dabei nicht verändert werden (GROK.md §5,
ADR-0009); Erweiterungen sind ausschließlich additiv.

## Decision

### Neuer interner Terminalstatus `partial`

`deploy_jobs.status` wird order-exakt um `partial` erweitert (Migration
`0019_deploy_partial_results`, Fresh-Schema identisch, ENUM-Sync-Check deckt die
Spiegel ab). `partial` ist terminal, sichtbar, retryfähig und blockiert keine neue
Mission-Ausführung (der Ein-Aktiv-Job-Guard prüft nur queued/running). Terminal
heißt auch: die Log-Retention behandelt partial-Jobs wie jeden beendeten Job, und
die Log-Seite beendet ihr Live-Polling. Das Badge-Label bleibt der rohe
Statusstring (`partial`), wie bei allen Job-Status; die Status-ENUMs werden
bewusst nicht lokalisiert.

### `deploy_jobs.result_json` ist die SSoT des Export-Ergebnisses

Eine Sequenz mit Export-Schritt (`export`, `powercycle`, `full`; abgeleitet aus
der Playbook-Liste) beweist ihr Ergebnis ausschließlich über den versionierten
Vertrag in `deploy_jobs.result_json`, nie über Exitcodes oder stdout:

```json
{
  "version": 1,
  "kind": "mac_import",
  "outcome": "success|partial|failed",
  "successful_vm_ids": [1, 2],
  "failed_vm_ids": [3],
  "errors": [{ "vm_id": 3, "vm_name": "vm03", "code": "interface_not_found", "vlan": "WDS" }],
  "counts": { "expected_vms": 3, "successful_vms": 2, "failed_vms": 1, "updated_interfaces": 2 },
  "retry": { "mode": "export", "vm_ids": [3] }
}
```

- **Schreibseite:** `db_importMAC.php` schreibt den Vertrag als raw prepared
  statement in derselben äußeren Request-Transaktion wie die Interface-/VM-Writes
  (keine `repo_transaction()`-Helfer in diesem Block; siehe
  `.claude/rules/webapi.md`). Wiederholte identische Callbacks sind idempotent,
  solange der Job `running` ist; nach Jobabschluss und für missionsfremde Jobs
  antwortet der Endpoint 409 ohne Writes. Ein Retry-Job schreibt in **sein**
  `result_json`, nie in das eines fremden Jobs.
- **Leseseite:** `mac_import_decode_result()` akzeptiert nur ein wohlgeformtes
  Version-1-Ergebnis mit bekanntem `outcome`; alles andere ist NULL, und NULL
  heißt für den Worker "kein verwertbares Ergebnis" und damit `failed`
  (fail-closed). Modi ohne Export-Schritt erwarten kein Ergebnis und können
  dadurch nie fälschlich scheitern.
- **Fehlercodes sind eine feste Liste** (`interface_not_found`, `duplicate_mac`,
  `invalid_mac`, `ambiguous_vlan`, `vm_not_in_mission`, `vm_not_in_job_scope`,
  `missing_name`, `missing_nic_data`, `esxi_query_failed`, `duplicate_result`,
  `identity_mismatch`) plus längenbegrenzte technische Identifikatoren. Keine
  Credentials, keine Rohantworten, keine freien Remote-Fehlertexte.
  `identity_mismatch` gehört zur VM-Identität: das Ergebnis nennt
  denselben VM-Namen mit einer anderen Instance-UUID als der gespeicherten,
  spricht also über eine fremde VM. Diese VM wird komplett verworfen, nicht nur
  ihre Identitätsfelder.
- `counts.expected_vms` ist die Anzahl der VMs im **Job-Scope** (Payload-Auswahl
  bzw. ganze Mission), nicht die Anzahl der Inputzeilen: nur so deckt die Bilanz
  auch Zeilen, die der Export gar nicht liefern konnte.

### Statuswahrheit bei spätem Folgefehler (E1)

Scheitert nach einem erfolgreichen oder teilweisen MAC-Import noch ein späteres
Playbook (`start`, `autostart`), dominiert `failed` den **Job**, aber die
VM-Markierung ist **selektiv**: VMs aus `successful_vm_ids` behalten
`deployed/pending` (ihre MACs sind importiert, MECM kann sie aufnehmen), nur die
übrigen werden konsistent `failed/failed` (Lifecycle **und** MECM-State).

### Retry-Semantik

Ein Retry darf nach einem committeten Import nie wieder `create` oder
`powercycle` für den ganzen Job ausführen:

- `partial` retryt als **Export-only-Auftrag** für exakt die `failed_vm_ids`.
- Ist die fehlgeschlagene Menge nicht vertrauenswürdig (Jobstatus und
  gespeichertes `outcome` divergieren, etwa `failed` bei `outcome=success` nach
  Antwortverlust; oder das Teilergebnis fehlt, ist unlesbar oder nennt keine VM),
  wiederholt der Export die **ursprüngliche Auswahl**, niemals den vollen Deploy.
- Reine `failed`/`cancelled`-Retries ohne committetes Import-Ergebnis behalten den
  alten Payload; ein Cancel macht keine Outcome-Behauptung, es gibt dort nichts,
  das divergieren könnte. Entscheidungsfunktion: `deploy_job_retry_plan()`.

### Rollbackregel

Vor einem App-Rollback auf Code ohne `partial` werden vorhandene `partial`-Jobs
per dokumentiertem SQL-Schritt kontrolliert zu `failed` normalisiert
(`UPDATE deploy_jobs SET status='failed' WHERE status='partial'`); die additive
Spalte `result_json` bleibt bestehen und stört Altcode nicht.

## Consequences

- Kein Datei-, JSON-, HTTP- oder ESXi-Abfragefehler kann einen Export-Job mehr
  fälschlich grün abschließen; Teilerfolg ist ein ehrlicher, gezielt
  wiederholbarer Endzustand statt einer Rundung.
- `partial` hat viele Spiegelstellen (Konstanten, ENUM-Mirrors, Badge, Retry-Gate,
  Terminal-Flag, Retention). Sie sind durch ENUM-Sync und Contract-/Unit-Tests
  gepinnt (`DeployConvergenceContractTest`, `DeployWorkerResultEvaluationTest`,
  `DeployJobRetryableTest`, `MacImportCallbackTest`,
  `MigrationPartialResultsContractTest`).
- Der Wire-Vertrag der Machine API bleibt für Legacy-Aufrufer ohne `job_id`
  unverändert; alle neuen Response-Felder sind additiv. Clientseitig schlägt der
  Desktop-Export bei echten Fehlern jetzt sichtbar fehl statt still grün zu
  bleiben (bewusste Verhaltensänderung, siehe CHANGELOG 2026-07-15).
- Ein Export-only-Retry startet bewusst keine VMs: schlug `start` nach dem Import
  fehl, bestätigt der Retry nur den Import; das Starten bleibt ein eigener,
  gezielter Auftrag des Operators. Das ist der Preis der Regel "nie wieder
  create/powercycle ohne ausdrückliche Entscheidung".
- Out of scope: i18n der Status-ENUMs (bewusst dagegen entschieden, E2), eine
  durchgängige Korrelations-ID (eigener ADR im Arbeitspaket AP7b) und jede
  Änderung an den fünf Legacy-Statusstrings.

## Amendment (2026-07-27): result_json und der Abbruch-Automat (ADR-0033)

Ein MAC-Rückruf wird auch im Zustand `cancelling` angenommen (die Sequenz, die
die Adressen erzeugt hat, besitzt den Job noch), und sein `result_json`
überlebt die Bestätigung des Abbruchs: das durable Ergebnis gehört zur
Sequenz, nicht zum Endstatus. Für den Retry ändert sich nichts - `cancelled`
behält das schlichte Wieder-Einreihen (eine Cancellation macht keine
Outcome-Aussage), `cancelling` ist aktiv und wird nie zum Retry angeboten.

## Amendment (2026-08-31): strict V2 network and callback evidence

New callbacks persist result version 2. Besides the aggregate lists and counts,
V2 requires one canonical `vm_results` entry per expected VM, the exact WDS
portgroup verdict, bounded closed error codes and a SHA-256
`callback_fingerprint` over the semantic request. The sorted expected VM IDs
are part of the fingerprint. Semantic rows and their NIC observations are
sorted, key order is canonicalized, and allowlisted diagnostic metadata/error
free text is excluded, while repeated input observations retain their full
multiplicity. A malformed or internally inconsistent V2 document is not
silently read as V1. Historical V1 documents remain readable with their
documented reduced evidence.

The callback is job- and execution-bound. It accepts only a mode whose current
playbook sequence contains the export step, an active `running` or `cancelling`
job, the current attempt/runtime generation and, for `remote_v1`, the exact
current export handle. `legacy_v1` keeps its existing wire and does not invent a
remote handle. VM lookup is exact, and success requires exactly one interface
whose VLAN is the mission's exact WDS portgroup plus a valid returned MAC.
Another interface can never establish VM success.

An identical semantic callback for the same active execution is HTTP 200 and a
write-free no-op. A different, unreadable or terminal duplicate is HTTP 409 and
cannot overwrite `result_json`, identity, MAC or lifecycle state. Rejections
append a bounded job-log trace where a job is known and use the existing
throttled machine-API audit event; secrets and request bodies are not logged.

The request body is limited to 16 MiB, persisted result and response to 1 MiB;
the PHP upload setting is 20 MiB so application validation remains the owner.
Bounds are UTF-8 safe. Response additions such as the structured reason code
and V2 details are additive: the endpoint path, request envelope, established
HTTP meanings and legacy status strings remain unchanged.

`deploy_job_retry_plan()` is the sole retry decision. It recomputes the current
VM scope and resolves blockers in the order unresolved remote execution,
identity, network, then external prerequisites. A partial MAC result repeats
only its currently failed VMs as export; successful VMs are never recreated or
power-cycled by that retry.

The separate pre-remote `network_preflight` version 1 result also records
canonical `missing_vm_ids` when an explicit queued selection no longer fully
materializes. Those IDs affect expected/blocked/missing counts but are not
misclassified as network issues. The result and `failed/configuration_blocked`
transition are one owner/status CAS. If an operator cancellation wins that
boundary, the job is confirmed `cancelled` without the preflight result and its
terminal detail states that no remote step started. This is distinct from a MAC
callback result produced by a sequence that had already reached remote work.

### Display, candidate and JSON bounds

Preflight findings are bounded in one place, `lib/deploy_preflight_bounds.php`,
and only ever for presentation or storage; the queue decision keeps reading the
complete `deploy_queue_blockers()` result for the complete scope, so no bound
can turn a blocker into a release. A bounded list always carries the complete
`total` and the number it omitted; a bounded candidate group additionally
carries `candidate_total` and `candidate_omitted_count`.

Selection runs on one canonical total order (severity registry, mission ID,
natural VM display name, binary exact VM name, VM ID, source-kind registry,
interface ID, code, configured value, binary exact candidate name). The final
binary comparisons matter: a case tie between two ESXi names is exactly where a
folded comparison stops deciding, and the server render, the live JSON island
and the stored worker result would each keep a different subset.

An encoded island above `VIRTUSPHERE_DEPLOY_PREFLIGHT_JSON_MAX_BYTES` loses
entries from the END of that order, one at a time, until it fits, and sets
`truncated_by_bytes`. The omitted count is recomputed inside that loop, so the
bytes and the numbers describing them cannot disagree. The stored worker result
is bounded the same way and never refused: throwing there would turn a precise
`configuration_blocked` verdict into `execution_failed`, which asserts that a
playbook ran when none did. Its `counts` keep describing the complete decision,
`vm_results_total` and `vm_results_omitted_count` explain the difference, and
the decoder accepts exactly that shape while still rejecting a document whose
declared numbers do not match its rows. Documents written before these fields
existed read back as complete lists, because that is what they are.

### The regular job scope

`VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS` and
`VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_INTERFACES_PER_VM` cap one job's scope, and
the cap is enforced at queue time, on the stagger member, on a retry and at the
worker's pre-remote recheck. The reason is the 1 MiB callback bound above: a V2
result that does not fit answers 409 AFTER the playbook created the VMs it is
reporting about, leaving a failed job over changes that really happened and no
selection the operator can split any more. The interface cap is the vSphere
per-VM NIC limit; the VM cap is derived from the proven worst case (every VM
failing, every identifier at its maximum stored byte length, two errors per
interface) and re-measured by `MacImportBoundsTest`. The union of a stagger
group is deliberately exempt, because it becomes one job per VM.
