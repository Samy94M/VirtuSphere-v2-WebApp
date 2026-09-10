# U13 code review: SC-008 and SC-025

Status: **implemented, reviewed against the code, runtime acceptance pending**. This review covers the live-blocker session lifecycle and the `getVMs()` relation owner. SC-021 and changes under `tests/load/` are owned by the separate U13 load-profile work. No lint, PHPStan, PHPUnit, Playwright, guard, build, QA, benchmark, browser measurement or container/system mutation was run while preparing this change.

## Preserved originals and file scope

The two existing product files were copied before their first edit:

| Product file to replace for an isolated Before run | Preserved original | SHA-256 |
|---|---|---|
| `Docker/WebAPI/portal/deploy_blockers.php` | `qa-artifacts/system-chain-audit/20260910-u13-u14/before/Docker/WebAPI/portal/deploy_blockers.php` | `6CCC9DFF8B718E0574D4968D5545D7B2118C607F39EC8C0B671D75FEF9FFD441` |
| `Docker/WebAPI/lib/repo/vms_legacy.php` | `qa-artifacts/system-chain-audit/20260910-u13-u14/before/Docker/WebAPI/lib/repo/vms_legacy.php` | `F1340C8204B9B469AFF154BD2E0CC0CF0C593C890AD501B7BAA2569650FBD64E` |

These local originals are under the ignored `qa-artifacts` tree. Preserve them
with the later measurement handoff; transferring only tracked source files
does not transfer the Before baseline.

New files have no original counterpart. Record them as additions in the change manifest, but keep them byte-identical in both isolated measurement copies; these tests and this report are not loaded by the product:

- `Docker/WebAPI/tests/Integration/VmRelationBatchingTest.php`
- `Docker/WebAPI/tests/Static/VmRelationBatchingContractTest.php`
- `Docker/WebAPI/tests/Static/DeployBlockerSessionReleaseContractTest.php`
- `tests/e2e/specs/deploy-blocker-session.spec.js`
- `docs/audits/2026-09-10-u13-code-review.md`

No other product, test, language, central register or central QA document belongs to this file owner's change.

## SC-008 session lifecycle

`portal/deploy_blockers.php` now resolves the user and completes the endpoint's `must_change_password` and `deploy.run` decisions before calling `session_write_close()`. The close therefore occurs after bootstrap has persisted a valid `?lang=` choice and after `current_user()` has handled all session-sensitive paths:

- lazy absolute-expiry initialization;
- expired-session audit followed by `logout()`/session destruction;
- user-active lookup;
- Active Directory disable/revalidation, successful verification timestamp, temporary retry timestamp, grace behavior and terminal logout;
- explicit-user RBAC evaluation.

After the close, the endpoint reads only request-local input, its local user snapshot and current database state. `deploy_blocker_json()` receives that user explicitly, and its action-permission calls use `can($permission, $user)`, so they do not reopen or read the session. Localized JSON reads the already loaded `Lang` catalog. The endpoint has no flash or CSRF write: it is a GET-only read, while the 405 branch remains before auth and exits immediately. The authenticated denial branch uses the already derived decision and returns 403 after releasing the lock. An anonymous request returns 401 after releasing any active anonymous/locale session; an expired authenticated session has already been destroyed by `current_user()`.

The new static PHP regression pins `current_user -> must-change -> explicit-user permission -> session close -> blocker read` and rejects later `$_SESSION`, `current_user()` or implicit `can()` access. Existing prepared runtime coverage in `tests/e2e/specs/etappe12-ux.spec.js` owns the JSON 405/401/403 and forced-password-change denial contract; `tests/e2e/specs/session-security.spec.js` owns server-side expiry and terminal logout. The new E2E regression creates a fresh login session, proves both request clients carry exactly the same PHP session ID without printing it, and holds the VM owner query in flight with a bounded audit-only table lock. A parallel CSRF-protected `session_ping.php` must answer successfully while the blocker is still waiting. This demonstrates that the follow-up writer can acquire the released session and accepts its CSRF token; it does not independently compare the persisted expiry value before and after the ping. The fresh browser explicitly uses `de-DE` and first confirms `lang="de"`. The blocker request then selects English; a later page without a language query must render `lang="en"`, making the locale persistence assertion independent of an English browser default. The helper bounds DB lock acquisition to five seconds and its pipe wait with `stream_select()` to 15 seconds. Readiness failure also closes stdin and awaits the helper's exit. Exit observation is bounded to ten seconds; if it fails, the error requires resolving the helper before stack reuse. The test's `finally` continues through request-context disposal, browser close and fixture cleanup even if release reports an error.

Manual follow-path review found no session access below the close. Runtime acceptance remains necessary for the same-session concurrency case, the 401/403 cases, the expired-session case, the CSRF peer write and the locale persistence assertion.

## SC-025 relation batching

`lib/repo/vms_legacy.php`, the existing compatibility owner, still selects the exact mission VM rows with `WHERE mission_id = ? ORDER BY vm_name`. It derives the exact VM IDs from that result and initializes all three relation arrays to empty for every VM. Packages, interfaces and disks are then loaded in three separate prepared queries per bounded ID chunk and attached only by their raw numeric `vm_id`.

The bound is 500 IDs per prepared `IN` list. Query count is therefore `1 + 3 * ceil(N / 500)` for a non-empty mission and one query for an empty mission. The audited profile sizes imply 4 queries for 10 and 40 VMs and 7 for 1,000 VMs, in place of the original `1 + 3N`. This is request-local work; there is no static, global or cross-request cache, and a repeated blocker/warning calculation still receives a fresh repository read.

The former result contract is retained:

- VM order remains `vm_name`; package order remains `package_name`; interface and disk order remain their row `id` within each VM.
- Package rows drop the temporary grouping alias before attachment, leaving the prior `dp.*` key shape and value types.
- Interface and disk rows remain their former `SELECT *` shapes.
- Empty relations remain `[]`; relation multiplicity is not deduplicated or folded.
- SQL null and empty-string values pass through untouched.
- Mission filtering happens before relation loading, and relation queries use only the exact returned VM IDs, so a foreign mission cannot leak rows.
- Progress display fields are derived after the complete relation aggregate exactly as before.

The integration regression constructs the previous per-VM aggregate as the equality oracle and compares it with `assertSame()`, covering key/value types, order, null/empty values, empty arrays, multiple relations and a foreign mission. Query deltas use `SHOW SESSION STATUS LIKE 'Com_select'`; the `SHOW` probes themselves are not SELECTs. Two VMs must cost four SELECTs, and an empty mission one. A 501-VM boundary fixture places a relation on VM 501, expects seven SELECTs, then inserts a disk and performs a second seven-SELECT read that must see the new row. The source contract separately pins the parameter bound, chunking, all three batched predicates/orderings and absence of a static row cache.

## Deferred isolated acceptance

Do not reset or check out the shared dirty tree. Build two disposable copies from the same recorded Git commit, then overlay the same complete current dirty/untracked source snapshot onto both. In the Before copy only, replace the two product files listed in the table with their preserved originals. Keep all remaining source, tests, measurement harness and documentation byte-identical between copies. Record manifests proving that only the two intended product files differ.

Run the canonical QA runner later under one exclusive owner of the exact synthetic `virtusphere-qa` stack with live, pollable `[n/total]` logs. First run the targeted static/integration/session regressions, then the appropriate full canonical lanes. Performance work runs separately from all tests: seed identical S/T/L data and capture seed hashes/counts, verify the intended authenticated VM and blocker URLs and content, then compare Before/After with the same repetitions and resource limits. Keep cold and warm runs separate. For session A/B, prove one exact ID for the same-session pair and two distinct IDs for the independent pair without exporting their values. Historical AP09 figures are context only and are not a Before measurement for this changed build.

Open acceptance items are the prepared but unexecuted PHP and browser regressions, the comparable S/T/L Before/After measurements, the corrected SC-021 VM-page load profile, and a fresh same/independent-session A/B measurement. No latency or capacity improvement is claimed until those runs complete.
