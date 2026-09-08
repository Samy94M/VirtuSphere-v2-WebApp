#!/usr/bin/env python3
"""Verify that the requirements lock is the exact installed dependency closure."""

from __future__ import annotations

import json
import pathlib
import re
import sys

import yaml
from packaging.specifiers import InvalidSpecifier, SpecifierSet
from packaging.version import InvalidVersion, Version


def fail(code: str, detail: str) -> None:
    print(f"FEHLER: [ansible-module-contract.{code}] {detail}", file=sys.stderr)


def main() -> int:
    if len(sys.argv) < 4:
        fail("collection-lock-args", "requirements, collection root and at least one used collection are required.")
        return 2

    requirements = pathlib.Path(sys.argv[1])
    collection_root = pathlib.Path(sys.argv[2])
    roots = sorted(set(sys.argv[3:]))
    try:
        document = yaml.safe_load(requirements.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, yaml.YAMLError) as error:
        fail("collection-lock-invalid", f"{requirements} is unreadable: {error}")
        return 2

    entries = document.get("collections") if isinstance(document, dict) else None
    if not isinstance(entries, list) or not entries:
        fail("collection-lock-invalid", f"{requirements} contains no collection pins.")
        return 2

    pins: dict[str, str] = {}
    for entry in entries:
        name = entry.get("name") if isinstance(entry, dict) else None
        version = entry.get("version") if isinstance(entry, dict) else None
        if not isinstance(name, str) or not re.fullmatch(r"[a-z0-9_]+\.[a-z0-9_]+", name):
            fail("collection-lock-invalid", "every collection needs a canonical namespace.name.")
            return 2
        if not isinstance(version, str) or not re.fullmatch(r"[0-9][0-9A-Za-z.-]*", version):
            fail("collection-lock-invalid", f"{name} has no exact version pin.")
            return 2
        if name in pins:
            fail("collection-lock-invalid", f"{name} is pinned more than once.")
            return 2
        pins[name] = version

    total = len(pins)
    manifests: dict[str, dict] = {}
    errors = 0
    for position, (name, expected) in enumerate(pins.items(), 1):
        print(f"[{position}/{total}] RUN collection-lock-{name}", flush=True)
        namespace, collection = name.split(".", 1)
        manifest_path = collection_root / namespace / collection / "MANIFEST.json"
        local_errors: list[tuple[str, str]] = []
        try:
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        except (OSError, UnicodeError, ValueError) as error:
            local_errors.append(("collection-missing", f"{name}: {manifest_path} is unreadable: {error}"))
            manifest = {}
        info = manifest.get("collection_info") if isinstance(manifest, dict) else None
        info = info if isinstance(info, dict) else {}
        installed = str(info.get("version", ""))
        if installed != expected:
            local_errors.append(("version-mismatch", f"{name} {installed or 'missing'} is installed, lock requires {expected}."))
        dependencies = info.get("dependencies", {})
        if not isinstance(dependencies, dict):
            local_errors.append(("collection-manifest-invalid", f"{name} has no readable dependency map."))
            dependencies = {}
        manifests[name] = dependencies
        for dependency, constraint in dependencies.items():
            if dependency not in pins:
                local_errors.append(("unpinned-dependency", f"{name} requires {dependency} {constraint}, but the lock has no exact pin."))
                continue
            try:
                if Version(pins[dependency]) not in SpecifierSet(str(constraint)):
                    local_errors.append(("dependency-version", f"{name} requires {dependency} {constraint}, lock pins incompatible {pins[dependency]}."))
            except (InvalidSpecifier, InvalidVersion):
                local_errors.append(("collection-manifest-invalid", f"{name} has unreadable dependency constraint {dependency} {constraint}."))
        for code, detail in local_errors:
            fail(code, detail)
        if local_errors:
            errors += len(local_errors)
            print(f"[{position}/{total}] FAIL collection-lock-{name}", flush=True)
        else:
            print(f"[{position}/{total}] PASS collection-lock-{name}", flush=True)

    reachable = set(roots)
    pending = list(roots)
    while pending:
        current = pending.pop()
        if current not in pins:
            fail("unpinned-dependency", f"used collection {current} has no exact pin.")
            errors += 1
            continue
        for dependency in manifests.get(current, {}):
            if dependency not in reachable:
                reachable.add(dependency)
                pending.append(dependency)
    stale = sorted(set(pins) - reachable)
    for name in stale:
        fail("stale-collection-pin", f"{name} is pinned but is neither used nor in the installed dependency closure.")
        errors += 1

    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
