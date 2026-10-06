#!/usr/bin/env python3
"""Exercise the async seam and production Prepare, Launch and Status playbooks."""

from __future__ import annotations

import base64
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time


MARKER = "::virtusphere-create::"


def run(command: list[str], *, cwd: Path, env: dict[str, str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(command, cwd=cwd, env=env, text=True, capture_output=True)


def decode_marker(line: str) -> dict[str, object]:
    parts = line.strip().split(" ")
    if len(parts) != 3 or parts[:2] != [MARKER, "v1"]:
        raise AssertionError(f"invalid create marker: {line!r}")
    encoded = parts[2] + "=" * ((4 - len(parts[2]) % 4) % 4)
    return json.loads(base64.urlsafe_b64decode(encoded).decode("utf-8"))


DEFAULT_INVENTORY = [{"guest_name": "fixture-vm", "moid": "vm-42", "instance_uuid": "fixture-uuid"}]


def write_stub_collection(root: Path, inventory: list[dict[str, str]] | None = None, read_failure: str | None = None) -> Path:
    modules = root / "ansible_collections" / "community" / "vmware" / "plugins" / "modules"
    modules.mkdir(parents=True)
    common = """from ansible.module_utils.basic import AnsibleModule
def module(extra=None):
    spec = dict(hostname=dict(type='str'), port=dict(type='int'), username=dict(type='str'), password=dict(type='str', no_log=True), validate_certs=dict(type='bool'), datacenter=dict(type='str'), moid=dict(type='str'))
    spec.update(extra or {})
    return AnsibleModule(argument_spec=spec)
"""
    listing = json.dumps(DEFAULT_INVENTORY if inventory is None else inventory)
    (modules / "vmware_vm_info.py").write_text(
        common
        + "import json\nm=module()\n"
        + ("m.fail_json(msg='synthetic identity read failure')\n" if read_failure == "identity" else "")
        + "m.exit_json(changed=False, virtual_machines=json.loads(" + repr(listing) + "))\n",
        encoding="utf-8",
    )
    (modules / "vmware_guest_info.py").write_text(
        common + "m=module()\n"
        + ("m.fail_json(msg='synthetic power read failure')\n" if read_failure == "power" else "")
        + "m.exit_json(changed=False, instance={'hw_power_status':'poweredOff'})\n",
        encoding="utf-8",
    )
    (modules / "vmware_guest.py").write_text(
        common + """import json
import os
m = module(dict(name=dict(type='str'), uuid=dict(type='str'), use_instance_uuid=dict(type='bool'), state=dict(type='str'), folder=dict(type='str'), guest_id=dict(type='str'), datastore=dict(type='str'), disk=dict(type='list'), hardware=dict(type='dict'), networks=dict(type='list')))
with open(os.environ['VS_GUEST_CALLS'], 'a', encoding='utf-8') as calls:
    calls.write(json.dumps({key: m.params[key] for key in ('name', 'uuid', 'use_instance_uuid', 'state')}) + '\\n')
m.exit_json(changed=True)
""",
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


def write_vars(work: Path, stored_uuid: str = "") -> None:
    (work / "serverlist.yml").write_text(
        """---
vm_configurations:
  - portal_vm_id: 1
    vm_name: fixture-vm
    vm_instance_uuid: '""" + stored_uuid + """'
    datacenter_name: ha-datacenter
    guest_id: windows9Server64Guest
    datastore_name: fixture-datastore
    memory: 4096
    vcpus: 2
    disks: []
    network: []
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


def run_status_case(repo: Path, label: str, source: str | None, expected: dict[str, object], remove_after_snapshot: bool = False, read_failure: str | None = None) -> None:
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
        collection_root = write_stub_collection(work / "collections", read_failure=read_failure)
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
        if read_failure == "identity":
            if completed.returncode == 0 or result_file.exists() or "synthetic identity read failure" not in completed.stdout:
                raise AssertionError(f"{label}: identity read failure must abort without a marker\n{completed.stdout}\n{completed.stderr}")
            return
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


def run_launch_case(repo: Path, label: str, power_state: str | None) -> None:
    """Execute the resumed prepared-unit path and observe actual module calls."""
    with tempfile.TemporaryDirectory(prefix="vs-create-launch-") as raw:
        work = Path(raw)
        for name in ("createVMLaunch-ESXi_playbook.yml", "create_identity_check_tasks.yml", "emit_create_result.py"):
            shutil.copy2(repo / "Ansible" / name, work / name)
        write_vars(work, "fixture-uuid")
        inventory = [dict(DEFAULT_INVENTORY[0])]
        if power_state is not None:
            inventory[0]["power_state"] = power_state
        collection_root = write_stub_collection(work / "collections", inventory)
        env = os.environ.copy()
        env["ANSIBLE_COLLECTIONS_PATH"] = f"{collection_root}:{env.get('ANSIBLE_COLLECTIONS_PATH', '/usr/share/ansible/collections')}"
        calls_file = work / "guest-calls.jsonl"
        env["VS_GUEST_CALLS"] = str(calls_file)
        async_dir = work / "async"
        async_dir.mkdir()
        result_file = work / "launch.json"
        extra = {"vs_portal_vm_id": 1, "vs_result_file": str(result_file), "vs_async_dir": str(async_dir),
                 "vs_async_timeout": 15, "vs_expected_existed_before": True,
                 "vs_expected_moid": "vm-42", "vs_expected_instance_uuid": "fixture-uuid"}
        completed = run(["ansible-playbook", "createVMLaunch-ESXi_playbook.yml", "-e", json.dumps(extra)], cwd=work, env=env)
        if completed.returncode != 0:
            raise AssertionError(f"{label}: launch failed\n{completed.stdout}\n{completed.stderr}")
        emitted = run(["python3", "emit_create_result.py", str(result_file)], cwd=work, env=env)
        if emitted.returncode != 0:
            raise AssertionError(f"{label}: marker emission failed\n{emitted.stdout}\n{emitted.stderr}")
        payload = decode_marker(emitted.stdout.strip())
        if power_state != "poweredOff":
            if payload.get("event") != "rejected" or payload.get("error_code") != "vm_not_powered_off":
                raise AssertionError(f"{label}: unsafe launch instead of power rejection: {payload!r}")
            if calls_file.exists() or list(async_dir.iterdir()):
                raise AssertionError(f"{label}: forbidden vmware_guest call or async launch")
            return
        if payload.get("event") != "launched" or payload.get("async_dir") != str(async_dir):
            raise AssertionError(f"{label}: powered-off VM was not launched: {payload!r}")
        state_file = async_dir / str(payload["async_jid"])
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            try:
                state = json.loads(state_file.read_text(encoding="utf-8"))
            except (FileNotFoundError, json.JSONDecodeError):
                state = {}
            if "changed" in state or state.get("failed"):
                break
            time.sleep(0.05)
        else:
            raise AssertionError(f"{label}: stub async result did not become terminal")
        if state.get("failed") or state.get("changed") is not True:
            raise AssertionError(f"{label}: stub async execution failed: {state!r}")
        calls = [json.loads(line) for line in calls_file.read_text(encoding="utf-8").splitlines()]
        if calls != [{"name": None, "uuid": "fixture-uuid", "use_instance_uuid": True, "state": "present"}]:
            raise AssertionError(f"{label}: expected exactly one UUID-bound mutation, got {calls!r}")


def run_prepare_case(repo: Path, label: str, stored_uuid: str, inventory: list[dict[str, str]], expected: dict[str, object]) -> None:
    """IDR-P02: the production preparation against a synthetic live inventory.

    The identity matrix is evaluated by ansible-core itself, so the Jinja the
    shared check uses (UUID lookup, case folding, regex escaping, precedence)
    is proven here rather than by reading its text.
    """
    with tempfile.TemporaryDirectory(prefix="vs-create-prepare-") as raw:
        work = Path(raw)
        for name in ("createVMPrepare-ESXi_playbook.yml", "create_identity_check_tasks.yml", "emit_create_result.py"):
            shutil.copy2(repo / "Ansible" / name, work / name)
        write_vars(work, stored_uuid)
        collection_root = write_stub_collection(work / "collections", inventory)
        result_file = work / "prepare.json"
        env = os.environ.copy()
        installed = env.get("ANSIBLE_COLLECTIONS_PATH", "/usr/share/ansible/collections")
        env["ANSIBLE_COLLECTIONS_PATH"] = f"{collection_root}:{installed}"
        extra = {"vs_portal_vm_id": 1, "vs_result_file": str(result_file)}
        completed = run(["ansible-playbook", "createVMPrepare-ESXi_playbook.yml", "-e", json.dumps(extra)], cwd=work, env=env)
        if completed.returncode != 0:
            raise AssertionError(f"{label}: production playbook failed\n{completed.stdout}\n{completed.stderr}")
        emitted = run(["python3", "emit_create_result.py", str(result_file)], cwd=work, env=env)
        if emitted.returncode != 0:
            raise AssertionError(f"{label}: marker emission failed\n{emitted.stdout}\n{emitted.stderr}")
        payload = decode_marker(emitted.stdout.strip())
        for key, value in expected.items():
            if payload.get(key, "<absent>") != value:
                raise AssertionError(f"{label}: expected {key}={value!r}, got {payload!r}")


def vm(name: str, moid: str, uuid: str) -> dict[str, str]:
    return {"guest_name": name, "moid": moid, "instance_uuid": uuid}


PREPARE_CASES = [
    ("prepare-first-create", "", [], {"event": "prepared", "existed_before": False, "replaced_instance_uuid": None, "precheck_power_state": None}),
    ("prepare-bound-present", "uuid-a", [vm("fixture-vm", "vm-42", "uuid-a")],
     {"event": "prepared", "existed_before": True, "precheck_instance_uuid": "uuid-a", "replaced_instance_uuid": None, "precheck_power_state": None}),
    ("prepare-bound-powered-on", "uuid-a", [dict(vm("fixture-vm", "vm-42", "uuid-a"), power_state="poweredOn")],
     {"event": "prepared", "precheck_power_state": "poweredOn"}),
    ("prepare-bound-absent", "uuid-a", [vm("other-vm", "vm-7", "uuid-b")],
     {"event": "prepared", "existed_before": False, "replaced_instance_uuid": "uuid-a"}),
    ("prepare-bound-renamed", "uuid-a", [vm("renamed-vm", "vm-42", "uuid-a")],
     {"event": "rejected", "error_code": "identity_bound_vm_renamed"}),
    ("prepare-renamed-uuid-case", "UUID-A", [vm("renamed-vm", "vm-42", "uuid-a")],
     {"event": "rejected", "error_code": "identity_bound_vm_renamed"}),
    ("prepare-foreign-namesake", "uuid-a", [vm("fixture-vm", "vm-9", "uuid-z")],
     {"event": "rejected", "error_code": "identity_conflict"}),
    ("prepare-renamed-beside-foreign-namesake", "uuid-a", [vm("fixture-vm", "vm-9", "uuid-z"), vm("renamed-vm", "vm-42", "uuid-a")],
     {"event": "rejected", "error_code": "identity_bound_vm_renamed"}),
    ("prepare-duplicate-names", "uuid-a", [vm("fixture-vm", "vm-8", "uuid-a"), vm("fixture-vm", "vm-9", "uuid-z")],
     {"event": "rejected", "error_code": "identity_conflict"}),
    ("prepare-unbound-foreign-namesake", "", [vm("fixture-vm", "vm-9", "uuid-z")],
     {"event": "rejected", "error_code": "identity_conflict"}),
    ("prepare-uuid-regex-escaped", "uuid.a", [vm("other-vm", "vm-1", "uuidXa")],
     {"event": "prepared", "existed_before": False, "replaced_instance_uuid": "uuid.a"}),
]


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
    cases += [(label, ("prepare", stored, inventory, expected)) for label, stored, inventory, expected in PREPARE_CASES]
    cases += [("launch-powered-on", ("launch", "poweredOn")),
              ("launch-power-unknown", ("launch", None)),
              ("launch-suspended", ("launch", "suspended")),
              ("launch-powered-off", ("launch", "poweredOff")),
              ("status-power-read-failure", ("read-failure", "power")),
              ("status-identity-read-failure", ("read-failure", "identity"))]

    for index, (label, case) in enumerate(cases, start=1):
        print(f"[{index}/{len(cases)}] RUN {label}", flush=True)
        try:
            if case is not None and case[0] == "prepare":
                _, stored, inventory, expected = case
                run_prepare_case(repo, label, stored, inventory, expected)
            elif case is not None and case[0] == "launch":
                run_launch_case(repo, label, case[1])
            elif case is not None and case[0] == "read-failure":
                run_status_case(repo, label, '{"failed":false,"changed":true}',
                                {"event": "succeeded", "changed": True, "power_state": "unknown"}, read_failure=case[1])
            elif case is None:
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
