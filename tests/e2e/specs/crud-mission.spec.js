// TESTPLAN 3.3: CRUD round-trips for the mission entity, verified through state
// (DB + a fresh GET), never through the POST response, since that renders sticky
// form_remember() values and proves nothing about persistence. Covers create,
// edit (a change survives reload while a neighbor field is untouched), and the
// delete confirm dialog: Cancel must leave the row in the DB, Confirm must remove
// it everywhere.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { submitAndWaitForNavigation } = require('../lib/navigation');
const { execFileSync } = require('node:child_process');

test.use({ storageState: ROLES.admin.storageState });

const PHP_CONTAINER = process.env.VIRTUSPHERE_PHP_CONTAINER || 'virtusphere-v2-webapp-php-1';
const PREFIX = 'e2ecrud-';

function runPhp(body) {
  const php =
    '<?php\n' +
    'require_once "/var/www/html/lib/bootstrap.php";\n' +
    'require_once "/var/www/html/lib/repo/missions.php";\n' +
    body;
  return execFileSync('docker', ['exec', '-i', PHP_CONTAINER, 'php'], {
    input: php,
    encoding: 'utf8',
    timeout: 15000,
  });
}

// A ready-to-edit mission: datacenter, datastore and domain are set so the
// detail update form (which requires them for a non-template) has valid
// neighbors, and a single-field edit does not trip validation on an unrelated
// empty field.
function seedReadyMission(name) {
  const out = runPhp(`
$db = db();
$id = repo_create_mission($db, ['mission_name' => '${name.replace(/'/g, "\\'")}', 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local'], false, null);
echo 'MID=' . $id;
`);
  const m = out.match(/MID=(\d+)/);
  return m ? Number(m[1]) : 0;
}

function missionRow(missionId) {
  const out = runPhp(`
$db = db();
$stmt = $db->prepare('SELECT mission_name, domain FROM deploy_missions WHERE id = ? LIMIT 1');
$id = ${Number(missionId)};
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
echo 'JSON' . json_encode($row ?: null) . 'JSON';
`);
  const m = out.match(/JSON([\s\S]*)JSON/);
  return m ? JSON.parse(m[1]) : null;
}

function idByName(name) {
  const out = runPhp(`
$db = db();
$stmt = $db->prepare('SELECT id FROM deploy_missions WHERE mission_name = ? ORDER BY id DESC LIMIT 1');
$n = '${name.replace(/'/g, "\\'")}';
$stmt->bind_param('s', $n);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
echo 'MID=' . ($row ? (int) $row['id'] : 0);
`);
  const m = out.match(/MID=(\d+)/);
  return m ? Number(m[1]) : 0;
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

async function createMission(page, name) {
  await page.goto('missions.php?type=missions');
  await page.locator('form:has(input[name="action"][value="create"]) input[name="mission_name"]').fill(name);
  await submitAndWaitForNavigation(
    page,
    page.locator('form:has(input[name="action"][value="create"]) button[type="submit"]'),
    'missions.php'
  );
  return idByName(name);
}

// e2e-covers: missions.php:create
test('create: the mission appears in the list, in a fresh GET and in the DB', async ({ page }) => {
  const name = PREFIX + 'create-1';
  const id = await createMission(page, name);
  expect(id, 'row exists in the DB').toBeGreaterThan(0);

  // Fresh GET, not the POST response.
  await page.goto('missions.php?type=missions');
  const row = page.locator('tr', { has: page.locator(`a[href="mission_details.php?id=${id}"]`) });
  await expect(row, 'the new mission is listed').toHaveCount(1);
  expect((await row.locator('td').first().textContent()).trim()).toBe(name);
});

// e2e-covers: mission_details.php:update
test('edit: a change survives reload and leaves a neighbor field untouched', async ({ page }) => {
  const name = PREFIX + 'edit-1';
  // Seed a ready mission (datacenter/datastore set) so the update form does not
  // reject on an unrelated required field the bare create form never fills.
  const id = seedReadyMission(name);
  expect(id).toBeGreaterThan(0);

  // Change the domain (a neighbor of mission_name) through the detail form.
  await page.goto(`mission_details.php?id=${id}`);
  await page.locator('form:has(input[name="action"][value="update"]) input[name="domain"]').fill('corp.example.local');
  await submitAndWaitForNavigation(
    page,
    page.locator('form:has(input[name="action"][value="update"]) button[type="submit"]').first(),
    'mission_details.php'
  );

  let stored = missionRow(id);
  expect(stored.domain, 'the domain persisted').toBe('corp.example.local');
  expect(stored.mission_name, 'the name neighbor is unchanged').toBe(name);

  // Now rename and confirm the domain neighbor survives the rename.
  const renamed = PREFIX + 'edit-1-renamed';
  await page.goto(`mission_details.php?id=${id}`);
  await page.locator('form:has(input[name="action"][value="update"]) input[name="mission_name"]').fill(renamed);
  await submitAndWaitForNavigation(
    page,
    page.locator('form:has(input[name="action"][value="update"]) button[type="submit"]').first(),
    'mission_details.php'
  );

  stored = missionRow(id);
  expect(stored.mission_name, 'the rename persisted after reload').toBe(renamed);
  expect(stored.domain, 'the domain neighbor survived the rename').toBe('corp.example.local');
});

// e2e-covers: missions.php:delete
// e2e-covers-cancel: missions.php:delete
test('delete: Cancel keeps the row, Confirm removes it from the DB and the list', async ({ page }) => {
  const name = PREFIX + 'del-1';
  const id = await createMission(page, name);

  await page.goto('missions.php?type=missions');
  const row = page.locator('tr', { has: page.locator(`a[href="mission_details.php?id=${id}"]`) });
  const deleteButton = row.locator('button.button-danger');
  const dialog = page.locator('[data-confirm-dialog]');

  // Open the confirm dialog.
  await deleteButton.click();
  await expect(dialog, 'the confirm dialog opens').toBeVisible();
  // Locale-agnostic: the admin session may be DE or EN. Either wording asks about
  // deleting a mission.
  const msg = page.locator('[data-confirm-msg]');
  await expect(msg, 'it shows the delete question').toContainText(/löschen|delete/i);
  // It names the row it is about, so a dialog cannot be confirmed for the wrong
  // mission when several read alike. The name is user input, so it must arrive as
  // literal text (core.js sets textContent), never as parsed markup.
  await expect(msg, 'the dialog names the mission it is about').toContainText(name);

  // Cancel: the dialog closes and nothing is deleted (DB proof).
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(missionRow(id), 'Cancel left the mission in the DB').not.toBeNull();

  // Confirm: the row is removed from the DB and the list.
  await deleteButton.click();
  await expect(dialog).toBeVisible();
  await submitAndWaitForNavigation(page, dialog.locator('[data-confirm-accept]'), 'missions.php');
  expect(missionRow(id), 'Confirm deleted the mission from the DB').toBeNull();
  await expect(
    page.locator('tr', { has: page.locator(`a[href="mission_details.php?id=${id}"]`) }),
    'the row is gone from the list'
  ).toHaveCount(0);
});
