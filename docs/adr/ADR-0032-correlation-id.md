# ADR-0032: Durchgängige Korrelations-ID über alle sieben Ausführungsschichten

Date: 2026-07-17
Status: Accepted (umgesetzt 2026-07-17: Migration 0022, alle sieben Schichten, Testmatrix 1-9)

## Context

Ein Deploy durchquert sieben Schichten mit je eigenem Logformat: Portalrequest,
Job-Datensatz, Worker, SSH-Transport, Ansible-Lauf, MAC-Import-Callback und den
PowerShell-Heartbeat des MECM-Servers. Heute existiert nur eine Fehler-Referenz
(`virtusphere_error_reference()`, 8 Hex) auf dem Portal-Fehlerpfad; sie entsteht
erst beim Fehler und reist nicht weiter. Wer einen fehlgeschlagenen MECM-Sync
diagnostiziert, korreliert Portal-Audit, `deploy_job_logs`, Remote-Ausgabe und
PowerShell-Log über Zeitstempel, also über Glück.

## Decision

Eine opake Korrelations-ID begleitet jeden Vorgang durch alle Schichten.

**Format:** 16 Hex-Zeichen klein (8 Bytes `random_bytes`), Muster
`^[0-9a-f]{8,32}$` für akzeptierte Fremd-IDs. Sie ist reine Diagnose: sie
authentifiziert nichts, autorisiert nichts, und eine fehlende oder fremde ID
ändert nie das Verhalten eines Handlers, nur die Logzeile.

**Schichtkontrakte:**

1. **Portalrequest:** Der Bootstrap mintet pro Request genau eine ID
   (`virtusphere_correlation_id()`); die bestehende Fehler-Referenz wird
   dieselbe ID (das Fehlerbild `error [id]` bleibt, zeigt aber jetzt die
   Request-ID). Audit-Zeilen (`deploy_logs`) tragen sie in einer additiven
   Spalte `correlation_id VARCHAR(32) NULL`.
2. **Job:** `deploy_jobs.correlation_id VARCHAR(32) NULL` (additive Migration,
   Fresh-Schema konvergent, database.md-Regeln). Das Enqueue übernimmt die ID
   des auslösenden Requests. Ein Retry mintet eine NEUE ID und schreibt die
   alte als System-Logzeile in den neuen Job ("retry of job N, correlation X"):
   zwei Läufe, zwei Spuren, ein Verweis.
3. **Worker:** Jede `deploy_job_logs`-Systemzeile eines Jobs trägt dessen ID
   (additive Spalte, vom Insert-Helfer befüllt); Begin-/End-Marker (AP6)
   bleiben unverändert lesbar.
4. **SSH/Ansible:** Die Kommandokette exportiert `VS_CORRELATION_ID` als
   Env-Variable vor dem Playbook-Aufruf. Remote wird sie nur durchgereicht
   (opak), nie geparst; Playbook-Dateinamen bleiben unverändert (ansible.md).
5. **MAC-Import-Client:** `upload_mac_list.py` erhält die ID wie `job_id` per
   `ansible_patch_upload_script()` und sendet sie additiv als
   `correlation_id`-Feld im Payload. Desktop-/Legacy-Rendering ohne das Feld
   bleibt gültig.
6. **Machine-API:** `db_importMAC.php` akzeptiert das optionale Feld, validiert
   es gegen das Muster (ungültig ⇒ ignoriert plus Logvermerk, nie 4xx), schreibt
   es in Audit-/Job-Logzeilen und echot es additiv in der Response. Der
   Wire-Vertrag bleibt additiv (machine-api.md); Legacy-Aufrufer ändern sich
   nicht.
7. **PowerShell-Heartbeat:** Der MECM-Client mintet pro Prozesslauf eine eigene
   ID (GUID-abgeleitet, 16 Hex) und sendet sie als Header
   `X-VirtuSphere-Correlation` an `mecm_report.php`/`mecm-api.php`; der Server
   loggt sie in der jeweiligen Audit-Zeile. Kein Registry-Wert, kein Zustand:
   ein Client-Neustart ist absichtlich eine neue Spur.

**Nicht-Ziele:** kein verteiltes Tracing (kein Span-Baum, keine Clock-Sync-
Annahme), kein Ersatz für `job_id` (die bleibt der fachliche Schlüssel), keine
Weitergabe an ESXi/vCenter (deren APIs kennen das Konzept nicht).

## Testmatrix (AP7b-Umsetzung; kein Punkt gilt ohne Test als erfüllt)

1. Pro Portalrequest genau eine ID, stabil über den Request; zwei Requests,
   zwei IDs (Unit).
2. Enqueue persistiert die Request-ID am Job; Gruppen-Slots teilen die ID des
   auslösenden Requests (Integration).
3. Retry: neuer Job trägt neue ID, Verweiszeile nennt alte ID und Job
   (Integration, erweitert `DeployJobRetryFlowTest`).
4. Worker-Systemzeilen tragen die Job-ID-Korrelation; der Fehlerpfad (Phase,
   AP6-Marker) ebenso (Integration, erweitert `DeployWorkerOutcomeTest`).
5. Kommandoketten-Kontrakt: `VS_CORRELATION_ID` steht vor jedem Playbook-Schritt
   im Env-Prefix (statisch, erweitert `AnsibleStepMarkerTest`).
6. Python: Payload trägt das Feld, Template ohne Patch bleibt gültig; Exitcodes
   unverändert (Ansible/tests, erweitert die bestehende Suite).
7. Endpoint: gültige ID wird geloggt und geechot; ungültige wird ignoriert
   (200-Pfad unverändert), fehlende bleibt Legacy-legal; die ID öffnet keinen
   Zugriff (409-/403-Pfade unverändert) (Integration, erweitert
   `MacImportCallbackTest`/`MachineApiWireTest`).
8. PowerShell: Header gesetzt, pro Lauf konstant, pro Neustart neu; Redaction
   lässt die ID sichtbar (sie ist kein Secret) (Pester).
9. E2E-Stichprobe: ein Deploy aus dem Portal hinterlässt dieselbe ID in
   Audit, Job und Job-Log (Playwright, ein Fall genügt: die Schichtverträge
   tragen den Rest).

## Consequences

- Migration `0022_correlation_ids` (drei additive NULL-Spalten) plus
  Fresh-Schema-Spiegel; kein ENUM, kein Sync-Skript betroffen.
- `MachineApiWireTest` erhält das additive Feld; bestehende Felder unverändert
  (Forbidden Patterns bleiben erfüllt).
- Logzeilen werden um `correlation=<id>` länger; Retention unverändert.
- Die Fehler-Referenz auf Fehlerseiten wird informativer (Request- statt
  Zufalls-ID); Operatoren-Doku (`docs/QA.md` Fehlersuche) wird im
  Umsetzungscommit nachgezogen.

## Amendment (2026-08-26, Etappe 10C): the id is not yet a search path

The correlation id still travels the same way: every audit row carries the id of
the execution that wrote it, an error page shows it as its reference, and the
job, the job log and the machine-API callback of the same run share it. Two
things changed and one thing did not.

**Changed: the row it lands on is structured.** Migration 0044 added
`event_code`, `object_type`, `object_id`, `result` and `context_json` next to
`correlation_id` (ADR-0026 amendment). Correlating an execution across rows no
longer means reading sentences: the event code says what happened, the object
says to what, and the correlation id says which run. `CorrelationTraceTest` now
writes its probe as a registered event and finds it by its object rather than by
a message it made up, which is the same claim proven without a string match.

**Changed: the id survives the redaction.** Every log sink now runs through
`virtusphere_redact_log_text()`. The correlation id is deliberately not a secret
and is deliberately not redacted; the same pass removes auth headers of any
scheme, token-bearing query parameters and JSON/form credential pairs from the
error log, the container log, the debug render and the audit context. An error
row itself stores only the class and the reference: the exception MESSAGE is the
one field an attacker can steer, and the audit trail is readable by every
`users.manage` holder. The full redacted line with message and trace stays in
`logs/error.log`, which is a filesystem artefact behind a different boundary.

**Unchanged, and stated because a runbook claimed otherwise: the portal cannot
search by correlation id.** `logs.php` filters by tab, category, free text over
the message and user name, and IP. The id is stored on the database row, but the
portal neither displays nor exports it: there is no exact-match field, no
display column, no copy action and no export scoped to one id.
`docs/operations/troubleshooting.md` once recommended a correlation search as
an operating step; that step did not exist, so it now tells the operator to
narrow by time, user and category and to keep the id only as evidence for a
separately authorized database or file diagnosis. Etappe 15 implements the
exact search, the display, the copy action and the scoped export, and may
document the id as an operating path again only once those are green.

## Amendment (Etappe 15): the id becomes an operating path

The sentence above is now historical. Etappe 15 implemented what it named, and
the runbook step it withdrew is restored.

**The id is displayed, copyable and exported.** One presenter
(`portal_correlation_id()`, `lib/correlation_display.php`) renders it in the
audit table, in the header of a deploy job log and in the CSV export. The value
is text first and the copy button is an accelerator on top of it: with
JavaScript disabled, and on a plain-HTTP LAN portal where `navigator.clipboard`
does not exist at all, the id stays visible and selectable. The button is a real
`<button>` with a label, so it is reached with Tab and activated by the browser;
the outcome is announced in a `role="status"` beside it, because a silent
success looks exactly like a silent failure and the failure here is a normal
branch, not an exception.

**The search is exact, and only exact.** `virtusphere_correlation_id_is_valid()`
already defined the accepted shape (8 to 32 lowercase hex); the filter reuses it
and refuses anything else with a field error. A substring search was considered
and rejected: it turns a diagnostic identity into free text, so `a1b2` would
return the traces of a dozen unrelated requests and read as one chain. A refused
value is not dropped and queried around either - `log_filter_is_usable()` stops
the page from querying at all, and the export with it, because a page that
answers a WIDER question while the field still shows the value the operator
believes is filtering is worse than an error message.

**One trace, one link.** `log_filter_correlation_url()` builds the jump and
drops every other filter: a trace is read to see the whole request, and carrying
the category or the date range that happened to be set would show a slice of it
while looking like the complete answer. Inside a trace the id no longer links to
itself.

**The jobs of a traced request are listed beside its rows**, with the two
permissions separate. `users.manage` decides the audit rows and therefore the
job ids and statuses; `deploy.run` decides opening a job LOG, so only the link
carries that condition. Hiding the row from a user-administrator would make a
trace look incomplete; handing them the log would widen what `users.manage`
grants. The list is bounded, says when it cut, and is ordered by job id alone,
because a staggered batch is written inside one second and `created_at` would
not decide it.

**Retention is asymmetric and is said out loud.** Audit rows outlive job logs,
and a mission-less system job is purged on its own schedule, so an older trace
legitimately shows audit rows and no jobs. Without that sentence the empty list
reads as "this request enqueued nothing", which is a different and wrong
conclusion.

**The id is now an indexed column.** Migration 0051 adds
`(correlation_id, id)` to `deploy_logs` and `deploy_jobs`. Before it, the count
behind the audit table was a full table scan and the page query a backwards walk
of the primary key that stopped only at the LIMIT; the migration's docblock
carries the measured `EXPLAIN` on both sides. It remains diagnostic: an index
changes no wire field, and the id still authorizes nothing.
