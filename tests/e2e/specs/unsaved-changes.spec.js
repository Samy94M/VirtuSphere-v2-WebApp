const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2eunsaved';

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
    'mission_notes' => '',
    'hypervisor_datastorage' => 'ds1',
    'hypervisor_datacenter' => 'DC1',
    'domain' => 'unsaved.invalid',
], false, null);
$vmName = '${MARK}-vm';
$hostname = 'E2EUNSAVED';
$stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os, vm_cpu, vm_ram, vm_notes) VALUES (?, ?, ?, ?, 2, 4096, '')");
$stmt->bind_param('isss', $missionId, $vmName, $hostname, $os);
$stmt->execute();
$vmId = (int) $db->insert_id;
$stmt = $db->prepare("INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac, mode) VALUES (?, '', '', '', ?, NULL, 'dhcp')");
$stmt->bind_param('is', $vmId, $vlan);
$stmt->execute();
$diskName = 'System';
$diskSize = 50;
$diskType = 'eagerzeroedthick';
$stmt = $db->prepare('INSERT INTO deploy_disks (vm_id, disk_name, disk_size, disk_type) VALUES (?, ?, ?, ?)');
$stmt->bind_param('isis', $vmId, $diskName, $diskSize, $diskType);
$stmt->execute();
echo 'JSON' . json_encode(['mission' => $missionId, 'vm' => $vmId]) . 'JSON';
`, ['lib/repo/missions.php']);
}

test.beforeEach(() => cleanup());
test.afterAll(() => cleanup());

function unsavedStatus(page) {
  return page.locator('[data-unsaved-status]');
}

test('mission editor reports exact changes and reuses the shared leave dialog', async ({ page }) => {
  const ids = seed();
  await page.goto(`mission_details.php?id=${ids.mission}`);

  const notes = page.locator('textarea[name="mission_notes"]');
  const status = unsavedStatus(page);
  await expect(status).toContainText(/No unsaved changes|Keine ungespeicherten Änderungen/i);

  await notes.fill('operator draft');
  await expect(status).toContainText(/Unsaved changes|Ungespeicherte Änderungen/i);
  await notes.fill('');
  await expect(status).toContainText(/No unsaved changes|Keine ungespeicherten Änderungen/i);

  await notes.fill('operator draft');
  const back = page.getByRole('link', { name: /Back|Zurück/i }).first();
  await back.click();
  const dialog = page.locator('[data-confirm-dialog]');
  await expect(dialog).toBeVisible();
  await expect(page.locator('[data-confirm-msg]')).toContainText(/unsaved|ungespeichert/i);
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  await expect(notes).toHaveValue('operator draft');
  await expect(back).toBeFocused();

  await back.click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForURL(/missions\.php/),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
});

test('VM dynamic rows and an optimistic-lock error retain truthful dirty state', async ({ page }) => {
  const ids = seed();
  await page.goto(`vm_edit.php?mission_id=${ids.mission}&vm_id=${ids.vm}`);

  const status = unsavedStatus(page);
  await expect(status).toContainText(/No unsaved changes|Keine ungespeicherten Änderungen/i);
  const rows = page.locator('[data-repeat-target="interfaces"] [data-repeat-row]');
  const initialRows = await rows.count();
  await page.locator('[data-add-row="interfaces"]').click();
  await expect(rows).toHaveCount(initialRows + 1);
  await expect(status).toContainText(/Unsaved changes|Ungespeicherte Änderungen/i);
  await rows.last().locator('[data-remove-row]').click();
  await expect(rows).toHaveCount(initialRows);
  await expect(status).toContainText(/No unsaved changes|Keine ungespeicherten Änderungen/i);

  // Move the confirmed server revision after this page was opened. The rejected
  // POST must keep the operator's values and may become clean only after the
  // module has compared them with a fresh GET of the confirmed server state.
  runPhp(`
$stmt = db()->prepare('UPDATE deploy_vms SET edit_version = edit_version + 1 WHERE id = ?');
$id = ${ids.vm};
$stmt->bind_param('i', $id);
$stmt->execute();
`);
  const notes = page.locator('textarea[name="vm_notes"]');
  await notes.fill('conflicting draft');
  await Promise.all([
    page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/vm_edit.php') && response.request().method() === 'POST'),
    page.getByRole('button', { name: /Save VM|VM speichern/i }).click(),
  ]);
  await expect(page).toHaveURL(/vm_edit\.php/);
  await expect(notes).toHaveValue('conflicting draft');
  await expect(status).toContainText(/Unsaved changes|Ungespeicherte Änderungen/i);
  await notes.fill('');
  await expect(status).toContainText(/No unsaved changes|Keine ungespeicherten Änderungen/i);
});

test('without JavaScript the status makes no claim and server links still work', async ({ browser }, testInfo) => {
  const ids = seed();
  const context = await browser.newContext({
    baseURL: testInfo.project.use.baseURL,
    storageState: ROLES.admin.storageState,
    javaScriptEnabled: false,
  });
  try {
    const page = await context.newPage();
    await page.goto(`mission_details.php?id=${ids.mission}`);
    await expect(unsavedStatus(page)).toBeHidden();
    await page.locator('textarea[name="mission_notes"]').fill('no-js draft');
    await page.getByRole('link', { name: /Back|Zurück/i }).first().click();
    await expect(page).toHaveURL(/missions\.php/);
  } finally {
    await context.close();
  }
});
