#!/usr/bin/env python3
"""Read-only readiness evidence for a future durable-runner site acceptance."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
from pathlib import Path

from virtusphere_remote_common import INSTALL_ROOT, PROTOCOL_FILE, STATE_ROOT, sha256_file, utc_now, validate_document

RUNNER_FILES = (
    "protocol-v1.json",
    "virtusphere_remote_common.py",
    "virtusphere_remote_launcher.py",
    "virtusphere_remote_runner.py",
    "virtusphere_remote_preflight.py",
)


def command(argv: list[str]) -> tuple[int, str]:
    try:
        result = subprocess.run(argv, check=False, capture_output=True, text=True, timeout=15)
        return result.returncode, (result.stdout or result.stderr).strip()
    except (OSError, subprocess.SubprocessError) as exc:
        return 127, str(exc)


def secure_directory(path: Path) -> tuple[bool, str]:
    try:
        info = path.lstat()
    except OSError as exc:
        return False, str(exc)
    ok = (
        stat.S_ISDIR(info.st_mode)
        and not path.is_symlink()
        and info.st_uid == os.getuid()
        and not info.st_mode & 0o077
    )
    return ok, "owner=current, mode=0700-equivalent" if ok else "must be owned by current user, non-symlink, mode 0700"


def manifest_checksums(directory: Path) -> tuple[bool, str]:
    checksum_file = directory / "SHA256SUMS"
    try:
        lines = checksum_file.read_text(encoding="utf-8").splitlines()
    except OSError as exc:
        return False, str(exc)
    expected: dict[str, str] = {}
    for line in lines:
        parts = line.split(None, 1)
        if len(parts) != 2:
            return False, "malformed SHA256SUMS"
        expected[parts[1].lstrip("*")] = parts[0]
    if set(expected) != set(RUNNER_FILES):
        return False, "SHA256SUMS does not cover the closed runner file set"
    for name, digest in expected.items():
        target = directory / name
        if target.is_symlink() or not target.is_file() or sha256_file(target) != digest:
            return False, f"checksum mismatch: {name}"
    return True, "all runner checksums match"


def fingerprint() -> str:
    pieces = [str(os.getuid()), sys.version.split()[0]]
    for candidate in (Path("/etc/machine-id"), Path("/var/lib/dbus/machine-id")):
        try:
            pieces.append(candidate.read_text(encoding="utf-8").strip())
            break
        except OSError:
            continue
    code, version = command(["systemd-run", "--version"])
    pieces.append(version.splitlines()[0] if code == 0 and version else "systemd-unavailable")
    return hashlib.sha256("\0".join(pieces).encode("utf-8")).hexdigest()


def collect(runner_dir: Path, state_root: Path, required_free_bytes: int) -> dict:
    checks: list[dict[str, object]] = []

    def add(name: str, ok: bool, detail: str) -> None:
        checks.append({"name": name, "ok": ok, "detail": detail[:500]})

    add("python", sys.version_info >= (3, 11), f"{sys.version_info.major}.{sys.version_info.minor}.{sys.version_info.micro}")
    for binary in ("ansible-playbook", "systemctl", "systemd-run", "loginctl"):
        location = shutil.which(binary)
        add(f"binary:{binary}", location is not None, "available" if location else "missing")
    code, manager = command(["systemctl", "--user", "is-system-running"])
    add("systemd-user-manager", code in (0, 1) and manager in {"running", "degraded"}, manager or f"exit={code}")
    code, linger = command(["loginctl", "show-user", str(os.getuid()), "--property=Linger", "--value"])
    add("linger", code == 0 and linger.lower() == "yes", linger or f"exit={code}")
    controllers = Path("/sys/fs/cgroup/cgroup.controllers")
    add("cgroup-v2", controllers.is_file(), "unified hierarchy" if controllers.is_file() else "cgroup.controllers missing")
    secure, detail = secure_directory(state_root)
    add("state-root", secure, detail)
    try:
        usage = shutil.disk_usage(state_root)
        add("free-space", usage.free >= required_free_bytes, f"free_bytes={usage.free}; required_bytes={required_free_bytes}")
    except OSError as exc:
        add("free-space", False, str(exc))
    checksums_ok, checksum_detail = manifest_checksums(runner_dir)
    add("runner-checksums", checksums_ok, checksum_detail)
    protocol_ok = (runner_dir / PROTOCOL_FILE.name).is_file()
    add("protocol", protocol_ok, "protocol-v1 present" if protocol_ok else "protocol-v1 missing")
    return {
        "schema": "virtusphere.remote.preflight/v1",
        "written_at": utc_now(),
        "host_fingerprint": fingerprint(),
        "required_free_bytes": required_free_bytes,
        "ready": all(bool(item["ok"]) for item in checks),
        "checks": checks,
        "scope": "site-evidence-only; does-not-activate-remote-execution",
    }


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runner-dir", type=Path, default=INSTALL_ROOT)
    parser.add_argument("--state-root", type=Path, default=STATE_ROOT)
    parser.add_argument("--required-free-bytes", type=int, required=True)
    args = parser.parse_args(argv[1:])
    if args.required_free_bytes <= 0:
        parser.error("--required-free-bytes must be positive and site-approved")
    evidence = collect(args.runner_dir, args.state_root, args.required_free_bytes)
    validate_document("preflight", evidence)
    print(json.dumps(evidence, ensure_ascii=True, sort_keys=True, indent=2))
    return 0 if evidence["ready"] else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
