import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path


SCRIPT_PATH = Path(__file__).resolve().parents[1] / "inspect_create_async_state.py"
SPEC = importlib.util.spec_from_file_location("inspect_create_async_state", SCRIPT_PATH)
INSPECT = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(INSPECT)


class InspectCreateAsyncStateTest(unittest.TestCase):
    def write(self, directory: str, jid: str, value: object) -> None:
        with open(Path(directory) / jid, "w", encoding="utf-8") as handle:
            if isinstance(value, str):
                handle.write(value)
            else:
                json.dump(value, handle)

    def test_missing_empty_and_corrupt_states_are_not_success(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            self.assertEqual("missing", INSPECT.inspect_state(directory, "123.1")["state"])
            self.write(directory, "123.1", "")
            self.assertEqual("invalid", INSPECT.inspect_state(directory, "123.1")["state"])
            self.write(directory, "123.1", "{not-json")
            self.assertEqual("invalid", INSPECT.inspect_state(directory, "123.1")["state"])

    def test_running_state_stays_running(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            self.write(directory, "123.2", {"started": True, "finished": False, "ansible_job_id": "123.2"})
            self.assertEqual({"state": "running"}, INSPECT.inspect_state(directory, "123.2"))

    def test_terminal_failure_keeps_the_module_error(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            self.write(directory, "123.3", {"failed": True, "changed": False, "msg": "module convergence failed"})
            self.assertEqual(
                {"state": "failed", "error": "module convergence failed"},
                INSPECT.inspect_state(directory, "123.3"),
            )

    def test_terminal_success_keeps_changed_true_and_false(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            for jid, changed in (("123.4", True), ("123.5", False)):
                self.write(directory, jid, {"failed": False, "changed": changed})
                self.assertEqual(
                    {"state": "succeeded", "changed": changed},
                    INSPECT.inspect_state(directory, jid),
                )

    def test_unbound_paths_symlinks_and_incomplete_success_are_invalid(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            self.assertEqual("invalid", INSPECT.inspect_state("relative", "123.6")["state"])
            self.assertEqual("invalid", INSPECT.inspect_state(directory, "../123.6")["state"])
            self.write(directory, "123.6", {"failed": False})
            self.assertEqual("invalid", INSPECT.inspect_state(directory, "123.6")["state"])
            if hasattr(os, "symlink"):
                target = Path(directory) / "target"
                target.write_text('{"failed":false,"changed":true}', encoding="utf-8")
                try:
                    os.symlink(target, Path(directory) / "123.7")
                except OSError:
                    return
                self.assertEqual("invalid", INSPECT.inspect_state(directory, "123.7")["state"])


if __name__ == "__main__":
    unittest.main()
