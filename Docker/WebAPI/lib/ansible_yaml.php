<?php

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/deploy_constants.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/repo/esxi_inventory.php';
require_once __DIR__ . '/ansible_paths.php';

/**
 * Deploy artifact content: the accounts.yml and serverlist.yml the playbooks
 * read, the per-VM value shaping behind them (disks, interfaces, autostart,
 * effective datacenter/datastore), the storage estimate that shares the same
 * shaping, and the YAML/Python escapers. Split out of ansible.php by domain
 * (ADR-0006 file-size discipline); no behaviour change.
 */

function ansible_accounts_yml(array $esxiCredential, string $esxiSecret, array $ansibleCredential, string $apiBaseUrl): string
{
    // The VMware modules expect a bare hostname/IP plus a numeric port. Stored
    // ESXi hosts may be full URLs (the portal allows them), so normalize here
    // instead of passing a raw https://host:port string into esxi_hostname.
    $rawHost = (string) ($esxiCredential['host'] ?? '');
    $esxi = credential_esxi_normalize($rawHost, $esxiCredential['port'] ?? null);
    if ($esxi === null) {
        throw new RuntimeException('ESXi credential host is invalid.');
    }
    $esxiHostname = $esxi['hostname'];
    $esxiPort = $esxi['port'];

    return implode("\n", [
        'esxi_hostname: ' . ansible_yaml_string($esxiHostname),
        'esxi_port: ' . $esxiPort,
        'esxi_username: ' . ansible_yaml_string((string) ($esxiCredential['username'] ?? '')),
        'esxi_password: ' . ansible_yaml_string($esxiSecret),
        // ansible_username and apiUrl are kept for parity with the desktop client's
        // accounts.yml; no current ESXi playbook reads them (the MAC export patches
        // its own api_base_url into upload_mac_list.py). Retire with the desktop API
        // at E3 rather than dropping them now and diverging the two formats.
        //
        // `WaitingTime` used to sit here and is deliberately gone: it was a
        // per-deploy value in a file that holds credentials and static parity
        // keys, and the start playbook read it as MINUTES. It now travels as
        // StartWaitSeconds in serverlist.yml, next to PowerCycleWaitSeconds,
        // which is where a per-job number belongs.
        'ansible_username: ' . ansible_yaml_string((string) ($ansibleCredential['username'] ?? '')),
        'apiUrl: ' . ansible_yaml_string($apiBaseUrl),
        '',
    ]);
}

/**
 * True when the PXE-relevant NIC (the one on the mission WDS VLAN) has no MAC yet,
 * so a power-cycle is needed to make ESXi assign it. Falls back to true when the
 * WDS-VLAN interface cannot be identified, so MAC generation is not skipped silently.
 */
function ansible_vm_needs_mac(array $mission, array $vm): bool
{
    $wdsVlan = trim((string) ($mission['wds_vlan'] ?? ''));
    if ($wdsVlan === '') {
        return true;
    }

    foreach (($vm['interfaces'] ?? []) as $interface) {
        if (trim((string) ($interface['vlan'] ?? '')) === $wdsVlan) {
            return trim((string) ($interface['mac'] ?? '')) === '';
        }
    }

    return true;
}

/**
 * Datacenter a single VM is deployed into. The one resolution chain:
 *
 *   VM override  ->  mission value  ->  the sole datacenter of the target host
 *
 * The last step only ever carries a value when the chosen ESXi credential reports
 * exactly one datacenter (a standalone host's implicit `ha-datacenter`), which is
 * why a mission may leave the field empty there. Both readiness gates refuse a
 * job where none of the three resolves, so this never returns an empty name for a
 * job that actually runs. All four playbooks read item.datacenter_name, so the
 * result is consistent across create, power-cycle, start and export.
 */
function ansible_effective_datacenter(array $mission, array $vm, string $hostDatacenter = ''): string
{
    $override = trim((string) ($vm['vm_datacenter'] ?? ''));
    if ($override !== '') {
        return $override;
    }

    $missionValue = trim((string) ($mission['hypervisor_datacenter'] ?? ''));

    return $missionValue !== '' ? $missionValue : trim($hostDatacenter);
}

/**
 * Datastore a single VM is created on. Same inheritance as the datacenter, but
 * only createVMs-ESXi_playbook.yml reads item.datastore_name, so changing it on
 * an existing VM has no effect until the VM is recreated.
 */
function ansible_effective_datastore(array $mission, array $vm): string
{
    $override = trim((string) ($vm['vm_datastore'] ?? ''));

    return $override !== '' ? $override : trim((string) ($mission['hypervisor_datastorage'] ?? ''));
}

function ansible_serverlist_yml(array $mission, array $vms, int $powerCycleWait = VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT, string $hostDatacenter = '', string $esxiHostName = '', int $startWait = VIRTUSPHERE_START_WAIT_SECONDS_DEFAULT, string $mode = VIRTUSPHERE_DEPLOY_MODE_FULL): string
{
    // Mission-wide values: they feed the mission_configuration block below and
    // act as the fallback for every VM without an own override. The datacenter
    // itself falls back to the target host's sole datacenter, so the block stays
    // truthful about what the VMs actually get.
    $datacenter = ansible_effective_datacenter($mission, [], $hostDatacenter);
    $datastore = trim((string) ($mission['hypervisor_datastorage'] ?? ''));
    $out = "vm_configurations:\n";

    foreach ($vms as $vm) {
        $out .= '  - vm_name: ' . ansible_yaml_string((string) ($vm['vm_name'] ?? '')) . "\n";
        // Stage 9 identity: the name selects a candidate, the instance UUID
        // proves which VM owns it. MOID is carried as the current inventory
        // handle for diagnostics and refreshes, never as the durable identity.
        $out .= '    vm_moid: ' . ansible_yaml_string(trim((string) ($vm['vm_moid'] ?? ''))) . "\n";
        $out .= '    vm_instance_uuid: ' . ansible_yaml_string(trim((string) ($vm['vm_instance_uuid'] ?? ''))) . "\n";
        $out .= '    memory: ' . ansible_positive_int($vm['vm_ram'] ?? null, (int) VIRTUSPHERE_VM_DEFAULTS['ram_mb']) . "\n";
        $out .= '    vcpus: ' . ansible_positive_int($vm['vm_cpu'] ?? null, (int) VIRTUSPHERE_VM_DEFAULTS['cpu_count']) . "\n";
        // Hot-add options (Paket F), applied by the create playbook only. Absent
        // (pre-migration) rows default to on.
        $out .= '    hotadd_cpu: ' . ((int) ($vm['cpu_hotplug'] ?? 1) === 0 ? 'false' : 'true') . "\n";
        $out .= '    hotadd_memory: ' . ((int) ($vm['ram_hotplug'] ?? 1) === 0 ? 'false' : 'true') . "\n";
        $out .= "    disks:\n";
        foreach (ansible_vm_disks($vm) as $disk) {
            $out .= '      - size_gb: ' . $disk['size_gb'] . "\n";
            $out .= '        type: ' . ansible_yaml_bare((string) $disk['type']) . "\n";
        }
        $out .= "    network:\n";
        foreach (ansible_vm_interfaces($mission, $vm) as $interface) {
            $out .= '      - name: ' . ansible_yaml_string((string) $interface['name']) . "\n";
            $out .= '        device_type: ' . ansible_yaml_bare((string) $interface['device_type']) . "\n";
        }
        $out .= '    datastore_name: ' . ansible_yaml_string(ansible_effective_datastore($mission, $vm)) . "\n";
        $out .= '    datacenter_name: ' . ansible_yaml_string(ansible_effective_datacenter($mission, $vm, $hostDatacenter)) . "\n";
        $out .= '    guest_id: ' . ansible_yaml_string(ansible_vm_string($vm, 'vm_guest_id', VIRTUSPHERE_VM_DEFAULTS['guest_id'])) . "\n";
        $out .= '    packages:';
        $packages = ansible_vm_packages($vm);
        if ($packages === []) {
            $out .= " []\n";
        } else {
            $out .= "\n";
            foreach ($packages as $package) {
                $out .= '      - ' . ansible_yaml_string($package) . "\n";
            }
        }
        $out .= '    os: ' . ansible_yaml_string(ansible_vm_string($vm, 'vm_os', 'Windows')) . "\n";
        $out .= '    needs_mac: ' . (ansible_vm_needs_mac($mission, $vm) ? 'true' : 'false') . "\n";
        // Autostart override (ADR-0025). The -1 delays travel to the module
        // unchanged; it reads them as "use the host default", so the inheritance
        // is resolved on ESXi and never guessed here. `enabled` is the EFFECTIVE
        // value (see ansible_vm_autostart): the playbook is a dumb executor and
        // must not have to re-derive it.
        $vmAutostart = ansible_vm_autostart($vm, $mission);
        $out .= "    autostart:\n";
        $out .= '      enabled: ' . ($vmAutostart['enabled'] ? 'true' : 'false') . "\n";
        $out .= '      start_delay: ' . $vmAutostart['start_delay'] . "\n";
        $out .= '      stop_delay: ' . $vmAutostart['stop_delay'] . "\n";
    }

    // Both waits are emitted in seconds and both playbooks pause in seconds, so
    // the number in the artifact is the number of seconds the deploy stands
    // still. Nothing converts a unit on the way.
    $out .= "\nPowerCycleWaitSeconds: " . $powerCycleWait . "\n";
    $out .= 'StartWaitSeconds: ' . $startWait . "\n";
    $out .= 'CreateSettleSeconds: ' . VIRTUSPHERE_CREATE_SETTLE_SECONDS . "\n";
    // Only a full pipeline may pass an unbound VM after create: the first
    // playbook in that same && sequence proved the name absent and created it.
    // A standalone power/export/start/autostart run has no such proof and must
    // require an identity previously learned by export or explicit adoption.
    $out .= 'identity_unbound_allowed: ' . ($mode === VIRTUSPHERE_DEPLOY_MODE_FULL ? 'true' : 'false') . "\n";

    $missionAutostart = ansible_mission_autostart($mission);
    $out .= "\nmission_configuration:\n";
    $out .= '  mission_name: ' . ansible_yaml_string((string) ($mission['mission_name'] ?? '')) . "\n";
    $out .= '  mission_id: ' . (int) ($mission['id'] ?? 0) . "\n";
    $out .= '  mission_datacenter: ' . ansible_yaml_string($datacenter) . "\n";
    $out .= '  mission_datastore: ' . ansible_yaml_string($datastore) . "\n";
    $out .= '  mission_notes: ' . ansible_yaml_string(ansible_mission_string($mission, 'mission_notes', 'Keine')) . "\n";
    $out .= '  mission_status: ' . ansible_yaml_string(ansible_mission_string($mission, 'mission_status', 'active')) . "\n";
    $out .= "  autostart:\n";
    $out .= '    enabled: ' . ($missionAutostart['enabled'] ? 'true' : 'false') . "\n";
    $out .= '    start_delay: ' . $missionAutostart['start_delay'] . "\n";
    $out .= '    stop_delay: ' . $missionAutostart['stop_delay'] . "\n";
    $out .= '    stop_action: ' . ansible_yaml_bare($missionAutostart['stop_action']) . "\n";
    $out .= '    wait_for_heartbeat: ' . ($missionAutostart['wait_for_heartbeat'] ? 'true' : 'false') . "\n";
    // vmware_host_auto_start's `esxi_hostname` names the host OBJECT, not the
    // address we connect to. A credential holding an IP would not resolve to it.
    // The inventory already knows the object name; empty means "not pulled yet",
    // and the playbook then falls back to the connection address.
    $out .= '    esxi_host: ' . ansible_yaml_string(trim($esxiHostName)) . "\n";

    return $out;
}

/**
 * Mission-level autostart defaults, clamped. A row from before migration 0016
 * (or a hand-built array in a test) falls back to the shared defaults, so the
 * generated YAML is always complete and the playbook never sees an undefined
 * variable.
 *
 * @return array{enabled:bool, start_delay:int, stop_delay:int, stop_action:string, wait_for_heartbeat:bool}
 */
function ansible_mission_autostart(array $mission): array
{
    $stopAction = (string) ($mission['autostart_stop_action'] ?? VIRTUSPHERE_MISSION_AUTOSTART_DEFAULTS['autostart_stop_action']);
    if (!in_array($stopAction, VIRTUSPHERE_AUTOSTART_STOP_ACTIONS, true)) {
        $stopAction = VIRTUSPHERE_MISSION_AUTOSTART_DEFAULTS['autostart_stop_action'];
    }

    return [
        'enabled' => (int) ($mission['autostart_enabled'] ?? 0) === 1,
        'start_delay' => ansible_autostart_delay($mission['autostart_start_delay'] ?? null, false),
        'stop_delay' => ansible_autostart_delay($mission['autostart_stop_delay'] ?? null, false),
        'stop_action' => $stopAction,
        'wait_for_heartbeat' => (int) ($mission['autostart_wait_for_heartbeat'] ?? 0) === 1,
    ];
}

/**
 * Per-VM autostart override, with `enabled` resolved against the mission.
 *
 * A VM only participates when its OWN checkbox is set AND its mission enables
 * autostart. The mission switch is not decoration: it decides whether the host's
 * autostart manager is turned on at all, and a VM written with `powerOn` while
 * the manager stays off would sit in the host's list waiting for some other
 * mission to switch it on. Resolving the pair here, once, keeps the playbook a
 * dumb executor and makes the rule unit-testable.
 *
 * Delays may be VIRTUSPHERE_AUTOSTART_DELAY_INHERIT and are passed through.
 *
 * @return array{enabled:bool, start_delay:int, stop_delay:int}
 */
function ansible_vm_autostart(array $vm, array $mission = []): array
{
    $missionEnabled = (int) ($mission['autostart_enabled'] ?? 0) === 1;
    $vmEnabled = (int) ($vm['autostart_enabled'] ?? 0) === 1;

    return [
        'enabled' => $missionEnabled && $vmEnabled,
        'start_delay' => ansible_autostart_delay($vm['autostart_start_delay'] ?? null, true),
        'stop_delay' => ansible_autostart_delay($vm['autostart_stop_delay'] ?? null, true),
    ];
}

/**
 * Clamps one delay. $allowInherit decides whether -1 is a legal value: only a VM
 * may inherit. A mission default of -1 would make the host inherit from itself.
 * An absent/empty value inherits where that is allowed, else it is the default.
 */
function ansible_autostart_delay(mixed $value, bool $allowInherit): int
{
    if ($value === null || $value === '') {
        return $allowInherit ? VIRTUSPHERE_AUTOSTART_DELAY_INHERIT : VIRTUSPHERE_AUTOSTART_DELAY_DEFAULT;
    }

    $int = (int) $value;
    if ($allowInherit && $int < VIRTUSPHERE_AUTOSTART_DELAY_MIN) {
        return VIRTUSPHERE_AUTOSTART_DELAY_INHERIT;
    }

    return max(VIRTUSPHERE_AUTOSTART_DELAY_MIN, min(VIRTUSPHERE_AUTOSTART_DELAY_MAX, $int));
}

function ansible_vm_disks(array $vm): array
{
    $disks = [];
    foreach (($vm['disks'] ?? []) as $disk) {
        $size = ansible_positive_int($disk['disk_size'] ?? null, (int) VIRTUSPHERE_VM_DEFAULTS['disk_size_gb']);
        $type = strtolower(trim((string) ($disk['disk_type'] ?? VIRTUSPHERE_VM_DEFAULTS['disk_type'])));
        $disks[] = ['size_gb' => $size, 'type' => $type !== '' ? $type : VIRTUSPHERE_VM_DEFAULTS['disk_type']];
    }

    if ($disks === []) {
        $disks[] = [
            'size_gb' => (int) VIRTUSPHERE_VM_DEFAULTS['disk_size_gb'],
            'type' => VIRTUSPHERE_VM_DEFAULTS['disk_type'],
        ];
    }

    return $disks;
}

/**
 * Provisioned disk size of one VM in bytes. Reads the same ansible_vm_disks() the
 * serverlist is built from, so the storage estimate on the deploy page and what
 * the create playbook actually provisions can never drift apart, down to the
 * default disk a VM without rows gets.
 *
 * The provisioned size is the right figure even for thin disks: it is what the
 * datastore has to be able to hold, and what a thick disk claims immediately.
 */
function ansible_vm_disk_bytes(array $vm): int
{
    $gb = 0;
    foreach (ansible_vm_disks($vm) as $disk) {
        $gb += (int) $disk['size_gb'];
    }

    return $gb * 1024 * 1024 * 1024;
}

/**
 * Storage a deploy of these VMs would need, grouped by the datastore each VM
 * actually lands on (ansible_effective_datastore: the per-VM override, else the
 * mission value). Warn-only input for the deploy page; it never gates a job.
 *
 * The group key is esxi_inventory_name_key(), so two VMs whose datastore differs
 * only in case or padding still add up on one row and can be matched against the
 * cached inventory. The label keeps the first spelling seen, like every other
 * dedupe in the inventory layer. A VM whose effective datastore is empty (a
 * template, or a mission that never set one) lands under the '' key and is the
 * caller's job to label.
 *
 * @param array<int, array<string, mixed>> $vms VMs with their `disks` attached
 * @return array<string, array{name:string, bytes:int, vm_count:int, per_vm:array<int,int>}>
 */
function ansible_storage_by_datastore(array $mission, array $vms): array
{
    $rows = [];
    foreach ($vms as $vm) {
        $name = ansible_effective_datastore($mission, $vm);
        $key = esxi_inventory_name_key($name);
        if (!isset($rows[$key])) {
            $rows[$key] = ['name' => $name, 'bytes' => 0, 'vm_count' => 0, 'per_vm' => []];
        }
        $bytes = ansible_vm_disk_bytes($vm);
        $rows[$key]['bytes'] += $bytes;
        $rows[$key]['vm_count']++;
        $rows[$key]['per_vm'][(int) ($vm['id'] ?? 0)] = $bytes;
    }

    return $rows;
}

function ansible_vm_interfaces(array $mission, array $vm): array
{
    $interfaces = [];
    foreach (($vm['interfaces'] ?? []) as $interface) {
        $name = trim((string) ($interface['vlan'] ?? ''));
        if ($name === '') {
            continue;
        }

        $type = trim((string) ($interface['type'] ?? ''));
        $interfaces[] = [
            'name' => $name,
            'device_type' => $type !== '' ? $type : VIRTUSPHERE_VM_DEFAULTS['interface_type'],
        ];
    }

    if ($interfaces === []) {
        $fallbackVlan = trim((string) ($mission['wds_vlan'] ?? ''));
        if ($fallbackVlan === '') {
            throw new RuntimeException('VM has no network interfaces and mission WDS VLAN is empty.');
        }

        $interfaces[] = [
            'name' => $fallbackVlan,
            'device_type' => VIRTUSPHERE_VM_DEFAULTS['interface_type'],
        ];
    }

    return $interfaces;
}

function ansible_vm_packages(array $vm): array
{
    $packages = [];
    foreach (($vm['packages'] ?? []) as $package) {
        $name = trim((string) ($package['package_name'] ?? ''));
        if ($name !== '') {
            $packages[] = $name;
        }
    }

    return $packages;
}

function ansible_patch_upload_script(string $path, string $apiBaseUrl, int $missionId, int $jobId, ?string $correlationId = null): void
{
    $script = file_get_contents($path);
    if ($script === false) {
        throw new RuntimeException('Cannot read upload_mac_list.py.');
    }

    // ADR-0032: default to the current execution's id (the worker has adopted
    // the job's), so callers do not need to thread it through.
    $correlationId ??= virtusphere_correlation_id();

    // The portal's own certificate fingerprint, but only when the callback URL is
    // actually https: the MAC callback is the one channel that decides whether a
    // deploy succeeded, and against a self-signed certificate an unpinned upload
    // would fail with a bare network error. Empty for http and for a certificate
    // from a PKI the Ansible host already trusts (then the default chain check
    // applies, which is the stronger of the two).
    $certFingerprint = ansible_portal_cert_fingerprint($apiBaseUrl);

    $expected = [
        'api_base_url = ' . ansible_python_string($apiBaseUrl),
        'mission_id = ' . ansible_python_string((string) $missionId),
        'job_id = ' . ansible_python_string((string) $jobId),
        'correlation_id = ' . ansible_python_string($correlationId),
        'cert_sha256 = ' . ansible_python_string($certFingerprint),
    ];
    $script = preg_replace("/^api_base_url = .*$/m", 'api_base_url = ' . ansible_python_string($apiBaseUrl), $script, 1);
    $script = preg_replace("/^mission_id = .*$/m", 'mission_id = ' . ansible_python_string((string) $missionId), (string) $script, 1);
    $script = preg_replace('/^job_id = .*$/m', 'job_id = ' . ansible_python_string((string) $jobId), (string) $script, 1);
    $script = preg_replace('/^correlation_id = .*$/m', 'correlation_id = ' . ansible_python_string($correlationId), (string) $script, 1);
    $script = preg_replace('/^cert_sha256 = .*$/m', 'cert_sha256 = ' . ansible_python_string($certFingerprint), (string) $script, 1);
    if ($script === null) {
        throw new RuntimeException('Cannot patch upload_mac_list.py.');
    }
    foreach ($expected as $line) {
        if (!str_contains($script, $line)) {
            throw new RuntimeException('Cannot patch upload_mac_list.py.');
        }
    }

    ansible_write_file($path, $script);
}

/**
 * SHA-256 fingerprint of the portal's installed certificate, hex without
 * separators, for pinning the MAC callback. Empty string whenever pinning does
 * not apply or cannot be established:
 *
 *  - the callback URL is http (nothing to pin),
 *  - no certificate is installed (the portal is not serving TLS at all),
 *  - the metadata cannot be read (then the Ansible host does its normal chain
 *    check, which is the stronger answer, and a self-signed certificate fails
 *    loudly rather than being trusted blindly).
 *
 * The last case is why this never throws: a fingerprint we cannot determine must
 * degrade to "verify properly", never to "verify nothing".
 */
function ansible_portal_cert_fingerprint(string $apiBaseUrl): string
{
    if (!str_starts_with(strtolower(trim($apiBaseUrl)), 'https://')) {
        return '';
    }

    require_once __DIR__ . '/https_config.php';
    $metadata = https_installed_metadata();
    $fingerprint = (string) ($metadata['fingerprint'] ?? '');

    // https_cert_metadata() formats it with colons for display; the wire form is
    // bare lowercase hex, which is what hashlib.sha256().hexdigest() produces.
    $bare = strtolower((string) preg_replace('/[^0-9A-Fa-f]/', '', $fingerprint));

    return strlen($bare) === 64 ? $bare : '';
}

function ansible_vm_string(array $vm, string $key, string|int $default): string
{
    $value = trim((string) ($vm[$key] ?? ''));
    return $value !== '' ? $value : (string) $default;
}

function ansible_mission_string(array $mission, string $key, string $default): string
{
    $value = trim((string) ($mission[$key] ?? ''));
    return $value !== '' ? $value : $default;
}

function ansible_positive_int(mixed $value, int $default): int
{
    $int = (int) trim((string) $value);
    return $int > 0 ? $int : $default;
}

function ansible_yaml_string(string $value): string
{
    $escaped = strtr($value, [
        "\\" => "\\\\",
        '"' => '\\"',
        "\r" => "\\r",
        "\n" => "\\n",
    ]);
    // YAML forbids raw C0 control characters and DEL even inside a double-quoted
    // scalar (\t, \r and \n excepted; the latter two are handled above). Left
    // literal, a single such byte in a free-text field (a mission note pasted
    // from Windows, an imported name) makes PyYAML reject the whole document and
    // fails the deploy, not just one field. Emit them as \xNN hex escapes.
    $escaped = preg_replace_callback(
        '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
        static fn (array $m): string => sprintf('\\x%02X', ord($m[0])),
        $escaped
    );

    return '"' . $escaped . '"';
}

function ansible_yaml_bare(string $value): string
{
    $value = trim($value);
    if ($value === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $value) !== 1) {
        return ansible_yaml_string($value);
    }

    return $value;
}

function ansible_python_string(string $value): string
{
    return "'" . strtr($value, [
        "\\" => "\\\\",
        "'" => "\\'",
        "\r" => "\\r",
        "\n" => "\\n",
    ]) . "'";
}
