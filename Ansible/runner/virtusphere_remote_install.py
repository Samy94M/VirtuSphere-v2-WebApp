#!/usr/bin/env python3
"""Install the verified runner payload without network or privilege changes."""

from __future__ import annotations

import os
import shutil
import sys
import tempfile
from pathlib import Path

from virtusphere_remote_common import INSTALL_ROOT, STATE_ROOT, ProtocolError, sha256_file

FILES = (
    "protocol-v1.json",
    "virtusphere_remote_common.py",
    "virtusphere_remote_launcher.py",
    "virtusphere_remote_runner.py",
    "virtusphere_remote_preflight.py",
)


def verified_files(source: Path) -> dict[str, str]:
    expected: dict[str, str] = {}
    try:
        lines = (source / "SHA256SUMS").read_text(encoding="utf-8").splitlines()
    except OSError as exc:
        raise ProtocolError(f"cannot read runner SHA256SUMS: {exc}") from exc
    for line in lines:
        parts = line.split(None, 1)
        if len(parts) != 2:
            raise ProtocolError("malformed runner SHA256SUMS")
        expected[parts[1].lstrip("*")] = parts[0]
    if set(expected) != set(FILES):
        raise ProtocolError("runner SHA256SUMS does not cover the closed file set")
    for name, digest in expected.items():
        path = source / name
        if path.is_symlink() or not path.is_file() or sha256_file(path) != digest:
            raise ProtocolError(f"runner checksum mismatch: {name}")
    return expected


def install(source: Path) -> None:
    verified_files(source)
    INSTALL_ROOT.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    STATE_ROOT.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(STATE_ROOT, 0o700)
    staging = Path(tempfile.mkdtemp(prefix=".virtusphere-runner-", dir=INSTALL_ROOT.parent))
    try:
        os.chmod(staging, 0o700)
        for name in FILES:
            target = staging / name
            shutil.copyfile(source / name, target, follow_symlinks=False)
            os.chmod(target, 0o700 if name.endswith(".py") else 0o600)
        shutil.copyfile(source / "SHA256SUMS", staging / "SHA256SUMS", follow_symlinks=False)
        os.chmod(staging / "SHA256SUMS", 0o600)
        if INSTALL_ROOT.exists():
            raise ProtocolError(f"install target already exists: {INSTALL_ROOT}; use a reviewed replacement procedure")
        os.replace(staging, INSTALL_ROOT)
    finally:
        if staging.exists():
            shutil.rmtree(staging)


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print("usage: virtusphere_remote_install.py RUNNER_BUNDLE_DIR", file=sys.stderr)
        return 2
    try:
        install(Path(argv[1]).resolve(strict=True))
        print(f"runner installed under {INSTALL_ROOT}; linger was not changed")
        return 0
    except (ProtocolError, OSError) as exc:
        print(f"runner-install: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
