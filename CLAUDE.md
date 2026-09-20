# VirtuSphere Claude Entry

@AGENTS.md

Stack reference: PHP 8.4 FPM. Use the shared routing table before changes. `.claude/rules/` contains short `paths`-scoped pointers to shared references under `docs/ai/`; do not import all references or ADRs at startup.

Run selected gates through `scripts/check.ps1` using `docs/QA.md`. No development-stack integration tests or separate remembered command battery. Hook success covers only its actual scope, not an entire QA lane.

For long runs tee a pollable log and report the actual latest `[n/total]` at least once per minute. The full progress contract is in the shared entry point.

At compaction preserve active package, decisions/authorization, source state, changed files, valid evidence/artifacts, running processes and next step. Keep history in the task register. Tool-native model names do not imply the requested Terra/Sol/Astra assignment; report a mismatch before substituting.
