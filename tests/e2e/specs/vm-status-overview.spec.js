// SP4: how far a mission is, and which VMs need a check. The mission list shows
// one phase badge per reached phase (R1); the dashboard's "check due" tile lists
// its groups, each a link to the VM overview with the filter of the same name
// (R2, F6). Read-only views, so no e2e-covers marker.

const { test, expect } = require('../lib/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const PREFIX = 'e2evs-';

/** A mission whose VMs carry the given lifecycle/MECM pairs. */
function seedMission(name, states) {
  return phpJson(`
$db = db();
$id = repo_create_mission($db, ['mission_name' => '${name}'], false, null);
$vmIds = [];
foreach (json_decode('${JSON.stringify(states)}', true) as $i => [$lifecycle, $mecm]) {
    $vmName = 'E2EVS' . $id . 'N' . $i;
    $stmt = $db->prepare('INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, lifecycle_state, mecm_sync_state) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issss', $id, $vmName, $vmName, $lifecycle, $mecm);
    $stmt->execute();
    $vmIds[] = (int) $db->insert_id;
}
echo 'JSON' . json_encode(['missionId' => $id, 'vmIds' => $vmIds]) . 'JSON';
`, ['lib/repo/missions.php']);
}

function cleanup() {
  runPhp(`
$db = db();
$like = '${PREFIX}%';
$stmt = $db->prepare('DELETE FROM deploy_vms WHERE mission_id IN (SELECT id FROM deploy_missions WHERE mission_name LIKE ?)');
$stmt->bind_param('s', $like);
$stmt->execute();
$stmt = $db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
$stmt->bind_param('s', $like);
$stmt->execute();
echo 'CLEAN';
`);
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

test('mission list shows one phase badge per reached phase and opens the VM list sorted by phase', async ({ page }) => {
  const seed = seedMission(PREFIX + 'phases', [
    ['deployed', 'registered'],
    ['ready', 'not_ready'],
    ['ready', 'not_ready'],
  ]);

  await page.goto('missions.php?type=missions');
  await expect(page.locator('thead'), 'the phase column is named').toContainText(/Phases|Phasen/);

  const cell = page.locator(`#mission-${seed.missionId} a.mission-phases`);
  await expect(cell, 'one link per mission').toHaveCount(1);
  await expect(cell.locator('.badge'), 'one badge per reached phase').toHaveCount(2);
  await expect(cell.locator('.badge').first(), 'the phases read along the chain').toHaveText(/^(Created|Angelegt): 2$/);
  await expect(cell.locator('.badge').nth(1)).toHaveText(/^(Handed to MECM|An MECM übergeben): 1$/);
  await expect(cell).toHaveAttribute('href', new RegExp(`^vms\\.php\\?mission_id=${seed.missionId}&sort=status`));

  await Promise.all([page.waitForURL(/vms\.php\?mission_id=/), cell.click()]);
  await expect(page.locator(`#vm-${seed.vmIds[0]}`), 'the VM list of the mission opens').toHaveCount(1);

  await page.goto('missions.php?type=templates');
  await expect(page.locator('thead'), 'templates have no phases').not.toContainText(/Phases|Phasen/);
});

test('dashboard lists the check-due groups and each opens the overview with the same filter', async ({ page }) => {
  const seed = seedMission(PREFIX + 'groups', [['failed', 'not_ready']]);
  const failedVm = seed.vmIds[0];

  await page.goto('dashboard.php');
  const tile = page.locator('.kpi-groups');
  await expect(tile, 'a failed VM puts the check-due tile on the dashboard').toHaveCount(1);
  const group = tile.locator('a[href*="filter=job_failed"]');
  await expect(group).toHaveCount(1);
  await expect(group).toContainText(/Job failed|Auftrag fehlgeschlagen/);
  await expect(group, 'the link carries the moment it was counted').toHaveAttribute('href', /counted=\d+&expected=\d+/);
  await expect(tile.locator('a[href*="attention=1"]'), 'the old mission filter is no longer the tile target').toHaveCount(0);

  await Promise.all([page.waitForURL(/vm_overview\.php\?filter=job_failed/), group.click()]);
  await expect(page.locator(`#vm-${failedVm}`), 'the failed VM is a row of the group list').toHaveCount(1);
  await expect(page.locator('main'), 'an unknown outcome is named, not called a failure').toContainText(/unknown outcome|Ausgang unbekannt/);
  await expect(page.locator('nav').filter({ has: page.locator('a[href*="filter=job_failed"]') }).first(), 'the group is a filter of the list').toHaveCount(1);
});
