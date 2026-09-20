const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2eeffective';

function cleanup() {
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name = '${MARK}-mission')");
$db->query("DELETE FROM deploy_missions WHERE mission_name = '${MARK}-mission'");
$db->query("DELETE FROM deploy_vlan WHERE vlan_name = '${MARK}-vlan'");
$db->query("DELETE FROM deploy_os WHERE os_name = '${MARK}-os'");
`);
}

function seed() {
  return phpJson(`
$db = db();
$os = '${MARK}-os';
$stmt = $db->prepare("INSERT INTO deploy_os (os_name, os_status, retired_at) VALUES (?, 'Active', NULL)");
$stmt->bind_param('s', $os);
$stmt->execute();
$vlan = '${MARK}-vlan';
$stmt = $db->prepare('INSERT INTO deploy_vlan (vlan_name, retired_at) VALUES (?, NULL)');
$stmt->bind_param('s', $vlan);
$stmt->execute();
$missionId = repo_create_mission($db, [
    'mission_name' => '${MARK}-mission',
    'hypervisor_datastorage' => 'mission-ds',
    'hypervisor_datacenter' => 'mission-dc',
    'domain' => 'effective.invalid',
    'autostart_enabled' => '1',
    'autostart_start_delay' => '90',
    'autostart_stop_delay' => '120',
], false, null);
$vmName = '${MARK}-vm';
$hostname = 'E2EEFFECTIVE';
$stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os, vm_cpu, vm_ram, vm_datastore, vm_datacenter, autostart_enabled, autostart_start_delay, autostart_stop_delay) VALUES (?, ?, ?, ?, 2, 4096, 'vm-ds', 'vm-dc', 1, -1, 0)");
$stmt->bind_param('isss', $missionId, $vmName, $hostname, $os);
$stmt->execute();
$vmId = (int) $db->insert_id;
$stmt = $db->prepare("INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac, mode) VALUES (?, '', '', '', ?, NULL, 'dhcp')");
$stmt->bind_param('is', $vmId, $vlan);
$stmt->execute();
$stmt = $db->prepare("INSERT INTO deploy_disks (vm_id, disk_name, disk_size, disk_type) VALUES (?, 'System', 50, 'eagerzeroedthick')");
$stmt->bind_param('i', $vmId);
$stmt->execute();
echo 'JSON' . json_encode(['mission' => $missionId, 'vm' => $vmId]) . 'JSON';
`, ['lib/repo/missions.php']);
}

function row(page, key) {
  return page.locator(`[data-effective-row="${key}"]`);
}

test.beforeEach(() => cleanup());
test.afterAll(() => cleanup());

test('live VM overrides, mission inheritance and zero delay stay distinct', async ({ page }) => {
  const ids = seed();
  await page.goto(`vm_edit.php?mission_id=${ids.mission}&vm_id=${ids.vm}`);

  await expect(row(page, 'datastore')).toContainText('vm-ds · VM overrides mission value mission-ds');
  await expect(row(page, 'datacenter')).toContainText('vm-dc · VM overrides mission value mission-dc');
  await expect(row(page, 'autostart')).toContainText('Yes · VM setting; mission permits autostart');
  await expect(row(page, 'start_delay')).toContainText('90 seconds · inherited from mission');
  await expect(row(page, 'stop_delay')).toContainText('0 seconds · VM overrides mission value 120 seconds');

  const datastore = page.locator('[name="vm_datastore"]');
  await row(page, 'datastore').locator('[data-effective-reset]').click();
  await expect(datastore).toHaveValue('');
  await expect(datastore).toBeFocused();
  await expect(row(page, 'datastore')).toContainText('mission-ds · inherited from mission');
  await expect(page.locator('[data-unsaved-status]')).toContainText('Unsaved changes');

  const stopDelay = page.locator('[name="autostart_stop_delay"]');
  await row(page, 'stop_delay').locator('[data-effective-reset]').click();
  await expect(stopDelay).toHaveValue('');
  await expect(row(page, 'stop_delay')).toContainText('120 seconds · inherited from mission');
  await stopDelay.fill('0');
  await expect(row(page, 'stop_delay')).toContainText('0 seconds · VM overrides mission value 120 seconds');

  await page.locator('input[type="checkbox"][name="autostart_enabled"]').uncheck();
  await expect(row(page, 'autostart')).toContainText('No · VM setting; mission permits autostart');
});

test('missing location and a disabled mission gate never invent effective values', async ({ page }) => {
  const ids = seed();
  runPhp(`
$stmt = db()->prepare("UPDATE deploy_missions SET hypervisor_datastorage = '', hypervisor_datacenter = '', autostart_enabled = 0 WHERE id = ?");
$id = ${ids.mission};
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt = db()->prepare("UPDATE deploy_vms SET vm_datastore = '', vm_datacenter = '' WHERE id = ?");
$id = ${ids.vm};
$stmt->bind_param('i', $id);
$stmt->execute();
`);
  await page.goto(`vm_edit.php?mission_id=${ids.mission}&vm_id=${ids.vm}`);

  await expect(row(page, 'datastore')).toContainText('No effective value · neither VM nor mission provides one');
  await expect(row(page, 'datacenter')).toContainText('Not determined yet · resolved from the selected ESXi host at deploy time');
  await expect(row(page, 'autostart')).toContainText('No · mission disables autostart; preserved VM setting: Yes');
  await expect(row(page, 'datastore').locator('[data-effective-reset]:visible')).toHaveCount(0);
  await expect(row(page, 'datacenter').locator('[data-effective-reset]:visible')).toHaveCount(0);
  await expect(row(page, 'stop_delay').locator('[data-effective-reset]')).toBeVisible();
});

test('No-JS shows the server truth without an inert reset control', async ({ browser }, testInfo) => {
  const ids = seed();
  const noJs = await browser.newContext({
    baseURL: testInfo.project.use.baseURL,
    storageState: ROLES.admin.storageState,
    javaScriptEnabled: false,
  });
  try {
    const noJsPage = await noJs.newPage();
    await noJsPage.goto(`vm_edit.php?mission_id=${ids.mission}&vm_id=${ids.vm}`);
    await expect(row(noJsPage, 'datastore')).toContainText('vm-ds · VM overrides mission value mission-ds');
    await expect(noJsPage.locator('[data-effective-reset]:visible')).toHaveCount(0);
  } finally {
    await noJs.close();
  }
});
