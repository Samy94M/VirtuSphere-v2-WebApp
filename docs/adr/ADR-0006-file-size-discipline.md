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
