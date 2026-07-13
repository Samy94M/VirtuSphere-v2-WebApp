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
