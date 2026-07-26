// The deploy queue form survives a mission change. Both controls that write
// mission_id reload the whole page on purpose (the VM list, the storage table
// and the per-host warnings only exist server-side, per mission), and the reload
// used to carry nothing but the mission: the credential pair, the mode, the wait
// time and the whole schedule block came back at their defaults.
//
// A browser is the only place this is visible at all: deploy.js reads the live
// controls and lib/deploy_form_state.php reads them back, so every automated
// check stays green while the form empties itself.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2edform';

// Every field of the queue form, with a value that is not its default, so a
// field that silently falls back to the default fails instead of passing.
const FILLED = {
  mode: 'powercycle', // staggerable and power-cycling, and it disables the start wait
  powercycle_wait: '42',
  // Deliberately the one field the chosen mode DOES disable: powercycle runs no
  // start playbook. A disabled-but-filled control is exactly what FormData drops
  // and form.elements keeps, so this value travelling is the proof of that rule.
  start_wait: '99',
  stagger_minutes: '7',
};

function seed() {
  return phpJson(`
$db = db();
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);
$esxi = repo_create_credential($db, ['type' => 'esxi', 'name' => '${MARK}-esxi', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'root'], 'secret123', $admin);
$ans = repo_create_credential($db, ['type' => 'ansible', 'name' => '${MARK}-ans', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'ans'], 'secret123', $admin);
$missions = [];
foreach (['${MARK}-a' => ['E2EDFA1', 'E2EDFA2'], '${MARK}-b' => ['E2EDFB1']] as $name => $vmNames) {
    $mid = repo_create_mission($db, ['mission_name' => $name, 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local'], false, $admin);
    foreach ($vmNames as $vmName) {
        $stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os) VALUES (?, ?, ?, 'Win11')");
        $stmt->bind_param('iss', $mid, $vmName, $vmName);
        $stmt->execute();
    }
    $missions[$name] = $mid;
}
echo 'JSON' . json_encode(['esxi' => $esxi, 'ansible' => $ans, 'a' => $missions['${MARK}-a'], 'b' => $missions['${MARK}-b']]) . 'JSON';
`, ['lib/repo/credentials.php', 'lib/repo/missions.php']);
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

test.beforeEach(() => cleanup());
test.afterAll(() => cleanup());

// The queue form is the one with the credential selects; the page carries a
// second select[name="mission_id"] in the job filter below.
function queueForm(page) {
  return page.locator('form:has(select[name="credential_esxi_id"])');
}

// mission_id=<id> as a whole value: a bare regex would match 123 for 12, and
// the carried URL now has more parameters behind it.
function atMission(missionId) {
  return new RegExp('mission_id=' + missionId + '(&|$)');
}

function localDatetimeIn(hours) {
  const dt = new Date(Date.now() + hours * 3600 * 1000);
  const pad = (n) => String(n).padStart(2, '0');
  return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
}

async function fillQueueForm(page, ids, scheduledAt) {
  const form = queueForm(page);
  await form.locator('select[name="credential_esxi_id"]').selectOption(String(ids.esxi));
  await form.locator('select[name="credential_ansible_id"]').selectOption(String(ids.ansible));
  // 'full' first, because it is the one mode that enables BOTH wait fields, so
  // both can be typed the way an operator types them. Then the mode changes to
  // the one under test, which disables the start wait while keeping the typed
  // value: that is the state the carrier has to survive.
  //
  // Filling a disabled input directly is not an option, with or without `force`:
  // the keystrokes go to whatever still holds focus, so the value lands in the
  // neighbouring field. This spec read 4299 in the power-cycle wait for exactly
  // that reason, and blamed the page for it.
  await form.locator('select[name="mode"]').selectOption('full');
  await form.locator('input[name="powercycle_wait"]').fill(FILLED.powercycle_wait);
  await form.locator('input[name="start_wait"]').fill(FILLED.start_wait);
  await form.locator('select[name="mode"]').selectOption(FILLED.mode);
  // The precondition of the rule this spec proves. Without it the "disabled but
  // filled" case silently degrades into an ordinary enabled field.
  await expect(form.locator('input[name="start_wait"]'), 'the mode disables the start wait').toBeDisabled();
  await form.locator('input[name="verbose"]').check();
  // The datetime field is hidden until the radio unhides it.
  await form.locator('input[name="start_mode"][value="scheduled"]').check();
  await form.locator('input[name="scheduled_at"]').fill(scheduledAt);
  await form.locator('input[name="stagger_minutes"]').fill(FILLED.stagger_minutes);
}

async function expectQueueFormIntact(page, ids, scheduledAt, because) {
  const form = queueForm(page);
  await expect(form.locator('select[name="credential_esxi_id"]'), because).toHaveValue(String(ids.esxi));
  await expect(form.locator('select[name="credential_ansible_id"]'), because).toHaveValue(String(ids.ansible));
  await expect(form.locator('select[name="mode"]'), because).toHaveValue(FILLED.mode);
  await expect(form.locator('input[name="powercycle_wait"]'), because).toHaveValue(FILLED.powercycle_wait);
  await expect(form.locator('input[name="start_wait"]'), because).toHaveValue(FILLED.start_wait);
  await expect(form.locator('input[name="verbose"]'), because).toBeChecked();
  await expect(form.locator('input[name="start_mode"][value="scheduled"]'), because).toBeChecked();
  await expect(form.locator('input[name="scheduled_at"]'), because).toHaveValue(scheduledAt);
  await expect(form.locator('input[name="stagger_minutes"]'), because).toHaveValue(FILLED.stagger_minutes);
}

test('changing the mission keeps every other field of the queue form', async ({ page }) => {
  const ids = seed();
  const scheduledAt = localDatetimeIn(26);

  await page.goto(`deploy.php?mission_id=${ids.a}`);
  await fillQueueForm(page, ids, scheduledAt);

  const form = queueForm(page);
  // Uncheck one VM of mission A: the selection must NOT travel, because those
  // checkboxes name the VMs of the mission being left.
  await form.locator('input[name="vm_ids[]"]').first().uncheck();

  await Promise.all([
    page.waitForURL(atMission(ids.b)),
    form.locator('select[name="mission_id"]').selectOption(String(ids.b)),
  ]);
  await page.waitForLoadState('load');

  await expectQueueFormIntact(page, ids, scheduledAt, 'the mission change carried the field along');
  await expect(form.locator('select[name="mission_id"]'), 'the new mission is selected').toHaveValue(String(ids.b));

  // Mission B's own VM, checked: a fresh mission starts fully selected instead
  // of inheriting a subset that named other rows.
  const boxes = form.locator('input[name="vm_ids[]"]');
  await expect(boxes, 'the VM list belongs to the new mission').toHaveCount(1);
  await expect(boxes.first(), 'a fresh mission starts fully selected').toBeChecked();
});

test('filtering the job history keeps the queue form too', async ({ page }) => {
  const ids = seed();
  const scheduledAt = localDatetimeIn(26);

  await page.goto(`deploy.php?mission_id=${ids.a}`);
  await fillQueueForm(page, ids, scheduledAt);

  // The filter writes the same mission_id, so it re-renders the queue form as
  // well: same loss through a second door.
  const filter = page.locator('form[data-deploy-filter]');
  await filter.locator('select[name="mission_id"]').selectOption(String(ids.b));
  await Promise.all([
    page.waitForURL(atMission(ids.b)),
    filter.locator('button[type="submit"]').click(),
  ]);
  await page.waitForLoadState('load');

  await expectQueueFormIntact(page, ids, scheduledAt, 'the job filter carried the field along');
  await expect(filter.locator('select[name="mission_id"]'), 'the filter shows what it filtered by').toHaveValue(String(ids.b));
});
