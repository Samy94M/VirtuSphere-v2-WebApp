# Bounded review roles

Read the shared entry and only the assigned section. The caller provides scope/source manifest, question, valid evidence and authorized environment. Missing context is a specific gap, not a full-repository audit request. Reviewers do not edit product files; an authorized QA role may write normal artifacts and use the isolated QA environment.

## qa-runner

Run assigned selected gates through `scripts/check.ps1`, using `docs/QA.md` and `docs/QUALITY-GATES.md`. Honor source manifest and the single QA owner. No development-container PHPUnit, hardcoded container name, retired single-JS-file check or separate command battery. The runner owns discovery, gate order, exit classes and final lane requirements.

Arrange live pollable output before long runs and report actual counters. Reuse only still-valid evidence. Missing prerequisites are `infrastructure_error`; unexpected skipped mandatory tests are not pass. Do not start unapproved shared/production stacks. Return scoped result, actual gate outcomes, artifact paths, minimal failure excerpts and missing evidence. No product fixes or implied external validation.

## drift-checker

Review assigned changed owners/mirrors with the applicable canonical gates. Documentation changes need document checks; ENUM changes need their mirror check. Select from the registry instead of running every historic check on every call. Required final lanes remain the caller's responsibility. Consume existing valid evidence where possible.

Report each deviation with gate/case ID, source location, scope and SSoT correction owner. Separate blockers, warnings and infrastructure gaps. Clean means the verified scope only. Check byte/line budgets for changed startup files and verify referenced files/sections.

## i18n-checker

Read relevant sections of `docs/ai/reference/i18n.md`; run the assigned `lang-parity` gate through the canonical runner. Inspect changed call sites/values for keys/placeholders, real German umlauts, portal prose dash rules, safe errors and display-only locale. Do not dump whole catalogs or flag identifier spelling as prose. Key parity alone does not prove an explanation true.

Return actionable discrepancies, source locations, actual audit results and gaps. Suggest wording only when requested. Preserve wire fields and do not rely on stale test counts.

## contract-reviewer

Review the assigned semantic diff and affected callers against routed `GROK.md` sections, implementation references and applicable ADRs. Include new files from the supplied manifest; verify its scope before trusting evidence. Do not rerun a publication inventory or QA suite as part of a bounded Astra review.

Machine changes require section 5 and exact wire tests; callbacks require transaction/revision/identity fencing. Deploy changes require ownership, cancellation, partial/unknown outcomes and safe recovery. Portal changes require the common field/form/status/link owners, `edit_version` rather than display timestamps, and target permissions. Check actual changed contracts and mandatory counterexamples; do not duplicate the forbidden catalog here.

Return severity-ranked findings with file/line, trigger, violated contract, impact and evidence. Distinguish defects, hypotheses and missing proof. A clean result names scope and limitations. Default Codex role is Sol High; Astra is only for a specifically assigned critical question before QA. Publication/model boundaries follow the accepted task.
