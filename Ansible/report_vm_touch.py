"""Record one fenced ESXi access attempt before querying exactly one VM."""
import argparse
import json
import re
import socket
import ssl
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request

import upload_mac_list as transport


EXIT_SUCCESS = 0
EXIT_FAILED = 1
TOUCH_PATH = '/deploy_vm_touch.php'
GENERATION_PATTERN = re.compile(r'^[a-f0-9]{32}$')
TOUCH_OPERATIONS = {
    'powercycle': 'VM-Touch Power-Cycle',
    'export': 'VM-Touch Export',
}
# Closed refusal causes of deploy_vm_touch.php (A68, MA-08); mirrors
# VIRTUSPHERE_DEPLOY_TOUCH_REFUSAL_CAUSES in lib/repo/deploy_job_vm_states.php.
TOUCH_CAUSES = frozenset((
    'job_unknown',
    'mission_missing',
    'job_not_active',
    'attempt_stale',
    'generation_malformed',
    'generation_stale',
    'runtime_mismatch',
    'mode_mismatch',
    'phase_mismatch',
    'execution_contract_mismatch',
    'remote_handle_mismatch',
    'create_unit_mismatch',
    'vm_not_in_scope',
    'journal_state_missing',
))
REFUSAL_MAX_BYTES = 2048


def build_payload(vm_id_value, phase, attempt_value, generation_value):
    """Return the closed touch request, or None for unpatched/invalid input."""
    mission = transport.positive_int(transport.mission_id)
    job_id = transport.positive_int(transport.job_id)
    vm_id = transport.positive_int(vm_id_value)
    attempt = transport.positive_int(attempt_value)
    generation = str(generation_value).strip()

    if (
        mission is None
        or job_id is None
        or vm_id is None
        or attempt is None
        or phase not in TOUCH_OPERATIONS
        or GENERATION_PATTERN.fullmatch(generation) is None
    ):
        return None

    return {
        'job_id': job_id,
        'vm_id': vm_id,
        'attempt': attempt,
        'generation': generation,
        'phase': phase,
    }


def exact_confirmation(response, expected):
    """Accept only the exact positive response for this VM and attempt."""
    if not isinstance(response, dict) or set(response) != {
        'success', 'job_id', 'vm_id', 'attempt', 'generation', 'phase'
    }:
        return False

    return (
        response['success'] is True
        and type(response['job_id']) is int
        and response['job_id'] == expected['job_id']
        and type(response['vm_id']) is int
        and response['vm_id'] == expected['vm_id']
        and type(response['attempt']) is int
        and response['attempt'] == expected['attempt']
        and response['generation'] == expected['generation']
        and response['phase'] == expected['phase']
    )


def refusal_cause(error):
    """The closed cause a refusal body names, or None. The body itself is never printed."""
    try:
        raw = error.read(REFUSAL_MAX_BYTES + 1)
    except Exception:
        return None
    if not isinstance(raw, bytes) or len(raw) > REFUSAL_MAX_BYTES:
        return None
    try:
        decoded = json.loads(raw.decode('utf-8'))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return None
    cause = decoded.get('cause') if isinstance(decoded, dict) else None
    if isinstance(cause, str) and cause in TOUCH_CAUSES:
        return cause
    return None


def send_touch(payload, base_url=transport.api_base_url, opener=None, pinned_fingerprint=None, sleeper=None):
    """Post the touch and return true only after its exact acknowledgement."""
    if payload is None:
        print('touch_refused: execution metadata is invalid.')
        return False

    parts = urlsplit(str(base_url))
    if (
        parts.scheme not in ('http', 'https')
        or not parts.netloc
        or '{{' in parts.netloc
        or '}}' in parts.netloc
    ):
        print('touch_refused: portal URL is unresolved.')
        return False

    url = str(base_url).rstrip('/') + TOUCH_PATH
    if opener is None:
        fingerprint = transport.cert_sha256 if pinned_fingerprint is None else pinned_fingerprint
        opener = transport.default_opener(url, fingerprint)

    body = json.dumps(payload, separators=(',', ':')).encode('utf-8')
    request = Request(url, data=body, headers={'Content-Type': 'application/json'}, method='POST')
    operation = TOUCH_OPERATIONS[payload['phase']]
    try:
        with transport.retry_open_request(request, opener, sleeper, operation) as response:
            status = response.getcode()
            if not isinstance(status, int) or status < 200 or status >= 300:
                print('touch_refused: HTTP response was not successful.')
                return False

            raw = response.read(transport.MAX_RESPONSE_BYTES + 1)
            if len(raw) > transport.MAX_RESPONSE_BYTES:
                print('touch_refused: confirmation was too large.')
                return False
            try:
                decoded = json.loads(raw.decode('utf-8'))
            except (UnicodeDecodeError, json.JSONDecodeError):
                decoded = None

            if not exact_confirmation(decoded, payload):
                print('touch_refused: exact confirmation is missing.')
                return False

        print('ESXi access attempt recorded for portal VM ' + str(payload['vm_id']) + '.')
        return True
    except HTTPError as error:
        if 400 <= error.code < 500:
            cause = refusal_cause(error)
            suffix = '' if cause is None else ' cause=' + cause
            print('touch_refused: server rejected this VM access attempt.' + suffix)
        else:
            print('touch_transport_failed: portal did not confirm the access attempt.')
        return False
    except (URLError, socket.timeout, TimeoutError, ConnectionError, ssl.SSLError):
        # No marker acknowledgement means the caller must not start an ESXi
        # request, including after an exhausted or permanent transport failure.
        print('touch_transport_failed: portal did not confirm the access attempt.')
        return False
    except Exception:
        print('touch_transport_failed: portal did not confirm the access attempt.')
        return False


def parse_args(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--vm-id', required=True)
    parser.add_argument('--phase', required=True, choices=tuple(TOUCH_OPERATIONS))
    parser.add_argument('--attempt', required=True)
    parser.add_argument('--generation', required=True)
    return parser.parse_args(argv)


def main(argv=None):
    args = parse_args(argv)
    payload = build_payload(args.vm_id, args.phase, args.attempt, args.generation)
    return EXIT_SUCCESS if send_touch(payload) else EXIT_FAILED


if __name__ == '__main__':
    raise SystemExit(main())
