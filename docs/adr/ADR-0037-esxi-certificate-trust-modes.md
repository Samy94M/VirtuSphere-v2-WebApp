# ADR-0037: ESXi certificate trust is explicit and staged

- Status: Accepted
- Date: 2026-08-08

## Context

Every VMware playbook used `validate_certs: false`. HTTPS encrypted credentials
in transit but did not authenticate the ESXi peer, so a LAN attacker able to
intercept the route could impersonate the host and capture the ESXi account.
Changing all installed credentials to strict verification in one migration
would also have stopped existing air-gapped sites whose private trust anchor is
not yet present on the Ansible host.

## Decision

An ESXi credential carries one explicit trust mode: `strict` or
`legacy_insecure`. New credentials default to strict. Migration marks only rows
that predate the column as legacy, visibly preserving their current behaviour.
There is no empty or implicit fallback.

Strict mode sets `validate_certs: true` in every VMware module call and uploads
a private `esxi-trust.pem` per job. Both `SSL_CERT_FILE` and
`REQUESTS_CA_BUNDLE` point to it. The stored material is either a CA bundle or
exactly one server certificate. A CA bundle tolerates normal leaf rotation; a
server certificate is an exact pin and must be replaced on renewal.

Legacy migration is staged: store trust material, run the real read-only
inventory path with strict validation while the durable mode remains legacy,
then activate strict through a separate confirmed action. Success is recorded
on the credential; editing endpoint, account, secret or certificate invalidates
that evidence. Downgrading to legacy is also explicit, confirmed and audited.

Certificate failures have their own `certificate` category. A missing or
invalid strict trust artifact fails during configuration with that category;
verification errors from Ansible use the same category and never fall through
to `parse`.

## Consequences

- Existing deployments continue until operators complete the staged migration,
  but the portal labels their unverified identity instead of presenting it as
  normal TLS.
- A strict credential without stored trust material cannot inventory or deploy.
- Trust material is public certificate data, not a private key, but is still
  uploaded with mode 0600 and removed with the per-job directory.
- The real-ESXi migration check remains a hardware gate and is not claimed by
  automated tests.

## Verification

`EsxiTrustModeTest` pins both YAML modes, PEM validation, all six VMware
playbooks, migration semantics and the named certificate failure. The
integration test covers fresh strict credentials, staged activation and the
strict-probe job payload. Ansible syntax/module contracts and the schema
convergence gate cover the cross-language boundaries.
