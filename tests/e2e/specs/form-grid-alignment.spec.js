// PR-R2 (G9): the controls of one form row start on one line, even when a
// caption wraps to two lines, inside a field group as well as between plain
// fields. The form grid gives every field the same band of row tracks and each
// field is a subgrid of it (controls.css); one column (520px and below) keeps
// the plain stacked layout. Geometry is the only proof: a source scan cannot
// see where a subgrid puts a control (A29, ADR-0013).
//
// The caption is made long in the browser, so the check does not depend on how
// a translation happens to wrap at this width.

const { test, expect } = require('../lib/test');
const { ROLES } = require('../lib/auth');
const { runPhp, phpJson } = require('../lib/php');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2eformgrid';
const LONG_CAPTION = 'A caption long enough to wrap onto a second line in its column';

function cleanup() {
  runPhp(`
$db = db();
$db->query("DELETE FROM deploy_missions WHERE mission_name = '${MARK}-mission'");
`);
}

function seedMission() {
  return phpJson(`
$id = repo_create_mission(db(), [
    'mission_name' => '${MARK}-mission',
    'hypervisor_datastorage' => 'formgrid-ds',
    'hypervisor_datacenter' => '',
    'domain' => 'formgrid.invalid',
], false, null);
echo 'JSON' . json_encode(['mission' => $id]) . 'JSON';
`, ['lib/repo/missions.php']);
}

// Replaces the caption text of the label that holds `control` and returns the
// top edge of every control named, after layout.
async function topsWithLongCaption(page, captionOf, controls) {
  return page.evaluate(({ captionOf, controls, text }) => {
    const label = document.querySelector(captionOf).closest('label');
    const caption = [...label.childNodes].find((node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim() !== '');
    caption.textContent = text;
    return {
      tops: controls.map((selector) => Math.round(document.querySelector(selector).getBoundingClientRect().top)),
      overflow: document.documentElement.scrollWidth - window.innerWidth,
    };
  }, { captionOf, controls, text: LONG_CAPTION });
}

test.beforeEach(() => cleanup());
test.afterAll(() => cleanup());

for (const width of [1440, 1000, 700]) {
  test(`a wrapped caption keeps the controls of its group row in line at ${width}px`, async ({ page }) => {
    const { mission } = seedMission();
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`deploy.php?mission_id=${mission}`);

    const before = await page.locator('input[name="start_wait"]').boundingBox();
    const result = await topsWithLongCaption(page, 'input[name="powercycle_wait"]', [
      'input[name="powercycle_wait"]',
      'input[name="start_wait"]',
    ]);

    expect(result.tops[0]).toBe(result.tops[1]);
    // Both controls moved down together with the taller caption track.
    expect(result.tops[1]).toBeGreaterThan(Math.round(before.y));
    expect(result.overflow).toBeLessThanOrEqual(0);
  });
}

test('a wrapped caption keeps plain fields of one row in line', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('users.php?view=directory');

  const result = await topsWithLongCaption(page, 'input[name="bind_upn"]', [
    'input[name="bind_upn"]',
    'input[name="bind_password"]',
  ]);

  expect(result.tops[0]).toBe(result.tops[1]);
});

test('one column keeps the stacked layout and does not overflow', async ({ page }) => {
  const { mission } = seedMission();
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`deploy.php?mission_id=${mission}`);

  const display = await page.locator('input[name="powercycle_wait"]').evaluate((input) => getComputedStyle(input.closest('label')).display);
  expect(display).toBe('block');
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
});
