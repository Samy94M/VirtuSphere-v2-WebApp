# ADR-0029: The PowerShell Integration Clients Get Checked

Date: 2026-07-13
Status: Accepted

## Context

The pre-release hardening campaign (`docs/TESTPLAN.md`) covered the portal, the workers and the machine API from four directions: PHPUnit, PHPStan, contract tests, Playwright, curl. Its tool table (Appendix D) lists no PowerShell tool, and no phase named the `Powershell-MECM/` tree.

That tree is not incidental code. It is eleven scripts that run **as SYSTEM, in endless loops, on the customer's SCCM server** (device sync, package sync, autoimporter) and on every freshly PXE-installed client (`getinfo` → `hostname` → `staticip` → `disks`). They were the only first-party code in the project that no tool had ever looked at: no linter, no test, not in CI, no `Set-StrictMode`. A typo in a variable name was a silent `$null`, and the loop would keep spinning and doing nothing.

The 2026-07-13 review found what that blind spot had been hiding:

- A tracked `functions.psm1` at the repository root carrying a **plaintext MySQL password**, a hardcoded host and `SslMode=none`. Dead WinForms-era code, referenced from nowhere, reading a table (`vms`) that does not exist in the schema, so it cannot ever have run against this database.
- The MAC canonicalization existed **three times** (PHP, MECM common, client common — the last two byte-identical copies) with no shared test. This is the exact seam where TESTPLAN finding 2.2 (P1) already bit once: a MAC stored in the wrong notation makes a VM invisible to MECM with no error at all.
- Every script threw away the JSON error envelope the WebApp had just been hardened to send (`199163e`): Windows PowerShell 5.1's `Invoke-RestMethod` raises on 4xx/5xx and discards the response body, so the logs said `(400) Bad Request` and never the reason.
- `http://` was hardcoded in five places, so enabling HTTPS on the portal and switching HTTP off would have silently killed the entire MECM integration and the client chain.

## Decision

Treat the PowerShell integration clients as first-class code with the same discipline as the rest: a linter, tests, CI, and a shared source of truth across the language boundary.

- **PSScriptAnalyzer + Pester**, run by `scripts/run-pester.ps1` and **in CI** (`.github/workflows/ci.yml`). `pwsh` is preinstalled on `ubuntu-latest`; the two modules come from the PSGallery and are **not vendored** — the same dev-tooling rule ADR-0028 set for Playwright and Infection. The air-gap rule governs the shipped runtime artifact, not the build host.
- **The MAC canonicalization gets a cross-language SSoT**: `Docker/WebAPI/tests/fixtures/mac-vectors.json`. PHPUnit (`MacNormalizeTest`) checks `virtusphere_normalize_mac()` against it; Pester checks both PowerShell twins against it; a further Pester test asserts the two twins are textually identical. Three implementations, one table, and a build fails when any of them drifts. They cannot share a file — they are deployed to three different machines — so the table is the only honest way to hold them together.
- **Pure logic moves out of the endless loops** into the shared modules (`Read-VsPackageConfig`, `Get-VsSupersededNamePattern`, `Convert-VsSubnetMaskToPrefix`, `Get-VsErrorDetail`). What lives inside a `while ($true)` cannot be called by a test without starting it. This mirrors the portal rule that a page's helpers live in a `lib/<page>_*.php` module.
- **`Set-StrictMode -Version 1.0`, deliberately not `Latest`.** Version 1.0 catches the failure that matters here (an uninitialized variable from a typo). From 2.0 upward PowerShell also throws on access to a *non-existent property*, and these scripts read JSON in which optional fields are legitimately absent (`$device.mission`, `$cfg.DeployTo`). A stricter level cannot tell "optional field absent" from "typo" and would crash the sync scripts in production.
- **The scheme becomes configurable** (`Scheme` in the registry, `http` by default). The machine API is exempt from the HTTP→HTTPS redirect, so *enabling* TLS does not break the clients; *disabling* HTTP would have, and nobody would have found out until the next PXE deployment. Self-signed certificates need an explicit opt-in (`$VsAllowSelfSignedTls`), because PS 5.1 has no `-SkipCertificateCheck` and a permanently blind TLS check is worse than honest HTTP.

## Consequences

- CI gets a PowerShell job. It costs about a minute and covers code that runs as SYSTEM at the customer's site.
- `PSAvoidUsingEmptyCatchBlock` stays **enabled**: a silent failure is precisely the bug class these scripts are prone to. The 20 empty `catch` blocks now write a `Write-Debug` line, so a `-Debug` run shows what was swallowed. Only three rules are excluded, each with its reason in `PSScriptAnalyzerSettings.psd1`.
- `functions.psm1` is deleted. The secret in it must be treated as compromised regardless: it was in the **initial commit** (`4fd9379`), so `git rm` does not remove it from history. Rotating that database account is an operator task, recorded in `docs/operations/go-live.md`.
- The Pester layer is dev/CI tooling only. Nothing here ships, nothing is mounted into a container, and `Powershell-MECM/` itself is unchanged in what it *does* — it is only now observable.

## Alternatives considered

- **Merge the two PowerShell MAC twins into one shared file.** Rejected: the MECM scripts are installed to `%ProgramFiles%\VirtuSphere\mecm` on the SCCM server, the client scripts are packaged into MECM applications and shipped to the VMs. They have no common deployment root. The vector table plus a textual twin-check gives the same guarantee without inventing a shared deployment path.
- **`Set-StrictMode -Version Latest`.** Rejected above: it would turn a legitimately absent JSON field into a crash inside an endless loop on the customer's server.
- **Rewrite `install.ps1` (Package_Vorlage) for PS 7.** Rejected: MECM 2509 hosts and the PXE clients run Windows PowerShell 5.1, which stays the target (`#Requires -Version 5.1`).
