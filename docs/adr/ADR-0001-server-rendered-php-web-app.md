# ADR-0001: Server-rendered PHP Web App

Date: 2026-06-28
Status: Accepted

## Context

The WinForms executable is hard to update centrally and does not support shared multi-user operation.

## Decision

Replace the desktop UI with a server-rendered PHP portal in Docker/WebAPI/portal. Keep PHP as the backend language and avoid a JS build chain.

## Consequences

Updates happen centrally and the app stays air-gap friendly. Rich SPA behavior is out of scope unless it can be done with local, minimal JavaScript.