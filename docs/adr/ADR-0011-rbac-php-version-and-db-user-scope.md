# ADR-0011: RBAC, PHP Version and DB User Scope

Date: 2026-06-28
Status: Accepted

## Context

The web app needs multiple users and admin-only configuration while staying operationally simple for v1.

## Decision

Target PHP 8.4, use roles `admin` and `user`, and keep one common application DB user for v1.

## Consequences

Least-privilege DB users are deferred. RBAC lives in a permission map rather than scattered conditionals.