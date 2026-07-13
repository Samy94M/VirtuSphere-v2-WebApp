// Auth setup project (ADR-0028): seed the plain-user account, then log in once
// per role and persist storageState for the specs to reuse. Runs before the
// chromium project via the config's project dependency.

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const { ROLES, AUTH_DIR } = require('../lib/auth');

const PHP_CONTAINER = process.env.VIRTUSPHERE_PHP_CONTAINER || 'virtusphere-v2-webapp-php-1';

fs.mkdirSync(AUTH_DIR, { recursive: true });

/**
 * Seeds (or resets) the e2e_user account with the 'user' role through the PHP
 * container, so the hash is produced by the app's own password_hash and the
 * account matches what the portal expects. Idempotent: deletes any prior row
 * first. Uses a heredoc so no secret rides on the command line.
 */
function seedUser(role) {
  // The opening tag is load-bearing: `php` reading a script from stdin treats
  // input without <?php as literal text and echoes it instead of running it.
  const php = `<?php
require_once '/var/www/html/lib/bootstrap.php';
$db = db();
$name = ${phpString(role.username)};
$stmt = $db->prepare('DELETE FROM deploy_users WHERE name = ?');
$stmt->bind_param('s', $name);
$stmt->execute();
$pw = password_hash(${phpString(role.password)}, PASSWORD_DEFAULT);
$rrole = 'user';
$mail = '';
$stmt = $db->prepare('INSERT INTO deploy_users (name, password, email, role, is_active, must_change_password) VALUES (?, ?, ?, ?, 1, 0)');
$stmt->bind_param('ssss', $name, $pw, $mail, $rrole);
$stmt->execute();
echo 'seeded ' . $name;
`;
  const out = execFileSync('docker', ['exec', '-i', PHP_CONTAINER, 'php'], {
    input: php,
    encoding: 'utf8',
    timeout: 15000,
  });
  if (!out.includes('seeded')) {
    throw new Error('user seed failed: ' + out);
  }
}

function phpString(value) {
  return "'" + String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}

async function signIn(page, role) {
  await page.goto('login.php');
  await page.locator('#username').fill(role.username);
  await page.locator('#password').fill(role.password);
  await Promise.all([
    page.waitForURL(/dashboard\.php/, { timeout: 15000 }),
    page.locator('button[type="submit"]').click(),
  ]);
  // The dashboard proves the session is real, not just a 302 target.
  await expect(page).toHaveURL(/dashboard\.php/);
}

test('seed and authenticate the user role', async ({ page }) => {
  seedUser(ROLES.user);
  await signIn(page, ROLES.user);
  await page.context().storageState({ path: ROLES.user.storageState });
});

test('authenticate the admin role', async ({ page }) => {
  await signIn(page, ROLES.admin);
  await page.context().storageState({ path: ROLES.admin.storageState });
});
