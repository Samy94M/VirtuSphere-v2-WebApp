import json
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

file_path = './vm_infos.json'
api_base_url = 'http://{{apiUrl}}'
mission_id = '{{missionId}}'


def load_vm_infos(path):
    try:
        with open(path, 'r', encoding='utf-8') as handle:
            return json.load(handle)
    except Exception as exc:
        print(f'Fehler beim Lesen der Datei: {exc}')
        return None


def build_payload(data):
    mission_value = str(mission_id).strip()
    if mission_value.isdigit():
        return {'mission_id': int(mission_value), 'results': data}
    return data


def send_data_to_server(path, base_url):
    data = load_vm_infos(path)
    if data is None:
        return

    url = base_url.rstrip('/') + '/db_importMAC.php?action=updateInterface'
    body = json.dumps(build_payload(data)).encode('utf-8')
    request = Request(url, data=body, headers={'Content-Type': 'application/json'}, method='POST')

    try:
        with urlopen(request, timeout=30) as response:
            text = response.read().decode('utf-8', errors='replace')
            print('Daten erfolgreich gesendet.')
            print(f'Antwort vom Server: {text}')
    except HTTPError as exc:
        text = exc.read().decode('utf-8', errors='replace')
        print(f'Fehler beim Senden der Daten: {exc.code}')
        print(f'Antwort vom Server: {text}')
    except URLError as exc:
        print(f'Fehler beim Senden der Daten: {exc.reason}')
    except Exception as exc:
        print(f'Fehler beim Senden der Daten: {exc}')


send_data_to_server(file_path, api_base_url)
