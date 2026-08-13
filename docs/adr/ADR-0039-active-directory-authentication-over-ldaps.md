# ADR-0039: Explicitly imported Active Directory identities authenticate over strict LDAPS

- Status: Accepted
- Date: 2026-08-13

## Context

VirtuSphere currently owns both authentication and authorization for local
portal accounts. Operators also maintain identities in one classic Active
Directory Domain Services domain. Duplicating those passwords in VirtuSphere
would create a second credential lifecycle, while automatic directory
provisioning or group-to-role mapping would transfer local authorization to an
external system without an explicit product decision.

LDAP over TLS has two implementation-specific risks that cannot be decided by
configuration labels alone. PHP's LDAP extension requires LDAPS TLS options to
be set globally before `ldap_connect()`, so CA rotation must be proven in the
real long-lived PHP-FPM/OpenLDAP build. Microsoft documentation also remains
inconsistent about the effect of an `Always` channel-binding policy on TLS
plus Simple Bind. A real test against the effective target policy is therefore
an activation and release gate, not an assumption.

## Decision

VirtuSphere supports one AD DS domain with multiple explicitly configured,
prioritized, writable domain controllers:

- Transport is `ldaps://` only, using LDAPv3, an ASCII FQDN, certificate-chain
  and peer-name validation, Server Authentication EKU and TLS 1.2 or newer.
  There is no port-389, trust, hostname or certificate bypass.
- This release performs no online OCSP or CRL retrieval. That boundary is
  documented with the operational certificate-lifetime and rotation
  requirement; adding fail-closed CRL material is a separate decision.
- The durable external identity is the 16-byte `objectGUID`. A user receives
  portal access only after an administrator imports that GUID and assigns a
  local VirtuSphere role. UPN, DN, display name, mail and account names are
  mutable observations, not authorization keys.
- Authentication source is explicit at login and immutable per portal user.
  An AD identity has no local password and no fallback to `password_verify()`.
  AD groups, JIT provisioning, multiple domains/forests, Global Catalog,
  RODCs, SSO, Kerberos, NTLM, Entra ID, MFA and Conditional Access are outside
  this decision.
- VirtuSphere retains authorization. `role` and `is_active` are read locally
  on every request. At least one active local administrator is protected by a
  transactional invariant and remains the emergency path.
- AD sign-in is exposed only on the secure portal origin while portal HTTPS
  and HTTP-to-HTTPS redirect are active. Existing machine-API transport and
  wire contracts are untouched.
- A read-only search account resolves an exact UPN and retrieves the GUID.
  The user password is checked by a separate bind to the server-returned DN.
  Search-account rejection and user-credential rejection are distinct typed
  results: only the latter counts as a user password failure, and neither is
  fanned out across controllers.
- Technical failures may fail over in deterministic priority order within one
  monotonic request deadline. A persisted cooldown limits known failures. A
  search-account rejection opens a configuration-revision circuit breaker;
  a manual test or a new tested revision is required before automatic binds
  resume.
- AD sessions are periodically revalidated by GUID. Local disablement or AD
  integration disablement ends them immediately; a technical directory outage
  gets only a bounded grace period anchored to the last successful check.
- Configuration, CA and search secret live in the database; the secret uses
  the existing `APP_KEY` crypto path. A CA file is derived atomically outside
  the webroot and is never backup state. Restore disables AD, advances the
  revision and requires every controller to be tested again.
- Authentication, user administration and directory configuration remain in
  the existing `auth`, `users` and new `directory` audit categories. Directory
  stays in the Security log tab; there is no new log tab and no collection of
  domain-controller event logs.

## Activation gate

Feature-disabled code may be developed against hermetic fixtures, but AD may
not be enabled, presented as release-ready or piloted until all of the
following are recorded for every target controller:

1. OS build and patch level, effective LDAP signing and channel-binding
   policies, and relevant PDC/WAN behavior.
2. Strict TLS, search-account bind, RootDSE, writable-DC detection, user search
   and user bind using the shipped PHP 8.4/OpenLDAP image.
3. CA overlap/rotation in already running FPM workers, including parallel
   requests and negative name, CA, expiry and EKU cases.
4. No insecure bind evidence in the controlled domain-controller event-log
   check and no secret or raw LDAP diagnostic in VirtuSphere logs.

If the effective policy rejects this Simple-Bind design, operators do not
weaken policy or TLS. Kerberos or an OIDC/AD FS architecture requires a new
ADR.

## Consequences

- Directory availability can affect AD sessions but never the local emergency
  administrator or the machine API.
- Password changes, lockout and replication can cause an authoritative
  rejection at the first reachable controller. VirtuSphere deliberately does
  not retry a rejected password against another controller.
- A restored backup and a changed directory configuration require explicit
  controller evidence before login resumes.
- Portal, repository, migration, timeout, log, i18n, CSS and link SSoTs gain
  negative tests described by the LDAPS/AD integration plan. Existing local
  login behavior remains a blocking regression surface.
