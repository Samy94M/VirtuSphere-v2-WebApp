# ADR-0031: Kanonischer Prüf-Runner, Lanes und Exitcode-Kontrakt

Date: 2026-07-16
Status: Accepted

## Context

Die Prüflandschaft bestand aus einzeln aufzurufenden Skripten (`scripts/check-*.sh`,
`lang-audit.php`, `run-pester.ps1`), einer davon getrennt gepflegten CI-Stepliste
und Doku-Seiten mit Kommandolisten. Drei strukturelle Probleme:

1. Es gab keine ausführbare SSoT der Gates: CI, lokale Checks und Doku konnten
   unabhängig voneinander driften, und niemand konnte "alle Pflichtgates" mit
   einem Kommando ausführen.
2. Fehlende Werkzeuge wurden als Skip oder stilles Grün umgedeutet. Ein Lauf ohne
   grep im PATH ließ den CSP-Scan leer durchlaufen; PHPUnit-Skips (8 Config-
   Contract-Tests im Container ohne Repo-Mount) zählten als Erfolg.
3. Die Guards selbst waren unbewiesen: kein Check hatte einen automatisierten
   Negativtest, ein kaputter Guard wäre dauerhaft grün geblieben.

## Decision

### `scripts/check.ps1` ist die ausführbare Gate-SSoT

Drei Lanes: `Fast` (jeder PR, lokaler Vorabcheck), `Integration` (Merge, Nightly,
Release-Kandidaten), `Release` (vor jeder Auslieferung). Eine Lane enthält alle
Gates der schnelleren Lanes. Flags: `-Lane`, `-Gate` (Teilmenge), `-List`,
`-Json <pfad>` (maschinenlesbares Artefakt ohne Secrets), `-KeepArtifacts`,
`-FailFast`, `-NoNetwork`. Der Runner läuft identisch unter Windows PowerShell
5.1 und PowerShell 7 (auch Linux-pwsh).

### Gate-Kontrakt

Jedes Gate deklariert seine Ausführungsform:

- `native`: läuft mit Host-Werkzeugen (mit dokumentiertem Docker-Fallback für
  sh/php über das Projekt-Image).
- `container`: läuft über Docker. Fehlt Docker oder ein Tool-Image, ist das
  Ergebnis `infrastructure_error`, niemals Skip. Die lokale Windows-Fast-Lane
  setzt Docker damit ausdrücklich voraus.
- `windows-only`: auf Nicht-Windows `not_applicable` mit Grund (der
  Legacy-C#-Build braucht MSBuild/NuGet).

Netzabhängige Gates (`composer-audit`, Legacy-Build-Restore) tragen ein
Netz-Flag; `-NoNetwork` markiert sie als `not_applicable` mit Grund und zieht
keine Tool-Images nach.

Ergebnisklassen je Gate: `pass`, `fail`, `skip`, `not_applicable`,
`infrastructure_error`. `not_applicable` ist ausschließlich für per
Plattform/Flag bewusst nicht anwendbare Gates zulässig; ein fehlendes Werkzeug
ist immer `infrastructure_error`. Datei-scannende Gates behandeln null Treffer
als `infrastructure_error` (Zero-Match darf nie leer grün werden).

### Exitcodes

`0` alle Pflichtgates bestanden; `1` mindestens ein Gate `fail`; `2`
Prüfumgebung unvollständig (`infrastructure_error`, ohne `fail`); `3`
ungültiger Aufruf. Präzedenz: `fail` dominiert `infrastructure_error`, weil ein
bewiesen rotes Gate bereits actionable ist; das JSON-Artefakt hält beide fest.

### Tool-Images und temporäre Ausnahmen

Die containerisierten Linter (yamllint, actionlint, ShellCheck, Hadolint,
ansible-lint/-syntax) beziehen ihre Images aus der Tool-Lockdatei
`scripts/tool-lock.json` (AP4): Registry-Images per Digest, PowerShell-Module
exakt versioniert. Ohne gültige Lockdatei startet der Runner nicht (Exit 2).
Das QA-Ansible-Image (`virtusphere-qa-ansible`) wird lokal aus
`Docker/qa-ansible/Dockerfile` gebaut: pip-Pins mit Hashes in dessen
`requirements.txt`, Collection-Pin aus `Ansible/requirements.yml` (ADR-0025);
fehlt das Image, sind beide Ansible-Gates `infrastructure_error` mit
Bau-Hinweis.
Hadolint-Ausnahmen stehen begründet in `.hadolint.yaml` und werden in AP8 je
Regel neu entschieden. yamllint läuft mit relaxed-Profil; `new-lines` ist
deaktiviert, weil Zeilenenden am Checkout hängen, nicht am Inhalt.

### PHPUnit ohne Skips

Das Fast-Gate `phpunit-unit` läuft als `docker run` mit vollem Repo-Mount und
`--fail-on-skipped`: im `docker exec`-Kontext des App-Containers wären die
Repo-Level-Contract-Tests (nginx-/php-Config) strukturell geskippt. Die
Integration-Lane fährt die volle Suite; ihr `--fail-on-skipped` folgt erst mit
der ADR-0015-Ergänzung (dynamische DB-/Credential-Skips brauchen vorher eine
Politik), Playwright erst mit der ADR-0028-Revision.

### Guards werden bewiesen: `scripts/test-guards.ps1`

Kein Guard gilt als vertrauenswürdig, bevor sein Positiv- (echtes Repo grün),
Negativ- (gezielte Mutation in einer temporären Fixturekopie wird mit der
richtigen Diagnose rot) und Zero-Match-Fall automatisiert bewiesen ist. Dafür
gelten zwei Konventionen für alle Checks:

- `VIRTUSPHERE_CHECK_ROOT` übersteuert das Repo-Root, sodass Checks gegen
  Fixtures laufen, ohne das Repo zu mutieren.
- Fehlerzeilen tragen stabile Diagnose-IDs in eckigen Klammern
  (`[enum-sync.drift]`, `[csp.interpolated-sql]`, `[lang-audit.placeholder-drift]`,
  `[bounds-sync.stale-exempt]`, ...). Die IDs sind der Vertrag zwischen Guard
  und Harness; Umbenennen bricht den Harness absichtlich.

Der Harness klassifiziert je Fall `proven`/`unproven`/`infra` (Exit 0/1/2) und
ist selbst ein Integration-Lane-Gate (`guard-harness`).

### Lane-Vollständigkeit ist gestaffelt

Die Fast-Lane ist mit diesem ADR vollständig. Integration enthält heute
`migrate-check`, `phpunit-full`, `schema-convergence`, `health-contract`,
`guard-harness` und den windows-only Legacy-Build; QA-Wegwerf-Stack,
Wire-Tests über echten HTTP-Pfad, Worker-Stub und Playwright folgen mit ihren
Arbeitspaketen (AP4-AP7). Release enthält heute den Restore-Drill; Browser-
Matrix, Staging, Last, Supply-Chain und Evidenzmanifest folgen (AP5-AP8). Ein
Gate, das es noch nicht gibt, wird nicht als Platzhalter-Pass vorgetäuscht: es
existiert im Runner erst, wenn es echt prüft.

## Consequences

- CI führt die Fast-Lane des Runners aus (`ci.yml`, AP4): Actions auf volle
  Commit-SHAs gepinnt, `timeout-minutes` gesetzt, das JSON-Lane-Artefakt wird
  mit begrenzter Retention hochgeladen. Nur der diff-bezogene CSP-`--range`-
  Schritt bleibt ein eigener CI-Schritt.
- `docs/QA.md` beschreibt Bedienung und Fehlersuche; die Gate-Liste selbst ist
  `scripts/check.ps1 -List`.
- Der CSP-Scan hat getrennte Modi (`--file`, `--worktree` für staged+unstaged+
  untracked, `--range <base> <head>` für CI, `--all-changed` als Alias) und
  schlägt ohne funktionierendes Git hart fehl statt leer grün zu laufen.
- Sessions-Hooks bleiben die leise Frühwarnung; der Runner ist die beweisende
  Instanz. Beide rufen dieselben Skripte mit denselben Diagnose-IDs.
