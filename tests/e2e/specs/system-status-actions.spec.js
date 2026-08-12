// TESTPLAN 3.5 / E6: the two system-status actions. The inventory refresh must
// enqueue a real system job for the clicked credential; the VLAN mass
// reassignment only renders when a genuine deviation exists, asks first
// (danger: it rewrites every stored assignment), and proves its Cancel branch
// by DB state.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2eint';

function cleanup() {
  runPhp(`
$db = db();
$db->query("DELETE l FROM deploy_logs l INNER JOIN deploy_credentials c ON l.log_message LIKE CONCAT('tested credential id ', c.id, ':%') WHERE c.name LIKE '${MARK}%'");
$db->query("DELETE FROM deploy_jobs WHERE credential_esxi_id IN (SELECT id FROM deploy_credentials WHERE name LIKE '${MARK}%') OR credential_ansible_id IN (SELECT id FROM deploy_credentials WHERE name LIKE '${MARK}%') OR mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE '${MARK}%')");
$db->query("DELETE FROM deploy_ansible_preflight_state WHERE credential_id IN (SELECT id FROM deploy_credentials WHERE name LIKE '${MARK}%')");
$db->query("DELETE FROM deploy_esxi_inventory_state WHERE credential_id IN (SELECT id FROM deploy_credentials WHERE name LIKE '${MARK}%')");
$db->query("DELETE FROM deploy_credentials WHERE name LIKE '${MARK}%'");
$db->query("DELETE FROM deploy_vlan WHERE vlan_name LIKE 'E2EVLAN-%'");
$db->query("DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE '${MARK}%')");
$db->query("DELETE FROM deploy_missions WHERE mission_name LIKE '${MARK}%'");
echo 'CLEANED';
`);
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

// e2e-covers: system_status.php:test
test('Ansible card separates an outdated full test from mission evidence and can retest in place', async ({ page }) => {
  const seed = phpJson(`
$db = db();
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);
$credential = repo_create_credential($db, [
    'type' => VIRTUSPHERE_CREDENTIAL_TYPE_ANSIBLE,
    'name' => '${MARK}-ansible-status',
    'host' => '127.0.0.1',
    'port' => 1,
    'username' => 'ansible',
], 'secret123', $admin);
repo_ansible_preflight_record($db, $credential, VIRTUSPHERE_ANSIBLE_PREFLIGHT_STATUS_OK, null);
$age = VIRTUSPHERE_ANSIBLE_PREFLIGHT_STALE_AFTER_DAYS + 1;
$db->query('UPDATE deploy_ansible_preflight_state SET last_checked_at = DATE_SUB(NOW(), INTERVAL ' . $age . ' DAY) WHERE credential_id = ' . $credential);
$mission = repo_create_mission($db, [
    'mission_name' => '${MARK}-ansible-mission',
    'hypervisor_datastorage' => 'ds1',
    'hypervisor_datacenter' => 'DC1',
    'domain' => 'seed.example.local',
    'wds_vlan' => 'E2EVLAN-STATUS',
], false, null);
// attempts = 1 is what makes this a job a worker actually claimed and ran.
// Seeding the schema default would prove the card with a row that never
// executed, which is exactly the display the reader now refuses.
$status = VIRTUSPHERE_DEPLOY_STATUS_SUCCEEDED;
$stmt = $db->prepare('INSERT INTO deploy_jobs (mission_id, status, attempts, payload_json, credential_ansible_id, updated_at) VALUES (?, ?, 1, \'{"mode":"start"}\', ?, DATE_SUB(NOW(), INTERVAL 1 HOUR))');
$stmt->bind_param('isi', $mission, $status, $credential);
$stmt->execute();
$processed = (int) $db->insert_id;
// Newer, but cancelled straight out of the queue: never claimed, never ran.
// It must not become the evidence the operator reads.
$cancelled = VIRTUSPHERE_DEPLOY_STATUS_CANCELLED;
$stmt = $db->prepare('INSERT INTO deploy_jobs (mission_id, status, attempts, credential_ansible_id, updated_at) VALUES (?, ?, 0, ?, NOW())');
$stmt->bind_param('isi', $mission, $cancelled, $credential);
$stmt->execute();
echo 'JSON' . json_encode(['credential' => $credential, 'job' => $processed, 'never_ran' => (int) $db->insert_id]) . 'JSON';
`, ['lib/repo/credentials.php', 'lib/repo/ansible_preflight.php', 'lib/repo/missions.php']);

  await page.goto('system_status.php');
  const row = page.locator('#ansible article.status-row').filter({ hasText: `${MARK}-ansible-status` });
  await expect(row.locator('.status-row-head .badge')).toHaveText(/Test veraltet|Test outdated/);
  await expect(row).toContainText(/Letzter vom Worker bearbeiteter Missionsauftrag|Last mission job processed by the worker/);
  await expect(row).toContainText(/Erfolgreich|Succeeded/);
  // Which mode ran decides how much the row proves, so it has to be readable.
  await expect(row).toContainText(/Modus start|Mode start/);
  await expect(row.locator(`a[href="deploy_log.php?id=${Number(seed.job)}"]`)).toBeVisible();
  // The newer never-claimed job stays out of the card entirely: neither its
  // outcome nor its log link may stand in for work that never happened.
  await expect(row).not.toContainText(/Abgebrochen|Cancelled/);
  await expect(row.locator(`a[href="deploy_log.php?id=${Number(seed.never_ran)}"]`)).toHaveCount(0);

  const form = row.locator('form[action="credentials.php"]:has(input[name="return_to"][value="ansible_status"])');
  await expect(form.getByRole('button', { name: /Volltest jetzt starten|Run full test now/ })).toBeVisible();

  // Three facts plus the action must stay usable when the card collapses to a
  // single-column mobile layout. This is the wrap boundary changed by the new
  // operational-history fact, so prove geometry rather than only text.
  await page.setViewportSize({ width: 360, height: 800 });
  const geometry = await row.evaluate((element) => {
    const rect = element.getBoundingClientRect();
    return {
      left: rect.left,
      right: rect.right,
      viewport: document.documentElement.clientWidth,
      pageWidth: document.documentElement.scrollWidth,
    };
  });
  expect(geometry.left).toBeGreaterThanOrEqual(0);
  expect(geometry.right).toBeLessThanOrEqual(geometry.viewport + 1);
  expect(geometry.pageWidth).toBeLessThanOrEqual(geometry.viewport);

  await Promise.all([
    page.waitForResponse((response) => response.url().includes('credentials.php') && response.request().method() === 'POST'),
    form.getByRole('button').click(),
  ]);
  await expect(page).toHaveURL(/system_status\.php$/);
  await expect(page.locator('.alert-error').first(), 'the failed localhost SSH test reports its real outcome').toBeVisible();

  const recorded = phpJson(`
$db = db();
$state = $db->query('SELECT last_status FROM deploy_ansible_preflight_state WHERE credential_id = ${Number(seed.credential)}')->fetch_assoc();
$audit = $db->query("SELECT id FROM deploy_logs WHERE category = 'credentials' AND log_message LIKE 'tested credential id ${Number(seed.credential)}:%' ORDER BY id DESC LIMIT 1")->fetch_assoc();
echo 'JSON' . json_encode(['status' => $state['last_status'] ?? null, 'audited' => $audit !== null]) . 'JSON';
`);
  expect(recorded.status).toBe('failed');
  expect(recorded.audited, 'the existing credential audit protocol is reused').toBe(true);
});

// e2e-covers: system_status.php:refresh_inventory
test('refresh_inventory: the targeted refresh enqueues a system job for the credential', async ({ page }) => {
  const seed = phpJson(`
$db = db();
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);
$esxi = repo_create_credential($db, ['type' => 'esxi', 'name' => '${MARK}-esxi', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'root'], 'secret123', $admin);
$hasAnsible = (int) ($db->query("SELECT COUNT(*) AS c FROM deploy_credentials WHERE type = 'ansible'")->fetch_assoc()['c'] ?? 0) > 0;
if (!$hasAnsible) {
    repo_create_credential($db, ['type' => 'ansible', 'name' => '${MARK}-ans', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'ans'], 'secret123', $admin);
}
echo 'JSON' . json_encode(['esxi' => $esxi]) . 'JSON';
`, ['lib/repo/credentials.php']);

  await page.goto('system_status.php');
  const card = page.locator('.inventory-card-grid .inventory-card', { hasText: `${MARK}-esxi` }).first();
  const refresh = card.locator('form:has(input[name="action"][value="refresh_inventory"]) button');
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('system_status.php') && r.request().method() === 'POST'),
    refresh.click(),
  ]);
  await expect(page.locator('.alert').first(), 'the refresh reports its outcome').toBeVisible();

  // A system job has no mission; its mode travels in payload_json.
  const jobs = phpJson(`
$db = db();
$stmt = $db->prepare("SELECT COUNT(*) AS c FROM deploy_jobs WHERE credential_esxi_id = ? AND mission_id IS NULL AND payload_json LIKE '%\\"inventory\\"%'");
$id = ${Number(seed.esxi)};
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode(['c' => (int) $stmt->get_result()->fetch_assoc()['c']]) . 'JSON';
`);
  expect(jobs.c, 'an inventory system job was enqueued for the credential').toBe(1);
});

// e2e-covers: system_status.php:reassign_vlan
// e2e-covers-cancel: system_status.php:reassign_vlan
test('reassign_vlan: renders only with a real deviation, Cancel keeps assignments, Confirm rewrites them', async ({ page }) => {
  const seed = phpJson(`
$db = db();
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);
$esxi = repo_create_credential($db, ['type' => 'esxi', 'name' => '${MARK}-inv', 'host' => '127.0.0.2', 'port' => 1, 'username' => 'root'], 'secret123', $admin);
$stmt = $db->prepare("INSERT INTO deploy_esxi_inventory (credential_id, kind, name) VALUES (?, 'network', 'E2EVLAN-OK')");
$stmt->bind_param('i', $esxi);
$stmt->execute();
$db->query("INSERT INTO deploy_vlan (vlan_name) VALUES ('E2EVLAN-OK')");
$mid = repo_create_mission($db, ['mission_name' => '${MARK}-mission', 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local', 'wds_vlan' => 'E2EVLAN-STALE'], false, null);
$stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, 'E2EIVM1', 'E2EIVM1')");
$stmt->bind_param('i', $mid);
$stmt->execute();
$vmId = (int) $db->insert_id;
$stmt = $db->prepare("INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mode) VALUES (?, '', '', '', 'E2EVLAN-STALE', 'dhcp')");
$stmt->bind_param('i', $vmId);
$stmt->execute();
echo 'JSON' . json_encode(['missionId' => $mid, 'vmId' => $vmId]) . 'JSON';
`, ['lib/repo/credentials.php', 'lib/repo/missions.php']);

  const missionVlan = () => phpJson(`
$db = db();
$stmt = $db->prepare('SELECT wds_vlan FROM deploy_missions WHERE id = ?');
$id = ${Number(seed.missionId)};
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc()) . 'JSON';
`).wds_vlan;

  await page.goto('system_status.php');
  await page.locator('details.repair-actions > summary').click();
  const form = page.locator('form:has(input[name="action"][value="reassign_vlan"])');
  await expect(form, 'the deviation makes the reassign form render').toBeVisible();
  await form.locator('input[name="vlan_from"]').fill('E2EVLAN-STALE');
  await form.locator('select[name="vlan_to"]').selectOption('E2EVLAN-OK');

  const dialog = page.locator('[data-confirm-dialog]');
  await form.locator('button[type="submit"]').click();
  await expect(dialog, 'the mass rewrite asks first').toBeVisible();
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(missionVlan(), 'Cancel kept the stale assignment').toBe('E2EVLAN-STALE');

  await form.locator('input[name="vlan_from"]').fill('E2EVLAN-OK');
  await form.locator('select[name="vlan_to"]').selectOption('E2EVLAN-OK');
  await form.locator('button[type="submit"]').click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('system_status.php') && r.request().method() === 'POST'),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  await expect(page.locator('select[name="vlan_to"]'), 'identical source and target is rejected at the target field').toHaveAttribute('aria-invalid', 'true');
  expect(missionVlan(), 'invalid equal values changed nothing').toBe('E2EVLAN-STALE');

  await form.locator('input[name="vlan_from"]').evaluate((input) => input.removeAttribute('required'));
  await form.locator('input[name="vlan_from"]').fill('');
  await form.locator('select[name="vlan_to"]').selectOption('E2EVLAN-OK');
  await form.locator('button[type="submit"]').click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('system_status.php') && r.request().method() === 'POST'),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  await expect(page.locator('input[name="vlan_from"]'), 'the server reports an empty source at its field').toHaveAttribute('aria-invalid', 'true');
  expect(missionVlan(), 'empty source changed nothing').toBe('E2EVLAN-STALE');

  await form.locator('input[name="vlan_from"]').fill('E2EVLAN-NOT-ASSIGNED');
  await form.locator('select[name="vlan_to"]').selectOption('E2EVLAN-OK');
  await form.locator('button[type="submit"]').click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('system_status.php') && r.request().method() === 'POST'),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  await expect(page.locator('.alert-warning').first(), 'zero matches is feedback, not a false success').toBeVisible();
  await expect(page).toHaveURL(/system_status\.php#reassign$/);
  await expect(form.locator('input[name="vlan_from"]'), 'zero-match input remains available for correction').toHaveValue('E2EVLAN-NOT-ASSIGNED');
  expect(missionVlan(), 'zero matches changed nothing').toBe('E2EVLAN-STALE');

  await form.locator('input[name="vlan_from"]').fill('E2EVLAN-STALE');
  await form.locator('select[name="vlan_to"]').selectOption('E2EVLAN-OK');
  await form.locator('button[type="submit"]').click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('system_status.php') && r.request().method() === 'POST'),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  await expect(page.locator('.alert-success').first(), 'the rewrite reports missions and interfaces').toBeVisible();
  expect(missionVlan(), 'the mission VLAN was reassigned').toBe('E2EVLAN-OK');
  const iface = phpJson(`
$db = db();
$stmt = $db->prepare('SELECT vlan FROM deploy_interfaces WHERE vm_id = ?');
$id = ${Number(seed.vmId)};
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc()) . 'JSON';
`);
  expect(iface.vlan, 'the interface VLAN was reassigned').toBe('E2EVLAN-OK');
});
