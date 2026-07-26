# Security-Testmatrix: OWASP ASVS 5.0 Level 2 + WSTG

Version 1 (2026-07-17, Plan-Etappe 7/AP7). Diese Matrix ist der versionierte
Vertrag, WELCHE Sicherheitszusagen das Projekt prüft, WO der Beweis liegt und
was offen ist. Sie behauptet nichts, wofür kein Test, Gate oder dokumentierter
manueller Schritt existiert. Statuswerte: **automatisiert** (läuft in einer
Lane), **manuell** (dokumentierter Abnahme-Schritt), **offen** (gebucht, mit
Etappe), **n. a.** (mit Begründung).

Scope-Anker: LAN-only-Portal ohne Self-Service-Registrierung, ohne OAuth/SSO,
ohne Zahlungs-/Mandantenlogik. ASVS-Kapitel zu Föderation, Mobile/WebRTC und
GraphQL sind n. a. (Funktionalität existiert nicht).

## V2/V6 Authentifizierung

| Zusage | Status | Beweis |
|---|---|---|
| Passwort-Policy konfigurierbar, Minimum serverseitig erzwungen | automatisiert | `PasswordPolicyTest`, `users.php`-Handler; E2E `crud-users.spec.js` (create/reset erzwingen Policy-Länge) |
| Kein Klartext-Passwort in DB (password_hash/PASSWORD_DEFAULT) | automatisiert | `crud-users.spec.js` (Hash-Wechsel-Beweis), Repo-Grep-Verbot roher Passwortspalten (GROK Forbidden Patterns) |
| Lockout: per-Benutzer-Sperre + IP-weite Drossel (15 min) | automatisiert | `AuthAuditTest` (Integration, login_attempt/ip_locked-Pfade); Entsperr-Flow E2E `crud-users.spec.js` clear_lock |
| Erstanmeldung erzwingt Passwortwechsel | automatisiert | `crud-users.spec.js` (must_change_password nach create/reset) |
| Generische Fehlermeldung bei Login-Fehlschlag (kein User-Enumeration-Orakel) | automatisiert | `AuthAuditTest` + `PhaseCContractTest` (generische Envelope) |
| Credential-Rotation des kompromittierten Alt-Kontos (functions.psm1-Fund) | **offen (Go-live-Blocker)** | `docs/operations/go-live.md`; Historie dokumentiert in `.gitleaks.toml` |

## V3 Session-Management

| Zusage | Status | Beweis |
|---|---|---|
| Session-ID-Rotation beim Login (Fixation) | automatisiert | `session.use_strict_mode` + `session_regenerate_id` gepinnt durch `SessionHardeningContractTest`; Browser-Beweis der Rotation E2E `session-security.spec.js` |
| Cookie-Flags HttpOnly/SameSite=Strict/use_only_cookies/kein trans_sid | automatisiert | `SessionHardeningContractTest` (INI-Ebene) + Bootstrap-Cookie-Params |
| Secure-Flag dynamisch ab TLS | automatisiert | ADR-0027-Pfad, `HttpsConfigTest` |
| Session-Datei überlebt die längste konfigurierbare Sitzung (gc_maxlifetime) | automatisiert | `SessionHardeningContractTest` (Konstante gegen INI) |
| Serverseitiger Ablauf (session_expires_at), Verlängerung via session_ping | automatisiert | Settings-Wert E2E `settings-flow.spec.js`; erzwungener Re-Login nach Ablauf E2E `session-security.spec.js` (Session-Datei gealtert, kein Client-Timer) |
| CSRF-Token sessiongebunden, POST ohne/mit fremdem Token abgelehnt | automatisiert | E2E `rbac-csrf.spec.js` (400 + unversehrte DB-Zeile), `PortalPostGuardContractTest` (jede Seite läuft durch den Guard) |
| Logout invalidiert serverseitig (Back-Button zeigt nichts Geschütztes) | automatisiert | E2E `session-security.spec.js` (destroy + Clear-Site-Data; direkter GET 302, Back+Reload landet am Login) |

## V4 Access Control

| Zusage | Status | Beweis |
|---|---|---|
| Sichtbarkeit == Handler-Berechtigung (statisch) | automatisiert | `PermissionParityTest` |
| Direkter POST mit unberechtigter Rolle wird abgelehnt (403), keine Writes | automatisiert | E2E `rbac-csrf.spec.js` (users.manage, catalog.write) |
| Anonymer POST landet am Login (302), keine Writes | automatisiert | E2E `rbac-csrf.spec.js` |
| Seiten-403 je Rolle (users/settings/logs für user-Rolle) | automatisiert | E2E `health-matrix.spec.js` (denied-Matrix) |
| IDOR: IDs werden gegen Mission/Besitzer-Scope geprüft (vm_id∈mission, job∈mission) | automatisiert | Repo-Guards (`repo_reset_vm_mecm_id`, `db_importMAC` 409-Pfad) + `MacImportCallbackTest`; Portal-Handler mit Scope-Checks |
| Machine-API: IP-Allowlist + Token; Ablehnung ungültiger Tokens | automatisiert | `AccessLegacyRbacTest`, `MachineApiWireTest`, Restore-Drill-Smoke (Token-Ablehnung) |

## V5 Input-Validierung / Injection

| Zusage | Status | Beweis |
|---|---|---|
| SQL nur über Prepared Statements | automatisiert | GROK Forbidden Patterns + contract-reviewer; PHPStan-Lane |
| Feld-Validierungsmatrix (Länge, Format, Unicode, Grenzen) | automatisiert | `ValidatorRulesTest`, `DomainInputPatternTest`; feindliche Wertematrix je Render-Kontext E2E `field-roundtrip.spec.js` |
| YAML-Injection: Benutzerwerte bleiben Skalare, nie Struktur | automatisiert | `AnsibleServerlistYamlSafetyTest` + `yaml-roundtrip`-Gate (semantischer Parser-Beweis mit feindlicher Golden-Mission) |
| Kommando-/SSH-Injection: Benutzertext erreicht Remote-Ausführung nur als YAML-Skalar in Dateien, nie im Kommando | automatisiert | `AnsibleServerlistYamlSafetyTest` + `AnsibleStepMarkerTest` (Kommandoketten-Kontrakt), `AnsiblePlaybookHygieneContractTest` |
| Upload-Validierung: Zertifikat (PEM/Größe/Schlüssel-Match), Mission-Import (JSON/Version/TTL) | automatisiert | `HttpsConfigTest` (Parser + Fixtures) + E2E `https-flow.spec.js` (Nicht-PEM-Ablehnung), `mission-transfer.spec.js` (Nicht-JSON-Ablehnung), `MissionTransferRoundTripTest` |
| XSS: kontextgerechtes Escaping, CSP mit Nonce, keine Inline-Handler | automatisiert | `lint-csp-patterns`-Gate, E2E `health-matrix.spec.js` (keine CSP-Violations), `field-roundtrip.spec.js` (Script-Payload bleibt Text) |
| Log-Injection: CR/LF-Kollaps + Längenbegrenzung vor Audit-Zeilen | automatisiert | `audit_snippet()` + `AuditEventsTest` |
| CSV-Formel-Injection im heruntergeladenen Artefakt erneut geprüft | automatisiert | `PortalCsvExportTest` (Guard isoliert) + E2E `csv-injection.spec.js` (jeder Formel-Lead im heruntergeladenen VM-CSV neutralisiert) |

## V7/V8 Krypto & Datenschutz

| Zusage | Status | Beweis |
|---|---|---|
| Credentials at rest: libsodium mit APP_KEY; falscher Schlüssel scheitert erwartbar | automatisiert | Restore-Drill (`tests/tools/restore-drill-probe.php`: Entschlüsselung mit richtigem, Scheitern mit falschem APP_KEY) |
| Secrets nie im Klartext zurückgerendert (value leer/maskiert) | automatisiert | `credentials.php`-Markup + E2E-CRUD (Editor zeigt Platzhalter) |
| TLS: Admin-Upload-Flow, HSTS-Toggle, Redirect-Guards | automatisiert | ADR-0027-Tests + E2E `https-flow.spec.js` |
| Private-Key-Dateirechte 0600 vor Sichtbarkeit | automatisiert | `https_write_material` + `HttpsConfigTest`; Restore-Drill prüft Rechte im Config-Archiv |

## V10/V14 Fehlerbehandlung, Logging, Konfiguration

| Zusage | Status | Beweis |
|---|---|---|
| Generische Fehlerseiten/-envelopes ohne Stacktrace/SQLSTATE | automatisiert | `PhaseCContractTest`, `MachineApiErrorShapeContractTest`; Fault-Beweis TESTPLAN 4.3 |
| Keine exakten Komponentenversionen nach außen (X-Powered-By, Server, health.php) | automatisiert | `VersionExposureContractTest` (Fast) + `health-contract`-Gate (Laufzeit) |
| Security-Header auch auf nginx-eigenen Antworten (403/404) | automatisiert | Header-Maps in `default.conf`; `health-contract`-Gate (nginx -t + Exposure) |
| Interna nicht erreichbar (/lib,/vendor,/tests,/logs → 403) | automatisiert | `health-contract`-Gate (/tests=403) + nginx deny-Regeln |
| Audit-Log für Auth-, Rechte- und CSRF-Ereignisse | automatisiert | `portal_guard_post`/`portal_forbid` + `AuditEventsTest`; E2E-Ablehnungen erzeugen Audit-Zeilen |
| Secret-Scan über volle Git-Historie, null unerklärte Funde | automatisiert | `secret-scan`-Gate (Release-Lane), Allowlist-Vertrag `.gitleaks.toml` |
| Dependency-/Image-CVE-Gates, SBOM, Digest-Pins | automatisiert | composer audit (Fast); `sbom`- und `image-cve`-Gates (Release-Lane, Trivy/Syft mit befristeten Ausnahmen in `.trivyignore.yaml`) |
| Container-Härtung gepinnt (read_only, cap_drop, no-new-privileges) | automatisiert | Compose gesetzt; `compose-hardening`-Gate in allen Lanes (`scripts/check-compose-hardening.ps1`) |

## WSTG-Zuordnung (Kurzindex)

| WSTG-Bereich | Abdeckung |
|---|---|
| ATHN (Authentication) | V2-Zeilen oben; Drossel-/Lockout-Tests, generische Fehler |
| SESS (Session) | V3-Zeilen; CSRF-Laufzeitmatrix `rbac-csrf.spec.js` |
| ATHZ (Authorization) | V4-Zeilen; `PermissionParityTest` + Laufzeit-403-Matrix |
| INPV (Input Validation) | V5-Zeilen; YAML/SQL/XSS/Log-Injection-Beweise |
| CRYP (Cryptography) | V7-Zeilen; libsodium + TLS-Flow |
| ERRH (Error Handling) | V10-Zeilen; PhaseC-Contract |
| CONF (Configuration) | Versionsoffenlegung, deny-Regeln, Secret-Scan, AP8-Rest |
| BUSL (Business Logic) | Deploy-Statusmaschine (Matrix 1-17, ADR-0030), Idempotenz-/409-Beweise `MacImportCallbackTest` |
| CLNT (Client-side) | CSP-Nonce-Contract, keine Inline-Handler, `health-matrix` Konsolen-/CSP-Scan |

## Pflege

Eine neue Sicherheitszusage bekommt eine Zeile MIT Beweisort, bevor sie
behauptet wird; ein neuer offener Punkt nennt die Etappe, die ihn schuldet.
Historische Läufe/Zahlen gehören in QA-Artefakte, nicht hierher.
