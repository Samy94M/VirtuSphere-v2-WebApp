// TESTPLAN 3.3, negative cases: deleting a referenced object must be refused in
// the operator's language, without a 500 and without a partial cascade. The
// portal renders Delete for every credential, including one an active deploy job
// still holds, so this rejection is one click away and has to read like a
// sentence, not like a stack trace.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { execFileSync } = require('node:child_process');

test.use({ storageState: ROLES.admin.storageState });

const PHP_CONTAINER = process.env.VIRTUSPHERE_PHP_CONTAINER || 'virtusphere-v2-webapp-php-1';
const MARK = 'e2eneg';

function runPhp(body) {
  const php =
    '<?php\n' +
    'require_once "/var/www/html/lib/bootstrap.php";\n' +
    'require_once "/var/www/html/lib/repo/credentials.php";\n' +
    body;
  return execFileSync('docker', ['exec', '-i', PHP_CONTAINER, 'php'], {
    input: php,
    encoding: 'utf8',
    timeout: 15000,
  });
}

/**
 * A credential pair plus a job that holds them. The job is seeded as `running`
 * with a fresh heartbeat rather than `queued`: the real worker polls this
 * database, and it would claim a queued job and finish it mid-test, releasing
 * the very reference the test is about to assert on.
 */
function seedCredentialInUse() {
  const out = runPhp(`
$db = db();
$db->query("DELETE FROM deploy_jobs WHERE locked_by = '${MARK}'");
$db->query("DELETE FROM deploy_credentials WHERE name LIKE '${MARK}%'");
$admin = (int) ($db->query("SELECT id FROM deploy_users WHERE role='admin' LIMIT 1")->fetch_assoc()['id'] ?? 1);
$esxi = repo_create_credential($db, ['type' => 'esxi', 'name' => '${MARK}-esxi', 'host' => '10.0.0.9', 'port' => 443, 'username' => 'root'], 'secret123', $admin);
$ans = repo_create_credential($db, ['type' => 'ansible', 'name' => '${MARK}-ans', 'host' => '10.0.0.8', 'port' => 22, 'username' => 'ans'], 'secret123', $admin);
$stmt = $db->prepare("INSERT INTO deploy_jobs (mission_id, user_id, status, credential_esxi_id, credential_ansible_id, locked_by, heartbeat_at) VALUES (NULL, NULL, 'running', ?, ?, '${MARK}', NOW())");
$stmt->bind_param('ii', $esxi, $ans);
$stmt->execute();
echo 'ESXI=' . $esxi;
`);
  const m = out.match(/ESXI=(\d+)/);
  return m ? Number(m[1]) : 0;
}

function credentialExists(id) {
  const out = runPhp(`
$db = db();
$stmt = $db->prepare('SELECT COUNT(*) AS c FROM deploy_credentials WHERE id = ?');
$id = ${Number(id)};
$stmt->bind_param('i', $id);
$stmt->execute();
echo 'C=' . (int) $stmt->get_result()->fetch_assoc()['c'];
`);
  return /C=1/.test(out);
}

function cleanup() {
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_jobs WHERE locked_by = '${MARK}'");
$db->query("DELETE FROM deploy_credentials WHERE name LIKE '${MARK}%'");
echo 'CLEANED';
`);
}

test.beforeAll(() => cleanup());
test.afterAll(() => cleanup());

test('deleting a credential held by an active job is refused, localized and without a 500', async ({ page }) => {
  const esxiId = seedCredentialInUse();
  expect(esxiId, 'the credential was seeded').toBeGreaterThan(0);

  await page.goto('credentials.php');
  const row = page.locator('tr', { hasText: `${MARK}-esxi` });
  await expect(row, 'the seeded credential is listed').toHaveCount(1);

  const dialog = page.locator('[data-confirm-dialog]');
  await row.locator('button.button-danger').click();
  await expect(dialog, 'the confirm dialog opens').toBeVisible();

  const response = await Promise.all([
    page.waitForResponse((r) => r.url().includes('credentials.php') && r.request().method() === 'POST'),
    dialog.locator('[data-confirm-accept]').click(),
  ]).then(([r]) => r);

  // No server error: the guard is a refusal, not a crash.
  expect(response.status(), 'the refusal is not a 500').toBeLessThan(500);

  // The credential survives: refused, not half-deleted.
  expect(credentialExists(esxiId), 'the credential was not deleted').toBe(true);

  // And the operator gets a sentence, not the raw English diagnostic.
  const alert = page.locator('.alert-error, .alert').first();
  await expect(alert, 'a refusal is shown').toBeVisible();
  const text = (await alert.textContent()).trim();
  expect(text, 'the refusal is not the raw RuntimeException text')
    .not.toBe('Credential is used by an active deploy job.');
  expect(text, 'the refusal explains the deploy job').toMatch(/deploy|Bereitstellung/i);
});
