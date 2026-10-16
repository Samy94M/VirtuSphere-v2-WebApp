// Version tab (plan docs/audits/2026-10-15-versioning-plan.md, section 4.5).
// The tab is read-only: no form and no setting, so this spec only proves that
// its deep link opens it, that each row answers the release question, and that
// the help section explains it. It writes nothing.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');

test.use({ storageState: ROLES.admin.storageState });

// A row is identified by its component label, the first cell of the row.
function rowFor(panel, label) {
  return panel.locator('th', { hasText: label }).locator('xpath=..');
}

test('Version tab opens by its deep link and answers the release question per component', async ({ page }) => {
  await page.goto('settings.php#panel-version');
  const panel = page.locator('#panel-version');
  await expect(panel).toBeVisible();

  // Portal: the reference row shows the release number of this checkout.
  const portal = rowFor(panel, /^Portal$/);
  await expect(portal).toHaveCount(1);
  await expect(portal.locator('td').first()).toContainText(/\b\d{4}\.\d{2}\.\d{1,2}\b/);

  // Database: no migration pending, so the state reads as matching.
  const database = rowFor(panel, /^(Database|Datenbank)$/);
  await expect(database).toHaveCount(1);
  await expect(database.locator('td').last()).toContainText(/passt|matches/);

  // One row per MECM report source, whatever state each one is in.
  await expect(rowFor(panel, /^MECM[ -]/)).toHaveCount(4);
});

test('help explains the Version tab in the settings section', async ({ page }) => {
  await page.goto('help.php#panel-settings');
  await expect(page.locator('#panel-settings')).toBeVisible();
  await expect(
    page.getByRole('heading', { name: /Is everything on the same release\?|Läuft überall derselbe Stand\?/ })
  ).toBeVisible();
});
