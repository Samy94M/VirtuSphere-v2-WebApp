const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2eetappe12';
let apiBaseUrlBefore = '';

function localDatetimeAfter(hours) {
  const value = new Date(Date.now() + (hours * 60 * 60 * 1000));
  const pad = (part) => String(part).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}T${pad(value.getHours())}:${pad(value.getMinutes())}`;
}

function blockerPayload(blockers, canQueue = false) {
  return {
    ok: true,
    count: blockers.length,
    can_queue: canQueue,
    blockers,
    labels: {
      prefix: 'Blocker:',
      count: blockers.length === 1 ? '1 Blocker verhindert das Einreihen.' : `${blockers.length} Blocker verhindern das Einreihen.`,
      jump: 'Zum ersten Blocker',
    },
  };
}

async function changeAndReadBlockerRequest(page, change) {
  const responsePromise = page.waitForResponse((response) => response.url().includes('deploy_blockers.php?'));
  await change();
  const response = await responsePromise;
  return new URL(response.url()).searchParams;
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

function seed() {
  return phpJson(`
$db = db();
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);
$esxi = repo_create_credential($db, ['type' => 'esxi', 'name' => '${MARK}-esxi', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'root'], 'secret123', $admin);
$ansible = repo_create_credential($db, ['type' => 'ansible', 'name' => '${MARK}-ansible', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'ansible'], 'secret123', $admin);
$mission = repo_create_mission($db, ['mission_name' => '${MARK}-mission', 'hypervisor_datastorage' => 'ds1', 'hypervisor_datacenter' => 'DC1', 'domain' => 'e12.invalid'], false, $admin);
$name = '${MARK}-vm';
$stmt = $db->prepare("INSERT INTO deploy_vms (mission_id, vm_name, vm_hostname, vm_os, updated) VALUES (?, ?, ?, 'Win11', 1)");
$stmt->bind_param('iss', $mission, $name, $name);
$stmt->execute();
test_prepare_network_mac_fixture($db, $mission, $esxi, 'WDS', 'DC1');
echo 'JSON' . json_encode(['mission' => $mission, 'esxi' => $esxi, 'ansible' => $ansible]) . 'JSON';
`, ['lib/repo/credentials.php', 'lib/repo/missions.php', 'tests/Support/NetworkMacFixtures.php']);
}

test.beforeAll(() => {
  apiBaseUrlBefore = phpJson(`
$value = repo_setting_value(db(), VIRTUSPHERE_SETTING_API_BASE_URL, '');
repo_set_setting(db(), VIRTUSPHERE_SETTING_API_BASE_URL, 'http://127.0.0.1:8021');
echo 'JSON' . json_encode(['value' => $value]) . 'JSON';
`, ['lib/deploy_constants.php', 'lib/repo/settings.php']).value;
});

test.beforeEach(() => cleanup());
test.afterAll(async ({ browser }) => {
  cleanup();
  runPhp(`repo_set_setting(db(), VIRTUSPHERE_SETTING_API_BASE_URL, '${apiBaseUrlBefore}'); echo 'OK';`, ['lib/deploy_constants.php', 'lib/repo/settings.php']);
  // `?lang=de` is not a per-request switch: __locale_resolve() writes the choice
  // into $_SESSION['locale_mode'], and every spec here shares one admin session.
  // Left alone it makes the portal German for everything that runs afterwards.
  // Inside one project that stays invisible, because this file sorts after the
  // specs asserting English; the release matrix runs Firefox to the end and
  // then starts WebKit from the top, so those specs met a session this file had
  // already switched. Two of them went red for a reason that had nothing to do
  // with them. A spec restores the shared state it changed.
  const context = await browser.newContext({ storageState: ROLES.admin.storageState });
  try {
    const page = await context.newPage();
    await page.goto('dashboard.php?lang=auto');
  } finally {
    await context.close();
  }
});

test('live blocker list and queue button use the same endpoint verdict', async ({ page }) => {
  const ids = seed();
  await page.goto(`deploy.php?mission_id=${ids.mission}&lang=de`);
  const form = page.locator('form:has([data-deploy-mission])');
  const summary = page.locator('[data-deploy-blocker-summary]');
  await expect(summary).toContainText('2 Blocker');
  await expect(page.locator('[data-deploy-queue-button]')).toBeDisabled();

  await form.locator('select[name="credential_esxi_id"]').selectOption(String(ids.esxi));
  const responsePromise = page.waitForResponse((response) => response.url().includes('deploy_blockers.php'));
  await form.locator('select[name="credential_ansible_id"]').selectOption(String(ids.ansible));
  const response = await responsePromise;
  expect(response.headers()['content-type']).toContain('application/json');
  await expect(summary).toBeHidden();
  await expect(page.locator('[data-deploy-queue-button]')).toBeEnabled();
});

test('every live queue control refreshes blockers and disabled filled values survive', async ({ page }) => {
  const ids = seed();
  const scheduledAt = localDatetimeAfter(26);
  await page.goto(`deploy.php?mission_id=${ids.mission}&lang=de`);
  const form = page.locator('form:has([data-deploy-mission])');
  let params = await changeAndReadBlockerRequest(page, () => form.locator('select[name="credential_esxi_id"]').selectOption(String(ids.esxi)));
  expect(params.get('credential_esxi_id')).toBe(String(ids.esxi));
  params = await changeAndReadBlockerRequest(page, () => form.locator('select[name="credential_ansible_id"]').selectOption(String(ids.ansible)));
  expect(params.get('credential_ansible_id')).toBe(String(ids.ansible));
  params = await changeAndReadBlockerRequest(page, () => form.locator('input[name="powercycle_wait"]').fill('42'));
  expect(params.get('powercycle_wait')).toBe('42');
  params = await changeAndReadBlockerRequest(page, () => form.locator('input[name="start_wait"]').fill('99'));
  expect(params.get('start_wait')).toBe('99');
  params = await changeAndReadBlockerRequest(page, () => form.locator('select[name="mode"]').selectOption('powercycle'));
  expect(params.get('mode')).toBe('powercycle');
  await expect(form.locator('input[name="start_wait"]')).toBeDisabled();
  expect(params.get('start_wait'), 'disabled-but-filled control remains in the live request').toBe('99');
  params = await changeAndReadBlockerRequest(page, () => form.locator('input[name="verbose"]').check());
  expect(params.get('verbose')).toBe('1');
  params = await changeAndReadBlockerRequest(page, () => form.locator('input[name="vm_ids[]"]').uncheck());
  expect(params.getAll('vm_ids[]')).toEqual([]);
  params = await changeAndReadBlockerRequest(page, () => form.locator('input[name="start_mode"][value="scheduled"]').check());
  expect(params.get('start_mode')).toBe('scheduled');
  params = await changeAndReadBlockerRequest(page, () => form.locator('input[name="scheduled_at"]').fill(scheduledAt));
  expect(params.get('scheduled_at')).toBe(scheduledAt);
  params = await changeAndReadBlockerRequest(page, () => form.locator('input[name="stagger_minutes"]').fill('7'));
  expect(params.get('stagger_minutes')).toBe('7');
  expect(params.get('mission_id')).toBe(String(ids.mission));
  expect(params.get('start_wait')).toBe('99');
  await expect(page.locator('[data-deploy-queue-button]')).toBeEnabled();
});

test('single-flight discards an older response and renders text without HTML injection', async ({ page }) => {
  const ids = seed();
  let requests = 0;
  await page.route('**/deploy_blockers.php?*', async (route) => {
    requests += 1;
    if (requests === 1) {
      await new Promise((resolve) => setTimeout(resolve, 900));
      try {
        await route.fulfill({
          contentType: 'application/json',
          body: JSON.stringify(blockerPayload([{ kind: 'prerequisite', code: 'old', target_id: 'deploy-blocker-1', message: 'ALTE ANTWORT' }])),
        });
      } catch (error) {
        // AbortController is expected to cancel this route before it can win.
      }
      return;
    }
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify(blockerPayload([{ kind: 'prerequisite', code: 'new', target_id: 'deploy-blocker-1', message: '<img src=x> NEUE ANTWORT' }], false)),
    });
  });
  await page.goto(`deploy.php?mission_id=${ids.mission}&lang=de`);
  const form = page.locator('form:has([data-deploy-mission])');
  const firstRequest = page.waitForRequest((request) => request.url().includes('deploy_blockers.php?'));
  await form.locator('select[name="credential_esxi_id"]').selectOption(String(ids.esxi));
  await firstRequest;
  const secondResponse = page.waitForResponse((response) => response.url().includes('deploy_blockers.php?'));
  await form.locator('select[name="credential_ansible_id"]').selectOption(String(ids.ansible));
  await secondResponse;
  await expect(page.getByText('<img src=x> NEUE ANTWORT')).toBeVisible();
  await expect(page.getByText('ALTE ANTWORT')).toHaveCount(0);
  await expect(page.locator('[data-deploy-blocker-list] img')).toHaveCount(0);
  expect(requests).toBe(2);
});

test('invalid content type fails closed and 403 stops later live requests', async ({ page }) => {
  const ids = seed();
  let requests = 0;
  await page.route('**/deploy_blockers.php?*', async (route) => {
    requests += 1;
    await route.fulfill({ status: 200, contentType: 'text/html', body: '<p>login page</p>' });
  });
  await page.goto(`deploy.php?mission_id=${ids.mission}&lang=de`);
  const form = page.locator('form:has([data-deploy-mission])');
  await changeAndReadBlockerRequest(page, () => form.locator('select[name="credential_esxi_id"]').selectOption(String(ids.esxi)));
  await expect(page.locator('[data-deploy-queue-button]')).toBeDisabled();
  await expect(page.getByText(/Blocker konnten nicht aktuell geprüft werden/)).toBeVisible();

  await page.unroute('**/deploy_blockers.php?*');
  requests = 0;
  await page.route('**/deploy_blockers.php?*', async (route) => {
    requests += 1;
    await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ ok: false, message: 'Berechtigungsprüfung beendet.' }) });
  });
  await changeAndReadBlockerRequest(page, () => form.locator('select[name="credential_ansible_id"]').selectOption(String(ids.ansible)));
  await expect(page.getByText('Berechtigungsprüfung beendet.')).toBeVisible();
  await form.locator('input[name="verbose"]').check();
  await page.waitForTimeout(400);
  expect(requests, '401/403 permanently stops this client instance').toBe(1);
});

test('password purpose, policy hints and updated display are user-facing', async ({ page }) => {
  const ids = seed();
  await page.goto('account.php?lang=de');
  await expect(page.locator('#current_password')).toHaveAttribute('autocomplete', 'current-password');
  await expect(page.locator('#form-account-new_password')).toHaveAttribute('autocomplete', 'new-password');
  await expect(page.locator('#form-account-new_password')).toHaveAttribute('minlength', /\d+/);
  await expect(page.locator('#form-account-passwords-hint')).toContainText(/Mindestens \d+ Zeichen/);

  await page.goto('users.php');
  await expect(page.locator('#form-create-password')).toHaveAttribute('autocomplete', 'new-password');
  await expect(page.locator('#form-create-password')).toHaveAttribute('minlength', /\d+/);

  await page.goto(`vms.php?mission_id=${ids.mission}`);
  await expect(page.getByText('Für MECM vorgemerkt')).toBeVisible();
});

test('nested help deep link opens and focuses its registered section', async ({ page }) => {
  await page.goto('help.php?lang=de#help-backup');
  const target = page.locator('#help-backup');
  await expect(target).toBeVisible();
  await expect(target).toBeFocused();
  await expect(page.locator('#panel-stack')).toBeVisible();
});

test('mission actions wrap without horizontal overflow on the mobile boundary', async ({ page }) => {
  const ids = seed();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`vms.php?mission_id=${ids.mission}&lang=de`);
  const geometry = await page.locator('section.panel > .actions').first().evaluate((actions) => {
    const tops = Array.from(actions.children).map((child) => Math.round(child.getBoundingClientRect().top));
    return {
      wrapped: new Set(tops).size > 1,
      pageWidth: document.documentElement.scrollWidth,
      viewport: document.documentElement.clientWidth,
    };
  });
  expect(geometry.wrapped).toBeTruthy();
  expect(geometry.pageWidth).toBeLessThanOrEqual(geometry.viewport + 1);
  await expect(page.getByRole('link', { name: 'Bereitstellen' })).toBeVisible();
});

test('blocker endpoint has JSON method, 401 and 403 contracts', async ({ playwright }, testInfo) => {
  const request = await playwright.request.newContext({
    baseURL: testInfo.project.use.baseURL,
    storageState: { cookies: [], origins: [] },
  });
  const response = await request.get('deploy_blockers.php?mission_id=1');
  expect(response.status()).toBe(401);
  expect(response.headers()['content-type']).toContain('application/json');
  expect((await response.json()).ok).toBeFalsy();
  const method = await request.post('deploy_blockers.php');
  expect(method.status()).toBe(405);
  expect(method.headers()['content-type']).toContain('application/json');
  expect(method.headers().allow).toBe('GET');
  await request.dispose();

  runPhp("db()->query(\"UPDATE deploy_users SET must_change_password = 1 WHERE name = 'e2e_user'\"); echo 'LOCKED';");
  try {
    const forbidden = await playwright.request.newContext({ baseURL: testInfo.project.use.baseURL, storageState: ROLES.user.storageState });
    const forbiddenResponse = await forbidden.get('deploy_blockers.php?mission_id=1');
    expect(forbiddenResponse.status()).toBe(403);
    expect(forbiddenResponse.headers()['content-type']).toContain('application/json');
    expect((await forbiddenResponse.json()).message).toEqual(expect.any(String));
    await forbidden.dispose();
  } finally {
    runPhp("db()->query(\"UPDATE deploy_users SET must_change_password = 0 WHERE name = 'e2e_user'\"); echo 'UNLOCKED';");
  }
});

test('successful blocker reads create no persisted audit or error event', async ({ request }) => {
  const ids = seed();
  const countLogs = () => Number(phpJson("echo 'JSON' . json_encode(['count' => (int) db()->query('SELECT COUNT(*) AS c FROM deploy_logs')->fetch_assoc()['c']]) . 'JSON';").count);
  const before = countLogs();
  const response = await request.get(`deploy_blockers.php?mission_id=${ids.mission}&credential_esxi_id=${ids.esxi}&credential_ansible_id=${ids.ansible}`);
  expect(response.status()).toBe(200);
  expect((await response.json()).ok).toBeTruthy();
  expect(countLogs()).toBe(before);
});

test('backend blocker recheck refuses a forged enabled submit before any write', async ({ page }) => {
  const ids = seed();
  await page.goto(`deploy.php?mission_id=${ids.mission}&lang=de`);
  const form = page.locator('form:has([data-deploy-mission])');
  await form.evaluate((element) => {
    element.querySelectorAll('[required]').forEach((control) => control.removeAttribute('required'));
    element.querySelector('[data-deploy-queue-button]').disabled = false;
  });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    form.locator('[data-deploy-queue-button]').click(),
  ]);
  await expect(page.getByText('Wählen Sie einen ESXi-Zugang aus.').first()).toBeVisible();
  const jobs = phpJson(`
$id = ${Number(ids.mission)};
$stmt = db()->prepare('SELECT COUNT(*) AS count FROM deploy_jobs WHERE mission_id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc()) . 'JSON';
`);
  expect(Number(jobs.count)).toBe(0);
});

test('server recheck queues a valid job with JavaScript disabled', async ({ browser }, testInfo) => {
  const ids = seed();
  const scheduledAt = localDatetimeAfter(26);
  const query = new URLSearchParams({
    mission_id: String(ids.mission),
    credential_esxi_id: String(ids.esxi),
    credential_ansible_id: String(ids.ansible),
    start_mode: 'scheduled',
    scheduled_at: scheduledAt,
    lang: 'de',
  });
  const context = await browser.newContext({
    baseURL: testInfo.project.use.baseURL,
    storageState: ROLES.admin.storageState,
    javaScriptEnabled: false,
  });
  const page = await context.newPage();
  await page.goto(`deploy.php?${query.toString()}`);
  await expect(page.locator('[data-deploy-queue-button]')).toBeEnabled();
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.locator('[data-deploy-queue-button]').click(),
  ]);
  await expect(page.getByRole('heading', { name: 'Zeitplan-Vorschau' })).toBeVisible();
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.getByRole('button', { name: 'Einreihen', exact: true }).click(),
  ]);
  const jobs = phpJson(`
$id = ${Number(ids.mission)};
$stmt = db()->prepare('SELECT id, status, scheduled_at FROM deploy_jobs WHERE mission_id = ? ORDER BY id');
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC)) . 'JSON';
`);
  expect(jobs).toHaveLength(1);
  expect(jobs[0].status).toBe('queued');
  expect(jobs[0].scheduled_at).toBeTruthy();
  await context.close();
});
