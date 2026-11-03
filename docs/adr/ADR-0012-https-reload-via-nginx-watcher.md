# ADR-0012: HTTPS Reload via nginx Watcher

Date: 2026-06-28
Status: Accepted

## Context

The admin config page must toggle HTTPS without granting PHP access to Docker or nginx internals.

## Decision

PHP writes cert/key/config to a shared volume. nginx reloads through an internal watcher in the nginx container.

## Consequences

No Docker socket, `docker exec` or `nginx -s reload` from PHP. Redirects must exempt machine API until clients are migrated.