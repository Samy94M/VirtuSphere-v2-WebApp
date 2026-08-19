# ADR-0021: Backup Status Channel and Mission Transfer Format

Date: 2026-07-09
Status: Accepted

## Context

Backups run as a host cron (`scripts/backup.sh`, ADR-0017) and are invisible to the portal: an admin only learns a backup failed by reading a log on the host. At the same time there was no supported way to move a single mission between environments (lab to production, or between customer sites) without hand-copying rows.

Two needs, one package:

1. Surface backup health in the portal without giving the web tier access to the dumps. The dumps and the config tar contain secrets (`.env` with the DB root password, encrypted credentials), so the PHP container must never be able to read them.
2. A portable, reviewable mission export/import that is explicitly *not* a backup/restore path and never carries identity or MAC state across environments.

## Decision

**Backup status channel.** `backup.sh` appends one JSON line per run to `Docker/backups/status/backup-status.jsonl` (`ts` as Unix epoch, `status`, sizes, `duration_s`, `keep`, `disk_free_pct/bytes`, `error`), truncated to the newest 90 lines, guarded by `flock`. Only this `status/` subdirectory is bind-mounted read-only into the PHP container (`/var/backups/virtusphere-status:ro`) — never the dumps or the config tar. A single reader (`lib/backup_status.php`) derives the state `ok | failed | stale | disk_low | unknown` (severity `failed > stale > disk_low`) and feeds the settings card, an admin-only dashboard banner and an informative `backup` field on `health.php`. The reader is fail-soft: a missing mount, empty file or half-written trailing line yields `unknown`, never a fatal. There is deliberately **no web restore and no dump download**; disaster recovery stays the documented CLI path.

**Mission transfer format.** `lib/mission_transfer.php` exports one mission as versioned JSON (`format_version` constant shared by export and import): mission fields, VMs, interfaces **without MAC**, disks and package references by name. Import re-creates the mission under a new name in one transaction, reusing the portal's own validators and write helpers (`repo_validate_vm_payload`, `repo_replace_interfaces/disks/packages`); VMs come in fresh (Registered / not_ready) exactly like a template clone. Import is a two-step flow — a dry-run preview report held server-side in the session with a 10-minute TTL, then a confirmed write. The report carries counts and the export timestamp, resolved vs missing packages, missing VLANs, global VM-name conflicts (with the mission that holds each name), VM names repeated inside the file, an invalid target name, and every field the portal's own validators reject in the mission block or in a VM. Everything except a missing package **blocks** the import (SCCM device names are global; missing packages are a warning and are skipped). A problem is a report field, never an exception: the preview must stay renderable for any document that can be read at all, because a preview that cannot render says nothing at all. Only an unreadable document (wrong `format_version`, malformed structure) throws. The report carries two block flags on purpose: `blocked` is the single predicate the write refuses on, `blocked_in_file` is the subset the confirm form cannot fix and is the only thing that disables its button. A name problem is deliberately outside that subset, because the field that fixes it sits under the message and the confirm re-runs the whole analysis against the name actually typed; disabling there would state an instruction the operator cannot carry out. The write refuses a blocked report again inside `mission_import()`: the preview is a report, not the security boundary. Upload is capped at 2 MB with a bounded JSON depth, and `Docker/php/conf.d/zz-virtusphere.ini` keeps `upload_max_filesize`/`post_max_size` above that cap so the app's own size message is the one the operator sees. CSV list export (`lib/portal_export.php`) is a convenience only: semicolon delimiter, UTF-8 BOM, formula-injection guard.

## Consequences

- Backup health is visible where admins already work, with zero new secret exposure: the web tier sees only metadata.
- The status channel is metadata and is intentionally not itself backed up; the inventory cache is self-healing and also excluded (see the coverage table in `docs/operations/backup.md`).
- Export/import is a transfer tool, not a recovery tool. It never writes primary keys, MAC addresses, MECM ids or workflow state from a file, so it cannot resurrect a decommissioned VM's identity.
- New compose mount (`Docker/backups/status`) must exist in every environment; deploy docs updated. The directory is created by `backup.sh`; before the first run the card shows `unknown`, which is correct.
