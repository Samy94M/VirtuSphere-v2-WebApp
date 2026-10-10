# ADR-0003: Full Desktop Feature Parity

Date: 2026-06-28
Status: Superseded by ADR-0035 (2026-07-27). The parity condition was met and the desktop client and its token API are removed.

## Context

The desktop application is still the reference workflow for missions, VMs, templates, VLANs, OS catalog, CSV and deploy.

## Decision

The web app must reach full feature parity before the desktop/token API is retired.

## Consequences

Partial UI ports cannot remove legacy endpoints. Characterization tests protect machine integrations during migration.