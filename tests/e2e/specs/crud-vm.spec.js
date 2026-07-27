// TESTPLAN 3.3 / E6: the five VM actions (row + bulk + the vm_edit variant),
// verified via DB state. MECM reset needs an imported MAC (repo rule), so every
// seeded VM carries an interface row with one. The bulk buttons stay disabled
// until a checkbox is ticked, which the specs exercise implicitly.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const PREFIX = 'e2evm-';

/** Mission plus `count` VMs, each deployed/registered with an imported MAC. */
function seedMission(name, count) {
  return phpJson(`
$db = db();
$id = repo_create_mission($db, ['mission_name' => '${name}', 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local'], false, null);
$vmIds = [];
for ($i = 1; $i <= ${Number(count)}; $i++) {
    $vmName = 'E2EVM' . $i;
    $stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os, lifecycle_state, mecm_sync_state, mecm_id, updated) VALUES (?, ?, ?, 'Win11', 'deployed', 'registered', ?, 1)");
    $mecm = 'E2E-MECM-' . $i;
    $stmt->bind_param('isss', $id, $vmName, $vmName, $mecm);
    $stmt->execute();
    $vmId = (int) $db->insert_id;
    $stmt = $db->prepare("INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac, mode) VALUES (?, '', '', '', 'WDS', ?, 'dhcp')");
    $mac = sprintf('00:50:56:AA:BB:%02X', $vmId % 256);
    $stmt->bind_param('is', $vmId, $mac);
    $stmt->execute();
    $vmIds[] = $vmId;
}
echo 'JSON' . json_encode(['missionId' => $id, 'vmIds' => $vmIds]) . 'JSON';
`, ['lib/repo/missions.php']);
}

function vmRow(id) {
  return phpJson(`
$db = db();
$stmt = $db->prepare('SELECT vm_name, mecm_id, mecm_sync_state, lifecycle_state, updated FROM deploy_vms WHERE id = ? LIMIT 1');
$id = ${Number(id)};
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc() ?: null) . 'JSON';
`);
}

function cleanup() {
  runPhp(`
$db = db();
$like = '${PREFIX}%';
$stmt = $db->prepare('DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)');
$stmt->bind_param('s', $like);
$stmt->execute();
$stmt = $db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
$stmt->bind_param('s', $like);
$stmt->execute();
echo 'CLEANED';
`);
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

function rowFor(page, vmName) {
  return page.locator('tbody tr', { hasText: vmName }).first();
}

// e2e-covers: vms.php:reset_mecm_id
// e2e-covers-cancel: vms.php:reset_mecm_id
test('row MECM reset: Cancel keeps the ID, Confirm clears it and re-queues the sync', async ({ page }) => {
  const seed = seedMission(PREFIX + 'reset', 1);
  const vmId = seed.vmIds[0];

  await page.goto(`vms.php?mission_id=${seed.missionId}`);
  const dialog = page.locator('[data-confirm-dialog]');
  const reset = rowFor(page, 'E2EVM1').locator('form:has(input[name="action"][value="reset_mecm_id"]) button');

  await reset.click();
  await expect(dialog, 'the reset asks first').toBeVisible();
  await expect(page.locator('[data-confirm-msg]'), 'the dialog names the VM').toContainText('E2EVM1');
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(vmRow(vmId).mecm_id, 'Cancel kept the MECM ID').toBe('E2E-MECM-1');

  await reset.click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForURL(/vms\.php/),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  const after = vmRow(vmId);
  expect(after.mecm_id, 'Confirm cleared the MECM ID').toBeNull();
  expect(after.mecm_sync_state, 'the sync is re-queued').toBe('pending');
});

// e2e-covers: vm_edit.php:reset_mecm_id
// e2e-covers-cancel: vm_edit.php:reset_mecm_id
test('vm_edit MECM reset: Cancel keeps the ID, Confirm clears it and returns to the editor', async ({ page }) => {
  const seed = seedMission(PREFIX + 'reset-edit', 1);
  const vmId = seed.vmIds[0];

  await page.goto(`vm_edit.php?mission_id=${seed.missionId}&vm_id=${vmId}`);
  const dialog = page.locator('[data-confirm-dialog]');
  const reset = page.locator('form:has(input[name="action"][value="reset_mecm_id"]) button');

  await reset.click();
  await expect(dialog, 'the reset asks first').toBeVisible();
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(vmRow(vmId).mecm_id, 'Cancel kept the MECM ID').toBe('E2E-MECM-1');

  await reset.click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    // return_to points back at the editor, so the operator keeps their context.
    page.waitForURL(new RegExp(`vm_edit\\.php\\?mission_id=${seed.missionId}&vm_id=${vmId}`)),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  expect(vmRow(vmId).mecm_id, 'Confirm cleared the MECM ID').toBeNull();
});

// e2e-covers: vm_edit.php:transfer_mecm
// e2e-covers-cancel: vm_edit.php:transfer_mecm
test('vm_edit MECM transfer: the preview names the removal, Cancel changes nothing, Confirm queues', async ({ page }) => {
  const seed = seedMission(PREFIX + 'transfer', 1);
  const vmId = seed.vmIds[0];
  // updated starts at 0 so the confirm's effect is observable, and one OWNED
  // rule no longer covered by the assignments makes the removal preview real
  // (ADR-0034: the transfer stopped being purely additive there).
  runPhp(`
$db = db();
$vmId = ${Number(vmId)};
$stmt = $db->prepare('UPDATE deploy_vms SET updated = 0 WHERE id = ?');
$stmt->bind_param('i', $vmId);
$stmt->execute();
$stmt = $db->prepare("INSERT INTO deploy_vm_mecm_rules (vm_id, collection_id, collection_name, collection_type, origin) VALUES (?, 'E2E00099', 'E2E-Obsolete-Pkg', 'package', 'created')");
$stmt->bind_param('i', $vmId);
$stmt->execute();
echo 'SEEDED';
`);

  await page.goto(`vm_edit.php?mission_id=${seed.missionId}&vm_id=${vmId}`);
  // The preview names the own rule the next sync run will remove.
  await expect(page.getByText('E2E-Obsolete-Pkg'), 'the removal preview names the obsolete own rule').toBeVisible();

  const dialog = page.locator('[data-confirm-dialog]');
  const transfer = page.locator('form:has(input[name="action"][value="transfer_mecm"]) button');

  await transfer.click();
  await expect(dialog, 'the transfer asks first').toBeVisible();
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(vmRow(vmId).updated, 'Cancel queued nothing').toBe(0);

  await transfer.click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForURL(new RegExp(`vm_edit\\.php\\?mission_id=${seed.missionId}&vm_id=${vmId}`)),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  expect(vmRow(vmId).updated, 'Confirm queued the VM for the device-sync').toBe(1);
});

// e2e-covers: vms.php:delete
// e2e-covers-cancel: vms.php:delete
test('row delete: Cancel keeps the VM, Confirm removes it with its interfaces', async ({ page }) => {
  const seed = seedMission(PREFIX + 'del', 1);
  const vmId = seed.vmIds[0];

  await page.goto(`vms.php?mission_id=${seed.missionId}`);
  const dialog = page.locator('[data-confirm-dialog]');
  const del = rowFor(page, 'E2EVM1').locator('form:has(input[name="action"][value="delete"]) button');

  await del.click();
  await expect(dialog, 'the delete asks first').toBeVisible();
  await expect(page.locator('[data-confirm-msg]'), 'the dialog names the VM').toContainText('E2EVM1');
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(vmRow(vmId), 'Cancel left the VM in the DB').not.toBeNull();

  await del.click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForURL(/vms\.php/),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  expect(vmRow(vmId), 'Confirm deleted the VM').toBeNull();
  const interfaces = phpJson(`
$db = db();
$stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_interfaces WHERE vm_id = ?');
$id = ${Number(vmId)};
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode(['c' => (int) $stmt->get_result()->fetch_assoc()['c']]) . 'JSON';
`);
  expect(interfaces.c, 'the interfaces cascaded').toBe(0);
});

// e2e-covers: vms.php:bulk_reset_mecm_id
// e2e-covers-cancel: vms.php:bulk_reset_mecm_id
test('bulk MECM reset: Cancel changes nothing, Confirm re-queues the selection', async ({ page }) => {
  const seed = seedMission(PREFIX + 'bulkreset', 2);

  await page.goto(`vms.php?mission_id=${seed.missionId}`);
  const dialog = page.locator('[data-confirm-dialog]');
  for (const vmId of seed.vmIds) {
    await page.locator(`input[name="vm_ids[]"][value="${vmId}"]`).check();
  }
  const bulkReset = page.locator('button[name="action"][value="bulk_reset_mecm_id"]');
  await expect(bulkReset, 'a selection enables the bulk button').toBeEnabled();

  await bulkReset.click();
  await expect(dialog, 'the bulk reset asks first').toBeVisible();
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(vmRow(seed.vmIds[0]).mecm_sync_state, 'Cancel changed nothing').toBe('registered');
  expect(vmRow(seed.vmIds[1]).mecm_sync_state, 'Cancel changed nothing').toBe('registered');

  await bulkReset.click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForURL(/vms\.php/),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  for (const vmId of seed.vmIds) {
    const after = vmRow(vmId);
    expect(after.mecm_id, 'the MECM ID is cleared').toBeNull();
    expect(after.mecm_sync_state, 'the sync is re-queued').toBe('pending');
  }
});

// e2e-covers: vms.php:bulk_delete
// e2e-covers-cancel: vms.php:bulk_delete
test('bulk delete: Cancel changes nothing, Confirm removes the selection', async ({ page }) => {
  const seed = seedMission(PREFIX + 'bulkdel', 2);

  await page.goto(`vms.php?mission_id=${seed.missionId}`);
  const dialog = page.locator('[data-confirm-dialog]');
  // The other bulk test ticks rows one by one; this one proves the select-all
  // header checkbox and the live counter.
  await page.locator('input[data-bulk-all]').check();
  await expect(page.locator('[data-bulk-count]'), 'the counter follows the selection').toHaveText('2');
  const bulkDelete = page.locator('button[name="action"][value="bulk_delete"]');
  await expect(bulkDelete, 'a selection enables the bulk button').toBeEnabled();

  await bulkDelete.click();
  await expect(dialog, 'the bulk delete asks first').toBeVisible();
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(vmRow(seed.vmIds[0]), 'Cancel left the VMs in the DB').not.toBeNull();
  expect(vmRow(seed.vmIds[1]), 'Cancel left the VMs in the DB').not.toBeNull();

  await bulkDelete.click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForURL(/vms\.php/),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  expect(vmRow(seed.vmIds[0]), 'Confirm deleted the selection').toBeNull();
  expect(vmRow(seed.vmIds[1]), 'Confirm deleted the selection').toBeNull();
});
