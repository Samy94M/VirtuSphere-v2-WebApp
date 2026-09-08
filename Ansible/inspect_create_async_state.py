#!/usr/bin/env python3
"""Read one bound Ansible async result without inventing terminal evidence.

The async directory and job id are worker-controlled. This helper constructs
the one permitted path, snapshots it once and emits a small closed JSON verdict.
It does not contact ESXi and never removes the state file.
"""

from __future__ import annotations

import json
import os
import re
import stat
import sys

MAX_STATE_BYTES = 1024 * 1024
JID_PATTERN = re.compile(r"^[A-Za-z0-9._-]{1,191}$")


def verdict(state: str, **fields: object) -> dict[str, object]:
    return {"state": state, **fields}


def bounded_error(value: object) -> str:
    text = value if isinstance(value, str) else "the create module reported a failure"
    text = " ".join(text.split())
    return (text or "the create module reported a failure")[:1024]


def inspect_state(async_dir: str, jid: str) -> dict[str, object]:
    if not os.path.isabs(async_dir) or ".." in async_dir.split(os.sep) or not JID_PATTERN.fullmatch(jid):
        return verdict("invalid", reason="the bound async path is invalid")

    path = os.path.join(async_dir, jid)
    try:
        metadata = os.lstat(path)
    except FileNotFoundError:
        return verdict("missing")
    except OSError:
        return verdict("unreadable")

    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        return verdict("invalid", reason="the async state is not a regular file")
    if metadata.st_size <= 0:
        return verdict("invalid", reason="the async state file is empty")
    if metadata.st_size > MAX_STATE_BYTES:
        return verdict("invalid", reason="the async state file exceeds its read bound")

    try:
        with open(path, "r", encoding="utf-8") as handle:
            source = handle.read(MAX_STATE_BYTES + 1)
    except OSError:
        return verdict("unreadable")
    if len(source.encode("utf-8")) > MAX_STATE_BYTES:
        return verdict("invalid", reason="the async state file exceeds its read bound")

    try:
        payload = json.loads(source)
    except (TypeError, ValueError):
        return verdict("invalid", reason="the async state file is not valid JSON")
    if not isinstance(payload, dict):
        return verdict("invalid", reason="the async state file is not a JSON object")

    if "started" in payload and not bool(payload.get("finished", False)):
        return verdict("running")

    failed = payload.get("failed", False)
    if not isinstance(failed, bool):
        return verdict("invalid", reason="the terminal async failure flag is not boolean")
    if failed:
        return verdict("failed", error=bounded_error(payload.get("msg")))

    changed = payload.get("changed")
    if not isinstance(changed, bool):
        return verdict("invalid", reason="the terminal async changed flag is missing or not boolean")
    return verdict("succeeded", changed=changed)


def main(argv: list[str]) -> int:
    if len(argv) != 3:
        return 2
    result = inspect_state(argv[1], argv[2])
    print(json.dumps(result, ensure_ascii=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
