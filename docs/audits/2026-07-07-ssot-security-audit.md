# Audit 2026-07-07: SSoT, Dateigrößen, Sicherheit, Agenten-Doku

Status: abgeschlossen; Befunde umgesetzt oder als Entscheidung dokumentiert.
Methodik: Vollscan des Repos (ohne `vendor/`), Abgleich gegen OWASP-Cheatsheets (Authentication, HTTP Headers, CSP, PHP Configuration), agents.md-Standard und Anthropic-Best-Practices.

## Ergebnis in Kürze

Der Stack war bereits überdurchschnittlich sauber: durchgängig Prepared Statements, nonce-basierte CSP, EnvBoot-Fail-fast, libsodium-Secretbox, gehärtetes Compose (`read_only`, `cap_drop: ALL`, `no-new-privileges`), Login-Lockout, Machine-API-Allowlists. Statusstrings, Timestamp-Format und Rollen existieren nur an je einer Stelle.

## Behobene Befunde

| Befund | Fix |
|---|---|
| `bin/`, `obj/`, `VirtuSphere_TemporaryKey.pfx` trotz `.gitignore` getrackt; `bin/Debug/Ansible/*` waren veraltete, abweichende Kopien der `Ansible/`-Playbooks (SSoT-Verstoß) | `git rm -r --cached`, Commit `77c992e`. Die `.pfx` bleibt in der Git-Historie (LAN-Repo, ClickOnce-Temp-Key; Rewrite bewusst unterlassen). |
| `access.php` renderte `$exception->getMessage()` als JSON an Clients (Info-Disclosure) | Serverseitiges Logging + generische Meldung, Wire-Shape unverändert. Commit `9d4df58`. |
| Login-Timing verriet, ob ein Konto existiert (kein `password_verify` bei unbekanntem User) | Dummy-bcrypt-Vergleich für unbekannte Usernamen. Commit `565212e`. |
| README driftete zum Changelog (datierte Fortschritts-Sektionen, Mojibake, `ue/ae`-Transliteration); AGENTS.md wiederholte GROK-§1-Verbote | README neu strukturiert, AGENTS/GROK-Eigentümerschaft geschärft. Commit `832d93c`. Regrowth-Schutz: `scripts/check-doc-hygiene.sh`. |

## Bewusste Entscheidungen (kein Fix)

- `portal/health.php` bleibt unauthentifiziert: Monitoring-Endpoint, gibt nur ok/error-Status aus. Bei Bedarf Machine-API-IP-Allowlist vorschalten.
- `vendor/` bleibt getrackt: Air-Gap-Anforderung (ADR-0007), bewusster Tradeoff.
- `logs/initial-admin-password.txt` ist Laufzeit-Artefakt (untracked); Setup-Doku verlangt Löschen nach Erstlogin.
- Kein PHPStan/Psalm in dieser Phase (ADR-0015-Scope); bei E3 als eigener ADR neu bewerten.

## Dateigrößen (ADR-0006, ~400 Zeilen)

Über der Schwelle, Split nur opportunistisch beim nächsten inhaltlichen Umbau: `lib/ansible.php` (588), `lib/repo/vms.php` (527), `lib/repo/deploy_jobs.php` (450), `portal/vm_edit.php` (404). Kein Refactoring-Sprint nötig — Dateien sind kohärent.

## Nachgelagerte Übernahmen aus LagerPro (2026-07-07)

Drift-Checks (`check-enum-sync.sh`, `check-php-version-sync.sh`, `check-doc-hygiene.sh`) mit SessionStart-Integration, PostToolUse-Hooks (`php-lint.sh`, `lang-parity.sh`), Backup/Restore-Probe (`backup.sh`, `restore_test.sh`, `docs/operations/backup.md`) und `PRE-SHIP-CHECKLIST.md`.
