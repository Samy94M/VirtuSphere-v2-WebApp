# ADR-0026: Gestaffelte Log-Aufbewahrung nach Kategorie

Date: 2026-07-11
Status: Accepted

## Context

Das Portal-Audit-Log (`deploy_logs`, Seite `logs.php`) wurde pauschal nach 7 Tagen
gelöscht: ein einziges `VIRTUSPHERE_LOG_RETENTION_DAYS = 7` deckte alle Kategorien ab,
von Betriebsmeldungen bis zu sicherheitsrelevanten Ereignissen (Anmeldung, Benutzer-
und Zugangsdaten-Änderungen). Der Anmeldeversuchs-Zähler (`deploy_login_attempts`)
teilte sich dasselbe Fenster.

7 Tage sind für einen Audit-Trail zu kurz. Vorfälle werden oft später als eine Woche
bemerkt, und dann ist die Spur schon weg. BSI-Mindeststandard Protokollierung/Detektion
(v2.1) nennt für allgemeine Protokolldaten einen Richtwert von 90 Tagen und erlaubt für
sicherheitsrelevante Ereignisse ausdrücklich längere Fenster; ISO 27001 A.8.15 erwartet
faktisch rund 12 Monate für Anmelde- und Konto-Ereignisse. Gleichzeitig verlangt die
DSGVO-Datensparsamkeit, personenbezogene Logzeilen (IP, `user_id`) nicht unbegrenzt zu
halten. Die Auflösung ist eine Staffelung, keine Einheitsfrist.

## Decision

Drei Fenster statt einer Frist:

- **Sicherheits-Kategorien** (auth, users, credentials) → **365 Tage**
  (`VIRTUSPHERE_LOG_RETENTION_SECURITY_DAYS`).
- **Alle übrigen `deploy_logs`-Kategorien** → **90 Tage**
  (`VIRTUSPHERE_LOG_RETENTION_DAYS`, Name beibehalten, Wert von 7 auf 90).
- **Anmeldeversuchs-Zähler** → eigene **7 Tage**
  (`VIRTUSPHERE_LOGIN_ATTEMPT_RETENTION_DAYS`). Er ist ein 15-Minuten-Lockout-Zähler,
  kein Archiv; die Anmelde-Historie lebt im `auth`-Kanal unter dem Sicherheitsfenster.

Welches Fenster eine Zeile bekommt, entscheidet ihr Tab: `removeLog()` leitet die
Sicherheits-Kategorienliste aus `VIRTUSPHERE_LOG_TABS[VIRTUSPHERE_LOG_TAB_SECURITY]` ab
und nennt sie nie ein zweites Mal (SSoT). Das lange Fenster löscht per `category IN (...)`,
das allgemeine per `category NOT IN (...)`: eine Kategorie außerhalb der heutigen Taxonomie
(Alt-Zeilen, später entfernte Kategorien) verfällt so auf dem allgemeinen Fenster statt
ewig zu überleben. `deploy_logs.category` ist `NOT NULL`, also gibt es keine NULL-Falle.

Die Fenster bleiben Code-Konstanten (keine Settings-Option). Hilfetext und Betriebsdoku
interpolieren die Konstanten, damit Text und Verhalten nicht auseinanderlaufen.

## Consequences

- `deploy_logs` wächst gegenüber vorher. Akzeptabel: der `auth`-Kanal ist flood-begrenzt
  (Rate-Limit-Beginn ist eine Zeile, nicht eine pro Request), und die Tabelle ist klein.
  Der erste Wartungslauf nach dem Deploy löscht in der Praxis *weniger* als vorher, weil
  alle Fenster nur gewachsen sind.
- Kein Composite-Index `(category, created_at)`: der Purge filtert über den bestehenden
  `category`-Index plus `created_at`; bei diesem Volumen genügt das. Ein Index wird erst
  gelegt, wenn die stündliche Löschung messbar langsam wird (bewusst vertagt).
- `removeLog()` liefert jetzt die Zahl gelöschter Zeilen zurück; der Wartungs-Worker meldet
  sie in seiner Zusammenfassung.
- Eine Umbenennung/Neuverteilung der Log-Tabs verschiebt bewusst eine Compliance-Zusage;
  `tests/Unit/LogRetentionTest.php` pinnt, dass das Sicherheitsfenster genau auth/users/
  credentials abdeckt und die Fenster geordnet und positiv bleiben.
- Keine Schema-Migration nötig: reine Anwendungslogik.

## Amendment (2026-07-27): rotation for the two file logs

The windows above purge database rows. The two FILE logs had no counterpart:
`logs/error.log` (the error handler appends with `FILE_APPEND`) and the PHP
engine log from the ini grew without bound, and the ini itself documented
"rotated by nothing" - on a LAN appliance the disk eventually is the incident.

Decision: the maintenance worker rotates both size-based
(`lib/log_rotation.php`, job `log-rotation`). Constants own the numbers
(`VIRTUSPHERE_LOG_ROTATE_MAX_BYTES` 10 MiB, `VIRTUSPHERE_LOG_ROTATE_GENERATIONS`
5, hourly check interval); generations shift `error.log -> .1 -> ... -> .5`
and the oldest falls off. Boundaries of record:

- Rotation only touches real children of the resolved log directory: an
  engine log configured elsewhere is not selected, and a symlink inside the
  directory pointing outside is an error, never a target.
- One rotation at a time per directory (`logs/.rotation.lock`, `flock`); a
  held lock reads as idle, never as a second rotation.
- A missing file is idleness (a fresh install has no error log). Permission
  and rename failures throw and reach the operator through the existing
  maintenance verdict; deliberately no extra System status row (display
  restraint), and no portal text spells out the numbers (bounds rule).
- Writers survive the rename because both append paths open the file per
  write; a racing write lands in the renamed or the fresh file, never nowhere.
- Pinned by `tests/Unit/LogRotationTest.php` (boundary, generation shift,
  containment, lock, missing-file idleness).

## Amendment (2026-08-26, Etappe 10C): structured events replace free prose

The windows above decide how long a row lives. They said nothing about what a
row IS, and until now the answer was "a free English sentence plus a category
picked at the call site". That is a convention, not a contract, and it degraded
exactly the way conventions do: two producers of the same event drifted into two
different sentences, and every reader that wanted a class of events had to match
on prose. Three of them did. The auth flood check was a `LIKE` over a `TEXT`
column, which a reworded sentence would have disarmed silently; the System
status counted the whole `machine_api` category as IP-allowlist refusals, so a
raced MAC callback sent an operator to fix an allowlist their host was already
on; and the retention rules above are the only reader that never had to, because
they read the category.

Decision: an event's shape is declared once, and a producer cannot widen it.

- Migration 0044 adds `event_code`, `object_type`, `object_id`, `result` and a
  byte-bounded `context_json` to `deploy_logs`, all nullable, with **no
  backfill**. A historical row keeps all five NULL and its unchanged text; a
  `CHECK` permits exactly that shape and refuses a half-structured one. Nothing
  guesses a code out of old prose, because a guess in an audit trail is worse
  than a gap: a gap is visibly a gap.
- `lib/audit_event_definitions.php` is the registry. Per event it owns the
  category, the allowed object types, the allowed results, whether the object id
  is required or nullable, a closed object-id allowlist where the set really is
  closed, and the required/optional context fields. `lib/audit_registry.php`
  enforces it and types every context value. An unknown event, object, result,
  field or integration source is refused, not defaulted.
- The category is no longer a parameter anywhere. Its former inline form was
  `$source === maintenance ? system : mecm`, which would have filed the first
  future non-MECM source's outage in the tab an operator opens to read MECM
  sync results.
- The English description survives as the compatibility display, but is
  RENDERED from the same columns (`lib/audit_presenter.php`) instead of being a
  second, older opinion. Where a wording is load-bearing for saved filters and
  browser assertions it is preserved verbatim.
- `audit_event()` (and its `audit()` alias) is the only writer. `addLog()` and
  `audit_auth()` are removed; `audit_change_note()` went with them, because its
  statement belongs to the rendered description. `scripts/check-audit-contract.php`
  fails the build on a free producer, an unknown code, a second INSERT path, a
  forbidden context key, a reintroduced sink, a log signature that can be handed
  a token, and on a scan that matched nothing at all.
- Context is redacted before persistence (`lib/log_redaction.php`), and the
  registry independently refuses any field whose NAME looks like a credential,
  a payload, an exception or a search term. Two independent reasons to refuse
  means neither one alone has to be complete.

Consequence for this ADR's own subject: the retention windows are unchanged, and
they still key on the category. The category is now derived rather than chosen,
so a producer can no longer place a security event outside the security window
by picking the wrong constant.

### The CSV export says what it left out

The audit CSV is evidence, and evidence that stops at ten thousand rows without
saying so reads as a complete answer. `lib/log_filter.php` is now the single
validated filter struct; the table and the export both take their repository
arguments from it, so a download cannot cover a different set than the screen it
was started from. The match count is established before the first row is
streamed, the cap is `VIRTUSPHERE_LOG_EXPORT_MAX_ROWS`, and the page says
"the first N of M" before the download starts. The response carries
`X-VirtuSphere-Total-Rows`, `-Export-Limit` and `-Truncated`; the file itself
gets no note row, because a comment line contradicts the CSV's own header and
every RFC 4180 reader parses it as data.

Exactly one audit row per export, written after the rows were read (so the file
cannot contain its own audit line) and before the stream starts (so a failing
insert is an error page, not a truncated file). Its context is the export, never
the filter's contents: counts, the limit, the truncation flag and a
**keyed** fingerprint. The fingerprint is an HMAC over the canonical filter with
`envboot_app_key_bytes()`, not a bare digest: the inputs are a handful of tabs,
fourteen categories, a 32-bit IP space and usually a person's name, so an
unkeyed hash of them is a lookup table, and a row that promised to keep the
search term out of the log would hand it to anyone who can read the row.

The cap is a diagnostic limit, not an authorization boundary. `users.manage`
already decided that this reader may see every one of those rows; the cap exists
so one click cannot hold a PHP worker and a MySQL cursor open over an unbounded
result set.
