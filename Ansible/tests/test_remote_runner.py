from __future__ import annotations

import copy
import hashlib
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

RUNNER_DIR = Path(__file__).resolve().parents[1] / "runner"
sys.path.insert(0, str(RUNNER_DIR))

import virtusphere_remote_common as common  # noqa: E402
import virtusphere_remote_launcher as launcher  # noqa: E402
import virtusphere_remote_observer as observer  # noqa: E402
import virtusphere_remote_preflight as preflight  # noqa: E402
import virtusphere_remote_runner as runner  # noqa: E402


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


@unittest.skipUnless(os.name == "posix", "remote runner targets Linux/systemd")
class ProtocolTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name) / "state"
        self.root.mkdir(mode=0o700)
        os.chmod(self.root, 0o700)
        vectors = json.loads((RUNNER_DIR / "golden-vectors.json").read_text(encoding="utf-8"))
        self.vector = vectors["valid"]

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def _run_dir(self, manifest: dict) -> Path:
        directory = common.expected_remote_dir(manifest, self.root)
        directory.mkdir(mode=0o700, parents=True)
        current = self.root
        for part in directory.relative_to(self.root).parts:
            current /= part
            os.chmod(current, 0o700)
        return directory

    def _materialize(self) -> tuple[Path, dict]:
        manifest = copy.deepcopy(self.vector)
        directory = self._run_dir(manifest)
        playbook = b"---\n- hosts: localhost\n"
        redactions = b'["super-secret"]\n'
        (directory / manifest["playbook"]).write_bytes(playbook)
        (directory / "secrets").mkdir(mode=0o700)
        (directory / "secrets" / "redactions.json").write_bytes(redactions)
        for path in (directory / manifest["playbook"], directory / "secrets" / "redactions.json"):
            os.chmod(path, 0o600)
        manifest["remote_dir"] = str(directory)
        manifest["playbook_sha256"] = digest(playbook)
        manifest["artifacts"][0].update(sha256=digest(playbook), size=len(playbook))
        manifest["artifacts"][1].update(sha256=digest(redactions), size=len(redactions))
        manifest_path = directory / "manifest.json"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
        os.chmod(manifest_path, 0o600)
        return manifest_path, manifest

    def test_golden_vector_and_unknown_fields(self) -> None:
        common.validate_document("manifest", self.vector)
        for mutation in json.loads((RUNNER_DIR / "golden-vectors.json").read_text())["invalid"]:
            candidate = copy.deepcopy(self.vector)
            candidate.update(mutation["set"])
            with self.subTest(mutation["case"]), self.assertRaises(common.ProtocolError):
                common.validate_document("manifest", candidate)

    def test_checked_in_runner_checksum_manifest(self) -> None:
        expected = {}
        for line in (RUNNER_DIR / "SHA256SUMS").read_text(encoding="utf-8").splitlines():
            checksum, name = line.split(None, 1)
            expected[name] = checksum
        self.assertEqual(set(preflight.RUNNER_FILES), set(expected))
        for name, checksum in expected.items():
            self.assertEqual(checksum, common.sha256_file(RUNNER_DIR / name), name)

    def test_manifest_identity_and_hashes_are_verified(self) -> None:
        path, manifest = self._materialize()
        self.assertEqual(manifest, common.validate_manifest(path, manifest["run_token"], self.root))
        with self.assertRaisesRegex(common.ProtocolError, "run token"):
            common.validate_manifest(path, "f" * 32, self.root)
        (path.parent / manifest["playbook"]).write_text("changed", encoding="utf-8")
        with self.assertRaisesRegex(common.ProtocolError, "integrity"):
            common.validate_manifest(path, manifest["run_token"], self.root)

    def test_traversal_and_symlink_are_rejected(self) -> None:
        with self.assertRaises(common.ProtocolError):
            common.safe_relative("../secret")
        path, manifest = self._materialize()
        playbook = path.parent / manifest["playbook"]
        original = path.parent / "original.yml"
        playbook.rename(original)
        playbook.symlink_to(original.name)
        with self.assertRaisesRegex(common.ProtocolError, "symlink"):
            common.validate_manifest(path, manifest["run_token"], self.root)

    def test_symlinked_artifact_parent_is_rejected(self) -> None:
        path, manifest = self._materialize()
        secrets = path.parent / "secrets"
        real_secrets = path.parent / "real-secrets"
        secrets.rename(real_secrets)
        secrets.symlink_to(real_secrets.name, target_is_directory=True)
        with self.assertRaisesRegex(common.ProtocolError, "symlink"):
            common.validate_manifest(path, manifest["run_token"], self.root)

    def test_atomic_json_leaves_only_complete_document(self) -> None:
        target = self.root / "result.json"
        document = {
            "schema": "virtusphere.remote.result/v1", "run_token": "3" * 32,
            "unit_name": "unit.service", "outcome": "completed", "exit_code": 0,
            "output_truncated": False, "started_at": "2026-01-01T00:00:00Z",
            "finished_at": "2026-01-01T00:00:01Z",
        }
        common.atomic_json(target, document, "result")
        self.assertEqual(document, json.loads(target.read_text(encoding="utf-8")))
        self.assertEqual([], list(self.root.glob(".result.json.*")))


@unittest.skipUnless(os.name == "posix", "remote runner targets Linux/systemd")
class ExecutionTest(unittest.TestCase):
    def test_redaction_survives_chunk_boundaries_and_cap(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            target = Path(temporary) / "output.log"
            writer = runner.RedactingWriter(target, ["super-secret"], 24)
            writer.feed("prefix super-")
            writer.feed("secret suffix and overflow")
            writer.close()
            output = target.read_text(encoding="utf-8")
            self.assertNotIn("super-secret", output)
            self.assertIn("[REDACTED]", output)
            self.assertLessEqual(target.stat().st_size, 24)
            self.assertTrue(writer.truncated)

    def test_runner_writes_result_and_redacted_merged_output(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            redaction_path = directory / "redactions.json"
            redaction_path.write_text('["super-secret"]', encoding="utf-8")
            fake = directory / "ansible-playbook"
            fake.write_text(
                "#!/usr/bin/env python3\nimport sys\nprint('stdout super-secret')\nprint('stderr super-secret', file=sys.stderr)\n",
                encoding="utf-8",
            )
            os.chmod(fake, 0o700)
            manifest = copy.deepcopy(json.loads((RUNNER_DIR / "golden-vectors.json").read_text())["valid"])
            manifest.update(
                remote_dir=str(directory), redaction_file="redactions.json",
                output_max_bytes=4096, heartbeat_interval_seconds=5,
            )
            manifest_path = directory / "manifest.json"
            manifest_path.write_text("{}", encoding="utf-8")
            with mock.patch.object(runner, "validate_manifest", return_value=manifest), mock.patch.object(
                runner.shutil, "which", return_value=str(fake)
            ):
                self.assertEqual(0, runner.execute(manifest_path, manifest["run_token"]))
            result = json.loads((directory / "result.json").read_text(encoding="utf-8"))
            self.assertEqual("completed", result["outcome"])
            output = (directory / "output.log").read_text(encoding="utf-8")
            self.assertIn("stdout", output)
            self.assertIn("stderr", output)
            self.assertNotIn("super-secret", output)

    def test_runner_error_after_validation_is_atomic(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            (directory / "redactions.json").write_text('["super-secret"]', encoding="utf-8")
            manifest = copy.deepcopy(json.loads((RUNNER_DIR / "golden-vectors.json").read_text())["valid"])
            manifest.update(remote_dir=str(directory), redaction_file="redactions.json")
            manifest_path = directory / "manifest.json"
            manifest_path.write_text("{}", encoding="utf-8")
            with mock.patch.object(runner, "validate_manifest", return_value=manifest), mock.patch.object(
                runner.shutil, "which", return_value=None
            ), self.assertRaisesRegex(common.ProtocolError, "not installed"):
                runner.execute(manifest_path, manifest["run_token"])
            result = json.loads((directory / "result.json").read_text(encoding="utf-8"))
            self.assertEqual("runner_error", result["outcome"])
            self.assertEqual(-1, result["exit_code"])

    def test_observer_reads_from_exact_offset_and_rejects_rotation_gap(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            manifest = copy.deepcopy(json.loads((RUNNER_DIR / "golden-vectors.json").read_text())["valid"])
            manifest.update(remote_dir=str(directory))
            manifest_path = directory / "manifest.json"
            manifest_path.write_text("{}", encoding="utf-8")
            (directory / "output.log").write_bytes(b"before\nafter\n")
            os.chmod(directory / "output.log", 0o600)
            launch = {
                "schema": "virtusphere.remote.launch/v1", "run_token": manifest["run_token"],
                "unit_name": manifest["unit_name"], "decision": "already_running",
                "written_at": "2026-01-01T00:00:00Z",
            }
            (directory / "launch.json").write_text(json.dumps(launch), encoding="utf-8")
            os.chmod(directory / "launch.json", 0o600)
            with mock.patch.object(observer, "validate_manifest", return_value=manifest), mock.patch.object(
                observer, "unit_state", return_value="active"
            ):
                result = observer.observe(manifest_path, manifest["run_token"], 7)
                self.assertEqual(7, result["offset"])
                self.assertEqual(b"after\n", __import__("base64").b64decode(result["output_b64"]))
                self.assertEqual(len(b"before\nafter\n"), result["next_offset"])
                self.assertIn("launch_json", result)
                with self.assertRaisesRegex(common.ProtocolError, "rotation or truncation"):
                    observer.observe(manifest_path, manifest["run_token"], 99)


@unittest.skipUnless(os.name == "posix", "remote runner targets Linux/systemd")
class LauncherAndPreflightTest(unittest.TestCase):
    def test_systemd_launch_has_closed_argv_and_required_lifecycle_properties(self) -> None:
        manifest = copy.deepcopy(json.loads((RUNNER_DIR / "golden-vectors.json").read_text())["valid"])
        command = launcher.systemd_command(manifest, Path("/state/manifest.json"), Path("/libexec/runner"))
        self.assertEqual("systemd-run", command[0])
        self.assertIn("--expand-environment=no", command)
        self.assertIn("--property=Type=exec", command)
        self.assertIn("--property=KillMode=control-group", command)
        self.assertIn("--property=UMask=0077", command)
        self.assertIn("--property=RuntimeMaxSec=3600", command)
        separator = command.index("--")
        self.assertEqual(["/libexec/runner", "/state/manifest.json", manifest["run_token"]], command[separator + 1:])
        self.assertNotIn("sh", command)
        self.assertNotIn("bash", command)

    def test_launcher_never_relaunches_started_or_finished_run(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            (directory / "started.json").write_text("{}", encoding="utf-8")
            with mock.patch.object(launcher, "unit_active", return_value=True):
                self.assertEqual("already_running", launcher.launch_decision(directory, "u.service"))
            with mock.patch.object(launcher, "unit_active", return_value=False):
                self.assertEqual("recovery_required", launcher.launch_decision(directory, "u.service"))
            (directory / "result.json").write_text(json.dumps({
                "schema": "virtusphere.remote.result/v1", "run_token": "3" * 32,
                "unit_name": "u.service", "outcome": "completed", "exit_code": 0,
                "output_truncated": False, "started_at": "2026-01-01T00:00:00Z",
                "finished_at": "2026-01-01T00:00:01Z",
            }), encoding="utf-8")
            self.assertEqual("already_finished", launcher.launch_decision(directory, "u.service"))

    def test_preflight_is_read_only(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            state_root = Path(temporary) / "state"
            state_root.mkdir(mode=0o700)
            before = sorted(str(path.relative_to(state_root)) for path in state_root.rglob("*"))
            evidence = preflight.collect(RUNNER_DIR, state_root, 1)
            after = sorted(str(path.relative_to(state_root)) for path in state_root.rglob("*"))
            self.assertEqual(before, after)
            self.assertEqual("site-evidence-only; does-not-activate-remote-execution", evidence["scope"])
            self.assertEqual(64, len(evidence["host_fingerprint"]))
            common.validate_document("preflight", evidence)


if __name__ == "__main__":
    unittest.main()
