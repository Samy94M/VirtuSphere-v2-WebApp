# VirtuSphere Agent Guide

VirtuSphere is a LAN/air-gap, server-rendered PHP portal with MySQL, MECM/PowerShell and Ansible/ESXi integrations. PHP target is 8.4. Runtime assets and dependencies stay local. The accepted task determines scope and authorization; a plan is not an instruction to repeat completed work.

## Read the affected contracts

This is the shared entry point. Before editing, read the relevant sections below, including cross-area dependencies. Use `rg -n` on headings and owner names, then read the matching sections; do not load every reference, ADR or old audit by default. References are mandatory for their subject. Tool-specific loading does not replace this routing step. Paths in this table are relative to the repository root.

| Work | Required sources |
|---|---|
| Any code/configuration change | `GROK.md` 1.1 and 3; `docs/ai/contracts/core.md` |
| Portal, shared HTML/JS/CSS, forms/navigation | `GROK.md` 1.2; matching sections of `docs/ai/contracts/portal.md` and `docs/ai/reference/portal.md` |
| PHP repositories, shared helpers, CLI closures | `docs/ai/reference/webapi.md`; also affected deploy/machine callers |
| Deploy/status/workers, identity/network, recovery | `GROK.md` 1.3; `docs/ai/contracts/deploy.md`; affected worker/status sections in the webapi/portal references |
| Machine endpoints or their clients | `GROK.md` 1.4 and 5; `docs/ai/contracts/machine.md`, `docs/ai/reference/machine-api.md` |
| Schema/migrations/persistence | `docs/ai/reference/database.md`; affected writer/consumer rows above |
| PowerShell / Ansible | `docs/ai/reference/powershell.md` / `docs/ai/reference/ansible.md`; affected machine/deploy contracts |
| Portal-visible text / language helper | `docs/ai/reference/i18n.md`; preserve DE/EN parity and display-only locale |
| Tests, runners, Compose, visuals | `GROK.md` 1.5; `docs/ai/contracts/qa.md`, `docs/QA.md`; gates from `scripts/check.ps1`, interpretation in `docs/QUALITY-GATES.md` |
| Rules, review roles, long tasks | `docs/ai/workflow.md`; assigned role section in `docs/ai/review-workflow.md` |

The architecture map is `docs/ai/contracts/architecture.md`; the decision index is `docs/adr/README.md`. Read them when locating an owner or resolving a contract. `GROK.md` section 1 remains the sole forbidden-pattern catalog. Implementation references name safe owners and counterexamples.

## Workflow boundaries

- Preserve unrelated/uncommitted work, including plans and fixtures. Establish actual sources before reusing evidence. Never reset or stage the whole tree to make it look clean.
- Use existing SSoT helpers, registries and writers. Preserve machine wire contracts, exact identities, transaction boundaries and unknown/partial outcomes. UI convenience is not authorization or a retry path.
- `scripts/check.ps1` is the only public QA runner. Select gates for the actual change and retain required final lanes. Do not substitute remembered development-container commands or a second gate list. Missing prerequisites are `infrastructure_error`, not a passing skip.
- Visual capture uses only the exact synthetic `virtusphere-qa` stack and its isolation/metadata contract. Only the human-invoked baseline writer changes reviewed PNGs; never lower tolerance or capture real data.
- Progress reporting is a repository contract. Known-size multi-unit work emits `[n/total] RUN <unit>` immediately before and `[n/total] <result> <unit>` immediately after each unit. Before a buffered long run arrange a live, pollable log; inspect at least once per minute and report the actual latest counter. Use `[0/total]` before the first unit when total is known; never invent totals or give elapsed-time-only updates. Preserve nested progress; new/changed canonical runner paths extend `tests/powershell/VirtuSphere.ProgressReporting.Tests.ps1`.
- Reuse passing checks only while their sources, dependencies, environment and scope remain valid. Fix new failures at their cause. Repeat full suites/reviews only for changed evidence or required final coverage.
- Durable rules belong here and in their references; progress/evidence belong in the existing task plan/register. After a package record results, source manifest, artifacts and next step; resume there after interruption.
- Ask only for missing information or authority; existing authorization persists. Prepare a concrete result before requesting final publication/production approval. Missing external labs or personal baseline actions remain open while independent work continues.

## Model and context economy

Follow explicit package assignments. For the accepted Terra/Sol/Astra workflow: Sol Medium coordinates QA and all Git/commit/push checks; Sol High implements and analyzes; Terra handles bounded inventory/docs or decided UI work. Astra is reserved for the specified critical contract question before QA, never routine checks or publication supervision. Details live in `docs/ai/workflow.md`.

Delegate only when authorized and when a bounded independent task saves work. One owner per edited file and one QA-stack owner. Give selected sources and a concrete question, not the full session. Logs stay on disk; return verdict, findings and artifact links. Search before reading, narrow truncated output and reopen sources only when needed. Token efficiency never waives contracts or required evidence.
