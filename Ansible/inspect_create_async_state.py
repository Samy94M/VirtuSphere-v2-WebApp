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
MAX_CAUSE_EVENTS = 8
# The one text for a failure that carries no message of its own. The status
# playbook writes `error` as given and keeps no second copy of it.
NO_MODULE_MESSAGE = "the create module reported a failure"


def verdict(state: str, **fields: object) -> dict[str, object]:
    return {"state": state, **fields}


def module_failure_message(payload: dict[str, object]) -> object:
    """Return the failed module's own message, or None.

    fail_json() writes it as `msg`. An exception that escapes the module is
    written by ansible-core 2.19 without `msg`: the text is in
    `exception.event.msg` and each cause follows in `event.chain.event`.
    Ansible joins the followed causes with ": " in its own error line, so the
    same join keeps the portal reason identical to the job log (job 1527:
    `503 Service Unavailable` arrived only as the generic fallback).
    """
    message = payload.get("msg")
    if isinstance(message, str) and message.strip():
        return message

    summary = payload.get("exception")
    event = summary.get("event") if isinstance(summary, dict) else None
    parts: list[str] = []
    while isinstance(event, dict) and len(parts) < MAX_CAUSE_EVENTS:
        text = event.get("msg")
        if not isinstance(text, str) or not text.strip():
            break
        parts.append(text)
        chain = event.get("chain")
        if not isinstance(chain, dict) or chain.get("follow") is not True:
            break
        event = chain.get("event")
    return ": ".join(parts) if parts else None


def bounded_error(value: object) -> str:
    text = " ".join(value.split()) if isinstance(value, str) else ""
    return (text or NO_MODULE_MESSAGE)[:1024]


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
        # `error_from_host` says at the source whether the text is the module's
        # own message or the fallback, so the portal never has to tell the two
        # apart by comparing text.
        message = module_failure_message(payload)
        from_host = isinstance(message, str) and message.strip() != ""
        return verdict("failed", error=bounded_error(message), error_from_host=from_host)

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
