# ADR-0046: Open ESXi access files lock their Ansible credential

Date: 2026-10-13
Status: Accepted (K21-F), implemented. Open: the Astra review of the implementation, the lab items named under Consequences, and K21-T1.

## Context

ADR-0041, amendment K21, makes the ESXi access file `accounts.yml` a file that never outlives its job. It is removed at every job end, and a removal the host did not confirm leaves a row in `deploy_remote_secret_files` with the target the file went to (host, effective SSH port, pinned host key). The next contact with the same Ansible credential and the same target removes it. The file carries the ESXi password, so every open row is a standing exposure.

That amendment left three gaps, all named as limits there:

- A credential that was pointed at another host or deleted keeps its rows. No contact reaches them any more, and nothing told an operator.
- The credential itself was the only way to reach the file. Deleting it or changing its host, port or pinned key removed the means to remove the file.
- A row whose job or test never returns is invisible until somebody reads the worker log.

The user decided on 12 and 13 October 2026 how to close these gaps (decisions 1 to 6 of the K21-F package). Changing a credential target is a change that can lose the only path to a file on a remote host, so it is a security decision and is recorded here.

## Decision

**1. The fence.** While any row of an Ansible credential is open, the credential cannot be deleted, and its target cannot change. The target is the type, the host, the effective SSH port and the pinned host key. Every open row counts, whatever its age, lease or job. A row of another credential does not block this one.

- `repo_remote_secret_fence_credential_change()` (`lib/repo/deploy_remote_secret_fence.php`) is called in the writer's own transaction, after `repo_deploy_create_fence_credential_change()`. Its callers are the credential update and delete (`lib/repo/credentials.php`) and the host-identity confirmation (`lib/repo/credential_host_identity.php`). The lock order is job, then credential, then rows.
- A password, username or name change on the same target stays allowed.
- Confirming the first host key, or a different key, is refused while rows are open. Confirming the pinned key again is allowed.
- The refusal is a `ValidationException` that names the count, the host, the port and the oldest row, carries no secret, and links to the credential row (`credentials.php#remote-secret-<id>`) for users with `credentials.manage`.
- `RemoteSecretFenceContractTest` derives the writers from the source and fails on a writer without the call.

**2. The way out, with a reason.** `repo_remote_secret_acknowledge()` closes every open row of one credential (or of a deleted credential, `credential_id` NULL) on one host and port, in one `repo_transaction()` with its audit row `deploy.remote_secret_acknowledged`. The audit context carries `count`, `host`, `port`, `oldest`, `reason` and `credential_id`; a deleted credential is addressed by 0. The operator must give a reason of at least 10 characters after trimming. The stored reason is bounded to 256 bytes, the limit of the audit registry. Leased rows and rows of active jobs are not closed. Zero closed rows write no audit row. The operator confirms "removed by hand or host out of service" in the browser, and the server checks the reason again.

The acknowledgement is the operator's statement, not a fact the system established. The UI says so and recommends changing the ESXi password.

**3. The contact removes the rest.** A passed full test of an Ansible credential, manual or scheduled, runs the same sweep owner as the job contacts (`deploy_remote_secret_sweep()`) after its verdict and outside any transaction. A failed test never sweeps. The sweep has a time budget:

- The budget is `VIRTUSPHERE_REMOTE_SECRET_TEST_SWEEP_BUDGET_SECONDS` (60 s), reduced so that the sweep ends before the path's limit minus a 30 s margin. The limits are 300 s for the manual request (`VIRTUSPHERE_ANSIBLE_TEST_WEB_LIMIT_SECONDS`, the nginx read timeout) and `VIRTUSPHERE_ANSIBLE_TEST_TOTAL_TIMEOUT_SECONDS` (600 s) for the scheduled test worker.
- The `expired` seam stops the sweep before a candidate starts. The `stopped` seam records an aborted sweep. Rows still open stay open for the next contact.
- The sweep never changes the verdict of the test. Its outcome is shown as an information flash, and the scheduled worker prints it on its line of output.

**4. The notice.** `repo_remote_secret_notice_groups()` (`lib/repo/deploy_remote_secret_notice.php`) reports open rows that are due. A row is due when:

- it is orphaned (its credential is deleted), or moved (its target no longer matches the credential): at once, or
- it is overdue (uploaded more than `VIRTUSPHERE_REMOTE_SECRET_NOTICE_AFTER_SECONDS`, 24 h, ago).

Rows under a running lease and rows of an active job are not counted. The groups are per credential and target. Each group carries its complete count, the oldest finding, the host and the reason, and they are sorted orphaned first, then moved, then overdue. The notice renders above the System status overview, with one acknowledgement form per group for `credentials.manage`. It has no traffic light and renders nothing when no group is due.

**5. The host timer, as an addition and not as the lock.** On the Ubuntu host, `Ansible/host-cleanup/` ships `virtusphere-secret-cleanup.sh`, a service and a timer, and `install-secret-cleanup.sh`, which an administrator runs once as root from the offline bundle with the Ansible account name. The script removes only a regular file named `accounts.yml` directly inside a `/tmp/virtusphere-job-*` directory, which belongs to the running account, is older than 24 h, and whose job directory `flock -n` can lock. It follows no link. It never reads the content of the file. The service runs as the Ansible account with `PrivateTmp=no`. The timer runs once after boot and then hourly, without catch-up. The installer is idempotent and needs no network and no package. It does not use systemd-tmpfiles and does not change lingering.

The timer writes no row. An existing row stays, and the next contact with the same credential and target finds the file gone (`absent`) and closes the row through the existing path.

The preflight reads whether the timer is enabled through a last component `secret-cleanup`, which can never fail the chain. A missing timer is a hint in the job log and in the test flash. It never changes the traffic light.

## Consequences

- Deleting an Ansible credential or changing its target now needs its rows closed first, either by a contact or by a logged acknowledgement. Rows that predate this ADR and belong to an already deleted credential show up as orphaned on the notice at once. They stay until somebody acknowledges them, even when the host timer has already removed their file, because the timer writes no row.
- A target change for a credential with open rows has two ways out: a job or test that still reaches the old target removes the rows, or an operator confirms the removal by hand with a reason.
- The 24 h age is the protection that carries the host timer. The `flock -n` only excludes other runs of the script. The job commands do not lock their job directories yet, so a job that runs longer than 24 h is not protected by the lock. Until the lock round follows, the age is the only guarantee. Do not read the lock as one.
- The window between the lease recheck and the removal of a status query is still open. It closes with the same lock round.
- The mapping of `systemctl is-enabled` answers to `enabled`, `missing` and `unknown` is proven with a fake systemctl in the containerised probe, because the test containers have no systemd. The output of Ubuntu 24.04 and the unit directives (`User=`, `PrivateTmp=no`, `NoNewPrivileges`, `RestrictAddressFamilies=AF_UNIX`) are not executed by any test. They are the open lab item.
- The reason text is the operator's own sentence and is therefore bounded by truncation, not refused. This is an exception to the rule for object ids (R20).

## Alternatives

- **systemd-tmpfiles rule.** Rejected. It deletes by age alone, cannot test the job lock or the owner in the way the script does, and was excluded by decision 5.
- **A user timer.** Rejected. It needs lingering for the account, which changes the host's login behaviour, and it does not survive a missing user session without that change.
- **A user crontab.** Possible for the removal itself. Rejected because it has no unit directives, no journal entry of its own, and no clean status for the preflight to read.
- **A hint only, without the fence.** Rejected. Deleting or re-targeting the credential would still silently remove the only path to the file, and the hint would tell the operator only after the fact.
- **A removal from inside the portal only.** Rejected. A host whose credential is deleted cannot be reached from the portal at all, which is the case that needs a removal.

## Verification

The tests below exist in the working tree. The integration tests need the QA database and run in the QA lane.

- Fence and acknowledgement, integration: `RemoteSecretFenceTest`, `RemoteSecretAcknowledgeActionTest`, `RemoteSecretNoticeTest`, `AnsibleFullTestSweepTest`.
- Unit: `RemoteSecretNoticeRenderTest`, `AnsibleFullTestSweepBudgetTest`, `AnsibleFullTestNoticeTest`, `AnsiblePreflightSecretCleanupTest`.
- Static: `RemoteSecretFenceContractTest`, `HostSecretCleanupContractTest`, `AnsibleTestWebLimitContractTest`, `RemoteSecretUploadContractTest`, and the earlier `K21RemoteSecretCleanupTest`.
- Gate `host-secret-cleanup`: `tests/host-cleanup/host-cleanup-probe.sh` in the digest-pinned image, with a control case for each rule.

Open before this ADR is closed:

- The Astra review of the implementation, as the user asked for after the change.
- The lab probe on Ubuntu 24.04 for the `systemctl` answers and the unit directives.
- The lock round: `flock` in every host command, which waits for the lab probe the user is to run.
- K21-T1, which stays open in the package register.
