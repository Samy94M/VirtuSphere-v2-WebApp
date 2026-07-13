# ADR-0010: Security Baseline

Date: 2026-06-28
Status: Accepted

## Context

The legacy PHP code has SQL injection, hardcoded secrets, weak headers, no CSRF and plaintext credential risk.

## Decision

Adopt EnvBoot, central DB access, CSP/security headers, CSRF, prepared statements, bcrypt, audit tables and hardened compose settings.

## Consequences

New code has stricter requirements than untouched legacy code. Hooks warn on unsafe patterns.