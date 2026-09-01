#!/usr/bin/env python3
"""Emit exactly one machine-readable create marker from a local result file.

Usage:  python3 emit_create_result.py <result.json>

Why this is a separate program rather than a debug task: the marker has to
reach the controller's stdout as one plain line the worker can read out of the
merged output. Ansible wraps every task result in its own formatting, so a
playbook writes its outcome to a local JSON file and this program turns that
file into the one line the protocol defines:

    ::virtusphere-create:: v1 <base64url without padding>

Everything about that line is closed. Six events, each with exactly the fields
it is allowed to have, no extras and none missing; every id checked against its
character class and length; the whole payload bounded. A create playbook that
produced something else does not get a marker with a warning, it gets no marker
at all and a non-zero exit, because the PHP side treats a missing or malformed
marker as protocol_error and must never reconstruct one from ordinary Ansible
prose.

Stdlib only and no network: this runs on an air-gapped Ansible host, like
upload_mac_list.py.

Exit codes: 0 marker written, 2 the file or the payload violates the contract.
"""

from __future__ import annotations

import base64
import json
import os
import re
import sys

MARKER_PREFIX = "::virtusphere-create::"
PROTOCOL_VERSION = "v1"

# The decoded payload is bounded because it is built from remote data and read
# by a parser on the other side. A name is capped by the portal's own column,
# an error string is truncated by the producer; this is the last line of defence
# rather than the first.
MAX_RESULT_FILE_BYTES = 65536
MAX_PAYLOAD_BYTES = 8192
MAX_NAME_LENGTH = 191
MAX_ERROR_LENGTH = 1024

JID_PATTERN = re.compile(r"^[A-Za-z0-9._-]{1,191}$")
MOID_PATTERN = re.compile(r"^[A-Za-z0-9._-]{1,64}$")
UUID_PATTERN = re.compile(r"^[A-Za-z0-9 :._-]{1,64}$")
POWER_STATE_PATTERN = re.compile(r"^[A-Za-z]{1,32}$")

ERROR_CODES = (
    "identity_conflict",
    "module_failed",
    "launch_unconfirmed",
    "async_state_missing",
    "identity_result_invalid",
    "transport_lost",
    "job_timeout",
    "protocol_error",
    "ownership_lost",
    "operator_released",
)

# Field name -> its check. The event tables below name fields only; what a field
# may contain is decided once, here, so two events cannot disagree about what a
# JID looks like.
FIELD_RULES = {
    "portal_vm_id": ("int", None),
    "vm_name": ("str", MAX_NAME_LENGTH),
    "existed_before": ("bool", None),
    "precheck_moid": ("moid_or_null", None),
    "precheck_instance_uuid": ("uuid_or_null", None),
    "async_jid": ("jid", None),
    "async_dir": ("abs_path", None),
    "changed": ("bool", None),
    "moid": ("moid", None),
    "instance_uuid": ("uuid", None),
    "power_state": ("power_state", None),
    "error": ("str", MAX_ERROR_LENGTH),
    "error_code": ("error_code", None),
}

EVENTS = {
    "prepared": ("portal_vm_id", "vm_name", "existed_before", "precheck_moid", "precheck_instance_uuid"),
    "launched": ("portal_vm_id", "vm_name", "async_jid", "async_dir", "existed_before", "precheck_moid", "precheck_instance_uuid"),
    "running": ("portal_vm_id", "async_jid"),
    "succeeded": ("portal_vm_id", "vm_name", "async_jid", "changed", "moid", "instance_uuid", "power_state"),
    "failed": ("portal_vm_id", "async_jid", "error"),
    "rejected": ("portal_vm_id", "vm_name", "error_code", "error"),
}


class ContractError(Exception):
    """The result file does not describe a legal event."""


def _check_field(name, value):
    kind, limit = FIELD_RULES[name]
    if kind == "int":
        # bool is an int subclass in Python, and a True that passed as an id
        # would address VM number one.
        if isinstance(value, bool) or not isinstance(value, int) or value <= 0:
            raise ContractError("%s must be a positive integer" % name)
        return value
    if kind == "bool":
        if not isinstance(value, bool):
            raise ContractError("%s must be a boolean" % name)
        return value
    if kind == "str":
        if not isinstance(value, str) or value == "" or len(value) > limit:
            raise ContractError("%s must be a non-empty string of at most %d characters" % (name, limit))
        # No control characters at all, tab included: the marker is one line and
        # the decoded value is later rendered next to other fields.
        if any(ord(char) < 32 for char in value):
            raise ContractError("%s must not carry control characters" % name)
        return value
    if kind in ("moid", "uuid", "jid", "power_state"):
        pattern = {"moid": MOID_PATTERN, "uuid": UUID_PATTERN, "jid": JID_PATTERN, "power_state": POWER_STATE_PATTERN}[kind]
        if not isinstance(value, str) or pattern.match(value) is None:
            raise ContractError("%s is outside its allowed character class" % name)
        return value
    if kind in ("moid_or_null", "uuid_or_null"):
        if value is None:
            return None
        pattern = MOID_PATTERN if kind == "moid_or_null" else UUID_PATTERN
        if not isinstance(value, str) or pattern.match(value) is None:
            raise ContractError("%s is outside its allowed character class" % name)
        return value
    if kind == "abs_path":
        if not isinstance(value, str) or not value.startswith("/") or ".." in value or len(value) > 1024:
            raise ContractError("%s must be an absolute path without traversal" % name)
        return value
    if kind == "error_code":
        if value not in ERROR_CODES:
            raise ContractError("error_code is not one of the closed codes")
        return value
    raise ContractError("unknown field rule for %s" % name)


def validate(payload):
    """Returns the payload in canonical field order, or raises ContractError."""
    if not isinstance(payload, dict):
        raise ContractError("the result file must contain one JSON object")
    event = payload.get("event")
    if event not in EVENTS:
        raise ContractError("unknown event")

    allowed = EVENTS[event]
    extra = sorted(set(payload) - set(allowed) - {"event"})
    if extra:
        raise ContractError("unexpected field(s): %s" % ", ".join(extra))
    missing = [name for name in allowed if name not in payload]
    if missing:
        raise ContractError("missing field(s): %s" % ", ".join(missing))

    # A precheck without an existing VM has no MOID and no UUID, and a precheck
    # WITH one must have both. Half an identity is what lets a name-based match
    # look like a proven one.
    canonical = {"event": event}
    for name in allowed:
        canonical[name] = _check_field(name, payload[name])
    if event in ("prepared", "launched"):
        has_moid = canonical["precheck_moid"] is not None
        has_uuid = canonical["precheck_instance_uuid"] is not None
        if canonical["existed_before"] != (has_moid and has_uuid) or has_moid != has_uuid:
            raise ContractError("existed_before does not match the precheck identity")

    return canonical


def build_marker(payload):
    encoded = json.dumps(payload, separators=(",", ":"), sort_keys=False).encode("utf-8")
    if len(encoded) > MAX_PAYLOAD_BYTES:
        raise ContractError("payload is larger than the protocol allows")
    text = base64.urlsafe_b64encode(encoded).decode("ascii").rstrip("=")

    return "%s %s %s" % (MARKER_PREFIX, PROTOCOL_VERSION, text)


def main(argv):
    if len(argv) != 2:
        sys.stderr.write("usage: emit_create_result.py <result.json>\n")
        return 2
    path = argv[1]
    try:
        if os.path.getsize(path) > MAX_RESULT_FILE_BYTES:
            sys.stderr.write("create result file is too large\n")
            return 2
        with open(path, "rb") as handle:
            payload = json.loads(handle.read().decode("utf-8"))
    except (OSError, ValueError, UnicodeDecodeError) as error:
        sys.stderr.write("create result file is unreadable: %s\n" % error.__class__.__name__)
        return 2

    try:
        sys.stdout.write(build_marker(validate(payload)) + "\n")
    except ContractError as error:
        # Deliberately no marker on the way out. A caller that printed a warning
        # line here would hand the PHP parser something to misread.
        sys.stderr.write("create result violates the protocol: %s\n" % error)
        return 2
    sys.stdout.flush()

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
