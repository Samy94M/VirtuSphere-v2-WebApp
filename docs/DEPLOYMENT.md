# Deployment

## Ubuntu Ansible Host

VirtuSphere keeps Ansible outside the PHP/nginx stack. The browser queues a deploy job in the portal, the CLI deploy worker uploads generated YAML and playbooks over SFTP, then the Ubuntu Ansible host runs `ansible-playbook` against ESXi.

Required on the Ubuntu host:

- A dedicated SSH user reachable from the `deploy-worker` container.
- `ansible-playbook` and `ansible-doc` in the SSH user's `PATH`, from `ansible-core` 2.19 or newer.
- `python3` with `pyvmomi` 8.0.3.0.1 or newer, importable as `pyVim` and `pyVmomi`.
- `python3` with `requests`, importable in the **same** interpreter as `pyvmomi`. This is not optional and `pyvmomi` does not pull it in: every `community.vmware` module the playbooks call imports the collection's `vmware_rest_client`, which aborts with `Failed to import the required Python library (requests)` before it looks at a single argument. Without it the inventory pull, the VM creation and both power playbooks all fail on a host that meets every other requirement here, and six of the seven inventory queries run under `ignore_errors`, so they report "0 datastores" rather than an error. The QA image proves the pair on every run (`check.ps1 -Gate ansible-module-contract`).
- The `community.vmware` collection, installed from the repository's pin **before** air-gapped operation:
  `ansible-galaxy collection install -r Ansible/requirements.yml`. That file is the single source of truth for the version. For an air-gapped host, run `ansible-galaxy collection download -r Ansible/requirements.yml -p ./offline` once on a connected machine and install from that directory. On a fully offline host `ansible-core` and `pyvmomi` come the same way: fetch their pip wheels on a connected machine and `pip install --no-index` them into the SSH user's Python environment. The 2.19 floor is not a preference; it is the `requires_ansible` the 6.2.0 pin enforces, and an older core makes the collection refuse to load.
- Network access from Ubuntu to ESXi and from Ubuntu back to the VirtuSphere WebAPI base URL.

### ESXi support matrix

| Dimension | Supported | Notes |
|---|---|---|
| ESXi / vSphere | 7.0, 8.0 | The range `community.vmware` 6.x targets. Older releases are out of general support. The 6.x line itself reaches end of life in November 2027; `Ansible/requirements.yml` is the single source for that date and for the one module the pin already marks deprecated. |
| ESXi for **creating** VMs | 8.0 Update 2 or newer | `createVMs-ESXi_playbook.yml` creates VMs at hardware version 21, and Broadcom documents vmx-21 as not compatible with ESXi releases before 8.0 Update 2. On an older host the create step fails hard, so this floor is narrower than the row above on purpose: inventory, autostart and power-cycling work from 7.0, only creation does not. The retired desktop client (ADR-0035) shipped the same 21, which is why nobody met this: it proves the hosts in the field are already 8.0 U2 or newer, not that 7.0 works. `check-doc-semantics.sh` (rule 15) fails the build if the playbook's version and this floor drift apart. |
| Licence | any licensed edition (Standard, Enterprise Plus, VCF) | The write API is what deploy and autostart need. |
| Free licence (incl. 8.0 U3e) | inventory only | Broadcom's free hypervisor exposes a read-only API. Inventory pulls work; creating VMs and writing the autostart policy do not. The portal reads the licence from the host and warns before a job runs. |
| Host in a vSphere HA cluster | deploy yes, autostart no | ESXi disables autostart on HA cluster members; the HA restart priority owns startup there. |
| Standalone host | full support | `datacenter_name` must resolve to `ha-datacenter`; the portal derives it from the credential's inventory when the mission leaves it empty. |
| vCenter (`api_type = VirtualCenter`) | deploy **no**, partial read-only inventory | Production support is standalone ESXi only (decision of 2026-07-26); vCenter credentials remain a partial read-only inventory source. The pull reads datacenters, datastores and distributed portgroups of the **first datacenter only** (`inventoryESXi_playbook.yml` limitation) and addresses VMs by name under folder `/`; standard portgroups and host capacity come from the host itself and stay empty. Register the ESXi host for a complete inventory and for deployments. |

The portal reports the host's product, version, licence and HA/maintenance state in System status after the first successful inventory pull (ADR-0023, ADR-0025). The URL remains `portal/system_status.php` for compatibility.

Runtime setup:

- Provide `APP_PUBLIC_BASE_URL` in `.env`, or open `Settings -> Deployment` and store an API base URL in the portal. The URL field and **Save** button form one row; examples and the host-side connection check are available under **Examples and connection test**. The non-empty portal value (`deploy_settings.api_base_url` / `VIRTUSPHERE_SETTING_API_BASE_URL`) takes precedence. **Reset to .env fallback** is only shown for a stored portal value and removes that override. With neither source configured, deploy jobs are blocked. The read-only **Effective deploy configuration** overview shows the effective URL and its source.
- Store one `esxi` credential for the ESXi API. The host may be a bare hostname/IP or an `http(s)` URL without path, credentials, query or fragment; deploy artifacts normalize it to `esxi_hostname` plus `esxi_port`.
- Store one `ansible` credential for the Ubuntu SSH account. Use only the Ansible host/IP in the host field, not a URL. This credential is the SSH/SFTP login; it is separate from the callback URL and is selected per deploy job.
- Use `Credentials -> Test` on the Ansible credential, or **Run full test now** in its System status row. Both use the same handler and run SSH login, the host toolchain preflight (`ansible-playbook`, `python3`, `pyvmomi`, `requests`, `community.vmware`), a real SFTP write into `/tmp`, and, when an API base URL is set, a portal-reachability probe (`health.php`). A failure names the component that broke, and the manual full-test result persists on both pages. Once older than `VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS`, a passed/restricted result reads "test outdated": not a reported failure, but no current full proof. The last mission job a worker actually processed is displayed separately from `deploy_jobs`, including outcome, time and direct job log; it proves only the mode that ran and never refreshes the full test. Only a job a worker claimed at least once (`attempts > 0`) qualifies, so a job cancelled out of the queue cannot pose as executed evidence. Editing the credential discards the full-test result (back to "not tested"), because it proved the old host, not the new one. The collection probe targets `vmware_host_auto_start`, so it also catches a collection that is present but older than the pinned floor. The deploy worker's own preflight reports the failed component in the job error the same way (`failed at: pyvmomi`), and the existing `credentials` audit trail carries each manual test without a duplicate log channel.

SSH and SFTP have separate, typed failure boundaries. `SshTransportBudgetExceeded` means only a VirtuSphere-owned idle, command-total, SFTP-operation or SFTP-total budget. `SftpTransportFailed` means the SFTP subsystem or a remote file operation failed after the SSH/preflight path worked. `SshTransportConfigurationException` is reserved for local prerequisites such as a missing phpseclib class, empty host/user fields or a vanished local artifact directory. The SFTP total uses a monotonic clock starting after login. Before every `is_dir`, `mkdir`, upload, probe write and probe delete, phpseclib receives the smaller of the operation budget and the remaining total; the same total is checked after every operation and before success. A `false` or exception is classified through `isTimeout()` before disconnect, and upload/probe each disconnect exactly once in their outer `finally`. Logger and heartbeat callbacks stay outside that guard, so a database fault cannot be relabelled as SFTP. The credential test maps the exact budget type to `ansible_timeout`, local prerequisites to `config` and other SFTP failures to its SFTP result; identical text in an ordinary `RuntimeException` is not a budget.
- Queueing a deploy job now preflights the session user, mission deploy fields, VM presence and ESXi/Ansible credential completeness before the worker can claim the job.

The worker never writes plaintext secrets to deploy logs. It generates `accounts.yml` only inside the temporary job directory, uploads it to the Ansible host, and removes the local job directory after processing.

A database outage while a job runs is survivable and does not fail the job. The playbook executes on the Ansible host, so the job log and the job heartbeat are a side channel, and ending the SSH stream because that side channel broke would throw away the remote exit code, which is the only remaining evidence about the VMs already created. Every write of a running job therefore goes through a channel that owns the live connection: it announces the outage exactly once on STDERR (redacted), spools finished log lines in a bounded FIFO, and attempts at most one backed-off reconnect per liveness tick. After a reconnect it re-checks ownership, refreshes the job heartbeat and replays the spool in order, reporting any lines the buffer limit dropped in its own SYSTEM line. A job whose ownership changed meanwhile stops without publishing a result, so an established terminal state is never overwritten by this worker's older view. When the remote command ends during the outage, the loop worker waits bounded for the database and finalizes exactly once; `deploy_worker.php --once` stays bounded and states on STDERR that the outcome could not be persisted and that the job stays claimed. Both effects are visible in `docker compose logs deploy-worker maintenance-worker`.

### Generated vars and the playbook variable contract

The playbooks under `Ansible/` are not self-contained: they read variables that the WebAPI generates at deploy time in `Docker/WebAPI/lib/ansible.php`. The playbook `{{ ... }}` names and the generated keys must stay in lockstep; renaming one side breaks the deploy silently, so change both together.

- `accounts.yml` (from `ansible_accounts_yml`): `esxi_hostname`, `esxi_port`, `esxi_username`, `esxi_password`, `ansible_username`, `apiUrl`. `esxi_port` is always emitted; `credential_esxi_normalize` defaults it to `443` (`VIRTUSPHERE_CREDENTIAL_PORT_ESXI_HTTPS`) when the ESXi credential has no explicit port, so `port: "{{ esxi_port | int }}"` is always defined.
- `serverlist.yml` (from `ansible_serverlist_yml`): `vm_configurations`, a list whose items carry `vm_name`, `vm_moid`, `vm_instance_uuid`, `guest_id`, `datacenter_name`, `datastore_name`, `disks` (a list), `network` (a list of `name` + `device_type`, where `device_type` is one of `vmxnet3`/`e1000`/`e1000e` from `VIRTUSPHERE_INTERFACE_TYPES`, enum-validated on write so it always matches what `vmware_guest` accepts), `memory`, `vcpus`, `hotadd_cpu`/`hotadd_memory` (bool, from the VM `cpu_hotplug`/`ram_hotplug` flags; the create playbook maps them to `hardware.hotadd_cpu`/`hotadd_memory`), `needs_mac` (bool) and `autostart` (a block of `enabled`, `start_delay`, `stop_delay`; a delay of `-1` means "inherit the mission default" and is passed through to `vmware_host_auto_start` unchanged). It also emits the top-level `PowerCycleWaitSeconds`, `StartWaitSeconds` (both in seconds, both portal fields), the fixed `CreateSettleSeconds` and `identity_unbound_allowed`; the last value is true only inside `full`, where create in the same sequential run has just proved an unbound name absent. Every playbook pause reads one of the three timing values, and none of them may be configured up to the SSH idle budget, which `AnsiblePauseBudgetContractTest` enforces. Plus a `mission_configuration.autostart` block (`enabled`, `start_delay`, `stop_delay`, `stop_action`, `wait_for_heartbeat`) which becomes the host's `system_defaults`. The create playbook maps these into `vmware_guest` `disk`/`networks`/`hardware`; when connecting straight to a standalone ESXi host, `datacenter_name` must resolve to `ha-datacenter`. When a deploy job selects specific VMs, `vm_configurations` contains only that subset; an empty selection means the whole mission.
- Only `createVMs-ESXi_playbook.yml` defines a `hardware:` block (VM sizing, firmware, hardware version); `startVMs-` powers on existing VMs via `vmware_guest_powerstate` and has no hardware settings. It pauses `StartWaitSeconds` first, so MECM can take the freshly registered devices into their collections before the VM PXE-boots.

### Deploy modes and the MAC-generation power-cycle

Modes map to playbooks in `ansible_playbooks_for_mode()`: `create`, `export`, `start`, `powercycle` (runs `powercycleVMs-ESXi_playbook.yml` then the export playbook), `autostart` (writes the ESXi autostart policy, ADR-0025) and `full` (`create → powercycle → export → start`, plus `autostart` when the mission enabled it). The power-cycle exists because ESXi may only assign a NIC its MAC once the VM has been powered on, and the MAC export otherwise reads nothing. `powercycleVMs-ESXi_playbook.yml` briefly powers a VM on, waits `PowerCycleWaitSeconds` (portal field, default 5s, clamped 1–300s), then hard powers it off (`state: powered-off`, `force: yes`; freshly created VMs have no guest OS/tools, so a graceful shutdown would hang).

The deploy form's label map (`virtusphere_deploy_mode_labels()`) is the source of truth for which modes an operator may ask for. `inventory` is a *system* mode: it has no label, cannot be posted, and `repo_create_system_job()` refuses anything else. The read side (`deploy_job_normalize_mode()`) still accepts it, because the worker reads back a queued inventory job's payload. The location gate follows the same shape: `autostart` reads neither `datacenter_name` nor `datastore_name`, so a mission without a datastore can still have its autostart policy written, while every other mode is refused. Staggering is refused for `autostart` in the repository as well as on the page, because a config write has nothing to spread over time.

### VM identity, collision block and adoption

The name is an address, not an identity. VirtuSphere binds a portal VM to vSphere's `instance_uuid`; `vm_moid` is retained as the current managed-object handle but may legitimately change when the same VM is unregistered and registered again. The inventory mirror supplies name, UUID and MOID. A matching UUID with a changed MOID therefore refreshes the handle; a different UUID means a different VM.

Queueing is blocked when the selected credential's fresh VM inventory already contains a namesake that is not bound to the portal row. Create repeats this check against the live host immediately before `vmware_guest state: present`, so the module cannot silently align an already-known foreign VM's hardware. Power-cycle, start and autostart validate the returned name and UUID before their first write. Export preserves its per-VM partial-result contract: a mismatch becomes a failed item before `upload_mac_list.py` calls the portal, and the callback independently rejects an identity contradiction before writing MACs, lifecycle or MECM state.

An operator may explicitly choose **Adopt identity** on the deploy page after comparing the VM in the ESXi Host Client. That confirmed action copies only MOID and instance UUID from inventory, under the mission lock and only without an active job. It does not change VM hardware or power state and does not queue a deploy. This is the only route for taking ownership of a pre-existing namesake; do not use it for a foreign VM. A standalone `powercycle`, `export`, `start` or `autostart` mode requires a stored UUID. Only `full` may carry an unbound VM after its own create step, because that same sequential run proved the name absent before creating it; the export callback then persists the identity.

`full` appends the autostart step only for a mission whose `autostart_enabled` is set. A full deploy of any other mission must not touch the host's autostart manager: one host can carry VMs of several missions, and writing `system_defaults` from a mission that never asked for autostart would overwrite the policy of the missions that did. The explicit `autostart` mode always runs, including for a disabled mission, because setting every one of its VMs to `start_action: none` is how a policy is withdrawn. Before the step runs, the worker checks the cached capability facts of the target credential: a fresh free-licence fact aborts the job, a fresh HA-cluster fact aborts mode `autostart` and skips the step inside `full`. Unknown or stale facts only warn, because the inventory cache never blocks a deploy (ADR-0023).

It only touches VMs whose `needs_mac` is true. `needs_mac` reflects whether the mission's **WDS-VLAN** NIC already has a MAC (`vlan == mission.wds_vlan`), so a VM whose MAC the portal already knows is left alone — and since the state-capture rework, so is every VM this run did not start itself: `vmware_guest_info` records each target's power state before any change, only targets that were `poweredOff` are powered on, and exactly that derived list is hard powered off again in an `always:` block, so an abort between power-on and power-off still cleans up. Running and suspended VMs keep their state (their MACs exist already; the export reads them without a cycle), a VM without a `needs_mac` statement is not touched at all, and a guest-info reply without a power state fails the run loudly instead of shrinking the selection. Remaining limit: a hard process kill (worker cancel that severs SSH, ansible process kill) cannot run the `always:` cleanup and may leave started VMs powered on; the selection logic itself is proven offline by the `ansible-powercycle-selection` gate. Boot order is intentionally **not** changed: PXE only completes on the NIC that receives a DHCP/PXE offer, which is the WDS-VLAN NIC, so the exported MAC matches the NIC that actually boots. Operating requirement: only the WDS VLAN may provide a PXE/DHCP responder; otherwise a different NIC could win PXE and its MAC would not match the MECM device record.

The MAC export is intentionally tolerant per VM: a failed `vmware_guest_info` item remains in `vm_infos.json` next to the successful items and is classified by the callback instead of aborting the whole export before the callback runs. `upload_mac_list.py` accepts only an HTTP 2xx response with valid JSON and an explicit `outcome` of `success`, `partial` or `failed`. These map to exit codes `0`, `20` and `21`; invalid local data, transport failure and an invalid response use `22`, `23` and `24`. The client retries exactly once after a timeout or HTTP 5xx and logs only the outcome plus bounded counters, never the response body.

The web worker patches `api_base_url`, `mission_id` and `job_id` into its private script copy. An unresolved `job_id` placeholder is omitted from the payload, which the callback since ADR-0035 rejects with 400: a mis-templated export fails loudly at the portal instead of importing unscoped. A genuine callback failure makes the export step fail instead of appearing green.

The callback accepts `{ "mission_id": 123, "job_id": 456, "results": [...] }`, `job_id` required (ADR-0035). It rejects a missing, mission-foreign or terminal job with HTTP 409 before opening its request transaction, then locks the running job again to close the worker race. The complete payload is resolved first; only a VM whose every NIC is valid is written. NICs, VM state, status history and the versioned `deploy_jobs.result_json` are one transaction, so a database/program failure cannot leave a partial callback. Repeating the same callback while the job is still `running` is idempotent; callbacks after terminal completion return 409.

The `Ansible/` copies are the web-app deploy source; the former desktop `bin/` build outputs are removed with the client (ADR-0035).

### Worker resilience

The `deploy-worker`, `maintenance-worker`, `php`, `webserver` and `mysql` services run with `restart: unless-stopped` so the stack recovers after a host reboot or a container crash without manual intervention. Every runtime service carries a healthcheck (AP8): MySQL answers `mysqladmin ping`, PHP answers the FPM FastCGI ping, nginx answers its own `/nginx-health` location, and both workers keep a liveness file fresh that `lib/worker_healthcheck.php` judges. `webserver` waits for real PHP readiness (`service_healthy`), so a cold `docker compose up -d --wait` only returns green when the whole chain accepts work. phpMyAdmin is admin tooling in the optional `tools` profile (`docker compose --profile tools up -d phpmyadmin`, loopback-only) and is not part of the runtime stack.

In `--loop` mode the worker tolerates a MySQL outage instead of exiting: it retries the initial connection with backoff (up to 30s between attempts) and, if the database drops mid-loop, reconnects through `db(true)` and continues claiming jobs. This closes the earlier failure where a slow MySQL start or a MySQL restart left the worker container dead and deploy jobs stuck in `queued` with no portal-visible error. The `--once` mode used by tooling still fails fast (three connection attempts, then a non-zero exit).

Long-running worker phases send heartbeats at least every `VIRTUSPHERE_DEPLOY_HEARTBEAT_INTERVAL_SECONDS` (30s) through phase boundaries and streamed Ansible output. At the beginning of each loop the worker reaps running jobs whose heartbeat is older than `VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS` (600s): the job is marked `failed`, its lock is cleared, a SYSTEM log line is written, and affected mission VMs are reset through the existing deploy-worker VM status path. A reaped ESXi inventory job additionally records the durable `worker` failure and its exact job id, so the System-status card cannot retain an older green result while the terminal system job disappears from the active queue. Terminal updates are guarded by `id`, `locked_by` and `running` status so a worker that lost ownership cannot overwrite a cancelled or reaped job.

### Deploy job retention

The maintenance worker prunes on its hourly retention pass:

- **`deploy_job_logs`** of jobs that have been terminal for more than `VIRTUSPHERE_DEPLOY_JOB_LOG_RETENTION_DAYS` (30). The window is measured on the **job**, not on the log row, so a job that streams for an hour never loses its opening lines and a live tail cannot race the purge. Queued and running jobs are untouchable by construction. The job row survives with its status and `last_error`; `deploy_log.php` says the output was pruned instead of showing an unexplained empty table.
- **Finished mission-less system jobs** (the ESXi inventory pulls) after `VIRTUSPHERE_SYSTEM_JOB_RETENTION_DAYS` (30), rows and all; their logs cascade. No shared list shows them once terminal, but until retention their System-status card links the exact latest completed job through `deploy_esxi_inventory_state.last_job_id`. That field is only a relationship: `deploy_jobs` and `deploy_job_logs` remain authoritative. Its `ON DELETE SET NULL` foreign key removes the link with the retained job, so an old failure renders a retention explanation instead of a dead URL. Mission jobs are kept, because the deploy page shows their history.

## Runtime logs and error references

VirtuSphere writes runtime logs into host-mounted directories so troubleshooting evidence survives container restarts:

- Application error handler log: `Docker/WebAPI/logs/error.log` maps to `/var/www/html/logs/error.log`.
- PHP engine log: `Docker/WebAPI/logs/php-error.log` maps to `/var/www/html/logs/php-error.log` through `Docker/php/conf.d/zz-virtusphere.ini`.
- Legacy repository failure log: `Docker/WebAPI/logs/fail.log`.
- nginx access/error logs: `Docker/logs/nginx/access.log` and `Docker/logs/nginx/error.log` map to `/var/log/nginx` in the webserver container.

The global handler in `Docker/WebAPI/lib/errors.php` is installed before EnvBoot completes. Web errors render a full internal error page with a short reference ID, while CLI errors print the same reference to STDERR and exit non-zero. The handler also appends the full context and stack trace to `error.log`; when the database boot path is healthy it records an `error [ref] ...` row in `deploy_logs` for the portal log view.

The trace carries **no argument values**: `zend.exception_ignore_args = 1` in `Docker/php/conf.d/zz-virtusphere.ini`. File, line and function of every frame stay, the values do not. That is not cosmetic. The decrypted ESXi password is a positional argument of `ansible_prepare_job_artifacts()`, of its inventory twin and of `ssh_execute_command()`, and those frames throw on routine conditions (mission not found, mission is a template, no VMs), so with PHP's default setting a plaintext credential would land in this file, in worker STDERR and, under `VIRTUSPHERE_DEBUG`, in the browser. Pasting a trace into a ticket is therefore safe; if you need an argument value, add a deliberate log line rather than turning the setting off.

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
- The former desktop token API (root `access.php`, `api/login.php`) is removed (ADR-0035); both paths answer 404 by wire contract.
- `mecm_packages.php` rejects a completely empty JSON payload (`{}` or `[]`) with HTTP 400 before catalog sync can run. Missing payload types are now non-destructive: if a request contains only packages, `deploy_os` is left untouched; if it contains only task sequences, `deploy_packages` is left untouched. The absent type is logged as a warning.
- `db_importMAC.php?action=updateInterface` requires `{ "mission_id": 123, "job_id": 456, "results": [...] }` (ADR-0035). Existing response fields remain; `result_version`, `outcome`, `job_id`, `vm_results`, `counts` and bounded `errors` are additive. Per-VM failures return HTTP 200 with `success:false` and write no NIC or deployed state for that VM. Job/mission/status conflicts return 409 without writes.
- `mecm-api.php?action=getDeviceInfos&mac=...` deliberately keeps its old IP-or-MAC allowlist behavior. The legacy 403 response still echoes the client IP for compatibility in the LAN contract.
- Since ADR-0019/E3 that GET is side-effect-free and returns only the client bootstrap fields. V23 posts client readiness to `mecm_client_ack.php`; the POST uses the same IP-or-known-MAC authentication, is idempotent, and works over the default HTTP mode without CA/certificate/thumbprint configuration.
- Unhandled machine-API exceptions return the existing JSON envelope shape with generic `Interner Serverfehler`; internal details are written through `machine_api_log_warning`/fallback logging instead of being sent to clients.

## Operational preflight

Etappe 8R-O enthält außerdem den rein lesenden Standortpreflight
`Ansible/runner/virtusphere_remote_preflight.py`. Er prüft Python/Ansible,
systemd-User-Manager, Linger, cgroup v2, Besitzer/Modi, freien Speicher und die
Runner-Prüfsummen und gibt nur redigiertes JSON mit gehashtem Hostfingerprint
aus. Er verändert den Host nicht und aktiviert keinen Produktpfad. Sein
Mindestfreiplatz hat absichtlich keinen Default: Ohne einen autorisierten
8R-S-Lauf auf dem echten Ziel und den späteren Evidenzimport bleiben alle neuen
Remote-Modi `disabled`; der bestehende SSH-Pfad ändert sich dadurch nicht.

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

`/portal/health.php` returns JSON for automation: `status` (`ok`, `degraded` or `error`), `db` and the coarse `php` version. `status` is `degraded` when the application or PHP error-log directory is unwritable, or when a running deploy job has not reported for `VIRTUSPHERE_DEPLOY_STALE_AFTER_SECONDS` (the reaper's own constant, so both agree on what stale means).

**The HTTP status is 200 for `ok` and for `degraded`; only a database/bootstrap failure answers 503** (`status: "error"`, a generic service-unavailable message, the exception in the server-side log). This endpoint is an address probe before it is a health report: the MECM installer, every client script's `Resolve-VsApi` and the Ansible host's deploy preflight all ask it "are you there", and PowerShell 5.1's `Invoke-RestMethod` throws on a 5xx while discarding the body. While `degraded` answered 503, a single stale deploy job made the portal look unreachable to the entire machine chain, and the PHPUnit integration suite skipped itself. The client side holds the same rule independently: a status code of any kind proves the address, only a transport error moves on to the next candidate (`Test-VsApiAnswered`).

The body deliberately carries nothing else. Job counts, heartbeat timestamps and the backup age used to be here, unauthenticated, for any host in the deploy VLAN; they are read in the portal instead.

## Backend validation and login throttling

Shared validation lives in `Docker/WebAPI/lib/validate.php`. VM saves now validate VM name, DNS FQDN domains, OS, RAM/CPU ranges, disk rows, static interface IP/subnet/gateway, DNS and MAC formats before repository writes. The portal VM editor and Ansible artifact generation share the `VIRTUSPHERE_VM_DEFAULTS` disk contract: missing/new disk rows default to a `System` disk with 50 GB and type `eagerzeroedthick`; allowed disk types stay `thin`, `thick` and `eagerzeroedthick`. Those three tokens are the wire values `vmware_guest` expects and stay unchanged in payloads and in the create/update audit lines; everything an operator reads goes through `disk_type_label()` instead. The type applies at creation only: changing it converts neither an existing VM nor a disk that already exists, and eager zeroing can lengthen the creation of large or numerous disks enough to reach the transport's time budgets. What the array actually provisions is not read back, so the portal reports the requested type and never claims a realized end state. Interface subnet masks accept IPv4 masks and CIDR-style `/0` through `/30` values. VM edits still preserve existing interface MACs when no new MAC is submitted.

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
- Contract preservation: machine API status strings, `updated`, `mecm_id`, `mecm-api.php?action=getDeviceInfos&mac=...` IP-or-MAC allowlist behavior and the legacy 403 IP echo are intentionally preserved. ADR-0019/E3 explicitly retired `getMissionName`, narrowed `getDeviceInfos`, and moved its lifecycle side effect to the POST-only ACK. The desktop token API is since removed (ADR-0035).

Not claimed as complete by this audit:

- Pure visual frontend/design work.
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
