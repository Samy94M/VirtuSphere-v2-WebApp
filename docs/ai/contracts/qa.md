# VirtuSphere qa contracts

Read the sections relevant to the current change. Paths below are relative to Docker/WebAPI unless they already name a repository directory. Forbidden patterns remain in GROK.md section 1; these are the constructive implementation contracts.

## A22 Progress reporting is a repository contract. Every multi-unit check, test or maintenance runner

- Progress reporting is a repository contract. Every multi-unit check, test or maintenance runner whose total is known before execution emits `[n/total] RUN <unit>` immediately before each unit and `[n/total] <result> <unit>` immediately afterwards; nested runners use the same convention at their useful observable boundary. Never invent a total for genuinely open-ended work. Agents must preserve these progress lines. Before starting a long suite through a buffered tool transport, they must arrange a live, pollable progress log (for example by teeing the runner output) or split the suite into observable blocks. Poll at least once per minute and report the most recently observed runner line with its actual `[n/total]`; an elapsed-time-only update is not a progress update. If no counter has been emitted yet, say `[0/total]` when the total is known. Do not start a buffered long suite without an observation path. Any new or changed multi-unit runner must extend `tests/powershell/VirtuSphere.ProgressReporting.Tests.ps1` when it introduces another canonical execution path.

## A23 Runner modules are dot-sourced libraries, not entry points: import scope contains function defi

- Runner modules are dot-sourced libraries, not entry points: import scope contains function definitions only, paths derive from `$PSScriptRoot`/the explicit check root, and gate IDs/order, lanes, exit codes, progress and JSON remain owned by `scripts/check.ps1`. Browser resolution has one SSoT, `tests/e2e/lib/browser-resolver.js`, consumed by runner and Playwright; never add a user/revision fallback or another cache-selection algorithm.

## A24 Visual screenshots run only through the visual Playwright project against the exact synthetic v

- Visual screenshots run only through the `visual` Playwright project against the exact synthetic `virtusphere-qa` stack. Refuse shared/dev/production Compose labels, refuse active jobs, and restore only previously running QA workers in `finally`. Validate the committed runner/font metadata before images; a mismatch is `infrastructure_error` and never authorizes a baseline update. Real data must never appear in a screenshot.

## A25 The reviewed target PNGs under tests/e2e/visual/baselines/ are the pass criterion at zero toler

- The reviewed target PNGs under `tests/e2e/visual/baselines/` are the pass criterion at zero tolerance, and `scripts/update-visual-baselines.ps1` is their only writer: a person invokes it with a reason, it refuses on a runner mismatch, and every lane clears its enabling variables. A mask covers only a control the portal does not style and must still match something; a capture that mismatches is retried within the contract's attempt bound and the retry is reported, never silent.
