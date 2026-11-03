#!/bin/sh
# VirtuSphere: installs the stale-accounts.yml cleanup on the Ansible host
# (K21-F, user decision 5). POSIX sh, LF, ASCII only.
#
# Usage, once, as root, from the offline bundle:
#   sh install-secret-cleanup.sh <ansible-account>
# <ansible-account> is the Linux account the Ansible credential logs in with. It
# owns the /tmp/virtusphere-job-* directories, and the service runs as that
# account. Root accounts are refused.
#
# Idempotent: a second run rewrites the same files and enables the timer again.
# No network, no package installation, no change to user lingering and no
# systemd-tmpfiles rule. The files come from the directory this script lives
# in, not from the current directory.
set -eu

if [ "$#" -ne 1 ]; then
    echo "usage: sh install-secret-cleanup.sh <ansible-account>" >&2
    exit 2
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "install-secret-cleanup: must run as root" >&2
    exit 1
fi

account=$1
# The whole name must match [a-z_][a-z0-9_-]*. The negated classes below reject
# any character outside that set, anywhere in the string (a trailing * alone
# would accept ";" and a newline as well, so a prefix test is not enough).
case "$account" in
    '' | [!a-z_]* | *[!a-z0-9_-]*)
        echo "install-secret-cleanup: not a plain Linux account name: $account" >&2
        exit 1
        ;;
esac

uid=$(getent passwd "$account" | cut -d: -f3) || uid=""
if [ -z "$uid" ]; then
    echo "install-secret-cleanup: no such account: $account" >&2
    exit 1
fi
if [ "$uid" -eq 0 ]; then
    echo "install-secret-cleanup: refuses the root account" >&2
    exit 1
fi

# The tools are checked, not assumed. flock (util-linux) and logger (bsdutils)
# are Essential on Ubuntu; the check is for a host that was stripped down.
for tool in find install sed systemctl getent mktemp grep; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "install-secret-cleanup: missing tool: $tool" >&2
        exit 1
    fi
done
command -v flock >/dev/null 2>&1 || { echo "install-secret-cleanup: missing tool: flock (util-linux)" >&2; exit 1; }
command -v logger >/dev/null 2>&1 || { echo "install-secret-cleanup: missing tool: logger (bsdutils)" >&2; exit 1; }

here=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
for name in virtusphere-secret-cleanup.sh virtusphere-secret-cleanup.service virtusphere-secret-cleanup.timer; do
    if [ ! -f "$here/$name" ]; then
        echo "install-secret-cleanup: missing file next to this installer: $name" >&2
        exit 1
    fi
done

libexec=/usr/local/libexec/virtusphere
units=/etc/systemd/system
tmp=$(mktemp "$units/.virtusphere-secret-cleanup.XXXXXX")
trap 'rm -f -- "${tmp:-}"' EXIT

install -d -m 0755 -o root -g root "$libexec"
install -m 0755 -o root -g root "$here/virtusphere-secret-cleanup.sh" "$libexec/virtusphere-secret-cleanup.sh"

sed "s/@ANSIBLE_ACCOUNT@/$account/" "$here/virtusphere-secret-cleanup.service" > "$tmp"
if grep -q '@ANSIBLE_ACCOUNT@' "$tmp"; then
    echo "install-secret-cleanup: the account placeholder was not replaced" >&2
    exit 1
fi
install -m 0644 -o root -g root "$tmp" "$units/virtusphere-secret-cleanup.service"
install -m 0644 -o root -g root "$here/virtusphere-secret-cleanup.timer" "$units/virtusphere-secret-cleanup.timer"

systemctl daemon-reload
systemctl enable --now virtusphere-secret-cleanup.timer

echo "install-secret-cleanup: installed for account $account; virtusphere-secret-cleanup.timer is enabled."
