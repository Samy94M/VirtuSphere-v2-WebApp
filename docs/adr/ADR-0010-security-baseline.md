# ADR-0010: Security Baseline

Date: 2026-06-28
Status: Accepted

## Context

The legacy PHP code has SQL injection, hardcoded secrets, weak headers, no CSRF and plaintext credential risk.

## Decision

Adopt EnvBoot, central DB access, CSP/security headers, CSRF, prepared statements, bcrypt, audit tables and hardened compose settings.

## Consequences

New code has stricter requirements than untouched legacy code. Hooks warn on unsafe patterns.

## Amendment 1 (2026-09-12): database root scope

The application database account and the database root account are separate
security owners. Compose now passes an explicit environment allowlist to PHP,
the deploy worker and the maintenance worker. None receives
`MYSQL_ROOT_PASSWORD`, and the QA override no longer mounts a complete dotenv
file into those services. EnvBoot ignores the root key even when a readable
dotenv file is present and validates only the application credentials it owns.

The MySQL image validates `MYSQL_ROOT_PASSWORD` and `MYSQL_PASSWORD` before it
hands control to the unchanged upstream entrypoint. Host-side backup and restore
continue to use root where their database-wide operation requires it. This keeps
the former fail-fast strength check without giving a LAN-facing PHP process or a
worker an unused administrative credential.

## Amendment 2 (2026-09-12): PHP runtime and delivery tooling

PHP-FPM and both workers share the versioned
`virtusphere-php:8.4-runtime` reference and the same Dockerfile target. Native
extensions are compiled in a builder stage; the final runtime keeps only their
shared libraries and removes the upstream `PHPIZE_DEPS`. Composer, git and
archive tools exist only in the explicit `tooling` target used by CI and the
canonical QA runner. This separates long-lived attack surface from build and
delivery capability while preserving one PHP/base/extension definition.

The same delivery owner produces two complete image manifests: the core set and
the optional phpMyAdmin tools payload. A core air-gap installation does not load
or start phpMyAdmin. The optional installer first verifies its own checksum
manifest and then starts only the loopback-bound `tools` profile.
