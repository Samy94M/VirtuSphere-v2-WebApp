const { test, expect } = require('@playwright/test');
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
