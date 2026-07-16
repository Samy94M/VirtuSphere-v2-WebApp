# ADR-0017: Backup and Restore

Date: 2026-07-07
Status: Accepted

## Context

VirtuSphere runs a production MySQL on the LAN holding missions, VMs, users and libsodium-encrypted credentials, but had no backup or disaster-recovery story. A host failure or a bad migration would have been unrecoverable. The project must stay air-gap friendly, so any backup/restore tooling has to work with images already present in the stack and must not depend on external services.

A dump that has never been restored is not a proven backup.

## Decision

Add two shell scripts and an operations runbook:

- `scripts/backup.sh` — one run produces `db-<ts>.sql.gz` (full `mysqldump --all-databases --routines --events --triggers --single-transaction`) and `config-<ts>.tar.gz` (`.env`, `docker-compose.yml`, nginx config/SSL) under `Docker/backups/` (gitignored). The DB password is read from the container environment, never passed on the host command line. The dump is validated (minimum size, gzip integrity) and retention keeps the newest runs of each of the two files independently (`KEEP=14` in the script is the SSoT for that count), so a daily schedule yields roughly 14 days of history.
- `scripts/restore_test.sh` — restores the newest dump into a throwaway `mysql:8.4` container (same image as the stack), asserts the application tables including `deploy_vms` are present, then removes the container. The running stack is never touched.
- `docs/operations/backup.md` documents scheduling (cron on the Docker host), the offsite-pull recommendation, the restore-proof cadence and the real disaster-recovery procedure.

The restore proof is a required item in `PRE-SHIP-CHECKLIST.md` before releases and is expected at least monthly and after each schema milestone.

## Consequences

The stack is recoverable, and recovery is exercised rather than assumed. Backups contain secrets (`.env`, encrypted credentials, full data), so `Docker/backups/` must live on an access-restricted target and be pulled to a second host rather than pushed from the app host. The MySQL data volume itself is not backed up directly; it is reconstructed from the dump. Point-in-time/binlog recovery is out of scope for this LAN deployment; the granularity is the backup interval.

## Amendment 1 (2026-07-16, AP6/E5): one canonical path, a real drill

- `scripts/backup.sh` and `scripts/restore_test.sh` are the ONLY backup/restore entry points. The parallel `Docker/scripts/backup.sh` and `Docker/scripts/restore.sh` (no dump validation, no retention, no status channel; restore overwrote `.env` and SSL material of the RUNNING stack unverified) are retired as hard-failing pointer scripts. `Docker/scripts/setup.sh` stays untouched: it is the documented installation path, not a backup tool.
- Every backup run additionally writes `manifest-<ts>.sha256` covering both archives. A backup without a verifiable manifest fails the drill.
- `scripts/restore_test.sh` is a full drill, not a table count: manifest hashes, file permissions of `.env`/SSL keys inside the config archive, import into an isolated throwaway MySQL, dump-vs-restore table count, migrations to `pending=0`, schema fingerprint against the fresh `struktur.sql`, FK/business invariants and row counts, credential decryption with the backed-up `APP_KEY` plus the expected failure with a wrong key, and an app smoke against the restored data (health, portal login with a drill-owned admin, machine-API rejection of an invalid token). Everything runs in a drill-owned Docker network and is removed by trap; the running stack is never touched. The DB-side checks live in `Docker/WebAPI/tests/tools/restore-drill-probe.php`.
- The drill is the `restore-drill` gate of the Release lane in `scripts/check.ps1` (ADR-0031).
