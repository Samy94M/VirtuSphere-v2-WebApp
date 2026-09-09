// The deploy queue form survives a mission change. Both controls that write
// mission_id reload the whole page on purpose (the VM list, the storage table
// and the per-host warnings only exist server-side, per mission), and the reload
// used to carry nothing but the mission: the credential pair, the mode, the wait
// time and the whole schedule block came back at their defaults.
//
// A browser is the only place this is visible at all: deploy_form.js reads the live
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
$vmIds = [];
foreach (['${MARK}-a' => ['E2EDFA1', 'E2EDFA2'], '${MARK}-b' => ['E2EDFB1']] as $name => $vmNames) {
    $mid = repo_create_mission($db, ['mission_name' => $name, 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'seed.example.local'], false, $admin);
    foreach ($vmNames as $vmName) {
        $stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os) VALUES (?, ?, ?, 'Win11')");
        $stmt->bind_param('iss', $mid, $vmName, $vmName);
        $stmt->execute();
        $vmIds[] = (int) $db->insert_id;
    }
    test_prepare_network_mac_fixture($db, $mid, $esxi, 'WDS', 'DC1');
    $missions[$name] = $mid;
}
echo 'JSON' . json_encode(['esxi' => $esxi, 'ansible' => $ans, 'a' => $missions['${MARK}-a'], 'b' => $missions['${MARK}-b'], 'vmIds' => $vmIds]) . 'JSON';
`, ['lib/repo/credentials.php', 'lib/repo/missions.php', 'tests/Support/NetworkMacFixtures.php']);
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

// Queue preview and confirmation require the callback URL as a local form
// prerequisite. Preserve the stack's previous value for the QA owner.
let apiBaseUrlBefore = '';
test.beforeAll(() => {
  apiBaseUrlBefore = phpJson(`
$v = repo_setting_value(db(), VIRTUSPHERE_SETTING_API_BASE_URL, '');
repo_set_setting(db(), VIRTUSPHERE_SETTING_API_BASE_URL, 'http://127.0.0.1:8021');
echo 'JSON' . json_encode(['v' => $v]) . 'JSON';
`, ['lib/deploy_constants.php', 'lib/repo/settings.php']).v;
});
test.afterAll(() => {
  cleanup();
  runPhp(`repo_set_setting(db(), VIRTUSPHERE_SETTING_API_BASE_URL, '${apiBaseUrlBefore}'); echo 'OK';`, ['lib/deploy_constants.php', 'lib/repo/settings.php']);
});

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

async function checkedVmIds(page) {
  return queueForm(page).locator('input[name="vm_ids[]"]:checked').evaluateAll((boxes) => (
    boxes.map((box) => Number(box.value))
  ));
}

function missionJobPayloads(missionId) {
  return phpJson(`
$db = db();
$mid = ${Number(missionId)};
$stmt = $db->prepare('SELECT payload_json FROM deploy_jobs WHERE mission_id = ? ORDER BY id');
$stmt->bind_param('i', $mid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
echo 'JSON' . json_encode(array_map(static fn (array $row): array => json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR), $rows)) . 'JSON';
`);
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

test('same-mission job filtering preserves a VM subset through blockers, preview and queue payload', async ({ page }) => {
  const ids = seed();
  const scheduledAt = localDatetimeIn(26);

  await page.goto(`deploy.php?mission_id=${ids.a}`);
  await fillQueueForm(page, ids, scheduledAt);

  const form = queueForm(page);
  const missionAVmIds = ids.vmIds.slice(0, 2);
  await expect(form.locator('input[name="vm_ids[]"]')).toHaveCount(2);
  const liveBlockerRequest = page.waitForRequest((request) => (
    request.url().includes('deploy_blockers.php')
      && new URL(request.url()).searchParams.getAll('vm_ids[]').length === 1
  ));
  await form.locator('input[name="vm_ids[]"]').nth(1).uncheck();
  const blockerRequest = await liveBlockerRequest;
  expect(
    new URL(blockerRequest.url()).searchParams.getAll('vm_ids[]').map(Number),
    'the live blocker evaluates the selected VM only',
  ).toEqual([missionAVmIds[0]]);

  // The filter re-renders the same mission. Its GET carrier must distinguish
  // this subset from a mission change before PHP rebuilds the checkbox list.
  const filter = page.locator('form[data-deploy-filter]');
  await filter.locator('select[name="mission_id"]').selectOption(String(ids.a));
  await Promise.all([
    page.waitForURL(atMission(ids.a)),
    filter.locator('button[type="submit"]').click(),
  ]);
  await page.waitForLoadState('load');

  await expectQueueFormIntact(page, ids, scheduledAt, 'the job filter carried the field along');
  expect(await checkedVmIds(page), 'the same-mission filter keeps the exact subset').toEqual([missionAVmIds[0]]);
  expect(new URL(page.url()).searchParams.get('vm_selection_mission_id'), 'the URL binds the selection to mission A')
    .toBe(String(ids.a));

  await queueForm(page).locator('button[type="submit"]').click();
  const confirm = page.locator('form:has(input[name="confirmed"])');
  await expect(confirm, 'the subset reaches the server-rendered preview').toBeVisible();
  const previewRows = page.locator('section.panel:has(input[name="confirmed"])').getByRole('table')
    .filter({ has: page.getByRole('columnheader', { name: /^(Geplant für|Scheduled for)$/ }) }).locator('tbody tr');
  await expect(previewRows).toHaveCount(1);
  await expect(previewRows).toContainText('E2EDFA1');
  await expect(confirm.locator('input[name="vm_ids[]"]')).toHaveValue(String(missionAVmIds[0]));

  await Promise.all([
    page.waitForResponse((response) => response.url().includes('deploy.php')
      && response.request().method() === 'POST'),
    confirm.locator('button[type="submit"]').click(),
  ]);
  const payloads = missionJobPayloads(ids.a);
  expect(payloads, 'confirmation queues one future job for the one selected VM').toHaveLength(1);
  expect(payloads[0].vm_ids, 'the durable queue payload retains the subset').toEqual([missionAVmIds[0]]);
});

test('same-mission job filtering preserves an explicitly empty VM selection', async ({ page }) => {
  const ids = seed();

  await page.goto(`deploy.php?mission_id=${ids.a}`);
  const form = queueForm(page);
  await form.locator('select[name="credential_esxi_id"]').selectOption(String(ids.esxi));
  await form.locator('select[name="credential_ansible_id"]').selectOption(String(ids.ansible));
  const emptyBlockerResponse = page.waitForResponse((response) => (
    response.url().includes('deploy_blockers.php')
      && new URL(response.url()).searchParams.getAll('vm_ids[]').length === 0
      && new URL(response.url()).searchParams.get('vm_selection_mission_id') === String(ids.a)
  ));
  await form.locator('[data-vm-select-all]').uncheck();
  const emptyVerdict = await (await emptyBlockerResponse).json();
  expect(emptyVerdict.can_queue, 'the live decision rejects an explicit empty selection').toBe(false);
  expect(emptyVerdict.blockers.map((blocker) => blocker.code), 'the live decision names the selection cause')
    .toContain('selection_empty');
  expect(await checkedVmIds(page), 'the precondition is an explicitly empty checkbox selection').toEqual([]);

  const filter = page.locator('form[data-deploy-filter]');
  await filter.locator('select[name="mission_id"]').selectOption(String(ids.a));
  await Promise.all([
    page.waitForURL(atMission(ids.a)),
    filter.locator('button[type="submit"]').click(),
  ]);

  expect(await checkedVmIds(page), 'the same-mission render keeps no VM checked').toEqual([]);
  const url = new URL(page.url());
  expect(url.searchParams.get('vm_selection_mission_id'), 'the marker carries empty-selection intent')
    .toBe(String(ids.a));
  expect(url.searchParams.getAll('vm_ids[]'), 'an empty selection adds no invented VM id').toEqual([]);
  await expect(queueForm(page).locator('[data-deploy-queue-button]'), 'server rendering keeps queueing blocked')
    .toBeDisabled();

  // Bypass the disabled button like a JS-less or handcrafted POST. The final
  // backend recheck must still refuse both preview and durable queue writes.
  await Promise.all([
    page.waitForResponse((response) => response.url().includes('deploy.php')
      && response.request().method() === 'POST'),
    queueForm(page).evaluate((node) => node.submit()),
  ]);
  await page.waitForLoadState('load');
  await expect(page.locator('form:has(input[name="confirmed"])'), 'an empty selection gets no preview').toHaveCount(0);
  expect(missionJobPayloads(ids.a), 'an empty portal selection creates no queue payload').toEqual([]);
});

test('job filtering to another mission discards the old VM ids', async ({ page }) => {
  const ids = seed();

  await page.goto(`deploy.php?mission_id=${ids.a}`);
  const form = queueForm(page);
  await form.locator('input[name="vm_ids[]"]').nth(1).uncheck();

  const filter = page.locator('form[data-deploy-filter]');
  await filter.locator('select[name="mission_id"]').selectOption(String(ids.b));
  await Promise.all([
    page.waitForURL(atMission(ids.b)),
    filter.locator('button[type="submit"]').click(),
  ]);

  await expect(queueForm(page).locator('input[name="vm_ids[]"]'), 'mission B renders its own VM list').toHaveCount(1);
  expect(await checkedVmIds(page), 'mission B starts with its own VM selected').toEqual([ids.vmIds[2]]);
  const url = new URL(page.url());
  expect(url.searchParams.has('vm_selection_mission_id'), 'a real mission change carries no old provenance').toBe(false);
  expect(url.searchParams.getAll('vm_ids[]'), 'a real mission change carries no old ids').toEqual([]);
});
