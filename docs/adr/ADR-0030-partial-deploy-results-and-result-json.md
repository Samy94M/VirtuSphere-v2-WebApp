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
  `missing_name`, `missing_nic_data`, `esxi_query_failed`, `duplicate_result`)
  plus längenbegrenzte technische Identifikatoren. Keine Credentials, keine
  Rohantworten, keine freien Remote-Fehlertexte.
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
