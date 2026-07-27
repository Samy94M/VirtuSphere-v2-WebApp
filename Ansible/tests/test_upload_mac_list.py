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

    def test_correlation_id_is_sent_when_patched_and_omitted_otherwise(self):
        # ADR-0032, matrix point 6: the id rides along only when the worker
        # patched a well-formed one; an unpatched template or garbage never
        # reaches the wire, and the legacy shape stays identical.
        data = [{'instance': {'hw_name': 'vm01'}}]

        payload = UPLOAD.build_payload(data, '12', '34', 'feedface00000021')
        self.assertEqual('feedface00000021', payload['correlation_id'])

        for invalid in ('{{correlationId}}', 'XYZ-not-hex', 'abc', 'A' * 40, ''):
            with self.subTest(invalid=invalid):
                self.assertNotIn('correlation_id', UPLOAD.build_payload(data, '12', '34', invalid))

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

    def test_http_4xx_surfaces_the_portals_own_error_field(self):
        # The deliberate WP-12 half of the body rule: the portal's own JSON
        # error field IS meant for the operator, and the job log is where they
        # look. "HTTP-Fehler 409." alone forced a portal-log search for a
        # sentence the response already carried.
        error = HTTPError(
            'http://api.invalid',
            409,
            'Conflict',
            None,
            io.BytesIO(json.dumps({'error': 'Deploy job does not accept this MAC import'}).encode('utf-8')),
        )
        exit_code, output, opener = self.run_upload([error])

        self.assertEqual(UPLOAD.EXIT_HTTP_ERROR, exit_code)
        self.assertEqual(1, len(opener.requests))
        self.assertIn('HTTP-Fehler 409', output)
        self.assertIn('Deploy job does not accept this MAC import', output)

    def test_the_surfaced_error_field_is_flattened_and_truncated(self):
        # The field is server text that crosses into a single job-log line:
        # newlines collapse and a runaway sentence is cut, exactly like the
        # transport reasons handled by short_reason().
        noisy = 'zeile1\nzeile2\t' + 'x' * 500
        error = HTTPError(
            'http://api.invalid', 422, 'Unprocessable', None,
            io.BytesIO(json.dumps({'error': noisy}).encode('utf-8')),
        )
        _, output, _ = self.run_upload([error])

        self.assertIn('zeile1 zeile2', output)
        self.assertNotIn('\nzeile2', output)
        self.assertNotIn('x' * 201, output)

    def test_an_oversized_or_non_string_error_body_stays_unlogged(self):
        # The read is bounded: a body past the limit is not portal JSON we
        # trust, and a non-string error field is not a sentence. Both fall
        # back to the bare status line.
        oversized = HTTPError(
            'http://api.invalid', 409, 'Conflict', None,
            io.BytesIO(b'{"error": "' + b'y' * (UPLOAD.MAX_ERROR_BODY_BYTES + 10) + b'"}'),
        )
        structured = HTTPError(
            'http://api.invalid', 409, 'Conflict', None,
            io.BytesIO(json.dumps({'error': {'nested': 'detail'}}).encode('utf-8')),
        )
        for error in (oversized, structured):
            with self.subTest(error=error):
                exit_code, output, _ = self.run_upload([error])
                self.assertEqual(UPLOAD.EXIT_HTTP_ERROR, exit_code)
                self.assertNotIn('Portal-Antwort', output)
                self.assertNotIn('nested', output)
                self.assertNotIn('yyyy', output)

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

    def test_a_network_error_names_its_reason(self):
        # A pinned-certificate mismatch and an unplugged cable both used to read
        # "Netzwerkfehler" and nothing else. The reason is the script's OWN text
        # (the exception message), not a server response body, so the rule that
        # the response body stays unlogged is untouched.
        exit_code, output, _ = self.run_upload([URLError('certificate verify failed')])

        self.assertEqual(UPLOAD.EXIT_HTTP_ERROR, exit_code)
        self.assertIn('certificate verify failed', output)


class TlsContextTest(unittest.TestCase):
    """
    The MAC callback is the one channel that decides whether a deploy succeeded,
    and this script had no `import ssl` and a bare urlopen(). Against a
    self-signed portal certificate it would have failed with a network error and
    no deploy would ever have completed on a TLS portal.
    """

    def test_http_keeps_the_plain_opener(self):
        # Nothing about the HTTP path may change: that is the shipped default.
        self.assertIs(UPLOAD.urlopen, UPLOAD.default_opener('http://portal.lan:8021/x'))

    def test_https_gets_a_tls_opener(self):
        opener = UPLOAD.default_opener('https://portal.lan:8443/x')

        self.assertIsNot(UPLOAD.urlopen, opener)
        self.assertTrue(callable(opener))

    def test_an_unpinned_https_url_verifies_the_chain(self):
        # No fingerprint means a certificate from a PKI the host already trusts,
        # and then the default verifying context is the STRONGER answer. It must
        # not silently degrade to "verify nothing".
        built = UPLOAD.build_https_opener('')
        handler = next(h for h in built.handlers if isinstance(h, UPLOAD.HTTPSHandler))
        context = getattr(handler, '_context', None)

        self.assertIsNotNone(context, 'the handler must carry an SSL context')
        self.assertTrue(context.check_hostname)
        self.assertEqual(UPLOAD.ssl.CERT_REQUIRED, context.verify_mode)

    def test_only_a_full_sha256_fingerprint_is_accepted_as_a_pin(self):
        # A truncated or garbled value must NOT be treated as a pin, because a
        # pin is the only thing that switches the chain check off: a half-read
        # registry value would otherwise disable verification silently.
        self.assertEqual('', UPLOAD.normalized_fingerprint(''))
        self.assertEqual('', UPLOAD.normalized_fingerprint('{{certSha256}}'))
        self.assertEqual('', UPLOAD.normalized_fingerprint('ab:cd'))
        self.assertEqual('', UPLOAD.normalized_fingerprint('f' * 63))

        colons = ':'.join(['AB'] * 32)
        self.assertEqual('ab' * 32, UPLOAD.normalized_fingerprint(colons))
        self.assertEqual('f' * 64, UPLOAD.normalized_fingerprint('F' * 64))

    def test_the_unpatched_template_placeholder_is_not_a_pin(self):
        # The shipped file carries the placeholder; if that counted as a pin, an
        # unpatched copy would run with verification disabled.
        self.assertEqual('', UPLOAD.normalized_fingerprint(UPLOAD.cert_sha256))


if __name__ == '__main__':
    unittest.main()
