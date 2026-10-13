#!/bin/sh
# K21-F behaviour probe for the Ansible host cleanup: virtusphere-secret-cleanup.sh
# and install-secret-cleanup.sh in Ansible/host-cleanup/.
#
# Run by the gate host-secret-cleanup (scripts/lib/check/gates-fast.ps1) in the
# digest-pinned debian:bookworm-slim image (scripts/tool-lock.json, key
# hostCleanupProbe), repository mounted read-only, no network:
#   sh /repo/tests/host-cleanup/host-cleanup-probe.sh /repo/Ansible/host-cleanup
#
# The probe runs as root in the container. The cleanup runs as uid 1000 (the
# Ansible account), as the systemd service runs it. Writes happen only below
# /tmp, /srv/k21f, /opt/k21f and the installer's targets inside the container.
#
# Each rule has a control case. The positive case (an old, own, unlocked file)
# must be removed; without it, the cases that expect a file to stay would also
# pass on a run that did nothing at all.
#
# Exit status: 0 every case passes, 1 a case fails, 2 the environment cannot run
# the probe (not root, a tool missing). The gate maps 2 to infrastructure_error.
#
# Progress: "[0/total] START", then "[n/total] RUN <case>" and
# "[n/total] PASS|FAIL <case>" for each case. The total is the number of check
# lines below, counted from this file, so it is known before the first case.
set -u

HOST=${1:-}
if [ -z "$HOST" ]; then
    echo "usage: host-cleanup-probe.sh <Ansible/host-cleanup>" >&2
    exit 2
fi
SCRIPT=$HOST/virtusphere-secret-cleanup.sh
INSTALLER=$HOST/install-secret-cleanup.sh
WORK=/opt/k21f
RUNPATH=$WORK/shim:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

if [ "$(id -u)" -ne 0 ]; then
    echo "host-cleanup-probe: must start as root (the container default)" >&2
    exit 2
fi
for tool in useradd setpriv flock touch stat sha256sum grep sed; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "host-cleanup-probe: missing tool: $tool" >&2
        exit 2
    fi
done
if [ ! -f "$SCRIPT" ] || [ ! -f "$INSTALLER" ]; then
    echo "FAIL host files present: $SCRIPT and $INSTALLER"
    echo "SUMMARY 1 failed (behaviour not evaluated)"
    exit 1
fi

TOTAL=$(grep -c '^check ' "$0")
DONE=0
FAILS=0

check() {
    label=$1
    shift
    DONE=$((DONE + 1))
    echo "[$DONE/$TOTAL] RUN $label"
    if "$@"; then
        echo "[$DONE/$TOTAL] PASS $label"
    else
        echo "[$DONE/$TOTAL] FAIL $label"
        FAILS=$((FAILS + 1))
    fi
}

echo "[0/$TOTAL] START host-cleanup probe"

# --- Fixtures (root) -----------------------------------------------------------
useradd -u 1000 -M -s /bin/sh ansible 2>/dev/null
useradd -u 1001 -M -s /bin/sh other 2>/dev/null
rm -rf /tmp/virtusphere-job-* /tmp/other-dir-8 /srv/k21f
mkdir -p "$WORK/shim" /srv/k21f

cat > "$WORK/shim/logger" <<'EOF'
#!/bin/sh
printf '%s\n' "$*" >> /opt/k21f/logger.out
exit 0
EOF
cat > "$WORK/shim/systemctl" <<'EOF'
#!/bin/sh
printf '%s\n' "$*" >> /opt/k21f/systemctl.out
exit 0
EOF
chmod 0755 "$WORK/shim/logger" "$WORK/shim/systemctl"
: > "$WORK/logger.out"
: > "$WORK/systemctl.out"
chmod 0666 "$WORK/logger.out" "$WORK/systemctl.out"

age() { touch -d "$2 hours ago" -- "$1"; }
mkjob() { mkdir -p "$1" && chown ansible:ansible "$1"; }
mkacc() { # mkacc <file> <owner:group> <hours-old>
    printf 'SECRET-%s\n' "$1" > "$1"
    chown "$2" "$1"
    age "$1" "$3"
}

# 1 positive: own, 25 h, unlocked -> removed
mkjob /tmp/virtusphere-job-1-old
mkacc /tmp/virtusphere-job-1-old/accounts.yml ansible:ansible 25
# 2 young: own, 23 h -> stays
mkjob /tmp/virtusphere-job-2-young
mkacc /tmp/virtusphere-job-2-young/accounts.yml ansible:ansible 23
# 3 locked: own, 25 h, job directory locked by another process -> stays
mkjob /tmp/virtusphere-job-3-locked
mkacc /tmp/virtusphere-job-3-locked/accounts.yml ansible:ansible 25
flock -x /tmp/virtusphere-job-3-locked sleep 120 &
LOCK_PID=$!
sleep 1
# 4 linked directory: the job directory is a symlink to a real directory with an
# old file -> nothing followed, the target stays
mkdir -p /srv/k21f/linktarget && chown ansible:ansible /srv/k21f/linktarget
mkacc /srv/k21f/linktarget/accounts.yml ansible:ansible 25
ln -s /srv/k21f/linktarget /tmp/virtusphere-job-4-linkdir
# 5 linked file: accounts.yml is a symlink to an old regular file -> both stay
mkjob /tmp/virtusphere-job-5-linkfile
printf 'SECRET-5\n' > /srv/k21f/target5
chown ansible:ansible /srv/k21f/target5
age /srv/k21f/target5 25
ln -s /srv/k21f/target5 /tmp/virtusphere-job-5-linkfile/accounts.yml
# 6 foreign owner: old file of another account in an own job directory; only the
# -user test keeps it, the directory is writable for the ansible account
mkjob /tmp/virtusphere-job-6-foreign
mkacc /tmp/virtusphere-job-6-foreign/accounts.yml other:other 25
# 7 too deep: one level below the job directory -> stays
mkjob /tmp/virtusphere-job-7-deep
mkdir -p /tmp/virtusphere-job-7-deep/sub && chown ansible:ansible /tmp/virtusphere-job-7-deep/sub
mkacc /tmp/virtusphere-job-7-deep/sub/accounts.yml ansible:ansible 25
# 8 outside the job pattern -> stays
mkdir -p /tmp/other-dir-8 && chown ansible:ansible /tmp/other-dir-8
mkacc /tmp/other-dir-8/accounts.yml ansible:ansible 25

# --- Cleanup script as root: refused --------------------------------------------
env PATH="$RUNPATH" sh "$SCRIPT" > "$WORK/root.out" 2>&1
root_rc=$?
check "root run is refused with a nonzero exit" test "$root_rc" -ne 0
check "root run is refused for the root reason" grep -q 'refuses to run as root' "$WORK/root.out"
check "root run removes nothing" test -f /tmp/virtusphere-job-1-old/accounts.yml

# --- Cleanup script as the ansible account (as the systemd service runs it) ------
setpriv --reuid=1000 --regid=1000 --clear-groups env PATH="$RUNPATH" HOME=/tmp sh "$SCRIPT" > "$WORK/cleanup.out" 2>&1
rc=$?
kill "$LOCK_PID" 2>/dev/null

check "run as ansible exits 0" test "$rc" -eq 0
check "control: own file older than 24 h without a lock is removed" test ! -e /tmp/virtusphere-job-1-old/accounts.yml
check "outcome 'removed' for that file reaches the journal line" grep -q 'removed /tmp/virtusphere-job-1-old/accounts.yml' "$WORK/logger.out"
check "own file younger than 24 h stays" test -f /tmp/virtusphere-job-2-young/accounts.yml
check "locked job directory keeps its file" test -f /tmp/virtusphere-job-3-locked/accounts.yml
check "outcome 'locked' for the locked file reaches the journal line" grep -q 'locked /tmp/virtusphere-job-3-locked/accounts.yml' "$WORK/logger.out"
check "linked job directory: target file intact" test -f /srv/k21f/linktarget/accounts.yml
check "linked job directory: the link itself untouched" test -L /tmp/virtusphere-job-4-linkdir
check "symlinked accounts.yml is not removed" test -L /tmp/virtusphere-job-5-linkfile/accounts.yml
check "symlink target intact" test -f /srv/k21f/target5
check "file of another owner stays" test -f /tmp/virtusphere-job-6-foreign/accounts.yml
check "file one level too deep stays" test -f /tmp/virtusphere-job-7-deep/sub/accounts.yml
check "path outside the job pattern stays" test -f /tmp/other-dir-8/accounts.yml
check "summary line with counts reaches logger under the tag" grep -q 'virtusphere-secret-cleanup summary seen=' "$WORK/logger.out"
check "logger never carries file content" sh -c "! grep -q 'SECRET-' '$WORK/logger.out'"

# --- Foreign job directory: second run as the ansible account ----------------------
# Kept out of the main fixtures, so the main run's exit status is still 0. The
# directory belongs to the other account but is group-writable for ansible, so the
# removal of its file would succeed. A refusal of it counts as failed, so this run
# exits 1. Case 3's file may or may not be removed in this run, depending on whether
# its lock is still held; no check below reads it.
# 9 foreign job directory: own old file in a directory of another account -> stays, run exits 1
mkdir -p /tmp/virtusphere-job-9-foreigndir && chown other:ansible /tmp/virtusphere-job-9-foreigndir && chmod 0770 /tmp/virtusphere-job-9-foreigndir
mkacc /tmp/virtusphere-job-9-foreigndir/accounts.yml ansible:ansible 25
setpriv --reuid=1000 --regid=1000 --clear-groups env PATH="$RUNPATH" HOME=/tmp sh "$SCRIPT" > "$WORK/run2.out" 2>&1
rc2=$?

check "foreign job directory run exits 1" test "$rc2" -eq 1
check "foreign job directory keeps its own file" test -f /tmp/virtusphere-job-9-foreigndir/accounts.yml
check "outcome 'failed' with 'not owned' for that file reaches the journal line" sh -c "grep 'failed /tmp/virtusphere-job-9-foreigndir/accounts.yml' '$WORK/logger.out' | grep -q 'not owned'"

# --- Installer -----------------------------------------------------------------
UNIT=/etc/systemd/system
LIB=/usr/local/libexec/virtusphere

(cd / && env PATH="$RUNPATH" sh "$INSTALLER" ansible) > "$WORK/install1.out" 2>&1
rc=$?
check "installer as root with the account exits 0" test "$rc" -eq 0
check "installed script is root:root 0755" sh -c "[ \"\$(stat -c '%U:%G %a' $LIB/virtusphere-secret-cleanup.sh)\" = 'root:root 755' ]"
check "service names the account and the placeholder is gone" sh -c "grep -qx 'User=ansible' $UNIT/virtusphere-secret-cleanup.service && ! grep -q '@ANSIBLE_ACCOUNT@' $UNIT/virtusphere-secret-cleanup.service"
check "timer unit installed" test -f "$UNIT/virtusphere-secret-cleanup.timer"
check "daemon-reload and enable --now were called" sh -c "grep -qx 'daemon-reload' '$WORK/systemctl.out' && grep -qx 'enable --now virtusphere-secret-cleanup.timer' '$WORK/systemctl.out'"

before=$(cat "$LIB/virtusphere-secret-cleanup.sh" "$UNIT/virtusphere-secret-cleanup.service" "$UNIT/virtusphere-secret-cleanup.timer" | sha256sum)
(cd / && env PATH="$RUNPATH" sh "$INSTALLER" ansible) > "$WORK/install2.out" 2>&1
rc=$?
after=$(cat "$LIB/virtusphere-secret-cleanup.sh" "$UNIT/virtusphere-secret-cleanup.service" "$UNIT/virtusphere-secret-cleanup.timer" | sha256sum)
check "second installer run exits 0" test "$rc" -eq 0
check "second installer run changes nothing" test "$before" = "$after"

(cd / && env PATH="$RUNPATH" sh "$INSTALLER" nosuchaccount) > "$WORK/install3.out" 2>&1
rc=$?
check "unknown account is refused" test "$rc" -ne 0
check "unknown account is refused for the account reason" grep -q 'no such account' "$WORK/install3.out"
check "unknown account changes no unit" grep -qx 'User=ansible' "$UNIT/virtusphere-secret-cleanup.service"

(cd / && env PATH="$RUNPATH" sh "$INSTALLER") > "$WORK/install4.out" 2>&1
rc=$?
check "missing account argument is refused" test "$rc" -ne 0
check "missing account argument is refused with the usage line" grep -q 'usage: sh install-secret-cleanup.sh' "$WORK/install4.out"

(cd / && env PATH="$RUNPATH" sh "$INSTALLER" 'ansible;id') > "$WORK/install5.out" 2>&1
rc=$?
check "account argument with shell syntax is refused" test "$rc" -ne 0
check "account argument with shell syntax is refused by the name check" grep -q 'not a plain Linux account name' "$WORK/install5.out"
check "shell-syntax argument changes no unit" grep -qx 'User=ansible' "$UNIT/virtusphere-secret-cleanup.service"

(cd / && setpriv --reuid=1000 --regid=1000 --clear-groups env PATH="$RUNPATH" sh "$INSTALLER" ansible) > "$WORK/install6.out" 2>&1
rc=$?
check "installer as the ansible account is refused" test "$rc" -ne 0
check "installer as the ansible account is refused for the root reason" grep -q 'must run as root' "$WORK/install6.out"

echo "SUMMARY $FAILS failed of $TOTAL"
[ "$FAILS" -eq 0 ]
