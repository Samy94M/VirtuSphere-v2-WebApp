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
INFO_TASKS = 'powercycle_vm_info.yml'
# K4a: the repeated ESXi calls and the shared pause run as production files too.
INCLUDES = ('powercycle_power_on_tasks.yml', 'powercycle_power_off_tasks.yml',
            'esxi_vm_read_tasks.yml', 'esxi_retry_wait_tasks.yml')
RETRY_PAUSES = [1, 2, 3]

TOUCH_STUB = '''
import json
import os
import sys
import time

args = dict(zip(sys.argv[1::2], sys.argv[2::2]))
vm_id = int(args['--vm-id'])
with open(os.environ['PC_EVENTS'], 'a') as stream:
    denied = vm_id == int(json.loads(open(os.environ['PC_SCENARIO']).read()).get('touch_failure_vm_id', 0))
    event = ['touch_refused' if denied else 'touch', vm_id, args['--phase'], time.monotonic()]
    stream.write(json.dumps(event) + '\\n')
if denied:
    raise SystemExit(1)
'''

# A stateful host double. The state file holds the power state per VM and
# how often each kind of call has been seen, so a scenario can fail a call a
# few times and then let it pass, or lose the answer of a power-on that did
# happen (W2).
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
        state_path = Path(os.environ['PC_STATE'])
        state = json.loads(state_path.read_text()) if state_path.exists() else {'power': {}, 'calls': {}}
        kind = args.pop('qa_kind')
        # AN-01: every call is addressed by instance UUID; events keep the VM name as identity for info.
        assert args.get('use_instance_uuid') is True
        assert 'name' not in args
        vm = args['uuid'][len('uuid-'):]
        identity = vm if kind == 'info' else args['uuid']
        event = [kind, identity, args.get('state'), time.monotonic()]
        with open(os.environ['PC_EVENTS'], 'a') as stream:
            stream.write(json.dumps(event) + '\\n')
        power = state['power'].setdefault(vm, scenario.get('initial', {}).get(vm, 'poweredOff'))
        key = kind + ':' + (args.get('state') or '') + ':' + vm
        seen = state['calls'].get(key, 0)
        state['calls'][key] = seen + 1
        result = None
        if kind == 'info':
            unreadable = scenario.get('read_fail_after_power_on') == vm and state['calls'].get('power:powered-on:' + vm, 0) > 0
            if scenario.get('info_failure') == vm or unreadable or seen < scenario.get('info_fail_times', {}).get(vm, 0):
                result = dict(failed=True, msg='synthetic info failure')
            else:
                shown = 'poweredOff' if power == 'pending' else power
                instance = dict(hw_name=scenario.get('live_name', {}).get(vm, vm), instance_uuid='uuid-' + vm,
                                hw_power_status=shown)
                if scenario.get('broken'):
                    instance.pop(scenario['broken'])
                result = dict(changed=False, instance=instance)
        else:
            assert args['force'] is (args['state'] == 'powered-off')
            uuid = args['uuid']
            if args['state'] == 'powered-on':
                if power == 'pending':
                    state['power'][vm] = 'poweredOn'
                    result = dict(changed=False, msg='already on')
                elif scenario.get('failure') == 'powered-on' and uuid == 'uuid-vm2':
                    result = dict(failed=True, msg='synthetic power-on failure')
                elif uuid in scenario.get('on_lost', []) and seen == 0:
                    state['power'][vm] = 'poweredOn'
                    result = dict(failed=True, msg='synthetic lost answer')
                elif uuid in scenario.get('on_pending', []) and seen == 0:
                    state['power'][vm] = 'pending'
                    result = dict(failed=True, msg='synthetic pending answer')
                elif seen < scenario.get('on_fail_times', {}).get(uuid, 0):
                    result = dict(failed=True, msg='synthetic power-on failure')
                else:
                    external = uuid in scenario.get('external', [])
                    changed = power != 'poweredOn' and not external
                    state['power'][vm] = 'poweredOn'
                    result = dict(changed=changed, msg='synthetic outcome')
            else:
                if scenario.get('failure') == 'powered-off' and uuid == 'uuid-vm2':
                    result = dict(failed=True, msg='synthetic power-off failure')
                elif seen < scenario.get('off_fail_times', {}).get(uuid, 0):
                    result = dict(failed=True, msg='synthetic power-off failure')
                else:
                    changed = power != 'poweredOff'
                    state['power'][vm] = 'poweredOff'
                    result = dict(changed=changed, msg='synthetic outcome')
        state_path.write_text(json.dumps(state))
        return result
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


def run_case(name, settings, expected, failed=False, output=()):
    with tempfile.TemporaryDirectory(prefix='powercycle-') as directory:
        work = Path(directory)
        plugins = work / 'action_plugins'
        plugins.mkdir()
        (plugins / 'qa_vmware.py').write_text(ACTION)
        (plugins / 'qa_pause.py').write_text(PAUSE)
        for filename in (PLAY, TASKS, INFO_TASKS) + INCLUDES:
            source = (ROOT / 'Ansible' / filename).read_text()
            lines = source.splitlines()
            for index in range(len(lines) - 1, -1, -1):
                stripped = lines[index].strip()
                indent = len(lines[index]) - len(lines[index].lstrip())
                if stripped == 'community.vmware.vmware_guest_info:':
                    lines[index] = ' ' * indent + 'qa_vmware:'
                    lines.insert(index + 1, ' ' * (indent + 2) + 'qa_kind: info')
                elif stripped == 'community.vmware.vmware_guest_powerstate:':
                    lines[index] = ' ' * indent + 'qa_vmware:'
                    lines.insert(index + 1, ' ' * (indent + 2) + 'qa_kind: power')
            source = '\n'.join(lines).replace('ansible.builtin.pause:', 'qa_pause:')
            (work / filename).write_text(source)
        (work / 'report_vm_touch.py').write_text(TOUCH_STUB)
        configs = [dict(vm_name=f'vm{i}', vm_instance_uuid=f'uuid-vm{i}', portal_vm_id=1000 + i,
                        datacenter_name='qa', needs_mac=True) for i in range(1, settings.get('count', 3) + 1)]
        if settings.get('empty'):
            configs = []
        if settings.get('known_mac'):
            configs[0]['needs_mac'] = False
            configs[1].pop('needs_mac')
        wait_seconds = settings.get('wait', 1)
        variables = dict(vm_configurations=configs,
                         job_execution={'attempt': 1, 'generation': 'a' * 32},
                         PowerCycleWaitSeconds=wait_seconds,
                         EsxiRetryPauseSeconds=RETRY_PAUSES,
                         esxi_hostname='never-connect.invalid', esxi_port=443,
                         esxi_username='synthetic', esxi_password='synthetic',
                         esxi_validate_certs=True, esxi_ca_bundle_path='/dev/null')
        (work / 'serverlist.yml').write_text(yaml.safe_dump(variables))
        (work / 'accounts.yml').write_text('{}\n')
        (work / 'scenario.json').write_text(json.dumps(settings))
        env = dict(os.environ, PC_SCENARIO=str(work / 'scenario.json'), PC_STATE=str(work / 'state.json'),
                   PC_EVENTS=str(work / 'events.jsonl'), ANSIBLE_NOCOLOR='1')
        result = subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local', PLAY],
                                cwd=work, env=env, text=True, capture_output=True, timeout=180)
        events_path = work / 'events.jsonl'
        events = [json.loads(line) for line in events_path.read_text().splitlines()] if events_path.exists() else []
        mutations = [(event[1], event[2]) for event in events if event[0] == 'power']
        assert (result.returncode != 0) == failed, result.stdout + result.stderr
        assert mutations == expected, (name, mutations, expected, result.stdout)
        names = {f'vm{i}': 1000 + i for i in range(1, settings.get('count', 3) + 1)}
        if settings.get('empty'):
            names = {}
        if settings.get('known_mac'):
            names = {'vm3': 1003}
        # The first read of every VM follows its own confirmed touch; later
        # reads of the same VM are retries or the W2 state read.
        first_read = set()
        for position, event in enumerate(events):
            if event[0] == 'info' and event[1] not in first_read:
                first_read.add(event[1])
                previous = events[position - 1]
                assert previous[0] == 'touch' and previous[1] == names[event[1]], (name, events)
        refused = [event for event in events if event[0] == 'touch_refused']
        if refused:
            refused_index = events.index(refused[0])
            assert not any(event[0] == 'info' and event[1] == f'vm{refused[0][1] - 1000}'
                           for event in events[refused_index + 1:]), (name, events)
            assert not any(event[0] == 'touch' for event in events[refused_index + 1:]), (name, events)
        failed_info = settings.get('info_failure')
        if failed_info:
            info_index = next(i for i, event in enumerate(events) if event[0] == 'info' and event[1] == failed_info)
            later = events[info_index + 1:]
            assert not any(event[0] == 'touch' for event in later), (name, events)
            assert not any(event[0] == 'info' and event[1] != failed_info for event in later), (name, events)
            assert sum(1 for event in events if event[0] == 'info' and event[1] == failed_info) == len(RETRY_PAUSES) + 1, (name, events)
            assert 'synthetic info failure' in result.stdout, (name, result.stdout)
        # Every own cycle includes its real wait before the first power-off;
        # a repeated power-off follows its retry pause.
        powered_off = set()
        for index, event in enumerate(events):
            if event[0] == 'power' and event[2] == 'powered-off' and not settings.get('pause_failure'):
                pause = events[index - 1]
                assert pause[0] == 'pause', events
                if event[1] not in powered_off:
                    assert int(pause[2]) == wait_seconds, events
                    assert event[3] - pause[3] >= wait_seconds - 0.1, events
                else:
                    assert int(pause[2]) in RETRY_PAUSES, events
                powered_off.add(event[1])
        if expected and not failed:
            assert '] RUN Powercycle' in result.stdout and '] pass Powercycle' in result.stdout
        if failed and mutations:
            assert '] fail Powercycle' in result.stdout
        for text in output:
            assert text in result.stdout, (name, text, result.stdout)


def cycle(number):
    return [(f'uuid-vm{number}', 'powered-on'), (f'uuid-vm{number}', 'powered-off')]


ATTEMPTS = len(RETRY_PAUSES) + 1

CASES = [
    ('five-second-wait', {'count': 1, 'wait': 5}, cycle(1), False),
    ('fifteen-sequential-cycles', {'count': 15}, sum((cycle(i) for i in range(1, 16)), []), False),
    ('three-sequential-cycles', {}, cycle(1) + cycle(2) + cycle(3), False),
    ('empty-selection', {'empty': True}, [], False),
    ('running-and-suspended', {'initial': {'vm1': 'poweredOn', 'vm2': 'suspended'}}, cycle(3), False),
    ('known-and-undeclared-mac', {'known_mac': True}, cycle(3), False),
    ('external-start', {'external': ['uuid-vm2']}, cycle(1) + [('uuid-vm2', 'powered-on')] + cycle(3), False),
    # W2: a power-on that keeps failing while the VM stays off is read back and
    # tried again, never more than the retry budget, and never powered off.
    ('ambiguous-power-on', {'failure': 'powered-on'}, cycle(1) + [('uuid-vm2', 'powered-on')] * ATTEMPTS, True,
     ('CHECK Powercycle vm2', 'RETRY Powercycle vm2', 'synthetic power-on failure')),
    # W2: the answer was lost, the VM is on. It counts as this run's start and
    # is powered off again; the run goes on.
    ('power-on-lost-answer', {'on_lost': ['uuid-vm2']}, cycle(1) + cycle(2) + cycle(3), False,
     ('CHECK Powercycle vm2', 'counts as started by this run')),
    ('power-on-transient', {'on_fail_times': {'uuid-vm2': 1}},
     cycle(1) + [('uuid-vm2', 'powered-on')] + cycle(2) + cycle(3), False, ('RETRY Powercycle vm2',)),
    # W2: the failed attempt took effect only after the state read. The next
    # attempt answers unchanged; the VM is still this run's start.
    ('power-on-pending', {'on_pending': ['uuid-vm2']},
     cycle(1) + [('uuid-vm2', 'powered-on')] + cycle(2) + cycle(3), False),
    ('power-on-state-unreadable', {'failure': 'powered-on', 'read_fail_after_power_on': 'vm2'},
     cycle(1) + [('uuid-vm2', 'powered-on')], True, ('power state could not be read',)),
    ('power-off-transient', {'off_fail_times': {'uuid-vm2': 2}},
     cycle(1) + cycle(2) + [('uuid-vm2', 'powered-off')] * 2 + cycle(3), False, ('RETRY Powercycle power-off vm2',)),
    ('power-off-failure', {'failure': 'powered-off'},
     cycle(1) + cycle(2) + [('uuid-vm2', 'powered-off')] * (ATTEMPTS - 1), True,
     ('hard power-off failed after 4 attempt(s)', 'synthetic power-off failure')),
    ('pause-failure-cleanup', {'pause_failure': True}, cycle(1), True),
    ('missing-power-state', {'broken': 'hw_power_status'}, [], True),
    ('unknown-power-state', {'initial': {'vm2': 'unknown'}}, [], True),
    ('missing-instance-uuid', {'broken': 'instance_uuid'}, [], True),
    ('wrong-instance-identity', {'broken': 'hw_name'}, [], True),
    ('mismatched-instance-identity', {'live_name': {'vm2': 'foreign-vm'}}, [], True),
    ('touch-refused-before-first-info', {'touch_failure_vm_id': 1001}, [], True),
    ('touch-refused-does-not-query-or-mark-neighbor', {'touch_failure_vm_id': 1002}, [], True),
    ('info-transient', {'info_fail_times': {'vm2': 2}}, cycle(1) + cycle(2) + cycle(3), False,
     ('RETRY Powercycle info vm2', 'attempt 3 of 4')),
    ('info-failure-stops-before-next-touch', {'info_failure': 'vm1'}, [], True,
     ('after 4 attempt(s)',)),
]

for position, case in enumerate(CASES, 1):
    print(f'[{position}/{len(CASES)}] RUN {case[0]}', flush=True)
    try:
        run_case(*case)
    except Exception:
        print(f'[{position}/{len(CASES)}] fail {case[0]}', flush=True)
        raise
    print(f'[{position}/{len(CASES)}] pass {case[0]}', flush=True)
