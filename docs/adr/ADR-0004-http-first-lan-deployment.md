# ADR-0004: HTTP-first LAN Deployment

Date: 2026-06-28
Status: Accepted

## Context

The app starts in internal LAN environments and HTTPS must be configurable later, including self-signed or internal CA certs.

## Decision

Start on HTTP. Prepare HTTPS for an admin-configured toggle, but do not force redirects until configured.

## Consequences

Initial setup is simple. HSTS and Secure cookie behavior must be conditional and documented.