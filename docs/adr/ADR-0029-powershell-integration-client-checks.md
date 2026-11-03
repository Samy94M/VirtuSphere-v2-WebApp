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

## Amendment 3 (2026-09-07): diagnostic streams and terminal-safe redaction

The server and client logging twins normalize bounded ANSI CSI and OSC terminal
sequences before applying their named-secret redactor. Any incomplete escape
introducer is removed or discarded conservatively before redaction, and field
separators, controls and UTF-8 byte limits are applied afterwards. This order is
part of the mirrored contract: formatting must not reassemble `password` or a
token header only after the last security check. The documented guarantee covers
known labelled header, query and key forms, not arbitrary unlabelled free text.

Logging is diagnostic output and therefore never uses PowerShell's success
stream. Structured helpers keep stream 1 exclusively for their return value;
sink warnings remain local, throttled and non-fatal and never create report or
audit traffic. Contract version 1 remains wire-compatible because file schema,
bounds, levels, packaging and public function signatures do not change.

## Amendment 4 (2026-09-07): complete client network plan and owned rollback

`client_staticip` no longer mutates the first matching adapter while later
targets can still prove missing or ambiguous. A pure Common helper validates the
complete snapshot against all local adapters first, including normalized MAC
uniqueness, link state, name collisions, IPv4 values and the single-default-
gateway policy. Empty target sets fail explicitly.

The writer preserves IPv6 and unknown manual IPv4 configuration. It removes an
IPv4 address or default route only when the previous successful VirtuSphere run
recorded that exact value for the same MAC. Matching values are not rebuilt.
Static empty DNS means preserve; DHCP resets DNS to automatic discovery.
Address verification waits at most 15 seconds for `Tentative` to clear and
rejects `Duplicate` and `Invalid`. Best-effort rollback is limited to values
this run can still prove it wrote, so a concurrent foreign change is not erased.
Local configuration success and delivery of the portal phase report remain
separate facts.

## Amendment 5 (2026-09-07): durable ownership for client disk setup

`Set-VMDisksOnline` no longer equates an empty offline selection with completed
work. Before the first write to a new offline RAW disk it persists a versioned
registry operation containing stable storage identity, expected size and the
states `intent`, `online`, `initialized`, `partitioned`, `formatted`, and
`complete`. Disk number and friendly name are deliberately excluded as durable
identity. `UniqueId` is preferred; only the complete serial-number,
LocationPath and size tuple is an accepted fallback.

Every run resolves all incomplete operations against the complete disk
inventory before any storage write. A uniquely owned operation may resume after
online, initialize, partition or format, including after renumbering. It reads
back disk, the single GPT basic-data partition, drive letter, NTFS volume and
the operation label. Unknown online RAW disks and any ambiguous, resized,
foreign-partitioned, boot/system, read-only, clustered or sizeless target fail
closed. Existing GPT/MBR data disks are only brought online. A named global
mutex prevents concurrent writers. Missing optional extra storage is a distinct
successful no-work result; an unresolved owned operation is not.

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

## Amendment 1 (2026-07-16, Plan v2 AP5): the 5.1 target becomes a tested matrix axis

The original CI job ran the suite only under `pwsh` on `ubuntu-latest` — a
different engine than the `powershell.exe` 5.1 the scripts run on in
production. Three additions close that gap:

- **Windows PowerShell 5.1 CI job** (`windows-powershell-51` in `ci.yml`):
  the same `powershell-syntax`/`powershell-tests` gates of the canonical
  runner, executed by `powershell.exe` on `windows-latest`. Only there do the
  registry-backed test blocks run (config loss, address chain, scheme
  override) — on Linux they are not defined.
- **PSScriptAnalyzer compatibility rules** (`PSUseCompatibleSyntax` for
  5.1 + 7.0, `PSUseCompatibleCommands`/`PSUseCompatibleTypes` against the
  Server-2019/5.1 profile) in `PSScriptAnalyzerSettings.psd1`. First catch:
  `Set-ItemProperty -Type` is a dynamic provider parameter the profile cannot
  resolve; the scripts now rely on the REG_SZ default instead.
- **Coverage ratchet** over the three Common/Packaging files, floor in
  `scripts/tool-lock.json` (`pesterCoverageFloorPercent`), enforced by
  `scripts/run-pester.ps1` only where the registry provider exists. The loop
  scripts stay unmeasured by design — an endless loop cannot be loaded by a
  test, which is why their logic lives in the Common files (see Decision).

## Amendment 2 (2026-08-27, Etappe 10D): mirrored logging, separate runtime packages

Server and client still cannot share a runtime file: the server package lives on
the MECM host while each client application is distributed independently to a
new VM. Their logging implementations therefore move out of the two Common
facades into `mecm/VirtuSphere-Logging.ps1` and
`clients/VirtuSphere-Client-Logging.ps1`, but expose a mirrored, explicitly
versioned contract. Pester compares version, levels, six-field schema, daily
filename, UTF-8 byte bounds, redaction and retention settings as one object.

Each Common facade hard-fails before runtime work when its adjacent module is
missing or has another contract version. The server installer stages every
server script, verifies SHA-256 and reads the staged literal contract versions
through the PowerShell AST without executing stateful staged files. It then
disables all existing triggers and stops running tasks fail-closed before moving
the logging module ahead of the Common facade. Client packaging stages and
hashes the phase script, Common facade and logging module as a sibling directory,
then activates the complete set through a same-volume directory swap for fresh
installs and upgrades; an activation error rolls the previous directory back.
This is package completeness, not a new cross-machine shared dependency.

File-sink errors remain non-fatal to the business action and are throttled to
one local outage warning and one recovery message. Cleanup is due once per 24
hours, retains the exact 30-day boundary and uses a shared marker plus an
exclusive lock across parallel processes. The coverage ratchet now includes the
two logging modules in addition to the Common and Packaging files. Wire JSON is
unchanged; the per-process correlation ID is an additive diagnostic header only.

## Alternatives considered

- **Merge the two PowerShell MAC twins into one shared file.** Rejected: the MECM scripts are installed to `%ProgramFiles%\VirtuSphere\mecm` on the SCCM server, the client scripts are packaged into MECM applications and shipped to the VMs. They have no common deployment root. The vector table plus a textual twin-check gives the same guarantee without inventing a shared deployment path.
- **`Set-StrictMode -Version Latest`.** Rejected above: it would turn a legitimately absent JSON field into a crash inside an endless loop on the customer's server.
- **Rewrite `install.ps1` (Package_Vorlage) for PS 7.** Rejected: MECM 2509 hosts and the PXE clients run Windows PowerShell 5.1, which stays the target (`#Requires -Version 5.1`).

## Amendment (2026-09-07): UTF-8 transport, TLS trust exception and ACLs

Server and client JSON writers now pass explicit UTF-8 byte arrays to Windows
PowerShell 5.1 with `application/json; charset=utf-8`. Root arrays and wire
fields remain unchanged. HTTP error diagnostics prefer
`ErrorRecord.ErrorDetails.Message` before attempting to consume the response
stream, and bound extracted envelope text before it reaches logging.

For HTTPS, an empty certificate fingerprint keeps normal PKI validation. A
configured fingerprint is a narrow trust exception for exactly that
certificate when chain validation fails; it is not mandatory pinning of an
otherwise valid PKI certificate. No accept-all callback is installed. The
client reads the installer-provided fingerprint from the registry. Its address
resolver accepts a responding endpoint only if it also returns the bounded
VirtuSphere health document (`status`, `db`, PHP major/minor), so an arbitrary
web server is not selected merely because it returns an HTTP status.

Package-source ACL diagnostics translate identities to Well-Known SIDs instead
of matching localized account names. The fully owned secret registry key is
rebuilt from SYSTEM and Administrators ACEs, including removal of pre-existing
explicit rules; unrelated shares remain diagnostic-only and are never
recursively rewritten.
