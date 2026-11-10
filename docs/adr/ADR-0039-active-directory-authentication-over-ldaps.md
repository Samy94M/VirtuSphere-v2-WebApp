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

## Amendment (2026-08-28): settings request shell and tab owners

The settings route is a thin authentication, authorization and request shell.
Its action dispatcher and view model live in focused `lib/settings_*.php`
modules; each rendered tab is owned by one partial under `lib/settings/`.
This is a structural boundary only. In particular, the directory and HTTPS
actions retain their existing transactions, revision gates, validation,
secret handling, redirect fragments and audit behavior.

Static owner scans include both direct settings modules and the nested partial
directory. A bidirectional contract rejects either an unrequired partial or a
require without a matching file, and the existing action/tab and rendered-id
contracts continue to walk the complete owner set. Future form work therefore
extends a focused tab owner without silently escaping CSRF, confirmation,
redirect or deep-link coverage.

## Amendment (2026-11-09): review findings, session revalidation and wall clock

Source status 2026-11-08. Current Microsoft documentation states that LDAP
signing and channel binding do not apply to a Simple Bind inside LDAPS: signing
concerns SASL without TLS, and channel binding has no effect on Simple Bind
over TLS. Windows Server 2025 enforces signing and sets channel binding to
"When supported" for new deployments; TLS 1.3 may be negotiated. This removes
the documentation inconsistency named in the context, but not the activation
gate: Gate 0B still proves sign-in against the effective target policy, now
with the step list in
`docs/audits/2026-08-13-ldaps-target-ad-validation-protocol.md` and the
read-only probe `scripts/gate0b-tls-probe.php`.

Package N1a extends the decision without changing its boundaries:

- **Input admission.** A password with a NUL byte, an AD password over
  `VIRTUSPHERE_DIRECTORY_PASSWORD_MAX_BYTES` and an AD name that is not a valid
  UPN are refused before any counter or LDAP contact. The same limit applies
  to a newly saved search-account password.
- **Session revalidation.** The periodic GUID recheck now also ends a session
  when AD reports the account locked (`msDS-User-Account-Control-Computed`) or
  when `pwdLastSet` differs from the value stored at sign-in. The local
  authorization cache is written before the decision. Sessions from before
  the change learn their baseline on the first successful recheck. A locally
  deactivated portal account ends its session for good with a typed
  `auth.session_ended` row; a later reactivation does not revive it.
- **Strict attributes.** `userAccountControl` and `pwdLastSet` must be present
  and parseable on every user entry; otherwise the result is
  `invalid_response`, never an enabled default.
- **Typed bind reasons.** Only the `data <hex>` sub-code is taken from the
  controller diagnostic, in exactly one reader, and mapped to a closed
  vocabulary. The text itself is never stored, logged or shown. A user
  rejection keeps the generic sign-in message and records the typed reason in
  the security log only. A search-account rejection still opens the revision
  circuit breaker; its stored reason now says whether the password expired,
  the account is disabled or expired, or locked.
- **Timeouts.** `LDAP_OPT_TIMEOUT` clamps every synchronous answer wait to the
  remaining request deadline, so a controller that completes a TLS 1.2
  handshake and then stays silent costs at most the answer budget and the
  sign-in fails over. After a TLS 1.3 handshake this does not hold: the
  server's session tickets wake libldap's `select`, and its blocking read
  then waits without a limit (measured against the fixture on 2026-11-09;
  PHP 8.4 calls `ldap_sasl_bind_s` and `ldap_search_ext_s`). TLS 1.3 stays
  allowed, because capping the client at TLS 1.2 would weaken transport for
  a rare fault (user decision 2026-11-09). For that residual the FPM wall
  clock below (300 s, shipped with the php image of the next release) is the
  only bound, and that request does not fail over. Measured in the QA
  network: 3.0 s and errno -5 after TLS 1.2 with a 3 s timeout, no end after
  TLS 1.3. Sources: OpenLDAP 2.6 `libraries/libldap/result.c` (`wait4msg`
  applies `LDAP_OPT_TIMEOUT` only to the readiness wait), php-src PHP-8.4
  `ext/ldap/ldap.c` (`ldap_bind` uses `ldap_sasl_bind_s`, `ldap_search` uses
  `ldap_search_ext_s`, both without a timeout argument). The fixture proof
  stays on TLS 1.2, and `DirectoryModuleContractTest` pins this boundary.
  PHP-FPM
  `request_terminate_timeout` equals nginx `fastcgi_read_timeout` (300 s) as a
  hard wall clock for any request; `request_slowlog_timeout` (30 s) logs the
  script name to the container error stream. The FPM master runs without
  `CAP_DAC_OVERRIDE` and `CAP_SYS_PTRACE`, so there is no slowlog file under
  `logs/` and no backtrace.
- **TLS floor fail-closed.** A runtime without the TLS minimum constants is
  `extension_missing`, not a silent omission. The former
  `LDAP_OPT_X_TLS_NEWCTX` branch is removed: PHP 8.4 resets the TLS context
  internally (php-src PR 17940), and the CA-rotation fixture test proves it
  for the shipped image.
- **Certificate data.** After a successful directory operation the portal
  reads the controller certificate through one TLS handshake at most once a
  day per controller and revision, so a renewed certificate replaces the
  expiry from the last manual test. A stored expiry in the past with a later
  successful contact is shown as stale, not as danger.

The 2026-11 hermetic fixture adds a certificate without Server Authentication
EKU, a TLS 1.1-only endpoint and a controller that stays silent after TLS.
AD sub-codes cannot be produced by OpenLDAP; their mapping is proven by unit
tests and on a real controller in Gate 0B step F6.
