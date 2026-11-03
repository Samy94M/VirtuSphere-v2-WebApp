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
                {"state": "failed", "error": "module convergence failed", "error_from_host": True},
                INSPECT.inspect_state(directory, "123.3"),
            )

    # Status files as ansible-core 2.19.11 writes them for an exception that
    # escapes the module (captured in virtusphere-qa-ansible, 29.10.2026). They
    # carry no `msg`; job 1527 showed only the generic fallback for this shape.
    UNCAUGHT = {
        "failed": True,
        "exception": {
            "event": {"msg": "503 Service Unavailable", "__ansible_type": "Event"},
            "__ansible_type": "ErrorSummary",
        },
    }
    CHAINED = {
        "failed": True,
        "exception": {
            "event": {
                "msg": "503 Service Unavailable",
                "chain": {
                    "msg_reason": "<<< caused by >>>",
                    "traceback_reason": "The above exception was the direct cause of the following exception:",
                    "event": {"msg": "[Errno 104] Connection reset by peer", "__ansible_type": "Event"},
                    "follow": True,
                    "__ansible_type": "EventChain",
                },
                "__ansible_type": "Event",
            },
            "__ansible_type": "ErrorSummary",
        },
    }

    def test_uncaught_module_exception_keeps_its_message(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            self.write(directory, "123.8", self.UNCAUGHT)
            self.assertEqual(
                {"state": "failed", "error": "503 Service Unavailable", "error_from_host": True},
                INSPECT.inspect_state(directory, "123.8"),
            )

    def test_followed_cause_is_joined_like_the_ansible_error_line(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            self.write(directory, "123.9", self.CHAINED)
            self.assertEqual(
                {"state": "failed", "error": "503 Service Unavailable: [Errno 104] Connection reset by peer", "error_from_host": True},
                INSPECT.inspect_state(directory, "123.9"),
            )
            unfollowed = json.loads(json.dumps(self.CHAINED))
            unfollowed["exception"]["event"]["chain"]["follow"] = False
            self.write(directory, "123.10", unfollowed)
            self.assertEqual(
                {"state": "failed", "error": "503 Service Unavailable", "error_from_host": True},
                INSPECT.inspect_state(directory, "123.10"),
            )

    def test_module_msg_wins_and_unusable_failures_keep_the_fallback(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            both = dict(self.UNCAUGHT, msg="Failed to create a virtual machine")
            self.write(directory, "123.11", both)
            self.assertEqual("Failed to create a virtual machine", INSPECT.inspect_state(directory, "123.11")["error"])
            for jid, payload in (
                ("123.12", {"failed": True}),
                ("123.13", {"failed": True, "msg": "  ", "exception": "not a summary"}),
                ("123.14", {"failed": True, "exception": {"event": {"msg": 503}}}),
            ):
                self.write(directory, jid, payload)
                self.assertEqual(
                    {"state": "failed", "error": "the create module reported a failure", "error_from_host": False},
                    INSPECT.inspect_state(directory, jid),
                )

    def test_origin_is_decided_at_the_source_not_by_the_text(self) -> None:
        # A module that happens to say the fallback's words is still the host.
        with tempfile.TemporaryDirectory() as directory:
            self.write(directory, "123.15", {"failed": True, "msg": "the create module reported a failure"})
            self.assertTrue(INSPECT.inspect_state(directory, "123.15")["error_from_host"])

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
