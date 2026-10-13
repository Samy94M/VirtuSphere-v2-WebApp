#!/bin/sh
# VirtuSphere: removes stale accounts.yml files from the job directories of the
# Ansible host (K21-F, user decision 5). POSIX sh, LF, ASCII only.
#
# Runs as the Ansible account the systemd service names (User=). It refuses to
# run as root. Nothing here needs the network, a package manager or tmpfiles.
#
# What is removed: exactly /tmp/virtusphere-job-*/accounts.yml, and only when
#   - it is a regular file (a symlink is never removed), exactly one level below
#     /tmp (find -maxdepth 2 -mindepth 2, no -L, no -H, no -follow),
#   - it belongs to the running account (find -user),
#   - it is older than 24 h by its modification time (find -mmin +1440),
#   - its job directory is a real directory and flock -n on it succeeds.
#
# What protects the file, stated plainly: the 24 h age is the protection that
# matters. The flock is taken by this script only, because the job commands do
# not lock their job directory yet. That is a separate round, after the lab
# probe. Until it is done, a job that runs longer than 24 h is NOT protected by
# the lock. Do not read the flock as a guarantee.
#
# The content of accounts.yml is never read. Only the path is logged.
#
# Output: one line per file (removed, locked or failed, each with the path) and
# one summary line, both through logger -t virtusphere-secret-cleanup, so they
# reach the journal. If logger fails, the same line goes to stderr, which the
# service also sends to the journal.
#
# Exit status: 0 when no removal failed (a locked directory is not a failure).
# 1 when a removal failed or the run could not start (root, missing tool).
set -u

log() {
    logger -t virtusphere-secret-cleanup "$1" 2>/dev/null || printf '%s\n' "virtusphere-secret-cleanup: $1" >&2
}

if [ "$(id -u)" -eq 0 ]; then
    printf '%s\n' "virtusphere-secret-cleanup: refuses to run as root" >&2
    exit 1
fi

for tool in find id flock; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        printf '%s\n' "virtusphere-secret-cleanup: missing tool: $tool" >&2
        exit 1
    fi
done

# Unreadable directories of other accounts in /tmp are normal (for example
# systemd private directories), so find's own messages are dropped and its
# status is not used. The -user test keeps every foreign entry out.
found=$(find /tmp -maxdepth 2 -mindepth 2 -path '/tmp/virtusphere-job-*/accounts.yml' -type f -user "$(id -un)" -mmin +1440 2>/dev/null)

seen=0
removed=0
locked=0
failed=0

# Here-document, not a pipe: the counters must survive the loop.
while IFS= read -r path; do
    [ -n "$path" ] || continue
    seen=$((seen + 1))
    dir=${path%/accounts.yml}
    # A job directory that became a link after find listed it is refused
    # before the lock, because flock would follow the link.
    if [ -L "$dir" ] || [ ! -d "$dir" ]; then
        log "failed $path: the job directory is not a real directory"
        failed=$((failed + 1))
        continue
    fi
    # flock -E 75: a lock conflict exits 75, so it can be told apart from a
    # failed rm, which exits with rm's own status.
    flock -n -E 75 "$dir" rm -f -- "$path"
    rc=$?
    case "$rc" in
        0) log "removed $path"; removed=$((removed + 1)) ;;
        75) log "locked $path"; locked=$((locked + 1)) ;;
        *) log "failed $path: rm exit $rc"; failed=$((failed + 1)) ;;
    esac
done <<EOF
$found
EOF

log "summary seen=$seen removed=$removed locked=$locked failed=$failed"

if [ "$failed" -ne 0 ]; then
    exit 1
fi
exit 0
