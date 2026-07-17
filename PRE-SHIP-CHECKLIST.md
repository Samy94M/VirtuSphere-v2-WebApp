# Pre-Ship-Checkliste (Vorlage)

Vor jedem Release-/Meilenstein-Abschluss durchgehen. Diese Datei bleibt eine **leere Vorlage**: Haken, Daten und Messwerte gehören in das QA-Artefakt des jeweiligen Laufs (`scripts/check.ps1 -Json …`) bzw. in `docs/CHANGELOG.md`, nie hierher; ein committeter Haken ist beim nächsten Lesen eine veraltete Behauptung (`doc-semantics`-Gate erzwingt das). Gate-Bedeutung und Interpretation: `docs/QUALITY-GATES.md`; Bedienung: `docs/QA.md`.

## Automatische Gates (der Runner ist der Nachweis)

- [ ] Fast-Lane grün: `powershell -NoProfile -File scripts\check.ps1 -Lane Fast -Json qa-fast.json`
- [ ] Integration-Lane grün (Wegwerf-QA-Stack, volle Suite ohne Skips, E2E, Guard-Harness): `scripts\check.ps1 -Lane Integration -Json qa-integration.json`
- [ ] Release-Lane grün (Restore-Drill, Secret-Scan, SBOM/CVE, Offline-Bundle): `scripts\check.ps1 -Lane Release -Json qa-release.json`
- [ ] Kein Gate endete als `skip`/`not_applicable` ohne dokumentierten Grund im Artefakt; `infrastructure_error` ist ein Blocker, kein Skip.

## Manuelle Nachweise (je Lauf im QA-Artefakt referenzieren)

- [ ] Tastatur-, Fokus- und Screenreader-Durchgang der Kernflüsse in beiden Themes (Login, Mission anlegen, VM bearbeiten, Deploy starten).
- [ ] PowerShell-SYSTEM-Smoke in einer Wegwerf-Windows-VM; Installer-Lebenszyklus (Erstinstallation, Re-Run, Upgrade, Deinstallation).
- [ ] MECM-Staging-Abnahme und reales Ansible-/ESXi-Staging mit zweitem Idempotenzlauf.
- [ ] Keine Secrets/Logs/Build-Artefakte neu getrackt: `git status --short` und `git ls-files | grep -iE '\.(env|pfx|pem|key|log)$'` leer.
- [ ] `docs/CHANGELOG.md` für den Meilenstein aktualisiert.
- [ ] Go-live-Schritte aus `docs/operations/go-live.md` geplant (IP-Allowlist für MECM- und Ansible-Host, Erstpasswort-Datei löschen, DB-Konto-Rotation).

## Meilenstein-gebundene Punkte (offen bis zur jeweiligen Etappe)

- [ ] Clean-Checkout-Releaseprobe auf frischem Host (Ubuntu) als Release-Nachweis.
- [ ] E3-Legacy-Retirement: Desktop-Client, `access.php`, `api/login.php`, `deploy_tokens` physisch entfernen (nur nach akzeptierter E3-Entscheidung, ADR-0019).
- [ ] Visuelles Frontend-Design-Handoff abgeschlossen (ADR-0013).
