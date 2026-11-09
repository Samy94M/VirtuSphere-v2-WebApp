// VE1: the VM editor shows mission inheritance at the fields themselves (the
// former "effective values" box repeated them and is gone; only a datacenter
// the mission leaves open gets a field hint), and adding a VM renders the
// same page as editing one, minus what only a saved VM has.

const { test, expect } = require('../lib/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');
const { ERROR_PAGE_SELECTOR } = require('../lib/error-page');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2einherit';
const HOST_HINT = 'The mission sets no datacenter.';

function cleanup() {
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name = '${MARK}-mission')");
$db->query("DELETE FROM deploy_missions WHERE mission_name = '${MARK}-mission'");
$db->query("DELETE FROM deploy_vlan WHERE vlan_name = '${MARK}-vlan'");
$db->query("DELETE FROM deploy_os WHERE os_name = '${MARK}-os'");
`);
}

function seed(missionDatacenter) {
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
    'hypervisor_datacenter' => '${missionDatacenter}',
    'domain' => 'inherit.invalid',
    'autostart_enabled' => '1',
    'autostart_start_delay' => '90',
    'autostart_stop_delay' => '120',
], false, null);
$vmName = '${MARK}-vm';
$hostname = 'E2EINHERIT';
$stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os, vm_cpu, vm_ram, vm_datastore, vm_datacenter, autostart_enabled, autostart_start_delay, autostart_stop_delay) VALUES (?, ?, ?, ?, 2, 4096, '', '', 1, -1, 0)");
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

test.beforeEach(() => cleanup());
test.afterAll(() => cleanup());

test('inherited values sit at their fields, without a separate summary box', async ({ page }) => {
  const ids = seed('mission-dc');
  await page.goto(`vm_edit.php?mission_id=${ids.mission}&vm_id=${ids.vm}`);

  await expect(page.locator('[data-effective-values]')).toHaveCount(0);
  await expect(page.locator('[name="autostart_start_delay"]')).toHaveAttribute('placeholder', 'Mission default (90)');
  // Explicit zero stays an override and is not shown as inherited.
  await expect(page.locator('[name="autostart_stop_delay"]')).toHaveValue('0');
  await expect(page.getByText(HOST_HINT)).toHaveCount(0);

  // Notes is a cell of the form grid, so its distance to the row above is the
  // grid's row gap, as in the mission editor.
  await expect(page.locator('.vm-form-grid > label.form-grid-span-2:has(textarea[name="vm_notes"])')).toHaveCount(1);
});

test('adding a VM renders to the end of the page, as wide as editing one', async ({ page }) => {
  const ids = seed('mission-dc');
  await page.setViewportSize({ width: 1280, height: 900 });

  await page.goto(`vm_edit.php?mission_id=${ids.mission}&vm_id=${ids.vm}`);
  const editWidth = await page.locator('main').evaluate((main) => main.getBoundingClientRect().width);
  await expect(page.locator('#vm-timeline-heading')).toHaveCount(1);

  // Before VE1 the timeline's chronicle link threw for an unsaved VM: the page
  // ended in the error renderer, whose inline style also narrowed <main>.
  await page.goto(`vm_edit.php?mission_id=${ids.mission}`);
  await expect(page.locator(ERROR_PAGE_SELECTOR)).toHaveCount(0);
  await expect(page.locator('dialog.modal-confirm')).toHaveCount(1);
  await expect(page.locator('#vm-timeline-heading')).toHaveCount(0);
  // The confirm dialog (layout_modals.php) closes every page with its own
  // hidden form; the VM form's submit is the last one outside that dialog.
  await expect(page.locator('main form:not([method="dialog"]) button[type="submit"]').last()).toBeVisible();
  const addWidth = await page.locator('main').evaluate((main) => main.getBoundingClientRect().width);
  expect(addWidth).toBe(editWidth);
});

test('without JavaScript, adding a VM renders completely with the server-side field hint', async ({ browser }, testInfo) => {
  const ids = seed('');
  const noJs = await browser.newContext({
    baseURL: testInfo.project.use.baseURL,
    storageState: ROLES.admin.storageState,
    javaScriptEnabled: false,
  });
  try {
    const noJsPage = await noJs.newPage();
    await noJsPage.goto(`vm_edit.php?mission_id=${ids.mission}`);
    await expect(noJsPage.locator(ERROR_PAGE_SELECTOR)).toHaveCount(0);
    await expect(noJsPage.locator('dialog.modal-confirm')).toHaveCount(1);
    const shown = await noJsPage.locator('[name="vm_datacenter"]:not([type="hidden"])').count();
    await expect(noJsPage.getByText(HOST_HINT)).toHaveCount(shown > 0 ? 1 : 0);
  } finally {
    await noJs.close();
  }
});

test('a saved VM without disks or network cards still renders to the end', async ({ page }) => {
  const ids = seed('mission-dc');
  runPhp(`
$id = ${ids.vm};
foreach (['deploy_interfaces', 'deploy_disks'] as $table) {
    $stmt = db()->prepare("DELETE FROM $table WHERE vm_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
}
`);
  await page.goto(`vm_edit.php?mission_id=${ids.mission}&vm_id=${ids.vm}`);

  await expect(page.locator(ERROR_PAGE_SELECTOR)).toHaveCount(0);
  await expect(page.locator('dialog.modal-confirm')).toHaveCount(1);
  await expect(page.locator('#vm-timeline-heading')).toHaveCount(1);
});

test('a datacenter neither VM nor mission sets is explained at the field', async ({ page }) => {
  const ids = seed('');
  await page.goto(`vm_edit.php?mission_id=${ids.mission}&vm_id=${ids.vm}`);

  // A standalone host with exactly one confirmed datacenter hides the control;
  // then nothing can be chosen and no hint is due.
  const shown = await page.locator('[name="vm_datacenter"]:not([type="hidden"])').count();
  await expect(page.getByText(HOST_HINT)).toHaveCount(shown > 0 ? 1 : 0);
});
