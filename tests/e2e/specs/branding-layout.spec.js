const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');

test('login logo is prominent without causing mobile overflow', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('login.php');

  const logo = page.locator('.login-logo');
  await expect(logo).toBeVisible();
  const box = await logo.boundingBox();
  expect(box?.width, 'login logo renders at the intended width').toBeCloseTo(129, 3);
  expect(box?.height, 'login logo renders at the intended height').toBeCloseTo(129, 3);

  const hasHorizontalOverflow = await page.evaluate(() =>
    document.documentElement.scrollWidth > document.documentElement.clientWidth
  );
  expect(hasHorizontalOverflow, 'larger login branding still fits at 360 px').toBe(false);
});

test.describe('authenticated portal branding', () => {
  test.use({ storageState: ROLES.admin.storageState });

  test('sidebar logo stays aligned when navigation collapses', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('dashboard.php');

    const logo = page.locator('.brand-mark');
    const title = page.locator('.brand > span');
    const toggle = page.locator('.nav-toggle');
    await expect(logo).toBeVisible();
    await expect(toggle).toBeVisible();

    const [logoBox, titleBox, toggleBox] = await Promise.all([
      logo.boundingBox(),
      title.boundingBox(),
      toggle.boundingBox(),
    ]);
    expect(logoBox?.width, 'sidebar logo renders at the intended width').toBeCloseTo(48, 3);
    expect(logoBox?.height, 'sidebar logo renders at the intended height').toBeCloseTo(48, 3);
    expect(titleBox.x, 'brand title remains to the right of the logo').toBeGreaterThan(logoBox.x + logoBox.width);
    expect(toggleBox.x, 'mobile navigation toggle does not overlap the title').toBeGreaterThanOrEqual(titleBox.x + titleBox.width);

    const hasHorizontalOverflow = await page.evaluate(() =>
      document.documentElement.scrollWidth > document.documentElement.clientWidth
    );
    expect(hasHorizontalOverflow, 'larger sidebar branding still fits at 360 px').toBe(false);
  });
});