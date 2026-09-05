# QA Baseline

This page is the operating manual for the VirtuSphere QA battery: how to run each check and how to debug a red one locally. What a gate means and how to interpret its result lives in `docs/QUALITY-GATES.md`; the decisions behind the setup are ADR-0015 (baseline and skip policy), ADR-0028 (E2E tiers) and ADR-0031 (runner). It is intentionally container-first so checks work the same way on Windows hosts and in air-gapped LAN environments once Docker images and Composer vendor artifacts are present.

## Canonical Check Runner

`scripts/check.ps1` is the executable SSoT of all quality gates (ADR-0031). It runs under Windows PowerShell 5.1 and PowerShell 7 and replaces "run these commands in order" lists; the commands below stay documented for targeted debugging of a single gate.

The public entry point is deliberately small. It dot-sources focused modules from `scripts/lib/check/` for runtime helpers and the Fast, Integration and Release registries; importing a module defines functions only and neither emits output nor changes environment/current directory. Gate names, order, lane membership, exit codes, progress lines and JSON schema remain contracts of `check.ps1`, not separate module APIs. `VirtuSphere.CheckRunner.Tests.ps1` holds the pre-split golden catalog, help/invalid-call behavior, Fast selection and JSON shape, including negative mutants.

```powershell
powershell -NoProfile -File scripts\check.ps1 -List                 # Gates der Fast-Lane anzeigen
powershell -NoProfile -File scripts\check.ps1                       # Fast-Lane (jeder PR / lokaler Vorabcheck)
powershell -NoProfile -File scripts\check.ps1 -Lane Integration     # Merge/Nightly-Gates (baut eigenen QA-Stack)
powershell -NoProfile -File scripts\check.ps1 -Gate enum-sync,phpstan   # gezielte Teilmenge
powershell -NoProfile -File scripts\check.ps1 -Json qa.json -KeepArtifacts
```

Every gate reports `pass`, `fail`, `skip`, `not_applicable` or `infrastructure_error`; a missing tool is an infrastructure error, never a skip. Runner exit codes: `0` all gates green, `1` at least one gate failed (dominates), `2` environment incomplete, `3` invalid invocation. Containerized linters (yamllint, actionlint, ShellCheck, Hadolint, ansible-lint) run through Docker; on Windows the Fast lane therefore requires Docker. `-NoNetwork` marks network-dependent gates (`composer-audit`, `secret-scan`, the AP8 supply-chain gates) as not applicable and never pulls images.

### Observable progress

The runner prints `[n/total] RUN <gate>` immediately before every selected gate and `[n/total] <result> <gate>` immediately afterwards. Keep those lines visible for long local and agent-driven runs. If a terminal or tool buffers the process output, tee the same stream to a file that can be polled while the process is running, or invoke the lane in observable blocks. Read that live output at least once per minute and report the latest real `[n/total]` line; a message containing only elapsed time does not show progress. Before the first gate, `[0/total]` is the honest status when the selected total is known. Do not infer a counter from process age or report a gate as complete before its result line exists.

Tool versions are pinned in `scripts/tool-lock.json` (AP4): registry images by digest, PowerShell modules by exact version. The runner refuses to start without a valid lock file (exit 2). The Ansible gates (`ansible-syntax`, `ansible-lint`, `yaml-roundtrip`) run against the locally built `virtusphere-qa-ansible` image; build it once with `docker build -f Docker/qa-ansible/Dockerfile -t virtusphere-qa-ansible:latest .` (pip pins with hashes in `Docker/qa-ansible/requirements.txt`, collection pin from `Ansible/requirements.yml`).

The `yaml-roundtrip` gate (AP5) is the semantic complement to the substring pins in PHPUnit: `Docker/WebAPI/tests/tools/render-golden-serverlist.php` renders the hostile golden mission (`tests/fixtures/golden-mission.json` — Norway tokens, control bytes, unicode, overrides, autostart clamps) through the production generators, and `Ansible/tests/roundtrip_verify.py` loads the result with PyYAML (Ansible's YAML 1.1 semantics) and deep-compares it against the fixture's `expected` contract. Change the generator and the `expected` block must move with it — that forced conversation is the point. `AnsiblePlaybookHygieneContractTest` pins the playbook side: `ignore_errors` only registered and allowlisted, command tasks classified, the ESXi password never outside the no_log module argument, credential files at mode 0600. Still open for the Release lane / real staging (rollout step 10): a second idempotence run against a real ESXi host.

Stage 9's VM identity is covered at four boundaries: `VmIdentityCollisionTest` proves cached namesake blocking, UUID-versus-MOID semantics and explicit identity-only adoption against MySQL; `AnsibleVmIdentityContractTest` pins the live name+UUID checks before create, power and autostart mutations and the export's per-VM mismatch conversion; the MAC callback wire tests prove that a contradiction writes neither identity, MAC nor lifecycle state; and `deploy-actions.spec.js` drives both confirmation branches and verifies that adoption creates no deploy job. The golden YAML fixture carries both bound and unbound identity fields. Still **not performed** and not replaced by these offline checks: collision/adoption and a second full/idempotent run against a real standalone ESXi host.

`scripts/test-guards.ps1` proves the guards themselves: every check runs once against the real repo (green), once against a mutated fixture copy (must turn red with the right `[check.case]` diagnostic ID) and once against a zero-match root (must not pass silently). Fixtures are wired through `VIRTUSPHERE_CHECK_ROOT`; the repo is never mutated. Exit codes: `0` all proven, `1` a guard failed to detect its mutation, `2` only infrastructure gaps (e.g. no host PHP for the php-lint hook case).

### Container hardening and supply chain (AP8)

The `compose-hardening` gate (all lanes) runs `scripts/check-compose-hardening.ps1`: it parses the resolved `docker compose --profile "*" config` semantically and pins the existing hardening as a contract — `read_only`+tmpfs, `cap_drop: ALL` plus the exact documented `cap_add` sets, `no-new-privileges`, PID/memory limits, restart policy, healthchecks, `service_healthy` start ordering, phpMyAdmin's `tools` profile and loopback binding, tag+digest pins on registry images, digest pins in every first-party `FROM`/`COPY --from`, and the absence of any Docker socket mount. Any loosening fails the build with a stable `[compose.<case>]` ID; the resolved config JSON carries interpolated secrets and is never printed or stored.

`phpunit-full` owns the QA database while it runs. The gate stops the QA deploy and maintenance workers before PHPUnit creates queued, running and stale job fixtures, then restarts both in a `finally` path with Compose `--wait`. This keeps deliberately synthetic rows from being claimed or reaped by a real loop, and it guarantees later health and browser gates see healthy workers even when PHPUnit itself fails.

The Release lane adds the supply-chain gates. `sbom` writes an SPDX SBOM per runtime image; `image-cve` scans each image with the digest-pinned trivy from `scripts/tool-lock.json`. Its documented policy: the full report (including unfixable findings) goes to the QA artifacts, but the gate only **blocks** on Critical/High findings that have a fix available (`--ignore-unfixed`) — a gate that is permanently red on Debian `will_not_fix` entries guards nothing. Exceptions live in `.trivyignore.yaml` and are only valid with CVE ID, justification, owner and an `expired_at` date; trivy re-reports expired entries automatically, so an expired exception breaks the build exactly like a missing one. `npm-audit` is the composer-audit counterpart for the dev-host e2e tooling (`tests/e2e`), blocking at `high`.

`offline-bundle` (Release lane) runs `scripts/build-offline-bundle.sh`: it saves the runtime images, builds `vendor.tar.gz` (`composer install --no-dev` inside the PHP image), downloads the Ansible collections for the air-gapped control node, adds the closed 8R-O durable-runner payload after verifying its own `runner/SHA256SUMS`, produces SBOMs and CVE reports, snapshots the source (`git archive`), writes `provenance.json`, `INSTALL.md` and a bundle-wide `SHA256SUMS` manifest, and then verifies itself with the bundled `verify.sh` — which needs only `sha256sum` and no network, matching how the target system verifies it offline. The runner installer and read-only preflight do not activate a product mode or change linger; their target-host output is still an open 8R-S artifact.

### Integration lane and the QA stack

The QA PHP service mounts `/tmp/virtusphere-directory` as a nested tmpfs owned by `www-data` (`uid=33`, `gid=33`, mode `0700`). This preserves the strict ownership contract of `directory_ca_file()` and makes an earlier diagnostic `docker exec` as root harmless: root cannot replace the mountpoint, while portal requests can still create their per-CA files. Do not remove this mount or loosen the production ownership check; `DirectoryModuleContractTest` pins the QA-side arrangement.

The Integration lane provisions its own throwaway stack as its first gate (`qa-stack`): a separate Compose project `virtusphere-qa` built from `docker-compose.yml` plus `Docker/qa/docker-compose.qa.yml` with `Docker/qa/qa.env` (committed throwaway values, no secrets). The database lives in a project-scoped volume seeded fresh from `struktur.sql`, `ssl/` and `conf.d/` are project volumes, and the web port is `127.0.0.1:8031`; nothing in the lane ever touches the dev stack or the dev database. The gate then applies migrations, seeds the QA admin from `qa.env` and waits for portal health. `check.ps1` tears the stack down (`down -v`) when the run ends; `-KeepArtifacts` leaves it up for debugging.

Against that stack the lane runs `migrate-check`, the **full** PHPUnit suite with `--fail-on-skipped` (a dynamic skip is never legitimate here; tests that need an allowlisted or non-allowlisted client IP arrange it themselves via `tests/Integration/ClientIpAllowlist.php` and restore the previous state), `schema-convergence`, the health/exposure contract, the guard harness, and `e2e-portal`: the functional Playwright Chromium suite plus the deterministic visual proof from `tests/e2e/` (ADR-0028 revisions). Runner and Playwright call the same resolver: `PLAYWRIGHT_CHROMIUM` when explicitly set, otherwise the exact executable belonging to the lockfile-installed `playwright-core`; there is no local-user fallback or highest-revision scan. `npm ci` runs automatically when `tests/e2e/node_modules` is missing.

### Etappe 12 deploy and portal UX contract

The deploy queue has one server-side decision surface: `deploy_queue_blockers()`.
Targeted PHPUnit covers the complete union, its exhaustive renderer, password
attributes, duration boundaries, MECM display text, mission/help links, the
settings/deploy owner sets and the bidirectional portal asset registry.
`tests/e2e/specs/etappe12-ux.spec.js` complements that with every live queue
control, disabled-but-populated values, stale/aborted responses, non-JSON and
`401`/`403` failure closure, DOM text injection, the immediate server recheck,
the no-JavaScript schedule path, nested help focus and 390 px overflow.

Successful blocker reads are intentionally read-only: the browser test compares
the deploy audit-log count before and after. Machine API endpoints and fields are
not part of this path. Run the canonical Fast and Integration lanes for final
acceptance; Integration exercises the functional Chromium suite and the exact
deterministic light/dark visual harness. Etappe 12 does not create or update
committed visual baselines; Etappe 17 introduced them and their update command.

### Etappe 14 form accessibility contract

`lib/forms.php` owns stable control, hint and error IDs, invalid state and the
complete `aria-describedby` list. `FormAccessibilityTest` proves the pure API;
`FormAccessibilityContractTest` scans every migrated renderer and the repeated
VM template. The page-wide negative check in `accessibility.spec.js` runs before
axe on every portal page in both themes and rejects duplicate IDs, dead
references, visible unowned generated hints/errors and an invalid control with
no error reference.

Targeted debugging commands:

```powershell
docker exec virtusphere-v2-webapp-php-1 vendor/bin/phpunit -c /var/www/html/phpunit.xml.dist --filter FormAccessibility
Push-Location tests/e2e; npx playwright test specs/form-accessibility.spec.js --project=chromium; Pop-Location
```

The browser spec disables native validation once to obtain a real server error,
checks the accessibility-tree description, inserts two interfaces and two disks
with the keyboard, and changes deploy mode/stagger values in both directions.
This is the repeatable screen-reader semantics sample: the invalid Host control
must announce its server error through the computed accessible description, and
the VM interface group must announce the Gateway hint. Integration remains the
final proof because it also runs the complete axe matrix and deterministic
light/dark visual project against the synthetic QA stack.

### Etappe 14A network and MAC contract

The contract is tested at four independent boundaries:

- `VmNetworkContractTest`, `EsxiObjectNamesTest`,
  `EsxiDatacenterResolutionTest` and `MacImportV2ContractTest` cover exact
  names, `0`, whitespace/case variants, grouped zero/duplicate issues,
  versioned fingerprints, WDS mode semantics, per-kind evidence, inclusive
  datacenter age, strict V2 decoding and historical V1 reads.
- `VmNetworkContractIntegrationTest` proves hard-mode zero inserts,
  Start/Autostart warning-only queuing, all-or-nothing stagger writes,
  grandfathered unrelated edits, stored-MAC preservation against a conflicting
  incoming value, a running/cancelling interface-writer race and the
  queued-versus-claimed Missions-WDS writer boundary.
  `DeployWorkerNetworkPreflightIntegrationTest` drives the actual worker: a
  changed bundle and a deleted explicit selection both produce canonical
  progress, structured evidence and zero remote work; a real second connection
  proves cancellation can win the combined result/terminal CAS without leaving
  `network_preflight` on the cancelled job.
  `DeployEnqueueRaceTest`, `DeployJobRetryFlowTest`,
  `DeployDatacenterResolutionTest` and `DeployWorkerOutcomeTest` pin current
  selection/retry scope, blocker precedence, cache races and convergence.
- `MacImportCallbackTest` proves exact WDS success, missing/case/ambiguous WDS,
  non-export modes, attempt/generation/handle fences, per-VM atomicity,
  order-independent semantic active replay, scope/fingerprint conflicts,
  terminal duplicate rejection, bounded joblog/audit traces and zero domain
  writes on the rejection matrix. `MachineApiWireTest` pins the additive 409
  reason envelope and the exact `413 request_too_large` response;
  `MacImportV2ContractTest` proves exact result/response bounds and precedence.
- `NetworkMacContractTest` is the positive/negative/zero-match ownership guard.
  Its production owner glob rejects an unguarded interface writer, a second mode list, a queue or
  worker bypass, case-insensitive callback lookup and a V2 path without the
  semantic fingerprint. It also pins the two bound families: one owner for the
  display/JSON bounds with no second `array_slice` over a finding list, and the
  per-job scope cap in the one repo gate that queue, stagger member, retry and
  worker recheck already pass through.
- `DeployPreflightBoundsTest` covers the display bounds: selection at
  DETAIL-1/DETAIL/DETAIL+1, an empty list, reproducible selection from a
  reordered input, the candidate bound with both counts, byte capping that
  removes from the list end and flags itself, an envelope that cannot fit even
  empty, UTF-8 names that survive whole, the shared byte limiter at every
  budget across a two-byte character, and the decoder against a truncated,
  a historical unbounded and three inconsistent documents.
- `MacImportBoundsTest` covers the callback bounds against the real constants:
  result and response at MAX-1/MAX/MAX+1, the request bound measured in bytes
  including a payload of umlauts, identifier cuts that end before a codepoint,
  and the worst case of the largest regular job scope
  (`VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_VMS` x
  `VIRTUSPHERE_DEPLOY_JOB_SCOPE_MAX_INTERFACES_PER_VM`, every VM failing, every
  identifier at its maximum stored width) producing a complete V2 result and a
  complete response inside 1 MiB with margin. The counter-test proves the cap is
  load-bearing: twice that scope breaks a bound.
  `MissionImportUploadLimitContractTest` extends the same ladder outwards, so
  `post_max_size` and both nginx sources stay above the 16 MiB request contract
  and the uploader read cap equals the application response bound.

The browser integration covers server-rendered and live queue blockers, exact
VM-editor VLAN controls, legacy invalid values, add/remove/undo/keyboard focus,
stale live responses, mobile wrap and both locales/themes. Machine API coverage
continues to assert the existing request envelope, HTTP meanings, five status
strings, RBAC/CSRF separation and additive-only response/result fields.

Final acceptance runs only through `scripts/check.ps1`: the complete Fast lane,
then Integration against its fresh `virtusphere-qa` stack. No dynamic skip or
`infrastructure_error` is a pass. Both lanes retain their canonical
`[n/total] RUN` and result lines; focused commands above are debugging evidence,
not a replacement for the lanes.

Recorded result of the Etappe-14A acceptance runs (2026-09-01, on a working
tree verified unchanged for the whole duration of each run):

- Fast: `29 pass, 0 fail, 0 infrastructure_error, 0 not_applicable, 0 skip`.
  `phpunit-unit` 217.7 s green with `--fail-on-skipped`.
- Integration: `35 pass, 1 fail, 0 infrastructure_error, 0 not_applicable,
  0 skip`. `phpunit-full` 334.5 s green **without skips** against the freshly
  built QA stack, which is where the 16 MiB request bound is exercised on the
  real path (`post_max_size = 20M` verified inside the running QA container);
  `migrate-check` pending=0 on a fresh database; `schema-convergence`,
  `health-contract` and `guard-harness` (722.6 s) green.

The one failure is `e2e-portal`, and it is a visual-harness determinism finding,
not an Etappe-14A defect. The harness captures every page twice per theme and
compares run 1 against run 2 under a committed zero-tolerance contract
(`channelThreshold: 0`, `maxDiffPixelRatio: 0`). Seven of eight images are
byte-identical; `missions-desktop` in the light theme differs in 38 of 1 440 000
pixels (ratio 0.0000264), by plus or minus one in single colour channels in both
directions, on the antialiased corners and edges of one control. What that
bounds it to:

- `portal/missions.php`, the page in that image, is not touched by Etappe 14A,
  and `components.css`, which styles that control, is not in the diff either (that file was still the single component sheet then; Etappe 16 split it into four).
- Run metadata of a green and a red run are identical: browser revision 1228,
  the three pinned font SHA-256 values, launch arguments, viewports, locale and
  timezone.
- The noise moves. Two consecutive runs produced exactly the same 38-pixel set
  on that one image; a third run on the same code produced zero differences;
  and the Release-lane run additionally surfaced 11 pixels on
  `deploy-desktop` in the dark theme, again plus or minus one on an antialiased
  edge. A defect would keep hitting the same place. Cross-run differences
  outside a session sit in a different region (a timestamp), which the contract
  never compares because it only relates run 1 to run 2 inside one session.

The `launchArgs` block in `tests/e2e/visual/runner-contract.json` is the
Etappe-14A attempt to make that rasterization deterministic. It reduced the
finding but did not remove it. Raising the tolerance or chasing the rasterizer
further both change what every future visual gate means, and reviewed visual
targets belong to Etappe 17, so neither was done here. The gate stays red and
named rather than silently retried until green.

The Release lane on the Etappe-14A commit answered
`42 pass, 2 fail, 0 infrastructure_error, 0 not_applicable, 0 skip`. Every
non-browser gate is green, including `restore-drill`, `secret-scan` over the
full history, `sbom`, `image-cve` (six images, no fixable critical or high),
`offline-bundle` built and verified offline, and `npm-audit`.

The second failure is `e2e-browser-matrix`, where a small minority of the suite
failed on Firefox and WebKit only while the rest passed. It is not an
Etappe-14A defect either, and the evidence is direct rather than inferred:

- `deploy-log.spec.js:120` and `deploy-recovery.spec.js:38` both PASS on WebKit
  when re-run against the same commit on a correctly seeded QA stack, together
  with every other case in those two files.
- `directory-ad.spec.js:436` exercises AD sign-in. The commit touches no auth,
  directory, LDAP, login, session or permission file at all, and the rest of
  that same file passed in the lane run.
- The same suite is fully green on Chromium (`e2e-portal`, Integration lane)
  and on Windows Edge (`e2e-msedge`, 786 s, Release lane).

The pattern is timing inside the 33-minute cross-engine run, not logic. The
suite already runs single-worker and serial, so parallelism is ruled out; what
is left is that both engines drive the portal through `docker exec` seeding
more slowly than Chromium, late in a run where the machine is busiest.

Two changes address it without hiding anything:

- Firefox and WebKit get a per-project test timeout of 60 s and an expect
  timeout of 15 s, against Chromium's 30 s and 7 s. More waiting time changes
  nothing about what is asserted, so a real defect still fails; it only stops
  the clock from deciding the outcome. Retries stay at zero on purpose: a retry
  turns a genuine intermittent defect green and tells nobody.
- Each Playwright gate writes its HTML report into its own folder
  (`VIRTUSPHERE_E2E_REPORT_DIR`, set from the project list in
  `Invoke-PlaywrightSuite`). All three gates used to share one folder, so the
  last one to run erased the report of the one that had failed, and a red
  matrix could not be diagnosed afterwards. That is what happened here: the
  assertion texts of the three failures were gone before they could be read.

Re-running the whole matrix until it is green would prove nothing and was not
done; the isolated re-run is the evidence, and the timeout change is the
mitigation whose effect the next Release lane will show.

### Etappe 14B baseline: what the create path looks like before the repair

The create repair (`docs/audits/2026-08-13-create-flow-reliability-implementation-plan.md`)
starts from a production incident: a fifteen-VM job ended after 1800 seconds
with `Remote command produced no output (idle timeout)` while fourteen of the
fifteen VMs appeared on ESXi. The output stream stopped; the ESXi task did not.
Two artefacts pin that starting point so every later stage proves its change
instead of asserting it.

**`ansible-output-buffering` (Fast lane, containerised).** The mechanism is
measurable without ESXi and without the network:
`Docker/qa-ansible/output-buffering-probe.py` runs a three-item sleep loop
twice in the pinned QA image and records when each finished item's line reaches
the reading process. Without `PYTHONUNBUFFERED` all three lines arrived at
9.57 s of a 9.82 s run; with it they arrived at 4.11 s, 6.70 s and 9.30 s of a
9.44 s run. Both cases are asserted, and the thresholds are fractions of each
run's own total so a slower machine moves both together. The control case is
not decoration: a runtime that never buffered would let any unbuffered claim
pass, and the gate would guard nothing. If the control case ever goes green-by-
buffering-no-more, the runtime changed and the plan's premise needs re-reading -
that is not a threshold to raise.

**`CreateFlowBaselineContractTest`.** Six facts, each written first as the
starting point and then rewritten by the sub-stage that changed it, which is how
the file reads today: create is no longer a step of the remote sequence at all
(Etappe E drives one call per VM), the playbook mutates exactly one VM
asynchronously instead of looping over the selection (Etappe D), the per-VM
create result and `create_started_at` exist and are written by the worker
(Etappe C/E), a retried create still repeats the entire original selection
because already created VMs become `verify_skip` units rather than disappearing
from the scope (Etappe F), create-only still produces no net lifecycle change,
which the new orchestration had to keep, and the full log tail contract was
already in place from Etappe 10A. A red assertion here without one of those
stages means the create path moved by accident.

Three things the plan asked for turned out to be done already, by stages that
landed after it was written: the `ansible-doc` preflight no longer copies the
module manual into the job log, `ansible_command.php` is already a require
facade over split modules, and the full log tail with `has_more`/`caught_up`
arrived with Etappe 10A. Etappe G therefore shrinks to the progress card.

Runtime versions, recorded rather than assumed. The pinned QA image carries
ansible-core 2.19.11, Python 3.13.14 and community.vmware 6.2.0. Production
reported ansible-core 2.16.3 in the incident; that number comes from the
customer report, not from a machine this repository can reach, and it sits below
the 2.19 floor that the 6.2.0 pin enforces. Whether the production host runs an
older collection or the ESXi path is failing there for that reason is a site
question, and Etappe D turns it into a hard preflight check instead of a
document.

### Etappe 14B: what proves the repaired create path

Four layers, none of which needs ESXi. **The async seam** is the
`ansible-create-async` gate: a sleep stub in the pinned QA image proves that a
`poll: 0` start leaves a re-findable job id in the dedicated directory, that a
separate later query reports running and then finished, and that the targeted
cleanup removes exactly that status file. It also pins the reason the whole
design hangs off the status file: an unknown job id answers `finished` without
`failed`, so a lost job would otherwise look like a completed one.

**The state machine** is `CreateFlowWorkerContractTest` (no database, no SSH):
no cleanup trap in the control command, 0700 creation without following
symlinks, extra-vars as typed JSON in both directions, the budget boundary at
-1/0/+1 and with no start time, an in-flight unit beating a lower pending
position, four materialisation defects, the terminal status matrix and the two
log line formats. `DeployCreateWorkerFlowTest` runs the same machine against
real MySQL on a deterministic fifteen-unit fixture with failures at positions 1,
8 and 15: twelve confirmed successes stay untouched, the job ends `partial`, a
confirmed failure continues while an `uncertain` unit stops the job, the
identity commit is exercised in all five branches including replay and case,
a foreign fence writes nothing, and the reaper converges exactly the one unit
still in flight and is idempotent.

**The retry boundary** is `DeployCreateRetryMatrixTest` plus
`DeployJobRetryFlowTest` and `DeployCreateReleaseTest`: an unresolved unit
blocks the retry with `retry_create_unresolved`, and the release that lifts it
requires a successful inventory pull strictly newer than both the job and the
unit, with neither the name nor the stored UUID present in it. The operator's
Recent-Tasks statement is deliberately not one of those conditions; it is
recorded as their statement, never as something VirtuSphere established.

**The browser layer** is `deploy-create-progress.spec.js` for the per-VM card
and its paging.

What none of this proves is the real host. Transport loss, a cancel during a
running VM and a database outage are exercised against the pure functions and
the stored rows, not against a real SSH transport, and the thirteen controlled
ESXi staging cases (section 15.6 of the create plan) remain a site acceptance:
a small thin VM, a second run ending `unchanged`, an allowed hardware
deviation ending `updated`, one and two foreign namesakes blocked before any
mutation, a representative eager-zeroed-thick VM running past the old idle
window, a fifteen-VM job whose vSphere Recent Tasks show at most one concurrent
create task, a controlled SSH break after and before a stored job id, a cancel
during a long VM, a worker restart during a live job id, a second complete run,
and the cleanup check. They create real VMs and real datastore usage, so target
host, datastore, VM prefix and the person responsible for deleting them are
recorded before the first one runs, and only those recorded throwaway VMs are
removed afterwards, never by a name prefix.

### Etappe 14C: what proves the supervised process shape

`DeploySupervisorPolicyTest` drives the decision machine as a pure function,
including the negative assertion the etappe rests on: a walk over every state in
which the child may still be alive, proving that none of them answers `start`.
It also pins that one stale observation is not a finding, that the restart
window ends in a stored deadline rather than a hot loop, and that a shutdown
outranks a pending cooldown.

`DeploySupervisorFaultRunTest` is the fault run, and it uses REAL processes. Its
child beats its liveness file, stops beating, stays alive and deliberately
ignores SIGTERM; the policy drives it through the real seam. What that proves
and the pure test cannot: that "the child is gone" is established by waitpid
against an actual process, that a child ignoring SIGTERM is escalated rather
than replaced, and that exactly one child exists at every point of the run. The
clock is simulated and the process is real, which is the only combination that
tests the thing without faking it.

`DeploySupervisorContractTest` pins what a source review keeps getting wrong
because everything reads correctly: the default compose file still starts the
worker, the override moves command AND healthcheck together, the healthcheck
touches no database, no supervisor module reaches into job execution, exactly
one call site starts a child, and the blocker vocabulary is closed in both
directions.

Two of its assertions exist because a real run contradicted a correct-looking
source. **`docker stop` sends SIGQUIT here**, not SIGTERM: the `php:*-fpm` base
image declares `STOPSIGNAL SIGQUIT` for php-fpm's graceful shutdown and this
container inherits it. Combined with the fact that PID 1 ignores any signal it
has no handler for, `docker stop` on the deploy worker took 30.4 s and ended in
exit 137 while `docker kill -s TERM` exited cleanly in the same build. After the
fix all three loop processes stop in 0.4 s with exit 0. The measurement is a
container-level fact, so the guard is a source contract on the shared signal
list plus the sentence that says why SIGQUIT is in it.

`DeployServiceHealthTest` gained the supervisor branch of the availability
precedence, including the order that matters: `cooldown` beats "the child is
missing", because a supervisor inside its restart window legitimately holds no
child and the other way round every planned restart would read as a breakage.

Not proven here and deliberately open: that a healed worker creates no second VM
on ESXi. Everything in front of that is proven (no second child, no second job,
no second create unit, no second async job id); the VM itself is the site
acceptance, case 11 of section 15.6 of the create plan.

### Etappe 14D: what proves the rollout hostname

`MecmRolloutContractTest` carries the fence decision table as a written-out data
provider rather than something derived from the implementation: a table
generated from the code only restates it, while a table a reader can check
against the rule is what makes "a future revision is refused as firmly as an old
one" reviewable. Alongside it, the closed reset-blocker vocabulary is walked
against the exhaustive `match`, against the `@param` union that keeps PHPStan
from demanding a `default` arm, and against the `vms.skip_<reason>` labels the
bulk result builds at RUNTIME, which no grep for literals would ever find. The
same file pins that `deploy_vm_hostname_claims` has exactly one writer and that
`REPO_VM_COLUMNS` carries no rollout runtime, because that list is what a
template capture, a clone and the JSON export copy field by field.

`MachineApiWireTest` walks `VIRTUSPHERE_MECM_DEVICE_LIST_COLUMNS` against the
live `getDeviceList` payload in BOTH directions, and its fixture seeds the
rollout snapshot as a deliberately DIFFERENT value from the desired hostname.
That second detail is the test: with both seeded equal, a wire that exported the
desired value would pass a test written to prove it exports the snapshot.

`VirtuSphere.RolloutIdentity.Tests.ps1` is the PowerShell half and it exists
because the defect it replaces is invisible in any single line. `$mecmDevices[$d.Name] = $d`
reads perfectly; what was wrong with it only shows in a table, so the suite is
one. Driving that table found two real defects before any reviewer did: a
PowerShell hashtable miss yields `$null`, and `@($null)` has Count 1, so an
unknown name plus an unknown MAC looked like two hits with the same empty
ResourceID and the resolver answered `use` with no ResourceID instead of
`import`. The suite therefore also asserts the property directly: no input may
produce `use` without a ResourceID.

Three guards that already existed caught regressions in this stage rather than
being written for it, which is the intended shape. `AuditRegistryTest` walks
every registered event through the presenter and found that both new events had
no description arm, so every fence-refusal audit was being swallowed by the
`catch (Throwable)` around the machine-audit path: the 409 was correct and the
row was silently absent. The cause-vocabulary guard found that `device_import_failed`
had lost its producer in the sync rewrite, which would have reported a genuinely
failed import as "pending, next scan" forever. And the file-size ratchet forced
the split that put the reset in `lib/repo/vms_mecm_reset.php` and the VM
identity fields in `lib/vm_edit_names.php`.

Verified against reality rather than only in source: the 409/200/404/400 matrix
was driven through the real endpoints against the dev database (legacy caller at
revision 1, legacy caller after a reset, stale, future, malformed, unknown VM,
idempotent replay, and the membership and client-ACK variants), the real
`getDeviceList` and `getDeviceInfos` payloads were read back, and the reset was
driven against real rows to observe the snapshot moving, the revision rising,
the tombstone being set and the two claim rows collapsing into one.

Not proven here and deliberately open: that the site's real task sequence adopts
the imported name unchanged. That is a lab fact about MECM, not something an
offline suite can establish, and the release stays blocked on it.

## Test Commands

Run PHPUnit inside the PHP container:

```powershell
docker exec virtusphere-v2-webapp-php-1 composer --working-dir=/var/www/html test
```

Run PHPStan (level 5, baseline ratchet per ADR-0015) inside the PHP container:

```powershell
docker exec virtusphere-v2-webapp-php-1 composer --working-dir=/var/www/html run stan
```

Run the stdlib-only Ansible client and durable-runner protocol tests in an isolated Python container. The runner cases cover closed golden vectors, unknown fields, token/hash/path/symlink rejection, atomic result files, bounded redacted output, no duplicate launch decision, exact observer offsets/rotation rejection and a read-only preflight:

```powershell
docker run --rm -v C:\projekte\VirtuSphere-v2-WebApp:/repo:ro -w /repo python:3.13-alpine python -m unittest discover -s Ansible/tests -v
```

Run the language catalog parity audit from the project image:

```powershell
docker run --rm -v C:\projekte\VirtuSphere-v2-WebApp:/repo -w /repo virtusphere-v2-webapp-php php scripts/lang-audit.php --ci
```

Der deaktivierte 8R-O-3-Inventarconsumer wird gezielt in drei Schichten geprüft:

```powershell
docker compose exec -T php vendor/bin/phpunit --fail-on-skipped tests/Unit/RemoteExecutionProtocolTest.php
docker compose exec -T php vendor/bin/phpunit --fail-on-skipped tests/Static/RemoteInventoryConsumerContractTest.php
docker compose exec -T php vendor/bin/phpunit --fail-on-skipped tests/Integration/RemoteInventoryConsumerTest.php
```

Die Tests beweisen die direkte JSON-Schema-SSoT, geschlossene Übergänge,
Unerreichbarkeit aus dem Produktworker, `disabled` als Prepare-Blocker,
Epoch-/Token-/Generationsfencing, Reattach nach verlorenem Zwischenwrite,
genau einmaligen Logoffset, Lücken- und Protokollfehler, Resultat-SHA,
Secret-Sentinel und Cleanup-Barriere. Sie ersetzen ausdrücklich keine echten
8R-S-Faults an SSH, User-Bus, Linger, cgroup, Hostrestart oder Ressourcenlimits.

Die unverdrahtete O4-Recoverygrundlage besitzt zusätzlich Unit-, Static- und
MySQL-Verträge:

```powershell
docker compose exec -T php vendor/bin/phpunit --fail-on-skipped tests/Unit/RemoteRecoveryPolicyTest.php
docker compose exec -T php vendor/bin/phpunit --fail-on-skipped tests/Static/RemoteRecoveryFoundationContractTest.php
docker compose exec -T php vendor/bin/phpunit --fail-on-skipped tests/Integration/RemoteRecoveryFoundationTest.php
```

Sie beweisen die geschlossene Entscheidungsmatrix, dass nur ein beweisbarer
Nichtstart terminalisierbar wäre, dass Recovery keinen aktiven Job beendet,
dass die Anforderung idempotent ist und dass der heutige Reaper die neue
Grundlage nicht lädt. Das reale gemeinsame Umschalten von Reaper, Consumer und
VM-Sweep bleibt ein 8R-S-Nachweis.

Die O5-Step-Policy wird ohne Produktverdrahtung gemeinsam mit ihrem statischen
Unerreichbarkeitsvertrag geprüft:

```powershell
docker compose exec -T php vendor/bin/phpunit --fail-on-skipped tests/Unit/RemoteStepPolicyTest.php tests/Static/RemoteStepPolicyContractTest.php
```

Die Matrix folgt für Inventory, Export, Start, Autostart und Powercycle der
Playbook-SSoT. Negative Fälle beweisen, dass Disabled, Legacy und Rollback nie
`remote_v1` wählen, Create und Full keine O5-Policy besitzen, inkonsistente
Aktivierungen blockieren und weder Aktivierungsschreiber noch Produktcaller
hinzukommen. Das prüft lokale Policylogik, aber keine Laufzeit, externe Wirkung,
HA-/Lizenzlage oder Powercyclephase auf dem Air-Gap-Ziel.

Der Mission-Import wird in vier Schichten geprüft. Die Unit- und Static-Fälle
brauchen keine Datenbank, der Grenzvertrag muss das ganze Repo sehen (er liest
`Docker/php/conf.d` und `Docker/nginx`):

```powershell
docker compose exec -T php vendor/bin/phpunit --fail-on-skipped tests/Unit/MissionTransferDocumentTest.php tests/Unit/MissionImportPortalTest.php
docker compose exec -T php vendor/bin/phpunit --fail-on-skipped tests/Static/MissionImportPreviewErrorContractTest.php
docker compose exec -T php vendor/bin/phpunit --fail-on-skipped tests/Integration/MissionTransferRoundTripTest.php tests/Integration/MissionImportShapeContractTest.php
docker run --rm -v C:\projekte\VirtuSphere-v2-WebApp:/repo -w /repo/Docker/WebAPI virtusphere-v2-webapp-php php vendor/bin/phpunit --fail-on-skipped tests/Static/MissionImportUploadLimitContractTest.php
```

Sie beweisen die gemeinsame Dokumentkanonisierung (kaputte Listen zählen nicht
mehr als ein Element, ein fremder MAC blockiert nicht und wird nicht
geschrieben, unbekannte Felder bleiben wirkungslos), die geschlossenen
Handoff-Status samt Token-Isolation, die Uploadcode-Klassifikation, genau eine
sichere Serverdiagnose ohne Payload/Token/Dateiname sowie die Ordnung
`App-Limit < upload_max_filesize < post_max_size < client_max_body_size`.

Zwei Fälle der Import-Abnahmematrix besitzen bewusst keinen funktionalen Test,
weil ein solcher Test die Aussage nicht stärker machen würde:

- **Bestätigen mit dem Token des vorherigen Uploads.** Die Vorschauseite des
  älteren Uploads existiert nicht mehr, sein Bestätigen-Formular also auch
  nicht; ein Nachweis bräuchte einen von Hand gebauten POST samt CSRF-Token und
  würde am Ende dieselbe Verzweigung prüfen. Bewiesen wird sie stattdessen
  zweifach: `MissionImportPortalTest` pinnt den Status `mismatch` und dass die
  reine Funktion nichts verändert, `MissionImportPreviewErrorContractTest` pinnt,
  dass genau dieser Zweig kein `unset($_SESSION['mission_import'])` enthält.
- **Datenbankausfall während Vorschau oder Bestätigen.** Der Ausfall lässt sich
  in der Suite nicht gefahrlos erzeugen, ohne den Stack für alle anderen Gates
  mitzureißen. Bewiesen wird die Antwort darauf: der Static-Vertrag trennt den
  erwarteten Dokumentfehler vom unerwarteten `catch (Throwable)` und verlangt für
  letzteren Diagnose plus Referenzmeldung, `MissionImportPortalTest` beweist die
  Logzeile selbst samt Sentinel-Prüfung auf Payload und Dateiname.

Die Ini-Grenzen liegen im Image, nicht im Mount. Nach einer Änderung an
`Docker/php/conf.d/zz-virtusphere.ini` müssen die drei darauf basierenden
Dienste neu gebaut und ersetzt werden, danach wird der laufende Stand belegt:

```powershell
docker compose build php deploy-worker maintenance-worker
docker compose up -d php deploy-worker maintenance-worker
docker exec virtusphere-v2-webapp-php-1 php -r "echo ini_get('upload_max_filesize'), ' ', ini_get('post_max_size'), PHP_EOL;"
```

Erwartet wird die Ausgabe `4M 6M`. Ein blosses `docker compose restart` genügt
nicht: es startet den alten Image-Stand mit den alten Grenzen.

Run the migration preflight without mutating the database:

```powershell
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --check
```

Check nginx and the live health/test exposure contract:

```powershell
docker exec virtusphere-v2-webapp-webserver-1 nginx -t
curl.exe -s -S -i http://127.0.0.1:8021/portal/health.php
curl.exe -s -S -i http://127.0.0.1:8021/tests/bootstrap.php
```

Expected results: PHPUnit and the Python client tests exit green, lang audit reports DE/EN parity clean, migration check ends with `check: ok`, the health endpoint returns HTTP 200, and `/tests/bootstrap.php` returns HTTP 403.

## Continuous Integration (GitHub Actions)

`.github/workflows/ci.yml` runs the **Fast lane of the canonical runner** (`scripts/check.ps1 -Lane Fast`) on every `push` to `main` and on every pull request, instead of maintaining a second step list that could drift from the local gates. The **Integration lane** runs as its own job on every merge to `main`, nightly and on manual dispatch (never on PRs): same setup plus a Playwright Chromium install pinned by `tests/e2e/package-lock.json`, then `scripts/check.ps1 -Lane Integration` with the throwaway QA stack described above; the machine-readable result and, on failure, the Playwright report are uploaded as artifacts. Actions are pinned to full commit SHAs, the job has a `timeout-minutes` budget, and the machine-readable lane result (`qa-fast.json`) is uploaded as a build artifact with limited retention. Setup before the lane: PHP 8.4 on the host (so `php -l`/lang-audit lint with the runtime version, not the runner default), Node 20, `.env` from `.env.example` for the compose gate, the project PHP image and the QA Ansible image built from their Dockerfiles, `composer install` inside the project image, and Pester/PSScriptAnalyzer at the exact versions from `scripts/tool-lock.json`.

**No MySQL server** is provisioned: the Fast lane runs the unit + static suites without skips (`--fail-on-skipped`); the full suite including integration tests belongs to the Integration lane, which follows the ADR-0015 amendment and ADR-0028 revision. One step stays outside the lane on purpose: `lint-csp-patterns.sh --range <base> <head>` checks the pushed commit range (the lane's `csp-patterns` gate checks the worktree, which is always clean in CI; locally use `--worktree`).

End-to-end browser tests gate the Integration and Release lanes, never the PR-facing Fast lane (ADR-0028 revision); connected CI may fetch the Playwright tooling while the shipped artifact stays browser- and Node-free. PHPStan findings in analysed files are fixed, not re-baselined; the legacy machine-API root files join the scope after the E3 retirement decision. To confirm the pipeline actually fails on regressions, remove a `__t()` key from one locale and observe the `lang-parity` gate turn red.

## Phase C Regression Coverage

`Docker/WebAPI/tests/Static/PhaseCContractTest.php` locks down the Phase C contracts that are easy to regress during later refactors: debug-gated error details, generic machine-API 500 envelopes, type-scoped MECM package sync, portal permission/admin guards, login `ip_locked` messaging, validator i18n, generic health failures, deploy-worker heartbeat/reaper wiring and terminal-job lock guards.

`Docker/WebAPI/tests/Static/PortalConfirmContractTest.php` locks down the confirmation contract (see below). Like `PortalComboHooksTest`, it pins a markup-to-JavaScript agreement as text, because no compiler or linter checks one.

The palette itself was proven in the browser rather than in a spreadsheet (Etappe 16). A contrast audit walks every element that owns text on eighteen portal addresses in both themes, resolves the foreground and the background by climbing ancestors and ALPHA-COMPOSITING every translucent layer until the stack is opaque, and applies the WCAG large-text thresholds exactly (24 CSS px, or 18.66 px at weight 700 or more): 3872 text nodes, none below its floor. A focus audit focuses 734 elements across eight pages in both themes and checks three different failures, because they are three: no indicator at all, an indicator below 3:1 against what it sits on, and an indicator that exists and is covered by a sticky element, which is WCAG 2.2 Focus Not Obscured and the one no colour check can see. A ring relocated onto a wrapper counts as drawn, which is what the option cards do. Forced colors is emulated and the policy verified on three pages. Writing the contrast audit produced one false alarm worth keeping in mind: flipping the theme after load measures the page mid-transition, because `.checkbox-item` animates its background while `color` switches at once, which reads as nine elements at 1.01:1; the audit therefore sets the theme through localStorage BEFORE the page loads, the way the portal head script does.
`Docker/WebAPI/tests/Static/CssColorTokenContractTest.php` with `tests/Support/CssColorScanner.php` (Etappe 16) holds the palette boundary: `base.css` names the colours, every other portal stylesheet asks for them through `var(--token)`. It parses rather than greps, because every interesting case hides: a hex inside a gradient stop, inside a shadow, inside a `var()` fallback, inside a nested function, and inside a data URL that is only CSS after decoding. Comments and ordinary strings are non-matches by construction, and custom property names are removed before the named-colour pass, so a token called `--danger` cannot be read as the colour it names. The diagnostic ids are stable: `color.hex`, `color.function`, `color.named`, `color.system-outside-forced-colors`, `color.system-unpaired`, `color.data-uri`. Sixteen forbidden shapes and thirteen allowed ones run through the scanner as fixtures, plus a comment case, an empty-sheet case and two zero-match assertions on the real run (at least two sheets scanned, more than two hundred declarations seen), because a scan that reads nothing reports exactly what a clean repository reports. Writing it found an evasion in the reader it started from: `CssRules::declarations()` ends a value at the next semicolon, which cuts `url(data:image/svg+xml;base64,...)` at the media type so the payload never reaches the decoder; the scanner therefore splits declarations on structure, respecting parentheses and quotes. System colours are the one exception, and they are allowed on purpose, because refusing them would be the accessibility defect: inside `@media (forced-colors: active)` the user agent owns the palette, and a system background must name the foreground it is paired with.

`Docker/WebAPI/tests/Static/ModalAxisContractTest.php` does the same for the modal layout rules in `feedback.css` (see below), for the same reason: nothing else in the toolchain reads that stylesheet.

`Docker/WebAPI/tests/Integration/DeployJobReaperTest.php` exercises the real database path for stale running jobs. It creates only `phpunit_phase_c_*` missions, removes them in setup/teardown, and skips if unrelated running deploy jobs exist because the production reaper is intentionally global.

`Docker/WebAPI/tests/Unit/DeployReapObserverGraceTest.php` pins the rule that a failure detector must not count silence it was not awake to observe: an observer that has just (re)connected is blind, an unset one counts as blind too, and the grace outlasts what a live worker needs to restore its heartbeat after the database returns while staying under the staleness window. That the gate cannot be bypassed is proven by the reaper integration tests, which all had to start declaring an observer; `MaintenanceReapTest` asserts both directions on the same job, and `DeployJobReaperTest` pins that the established cause travels into `last_error` and the job log while a caller without one keeps the bare observation.

`Docker/WebAPI/tests/Unit/DeployWorkerDbChannelTest.php` proves the side channel of a running job without a database and without waiting out a backoff. `DeployWorkerDbOperations` is the seam that makes that possible: there is no honest way to make a real `mysqli` fail on demand, so the four operations are injected and the test drives the outage, the bounded spool and its overflow line, the at-most-one backed-off reconnect per tick, the fixed resume order (ownership, heartbeat, spool), the exactly-one STDERR state line per outage, redaction, lost ownership dropping the spool unwritten, and the bounded `--once` versus loop recovery. The point it pins is what the class exists for: a database write that fails must not close the SSH stream, because the playbook keeps running on the Ansible host either way and its exit code is the only remaining evidence about the VMs it created.

`Docker/WebAPI/tests/Unit/DeployJobReapObservationTest.php` pins the reap wording as a pure function, so the sentence an operator reads can be checked without a database: job id, heartbeat age against the limit, `locked_by` (or "nobody"), and the transition - and nothing about cause. A caller's additional observation is appended labelled as separate, which is the whole difference between reporting what was seen and asserting why.

`Docker/WebAPI/tests/Static/AnsibleActivityContractTest.php` pins what the System-status Ansible card may call evidence, and how it reads it. `attempts > 0` is the entire difference between a job a worker claimed and one that was cancelled out of the queue, so the guard holds that predicate against the single place that increments the column (`repo_claim_deploy_job()`). Its second half is a query-shape contract nothing can observe at fixture scale: mission history is never purged, and the `ROW_NUMBER()` form this replaced read all of it to keep three rows, which `EXPLAIN` against a representative QA history showed as a full table scan plus a sort and a materialized result (the plan and its numbers are in the stage record of `docs/audits/2026-08-11-deploy-reliability-master-plan.md`). The reader now walks `deploy_jobs_ansible_activity` backwards per credential and stops at the first match, so the guard forbids `ROW_NUMBER` and requires the `ORDER BY ... LIMIT 1` shape that keeps the index usable. It finally derives from the fixtures themselves that every seeded deploy job names its `attempts`, because a fixture left at the schema default would prove the card with a job that never ran; `tests/Integration/AnsibleActivityTest.php` drives the same rule against MySQL, including the newer queued-cancelled job that must not displace an older processed one.

`Docker/WebAPI/tests/Static/CliRequireClosureContractTest.php` walks the require closure of every CLI entrypoint and fails on a call the closure does not define. A portal page may call `h()` without requiring `lib/layout.php`, because the bootstrap loaded it; a worker has no bootstrap. `lib/deploy_worker_outcome.php` called `repo_record_worker_result()` without requiring `lib/repo/heartbeats.php`, so the deploy worker never wrote its System status row and the page told the operator to restart a worker that was up and processing jobs. Nothing caught it: the report is wrapped in `catch (Throwable)` so an `Error` decayed into one STDERR line per minute, `PhaseCContractTest` pins the call and passes while the callee is missing, and `WorkerTrafficLightTest` requires `lib/maintenance_tasks.php`, which pulls the module in and made the broken entry point green.

"Every CLI entrypoint" is a claim the test now earns rather than asserts. Which files those are is derived from the guard the entrypoints carry themselves (a top-level `if (PHP_SAPI !== 'cli')`), plus a `DUAL_SAPI` registry for the one file that serves both SAPIs on purpose and therefore cannot carry it (`lib/migrate.php`). A second, independent scan closes that registry from the outside: every `php .../lib/<x>.php` the project's own compose commands, healthchecks and setup scripts start must be a registered entrypoint. The hand-written list this replaced was missing `lib/seed.php`, and walking it surfaced a real gap - `lib/repo/log.php` calls `__t()` and the seed closure never loaded `lib/lang.php`. What counts as "our function" is every name `lib/` defines, never a prefix list; source-reachable but CLI-unreachable calls sit in `GUARDED`, each with the guard that makes it so. Positive, negative and zero-match fixtures drive the analyser on a throwaway tree, so its negative direction is proven without breaking the repo.

The PHP container mounts `Docker/WebAPI` as `/var/www/html`, so schema baseline files outside that tree are checked from the host when relevant:

```powershell
Select-String -Path Docker\mysql\mysql-init\struktur.sql -Pattern 'deploy_interfaces_mac_lookup'
```

Several contract tests read above that mount, because their subject lives there: `struktur.sql`, the E2E specs, the compose/setup scripts, the PHP ini drop-in. They **skip** in the container and only enforce in a run that sees the repo root, so a change to those files is proven by the command below, not by `composer test`. Which tests those are is not worth counting here - the number changes whenever a contract gains a repo-level source, and a stale count reads as a promise; `grep -l markTestSkipped Docker/WebAPI/tests/Static/*.php` lists the current set with its reason, and the Fast lane runs them all with `--fail-on-skipped`, so none of them can quietly stop enforcing:

```powershell
docker run --rm -v C:\projekte\VirtuSphere-v2-WebApp:/repo -w /repo/Docker/WebAPI virtusphere-v2-webapp-php ./vendor/bin/phpunit --no-coverage tests/Static tests/Unit
```

Skipping is the deliberate choice over failing: the documented container command has to stay green, or a lane that is always red is a lane nobody reads. What must never happen is the third option, passing. `file_get_contents()` on a missing path returns false, and an empty haystack makes every `assertStringNotContainsString()` vacuously true, so these tests ask `is_file()` first and assert the file is non-empty before scanning it.

## MECM Report Channel Coverage (reportRun + Site Health)

The additive `action=reportRun` on `mecm_report.php` and the new "VirtuSphere MECM
Site Health" reporter (ADR-0018, Amendment 2026-07-23) are pinned across the three
languages that carry the contract:

- **Wire (`MecmReportWireTest`, PHPUnit).** The legacy `heartbeat` stays byte-exact
  and its response unchanged. `reportRun` covers validation, auth, the allowed
  sources (`device-sync`, `packages-sync`, `autoimporter`, `mecm-site-health`) and
  the `error`-keyed envelope; `started`/`completed` with site health sending only
  `completed`; the idempotent replay of an identical completed `run_id`
  (`200 {deduplicated:true}` with no counter or timestamp change); a completed report
  with a new `run_id` always accepted, never rejected for arrival order; the
  category/outcome binding for site health
  (`site_warning`/`site_critical`/`provider_*`/`query_failed`); the size limit; and
  no VM-lifecycle write.
- **Status derivation and repository (PHPUnit).** `last_event` drives the badge:
  fresh V2 `ok`/`warning`/`fail`/`unknown`, a fresh V1 heartbeat yellow as Legacy
  (again after a script rollback), the two-clock running run with "läuft seit" and
  stale only after `max(3 × interval, 60 s, RUN_GRACE)`, group-scoped `missing` (site
  health does not make a sync red), and `failure_streak` counting consecutive `fail`
  only.
- **Pester (`VirtuSphere-Common`, `mecm_site-health`).** `Send-VsRunReport` payload,
  header and byte-length truncation before send; token/secret redaction; the pure
  status mapping `0→ok`, `1→warning`, `2→fail`, anything else `→unknown`; provider
  discovery order; `provider_unreachable` only after two consecutive failures; each
  loop turn sends at most one `started` and exactly one `completed`; the installer
  registers exactly four task definitions, all `IgnoreNew`/SYSTEM/AtStartup/`PT0S`.
  Interval resolution: every task reports the cadence it sleeps on (each
  `IntervalSeconds` argument carries the same variable as the sleep, a literal
  fails), `Resolve-VsInterval` clamps to the per-task range and logs the
  correction at WARN, and the installer's `ValidateRange` plus its four parameter
  defaults are pinned against `$script:VsIntervalBounds` and `Get-VsConfig`.
- **Static (`PhaseCContractTest`, `MachineApiPanelContractTest`).** The
  maintenance-worker keeps retention and no longer carries a probe/socket path (this
  replaces the former pin on `maintenance_worker_tcp_check`); the machine-API panel
  has no outbound path, target or port, and the allowlist stays deny-by-default.
- **Playwright (`system-status-ampel.spec.js`, `system-status.spec.js`,
  `settings-flow.spec.js`).** Legacy yellow (and yellow again after V2→V1); V2 green;
  warning yellow; fail immediately red; a running attempt keeps the last result and
  shows "läuft seit"; stale/missing/unknown; site warning/critical; a provider fault
  grey and never labelled site-critical; sync and site health rendered separately;
  the dashboard tile carrying two labelled badges; no probe/retry button; the removed
  probe card/host/port/mode; and the old `save_probe` POST returning 400.

## Portal Confirmation Dialogs

Confirmations are the shared `<dialog>` from `lib/layout_modals.php`, rendered once per page by `layout_footer()` and driven only by `data-confirm` (ADR-0013). The contract is an attribute agreement between markup, that module and the portal scripts (`assets/core.js`, which holds the confirm dialog), so `php -l`, `node --check`, the lang audit and the rest of the suite all stay green while it is broken. `tests/Static/PortalConfirmContractTest.php` closes that gap and runs in the normal `unit` suite. It fails when:

- a postable form action is neither confirmed with `data-confirm` nor declared in `SAFE_ACTIONS` with its reason, or a `SAFE_ACTIONS` entry goes stale or contradicts the markup;
- a `.button-danger` submit ships without `data-confirm`;
- a prompt is a literal instead of a `__t()` key, directly or through one local variable;
- a page hand-rolls `window.confirm()`, `alert()`, a second confirm dialog or any `<dialog>` of its own;
- the accepted click is replayed with `form.submit()` instead of `form.requestSubmit(trigger)`;
- `layout_footer()` stops calling `layout_confirm_dialog()` or `layout_session_modal()`, which would ship a portal whose destructive buttons submit with no prompt at all.

The classification is closed on purpose. An earlier version guessed danger from the action name and so never looked at `generate_token`, which invalidates the token deployed on the MECM server, or `set_role`, by which an admin demotes themselves. Adding an action now fails the build until it is confirmed or declared safe.

That last one is the reason the test exists. `form.submit()` drops the form's submitter, which strips a `name="action"` button's value, so the handler falls through to its default branch: the page redirects, the flash appears, and nothing is deleted. A deliberately unconfirmed action (today only `clear_lock`, a reversible unlock) is listed in `UNCONFIRMED_BY_DESIGN` with its reason.

The static test cannot see behaviour, so after touching `assets/core.js`, `lib/layout_modals.php` or a `data-confirm` call site, still drive one confirmation of each shape in a browser:

1. A button whose action rides on a hidden input (`missions.php` delete) and one whose action rides on the button itself (`os.php`, `credentials.php`, the `vms.php` bulk actions). Accept, then confirm the record is really gone rather than trusting the flash.
2. Escape, backdrop click and the dismiss button each close the dialog without sending a request.
3. A form with a required field (`users.php` password reset) shows its validation bubble instead of opening the dialog.

The accept button takes its label from the trigger, so a new destructive button needs no JavaScript change; only a trigger whose own label would collide with the dialog's "Abbrechen" needs `data-confirm-action`.

## Modal Axis

Where modal content sits is decided in `feedback.css`, which no linter in this repo reads: `php -l`, `node --check` and the whole unit suite stay green while a dialog drifts off its axis or clips the name it is asking about. `tests/Static/ModalAxisContractTest.php` runs in the normal `unit` suite and parses the stylesheet (brace-depth aware, so `@media` does not swallow the rules nested inside it). It fails when:

- any rule other than `.modal[open]`, `.modal-box`, `.modal-msg` or `.modal-actions` declares `text-align`, `justify-content` or `align-items` on a modal, which is how a per-dialog override gets in;
- one of those four stops making its decision, or makes a different one;
- `.modal-msg` loses `width: fit-content`, `max-width: 100%` or its auto inline margins, any of which leaves `text-align: left` applying to every message so a short question hangs off the axis;
- `.modal-msg` loses `overflow-wrap: anywhere`.

Alignment is derived from the text length rather than restated per dialog. `fit-content` plus auto inline margins let a one-line message shrink to its own width and recentre, while a wrapping one hits `max-width` and keeps its left edge, where the reader needs it. No rule has to guess at the sentence count, and the same rule holds in German and English although the two wrap at different points.

`overflow-wrap` must be `anywhere` and not `break-word`. A confirm question names its target (`:name`) and a target name is user input; `anywhere` also lowers the element's min-content width, and min-content is what `fit-content` resolves against. With `break-word` the longest word stays the min-content width, the block outgrows the box, and only `max-width` catches it, clipping the name mid-token, which is exactly the misread the naming rule exists to prevent.

Each guard was run against a deliberately broken stylesheet, so each is known to *fail* and not merely to pass. Two traps worth knowing if you repeat that: the file is CRLF, so a `\n` in a mutation pattern silently matches nothing, and `overflow-wrap: anywhere` occurs three times in it, so an unanchored substitution breaks a foreign rule and leaves the modal test rightly green.

## Settings Tab Redirects and Deep Links

`settings.php` re-opens the posting form's tab after the POST redirect via a URL fragment (`settings.php#panel-<tab>`, restored by `initTabs` in `assets/core.js`). The `$actionTabs` map next to the action dispatch is the SSoT for which form lives in which tab. `tests/Static/SettingsTabRedirectContractTest.php` runs in the normal `unit` suite and fails when a postable action has no map entry (its redirect would fall back to the first tab, hiding sticky field errors and the one-time report token in a hidden panel), when a map entry names an action no form posts any more, or when a mapped tab has no rendered tabpanel.

Links *into* the page have the same failure mode and used to be hand-written at eight call sites, the redirect above among them: a missing or misspelled fragment opens the first tab, so the operator lands on a settings page that does not contain the field the message pointing there just named, without an error. `settings_url()` (`lib/settings_page.php`) is the only builder, validating the anchor against `VIRTUSPHERE_SETTINGS_TABS` and `VIRTUSPHERE_SETTINGS_SECTIONS` (the sections are ids *inside* a tab, like the dashboard backup banner's `panel-backup`; `initTabs` opens the owning tab and scrolls to them). `tests/Static/SettingsDeepLinkContractTest.php` rejects a hand-written `settings.php#` outside the builder and walks the constants against the rendered panel ids in both directions. This is the twin of the log deep link rule; see `LogDeepLinkContractTest`.

## Messages That Name a Fix

A portal message that states a prerequisite or an instruction carries the link that satisfies it, gated on the *target's* permission while the sentence stays ungated. On the deploy page `deploy_queue_blockers()` is the complete gate for one normalized form state. Server render, live JSON, schedule preview and the immediate pre-write recheck consume the same discriminated union, so a disabled button cannot exist without its matching visible reason. Structured actions carry their target permission; the reason remains visible when that action is hidden. `DeployQueueBlockersTest`, `DeployBlockerContractTest` and the Etappe-12 browser path prove both sides, including a forged enabled submit and the no-JavaScript path. Connection-test results whose fix lives on another page carry a flash action (`credentials_test_action()`, `tests/Unit/CredentialsTestActionTest.php`); results fixed on the Ansible host deliberately carry none.

## Correlation IDs (ADR-0032)

Every portal error page shows a reference like `error [a1b2c3d4e5f60718]`. That value is the request's correlation id, and the same id sits on the audit rows (`deploy_logs.correlation_id`), on the deploy job the request enqueued (`deploy_jobs.correlation_id`) and on every log line the worker writes for that job (`deploy_job_logs.correlation_id`). To trace an incident, take the id from the screenshot or from any of the three tables and grep the other two; the remote Ansible run sees it as `VS_CORRELATION_ID`, the MAC callback echoes it, and the MECM PowerShell scripts send their own per-run id as `X-VirtuSphere-Correlation`. A retry deliberately starts a new id; the new job's first system line names the old one (`[correlation …]`). The id is diagnostic only and never grants access.

## Drift Checks

These checks guard SSoT mirrors, doc hygiene and the file-size budget. They run quietly on every Claude session start and must be green before commits that touch the mirrored places:

```powershell
sh scripts/check-enum-sync.sh          # PHP-Const-SSoT vs. ENUM in struktur.sql und migrate.php
sh scripts/check-php-version-sync.sh   # Dockerfile-FROM (SSoT) vs. composer.json, constants.php, Docs
sh scripts/check-doc-hygiene.sh        # Changelog-Marker-Verbot + Zeilen-Budgets fuer AGENTS/GROK/CLAUDE/README
sh scripts/check-doc-semantics.sh      # Betriebsdoku behauptet keine veraltbaren Staende (Zahlen, Level, Pfade)
php scripts/check-bounds-sync.php      # keine Konstante als ausgeschriebene Zahl in Portal-Texten
php scripts/check-file-size.php        # ADR-0006-Budget fuer lib/ und portal/; --list zeigt Ist gegen Ausnahme
php scripts/check-audit-contract.php   # Etappe 10C: Auditproducer nur ueber die Registry, kein zweiter Schreibpfad
```

`check-doc-semantics` (AP9) polices the operating docs the way `check-bounds-sync` polices portal texts: `PRE-SHIP-CHECKLIST.md` must stay an empty template (no `[x]`, no dated evidence), no active doc may hardcode test/migration counts or load metrics, and PHPStan-level, MySQL- and Node-version mentions must match their SSoT (`phpstan.neon.dist`, `docker-compose.yml`, `ci.yml`). Retired backup paths may only appear next to a retirement marker. Historical documents (`docs/audits/`, `docs/CHANGELOG.md`, ADRs) are exempt: they describe a dated state on purpose.

Five more rules cover failure classes that were each found the hard way. A file path claimed in backticks needs a producer outside `docs/` (the go-live runbook sent the admin to an initial-password file nothing ever writes). Every `.env.example` key that compose interpolates without a default must be named in the go-live runbook, or nobody can set it without reading the compose file. A migration *range* spanning from the first migration to the current one is as stale as a count, even though both ends look like legitimate references, which is why this sentence cannot show you one. German documents write real umlauts, the same rule the portal catalog follows, with code spans and fenced blocks excluded because an ASCII identifier in backticks is a quoted value (`Uebersprungen` is a string a PowerShell script really compares against); `docs/INSTALLATION-ANLEITUNG.md` carries a named exemption until its own umlaut pass lands. And the hardware version in `createVMs-ESXi_playbook.yml` is checked against the ESXi support matrix: vmx-21 needs 8.0 Update 2, so a matrix that promises 7.0 for *creating* VMs promises a hard failure.

`check-file-size` turns the ADR-0006 target into a gate. A hook warning nobody has to answer is a budget nobody keeps: twenty-three files under `lib/` and `portal/` had grown past it, the largest bundling five independent transaction domains on 1220 lines. The scope is `lib/` and `portal/` (the machine-API root files are a frozen wire surface, the same reason PHPStan excludes them). `FILE_SIZE_ALLOWANCES` records every file that is over budget today with its exact size, the reason and the stage that splits it, which makes the list a ratchet in both directions: an unlisted file may not cross 400 lines (`file-size.oversize`), a listed file may not gain one line (`file-size.grown`), and one that came back under the budget must leave the list (`file-size.stale`). An empty scan is a finding, never a pass (`file-size.zero-match`). `php scripts/check-file-size.php --list` prints the current sizes against their allowances, which is how the table is maintained after a split.

`check-audit-contract` (Etappe 10C) proves that a persisted event has exactly one owner. Before it, an audit line was a free English sentence plus a category chosen at the call site, which is a convention rather than a contract, and it had already degraded in three places: the auth flood check matched on prose with a `LIKE` over a `TEXT` column, the System status counted a whole category as IP-allowlist refusals, and two producers of one event had drifted into two sentences. The guard reports `free-producer` (a call passing a category instead of a `VIRTUSPHERE_AUDIT_EVENT_*` constant), `unknown-event`, `registry-bypass` (any `INSERT INTO deploy_logs` outside `lib/repo/log.php`), `forbidden-field` (a context key that could carry a secret, a payload, an exception or a search term), `dead-sink` (`addLog()`/`audit_auth()` reintroduced), `token-sink` (a log or audit *signature* that can be handed a credential, which is what the dead `addLog($db, $token, …)` was), `sentinel` (a credential-shaped literal in a log call), `unredacted-sink` (an exception message sent directly to `error_log()` or worker STDERR without the central redactor), `unused-event` (a registered code no producer writes) and three `zero-match` cases. The last is the one that matters in a year: every other rule searches for something bad, and a search that silently matches nothing reads exactly like a clean repository. It strips comments before scanning, so the constants file may go on explaining why `addLog()` was removed. `tests/Static/AuditProducerContractTest.php` enforces the same rules in the unit lane by walking the parsed registry rather than the text, so neither is a copy of the other's assertions.

`AuditRegistryTest` walks every registered event through every object type and result it declares, builds a minimal valid context for each and renders it; a new event cannot skip it, which its hand-written predecessor shape could not promise. The refusals are the point: unknown code, unknown object, a result outside the event's own set, an object id outside a closed allowlist, a missing required field, a field required only by one result, an unknown field, a known field on an event that does not declare it, an explicit `null`, a coerced type, a map passed as a typed list, an over-long list, and every secret-shaped field name. Over-length object ids are refused rather than truncated, because a truncated id points at a different row than the event happened to. `deploy.vlan_reassigned` is the one event whose object id is a `name` (a VLAN has no id and its operator-typed name may contain a space); it is still bounded and still refuses control characters, and the test pins that it is the only one.

`LogRedactionTest` is the token sentinel. Each case carries a distinctive value through one syntax (auth headers of every scheme including Basic, `HTTP_*` superglobal spellings, cookies, query parameters in plain and URL-encoded form including encoded value bytes before the encoded `&` separator, JSON, form bodies, PHP array literals, a DSN inside an exception message) and asserts the value is gone, not that the output equals an expected string: an expected string lets a rewrite pass by matching its own new output. It also pins idempotence, which found a real defect (two overlapping passes produced `token=[redacted]]`), that the auth scheme survives because "the client sent Basic where we expect Bearer" is most of an auth investigation, and that the neighbouring fields are not swallowed, because a redactor that eats the rest of the line makes people stop logging rather than stop leaking.

`StructuredAuditSchemaTest` and `LogCsvExportTest` run against real MySQL. The first proves the additive columns, that a historical row with all five NULL is still writable and readable, that a half-structured row is refused, that `context_json` must be a JSON *object*, and the byte cap at 4095/4096/4097 against the actual `CHECK` plus the application's own refusal of the same size in bytes (a multibyte payload is under the limit in characters and over it in bytes). `check-bounds-sync` proves the literals in migration 0044 and `struktur.sql` both equal `VIRTUSPHERE_AUDIT_CONTEXT_MAX_BYTES`, since MySQL cannot read a PHP constant and interpolated DDL is forbidden. The second drives the production preparation path at 0, 1, 9 999, 10 000 and 10 001 matches: exactly the cap is **not** truncated, because every matching row is in the file, and saying otherwise sends an operator hunting for rows already in front of them. Each boundary proves exactly one structured, redacted export audit row with the same count and truncation verdict; the static producer contract independently proves that this is the only audit call, that rows are read before it, and that response streaming starts afterwards. The same integration class also proves the table and the export select the same rows in the same order and that a filter narrows both identically.

`InventoryErrorVocabularyContractTest` keeps the inventory error list closed across its real mirrors: the SSoT in `lib/inventory_error_constants.php`, the qualified message keys, every DE/EN `esxi_cause_fix_<code>` help entry, the first column of the error table in `docs/operations/esxi-inventory.md`, and the `last_error_category` width in both migration and fresh schema. Its validator is also driven with empty, missing, additional, invalid-token and over-width fixtures. The normal WebAPI-only container cannot see `docs/` or `Docker/mysql/`; run this test with the whole repository mounted, as the QA lane does, when changing the vocabulary.

`ConnectionErrorMappingContractTest` (Etappe 7) guards the one fact that exists three times: which categories `connection_error_category()` can actually return. It lives in that function's `$needles` keys plus its `parse` fallback, and is mirrored in the `@return` union of its own docblock and in the subjects of the `match` in `ansible_connection_error_category_for_text()`. The union is load-bearing, not decoration: it is the only thing that lets PHPStan prove that match exhaustive, so a needle group added without touching the docblock keeps the build green and turns into an `\UnhandledMatchError` raised inside the inventory worker's `catch (Throwable)`. An `\Error` is not caught by that same catch, so the job would lose its terminal state altogether rather than store a wrong category. The test holds all three together in both directions, requires every match target to satisfy `inventory_error_is_ansible()`, and guards each extraction against a zero match. Same shape as `DiskTypeLabelTest` holding the `disk_type_label()` union against `VIRTUSPHERE_DISK_TYPES`.

The Etappe-8 proof splits along the three things that stage changed. `DeployWorkerFailureClassificationTest` now walks the binding order of section 3 of the deploy-reliability masterplan: a `mysqli_sql_exception` stays `worker` in every phase (driven with wording whose every word is a needle of the generic classifier) while the same text in a plain `RuntimeException` stays a remote finding; a budget type outside the three legs that talk to the Ansible host keeps its phase's answer; and the upload leg says `ansible_sftp` where the generic fallback would say `ansible_transport`. `ConnectionErrorMappingContractTest` gained a second mirror to hold: `ansible_connection_error_is_typed()` must list exactly the transport types `ansible_connection_error_category()` decides by `instanceof`, because the SFTP probe asks that predicate which failures it owns and a fourth type added to one and not the other is silent in the direction that hurts. `DeployJobOutputLimitsTest` drives the output gate without a database through `RecordingDbOperations`: ANSI/OSC/C0 removal with a surviving tab, invalid UTF-8, a multi-byte cut boundary, one truncation notice per kind and job, silence after the total budget, and a synthetic secret sentinel in plain, URL-encoded and colour-interrupted form. `DeployCancellationStateMachineTest` covers both directions of the last-step race (a cancel that wins is confirmed with the sentence that the running step completed its work; a terminal swap that wins first refuses a later cancel) and that a foreign terminal state is reported without being overwritten. `AnsibleCommandModuleContractTest` keeps the split registry bidirectional and proves `ansible_sh_quote()` has exactly one implementation in all of `lib/`.

`SystemStatusDeepLinkContractTest` (Etappe 9) is the twin of `SettingsDeepLinkContractTest` for the other tabbed-by-fragment page. A fragment naming a section `system_status.php` does not render is not an error: the browser stays at the top, so a message that just named a thing leads to a page not showing it. Etappe 9 made one of those links load-bearing, because an `ansible_*` failure on the ESXi card now carries `Ansible-Status öffnen` into the Ansible host section, the card being unable to show that machine itself. The test walks every `VIRTUSPHERE_SYSTEM_STATUS_ANCHOR_*` constant against the ids the `lib/system_status*.php` renderers emit (globbed, never a listed file, and reading both a literal id and one echoed from the constant), rejects a hand-written `system_status.php#` outside the builder, and requires `system_status_url()` to still have callers plus to throw on an anchor no page could render. The two remaining anchors are deliberately outside the reverse direction: `reassign` is a details element inside the deviation section and `credential-<id>` is generated per row, so a value list would have to be invented for them.

`LogFilterTest`, `LogFilterQueryTest` and `LogCorrelationContractTest` (Etappe 15) cover the log filter and the correlation path. The first is pure: it drives the struct with a whole day range, both DST days, an impossible date (`2026-02-31`), a reversed range, an open bound, a partial and a mistyped correlation id, unknown structured values and an over-long object id, and pins the canonical key set and the URL round trip. Two of its assertions are the reason the stage exists at all: the local day span across the two transition days must be 23 and 25 hours, which is what `+1 day` gives and `+86400` does not, and a rejected value must stay visible in the struct AND in the URL while `log_filter_is_usable()` is false, so no repository query happens. The second runs against real MySQL, because "what the struct says" and "what the database then selects" are different claims: it seeds a row at local `23:59:59` of the last day and one at `00:00:00` of the next, proving the upper bound is exclusive and the last day whole; it seeds two ids where one is a prefix of the other, proving the correlation match is an identity and not a prefix; and it proves a historical row with every structured column NULL is neither matched by a structured filter nor broken by one. The third is static: one presenter for all three readers, the CSV header and value plus the projection that must select the column, the two separate permissions held apart by POSITION in the panel (the job id and status are rendered before the `deploy.run` gate is consulted, so a later edit that wraps the row loop fails here rather than hiding a trace's jobs from a user-administrator), the bounded and deterministically ordered job list, and the fact that the trace link carries no other filter. `tests/e2e/specs/log-correlation.spec.js` measures what none of them can: the copy button is reached with Tab and fired with Enter, the clipboard really holds the id, the confirmation is visible, an exact filter cuts to one request, and a partial id produces a field error with no rows, no export link and no job panel.

`PortalPageNavContractTest` and `SystemStatusOverviewContractTest` (Etappe 15) pin two portal shapes that look like styling. The first scans the portal sources with their PHP comments stripped - the rule is explained in prose in `lib/layout_presenters.php`, and a guard that matches the explanation of a rule as a violation of it teaches people to stop writing the explanation - and requires that only `help.php` and `settings.php`, the two real in-page tab widgets, carry `role="tab"`/`aria-selected`, that `aria-current="page"` is written only by `portal_page_nav()`, and that the pinned action column is opt-in on the VM list alone with `users.php` named as exempt by decision. The second holds the overview strip's grid column count against the card list the renderer builds, because a fifth card added in PHP alone starts a second row of one, and forbids a second derivation of the deviation count. `tests/e2e/specs/table-navigation.spec.js` and the two new cases in `system-status.spec.js` measure the geometry: the pinned cell keeps its screen position while the table scrolls under it, it paints an opaque background, it is released below the wrap breakpoint, and the five overview cards sit on one row at desktop width, reflow below the boundary and end one per row on a narrow viewport.

`LogFilterVocabularyLabelTest` (Etappe 15) walks `audit_event_registry()` in both directions against `lang/{de,en}/logs.php`: every registered event code and object type needs a name in both locales, and a name whose value left the registry is a stale entry that would translate nothing. Both walks fail closed on an empty registry, because a vacuous pass reads exactly like a clean one. It exists because the picker first shipped the identifiers themselves, and that was wrong on two counts nobody had separated: no other portal surface shows an event code, so the vocabulary could not be learned, and an option whose entire text is `directory.bind_rejected` is indistinguishable from an untranslated `__t()` key, which is exactly what `health-matrix.spec.js` reported on `logs.php` (six `directory.*` codes collided with a catalog name; the deploy tab would have added sixteen more the moment it was scanned). The fix is a name plus the identifier, so the browser scan stays honest instead of being taught an exception, and a fourth assertion pins the renderer to the shared helper so neither picker can go back to labelling a value with itself.

The rest of the Etappe-9 proof sits in two walks rather than in examples. `ConnectionErrorTest::testNoAnsibleSentenceInterpolatesTheEsxiHost` calls every category `inventory_error_is_ansible()` recognizes, in both locales, with the ESXi host in context, and forbids the value, the raw placeholder and a key echoing itself; the opposite direction requires an ESXi category to still name its host, so the check tests a real distinction rather than a context key nothing reads. `SystemStatusPanelBranchTest::testEveryCategoryDecidesSentenceOriginAndPermissionSeparately` renders the whole vocabulary twice, with and without `deploy.run`, and keeps the three decisions apart: the sentence is never gated, the repair link follows the origin, the job log follows the permission. Folding any two of those together is how the card came to name the ESXi host for a fault on the Ansible one.

Etappe 10 closes the operator-side semantics rather than adding another error source. `InventoryErrorVocabularyContractTest` still derives the table vocabulary from its first column, and now also validates the surrounding contract: the heading names the sole pause condition, the generic `unreachable` row cannot absorb a timeout again, the retained DB job log is the only technical original, RBAC and retention stay coupled to its link, historical origin is never backfilled, and no `last_error_detail` shadow copy is promised. Its mutation fixtures remove each boundary and include an empty-source zero-match case. `DeployRecoveryHelpContractTest` reads `deploy_identity_p2` from both real catalogs and mutates every required clause, so the retry guidance cannot lose the create boundary, confirmed MAC state, ESXi inventory check or stored-identity check in only one locale.

Etappe 10A proves the job-log reader at three layers. `DeployJobLogReadContractTest` mutates every required repository, endpoint, RBAC, raw-header and single-flight client boundary and includes an empty-source failure. `DeployJobLogCursorTest` drives MySQL with more than 1,000 initial rows, more than 500 forward rows plus the atomic terminal line, older cursors, empty/pruned states, invalid cursors, post-terminal write rejection and bounded raw batches. `deploy-log.spec.js` proves the rendered newest tail, stable older-page scroll anchor, complete NDJSON download, terminal backlog drain, 401/403 stop behavior, bounded network retry and one in-flight request. The existing output-limit/redaction and retention tests remain the producer and purge halves of the same contract; the raw endpoint only streams retained `deploy_job_logs` and creates no additional copy.

Etappe 10B separates terminal semantics from retained technical output. `DeployTerminalMetadataContractTest` holds migration 0043, fresh schema, the bounded fields, status/code CHECK, central presenter, additive poll response and closed cancel-origin token together. `DeployTerminalPresenterTest` exhausts the reason registry in both locales, bounds Unicode detail, proves the versioned generic result and richer MAC-result precedence, retains a real failed fallback, suppresses legacy cancellation prose by status and renders a deleted requester by historical id. `DeployCancellationStateMachineTest` drives queued, running, repeated and group cancellation plus worker/reaper/race confirmation against MySQL, requiring immutable first-request metadata, NULL `last_error` and at most one cancellation SYSTEM line. `AnsiblePreflightTest` pins the silent exact-key JSON probe; the Ansible QA image additionally runs it once with an installed and once with a missing module, so success cannot emit the former thousand-line manual and a false `ansible-doc` exit zero cannot pass.

Neither of those two walks would have found what a browser found immediately. Rendered, the repair link and the job log sat side by side with one space between them, both underlined, and read as a single long link; until this branch existed the card never showed more than one of them. `system-status.spec.js` therefore ends its ESXi-card test by re-recording the same durable failure as `ansible_auth` and asserting the geometry rather than the markup: both links visible, at least 10 px of horizontal gap between their bounding boxes, and a click that lands on `system_status.php#ansible` with that section actually visible. The gap number is the assertion because the markup was already correct when the defect was there.

The Etappe-6 transport proof is split by responsibility. `SshStreamHardeningTest` pins exact budget exceptions for SSH idle and total limits. `SshSftpBudgetTest` drives the SFTP guard with a phpseclib mock: operation versus remaining-total timeout, positive sub-second remainder, `remaining <= 0`, successful crossing immediately after an operation, allowed `is_dir() === false`, forbidden false, foreign exception and timeout exception with `previous`. `SshTransportExceptionRequireContractTest` checks the four direct requires and loads `deploy_worker_outcome.php` in an isolated process without `ssh.php`; `SshTransportModuleContractTest` keeps the registry bidirectional with `lib/ssh*.php`, proves a single owner per function, both transport modules below 400 lines, exactly-one cleanup per upload/probe and no logger/disconnect inside the operation guard. `DeployWorkerTransportTypeTest` and `DeployWorkerFailureClassificationTest` keep mission, inventory and cancel paths typed and prove that matching RuntimeException text is not a budget. The Fast lane supplies the full-repository mount and `--fail-on-skipped` closure proof.

`check-bounds-sync` guards a failure that is quiet by construction: the code keeps working and only the prose starts lying, so no test notices. A text that states a number followed by a unit must interpolate the constant that owns it (`:min`, `:days`, …) instead of writing the digits. It matches on value **and** unit, because the stale timeout is 600 seconds, which is also 10 minutes, and "10 Prozent" in the backup hint is not that; a check that cries wolf is a check that gets ignored. Numbers the project does not own (the NetBIOS 15, a VARCHAR width, the MECM sync cadence configured on the MECM server) are listed in `BOUNDS_EXEMPT` with the reason, and a stale exemption fails the check too.

## Backup and Restore Proof

```powershell
sh scripts/backup.sh        # DB- und Config-Backup nach Docker/backups/
sh scripts/restore_test.sh  # Restore-Probe in Wegwerf-Container
```

See `docs/operations/backup.md` for the runbook and `PRE-SHIP-CHECKLIST.md` for when these are mandatory.

## Schema Convergence Proof

`struktur.sql` (the fresh-install schema, mounted into `docker-entrypoint-initdb.d`) and `lib/migrate.php` (incremental delta migrations on top of that base) must converge to the same shape, and `struktur.sql` must load standalone on an empty volume:

```bash
sh scripts/check-schema-convergence.sh
```

The script builds one throwaway DB from `struktur.sql` alone and one from `struktur.sql` + all migrations, then diffs the `--no-data` dumps (AUTO_INCREMENT stripped). Before the migration run it reconstructs the schema directly preceding migrations 0019/0020 and seeds the default-interface edge cases: materializable VM, empty WDS VLAN, template, and an already stored interface. It asserts the JSON column, exact backfill, named skip report, untouched rows, and a forced second run of migration 0020. The script is mandatory after any schema change touching either side. The migrations are deltas on the `struktur.sql` base, not a from-empty rebuild, so "build DB from migrations on an empty DB" is not a valid check; this script is. On Git Bash it exports `MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'` itself, because Git Bash otherwise mangles the container-absolute paths.

## Concurrency and HTTPS Integration Tests

These live in `Docker/WebAPI/tests/Integration/` and need a running MySQL, so they skip in CI (no DB) and run against the dev stack:

- `DeployEnqueueRaceTest` pins the one-active-job-per-mission guard under two overlapping enqueues. It reproduces the interleaving deterministically with two connections (session A pins its snapshot, session B commits, A enqueues inside the old snapshot), so it cannot flake on timing. Both enqueue paths (single job and staggered group) are covered.
- `RepoTransactionReentrancyTest` proves `repo_transaction()` re-entrancy against the live server: nested calls commit exactly once (observed through a second connection, i.e. what is actually committed), an inner failure rolls back the outer work, and the depth tracker recovers after an exception.
- `DeployJobReaperTest` (Phase C) exercises the stale-job reaper on the real DB path.
- `HttpsConfigTest` pins the WP7 HTTPS admin flow, including that the redirect only fires while the generated listener config exists (the boot-quarantine lockout guard) and that the HTTP and generated HTTPS server blocks keep the same deny rules and fallback security headers. It reads `Docker/nginx/default.conf`, which is not mounted into the PHP container, so run it against a repo checkout:

```powershell
docker run --rm -v C:\projekte\VirtuSphere-v2-WebApp:/repo -w /repo/Docker/WebAPI virtusphere-v2-webapp-php ./vendor/bin/phpunit --filter HttpsConfig
```

## Browser E2E (dev-host only)

A Playwright layer under `tests/e2e/` (ADR-0028). Dev-host tooling: `node_modules` is git-ignored and nothing is mounted into the containers. The same suite runs in three contexts: on demand against the local dev stack (this section), as the `e2e-portal` gate of the Integration lane (Chromium), and as the `e2e-browser-matrix`/`e2e-msedge` gates of the Release lane (Firefox, WebKit, Windows Edge) against the throwaway QA stack (ADR-0028 revision); the PR-facing Fast lane stays browser-free. `npm test` stays pinned to Chromium for the dev loop; `npm run test:matrix` runs the other engines after a one-time `npx playwright install firefox webkit` (Edge resolves through the installed browser).

```bash
cd tests/e2e
PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install   # once
npm test                                          # all specs
npx playwright test accessibility                 # one spec
npm run report                                    # last HTML report
```

### Deploy service and the job log view (Etappe 13/13R)

`deploy_service_health_snapshot()` (`lib/deploy_service_health.php`) is the one
source the dashboard, the deploy page, the System status card and the anonymous
`health.php` read. Its three axes are independent: `availability` (ready, busy,
degraded, cooldown, offline), `claim_state` (accepting, pause_after_current,
paused) and `recovery_attention` (none, recovering, manual_review). The
derivations are pure functions over a fact struct and are exercised without a
database by `DeployServiceHealthTest`, including the corners an operator only
meets on a bad day.

The claim axis is persisted by migration 0045 on the singleton runtime row.
Every transition is a compare-and-swap and the gate sits inside
`repo_claim_next_deploy_job()`. To exercise a pause locally:

```powershell
docker exec virtusphere-qa-mysql-1 mysql -uroot deploymentcenter -e "UPDATE deploy_runtime_identity SET claim_state='paused' WHERE id=1"
```

A paused service is not degraded and `health.php` keeps answering `200` with
`status=ok`; only `degraded` and `offline` make it report `status=degraded`. A
snapshot that cannot be computed is `degraded`, never `503`.

The job log view is proven in the browser by `deploy-log.spec.js`,
`deploy-recovery.spec.js` and `deploy-create-progress.spec.js`. The last one
covers the per-VM create card and the paging that has to work with scripting
off. Both of its central assertions are only decidable in a browser. That the
card counts from `deploy_create_vm_results` and not from the log above it shows
only when the two say different things, which is what a windowed log of more
than 1,500 lines produces; and whether the "load older" control works without
JavaScript is a property of the rendered anchor plus the HTML render path, not
of any function. It was a `<button type="button">` for three stages while the
endpoint accepted `before_seq` all along, and when it became an anchor the
renderer still answered every request with the newest tail, so the link looked
live and reloaded the same page. A context with `javaScriptEnabled: false` is
what caught the second half; clicking it with scripts running proves the
handler, which was never the part that was missing. Follow mode is measured there as geometry rather than
asserted as source text, because the two are not the same question: the file can
carry every follow line, pass its static contract and still open a live log at
the top of the newest window, where `atBottom()` is false, every batch counts as
unseen and the switch labelled live moves nothing. That was the actual state
until the spec existed. It now checks that a running job opens at the newest
line, that twenty lines arriving under a scrolled-up reader move them zero
pixels while the counter appears, that only a deliberate return clears it, that
turning following off keeps the reader where they are across a reload, that the
region is `role="log"` with `aria-live="off"` beside two `role="status"`
summaries, and that a hidden tab issues no request and returns with exactly one.
The recovery block renders only for a job with a
durable remote execution, which the remote path being disabled means no job has
on its own; the spec seeds the row directly and proves the three things that do
not depend on the remote path: the stored state is rendered, a cleanup retry is
refused when the evidence hash moved between rendering and clicking, and a
documented external check is appended rather than replacing an earlier entry.

### Deterministic visual proof

The `visual` project is not a dev-stack screenshot command. It runs only as part of the canonical Integration `e2e-portal` gate after `qa-stack`, because its seed and worker pause guards require the exact `virtusphere-qa` identity:

```powershell
powershell -NoProfile -File scripts\check.ps1 -Lane Integration -Gate qa-stack,e2e-portal -Json qa-artifacts/qa-visual.json
```

The committed `visual/runner-contract.json` pins Windows/x64, OS release, Playwright and Chromium revisions/versions, Segoe UI font hashes, `de-DE`, `Europe/Berlin`, the desktop/wrap/mobile viewports, the captured pages, `deviceScaleFactor=1`, CSS screenshot scale, clock/random seed, both themes, reduced motion, disabled animation, hidden caret and the mask list. Validation occurs before screenshots. Any mismatch is `infrastructure_error`; `UPDATE_SNAPSHOTS`, `VIRTUSPHERE_UPDATE_VISUAL_BASELINES` and `VIRTUSPHERE_VISUAL_BASELINE_UPDATE` are refused, and the gate clears all three before calling the harness.

The runner proves exact QA Compose labels and zero queued/running/cancelling jobs before pausing workers. It restores only those that were originally running in `finally`; shared/dev/production labels fail before `stop`. The seed is namespaced `visuale11fixture`, cleans only its own rows and is guarded by the exact QA URL, containers and DB. The harness decodes PNG pixels rather than comparing encoded bytes and writes metadata, comparison and diff evidence below ignored `qa-artifacts/visual-baselines-*`.

#### Reviewed target baselines (Etappe 17)

Comparing two runs of the same build proves the harness is deterministic and nothing else: a build whose every page had turned magenta would have passed it twice. Since Etappe 17 the pass criterion is the committed, reviewed PNG under `tests/e2e/visual/baselines/<theme>/<page>-<viewport>.png` — six images per theme, from `missions` and `deploy` across the desktop (1440), wrap (860, the shell's own `max-width` breakpoint, where the sidebar becomes a flat area) and mobile (390) viewports. The expected set is derived from the contract, so a capture that silently stopped being taken is a failure and not "nothing to report". `baselines/manifest.json` binds the set to the runner that produced it and carries a SHA-256 per file, a reason and a timestamp; a hand-swapped PNG or a set taken on another Chromium is caught before the first pixel comparison. A runner mismatch is `infrastructure_error`, a missing or altered image is a failure that a person has to answer.

Updating is a separate command, never a gate:

```powershell
powershell -NoProfile -File scripts\check.ps1 -Lane Integration -Gate qa-stack -KeepArtifacts
powershell -NoProfile -File scripts\update-visual-baselines.ps1 -Reason "<why this image changed>"
```

It shares the QA stack identity (`scripts/lib/check/qa-identity.ps1`) and the worker pause with the lanes, so a target image is taken under exactly the conditions that later reproduce it. It refuses without the reason and without `VIRTUSPHERE_VISUAL_BASELINE_UPDATE=1`, refuses on a runner or font mismatch before overwriting anything, and writes the previous image plus a diff image (changed pixels red, everything else dimmed) and a bounding box per change into `qa-artifacts/visual-baseline-review-*`. Those are audit artifacts of what was replaced; they never become a second baseline. Review every diff image, then commit the changed PNGs deliberately.

Two decisions keep the tolerance at zero while the harness stays usable:

- **Mask.** The native file-input in the mission import form is the one control the portal does not style. Its antialiased edges changed between otherwise identical captures across Etappen 14D, 15 and 16 (29 pixels on `missions-desktop`, maximum channel deviation 1 to 2, both themes, same place). The portal cannot influence how the browser rasterises its own widget and therefore cannot regress it, so a target image over it asserts nothing about our code. It is masked with its reason in the contract, the mask is painted in the image so a reviewer sees the excluded area, and a mask that stops matching fails the capture: a dead exemption is how a masked area silently grows over something we do own.
- **Bounded retry.** A design regression is deterministic and is in every capture, so no number of attempts produces one that equals the target. Rasterisation noise is not. A file therefore passes when at least one attempt is byte-identical to the reviewed image, within `baselines.maxAttempts`; two attempts always run, further ones only for files still unmatched. A retry that was needed is named in the run output and in `comparison.json` — a harness that quietly retries is a harness nobody can judge.

Both captured pages render rows the fixture does not own: `missions.php` lists every mission, `deploy.php` lists every job of the selected one. That data precondition is stated rather than assumed. Before the first capture the harness checks that the QA database holds no mission besides its own fixture and no deploy job at all, and refuses as `infrastructure_error` naming the offending rows. It reports and never deletes: a fixture that outlived its owner is that owner's defect, and removing it here would make the picture pretty while hiding the defect. Etappe 11 could not see this class at all, because it compared two runs of one session and any residue was in both.

That check found one on its first run, and the leftover had a cause worth knowing. `field-roundtrip.spec.js` cleaned up with `DELETE ... WHERE mission_name LIKE 'e2ert-%'`, which silently left its emoji probe behind on every run: `mission_name` is `utf8mb4_unicode_ci` with a unique index, and MySQL plans that DELETE as an index **range** while planning the equivalent SELECT as a full index scan. A supplementary character (U+10000 and above) carries an implicit collation weight the range endpoints do not represent, so the row falls outside the computed range. `SELECT ... LIKE 'e2ert-%'` finds it, the DELETE reports zero affected rows and throws nothing; BMP characters such as an umlaut or a combining accent are unaffected. The cleanup now compares with `LEFT(mission_name, CHAR_LENGTH(?))`, which defeats the index so the predicate is evaluated per row.

**Reading a red visual gate.** The two failure shapes look different and the artifact tells them apart before you open a single PNG. Read `comparison.json` in the run's `qa-artifacts/visual-baselines-*` directory: it names, per image, which attempt matched and the pixel count plus bounding box of every attempt that did not.

- **A design change** paints hundreds to thousands of pixels across a wide box, in every attempt, and usually across several images at once. The deliberate token mutation that proves the gate works looks exactly like this: one channel of `--accent` moved by one produced 657 to 1270 differing pixels on each of the six light images while dark stayed green. If the change was intended, review the diff images and run the update command with the reason. If it was not, you have found a regression.
- **Rasterisation noise** is a handful of pixels in a thin band, deviating by one or two in a single channel, on the antialiased edge of one rounded control. It does not reproduce: the first canonical lane run after this stage saw 16 such pixels on `dark/deploy-mobile` in attempt 1 and a byte-identical image in attempt 2, reported as `rasterisationNoise` and not a failure. The same band (rows 248 to 255 of that page) is where the finding first appeared on 2026-09-03, so the shape is known.

One case sits between them and is worth naming, because a retry cannot resolve it: a corner-only difference that is stable within a session but differs from the baseline in every attempt. That happened once here, on `light/deploy-desktop`, 14 pixels on the rounded corners of the session button. It was decided by measurement rather than argument: five later capture sessions, one of them against a QA stack rebuilt from scratch, were byte-identical to each other and all differed from the original image by the same 14 pixels, which made the baseline the outlier. It was re-captured with exactly that reason recorded in the manifest. If you meet this shape, take the same route: capture again, compare the runs against each other, and let the majority decide which image is the anomaly.

`node --test "tests/*.test.js"` in `tests/e2e` is the static half and runs as the Integration/Release gate `visual-contract`: browser resolver, metadata mismatch, the update refusal on all three variables, the mask declaration, the derived image set, the wrap viewport against `layout.css`, the manifest and the diff image. The `visual-contract` gate runs `tests/*.test.js` as a pattern, not the directory: node 24 resolves a bare `tests/` as a module path and exits with `MODULE_NOT_FOUND` instead of running the file inside it.

This harness touches no portal-visible string or product PHP/JS/CSS. Portal help, audit/event persistence, deploy-job/job-log semantics, shipped container configuration and machine endpoints/wire fields are therefore outside its write set. Their existing contract suites remain part of the unchanged Fast/Integration lanes and are explicitly re-run for acceptance.

The `setup` project seeds an `e2e_user` account through the PHP container and caches a `storageState` per role; specs reuse those sessions. Fixtures are prefixed (`e2e*`) and cleaned in setup/teardown, the same discipline the PHPUnit integration suite follows. What it covers:

- `health-matrix.spec.js` — every page x {light, dark} x {admin, user}: right access outcome, no PHP error in the HTML, no console error or CSP violation, **no request off `127.0.0.1`** (the air-gap proof), no untranslated key.
- `field-roundtrip.spec.js` — a hostile-value matrix (XSS payload, attribute breakout, HTML/SQL metachars, umlauts, 4-byte emoji) through the real form: byte-exact in MySQL with no entity in storage, escaped in every render context, and a dialog handler fails the test if a script ever executes.
- `crud-mission.spec.js` / `crud-negative.spec.js` — the entity lifecycle verified through state, plus the reference guard (deleting a credential an active job holds must refuse in the operator's language, without a 500 and without a partial cascade).
- `accessibility.spec.js` — axe-core, WCAG 2.1/2.2 A+AA, every page in both themes.
- `mission-transfer.spec.js` — export, upload, preview, confirm, plus the cases only a browser can settle: a template export re-imported under a corrected name (prefix error at the field, confirm still enabled), an oversize file and one above `upload_max_filesize` mapped to the localized size sentence instead of "no file selected", a scalar `interfaces`/`disks` producing a positioned finding with canonical counts and a disabled confirm, two uploads in one session where the older link must not destroy the newer preview, Cancel plus browser Back showing the same preview inside the TTL, and exactly one `missions` audit row for a successful import against none for an expected failure. The size boundaries are read from the PHP constants through the test helper, never spelled out in the spec.
- `system-status-ampel.spec.js` — the statements the status page makes about its own traffic lights: the legend explains every state the page can render and lists **the same** heartbeat states as the help panel (compared across the two rendered pages, which is the only place that drift is visible), an action hint appears on a broken row and not on a healthy one, an unconnected MECM names the setup step once instead of repeating repair hints, and Dashboard and System status render the two separated MECM badges (Integration, MECM-Site) from the same health snapshot, a provider fault shown grey and never labelled site-critical. Drives result and site-health states through seeded rows and hands the table back intact.

Two traps worth knowing before writing a spec: the layout header carries a logout form whose submit is the **first** on every page, so a `.first()` submit selector logs you out instead of saving; and the real deploy worker polls the same database, so a fixture job seeded as `queued` gets claimed and finished mid-test (seed it `running` with a fresh heartbeat when a test depends on it staying active).

## PowerShell Integration Clients (dev host + CI)

The `Powershell-MECM/` tree runs as SYSTEM in endless loops on the customer's MECM server and on every freshly PXE-installed client. Until 2026-07 nothing checked it: no linter, no test, no CI, no `Set-StrictMode`. It now has all four (ADR-0029).

```powershell
powershell -NoProfile -File scripts\run-pester.ps1              # analyzer + tests
powershell -NoProfile -File scripts\run-pester.ps1 -SkipTests   # analyzer only
```

The modules are dev-host tooling and are **not vendored** (same rule as Playwright and Infection). Install once:

```powershell
Install-Module Pester -MinimumVersion 5.5.0 -Scope CurrentUser -Force -SkipPublisherCheck
Install-Module PSScriptAnalyzer -Scope CurrentUser -Force
```

Windows ships Pester **3.4** in the box, whose syntax is incompatible; the script refuses to run against it and says so. If `Install-Module` fails with a NuGet provider error, force TLS 1.2 first (`[Net.ServicePointManager]::SecurityProtocol = 'Tls12'`) — Windows PowerShell 5.1 still negotiates TLS 1.0 by default and the gallery no longer accepts it.

This one **does run in CI**, twice (AP5): under `pwsh` on `ubuntu-latest` in the Fast lane, and under real `powershell.exe` 5.1 in the `windows-powershell-51` job — the engine the scripts run on in production. Only the Windows job executes the registry-backed error-path tests (`VirtuSphere.ErrorPaths.Tests.ps1`: lost/broken registry values, address chain, scheme override) and enforces the coverage ratchet over the Common, Server/Client Logging and Packaging files (`pesterCoverageFloorPercent` in `scripts/tool-lock.json`; the floor only ever rises). PSScriptAnalyzer additionally runs the compatibility rules (syntax 5.1+7.0, commands/types against the Server-2019/5.1 profile), so a cmdlet or .NET type that 5.1 does not know fails the build instead of failing at night as SYSTEM. Still manual by design (Release lane / staging, rollout step 10): SYSTEM smoke in a throwaway Windows VM, the installer lifecycle on a real host, and an MECM staging acceptance.

### The PowerShell log schema is a mirrored package contract

`VirtuSphere.Logging.Tests.ps1` loads the server and client logging modules in
isolated scopes and compares their version, levels, six-field schema, daily
filename, byte bounds, retention interval and redaction behavior. It exercises
UTF-8 boundaries, the exact 29/30/31-day edge, the shared 24-hour marker,
parallel cleanup locking, recovery from a corrupt cleanup marker and the
once-per-outage plus once-per-recovery warning against a deliberately broken
sink. The redaction matrix includes quoted values, cookies, private keys and
the complete mirrored secret vocabulary. Packaging tests cover fresh copy and
upgrade replacement of phase, Common facade and logging module, including
SHA-256 equality, AST-only version checks that preserve the current logging
state, a complete rollback after simulated activation failure and hard failure
for missing or version-mismatched modules. Static installer checks additionally
pin trigger disable before stop and live move.

The same suite proves that client phase/ready-ACK and server heartbeat JSON stay
unchanged while the process correlation ID is only an additive header. Routine
heartbeat and `reportRun` traffic therefore does not grow just because the
local log sink is unavailable; the WebAPI audit-free side is pinned separately
by the ADR-0018 audit contract. The real-host staging acceptance still checks
the SYSTEM ACL, a live log rotation boundary and an installer re-run while the
four scheduled tasks are active.

### The MAC canonicalization is a cross-language contract

`Docker/WebAPI/tests/fixtures/mac-vectors.json` is the shared source of truth for three implementations that cannot share a file, because they are deployed to three different machines: `virtusphere_normalize_mac()` (PHP), and the two `ConvertTo-VsNormalizedMac` twins (MECM server, deploy client). PHPUnit's `MacNormalizeTest` and Pester's `VirtuSphere.Common.Tests.ps1` both read that table, and a further Pester test asserts the two PowerShell twins stay textually identical.

Change the canonicalization in one place and a build fails. That is the point: this seam already produced a P1 once (finding 2.2 in `docs/audits/2026-07-hardening.md` — a MAC stored in the wrong notation makes a VM invisible to MECM, with no error anywhere).

`PSAvoidUsingEmptyCatchBlock` stays enabled on purpose; a silent failure is exactly this code's failure mode. Empty catches carry a `Write-Debug` line, so a `-Debug` run shows what was swallowed. Three rules are excluded, each with its reason in `PSScriptAnalyzerSettings.psd1`.

## Session Hardening

`Docker/php/conf.d/zz-virtusphere.ini` carries the session settings, and `tests/Static/SessionHardeningContractTest.php` pins them. The one number the application owns — `session.gc_maxlifetime` — must stay at or above `VIRTUSPHERE_SESSION_LIFETIME_MINUTES_MAX * 60`, because an `.ini` cannot interpolate a PHP constant and a mirror drifts.

It drifted on the default: `gc_maxlifetime` was 1440 s (24 min) while the settings card promised sessions up to 480 min. PHP's garbage collector was free to delete the session file of an operator the portal still considered signed in, and because the GC fires probabilistically on *other people's* requests, the logout happened at a random moment nobody could reproduce.

The file is **COPYed at image build time**, not bind-mounted:

```powershell
docker compose build php ; docker compose up -d php
docker exec virtusphere-v2-webapp-php-1 php -r "echo ini_get('session.gc_maxlifetime');"
```

A `docker restart` alone changes nothing, which makes a wrong `.ini` easy to believe you have fixed.

## Mutation Testing (dev-host only)

Infection answers what coverage cannot: whether a test actually pins the behaviour it names, or would still pass if the code were subtly wrong. It is dev-host tooling, on the same footing as the Playwright layer (ADR-0028): **not vendored** into `vendor/`, **not in CI**, and it needs a coverage driver (pcov or xdebug) that the air-gapped runtime image deliberately does not carry. Config lives in `Docker/WebAPI/infection.json5.dist`.

Run it on a networked dev host with coverage enabled:

```bash
# once, outside the tracked vendor/ (its tree is large and dev-only):
curl -sSLO https://github.com/infection/infection/releases/download/0.29.14/infection.phar   # pin the version
# then, from Docker/WebAPI, against the fast suites and a targeted file:
php -d pcov.enabled=1 infection.phar --configuration=infection.json5.dist --filter=validate.php
```

Target the files where the payoff is highest with `--filter` rather than mutating all of `lib`: the pure-logic ones with dense unit tests, where a surviving mutant means a test asserts too little. Highest value first: `validate.php` (the validation matrix), `ansible_yaml.php` (the deploy-YAML escaper), `permissions.php`, `password_policy.php`, `catalog.php` (status normalization). A surviving mutant is a to-do for the test, not for the code: strengthen the assertion that let the mutant live. Raise the `minMsi`/`minCoveredMsi` floors as tests are hardened; never lower them to make a run pass.

The baseline run is deferred to a dev host on purpose: this stack is air-gapped and carries no coverage driver, which is exactly what Infection needs, so it cannot run here.

## Load Probe (dev-host only)

`tests/load/` holds the k6 profiles (campaign reference: TESTPLAN 4.7), dev-host tooling on the same footing as the E2E and mutation layers: not vendored, not in CI. `portal-read.js` has each VU sign in as its own operator and poll the pages an operator sits on; `portal-write.js` drives the write path and refuses to run without an explicit throwaway-stack opt-in, because a load test must never mutate real rows. k6 runs from a container sharing the web server's network namespace. Commands, thresholds and the recorded baseline live **only** in `tests/load/README.md`; this page deliberately repeats none of those numbers. Run the probes if the volume behaviour of the list pages is ever in question, not on every change.

## Hook Scan

The Claude hook delegates to the project script:

```powershell
docker run --rm -v C:\projekte\VirtuSphere-v2-WebApp:/repo -w /repo virtusphere-v2-webapp-php sh scripts/lint-csp-patterns.sh --all-changed
```

`BLOCK:` findings are release blockers. `WARN:` findings are cleanup signals and can remain when they document legacy or staged refactor work. Vendor paths are excluded from the hook's project-code checks.

## Git Hygiene

Run whitespace checks for first-party files before commit:

```powershell
git diff --check -- ':!Docker/WebAPI/vendor/**'
```

Vendored Composer packages can contain upstream trailing whitespace or blank EOF lines. Do not rewrite vendor files just to satisfy whitespace checks; keep them as Composer installed them.

## Vendored Dev Dependencies

This repository already tracks `Docker/WebAPI/vendor` and the application must stay usable in air-gapped environments. When PHPUnit is updated through Composer, commit the matching `composer.json`, `composer.lock`, `vendor/composer/*` metadata and new `vendor/*` package directories together.

Do not commit runtime logs, `.env`, MySQL data, `Docker/WebAPI/var/`, `Docker/WebAPI/.phpunit.result.cache` or other generated local state.
