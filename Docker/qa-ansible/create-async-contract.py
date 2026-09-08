#!/usr/bin/env python3
"""Exercise the async seam and the production create status playbook."""

from __future__ import annotations

import base64
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile


MARKER = "::virtusphere-create::"


def run(command: list[str], *, cwd: Path, env: dict[str, str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(command, cwd=cwd, env=env, text=True, capture_output=True)


def decode_marker(line: str) -> dict[str, object]:
    parts = line.strip().split(" ")
    if len(parts) != 3 or parts[:2] != [MARKER, "v1"]:
        raise AssertionError(f"invalid create marker: {line!r}")
    encoded = parts[2] + "=" * ((4 - len(parts[2]) % 4) % 4)
    return json.loads(base64.urlsafe_b64decode(encoded).decode("utf-8"))


def write_stub_collection(root: Path) -> Path:
    modules = root / "ansible_collections" / "community" / "vmware" / "plugins" / "modules"
    modules.mkdir(parents=True)
    common = """from ansible.module_utils.basic import AnsibleModule
def module():
    return AnsibleModule(argument_spec=dict(hostname=dict(type='str'), port=dict(type='int'), username=dict(type='str'), password=dict(type='str', no_log=True), validate_certs=dict(type='bool'), datacenter=dict(type='str'), moid=dict(type='str')))
"""
    (modules / "vmware_vm_info.py").write_text(
        common
        + "m=module()\nm.exit_json(changed=False, virtual_machines=[{'guest_name':'fixture-vm','moid':'vm-42','instance_uuid':'fixture-uuid'}])\n",
        encoding="utf-8",
    )
    (modules / "vmware_guest_info.py").write_text(
        common + "m=module()\nm.exit_json(changed=False, instance={'hw_power_status':'poweredOff'})\n",
        encoding="utf-8",
    )
    return root


def write_callback(root: Path) -> Path:
    callbacks = root / "callbacks"
    callbacks.mkdir()
    (callbacks / "remove_async_state.py").write_text(
        """from ansible.plugins.callback import CallbackBase
import os
class CallbackModule(CallbackBase):
    CALLBACK_VERSION = 2.0
    CALLBACK_TYPE = 'aggregate'
    CALLBACK_NAME = 'remove_async_state'
    def v2_runner_on_ok(self, result, **kwargs):
        if result._task.get_name().endswith('Statusdatei des Async-Jobs gebunden lesen'):
            target = os.environ.get('VS_REMOVE_ASYNC_STATE', '')
            if target:
                try:
                    os.unlink(target)
                except FileNotFoundError:
                    pass
""",
        encoding="utf-8",
    )
    return callbacks


def write_vars(work: Path) -> None:
    (work / "serverlist.yml").write_text(
        """---
vm_configurations:
  - portal_vm_id: 1
    vm_name: fixture-vm
    vm_instance_uuid: ''
    datacenter_name: ha-datacenter
""",
        encoding="utf-8",
    )
    (work / "accounts.yml").write_text(
        """---
esxi_hostname: fixture.invalid
esxi_port: 443
esxi_username: fixture
esxi_password: synthetic-secret
esxi_validate_certs: false
esxi_ca_bundle_path: ''
""",
        encoding="utf-8",
    )


def run_status_case(repo: Path, label: str, source: str | None, expected: dict[str, object], remove_after_snapshot: bool = False) -> None:
    with tempfile.TemporaryDirectory(prefix="vs-create-status-") as raw:
        work = Path(raw)
        for name in (
            "createVMStatus-ESXi_playbook.yml",
            "create_identity_check_tasks.yml",
            "inspect_create_async_state.py",
            "emit_create_result.py",
        ):
            shutil.copy2(repo / "Ansible" / name, work / name)
        write_vars(work)
        collection_root = write_stub_collection(work / "collections")
        callback_root = write_callback(work)
        async_dir = work / "async"
        async_dir.mkdir()
        jid = "1234567890.42"
        state_file = async_dir / jid
        if source is not None:
            state_file.write_text(source, encoding="utf-8")
        result_file = work / "status.json"

        env = os.environ.copy()
        installed = env.get("ANSIBLE_COLLECTIONS_PATH", "/usr/share/ansible/collections")
        env["ANSIBLE_COLLECTIONS_PATH"] = f"{collection_root}:{installed}"
        env["ANSIBLE_CALLBACK_PLUGINS"] = str(callback_root)
        env["ANSIBLE_CALLBACKS_ENABLED"] = "remove_async_state"
        if remove_after_snapshot:
            env["VS_REMOVE_ASYNC_STATE"] = str(state_file)

        extra = {
            "vs_portal_vm_id": 1,
            "vs_async_jid": jid,
            "vs_async_dir": str(async_dir),
            "vs_result_file": str(result_file),
        }
        completed = run(
            ["ansible-playbook", "createVMStatus-ESXi_playbook.yml", "-e", json.dumps(extra)],
            cwd=work,
            env=env,
        )
        if completed.returncode != 0:
            raise AssertionError(f"{label}: production playbook failed\n{completed.stdout}\n{completed.stderr}")
        emitted = run(["python3", "emit_create_result.py", str(result_file)], cwd=work, env=env)
        if emitted.returncode != 0:
            raise AssertionError(f"{label}: marker emission failed\n{emitted.stdout}\n{emitted.stderr}")
        payload = decode_marker(emitted.stdout.strip())
        for key, value in expected.items():
            if payload.get(key) != value:
                raise AssertionError(
                    f"{label}: expected {key}={value!r}, got {payload!r}\n"
                    f"production stdout:\n{completed.stdout}\nproduction stderr:\n{completed.stderr}"
                )


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print("usage: create-async-contract.py <repo-root>", file=sys.stderr)
        return 2
    repo = Path(argv[1]).resolve()
    cases = [
        ("async-core-seam", None),
        ("changed-success", ('{"failed":false,"changed":true}', {"event": "succeeded", "changed": True}, False)),
        ("unchanged-success", ('{"failed":false,"changed":false}', {"event": "succeeded", "changed": False}, False)),
        ("terminal-module-failure", ('{"failed":true,"changed":false,"msg":"synthetic module failure"}', {"event": "failed", "error": "synthetic module failure"}, False)),
        ("running", ('{"started":true,"finished":false,"ansible_job_id":"1234567890.42"}', {"event": "running"}, False)),
        ("missing", (None, {"event": "rejected", "error_code": "async_state_missing"}, False)),
        ("empty", ("", {"event": "rejected", "error_code": "protocol_error"}, False)),
        ("corrupt", ("{not-json", {"event": "rejected", "error_code": "protocol_error"}, False)),
        ("removed-during-query", ('{"failed":false,"changed":true}', {"event": "rejected", "error_code": "async_state_missing"}, True)),
    ]

    for index, (label, case) in enumerate(cases, start=1):
        print(f"[{index}/{len(cases)}] RUN {label}", flush=True)
        try:
            if case is None:
                completed = subprocess.run(
                    ["ansible-playbook", str(repo / "Docker" / "qa-ansible" / "create-async-fixtures.yml")],
                    cwd=repo,
                    text=True,
                    capture_output=True,
                )
                if completed.returncode != 0:
                    raise AssertionError(completed.stdout + completed.stderr)
            else:
                source, expected, remove_after_snapshot = case
                run_status_case(repo, label, source, expected, remove_after_snapshot)
        except Exception as exc:
            print(f"[{index}/{len(cases)}] FAIL {label}: {exc}", flush=True)
            return 1
        print(f"[{index}/{len(cases)}] PASS {label}", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
