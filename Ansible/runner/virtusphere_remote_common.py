#!/usr/bin/env python3
"""Closed protocol and filesystem checks for the durable remote runner."""

from __future__ import annotations

import hashlib
import json
import os
import re
import stat
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

PROTOCOL_FILE = Path(__file__).with_name("protocol-v1.json")
STATE_ROOT = Path.home() / ".local" / "state" / "virtusphere"
INSTALL_ROOT = Path.home() / ".local" / "libexec" / "virtusphere"
STEP_PLAYBOOKS = {
    "inventory": "inventoryESXi_playbook.yml",
    "export": "exportVMs-Informations-ESXi_playbook.yml",
    "start": "startVMs-ESXi_playbook.yml",
    "autostart": "autostartVMs-ESXi_playbook.yml",
    "powercycle": "powercycleVMs-ESXi_playbook.yml",
}


class ProtocolError(ValueError):
    """An input violates the closed runner protocol."""


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def load_protocol() -> dict[str, Any]:
    with PROTOCOL_FILE.open("r", encoding="utf-8") as handle:
        protocol = json.load(handle)
    if protocol.get("protocol") != 1 or not isinstance(protocol.get("schemas"), dict):
        raise ProtocolError("protocol-v1.json has an unsupported root")
    return protocol


def _type_matches(value: Any, expected: str) -> bool:
    if expected == "object":
        return isinstance(value, dict)
    if expected == "array":
        return isinstance(value, list)
    if expected == "string":
        return isinstance(value, str)
    if expected == "integer":
        return isinstance(value, int) and not isinstance(value, bool)
    if expected == "boolean":
        return isinstance(value, bool)
    return False


def _validate(value: Any, rule: dict[str, Any], path: str) -> None:
    if "const" in rule and value != rule["const"]:
        raise ProtocolError(f"{path}: expected constant {rule['const']!r}")
    expected = rule.get("type")
    if expected is not None and not _type_matches(value, expected):
        raise ProtocolError(f"{path}: expected {expected}")
    if "enum" in rule and value not in rule["enum"]:
        raise ProtocolError(f"{path}: value is not in the closed enum")
    if isinstance(value, dict):
        properties = rule.get("properties", {})
        missing = [key for key in rule.get("required", []) if key not in value]
        if missing:
            raise ProtocolError(f"{path}: missing {', '.join(missing)}")
        if rule.get("additionalProperties") is False:
            unknown = sorted(set(value) - set(properties))
            if unknown:
                raise ProtocolError(f"{path}: unknown field(s): {', '.join(unknown)}")
        for key, child in value.items():
            if key in properties:
                _validate(child, properties[key], f"{path}.{key}")
    elif isinstance(value, list):
        if len(value) < rule.get("minItems", 0) or len(value) > rule.get("maxItems", len(value)):
            raise ProtocolError(f"{path}: array length outside bounds")
        for index, child in enumerate(value):
            _validate(child, rule.get("items", {}), f"{path}[{index}]")
    elif isinstance(value, str):
        if len(value) < rule.get("minLength", 0) or len(value) > rule.get("maxLength", len(value)):
            raise ProtocolError(f"{path}: string length outside bounds")
        pattern = rule.get("pattern")
        if pattern is not None and re.fullmatch(pattern, value) is None:
            raise ProtocolError(f"{path}: string does not match the protocol pattern")
    elif isinstance(value, int) and not isinstance(value, bool):
        if value < rule.get("minimum", value) or value > rule.get("maximum", value):
            raise ProtocolError(f"{path}: integer outside bounds")


def validate_document(name: str, document: dict[str, Any]) -> None:
    schema = load_protocol()["schemas"].get(name)
    if schema is None:
        raise ProtocolError(f"unknown protocol document: {name}")
    _validate(document, schema, name)


def read_json(path: Path) -> dict[str, Any]:
    try:
        with path.open("r", encoding="utf-8") as handle:
            value = json.load(handle)
    except (OSError, json.JSONDecodeError) as exc:
        raise ProtocolError(f"cannot read JSON {path.name}: {exc}") from exc
    if not isinstance(value, dict):
        raise ProtocolError(f"{path.name}: JSON root must be an object")
    return value


def atomic_json(path: Path, document: dict[str, Any], schema: str) -> None:
    validate_document(schema, document)
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(document, handle, ensure_ascii=True, separators=(",", ":"), sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
        directory_fd = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def safe_relative(value: str) -> Path:
    path = Path(value)
    if path.is_absolute() or value in ("", ".") or any(part in ("", ".", "..") for part in path.parts):
        raise ProtocolError(f"unsafe relative path: {value!r}")
    return path


def expected_remote_dir(manifest: dict[str, Any], root: Path = STATE_ROOT) -> Path:
    return root.joinpath(
        manifest["instance_id"], manifest["generation_id"], "jobs",
        str(manifest["job_id"]), str(manifest["attempt"]), manifest["step"], manifest["run_token"],
    )


def expected_unit_name(manifest: dict[str, Any]) -> str:
    return (
        f"virtusphere-j{manifest['job_id']}-a{manifest['attempt']}-"
        f"{manifest['step']}-{manifest['run_token'][:12]}.service"
    )


def _check_node(path: Path, uid: int, require_directory: bool) -> None:
    info = path.lstat()
    if stat.S_ISLNK(info.st_mode):
        raise ProtocolError(f"symlink is forbidden: {path}")
    if info.st_uid != uid:
        raise ProtocolError(f"wrong owner: {path}")
    if info.st_mode & 0o077:
        raise ProtocolError(f"group/world permissions are forbidden: {path}")
    if require_directory and not stat.S_ISDIR(info.st_mode):
        raise ProtocolError(f"not a directory: {path}")
    if not require_directory and not stat.S_ISREG(info.st_mode):
        raise ProtocolError(f"not a regular file: {path}")


def validate_manifest(path: Path, supplied_token: str, root: Path = STATE_ROOT) -> dict[str, Any]:
    if path.is_symlink():
        raise ProtocolError("manifest symlink is forbidden")
    manifest = read_json(path)
    validate_document("manifest", manifest)
    if manifest["run_token"] != supplied_token:
        raise ProtocolError("run token does not match manifest")
    expected_dir = expected_remote_dir(manifest, root)
    if Path(manifest["remote_dir"]) != expected_dir or path.parent != expected_dir or path.name != "manifest.json":
        raise ProtocolError("manifest path is not the derived run directory")
    if manifest["unit_name"] != expected_unit_name(manifest):
        raise ProtocolError("unit name is not derived from immutable identity")
    expected_playbook = STEP_PLAYBOOKS[manifest["step"]]
    if manifest["playbook"] != expected_playbook:
        raise ProtocolError("step/playbook policy mismatch")

    uid = os.getuid()
    root_resolved = root.resolve(strict=True)
    expected_resolved = expected_dir.resolve(strict=True)
    if root_resolved not in expected_resolved.parents:
        raise ProtocolError("run directory escapes the state root")
    current = root_resolved
    _check_node(current, uid, True)
    for part in expected_resolved.relative_to(root_resolved).parts:
        current /= part
        _check_node(current, uid, True)
    _check_node(path, uid, False)

    seen: set[str] = set()
    playbook_seen = False
    redaction_seen = False
    for artifact in manifest["artifacts"]:
        relative = safe_relative(artifact["path"])
        if artifact["path"] in seen:
            raise ProtocolError("duplicate artifact path")
        seen.add(artifact["path"])
        artifact_parent = expected_dir
        for part in relative.parts[:-1]:
            artifact_parent /= part
            _check_node(artifact_parent, uid, True)
        target = artifact_parent / relative.name
        _check_node(target, uid, False)
        if expected_resolved not in target.resolve(strict=True).parents:
            raise ProtocolError(f"artifact escapes the run directory: {artifact['path']}")
        if target.stat().st_size != artifact["size"] or sha256_file(target) != artifact["sha256"]:
            raise ProtocolError(f"artifact integrity mismatch: {artifact['path']}")
        playbook_seen |= artifact["kind"] == "playbook" and artifact["path"] == manifest["playbook"]
        redaction_seen |= artifact["kind"] == "redaction" and artifact["path"] == manifest["redaction_file"]
    if not playbook_seen or not redaction_seen:
        raise ProtocolError("playbook or redaction artifact is not declared with its required kind")
    if manifest["playbook_sha256"] != sha256_file(expected_dir / manifest["playbook"]):
        raise ProtocolError("playbook hash does not match")
    return manifest
