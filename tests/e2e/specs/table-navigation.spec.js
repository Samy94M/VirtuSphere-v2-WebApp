// Etappe 15.1/15.2: the pinned action column of the VM list and the
// server-rendered page navigation.
//
// Both are geometry and semantics that no source scan can see.
// PortalPageNavContractTest proves the classes and the stylesheet rules exist;
// it cannot prove that the pinned cell actually stays on screen while the table
// scrolls under it, that it stays opaque over the cell it covers, or that it
// lets go on a narrow viewport. Those are measured here.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const PREFIX = 'e2enav-';

/** A mission with enough VMs that the list is worth scrolling. */
function seedMission(name, count) {
  return phpJson(`
$db = db();
$id = repo_create_mission($db, ['mission_name' => '${name}', 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local'], false, null);
for ($i = 1; $i <= ${Number(count)}; $i++) {
    $vmName = 'E2ENAV' . $i;
    $vmHost = 'E2EN' . $id . 'V' . $i;
    $stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os, lifecycle_state) VALUES (?, ?, ?, 'Win11', 'ready')");
    $stmt->bind_param('iss', $id, $vmName, $vmHost);
    $stmt->execute();
}
echo 'JSON' . json_encode(['missionId' => $id]) . 'JSON';
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
echo 'CLEANED';
`);
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

test('VM list: the action column stays on screen while the table scrolls under it', async ({ page }) => {
  const seed = seedMission(PREFIX + 'sticky', 3);

  // Narrow enough that a fifteen-column table has to scroll, wide enough to be
  // well above the 720px release breakpoint.
  await page.setViewportSize({ width: 900, height: 800 });
  await page.goto(`vms.php?mission_id=${seed.missionId}`);

  const wrap = page.locator('.table-wrap').first();
  const overflow = await wrap.evaluate((node) => node.scrollWidth - node.clientWidth);
  expect(overflow, 'the VM table must actually overflow, or this test proves nothing').toBeGreaterThan(0);

  const cell = page.locator('td.table-action-cell').first();
  const before = await cell.boundingBox();

  await wrap.evaluate((node) => { node.scrollLeft = node.scrollWidth; });
  const after = await cell.boundingBox();

  // Pinned means the cell keeps its screen position while its neighbours move.
  expect(Math.abs(after.x - before.x), 'the action cell must not move with the scroll').toBeLessThan(2);

  const wrapBox = await wrap.boundingBox();
  expect(after.x + after.width, 'the action cell stays inside the scroll container').toBeLessThanOrEqual(wrapBox.x + wrapBox.width + 2);

  // Opaque, not see-through: the cell now covers data cells, and a transparent
  // background would print two rows of text on top of each other.
  const painted = await cell.evaluate((node) => getComputedStyle(node).backgroundColor);
  expect(painted, 'the pinned cell paints its own background').not.toBe('rgba(0, 0, 0, 0)');
  expect(painted, 'the pinned cell is fully opaque').not.toMatch(/rgba\([^)]*,\s*0(\.\d+)?\)$/);
});

test('VM list: the pinned column is released on a narrow viewport', async ({ page }) => {
  const seed = seedMission(PREFIX + 'mobile', 2);

  await page.setViewportSize({ width: 700, height: 800 });
  await page.goto(`vms.php?mission_id=${seed.missionId}`);

  const position = await page.locator('td.table-action-cell').first().evaluate((node) => getComputedStyle(node).position);
  expect(position, 'below the wrap breakpoint the column gives its width back').toBe('static');
});

test('VM list: the pinned header cell stays above the pinned body cells', async ({ page }) => {
  const seed = seedMission(PREFIX + 'stack', 2);

  await page.setViewportSize({ width: 900, height: 800 });
  await page.goto(`vms.php?mission_id=${seed.missionId}`);

  const layers = await page.evaluate(() => ({
    head: Number(getComputedStyle(document.querySelector('th.table-action-cell')).zIndex),
    body: Number(getComputedStyle(document.querySelector('td.table-action-cell')).zIndex),
  }));
  expect(layers.head, 'the header cell covers the body cells scrolling under it').toBeGreaterThan(layers.body);
});

test('mission pages: exactly one current entry, and no tab widget semantics', async ({ page }) => {
  const seed = seedMission(PREFIX + 'pagenav', 1);

  for (const [url, expected] of [
    [`mission_details.php?id=${seed.missionId}`, 'Details'],
    [`vms.php?mission_id=${seed.missionId}`, 'VMs'],
  ]) {
    await page.goto(url);
    const nav = page.locator('nav.tab-list').first();
    await expect(nav.locator('[aria-current="page"]'), 'exactly one entry is the page you are on').toHaveCount(1);
    await expect(nav.locator('[aria-current="page"]')).toHaveText(expected);
    await expect(nav.locator('[role="tab"]'), 'these navigate, they are not tabs').toHaveCount(0);
    await expect(nav.locator('[aria-selected]'), 'aria-selected belongs to a tab widget').toHaveCount(0);
    await expect(nav, 'the navigation names itself for a screen reader').toHaveAttribute('aria-label', /.+/);
  }
});

test('mission lists: switching between missions and templates moves the current marker', async ({ page }) => {
  await page.goto('missions.php?type=missions');
  const nav = page.locator('nav.tab-list').first();
  await expect(nav.locator('[aria-current="page"]')).toHaveCount(1);
  const first = await nav.locator('[aria-current="page"]').textContent();

  await nav.getByText('Templates', { exact: true }).click();
  await page.waitForURL(/type=templates/);
  await expect(nav.locator('[aria-current="page"]')).toHaveCount(1);
  const second = await nav.locator('[aria-current="page"]').textContent();

  expect(second, 'the marker follows the page, it does not stay put').not.toBe(first);
});
