#!/usr/bin/env python3
"""Idempotently launch one validated run as a durable systemd user unit."""

from __future__ import annotations

import fcntl
import json
import os
import subprocess
import sys
from pathlib import Path

from virtusphere_remote_common import (
    INSTALL_ROOT,
    ProtocolError,
    STATE_ROOT,
    atomic_json,
    read_json,
    utc_now,
    validate_document,
    validate_manifest,
)


def unit_active(unit_name: str) -> bool:
    result = subprocess.run(
        ["systemctl", "--user", "show", unit_name, "--property=ActiveState", "--value"],
        check=False, capture_output=True, text=True, timeout=15,
    )
    return result.returncode == 0 and result.stdout.strip() in {"activating", "active", "reloading", "deactivating"}


def launch_decision(directory: Path, unit_name: str) -> str:
    result_path = directory / "result.json"
    if result_path.exists():
        result = read_json(result_path)
        validate_document("result", result)
        return "already_finished"
    if (directory / "started.json").exists():
        return "already_running" if unit_active(unit_name) else "recovery_required"
    return "launched"


def write_launch(directory: Path, manifest: dict, decision: str) -> None:
    atomic_json(directory / "launch.json", {
        "schema": "virtusphere.remote.launch/v1",
        "run_token": manifest["run_token"],
        "unit_name": manifest["unit_name"],
        "decision": decision,
        "written_at": utc_now(),
    }, "launch")


def systemd_command(manifest: dict, manifest_path: Path, runner: Path) -> list[str]:
    return [
        "systemd-run", "--user", f"--unit={manifest['unit_name']}", "--collect",
        "--expand-environment=no", "--property=Type=exec",
        "--property=KillMode=control-group", "--property=UMask=0077",
        f"--property=RuntimeMaxSec={manifest['runtime_max_seconds']}",
        "--", str(runner), str(manifest_path), manifest["run_token"],
    ]


def launch(manifest_path: Path, token: str) -> int:
    manifest = validate_manifest(manifest_path, token, STATE_ROOT)
    directory = manifest_path.parent
    lock_path = directory / "launch.lock"
    lock_fd = os.open(lock_path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        os.fchmod(lock_fd, 0o600)
        fcntl.flock(lock_fd, fcntl.LOCK_EX)
        decision = launch_decision(directory, manifest["unit_name"])
        if decision != "launched":
            write_launch(directory, manifest, decision)
            return 3 if decision == "recovery_required" else 0

        runner = INSTALL_ROOT / "virtusphere_remote_runner.py"
        if runner.is_symlink() or not runner.is_file() or runner.stat().st_uid != os.getuid():
            raise ProtocolError("installed runner is missing, unowned, or a symlink")
        if runner.stat().st_mode & 0o022:
            raise ProtocolError("installed runner is group/world writable")
        command = systemd_command(manifest, manifest_path, runner)
        completed = subprocess.run(command, check=False, capture_output=True, text=True, timeout=30)
        if completed.returncode != 0:
            detail = (completed.stderr or completed.stdout).strip()
            raise ProtocolError(f"systemd-run rejected the unit: {detail[:500]}")
        write_launch(directory, manifest, "launched")
        return 0
    finally:
        os.close(lock_fd)


def main(argv: list[str]) -> int:
    if len(argv) != 3:
        print("usage: virtusphere_remote_launcher.py MANIFEST RUN_TOKEN", file=sys.stderr)
        return 2
    try:
        return launch(Path(argv[1]), argv[2])
    except (ProtocolError, OSError, subprocess.SubprocessError, json.JSONDecodeError) as exc:
        print(f"launcher: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
