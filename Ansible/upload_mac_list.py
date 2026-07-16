import json
import socket
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

file_path = './vm_infos.json'
api_base_url = 'http://{{apiUrl}}'
mission_id = '{{missionId}}'
job_id = '{{jobId}}'

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


def positive_int(value):
    text = str(value).strip()
    if not text.isdigit():
        return None

    number = int(text)
    return number if number > 0 else None


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


def build_payload(data, mission_value=mission_id, job_value=job_id):
    parsed_mission_id = positive_int(mission_value)
    if parsed_mission_id is None:
        # Legacy/Desktop rendering can leave placeholders unresolved. Keep the
        # historic array shape in that path; the web worker always patches IDs.
        return data

    payload = {'mission_id': parsed_mission_id, 'results': data}
    parsed_job_id = positive_int(job_value)
    if parsed_job_id is not None:
        payload['job_id'] = parsed_job_id

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
            print(f'MAC-Upload abgebrochen: HTTP-Fehler {error.code}.')
            return EXIT_HTTP_ERROR, None
        except (URLError, socket.timeout, TimeoutError) as error:
            if is_timeout_error(error) and attempt == 0:
                continue
            print('MAC-Upload abgebrochen: Netzwerkfehler.')
            return EXIT_HTTP_ERROR, None
        except Exception:
            print('MAC-Upload abgebrochen: unerwarteter Transportfehler.')
            return EXIT_HTTP_ERROR, None

    return EXIT_HTTP_ERROR, None


def send_data_to_server(
    path,
    base_url,
    mission_value=mission_id,
    job_value=job_id,
    opener=urlopen,
):
    data = load_vm_infos(path)
    if data is None:
        return EXIT_LOCAL_DATA_ERROR

    url = base_url.rstrip('/') + '/db_importMAC.php?action=updateInterface'
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
