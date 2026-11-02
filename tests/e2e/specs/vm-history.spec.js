const { test, expect } = require('../lib/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

// The VM chronicle (SP3, F03 with F2/F4): the editor links to it, it pages one
// global order with a cursor, filters by source, shows bulk changes through the
// index of migration 0069 and hides the change log without users.manage.

const MARK = 'e2evmhistory';

function cleanup() {
  runPhp(`
$db = db();
$db->query("DELETE l FROM deploy_logs l JOIN deploy_log_vm_refs x ON x.log_id = l.id JOIN deploy_vms v ON v.id = x.vm_id JOIN deploy_missions m ON m.id = v.mission_id WHERE m.mission_name = '${MARK}-mission'");
$db->query("DELETE FROM deploy_missions WHERE mission_name = '${MARK}-mission'");
`);
}

/** A VM with $statusCount status events and one bulk change that names it. */
function seed(statusCount) {
  return phpJson(`
$db = db();
$db->query("INSERT INTO deploy_missions (mission_name, mission_status) VALUES ('${MARK}-mission', 'active')");
$mission = (int) $db->insert_id;
$name = '${MARK}-vm';
$stmt = $db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname) VALUES (?, ?, ?)');
$stmt->bind_param('iss', $mission, $name, $name);
$stmt->execute();
$vm = (int) $db->insert_id;
for ($i = 0; $i < ${statusCount}; $i++) {
    repo_record_vm_status_event($db, $vm, 'ready', 'not_ready', '2/5 Registered', 'created from portal');
}
audit_event($db, VIRTUSPHERE_AUDIT_EVENT_VM_BULK_CHANGED, 'mission', $mission, VIRTUSPHERE_AUDIT_RESULT_SUCCESS, ['action' => 'bulk_reset_mecm_id', 'affected_count' => 1, 'vm_ids' => [$vm]], null, 'cli');
echo 'JSON' . json_encode(['mission' => $mission, 'vm' => $vm]) . 'JSON';
`, ['lib/repo/status_events.php', 'lib/repo/log.php']);
}

test.beforeEach(() => cleanup());
test.afterAll(() => cleanup());

test.describe('as admin', () => {
  test.use({ storageState: ROLES.admin.storageState });

  test('the editor links to the chronicle, which shows the bulk change and filters by source', async ({ page }) => {
    const ids = seed(2);
    await page.goto(`vm_edit.php?mission_id=${ids.mission}&vm_id=${ids.vm}`);
    await page.locator('[data-vm-history-link]').click();
    await expect(page).toHaveURL(new RegExp(`vm_history\\.php\\?mission_id=${ids.mission}&vm_id=${ids.vm}`));

    const rows = page.locator('[data-vm-history-entry]');
    await expect(rows).toHaveCount(3);
    await expect(page.locator('[data-vm-history-entry^="audit-"]')).toHaveCount(1);
    await expect(page.locator('[data-vm-history-entry^="audit-"]')).toContainText('Change log');

    await page.getByRole('checkbox', { name: 'Status' }).check();
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page).toHaveURL(/sources/);
    await expect(rows).toHaveCount(2);
    await expect(page.locator('[data-vm-history-entry^="audit-"]')).toHaveCount(0);

    await page.getByRole('link', { name: 'Back to the VM' }).click();
    await expect(page).toHaveURL(new RegExp(`vm_edit\\.php\\?mission_id=${ids.mission}&vm_id=${ids.vm}`));
  });

  test('without timeline entries the editor still links to the chronicle (NL1)', async ({ page }) => {
    const ids = seed(0);
    await page.goto(`vm_edit.php?mission_id=${ids.mission}&vm_id=${ids.vm}`);
    const timeline = page.locator('section', { has: page.locator('#vm-timeline-heading') });
    await expect(timeline).toBeVisible();
    await expect(timeline.locator('table')).toHaveCount(0);
    await timeline.locator('[data-vm-history-link]').click();
    await expect(page).toHaveURL(new RegExp(`vm_history\\.php\\?mission_id=${ids.mission}&vm_id=${ids.vm}`));
    await expect(page.locator('[data-vm-history-entry^="audit-"]'), 'the change log reaches the chronicle through the editor').toHaveCount(1);
  });

  test('paging continues one snapshot and a later entry waits for "Show newest"', async ({ page }) => {
    const ids = seed(60);
    await page.goto(`vm_history.php?mission_id=${ids.mission}&vm_id=${ids.vm}`);
    const rows = page.locator('[data-vm-history-entry]');
    await expect(rows).toHaveCount(50);
    const first = await rows.evaluateAll((list) => list.map((row) => row.getAttribute('data-vm-history-entry')));

    runPhp(`repo_record_vm_status_event(db(), ${ids.vm}, 'ready', 'not_ready', '2/5 Registered', 'created from portal');`, ['lib/repo/status_events.php']);
    await page.locator('[data-vm-history-older]').click();
    await expect(rows).toHaveCount(11);
    const second = await rows.evaluateAll((list) => list.map((row) => row.getAttribute('data-vm-history-entry')));
    expect(second.filter((key) => first.includes(key))).toEqual([]);
    await expect(page.locator('[data-vm-history-older]')).toHaveCount(0);

    await page.getByRole('link', { name: 'Show newest' }).click();
    await expect(rows).toHaveCount(50);
    await expect(page.locator('[data-vm-history-older]')).toHaveCount(1);
  });

  test('a manipulated cursor shows the newest entries with a notice', async ({ page }) => {
    const ids = seed(1);
    await page.goto(`vm_history.php?mission_id=${ids.mission}&vm_id=${ids.vm}&cursor=1.2.x`);
    await expect(page.getByText('The paging position was invalid.', { exact: false })).toBeVisible();
    await expect(page.locator('[data-vm-history-entry]')).toHaveCount(2);
  });
});

test.describe('as a user without users.manage', () => {
  test.use({ storageState: ROLES.user.storageState });

  test('the change log is neither offered nor shown', async ({ page }) => {
    const ids = seed(2);
    await page.goto(`vm_history.php?mission_id=${ids.mission}&vm_id=${ids.vm}&sources[]=audit`);
    await expect(page.getByRole('checkbox', { name: 'Change log' })).toHaveCount(0);
    await expect(page.locator('[data-vm-history-entry^="audit-"]')).toHaveCount(0);
    await expect(page.locator('[data-vm-history-entry]')).toHaveCount(2);
  });
});
