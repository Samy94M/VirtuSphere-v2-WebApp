import contextlib
import importlib.util
import io
import json
import socket
import tempfile
import unittest
from pathlib import Path
from urllib.error import HTTPError, URLError


SCRIPT_PATH = Path(__file__).resolve().parents[1] / 'upload_mac_list.py'
SPEC = importlib.util.spec_from_file_location('upload_mac_list', SCRIPT_PATH)
UPLOAD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(UPLOAD)


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


class UploadMacListTest(unittest.TestCase):
    def run_upload(self, events, data=None, mission='12', job='34'):
        if data is None:
            data = [{'instance': {'hw_name': 'vm01'}}]

        opener = SequenceOpener(*events)
        output = io.StringIO()
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'vm_infos.json'
            path.write_text(json.dumps(data), encoding='utf-8')
            with contextlib.redirect_stdout(output):
                exit_code = UPLOAD.send_data_to_server(
                    str(path),
                    'http://api.invalid',
                    mission,
                    job,
                    opener,
                )

        return exit_code, output.getvalue(), opener

    @staticmethod
    def response(payload, status=200):
        return FakeResponse(json.dumps(payload).encode('utf-8'), status)

    def test_web_payload_includes_mission_and_job_ids(self):
        exit_code, output, opener = self.run_upload([
            self.response({
                'outcome': 'success',
                'counts': {
                    'expected_vms': 1,
                    'successful_vms': 1,
                    'failed_vms': 0,
                    'updated_interfaces': 2,
                },
                'remote_detail': 'must-not-be-logged',
            }),
        ])

        self.assertEqual(UPLOAD.EXIT_SUCCESS, exit_code)
        request, timeout = opener.requests[0]
        payload = json.loads(request.data.decode('utf-8'))
        self.assertEqual(12, payload['mission_id'])
        self.assertEqual(34, payload['job_id'])
        self.assertEqual([{'instance': {'hw_name': 'vm01'}}], payload['results'])
        self.assertEqual(UPLOAD.REQUEST_TIMEOUT_SECONDS, timeout)
        self.assertIn('outcome=success', output)
        self.assertIn('updated_interfaces=2', output)
        self.assertNotIn('must-not-be-logged', output)

    def test_unresolved_desktop_placeholders_keep_the_legacy_array_shape(self):
        data = [{'instance': {'hw_name': 'vm01'}}]
        self.assertEqual(data, UPLOAD.build_payload(data, '{{missionId}}', '{{jobId}}'))

        payload = UPLOAD.build_payload(data, '12', '{{jobId}}')
        self.assertEqual({'mission_id': 12, 'results': data}, payload)
        self.assertNotIn('job_id', payload)

    def test_recognized_outcomes_map_to_fixed_exit_codes(self):
        cases = {
            'success': UPLOAD.EXIT_SUCCESS,
            'partial': UPLOAD.EXIT_PARTIAL,
            'failed': UPLOAD.EXIT_FAILED,
        }
        for outcome, expected in cases.items():
            with self.subTest(outcome=outcome):
                exit_code, output, opener = self.run_upload([
                    self.response({'outcome': outcome, 'updated_vms': 1, 'updated_interfaces': 2}),
                ])
                self.assertEqual(expected, exit_code)
                self.assertEqual(1, len(opener.requests))
                self.assertIn(f'outcome={outcome}', output)

    def test_invalid_local_files_exit_22_without_http(self):
        cases = ('not-json', '[]', '{}')
        for content in cases:
            with self.subTest(content=content):
                opener = SequenceOpener(self.response({'outcome': 'success'}))
                output = io.StringIO()
                with tempfile.TemporaryDirectory() as directory:
                    path = Path(directory) / 'vm_infos.json'
                    path.write_text(content, encoding='utf-8')
                    with contextlib.redirect_stdout(output):
                        exit_code = UPLOAD.send_data_to_server(
                            str(path),
                            'http://api.invalid',
                            '12',
                            '34',
                            opener,
                        )

                self.assertEqual(UPLOAD.EXIT_LOCAL_DATA_ERROR, exit_code)
                self.assertEqual([], opener.requests)

    def test_invalid_server_responses_exit_24(self):
        responses = (
            FakeResponse(b''),
            FakeResponse(b'not-json'),
            FakeResponse(b'[]'),
            self.response({'success': True}),
            self.response({'outcome': 'unknown'}),
        )
        for response in responses:
            with self.subTest(body=response.body):
                exit_code, output, opener = self.run_upload([response])
                self.assertEqual(UPLOAD.EXIT_RESPONSE_ERROR, exit_code)
                self.assertEqual(1, len(opener.requests))
                self.assertIn('semantisch ungueltig', output)

    def test_oversized_server_response_exits_24(self):
        body = b'x' * (UPLOAD.MAX_RESPONSE_BYTES + 1)
        exit_code, output, opener = self.run_upload([FakeResponse(body)])

        self.assertEqual(UPLOAD.EXIT_RESPONSE_ERROR, exit_code)
        self.assertEqual(1, len(opener.requests))
        self.assertIn('zu gross', output)

    def test_http_4xx_is_not_retried_and_never_logs_the_body(self):
        error = HTTPError(
            'http://api.invalid',
            409,
            'Conflict',
            None,
            io.BytesIO(b'private-server-detail'),
        )
        exit_code, output, opener = self.run_upload([error])

        self.assertEqual(UPLOAD.EXIT_HTTP_ERROR, exit_code)
        self.assertEqual(1, len(opener.requests))
        self.assertIn('HTTP-Fehler 409', output)
        self.assertNotIn('private-server-detail', output)

    def test_http_5xx_is_retried_once(self):
        error = HTTPError(
            'http://api.invalid',
            503,
            'Unavailable',
            None,
            io.BytesIO(b'private-server-detail'),
        )
        exit_code, output, opener = self.run_upload([
            error,
            self.response({'outcome': 'success'}),
        ])

        self.assertEqual(UPLOAD.EXIT_SUCCESS, exit_code)
        self.assertEqual(2, len(opener.requests))
        self.assertNotIn('private-server-detail', output)

    def test_repeated_timeout_is_retried_exactly_once(self):
        exit_code, output, opener = self.run_upload([
            URLError(socket.timeout()),
            URLError(socket.timeout()),
        ])

        self.assertEqual(UPLOAD.EXIT_HTTP_ERROR, exit_code)
        self.assertEqual(2, len(opener.requests))
        self.assertIn('Netzwerkfehler', output)

    def test_non_timeout_network_error_is_not_retried(self):
        exit_code, output, opener = self.run_upload([
            URLError('connection refused'),
        ])

        self.assertEqual(UPLOAD.EXIT_HTTP_ERROR, exit_code)
        self.assertEqual(1, len(opener.requests))
        self.assertIn('Netzwerkfehler', output)


if __name__ == '__main__':
    unittest.main()
