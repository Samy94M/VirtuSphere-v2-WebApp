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

## Amendment 2 (2026-07-26): the archive must be able to start a stack

The config archive held `docker-compose.yml`, `.env` and the nginx directories. It did not hold `docker-compose.override.yml`, and the production host needs that file to come up at all: it pins the Docker subnet (without which the bridge cuts the operator's SSH session), clears the proxy environment per service, and adjusts a healthcheck. A restore from two intact archives therefore produced a stack that could not start, and the drill could not notice, because it never resolved the archived compose set.

Three consequences of record:

- The archive now includes `docker-compose.override.yml` when the host has one. It is conditional, so a dev host without an override still produces a valid archive. That the file is host-specific is the reason it belongs in the *backup* rather than in Git; it is now also `.gitignore`d, so the older sentence "everything else comes from Git" is true again.
- The drill resolves what it restored: it extracts the compose set next to the `.env` and runs `docker compose ... config --quiet`. A config archive that cannot be resolved is a finding, not a warning. The closing line names whether an override was in the archive, so a drill against a production backup is distinguishable from one against a Docker Desktop backup.
- One thing stays deliberately outside every archive and is now named as such in `docs/operations/backup.md`: the **mode of the log directories**. `0777` on `Docker/WebAPI/logs` and `Docker/logs/nginx` is host state; a tar cannot carry it onto a fresh host in a way that survives, and without it the error handler cannot write. The restore steps and the coverage table say so, and point at `docs/operations/go-live.md` step 1a where the reasoning lives.

## Amendment 3 (2026-07-27): the dump carries the application database, the .env carries the accounts

`backup.sh` dumped `--all-databases`, which includes the mysql grant tables, and
the drill connected exclusively as root while reading only `DB_NAME`/`APP_KEY`
from the archived `.env`. Two measured consequences (reproduced in a throwaway
pair of MySQL 8.4 containers): importing such a dump onto a freshly provisioned
host **replaces the grant tables**, so after the next `FLUSH PRIVILEGES` or
server restart the app user carries the archived password instead of the one in
the effective `.env` (fatal after a password rotation - the app cannot connect
while a root drill stays green), and even root itself carries the archived
password. The drill never noticed because it neither flushed nor ever connected
as the app user.

Decision:

- `backup.sh` dumps **only the application database** (`--databases
  "$MYSQL_DATABASE"` with routines/events/triggers). Database accounts are host
  state provisioned from the `.env` (`MYSQL_USER`/`MYSQL_PASSWORD` in compose),
  never restored from a dump.
- The throwaway MySQL of the drill is provisioned like production (app user
  from the archived `.env`), the import is followed by `FLUSH PRIVILEGES` (the
  restart the real restore implies), and **everything from the migrations on
  connects as the app user**. A restore after which the app cannot work is now
  a red drill, which is the sentence that was false before.
- Old `--all-databases` archives stay restorable: the drill detects them,
  switches to the archived root password when the import replaced root, runs
  the grant-repair step documented in `docs/operations/backup.md` (create/alter
  user + grant from the archived `.env`) and only then re-checks the app user.
  The repair is the documented operator step for the disaster case, proven by
  the drill rather than promised.

## Amendment 4 (2026-09-12): one bounded runtime-log stream and no unused binlog

- The six long-lived Compose services use Docker's `json-file` driver with
  `max-size=10m` and `max-file=5`. nginx sends both its HTTP and generated HTTPS
  access/error logs to stdout/stderr. The old unrotated
  `Docker/logs/nginx:/var/log/nginx` mount is removed; nginx diagnosis survives
  container restarts through Docker's retained stream and uses
  `docker compose logs webserver`.
- Persistent PHP application/error files remain under `Docker/WebAPI/logs` and
  outside the backup. Only that directory needs the documented host permission
  repair after a restore.
- There is no replication or point-in-time-recovery consumer. Compose therefore
  starts MySQL with `--skip-log-bin`; the supported recovery granularity remains
  the verified logical backup interval. The option does not delete historical
  binlog files, and operators must never remove such files directly from the
  data directory. A future PITR decision must add its own archive, retention and
  restore proof before binary logging is enabled again.

## Amendment 5 (2026-10-11, VT-E2): backups are encrypted to a public key

The original decision accepted that the config archive (with `APP_KEY`) and the
dump (with the credentials encrypted under it) lie side by side and readable,
protected only by directory permissions and a pull to a second host. Decision
VT-E2 (05.10.2026) replaces that acceptance:

- Both artifacts are encrypted, separately, to a public key whenever the portal
  holds an X.509 certificate (RSA, at least 3072 bits) in the setting
  `backup_encryption_certificate`. The portal stores only the certificate,
  refuses any private key block and never generates a key pair; the private key
  stays offline with the operator and never touches the app host.
- `scripts/backup.sh` reads the certificate from the database at every run and
  writes `db-<ts>.sql.gz.cms` and `config-<ts>.tar.gz.cms` as CMS EnvelopedData
  (`openssl cms`, AES-256-CBC, DER) through `scripts/lib/backup_crypto.sh`; the
  manifest hashes those files. Plaintext exists only as mode-0600 temporary
  files that the exit trap removes after success and failure alike. A failing
  certificate query or encryption fails the run instead of falling back to
  plaintext. Without a certificate the run stays unencrypted and records
  `encrypted:false`; System status and the settings backup card then report
  "Backups unencrypted" from one derivation (`backup_encryption_finding()`).
- Encryption gives confidentiality, not authenticity: anyone holding the
  certificate can build a valid envelope. Corruption detection stays with the
  manifest. CBC instead of GCM keeps OpenSSL 1.1 restore machines able to
  decrypt.
- `scripts/restore_test.sh` selects encrypted triplets as well, decrypts them
  into its throwaway work directory with the key named by
  `VIRTUSPHERE_BACKUP_PRIVATE_KEY`, and reports a missing key as a missing
  prerequisite (exit 2), never as a pass.
- Retention treats plaintext and encrypted runs of one artifact as one series,
  so old plaintext backups age out after `KEEP` encrypted runs; nothing is
  deleted early. Losing the private key makes every encrypted backup
  unrestorable; key generation, safekeeping, rotation and decryption are in
  `docs/operations/backup.md`.

## Amendment 6 (2026-10-12, K19 follow-up): recipient pin and orphan sweep

Two gaps of amendment 5 are closed without changing the portal or the stored
certificate (VT-E2 unchanged):

- Orphaned plaintext temp files. A run killed without its exit trap (power
  loss, OOM killer, `kill -9`) can leave `.db-*`, `.config-*` and `.cert-*`
  entries in the backup directory. The next run removes them right after it
  takes the single-run lock and reports their number. The sweep looks at depth
  one only, never follows links, and runs only under `flock`; without `flock`
  it warns and removes nothing, so a parallel run keeps its own temp files.
  Hosts that sync the backup directory must exclude dot files, and the
  operator checks the backup host after such a report.
- Recipient pin. An optional `VIRTUSPHERE_BACKUP_RECIPIENT_SHA256` in `.env`
  fixes the SHA-256 fingerprint of the certificate. It is read from `.env`
  only, never from the process environment, and normalized to the 64-character
  lower-case form. A run fails loudly when the pin does not match the stored
  certificate, when a pin is set but no certificate is stored, and when the
  value is malformed; a malformed value never counts as unset. Nothing is
  written in any of these cases. The pin is checked in step 2 (encryption-key),
  so the progress contract keeps its six units. The restore drill does not read
  the pin; `.env` and the certificate come back together on a new host. Backup
  and drill read `.env` through one shared parser (`scripts/lib/env_file.sh`),
  so both take the first matching line the same way.

The pin turns a certificate exchange into a visible decision instead of a silent
change of the recipient. It does not replace the offline private key: a pin that
names a key nobody holds makes every run fail, which is the intended loud
failure.

## Amendment 7 (2026-10-31, R1): wait for the final MySQL server, not the init server

The drill waited with `SELECT 1` as root over the socket and then imported the
dump. The image entrypoint initializes an empty data directory with a temporary
server started with `--skip-networking` on the same socket. It sets the root
password first, then creates the database and the app user, runs
`/docker-entrypoint-initdb.d`, shuts the server down and only then starts the
final server. A socket probe therefore already succeeds on the temporary server.
Under load the import fell into the gap before the final server listened
(`ERROR 2002` in the Release lanes of 2026.10.1 and 2026.10.2). The measurements
are in the task register, section "Welle R1".

- The drill probes over TCP (`--protocol=TCP -h127.0.0.1 -P3306`). The
  temporary server has no network, so only the final server answers. The wait
  has a fixed bound of 300 s; expiry ends the drill as a missing prerequisite
  (`[restore.mysql-not-ready]`, exit 2) before any import. The import is never
  retried: a failed import stays a finding, because a retry could hide a
  partial one.
- The production restore has the same gap, and a longer one: it restores into
  an emptied data directory, and Compose mounts `struktur.sql` as init script,
  so the temporary server also loads the full schema. Step 3 of the runbook
  starts MySQL with `docker compose up -d --wait mysql`. The service healthcheck
  (`mysqladmin ping -h 127.0.0.1`) uses TCP and passes only on the final server.
  The same command applies when a restore follows the runbook of an older
  source release; every release so far carries this healthcheck.
- `VirtuSphere.RestoreRoot.Tests.ps1` pins both: the fake-Docker drill imports
  only after the TCP probe answered, and the runbook waits with `--wait` before
  the import while the healthcheck stays on TCP.

## Amendment 8 (2026-11-09, N1b): a fixed subnet for the drill network

The drill created its throwaway network without `--subnet`, so Docker took one from its default pools (`172.17.0.0/16` to `172.31.0.0/16`, `192.168.0.0/16`). On a host whose operator reaches it over SSH from an address in that range, the new bridge takes the return route and the session drops mid-drill; the production rollout of 2026-11-09 had to run the drill detached for this reason. It is the trap that the subnet pins of the stack networks avoid (C-07, go-live step 1a.2).

- The network is created with `--subnet`. The default is `10.250.0.0/24`, a private range outside the Docker default pools; `VIRTUSPHERE_RESTORE_SUBNET` overrides it. Set but empty, or not an IPv4 network address with prefix /8 to /29, ends as a missing prerequisite before any resource exists, never as a fallback to the default. A site's real subnet stays in its own operating notes, not in the repository.
- Before creating the network the drill reads the host routes (`ip -4 route show`) and the established TCP connections (`ss -tn state established`). A route or a connection address inside the subnet ends with `[restore.subnet-conflict]` (exit 2). The connection check also covers an SSH session that arrives through the default gateway and therefore matches no route. On a Linux host without iproute2 the probe is a missing prerequisite (`[restore.subnet-probe]`), not a skip; on Docker Desktop the network lives in the Docker VM and the probe does not apply, which the `Restore-Drill Netz` line states.
- Two drills on one host now collide on the subnet; Docker refuses the second network (`[restore.network-create]`). The Release lane runs heavy gates one at a time, and the runbook names the command that lists leftovers of an aborted drill.
