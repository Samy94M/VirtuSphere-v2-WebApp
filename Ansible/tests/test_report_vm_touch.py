import contextlib
import importlib.util
import io
import json
import socket
import ssl
import sys
import unittest
from pathlib import Path
from unittest import mock
from urllib.error import HTTPError, URLError


ANSIBLE_DIR = Path(__file__).resolve().parents[1]
UPLOAD_SPEC = importlib.util.spec_from_file_location('upload_mac_list', ANSIBLE_DIR / 'upload_mac_list.py')
UPLOAD = importlib.util.module_from_spec(UPLOAD_SPEC)
UPLOAD_SPEC.loader.exec_module(UPLOAD)
sys.modules['upload_mac_list'] = UPLOAD
TOUCH_SPEC = importlib.util.spec_from_file_location('report_vm_touch', ANSIBLE_DIR / 'report_vm_touch.py')
TOUCH = importlib.util.module_from_spec(TOUCH_SPEC)
TOUCH_SPEC.loader.exec_module(TOUCH)


class FakeResponse:
    def __init__(self, body, status=200):
        self.body = body
        self.status = status

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc_value, traceback):
        return False

    def getcode(self):
        return self.status

    def read(self, limit=-1):
        return self.body if limit < 0 else self.body[:limit]


class SequenceOpener:
    def __init__(self, *events):
        self.events = list(events)
        self.requests = []

    def __call__(self, request, timeout):
        self.requests.append((request, timeout))
        event = self.events.pop(0)
        if isinstance(event, BaseException):
            raise event
        return event


class ReportVmTouchTest(unittest.TestCase):
    def setUp(self):
        self.sleeps = []
        self.payload = {
            'job_id': 34,
            'vm_id': 56,
            'attempt': 2,
            'generation': '0123456789abcdef0123456789abcdef',
            'phase': 'export',
        }

    @staticmethod
    def response(payload, status=200):
        return FakeResponse(json.dumps(payload).encode('utf-8'), status)

    def run_touch(self, events, payload=None, base_url='https://portal.invalid:8443'):
        opener = SequenceOpener(*events)
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            result = TOUCH.send_touch(
                self.payload if payload is None else payload,
                base_url,
                opener,
                'a' * 64,
                self.sleeps.append,
            )
        return result, output.getvalue(), opener

    def test_payload_requires_patched_job_and_mission_and_closed_execution_identity(self):
        with mock.patch.object(UPLOAD, 'mission_id', '12'), mock.patch.object(UPLOAD, 'job_id', '34'):
            self.assertEqual(self.payload, TOUCH.build_payload('56', 'export', '2', self.payload['generation']))
            for bad in (
                ('{{vmId}}', 'export', '2', self.payload['generation']),
                ('56', 'create', '2', self.payload['generation']),
                ('56', 'export', '0', self.payload['generation']),
                ('56', 'export', '2', '{{generation}}'),
                ('56', 'export', '2', 'A' * 32),
            ):
                with self.subTest(bad=bad):
                    self.assertIsNone(TOUCH.build_payload(*bad))

    def test_only_the_exact_acknowledgement_opens_the_vm_step(self):
        result, output, opener = self.run_touch([self.response({'success': True, **self.payload})])

        self.assertTrue(result)
        self.assertIn('access attempt recorded', output)
        self.assertEqual(1, len(opener.requests))
        request, timeout = opener.requests[0]
        self.assertEqual('https://portal.invalid:8443/deploy_vm_touch.php', request.full_url)
        self.assertEqual('POST', request.get_method())
        self.assertEqual(self.payload, json.loads(request.data.decode('utf-8')))
        self.assertEqual(UPLOAD.REQUEST_TIMEOUT_SECONDS, timeout)

    def test_missing_extra_or_mismatched_ack_fields_refuse_the_step(self):
        bad_responses = (
            {'success': True, **{key: value for key, value in self.payload.items() if key != 'vm_id'}},
            {'success': True, **self.payload, 'other': 'unexpected'},
            {'success': True, **{**self.payload, 'vm_id': 57}},
            {'success': 1, **self.payload},
            {'success': True, **{**self.payload, 'attempt': '2'}},
        )
        for body in bad_responses:
            with self.subTest(body=body):
                result, output, _ = self.run_touch([self.response(body)])
                self.assertFalse(result)
                self.assertIn('touch_refused', output)

    def test_http_4xx_is_closed_and_never_retried(self):
        error = HTTPError(
            'https://portal.invalid/deploy_vm_touch.php',
            409,
            'Conflict',
            None,
            io.BytesIO(b'private refusal detail'),
        )
        result, output, opener = self.run_touch([error])

        self.assertFalse(result)
        self.assertIn('touch_refused', output)
        self.assertNotIn('private refusal detail', output)
        self.assertEqual(1, len(opener.requests))
        self.assertEqual([], self.sleeps)

    def test_transient_http_and_network_errors_reuse_the_upload_retry_policy(self):
        server_error = HTTPError(
            'https://portal.invalid/deploy_vm_touch.php', 503, 'Unavailable', None, io.BytesIO(b'ignored')
        )
        refused = URLError(ConnectionRefusedError(111, 'Connection refused'))
        result, output, opener = self.run_touch([
            server_error,
            refused,
            self.response({'success': True, **self.payload}),
        ])

        self.assertTrue(result)
        self.assertEqual(3, len(opener.requests))
        self.assertEqual([5, 15], self.sleeps)
        self.assertIn('VM-Touch Export:', output)
        self.assertNotIn('ignored', output)

    def test_timeout_retries_to_the_shared_bound_and_then_fails_closed(self):
        result, output, opener = self.run_touch([URLError(socket.timeout()) for _ in range(4)])

        self.assertFalse(result)
        self.assertEqual(4, len(opener.requests))
        self.assertEqual([5, 15, 45], self.sleeps)
        self.assertIn('touch_transport_failed', output)

    def test_tls_failure_is_not_retried(self):
        result, output, opener = self.run_touch([URLError(ssl.SSLCertVerificationError(1, 'certificate verify failed'))])

        self.assertFalse(result)
        self.assertEqual(1, len(opener.requests))
        self.assertEqual([], self.sleeps)
        self.assertIn('touch_transport_failed', output)

    def test_unresolved_portal_url_fails_before_opening_a_connection(self):
        opener = SequenceOpener()
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            result = TOUCH.send_touch(self.payload, 'http://{{apiUrl}}', opener)

        self.assertFalse(result)
        self.assertEqual([], opener.requests)
        self.assertIn('touch_refused', output.getvalue())

    def test_tls_opener_and_fingerprint_come_from_the_mac_transport_owner(self):
        opened = object()
        with mock.patch.object(UPLOAD, 'default_opener', return_value=opened) as default_opener:
            with mock.patch.object(UPLOAD, 'retry_open_request', return_value=FakeResponse(
                json.dumps({'success': True, **self.payload}).encode('utf-8')
            )):
                TOUCH.send_touch(self.payload, 'https://portal.invalid', pinned_fingerprint='b' * 64)

        default_opener.assert_called_once_with(
            'https://portal.invalid/deploy_vm_touch.php', 'b' * 64
        )


if __name__ == '__main__':
    unittest.main()
