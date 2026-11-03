#!/usr/bin/env python3
"""Execute one closed-policy Ansible step and persist an atomic result."""

from __future__ import annotations

import json
import os
import selectors
import shutil
import signal
import subprocess
import sys
import time
from pathlib import Path

from virtusphere_remote_common import ProtocolError, atomic_json, utc_now, validate_document, validate_manifest


class RedactingWriter:
    """Stream text without leaking secrets split across read boundaries."""

    def __init__(self, path: Path, secrets: list[str], limit: int) -> None:
        self.handle = path.open("wb")
        os.chmod(path, 0o600)
        self.secrets = sorted(set(secrets), key=len, reverse=True)
        self.limit = limit
        self.written = 0
        self.pending = ""
        self.truncated = False

    def _emit(self, text: str) -> None:
        data = text.encode("utf-8", errors="replace")
        remaining = self.limit - self.written
        if remaining <= 0:
            self.truncated |= bool(data)
            return
        chunk = data[:remaining]
        self.handle.write(chunk)
        self.written += len(chunk)
        self.truncated |= len(chunk) != len(data)

    def feed(self, text: str, final: bool = False) -> None:
        self.pending += text
        while self.pending:
            matches = [(self.pending.find(secret), secret) for secret in self.secrets]
            matches = [(index, secret) for index, secret in matches if index >= 0]
            if matches:
                index, secret = min(matches, key=lambda item: item[0])
                self._emit(self.pending[:index] + "[REDACTED]")
                self.pending = self.pending[index + len(secret):]
                continue
            if final:
                self._emit(self.pending)
                self.pending = ""
                break
            hold = 0
            for secret in self.secrets:
                maximum = min(len(secret) - 1, len(self.pending))
                for length in range(maximum, 0, -1):
                    if self.pending.endswith(secret[:length]):
                        hold = max(hold, length)
                        break
            if len(self.pending) == hold:
                break
            self._emit(self.pending[:-hold] if hold else self.pending)
            self.pending = self.pending[-hold:] if hold else ""

    def close(self) -> None:
        self.feed("", final=True)
        self.handle.flush()
        os.fsync(self.handle.fileno())
        self.handle.close()


def load_redactions(path: Path) -> list[str]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ProtocolError(f"invalid redaction file: {exc}") from exc
    if not isinstance(value, list) or not value:
        raise ProtocolError("redaction file must be a non-empty JSON array")
    if any(not isinstance(item, str) or len(item) < 4 for item in value):
        raise ProtocolError("every redaction value must be a string of at least four characters")
    return value


def write_started(directory: Path, manifest: dict, state: str, pid: int) -> None:
    atomic_json(directory / "started.json", {
        "schema": "virtusphere.remote.started/v1",
        "run_token": manifest["run_token"],
        "unit_name": manifest["unit_name"],
        "state": state,
        "pid": pid,
        "written_at": utc_now(),
    }, "started")


def write_heartbeat(directory: Path, manifest: dict, pid: int) -> None:
    atomic_json(directory / "heartbeat.json", {
        "schema": "virtusphere.remote.heartbeat/v1",
        "run_token": manifest["run_token"],
        "pid": pid,
        "written_at": utc_now(),
    }, "heartbeat")


def execute(manifest_path: Path, token: str) -> int:
    manifest = validate_manifest(manifest_path, token)
    directory = manifest_path.parent
    result_path = directory / "result.json"
    if result_path.exists():
        existing = json.loads(result_path.read_text(encoding="utf-8"))
        validate_document("result", existing)
        return 0 if existing.get("outcome") == "completed" else 1

    started_at = utc_now()
    write_started(directory, manifest, "prepared", 0)
    writer: RedactingWriter | None = None
    process: subprocess.Popen[bytes] | None = None
    old_handlers: dict[int, object] = {}

    def forward(signum: int, _frame: object) -> None:
        if process is not None and process.poll() is None:
            os.killpg(process.pid, signum)

    try:
        redactions = load_redactions(directory / manifest["redaction_file"])
        executable = shutil.which("ansible-playbook")
        if executable is None:
            raise ProtocolError("ansible-playbook is not installed")
        writer = RedactingWriter(directory / "output.log", redactions, manifest["output_max_bytes"])
        command = [executable, manifest["playbook"]]
        process = subprocess.Popen(
            command,
            cwd=directory,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            start_new_session=True,
        )
        write_started(directory, manifest, "running", process.pid)
        write_heartbeat(directory, manifest, process.pid)
        for signum in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
            old_handlers[signum] = signal.signal(signum, forward)

        selector = selectors.DefaultSelector()
        assert process.stdout is not None
        selector.register(process.stdout, selectors.EVENT_READ)
        next_heartbeat = time.monotonic() + manifest["heartbeat_interval_seconds"]
        while selector.get_map():
            timeout = max(0.0, next_heartbeat - time.monotonic())
            for key, _mask in selector.select(timeout):
                chunk = os.read(key.fileobj.fileno(), 65536)
                if chunk:
                    writer.feed(chunk.decode("utf-8", errors="replace"))
                else:
                    selector.unregister(key.fileobj)
            if time.monotonic() >= next_heartbeat and process.poll() is None:
                write_heartbeat(directory, manifest, process.pid)
                next_heartbeat = time.monotonic() + manifest["heartbeat_interval_seconds"]
        selector.close()
        process.stdout.close()
        exit_code = process.wait()
        writer.close()
        outcome = "completed" if exit_code == 0 else "failed"
        atomic_json(result_path, {
            "schema": "virtusphere.remote.result/v1",
            "run_token": manifest["run_token"],
            "unit_name": manifest["unit_name"],
            "outcome": outcome,
            "exit_code": max(-1, min(255, exit_code)),
            "output_truncated": writer.truncated,
            "started_at": started_at,
            "finished_at": utc_now(),
        }, "result")
        return 0 if exit_code == 0 else 1
    except Exception:
        if writer is not None and not writer.handle.closed:
            writer.close()
        atomic_json(result_path, {
            "schema": "virtusphere.remote.result/v1",
            "run_token": manifest["run_token"],
            "unit_name": manifest["unit_name"],
            "outcome": "runner_error",
            "exit_code": -1,
            "output_truncated": writer.truncated if writer is not None else False,
            "started_at": started_at,
            "finished_at": utc_now(),
        }, "result")
        raise
    finally:
        for signum, handler in old_handlers.items():
            signal.signal(signum, handler)


def main(argv: list[str]) -> int:
    if len(argv) != 3:
        print("usage: virtusphere_remote_runner.py MANIFEST RUN_TOKEN", file=sys.stderr)
        return 2
    try:
        return execute(Path(argv[1]), argv[2])
    except (ProtocolError, OSError, subprocess.SubprocessError, json.JSONDecodeError) as exc:
        print(f"runner: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
