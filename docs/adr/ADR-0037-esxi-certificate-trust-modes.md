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

## Amendment (2026-10-14): the form chooses the mode, and no verification is the default

Operators created ESXi credentials without a certificate, as they had before
this ADR, and got a strict credential that failed every inventory pull with a
certificate error. The way back to working was a row button whose name did not
match the dropdown the form had shown them. The user decided, knowing that it
weakens the default:

- The certificate-type select is the one control of the trust mode. It offers
  CA bundle, server certificate and "no certificate verification"; the last is
  preselected for a new credential. A certificate type requires a certificate,
  and "no certificate verification" refuses one, both at the field, so a
  credential can no longer be saved in a mode it cannot run in.
- A new credential is strict exactly when a certificate type is chosen, and
  `legacy_insecure` otherwise. The same holds for an edit that turns another
  credential type into ESXi.
- Choosing "no certificate verification" on a strict credential downgrades it:
  the stored certificate, its type and the strict-test evidence are removed, a
  browser confirmation precedes the save, and the audit row is the same
  `trust_mode_changed` the former row button wrote. The row button is gone.
- The staged way up stays as decided above: storing a certificate on a
  credential without verification keeps it unverified, and that save starts the
  strict probe directly. Activation stays a separate confirmed action and now
  starts an inventory pull, like every save does.
- The portal calls the mode "no certificate verification" everywhere the
  operator sees it; `legacy_insecure` remains the stored value.

What does not change: the two stored modes, the YAML of both modes, the staged
activation, the `certificate` failure category and the warning badge of an
unverified credential.
