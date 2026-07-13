# ADR-0002: Server-side SSH and Ansible

Date: 2026-06-28
Status: Accepted

## Context

The desktop client currently prepares playbooks and starts SSH work from a workstation.

## Decision

Move SSH/SFTP and Ansible execution to the server side, eventually through a dedicated worker container.

## Consequences

Users do not need local SSH tooling. Credential handling becomes a server responsibility and must be encrypted at rest.