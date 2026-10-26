const { test, expect } = require('../lib/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2ewctx';

function cleanup() {
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name IN ('${MARK}-mission', '_${MARK}-template'))");
$db->query("DELETE FROM deploy_missions WHERE mission_name IN ('${MARK}-mission', '_${MARK}-template')");
$db->query("DELETE FROM deploy_vlan WHERE vlan_name = '${MARK}-vlan'");
$db->query("DELETE FROM deploy_os WHERE os_name = '${MARK}-os'");
echo 'CLEANED';
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
$missions = [];
foreach (['${MARK}-mission' => false, '_${MARK}-template' => true] as $name => $template) {
    $missionId = repo_create_mission($db, [
        'mission_name' => $name,
        'hypervisor_datastorage' => 'ds1',
        'hypervisor_datacenter' => 'DC1',
        'domain' => 'work-context.invalid',
    ], false, null);
    $vmName = $template ? '${MARK}-t-vm' : '${MARK}-m-vm';
    $hostname = $template ? 'E2EWCTXT' : 'E2EWCTXM';
    $ram = $template ? 2048 : 4096;
    $stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os, vm_cpu, vm_ram) VALUES (?, ?, ?, ?, 2, ?)");
    $stmt->bind_param('isssi', $missionId, $vmName, $hostname, $os, $ram);
    $stmt->execute();
    $vmId = (int) $db->insert_id;
    $stmt = $db->prepare("INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac, mode) VALUES (?, '', '', '', ?, NULL, 'dhcp')");
    $stmt->bind_param('is', $vmId, $vlan);
    $stmt->execute();
    $missions[$template ? 'template' : 'mission'] = ['id' => $missionId, 'vm' => $vmId, 'name' => $name, 'vm_name' => $vmName];
}
echo 'JSON' . json_encode($missions) . 'JSON';
`, ['lib/repo/missions.php']);
}

test.beforeEach(() => cleanup());
test.afterAll(() => cleanup());

test('two tabs keep independent mission and VM list context through edit and save', async ({ page, context }) => {
  const ids = seed();
  const templatePage = await context.newPage();

  await page.goto('missions.php?type=missions&sort=vms&dir=desc');
  const missionRow = page.locator(`#mission-${ids.mission.id}`);
  await missionRow.getByRole('link', { name: 'Details' }).click();
  await expect(page).toHaveURL(/mission_details\.php/);
  expect(new URL(page.url()).searchParams.get('work_list_type')).toBeNull();
  expect(new URL(page.url()).searchParams.get('work_list_sort')).toBe('vms');
  expect(new URL(page.url()).searchParams.get('work_list_dir')).toBe('desc');

  await templatePage.goto('missions.php?type=templates&sort=name&dir=desc');
  const templateRow = templatePage.locator(`#mission-${ids.template.id}`);
  await templateRow.getByRole('link', { name: 'VMs' }).click();
  expect(new URL(templatePage.url()).searchParams.get('work_list_type')).toBe('templates');
  expect(new URL(templatePage.url()).searchParams.get('work_list_sort')).toBeNull();
  expect(new URL(templatePage.url()).searchParams.get('work_list_dir')).toBe('desc');

  await templatePage.getByRole('link', { name: 'RAM' }).click();
  expect(new URL(templatePage.url()).searchParams.get('sort')).toBe('ram');
  const vmRow = templatePage.locator(`#vm-${ids.template.vm}`);
  await vmRow.getByRole('link', { name: 'Edit' }).click();
  await expect(templatePage.getByText(`Part of mission: ${ids.template.name}`)).toBeVisible();
  expect(new URL(templatePage.url()).searchParams.get('work_vm_sort')).toBe('ram');
  expect(new URL(templatePage.url()).searchParams.get('work_list_type')).toBe('templates');

  await templatePage.locator('textarea[name="vm_notes"]').fill('context save');
  const invalidFields = await templatePage.locator('form.stack :invalid').evaluateAll((fields) => fields.map((field) => ({
    name: field.getAttribute('name'),
    value: field.value,
    validationMessage: field.validationMessage,
  })));
  expect(invalidFields).toEqual([]);
  await Promise.all([
    templatePage.waitForURL(new RegExp(`vms\\.php\\?[^#]*mission_id=${ids.template.id}[^#]*#vm-${ids.template.vm}$`)),
    templatePage.getByRole('button', { name: 'Save VM' }).click(),
  ]);
  expect(new URL(templatePage.url()).searchParams.get('sort')).toBe('ram');
  expect(new URL(templatePage.url()).searchParams.get('work_list_type')).toBe('templates');
  await expect(templatePage.locator(`#vm-${ids.template.vm}`)).toBeFocused();

  await page.getByRole('link', { name: 'Back' }).click();
  expect(new URL(page.url()).searchParams.get('type')).toBe('missions');
  expect(new URL(page.url()).searchParams.get('sort')).toBe('vms');
  expect(new URL(page.url()).searchParams.get('dir')).toBe('desc');
  expect(new URL(page.url()).hash).toBe(`#mission-${ids.mission.id}`);
  await expect(page.locator(`#mission-${ids.mission.id}`)).toBeFocused();

  expect(new URL(templatePage.url()).searchParams.get('work_list_type')).toBe('templates');
  await templatePage.close();
});

test('server-rendered return links work without JavaScript and direct entry stays safe', async ({ browser }, testInfo) => {
  const ids = seed();
  const noJs = await browser.newContext({
    baseURL: testInfo.project.use.baseURL,
    storageState: ROLES.admin.storageState,
    javaScriptEnabled: false,
  });
  try {
    const page = await noJs.newPage();
    await page.goto('missions.php?type=missions&sort=vms&dir=desc');
    await page.locator(`#mission-${ids.mission.id}`).getByRole('link', { name: 'Details' }).click();
    const back = page.getByRole('link', { name: 'Back' });
    const href = await back.getAttribute('href');
    expect(href).toBe(`missions.php?type=missions&sort=vms&dir=desc#mission-${ids.mission.id}`);
    await back.click();
    expect(new URL(page.url()).hash).toBe(`#mission-${ids.mission.id}`);

    await page.goto(`vm_edit.php?mission_id=${ids.mission.id}&vm_id=${ids.mission.vm}&return_to=https://example.invalid/escape`);
    const directBack = await page.getByRole('link', { name: 'Back to VMs' }).getAttribute('href');
    expect(directBack).toBe(`vms.php?mission_id=${ids.mission.id}#vm-${ids.mission.vm}`);
    expect(directBack).not.toContain('example.invalid');
  } finally {
    await noJs.close();
  }
});

test('a VM opened from the VM overview returns to the same list view after save and back', async ({ page }) => {
  const ids = seed();
  // The MECM-pending list holds template rows too; queuing the template VM puts it there.
  runPhp(`
$db = db();
$stmt = $db->prepare('UPDATE deploy_vms SET updated = 1 WHERE id = ?');
$vmId = ${ids.template.vm};
$stmt->bind_param('i', $vmId);
$stmt->execute();
echo 'QUEUED';
`);

  await page.goto('vm_overview.php?filter=mecm_pending&sort=name&dir=desc');
  await page.locator(`#vm-${ids.template.vm}`).getByRole('link', { name: 'Open VM' }).click();
  await expect(page).toHaveURL(/vm_edit\.php/);
  const editorUrl = new URL(page.url());
  expect(editorUrl.searchParams.get('work_ov_filter')).toBe('mecm_pending');
  expect(editorUrl.searchParams.get('work_ov_sort')).toBe('name');
  expect(editorUrl.searchParams.get('work_ov_dir')).toBe('desc');
  expect(editorUrl.searchParams.get('counted')).toBeNull();

  const back = page.getByRole('link', { name: 'Back to "MECM pending"' });
  expect(await back.getAttribute('href')).toBe(`vm_overview.php?filter=mecm_pending&sort=name&dir=desc#vm-${ids.template.vm}`);
  const details = await page.getByRole('link', { name: 'Mission details' }).first().getAttribute('href');
  expect(details).not.toContain('work_ov_');

  await page.locator('textarea[name="vm_notes"]').fill('overview save');
  await Promise.all([
    page.waitForURL(new RegExp(`vm_overview\\.php\\?filter=mecm_pending&sort=name&dir=desc#vm-${ids.template.vm}$`)),
    page.getByRole('button', { name: 'Save VM' }).click(),
  ]);
  await expect(page.locator(`#vm-${ids.template.vm}`)).toBeFocused();

  await page.goto(`vm_edit.php?mission_id=${ids.template.id}&vm_id=${ids.template.vm}&work_ov_filter=bogus`);
  expect(await page.getByRole('link', { name: 'Back to VMs' }).getAttribute('href'))
    .toBe(`vms.php?mission_id=${ids.template.id}&work_list_type=templates#vm-${ids.template.vm}`);
});

test('mission and template details opened from a VM overview row return to the same list view', async ({ page }) => {
  const ids = seed();
  // Fifty-one queued template VMs fill page 1 of the MECM-pending list (50 rows, name descending)
  // and push the first one, t-00, onto page 2. The mission VM is renamed so that it sorts first
  // in the all-VMs list, which leaves out template missions.
  const { target } = phpJson(`
$db = db();
$missionId = ${ids.template.id};
$missionVmId = ${ids.mission.vm};
$missionVmName = 'zzz-${MARK}-m-vm';
$stmt = $db->prepare('UPDATE deploy_vms SET vm_name = ? WHERE id = ?');
$stmt->bind_param('si', $missionVmName, $missionVmId);
$stmt->execute();
$os = '${MARK}-os';
$ram = 2048;
$targetId = 0;
for ($i = 0; $i <= 50; $i++) {
    $vmName = sprintf('zzz-${MARK}-t-%02d', $i);
    $hostname = sprintf('E2EWCTXT%02d', $i);
    $stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os, vm_cpu, vm_ram) VALUES (?, ?, ?, ?, 2, ?)");
    $stmt->bind_param('isssi', $missionId, $vmName, $hostname, $os, $ram);
    $stmt->execute();
    if ($i === 0) {
        $targetId = (int) $db->insert_id;
    }
}
$stmt = $db->prepare('UPDATE deploy_vms SET updated = 1 WHERE mission_id = ?');
$stmt->bind_param('i', $missionId);
$stmt->execute();
echo 'JSON' . json_encode(['target' => $targetId]) . 'JSON';
`);

  // Template details, reached from page 2 of the MECM-pending list.
  await page.goto('vm_overview.php?filter=mecm_pending&sort=name&dir=desc&page=2');
  const templateRow = page.locator(`#vm-${target}`);
  await expect(templateRow).toBeVisible();
  await templateRow.getByRole('link', { name: ids.template.name }).click();
  await expect(page).toHaveURL(/mission_details\.php/);
  const templateDetails = new URL(page.url());
  expect(templateDetails.searchParams.get('work_ov_filter')).toBe('mecm_pending');
  expect(templateDetails.searchParams.get('work_ov_sort')).toBe('name');
  expect(templateDetails.searchParams.get('work_ov_dir')).toBe('desc');
  expect(templateDetails.searchParams.get('work_ov_page')).toBe('2');
  expect(templateDetails.searchParams.get('work_ov_vm')).toBe(String(target));
  expect(templateDetails.searchParams.get('counted')).toBeNull();

  const templateBack = page.getByRole('link', { name: 'Back to "MECM pending"' });
  expect(await templateBack.getAttribute('href'))
    .toBe(`vm_overview.php?filter=mecm_pending&sort=name&dir=desc&page=2#vm-${target}`);
  const overviewLeaks = await page.$$eval('a[href], form[action]', (elements) => elements
    .map((element) => element.getAttribute('href') ?? element.getAttribute('action') ?? '')
    .filter((value) => value.includes('work_ov_')));
  expect(overviewLeaks).toEqual([]);

  await templateBack.click();
  await expect(page).toHaveURL(new RegExp(`vm_overview\\.php\\?filter=mecm_pending&sort=name&dir=desc&page=2#vm-${target}$`));
  await expect(page.locator(`#vm-${target}`)).toBeFocused();

  // Mission details, reached from the all-VMs list.
  await page.goto('vm_overview.php?filter=all&sort=name&dir=desc');
  await page.locator(`#vm-${ids.mission.vm}`).getByRole('link', { name: ids.mission.name }).click();
  await expect(page).toHaveURL(/mission_details\.php/);
  const missionBack = page.getByRole('link', { name: 'Back to "VMs in missions"' });
  expect(await missionBack.getAttribute('href'))
    .toBe(`vm_overview.php?filter=all&sort=name&dir=desc#vm-${ids.mission.vm}`);

  // The mission's own tabs leave the overview behind: the VMs tab and the editor it opens.
  await page.locator('nav.tab-list').getByRole('link', { name: 'VMs', exact: true }).click();
  await expect(page).toHaveURL(/vms\.php/);
  expect(new URL(page.url()).search).not.toContain('work_ov_');
  await page.locator(`#vm-${ids.mission.vm}`).getByRole('link', { name: 'Edit' }).click();
  await expect(page).toHaveURL(/vm_edit\.php/);
  await expect(page.getByRole('link', { name: 'Back to VMs' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Back to "VMs in missions"' })).toHaveCount(0);

  // Opened again from the overview, the row returns there after going back.
  await page.goto('vm_overview.php?filter=all&sort=name&dir=desc');
  await page.locator(`#vm-${ids.mission.vm}`).getByRole('link', { name: ids.mission.name }).click();
  await expect(page).toHaveURL(/mission_details\.php/);
  await page.getByRole('link', { name: 'Back to "VMs in missions"' }).click();
  await expect(page).toHaveURL(new RegExp(`vm_overview\\.php\\?filter=all&sort=name&dir=desc#vm-${ids.mission.vm}$`));
  await expect(page.locator(`#vm-${ids.mission.vm}`)).toBeFocused();

  // Direct entry names no overview row, so the back link is the normal mission list.
  await page.goto(`mission_details.php?id=${ids.mission.id}&work_ov_filter=all`);
  expect(await page.getByRole('link', { name: 'Back', exact: true }).getAttribute('href'))
    .toBe(`missions.php?type=missions#mission-${ids.mission.id}`);
  await page.goto(`mission_details.php?id=${ids.mission.id}&work_ov_filter=all&work_ov_vm=abc`);
  expect(await page.getByRole('link', { name: 'Back', exact: true }).getAttribute('href'))
    .toBe(`missions.php?type=missions#mission-${ids.mission.id}`);

  // A missing mission returns to the overview list, without a row fragment.
  await page.goto('mission_details.php?id=2147480000&work_ov_filter=all&work_ov_vm=5');
  await expect(page).toHaveURL(/vm_overview\.php\?filter=all$/);
});
