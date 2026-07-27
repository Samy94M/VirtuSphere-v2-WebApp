<?php

declare(strict_types=1);

/**
 * The MECM membership reconciliation plan (ADR-0034, decisions 1-3): a pure
 * function from what the portal wants (desired), what VirtuSphere owns
 * (provenance) and what MECM currently holds (present) to the actions the
 * device-sync may take. Pure on purpose: the PowerShell side implements the
 * identical mapping (Get-VsMembershipPlan) and both run the shared vectors in
 * tests/fixtures/mecm-plan-vectors.json, so the portal's transfer preview and
 * the script's apply can never disagree.
 *
 * The one safety rule everything here serves: a REMOVE is only ever planned
 * for a rule that is both owned and present. A rule an administrator created
 * by hand has no provenance row and is untouchable by construction; it shows
 * up as preserve_manual (it covers a desired target) or foreign (it does not),
 * and neither bucket is ever acted on.
 */

/**
 * @param array<int, array{name: string, type: string}> $desired what the VM's
 *        os/packages/mission currently ask for, matched by exact name
 * @param array<int, array<string, mixed>> $owned provenance rows
 *        (collection_id + collection_name)
 * @param array<int, array<string, mixed>> $present MECM's current direct
 *        membership rules for this device (collection_id + collection_name)
 * @return array{add: array<int, array{name: string, type: string}>, preserve: array<int, array<string, mixed>>, preserve_manual: array<int, array<string, mixed>>, remove: array<int, array<string, mixed>>, stale_owned: array<int, array<string, mixed>>, foreign: array<int, array<string, mixed>>}
 */
function mecm_membership_plan(array $desired, array $owned, array $present): array
{
    $ownedById = [];
    foreach ($owned as $rule) {
        $ownedById[(string) ($rule['collection_id'] ?? '')] = $rule;
    }

    $desiredByName = [];
    foreach ($desired as $target) {
        $desiredByName[(string) ($target['name'] ?? '')] = $target;
    }

    $plan = [
        'add' => [],
        'preserve' => [],
        'preserve_manual' => [],
        'remove' => [],
        'stale_owned' => [],
        'foreign' => [],
    ];

    $presentNames = [];
    $presentIds = [];
    foreach ($present as $rule) {
        $id = (string) ($rule['collection_id'] ?? '');
        $name = (string) ($rule['collection_name'] ?? '');
        $presentIds[$id] = true;
        $presentNames[$name] = true;
        $isOwned = isset($ownedById[$id]);
        $isDesired = isset($desiredByName[$name]);

        if ($isDesired) {
            $plan[$isOwned ? 'preserve' : 'preserve_manual'][] = $rule;
        } elseif ($isOwned) {
            $plan['remove'][] = $rule;
        } else {
            $plan['foreign'][] = $rule;
        }
    }

    foreach ($desiredByName as $name => $target) {
        if (!isset($presentNames[(string) $name])) {
            $plan['add'][] = $target;
        }
    }

    // Owned but no longer present: somebody removed the rule directly in MECM.
    // The provenance is stale and is withdrawn (reported as removed), never
    // re-fought - MECM stays the truth about what exists (decision 1).
    foreach ($ownedById as $id => $rule) {
        if (!isset($presentIds[(string) $id])) {
            $plan['stale_owned'][] = $rule;
        }
    }

    return $plan;
}

/**
 * The assignment revision the transfer preview carries (ADR-0034): a stale
 * preview must not authorize an apply over assignments that changed since the
 * page rendered. Order-insensitive, because entry order is an implementation
 * detail of the queries behind both lists.
 *
 * @param array<int, array{name: string, type: string}> $desired
 * @param array<int, array<string, mixed>> $owned
 */
function mecm_transfer_revision(array $desired, array $owned): string
{
    $desiredKeys = array_map(
        static fn (array $t): string => (string) ($t['type'] ?? '') . ':' . (string) ($t['name'] ?? ''),
        $desired
    );
    $ownedKeys = array_map(
        static fn (array $r): string => (string) ($r['collection_id'] ?? '') . ':' . (string) ($r['collection_name'] ?? ''),
        $owned
    );
    sort($desiredKeys);
    sort($ownedKeys);

    return hash('sha256', json_encode([$desiredKeys, $ownedKeys], JSON_THROW_ON_ERROR));
}

/**
 * Desired targets, owned rules, portal-view plan and revision of one VM, from
 * the database. ONE loader for the preview (vm_edit) and the revision gate
 * (vms.php): two loaders would eventually disagree about what "desired" reads,
 * and the revision comparison would reject every transfer.
 *
 * The portal-view plan passes the owned rules as `present`: the portal cannot
 * see MECM, so its preview answers exactly "what happens to OUR rules"; the
 * manual/foreign buckets exist only on the script side, where MECM is queried.
 *
 * @return array{desired: array<int, array{name: string, type: string}>, owned: array<int, array<string, mixed>>, plan: array<string, array<int, array<string, mixed>>>, revision: string}
 */
function mecm_transfer_state(mysqli $db, int $missionId, int $vmId): array
{
    require_once __DIR__ . '/repo/helpers.php';
    require_once __DIR__ . '/repo/mecm_provenance.php';

    $vm = repo_fetch_one(
        $db,
        'SELECT v.vm_os, m.mission_name FROM deploy_vms v INNER JOIN deploy_missions m ON m.id = v.mission_id WHERE v.id = ? AND v.mission_id = ? LIMIT 1',
        'ii',
        [$vmId, $missionId]
    );
    if ($vm === null) {
        throw new RuntimeException('VM not found.');
    }

    $stmt = $db->prepare('SELECT p.package_name FROM deploy_vm_packages link INNER JOIN deploy_packages p ON p.id = link.package_id WHERE link.vm_id = ? ORDER BY p.package_name');
    $stmt->bind_param('i', $vmId);
    $stmt->execute();
    $vm['packages'] = repo_fetch_all($stmt->get_result());

    $desired = mecm_desired_targets($vm);
    $owned = repo_mecm_rules_for_vm($db, $vmId);

    return [
        'desired' => $desired,
        'owned' => $owned,
        'plan' => mecm_membership_plan($desired, $owned, $owned),
        'revision' => mecm_transfer_revision($desired, $owned),
    ];
}

/**
 * The desired membership targets of one VM bundle, in plan shape. One place,
 * because the preview (vm_edit), the revision check (vms.php) and the wire
 * consumer documentation must all mean the same thing by "desired": the OS
 * collection carries the OS name, one package collection per assigned package
 * name, and the mission collection carries the mission name.
 *
 * @param array<string, mixed> $vm bundle row (vm_os, mission_name, packages)
 * @return array<int, array{name: string, type: string}>
 */
function mecm_desired_targets(array $vm): array
{
    require_once __DIR__ . '/repo/mecm_provenance.php';

    $targets = [];
    $os = trim((string) ($vm['vm_os'] ?? ''));
    if ($os !== '') {
        $targets[] = ['name' => $os, 'type' => VIRTUSPHERE_MECM_RULE_TYPE_OS];
    }
    foreach ((array) ($vm['packages'] ?? []) as $package) {
        $name = trim((string) (is_array($package) ? ($package['package_name'] ?? '') : $package));
        if ($name !== '') {
            $targets[] = ['name' => $name, 'type' => VIRTUSPHERE_MECM_RULE_TYPE_PACKAGE];
        }
    }
    $mission = trim((string) ($vm['mission_name'] ?? ''));
    if ($mission !== '') {
        $targets[] = ['name' => $mission, 'type' => VIRTUSPHERE_MECM_RULE_TYPE_MISSION];
    }

    return $targets;
}
