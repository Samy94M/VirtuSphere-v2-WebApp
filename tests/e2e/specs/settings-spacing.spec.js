// PR-R2F (G12, G13, G15): on the settings page a sentence stands with the
// button it explains, a subheading keeps its distance from the table or form
// above it, and an alert in the tab stack takes the stack's gap like a panel.
// Geometry is the proof: the source order alone cannot say which button a
// sentence reads as belonging to (A29, ADR-0013).

const { test, expect } = require('../lib/test');
const { ROLES } = require('../lib/auth');

test.use({ storageState: ROLES.admin.storageState });

async function openTab(page, tab, width) {
  await page.setViewportSize({ width, height: 900 });
  await page.goto(`settings.php#panel-${tab}`);
  await expect(page.locator(`#panel-${tab}`)).toBeVisible();
}

for (const width of [1440, 390]) {
  test(`each HTTPS switch sentence sits nearer its own button than the one above at ${width}px`, async ({ page }) => {
    await openTab(page, 'https', width);

    const gaps = await page.evaluate(() => {
      const actions = ['save_https_enabled', 'save_https_redirect', 'save_https_hsts'];
      const buttons = actions.map((action) => document.querySelector(`form:has(input[name="action"][value="${action}"]) button[type="submit"]`));
      const section = buttons[0].closest('section');
      const paragraphs = [...section.querySelectorAll('p')];
      return buttons.map((button, index) => {
        // The sentence of a button is the last paragraph before it.
        const own = paragraphs.filter((p) => p.compareDocumentPosition(button) & Node.DOCUMENT_POSITION_FOLLOWING).pop();
        const ownRect = own.getBoundingClientRect();
        const toOwn = Math.round(button.getBoundingClientRect().top - ownRect.bottom);
        const toPrevious = index === 0 ? null : Math.round(ownRect.top - buttons[index - 1].getBoundingClientRect().bottom);
        return { toOwn, toPrevious };
      });
    });

    for (const { toOwn, toPrevious } of gaps.slice(1)) {
      expect(toOwn).toBeLessThan(toPrevious);
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  });
}

test('no sentence stands under the save button of a catalog or backup form', async ({ page }) => {
  await openTab(page, 'catalog', 1440);
  expect(await page.locator('#panel-catalog .panel > form ~ p').count()).toBe(0);
  expect(await page.locator('#panel-backup > form.form-grid ~ p').count()).toBe(0);
});

test('a subheading or sentence after a table or form keeps its distance', async ({ page }) => {
  await openTab(page, 'https', 1440);
  const httpsGap = await page.evaluate(() => {
    const heading = document.querySelector('#panel-https form:has(input[value="upload_https_cert"]) + h3');
    // The form grid ends its box 14px under its last control; measure from that control.
    const controls = [...heading.previousElementSibling.children].filter((child) => child.getBoundingClientRect().height > 0);
    return Math.round(heading.getBoundingClientRect().top - Math.max(...controls.map((child) => child.getBoundingClientRect().bottom)));
  });
  expect(httpsGap).toBeGreaterThanOrEqual(18);

  await openTab(page, 'system', 1440);
  const systemGaps = await page.evaluate(() => {
    const below = (table) => {
      let next = table.nextElementSibling;
      while (next && next.getBoundingClientRect().height === 0) next = next.nextElementSibling;
      return Math.round(next.getBoundingClientRect().top - table.getBoundingClientRect().bottom);
    };
    return {
      time: below(document.querySelector('#panel-time > .table-wrap')),
      backup: below(document.querySelector('#panel-backup > .table-wrap')),
    };
  });
  expect(systemGaps.time).toBeGreaterThanOrEqual(14);
  expect(systemGaps.backup).toBeGreaterThanOrEqual(14);
});

test('the machine API alert takes the stack gap like the panels after it', async ({ page }) => {
  await openTab(page, 'machine-api', 1440);
  const gaps = await page.evaluate(() => {
    const items = [...document.querySelector('#panel-machine-api').children].filter((child) => child.getBoundingClientRect().height > 0);
    return items.slice(1).map((item, index) => Math.round(item.getBoundingClientRect().top - items[index].getBoundingClientRect().bottom));
  });
  expect(gaps.length).toBeGreaterThanOrEqual(2);
  expect(gaps[0]).toBe(gaps[1]);
});
