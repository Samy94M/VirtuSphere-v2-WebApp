# ADR-0008: Desktop Replacement and Status Model

Date: 2026-06-28
Status: Accepted

## Context

VM state is currently encoded in five legacy strings and the MECM `updated` flag.

## Decision

Introduce `lifecycle_state` and `mecm_sync_state` internally. Keep legacy strings as compatibility labels at integration boundaries.

## Consequences

Normal VM edits must not reset machine-owned state. Template clone resets runtime data explicitly.