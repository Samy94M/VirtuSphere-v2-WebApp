# ADR-0006: File Size Discipline

Date: 2026-06-28
Status: Accepted

## Context

The legacy PHP and WinForms files are large enough to hide security and behavioral coupling.

## Decision

New PHP modules should stay below roughly 400 lines; larger files trigger hook warnings and should be split by domain.

## Consequences

Refactors favor small repo/service/helper files. Warnings are non-blocking during legacy cleanup.

## Amendment 2026-08-09: help follows renderer boundaries

The portal help page is a shell, not a second monolith. `portal/help.php` owns
tab order and authorization; each panel is rendered by its matching partial in
`lib/help/`. Its DE/EN text lives in the equally named `help_<renderer>.php`
catalog, following the earlier `help_system_status.php` boundary.

Moving a key between these catalogs is an intentional i18n key rename: the
renderer and both locales change in the same package, and language parity must
pass before commit. The small `help.php` catalog contains only the page title
and tab labels. This makes renderer ownership visible and prevents future help
growth from recreating a file that hides unrelated domains.

## Amendment 2026-08-11: the budget is enforced, and every exception is dated

A warning nobody has to answer is a budget nobody keeps. Two years of
non-blocking hook warnings left twenty-three files under `lib/` and `portal/`
over the limit, the largest at 1220 lines bundling five independent transaction
domains. A warning also cannot distinguish "this legacy file is on a named
teardown plan" from "someone just added 300 lines to it", which is the only
distinction that matters while a cleanup is in flight.

`scripts/check-file-size.php --ci` is therefore a blocking gate in every lane of
`check.ps1`. Scope is `Docker/WebAPI/lib` and `Docker/WebAPI/portal`; the
machine-API files in the WebAPI root are a frozen wire surface that is
deliberately not refactored, and tests are measured by what they pin. The
`FILE_SIZE_ALLOWANCES` table in that script records every file that is over
budget today with its exact current size, the reason, and the stage that takes
it apart. That makes it a ratchet in both directions: an unlisted file may not
cross the budget (`file-size.oversize`), a listed file may not gain one line
(`file-size.grown`), and a listed file that came back under the budget must
leave the list (`file-size.stale`). The third rule is what stops the table from
becoming a permanent amnesty. An empty scan is a finding, not a pass
(`file-size.zero-match`). `scripts/test-guards.ps1` proves all four plus the
positive case that a new small module stays silent.

Splitting a file is not a licence for a big bang. The public require path stays
as a small compatibility facade that loads the domain modules in a deterministic
order, so callers, function names, signatures, transaction and lock boundaries,
query results, exceptions, audit/job-log wording and wire fields survive the
move unchanged. Characterization and require-closure tests pin that contract
before the structural hunk, and only a green parity run releases the semantic
change that motivated the split.

One consequence for the static contracts: a guard that reads exactly one source
file silently stops guarding the moment that file is split. Every scanner that
covers a page or module family therefore reads an owner glob or registry rather
than a filename, and carries a negative case proving that an unregistered new
module makes it red.
