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
