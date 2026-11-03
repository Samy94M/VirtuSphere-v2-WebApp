# ADR-0005: Local Docker Development Baseline

Date: 2026-06-28
Status: Accepted

## Context

The migration needs a reproducible local runtime for PHP, nginx, MySQL and phpMyAdmin.

## Decision

Use Docker Compose on this PC as the development and verification baseline.

## Consequences

E0 validation uses compose health, portal health checks and MySQL migrations. Runtime assumptions should be proven in containers.