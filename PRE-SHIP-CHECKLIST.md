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
  - [ ] Client-Kette an einer echten VM: `Set-VMDisksOnline`, `client_staticip` und `client_getinfo` melden **fail**, wenn kein Datenträger online kommt, kein Adapter zur VLAN-Vorgabe passt oder die Adresse nicht trägt. Offline ist nur die Skriptstruktur prüfbar, nicht das Verhalten der Storage- und Netz-Cmdlets; ein grüner Test ersetzt hier keinen grünen Durchlauf.
  - [ ] Zwei Trigger je Aufgabe wirklich registriert (`Get-ScheduledTask | Select-Object -ExpandProperty Triggers`) und die Aufgabe kommt nach `Stop-ScheduledTask` innerhalb einer Stunde von selbst zurück. Ein abgelehnter Trigger lässt den Installer abbrechen, ein akzeptierter aber wirkungsloser nicht.
- [ ] MECM-Staging-Abnahme und reales Ansible-/ESXi-Staging mit zweitem Idempotenzlauf.
  - [ ] Ein `full`-Deploy von Anfang bis Ende: die Startwartezeit ist jetzt in **Sekunden** parametrisiert (`StartWaitSeconds`, `CreateSettleSeconds` in der serverlist.yml). Offline geprüft ist, dass jede `pause` einer Konstante gehört und unter dem SSH-Idle-Timeout bleibt, nicht dass die gewählte Zeit für diese Flotte reicht.
  - [ ] Autoimporter gegen echte MECM-Cmdlets: ein Paket, dessen Content-Verteilung beim ersten Versuch scheitert, wird im nächsten Durchlauf **erneut** verteilt, und der Lauf meldet solange `warning` mit Ursachencode statt `ok`. `Get-CMDistributionStatus`, `Get-CMApplicationDeployment` und die Ordner-Zuordnung sind offline nur als Argumentliste prüfbar, nicht in ihrer Antwortform.
  - [ ] Device-Sync gegen echte MECM-Cmdlets: ein Gerät ohne ResourceID und ein MAC-Konflikt beenden den Lauf als `warning`, und die gemeldeten Ursachen nennen das betroffene Gerät.
  - [ ] Datastore-Health gegen den produktiven Host belegt: nach dem ersten echten Inventar-Abruf im Job-Log die Zeile `Datastore health:` lesen (steht neben `Inventory queries:`, auch im Gutfall) und prüfen, dass Erreichbarkeit und Wartungsmodus für **alle** Datastores ankamen, nicht nur für einen Teil. `accessible`/`maintenanceMode` sind gegen die Moduldoku gebaut; offline lässt sich nur die Argumentliste prüfen, nicht die Form der Antwort (ADR-0023). Ein Feldpfad, der nicht mehr trifft, sieht ohne diese Zeile aus wie eine Flotte ohne Wartung. Bedeutung der Zeile: `docs/operations/esxi-inventory.md`.
- [ ] Keine Secrets/Logs/Build-Artefakte neu getrackt: `git status --short` und `git ls-files | grep -iE '\.(env|pfx|pem|key|log)$'` leer.
- [ ] `docs/CHANGELOG.md` für den Meilenstein aktualisiert.
- [ ] Go-live-Schritte aus `docs/operations/go-live.md` geplant (IP-Allowlist für MECM- und Ansible-Host, `SEED_ADMIN_*`-Werte nach dem ersten Login aus der `.env` entfernen sofern dieser Seed-Weg genutzt wurde, DB-Konto-Rotation).

## Meilenstein-gebundene Punkte (offen bis zur jeweiligen Etappe)

- [ ] Clean-Checkout-Releaseprobe auf frischem Host (Ubuntu) als Release-Nachweis.
- [ ] **Rollout-Checkpunkt vor dem ersten Prod-Deploy mit Migration 0034 (E3-Retirement, ADR-0035):** letzte echte Legacy-API-Nutzung aus der Prod-DB belegen (`SELECT MAX(created_at), COUNT(*) FROM deploy_logs WHERE category='legacy_api';` plus letzte Zeilen im Detail) und das Ergebnis im Rollout-Protokoll festhalten. Die Migration löscht nur `deploy_tokens`; die Beweiszeilen in `deploy_logs` bleiben auch danach abfragbar (ADR-0035).
