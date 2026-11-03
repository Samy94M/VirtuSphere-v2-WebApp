# VM and mission editor conflicts

VM and mission editors round-trip `edit_version`, a decimal counter independent
of `updated_at`. Missing, malformed and stale versions are rejected under the
existing mission/VM locks before the editor changes the row, child rows or
hostname claims.

After a refused save both editors render the version the save was submitted
with (`form_old()`), not the row's current one, so every further ordinary save
fails until the operator either chooses "Continue with my input" or reloads on
purpose. The conflict message names both ways and says that a reload discards
the input.

Above the form both editors then show a comparison: per field
the operator's input and the value saved now, only for fields that differ after
the save path's normalization. Network cards, disks and packages appear as
counts only. The field lists are the save path's own
(`vm_edit_submitted_values()`, `mission_details_submitted_changes()`); the pure
owner is `lib/edit_conflict.php`, the panel `lib/edit_conflict_view.php`. There
is no three-way view: the version the operator started from is not sent.

"Continue with my input" posts the form with the version the comparison showed
and saves nothing; only the next ordinary save writes, and it stores the values
from the "Your input" column. If someone saves in between, that save fails
again and shows a new comparison, so no save happens without a deliberate step.

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
