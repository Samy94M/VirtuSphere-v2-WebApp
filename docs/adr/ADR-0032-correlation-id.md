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
