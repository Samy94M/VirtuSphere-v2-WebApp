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
