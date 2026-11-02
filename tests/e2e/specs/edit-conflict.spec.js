// QL-01/QL-03 B: two sessions edit the same mission and the same VM. The
// second save is refused and shows a comparison of exactly the differing
// fields; "continue with my input" saves nothing and adopts the shown version;
// a change made after that refuses the next save again with a new comparison;
// only an ordinary save on the current version writes.

const { test, expect } = require('../lib/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2econflict';

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
    'domain' => 'conflict.invalid',
], false, null);
$vmName = '${MARK}-vm';
$hostname = 'E2ECONFLICT';
$stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os, vm_cpu, vm_ram, vm_notes) VALUES (?, ?, ?, ?, 2, 4096, '')");
$stmt->bind_param('isss', $missionId, $vmName, $hostname, $os);
$stmt->execute();
$vmId = (int) $db->insert_id;
$stmt = $db->prepare("INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac, mode) VALUES (?, '', '', '', ?, NULL, 'dhcp')");
$stmt->bind_param('is', $vmId, $vlan);
$stmt->execute();
$diskName = 'System';
$diskSize = 50;
$diskType = 'thin';
$stmt = $db->prepare('INSERT INTO deploy_disks (vm_id, disk_name, disk_size, disk_type) VALUES (?, ?, ?, ?)');
$stmt->bind_param('isis', $vmId, $diskName, $diskSize, $diskType);
$stmt->execute();
echo 'JSON' . json_encode(['mission' => $missionId, 'vm' => $vmId]) . 'JSON';
`, ['lib/repo/missions.php']);
}

function storedMission(id) {
  return phpJson(`
$row = repo_fetch_one(db(), 'SELECT mission_notes, domain, autostart_start_delay, edit_version FROM deploy_missions WHERE id = ?', 'i', [${id}]);
echo 'JSON' . json_encode($row) . 'JSON';
`);
}

function storedVm(id) {
  return phpJson(`
$row = repo_fetch_one(db(), 'SELECT vm_notes, vm_cpu, edit_version FROM deploy_vms WHERE id = ?', 'i', [${id}]);
$row['disks'] = (int) repo_scalar(db(), 'SELECT COUNT(*) FROM deploy_disks WHERE vm_id = ?', 'i', [${id}]);
echo 'JSON' . json_encode($row) . 'JSON';
`);
}

/** The comparison panel's rows as [label, mine, current]. */
async function comparisonRows(page) {
  return page.locator('[data-edit-conflict] [data-edit-conflict-row]').evaluateAll((rows) =>
    rows.map((row) => Array.from(row.querySelectorAll('td'), (cell) => cell.textContent.trim())));
}

async function submitAndWait(page, button, path) {
  await Promise.all([
    page.waitForResponse((response) => new URL(response.url()).pathname.endsWith(path) && response.request().method() === 'POST'),
    button.click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
}

// NL1: the busy handler in core.js must mark only the button that submits. The
// listener on window runs after the handler on document and records which
// buttons carry the busy state when the submit leaves the page.
async function recordBusyOnSubmit(page) {
  await page.evaluate(() => {
    sessionStorage.removeItem('nl1-busy');
    window.addEventListener('submit', (event) => {
      if (event.defaultPrevented) { return; }
      const busy = Array.from(document.querySelectorAll('button.is-busy'), (button) => button.textContent.trim());
      sessionStorage.setItem('nl1-busy', JSON.stringify(busy));
    });
  });
}

async function recordedBusy(page) {
  return JSON.parse(await page.evaluate(() => sessionStorage.getItem('nl1-busy') || 'null'));
}

test.beforeEach(() => cleanup());
test.afterAll(() => cleanup());

test('mission: comparison, continue without writing, a new conflict, then the deliberate save', async ({ page, browser }, testInfo) => {
  const ids = seed();
  const other = await browser.newContext({ storageState: ROLES.admin.storageState, baseURL: testInfo.project.use.baseURL });
  const second = await other.newPage();
  const url = `mission_details.php?id=${ids.mission}`;
  const save = (p) => p.locator('#mission-settings-form button[type="submit"]');

  await page.goto(url);
  await second.goto(url);
  await second.locator('input[name="domain"]').fill('other.invalid');
  await submitAndWait(second, save(second), '/mission_details.php');
  await expect(second.locator('.alert-success')).toBeVisible();

  await page.locator('textarea[name="mission_notes"]').fill('first draft');
  await submitAndWait(page, save(page), '/mission_details.php');
  await expect(page.locator('[data-edit-conflict="conflict"]')).toBeVisible();
  expect(await comparisonRows(page)).toEqual([
    [expect.stringMatching(/^(Notes|Notizen)$/), 'first draft', expect.stringMatching(/^(empty|leer)$/)],
    [expect.stringMatching(/^(Domain|Domäne)$/), 'conflict.invalid', 'other.invalid'],
  ]);
  await expect(page.locator('textarea[name="mission_notes"]'), 'the input survives the refusal').toHaveValue('first draft');
  const afterRefusal = storedMission(ids.mission);
  expect(afterRefusal.mission_notes).toBe('');

  // Continue saves nothing and adopts the shown version.
  await submitAndWait(page, page.locator('[data-edit-conflict] button[name="edit_conflict"]'), '/mission_details.php');
  await expect(page.locator('[data-edit-conflict="continued"]')).toBeVisible();
  await expect(page.locator('#mission-settings-form input[name="edit_version"]')).toHaveValue(String(afterRefusal.edit_version));
  await expect(page.locator('textarea[name="mission_notes"]')).toHaveValue('first draft');
  expect(storedMission(ids.mission)).toEqual(afterRefusal);

  // A change after the comparison: the next save fails again with a new comparison.
  await second.goto(url);
  await second.locator('input[name="autostart_start_delay"]').fill('45');
  await submitAndWait(second, save(second), '/mission_details.php');
  await submitAndWait(page, save(page), '/mission_details.php');
  await expect(page.locator('[data-edit-conflict="conflict"]')).toBeVisible();
  const rows = await comparisonRows(page);
  expect(rows.map((row) => row[0])).toContainEqual(expect.stringMatching(/^(Start delay \(seconds\)|Startverzögerung \(Sekunden\))$/));
  expect(storedMission(ids.mission).mission_notes).toBe('');

  // Continue, then the ordinary save writes the operator's input as shown.
  // Continue saves nothing and shows no busy save button; the save does (NL1).
  await recordBusyOnSubmit(page);
  await submitAndWait(page, page.locator('[data-edit-conflict] button[name="edit_conflict"]'), '/mission_details.php');
  expect(await recordedBusy(page), 'continue marks no button busy').toEqual([]);
  const busyLabel = await save(page).getAttribute('data-busy-label');
  await recordBusyOnSubmit(page);
  await submitAndWait(page, save(page), '/mission_details.php');
  expect(await recordedBusy(page), 'the save button shows its busy label').toEqual([busyLabel]);
  await expect(page.locator('[data-edit-conflict]')).toHaveCount(0);
  await expect(page.locator('.alert-success')).toBeVisible();
  const saved = storedMission(ids.mission);
  expect(saved.mission_notes).toBe('first draft');
  expect(saved.domain, 'the column "Your input" is what the save stored').toBe('conflict.invalid');
  await other.close();
});

test('VM: comparison with the child count, continue without writing, then the deliberate save', async ({ page, browser }, testInfo) => {
  const ids = seed();
  const other = await browser.newContext({ storageState: ROLES.admin.storageState, baseURL: testInfo.project.use.baseURL });
  const second = await other.newPage();
  const url = `vm_edit.php?mission_id=${ids.mission}&vm_id=${ids.vm}`;
  const save = (p) => p.locator('#vm-editor-form [data-mecm-save-single]');

  await page.goto(url);
  await second.goto(url);
  await second.locator('input[name="vm_cpu"]').fill('4');
  await second.locator('[data-add-row="disks"]').click();
  await second.locator('[data-repeat-target="disks"] [data-repeat-row]').last().locator('input[name$="[disk_size]"]').fill('10');
  await submitAndWait(second, save(second), '/vm_edit.php');
  expect(storedVm(ids.vm).disks).toBe(2);

  await page.locator('textarea[name="vm_notes"]').fill('first draft');
  await submitAndWait(page, save(page), '/vm_edit.php');
  await expect(page.locator('[data-edit-conflict="conflict"]')).toBeVisible();
  expect(await comparisonRows(page)).toEqual([
    ['CPU', '2', '4'],
    [expect.stringMatching(/^(Notes|Notizen)$/), 'first draft', expect.stringMatching(/^(empty|leer)$/)],
    [expect.stringMatching(/^(Disks|Datenträger)$/), expect.stringMatching(/: 1$/), expect.stringMatching(/: 2$/)],
  ]);
  const afterRefusal = storedVm(ids.vm);
  expect(afterRefusal.vm_notes).toBe('');

  await recordBusyOnSubmit(page);
  await submitAndWait(page, page.locator('[data-edit-conflict] button[name="edit_conflict"]'), '/vm_edit.php');
  expect(await recordedBusy(page), 'continue marks no button busy (NL1)').toEqual([]);
  await expect(page.locator('[data-edit-conflict="continued"]')).toBeVisible();
  await expect(page.locator('#vm-editor-form input[name="edit_version"]')).toHaveValue(String(afterRefusal.edit_version));
  expect(storedVm(ids.vm)).toEqual(afterRefusal);

  const busyLabel = await save(page).getAttribute('data-busy-label');
  await recordBusyOnSubmit(page);
  await submitAndWait(page, save(page), '/vm_edit.php');
  expect(await recordedBusy(page), 'the save button shows its busy label (NL1)').toEqual([busyLabel]);
  await expect(page).not.toHaveURL(/vm_edit\.php/);
  const saved = storedVm(ids.vm);
  expect(saved.vm_notes).toBe('first draft');
  expect(String(saved.vm_cpu), 'the column "Your input" is what the save stored').toBe('2');
  expect(saved.disks).toBe(1);
  await other.close();
});
