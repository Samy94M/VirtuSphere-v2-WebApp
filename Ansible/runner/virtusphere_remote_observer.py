#!/usr/bin/env python3
"""Read one durable run without starting, stopping, or deleting anything."""

from __future__ import annotations

import base64
import json
import os
import stat
import subprocess
import sys
from pathlib import Path

from virtusphere_remote_common import (
    ProtocolError,
    STATE_ROOT,
    read_json,
    validate_document,
    validate_manifest,
)

MAX_OUTPUT_CHUNK = 1024 * 1024
MARKERS = ("launch", "started", "heartbeat", "result")
MAX_MARKER_BYTES = 65536


def secure_file(path: Path, max_bytes: int) -> None:
    info = path.lstat()
    if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode):
        raise ProtocolError(f"{path.name} is not a regular file")
    if info.st_uid != os.getuid() or info.st_mode & 0o077:
        raise ProtocolError(f"{path.name} has unsafe owner or permissions")
    if info.st_size > max_bytes:
        raise ProtocolError(f"{path.name} exceeds its byte limit")


def unit_state(unit_name: str) -> str:
    completed = subprocess.run(
        ["systemctl", "--user", "show", unit_name, "--property=LoadState,ActiveState", "--value"],
        check=False, capture_output=True, text=True, timeout=15,
    )
    values = [value.strip() for value in completed.stdout.splitlines()]
    if completed.returncode != 0 or len(values) < 2:
        return "unknown"
    if values[0] == "not-found":
        return "not_found"
    if values[1] in {"activating", "active", "reloading", "deactivating"}:
        return "active"
    return "inactive"


def marker_json(directory: Path, name: str) -> str | None:
    path = directory / f"{name}.json"
    if not path.exists():
        return None
    secure_file(path, MAX_MARKER_BYTES)
    document = read_json(path)
    validate_document(name, document)
    return json.dumps(document, ensure_ascii=True, separators=(",", ":"), sort_keys=True)


def observe(manifest_path: Path, token: str, offset: int) -> dict:
    if offset < 0:
        raise ProtocolError("output offset must be non-negative")
    manifest = validate_manifest(manifest_path, token, STATE_ROOT)
    directory = manifest_path.parent
    output_path = directory / "output.log"
    size = output_path.stat().st_size if output_path.exists() else 0
    if offset > size:
        raise ProtocolError("output offset is beyond the current log; rotation or truncation detected")
    chunk = b""
    if output_path.exists():
        secure_file(output_path, int(manifest["output_max_bytes"]))
        with output_path.open("rb") as handle:
            handle.seek(offset)
            chunk = handle.read(MAX_OUTPUT_CHUNK)
    document = {
        "schema": "virtusphere.remote.observation/v1",
        "protocol": 1,
        "instance_id": manifest["instance_id"],
        "generation_id": manifest["generation_id"],
        "run_token": manifest["run_token"],
        "unit_name": manifest["unit_name"],
        "unit_state": unit_state(manifest["unit_name"]),
        "offset": offset,
        "next_offset": offset + len(chunk),
        "output_b64": base64.b64encode(chunk).decode("ascii"),
    }
    for name in MARKERS:
        value = marker_json(directory, name)
        if value is not None:
            document[f"{name}_json"] = value
    validate_document("observation", document)
    return document


def main(argv: list[str]) -> int:
    if len(argv) != 4:
        print("usage: virtusphere_remote_observer.py MANIFEST RUN_TOKEN LOG_OFFSET", file=sys.stderr)
        return 2
    try:
        result = observe(Path(argv[1]), argv[2], int(argv[3]))
        print(json.dumps(result, ensure_ascii=True, separators=(",", ":"), sort_keys=True))
        return 0
    except (ProtocolError, OSError, ValueError, subprocess.SubprocessError, json.JSONDecodeError) as exc:
        print(f"observer: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
