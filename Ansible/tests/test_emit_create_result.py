import base64
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path


SCRIPT_PATH = Path(__file__).resolve().parents[1] / 'emit_create_result.py'
SPEC = importlib.util.spec_from_file_location('emit_create_result', SCRIPT_PATH)
EMIT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(EMIT)


def decode(marker):
    prefix, version, payload = marker.split(' ')
    assert prefix == EMIT.MARKER_PREFIX
    assert version == EMIT.PROTOCOL_VERSION
    padded = payload + '=' * (-len(payload) % 4)

    return json.loads(base64.urlsafe_b64decode(padded).decode('utf-8'))


PREPARED = {
    'event': 'prepared',
    'portal_vm_id': 123,
    'vm_name': 'ATeP04-001',
    'existed_before': False,
    'precheck_moid': None,
    'precheck_instance_uuid': None,
}

SUCCEEDED = {
    'event': 'succeeded',
    'portal_vm_id': 123,
    'vm_name': 'ATeP04-001',
    'async_jid': '747689456876.6394',
    'changed': True,
    'moid': 'vm-123',
    'instance_uuid': '503c9ab1-0000-0000-0000-000000000001',
    'power_state': 'poweredOff',
}


class EmitCreateResultTest(unittest.TestCase):
    """The one machine-readable line of the per-VM create protocol.

    Everything here is about what must NOT get through. A marker the PHP side
    can parse is a statement about a real VM on a real host, so an event with a
    stray field, a job id that could reach a shell, or an identity that is only
    half present has to end without a marker rather than with a lenient one.
    """

    def test_a_known_event_round_trips_without_padding(self):
        marker = EMIT.build_marker(EMIT.validate(dict(PREPARED)))
        self.assertNotIn('=', marker.split(' ')[2], 'base64url is emitted without padding')
        self.assertEqual(PREPARED, decode(marker))

    def test_every_declared_event_has_a_working_example(self):
        # A closed vocabulary that nobody exercises is a list, not a contract.
        self.assertEqual(
            {'prepared', 'launched', 'running', 'succeeded', 'failed', 'rejected'},
            set(EMIT.EVENTS),
        )

    def test_an_unknown_event_is_refused(self):
        with self.assertRaises(EMIT.ContractError):
            EMIT.validate({'event': 'almost_done', 'portal_vm_id': 1})

    def test_an_extra_field_is_refused(self):
        payload = dict(PREPARED)
        payload['power_state'] = 'poweredOn'
        with self.assertRaises(EMIT.ContractError) as caught:
            EMIT.validate(payload)
        self.assertIn('unexpected field', str(caught.exception))

    def test_a_missing_field_is_refused(self):
        payload = dict(PREPARED)
        del payload['precheck_moid']
        with self.assertRaises(EMIT.ContractError) as caught:
            EMIT.validate(payload)
        self.assertIn('missing field', str(caught.exception))

    def test_a_job_id_that_could_reach_a_shell_is_refused(self):
        for jid in ('123; rm -rf /', '12 34', '../../etc/passwd', '', 'a' * 192):
            payload = dict(SUCCEEDED)
            payload['async_jid'] = jid
            with self.assertRaises(EMIT.ContractError):
                EMIT.validate(payload)

    def test_a_half_identity_is_refused(self):
        # The VM was there but only one half of its identity came back. Passing
        # that on would let a name-based match look like a proven one.
        payload = dict(PREPARED)
        payload['existed_before'] = True
        payload['precheck_moid'] = 'vm-7'
        with self.assertRaises(EMIT.ContractError) as caught:
            EMIT.validate(payload)
        self.assertIn('existed_before', str(caught.exception))

        # And the reverse: a full identity while claiming nothing was there.
        payload = dict(PREPARED)
        payload['precheck_moid'] = 'vm-7'
        payload['precheck_instance_uuid'] = '503c-1'
        with self.assertRaises(EMIT.ContractError):
            EMIT.validate(payload)

    def test_a_portal_vm_id_must_be_a_real_id(self):
        for value in (0, -1, '123', True, None):
            payload = dict(PREPARED)
            payload['portal_vm_id'] = value
            with self.assertRaises(EMIT.ContractError):
                EMIT.validate(payload)

    def test_an_unknown_error_code_is_refused(self):
        payload = {
            'event': 'rejected',
            'portal_vm_id': 5,
            'vm_name': 'VM',
            'error_code': 'the module said something red',
            'error': 'detail',
        }
        with self.assertRaises(EMIT.ContractError):
            EMIT.validate(payload)
        payload['error_code'] = 'identity_conflict'
        self.assertEqual('identity_conflict', EMIT.validate(payload)['error_code'])

    def test_a_control_character_cannot_enter_the_line(self):
        payload = {
            'event': 'failed',
            'portal_vm_id': 5,
            'async_jid': '1.2',
            'error': 'first line\nsecond line',
        }
        with self.assertRaises(EMIT.ContractError):
            EMIT.validate(payload)

    def test_an_async_dir_outside_an_absolute_path_is_refused(self):
        payload = {
            'event': 'launched',
            'portal_vm_id': 5,
            'vm_name': 'VM',
            'async_jid': '1.2',
            'async_dir': '../async',
            'existed_before': False,
            'precheck_moid': None,
            'precheck_instance_uuid': None,
        }
        with self.assertRaises(EMIT.ContractError):
            EMIT.validate(payload)
        payload['async_dir'] = '/state/jobs/105/1/create.vm.1/async'
        self.assertEqual('/state/jobs/105/1/create.vm.1/async', EMIT.validate(payload)['async_dir'])

    def test_an_oversized_payload_produces_no_marker(self):
        payload = {
            'event': 'failed',
            'portal_vm_id': 5,
            'async_jid': '1.2',
            'error': 'x' * EMIT.MAX_ERROR_LENGTH,
        }
        # Inside the field limit but the emitter still bounds the whole line.
        EMIT.validate(payload)
        original = EMIT.MAX_PAYLOAD_BYTES
        try:
            EMIT.MAX_PAYLOAD_BYTES = 64
            with self.assertRaises(EMIT.ContractError):
                EMIT.build_marker(EMIT.validate(payload))
        finally:
            EMIT.MAX_PAYLOAD_BYTES = original

    def test_the_program_exits_without_a_marker_on_a_bad_file(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'result.json'
            path.write_text('{"event": "nope"}', encoding='utf-8')
            self.assertEqual(2, EMIT.main(['emit_create_result.py', str(path)]))

            path.write_text('not json at all', encoding='utf-8')
            self.assertEqual(2, EMIT.main(['emit_create_result.py', str(path)]))

            self.assertEqual(2, EMIT.main(['emit_create_result.py', str(Path(directory) / 'missing.json')]))

    def test_the_program_writes_exactly_one_line_for_a_valid_file(self):
        import contextlib
        import io

        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'result.json'
            path.write_text(json.dumps(SUCCEEDED), encoding='utf-8')
            buffer = io.StringIO()
            with contextlib.redirect_stdout(buffer):
                code = EMIT.main(['emit_create_result.py', str(path)])
            self.assertEqual(0, code)
            lines = buffer.getvalue().splitlines()
            self.assertEqual(1, len(lines), 'exactly one marker per control call')
            self.assertEqual(SUCCEEDED, decode(lines[0]))


if __name__ == '__main__':
    unittest.main()
