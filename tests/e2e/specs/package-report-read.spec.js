const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2epkgreport';
const RUN_ID = '018f2f49-5e41-4d55-8f05-8f55a5335e71';

function cleanup() {
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_missions WHERE mission_name = '${MARK}-mission'");
$db->query("DELETE FROM deploy_package_run_markers WHERE run_id = UUID_TO_BIN('${RUN_ID}')");
`);
}

function seed() {
  return phpJson(`
$db = db();
$db->query("INSERT INTO deploy_missions (mission_name, mission_status) VALUES ('${MARK}-mission', 'active')");
$mission = (int) $db->insert_id;
foreach (['first', 'second'] as $suffix) {
    $name = '${MARK}-' . $suffix;
    $stmt = $db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $mission, $name, $name);
    $stmt->execute();
    $ids[] = (int) $db->insert_id;
}
$db->query("INSERT INTO deploy_package_run_markers (run_id, expires_at) VALUES (UUID_TO_BIN('${RUN_ID}'), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 DAY))");
$db->query("INSERT INTO deploy_package_runs
    (run_id, vm_id, device_generation, acceptance_generation, rollout_revision,
     project_name, package_version, run_context, mac_candidates, client_started_at,
     first_received_at, last_evidence_at)
    SELECT UUID_TO_BIN('${RUN_ID}'), {$ids[0]}, v.package_report_generation,
           s.acceptance_generation, 1, 'E2E-REPORT-PACKAGE', '1.0', 'system', JSON_ARRAY(),
           UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
    FROM deploy_vms v CROSS JOIN deploy_package_report_state s WHERE v.id = {$ids[0]} AND s.id = 1");
echo 'JSON' . json_encode(['mission' => $mission, 'first' => $ids[0], 'second' => $ids[1]]) . 'JSON';
`);
}

test.beforeEach(() => cleanup());
test.afterAll(() => cleanup());

test('VM report links keep exact ownership and do not invent a completion', async ({ page }) => {
  const ids = seed();
  await page.goto(`vm_edit.php?mission_id=${ids.mission}&vm_id=${ids.first}`);
  const panel = page.locator('#package-reports');
  await expect(panel).toContainText('E2E-REPORT-PACKAGE 1.0');
  await expect(panel).toContainText('Completion not reported');
  await panel.getByRole('link', { name: 'Details' }).click();
  await expect(page).toHaveURL(new RegExp(`package_run\\.php\\?mission_id=${ids.mission}.*run_id=${RUN_ID}`));
  await expect(page.locator('#main').getByRole('heading', { name: 'Package report' })).toBeVisible();
  await expect(page.getByText('Processed count unknown because no completion was reported.', { exact: true })).toBeVisible();
  const runLink = page.getByRole('link', { name: 'Link to this attempt' });
  await expect(runLink).toHaveAttribute('href', `package_run.php?mission_id=${ids.mission}&vm_id=${ids.first}&run_id=${RUN_ID}`);
  await expect(page.locator('[data-copy-link]')).toHaveAttribute('data-copy-link', await runLink.getAttribute('href'));
  await page.getByText('Support copy of this data snapshot').click();
  await expect(page.locator('#package-report-support-text')).toHaveValue(new RegExp(RUN_ID));
  await expect(page.locator('#package-report-support-text')).toHaveValue(/Processed count unknown because no completion was reported/);
  await page.getByRole('link', { name: 'View package reports on other devices (without error comparison)' }).click();
  await expect(page.getByText('Matching attempts: 0. Devices: 0.')).toBeVisible();

  await page.goto('package_reports.php?name=E2E-REPORT&state=no_completion');
  await expect(page.getByText('Matching attempts: 1. Devices: 1.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Details' })).toHaveCount(1);
  await page.getByRole('link', { name: 'Details' }).click();
  await expect(page.getByRole('link', { name: 'Refresh view' })).toHaveAttribute('href', /list=1.*name=E2E-REPORT/);
  await page.getByRole('link', { name: 'Back to filtered package reports' }).click();
  await expect(page.getByText('Matching attempts: 1. Devices: 1.')).toBeVisible();
  await page.goto('package_reports.php?name=%25');
  await expect(page.getByText('No stored reports match these filters.')).toBeVisible();
  await page.getByRole('link', { name: 'Select today' }).click();
  const todayUrl = new URL(page.url());
  expect(todayUrl.searchParams.get('from')).toBe(todayUrl.searchParams.get('to'));
  await page.goto(`package_reports.php?run_id=${RUN_ID}`);
  await expect(page).toHaveURL(new RegExp(`package_run\\.php\\?mission_id=${ids.mission}.*run_id=${RUN_ID}`));

  await page.goto(`package_run.php?mission_id=${ids.mission}&vm_id=${ids.second}&run_id=${RUN_ID}`);
  await expect(page.getByRole('heading', { name: 'Package report unavailable.' })).toBeVisible();
});
