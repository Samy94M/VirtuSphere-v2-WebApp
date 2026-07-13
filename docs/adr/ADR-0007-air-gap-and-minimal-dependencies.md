# ADR-0007: Air-gap and Minimal Dependencies

Date: 2026-06-28
Status: Accepted

## Context

Target environments may not have internet access and should not rely on cloud/CDN services.

## Decision

No CDN, telemetry or runtime package downloads. Use local assets and minimal dependencies, with phpseclib as the SSH library unless a later ADR changes that.

## Consequences

Composer vendor artifacts need an offline strategy. Frontend assets must be local.