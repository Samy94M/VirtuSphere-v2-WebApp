import hashlib
import json
import socket
import ssl
from urllib.error import HTTPError, URLError
from urllib.request import HTTPSHandler, Request, build_opener, urlopen

file_path = './vm_infos.json'
api_base_url = 'http://{{apiUrl}}'
mission_id = '{{missionId}}'
job_id = '{{jobId}}'
correlation_id = '{{correlationId}}'
# SHA-256 fingerprint of the portal certificate, hex without separators, patched
# in by ansible_patch_upload_script() when the portal runs on HTTPS with a
# self-signed certificate. Empty means ordinary chain validation.
#
# Why this exists at all: this script had no ssl import and a bare urlopen(). The
# MAC callback is the one channel that decides whether a deploy succeeded, so
# against a self-signed certificate it would have failed with "Netzwerkfehler" and
# no deploy would ever have completed on a TLS portal. Pinned rather than
# unverified: an "accept everything" context on the channel that carries the MAC
# addresses is worse than plain HTTP, because it looks encrypted.
cert_sha256 = '{{certSha256}}'

EXIT_SUCCESS = 0
EXIT_PARTIAL = 20
EXIT_FAILED = 21
EXIT_LOCAL_DATA_ERROR = 22
EXIT_HTTP_ERROR = 23
EXIT_RESPONSE_ERROR = 24

OUTCOME_EXIT_CODES = {
    'success': EXIT_SUCCESS,
    'partial': EXIT_PARTIAL,
    'failed': EXIT_FAILED,
}

REQUEST_TIMEOUT_SECONDS = 30
MAX_RESPONSE_BYTES = 1024 * 1024
MAX_ERROR_BODY_BYTES = 4096


def positive_int(value):
    text = str(value).strip()
    if not text.isdigit():
        return None

    number = int(text)
    return number if number > 0 else None


def hex_correlation(value):
    # ADR-0032: 8-32 lowercase hex; anything else (unpatched template, garbage)
    # is silently omitted - the id is diagnostic, never load-bearing.
    text = str(value).strip()
    if 8 <= len(text) <= 32 and all(c in '0123456789abcdef' for c in text):
        return text

    return None


def load_vm_infos(path):
    try:
        with open(path, 'r', encoding='utf-8') as handle:
            data = json.load(handle)
    except (OSError, UnicodeError, json.JSONDecodeError):
        print('MAC-Upload abgebrochen: lokale Ergebnisdatei ist ungueltig.')
        return None

    if not isinstance(data, list) or not data:
        print('MAC-Upload abgebrochen: lokale Ergebnisliste ist leer oder ungueltig.')
        return None

    return data


def build_payload(data, mission_value=mission_id, job_value=job_id, correlation_value=None):
    parsed_mission_id = positive_int(mission_value)
    if parsed_mission_id is None:
        # Legacy/Desktop rendering can leave placeholders unresolved. Keep the
        # historic array shape in that path; the web worker always patches IDs.
        return data

    payload = {'mission_id': parsed_mission_id, 'results': data}
    parsed_job_id = positive_int(job_value)
    if parsed_job_id is not None:
        payload['job_id'] = parsed_job_id

    parsed_correlation = hex_correlation(correlation_id if correlation_value is None else correlation_value)
    if parsed_correlation is not None:
        payload['correlation_id'] = parsed_correlation

    return payload


def is_timeout_error(error):
    if isinstance(error, (socket.timeout, TimeoutError)):
        return True

    return isinstance(error, URLError) and isinstance(error.reason, (socket.timeout, TimeoutError))


def response_counter(response, key):
    value = response.get(key)
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        return None
    return value


def log_redacted_result(response, submitted_results):
    outcome = response['outcome']
    counters = [('submitted_results', submitted_results)]

    counts = response.get('counts')
    if isinstance(counts, dict):
        for key in ('expected_vms', 'successful_vms', 'failed_vms', 'updated_interfaces'):
            value = response_counter(counts, key)
            if value is not None:
                counters.append((key, value))
    else:
        for key in ('updated_vms', 'updated_interfaces'):
            value = response_counter(response, key)
            if value is not None:
                counters.append((key, value))

    summary = ', '.join(f'{key}={value}' for key, value in counters)
    print(f'MAC-Import abgeschlossen: outcome={outcome}, {summary}.')


def decode_response(raw_response):
    if not raw_response or not raw_response.strip():
        return None

    try:
        response = json.loads(raw_response.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return None

    if not isinstance(response, dict) or response.get('outcome') not in OUTCOME_EXIT_CODES:
        return None

    return response


def short_reason(error, limit=200):
    """One short line from an exception, control characters stripped."""
    text = ' '.join(str(error).split())
    return text[:limit]


def portal_error_reason(error):
    """
    The portal's own `error` field from a failed HTTP response, flattened to
    one short line; None for anything else.

    Bounded and shape-checked on purpose: a raw response can be an nginx page
    or a proxy error, and that text must never reach the job log (pinned by
    test_http_4xx_is_not_retried_and_never_logs_the_body). Only the sentence
    the portal wrote FOR the operator is surfaced - {"error": "..."} is the
    machine API's frozen error shape.
    """
    try:
        raw = error.read(MAX_ERROR_BODY_BYTES + 1)
    except Exception:
        return None
    if not raw or len(raw) > MAX_ERROR_BODY_BYTES:
        return None
    try:
        data = json.loads(raw.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return None
    reason = data.get('error') if isinstance(data, dict) else None
    if not isinstance(reason, str) or not reason.strip():
        return None

    return short_reason(reason)


def normalized_fingerprint(value):
    """Hex fingerprint without separators, lowercase. Empty for anything unusable."""
    text = ''.join(c for c in str(value).lower() if c in '0123456789abcdef')
    return text if len(text) == 64 else ''


def build_https_opener(pinned_fingerprint):
    """
    Opener for an HTTPS portal.

    With a pinned fingerprint the chain and hostname checks are switched off and
    replaced by an exact comparison of the certificate's SHA-256: that is what a
    self-signed certificate needs, and it is strictly stronger than the usual
    "just disable verification", because only ONE certificate is accepted and a
    swap fails loudly instead of silently trusting a new one.

    Without one, the default verifying context applies (a certificate from a PKI
    this host already trusts).
    """
    if not pinned_fingerprint:
        return build_opener(HTTPSHandler(context=ssl.create_default_context()))

    context = ssl.create_default_context()
    context.check_hostname = False
    context.verify_mode = ssl.CERT_NONE

    class PinnedHandler(HTTPSHandler):
        def https_open(self, req):
            return self.do_open(self._pinned_connection, req)

        def _pinned_connection(self, host, **kwargs):
            import http.client

            connection = http.client.HTTPSConnection(host, context=context, **kwargs)
            original_connect = connection.connect

            def connect_and_verify():
                original_connect()
                der = connection.sock.getpeercert(binary_form=True)
                actual = hashlib.sha256(der).hexdigest()
                if actual != pinned_fingerprint:
                    connection.close()
                    raise ssl.SSLError(
                        'Portal-Zertifikat weicht vom hinterlegten Fingerabdruck ab '
                        '(erwartet %s..., erhalten %s...).' % (pinned_fingerprint[:16], actual[:16])
                    )

            connection.connect = connect_and_verify
            return connection

    return build_opener(PinnedHandler())


def default_opener(url, pinned_fingerprint=''):
    """The opener for this URL: plain urlopen for http, a TLS opener for https."""
    if not str(url).lower().startswith('https://'):
        return urlopen

    return build_https_opener(normalized_fingerprint(pinned_fingerprint)).open


def send_request(request, opener=urlopen):
    for attempt in range(2):
        try:
            with opener(request, timeout=REQUEST_TIMEOUT_SECONDS) as response:
                status = response.getcode()
                if not isinstance(status, int) or status < 200 or status >= 300:
                    print('MAC-Upload abgebrochen: HTTP-Antwort war nicht erfolgreich.')
                    return EXIT_HTTP_ERROR, None

                raw_response = response.read(MAX_RESPONSE_BYTES + 1)
                if len(raw_response) > MAX_RESPONSE_BYTES:
                    print('MAC-Upload abgebrochen: Serverantwort ist zu gross.')
                    return EXIT_RESPONSE_ERROR, None

                decoded = decode_response(raw_response)
                if decoded is None:
                    print('MAC-Upload abgebrochen: Serverantwort ist semantisch ungueltig.')
                    return EXIT_RESPONSE_ERROR, None

                return OUTCOME_EXIT_CODES[decoded['outcome']], decoded
        except HTTPError as error:
            if 500 <= error.code < 600 and attempt == 0:
                continue
            # The portal's own error field is surfaced (WP-12); everything else
            # about the body stays unlogged (see portal_error_reason).
            reason = portal_error_reason(error)
            if reason:
                print(f'MAC-Upload abgebrochen: HTTP-Fehler {error.code}. Portal-Antwort: {reason}')
            else:
                print(f'MAC-Upload abgebrochen: HTTP-Fehler {error.code}.')
            return EXIT_HTTP_ERROR, None
        except (URLError, socket.timeout, TimeoutError) as error:
            if is_timeout_error(error) and attempt == 0:
                continue
            # The reason matters here too: a pinned-certificate mismatch and an
            # unplugged cable both used to read "Netzwerkfehler".
            print(f'MAC-Upload abgebrochen: Netzwerkfehler. {short_reason(error)}')
            return EXIT_HTTP_ERROR, None
        except ssl.SSLError as error:
            print(f'MAC-Upload abgebrochen: TLS-Fehler. {short_reason(error)}')
            return EXIT_HTTP_ERROR, None
        except Exception as error:
            print(f'MAC-Upload abgebrochen: unerwarteter Transportfehler. {short_reason(error)}')
            return EXIT_HTTP_ERROR, None

    return EXIT_HTTP_ERROR, None


def send_data_to_server(
    path,
    base_url,
    mission_value=mission_id,
    job_value=job_id,
    opener=None,
    pinned_fingerprint=None,
):
    data = load_vm_infos(path)
    if data is None:
        return EXIT_LOCAL_DATA_ERROR

    url = base_url.rstrip('/') + '/db_importMAC.php?action=updateInterface'
    # Chosen from the URL, not configured: an http portal keeps the plain opener,
    # an https one gets a verifying (or pinned) TLS context. The tests inject
    # their own opener and never reach this.
    if opener is None:
        opener = default_opener(url, cert_sha256 if pinned_fingerprint is None else pinned_fingerprint)
    body = json.dumps(build_payload(data, mission_value, job_value)).encode('utf-8')
    request = Request(url, data=body, headers={'Content-Type': 'application/json'}, method='POST')
    exit_code, response = send_request(request, opener)
    if response is not None:
        log_redacted_result(response, len(data))

    return exit_code


def main():
    return send_data_to_server(file_path, api_base_url)


if __name__ == '__main__':
    raise SystemExit(main())
