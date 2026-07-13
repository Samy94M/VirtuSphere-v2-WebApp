# ADR-0027: HTTPS Toggles, Cert Upload and HSTS

Date: 2026-07-11
Status: Accepted

## Context

ADR-0012 fixed the mechanics (PHP writes to a shared volume, an in-container
watcher reloads nginx) but left open how admins provide certificates, how the
redirect honors the machine-API exemption, and what HSTS value ships. The LAN
uses a Windows domain CA (AD CS), whose native export format is PFX/PKCS#12.

## Decision

The portal settings page owns HTTPS through one upload and three independent
toggles, all stored in `deploy_settings`:

- **Upload** accepts PFX/PKCS#12 (with password) and PEM pairs.
  `lib/https_config.php` validates before anything reaches the shared volume:
  key must match the certificate, expired leafs are rejected, chain
  certificates are carried along. Writes are atomic; the key gets mode `0600`
  before its rename and is never displayed. The PFX password is never
  persisted (`pfx_password` is a sticky-form sensitive field).
- **`https_enabled`** writes/removes the generated 8443 server block
  (TLS 1.2/1.3, `fastcgi_param HTTPS on`, deny rules mirrored from
  `default.conf` and pinned by `HttpsConfigTest`). The HTTP block lives in the
  image and is never generated, so HTTP cannot be broken by generated config.
- **`https_redirect_enabled`** redirects portal HTTP requests in
  `lib/bootstrap.php` (301 for GET/HEAD, 308 otherwise). Only portal pages
  load the bootstrap, so the machine API and `portal/health.php` are exempt by
  construction rather than by an nginx location list. Disabling HTTPS
  force-disables the redirect. Recovery from a redirect lockout is
  `https_redirect_enabled=0` in the database; HTTP always stays up.
- **`https_hsts_enabled`** sends `Strict-Transport-Security: max-age=15552000`
  (180 days, no `includeSubDomains`, no `preload`) on secure requests only.
  The former `HTTPS_HSTS_ENABLED` env var is gone. A settings-read failure
  means "no header".

The watcher gates every reload behind `nginx -t` and quarantines a broken
generated conf to `*.conf.bad` at container start. The webserver container
carries `DAC_READ_SEARCH` so nginx's root master can read the `0600` key that
PHP writes as `www-data`.

Worker-to-Ansible traffic is SSH/SFTP and Ansible talks to ESXi with ESXi's
own API certificate; neither path is affected by portal HTTPS. Moving the
machine API itself to HTTPS is an E3 decision, tracked in ADR-0019.

## Consequences

- Enabling HTTPS end-to-end needs no container operation beyond the one-time
  compose re-up that added the mounts and the 8443 port mapping.
- HSTS rollback is bounded: browsers pin for at most 180 days after disable;
  the toggle hint says so.
- The generated server block duplicates the HTTP block's root/deny/fastcgi
  settings; `HttpsConfigTest::testHttpAndHttpsDenyRulesStayInSync` fails when
  a deny rule is added to `default.conf` but not the generator.
- Operations runbook: `docs/operations/https.md`.
