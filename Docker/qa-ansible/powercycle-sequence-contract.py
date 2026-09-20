"""Execute production control flow with local action doubles; never contact ESXi."""
import json
import os
from pathlib import Path
import subprocess
import tempfile

import yaml

ROOT = Path('/repo')
PLAY = 'powercycleVMs-ESXi_playbook.yml'
TASKS = 'powercycle_vm_tasks.yml'

ACTION = '''
import json
import os
import time
from pathlib import Path
from ansible.plugins.action import ActionBase

class ActionModule(ActionBase):
    def run(self, tmp=None, task_vars=None):
        args = self._task.args
        scenario = json.loads(Path(os.environ['PC_SCENARIO']).read_text())
        kind = args.pop('qa_kind')
        identity = args.get('uuid', args.get('name'))
        event = [kind, identity, args.get('state'), time.monotonic()]
        with open(os.environ['PC_EVENTS'], 'a') as stream:
            stream.write(json.dumps(event) + '\\n')
        if kind == 'info':
            state = scenario.get('initial', {}).get(identity, 'poweredOff')
            live_name = scenario.get('live_name', {}).get(identity, identity)
            instance = dict(hw_name=live_name, instance_uuid='uuid-' + identity,
                            hw_power_status=state)
            if scenario.get('broken'):
                instance.pop(scenario['broken'])
            return dict(changed=False, instance=instance)
        assert args.get('use_instance_uuid') is True
        assert 'name' not in args
        assert args['force'] is (args['state'] == 'powered-off')
        failure = scenario.get('failure') == args['state'] and identity == 'uuid-vm2'
        external = identity in scenario.get('external', [])
        return dict(changed=not external, failed=failure, msg='synthetic outcome')
'''

PAUSE = '''
import json
import os
import time
from pathlib import Path
from ansible.plugins.action.pause import ActionModule as Pause

class ActionModule(Pause):
    def run(self, tmp=None, task_vars=None):
        with open(os.environ['PC_EVENTS'], 'a') as stream:
            stream.write(json.dumps(['pause', None, self._task.args['seconds'], time.monotonic()]) + '\\n')
        scenario = json.loads(Path(os.environ['PC_SCENARIO']).read_text())
        if scenario.get('pause_failure'):
            return dict(failed=True, msg='synthetic pause failure')
        return super().run(tmp, task_vars)
'''


def run_case(name, settings, expected, failed=False):
    with tempfile.TemporaryDirectory(prefix='powercycle-') as directory:
        work = Path(directory)
        plugins = work / 'action_plugins'
        plugins.mkdir()
        (plugins / 'qa_vmware.py').write_text(ACTION)
        (plugins / 'qa_pause.py').write_text(PAUSE)
        for filename in (PLAY, TASKS):
            source = (ROOT / 'Ansible' / filename).read_text()
            # Replace only external actions. Loops, guards, includes and failure
            # propagation are the production YAML, not a reimplemented fixture.
            source = source.replace('community.vmware.vmware_guest_info:', 'qa_vmware:\n        qa_kind: info')
            source = source.replace('community.vmware.vmware_guest_powerstate:', 'qa_vmware:')
            lines = source.splitlines()
            for index in range(len(lines) - 1, -1, -1):
                if lines[index].strip() == 'qa_vmware:' and filename == TASKS:
                    indent = len(lines[index]) - len(lines[index].lstrip()) + 2
                    lines.insert(index + 1, ' ' * indent + 'qa_kind: power')
            source = '\n'.join(lines).replace('ansible.builtin.pause:', 'qa_pause:')
            (work / filename).write_text(source)
        configs = [dict(vm_name=f'vm{i}', vm_instance_uuid=f'uuid-vm{i}',
                        datacenter_name='qa', needs_mac=True) for i in range(1, settings.get('count', 3) + 1)]
        if settings.get('empty'):
            configs = []
        if settings.get('known_mac'):
            configs[0]['needs_mac'] = False
            configs[1].pop('needs_mac')
        wait_seconds = settings.get('wait', 1)
        variables = dict(vm_configurations=configs, PowerCycleWaitSeconds=wait_seconds,
                         esxi_hostname='never-connect.invalid', esxi_port=443,
                         esxi_username='synthetic', esxi_password='synthetic',
                         esxi_validate_certs=True, esxi_ca_bundle_path='/dev/null')
        (work / 'serverlist.yml').write_text(yaml.safe_dump(variables))
        (work / 'accounts.yml').write_text('{}\n')
        (work / 'scenario.json').write_text(json.dumps(settings))
        env = dict(os.environ, PC_SCENARIO=str(work / 'scenario.json'),
                   PC_EVENTS=str(work / 'events.jsonl'), ANSIBLE_NOCOLOR='1')
        result = subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local', PLAY],
                                cwd=work, env=env, text=True, capture_output=True, timeout=90)
        events_path = work / 'events.jsonl'
        events = [json.loads(line) for line in events_path.read_text().splitlines()] if events_path.exists() else []
        mutations = [(event[1], event[2]) for event in events if event[0] == 'power']
        assert (result.returncode != 0) == failed, result.stdout + result.stderr
        assert mutations == expected, (name, mutations, expected, result.stdout)
        # Every successful own cycle includes its real pause before power-off.
        for index, event in enumerate(events):
            if event[0] == 'power' and event[2] == 'powered-off' and not settings.get('pause_failure'):
                pause = events[index - 1]
                assert pause[0] == 'pause' and int(pause[2]) == wait_seconds, events
                assert event[3] - pause[3] >= wait_seconds - 0.1, events
        if expected and not failed:
            assert '] RUN Powercycle' in result.stdout and '] pass Powercycle' in result.stdout
        if failed and mutations:
            assert '] fail Powercycle' in result.stdout


def cycle(number):
    return [(f'uuid-vm{number}', 'powered-on'), (f'uuid-vm{number}', 'powered-off')]


CASES = [
    ('five-second-wait', {'count': 1, 'wait': 5}, cycle(1), False),
    ('fifteen-sequential-cycles', {'count': 15}, sum((cycle(i) for i in range(1, 16)), []), False),
    ('three-sequential-cycles', {}, cycle(1) + cycle(2) + cycle(3), False),
    ('empty-selection', {'empty': True}, [], False),
    ('running-and-suspended', {'initial': {'vm1': 'poweredOn', 'vm2': 'suspended'}}, cycle(3), False),
    ('known-and-undeclared-mac', {'known_mac': True}, cycle(3), False),
    ('external-start', {'external': ['uuid-vm2']}, cycle(1) + [('uuid-vm2', 'powered-on')] + cycle(3), False),
    ('ambiguous-power-on', {'failure': 'powered-on'}, cycle(1) + [('uuid-vm2', 'powered-on')], True),
    ('power-off-failure', {'failure': 'powered-off'}, cycle(1) + cycle(2), True),
    ('pause-failure-cleanup', {'pause_failure': True}, cycle(1), True),
    ('missing-power-state', {'broken': 'hw_power_status'}, [], True),
    ('unknown-power-state', {'initial': {'vm2': 'unknown'}}, [], True),
    ('missing-instance-uuid', {'broken': 'instance_uuid'}, [], True),
    ('wrong-instance-identity', {'broken': 'hw_name'}, [], True),
    ('mismatched-instance-identity', {'live_name': {'vm2': 'foreign-vm'}}, [], True),
]

for position, case in enumerate(CASES, 1):
    print(f'[{position}/{len(CASES)}] RUN {case[0]}', flush=True)
    try:
        run_case(*case)
    except Exception:
        print(f'[{position}/{len(CASES)}] fail {case[0]}', flush=True)
        raise
    print(f'[{position}/{len(CASES)}] pass {case[0]}', flush=True)
