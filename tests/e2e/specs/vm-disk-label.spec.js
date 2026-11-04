// NZ1: the VM editor shows the Windows volume label next to a disk name field
// while the operator types, only where it differs from the name. The rule and
// its mirror are pinned by shared vectors (VmDiskLayoutTest and
// tests/disk-label-mirror.test.js); this spec proves the wiring in the page:
// position, island, hidden state and aria-describedby. Locale-neutral: it
// checks labels and the client fallback token, never a sentence.

const { test, expect } = require('../lib/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const PREFIX = 'e2edisklabel-';

function cleanup() {
  runPhp(`
$db = db();
$like = '${PREFIX}%';
$stmt = $db->prepare('DELETE FROM deploy_missions WHERE mission_name LIKE ?');
$stmt->bind_param('s', $like);
$stmt->execute();
echo 'CLEANED';
`);
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

test('disk name field shows the Windows label only where it differs', async ({ page }) => {
  const seed = phpJson(`
$db = db();
$id = repo_create_mission($db, ['mission_name' => '${PREFIX}1', 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local'], false, null);
echo 'JSON' . json_encode(['missionId' => $id]) . 'JSON';
`, ['lib/repo/missions.php']);

  await page.goto(`vm_edit.php?mission_id=${seed.missionId}`);
  const rows = page.locator('[data-repeat-target="disks"] [data-repeat-row]');
  await page.locator('[data-add-row="disks"]').click();
  await expect(rows).toHaveCount(2);

  const system = rows.nth(0);
  const data = rows.nth(1);
  const systemInput = system.locator('[data-disk-label-input]');
  const dataInput = data.locator('[data-disk-label-input]');
  const systemHint = system.locator('[data-disk-label-hint]');
  const dataHint = data.locator('[data-disk-label-hint]');

  // The proposed name of a new row fits: nothing to say.
  await expect(dataInput).toHaveValue('Disk 2');
  await expect(dataHint).toBeHidden();

  // Over the limit: the cut label appears and describes the field.
  await dataInput.fill('Datenablage Datenablage Datenablage Ende');
  await expect(dataHint).toBeVisible();
  await expect(dataHint).toContainText('Datenablage Datenablage Datenab');
  await expect(dataHint).not.toContainText('Ende');
  const hintId = await dataHint.getAttribute('id');
  expect(await dataInput.getAttribute('aria-describedby')).toContain(hintId);

  // Empty: the validator saves the default name, which becomes the label.
  await dataInput.fill('');
  await expect(dataHint).toContainText('Disk 2');

  // Nothing usable: the client keeps its own VM_Disk_ label.
  await dataInput.fill('???');
  await expect(dataHint).toContainText('VM_Disk_');

  // Back to a fitting name: hidden again and no longer describing the field.
  await dataInput.fill('Daten');
  await expect(dataHint).toBeHidden();
  expect(String(await dataInput.getAttribute('aria-describedby') || '')).not.toContain(hintId);

  // The system disk is never formatted, so it never gets a label hint.
  await systemInput.fill('Datenablage Datenablage Datenablage Ende');
  await expect(systemHint).toBeHidden();
});
