# Deployment

## Ubuntu Ansible Host

VirtuSphere keeps Ansible outside the PHP/nginx stack. The browser queues a deploy job in the portal, the CLI deploy worker uploads generated YAML and playbooks over SFTP, then the Ubuntu Ansible host runs `ansible-playbook` against ESXi.

Required on the Ubuntu host:

- A dedicated SSH user reachable from the `deploy-worker` container.
- `ansible-playbook` and `ansible-doc` in the SSH user's `PATH`, from `ansible-core` 2.19 or newer.
- `python3` with `pyvmomi` 8.0.3.0.1 or newer, importable as `pyVim` and `pyVmomi`.
- The `community.vmware` collection, installed from the repository's pin **before** air-gapped operation:
  `ansible-galaxy collection install -r Ansible/requirements.yml`. That file is the single source of truth for the version. For an air-gapped host, run `ansible-galaxy collection download -r Ansible/requirements.yml -p ./offline` once on a connected machine and install from that directory. On a fully offline host `ansible-core` and `pyvmomi` come the same way: fetch their pip wheels on a connected machine and `pip install --no-index` them into the SSH user's Python environment. The 2.19 floor is not a preference; it is the `requires_ansible` the 6.2.0 pin enforces, and an older core makes the collection refuse to load.
- Network access from Ubuntu to ESXi and from Ubuntu back to the VirtuSphere WebAPI base URL.

### ESXi support matrix

| Dimension | Supported | Notes |
|---|---|---|
| ESXi / vSphere | 7.0, 8.0 | The range `community.vmware` 6.x targets. Older releases are out of general support. |
| Licence | any licensed edition (Standard, Enterprise Plus, VCF) | The write API is what deploy and autostart need. |
| Free licence (incl. 8.0 U3e) | inventory only | Broadcom's free hypervisor exposes a read-only API. Inventory pulls work; creating VMs and writing the autostart policy do not. The portal reads the licence from the host and warns before a job runs. |
| Host in a vSphere HA cluster | deploy yes, autostart no | ESXi disables autostart on HA cluster members; the HA restart priority owns startup there. |
| Standalone host | full support | `datacenter_name` must resolve to `ha-datacenter`; the portal derives it from the credential's inventory when the mission leaves it empty. |
| vCenter (`api_type = VirtualCenter`) | deploy yes, autostart with care | DRS/vMotion can move a VM off the host whose autostart list names it. Autostart assumes a static host. |

The portal reports the host's product, version, licence and HA/maintenance state on the integrations page after the first successful inventory pull (ADR-0023, ADR-0025).

Runtime setup:

- Store one `esxi` credential for the ESXi API. The host may be a bare hostname/IP or an `http(s)` URL without path, credentials, query or fragment; deploy artifacts normalize it to `esxi_hostname` plus `esxi_port`.
- Store one `ansible` credential for the Ubuntu SSH account. Use only the Ansible host/IP in the host field, not an URL.
- Provide `APP_PUBLIC_BASE_URL` in `.env`; `deploy_settings.api_base_url` / `VIRTUSPHERE_SETTING_API_BASE_URL` can override it from the portal settings page.
- Use `Credentials -> Test` on the Ansible credential; it runs SSH login plus the local Ansible/Python/community.vmware preflight.
- Queueing a deploy job now preflights the session user, mission deploy fields, VM presence and ESXi/Ansible credential completeness before the worker can claim the job.

The worker never writes plaintext secrets to deploy logs. It generates `accounts.yml` only inside the temporary job directory, uploads it to the Ansible host, and removes the local job directory after processing.

### Generated vars and the playbook variable contract

The playbooks under `Ansible/` are not self-contained: they read variables that the WebAPI generates at deploy time in `Docker/WebAPI/lib/ansible.php`. The playbook `{{ ... }}` names and the generated keys must stay in lockstep; renaming one side breaks the deploy silently, so change both together.

- `accounts.yml` (from `ansible_accounts_yml`): `esxi_hostname`, `esxi_port`, `esxi_username`, `esxi_password`, `ansible_username`, `WaitingTime`, `apiUrl`. `esxi_port` is always emitted; `credential_esxi_normalize` defaults it to `443` (`VIRTUSPHERE_CREDENTIAL_PORT_ESXI_HTTPS`) when the ESXi credential has no explicit port, so `port: "{{ esxi_port | int }}"` is always defined.
- `serverlist.yml` (from `ansible_serverlist_yml`): `vm_configurations`, a list whose items carry `vm_name`, `guest_id`, `datacenter_name`, `datastore_name`, `disks` (a list), `network` (a list of `name` + `device_type`, where `device_type` is one of `vmxnet3`/`e1000`/`e1000e` from `VIRTUSPHERE_INTERFACE_TYPES`, enum-validated on write so it always matches what `vmware_guest` accepts), `memory`, `vcpus`, `hotadd_cpu`/`hotadd_memory` (bool, from the VM `cpu_hotplug`/`ram_hotplug` flags; the create playbook maps them to `hardware.hotadd_cpu`/`hotadd_memory`), `needs_mac` (bool) and `autostart` (a block of `enabled`, `start_delay`, `stop_delay`; a delay of `-1` means "inherit the mission default" and is passed through to `vmware_host_auto_start` unchanged). It also emits the top-level `PowerCycleWaitSeconds` and a `mission_configuration.autostart` block (`enabled`, `start_delay`, `stop_delay`, `stop_action`, `wait_for_heartbeat`) which becomes the host's `system_defaults`. The create playbook maps these into `vmware_guest` `disk`/`networks`/`hardware`; when connecting straight to a standalone ESXi host, `datacenter_name` must resolve to `ha-datacenter`. When a deploy job selects specific VMs, `vm_configurations` contains only that subset; an empty selection means the whole mission.
- Only `createVMs-ESXi_playbook.yml` defines a `hardware:` block (VM sizing, firmware, hardware version); `startVMs-` powers on existing VMs via `vmware_guest_powerstate` and has no hardware settings.

### Deploy modes and the MAC-generation power-cycle

Modes map to playbooks in `ansible_playbooks_for_mode()`: `create`, `export`, `start`, `powercycle` (runs `powercycleVMs-ESXi_playbook.yml` then the export playbook), `autostart` (writes the ESXi autostart policy, ADR-0025) and `full` (`create → powercycle → export → start`, plus `autostart` when the mission enabled it). The power-cycle exists because ESXi may only assign a NIC its MAC once the VM has been powered on, and the MAC export otherwise reads nothing. `powercycleVMs-ESXi_playbook.yml` briefly powers a VM on, waits `PowerCycleWaitSeconds` (portal field, default 5s, clamped 1–300s), then hard powers it off (`state: powered-off`, `force: yes`; freshly created VMs have no guest OS/tools, so a graceful shutdown would hang).

The deploy form's label map (`virtusphere_deploy_mode_labels()`) is the source of truth for which modes an operator may ask for. `inventory` is a *system* mode: it has no label, cannot be posted, and `repo_create_system_job()` refuses anything else. The read side (`deploy_job_normalize_mode()`) still accepts it, because the worker reads back a queued inventory job's payload. The location gate follows the same shape: `autostart` reads neither `datacenter_name` nor `datastore_name`, so a mission without a datastore can still have its autostart policy written, while every other mode is refused. Staggering is refused for `autostart` in the repository as well as on the page, because a config write has nothing to spread over time.

`full` appends the autostart step only for a mission whose `autostart_enabled` is set. A full deploy of any other mission must not touch the host's autostart manager: one host can carry VMs of several missions, and writing `system_defaults` from a mission that never asked for autostart would overwrite the policy of the missions that did. The explicit `autostart` mode always runs, including for a disabled mission, because setting every one of its VMs to `start_action: none` is how a policy is withdrawn. Before the step runs, the worker checks the cached capability facts of the target credential: a fresh free-licence fact aborts the job, a fresh HA-cluster fact aborts mode `autostart` and skips the step inside `full`. Unknown or stale facts only warn, because the inventory cache never blocks a deploy (ADR-0023).

It only touches VMs whose `needs_mac` is true. `needs_mac` reflects whether the mission's **WDS-VLAN** NIC already has a MAC (`vlan == mission.wds_vlan`), so already-provisioned or running VMs are left alone. Boot order is intentionally **not** changed: PXE only completes on the NIC that receives a DHCP/PXE offer, which is the WDS-VLAN NIC, so the exported MAC matches the NIC that actually boots. Operating requirement: only the WDS VLAN may provide a PXE/DHCP responder; otherwise a different NIC could win PXE and its MAC would not match the MECM device record.

The MAC export is intentionally tolerant per VM: a failed `vmware_guest_info` item remains in `vm_infos.json` next to the successful items and is classified by the callback instead of aborting the whole export before the callback runs. `upload_mac_list.py` accepts only an HTTP 2xx response with valid JSON and an explicit `outcome` of `success`, `partial` or `failed`. These map to exit codes `0`, `20` and `21`; invalid local data, transport failure and an invalid response use `22`, `23` and `24`. The client retries exactly once after a timeout or HTTP 5xx and logs only the outcome plus bounded counters, never the response body.

The web worker patches `api_base_url`, `mission_id` and `job_id` into its private script copy. The source placeholders remain for the legacy desktop packaging path; an unresolved `job_id` is omitted from that legacy payload. Both workflows execute the same export playbook, so a genuine callback failure now makes the export step fail instead of appearing green. Playbook filenames remain unchanged for desktop compatibility.

For worker-managed exports, the callback accepts `{ "mission_id": 123, "job_id": 456, "results": [...] }`. It rejects a missing, mission-foreign or terminal job with HTTP 409 before opening its request transaction, then locks the running job again to close the worker race. The complete payload is resolved first; only a VM whose every NIC is valid is written. NICs, VM state, status history and the versioned `deploy_jobs.result_json` are one transaction, so a database/program failure cannot leave a partial callback. Repeating the same callback while the job is still `running` is idempotent; callbacks after terminal completion return 409.

The `Ansible/` copies are the web-app deploy source. The `bin/Debug|Release/Ansible/` copies are build outputs of the legacy WinForms desktop client, which generates its own vars in C# and does not carry `esxi_port`. The two paths are intentionally separate; do not sync desktop `bin/` playbooks against the web-app source.

### Worker resilience

The `deploy-worker`, `maintenance-worker`, `php`, `webserver` and `mysql` services run with `restart: unless-stopped` so the stack recovers after a host reboot or a container crash without manual intervention. Every runtime service carries a healthcheck (AP8): MySQL answers `mysqladmin ping`, PHP answers the FPM FastCGI ping, nginx answers its own `/nginx-health` location, and both workers keep a liveness file fresh that `lib/worker_healthcheck.php` judges. `webserver` waits for real PHP readiness (`service_healthy`), so a cold `docker compose up -d --wait` only returns green when the whole chain accepts work. phpMyAdmin is admin tooling in the optional `tools` profile (`docker compose --profile tools up -d phpmyadmin`, loopback-only) and is not part of the runtime stack.

In `--loop` mode the worker tolerates a MySQL outage instead of exiting: it retries the initial connection with backoff (up to 30s between attempts) and, if the database drops mid-loop, reconnects through `db(true)` and continues claiming jobs. This closes the earlier failure where a slow MySQL start or a MySQL restart left the worker container dead and deploy jobs stuck in `queued` with no portal-visible error. The `--once` mode used by tooling still fails fast (three connection attempts, then a non-zero exit).

Long-running worker phases send heartbeats at least every `VIRTUSPHERE_DEPLOY_HEARTBEAT_INTERVAL_SECONDS` (30s) through phase boundaries and streamed Ansible output. At the beginning of each loop the worker reaps running jobs whose heartbeat is older than `VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS` (600s): the job is marked `failed`, its lock is cleared, a SYSTEM log line is written, and affected mission VMs are reset through the existing deploy-worker VM status path. Terminal updates are guarded by `id`, `locked_by` and `running` status so a worker that lost ownership cannot overwrite a cancelled or reaped job.

### Deploy job retention

The maintenance worker prunes on its hourly retention pass:

- **`deploy_job_logs`** of jobs that have been terminal for more than `VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS` (30). The window is measured on the **job**, not on the log row, so a job that streams for an hour never loses its opening lines and a live tail cannot race the purge. Queued and running jobs are untouchable by construction. The job row survives with its status and `last_error`; `deploy_log.php` says the output was pruned instead of showing an unexplained empty table.
- **Finished mission-less system jobs** (the ESXi inventory pulls) after `VIRTUSPHERE_SYSTEM_JOB_RETENTION_DAYS` (30), rows and all; their logs cascade. No page lists them once terminal, and their one durable result lives in `deploy_esxi_inventory_state`. Mission jobs are kept, because the deploy page shows their history.

## Runtime logs and error references

VirtuSphere writes runtime logs into host-mounted directories so troubleshooting evidence survives container restarts:

- Application error handler log: `Docker/WebAPI/logs/error.log` maps to `/var/www/html/logs/error.log`.
- PHP engine log: `Docker/WebAPI/logs/php-error.log` maps to `/var/www/html/logs/php-error.log` through `Docker/php/conf.d/zz-virtusphere.ini`.
- Legacy repository failure log: `Docker/WebAPI/logs/fail.log`.
- nginx access/error logs: `Docker/logs/nginx/access.log` and `Docker/logs/nginx/error.log` map to `/var/log/nginx` in the webserver container.

The global handler in `Docker/WebAPI/lib/errors.php` is installed before EnvBoot completes. Web errors render a full internal error page with a short reference ID, while CLI errors print the same reference to STDERR and exit non-zero. The handler also appends the full context and stack trace to `error.log`; when the database boot path is healthy it records an `error [ref] ...` row in `deploy_logs` for the portal log view.

For a reported error page, use this order:

1. Copy the displayed reference ID.
2. Search `Docker/WebAPI/logs/error.log` for that ID.
3. Check `Docker/WebAPI/logs/php-error.log` for PHP engine-level parse/startup errors or handler fallback messages.
4. Check `Docker/logs/nginx/error.log` if nginx returned the response before PHP was reached.
5. In the portal log view, search for `error [ref]` when the database is available.

Expected behavior:

- A portal exception, warning promoted to `ErrorException`, or parse error returns HTTP 500 with a generic error page plus reference ID and writes the full detail to `error.log`. Set `VIRTUSPHERE_DEBUG=1` only in a local/debug environment to include message, file and stack details in the browser response.
- A CLI exception returns exit code `1`, writes STDERR, and writes `error.log`.
- EnvBoot failures still render/log through the handler; database audit may be skipped because the same broken environment can block DB boot.
- Real log files are ignored by Git. Only `.gitkeep` files keep the log directories present in fresh checkouts.

Useful checks:

```bash
docker compose ps
docker compose config --quiet
docker exec virtusphere-v2-webapp-php-1 php -r "echo ini_get('error_log'), PHP_EOL;"
docker exec virtusphere-v2-webapp-webserver-1 nginx -t
docker exec virtusphere-v2-webapp-php-1 php-fpm -t
```

## Backup and restore

`scripts/backup.sh` writes a full MySQL dump, a config tarball and a SHA-256 manifest to `Docker/backups/`; `scripts/restore_test.sh` runs the full restore drill against a throwaway environment (manifest, schema convergence, migrations, invariants, `APP_KEY` binding, app smoke). See `docs/operations/backup.md` for scheduling, offsite handling and the disaster-recovery procedure (ADR-0017, amendment 1). The drill is the `restore-drill` gate of the Release lane (`scripts/check.ps1`).

Each run also appends a status line to `Docker/backups/status/backup-status.jsonl`. Only this `status/` subdirectory is bind-mounted read-only into the `php` service (`./Docker/backups/status:/var/backups/virtusphere-status:ro`, ADR-0021) so the portal can show a backup card and dashboard banner; the dumps and the config tar (which holds `.env`) are never mounted. After adding or changing this mount, recreate the container (`docker compose up -d php`, or `--force-recreate` if the bind was already present) or the portal keeps reporting the `unknown` state.

**Rule: run `sh scripts/backup.sh` once before every migration.** Migrations have no down path, so a fresh dump is the only rollback net.

## Machine API hardening notes

The legacy machine API remains wire-compatible during migration, but the WP1 hotfixes make dangerous edge cases loud:

- Phase D removes the old root/portal tombstones and passthroughs from the WebAPI webroot: `login.php`, `register.php`, `db_cleanup.php`, `upgradeMysqlLatest.php`, `testdata.php`, `mecm_api_old.php`, `portal/register.php`, `portal/createUser.php`, `portal/create_user.php`, `portal/TESTintern.php`, `portal/_OLDlandingpage.php`, `portal/_OLDindex2.php`, `portal/access.php`, `portal/mysql.php`, `portal/intern.php` and `portal/vorgaben.txt`. Create users only from the authenticated portal user administration or `lib/seed.php`.
- Root `intern.php` remains a redirect stub to `/portal/dashboard.php`; root `login.php` is no longer present. Logout is only `/portal/logout.php` as a POST plus CSRF. A stale logout POST without an active portal session, for example after a container restart or expired server-side session, is treated as already logged out and redirects to `login.php`; GET requests and active-session CSRF mismatches still return HTTP 400.
- The legacy desktop token API remains at root `access.php` and `api/login.php` until E3; the removed `portal/access.php` wrapper must not be used for new flows.
- `mecm_packages.php` rejects a completely empty JSON payload (`{}` or `[]`) with HTTP 400 before catalog sync can run. Missing payload types are now non-destructive: if a request contains only packages, `deploy_os` is left untouched; if it contains only task sequences, `deploy_packages` is left untouched. The absent type is logged as a warning.
- `db_importMAC.php?action=updateInterface` requires `{ "mission_id": 123, "results": [...] }`; `job_id` is optional only for the legacy/Desktop path. Existing response fields remain; `result_version`, `outcome`, `job_id`, `vm_results`, `counts` and bounded `errors` are additive. Per-VM failures return HTTP 200 with `success:false` and write no NIC or deployed state for that VM. Job/mission/status conflicts return 409 without writes.
- `mecm-api.php?action=getDeviceInfos&mac=...` deliberately keeps its old IP-or-MAC allowlist behavior. The legacy 403 response still echoes the client IP for compatibility in the LAN contract.
- Unhandled machine-API exceptions return the existing JSON envelope shape with generic `Interner Serverfehler`; internal details are written through `machine_api_log_warning`/fallback logging instead of being sent to clients.

## Operational preflight

Use the migration checker before deployments or after changing `.env`:

```bash
docker compose exec -T php php /var/www/html/lib/migrate.php --check
```

The check is non-mutating. It verifies EnvBoot, database connectivity, data preflight rules and reports pending migrations. Run the real migration only after the check is clean:

```bash
docker compose exec -T php php /var/www/html/lib/migrate.php
```

`Docker/scripts/setup.sh` wraps the local bootstrap flow: create `.env` when missing, generate random local secrets with `openssl`, create log/data directories, run compose validation, build/start containers, execute `migrate.php --check`, then apply migrations. It skips first-admin seeding unless `SEED_ADMIN_USER` and `SEED_ADMIN_PASSWORD` are set in `.env`.

## Health endpoint

`/portal/health.php` returns JSON for automation. The response includes:

- `db`: database connectivity.
- `logs`: writability of the application log directory and PHP engine error-log directory.
- `worker`: count of running deploy jobs, stale running jobs and the latest worker heartbeat.

A degraded log or worker state returns HTTP 503 with `status: "degraded"`; a database/bootstrap failure returns `status: "error"` with a generic service-unavailable message and logs the internal exception server-side.

## Backend validation and login throttling

Shared validation lives in `Docker/WebAPI/lib/validate.php`. VM saves now validate VM name, DNS FQDN domains, OS, RAM/CPU ranges, disk rows, static interface IP/subnet/gateway, DNS and MAC formats before repository writes. The portal VM editor and Ansible artifact generation share the `VIRTUSPHERE_VM_DEFAULTS` disk contract: missing/new disk rows default to a `System` disk with 50 GB and type `thick`; allowed disk types stay `thin`, `thick` and `eagerzeroedthick`. Interface subnet masks accept IPv4 masks and CIDR-style `/0` through `/30` values. VM edits still preserve existing interface MACs when no new MAC is submitted.

The backend-only follow-up extends the same validation style to repo/service layers that do not need frontend design work: Credentials validate type, name, host/URL, port, username, secret requirement and per-type duplicate names; missions validate names/status/details and duplicate names; OS/VLAN/package catalog writes validate required values, lengths, duplicates and missing IDs before returning success.

Deploy queue creation now blocks invalid work before the CLI worker sees it: missing user, template mission, missing datacenter/datastore, empty mission VM list, wrong credential type or incomplete credential data all return clear repository errors.

Portal login keeps the existing per-user lockout and adds an IP-wide 15-minute throttle for repeated failed attempts across usernames. The throttle is backed by `deploy_login_attempts` and the `login_attempt_ip_lookup` index.

## Retired unsafe bootstrap endpoints

The root `index.php` no longer conditionally redirects to `testdata.php`. It redirects to `/portal/login.php`. `testdata.php` is removed; use `lib/seed.php` and migrations instead.

## Backend/Ops plan completion audit

Audit date: 2026-07-05. Scope: the backend/Ops plan plus the Findings/SSoT update, excluding future milestones that the migration plan already keeps separate.

Completed in code and runtime-tested:

- Phase 1 logging/error handling: global web/CLI handlers, persistent PHP/nginx log paths, non-fatal but loud status-event logging, worker/repo/migration errors surfaced with clear messages.
- Phase 2 validation and edge cases: shared `ValidationException`/`Validator`, VM/credential/mission/catalog/deploy preflight validation, empty MECM payload guard, mandatory MAC-import `mission_id`, unmatched MAC import entries with `status:error`.
- Phase 3 Ops/QOL backend: `setup.sh`, `.env.example` Ubuntu `openssl` hint, `migrate.php --check`, JSON health endpoint and backend-side form error plumbing.
- Findings/SSoT update: unsafe legacy web entry points were first retired and later removed in Phase D, root `intern.php` remains only a redirect stub, nginx denies internal webroot paths, credential/deploy/status/role/settings/machine-API literals centralized, ESXi URL normalization writes `esxi_hostname` plus `esxi_port`, Ansible required files derive from `VIRTUSPHERE_PLAYBOOKS`, `VIRTUSPHERE_TABLES` removed, and real historical log files are removed from Git tracking.
- Contract preservation: `access.php` and `api/login.php` remain legacy-only until E3; machine API status strings, `updated`, `mecm_id`, `mecm-api.php?action=getDeviceInfos&mac=...` IP-or-MAC allowlist behavior and the legacy 403 IP echo are intentionally preserved.

Not claimed as complete by this audit:

- Pure visual frontend/design work.
- E3 physical retirement of the desktop token API.
- A completely fresh Ubuntu/Clean-Checkout execution of `Docker/scripts/setup.sh`; the same setup steps are verified locally through Docker build/start, migration check, migration and health probes.

## Backend/Ops release checklist

For backend-only slices, keep the frontend/settings worktree separate and verify with this minimum set before commit or handoff:

```bash
git diff --check -- ':!Docker/WebAPI/vendor/**'
docker compose config --quiet
docker exec virtusphere-v2-webapp-php-1 composer --working-dir=/var/www/html test
docker run --rm -v C:\projekte\VirtuSphere-v2-WebApp:/repo -w /repo virtusphere-v2-webapp-php php scripts/lang-audit.php --ci
docker exec virtusphere-v2-webapp-php-1 php /var/www/html/lib/migrate.php --check
curl -fsS http://127.0.0.1:8021/portal/health.php
```

Also lint every changed PHP entry point in the PHP container. For broader confidence, lint all first-party PHP files while excluding `vendor/` and `logs/`.

See `docs/QA.md` for the full container-first QA baseline, hook scan, `/tests/` exposure check and Composer vendor policy.

When testing deploy-worker failure handling, a failed probe should leave the job in `failed`, write at least one `deploy_job_logs` row and set `deploy_jobs.last_error`. Clean up any probe rows afterwards.

## Portal validation UX

`Docker/WebAPI/lib/forms.php` provides session-backed sticky-form helpers. On a `ValidationException` the POST handler stashes submitted values and per-field errors, redirects, and the next render consumes the stash exactly once. `_csrf`, `password` and `secret` fields are never stashed. The helpers back the OS, VLAN, package, credential, user, mission and settings forms; the VM editor renders inline errors on its main fields plus an aggregated list for interface/disk rows. `portal/logs.php` groups the audit log into three server-rendered tabs (access & system, resources, deployment) defined by `VIRTUSPHERE_LOG_TABS` in `lib/constants.php`, so the protocol is not one undifferentiated table; each tab scopes a `deploy_logs.category IN (...)` filter and the category dropdown is limited to that tab's categories. Within a tab it adds a full-text search over message/user, an exact IP filter (LIKE wildcards are escaped) and a per-category filter; results paginate at 50 rows per page instead of a single bounded dump. Every `audit()`/`addLog()` call site now passes one of the `VIRTUSPHERE_LOG_CATEGORY_*` constants from `lib/constants.php` (e.g. `users`, `vms`, `deploy`, `legacy_api`, `system`) so log entries can be filtered/grouped by the module that wrote them.

Remaining frontend work is purely visual (navigation, settings styling, form layout); those files can still be iterated separately without touching the validation flow.
