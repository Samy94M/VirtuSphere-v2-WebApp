// Plan section 18.4 / E2eActionCoverageContractTest PENDING_ACTIONS: the
// browser slice for every users.php:directory_* action, unblocked now that
// the hermetic LDAP fixture (Docker/ldap-fixture, docker-compose.qa.yml)
// gives the QA stack a real LDAPS server to point the admin forms and the
// login form at. Skips the whole file when the fixture hostnames are not
// resolvable from the PHP container (not running inside the QA compose
// network), the same contract DirectoryLdapFixtureTest.php uses.
//
// One structural limit carries through from the hermetic fixture itself
// (Docker/WebAPI/tests/fixtures/ldap/README.md, plan section 18.3): the
// fixture's slapd does not serve an AD-shaped RootDSE (defaultNamingContext,
// dsServiceName/msDS-isRODC), so the admin "Verbindung testen" action can
// never succeed against it - that RootDSE-derived proof is real-AD-only
// (Gate 0B). Controller/config CRUD and the "test controller" *failure* path
// are driven through the real forms; the enabled+validated state a search,
// import or login test needs is seeded directly (repo_directory_*, the same
// functions the successful admin flow itself would have called), exactly
// like DirectoryLdapFixtureFailoverTest.php's PHPUnit-level fixture setup.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { submitAndWaitForNavigation } = require('../lib/navigation');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const DIRECTORY_LIBS = [
  'lib/crypto.php',
  'lib/directory_auth.php',
  'lib/directory_service.php',
  'lib/repo/directory.php',
  'lib/repo/directory_users.php',
];

const FIXTURE_HOST_DC1 = 'dc1.vs-ldap.test';
const FIXTURE_HOST_DC2 = 'dc2.vs-ldap.test';
const FIXTURE_PORT = 636;
const SERVICE_BIND_DN = 'cn=svc-bind,dc=vs-ldap,dc=test';
const SERVICE_BIND_PASSWORD = 'fixture-svc-Pass123!';
const USER_SEARCH_BASE = 'ou=people,dc=vs-ldap,dc=test';
const ALICE_UPN = 'alice@vs-ldap.test';
const ALICE_PASSWORD = 'fixture-alice-Pass123!';

function fixtureAvailable() {
  return phpJson('echo \'JSON\' . json_encode(gethostbyname(\'' + FIXTURE_HOST_DC1 + '\') !== \'' + FIXTURE_HOST_DC1 + '\') . \'JSON\';');
}

function cleanupDirectory() {
  runPhp(`
db()->query('DELETE FROM deploy_ad_config WHERE id = 1');
$source = VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY;
$stmt = db()->prepare('DELETE FROM deploy_users WHERE auth_source = ?');
$stmt->bind_param('s', $source);
$stmt->execute();
echo 'CLEANED';
`, DIRECTORY_LIBS);
}

/** Draft config (AD disabled) pointed at the hermetic fixture's trusted root. */
function seedDraftConfig() {
  return phpJson(`
$ca = file_get_contents('/var/www/html/tests/fixtures/ldap/root-a.crt.txt');
$cipher = crypto_encrypt_secret('${SERVICE_BIND_PASSWORD}');
$stmt = db()->prepare('INSERT INTO deploy_ad_config (id, enabled, revision, user_search_base_dn, bind_upn, bind_secret_ciphertext, ca_certificate_pem, created_by, updated_by) VALUES (1, 0, 1, ?, ?, ?, ?, 1, 1)');
$base = '${USER_SEARCH_BASE}';
$upn = '${SERVICE_BIND_DN}';
$stmt->bind_param('ssss', $base, $upn, $cipher, $ca);
$stmt->execute();
echo 'JSON' . json_encode(['revision' => 1]) . 'JSON';
`, DIRECTORY_LIBS);
}

/** Marks a controller validated for the given revision without a real RootDSE test (Gate 0B boundary, see file header). */
function seedValidatedController(host, revision) {
  return phpJson(`
$stmt = db()->prepare('INSERT INTO deploy_ad_controllers (config_id, host, port, priority, enabled, validated_revision, validated_at, created_by, updated_by) SELECT 1, ?, ${FIXTURE_PORT}, COALESCE(MAX(priority), 0) + 1, 1, ${Number(revision)}, NOW(), 1, 1 FROM deploy_ad_controllers WHERE config_id = 1');
$host = '${host}';
$stmt->bind_param('s', $host);
$stmt->execute();
echo 'JSON' . json_encode(['id' => db()->insert_id]) . 'JSON';
`, DIRECTORY_LIBS);
}

function enableDirectory() {
  runPhp(`db()->query('UPDATE deploy_ad_config SET enabled = 1 WHERE id = 1');echo 'OK';`, DIRECTORY_LIBS);
}

function configRow() {
  return phpJson(`echo 'JSON' . json_encode(repo_directory_config(db())) . 'JSON';`, DIRECTORY_LIBS);
}

function controllerRows() {
  return phpJson(`echo 'JSON' . json_encode(repo_directory_controllers(db())) . 'JSON';`, DIRECTORY_LIBS);
}

function aliceUserRow() {
  return phpJson(`
$source = VIRTUSPHERE_AUTH_SOURCE_ACTIVE_DIRECTORY;
$stmt = db()->prepare('SELECT id, name, is_active, role FROM deploy_users WHERE auth_source = ? AND ad_upn = ? LIMIT 1');
$upn = '${ALICE_UPN}';
$stmt->bind_param('ss', $source, $upn);
$stmt->execute();
echo 'JSON' . json_encode($stmt->get_result()->fetch_assoc() ?: null) . 'JSON';
`, DIRECTORY_LIBS);
}

let fixtureUp = false;

test.beforeAll(() => {
  fixtureUp = fixtureAvailable();
});

test.beforeEach(() => {
  test.skip(!fixtureUp, 'LDAP fixture hostnames not resolvable from the PHP container (not the QA compose network)');
});

test.afterEach(() => cleanupDirectory());

const actionUrl = () => 'users.php?view=directory';
const directoryForm = (page, action) => page.locator(`form:has(input[name="action"][value="${action}"])`);

test.describe('Active Directory setup', () => {
  // e2e-covers: users.php:directory_save_config
  test('setup blockers name what is missing, and saving a draft config while disabled needs no controller test', async ({ page }) => {
    await page.goto(actionUrl());
    // Locale-agnostic: the blockers list names HTTPS as the first missing
    // prerequisite (directory_activation_blockers()) in both DE and EN.
    await expect(page.locator('#directory-config .alert-warning')).toContainText('HTTPS');

    const form = directoryForm(page, 'directory_save_config');
    // directory_config_candidate() requires an @-shaped bind_upn
    // (directory_upn_is_valid()); a real DN like SERVICE_BIND_DN only binds
    // once an actual LDAP contact happens, which a disabled draft save never
    // makes (no existing config yet -> directory_test_candidate() is skipped).
    await form.locator('input[name="bind_upn"]').fill('svc-bind@vs-ldap.test');
    await form.locator('input[name="bind_password"]').fill(SERVICE_BIND_PASSWORD);
    await form.locator('textarea[name="ca_certificate_pem"]').fill(
      phpJson(`echo 'JSON' . json_encode(file_get_contents('/var/www/html/tests/fixtures/ldap/root-a.crt.txt')) . 'JSON';`)
    );
    await form.locator('input[name="user_search_base_dn"]').fill(USER_SEARCH_BASE);
    await submitAndWaitForNavigation(page, form.locator('button[type="submit"]'), 'users.php');
    await expect(page.locator('.alert-success').first()).toBeVisible();

    const saved = configRow();
    expect(saved, 'the draft config exists').not.toBeNull();
    expect(Number(saved.enabled), 'a draft config stays disabled').toBe(0);
  });
});

test.describe('Controller administration', () => {
  test.beforeEach(() => {
    seedDraftConfig();
  });

  // e2e-covers: users.php:directory_add_controller
  // e2e-covers: users.php:directory_move_controller
  // e2e-covers: users.php:directory_delete_controller
  // e2e-covers-cancel: users.php:directory_delete_controller
  test('add, reprioritize and delete controllers', async ({ page }) => {
    await page.goto(actionUrl());

    const addForm = directoryForm(page, 'directory_add_controller');
    await addForm.locator('input[name="host"]').fill(FIXTURE_HOST_DC1);
    await submitAndWaitForNavigation(page, addForm.locator('button[type="submit"]'), 'users.php');
    await expect(page.locator('.alert-success').first()).toBeVisible();

    await directoryForm(page, 'directory_add_controller').locator('input[name="host"]').fill(FIXTURE_HOST_DC2);
    await submitAndWaitForNavigation(page, directoryForm(page, 'directory_add_controller').locator('button[type="submit"]'), 'users.php');

    let rows = controllerRows();
    expect(rows.map((r) => r.host), 'both controllers were added').toEqual(
      expect.arrayContaining([FIXTURE_HOST_DC1, FIXTURE_HOST_DC2])
    );
    const first = rows.find((r) => r.host === FIXTURE_HOST_DC1);
    expect(Number(first.priority)).toBe(1);

    // Move the first controller down: priorities swap.
    const row = page.locator('tr', { hasText: FIXTURE_HOST_DC1 });
    await submitAndWaitForNavigation(page, row.locator('button[name="direction"][value="down"]'), 'users.php');
    rows = controllerRows();
    expect(Number(rows.find((r) => r.host === FIXTURE_HOST_DC1).priority)).toBe(2);
    expect(Number(rows.find((r) => r.host === FIXTURE_HOST_DC2).priority)).toBe(1);

    // Delete confirms and names the host; Cancel changes nothing.
    await page.goto(actionUrl());
    const dialog = page.locator('[data-confirm-dialog]');
    const deleteButton = page.locator('tr', { hasText: FIXTURE_HOST_DC2 }).locator('form:has(input[name="action"][value="directory_delete_controller"]) button');
    await deleteButton.click();
    await expect(dialog).toBeVisible();
    await expect(page.locator('[data-confirm-msg]')).toContainText(FIXTURE_HOST_DC2);
    await dialog.locator('button[value="cancel"]').click();
    await expect(dialog).toBeHidden();
    expect(controllerRows().map((r) => r.host), 'Cancel kept both controllers').toEqual(
      expect.arrayContaining([FIXTURE_HOST_DC1, FIXTURE_HOST_DC2])
    );

    await deleteButton.click();
    await expect(dialog).toBeVisible();
    await submitAndWaitForNavigation(page, dialog.locator('[data-confirm-accept]'), 'users.php');
    expect(controllerRows().map((r) => r.host), 'Confirm deleted dc2').toEqual([FIXTURE_HOST_DC1]);
  });

  // e2e-covers: users.php:directory_test_controller
  test('testing a controller against the hermetic fixture fails honestly (no AD RootDSE, see file header) instead of a false green', async ({ page }) => {
    await page.goto(actionUrl());
    const addForm = directoryForm(page, 'directory_add_controller');
    await addForm.locator('input[name="host"]').fill(FIXTURE_HOST_DC1);
    await submitAndWaitForNavigation(page, addForm.locator('button[type="submit"]'), 'users.php');

    await page.goto(actionUrl());
    const testForm = page.locator('tr', { hasText: FIXTURE_HOST_DC1 }).locator('form:has(input[name="action"][value="directory_test_controller"])');
    await submitAndWaitForNavigation(page, testForm.locator('button[type="submit"]'), 'users.php');
    await expect(page.locator('.alert-error').first()).toBeVisible();
    expect(controllerRows()[0].validated_revision, 'a failed test never validates the controller').toBeNull();
  });

  // e2e-covers: users.php:directory_set_controller_enabled
  test('activating an unvalidated controller is refused', async ({ page }) => {
    await page.goto(actionUrl());
    const addForm = directoryForm(page, 'directory_add_controller');
    await addForm.locator('input[name="host"]').fill(FIXTURE_HOST_DC1);
    await submitAndWaitForNavigation(page, addForm.locator('button[type="submit"]'), 'users.php');

    await page.goto(actionUrl());
    const activateForm = page.locator('tr', { hasText: FIXTURE_HOST_DC1 }).locator('form:has(input[name="action"][value="directory_set_controller_enabled"])');
    await expect(activateForm.locator('button'), 'activating is the harmless-looking branch, no confirm').not.toHaveAttribute('data-confirm', /./);
    await submitAndWaitForNavigation(page, activateForm.locator('button'), 'users.php');
    await expect(page.locator('.alert-error').first()).toBeVisible();
    expect(Number(controllerRows()[0].enabled), 'refused: still disabled').toBe(0);
  });
});

test.describe('Search, import and sync against an enabled, seeded configuration', () => {
  let revision;

  test.beforeEach(() => {
    revision = seedDraftConfig().revision;
    seedValidatedController(FIXTURE_HOST_DC1, revision);
    enableDirectory();
  });

  // e2e-covers: users.php:directory_search
  // e2e-covers: users.php:directory_import
  test('search finds alice on the fixture, and import creates a ready AD account', async ({ page }) => {
    await page.goto(actionUrl());
    const searchForm = directoryForm(page, 'directory_search');
    await searchForm.locator('input[name="directory_search"]').fill('alice');
    await submitAndWaitForNavigation(page, searchForm.locator('button[type="submit"]'), 'users.php');
    await expect(page.locator('.alert-success, .alert-warning').first()).toBeVisible();
    await expect(page.locator('#directory-search')).toContainText('alice@vs-ldap.test');

    const importForm = page.locator('#directory-search tr', { hasText: 'alice@vs-ldap.test' }).locator('form:has(input[name="action"][value="directory_import"])');
    await submitAndWaitForNavigation(page, importForm.locator('button[type="submit"]'), 'users.php');
    await expect(page.locator('.alert-success').first()).toBeVisible();

    const imported = aliceUserRow();
    expect(imported, 'alice now has a portal row').not.toBeNull();
    expect(Number(imported.is_active)).toBe(1);
  });

  // e2e-covers: users.php:directory_sync_user
  test('an imported AD account shows the source badge, has no password reset action, and can be synced', async ({ page }) => {
    runPhp(`
require_once '/var/www/html/lib/directory_service.php';
require_once '/var/www/html/lib/repo/directory_users.php';
$entry = directory_find_user_by_upn(db(), '${ALICE_UPN}');
repo_directory_import_user(db(), $entry, VIRTUSPHERE_ROLE_USER);
echo 'OK';
`, DIRECTORY_LIBS, { user: 'www-data' });
    const alice = aliceUserRow();

    await page.goto('users.php');
    const row = page.locator('tr', { hasText: alice.name });
    await expect(row).toContainText('Active Directory');
    await expect(row.locator('input[name="password"]'), 'no password reset field on an AD row').toHaveCount(0);

    const syncForm = row.locator('form:has(input[name="action"][value="directory_sync_user"])');
    await submitAndWaitForNavigation(page, syncForm.locator('button[type="submit"]'), 'users.php');
    await expect(page.locator('.alert-success').first()).toBeVisible();
  });

  // e2e-covers-cancel: users.php:directory_set_controller_enabled
  test('deactivating a validated controller asks first, Cancel keeps it enabled', async ({ page }) => {
    // repo_directory_change_controller_enabled() refuses to deactivate the
    // last usable controller while AD is active (guards the same invariant
    // as the last-local-admin check); a second validated controller is what
    // makes the disable branch reachable at all.
    seedValidatedController(FIXTURE_HOST_DC2, revision);

    await page.goto(actionUrl());
    const dialog = page.locator('[data-confirm-dialog]');
    const toggle = page.locator('tr', { hasText: FIXTURE_HOST_DC1 }).locator('form:has(input[name="action"][value="directory_set_controller_enabled"]) button');
    await expect(toggle, 'the enabled controller shows the deactivate branch').toHaveAttribute('data-confirm', /./);

    await toggle.click();
    await expect(dialog).toBeVisible();
    await expect(page.locator('[data-confirm-msg]')).toContainText(FIXTURE_HOST_DC1);
    await dialog.locator('button[value="cancel"]').click();
    await expect(dialog).toBeHidden();
    expect(Number(controllerRows().find((r) => r.host === FIXTURE_HOST_DC1).enabled), 'Cancel left the controller enabled').toBe(1);

    await toggle.click();
    await expect(dialog).toBeVisible();
    await submitAndWaitForNavigation(page, dialog.locator('[data-confirm-accept]'), 'users.php');
    expect(Number(controllerRows().find((r) => r.host === FIXTURE_HOST_DC1).enabled), 'Confirm deactivated it').toBe(0);
  });

  // e2e-covers: users.php:directory_set_enabled
  // e2e-covers-cancel: users.php:directory_set_enabled
  test('disabling Active Directory asks first, Cancel leaves it active', async ({ page }) => {
    await page.goto(actionUrl());
    const dialog = page.locator('[data-confirm-dialog]');
    const toggle = directoryForm(page, 'directory_set_enabled').locator('button[type="submit"]');
    await expect(toggle, 'the disable branch confirms').toHaveAttribute('data-confirm', /./);

    await toggle.click();
    await expect(dialog).toBeVisible();
    await dialog.locator('button[value="cancel"]').click();
    await expect(dialog).toBeHidden();
    expect(Number(configRow().enabled), 'Cancel left AD active').toBe(1);

    await toggle.click();
    await expect(dialog).toBeVisible();
    await submitAndWaitForNavigation(page, dialog.locator('[data-confirm-accept]'), 'users.php');
    expect(Number(configRow().enabled), 'Confirm disabled AD').toBe(0);
  });

  // e2e-covers: users.php:directory_delete_config
  // e2e-covers-cancel: users.php:directory_delete_config
  test('deleting the configuration asks first, Cancel keeps it, Confirm removes it', async ({ page }) => {
    await page.goto(actionUrl());
    const dialog = page.locator('[data-confirm-dialog]');
    const deleteButton = directoryForm(page, 'directory_delete_config').locator('button[type="submit"]');
    await expect(deleteButton).toHaveAttribute('data-confirm', /./);

    await deleteButton.click();
    await expect(dialog).toBeVisible();
    await dialog.locator('button[value="cancel"]').click();
    await expect(dialog).toBeHidden();
    expect(configRow(), 'Cancel kept the configuration').not.toBeNull();

    await deleteButton.click();
    await expect(dialog).toBeVisible();
    await submitAndWaitForNavigation(page, dialog.locator('[data-confirm-accept]'), 'users.php');
    expect(configRow(), 'Confirm removed the configuration').toBeNull();
  });
});

test.describe('AD sign-in, own session, over HTTPS', () => {
  // The plan (section 1) requires HTTPS for AD sign-in by contract, so this is
  // the one spec in the suite that actually loads an https:// URL: every other
  // spec deliberately stays on the shared http baseURL (see https-flow.spec.js's
  // header). HTTPS material/enable is installed directly (https_write_material,
  // VIRTUSPHERE_SETTING_HTTPS_ENABLED): the upload/toggle UI itself is
  // https-flow.spec.js's job, not this file's. The redirect setting is left
  // off on purpose so the shared http baseURL keeps serving every other spec.
  test.use({ storageState: { cookies: [], origins: [] }, ignoreHTTPSErrors: true });

  const HTTPS_LIBS = ['lib/https_config.php', 'lib/repo/settings.php'];
  let httpsBaseUrl;

  test.beforeAll(() => {
    const url = new URL(process.env.VIRTUSPHERE_BASE_URL || 'http://127.0.0.1:8031/portal/');
    httpsBaseUrl = `https://${url.hostname}:8032/portal/`;
  });

  test.beforeEach(async ({ request }) => {
    const revision = seedDraftConfig().revision;
    seedValidatedController(FIXTURE_HOST_DC1, revision);
    enableDirectory();
    runPhp(`
require_once '/var/www/html/lib/directory_service.php';
require_once '/var/www/html/lib/repo/directory_users.php';
$entry = directory_find_user_by_upn(db(), '${ALICE_UPN}');
repo_directory_import_user(db(), $entry, VIRTUSPHERE_ROLE_USER);
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => 'e2e-ad-https.local'], $key, ['digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);
openssl_x509_export($cert, $certPem);
openssl_pkey_export($key, $keyPem);
https_write_material($certPem, '', $keyPem);
repo_set_setting(db(), VIRTUSPHERE_SETTING_HTTPS_ENABLED, '1');
https_apply_state(db());
echo 'OK';
`, [...DIRECTORY_LIBS, ...HTTPS_LIBS], { user: 'www-data' });
    // Docker/nginx/init.sh's watcher polls the generated conf/material every
    // 5s and only then reloads nginx; https_apply_state() above only writes
    // the files (https_listener_live() would go true immediately from that
    // alone). Poll the real port so the tests below never race the reload.
    await expect
      .poll(
        async () => {
          try {
            const response = await request.get(httpsBaseUrl + 'health.php', { ignoreHTTPSErrors: true, timeout: 3000 });
            return response.status();
          } catch {
            return 0;
          }
        },
        { message: 'nginx never picked up the generated HTTPS listener', timeout: 20000, intervals: [500] }
      )
      .toBe(200);
  });

  test.afterEach(() => {
    runPhp(`
repo_set_setting(db(), VIRTUSPHERE_SETTING_HTTPS_ENABLED, '0');
https_apply_state(db());
@unlink(VIRTUSPHERE_HTTPS_SSL_DIR . '/server.crt');
@unlink(VIRTUSPHERE_HTTPS_SSL_DIR . '/server.key');
echo 'OK';
`, HTTPS_LIBS);
  });

  function csrfFrom(html) {
    const match = html.match(/name="_csrf" value="([^"]+)"/);
    if (!match) {
      throw new Error('login form carried no _csrf field');
    }
    return match[1];
  }

  test('AD sign-in is offered only with a source choice, succeeds with LDAP credentials, and never falls back silently', async ({ page }) => {
    await page.goto(httpsBaseUrl + 'login.php');
    const sourceSelect = page.locator('#auth_source');
    await expect(sourceSelect, 'HTTPS + AD enabled shows the explicit source choice').toBeVisible();
    await expect(sourceSelect.locator('option')).toHaveCount(2);

    await sourceSelect.selectOption('active_directory');
    await page.locator('#username').fill(ALICE_UPN);
    await page.locator('#password').fill(ALICE_PASSWORD);
    await Promise.all([page.waitForLoadState('load'), page.locator('button[type="submit"]').click()]);
    await expect(page).toHaveURL(/account\.php|dashboard\.php/);
  });

  test('a wrong AD password is rejected without a local fallback, and local source rejects the LDAP-only account', async ({ page, request }) => {
    await page.goto(httpsBaseUrl + 'login.php');
    await page.locator('#auth_source').selectOption('active_directory');
    await page.locator('#username').fill(ALICE_UPN);
    await page.locator('#password').fill('definitely-not-the-real-password');
    await Promise.all([page.waitForLoadState('load'), page.locator('button[type="submit"]').click()]);
    await expect(page.locator('.alert-error').first()).toBeVisible();
    await expect(page).toHaveURL(/login\.php/);

    // A manipulated POST that names the local source for an AD-only account
    // must fail exactly like an unknown user, never fall back to comparing
    // against a local hash the account does not have.
    const html = await page.content();
    const csrf = csrfFrom(html);
    const response = await request.post(httpsBaseUrl + 'login.php', {
      form: { _csrf: csrf, username: ALICE_UPN, password: ALICE_PASSWORD, auth_source: 'local' },
      ignoreHTTPSErrors: true,
      maxRedirects: 0,
    });
    expect(response.status(), 'a manipulated local-source POST for an AD-only account is rejected exactly like an unknown user, not a server error').toBe(302);
    expect(response.headers()['location'], 'rejected back to the login form, not signed in').toContain('login.php');
  });
});

test.describe('Status/log deep links, DE/EN, wrap geometry', () => {
  test.beforeEach(() => {
    const revision = seedDraftConfig().revision;
    seedValidatedController(FIXTURE_HOST_DC1, revision);
    enableDirectory();
  });

  test('the System status AD card links to the admin view and the filtered security log', async ({ page }) => {
    await page.goto('system_status.php');
    const card = page.locator('#directory-status');
    await expect(card).toBeVisible();

    await card.getByRole('link', { name: /Active Directory/i }).click();
    await expect(page).toHaveURL(/users\.php\?view=directory/);

    await page.goto('system_status.php');
    await card.locator('a[href*="logs.php"]').click();
    await expect(page).toHaveURL(/logs\.php\?tab=security&category=directory/);
  });

  test('DE and EN both render the AD admin view and its help legend without a missing-key marker', async ({ page }) => {
    for (const lang of ['de', 'en']) {
      await page.goto(`users.php?view=directory&lang=${lang}`);
      await expect(page.locator('#directory-config h2')).toBeVisible();
      const bodyText = await page.locator('#directory-config').innerText();
      expect(bodyText, `${lang}: a raw catalog key leaked into the page`).not.toMatch(/directory\.[a-z_]+/);

      // help.php renders every tab panel into the DOM up front and toggles
      // visibility client-side (no server-side ?tab=), so the directory
      // legend's markup and translated text are checked in the raw response.
      await page.goto(`help.php?lang=${lang}`);
      const helpHtml = await page.content();
      expect(helpHtml, `${lang}: the directory legend panel is missing`).toContain('panel-system-status');
      expect(helpHtml, `${lang}: a raw system_status.directory_legend_* key leaked into help.php`).not.toMatch(/system_status\.directory_legend_[a-z]+/);
    }
  });

  test('the controller table stays inside its wrapper at a narrow mobile width', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto(actionUrl());
    await expect(page.locator('#directory-controllers .table-wrap')).toBeVisible();

    const hasHorizontalOverflow = await page.evaluate(() =>
      document.documentElement.scrollWidth > document.documentElement.clientWidth
    );
    expect(hasHorizontalOverflow, 'the controllers table scrolls inside table-wrap instead of widening the page').toBe(false);
  });
});
