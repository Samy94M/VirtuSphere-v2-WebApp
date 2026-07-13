# ADR-0006: File Size Discipline

Date: 2026-06-28
Status: Accepted

## Context

The legacy PHP and WinForms files are large enough to hide security and behavioral coupling.

## Decision

New PHP modules should stay below roughly 400 lines; larger files trigger hook warnings and should be split by domain.

## Consequences

Refactors favor small repo/service/helper files. Warnings are non-blocking during legacy cleanup.