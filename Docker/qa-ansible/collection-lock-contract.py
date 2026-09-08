#!/usr/bin/env python3
"""Exercise the production collection-lock verifier, including closed failures."""

from __future__ import annotations

import json
import pathlib
import subprocess
import sys
import tempfile

import yaml


def write_manifest(root: pathlib.Path, name: str, version: str, dependencies: dict[str, str]) -> None:
    namespace, collection = name.split(".", 1)
    path = root / namespace / collection
    path.mkdir(parents=True, exist_ok=True)
    (path / "MANIFEST.json").write_text(
        json.dumps({"collection_info": {"version": version, "dependencies": dependencies}}),
        encoding="utf-8",
    )


def run(verifier: pathlib.Path, requirements: pathlib.Path, root: pathlib.Path, used: list[str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [sys.executable, str(verifier), str(requirements), str(root), *used],
        text=True,
        capture_output=True,
        check=False,
    )


def main() -> int:
    if len(sys.argv) < 5:
        print("collection-lock-contract: verifier, requirements, collection root and used collection required", file=sys.stderr)
        return 2
    verifier = pathlib.Path(sys.argv[1])
    live_requirements = pathlib.Path(sys.argv[2])
    live_root = pathlib.Path(sys.argv[3])
    used = sorted(set(sys.argv[4:]))

    with tempfile.TemporaryDirectory(prefix="virtusphere-collection-lock-") as temp:
        work = pathlib.Path(temp)
        cases: list[tuple[str, subprocess.CompletedProcess[str], int, str | None]] = []
        cases.append(("installed-closure", run(verifier, live_requirements, live_root, used), 0, None))

        missing_root = work / "missing"
        missing_requirements = work / "missing.yml"
        missing_requirements.write_text(yaml.safe_dump({"collections": [
            {"name": "community.vmware", "version": "6.2.0"},
            {"name": "vmware.vmware", "version": "2.9.0"},
        ]}, sort_keys=False), encoding="utf-8")
        write_manifest(missing_root, "community.vmware", "6.2.0", {"vmware.vmware": ">=2.5.0"})
        cases.append(("missing-installed", run(verifier, missing_requirements, missing_root, ["community.vmware"]), 1, "collection-missing"))

        mismatch_root = work / "mismatch"
        write_manifest(mismatch_root, "community.vmware", "6.1.0", {"vmware.vmware": ">=2.5.0"})
        write_manifest(mismatch_root, "vmware.vmware", "2.9.0", {})
        cases.append(("version-mismatch", run(verifier, missing_requirements, mismatch_root, ["community.vmware"]), 1, "version-mismatch"))

        unpinned_root = work / "unpinned"
        unpinned_requirements = work / "unpinned.yml"
        unpinned_requirements.write_text(yaml.safe_dump({"collections": [
            {"name": "community.vmware", "version": "6.2.0"},
        ]}, sort_keys=False), encoding="utf-8")
        write_manifest(unpinned_root, "community.vmware", "6.2.0", {"vmware.vmware": ">=2.5.0"})
        cases.append(("unpinned-dependency", run(verifier, unpinned_requirements, unpinned_root, ["community.vmware"]), 1, "unpinned-dependency"))

        total = len(cases)
        failed = False
        for position, (label, result, expected_code, expected_text) in enumerate(cases, 1):
            print(f"[{position}/{total}] RUN collection-lock-contract-{label}", flush=True)
            okay = result.returncode == expected_code and (expected_text is None or expected_text in result.stderr)
            if okay:
                print(f"[{position}/{total}] PASS collection-lock-contract-{label}", flush=True)
                continue
            failed = True
            print(f"[{position}/{total}] FAIL collection-lock-contract-{label}", flush=True)
            print(result.stdout, file=sys.stderr)
            print(result.stderr, file=sys.stderr)
        return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
