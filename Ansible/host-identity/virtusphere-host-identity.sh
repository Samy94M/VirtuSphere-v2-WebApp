#!/bin/sh
# VirtuSphere: prints this Ansible host's own public SSH host keys as the
# fingerprint lines the Zugangsdaten page accepts (UX-HK, way b). POSIX sh, LF,
# ASCII only.
#
# Usage, on the Ansible host, from any account that may read /etc/ssh:
#   sh virtusphere-host-identity.sh
# Copy one printed line into the field "SHA256-Fingerprint oder ganze
# ssh-keygen-Zeile" of the Ansible credential. The portal takes the key type
# from the line, so nothing is typed.
#
# What it reads: exactly the public halves /etc/ssh/ssh_host_*_key.pub. The
# private keys (the same names without .pub) are never named or opened, and the
# script takes no argument that could point it elsewhere. It writes no file and
# needs no root, no network and no package. Each line is the output of
# ssh-keygen -l -E sha256, for example "256 SHA256:... root@ubuntu-102 (ED25519)".
#
# Exit status: 0 when at least one line was printed, 2 when called with an
# argument, 1 when ssh-keygen is missing
# or no public host key exists. Errors go to stderr only, so stdout stays a
# clean copy source.
set -eu

if [ "$#" -ne 0 ]; then
    echo "usage: sh virtusphere-host-identity.sh" >&2
    exit 2
fi

if ! command -v ssh-keygen >/dev/null 2>&1; then
    echo "virtusphere-host-identity: ssh-keygen not found (install openssh-client)" >&2
    exit 1
fi

count=0
for key in /etc/ssh/ssh_host_*_key.pub; do
    # A pattern that matches nothing stays literal; skip it instead of failing.
    if [ ! -f "$key" ]; then
        continue
    fi
    ssh-keygen -l -E sha256 -f "$key"
    count=$((count + 1))
done

if [ "$count" -eq 0 ]; then
    echo "virtusphere-host-identity: no public host key under /etc/ssh" >&2
    exit 1
fi
