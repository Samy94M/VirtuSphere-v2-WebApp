// TESTPLAN 3.5 / E6: the HTTPS admin flow (ADR-0027) as one ordered sequence.
// Self-gated: it only runs against a stack that carries no HTTPS material and
// has HTTPS off (the throwaway QA stack always qualifies), and it removes the
// material it installed. The redirect toggle is deliberately proven only in
// its Cancel and refusal branches: actually enabling the redirect would move
// this very HTTP session to a TLS listener with a self-signed certificate and
// lock the remaining steps out.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const HTTPS_LIBS = ['lib/deploy_constants.php', 'lib/repo/settings.php', 'lib/https_config.php'];

function httpsState() {
  return phpJson(`
echo 'JSON' . json_encode([
    'material' => https_material_present(),
    'enabled' => repo_setting_value(db(), VIRTUSPHERE_SETTING_HTTPS_ENABLED, '0'),
    'redirect' => repo_setting_value(db(), VIRTUSPHERE_SETTING_HTTPS_REDIRECT_ENABLED, '0'),
    'hsts' => repo_setting_value(db(), VIRTUSPHERE_SETTING_HTTPS_HSTS_ENABLED, '0'),
    'fingerprint' => (https_installed_metadata() ?? ['fingerprint' => null])['fingerprint'],
]) . 'JSON';
`, HTTPS_LIBS);
}

function makeSelfSignedPair() {
  return phpJson(`
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => 'e2e-https.local'], $key, ['digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);
openssl_x509_export($cert, $certPem);
openssl_pkey_export($key, $keyPem);
echo 'JSON' . json_encode(['cert' => $certPem, 'key' => $keyPem]) . 'JSON';
`);
}

let ranSequence = false;

test.afterAll(() => {
  if (!ranSequence) {
    return;
  }
  // Leave the stack as found: no material, every toggle off, generated conf
  // withdrawn (https_apply_state removes it once the setting is off).
  runPhp(`
repo_set_setting(db(), VIRTUSPHERE_SETTING_HTTPS_ENABLED, '0');
repo_set_setting(db(), VIRTUSPHERE_SETTING_HTTPS_REDIRECT_ENABLED, '0');
repo_set_setting(db(), VIRTUSPHERE_SETTING_HTTPS_HSTS_ENABLED, '0');
https_apply_state(db());
@unlink(VIRTUSPHERE_HTTPS_SSL_DIR . '/server.crt');
@unlink(VIRTUSPHERE_HTTPS_SSL_DIR . '/server.key');
echo 'CLEANED';
`, HTTPS_LIBS);
});

// e2e-covers: settings.php:upload_https_cert
// e2e-covers-cancel: settings.php:upload_https_cert
// e2e-covers: settings.php:save_https_enabled
// e2e-covers-cancel: settings.php:save_https_enabled
// e2e-covers: settings.php:save_https_redirect
// e2e-covers-cancel: settings.php:save_https_redirect
// e2e-covers: settings.php:save_https_hsts
test('HTTPS flow: refusals without material, upload, overwrite guard, toggles, disable', async ({ page }) => {
  const initial = httpsState();
  test.skip(
    initial.material || initial.enabled === '1',
    'this stack already carries HTTPS material; the flow only runs on a pristine stack (QA stack)'
  );
  ranSequence = true;

  const dialog = page.locator('[data-confirm-dialog]');
  const openHttpsTab = async () => {
    await page.goto('settings.php#panel-https');
    await expect(page.locator('#panel-https')).toBeVisible();
  };
  const settingsForm = (action) => page.locator(`form:has(input[name="action"][value="${action}"])`);
  const submitAndFlash = async (form) => {
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('settings.php') && r.request().method() === 'POST'),
      form.locator('button[type="submit"]').click(),
    ]);
    await expect(page.locator('.alert-success, .alert-error').first()).toBeVisible();
  };

  // 1. Redirect: asks first (lockout risk); Cancel changes nothing, Confirm is
  //    refused while HTTPS is off. The successful enable is deliberately out of
  //    scope, see the header comment.
  await openHttpsTab();
  const redirectButton = settingsForm('save_https_redirect').locator('button[type="submit"]');
  await redirectButton.click();
  await expect(dialog, 'enabling the redirect asks first').toBeVisible();
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(httpsState().redirect, 'dismissing the dialog changed nothing').toBe('0');

  await redirectButton.click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('settings.php') && r.request().method() === 'POST'),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  await expect(page.locator('.alert-error').first(), 'the redirect requires HTTPS first').toBeVisible();
  expect(httpsState().redirect, 'the refusal wrote nothing').toBe('0');

  // 2. Enabling HTTPS without material is refused.
  await openHttpsTab();
  await submitAndFlash(settingsForm('save_https_enabled'));
  expect(httpsState().enabled, 'no material, no listener').toBe('0');

  // 3. A non-PEM upload is a sticky field error and installs nothing.
  await openHttpsTab();
  let uploadForm = settingsForm('upload_https_cert');
  await uploadForm.locator('input[name="cert_file"]').setInputFiles({
    name: 'not-a-cert.txt',
    mimeType: 'text/plain',
    buffer: Buffer.from('certainly not PEM'),
  });
  await submitAndFlash(uploadForm);
  expect(httpsState().material, 'the broken upload installed nothing').toBe(false);

  // 4. A valid self-signed pair installs and the metadata table names it.
  const pair = makeSelfSignedPair();
  await openHttpsTab();
  uploadForm = settingsForm('upload_https_cert');
  await uploadForm.locator('input[name="cert_file"]').setInputFiles({
    name: 'server.crt', mimeType: 'application/x-pem-file', buffer: Buffer.from(pair.cert),
  });
  await uploadForm.locator('input[name="key_file"]').setInputFiles({
    name: 'server.key', mimeType: 'application/x-pem-file', buffer: Buffer.from(pair.key),
  });
  await submitAndFlash(uploadForm);
  const installed = httpsState();
  expect(installed.material, 'the material is installed').toBe(true);
  await expect(page.locator('#panel-https'), 'the metadata names the subject').toContainText('e2e-https.local');

  // 5. Overwriting an installed certificate asks first; Cancel keeps it.
  await openHttpsTab();
  uploadForm = settingsForm('upload_https_cert');
  const secondPair = makeSelfSignedPair();
  await uploadForm.locator('input[name="cert_file"]').setInputFiles({
    name: 'server2.crt', mimeType: 'application/x-pem-file', buffer: Buffer.from(secondPair.cert),
  });
  await uploadForm.locator('input[name="key_file"]').setInputFiles({
    name: 'server2.key', mimeType: 'application/x-pem-file', buffer: Buffer.from(secondPair.key),
  });
  await uploadForm.locator('button[type="submit"]').click();
  await expect(dialog, 'overwriting an installed certificate asks first').toBeVisible();
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(httpsState().fingerprint, 'Cancel kept the installed certificate').toBe(installed.fingerprint);

  // 6. HSTS is the safe toggle: on, proven, off again.
  await openHttpsTab();
  await submitAndFlash(settingsForm('save_https_hsts'));
  expect(httpsState().hsts, 'HSTS is on').toBe('1');
  await openHttpsTab();
  await submitAndFlash(settingsForm('save_https_hsts'));
  expect(httpsState().hsts, 'HSTS is off again').toBe('0');

  // 7. With material present, enabling adds the listener (no dialog: enabling
  //    is the harmless branch).
  await openHttpsTab();
  await submitAndFlash(settingsForm('save_https_enabled'));
  expect(httpsState().enabled, 'HTTPS is enabled').toBe('1');

  // 8. Disabling drops every TLS session, so that branch asks; Cancel keeps it
  //    on, Confirm turns it off.
  await openHttpsTab();
  const disableButton = settingsForm('save_https_enabled').locator('button[type="submit"]');
  await disableButton.click();
  await expect(dialog, 'disabling HTTPS asks first').toBeVisible();
  await dialog.locator('button[value="cancel"]').click();
  await expect(dialog).toBeHidden();
  expect(httpsState().enabled, 'dismissing the dialog kept HTTPS on').toBe('1');

  await disableButton.click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('settings.php') && r.request().method() === 'POST'),
    dialog.locator('[data-confirm-accept]').click(),
  ]);
  await expect(page.locator('.alert-success').first()).toBeVisible();
  expect(httpsState().enabled, 'HTTPS is off again').toBe('0');
});
