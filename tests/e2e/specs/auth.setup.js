// Auth setup project (ADR-0028): seed the plain-user account, then log in once
// per role and persist storageState for the specs to reuse. Runs before the
// chromium project via the config's project dependency. lib/test.js renews the
// logins per browser project; the seeding happens only here.

const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const { ROLES, AUTH_DIR, signIn } = require('../lib/auth');
const { runPhp } = require('../lib/php');

fs.mkdirSync(AUTH_DIR, { recursive: true });

/**
 * Seeds (or resets) the e2e_user account with the 'user' role through the PHP
 * container, so the hash is produced by the app's own password_hash and the
 * account matches what the portal expects. Idempotent: deletes any prior row
 * first. Uses a heredoc so no secret rides on the command line.
 */
function seedUser(role) {
  // runPhp prepends the <?php tag and the bootstrap require.
  const out = runPhp(`
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
`, [], { timeout: 15000 });
  if (!out.includes('seeded')) {
    throw new Error('user seed failed: ' + out);
  }
}

function phpString(value) {
  return "'" + String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}

test('seed and authenticate the user role', async ({ page }) => {
  seedUser(ROLES.user);
  await signIn(page, ROLES.user);
  // The dashboard proves the session is real, not just a 302 target.
  await expect(page).toHaveURL(/dashboard\.php/);
  await page.context().storageState({ path: ROLES.user.storageState });
});

test('authenticate the admin role', async ({ page }) => {
  await signIn(page, ROLES.admin);
  await expect(page).toHaveURL(/dashboard\.php/);
  await page.context().storageState({ path: ROLES.admin.storageState });
});
