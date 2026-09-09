# VM and mission editor conflicts

VM and mission editors round-trip `edit_version`, a decimal counter independent
of `updated_at`. Missing, malformed and stale versions are rejected under the
existing mission/VM locks before the editor changes the row, child rows or
hostname claims. Reload the page and re-enter the change after a conflict.
Forms opened before this contract was installed must also be reloaded.

Migration `0053_edit_versions` adds the counters; the fresh schema contains the
same definitions. Configuration writes advance their version through the
repository, even within the same database second. Rollback rolls the version
back with the data. Display timestamps, runtime lifecycle observations and
machine rollout revisions keep their separate meanings. The counter describes
the editable configuration, not every UPDATE issued by a runtime callback.

`repo_save_vm()` and `repo_update_mission_checked()` intentionally retain the
empty-string expectation for legacy callers without a form. Portal callers
explicitly pass `requireVersion: true`, which forbids that opt-out on edits.
Nonempty expectations must be versions; old datetime values are not accepted.
Legacy VM bundle writes, VLAN reassignment and package reassignment invalidate
open editor snapshots as part of their transaction. Clones/imports receive their
own counter; it is not a transfer field or a caller-controlled runtime value.
