<?php

declare(strict_types=1);

/**
 * What a VM may be created with on one ESXi host: the virtual hardware version
 * and the guest OS identifiers, derived from the release the last inventory
 * pull reported (ADR-0023 amendment 7).
 *
 * Until 15.10.2026 the create playbook hardwired hardware version 21, which
 * ESXi cannot create before 8.0 Update 2. On a 7.0.3 host every create failed
 * with "The operation is not supported on the object" while the portal knew
 * the host version all along. Before July 2026 the playbook set no version at
 * all and ESXi picked its own default; that is still the answer whenever the
 * host version is not CURRENTLY known.
 *
 * The cache-never-blocks rule holds: only a fresh, parsed host version can
 * refuse a job or pin a version. Stale or missing facts leave the decision to
 * ESXi (no version passed) and refuse nothing.
 */

require_once __DIR__ . '/defaults.php';
require_once __DIR__ . '/esxi_capabilities.php';
require_once __DIR__ . '/validate.php';

/**
 * The first ESXi release that can CREATE each hardware version (Broadcom KB
 * 312100, "ESXi hosts and compatible virtual machine hardware versions").
 * Ascending; a host takes the last row its release reaches. A release newer
 * than the last row keeps the last row: a newer host still creates it, and a
 * higher version is a deliberate decision with its own evidence, not something
 * a host upgrade should change behind the operator's back.
 */
const VIRTUSPHERE_ESXI_CREATE_HARDWARE_VERSIONS = [
    ['esxi' => '7.0.0', 'vmx' => 17],
    ['esxi' => '7.0.1', 'vmx' => 18],
    ['esxi' => '7.0.2', 'vmx' => 19],
    ['esxi' => '8.0.0', 'vmx' => 20],
    ['esxi' => '8.0.2', 'vmx' => 21],
];

const VIRTUSPHERE_ESXI_CREATE_BLOCK_HOST = 'esxi_create_host_unsupported';
const VIRTUSPHERE_ESXI_CREATE_BLOCK_GUEST = 'esxi_create_guest_unsupported';

/**
 * The host release as "major.minor.patch", or null when the facts do not prove
 * one. Only a standalone host counts: through vCenter the version would be the
 * vCenter's, not the host's that creates the VM, and vCenter is no deploy
 * target anyway. The product string looks like "VMware ESXi 7.0.3 build-23794027";
 * the first dotted number is the release, never the build.
 *
 * @param array{api_type:?string, product_version:?string} $facts from esxi_capabilities()
 */
function esxi_host_release(array $facts): ?string
{
    if (($facts['api_type'] ?? null) !== 'HostAgent') {
        return null;
    }
    $product = (string) ($facts['product_version'] ?? '');
    if (preg_match('/\bESXi\b\D*?(\d+)\.(\d+)(?:\.(\d+))?/i', $product, $match) !== 1) {
        return null;
    }

    return (int) $match[1] . '.' . (int) $match[2] . '.' . (int) ($match[3] ?? 0);
}

/**
 * "8.0 Update 2" for "8.0.2", "7.0" for "7.0.0": the way Broadcom and the
 * operator name a release. VMware terms stay English in both portal languages.
 */
function esxi_release_label(string $release): string
{
    $parts = array_map('intval', explode('.', $release) + [0, 0, 0]);
    $base = $parts[0] . '.' . $parts[1];

    return $parts[2] > 0 ? $base . ' Update ' . $parts[2] : $base;
}

/** The hardware version this release creates, or null below the lowest row. */
function esxi_create_hardware_version_for(string $release): ?int
{
    $version = null;
    foreach (VIRTUSPHERE_ESXI_CREATE_HARDWARE_VERSIONS as $row) {
        if (version_compare($release, $row['esxi'], '>=')) {
            $version = $row['vmx'];
        }
    }

    return $version;
}

/**
 * Whether a mode creates VMs. A system mode (`inventory`) is excluded first,
 * the same way ansible_mode_writes_esxi() does it: ansible_playbooks_for_mode()
 * answers an unknown mode with the full sequence, so asking it alone would
 * call a read-only pull a create.
 */
function esxi_create_mode_applies(string $mode): bool
{
    return !array_key_exists($mode, VIRTUSPHERE_SYSTEM_PLAYBOOKS) && ansible_mode_creates_vms($mode);
}

/** The lowest release that can create any VM at all, from the table above. */
function esxi_create_release_floor(): string
{
    return VIRTUSPHERE_ESXI_CREATE_HARDWARE_VERSIONS[0]['esxi'];
}

/** The first release that knows a guest identifier; null for a legacy value without a catalog row. */
function esxi_guest_os_min_release(string $guestId): ?string
{
    foreach (VIRTUSPHERE_GUEST_OS_OPTIONS as $option) {
        if ((string) $option['guest_id'] === $guestId) {
            return (string) $option['min_esxi'];
        }
    }

    return null;
}

/**
 * The create verdict for one host and one VM scope.
 *
 * A mode that creates no VM is always `ok` without a version. Otherwise:
 * - fresh, proven release below the table: `block` (host);
 * - fresh, proven release and a VM whose guest OS that release does not know:
 *   `block` (guest), every such VM listed in scope order;
 * - fresh, proven release: `ok` with the pinned hardware version;
 * - anything else: `ok` without a version, so ESXi picks its own default.
 *
 * @param array<string,mixed>|null $state
 * @param list<array{id?:int, vm_name?:string, vm_guest_id?:string}> $vms
 * @return array{verdict:string, reason:?string, hardware_version:?int, release:?string, known:bool, unsupported_guests:list<array{vm_id:int, vm_name:string, guest_id:string, min_release:string}>}
 */
function esxi_create_compatibility(?array $state, int $intervalHours, string $mode, array $vms, ?int $now = null): array
{
    $result = ['verdict' => 'ok', 'reason' => null, 'hardware_version' => null, 'release' => null, 'known' => false, 'unsupported_guests' => []];
    if (!esxi_create_mode_applies($mode)) {
        return $result;
    }
    $release = esxi_host_release(esxi_capabilities($state));
    if ($release === null || !esxi_capabilities_fresh($state, $intervalHours, $now)) {
        return $result;
    }
    $result['release'] = $release;
    $result['known'] = true;
    $result['hardware_version'] = esxi_create_hardware_version_for($release);
    if ($result['hardware_version'] === null) {
        $result['verdict'] = 'block';
        $result['reason'] = VIRTUSPHERE_ESXI_CREATE_BLOCK_HOST;

        return $result;
    }
    foreach ($vms as $vm) {
        $guestId = (string) ($vm['vm_guest_id'] ?? '');
        $minRelease = esxi_guest_os_min_release($guestId);
        if ($minRelease !== null && version_compare($release, $minRelease, '<')) {
            $result['unsupported_guests'][] = [
                'vm_id' => (int) ($vm['id'] ?? 0),
                'vm_name' => (string) ($vm['vm_name'] ?? ''),
                'guest_id' => $guestId,
                'min_release' => $minRelease,
            ];
        }
    }
    if ($result['unsupported_guests'] !== []) {
        $result['verdict'] = 'block';
        $result['reason'] = VIRTUSPHERE_ESXI_CREATE_BLOCK_GUEST;
    }

    return $result;
}

/**
 * The operator sentence for one unsupported guest, shared by the queue blocker
 * (one per VM, linked to its editor) and the enqueue/worker refusal.
 *
 * @param array{vm_name:string, guest_id:string, min_release:string} $guest
 */
function esxi_create_guest_finding_text(array $guest, string $release): string
{
    $labelKey = '';
    foreach (VIRTUSPHERE_GUEST_OS_OPTIONS as $option) {
        if ((string) $option['guest_id'] === $guest['guest_id']) {
            $labelKey = (string) $option['label_key'];
        }
    }

    return validator_text('layout.err_esxi_create_guest', 'VM :name: the guest OS :os needs at least ESXi :floor, but the selected host reports ESXi :release. Choose another guest OS in the VM or use a newer host.', [
        'name' => $guest['vm_name'],
        'os' => $labelKey !== '' ? validator_text($labelKey, $guest['guest_id']) : $guest['guest_id'],
        'floor' => esxi_release_label($guest['min_release']),
        'release' => esxi_release_label($release),
    ]);
}

/** The operator sentence for a refused verdict; empty for `ok`. */
function esxi_create_compatibility_message(array $verdict): string
{
    if ($verdict['verdict'] !== 'block') {
        return '';
    }
    if ($verdict['reason'] === VIRTUSPHERE_ESXI_CREATE_BLOCK_HOST) {
        return validator_text('layout.err_esxi_create_host', 'VMs cannot be created on the selected ESXi host: it reports ESXi :release, and VirtuSphere creates VMs from ESXi :floor. After a host update, refresh the ESXi inventory.', [
            'release' => esxi_release_label((string) $verdict['release']),
            'floor' => esxi_release_label(esxi_create_release_floor()),
        ]);
    }

    return implode(' ', array_map(
        static fn (array $guest): string => esxi_create_guest_finding_text($guest, (string) $verdict['release']),
        $verdict['unsupported_guests']
    ));
}

/** One-line, non-localized job-log summary of the verdict (operator diagnostics, English like its neighbours). */
function esxi_create_compatibility_log_line(array $verdict): string
{
    if (!$verdict['known']) {
        return 'ESXi create preflight: host release not currently known. No hardware version is pinned; ESXi creates the VM at its own default and stays the authority for the guest OS.';
    }
    $line = 'ESXi create preflight: host release ' . (string) $verdict['release'];
    if ($verdict['hardware_version'] === null) {
        return $line . ' is below ESXi ' . esxi_release_label(esxi_create_release_floor()) . '; no VM can be created there.';
    }
    $line .= ', hardware version ' . (int) $verdict['hardware_version'] . ' for new VMs; existing VMs keep theirs.';
    foreach ($verdict['unsupported_guests'] as $guest) {
        $line .= ' ' . $guest['vm_name'] . ': guest ' . $guest['guest_id'] . ' needs ESXi ' . esxi_release_label($guest['min_release']) . '.';
    }

    return $line;
}
