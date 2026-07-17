// TESTPLAN 3.5 / E6: the five deploy actions. Every seeded job is *scheduled*
// (scheduled_at in the future), because the claim query skips those (ADR-0022):
// the real worker of the stack can never grab a fixture mid-test, which makes
// cancel/cancel_group deterministic. Only the retry test creates a job the
// worker may claim; its assertions do not depend on the job's later fate.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2edeploy';

function seedBase() {
  return phpJson(`
$db = db();
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);
$esxi = repo_create_credential($db, ['type' => 'esxi', 'name' => '${MARK}-esxi', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'root'], 'secret123', $admin);
$ans = repo_create_credential($db, ['type' => 'ansible', 'name' => '${MARK}-ans', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'ans'], 'secret123', $admin);
$mid = repo_create_mission($db, ['mission_name' => '${MARK}-m1', 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local'], false, $admin);
$vmIds = [];
foreach (['E2EDVM1', 'E2EDVM2'] as $i => $vmName) {
    $stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os) VALUES (?, ?, ?, 'Win11')");
    $stmt->bind_param('iss', $mid, $vmName, $vmName);
    $stmt->execute();
    $vmId = (int) $db->insert_id;
    $stmt = $db->prepare("INSERT INTO deploy_interfaces (vm_id, ip, subnet, gateway, vlan, mac, mode) VALUES (?, '', '', '', 'WDS', ?, 'dhcp')");
    $mac = sprintf('00:50:56:CC:DD:%02X', $vmId % 256);
    $stmt->bind_param('is', $vmId, $mac);
    $stmt->execute();
}
echo 'JSON' . json_encode(['admin' => $admin, 'esxi' => $esxi, 'ansible' => $ans, 'missionId' => $mid]) . 'JSON';
`, ['lib/repo/credentials.php', 'lib/repo/missions.php']);
}

function seedScheduledJob(seed) {
  return phpJson(`
$db = db();
$payload = ['mode' => 'export', 'verbose' => false, 'vm_ids' => [], 'powercycle_wait' => VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT];
$jobId = repo_create_deploy_job($db, ${seed.missionId}, ${seed.admin}, ${seed.esxi}, ${seed.ansible}, $payload, gmdate('Y-m-d H:i:s', time() + 7200));
echo 'JSON' . json_encode(['jobId' => $jobId]) . 'JSON';
`, ['lib/deploy_constants.php', 'lib/repo/deploy_jobs.php']).jobId;
}

function jobRow(id) {
  return phpJson(`
$db = db();
$stmt = $db->prepare('SELECT status, cancelled_at, scheduled_at FROM deploy_jobs WHERE id = ?');
$id = ${Number(id)};
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc() ?: null) . 'JSON';
`);
}

function missionJobs(missionId) {
  return phpJson(`
$db = db();
$stmt = $db->prepare('SELECT id, status, scheduled_at, group_id FROM deploy_jobs WHERE mission_id = ? ORDER BY id');
$id = ${Number(missionId)};
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC)) . 'JSON';
`);
}

function cleanup() {
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_jobs WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE '${MARK}%')");
$db->query("DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE '${MARK}%')");
$db->query("DELETE FROM deploy_missions WHERE mission_name LIKE '${MARK}%'");
$db->query("DELETE FROM deploy_credentials WHERE name LIKE '${MARK}%'");
echo 'CLEANED';
`);
}

// Before EACH test: every test seeds its own mission and credentials, and the
// credential names are unique per type, so a leftover seed would make the next
// seedBase() throw.
test.beforeEach(() => cleanup());
test.afterAll(() => cleanup());

// Queueing requires a configured API base URL ($apiBaseUrlReady); a fresh dev
// stack leaves it empty. Set it for the spec's lifetime, restore what was there.
let apiBaseUrlBefore = '';
test.beforeAll(() => {
  apiBaseUrlBefore = phpJson(`
$v = repo_setting_value(db(), VIRTUSPHERE_SETTING_API_BASE_URL, '');
repo_set_setting(db(), VIRTUSPHERE_SETTING_API_BASE_URL, 'http://127.0.0.1:8021');
echo 'JSON' . json_encode(['v' => $v]) . 'JSON';
`, ['lib/deploy_constants.php', 'lib/repo/settings.php']).v;
});
test.afterAll(() => {
  runPhp(`repo_set_setting(db(), VIRTUSPHERE_SETTING_API_BASE_URL, '${apiBaseUrlBefore}'); echo 'OK';`, ['lib/deploy_constants.php', 'lib/repo/settings.php']);
});

function localDatetimeIn(hours) {
  const dt = new Date(Date.now() + hours * 3600 * 1000);
  const pad = (n) => String(n).padStart(2, '0');
  return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
}

// e2e-covers: deploy.php:start
test('start (scheduled): the preview must be confirmed, then a scheduled job is queued', async ({ page }) => {
  const seed = seedBase();

  await page.goto(`deploy.php?mission_id=${seed.missionId}`);
  // Not select[name="mission_id"]: the jobs filter form below carries one too.
  const form = page.locator('form:has(select[name="credential_esxi_id"])');
  await form.locator('select[name="credential_esxi_id"]').selectOption(String(seed.esxi));
  await form.locator('select[name="credential_ansible_id"]').selectOption(String(seed.ansible));
  await form.locator('input[name="start_mode"][value="scheduled"]').check();
  // +26h: in the future in any timezone the portal may be configured for, and
  // far inside the scheduling horizon.
  await form.locator('input[name="scheduled_at"]').fill(localDatetimeIn(26));
  await form.locator('button[type="submit"]').click();

  // B3.3: no redirect; the page renders the computed start times and a confirm
  // form that re-submits with confirmed=1.
  const confirm = page.locator('form:has(input[name="confirmed"])');
  await expect(confirm, 'the schedule preview offers the confirm step').toBeVisible();
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('deploy.php') && r.request().method() === 'POST'),
    confirm.locator('button[type="submit"]').click(),
  ]);
  await expect(page.locator('.alert-success').first(), 'the schedule is confirmed with a flash').toBeVisible();

  const jobs = missionJobs(seed.missionId);
  expect(jobs.length, 'exactly one job was queued').toBe(1);
  expect(jobs[0].status, 'the job waits as queued').toBe('queued');
  expect(jobs[0].scheduled_at, 'the job carries its start time').not.toBeNull();
});

// e2e-covers: deploy.php:cancel
// e2e-covers-cancel: deploy.php:cancel
test('cancel: Cancel keeps the job queued, Confirm cancels it', async ({ page }) => {
  const seed = seedBase();
  const jobId = seedScheduledJob(seed);

  await page.goto(`deploy.php?mission_id=${seed.missionId}`);
  const row = page.locator('tbody tr', { hasText: `${MARK}-m1` }).first();
  const dialog = page.locator('[data-confirm-dialog]');
  const cancelButton = row.locator('form:has(input[name="action"][value="cancel"]) button');

  await cancelButton.click();
  await expect(dialog, 'cancelling a job asks first').toBeVisible();
  await expect(page.locator('[data-confirm-msg]'), 'the dialog names the mission').toContainText(`${MARK}-m1`);
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(jobRow(jobId).status, 'dismissing the dialog keeps the job queued').toBe('queued');

  await cancelButton.click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForURL(/deploy\.php/),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  const after = jobRow(jobId);
  expect(after.status, 'the job is cancelled').toBe('cancelled');
  expect(after.cancelled_at, 'the cancellation is timestamped').not.toBeNull();
});

// e2e-covers: deploy.php:cancel_group
// e2e-covers-cancel: deploy.php:cancel_group
test('cancel_group: Cancel keeps the slots, Confirm cancels every queued slot', async ({ page }) => {
  const seed = seedBase();
  runPhp(`
$db = db();
$payload = ['mode' => 'powercycle', 'verbose' => false, 'vm_ids' => [], 'powercycle_wait' => VIRTUSPHERE_POWERCYCLE_WAIT_DEFAULT];
$result = repo_enqueue_deploy_group($db, ${seed.missionId}, ${seed.admin}, ${seed.esxi}, ${seed.ansible}, $payload, gmdate('Y-m-d H:i:s', time() + 7200), 5);
echo 'GROUP=' . $result['group_id'];
`, ['lib/deploy_constants.php', 'lib/repo/deploy_jobs.php']);

  const before = missionJobs(seed.missionId);
  expect(before.length, 'one slot per VM was queued').toBe(2);

  await page.goto(`deploy.php?mission_id=${seed.missionId}`);
  const dialog = page.locator('[data-confirm-dialog]');
  const groupCancel = page.locator('form:has(input[name="action"][value="cancel_group"]) button').first();

  await groupCancel.click();
  await expect(dialog, 'cancelling the group asks first').toBeVisible();
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  for (const job of missionJobs(seed.missionId)) {
    expect(job.status, 'dismissing the dialog keeps every slot queued').toBe('queued');
  }

  await groupCancel.click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForURL(/deploy\.php/),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  for (const job of missionJobs(seed.missionId)) {
    expect(job.status, 'every queued slot is cancelled').toBe('cancelled');
  }
});

// e2e-covers: deploy.php:retry
// e2e-covers-cancel: deploy.php:retry
test('retry: Cancel creates nothing, Confirm queues a new job for the failed one', async ({ page }) => {
  const seed = seedBase();
  const failedId = phpJson(`
$db = db();
$payload = json_encode(['mode' => 'export', 'verbose' => false, 'vm_ids' => [], 'powercycle_wait' => 5], JSON_THROW_ON_ERROR);
$stmt = $db->prepare("INSERT INTO deploy_jobs (mission_id, user_id, status, payload_json, credential_esxi_id, credential_ansible_id, last_error) VALUES (?, ?, 'failed', ?, ?, ?, 'seeded failure')");
$mid = ${seed.missionId};
$uid = ${seed.admin};
$esxi = ${seed.esxi};
$ans = ${seed.ansible};
$stmt->bind_param('iisii', $mid, $uid, $payload, $esxi, $ans);
$stmt->execute();
echo 'JSON' . json_encode(['id' => (int) $db->insert_id]) . 'JSON';
`).id;

  await page.goto(`deploy.php?mission_id=${seed.missionId}`);
  const dialog = page.locator('[data-confirm-dialog]');
  const retry = page.locator('form:has(input[name="action"][value="retry"]) button').first();

  await retry.click();
  await expect(dialog, 'retrying asks first').toBeVisible();
  await expect(page.locator('[data-confirm-msg]'), 'the dialog names the mission').toContainText(`${MARK}-m1`);
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(missionJobs(seed.missionId).length, 'dismissing the dialog created no job').toBe(1);

  await retry.click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    // A successful retry redirects into the new job's log page.
    page.waitForURL(/deploy_log\.php\?id=/),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  const jobs = missionJobs(seed.missionId);
  expect(jobs.length, 'the retry queued a second job').toBe(2);
  expect(Number(jobs[0].id), 'the original job is untouched').toBe(failedId);
});

// e2e-covers: deploy_log.php:cancel
// e2e-covers-cancel: deploy_log.php:cancel
test('deploy_log cancel: Cancel keeps the job, Confirm cancels it from the log page', async ({ page }) => {
  const seed = seedBase();
  const jobId = seedScheduledJob(seed);

  await page.goto(`deploy_log.php?id=${jobId}`);
  const dialog = page.locator('[data-confirm-dialog]');
  const cancelButton = page.locator('form:has(input[name="action"][value="cancel"]) button');

  await cancelButton.click();
  await expect(dialog, 'cancelling from the log page asks first').toBeVisible();
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(jobRow(jobId).status, 'dismissing the dialog keeps the job queued').toBe('queued');

  await cancelButton.click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForURL(/deploy\.php/),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  expect(jobRow(jobId).status, 'the job is cancelled').toBe('cancelled');
});
