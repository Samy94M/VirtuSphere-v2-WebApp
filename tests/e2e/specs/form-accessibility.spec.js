// Etappe 14: dynamic form semantics that a static source scan cannot prove.
// Covers the server-error state, repeated-row template IDs, keyboard insertion,
// the deploy form's live describedby changes and one screen-reader description
// sample. The page-wide negative reference scan lives in accessibility.spec.js.

const { test, expect } = require('@playwright/test');
const { ROLES } = require('../lib/auth');
const { seedMatrixFixtures, cleanupMatrixFixtures } = require('../lib/matrix-seed');
const { formReferenceProblems } = require('../lib/form-accessibility');

test.use({ storageState: ROLES.admin.storageState });

const MARK = 'e2eformaria';
let seeded = null;

test.beforeAll(() => {
  cleanupMatrixFixtures(MARK);
  seeded = seedMatrixFixtures(MARK);
});

test.afterAll(() => cleanupMatrixFixtures(MARK));

async function expectReferenceIntegrity(page, context) {
  const problems = await formReferenceProblems(page);
  expect(problems, `${context}\n${problems.join('\n')}`).toEqual([]);
}

test('server validation binds every visible field error to exactly one invalid control', async ({ page }) => {
  await page.goto('credentials.php');
  let form = page.locator('form:has(input[name="action"][value="create"])');
  await form.locator('select[name="type"]').evaluate((select) => {
    select.add(new Option('invalid', 'invalid'));
    select.value = 'invalid';
  });
  await form.locator('input[name="name"]').fill('');
  await form.locator('input[name="host"]').fill('');
  await form.locator('input[name="port"]').fill('0');
  await form.locator('input[name="username"]').fill('');
  await form.locator('input[name="secret"]').fill('');
  await form.evaluate((node) => { node.noValidate = true; });
  await Promise.all([
    page.waitForURL(/credentials\.php/),
    form.locator('button[type="submit"]').click(),
  ]);

  form = page.locator('form:has(input[name="action"][value="create"])');
  await expect(form.locator('.field-error')).toHaveCount(6);
  await expectReferenceIntegrity(page, 'credential validation error references');

  const host = form.locator('input[name="host"]');
  await expect(host).toHaveAttribute('aria-invalid', 'true');
  await expect(host).toHaveAccessibleDescription(/erforderlich|required/i);
});

test('VM repeat templates produce unique names and IDs through keyboard activation', async ({ page }) => {
  await page.goto(`vm_edit.php?mission_id=${seeded.missionId}&vm_id=${seeded.vmId}`);

  const interfaceRows = page.locator('[data-repeat-target="interfaces"] [data-repeat-row]');
  const diskRows = page.locator('[data-repeat-target="disks"] [data-repeat-row]');
  const initialInterfaces = await interfaceRows.count();
  const initialDisks = await diskRows.count();

  const addInterface = page.locator('[data-add-row="interfaces"]');
  await addInterface.focus();
  await page.keyboard.press('Enter');
  await page.keyboard.press('Enter');
  await expect(interfaceRows).toHaveCount(initialInterfaces + 2);

  const addDisk = page.locator('[data-add-row="disks"]');
  await addDisk.focus();
  await page.keyboard.press('Enter');
  await page.keyboard.press('Enter');
  await expect(diskRows).toHaveCount(initialDisks + 2);

  const repeatShape = await page.locator('[data-repeat-target] [data-repeat-row]').evaluateAll((rows) => {
    const controls = rows.flatMap((row) => Array.from(row.querySelectorAll('input, select, textarea')));
    return {
      ids: controls.map((control) => control.id).filter(Boolean),
      names: controls.map((control) => control.getAttribute('name')).filter(Boolean),
      html: rows.map((row) => row.outerHTML).join('\n'),
    };
  });
  expect(repeatShape.html).not.toContain('__INDEX__');
  expect(new Set(repeatShape.ids).size, 'every repeated control ID stays unique').toBe(repeatShape.ids.length);
  expect(new Set(repeatShape.names).size, 'every repeated control name stays unique').toBe(repeatShape.names.length);
  await expectReferenceIntegrity(page, 'dynamic VM row references');
  await expect(page.locator('[role="group"][id="form-vm_edit-interfaces"]')).toHaveAccessibleDescription(/Gateway/i);
});

test('deploy mode locks add and remove only their live hint references', async ({ page }) => {
  await page.goto(`deploy.php?mission_id=${seeded.missionId}&credential_esxi_id=${seeded.esxiId}`);
  const form = page.locator('form:has(select[name="credential_esxi_id"])');
  const mode = form.locator('select[name="mode"]');
  const stagger = form.locator('input[name="stagger_minutes"]');
  const staggerLock = form.locator('[data-stagger-lock]');
  const startWait = form.locator('input[name="start_wait"]');
  const startLock = form.locator('[data-start-wait-lock]');

  await mode.selectOption('full');
  await expect(startWait).toBeEnabled();
  await expect(startLock).toBeHidden();
  await expect(startWait).toHaveAttribute('aria-describedby', /form-schedule-start_wait-hint/);
  await expect(startWait).not.toHaveAttribute('aria-describedby', /start_wait_lock/);

  await mode.selectOption('powercycle');
  await expect(startWait).toBeDisabled();
  await expect(startLock).toBeVisible();
  await expect(startWait).toHaveAttribute('aria-describedby', /form-schedule-start_wait_lock-hint/);

  await stagger.fill('5');
  await expect(staggerLock).toBeVisible();
  await expect(mode).toHaveAttribute('aria-describedby', /form-schedule-mode_stagger_lock-hint/);
  await stagger.fill('');
  await expect(staggerLock).toBeHidden();
  await expect(mode).not.toHaveAttribute('aria-describedby', /mode_stagger_lock/);

  await expectReferenceIntegrity(page, 'dynamic deploy hint references');
});
