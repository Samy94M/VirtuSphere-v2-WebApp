"""Run the production export, start and autostart playbooks against a host double.

K4a (W1): a short ESXi outage must not end these steps. Every ESXi call is
repeated up to three times after a failure, each repetition is a RETRY line
with the module's message, and start and autostart write `[n/total]` progress
lines per VM (AGENTS.md). Nothing here contacts ESXi: the vmware modules are
replaced by a local action plugin with a scripted host, the portal callback
and the touch marker by stubs.
"""
import json
import os
from pathlib import Path
import subprocess
import tempfile

import yaml

ROOT = Path('/repo')
FILES = (
    'exportVMs-Informations-ESXi_playbook.yml', 'export_vm_info.yml',
    'startVMs-ESXi_playbook.yml', 'start_vm_power_tasks.yml',
    'autostartVMs-ESXi_playbook.yml', 'autostart_write_tasks.yml',
    'vm_identity_read_tasks.yml', 'esxi_vm_read_tasks.yml', 'esxi_retry_wait_tasks.yml',
)
MODULES = {
    'community.vmware.vmware_guest_info:': 'info',
    'community.vmware.vmware_guest_powerstate:': 'power',
    'community.vmware.vmware_host_auto_start:': 'autostart',
}
RETRY_PAUSES = [1, 1, 1]
ATTEMPTS = len(RETRY_PAUSES) + 1

TOUCH_STUB = '''
import sys
raise SystemExit(0)
'''

UPLOAD_STUB = '''
import shutil
shutil.copy('vm_infos.json', 'uploaded.json')
'''

ACTION = '''
import json
import os
import time
from pathlib import Path
from ansible.plugins.action import ActionBase

class ActionModule(ActionBase):
    def run(self, tmp=None, task_vars=None):
        args = self._task.args
        scenario = json.loads(Path(os.environ['QA_SCENARIO']).read_text())
        counter_path = Path(os.environ['QA_COUNTER'])
        counters = json.loads(counter_path.read_text()) if counter_path.exists() else {}
        kind = args.pop('qa_kind')
        if kind == 'autostart' and 'system_defaults' in args:
            assert 'uuid' not in args and 'power_info' not in args, args
            kind, target = 'defaults', args['esxi_hostname']
        else:
            assert args.get('use_instance_uuid') is True and 'name' not in args, args
            target = args['uuid'][len('uuid-'):]
            if kind == 'autostart':
                assert 'system_defaults' not in args, args
                assert args['power_info']['start_order'] == -1, args
            if kind == 'power':
                assert args['state'] == 'powered-on' and args['force'] is False, args
        key = kind + ':' + target
        seen = counters.get(key, 0)
        counters[key] = seen + 1
        counter_path.write_text(json.dumps(counters))
        with open(os.environ['QA_EVENTS'], 'a') as stream:
            stream.write(json.dumps([kind, target, time.monotonic()]) + '\\n')
        failures = scenario.get('fail', {}).get(key, 0)
        if failures == 'always' or seen < failures:
            return dict(failed=True, msg='synthetic 503 Service Unavailable for ' + key)
        if kind == 'info':
            return dict(changed=False, instance=dict(hw_name=target, instance_uuid='uuid-' + target,
                                                     hw_power_status='poweredOff'))
        return dict(changed=True)
'''


def run_case(name, playbook, settings, expected_events, failed, output=(), uploaded=None):
    with tempfile.TemporaryDirectory(prefix='esxi-retry-') as directory:
        work = Path(directory)
        plugins = work / 'action_plugins'
        plugins.mkdir()
        (plugins / 'qa_vmware.py').write_text(ACTION)
        for filename in FILES:
            lines = (ROOT / 'Ansible' / filename).read_text().splitlines()
            for index in range(len(lines) - 1, -1, -1):
                stripped = lines[index].strip()
                if stripped in MODULES:
                    indent = len(lines[index]) - len(lines[index].lstrip())
                    lines[index] = ' ' * indent + 'qa_vmware:'
                    lines.insert(index + 1, ' ' * (indent + 2) + 'qa_kind: ' + MODULES[stripped])
            (work / filename).write_text('\n'.join(lines) + '\n')
        (work / 'report_vm_touch.py').write_text(TOUCH_STUB)
        (work / 'upload_mac_list.py').write_text(UPLOAD_STUB)
        configs = [dict(vm_name=f'vm{i}', vm_instance_uuid=f'uuid-vm{i}', portal_vm_id=1000 + i,
                        datacenter_name='qa', autostart=dict(enabled=True, start_delay=-1, stop_delay=-1))
                   for i in range(1, 4)]
        variables = dict(
            vm_configurations=configs,
            job_execution={'attempt': 1, 'generation': 'a' * 32},
            StartWaitSeconds=1,
            EsxiRetryPauseSeconds=RETRY_PAUSES,
            mission_configuration=dict(autostart=dict(
                enabled=settings.get('mission_autostart', True), start_delay=120, stop_delay=120,
                stop_action='guestShutdown', wait_for_heartbeat=False, esxi_host='esxi01')),
            esxi_hostname='never-connect.invalid', esxi_port=443, esxi_username='synthetic',
            esxi_password='synthetic', esxi_validate_certs=True, esxi_ca_bundle_path='/dev/null')
        (work / 'serverlist.yml').write_text(yaml.safe_dump(variables))
        (work / 'accounts.yml').write_text('{}\n')
        (work / 'scenario.json').write_text(json.dumps(settings))
        env = dict(os.environ, QA_SCENARIO=str(work / 'scenario.json'), QA_COUNTER=str(work / 'counter.json'),
                   QA_EVENTS=str(work / 'events.jsonl'), ANSIBLE_NOCOLOR='1')
        result = subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local', playbook],
                                cwd=work, env=env, text=True, capture_output=True, timeout=240)
        events_path = work / 'events.jsonl'
        events = [(event[0], event[1]) for event in
                  (json.loads(line) for line in events_path.read_text().splitlines())] if events_path.exists() else []
        assert (result.returncode != 0) == failed, (name, result.stdout + result.stderr)
        assert events == expected_events, (name, events, expected_events, result.stdout)
        for text in output:
            assert text in result.stdout, (name, text, result.stdout)
        if uploaded is not None:
            rows = json.loads((work / 'uploaded.json').read_text())
            states = {row['item']['vm_name']: (bool(row.get('failed')), row.get('msg', '')) for row in rows}
            for vm, (want_failed, want_msg) in uploaded.items():
                assert states[vm][0] is want_failed, (name, vm, states)
                assert want_msg in states[vm][1], (name, vm, states)


def reads(*vms):
    return [('info', vm) for vm in vms]


def powers(*vms):
    return [('power', vm) for vm in vms]


def writes(*vms):
    return [('autostart', vm) for vm in vms]


EXPORT = 'exportVMs-Informations-ESXi_playbook.yml'
START = 'startVMs-ESXi_playbook.yml'
AUTOSTART = 'autostartVMs-ESXi_playbook.yml'
ALWAYS = 'always'

CASES = [
    ('export-transient', EXPORT, {'fail': {'info:vm2': 2}}, reads('vm1', 'vm2', 'vm2', 'vm2', 'vm3'), False,
     ('[2/3] RETRY Export info vm2: synthetic 503', 'attempt 3 of 4', '[2/3] pass Export info vm2'),
     {'vm1': (False, ''), 'vm2': (False, ''), 'vm3': (False, '')}),
    # The last attempt's failure stays a per-VM callback row with the module
    # message; the export goes on and uploads the others.
    ('export-persistent', EXPORT, {'fail': {'info:vm2': ALWAYS}}, reads('vm1', *['vm2'] * ATTEMPTS, 'vm3'), False,
     ('[2/3] fail Export info vm2: query_failed after 4 attempt(s): synthetic 503', '[3/3] pass Export info vm3'),
     {'vm1': (False, ''), 'vm2': (True, 'synthetic 503'), 'vm3': (False, '')}),
    ('start-all', START, {}, reads('vm1', 'vm2', 'vm3') + powers('vm1', 'vm2', 'vm3'), False,
     ('[1/3] RUN Start info vm1', '[3/3] pass Start info vm3', '[1/3] RUN Start vm1', '[3/3] pass Start vm3')),
    ('start-read-transient', START, {'fail': {'info:vm2': 1}},
     reads('vm1', 'vm2', 'vm2', 'vm3') + powers('vm1', 'vm2', 'vm3'), False, ('[2/3] RETRY Start info vm2',)),
    # No identity, no start: nothing powers on, and the next VM is not read.
    ('start-read-persistent', START, {'fail': {'info:vm2': ALWAYS}}, reads('vm1', *['vm2'] * ATTEMPTS), True,
     ('[2/3] fail Start info vm2', 'after 4 attempt(s)', 'No VM of this job was changed')),
    ('start-power-transient', START, {'fail': {'power:vm2': 1}},
     reads('vm1', 'vm2', 'vm3') + powers('vm1', 'vm2', 'vm2', 'vm3'), False,
     ('[2/3] RETRY Start vm2', '[2/3] pass Start vm2')),
    # One VM that does not start does not stop the others; the step ends red.
    ('start-power-persistent', START, {'fail': {'power:vm2': ALWAYS}},
     reads('vm1', 'vm2', 'vm3') + powers('vm1', *['vm2'] * ATTEMPTS, 'vm3'), True,
     ('[2/3] fail Start vm2', '[3/3] pass Start vm3', '1 of 3 VMs did not start: vm2 after 4 attempt(s)')),
    ('autostart-all', AUTOSTART, {}, reads('vm1', 'vm2', 'vm3') + [('defaults', 'esxi01')] + writes('vm1', 'vm2', 'vm3'),
     False, ('[1/3] RUN Autostart info vm1', '[1/1] pass Autostart defaults esxi01', '[3/3] pass Autostart vm3',
             '::virtusphere-autostart:: written=3 total=3')),
    ('autostart-mission-off', AUTOSTART, {'mission_autostart': False}, reads('vm1', 'vm2', 'vm3') + writes('vm1', 'vm2', 'vm3'),
     False, ('::virtusphere-autostart:: written=3 total=3',)),
    ('autostart-defaults-transient', AUTOSTART, {'fail': {'defaults:esxi01': 1}},
     reads('vm1', 'vm2', 'vm3') + [('defaults', 'esxi01')] * 2 + writes('vm1', 'vm2', 'vm3'), False,
     ('[1/1] RETRY Autostart defaults esxi01',)),
    # Level 1 failed for good: no VM entry is written, and nothing is counted.
    ('autostart-defaults-persistent', AUTOSTART, {'fail': {'defaults:esxi01': ALWAYS}},
     reads('vm1', 'vm2', 'vm3') + [('defaults', 'esxi01')] * ATTEMPTS, True,
     ('Autostart defaults of host esxi01 could not be written after 4 attempt(s)', 'No VM entry was written')),
    ('autostart-vm-transient', AUTOSTART, {'fail': {'autostart:vm2': 2}},
     reads('vm1', 'vm2', 'vm3') + [('defaults', 'esxi01')] + writes('vm1', 'vm2', 'vm2', 'vm2', 'vm3'), False,
     ('[2/3] RETRY Autostart vm2', '::virtusphere-autostart:: written=3 total=3')),
    # FC2-16: the others are written, the marker counts them, the step is red.
    ('autostart-vm-persistent', AUTOSTART, {'fail': {'autostart:vm2': ALWAYS}},
     reads('vm1', 'vm2', 'vm3') + [('defaults', 'esxi01')] + writes('vm1', *['vm2'] * ATTEMPTS, 'vm3'), True,
     ('[2/3] fail Autostart vm2', '::virtusphere-autostart:: written=2 total=3',
      '1 of 3 VMs did not receive their autostart entry: vm2 after 4 attempt(s)')),
]

for position, case in enumerate(CASES, 1):
    print(f'[{position}/{len(CASES)}] RUN {case[0]}', flush=True)
    try:
        run_case(*case)
    except Exception:
        print(f'[{position}/{len(CASES)}] fail {case[0]}', flush=True)
        raise
    print(f'[{position}/{len(CASES)}] pass {case[0]}', flush=True)
