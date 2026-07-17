// TESTPLAN 3.5 / E6: the mission transfer and template flows. Export must hand
// the browser a real JSON download (header contract, parseable payload); the
// import goes upload -> preview -> confirm with DB proof, plus the rejection
// path for a non-JSON file. Template clone and save-as-template prove that the
// copy exists and carries the VMs.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const PREFIX = 'e2etransfer-';

// VM names are unique across missions (the import/clone guards enforce it),
// so every seed carries its own, or test 2 collides with what test 1 imported.
function seedMissionWithVm(name, vmName) {
  return phpJson(`
$db = db();
$id = repo_create_mission($db, ['mission_name' => '${name}', 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local'], false, null);
$stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os) VALUES (?, '${vmName}', '${vmName}', 'Win11')");
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode(['id' => $id]) . 'JSON';
`, ['lib/repo/missions.php']);
}

function seedTemplate(baseName, vmName) {
  return phpJson(`
$db = db();
$name = VIRTUSPHERE_TEMPLATE_PREFIX . '${baseName}';
$id = repo_create_mission($db, ['mission_name' => $name, 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local'], true, null);
$stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os) VALUES (?, '${vmName}', '${vmName}', 'Win11')");
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode(['id' => $id, 'name' => $name]) . 'JSON';
`, ['lib/repo/missions.php']);
}

function missionByName(name) {
  return phpJson(`
$db = db();
$stmt = $db->prepare('SELECT m.id, (SELECT COUNT(*) FROM deploy_vms v WHERE v.mission_id = m.id) AS vm_count FROM deploy_missions m WHERE m.mission_name = ? ORDER BY m.id DESC LIMIT 1');
$n = '${name}';
$stmt->bind_param('s', $n);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc() ?: null) . 'JSON';
`);
}

function cleanup() {
  runPhp(`
$db = db();
foreach (['${PREFIX}%', VIRTUSPHERE_TEMPLATE_PREFIX . '${PREFIX}%'] as $like) {
    $stmt = $db->prepare('DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)');
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $stmt = $db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
    $stmt->bind_param('s', $like);
    $stmt->execute();
}
echo 'CLEANED';
`);
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

// e2e-covers: mission_details.php:export
// e2e-covers: missions.php:import_preview
// e2e-covers: missions.php:import_confirm
test('export -> import preview -> confirm round-trips a mission with its VMs', async ({ page }) => {
  const sourceName = PREFIX + 'src';
  const importedName = PREFIX + 'imported';
  const seed = seedMissionWithVm(sourceName, 'E2ETVMSRC');

  // Export: the browser receives a real JSON download.
  await page.goto(`mission_details.php?id=${seed.id}`);
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.locator('form:has(input[name="action"][value="export"]) button').click(),
  ]);
  expect(download.suggestedFilename(), 'the download is named as JSON').toMatch(/\.json$/);
  const exportPath = await download.path();
  const payload = JSON.parse(require('node:fs').readFileSync(exportPath, 'utf8'));
  expect(payload.mission.mission_name, 'the export names the mission').toBe(sourceName);
  expect(payload.format_version, 'the export carries its format version').toBeGreaterThan(0);

  // Rejection path first: a non-JSON file is refused with a sentence.
  await page.goto('missions.php?type=missions');
  const uploadForm = page.locator('form:has(input[name="action"][value="import_preview"])');
  await uploadForm.locator('input[type="file"]').setInputFiles({
    name: 'not-json.txt',
    mimeType: 'text/plain',
    buffer: Buffer.from('this is not an export'),
  });
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('missions.php') && r.request().method() === 'POST'),
    uploadForm.locator('button[type="submit"]').click(),
  ]);
  await expect(page.locator('.alert-error').first(), 'the broken file is refused, localized').toBeVisible();
  expect(missionByName(importedName), 'nothing was imported').toBeNull();

  // The preview blocks structurally while the source mission still holds the
  // same VM names (they are unique across missions), so the source goes away
  // first: the real-world flow is export here, import on another instance.
  runPhp(`
$db = db();
$id = ${Number(seed.id)};
$stmt = $db->prepare('DELETE FROM deploy_vms WHERE mission_id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt = $db->prepare('DELETE FROM deploy_missions WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'REMOVED';
`);

  // Preview: the real export is accepted and the confirm step appears.
  const uploadForm2 = page.locator('form:has(input[name="action"][value="import_preview"])');
  await uploadForm2.locator('input[type="file"]').setInputFiles(exportPath);
  await Promise.all([
    page.waitForURL(/missions\.php\?type=missions&import=/),
    uploadForm2.locator('button[type="submit"]').click(),
  ]);
  const confirmForm = page.locator('form:has(input[name="action"][value="import_confirm"])');
  await expect(confirmForm, 'the preview offers the confirm step').toBeVisible();

  // Confirm under a new name: the mission and its VM exist in the DB.
  await confirmForm.locator('input[name="mission_name"]').fill(importedName);
  await Promise.all([
    page.waitForURL(/mission_details\.php\?id=/),
    confirmForm.locator('button[type="submit"]').click(),
  ]);
  const imported = missionByName(importedName);
  expect(imported, 'the imported mission exists').not.toBeNull();
  expect(Number(imported.vm_count), 'the VM came along').toBe(1);
});

// e2e-covers: mission_details.php:clone_template
test('clone_template: a template copy becomes a new mission with the VMs', async ({ page }) => {
  const template = seedTemplate(PREFIX + 'tpl', 'E2ETVMTPL');
  const cloneName = PREFIX + 'clone';

  await page.goto(`mission_details.php?id=${template.id}`);
  const form = page.locator('form:has(input[name="action"][value="clone_template"])');
  await form.locator('input[name="target_mission_name"]').fill(cloneName);
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('mission_details.php') && r.request().method() === 'POST'),
    form.locator('button[type="submit"]').click(),
  ]);

  const clone = missionByName(cloneName);
  expect(clone, 'the clone exists as a mission').not.toBeNull();
  expect(Number(clone.vm_count), 'the template VM was copied').toBe(1);
});

// e2e-covers: mission_details.php:save_as_template
test('save_as_template: a mission becomes a prefixed template with the VMs', async ({ page }) => {
  const missionName = PREFIX + 'to-tpl';
  const seed = seedMissionWithVm(missionName, 'E2ETVMSAVE');
  const templateName = phpJson(`echo 'JSON' . json_encode(['v' => VIRTUSPHERE_TEMPLATE_PREFIX . '${missionName}']) . 'JSON';`).v;

  await page.goto(`mission_details.php?id=${seed.id}`);
  const form = page.locator('form:has(input[name="action"][value="save_as_template"])');
  // The field pre-fills with prefix + mission name; keep it.
  await expect(form.locator('input[name="target_template_name"]')).toHaveValue(templateName);
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('mission_details.php') && r.request().method() === 'POST'),
    form.locator('button[type="submit"]').click(),
  ]);

  const template = missionByName(templateName);
  expect(template, 'the template exists').not.toBeNull();
  expect(Number(template.vm_count), 'the VM was copied into the template').toBe(1);
});
