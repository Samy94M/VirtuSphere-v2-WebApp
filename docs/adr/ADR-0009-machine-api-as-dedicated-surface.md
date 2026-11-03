# ADR-0009: Machine API as Dedicated Surface

Date: 2026-06-28
Status: Accepted

## Context

PowerShell and Ansible scripts depend on stable endpoints and fields.

## Decision

Keep `mecm-*` and `db_importMAC.php` as their own internal API surface while hardening auth, methods and SQL.

## Consequences

Wire-contract tests are required before major refactors. Endpoint removal waits for explicit migration acceptance.