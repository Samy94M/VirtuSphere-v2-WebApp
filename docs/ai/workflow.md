# Efficient agent workflow

This procedure consumes the current user task and accepted plan. It does not start a feature campaign, authorize publication or replace the executable gate registry.

## Load and locate

- Read the root entry and only the routed contract/plan sections. Cross-area edits load all affected contracts, including callers outside the edited directory. Read the architecture/ADR index when locating an owner or deciding a contract.
- Locate with `rg --files`; search owner names/headings with `rg -n`. On PowerShell pass a real directory plus `-g '*.php'`, not an unexpanded wildcard filename. Batch independent reads with bounded output. Narrow a truncated query instead of reprinting the entire group.
- Use the existing task plan/register for requirement IDs, decisions, evidence and next step. Full logs and transient manifests belong in `qa-artifacts/`. Do not create competing plans or a second gate registry.
- Tool adapters reference the same detailed contracts. Markdown links do not guarantee automatic loading. Claude `paths` rules and Codex's instruction chain differ; the explicit root routing duty remains. Verify loading in a fresh task before claiming live-client behavior changed.

## Implement and verify

Identify owner, affected entry points, state transitions and counterexamples before a nontrivial package. Implement the smallest complete change satisfying them. Existing constants, schemas, helpers and registries retain product facts.

Run applicable gates through `scripts/check.ps1`; `docs/QA.md` supplies setup and `docs/QUALITY-GATES.md` interpretation. Documentation-only work receives document/contract checks rather than a gratuitous product suite. Portal text still requires language checks. Expand verification for new failures, changed dependencies and required final integration/release coverage.

Reuse evidence only with a valid source manifest, relevant environment and scope. A changed dependency can invalidate tests whose own files stayed unchanged. A hook or selected early gate is not final release evidence. Preserve actual result classes; missing tools are not passing skips, and no-network runs cannot discharge mandatory network checks.

After a failed command distinguish invocation, prerequisite, sandbox/infrastructure and product errors. Correct the identified cause before retrying. Do not repeat identical failed calls or restart full suites for the same information. Preserve canonical progress and QA isolation.

## Models and delegation

Use accepted package assignments; for Terra/Sol/Astra work:

| Model / effort | Scope |
|---|---|
| Terra Low | Bounded inventories, artifact extraction, decided documentation updates |
| Terra Medium | Small decided UI/pattern implementations, then Sol review |
| Sol Medium | Coordination, QA/benchmarks, log observation, evidence, all Git/commit/push work |
| Sol High | Semantic implementation, cause analysis, test design, bounded independent review |
| Astra High | Explicitly required critical contract review or documented unresolved hard counterexample before QA |

Mandatory independent reviews remain mandatory. Avoid redundant whole-repository reviews and routine reviews at every handoff. Astra never runs publication checks or remains root merely to monitor Sol doing them. XHigh needs an unresolved counterexample; Max/Ultra are not defaults. Settings must actually select the intended model/effort. Report unavailable assignments rather than asserting a substitution.

Delegate only with authorization and a useful independent bounded task. Provide package ID, question, file ownership, necessary source versions, decisions, counterexamples and artifact paths. Workers preserve others' changes. One owner operates a shared QA stack; measurements are exclusive. No agent exists just to wait or rediscover another agent's findings.

Delegate output: scoped verdict, actionable findings with locations/evidence, validation and gaps, next action. Full logs stay in artifacts. Missing models or required reviews remain explicit gaps while independent work continues.

## Finish and resume

After each package update the existing register: requirement IDs, implementation, source manifest, applicable checks/artifacts, external gaps and next step. At interruption also record running processes/agents and the QA owner. Resume there without repeating the source tour or erasing prior failures.

Complete required joint end-state checks, help/documentation and actual operator flows. Separate implemented, locally checked, published, installed and externally verified. Necessary new gaps belong to their package; unselected features stay outside scope. Ask for missing lab details early; prepare concrete publication/production actions before requesting missing authority.

## Maintain and measure

Root instructions contain cross-cutting rules and routing. Detailed contracts and non-obvious counterexamples live under `contracts/` and `reference/`; history/research goes in `docs/audits/`. Forbidden patterns remain solely in `GROK.md` section 1. Avoid repeating inventories and execution history in startup files.

UTF-8 bytes are a stable repository size proxy, not exact tokens or account cost. Byte limits complement line limits. Do not raise discovery caps or remove safety rules to improve a metric. Evaluate behavioral efficiency separately on representative tasks with the same task, source state, tools and model; compare correctness, available input/output tokens, rereads, tool calls and test reruns. Never invent savings or transfer API-cache discounts to a Codex quota.
