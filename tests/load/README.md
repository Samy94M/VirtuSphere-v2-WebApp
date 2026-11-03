# Portal load probes (dev-host only)

k6 profiles for the portal (TESTPLAN 4.7). They are local, on-demand tools and
do not register a second QA runner. Use them only after the canonical quality
gates, against an exclusive synthetic stack, as described in
[`docs/audits/2026-09-10-u13-measurement-plan.md`](../../docs/audits/2026-09-10-u13-measurement-plan.md).

## Profiles

- `portal-read.js` is the read-heavy S/T/L profile. Every VU signs in with its
  own session and reads dashboard, mission list, one explicit mission's VM list
  and health. The VM timing enters `target_vms_duration` only after exact final
  URL, authenticated HTML, mission title, expected row count, expected selectable
  row count and a known VM marker all match.
- `portal-read-cold.js` records exactly one first VM-target request after an
  externally verified reset state. Authentication warms shared code first, so
  this is a target-route observation, not a claim that the host is cold.
- `session-pair.js` is the SC-008 A/B probe. `MODE=same` gives blocker and peer
  the same freshly authenticated cookie jar; `MODE=independent` gives them two
  separately authenticated jars. Its evidence line records only the proven
  `same`/`different` relation and boolean validity. Raw session IDs, CSRF tokens
  and passwords are never written to the profile's evidence.
- `portal-write.js` is the existing write profile for throwaway stacks. Every
  arrival creates, edits and deletes its own mission. `BASE` has no default and
  `VS_ALLOW_WRITES=1` is required.

The existing read/write profiles retain their pass semantics: `checks` above
99 percent, HTTP failures below 1 percent, p95 below 800 ms for read lists,
300 ms for health, 1 s for writes, and zero dropped iterations where an
arrival-rate scenario defines that contract. Session A/B requires valid checks,
HTTP responses and at least one accepted pair, with no new latency threshold.
The single cold probe requires every check to pass, zero HTTP failures and
one accepted sample; it has no percentile latency threshold.

## Required read-profile inputs

`portal-read.js` deliberately has no credential or target fallback. Provide the
following through an environment file kept outside the artifact directory:

| Variable | Contract |
|---|---|
| `VS_USER`, `VS_PASS` | synthetic operator credentials; never copy them into run logs or manifests |
| `U13_PROFILE` | exactly `S`, `T` or `L` |
| `U13_THERMAL_STATE` | exactly `warmup` or `warm`; ramp runs reject `cold` |
| `TARGET_MISSION_ID` | positive ID emitted for the current fixture |
| `TARGET_MISSION_NAME` | exact fixture mission name expected in the page title |
| `TARGET_VM_COUNT` | `10` for S, `40` for T, `1000` for L |
| `TARGET_VM_MARKER` | one VM name known to belong to that target mission |

The S/T/L definitions remain the AP09 definitions: S has one mission and 10
target VMs with one NIC, one disk and three packages per VM; T has 10 missions,
202 VMs total and 40 target VMs with two NICs, two disks and three packages per
target VM; L has 100 missions, 1099 VMs total and 1000 target VMs with the same
relation shape. Their log fixtures remain 100, 1000 and 10000 rows.

The script refuses a profile/count mismatch before issuing a request. A target
request is tagged `page:vms_candidate`; only a positively validated response is
added to `target_vms_duration`. Thus a redirect to the mission list, a followed
login redirect, an error page or the wrong mission cannot be reported as a
successful VM latency sample. `accepted_vm_samples > 0` makes missing target
samples a failed measurement even when authentication never reached a check.

The fixture likewise guards every mutation with its S/T/L validity flag. An
invalid direct SQL profile reports `valid = 0` with null counts and target
fields and cannot attach relations, jobs or logs to pre-existing lookalike rows.

`session-pair.js` requires the same target variables, plus `MODE` (`same` or
`independent`) and a non-secret lowercase `U13_RUN_ID`. Run both modes only on
the same already-warm fixture and unchanged source/container state. Do not put
the environment file or Docker command line in the exported artifacts.

`portal-read-cold.js` requires the same target inputs and the literal
`U13_COLD_PRECONDITION=verified-target-route-reset`. The surrounding manifest
must prove the reset/start state and that no earlier VM-target request occurred.
Its single `target_vms_cold_duration` sample stays separate from warm-up and
warm percentiles; `accepted_cold_vm_samples > 0` requires that sample to exist.
The paired profile likewise requires `accepted_session_pairs > 0`.

## Historical values and current evidence boundary

The July 2026 read runs correctly established independent sessions and the
dashboard, mission-list and health checks. Their values labelled `vms.php` are
invalid as VM evidence: the old request omitted `mission_id`, followed the
portal's correct redirect to `missions.php?type=missions`, and accepted that
signed-in page. The reported 50 ms (13 July) and 63 ms (17 July) therefore
describe another mission-list request and are withdrawn as VM baselines.

The historical write figures remain scoped to their original dev dataset and
source state. No historical number becomes a before-value for U13. The same
corrected harness must be used later for both isolated before and after source
trees.

## Manual review before any later run

Confirm that the chosen fixture output matches profile, mission ID, mission
name, VM count and marker; review the `portal-read.js` target validator; then
review that `session-pair.js` constructs one jar in `same`, two fresh jars in
`independent`, and emits no raw ID or authentication material. Cold, warm-up and
three warm repetitions stay separate. The prepared profiles have not been run
as part of U13 implementation.
